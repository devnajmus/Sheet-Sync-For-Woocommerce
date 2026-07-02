<?php
/**
 * Starts sync jobs (queue + lock).
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Sync_Coordinator' ) ) :

class SheetSync_Sync_Coordinator {

	private SheetSync_Job_Repository $jobs;
	private SheetSync_Sync_State_Repository $state;

	public function __construct() {
		$this->jobs  = new SheetSync_Job_Repository();
		$this->state = new SheetSync_Sync_State_Repository();
	}

	/**
	 * @param array<string, mixed> $args direction, mode, triggered_by
	 * @return array{success: bool, job_id?: int, message: string, async?: bool}
	 */
	public function start( int $connection_id, array $args = array() ): array {
		$conn = SheetSync_Sync_Engine::get_connection( $connection_id );
		if ( ! $conn || 'active' !== $conn->status ) {
			return array(
				'success' => false,
				'message' => __( 'Connection not found or inactive.', 'sheetsync-for-woocommerce' ),
			);
		}

		if ( SheetSync_Sync_Engine::is_orders_type( $conn->connection_type ?? 'products' ) ) {
			return $this->start_order( $connection_id, $conn, $args );
		}

		$mode_override = isset( $args['mode'] ) ? (string) $args['mode'] : null;
		if ( null === $mode_override && ! empty( $args['sync_strategy'] ) ) {
			$strategy = (string) $args['sync_strategy'];
			$mode_override = ( 'full' === $strategy ) ? 'full' : 'incremental';
		}
		$mode = SheetSync_Sync_Mode::resolve( $connection_id, $mode_override );

		$pull_mode = isset( $args['pull_mode'] ) ? sanitize_text_field( (string) $args['pull_mode'] ) : 'default';
		if ( ! in_array( $pull_mode, array( 'default', 'create_new', 'update_only' ), true ) ) {
			$pull_mode = 'default';
		}
		if ( 'update_only' === $pull_mode
			&& class_exists( 'SheetSync_Product_Map_Repository', false )
			&& 0 === ( new SheetSync_Product_Map_Repository() )->count_for_connection( $connection_id )
			&& in_array( (string) ( $conn->sync_direction ?? '' ), array( 'sheets_to_wc', 'two_way' ), true ) ) {
			$pull_mode = 'default';
		}

		// Full export to sheet: bootstrap push only (no sheet→WC pull of thousands of empty rows).
		// Two-way + pull_before_export: pull sheet edits first, then full push (safer than overwriting merchant sheet edits).
		$explicit_direction = isset( $args['direction'] ) ? sanitize_text_field( (string) $args['direction'] ) : '';
		if ( in_array( $explicit_direction, array( 'pull', 'push', 'two_way' ), true ) ) {
			$direction = $explicit_direction;
		} elseif ( 'full' === $mode && 'two_way' === (string) $conn->sync_direction && ! empty( $args['pull_before_export'] ) ) {
			$direction = 'two_way';
		} elseif ( 'full' === $mode && in_array( (string) $conn->sync_direction, array( 'wc_to_sheets', 'two_way' ), true ) ) {
			$direction = 'bootstrap';
		} else {
			$direction = SheetSync_Sync_Mode::job_direction_from_connection( (string) $conn->sync_direction, $mode );

			// Manual sheet→WC on a two-way connection: pull only (do not push WC back in the same run).
			if ( 'two_way' === $direction
				&& in_array( $pull_mode, array( 'create_new', 'update_only' ), true )
				&& ( $args['triggered_by'] ?? '' ) === 'manual' ) {
				$direction = 'pull';
			}
		}

		$running = $this->jobs->get_running_for_connection( $connection_id );
		if ( $running && 'pull' === $direction && ( $args['triggered_by'] ?? '' ) === 'manual' ) {
			$this->jobs->yield_export_job_for_pull( $running );
			$running = $this->jobs->get_running_for_connection( $connection_id );
		}
		if ( $running ) {
			return $this->running_job_response( $running );
		}

		if ( in_array( $direction, array( 'pull', 'two_way' ), true )
			&& function_exists( 'sheetsync_validate_sheet_import_sync' ) ) {
			$validation = sheetsync_validate_sheet_import_sync( $connection_id, $conn, $direction, $mode, $pull_mode );
			if ( ! ( $validation['ok'] ?? true ) ) {
				return array(
					'success'           => false,
					'message'           => (string) ( $validation['message'] ?? __( 'Sync pre-check failed.', 'sheetsync-for-woocommerce' ) ),
					'validation_errors' => $validation['errors'] ?? array(),
				);
			}
		}

		if ( in_array( $direction, array( 'push', 'bootstrap', 'two_way' ), true )
			&& function_exists( 'sheetsync_validate_sheet_export_sync' ) ) {
			$validation = sheetsync_validate_sheet_export_sync( $connection_id, $conn );
			if ( ! ( $validation['ok'] ?? true ) ) {
				$verify = is_array( $validation['sheet_verify'] ?? null ) ? $validation['sheet_verify'] : array();
				return array(
					'success'           => false,
					'message'           => (string) ( $validation['message'] ?? __( 'Export pre-check failed.', 'sheetsync-for-woocommerce' ) ),
					'validation_errors' => $validation['errors'] ?? array(),
					'fix_url'           => (string) ( $verify['fix_url'] ?? '' ),
					'sheet_not_ready'   => true,
				);
			}
		}

		$total_estimate = null;
		if ( function_exists( 'sheetsync_count_exportable_products' ) ) {
			$conn_dir = (string) $conn->sync_direction;
			$needs_export_total = 'bootstrap' === $direction
				|| ( 'push' === $direction && in_array( $conn_dir, array( 'wc_to_sheets', 'two_way' ), true ) )
				|| ( 'two_way' === $direction && in_array( $conn_dir, array( 'wc_to_sheets', 'two_way' ), true ) );
			if ( $needs_export_total ) {
				$total_estimate = sheetsync_count_exportable_products( $connection_id );
			}
		}
		if ( 'pull' === $direction && class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			if ( function_exists( 'sheetsync_connection_row_breakdown' ) ) {
				$import_breakdown = sheetsync_connection_row_breakdown( $connection_id, 'import' );
				$import_total     = (int) ( $import_breakdown['sheet_rows'] ?? 0 );
				if ( $import_total > 0 ) {
					$total_estimate = $import_total;
				}
			}
			if ( null === $total_estimate || $total_estimate <= 0 ) {
				$mapped = ( new SheetSync_Product_Map_Repository() )->count_for_connection( $connection_id );
				if ( $mapped > 0 ) {
					$total_estimate = $mapped;
				} elseif ( function_exists( 'sheetsync_estimate_sheet_data_rows' ) ) {
					$sheet_rows = sheetsync_estimate_sheet_data_rows( $conn );
					if ( $sheet_rows > 0 ) {
						$total_estimate = $sheet_rows;
					}
				}
			}
		}

		$scheduler_warning   = false;
		$scheduler_message   = '';
		$scheduler_tools_url = '';
		$gate_threshold      = function_exists( 'sheetsync_large_sync_gate_threshold' )
			? sheetsync_large_sync_gate_threshold()
			: 200;
		$large_threshold     = function_exists( 'sheetsync_large_sync_scheduler_threshold' )
			? sheetsync_large_sync_scheduler_threshold()
			: (int) apply_filters( 'sheetsync_large_sync_scheduler_threshold', 500 );
		$force_degraded      = ! empty( $args['force_degraded_sync'] );
		$block_on_unhealthy  = (bool) apply_filters( 'sheetsync_block_sync_on_unhealthy_scheduler', false );
		$is_off_peak         = (string) ( $args['triggered_by'] ?? '' ) === 'off_peak';

		if ( ! $is_off_peak
			&& null !== $total_estimate
			&& $total_estimate >= $gate_threshold
			&& function_exists( 'sheetsync_get_action_scheduler_health' ) ) {
			$health = sheetsync_get_action_scheduler_health();
			if ( ! ( $health['ok'] ?? true ) ) {
				$scheduler_message   = (string) ( $health['message'] ?? '' );
				$scheduler_tools_url = (string) ( $health['tools_url'] ?? '' );
				$eta_hint            = '';
				if ( function_exists( 'sheetsync_estimate_job_eta_minutes' ) ) {
					$eta_job = (object) array(
						'connection_id'   => $connection_id,
						'total_estimate'  => $total_estimate,
						'processed_count' => 0,
						'cursor_offset'   => 0,
						'direction'       => $direction,
						'phase'           => 'bootstrap' === $direction ? 'push' : 'pull',
						'status'          => 'pending',
					);
					$eta_mins = sheetsync_estimate_job_eta_minutes( $eta_job );
					if ( null !== $eta_mins && $eta_mins > 0 ) {
						$degraded_eta = max( $eta_mins, (int) ceil( $eta_mins * 2.2 ) );
						$eta_hint     = ' ' . sprintf(
							/* translators: 1: row count, 2: healthy ETA minutes, 3: slow-mode ETA minutes */
							__( 'This catalog has ~%1$d rows (~%2$d min when the queue is healthy, ~%3$d min in slow browser mode).', 'sheetsync-for-woocommerce' ),
							$total_estimate,
							$eta_mins,
							$degraded_eta
						);
					}
				}
				if ( $block_on_unhealthy && ! $force_degraded ) {
					return array(
						'success'             => false,
						'message'             => trim(
							$scheduler_message . ' '
							. __( 'Large syncs cannot start until WooCommerce Scheduled Actions is running.', 'sheetsync-for-woocommerce' )
							. $eta_hint
						),
						'scheduler_warning'   => true,
						'scheduler_blocked'   => true,
						'scheduler_message'   => $scheduler_message,
						'scheduler_tools_url' => $scheduler_tools_url,
						'total_estimate'      => (int) $total_estimate,
					);
				}
				if ( ! $force_degraded ) {
					return array(
						'success'             => false,
						'message'             => trim(
							$scheduler_message . ' '
							. __( 'Fix background tasks first for the fastest sync, or choose slow sync and keep this browser tab open.', 'sheetsync-for-woocommerce' )
							. $eta_hint
						),
						'scheduler_warning'   => true,
						'scheduler_blocked'   => true,
						'scheduler_soft_gate' => true,
						'scheduler_message'   => $scheduler_message,
						'scheduler_tools_url' => $scheduler_tools_url,
						'total_estimate'      => (int) $total_estimate,
					);
				}
				$scheduler_warning = true;
			}
		}

		$needs_export_confirm = in_array( $direction, array( 'bootstrap', 'two_way' ), true )
			&& 'full' === $mode
			&& in_array( (string) $conn->sync_direction, array( 'wc_to_sheets', 'two_way' ), true )
			&& ( $args['triggered_by'] ?? '' ) === 'manual'
			&& empty( $args['confirm_full_export'] );
		if ( $needs_export_confirm ) {
			$confirm_ctx = function_exists( 'sheetsync_get_export_confirm_context' )
				? sheetsync_get_export_confirm_context( $connection_id )
				: array();
			return array(
				'success'               => false,
				'message'               => __(
					'Full catalog export writes every product to the sheet and removes stale rows when it completes. Confirm to proceed.',
					'sheetsync-for-woocommerce'
				),
				'requires_confirmation' => true,
				'confirmation_type'     => 'full_export',
				'export_confirm'        => $confirm_ctx,
			);
		}

		if ( ! $this->state->acquire_lock(
			$connection_id,
			function_exists( 'sheetsync_lock_ttl_for_estimate' )
				? sheetsync_lock_ttl_for_estimate( (int) ( $total_estimate ?? 0 ) )
				: 300
		) ) {
			return $this->lock_denied_response( $connection_id );
		}

		$job_data = array(
			'connection_id' => $connection_id,
			'direction'     => $direction,
			'mode'          => $mode,
			'triggered_by'  => $args['triggered_by'] ?? 'manual',
			'cursor_meta'   => array(
				'pull_next_row'            => 0,
				'push_last_id'             => 0,
				'export_after_id'              => 0,
				'export_after_menu_order'      => -1,
				'export_after_title'           => '',
				'export_pending_parent_id'     => 0,
				'export_variation_after_id'    => 0,
				'phases_pending'           => self::phases_for_direction( $direction, $connection_id ),
				'pull_mode'                => $pull_mode,
			),
		);
		if ( null !== $total_estimate && $total_estimate > 0 ) {
			$job_data['total_estimate'] = $total_estimate;
		}

		$job_id = $this->jobs->create( $job_data );

		if ( 'default' !== $pull_mode ) {
			set_transient( 'sheetsync_pull_mode_' . $connection_id, $pull_mode, HOUR_IN_SECONDS );
		}

		$this->state->set_current_job( $connection_id, $job_id );
		( new SheetSync_Product_Map_Repository() )->migrate_legacy_hashes( $connection_id );

		sheetsync_schedule_job_action( $job_id, 'sheetsync_run_job', 0 );

		$is_ajax_manual = ( $args['triggered_by'] ?? '' ) === 'manual'
			&& defined( 'DOING_AJAX' ) && DOING_AJAX;

		$conn_dir        = (string) ( $conn->sync_direction ?? '' );
		$sheet_import    = in_array( $direction, array( 'pull', 'two_way' ), true )
			&& in_array( $conn_dir, array( 'sheets_to_wc', 'two_way' ), true );
		$sheet_export    = in_array( $direction, array( 'push', 'bootstrap', 'two_way' ), true )
			&& in_array( $conn_dir, array( 'wc_to_sheets', 'two_way' ), true );
		// Tab-first sync: manual AJAX always returns quickly; browser tab drain continues the job.
		$ajax_fast_start = $is_ajax_manual && ( $sheet_import || $sheet_export );

		// Shared hosting often kills the HTTP connection at 30–60s. AJAX start must return quickly;
		// admin JS continues via ajax_drain_job + REST polling (see admin-script.js maybeDrainJob).
		if ( function_exists( 'sheetsync_kick_background_queue' ) && ! $ajax_fast_start ) {
			if ( $is_ajax_manual ) {
				sheetsync_kick_background_queue(
					(int) apply_filters( 'sheetsync_ajax_start_kick_seconds', 2 )
				);
			} else {
				sheetsync_kick_background_queue( $scheduler_warning ? 30 : 12 );
			}
		}

		$background_only = false;

		// Manual sync: optional short inline kick; AJAX must return quickly on shared hosting.
		if ( ( $args['triggered_by'] ?? '' ) === 'manual' && function_exists( 'sheetsync_process_job_inline' ) ) {
			$inline_seconds = 8;

			if ( $is_ajax_manual && $ajax_fast_start ) {
				// Tab-first: always process a short first batch so rows appear in the sheet immediately.
				$inline_seconds = function_exists( 'sheetsync_admin_request_budget_seconds' )
					? sheetsync_admin_request_budget_seconds()
					: 4;
				$inline_seconds = (int) apply_filters( 'sheetsync_ajax_fast_inline_seconds', $inline_seconds );
			} elseif ( $is_ajax_manual && $sheet_import ) {
				if ( $scheduler_warning || $force_degraded ) {
					$inline_seconds = (int) apply_filters( 'sheetsync_degraded_pull_inline_seconds', 4 );
				} else {
					$inline_seconds = (int) apply_filters( 'sheetsync_manual_pull_inline_seconds', 3 );
				}
			} elseif ( $is_ajax_manual ) {
				if ( $scheduler_warning || $force_degraded ) {
					$inline_seconds = (int) apply_filters( 'sheetsync_degraded_inline_seconds', 4 );
				} else {
					$budget         = function_exists( 'sheetsync_admin_request_budget_seconds' )
						? sheetsync_admin_request_budget_seconds()
						: 4;
					$default_inline = in_array( $direction, array( 'bootstrap', 'two_way', 'push' ), true ) ? $budget : min( 6, $budget + 1 );
					$inline_seconds = (int) apply_filters( 'sheetsync_manual_inline_seconds', $default_inline );
				}
			} elseif ( $scheduler_warning ) {
				$inline_seconds = (int) apply_filters( 'sheetsync_degraded_inline_seconds', 45 );
			} elseif ( 'full' === $mode || in_array( $direction, array( 'bootstrap', 'two_way' ), true ) ) {
				$inline_seconds = (int) apply_filters( 'sheetsync_manual_inline_seconds', 25 );
			}
			if ( $inline_seconds > 0 ) {
				sheetsync_process_job_inline( $job_id, $inline_seconds );
			}

			self::ensure_job_continues_in_background( $job_id, $is_ajax_manual );
		}

		$job = $this->jobs->get( $job_id );

		$completed = $job && 'completed' === $job->status;
		$failed    = $job && 'failed' === $job->status;

		$message = $completed
			? self::format_completed_sync_message( $job )
			: ( $failed
				? (string) ( $job->last_error ?? __( 'Sync failed.', 'sheetsync-for-woocommerce' ) )
				: __( 'Sync queued. Processing in the background.', 'sheetsync-for-woocommerce' ) );

		if ( $completed && 'incremental' === $mode && 'wc_to_sheets' === (string) $conn->sync_direction && function_exists( 'sheetsync_count_exportable_products' ) ) {
			$mapped = ( new SheetSync_Product_Map_Repository() )->count_for_connection( $connection_id );
			$total  = sheetsync_count_exportable_products( $connection_id );
			if ( $total > $mapped ) {
				$message .= ' ' . sprintf(
					/* translators: 1: mapped count, 2: total product count */
					__( 'Only %1$d of %2$d products have sheet rows. Use Full Sheet Refresh and Sync Now to export the full catalog.', 'sheetsync-for-woocommerce' ),
					$mapped,
					$total
				);
			}
		}

		$health = function_exists( 'sheetsync_get_action_scheduler_health' )
			? sheetsync_get_action_scheduler_health()
			: array( 'ok' => true, 'past_due' => 0, 'message' => '', 'tools_url' => '' );

		if ( $scheduler_warning && $scheduler_message !== '' ) {
			$message .= ' ' . sprintf(
				/* translators: %s: scheduler health message */
				__( 'Warning: background queue is slow (%s). Sync started in degraded mode — keep this tab open or fix Scheduled Actions.', 'sheetsync-for-woocommerce' ),
				$scheduler_message
			);
		} elseif ( ! $completed && ! $failed && ! ( $health['ok'] ?? true ) ) {
			$message .= ' ' . (string) ( $health['message'] ?? '' );
		}

		$still_running = $job && ! in_array( $job->status, array( 'completed', 'failed', 'cancelled' ), true );
		if ( ! empty( $background_only ) && $still_running ) {
			$message = __( 'Sync queued — running in the background. You can close this tab; progress updates when you return.', 'sheetsync-for-woocommerce' );
		}

		$progress      = $job && function_exists( 'sheetsync_job_progress_numbers' )
			? sheetsync_job_progress_numbers( $job )
			: array(
				'done'  => $job ? (int) ( $job->processed_count ?? 0 ) : 0,
				'total' => $job ? (int) ( $job->total_estimate ?? $total_estimate ?? 0 ) : (int) ( $total_estimate ?? 0 ),
				'pct'   => null,
			);
		$processed     = (int) ( $progress['done'] ?? 0 );
		$err_count     = $job ? (int) ( $job->error_count ?? 0 ) : 0;
		$eta_minutes   = null;
		if ( $job && $still_running && function_exists( 'sheetsync_estimate_job_eta_minutes' ) ) {
			$eta_minutes = sheetsync_estimate_job_eta_minutes( $job );
		} elseif ( null !== $total_estimate && $total_estimate > 0 && function_exists( 'sheetsync_estimate_job_eta_minutes' ) ) {
			$eta_minutes = sheetsync_estimate_job_eta_minutes(
				(object) array(
					'connection_id'   => $connection_id,
					'total_estimate'  => $total_estimate,
					'processed_count' => 0,
					'cursor_offset'   => 0,
					'direction'       => $direction,
					'phase'           => 'bootstrap' === $direction ? 'push' : 'pull',
					'status'          => 'running',
				)
			);
		}

		return array(
			'success'              => $still_running
				|| $processed > 0
				|| ( $completed && 0 === $err_count ),
			'job_id'               => $job_id,
			'message'              => $message,
			'async'                => $still_running || ( ! $completed && ! $failed ),
			'show_progress'        => $still_running || (int) ( $total_estimate ?? 0 ) > 0,
			'mode'                 => $mode,
			'processed'            => $processed,
			'skipped'              => (int) ( $job->skipped_count ?? 0 ),
			'errors'               => $err_count,
			'total_estimate'       => (int) ( $progress['total'] ?? ( $job ? (int) ( $job->total_estimate ?? $total_estimate ?? 0 ) : (int) ( $total_estimate ?? 0 ) ) ),
			'progress_pct'         => $progress['pct'] ?? null,
			'eta_minutes'          => $eta_minutes,
			'scheduler_warning'    => $scheduler_warning || ! ( $health['ok'] ?? true ),
			'scheduler_degraded'   => $scheduler_warning,
			'scheduler_message'    => $scheduler_message !== '' ? $scheduler_message : (string) ( $health['message'] ?? '' ),
			'scheduler_tools_url'  => $scheduler_tools_url !== '' ? $scheduler_tools_url : (string) ( $health['tools_url'] ?? '' ),
			'background_only'      => ! empty( $background_only ),
		);
	}

	/**
	 * @return string[]
	 */
	private static function format_completed_sync_message( object $job ): string {
		$ok      = (int) ( $job->processed_count ?? 0 );
		$failed  = (int) ( $job->error_count ?? 0 );
		$total   = (int) ( $job->total_estimate ?? 0 );
		$skipped = (int) ( $job->skipped_count ?? 0 );
		$dir     = (string) ( $job->direction ?? '' );

		$wc_edited = 0;
		$wc_pushed = 0;
		if ( class_exists( 'SheetSync_Job_Repository', false ) ) {
			$wc_edited = ( new SheetSync_Job_Repository() )->get_wc_edited_skips( $job );
			$wc_pushed = ( new SheetSync_Job_Repository() )->get_wc_edited_pushed( $job );
		}

		if ( in_array( $dir, array( 'bootstrap', 'push' ), true ) && $total > 0 ) {
			$msg = sprintf(
				/* translators: 1: rows written, 2: total sheet rows (parents + variations) */
				__( 'Exported %1$d of %2$d sheet rows (variable products + their variations).', 'sheetsync-for-woocommerce' ),
				$ok,
				$total
			);
			if ( function_exists( 'sheetsync_count_exportable_breakdown' ) ) {
				$breakdown = sheetsync_count_exportable_breakdown( (int) ( $job->connection_id ?? 0 ) );
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
					__( '%d row(s) failed — see SheetSync → Sync Logs.', 'sheetsync-for-woocommerce' ),
					$failed
				);
			} elseif ( $ok >= $total ) {
				$msg .= ' ' . __( 'All rows are on the sheet.', 'sheetsync-for-woocommerce' );
			}
			return $msg;
		}

		$msg = sprintf(
			/* translators: 1: updated rows, 2: skipped, 3: failed */
			__( 'Sync complete. %1$d updated, %2$d skipped, %3$d failed.', 'sheetsync-for-woocommerce' ),
			$ok,
			$skipped,
			$failed
		);
		if ( $wc_pushed > 0 ) {
			$msg .= ' ' . sprintf(
				/* translators: %d: rows pushed to sheet after WC-only edits */
				_n(
					'%d WooCommerce edit was pushed to the sheet.',
					'%d WooCommerce edits were pushed to the sheet.',
					$wc_pushed,
					'sheetsync-for-woocommerce'
				),
				$wc_pushed
			);
		} elseif ( $wc_edited > 0 ) {
			$msg .= ' ' . sprintf(
				/* translators: %d: rows skipped because WooCommerce changed but sheet did not */
				_n(
					'%d row had WooCommerce edits not applied (sheet unchanged) — queued for push or run WC→Sheet sync.',
					'%d rows had WooCommerce edits not applied (sheet unchanged) — queued for push or run WC→Sheet sync.',
					$wc_edited,
					'sheetsync-for-woocommerce'
				),
				$wc_edited
			);
		}
		return $msg;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function lock_denied_response( int $connection_id ): array {
		$running = $this->jobs->get_running_for_connection( $connection_id );
		if ( $running ) {
			return $this->running_job_response( $running );
		}
		return array(
			'success' => false,
			'message' => __( 'Another sync is running for this connection.', 'sheetsync-for-woocommerce' ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function running_job_response( object $running ): array {
		return array(
			'success'        => true,
			'job_id'         => (int) $running->id,
			'message'        => __( 'Sync already in progress.', 'sheetsync-for-woocommerce' ),
			'async'          => true,
			'show_progress'  => true,
			'processed'      => (int) ( $running->processed_count ?? 0 ),
			'errors'         => (int) ( $running->error_count ?? 0 ),
			'total_estimate' => (int) ( $running->total_estimate ?? 0 ),
			'mode'           => (string) ( $running->mode ?? 'incremental' ),
		);
	}

	/**
	 * @return string[]
	 */
	public static function phases_for_direction( string $direction, int $connection_id = 0 ): array {
		if ( self::is_order_job_direction( $direction ) ) {
			return self::phases_for_order_direction( $direction );
		}
		if ( 'two_way' === $direction && $connection_id > 0 && class_exists( 'SheetSync_Sync_State_Repository', false ) ) {
			return ( new SheetSync_Sync_State_Repository() )->get_two_way_phases( $connection_id );
		}
		return match ( $direction ) {
			'pull'       => array( 'pull', 'finalize' ),
			'push'       => array( 'push', 'finalize' ),
			'bootstrap'  => array( 'push', 'finalize' ),
			'two_way'    => array( 'pull', 'push', 'finalize' ),
			default      => array( 'pull', 'push', 'finalize' ),
		};
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array{success: bool, job_id?: int, message: string, async?: bool}
	 */
	private function start_order( int $connection_id, object $conn, array $args ): array {
		if ( ! sheetsync_order_job_engine() ) {
			return array(
				'success' => false,
				'message' => __( 'Order background sync is disabled. Set sheetsync_order_job_engine to true or run sync without the job queue.', 'sheetsync-for-woocommerce' ),
			);
		}

		if ( ! class_exists( 'SheetSync_Order_Sync', false ) ) {
			return array(
				'success' => false,
				'message' => __( 'Order sync module not loaded.', 'sheetsync-for-woocommerce' ),
			);
		}

		$running = $this->jobs->get_running_for_connection( $connection_id );
		if ( $running ) {
			return $this->running_job_response( $running );
		}

		if ( ! $this->state->acquire_lock( $connection_id ) ) {
			return $this->lock_denied_response( $connection_id );
		}

		$sync_direction = isset( $args['order_sync_direction'] )
			? (string) $args['order_sync_direction']
			: (string) $conn->sync_direction;
		$job_direction  = self::order_job_direction_from_connection( $sync_direction );
		$phases         = self::phases_for_order_direction( $job_direction );

		$order_sync     = new SheetSync_Order_Sync();
		$total_estimate = $order_sync->count_orders_for_connection( $conn );

		$job_data = array(
			'connection_id' => $connection_id,
			'direction'     => $job_direction,
			'mode'          => 'full',
			'triggered_by'  => $args['triggered_by'] ?? 'manual',
			'cursor_meta'   => array(
				'phases_pending'          => $phases,
				'order_wc_page'           => 1,
				'order_sheet_row'         => SheetSync_Order_Sync::ORDER_DATA_START_ROW,
				'order_sheet_initialized' => false,
				'order_rows_written'      => 0,
			),
		);
		if ( $total_estimate > 0 ) {
			$job_data['total_estimate'] = $total_estimate;
		}

		$job_id = $this->jobs->create( $job_data );
		$this->state->set_current_job( $connection_id, $job_id );
		sheetsync_schedule_job_action( $job_id, 'sheetsync_run_job', 0 );

		if ( ( $args['triggered_by'] ?? '' ) === 'manual' && function_exists( 'sheetsync_process_job_inline' ) ) {
			$is_ajax_manual = defined( 'DOING_AJAX' ) && DOING_AJAX;
			$default_inline = $is_ajax_manual ? 8 : 28;
			sheetsync_process_job_inline( $job_id, (int) apply_filters( 'sheetsync_manual_inline_seconds', $default_inline ) );
			self::ensure_job_continues_in_background( $job_id, $is_ajax_manual );
		}

		$job             = $this->jobs->get( $job_id );
		$completed       = $job && 'completed' === $job->status;
		$failed          = $job && 'failed' === $job->status;
		$still_running   = $job && ! in_array( $job->status, array( 'completed', 'failed', 'cancelled' ), true );
		$message         = $completed
			? self::format_order_job_message( $job )
			: ( $failed
				? (string) ( $job->last_error ?? __( 'Sync failed.', 'sheetsync-for-woocommerce' ) )
				: __( 'Order sync queued. Processing in the background.', 'sheetsync-for-woocommerce' ) );

		return array(
			'success'        => $completed || ( ! $failed && $job ),
			'job_id'         => $job_id,
			'message'        => $message,
			'async'          => $still_running || ( ! $completed && ! $failed ),
			'show_progress'  => $still_running || $total_estimate > 0,
			'processed'      => (int) ( $job->processed_count ?? 0 ),
			'skipped'        => (int) ( $job->skipped_count ?? 0 ),
			'errors'         => (int) ( $job->error_count ?? 0 ),
			'total_estimate' => (int) ( $job->total_estimate ?? $total_estimate ),
		);
	}

	public static function order_job_direction_from_connection( string $sync_direction ): string {
		return match ( $sync_direction ) {
			'sheets_to_wc' => 'order_pull',
			'two_way'      => 'order_two_way',
			default        => 'order_push',
		};
	}

	public static function is_order_job_direction( string $direction ): bool {
		return in_array( $direction, array( 'order_push', 'order_pull', 'order_two_way' ), true );
	}

	/**
	 * @return string[]
	 */
	public static function phases_for_order_direction( string $direction ): array {
		return match ( $direction ) {
			'order_pull'    => array( 'order_pull', 'finalize' ),
			'order_two_way' => array( 'order_pull', 'order_push', 'finalize' ),
			default         => array( 'order_push', 'finalize' ),
		};
	}

	private static function ensure_job_continues_in_background( int $job_id, bool $ajax_start = false ): void {
		$repo = new SheetSync_Job_Repository();
		$job  = $repo->get( $job_id );
		if ( ! $job || in_array( (string) $job->status, array( 'completed', 'failed', 'cancelled' ), true ) ) {
			return;
		}
		if ( function_exists( 'sheetsync_reschedule_stuck_job' ) ) {
			sheetsync_reschedule_stuck_job( $job_id, 2 );
		} else {
			sheetsync_schedule_job_action( $job_id, 'sheetsync_run_job', 2 );
		}
		// AJAX sheet imports rely on ajax_drain_job batches — skip in-request queue kicks.
		if ( function_exists( 'sheetsync_kick_background_queue' ) && ! $ajax_start ) {
			sheetsync_kick_background_queue(
				(int) apply_filters( 'sheetsync_manual_followup_kick_seconds', 10 )
			);
		}
	}

	private static function format_order_job_message( object $job ): string {
		$ok     = (int) ( $job->processed_count ?? 0 );
		$failed = (int) ( $job->error_count ?? 0 );
		$total  = (int) ( $job->total_estimate ?? 0 );
		$msg    = sprintf(
			/* translators: 1: orders written, 2: total estimate, 3: job id */
			__( 'Synced %1$d of %2$d orders (Job #%3$d).', 'sheetsync-for-woocommerce' ),
			$ok,
			$total > 0 ? $total : $ok,
			(int) $job->id
		);
		if ( $failed > 0 ) {
			$msg .= ' ' . sprintf(
				/* translators: %d: failed rows */
				__( '%d order(s) failed — see Sync Logs.', 'sheetsync-for-woocommerce' ),
				$failed
			);
		}
		return $msg;
	}
}

endif;
