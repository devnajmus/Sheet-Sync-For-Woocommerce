<?php
/**
 * Export order: parent products (WooCommerce Sorting) then variations grouped underneath.
 *
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class SheetSync_Export_Order {

	/**
	 * @param array<string, mixed> $cursor
	 * @return array<string, mixed>
	 */
	public static function normalize_cursor( array $cursor ): array {
		$legacy_id = max( 0, (int) ( $cursor['id'] ?? 0 ) );
		if ( ! isset( $cursor['parent_after_id'] ) && $legacy_id > 0 && ! isset( $cursor['pending_parent_id'] ) ) {
			$product = wc_get_product( $legacy_id );
			if ( $product ) {
				if ( $product instanceof WC_Product_Variation ) {
					$parent = wc_get_product( $product->get_parent_id() );
					if ( $parent ) {
						return array(
							'parent_after_menu_order' => (int) $parent->get_menu_order(),
							'parent_after_title'      => (string) $parent->get_name(),
							'parent_after_id'         => (int) $parent->get_id(),
							'pending_parent_id'       => (int) $parent->get_id(),
							'variation_after_id'      => (int) $product->get_id(),
						);
					}
				}
				return array(
					'parent_after_menu_order' => (int) $product->get_menu_order(),
					'parent_after_title'      => (string) $product->get_name(),
					'parent_after_id'         => (int) $product->get_id(),
					'pending_parent_id'       => 0,
					'variation_after_id'      => 0,
				);
			}
		}

		return array(
			'parent_after_menu_order' => (int) ( $cursor['parent_after_menu_order'] ?? -1 ),
			'parent_after_title'      => (string) ( $cursor['parent_after_title'] ?? '' ),
			'parent_after_id'         => max( 0, (int) ( $cursor['parent_after_id'] ?? 0 ) ),
			'pending_parent_id'       => max( 0, (int) ( $cursor['pending_parent_id'] ?? 0 ) ),
			'variation_after_id'      => max( 0, (int) ( $cursor['variation_after_id'] ?? 0 ) ),
			'parent_queue'            => isset( $cursor['parent_queue'] ) && is_array( $cursor['parent_queue'] )
				? array_values( array_map( 'intval', $cursor['parent_queue'] ) )
				: array(),
		);
	}

	/**
	 * Next product IDs for sheet export: each parent (Sorting order), then its variations underneath.
	 *
	 * @param array<string, mixed> $cursor
	 * @return array{ids: int[], cursor: array<string, mixed>}
	 */
	public static function get_next_export_ids( int $connection_id, array $cursor, int $limit ): array {
		$cursor = self::normalize_cursor( $cursor );
		$ids    = array();
		$limit  = max( 1, $limit );
		$queue  = $cursor['parent_queue'];

		while ( count( $ids ) < $limit ) {
			if ( $cursor['pending_parent_id'] > 0 ) {
				$remaining = $limit - count( $ids );
				$chunk     = self::get_variation_ids(
					$cursor['pending_parent_id'],
					$cursor['variation_after_id'],
					$remaining
				);
				foreach ( $chunk as $vid ) {
					$ids[] = $vid;
					$cursor['variation_after_id'] = $vid;
				}
				if ( count( $chunk ) < $remaining ) {
					$parent = wc_get_product( $cursor['pending_parent_id'] );
					if ( $parent ) {
						$cursor['parent_after_menu_order'] = (int) $parent->get_menu_order();
						$cursor['parent_after_title']      = (string) $parent->get_name();
						$cursor['parent_after_id']         = (int) $parent->get_id();
					}
					$cursor['pending_parent_id']  = 0;
					$cursor['variation_after_id'] = 0;
				}
				if ( count( $ids ) >= $limit ) {
					break;
				}
				continue;
			}

			if ( empty( $queue ) ) {
				$prefetch = (int) apply_filters( 'sheetsync_export_parent_prefetch_size', 15 );
				$prefetch = max( 1, min( 50, $prefetch ) );
				$queue    = self::get_next_parent_ids_batch( $connection_id, $cursor, $prefetch );
				if ( empty( $queue ) ) {
					break;
				}
			}

			$parent_id = (int) array_shift( $queue );
			if ( $parent_id <= 0 ) {
				continue;
			}

			$ids[] = $parent_id;
			$cursor['pending_parent_id']  = $parent_id;
			$cursor['variation_after_id'] = 0;

			if ( count( $ids ) >= $limit ) {
				break;
			}
		}

		$cursor['parent_queue'] = $queue;

		return array(
			'ids'    => $ids,
			'cursor' => $cursor,
		);
	}

	/**
	 * Human-readable row label for the sheet (Row Type column).
	 */
	public static function sheet_row_role_label( WC_Product $product ): string {
		if ( $product instanceof WC_Product_Variation ) {
			return 'Variation (option)';
		}
		if ( $product->is_type( 'variable' ) ) {
			return 'Variable (main)';
		}
		return 'Simple product';
	}

	/**
	 * Same family ID on parent + all its variations (filter/group in sheet).
	 */
	public static function sheet_product_group_key( WC_Product $product ): string {
		$target = $product;
		if ( $product instanceof WC_Product_Variation ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $parent ) {
				return '';
			}
			$target = $parent;
		}
		if ( ! $target->is_type( 'variable' ) ) {
			$sku = (string) $target->get_sku();
			return $sku !== '' ? $sku : '';
		}
		$sku = (string) $target->get_sku();
		return $sku !== '' ? $sku : 'parent#' . $target->get_id();
	}

	/**
	 * Plain-language option text (e.g. "Color: Red · Size: M").
	 */
	public static function sheet_option_summary( WC_Product $product ): string {
		if ( $product->is_type( 'variable' ) ) {
			$count = count( $product->get_children() );
			if ( $count < 1 ) {
				return 'Variable product — add variation rows below';
			}
			return sprintf(
				/* translators: %d: number of variation rows */
				'Variable — %d option rows below (expand ▸ on left)',
				$count
			);
		}
		if ( $product instanceof WC_Product_Variation ) {
			return class_exists( 'SheetSync_Variation_Sync', false )
				? SheetSync_Variation_Sync::export_variation_human_summary( $product )
				: '';
		}
		return '';
	}

	/**
	 * Shows which main product a variation belongs to.
	 */
	public static function sheet_belongs_to_label( WC_Product $product ): string {
		if ( $product instanceof WC_Product_Variation ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $parent ) {
				return '';
			}
			$name = function_exists( 'sheetsync_decode_sheet_text' )
				? sheetsync_decode_sheet_text( (string) $parent->get_name() )
				: (string) $parent->get_name();
			$sku  = (string) $parent->get_sku();
			return $sku !== '' ? $name . ' (' . $sku . ')' : $name;
		}
		if ( $product->is_type( 'variable' ) ) {
			return '— this is the main row —';
		}
		return '';
	}

	/**
	 * Optional title prefix so variations are visually nested in the Product Title column.
	 */
	public static function sheet_export_title( WC_Product $product ): string {
		$name = function_exists( 'sheetsync_decode_sheet_text' )
			? sheetsync_decode_sheet_text( (string) $product->get_name() )
			: (string) $product->get_name();
		if ( $product instanceof WC_Product_Variation ) {
			return '  ↳ ' . $name;
		}
		if ( $product->is_type( 'variable' ) ) {
			return '▸ ' . $name;
		}
		return $name;
	}

	/**
	 * Remove export-only title markers before sheet → WooCommerce import.
	 */
	public static function sanitize_import_title( string $title ): string {
		$title = preg_replace( '/^[\s▸↳]+/u', '', $title );
		return trim( $title );
	}

	/**
	 * @param array<string, mixed> $cursor
	 * @return int[]
	 */
	private static function get_next_parent_ids_batch( int $connection_id, array $cursor, int $limit ): array {
		global $wpdb;

		$limit        = max( 1, min( 50, $limit ) );
		$statuses     = self::export_post_statuses();
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$params       = array_merge( array( 'product' ), $statuses );

		$join       = '';
		$cursor_sql = '';
		$after_mo    = (int) $cursor['parent_after_menu_order'];
		$after_title = (string) $cursor['parent_after_title'];
		$after_id    = (int) $cursor['parent_after_id'];

		if ( $after_mo >= 0 ) {
			$cursor_sql = ' AND (
				(p.menu_order > %d)
				OR (p.menu_order = %d AND p.post_title > %s)
				OR (p.menu_order = %d AND p.post_title = %s AND p.ID > %d)
			)';
			$params[] = $after_mo;
			$params[] = $after_mo;
			$params[] = $after_title;
			$params[] = $after_mo;
			$params[] = $after_title;
			$params[] = $after_id;
		}

		$cat_join = self::category_join_sql( $connection_id, $params );
		$join    .= $cat_join['join'];
		$params[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT p.ID FROM {$wpdb->posts} p {$join}
			WHERE p.post_type = %s AND p.post_status IN ({$placeholders})
			{$cursor_sql}
			ORDER BY p.menu_order ASC, p.post_title ASC, p.ID ASC
			LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $wpdb->get_col( sheetsync_wpdb_prepare( $sql, $params ) ) );
	}

	/**
	 * @param array<string, mixed> $cursor
	 */
	private static function get_next_parent_id( int $connection_id, array $cursor ): int {
		$batch = self::get_next_parent_ids_batch( $connection_id, $cursor, 1 );
		return ! empty( $batch ) ? (int) $batch[0] : 0;
	}

	/**
	 * @return int[]
	 */
	private static function get_variation_ids( int $parent_id, int $after_id, int $limit ): array {
		global $wpdb;

		$statuses     = self::export_post_statuses();
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$params       = array_merge( array( 'product' ), $statuses, array( 'product_variation', $parent_id ), $statuses );
		$after_sql    = '';
		if ( $after_id > 0 ) {
			$after_sql  = ' AND v.ID > %d';
			$params[]   = $after_id;
		}
		$params[] = max( 1, $limit );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT v.ID FROM {$wpdb->posts} v
			INNER JOIN {$wpdb->posts} parent ON parent.ID = v.post_parent
				AND parent.post_type = %s AND parent.post_status IN ({$placeholders})
			WHERE v.post_type = %s AND v.post_parent = %d AND v.post_status IN ({$placeholders})
			{$after_sql}
			ORDER BY v.menu_order ASC, v.post_title ASC, v.ID ASC
			LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $wpdb->get_col( sheetsync_wpdb_prepare( $sql, $params ) ) );
	}

	/**
	 * Post statuses included in catalog export (matches parent + variation queries).
	 *
	 * @return string[]
	 */
	public static function export_post_statuses(): array {
		$statuses = array( 'publish', 'draft', 'private', 'pending', 'future' );
		/**
		 * Post statuses included in WC → Sheet export (parents and variations).
		 * Example: `return array( 'publish' );` for published products only.
		 *
		 * @param string[] $statuses
		 */
		$statuses = apply_filters( 'sheetsync_export_post_statuses', $statuses );
		if ( ! is_array( $statuses ) ) {
			return array( 'publish' );
		}
		$flat = array();
		foreach ( $statuses as $status ) {
			if ( is_array( $status ) || is_object( $status ) ) {
				continue;
			}
			$key = sanitize_key( (string) $status );
			if ( $key !== '' ) {
				$flat[] = $key;
			}
		}
		return ! empty( $flat ) ? array_values( array_unique( $flat ) ) : array( 'publish' );
	}

	/**
	 * Verify export cursor walks each product ID once (no duplicate rows in export order).
	 *
	 * @return array{total: int, unique: int, duplicate_ids: int}
	 */
	public static function audit_export_id_uniqueness( int $connection_id ): array {
		$all    = self::get_all_export_ids_ordered( $connection_id );
		$total  = count( $all );
		$unique = count( array_unique( $all ) );

		return array(
			'total'          => $total,
			'unique'         => $unique,
			'duplicate_ids'  => max( 0, $total - $unique ),
		);
	}

	/**
	 * Category filter applies to parent products only; variations follow their parent.
	 *
	 * @param array<int|string> $params
	 * @return array{join: string}
	 */
	private static function category_join_sql( int $connection_id, array &$params ): array {
		$cat_raw = (string) get_option( 'sheetsync_sync_category_ids_' . $connection_id, '' );
		if ( '' === trim( $cat_raw ) ) {
			return array( 'join' => '' );
		}

		$term_ids = array_filter( array_map( 'absint', explode( ',', str_replace( ' ', '', $cat_raw ) ) ) );
		if ( empty( $term_ids ) ) {
			return array( 'join' => '' );
		}

		global $wpdb;
		$cat_ph = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
		foreach ( $term_ids as $tid ) {
			$params[] = $tid;
		}

		return array(
			'join' => " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					AND tt.taxonomy = 'product_cat' AND tt.term_id IN ({$cat_ph})",
		);
	}

	/**
	 * Export cursor after a prefix of batch IDs was written (partial batch / API failure).
	 *
	 * Walks from $cursor_before through $batch_ids in order and stops once every ID in
	 * $written_ids has been consumed — so the cursor never jumps past unwritten products.
	 *
	 * @param int[] $batch_ids    Full ID list from get_next_export_ids for this batch.
	 * @param int[] $written_ids  Product IDs successfully written to the sheet (in write order).
	 * @return array<string, mixed>
	 */
	public static function cursor_after_written_products(
		int $connection_id,
		array $cursor_before,
		array $batch_ids,
		array $written_ids
	): array {
		$written_ids = array_values( array_filter( array_map( 'intval', $written_ids ) ) );
		if ( empty( $written_ids ) ) {
			return self::normalize_cursor( $cursor_before );
		}

		$remaining = array_fill_keys( $written_ids, true );
		$cursor    = self::normalize_cursor( $cursor_before );

		foreach ( $batch_ids as $product_id ) {
			$product_id = (int) $product_id;
			if ( $product_id <= 0 ) {
				continue;
			}

			$step = self::get_next_export_ids( $connection_id, $cursor, 1 );
			if ( empty( $step['ids'] ) || (int) $step['ids'][0] !== $product_id ) {
				break;
			}

			$cursor = $step['cursor'];
			if ( isset( $remaining[ $product_id ] ) ) {
				unset( $remaining[ $product_id ] );
				if ( empty( $remaining ) ) {
					break;
				}
			}
		}

		return $cursor;
	}

	/**
	 * Flat list of all export IDs in order (for one-shot export / page helper).
	 *
	 * @return int[]
	 */
	public static function get_all_export_ids_ordered( int $connection_id ): array {
		$cursor = self::normalize_cursor( array() );
		$all    = array();
		do {
			$batch  = self::get_next_export_ids( $connection_id, $cursor, 200 );
			$cursor = $batch['cursor'];
			$all    = array_merge( $all, $batch['ids'] );
		} while ( ! empty( $batch['ids'] ) );

		return $all;
	}
}
