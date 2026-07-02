<?php
/**
 * Pull changed sheet rows into WooCommerce (windowed reads + product_map hashes).
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Pull_From_Sheet_Service' ) ) :

class SheetSync_Pull_From_Sheet_Service {

	private SheetSync_Product_Map_Repository $map_repo;
	private SheetSync_Hash_Normalizer $hasher;
	private SheetSync_Rate_Limiter $limiter;

	public function __construct() {
		$this->map_repo = new SheetSync_Product_Map_Repository();
		$this->hasher   = new SheetSync_Hash_Normalizer();
		$this->limiter  = new SheetSync_Rate_Limiter();
	}

	/**
	 * Process one batch of sheet rows.
	 *
	 * @return array{done: bool, processed: int, skipped: int, errors: int, advance?: int}
	 */
	public function process_batch( object $job, object $conn, int $batch_size, int $deadline ): array {
		$connection_id = (int) $job->connection_id;
		$maps          = SheetSync_Field_Mapper::get_maps_for_sync( $connection_id, $conn );
		if ( empty( $maps ) ) {
			return array( 'done' => true, 'processed' => 0, 'skipped' => 0, 'errors' => 0 );
		}

		$this->map_repo->migrate_legacy_hashes( $connection_id );

		if ( 0 === (int) $job->cursor_offset ) {
			SheetSync_Import_Row_Service::begin_import_run( $connection_id );
		} else {
			SheetSync_Import_Row_Service::continue_import_run( $connection_id );
		}

		$header_row     = max( 1, (int) $conn->header_row );
		$first_data_row = $header_row + 1;
		$start_row      = $first_data_row + (int) $job->cursor_offset;
		$end_row        = $start_row + $batch_size - 1;
		$last_col       = SheetSync_Field_Mapper::max_column_letter( $maps );

		$client   = new SheetSync_Sheets_Client();
		$range    = "{$conn->sheet_name}!A{$start_row}:{$last_col}{$end_row}";
		$job_repo = new SheetSync_Job_Repository();
		$meta     = $job_repo->get_cursor_meta( $job );
		$offset   = (int) $job->cursor_offset;

		$rows       = null;
		$read_error = null;
		$read_attempts = max( 1, min( 5, (int) apply_filters( 'sheetsync_sheet_read_attempts', 3 ) ) );
		for ( $read_try = 0; $read_try < $read_attempts; ++$read_try ) {
			try {
				$this->limiter->acquire_read();
				$rows       = $client->get_rows( $conn->spreadsheet_id, $range );
				$read_error = null;
				break;
			} catch ( SheetSync_Rate_Limit_Exception $e ) {
				throw $e;
			} catch ( Exception $e ) {
				$read_error = $e;
				if ( $read_try < $read_attempts - 1 ) {
					$allow_sleep = ( defined( 'SHEETSYNC_BG_SYNC' ) && SHEETSYNC_BG_SYNC )
						|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() );
					if ( $allow_sleep ) {
						sleep( min( 8, 2 * ( $read_try + 1 ) ) );
					}
				}
			}
		}

		if ( null === $rows ) {
			$detail     = $read_error instanceof Exception ? $read_error->getMessage() : __( 'Could not read Google Sheet.', 'sheetsync-for-woocommerce' );
			$fail_key   = 'pull_read_fail_at_' . $offset;
			$failures   = (int) ( $meta[ $fail_key ] ?? 0 ) + 1;
			$max_fail   = max( 1, (int) apply_filters( 'sheetsync_pull_read_fail_max', 3 ) );
			$meta[ $fail_key ] = $failures;
			$job_repo->update_cursor( (int) $job->id, $offset, $meta );

			SheetSync_Logger::log(
				$connection_id,
				'job',
				'error',
				0,
				0,
				sprintf(
					'Sheet pull failed (rows %1$d–%2$d, attempt %3$d/%4$d): %5$s',
					$start_row,
					$end_row,
					$failures,
					$max_fail,
					$detail
				),
				1
			);

			if ( $failures >= $max_fail ) {
				throw new RuntimeException(
					sprintf(
						/* translators: 1: failure count, 2: row offset, 3: first sheet row in window, 4: error detail */
						__( 'Sheet pull failed %1$d times at offset %2$d (sheet row ~%3$d): %4$s', 'sheetsync-for-woocommerce' ),
						$failures,
						$offset,
						$start_row,
						$detail
					)
				);
			}

			return array(
				'done'      => false,
				'processed' => 0,
				'skipped'   => 0,
				'errors'    => 1,
				'advance'   => 0,
			);
		}

		$fail_key = 'pull_read_fail_at_' . $offset;
		if ( isset( $meta[ $fail_key ] ) ) {
			unset( $meta[ $fail_key ] );
			$job_repo->update_cursor( (int) $job->id, $offset, $meta );
		}

		if ( empty( $rows ) ) {
			return array( 'done' => true, 'processed' => 0, 'skipped' => 0, 'errors' => 0 );
		}

		$fetched = count( $rows );
		$updater = new SheetSync_Product_Updater( $maps );
		if ( class_exists( 'SheetSync_Variation_Sync', false ) || class_exists( 'SheetSync_Import_Row_Service', false ) ) {
			$tagged = array();
			foreach ( $rows as $i => $row ) {
				$tagged[] = array(
					'sheet_row' => $start_row + $i,
					'row'       => $row,
				);
			}
			if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
				$tagged = SheetSync_Import_Row_Service::prepare_tagged_rows_for_import( $tagged, $updater, $start_row );
				SheetSync_Import_Row_Service::warm_sku_lookup_for_tagged_rows( $updater, $tagged );
			} elseif ( class_exists( 'SheetSync_Variation_Sync', false ) ) {
				$tagged = SheetSync_Variation_Sync::sort_rows_parents_first( $rows, $updater, $start_row );
			}
		} else {
			$tagged = array();
			foreach ( $rows as $i => $row ) {
				$tagged[] = array(
					'sheet_row' => $start_row + $i,
					'row'       => $row,
				);
			}
		}

		SheetSync_Import_Row_Service::begin_batch();
		$processed     = $skipped = $errors = 0;
		$had_data      = false;
		$timed_out            = false;
		$completed_sheet_rows = array();
		$mark_row_done        = static function ( int $sheet_row_num ) use ( &$completed_sheet_rows ): void {
			if ( $sheet_row_num > 0 ) {
				$completed_sheet_rows[ $sheet_row_num ] = true;
			}
		};

		// Prefetch product_map hashes for the whole window (one query vs N per row).
		$prefetch_keys = array();
		foreach ( $tagged as $item ) {
			$row           = $item['row'];
			$sheet_row_num = (int) $item['sheet_row'];
			if ( empty( array_filter( $row, static fn( $v ) => $v !== '' && $v !== null ) ) ) {
				continue;
			}
			$peek_data = $updater->extract_data( $row );
			if ( function_exists( 'sheetsync_importable_product_row_skip_reason' )
				&& is_string( sheetsync_importable_product_row_skip_reason( $peek_data ) ) ) {
				continue;
			}
			$key = $this->hasher->external_key_from_row( $row, $maps, $updater, $sheet_row_num );
			if ( $key !== '' ) {
				$prefetch_keys[] = $key;
			}
		}
		if ( ! empty( $prefetch_keys ) ) {
			$this->map_repo->prefetch_by_external_keys( $connection_id, $prefetch_keys );
		}

		foreach ( $tagged as $item ) {
			if ( time() >= $deadline ) {
				$timed_out = true;
				break;
			}

			$row           = $item['row'];
			$sheet_row_num = (int) $item['sheet_row'];

			if ( empty( array_filter( $row, static fn( $v ) => $v !== '' && $v !== null ) ) ) {
				$mark_row_done( $sheet_row_num );
				continue;
			}

			$had_data     = true;
			$peek_data    = $updater->extract_data( $row );
			$external_key = $this->hasher->external_key_from_row( $row, $maps, $updater, $sheet_row_num );
			$row_hash     = $this->hasher->sheet_hash( $row, $maps, $peek_data );

			$import_skip = function_exists( 'sheetsync_importable_product_row_skip_reason' )
				? sheetsync_importable_product_row_skip_reason( $peek_data )
				: null;
			if ( is_string( $import_skip ) && $import_skip !== '' ) {
				SheetSync_Import_Row_Service::log_row_result(
					$connection_id,
					$sheet_row_num,
					$peek_data,
					'error',
					$import_skip
				);
				++$errors;
				$mark_row_done( $sheet_row_num );
				continue;
			}

			if ( SheetSync_Import_Row_Service::is_later_duplicate_sheet_sku( $connection_id, $sheet_row_num, $peek_data ) ) {
				SheetSync_Import_Row_Service::log_row_result(
					$connection_id,
					$sheet_row_num,
					$peek_data,
					'skipped',
					__( 'Duplicate SKU in sheet — first row wins.', 'sheetsync-for-woocommerce' )
				);
				++$skipped;
				$mark_row_done( $sheet_row_num );
				continue;
			}

			if ( SheetSync_Import_Row_Service::is_later_duplicate_sheet_title( $connection_id, $sheet_row_num, $peek_data ) ) {
				SheetSync_Import_Row_Service::log_row_result(
					$connection_id,
					$sheet_row_num,
					$peek_data,
					'skipped',
					__( 'Duplicate title in sheet — first row wins.', 'sheetsync-for-woocommerce' )
				);
				++$skipped;
				$mark_row_done( $sheet_row_num );
				continue;
			}

			if ( SheetSync_Import_Row_Service::is_later_duplicate_variation_row( $connection_id, $sheet_row_num, $peek_data ) ) {
				SheetSync_Import_Row_Service::log_row_result(
					$connection_id,
					$sheet_row_num,
					$peek_data,
					'skipped',
					__( 'Duplicate variation in sheet — first row wins.', 'sheetsync-for-woocommerce' )
				);
				++$skipped;
				$mark_row_done( $sheet_row_num );
				continue;
			}

			$ambiguous_sku_msg = SheetSync_Import_Row_Service::ambiguous_sku_import_message( $peek_data, $connection_id );
			if ( is_string( $ambiguous_sku_msg ) && $ambiguous_sku_msg !== '' ) {
				SheetSync_Import_Row_Service::log_row_result(
					$connection_id,
					$sheet_row_num,
					$peek_data,
					'error',
					$ambiguous_sku_msg
				);
				++$errors;
				$mark_row_done( $sheet_row_num );
				continue;
			}

			if ( SheetSync_Import_Row_Service::is_ambiguous_title_match( $peek_data, $connection_id ) ) {
				SheetSync_Import_Row_Service::log_row_result(
					$connection_id,
					$sheet_row_num,
					$peek_data,
					'error',
					__( 'Multiple WooCommerce products share this title — map Product ID or use a unique SKU.', 'sheetsync-for-woocommerce' )
				);
				++$errors;
				$mark_row_done( $sheet_row_num );
				continue;
			}

			$fast_skip = SheetSync_Import_Row_Service::try_fast_incremental_row_skip(
				(string) $job->mode,
				$conn,
				$external_key,
				$row_hash,
				$peek_data,
				$this->map_repo,
				$maps,
				$connection_id
			);
			if ( 'unchanged' === $fast_skip ) {
				SheetSync_Import_Row_Service::refresh_map_on_incremental_skip(
					$connection_id,
					$this->map_repo,
					$external_key,
					$row_hash,
					$sheet_row_num,
					$peek_data,
					true
				);
				++$skipped;
				$mark_row_done( $sheet_row_num );
				continue;
			}

			$wc_product = SheetSync_Import_Row_Service::resolve_wc_product(
				$connection_id,
				$external_key,
				$sheet_row_num,
				$row,
				$updater,
				$this->map_repo
			);

			$skip_reason = SheetSync_Import_Row_Service::evaluate_incremental_skip(
				(string) $job->mode,
				$conn,
				$external_key,
				$row_hash,
				$peek_data,
				$row,
				$wc_product,
				$updater,
				$this->map_repo,
				$maps,
				$connection_id
			);

			if ( in_array( $skip_reason, array( 'matched', 'unchanged' ), true ) ) {
				if ( $external_key !== '' && $wc_product ) {
					SheetSync_Import_Row_Service::store_row_hash(
						$connection_id,
						$this->map_repo,
						$external_key,
						$row_hash,
						$sheet_row_num,
						$peek_data,
						false,
						$wc_product
					);
				}
				++$skipped;
				$mark_row_done( $sheet_row_num );
				continue;
			}

			if ( 'wc_edited' === $skip_reason ) {
				SheetSync_Import_Row_Service::handle_wc_edited_pull_skip(
					$conn,
					$connection_id,
					$sheet_row_num,
					$peek_data,
					$wc_product,
					isset( $job->id ) ? (int) $job->id : 0
				);
				++$skipped;
				if ( isset( $job->id ) ) {
					( new SheetSync_Job_Repository() )->increment_wc_edited_skips( (int) $job->id );
				}
				$mark_row_done( $sheet_row_num );
				continue;
			}

			if ( ! apply_filters( 'sheetsync_should_process_sheet_row', true, $peek_data, $row, $conn, $connection_id ) ) {
				++$skipped;
				$mark_row_done( $sheet_row_num );
				continue;
			}

			$result = SheetSync_Import_Row_Service::apply_sheet_row_to_wc(
				$conn,
				$connection_id,
				(string) $job->mode,
				$external_key,
				$row_hash,
				$peek_data,
				$row,
				$sheet_row_num,
				$wc_product,
				$updater,
				$this->map_repo
			);
			match ( $result ) {
				'updated' => ++$processed,
				'queued'  => ++$skipped,
				'skipped' => ++$skipped,
				'error'   => ++$errors,
				default   => ++$skipped,
			};

			if ( 'error' === $result ) {
				SheetSync_Import_Row_Service::log_row_result( $connection_id, $sheet_row_num, $peek_data, 'error' );
			}

			if ( 'updated' === $result && $external_key !== '' ) {
				$pid           = SheetSync_Product_Map_Repository::resolve_import_product_id( $peek_data, null, $connection_id );
				$product_after = $pid > 0 ? wc_get_product( $pid ) : null;
				SheetSync_Import_Row_Service::store_row_hash(
					$connection_id,
					$this->map_repo,
					$external_key,
					$row_hash,
					$sheet_row_num,
					$peek_data,
					true,
					$product_after instanceof WC_Product ? $product_after : null
				);
			}

			$mark_row_done( $sheet_row_num );
		}

		SheetSync_Import_Row_Service::end_batch();
		SheetSync_Import_Row_Service::flush_import_run_state( $connection_id );
		$this->map_repo->clear_request_cache( $connection_id );

		if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
			$mid_retry = SheetSync_Import_Row_Service::run_mid_batch_deferred_retries( $connection_id, $maps );
			if ( ( $mid_retry['processed'] ?? 0 ) > 0 || ( $mid_retry['errors'] ?? 0 ) > 0 ) {
				$processed += (int) ( $mid_retry['processed'] ?? 0 );
				$skipped   += (int) ( $mid_retry['skipped'] ?? 0 );
				$errors    += (int) ( $mid_retry['errors'] ?? 0 );
			}
		}

		if ( class_exists( 'SheetSync_Media_Queue', false ) ) {
			SheetSync_Media_Queue::schedule_processing( $connection_id );
			if ( function_exists( 'sheetsync_media_queue_maybe_process_inline' ) ) {
				sheetsync_media_queue_maybe_process_inline( $connection_id );
			}
		}

		if ( ! $had_data ) {
			return array(
				'done'      => true,
				'processed' => $processed,
				'skipped'   => $skipped,
				'errors'    => $errors,
				'advance'   => $fetched,
			);
		}

		if ( $timed_out ) {
			$advance = 0;
			for ( $row_num = $start_row; $row_num < $start_row + $fetched; $row_num++ ) {
				if ( empty( $completed_sheet_rows[ $row_num ] ) ) {
					break;
				}
				++$advance;
			}
			return array(
				'done'      => false,
				'processed' => $processed,
				'skipped'   => $skipped,
				'errors'    => $errors,
				'advance'   => $advance,
			);
		}

		return array(
			'done'      => $fetched < $batch_size,
			'processed' => $processed,
			'skipped'   => $skipped,
			'errors'    => $errors,
			'advance'   => $fetched,
		);
	}
}

endif;
