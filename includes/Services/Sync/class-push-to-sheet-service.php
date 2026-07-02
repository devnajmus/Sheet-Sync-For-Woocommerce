<?php
/**
 * Push changed WooCommerce products to mapped sheet rows (incremental).
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Push_To_Sheet_Service' ) ) :

class SheetSync_Push_To_Sheet_Service {

	private SheetSync_Product_Map_Repository $map_repo;
	private SheetSync_Hash_Normalizer $hasher;
	private SheetSync_Rate_Limiter $limiter;

	public function __construct() {
		$this->map_repo = new SheetSync_Product_Map_Repository();
		$this->hasher   = new SheetSync_Hash_Normalizer();
		$this->limiter  = new SheetSync_Rate_Limiter();
	}

	/**
	 * @return array{done: bool, processed: int, skipped: int, errors: int}
	 */
	public function process_batch( object $job, object $conn, int $batch_size, int $deadline ): array {
		$connection_id = (int) $job->connection_id;
		$maps = class_exists( 'SheetSync_Bulk_Processor', false )
			? SheetSync_Bulk_Processor::maps_for_sheet_export( $connection_id )
			: SheetSync_Field_Mapper::get_maps_for_export( $connection_id );
		if ( empty( $maps ) ) {
			SheetSync_Logger::log(
				$connection_id,
				'export',
				'error',
				0,
				0,
				__( 'No field mappings found. Open Field Mapping, assign sheet columns (SKU required), save, then run Full Sync.', 'sheetsync-for-woocommerce' )
			);
			return array( 'done' => true, 'processed' => 0, 'skipped' => 0, 'errors' => 1 );
		}
		$hash_maps = $this->hasher->maps_for_hash( $maps );

		$state_repo = new SheetSync_Sync_State_Repository();
		$state      = $state_repo->get( $connection_id );
		$meta       = ( new SheetSync_Job_Repository() )->get_cursor_meta( $job );

		$watermark = $state->wc_watermark_gmt ?? '1970-01-01 00:00:00';
		$last_id   = (int) ( $meta['push_last_id'] ?? 0 );

		// Full / bootstrap: export every product to sequential sheet rows (ID cursor).
		// Do NOT mix with incremental after batch 1 — that caused "1025 synced" while the sheet stopped ~row 951.
		if ( 'full' === $job->mode || 'bootstrap' === $job->direction ) {
			return $this->process_bootstrap_batch( $job, $conn, $maps, $batch_size, $deadline );
		}

		$job_repo       = new SheetSync_Job_Repository();
		$force_push_ids = array();
		if ( 'two_way' === (string) ( $job->direction ?? '' ) ) {
			$force_push_ids = $job_repo->pop_wc_edited_push_ids( (int) $job->id, $batch_size );
			$meta           = $job_repo->get_cursor_meta( $job );
		}

		$remaining = max( 0, $batch_size - count( $force_push_ids ) );
		$ids       = $force_push_ids;
		if ( $remaining > 0 ) {
			$modified = $this->get_modified_product_ids( $watermark, $last_id, $remaining, $connection_id );
			foreach ( $modified as $mid ) {
				$mid = (int) $mid;
				if ( $mid > 0 && ! in_array( $mid, $ids, true ) ) {
					$ids[] = $mid;
				}
			}
		}

		if ( empty( $ids ) ) {
			$fresh = $job_repo->get( (int) $job->id );
			if ( $fresh && $job_repo->has_wc_edited_push_queue( $fresh ) ) {
				return array( 'done' => false, 'processed' => 0, 'skipped' => 0, 'errors' => 0 );
			}
			return array( 'done' => true, 'processed' => 0, 'skipped' => 0, 'errors' => 0 );
		}

		$force_push_lookup = array_fill_keys( array_map( 'intval', $force_push_ids ), true );

		$client       = new SheetSync_Sheets_Client();
		$updater      = new SheetSync_Product_Updater( $maps );
		$last_col     = SheetSync_Field_Mapper::max_column_letter( $maps );
		$min_cols     = SheetSync_Field_Mapper::col_to_index( $last_col ) + 1;
		$write_chunk  = (int) apply_filters( 'sheetsync_incremental_push_write_chunk_size', 50 );
		$write_chunk  = max( 1, min( 100, $write_chunk ) );
		$processed    = $skipped = $errors = 0;
		$max_modified = $watermark;
		$max_id       = $last_id;
		$missing_ids  = array();
		$timed_out    = false;
		$wc_edited_pushed = 0;
		/** @var array<int, array<string, mixed>> */
		$pending      = array();

		$this->map_repo->prefetch_by_product_ids( $connection_id, $ids );
		$products_by_id = $this->load_products_batch( $ids );

		$flush_pending = function () use (
			&$pending,
			&$processed,
			&$errors,
			&$max_id,
			&$max_modified,
			&$wc_edited_pushed,
			$force_push_lookup,
			$client,
			$conn,
			$connection_id,
			$last_col,
			$min_cols
		): void {
			if ( empty( $pending ) ) {
				return;
			}
			$batch     = $pending;
			$result    = $this->flush_incremental_push_pending(
				$client,
				$conn,
				$connection_id,
				$last_col,
				$min_cols,
				$batch
			);
			$processed += (int) ( $result['processed'] ?? 0 );
			$errors    += (int) ( $result['errors'] ?? 0 );

			$handled_ids = array_unique(
				array_merge(
					array_map( 'intval', (array) ( $result['flushed_ids'] ?? array() ) ),
					array_map( 'intval', (array) ( $result['failed_ids'] ?? array() ) )
				)
			);

			if ( ! empty( $handled_ids ) ) {
				foreach ( $batch as $flushed_item ) {
					$flushed_pid = (int) $flushed_item['product_id'];
					if ( ! in_array( $flushed_pid, $handled_ids, true ) ) {
						continue;
					}
					if ( isset( $force_push_lookup[ $flushed_pid ] ) ) {
						++$wc_edited_pushed;
					}
					$max_id = max( $max_id, $flushed_pid );
					$mod    = (string) ( $flushed_item['wc_modified_gmt'] ?? '' );
					if ( $mod !== '' && $mod > $max_modified ) {
						$max_modified = $mod;
					}
				}
				$handled_lookup = array_flip( $handled_ids );
				$pending        = array_values(
					array_filter(
						$pending,
						static fn( array $item ): bool => ! isset( $handled_lookup[ (int) $item['product_id'] ] )
					)
				);
			}
		};

		foreach ( $ids as $product_id ) {
			if ( time() >= $deadline ) {
				$timed_out = true;
				if ( class_exists( 'SheetSync_Sync_Tick_Perf_Log', false ) ) {
					SheetSync_Sync_Tick_Perf_Log::note_deadline_exit();
				}
				break;
			}

			$product = $products_by_id[ $product_id ] ?? null;
			if ( ! $product ) {
				$product = wc_get_product( $product_id );
			}
			if ( ! $product ) {
				++$skipped;
				$missing_ids[] = (int) $product_id;
				$max_id        = max( $max_id, (int) $product_id );
				continue;
			}
			$external_key = SheetSync_Product_Map_Repository::external_key_for_product( $product );
			$new_hash     = $this->hasher->wc_hash( $product, $maps );
			$map          = $this->map_repo->find_by_product_id( $connection_id, $product_id )
				?: $this->map_repo->find_by_external_key( $connection_id, $external_key );

			$force_push = isset( $force_push_lookup[ $product_id ] );

			if ( ! $force_push && $map && (string) ( $map->wc_hash ?? '' ) === $new_hash
				&& ! $this->hasher->product_modified_since_last_push( $product, $map ) ) {
				++$skipped;
				$max_id  = max( $max_id, $product_id );
				$mod_gmt = sheetsync_get_product_modified_gmt( $product );
				if ( $mod_gmt ) {
					$max_modified = max( $max_modified, $mod_gmt );
				}
				continue;
			}

			$row_data  = $updater->product_to_row( $product );
			$row_hash  = $this->hasher->sheet_hash( $row_data, $hash_maps );
			$sheet_row = $this->map_repo->resolve_push_sheet_row( $connection_id, $product, $external_key );
			if ( $sheet_row <= 0 && $map && $map->sheet_row ) {
				$sheet_row = (int) $map->sheet_row;
			}

			$pending[] = array(
				'product_id'      => $product_id,
				'product'         => $product,
				'external_key'    => $external_key,
				'new_hash'        => $new_hash,
				'row_hash'        => $row_hash,
				'row_data'        => $row_data,
				'sheet_row'       => $sheet_row,
				'wc_modified_gmt' => sheetsync_get_product_modified_gmt( $product ),
			);

			if ( count( $pending ) >= $write_chunk ) {
				try {
					$flush_pending();
				} catch ( SheetSync_Rate_Limit_Exception $e ) {
					throw $e;
				}
			}
		}

		try {
			$flush_pending();
		} catch ( SheetSync_Rate_Limit_Exception $e ) {
			throw $e;
		}

		if ( ! empty( $missing_ids ) && function_exists( 'sheetsync_log_skipped_export_products' ) ) {
			sheetsync_log_skipped_export_products( $connection_id, $missing_ids );
		}

		if ( $wc_edited_pushed > 0 ) {
			$job_repo->increment_wc_edited_pushed( (int) $job->id, $wc_edited_pushed );
		}

		$job_repo->update_cursor(
			$job->id,
			(int) $job->cursor_offset,
			array_merge( $meta, array( 'push_last_id' => $max_id ) )
		);
		if ( $processed > 0 ) {
			$state_repo->set_watermark( $connection_id, $max_modified );
		}

		$fresh           = $job_repo->get( (int) $job->id );
		$queue_remaining = $fresh && $job_repo->has_wc_edited_push_queue( $fresh );
		$done            = ! $timed_out && count( $ids ) < $batch_size && ! $queue_remaining;

		$this->map_repo->clear_request_cache( $connection_id );

		return array(
			'done'      => $done,
			'processed' => $processed,
			'skipped'   => $skipped,
			'errors'    => $errors,
		);
	}

	/**
	 * Batch-load WooCommerce products (avoids N× wc_get_product per push batch).
	 *
	 * @param int[] $product_ids
	 * @return array<int, WC_Product>
	 */
	private function load_products_batch( array $product_ids ): array {
		if ( function_exists( 'sheetsync_load_products_batch' ) ) {
			return sheetsync_load_products_batch( $product_ids );
		}

		$ids = array_values(
			array_unique(
				array_filter( array_map( 'absint', $product_ids ) )
			)
		);
		if ( empty( $ids ) ) {
			return array();
		}

		$by_id = array();
		if ( function_exists( 'wc_get_products' ) ) {
			$products = wc_get_products(
				array(
					'include' => $ids,
					'limit'   => count( $ids ),
					'status'  => array( 'publish', 'draft', 'private', 'pending', 'future' ),
					'return'  => 'objects',
				)
			);
			if ( is_array( $products ) ) {
				foreach ( $products as $product ) {
					if ( $product instanceof WC_Product ) {
						$by_id[ (int) $product->get_id() ] = $product;
					}
				}
			}
		}

		return $by_id;
	}

	/**
	 * @return int[]
	 */
	private function get_modified_product_ids( string $watermark, int $last_id, int $limit, int $connection_id ): array {
		global $wpdb;

		$statuses = class_exists( 'SheetSync_Export_Order', false )
			? SheetSync_Export_Order::export_post_statuses()
			: array( 'publish', 'draft', 'private', 'pending', 'future' );
		$status_ph = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$join   = '';
		$where  = "p.post_type IN ('product','product_variation')
			AND p.post_status IN ({$status_ph})
			AND ( p.post_modified_gmt > %s OR ( p.post_modified_gmt = %s AND p.ID > %d ) )";
		$params = array_merge( $statuses, array( $watermark, $watermark, $last_id ) );

		if ( function_exists( 'sheetsync_product_category_filter_join_sql' ) ) {
			$join = sheetsync_product_category_filter_join_sql( $connection_id, $params );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$sql = sheetsync_wpdb_prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p {$join}
			WHERE {$where}
			ORDER BY p.post_modified_gmt ASC, p.ID ASC
			LIMIT %d",
			array_merge( $params, array( $limit ) )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $wpdb->get_col( $sql ) );
	}

	/**
	 * Bootstrap uses existing bulk export in chunks via cursor page.
	 *
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
	 * @return array{done: bool, processed: int, skipped: int, errors: int}
	 */
	private function process_bootstrap_batch( object $job, object $conn, array $maps, int $batch_size, int $deadline ): array {
		$job_repo = new SheetSync_Job_Repository();
		$meta     = $job_repo->get_cursor_meta( $job );
		$export_cursor = array(
			'parent_after_menu_order' => (int) ( $meta['export_after_menu_order'] ?? $meta['export_parent_after_menu_order'] ?? -1 ),
			'parent_after_title'      => (string) ( $meta['export_after_title'] ?? $meta['export_parent_after_title'] ?? '' ),
			'parent_after_id'         => (int) ( $meta['export_after_id'] ?? $meta['export_parent_after_id'] ?? 0 ),
			'pending_parent_id'       => (int) ( $meta['export_pending_parent_id'] ?? 0 ),
			'variation_after_id'      => (int) ( $meta['export_variation_after_id'] ?? 0 ),
			'parent_queue'            => isset( $meta['export_parent_queue'] ) && is_array( $meta['export_parent_queue'] )
				? $meta['export_parent_queue']
				: array(),
		);
		if ( class_exists( 'SheetSync_Export_Order', false ) ) {
			$export_cursor = SheetSync_Export_Order::normalize_cursor( $export_cursor );
		}

		$cursor_started = (int) ( $export_cursor['parent_after_menu_order'] ?? -1 ) >= 0
			|| (int) ( $export_cursor['pending_parent_id'] ?? 0 ) > 0;
		$needs_sheet_reset = (int) $job->processed_count === 0
			&& ! $cursor_started
			&& empty( $meta['catalog_sheet_reset'] );

		if ( $needs_sheet_reset && class_exists( 'SheetSync_Bulk_Processor', false ) ) {
			try {
				SheetSync_Bulk_Processor::begin_catalog_export( (int) $job->connection_id, $maps );
				$meta['catalog_sheet_reset'] = 1;
				$job_repo->update_cursor( $job->id, (int) $job->cursor_offset, $meta );
			} catch ( Throwable $e ) {
				$friendly = function_exists( 'sheetsync_format_sheet_error_for_connection' )
					? sheetsync_format_sheet_error_for_connection( $e, (int) $job->connection_id )
					: $e->getMessage();
				$fatal    = function_exists( 'sheetsync_is_sheet_tab_error' )
					&& sheetsync_is_sheet_tab_error( $e->getMessage() );
				SheetSync_Logger::log( (int) $job->connection_id, 'export', 'error', 0, 0, $friendly, 1 );
				return array(
					'done'          => false,
					'processed'     => 0,
					'skipped'       => 0,
					'errors'        => 1,
					'fatal'         => $fatal,
					'fatal_message' => $fatal ? $friendly : '',
				);
			}
		}

		if ( $deadline > 0 && time() >= $deadline - 1 ) {
			if ( class_exists( 'SheetSync_Sync_Tick_Perf_Log', false ) ) {
				SheetSync_Sync_Tick_Perf_Log::note_deadline_exit();
			}
			return array(
				'done'      => false,
				'processed' => 0,
				'skipped'   => 0,
				'errors'    => 0,
			);
		}

		$effective_batch = $batch_size;
		if ( $deadline > 0 ) {
			$seconds_left    = max( 2, $deadline - time() - 2 );
			$effective_batch = min( $batch_size, max( 3, (int) floor( $seconds_left / 2 ) ) );
		}

		// Resume from last mapped row (reliable after quota pause) instead of processed_count alone.
		$map_repo          = new SheetSync_Product_Map_Repository();
		$header_data_row   = (int) $conn->header_row + 1;
		if ( ! empty( $meta['catalog_sheet_reset'] ) && ! $cursor_started ) {
			$start_row = $header_data_row;
			unset( $meta['export_next_start_row'] );
		} elseif ( ! empty( $meta['export_next_start_row'] ) && (int) $meta['export_next_start_row'] >= $header_data_row ) {
			$start_row = (int) $meta['export_next_start_row'];
		} else {
			$max_row   = $map_repo->get_max_sheet_row( (int) $conn->id );
			$start_row = $max_row >= $header_data_row ? $max_row + 1 : $header_data_row;
		}

		$result = SheetSync_Bulk_Processor::export_connection_batch_to_sheet(
			(int) $job->connection_id,
			$export_cursor,
			$effective_batch,
			$start_row,
			$deadline
		);

		if ( ! empty( $result['success'] ) || (int) ( $result['processed'] ?? 0 ) > 0 ) {
			$cursor = $result['export_cursor'] ?? $export_cursor;
			$meta['export_after_menu_order']   = (int) ( $cursor['parent_after_menu_order'] ?? -1 );
			$meta['export_after_title']        = (string) ( $cursor['parent_after_title'] ?? '' );
			$meta['export_after_id']           = (int) ( $cursor['parent_after_id'] ?? 0 );
			$meta['export_pending_parent_id']  = (int) ( $cursor['pending_parent_id'] ?? 0 );
			$meta['export_variation_after_id'] = (int) ( $cursor['variation_after_id'] ?? 0 );
			$meta['export_parent_queue']       = isset( $cursor['parent_queue'] ) && is_array( $cursor['parent_queue'] )
				? $cursor['parent_queue']
				: array();
			if ( (int) ( $result['processed'] ?? 0 ) > 0 ) {
				$meta['export_next_start_row'] = $start_row + (int) $result['processed'];
			}
		}
		$batch_num = (int) $job->cursor_offset + ( ! empty( $result['success'] ) ? 1 : 0 );
		$job_repo->update_cursor( $job->id, $batch_num, $meta );

		$has_more = ! empty( $result['has_more'] );
		$done     = ! $has_more && ! empty( $result['success'] );
		if ( (int) ( $result['errors'] ?? 0 ) > 0 ) {
			$done = false;
		}

		if ( $done && class_exists( 'SheetSync_Bulk_Processor', false ) ) {
			try {
				SheetSync_Bulk_Processor::finalize_catalog_export( (int) $job->connection_id, $maps );
				$meta['catalog_finalized'] = 1;
				$meta['export_complete']   = 1;
				$job_repo->update_cursor( $job->id, $batch_num, $meta );
			} catch ( Throwable $e ) {
				$done = false;
				$friendly = function_exists( 'sheetsync_format_sheet_error_for_connection' )
					? sheetsync_format_sheet_error_for_connection( $e, (int) $job->connection_id )
					: $e->getMessage();
				SheetSync_Logger::log( (int) $job->connection_id, 'export', 'error', 0, 0, $friendly, 1 );
				return array(
					'done'      => false,
					'processed' => (int) ( $result['processed'] ?? 0 ),
					'skipped'   => 0,
					'errors'    => 1,
				);
			}
		}

		if ( (int) ( $result['processed'] ?? 0 ) > 0 ) {
			SheetSync_Logger::info(
				sprintf(
					/* translators: 1: rows written this batch */
					__( 'Export batch: %1$d rows written to sheet.', 'sheetsync-for-woocommerce' ),
					(int) $result['processed']
				),
				(int) $job->connection_id,
				(int) $result['processed'],
				(int) $job->id
			);
			SheetSync_Sync_Engine::touch_connection_sync_time( (int) $job->connection_id );
		}

		return array(
			'done'      => $done,
			'processed' => (int) ( $result['processed'] ?? 0 ),
			'skipped'   => 0,
			'errors'    => (int) ( $result['errors'] ?? 0 ),
		);
	}

	private function estimate_appended_row( object $conn ): int {
		$map_repo = new SheetSync_Product_Map_Repository();
		global $wpdb;
		$table = SheetSync_Schema::table( 'product_map' );
		$max   = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT MAX(sheet_row) FROM {$table} WHERE connection_id = %d",
				(int) $conn->id
			)
		);
		return max( (int) $conn->header_row + 1, $max + 1 );
	}

	/**
	 * Write queued incremental push rows (batched API calls).
	 *
	 * @param array<int, array<string, mixed>> $pending
	 * @return array{processed: int, errors: int, flushed_ids: int[], failed_ids: int[]}
	 */
	private function flush_incremental_push_pending(
		SheetSync_Sheets_Client $client,
		object $conn,
		int $connection_id,
		string $last_col,
		int $min_cols,
		array $pending
	): array {
		if ( empty( $pending ) ) {
			return array(
				'processed'   => 0,
				'errors'      => 0,
				'flushed_ids' => array(),
				'failed_ids'  => array(),
			);
		}

		$updates = array();
		$appends = array();
		foreach ( $pending as $item ) {
			if ( (int) ( $item['sheet_row'] ?? 0 ) > 0 ) {
				$updates[] = $item;
			} else {
				$appends[] = $item;
			}
		}

		$processed   = 0;
		$errors      = 0;
		$flushed_ids = array();
		$failed_ids  = array();

		if ( ! empty( $updates ) ) {
			$result      = $this->write_incremental_push_updates( $client, $conn, $connection_id, $last_col, $min_cols, $updates );
			$processed  += (int) ( $result['processed'] ?? 0 );
			$errors     += (int) ( $result['errors'] ?? 0 );
			$flushed_ids = array_merge( $flushed_ids, (array) ( $result['flushed_ids'] ?? array() ) );
			$failed_ids  = array_merge( $failed_ids, (array) ( $result['failed_ids'] ?? array() ) );
		}

		if ( ! empty( $appends ) ) {
			$result      = $this->write_incremental_push_appends( $client, $conn, $connection_id, $last_col, $min_cols, $appends );
			$processed  += (int) ( $result['processed'] ?? 0 );
			$errors     += (int) ( $result['errors'] ?? 0 );
			$flushed_ids = array_merge( $flushed_ids, (array) ( $result['flushed_ids'] ?? array() ) );
			$failed_ids  = array_merge( $failed_ids, (array) ( $result['failed_ids'] ?? array() ) );
		}

		return array(
			'processed'   => $processed,
			'errors'      => $errors,
			'flushed_ids' => $flushed_ids,
			'failed_ids'  => $failed_ids,
		);
	}

	/**
	 * Overwrite mapped rows — one API call per chunk (batchUpdate or contiguous set_rows).
	 *
	 * @param array<int, array<string, mixed>> $updates
	 * @return array{processed: int, errors: int, flushed_ids: int[], failed_ids: int[]}
	 */
	private function write_incremental_push_updates(
		SheetSync_Sheets_Client $client,
		object $conn,
		int $connection_id,
		string $last_col,
		int $min_cols,
		array $updates
	): array {
		usort(
			$updates,
			static fn( array $a, array $b ): int => (int) $a['sheet_row'] <=> (int) $b['sheet_row']
		);

		$max_row = (int) $updates[ array_key_last( $updates ) ]['sheet_row'];
		try {
			$client->ensure_sheet_grid_capacity(
				$conn->spreadsheet_id,
				$conn->sheet_name,
				$max_row + 5,
				$min_cols
			);
		} catch ( Exception $e ) {
			if ( function_exists( 'sheetsync_api_error_is_quota' ) && sheetsync_api_error_is_quota( $e->getMessage() ) ) {
				throw new SheetSync_Rate_Limit_Exception( $e->getMessage() );
			}
		}

		$chunk_size = (int) apply_filters( 'sheetsync_incremental_push_write_chunk_size', 50 );
		$chunk_size = max( 1, min( 100, $chunk_size ) );

		$processed   = 0;
		$errors      = 0;
		$flushed_ids = array();
		$failed_ids  = array();

		foreach ( array_chunk( $updates, $chunk_size ) as $chunk ) {
			$result      = $this->write_incremental_push_update_chunk( $client, $conn, $connection_id, $last_col, $chunk );
			$processed  += (int) ( $result['processed'] ?? 0 );
			$errors     += (int) ( $result['errors'] ?? 0 );
			$flushed_ids = array_merge( $flushed_ids, (array) ( $result['flushed_ids'] ?? array() ) );
			$failed_ids  = array_merge( $failed_ids, (array) ( $result['failed_ids'] ?? array() ) );
		}

		return array(
			'processed'   => $processed,
			'errors'      => $errors,
			'flushed_ids' => $flushed_ids,
			'failed_ids'  => $failed_ids,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $chunk
	 * @return array{processed: int, errors: int, flushed_ids: int[], failed_ids: int[]}
	 */
	private function write_incremental_push_update_chunk(
		SheetSync_Sheets_Client $client,
		object $conn,
		int $connection_id,
		string $last_col,
		array $chunk
	): array {
		$count = count( $chunk );
		if ( $count === 0 ) {
			return array(
				'processed'   => 0,
				'errors'      => 0,
				'flushed_ids' => array(),
				'failed_ids'  => array(),
			);
		}

		try {
			$this->limiter->acquire_write();

			if ( $count > 1 && $this->push_group_is_contiguous( $chunk ) ) {
				$start_row = (int) $chunk[0]['sheet_row'];
				$end_row   = (int) $chunk[ $count - 1 ]['sheet_row'];
				$values    = array_map(
					static fn( array $item ): array => $item['row_data'],
					$chunk
				);
				$range = "{$conn->sheet_name}!A{$start_row}:{$last_col}{$end_row}";
				$client->set_rows( $conn->spreadsheet_id, $range, $values );
			} else {
				$batch_data = array();
				foreach ( $chunk as $item ) {
					$row          = (int) $item['sheet_row'];
					$batch_data[] = array(
						'range'  => "{$conn->sheet_name}!A{$row}:{$last_col}{$row}",
						'values' => array( $item['row_data'] ),
					);
				}
				$client->values_batch_update( $conn->spreadsheet_id, $batch_data );
			}

			foreach ( $chunk as $item ) {
				$this->upsert_incremental_push_map( $connection_id, $item, (int) $item['sheet_row'] );
			}

			return array(
				'processed'   => $count,
				'errors'      => 0,
				'flushed_ids' => array_map(
					static fn( array $item ): int => (int) $item['product_id'],
					$chunk
				),
				'failed_ids'  => array(),
			);
		} catch ( SheetSync_Rate_Limit_Exception $e ) {
			throw $e;
		} catch ( Exception $e ) {
			if ( function_exists( 'sheetsync_api_error_is_quota' ) && sheetsync_api_error_is_quota( $e->getMessage() ) ) {
				throw new SheetSync_Rate_Limit_Exception( $e->getMessage() );
			}

			if ( $count > 1 ) {
				$mid   = (int) ceil( $count / 2 );
				$left  = $this->write_incremental_push_update_chunk( $client, $conn, $connection_id, $last_col, array_slice( $chunk, 0, $mid ) );
				$right = $this->write_incremental_push_update_chunk( $client, $conn, $connection_id, $last_col, array_slice( $chunk, $mid ) );
				return array(
					'processed'   => (int) $left['processed'] + (int) $right['processed'],
					'errors'      => (int) $left['errors'] + (int) $right['errors'],
					'flushed_ids' => array_merge( (array) $left['flushed_ids'], (array) $right['flushed_ids'] ),
					'failed_ids'  => array_merge( (array) $left['failed_ids'], (array) $right['failed_ids'] ),
				);
			}

			$this->log_incremental_push_failure( $connection_id, $chunk[0], $e->getMessage() );
			return array(
				'processed'   => 0,
				'errors'      => 1,
				'flushed_ids' => array(),
				'failed_ids'  => array( (int) $chunk[0]['product_id'] ),
			);
		}
	}

	/**
	 * Append new products with no mapped sheet row (one API call per batch).
	 *
	 * @param array<int, array<string, mixed>> $appends
	 * @return array{processed: int, errors: int, flushed_ids: int[], failed_ids: int[]}
	 */
	private function write_incremental_push_appends(
		SheetSync_Sheets_Client $client,
		object $conn,
		int $connection_id,
		string $last_col,
		int $min_cols,
		array $appends
	): array {
		$count = count( $appends );
		if ( $count === 0 ) {
			return array(
				'processed'   => 0,
				'errors'      => 0,
				'flushed_ids' => array(),
				'failed_ids'  => array(),
			);
		}

		$values    = array_map(
			static fn( array $item ): array => $item['row_data'],
			$appends
		);
		$start_row = $this->estimate_appended_row( $conn );

		try {
			$client->ensure_sheet_grid_capacity(
				$conn->spreadsheet_id,
				$conn->sheet_name,
				$start_row + $count + 5,
				$min_cols
			);
			$this->limiter->acquire_write();
			$append_start = $client->append_rows( $conn->spreadsheet_id, "{$conn->sheet_name}!A:{$last_col}", $values );
			$row_num      = $append_start > 0 ? $append_start : $start_row;
			$flushed_ids  = array();
			foreach ( $appends as $item ) {
				$this->upsert_incremental_push_map( $connection_id, $item, $row_num );
				$flushed_ids[] = (int) $item['product_id'];
				++$row_num;
			}

			return array(
				'processed'   => $count,
				'errors'      => 0,
				'flushed_ids' => $flushed_ids,
				'failed_ids'  => array(),
			);
		} catch ( SheetSync_Rate_Limit_Exception $e ) {
			throw $e;
		} catch ( Exception $e ) {
			if ( function_exists( 'sheetsync_api_error_is_quota' ) && sheetsync_api_error_is_quota( $e->getMessage() ) ) {
				throw new SheetSync_Rate_Limit_Exception( $e->getMessage() );
			}

			if ( $count > 1 ) {
				$mid   = (int) ceil( $count / 2 );
				$left  = $this->write_incremental_push_appends( $client, $conn, $connection_id, $last_col, $min_cols, array_slice( $appends, 0, $mid ) );
				$right = $this->write_incremental_push_appends( $client, $conn, $connection_id, $last_col, $min_cols, array_slice( $appends, $mid ) );
				return array(
					'processed'   => (int) $left['processed'] + (int) $right['processed'],
					'errors'      => (int) $left['errors'] + (int) $right['errors'],
					'flushed_ids' => array_merge( (array) $left['flushed_ids'], (array) $right['flushed_ids'] ),
					'failed_ids'  => array_merge( (array) $left['failed_ids'], (array) $right['failed_ids'] ),
				);
			}

			$this->log_incremental_push_failure( $connection_id, $appends[0], $e->getMessage() );
			return array(
				'processed'   => 0,
				'errors'      => 1,
				'flushed_ids' => array(),
				'failed_ids'  => array( (int) $appends[0]['product_id'] ),
			);
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $group
	 */
	private function push_group_is_contiguous( array $group ): bool {
		if ( count( $group ) < 2 ) {
			return true;
		}
		for ( $i = 1, $len = count( $group ); $i < $len; ++$i ) {
			if ( (int) $group[ $i ]['sheet_row'] !== (int) $group[ $i - 1 ]['sheet_row'] + 1 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function upsert_incremental_push_map( int $connection_id, array $item, int $sheet_row ): void {
		/** @var WC_Product $product */
		$product = $item['product'];
		$this->map_repo->upsert(
			$connection_id,
			array(
				'product_id'      => (int) $item['product_id'],
				'external_key'    => (string) $item['external_key'],
				'sheet_row'       => $sheet_row,
				'wc_hash'         => (string) $item['new_hash'],
				'sheet_hash'      => (string) $item['row_hash'],
				'wc_modified_gmt' => (string) ( $item['wc_modified_gmt'] ?? sheetsync_get_product_modified_gmt( $product ) ),
				'last_pushed_at'  => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function log_incremental_push_failure( int $connection_id, array $item, string $message ): void {
		/** @var WC_Product $product */
		$product    = $item['product'];
		$product_id = (int) $item['product_id'];
		$sku        = $product->get_sku();
		SheetSync_Logger::log(
			$connection_id,
			'export',
			'error',
			0,
			0,
			sprintf(
				'Push to sheet failed for %s: %s',
				$sku !== '' ? 'SKU ' . $sku : 'product #' . $product_id,
				$message
			),
			1
		);
	}

	public function push_single_product( WC_Product $product, object $conn ): bool {
		$connection_id = (int) $conn->id;

		if ( ! function_exists( 'sheetsync_connection_allows_push' ) || ! sheetsync_connection_allows_push( $conn ) ) {
			return false;
		}

		if ( function_exists( 'sheetsync_is_sync_locked' ) && sheetsync_is_sync_locked( $connection_id ) ) {
			if ( function_exists( 'sheetsync_mark_product_dirty' ) ) {
				sheetsync_mark_product_dirty( $connection_id, $product->get_id() );
			}
			return false;
		}

		$state_repo = class_exists( 'SheetSync_Sync_State_Repository', false )
			? new SheetSync_Sync_State_Repository()
			: null;
		if ( $state_repo && ! $state_repo->try_realtime_slot( $connection_id, 60 ) ) {
			if ( function_exists( 'sheetsync_mark_product_dirty' ) ) {
				sheetsync_mark_product_dirty( $connection_id, $product->get_id() );
			}
			return false;
		}

		try {
			return $this->push_single_product_unlocked( $product, $conn );
		} finally {
			if ( $state_repo ) {
				$state_repo->release_realtime_slot( $connection_id );
			}
		}
	}

	/**
	 * Push single product when the caller already holds sync/realtime locks.
	 */
	public function push_single_product_unlocked( WC_Product $product, object $conn ): bool {
		$connection_id = (int) $conn->id;
		$maps          = class_exists( 'SheetSync_Bulk_Processor', false )
			? SheetSync_Bulk_Processor::maps_for_sheet_export( $connection_id )
			: SheetSync_Field_Mapper::get_maps_for_export( $connection_id );
		if ( empty( $maps ) ) {
			SheetSync_Logger::log(
				$connection_id,
				'export',
				'error',
				0,
				0,
				sprintf(
					'Auto push skipped for product #%d: no field mappings configured.',
					$product->get_id()
				),
				1
			);
			return false;
		}
		$hash_maps = $this->hasher->maps_for_hash( $maps );

		$external_key  = SheetSync_Product_Map_Repository::external_key_for_product( $product );
		$map           = $this->map_repo->find_by_product_id( $connection_id, $product->get_id() )
			?: $this->map_repo->find_by_external_key( $connection_id, $external_key );

		$client   = new SheetSync_Sheets_Client();
		$updater  = new SheetSync_Product_Updater( $maps );
		$last_col = SheetSync_Field_Mapper::max_column_letter( $maps );
		$row_data = $updater->product_to_row( $product );
		$new_hash = $this->hasher->wc_hash( $product, $maps );

		try {
			$this->limiter->acquire_write();
			$row = $this->map_repo->resolve_push_sheet_row( $connection_id, $product, $external_key );
			if ( $row <= 0 && $map && $map->sheet_row ) {
				$row = (int) $map->sheet_row;
			}
			if ( $row > 0 ) {
				$client->set_rows(
					$conn->spreadsheet_id,
					"{$conn->sheet_name}!A{$row}:{$last_col}{$row}",
					array( $row_data )
				);
			} else {
				$append_start = $client->append_rows( $conn->spreadsheet_id, "{$conn->sheet_name}!A:{$last_col}", array( $row_data ) );
				$row          = $append_start > 0 ? $append_start : $this->estimate_appended_row( $conn );
			}

			$this->map_repo->upsert(
				$connection_id,
				array(
					'product_id'      => $product->get_id(),
					'external_key'    => $external_key,
					'sheet_row'       => $row,
					'wc_hash'         => $new_hash,
					'sheet_hash'      => $this->hasher->sheet_hash( $row_data, $hash_maps ),
					'wc_modified_gmt' => sheetsync_get_product_modified_gmt( $product ),
					'last_pushed_at'  => current_time( 'mysql', true ),
				)
			);
			return true;
		} catch ( Exception $e ) {
			if ( function_exists( 'sheetsync_api_error_is_quota' ) && sheetsync_api_error_is_quota( $e->getMessage() ) ) {
				throw new SheetSync_Rate_Limit_Exception( $e->getMessage() );
			}
			$sku = $product->get_sku();
			SheetSync_Logger::log(
				$connection_id,
				'export',
				'error',
				0,
				0,
				sprintf(
					'Push to sheet failed for %s: %s',
					$sku !== '' ? 'SKU ' . $sku : 'product #' . $product->get_id(),
					$e->getMessage()
				),
				1
			);
			return false;
		}
	}
}

endif;
