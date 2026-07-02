<?php
/**
 * Two-way sync conflict detection, field-policy merge, and conflict queue.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Conflict_Resolver' ) ) :

class SheetSync_Conflict_Resolver {

	/**
	 * Whether sheet and WooCommerce both changed since the last successful sync.
	 */
	public static function is_simultaneous_edit(
		string $mode,
		object $conn,
		string $external_key,
		string $row_hash,
		array $row,
		?WC_Product $wc_product,
		SheetSync_Product_Updater $updater,
		SheetSync_Product_Map_Repository $map_repo,
		int $connection_id
	): bool {
		if ( 'two_way' !== (string) ( $conn->sync_direction ?? '' ) ) {
			return false;
		}
		if ( ! $wc_product || $external_key === '' ) {
			return false;
		}

		$prev = $map_repo->get_sheet_hash( $connection_id, $external_key );
		if ( $prev === null || $prev === $row_hash ) {
			return false;
		}

		// Sheet changed — only a true conflict when WooCommerce also changed since the last sync.
		$map    = $map_repo->find_by_external_key( $connection_id, $external_key );
		$hasher = new SheetSync_Hash_Normalizer();
		if ( ! $hasher->wc_modified_since_last_sync( $wc_product, $map ) ) {
			return false;
		}

		return ! $updater->row_matches_product( $row, $wc_product );
	}

	/**
	 * Apply two-way pull for a row that may have simultaneous edits.
	 *
	 * @param array<string, string> $peek_data
	 * @param array<int, string>    $row
	 * @return array{result: string, detail?: string}
	 */
	public static function resolve_pull_row(
		object $conn,
		int $connection_id,
		string $mode,
		string $external_key,
		string $row_hash,
		array $peek_data,
		array $row,
		int $sheet_row_num,
		WC_Product $wc_product,
		SheetSync_Product_Updater $updater,
		SheetSync_Product_Map_Repository $map_repo
	): array {
		if ( ! self::is_simultaneous_edit( $mode, $conn, $external_key, $row_hash, $row, $wc_product, $updater, $map_repo, $connection_id ) ) {
			return array( 'result' => $updater->update( $row, $sheet_row_num ) );
		}

		$settings     = ( new SheetSync_Sync_State_Repository() )->get_settings( $connection_id );
		$on_conflict  = (string) ( $settings['on_conflict'] ?? 'merge' );
		$field_policy = is_array( $settings['field_policies'] ?? null ) ? $settings['field_policies'] : array();

		if ( 'queue' === $on_conflict ) {
			self::record_conflict(
				$connection_id,
				$map_repo,
				$external_key,
				$sheet_row_num,
				$peek_data,
				$row_hash,
				$wc_product,
				$updater,
				$field_policy
			);
			return array(
				'result' => 'queued',
				'detail' => __( 'Sheet and WooCommerce both changed — conflict queued for review.', 'sheetsync-for-woocommerce' ),
			);
		}

		if ( 'wc' === $on_conflict ) {
			return array(
				'result' => 'skipped',
				'detail' => __( 'WooCommerce wins — sheet changes deferred until next push.', 'sheetsync-for-woocommerce' ),
			);
		}

		if ( 'sheet' === $on_conflict ) {
			return array( 'result' => $updater->update( $row, $sheet_row_num ) );
		}

		// merge (default): apply per-field policies.
		$merged = self::merge_field_data(
			$wc_product,
			$peek_data,
			$field_policy,
			$updater,
			$map_repo->find_by_external_key( $connection_id, $external_key )
		);

		return array( 'result' => $updater->update_from_data( $wc_product, $merged, $sheet_row_num ) );
	}

	/**
	 * @param array<string, string> $sheet_data
	 * @param array<string, string> $field_policies
	 * @return array<string, string>
	 */
	public static function merge_field_data(
		WC_Product $product,
		array $sheet_data,
		array $field_policies,
		SheetSync_Product_Updater $updater,
		?object $map_entry
	): array {
		$wc_data  = $updater->extract_product_data( $product, array_keys( $sheet_data ) );
		$merged   = $sheet_data;
		$default  = (string) ( $field_policies['default'] ?? 'newest' );
		$wc_gmt   = sheetsync_get_product_modified_gmt( $product );
		$sheet_at = $map_entry && ! empty( $map_entry->last_pulled_at )
			? (string) $map_entry->last_pulled_at
			: ( $map_entry && ! empty( $map_entry->sheet_updated_at ) ? (string) $map_entry->sheet_updated_at : null );

		foreach ( $sheet_data as $field => $sheet_value ) {
			if ( in_array( $field, array( 'product_id', 'parent_sku' ), true ) ) {
				continue;
			}

			$wc_value = (string) ( $wc_data[ $field ] ?? '' );
			if ( (string) $sheet_value === $wc_value ) {
				$merged[ $field ] = $sheet_value;
				continue;
			}

			$policy = (string) ( $field_policies[ $field ] ?? $default );

			switch ( $policy ) {
				case 'wc':
					$merged[ $field ] = $wc_value;
					break;
				case 'sheet':
					$merged[ $field ] = (string) $sheet_value;
					break;
				case 'newest':
				default:
					$merged[ $field ] = self::pick_newest_value(
						(string) $sheet_value,
						$wc_value,
						$sheet_at,
						$wc_gmt
					);
					break;
			}
		}

		return $merged;
	}

	/**
	 * @param array<string, string> $peek_data
	 * @param array<string, string> $field_policies
	 */
	public static function record_conflict(
		int $connection_id,
		SheetSync_Product_Map_Repository $map_repo,
		string $external_key,
		int $sheet_row,
		array $peek_data,
		string $row_hash,
		WC_Product $wc_product,
		SheetSync_Product_Updater $updater,
		array $field_policies = array()
	): void {
		$changed_fields = self::summarize_conflicting_fields( $wc_product, $peek_data, $updater, $field_policies );
		$payload = array(
			'at'             => current_time( 'mysql', true ),
			'sheet_row'      => $sheet_row,
			'sheet_hash'     => $row_hash,
			'product_id'     => $wc_product->get_id(),
			'sku'            => sanitize_text_field( $peek_data['_sku'] ?? '' ),
			'title'          => sanitize_text_field( $peek_data['post_title'] ?? '' ),
			'wc_modified'    => sheetsync_get_product_modified_gmt( $wc_product ),
			'changed_fields' => $changed_fields,
		);

		$map_repo->upsert(
			$connection_id,
			array(
				'external_key'  => $external_key,
				'product_id'    => $wc_product->get_id(),
				'sheet_row'     => $sheet_row,
				'sync_status'   => 'conflict',
				'conflict_json' => wp_json_encode( $payload ),
			)
		);

		SheetSync_Logger::log(
			$connection_id,
			'import',
			'partial',
			0,
			1,
			sprintf(
				/* translators: 1: sheet row, 2: SKU or product id */
				__( 'Conflict queued: row %1$d (%2$s) — sheet and WooCommerce both changed.', 'sheetsync-for-woocommerce' ),
				$sheet_row,
				$payload['sku'] !== '' ? $payload['sku'] : '#' . $wc_product->get_id()
			),
			0
		);
	}

	private static function pick_newest_value(
		string $sheet_value,
		string $wc_value,
		?string $sheet_at,
		?string $wc_gmt
	): string {
		$sheet_ts = $sheet_at ? strtotime( $sheet_at . ' UTC' ) : 0;
		$wc_ts    = $wc_gmt ? strtotime( $wc_gmt . ' UTC' ) : 0;

		if ( $wc_ts > $sheet_ts ) {
			return $wc_value;
		}

		return $sheet_value;
	}

	/**
	 * Human-readable list of fields that differ between sheet row and WooCommerce.
	 *
	 * @param array<string, string> $sheet_data
	 * @param array<string, string> $field_policies
	 * @return string[]
	 */
	public static function summarize_conflicting_fields(
		WC_Product $product,
		array $sheet_data,
		SheetSync_Product_Updater $updater,
		array $field_policies = array()
	): array {
		unset( $field_policies );
		$wc_data = $updater->extract_product_data( $product, array_keys( $sheet_data ) );
		$labels  = class_exists( 'SheetSync_Field_Mapper', false )
			? SheetSync_Field_Mapper::get_available_fields( function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro() )
			: array();
		$changed = array();

		foreach ( $sheet_data as $field => $sheet_value ) {
			if ( in_array( $field, array( 'product_id', 'parent_sku', 'sheet_color', 'sheet_size', 'variation_attrs' ), true ) ) {
				continue;
			}
			$wc_value = (string) ( $wc_data[ $field ] ?? '' );
			if ( (string) $sheet_value === $wc_value ) {
				continue;
			}
			if ( trim( (string) $sheet_value ) === '' && trim( $wc_value ) === '' ) {
				continue;
			}
			$changed[] = (string) ( $labels[ $field ] ?? $field );
		}

		return array_slice( array_values( array_unique( $changed ) ), 0, 8 );
	}

	/**
	 * Resolve a queued conflict from the admin UI.
	 *
	 * @return array{success: bool, message: string}
	 */
	public static function resolve_queued_conflict( int $connection_id, int $map_id, string $resolution ): array {
		$map_repo = new SheetSync_Product_Map_Repository();
		$map      = $map_repo->find_by_id( $map_id );
		if ( ! $map || (int) $map->connection_id !== $connection_id || 'conflict' !== (string) ( $map->sync_status ?? '' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Conflict not found.', 'sheetsync-for-woocommerce' ),
			);
		}

		$conn = SheetSync_Sync_Engine::get_connection( $connection_id );
		if ( ! $conn ) {
			return array(
				'success' => false,
				'message' => __( 'Connection not found.', 'sheetsync-for-woocommerce' ),
			);
		}

		$product = (int) $map->product_id > 0 ? wc_get_product( (int) $map->product_id ) : null;
		if ( ! $product && ! empty( $map->external_key ) ) {
			$peek = array( '_sku' => (string) $map->external_key );
			if ( str_starts_with( (string) $map->external_key, 'pid:' ) ) {
				$peek = array( 'product_id' => (string) substr( (string) $map->external_key, 4 ) );
			}
			$pid = SheetSync_Product_Map_Repository::resolve_import_product_id( $peek );
			if ( $pid > 0 ) {
				$product = wc_get_product( $pid );
			}
		}

		if ( 'dismiss' === $resolution ) {
			$map_repo->clear_conflict( $map_id );
			return array(
				'success' => true,
				'message' => __( 'Conflict dismissed.', 'sheetsync-for-woocommerce' ),
			);
		}

		if ( ! $product ) {
			return array(
				'success' => false,
				'message' => __( 'WooCommerce product not found for this conflict.', 'sheetsync-for-woocommerce' ),
			);
		}

		if ( 'apply_wc' === $resolution ) {
			if ( ! class_exists( 'SheetSync_Push_To_Sheet_Service', false ) ) {
				return array(
					'success' => false,
					'message' => __( 'Push service unavailable.', 'sheetsync-for-woocommerce' ),
				);
			}
			$ok = ( new SheetSync_Push_To_Sheet_Service() )->push_single_product( $product, $conn );
			if ( $ok ) {
				$map_repo->clear_conflict( $map_id );
			}
			return array(
				'success' => $ok,
				'message' => $ok
					? __( 'WooCommerce version pushed to the sheet.', 'sheetsync-for-woocommerce' )
					: __( 'Push to sheet failed — see Sync Logs.', 'sheetsync-for-woocommerce' ),
			);
		}

		if ( 'apply_sheet' === $resolution ) {
			$sheet_row = (int) ( $map->sheet_row ?? 0 );
			if ( $sheet_row < 1 ) {
				$conflict = json_decode( (string) ( $map->conflict_json ?? '' ), true );
				$sheet_row = (int) ( $conflict['sheet_row'] ?? 0 );
			}
			if ( $sheet_row < 1 ) {
				return array(
					'success' => false,
					'message' => __( 'Sheet row unknown for this conflict.', 'sheetsync-for-woocommerce' ),
				);
			}

			$maps     = SheetSync_Field_Mapper::get_maps_for_sync( $connection_id, $conn );
			$last_col = SheetSync_Field_Mapper::max_column_letter( $maps );
			$client   = new SheetSync_Sheets_Client();
			$rows     = $client->get_rows(
				$conn->spreadsheet_id,
				"{$conn->sheet_name}!A{$sheet_row}:{$last_col}{$sheet_row}"
			);
			$row = is_array( $rows[0] ?? null ) ? $rows[0] : array();
			if ( empty( array_filter( $row, static fn( $v ) => $v !== '' && $v !== null ) ) ) {
				return array(
					'success' => false,
					'message' => __( 'Sheet row is empty.', 'sheetsync-for-woocommerce' ),
				);
			}

			$updater = new SheetSync_Product_Updater( $maps );
			$result  = $updater->update_from_data( $product, $updater->extract_data( $row ), $sheet_row );
			if ( 'updated' !== $result ) {
				return array(
					'success' => false,
					'message' => __( 'Could not apply sheet row to WooCommerce.', 'sheetsync-for-woocommerce' ),
				);
			}

			$hasher       = new SheetSync_Hash_Normalizer();
			$external_key = (string) ( $map->external_key ?? SheetSync_Product_Map_Repository::external_key_for_product( $product ) );
			$peek_data    = $updater->extract_data( $row );
			SheetSync_Import_Row_Service::store_row_hash(
				$connection_id,
				$map_repo,
				$external_key,
				$hasher->sheet_hash( $row, $maps, $peek_data ),
				$sheet_row,
				$peek_data,
				true,
				$product
			);
			$map_repo->clear_conflict( $map_id );

			return array(
				'success' => true,
				'message' => __( 'Sheet version applied to WooCommerce.', 'sheetsync-for-woocommerce' ),
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Unknown resolution action.', 'sheetsync-for-woocommerce' ),
		);
	}
}

endif;
