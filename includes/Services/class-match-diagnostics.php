<?php
/**
 * Match diagnostics — product ↔ sheet link health and identity contract.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Match_Diagnostics', false ) ) :

class SheetSync_Match_Diagnostics {

	/**
	 * @return array<string, mixed>
	 */
	public static function run( int $connection_id, bool $include_sheet_scan = false ): array {
		$connection_id = absint( $connection_id );
		$findings      = array();

		$conn = class_exists( 'SheetSync_Sync_Engine', false )
			? SheetSync_Sync_Engine::get_connection( $connection_id )
			: null;

		if ( ! $conn ) {
			return array(
				'ok'              => false,
				'score'           => 'critical',
				'connection_id'   => $connection_id,
				'connection_name' => '',
				'findings'        => array(
					self::finding(
						'error',
						'connection',
						__( 'Connection not found.', 'sheetsync-for-woocommerce' )
					),
				),
			);
		}

		$is_orders = SheetSync_Sync_Engine::is_orders_type( (string) ( $conn->connection_type ?? 'products' ) );
		if ( $is_orders ) {
			return array(
				'ok'              => true,
				'score'           => 'healthy',
				'connection_id'   => $connection_id,
				'connection_name' => (string) ( $conn->name ?? '' ),
				'is_orders'       => true,
				'findings'        => array(
					self::finding(
						'info',
						'connection',
						__( 'Match diagnostics apply to product connections. Order tabs use order ID matching automatically.', 'sheetsync-for-woocommerce' )
					),
				),
			);
		}

		$maps     = SheetSync_Field_Mapper::get_maps_for_sync( $connection_id, $conn );
		$sku_col  = trim( (string) ( $maps['_sku']['sheet_column'] ?? '' ) );
		$pid_col  = trim( (string) ( $maps['product_id']['sheet_column'] ?? '' ) );
		$has_key  = ! empty( $maps['_sku']['is_key_field'] ) || ! empty( $maps['product_id']['is_key_field'] );

		$identity = array(
			'sku_column'          => $sku_col,
			'product_id_column'   => $pid_col,
			'has_key_field'       => $has_key,
			'resolver_order'      => array_filter(
				array(
					$pid_col !== ''
						? sprintf(
							/* translators: %s: column letter */
							__( 'Product ID (column %s)', 'sheetsync-for-woocommerce' ),
							$pid_col
						)
						: '',
					$sku_col !== ''
						? sprintf(
							/* translators: %s: column letter */
							__( 'SKU (column %s)', 'sheetsync-for-woocommerce' ),
							$sku_col
						)
						: '',
					__( 'Saved row map', 'sheetsync-for-woocommerce' ),
					__( 'Product title (fallback)', 'sheetsync-for-woocommerce' ),
				)
			),
		);

		if ( $sku_col === '' && $pid_col === '' ) {
			$findings[] = self::finding(
				'error',
				'identity',
				SheetSync_Field_Mapper::identity_requirement_message( $maps ),
				0,
				admin_url( "admin.php?page=sheetsync&sheetsync_action=edit&connection_id={$connection_id}#tab-field-mapping" ),
				__( 'Fix field mapping', 'sheetsync-for-woocommerce' )
			);
		} elseif ( $pid_col === '' ) {
			$schema_pid = SheetSync_Field_Mapper::preset_to_maps( SheetSync_Field_Mapper::PROFILE_FULL )['product_id']['sheet_column'] ?? 'B';
			$findings[] = self::finding(
				'warn',
				'identity',
				sprintf(
					/* translators: %s: recommended Product ID column letter */
					__( 'Product ID column is not mapped — export cannot write stable IDs back to the sheet. Map column %s for best two-way matching.', 'sheetsync-for-woocommerce' ),
					$schema_pid
				),
				0,
				admin_url( "admin.php?page=sheetsync&sheetsync_action=edit&connection_id={$connection_id}#tab-field-mapping" ),
				__( 'Map Product ID', 'sheetsync-for-woocommerce' )
			);
		} else {
			$findings[] = self::finding(
				'ok',
				'identity',
				sprintf(
					/* translators: 1: SKU column, 2: product ID column */
					__( 'Identity columns mapped: SKU %1$s, Product ID %2$s.', 'sheetsync-for-woocommerce' ),
					$sku_col !== '' ? $sku_col : '—',
					$pid_col
				)
			);
		}

		$repo           = class_exists( 'SheetSync_Product_Map_Repository', false )
			? new SheetSync_Product_Map_Repository()
			: null;
		$mapped_rows    = $repo ? $repo->count_for_connection( $connection_id ) : 0;
		$conflicts      = $repo ? $repo->count_conflicts( $connection_id ) : 0;
		$orphaned       = $repo ? $repo->get_orphaned_map_product_ids( $connection_id, 10 ) : array();
		$orphaned_total = $repo ? $repo->count_orphaned_maps( $connection_id ) : 0;
		$unlinked_maps  = self::count_unlinked_sheet_maps( $connection_id );

		$export_breakdown = function_exists( 'sheetsync_connection_row_breakdown' )
			? sheetsync_connection_row_breakdown( $connection_id, 'import' )
			: ( function_exists( 'sheetsync_count_exportable_breakdown' )
				? sheetsync_count_exportable_breakdown( $connection_id )
				: array() );
		$export_rows      = (int) ( $export_breakdown['sheet_rows'] ?? 0 );
		$unlinked_wc      = max( 0, $export_rows - $mapped_rows );

		$links = array(
			'mapped_rows'        => $mapped_rows,
			'orphaned_maps'      => $orphaned_total,
			'orphaned_samples'   => array_map(
				static fn( int $id ): array => array(
					'product_id' => $id,
					'edit_url'   => get_edit_post_link( $id, 'raw' ) ?: '',
				),
				$orphaned
			),
			'unlinked_sheet_maps'=> $unlinked_maps,
			'open_conflicts'     => $conflicts,
			'exportable_rows'  => $export_rows,
			'unlinked_wc_estimate' => $unlinked_wc,
		);

		if ( $mapped_rows < 1 ) {
			$findings[] = self::finding(
				'warn',
				'links',
				__( 'No products linked yet — run Sync now once to build row maps (first sync links all rows).', 'sheetsync-for-woocommerce' ),
				0,
				admin_url( "admin.php?page=sheetsync&sheetsync_action=edit&connection_id={$connection_id}#tab-sync" ),
				__( 'Open Sync tab', 'sheetsync-for-woocommerce' )
			);
		} else {
			$findings[] = self::finding(
				'ok',
				'links',
				sprintf(
					/* translators: %d: linked row count */
					__( '%d sheet rows linked to WooCommerce products.', 'sheetsync-for-woocommerce' ),
					$mapped_rows
				)
			);
		}

		if ( $orphaned_total > 0 ) {
			$findings[] = self::finding(
				'warn',
				'links',
				sprintf(
					/* translators: %d: orphan map count */
					__( '%d linked rows point at deleted WooCommerce products — run a full export or sync to refresh.', 'sheetsync-for-woocommerce' ),
					$orphaned_total
				),
				0,
				admin_url( "admin.php?page=sheetsync&sheetsync_action=edit&connection_id={$connection_id}#tab-sync" ),
				__( 'Refresh sync', 'sheetsync-for-woocommerce' )
			);
		}

		if ( $unlinked_maps > 0 ) {
			$findings[] = self::finding(
				'warn',
				'links',
				sprintf(
					/* translators: %d: row count */
					__( '%d sheet row maps have no WooCommerce product ID — matching may rely on SKU/title only.', 'sheetsync-for-woocommerce' ),
					$unlinked_maps
				)
			);
		}

		if ( $conflicts > 0 ) {
			$findings[] = self::finding(
				'warn',
				'conflicts',
				sprintf(
					/* translators: %d: conflict count */
					__( '%d sync conflicts waiting for review.', 'sheetsync-for-woocommerce' ),
					$conflicts
				),
				0,
				admin_url( 'admin.php?page=sheetsync-conflicts' ),
				__( 'Open conflicts', 'sheetsync-for-woocommerce' )
			);
		}

		if ( $unlinked_wc > 0 && in_array( (string) ( $conn->sync_direction ?? '' ), array( 'wc_to_sheets', 'two_way' ), true ) ) {
			$findings[] = self::finding(
				'info',
				'links',
				sprintf(
					/* translators: %d: product count */
					__( 'About %d WooCommerce products may not be linked to sheet rows yet.', 'sheetsync-for-woocommerce' ),
					$unlinked_wc
				)
			);
		}

		$ambiguous = self::find_ambiguous_catalog_skus( 12 );
		$catalog   = array(
			'ambiguous_sku_count' => count( $ambiguous ),
			'ambiguous_skus'      => $ambiguous,
		);

		foreach ( $ambiguous as $row ) {
			$findings[] = self::finding(
				'warn',
				'catalog',
				sprintf(
					/* translators: 1: SKU, 2: duplicate count */
					__( 'SKU "%1$s" is used by %2$d WooCommerce products — add Product ID on sheet rows or fix duplicate SKUs in WooCommerce.', 'sheetsync-for-woocommerce' ),
					(string) ( $row['sku'] ?? '' ),
					(int) ( $row['count'] ?? 0 )
				),
				0,
				admin_url( 'edit.php?post_type=product&s=' . rawurlencode( (string) ( $row['sku'] ?? '' ) ) ),
				__( 'Find products', 'sheetsync-for-woocommerce' )
			);
		}

		$sheet_check = null;
		if ( $include_sheet_scan && class_exists( 'SheetSync_Sheet_Validator', false ) && ( $sku_col !== '' || $pid_col !== '' ) ) {
			$sheet_check = ( new SheetSync_Sheet_Validator() )->validate_connection( $connection_id );
			foreach ( (array) ( $sheet_check['issues'] ?? array() ) as $issue ) {
				if ( ! is_array( $issue ) ) {
					continue;
				}
				$level = (string) ( $issue['level'] ?? 'warn' );
				if ( 'ok' === $level ) {
					continue;
				}
				$findings[] = self::finding(
					'error' === $level ? 'error' : 'warn',
					'sheet',
					(string) ( $issue['message'] ?? '' ),
					(int) ( $issue['row'] ?? 0 ),
					! empty( $issue['edit_url'] ) ? (string) $issue['edit_url'] : '',
					! empty( $issue['edit_url'] ) ? __( 'Edit product', 'sheetsync-for-woocommerce' ) : ''
				);
			}
		}

		$score = self::score_from_findings( $findings );
		$ok    = 'critical' !== $score;

		return array(
			'ok'              => $ok,
			'score'           => $score,
			'connection_id'   => $connection_id,
			'connection_name' => (string) ( $conn->name ?? '' ),
			'direction'       => (string) ( $conn->sync_direction ?? '' ),
			'identity'        => $identity,
			'links'           => $links,
			'catalog'         => $catalog,
			'sheet_check'     => $sheet_check,
			'findings'        => $findings,
			'edit_url'        => admin_url( "admin.php?page=sheetsync&sheetsync_action=edit&connection_id={$connection_id}" ),
			'mapping_url'     => admin_url( "admin.php?page=sheetsync&sheetsync_action=edit&connection_id={$connection_id}#tab-field-mapping" ),
		);
	}

	/**
	 * @return array<int, array{sku:string,count:int}>
	 */
	public static function find_ambiguous_catalog_skus( int $limit = 15 ): array {
		global $wpdb;
		$limit = max( 1, min( 50, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS sku, COUNT(*) AS cnt
				 FROM {$wpdb->postmeta}
				 WHERE meta_key = '_sku' AND meta_value != ''
				 GROUP BY meta_value
				 HAVING cnt > 1
				 ORDER BY cnt DESC
				 LIMIT %d",
				$limit
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$sku = sanitize_text_field( (string) ( $row->sku ?? '' ) );
			if ( $sku === '' ) {
				continue;
			}
			$out[] = array(
				'sku'   => $sku,
				'count' => (int) ( $row->cnt ?? 0 ),
			);
		}
		return $out;
	}

	public static function count_unlinked_sheet_maps( int $connection_id ): int {
		if ( ! class_exists( 'SheetSync_Schema', false ) ) {
			return 0;
		}
		global $wpdb;
		$table = SheetSync_Schema::table( 'product_map' );
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE connection_id = %d AND sheet_row > 0 AND (product_id IS NULL OR product_id = 0)",
				$connection_id
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $findings
	 */
	private static function score_from_findings( array $findings ): string {
		$has_error = false;
		$has_warn  = false;
		foreach ( $findings as $f ) {
			$sev = (string) ( $f['severity'] ?? '' );
			if ( 'error' === $sev ) {
				$has_error = true;
			} elseif ( 'warn' === $sev ) {
				$has_warn = true;
			}
		}
		if ( $has_error ) {
			return 'critical';
		}
		if ( $has_warn ) {
			return 'attention';
		}
		return 'healthy';
	}

	/**
	 * @return array{severity:string,category:string,message:string,row:int,action_url:string,action_label:string}
	 */
	private static function finding(
		string $severity,
		string $category,
		string $message,
		int $row = 0,
		string $action_url = '',
		string $action_label = ''
	): array {
		return array(
			'severity'     => $severity,
			'category'     => $category,
			'message'      => $message,
			'row'          => $row,
			'action_url'   => $action_url,
			'action_label' => $action_label,
		);
	}
}

endif;
