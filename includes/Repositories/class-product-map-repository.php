<?php
/**
 * Product ↔ sheet row mapping repository.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Product_Map_Repository' ) ) :

class SheetSync_Product_Map_Repository {

	private string $table;

	/** @var array<int, array<string, object>> Request-scoped external_key cache per connection. */
	private array $external_key_cache = array();

	/** @var array<int, array<int, object>> Request-scoped product_id cache per connection. */
	private array $product_id_cache = array();

	public function __construct() {
		$this->table = SheetSync_Schema::table( 'product_map' );
	}

	/**
	 * Clear in-memory prefetch caches (call at end of large batches).
	 */
	public function clear_request_cache( ?int $connection_id = null ): void {
		if ( null === $connection_id ) {
			$this->external_key_cache = array();
			$this->product_id_cache   = array();
			return;
		}
		unset( $this->external_key_cache[ $connection_id ], $this->product_id_cache[ $connection_id ] );
	}

	/**
	 * Batch-load product_map rows by external_key (one query per import/export batch).
	 *
	 * @param string[] $external_keys
	 */
	public function prefetch_by_external_keys( int $connection_id, array $external_keys ): void {
		if ( $connection_id <= 0 ) {
			return;
		}

		$keys = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $external_keys ),
					static fn( string $k ): bool => $k !== ''
				)
			)
		);
		if ( empty( $keys ) ) {
			return;
		}

		if ( ! isset( $this->external_key_cache[ $connection_id ] ) ) {
			$this->external_key_cache[ $connection_id ] = array();
		}

		$missing = array();
		foreach ( $keys as $key ) {
			if ( ! isset( $this->external_key_cache[ $connection_id ][ $key ] ) ) {
				$missing[] = $key;
			}
		}
		if ( empty( $missing ) ) {
			return;
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $missing ), '%s' ) );
		$params       = array_merge( array( $connection_id ), $missing );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			sheetsync_wpdb_prepare(
				"SELECT * FROM {$this->table} WHERE connection_id = %d AND external_key IN ({$placeholders})",
				$params
			)
		);

		foreach ( $missing as $key ) {
			$this->external_key_cache[ $connection_id ][ $key ] = null;
		}
		foreach ( (array) $rows as $row ) {
			$key = (string) ( $row->external_key ?? '' );
			if ( $key !== '' ) {
				$this->external_key_cache[ $connection_id ][ $key ] = $row;
				$pid = (int) ( $row->product_id ?? 0 );
				if ( $pid > 0 ) {
					if ( ! isset( $this->product_id_cache[ $connection_id ] ) ) {
						$this->product_id_cache[ $connection_id ] = array();
					}
					$this->product_id_cache[ $connection_id ][ $pid ] = $row;
				}
			}
		}
	}

	/**
	 * Batch-load product_map rows by WooCommerce product ID.
	 *
	 * @param int[] $product_ids
	 */
	public function prefetch_by_product_ids( int $connection_id, array $product_ids ): void {
		if ( $connection_id <= 0 ) {
			return;
		}

		$ids = array_values(
			array_unique(
				array_filter( array_map( 'absint', $product_ids ) )
			)
		);
		if ( empty( $ids ) ) {
			return;
		}

		if ( ! isset( $this->product_id_cache[ $connection_id ] ) ) {
			$this->product_id_cache[ $connection_id ] = array();
		}

		$missing = array();
		foreach ( $ids as $id ) {
			if ( ! array_key_exists( $id, $this->product_id_cache[ $connection_id ] ) ) {
				$missing[] = $id;
			}
		}
		if ( empty( $missing ) ) {
			return;
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
		$params       = array_merge( array( $connection_id ), $missing );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			sheetsync_wpdb_prepare(
				"SELECT * FROM {$this->table} WHERE connection_id = %d AND product_id IN ({$placeholders})",
				$params
			)
		);

		foreach ( $missing as $id ) {
			$this->product_id_cache[ $connection_id ][ $id ] = null;
		}
		foreach ( (array) $rows as $row ) {
			$pid = (int) ( $row->product_id ?? 0 );
			if ( $pid > 0 ) {
				$this->product_id_cache[ $connection_id ][ $pid ] = $row;
				$key = (string) ( $row->external_key ?? '' );
				if ( $key !== '' ) {
					if ( ! isset( $this->external_key_cache[ $connection_id ] ) ) {
						$this->external_key_cache[ $connection_id ] = array();
					}
					$this->external_key_cache[ $connection_id ][ $key ] = $row;
				}
			}
		}
	}

	/**
	 * @return array<string, string> external_key => sheet_hash
	 */
	public function get_sheet_hashes_batch( int $connection_id, array $external_keys ): array {
		$this->prefetch_by_external_keys( $connection_id, $external_keys );
		$hashes = array();
		foreach ( $external_keys as $key ) {
			$key = (string) $key;
			if ( $key === '' ) {
				continue;
			}
			$hash = $this->get_sheet_hash( $connection_id, $key );
			if ( null !== $hash ) {
				$hashes[ $key ] = $hash;
			}
		}
		return $hashes;
	}

	/**
	 * @return object|null
	 */
	public function find_by_product_id( int $connection_id, int $product_id ) {
		if ( $product_id <= 0 ) {
			return null;
		}
		if ( isset( $this->product_id_cache[ $connection_id ] )
			&& array_key_exists( $product_id, $this->product_id_cache[ $connection_id ] ) ) {
			return $this->product_id_cache[ $connection_id ][ $product_id ];
		}
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE connection_id = %d AND product_id = %d LIMIT 1",
				$connection_id,
				$product_id
			)
		);
		if ( ! isset( $this->product_id_cache[ $connection_id ] ) ) {
			$this->product_id_cache[ $connection_id ] = array();
		}
		$this->product_id_cache[ $connection_id ][ $product_id ] = $row ?: null;
		return $row ?: null;
	}

	/**
	 * @return object|null
	 */
	public function find_by_external_key( int $connection_id, string $external_key ) {
		if ( $external_key === '' ) {
			return null;
		}
		if ( isset( $this->external_key_cache[ $connection_id ] )
			&& array_key_exists( $external_key, $this->external_key_cache[ $connection_id ] ) ) {
			return $this->external_key_cache[ $connection_id ][ $external_key ];
		}
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE connection_id = %d AND external_key = %s LIMIT 1",
				$connection_id,
				$external_key
			)
		);
		if ( ! isset( $this->external_key_cache[ $connection_id ] ) ) {
			$this->external_key_cache[ $connection_id ] = array();
		}
		$this->external_key_cache[ $connection_id ][ $external_key ] = $row ?: null;
		return $row ?: null;
	}

	/**
	 * @return object|null
	 */
	public function find_by_sheet_row( int $connection_id, int $sheet_row ) {
		global $wpdb;
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE connection_id = %d AND sheet_row = %d ORDER BY id ASC LIMIT 1",
				$connection_id,
				$sheet_row
			)
		);
	}

	/**
	 * @return object|null
	 */
	public function find_by_id( int $map_id ) {
		if ( $map_id <= 0 ) {
			return null;
		}
		global $wpdb;
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $map_id )
		);
	}

	/**
	 * Queued two-way conflicts awaiting merchant review.
	 *
	 * @return object[]
	 */
	public function list_conflicts( int $connection_id, int $limit = 25 ): array {
		global $wpdb;
		$limit = max( 1, min( 50, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE connection_id = %d AND sync_status = 'conflict'
				ORDER BY id DESC
				LIMIT %d",
				$connection_id,
				$limit
			)
		) ?: array();
	}

	public function count_conflicts( int $connection_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE connection_id = %d AND sync_status = 'conflict'",
				$connection_id
			)
		);
	}

	public function clear_conflict( int $map_id ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array(
				'sync_status'   => 'ok',
				'conflict_json' => null,
			),
			array( 'id' => $map_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Best sheet row for push — avoids duplicate appends when map exists without product link.
	 */
	public function resolve_push_sheet_row( int $connection_id, WC_Product $product, string $external_key ): int {
		$product_id = (int) $product->get_id();
		$map        = $this->find_by_product_id( $connection_id, $product_id );
		if ( $map && (int) $map->sheet_row > 0 ) {
			return (int) $map->sheet_row;
		}

		if ( $external_key !== '' ) {
			$map = $this->find_by_external_key( $connection_id, $external_key );
			if ( $map && (int) $map->sheet_row > 0 ) {
				$mapped_pid = (int) ( $map->product_id ?? 0 );
				if ( 0 === $mapped_pid || $mapped_pid === $product_id ) {
					return (int) $map->sheet_row;
				}
			}
		}

		return 0;
	}

	public function count_for_connection( int $connection_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE connection_id = %d",
				$connection_id
			)
		);
	}

	/**
	 * Product IDs in product_map that no longer exist as WC products (deleted / invalid).
	 *
	 * @return int[]
	 */
	public function get_orphaned_map_product_ids( int $connection_id, int $limit = 50 ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT pm.product_id FROM {$this->table} pm
			LEFT JOIN {$wpdb->posts} p ON p.ID = pm.product_id
				AND p.post_type IN ('product', 'product_variation')
			WHERE pm.connection_id = %d AND pm.product_id > 0 AND p.ID IS NULL
			LIMIT %d",
			$connection_id,
			$limit
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $wpdb->get_col( $sql ) );
	}

	public function count_orphaned_maps( int $connection_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} pm
				LEFT JOIN {$wpdb->posts} p ON p.ID = pm.product_id
					AND p.post_type IN ('product', 'product_variation')
				WHERE pm.connection_id = %d AND pm.product_id > 0 AND p.ID IS NULL",
				$connection_id
			)
		);
	}

	/**
	 * Remove product_map rows whose WooCommerce product no longer exists.
	 *
	 * @return int Rows deleted.
	 */
	public function delete_orphaned_maps( int $connection_id, int $limit = 100 ): int {
		global $wpdb;

		if ( $connection_id <= 0 ) {
			return 0;
		}

		$limit = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE pm FROM {$this->table} pm
				LEFT JOIN {$wpdb->posts} p ON p.ID = pm.product_id
					AND p.post_type IN ('product', 'product_variation')
				WHERE pm.connection_id = %d AND pm.product_id > 0 AND p.ID IS NULL
				LIMIT %d",
				$connection_id,
				$limit
			)
		);
	}

	/**
	 * Remove all row maps for a deleted product (every connection).
	 *
	 * @return int Rows deleted.
	 */
	public function delete_by_product_id( int $product_id ): int {
		global $wpdb;

		if ( $product_id <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table} WHERE product_id = %d",
				$product_id
			)
		);
	}

	/**
	 * Remove all product ↔ sheet row mappings for a connection (before a fresh Full Sync export).
	 */
	public function delete_all_for_connection( int $connection_id ): void {
		global $wpdb;
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array( 'connection_id' => $connection_id ),
			array( '%d' )
		);
	}

	/**
	 * Remove row maps not refreshed during the current catalog export run.
	 *
	 * @return int Rows deleted.
	 */
	public function delete_maps_not_pushed_since( int $connection_id, string $since_gmt ): int {
		global $wpdb;

		if ( $connection_id <= 0 || $since_gmt === '' ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table}
				WHERE connection_id = %d
				AND ( last_pushed_at IS NULL OR last_pushed_at < %s )",
				$connection_id,
				$since_gmt
			)
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function upsert( int $connection_id, array $data ): int {
		global $wpdb;

		$external_key = self::normalize_external_key( $data );
		if ( $external_key === '' || $connection_id <= 0 ) {
			return 0;
		}

		$product_id = (int) ( $data['product_id'] ?? 0 );
		$existing   = $this->find_by_external_key( $connection_id, $external_key );
		if ( $existing && $product_id > 0 ) {
			$existing_pid = (int) ( $existing->product_id ?? 0 );
			if ( $existing_pid > 0 && $existing_pid !== $product_id ) {
				$external_key         = 'pid:' . $product_id;
				$data['external_key'] = $external_key;
			}
		}

		$sheet_row = isset( $data['sheet_row'] ) ? (int) $data['sheet_row'] : null;
		if ( null !== $sheet_row && $sheet_row <= 0 ) {
			$sheet_row = null;
		}

		$wc_hash          = isset( $data['wc_hash'] ) ? (string) $data['wc_hash'] : null;
		$sheet_hash       = isset( $data['sheet_hash'] ) ? (string) $data['sheet_hash'] : null;
		$wc_modified_gmt  = isset( $data['wc_modified_gmt'] ) ? (string) $data['wc_modified_gmt'] : null;
		$sheet_updated_at = isset( $data['sheet_updated_at'] ) ? (string) $data['sheet_updated_at'] : null;
		$last_pulled_at   = isset( $data['last_pulled_at'] ) ? (string) $data['last_pulled_at'] : null;
		$last_pushed_at   = isset( $data['last_pushed_at'] ) ? (string) $data['last_pushed_at'] : null;
		$sync_status      = (string) ( $data['sync_status'] ?? 'ok' );
		$conflict_json    = isset( $data['conflict_json'] ) ? (string) $data['conflict_json'] : null;
		$meta_json        = isset( $data['meta_json'] ) ? (string) $data['meta_json'] : null;

		$sheet_row_sql = null === $sheet_row ? 'NULL' : '%d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "INSERT INTO `{$this->table}` (
			connection_id, product_id, external_key, sheet_row,
			wc_hash, sheet_hash, wc_modified_gmt, sheet_updated_at,
			last_pulled_at, last_pushed_at, sync_status, conflict_json, meta_json
		) VALUES (
			%d, %d, %s, {$sheet_row_sql},
			%s, %s, %s, %s,
			%s, %s, %s, %s, %s
		) ON DUPLICATE KEY UPDATE
			product_id = IF(
				product_id > 0 AND VALUES(product_id) > 0 AND product_id <> VALUES(product_id),
				product_id,
				IF(VALUES(product_id) > 0, VALUES(product_id), product_id)
			),
			sheet_row = COALESCE(VALUES(sheet_row), sheet_row),
			wc_hash = IF(VALUES(wc_hash) IS NOT NULL AND VALUES(wc_hash) <> '', VALUES(wc_hash), wc_hash),
			sheet_hash = IF(VALUES(sheet_hash) IS NOT NULL AND VALUES(sheet_hash) <> '', VALUES(sheet_hash), sheet_hash),
			wc_modified_gmt = IF(VALUES(wc_modified_gmt) IS NOT NULL AND VALUES(wc_modified_gmt) <> '', VALUES(wc_modified_gmt), wc_modified_gmt),
			sheet_updated_at = IF(VALUES(sheet_updated_at) IS NOT NULL AND VALUES(sheet_updated_at) <> '', VALUES(sheet_updated_at), sheet_updated_at),
			last_pulled_at = IF(VALUES(last_pulled_at) IS NOT NULL AND VALUES(last_pulled_at) <> '', VALUES(last_pulled_at), last_pulled_at),
			last_pushed_at = IF(VALUES(last_pushed_at) IS NOT NULL AND VALUES(last_pushed_at) <> '', VALUES(last_pushed_at), last_pushed_at),
			sync_status = IF(VALUES(sync_status) <> '', VALUES(sync_status), sync_status),
			conflict_json = IF(VALUES(conflict_json) IS NOT NULL AND VALUES(conflict_json) <> '', VALUES(conflict_json), conflict_json),
			meta_json = IF(VALUES(meta_json) IS NOT NULL AND VALUES(meta_json) <> '', VALUES(meta_json), meta_json),
			updated_at = CURRENT_TIMESTAMP";

		$params = array(
			$connection_id,
			$product_id,
			$external_key,
		);
		if ( null !== $sheet_row ) {
			$params[] = $sheet_row;
		}
		$params = array_merge(
			$params,
			array(
				$wc_hash ?? '',
				$sheet_hash ?? '',
				$wc_modified_gmt ?? '',
				$sheet_updated_at ?? '',
				$last_pulled_at ?? '',
				$last_pushed_at ?? '',
				$sync_status,
				$conflict_json ?? '',
				$meta_json ?? '',
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( sheetsync_wpdb_prepare( $sql, $params ) );

		if ( false === $result ) {
			return 0;
		}

		$map_id = (int) $wpdb->insert_id;
		if ( $map_id > 0 ) {
			return $map_id;
		}

		$map_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE connection_id = %d AND external_key = %s LIMIT 1",
				$connection_id,
				$external_key
			)
		);

		return $map_id > 0 ? $map_id : 0;
	}

	/**
	 * Batch upsert with a single prefetch query (avoids N SELECT before INSERT).
	 *
	 * @param array<int, array<string, mixed>> $map_rows
	 */
	public function upsert_many( int $connection_id, array $map_rows ): int {
		if ( $connection_id <= 0 || empty( $map_rows ) ) {
			return 0;
		}

		$keys = array();
		foreach ( $map_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = self::normalize_external_key( $row );
			if ( $key !== '' ) {
				$keys[] = $key;
			}
		}
		if ( ! empty( $keys ) ) {
			$this->prefetch_by_external_keys( $connection_id, $keys );
		}

		$count = 0;
		foreach ( $map_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( $this->upsert( $connection_id, $row ) > 0 ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Stable external key for product_map upserts (never empty).
	 *
	 * @param array<string, mixed> $data
	 */
	public static function normalize_external_key( array $data ): string {
		$external_key = trim( (string) ( $data['external_key'] ?? '' ) );
		if ( $external_key !== '' ) {
			return $external_key;
		}
		if ( ! empty( $data['product_id'] ) && (int) $data['product_id'] > 0 ) {
			return 'pid:' . (int) $data['product_id'];
		}
		if ( ! empty( $data['sheet_row'] ) && (int) $data['sheet_row'] > 0 ) {
			return 'row:' . (int) $data['sheet_row'];
		}
		return '';
	}

	/**
	 * One-time repair for rows stored with empty external_key (UNIQUE collisions).
	 */
	public static function repair_empty_external_keys(): int {
		global $wpdb;
		$table = SheetSync_Schema::table( 'product_map' );
		$fixed = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, connection_id, product_id, sheet_row FROM `{$table}`
			WHERE external_key = '' OR external_key IS NULL
			LIMIT 200"
		);

		if ( empty( $rows ) ) {
			return 0;
		}

		foreach ( $rows as $row ) {
			$key = '';
			if ( (int) $row->product_id > 0 ) {
				$key = 'pid:' . (int) $row->product_id;
			} elseif ( (int) $row->sheet_row > 0 ) {
				$key = 'row:' . (int) $row->sheet_row;
			} else {
				$key = 'map:' . (int) $row->id;
			}

			$conflict = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE connection_id = %d AND external_key = %s AND id != %d LIMIT 1",
					(int) $row->connection_id,
					$key,
					(int) $row->id
				)
			);
			if ( $conflict ) {
				$key = 'map:' . (int) $row->id;
			}

			$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array( 'external_key' => $key ),
				array( 'id' => (int) $row->id ),
				array( '%s' ),
				array( '%d' )
			);
			if ( false !== $updated ) {
				++$fixed;
			}
		}

		return $fixed;
	}

	/**
	 * Resolve duplicate (connection_id, sheet_row) rows before UNIQUE index migration.
	 *
	 * @return int Rows adjusted.
	 */
	public static function dedupe_sheet_row_mappings(): int {
		global $wpdb;

		$table = SheetSync_Schema::table( 'product_map' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dupes = $wpdb->get_results(
			"SELECT connection_id, sheet_row FROM `{$table}`
			WHERE sheet_row IS NOT NULL
			GROUP BY connection_id, sheet_row
			HAVING COUNT(*) > 1"
		);

		if ( empty( $dupes ) ) {
			return 0;
		}

		$adjusted = 0;
		foreach ( $dupes as $dupe ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, product_id, external_key FROM `{$table}`
					WHERE connection_id = %d AND sheet_row = %d
					ORDER BY id ASC",
					(int) $dupe->connection_id,
					(int) $dupe->sheet_row
				)
			);
			if ( empty( $rows ) || count( $rows ) < 2 ) {
				continue;
			}

			array_shift( $rows );
			foreach ( $rows as $row ) {
				$pid = (int) ( $row->product_id ?? 0 );
				$key = $pid > 0 ? 'pid:' . $pid : 'map:' . (int) $row->id;

				$conflict = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT id FROM `{$table}` WHERE connection_id = %d AND external_key = %s AND id != %d LIMIT 1",
						(int) $dupe->connection_id,
						$key,
						(int) $row->id
					)
				);
				if ( $conflict ) {
					$key = 'map:' . (int) $row->id;
				}

				$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table,
					array(
						'external_key' => $key,
						'sheet_row'    => null,
					),
					array( 'id' => (int) $row->id ),
					array( '%s', '%d' ),
					array( '%d' )
				);
				if ( false !== $updated ) {
					++$adjusted;
				}
			}
		}

		return $adjusted;
	}

	/**
	 * Add UNIQUE (connection_id, sheet_row) when missing (schema 2.0.4).
	 */
	public static function ensure_unique_sheet_row_index(): void {
		global $wpdb;

		$table = SheetSync_Schema::table( 'product_map' );

		$index = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT INDEX_NAME FROM information_schema.STATISTICS
			WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s LIMIT 1',
			DB_NAME,
			$table,
			'conn_sheet_row_unique'
		) );

		if ( ! empty( $index ) ) {
			return;
		}

		$legacy = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT INDEX_NAME FROM information_schema.STATISTICS
			WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s LIMIT 1',
			DB_NAME,
			$table,
			'conn_sheet_row'
		) );

		if ( ! empty( $legacy ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX conn_sheet_row" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY conn_sheet_row_unique (connection_id, sheet_row)" );
	}

	public function update_hashes(
		int $map_id,
		?string $wc_hash = null,
		?string $sheet_hash = null
	): void {
		global $wpdb;
		$fields = array();
		$formats = array();
		if ( null !== $wc_hash ) {
			$fields['wc_hash'] = $wc_hash;
			$formats[]         = '%s';
		}
		if ( null !== $sheet_hash ) {
			$fields['sheet_hash'] = $sheet_hash;
			$formats[]            = '%s';
		}
		if ( empty( $fields ) ) {
			return;
		}
		$wpdb->update( $this->table, $fields, array( 'id' => $map_id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * All mapped rows for a connection, ordered by sheet row (for export styling).
	 *
	 * @return object[]
	 */
	public function get_max_sheet_row( int $connection_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT MAX(sheet_row) FROM {$this->table} WHERE connection_id = %d",
				$connection_id
			)
		);
	}

	/**
	 * @return object[]
	 */
	public function list_ordered_by_sheet_row( int $connection_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE connection_id = %d AND sheet_row IS NOT NULL ORDER BY sheet_row ASC",
				$connection_id
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	public function delete_for_connection( int $connection_id ): void {
		global $wpdb;
		$wpdb->delete( $this->table, array( 'connection_id' => $connection_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Migrate legacy wp_options row hashes into product_map.
	 */
	public function migrate_legacy_hashes( int $connection_id ): int {
		$hashes = get_option( 'sheetsync_row_hashes_' . $connection_id, array() );
		if ( ! is_array( $hashes ) || empty( $hashes ) ) {
			return 0;
		}
		$migrated = 0;
		foreach ( $hashes as $external_key => $sheet_hash ) {
			if ( ! is_string( $external_key ) || $external_key === '' ) {
				continue;
			}
			$this->upsert(
				$connection_id,
				array(
					'external_key' => $external_key,
					'sheet_hash'   => is_string( $sheet_hash ) ? $sheet_hash : md5( (string) $sheet_hash ),
					'product_id'   => 0,
				)
			);
			++$migrated;
		}
		delete_option( 'sheetsync_row_hashes_' . $connection_id );
		return $migrated;
	}

	/**
	 * Build external key from product.
	 * Uses SKU when it uniquely identifies this product; falls back to pid:{id} on duplicate SKU.
	 */
	public static function external_key_for_product( WC_Product $product ): string {
		$product_id = (int) $product->get_id();
		$sku        = (string) $product->get_sku();
		if ( $sku !== '' ) {
			$sku_owner = (int) wc_get_product_id_by_sku( $sku );
			if ( $sku_owner > 0 && $sku_owner !== $product_id ) {
				return 'pid:' . $product_id;
			}
			if ( self::sku_is_ambiguous_in_catalog( $sku ) ) {
				return 'pid:' . $product_id;
			}
			return sanitize_text_field( $sku );
		}
		return 'pid:' . $product_id;
	}

	/**
	 * External key for a sheet row being imported (mirrors export pid: fallback on duplicate SKUs).
	 *
	 * @param array<string, string> $data Mapped row values.
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps Field maps.
	 */
	public static function external_key_from_import_data( array $data, array $maps, int $sheet_row = 0 ): string {
		if ( ! empty( $data['product_id'] ) ) {
			$pid = absint( $data['product_id'] );
			if ( $pid > 0 ) {
				return 'pid:' . $pid;
			}
		}
		foreach ( $maps as $field => $info ) {
			if ( ! empty( $info['is_key_field'] ) && ! empty( $data[ $field ] ) ) {
				$value = sanitize_text_field( (string) $data[ $field ] );
				if ( '_sku' === $field && self::sku_is_ambiguous_in_catalog( $value ) ) {
					$pid = ! empty( $data['product_id'] ) ? absint( $data['product_id'] ) : 0;
					return $pid > 0 ? 'pid:' . $pid : $value;
				}
				if ( 'post_title' === $field ) {
					return self::title_external_key( $value );
				}
				return $value;
			}
		}
		if ( ! empty( $data['_sku'] ) ) {
			$sku = sanitize_text_field( (string) $data['_sku'] );
			if ( self::sku_is_ambiguous_in_catalog( $sku ) ) {
				$pid = ! empty( $data['product_id'] ) ? absint( $data['product_id'] ) : 0;
				if ( $pid > 0 ) {
					return 'pid:' . $pid;
				}
			}
			return $sku;
		}
		if ( ! empty( $data['post_title'] ) ) {
			$title_key = self::title_external_key( (string) $data['post_title'] );
			if ( $title_key !== '' ) {
				return $title_key;
			}
		}
		if ( $sheet_row > 0 ) {
			return 'row:' . $sheet_row;
		}
		return '';
	}

	/**
	 * Stable external key for title-only rows (case/whitespace insensitive).
	 */
	public static function title_external_key( string $title ): string {
		$match_key = function_exists( 'sheetsync_title_match_key' )
			? sheetsync_title_match_key( $title )
			: strtolower( trim( $title ) );
		if ( $match_key === '' ) {
			return '';
		}
		$max_len = 120;
		if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $match_key ) : strlen( $match_key ) ) > $max_len ) {
			return 'title:h:' . md5( $match_key );
		}
		return 'title:' . $match_key;
	}

	/**
	 * Resolve product ID from a prior import map (title key or legacy raw title).
	 */
	public static function resolve_product_id_from_connection_map( int $connection_id, string $title ): int {
		if ( $connection_id <= 0 || $title === '' ) {
			return 0;
		}

		global $wpdb;
		$table = SheetSync_Schema::table( 'product_map' );
		$keys  = array_unique(
			array_filter(
				array(
					self::title_external_key( $title ),
					function_exists( 'sheetsync_normalize_sheet_title' )
						? sanitize_text_field( sheetsync_normalize_sheet_title( $title ) )
						: sanitize_text_field( $title ),
				)
			)
		);

		foreach ( $keys as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$product_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT product_id FROM {$table}
					WHERE connection_id = %d AND external_key = %s AND product_id > 0
					LIMIT 1",
					$connection_id,
					$key
				)
			);
			if ( $product_id > 0 && wc_get_product( $product_id ) ) {
				return $product_id;
			}
		}

		return 0;
	}

	/**
	 * @return int[]
	 */
	private static function find_product_ids_by_title_match_key( string $title ): array {
		global $wpdb;

		$normalized = function_exists( 'sheetsync_normalize_sheet_title' )
			? sheetsync_normalize_sheet_title( $title )
			: trim( $title );
		$match_key  = function_exists( 'sheetsync_title_match_key' )
			? sheetsync_title_match_key( $title )
			: strtolower( $normalized );

		if ( $match_key === '' ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts}
				WHERE post_type IN ('product', 'product_variation')
				AND post_status != 'trash'
				AND ( post_title = %s OR LOWER(TRIM(post_title)) = %s )
				ORDER BY ID ASC
				LIMIT 15",
				$normalized,
				$match_key
			)
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$ids = array();
		foreach ( $rows as $row ) {
			$row_key = function_exists( 'sheetsync_title_match_key' )
				? sheetsync_title_match_key( (string) $row->post_title )
				: strtolower( trim( (string) $row->post_title ) );
			if ( $row_key === $match_key ) {
				$ids[] = (int) $row->ID;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Resolve WooCommerce product ID after a successful import row.
	 *
	 * @param array<string, string> $data Mapped row values.
	 */
	public static function resolve_import_product_id( array $data, ?WC_Product $product = null, int $connection_id = 0 ): int {
		if ( $product && $product->get_id() > 0 ) {
			return (int) $product->get_id();
		}
		if ( ! empty( $data['product_id'] ) ) {
			$pid = absint( $data['product_id'] );
			if ( $pid > 0 && wc_get_product( $pid ) ) {
				return $pid;
			}
		}
		if ( ! empty( $data['_sku'] ) ) {
			$sku = sanitize_text_field( (string) $data['_sku'] );
			if ( $sku !== '' && ! self::sku_is_ambiguous_in_catalog( $sku ) ) {
				$id = (int) wc_get_product_id_by_sku( $sku );
				if ( $id > 0 ) {
					return $id;
				}
			}
		}
		if ( ! empty( $data['post_title'] ) ) {
			$title = function_exists( 'sheetsync_normalize_sheet_title' )
				? sheetsync_normalize_sheet_title( (string) $data['post_title'] )
				: trim( (string) $data['post_title'] );
			if ( $title !== '' ) {
				$id = self::resolve_product_id_by_title( $title, $data, $connection_id );
				if ( $id > 0 ) {
					return $id;
				}
			}
		}
		return 0;
	}

	/**
	 * Resolve a product ID by normalized title, disambiguating with map / product_id / SKU.
	 *
	 * @param array<string, string> $data Mapped row values.
	 */
	public static function resolve_product_id_by_title( string $title, array $data, int $connection_id = 0 ): int {
		$title = function_exists( 'sheetsync_normalize_sheet_title' )
			? sheetsync_normalize_sheet_title( $title )
			: trim( $title );
		if ( $title === '' ) {
			return 0;
		}

		if ( $connection_id > 0 ) {
			$mapped_id = self::resolve_product_id_from_connection_map( $connection_id, $title );
			if ( $mapped_id > 0 ) {
				return $mapped_id;
			}
		}

		$ids = self::find_product_ids_by_title_match_key( $title );

		if ( empty( $ids ) ) {
			return 0;
		}
		if ( 1 === count( $ids ) ) {
			return (int) $ids[0];
		}

		if ( ! empty( $data['product_id'] ) ) {
			$pid = absint( $data['product_id'] );
			if ( $pid > 0 && in_array( $pid, $ids, true ) ) {
				return $pid;
			}
		}

		if ( ! empty( $data['_sku'] ) ) {
			$sku    = sanitize_text_field( (string) $data['_sku'] );
			$sku_id = (int) wc_get_product_id_by_sku( $sku );
			if ( $sku_id > 0 && in_array( $sku_id, $ids, true ) ) {
				return $sku_id;
			}
		}

		return 0;
	}

	/**
	 * True when multiple WooCommerce products share the same normalized title.
	 */
	public static function title_has_multiple_matches( string $title ): bool {
		return count( self::find_product_ids_by_title_match_key( $title ) ) > 1;
	}

	/**
	 * True when more than one WooCommerce product shares the same SKU meta value.
	 */
	public static function sku_is_ambiguous_in_catalog( string $sku ): bool {
		$sku = sanitize_text_field( $sku );
		if ( $sku === '' ) {
			return false;
		}

		static $cache = array();
		if ( array_key_exists( $sku, $cache ) ) {
			return $cache[ $sku ];
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s",
				$sku
			)
		);

		$cache[ $sku ] = $count > 1;
		return $cache[ $sku ];
	}

	/**
	 * Get sheet hash for row using product map or legacy option fallback.
	 */
	public function get_sheet_hash( int $connection_id, string $external_key ): ?string {
		$map = $this->find_by_external_key( $connection_id, $external_key );
		if ( $map && ! empty( $map->sheet_hash ) ) {
			return (string) $map->sheet_hash;
		}
		$legacy = get_option( 'sheetsync_row_hashes_' . $connection_id, array() );
		return is_array( $legacy ) && isset( $legacy[ $external_key ] )
			? (string) $legacy[ $external_key ]
			: null;
	}

	public function set_sheet_hash( int $connection_id, string $external_key, string $hash, array $extra = array() ): void {
		$this->upsert(
			$connection_id,
			array_merge(
				array(
					'external_key' => $external_key,
					'sheet_hash'   => $hash,
				),
				$extra
			)
		);
	}

	/**
	 * Whether WooCommerce still has a product for this mapped sheet row (Smart Diff).
	 *
	 * @param array<string, string> $peek_data Mapped row values (SKU, parent_sku, etc.).
	 */
	public function mapped_product_still_exists( int $connection_id, string $external_key, array $peek_data ): bool {
		if ( $external_key !== '' ) {
			$map = $this->find_by_external_key( $connection_id, $external_key );
			if ( $map && (int) $map->product_id > 0 ) {
				$product = wc_get_product( (int) $map->product_id );
				if ( $product ) {
					return true;
				}
			}
		}

		if ( ! empty( $peek_data['_sku'] ) ) {
			$id = (int) wc_get_product_id_by_sku( sanitize_text_field( $peek_data['_sku'] ) );
			if ( $id && wc_get_product( $id ) ) {
				return true;
			}
		}

		if ( ! empty( $peek_data['post_title'] ) ) {
			$title = function_exists( 'sheetsync_normalize_sheet_title' )
				? sheetsync_normalize_sheet_title( (string) $peek_data['post_title'] )
				: trim( (string) $peek_data['post_title'] );
			if ( $title !== '' ) {
				$mapped_id = self::resolve_product_id_from_connection_map( $connection_id, $title );
				if ( $mapped_id > 0 ) {
					return true;
				}
				$id = self::resolve_product_id_by_title( $title, $peek_data, $connection_id );
				if ( $id > 0 && wc_get_product( $id ) ) {
					return true;
				}
			}
		}

		return false;
	}
}

endif;
