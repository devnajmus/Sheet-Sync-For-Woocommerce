<?php
/**
 * Action Scheduler job runner — bounded batches with resume.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Job_Runner' ) ) :

class SheetSync_Job_Runner {

	private SheetSync_Job_Repository $jobs;
	private SheetSync_Sync_State_Repository $state;

	public function __construct() {
		$this->jobs  = new SheetSync_Job_Repository();
		$this->state = new SheetSync_Sync_State_Repository();
	}

	public function tick( int $job_id ): void {
		if ( function_exists( 'sheetsync_acquire_job_mutex' ) && ! sheetsync_acquire_job_mutex( $job_id ) ) {
			return;
		}

		try {
			$this->run_tick( $job_id );
		} finally {
			if ( function_exists( 'sheetsync_release_job_mutex' ) ) {
				sheetsync_release_job_mutex( $job_id );
			}
		}
	}

	private function run_tick( int $job_id ): void {
		$job = $this->jobs->get( $job_id );
		if ( ! $job || in_array( $job->status, array( 'completed', 'failed', 'cancelled' ), true ) ) {
			return;
		}

		$this->apply_job_pull_mode( $job );

		$this->jobs->mark_running( $job_id );
		$conn = SheetSync_Sync_Engine::get_connection( (int) $job->connection_id );
		if ( ! $conn ) {
			$this->jobs->fail( $job_id, 'Connection missing' );
			$this->state->release_lock( (int) $job->connection_id, $job_id );
			SheetSync_Logger::log( (int) $job->connection_id, 'job', 'error', 0, 0, 'Connection missing', 1, $job_id );
			return;
		}

		$lock_ttl = function_exists( 'sheetsync_lock_ttl_for_job' )
			? sheetsync_lock_ttl_for_job( $job )
			: 300;
		$this->state->extend_lock( (int) $job->connection_id, $lock_ttl, $job_id );

		$settings   = $this->state->get_settings( (int) $job->connection_id );
		$deadline   = function_exists( 'sheetsync_job_tick_deadline' )
			? sheetsync_job_tick_deadline()
			: time() + 25;
		$batches    = function_exists( 'sheetsync_resolve_batch_sizes' )
			? sheetsync_resolve_batch_sizes( $job, $settings )
			: array(
				'pull' => (int) ( $settings['pull_batch_size'] ?? 200 ),
				'push' => max( 10, min( 100, (int) ( $settings['push_batch_size'] ?? 100 ) ) ),
			);
		$pull_size  = (int) ( $batches['pull'] ?? 200 );
		$push_size  = (int) ( $batches['push'] ?? 100 );
		$push_size  = max( 10, min( 150, $push_size ) );

		$suspend_cache = function_exists( 'wp_suspend_cache_addition' );
		if ( $suspend_cache ) {
			wp_suspend_cache_addition( true );
		}

		try {
			if ( 'init' === $job->phase ) {
				$this->init_phase( $job );
				sheetsync_schedule_job_action( $job_id, 'sheetsync_run_job', 1 );
				return;
			}

			if ( 'pull' === $job->phase ) {
				$result = ( new SheetSync_Pull_From_Sheet_Service() )->process_batch( $job, $conn, $pull_size, $deadline );
				$this->jobs->increment_stats( $job_id, $result['processed'], $result['skipped'], $result['errors'] );
				if ( ! empty( $result['advance'] ) ) {
					$this->jobs->update_cursor( $job_id, (int) $job->cursor_offset + (int) $result['advance'] );
				}
				if ( ! $result['done'] ) {
					sheetsync_schedule_job_action( $job_id, 'sheetsync_batch_pull', 2 );
					return;
				}
				if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
					$maps  = SheetSync_Field_Mapper::get_maps_for_sync( (int) $conn->id, $conn );
					$retry = SheetSync_Import_Row_Service::retry_deferred_import_passes( (int) $conn->id, $maps, true );
					if ( ( $retry['processed'] + $retry['errors'] ) > 0 ) {
						$this->jobs->increment_stats( $job_id, $retry['processed'], $retry['skipped'], $retry['errors'] );
					}
					$grouped_final = SheetSync_Import_Row_Service::finalize_grouped_link_queue( (int) $conn->id );
					if ( (int) ( $grouped_final['errors'] ?? 0 ) > 0 ) {
						$this->jobs->increment_stats( $job_id, 0, 0, (int) $grouped_final['errors'] );
					}
				}
				if ( class_exists( 'SheetSync_Media_Queue', false ) ) {
					SheetSync_Media_Queue::schedule_processing( (int) $conn->id );
				}
				$this->state->mark_pull_complete( (int) $job->connection_id );
				$this->advance_phase( $job );
				sheetsync_schedule_job_action( $job_id, 'sheetsync_run_job', 1 );
				return;
			}

			if ( 'push' === $job->phase ) {
				$push    = new SheetSync_Push_To_Sheet_Service();
				$done    = false;
				$loops   = 0;
				$last_errors = 0;
				$max_loops = apply_filters( 'sheetsync_inline_job_drain', false ) ? 1 : 8;
				$inline_drain = (bool) apply_filters( 'sheetsync_inline_job_drain', false );
				while ( time() < $deadline && $loops < $max_loops ) {
					++$loops;
					if ( $loops > 1 ) {
						$this->state->extend_lock( (int) $job->connection_id, $lock_ttl, $job_id );
					}
					$job = $this->jobs->get( $job_id );
					if ( ! $job || 'push' !== $job->phase ) {
						break;
					}
					if ( $loops > 1 && 'bootstrap' === (string) ( $job->direction ?? '' )
						&& ! $inline_drain
						&& defined( 'SHEETSYNC_BG_SYNC' ) && SHEETSYNC_BG_SYNC ) {
						sleep( (int) apply_filters( 'sheetsync_bootstrap_batch_pause_seconds', 4 ) );
					}
					$result = $push->process_batch( $job, $conn, $push_size, $deadline );
					$this->jobs->increment_stats( $job_id, $result['processed'], $result['skipped'], $result['errors'] );
					$last_errors = (int) ( $result['errors'] ?? 0 );
					if ( ! empty( $result['fatal'] ) ) {
						$this->fail_job_fatal(
							$job_id,
							$conn,
							(string) ( $result['fatal_message'] ?? __( 'Sync stopped — fix your Google Sheet connection and try again.', 'sheetsync-for-woocommerce' ) )
						);
						return;
					}
					if ( (int) ( $result['processed'] ?? 0 ) > 0 ) {
						$this->jobs->reset_attempts( $job_id );
					}
					if ( ! empty( $result['done'] ) ) {
						$done = true;
						break;
					}
				}

				if ( ! $done && $deadline > 0 && time() >= $deadline ) {
					if ( class_exists( 'SheetSync_Sync_Tick_Perf_Log', false ) ) {
						SheetSync_Sync_Tick_Perf_Log::note_deadline_exit();
					}
				}

				if ( ! $done ) {
					$job = $this->jobs->get( $job_id );
					$delay = function_exists( 'sheetsync_export_batch_delay_seconds' )
						? sheetsync_export_batch_delay_seconds( $last_errors, (string) ( $job->direction ?? '' ), $job )
						: ( $last_errors > 0 ? 60 : 10 );
					sheetsync_schedule_job_action( $job_id, 'sheetsync_batch_push', $delay );
					return;
				}
				$this->state->mark_push_complete( (int) $job->connection_id );
				$this->advance_phase( $job );
				sheetsync_schedule_job_action( $job_id, 'sheetsync_run_job', 1 );
				return;
			}

			if ( 'order_pull' === $job->phase ) {
				if ( class_exists( 'SheetSync_Order_Sync', false ) ) {
					$result = ( new SheetSync_Order_Sync() )->sync_order_statuses_from_sheet( $conn );
					$this->jobs->increment_stats(
						$job_id,
						(int) ( $result['processed'] ?? 0 ),
						(int) ( $result['skipped'] ?? 0 ),
						(int) ( $result['errors'] ?? 0 )
					);
				}
				$job = $this->jobs->get( $job_id );
				if ( $job ) {
					$this->advance_phase( $job );
				}
				sheetsync_schedule_job_action( $job_id, 'sheetsync_run_job', 1 );
				return;
			}

			if ( 'order_push' === $job->phase ) {
				$order_batch = max( 10, min( 100, (int) ( $settings['push_batch_size'] ?? 50 ) ) );
				$result      = ( new SheetSync_Order_Push_Service() )->process_batch( $job, $conn, $order_batch, $deadline );
				$this->jobs->increment_stats( $job_id, $result['processed'], $result['skipped'], $result['errors'] );
				if ( empty( $result['done'] ) ) {
					sheetsync_schedule_job_action( $job_id, 'sheetsync_batch_push', 5 );
					return;
				}
				$job = $this->jobs->get( $job_id );
				if ( $job ) {
					$this->advance_phase( $job );
				}
				sheetsync_schedule_job_action( $job_id, 'sheetsync_run_job', 1 );
				return;
			}

			if ( 'finalize' === $job->phase ) {
				$this->finalize( $job_id, $conn );
				return;
			}

			sheetsync_schedule_job_action( $job_id, 'sheetsync_run_job', 1 );
		} catch ( SheetSync_Rate_Limit_Exception $e ) {
			$this->jobs->set_last_error( $job_id, $e->getMessage() );
			$delay = min( 120, max( 30, (int) apply_filters( 'sheetsync_quota_retry_delay_seconds', 45 ) ) );
			sheetsync_schedule_job_action( $job_id, 'sheetsync_run_job', $delay );
		} catch ( Throwable $e ) {
			if ( function_exists( 'sheetsync_api_error_is_quota' ) && sheetsync_api_error_is_quota( $e->getMessage() ) ) {
				$this->jobs->set_last_error( $job_id, $e->getMessage() );
				sheetsync_schedule_job_action(
					$job_id,
					'sheetsync_run_job',
					(int) apply_filters( 'sheetsync_quota_retry_delay_seconds', 45 )
				);
				return;
			}
			if ( function_exists( 'sheetsync_is_sheet_tab_error' ) && sheetsync_is_sheet_tab_error( $e->getMessage() ) ) {
				$friendly = function_exists( 'sheetsync_format_sheet_error_for_connection' )
					? sheetsync_format_sheet_error_for_connection( $e, (int) $conn->id )
					: $e->getMessage();
				$this->fail_job_fatal( $job_id, $conn, $friendly );
				return;
			}
			$this->jobs->increment_attempts( $job_id, $e->getMessage() );
			$job = $this->jobs->get( $job_id );
			if ( $job && (int) $job->attempts >= (int) $job->max_attempts ) {
				delete_transient( 'sheetsync_pull_mode_' . (int) $job->connection_id );
				$processed = (int) ( $job->processed_count ?? 0 );
				if ( class_exists( 'SheetSync_Bulk_Processor', false ) ) {
					$meta = $this->jobs->get_cursor_meta( $job );
					if ( empty( $meta['catalog_finalized'] ) ) {
						SheetSync_Bulk_Processor::abort_catalog_export( (int) $job->connection_id );
					}
				}
				self::maybe_apply_export_sheet_styling( $job, $conn );
				$fail_message = $processed > 0
					? sprintf(
						/* translators: 1: error message, 2: rows processed, 3: job id */
						__( 'Job stopped after max retries (%1$s). %2$d rows were processed — run Sync again to resume (Job #%3$d).', 'sheetsync-for-woocommerce' ),
						$e->getMessage(),
						$processed,
						$job_id
					)
					: $e->getMessage();
				$this->jobs->fail( $job_id, $fail_message );
				$this->state->release_lock( (int) $job->connection_id, $job_id );
				SheetSync_Logger::log( (int) $job->connection_id, 'job', 'error', $processed, 0, $fail_message, 1, $job_id );

				$settings = get_option( 'sheetsync_settings', array() );
				if ( ! empty( $settings['email_notifications'] ) && class_exists( 'SheetSync_Email_Notifier' ) ) {
					SheetSync_Email_Notifier::send_sync_result(
						array(
							'success'      => false,
							'processed'    => (int) ( $job->processed_count ?? 0 ),
							'skipped'      => (int) ( $job->skipped_count ?? 0 ),
							'errors'       => (int) ( $job->error_count ?? 0 ) + 1,
							'message'      => sprintf(
								/* translators: 1: job id, 2: error message */
								__( 'Job #%1$d failed after max retries: %2$s', 'sheetsync-for-woocommerce' ),
								$job_id,
								$e->getMessage()
							),
							'triggered_by' => (string) ( $job->triggered_by ?? 'manual' ),
						),
						(int) $job->connection_id
					);
				}
			} else {
				sheetsync_schedule_job_action( $job_id, 'sheetsync_run_job', 10 );
			}
		} finally {
			if ( $suspend_cache ) {
				wp_suspend_cache_addition( false );
			}
		}
	}

	private function fail_job_fatal( int $job_id, object $conn, string $message ): void {
		delete_transient( 'sheetsync_pull_mode_' . (int) $conn->id );
		$job = $this->jobs->get( $job_id );
		$processed = $job ? (int) ( $job->processed_count ?? 0 ) : 0;
		if ( class_exists( 'SheetSync_Bulk_Processor', false ) ) {
			$meta = $job ? $this->jobs->get_cursor_meta( $job ) : array();
			if ( empty( $meta['catalog_finalized'] ) ) {
				SheetSync_Bulk_Processor::abort_catalog_export( (int) $conn->id );
			}
		}
		self::maybe_apply_export_sheet_styling( $job, $conn );
		$this->jobs->fail( $job_id, $message );
		$this->state->release_lock( (int) $conn->id, $job_id );
		SheetSync_Logger::log( (int) $conn->id, 'job', 'error', $processed, 0, $message, 1, $job_id );
	}

	private function init_phase( object $job ): void {
		$meta   = $this->jobs->get_cursor_meta( $job );
		$phases = $meta['phases_pending'] ?? SheetSync_Sync_Coordinator::phases_for_direction( $job->direction );
		$first  = is_array( $phases ) && ! empty( $phases ) ? (string) $phases[0] : 'pull';
		if ( 'finalize' === $first && count( $phases ) > 1 ) {
			$first = (string) $phases[1];
		}
		$this->jobs->update_phase( $job->id, $first );
		$this->jobs->update_cursor( $job->id, 0, $meta );

		if ( 'pull' === $first && (int) ( $job->total_estimate ?? 0 ) <= 0 ) {
			$connection_id = (int) $job->connection_id;
			if ( $connection_id > 0 && function_exists( 'sheetsync_connection_row_breakdown' ) ) {
				$breakdown = sheetsync_connection_row_breakdown( $connection_id, 'import' );
				$estimate  = (int) ( $breakdown['sheet_rows'] ?? 0 );
				if ( $estimate > 0 ) {
					$this->jobs->set_total_estimate( (int) $job->id, $estimate );
				}
			}
			if ( (int) ( $job->total_estimate ?? 0 ) <= 0 ) {
				$conn = SheetSync_Sync_Engine::get_connection( $connection_id );
				if ( $conn && function_exists( 'sheetsync_estimate_sheet_data_rows' ) ) {
					$estimate = sheetsync_estimate_sheet_data_rows( $conn );
					if ( $estimate > 0 ) {
						$this->jobs->set_total_estimate( (int) $job->id, $estimate );
					}
				}
			}
		}
	}

	private function advance_phase( object $job ): void {
		$meta   = $this->jobs->get_cursor_meta( $job );
		$phases = $meta['phases_pending'] ?? array();
		if ( ! is_array( $phases ) ) {
			$phases = array();
		}
		$current = $job->phase;
		$idx     = array_search( $current, $phases, true );
		$next    = ( false !== $idx && isset( $phases[ $idx + 1 ] ) ) ? $phases[ $idx + 1 ] : 'finalize';

		$meta['push_last_id'] = 0;
		$this->jobs->update_phase( $job->id, $next );
		$this->jobs->update_cursor( $job->id, 0, $meta );
	}

	/**
	 * Restore pull mode for background job batches (create_new / update_only).
	 */
	private function apply_job_pull_mode( object $job ): void {
		$meta = $this->jobs->get_cursor_meta( $job );
		$mode = isset( $meta['pull_mode'] ) ? (string) $meta['pull_mode'] : 'default';
		if ( in_array( $mode, array( 'create_new', 'update_only' ), true ) ) {
			set_transient( 'sheetsync_pull_mode_' . (int) $job->connection_id, $mode, HOUR_IN_SECONDS );
		}
	}

	private function finalize( int $job_id, object $conn ): void {
		global $wpdb;
		delete_transient( 'sheetsync_pull_mode_' . (int) $conn->id );
		if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
			SheetSync_Import_Row_Service::end_import_run( (int) $conn->id );
		}
		$this->jobs->complete( $job_id );
		$this->state->release_lock( (int) $conn->id, $job_id );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			SheetSync_Schema::table( 'connections' ),
			array( 'last_sync_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $conn->id ),
			array( '%s' ),
			array( '%d' )
		);
		wp_cache_delete( "sheetsync_connection_{$conn->id}", 'sheetsync' );

		if ( function_exists( 'sheetsync_flush_dirty_products_after_sync' ) ) {
			sheetsync_flush_dirty_products_after_sync( (int) $conn->id );
		}

		$job     = $this->jobs->get( $job_id );
		if ( $job && in_array( (string) ( $job->direction ?? '' ), array( 'bootstrap', 'push' ), true ) ) {
			$this->jobs->sync_processed_from_map( $job_id, (int) $conn->id );
			$job = $this->jobs->get( $job_id );
		}
		$ok      = (int) ( $job->processed_count ?? 0 );
		$skipped = (int) ( $job->skipped_count ?? 0 );
		$failed  = (int) ( $job->error_count ?? 0 );
		$total   = (int) ( $job->total_estimate ?? 0 );
		$msg     = self::format_job_completion_message( $job_id, $job, $ok, $skipped, $failed, $total );
		$wc_skip = $this->jobs->get_wc_edited_skips( $job );
		$wc_push = $this->jobs->get_wc_edited_pushed( $job );
		if ( $wc_push > 0 ) {
			$msg .= ' ' . sprintf(
				/* translators: %d: rows pushed to sheet after WC-only edits */
				_n(
					'%d WooCommerce edit was pushed to the sheet.',
					'%d WooCommerce edits were pushed to the sheet.',
					$wc_push,
					'sheetsync-for-woocommerce'
				),
				$wc_push
			);
		} elseif ( $wc_skip > 0 ) {
			$msg .= ' ' . sprintf(
				/* translators: %d: rows skipped because WooCommerce changed but sheet did not */
				_n(
					'%d row had WooCommerce edits not applied (sheet unchanged) — queued for push or run WC→Sheet sync.',
					'%d rows had WooCommerce edits not applied (sheet unchanged) — queued for push or run WC→Sheet sync.',
					$wc_skip,
					'sheetsync-for-woocommerce'
				),
				$wc_skip
			);
		}
		$log_status = $failed > 0 ? 'partial' : 'success';
		if ( $failed > 0 && $ok === 0 ) {
			$log_status = 'error';
		}
		SheetSync_Logger::log( (int) $conn->id, 'job', $log_status, $ok, $skipped, $msg, $failed, $job_id );

		self::maybe_apply_export_sheet_styling( $job, $conn );

		if ( in_array( (string) ( $conn->sync_direction ?? '' ), array( 'wc_to_sheets', 'two_way' ), true )
			&& function_exists( 'sheetsync_log_stale_product_maps' ) ) {
			sheetsync_log_stale_product_maps( (int) $conn->id );
		}

		$settings = get_option( 'sheetsync_settings', array() );
		if ( ! empty( $settings['email_notifications'] ) && class_exists( 'SheetSync_Email_Notifier' ) ) {
			SheetSync_Email_Notifier::send_sync_result(
				array(
					'success'      => $failed === 0,
					'processed'    => $ok,
					'skipped'      => $skipped,
					'errors'       => $failed,
					'message'      => $msg,
					'triggered_by' => (string) ( $job->triggered_by ?? 'manual' ),
				),
				(int) $conn->id
			);
		}
	}

	/**
	 * Filters, banding, and variation row groups after WC → Sheet export.
	 */
	private static function maybe_apply_export_sheet_styling( ?object $job, object $conn ): void {
		if ( ! $job || ! class_exists( 'SheetSync_Bulk_Processor', false ) ) {
			return;
		}
		if ( (int) ( $job->processed_count ?? 0 ) < 1 ) {
			return;
		}
		$conn_dir = (string) ( $conn->sync_direction ?? '' );
		if ( ! in_array( $conn_dir, array( 'wc_to_sheets', 'two_way' ), true ) ) {
			return;
		}
		$dir = (string) ( $job->direction ?? '' );
		if ( ! in_array( $dir, array( 'bootstrap', 'push', 'two_way' ), true ) ) {
			return;
		}
		try {
			SheetSync_Bulk_Processor::finish_export_sheet_styling(
				(int) $conn->id,
				(int) $job->processed_count
			);
		} catch ( \Throwable $e ) {
			SheetSync_Logger::log( (int) $conn->id, 'export', 'error', 0, 0, $e->getMessage() );
		}
	}

	/**
	 * Human-readable job summary for export jobs.
	 */
	private static function format_job_completion_message(
		int $job_id,
		object $job,
		int $ok,
		int $skipped,
		int $failed,
		int $total
	): string {
		$direction = (string) ( $job->direction ?? '' );
		if ( in_array( $direction, array( 'bootstrap', 'push' ), true ) && $total > 0 ) {
			$msg = sprintf(
				/* translators: 1: rows written, 2: total sheet rows, 3: job id */
				__( 'Exported %1$d of %2$d sheet rows to Google Sheet (Job #%3$d). Includes variable parents and variations.', 'sheetsync-for-woocommerce' ),
				$ok,
				$total,
				$job_id
			);
			if ( function_exists( 'sheetsync_count_exportable_breakdown' ) ) {
				$breakdown = sheetsync_count_exportable_breakdown( (int) $job->connection_id );
				$parents   = (int) ( $breakdown['parent_products'] ?? 0 );
				$vars      = (int) ( $breakdown['variations'] ?? 0 );
				if ( $vars > 0 && $parents > 0 ) {
					$msg .= ' ' . sprintf(
						/* translators: 1: parent product count, 2: variation row count */
						__( '(%1$d parent products + %2$d variation rows.)', 'sheetsync-for-woocommerce' ),
						$parents,
						$vars
					);
				}
			}
			if ( $failed > 0 ) {
				$msg .= ' ' . sprintf(
					/* translators: %d: rows that failed */
					__( '%d row(s) could not be written — open Sync Logs (type: export) for SKU and details.', 'sheetsync-for-woocommerce' ),
					$failed
				);
			}
			if ( $skipped > 0 && 'push' === $direction ) {
				$msg .= ' ' . sprintf(
					/* translators: %d: skipped unchanged products */
					__( '%d unchanged (skipped).', 'sheetsync-for-woocommerce' ),
					$skipped
				);
			}
			return $msg;
		}

		return sprintf(
			'Job #%d complete. Updated: %d | Skipped: %d | Failed: %d',
			$job_id,
			$ok,
			$skipped,
			$failed
		);
	}

	/**
	 * Run full finalize() cleanup when a completed export job stalled before finishing.
	 */
	public static function finalize_stalled_job( int $job_id, object $conn ): void {
		$jobs = new SheetSync_Job_Repository();
		$job  = $jobs->get( $job_id );
		if ( $job && class_exists( 'SheetSync_Bulk_Processor', false ) ) {
			$meta              = $jobs->get_cursor_meta( $job );
			$transient_live    = (bool) get_transient( 'sheetsync_catalog_export_' . (int) $conn->id );
			$needs_finalize    = empty( $meta['catalog_finalized'] )
				&& ( ! empty( $meta['export_complete'] ) || $transient_live );
			if ( $needs_finalize && 'push' === (string) ( $job->phase ?? '' ) ) {
				try {
					$maps = SheetSync_Bulk_Processor::maps_for_sheet_export( (int) $conn->id );
					SheetSync_Bulk_Processor::finalize_catalog_export( (int) $conn->id, $maps );
					$meta['catalog_finalized'] = 1;
					$meta['export_complete']   = 1;
					$jobs->update_cursor( $job_id, (int) $job->cursor_offset, $meta );
				} catch ( Throwable $e ) {
					if ( class_exists( 'SheetSync_Logger', false ) ) {
						SheetSync_Logger::log(
							(int) $conn->id,
							'export',
							'error',
							0,
							0,
							$e->getMessage(),
							1,
							$job_id
						);
					}
					return;
				}
			}
		}
		( new self() )->finalize( $job_id, $conn );
	}
}

endif;
