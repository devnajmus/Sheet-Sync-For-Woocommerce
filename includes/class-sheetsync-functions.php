<?php
/**
 * Global helpers for SheetSync enterprise sync.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'sheetsync_perf_log_enabled' ) ) {
	/**
	 * Opt-in sync tick profiling (add define( 'SHEETSYNC_PERF_LOG', true ); to wp-config.php while debugging).
	 */
	function sheetsync_perf_log_enabled(): bool {
		return defined( 'SHEETSYNC_PERF_LOG' ) && SHEETSYNC_PERF_LOG;
	}
}

if ( ! function_exists( 'sheetsync_debug_log' ) ) {
	/**
	 * Write to debug.log only when WP_DEBUG_LOG is enabled (safe for production).
	 */
	function sheetsync_debug_log( string $message ): void {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[SheetSync] ' . $message );
	}
}

if ( ! function_exists( 'sheetsync_wpdb_prepare' ) ) {
	/**
	 * Safe wrapper for $wpdb->prepare() — accepts either variadic args or a single values array.
	 *
	 * Prevents "Unsupported value type (array)" when legacy call sites pass $params without spread.
	 *
	 * @param string        $sql  SQL with placeholders.
	 * @param mixed         ...$args Placeholders or one array of placeholders.
	 * @return string|void Prepared SQL (same return type as wpdb::prepare).
	 */
	function sheetsync_wpdb_prepare( string $sql, ...$args ) {
		global $wpdb;

		if ( empty( $args ) ) {
			return $sql;
		}
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$normalized = array();
		foreach ( $args as $arg ) {
			if ( is_array( $arg ) ) {
				foreach ( $arg as $item ) {
					if ( is_array( $item ) || is_object( $item ) ) {
						continue;
					}
					$normalized[] = $item;
				}
				continue;
			}
			if ( is_object( $arg ) ) {
				continue;
			}
			$normalized[] = $arg;
		}

		if ( empty( $normalized ) ) {
			return $sql;
		}

		return $wpdb->prepare( $sql, ...$normalized );
	}
}

if ( ! function_exists( 'sheetsync_use_job_engine' ) ) {
	function sheetsync_use_job_engine(): bool {
		return (bool) get_option( 'sheetsync_use_job_engine', true );
	}
}

if ( ! function_exists( 'sheetsync_order_job_engine' ) ) {
	/**
	 * Background job queue for order connections (off by default for backward compatibility).
	 */
	function sheetsync_order_job_engine(): bool {
		return (bool) get_option( 'sheetsync_order_job_engine', false );
	}
}

if ( ! function_exists( 'sheetsync_is_inline_job_drain' ) ) {
	function sheetsync_is_inline_job_drain(): bool {
		return (bool) apply_filters( 'sheetsync_inline_job_drain', false );
	}
}

if ( ! function_exists( 'sheetsync_dashboard_products_per_page' ) ) {
	/**
	 * Products loaded per dashboard pagination batch (inventory + sales filters).
	 */
	function sheetsync_dashboard_products_per_page(): int {
		return max( 100, min( 1000, (int) apply_filters( 'sheetsync_dashboard_products_per_page', 500 ) ) );
	}
}

if ( ! function_exists( 'sheetsync_dashboard_products_max_pages' ) ) {
	/**
	 * Max product pages for dashboard queries (default cap: 100k products at 500/page).
	 */
	function sheetsync_dashboard_products_max_pages(): int {
		return max( 1, (int) apply_filters( 'sheetsync_dashboard_products_max_pages', 200 ) );
	}
}

if ( ! function_exists( 'sheetsync_dashboard_orders_per_page' ) ) {
	function sheetsync_dashboard_orders_per_page(): int {
		return max( 100, min( 1000, (int) apply_filters( 'sheetsync_dashboard_orders_per_page', 500 ) ) );
	}
}

if ( ! function_exists( 'sheetsync_dashboard_orders_max_pages' ) ) {
	/**
	 * Max order pages for sales dashboard (default cap: 200k orders at 500/page).
	 */
	function sheetsync_dashboard_orders_max_pages(): int {
		return max( 1, (int) apply_filters( 'sheetsync_dashboard_orders_max_pages', 400 ) );
	}
}

if ( ! function_exists( 'sheetsync_prefers_background_queue' ) ) {
	/**
	 * Whether manual AJAX sync may return immediately and rely on background runners only.
	 *
	 * Action Scheduler can look "healthy" on a new site while WP-Cron never fires (no traffic).
	 * Only skip browser tab drain when External Cron is enabled — otherwise the admin UI polls drain_job.
	 */
	function sheetsync_prefers_background_queue(): bool {
		return class_exists( 'SheetSync_External_Cron', false ) && SheetSync_External_Cron::is_enabled();
	}
}

if ( ! function_exists( 'sheetsync_admin_request_budget_seconds' ) ) {
	/**
	 * Max seconds of inline job work per admin AJAX request (stay under gateway ~10–30s limits).
	 */
	function sheetsync_admin_request_budget_seconds(): int {
		if ( class_exists( 'SheetSync_Host_Profile', false ) ) {
			return SheetSync_Host_Profile::admin_request_budget_seconds();
		}
		return 4;
	}
}

if ( ! function_exists( 'sheetsync_record_host_timeout' ) ) {
	/**
	 * Learn from gateway timeouts and auto-tighten future request budgets.
	 */
	function sheetsync_record_host_timeout(): void {
		if ( class_exists( 'SheetSync_Host_Profile', false ) ) {
			SheetSync_Host_Profile::record_timeout();
		}
	}
}

if ( ! function_exists( 'sheetsync_job_tick_deadline_seconds' ) ) {
	/**
	 * Max seconds per Action Scheduler job tick (stay under PHP/host limits).
	 */
	function sheetsync_job_tick_deadline_seconds(): int {
		$seconds = (int) apply_filters( 'sheetsync_job_tick_deadline_seconds', 25 );
		if ( apply_filters( 'sheetsync_inline_job_drain', false ) ) {
			$seconds = min( $seconds, 22 );
		}
		return max( 12, min( 28, $seconds ) );
	}
}

if ( ! function_exists( 'sheetsync_job_tick_deadline' ) ) {
	/**
	 * Unix timestamp when the current job tick should stop processing rows.
	 */
	function sheetsync_job_tick_deadline(): int {
		return time() + sheetsync_job_tick_deadline_seconds();
	}
}

if ( ! function_exists( 'sheetsync_lock_ttl_for_estimate' ) ) {
	/**
	 * Connection lock TTL scaled to catalog size (prevents expiry mid 100k+ sync).
	 */
	function sheetsync_lock_ttl_for_estimate( int $total_estimate ): int {
		$total = max( 0, $total_estimate );
		if ( $total >= 100000 ) {
			$ttl = 1800;
		} elseif ( $total >= 50000 ) {
			$ttl = 1200;
		} elseif ( $total >= 10000 ) {
			$ttl = 900;
		} elseif ( $total >= 1000 ) {
			$ttl = 600;
		} else {
			$ttl = 300;
		}
		return max( 300, min( 3600, (int) apply_filters( 'sheetsync_lock_ttl_seconds', $ttl, $total ) ) );
	}
}

if ( ! function_exists( 'sheetsync_lock_ttl_for_job' ) ) {
	/**
	 * Lock TTL for an active sync job row.
	 */
	function sheetsync_lock_ttl_for_job( object $job ): int {
		$total = (int) ( $job->total_estimate ?? 0 );
		if ( $total <= 0 && (int) ( $job->cursor_offset ?? 0 ) > 0 ) {
			$total = (int) $job->cursor_offset;
		}
		return sheetsync_lock_ttl_for_estimate( $total );
	}
}

if ( ! function_exists( 'sheetsync_resolve_batch_sizes' ) ) {
	/**
	 * Adaptive pull/push batch sizes for large catalogs (smaller batches = more reliable ticks).
	 *
	 * @param array<string, mixed> $settings Connection sync_state settings.
	 * @return array{pull: int, push: int}
	 */
	function sheetsync_resolve_batch_sizes( object $job, array $settings ): array {
		$pull = max( 25, (int) ( $settings['pull_batch_size'] ?? 200 ) );
		$push = max( 10, (int) ( $settings['push_batch_size'] ?? 100 ) );

		$global_settings = get_option( 'sheetsync_settings', array() );
		$default_batch   = class_exists( 'SheetSync_Host_Profile', false )
			? SheetSync_Host_Profile::default_batch_size()
			: 25;
		$user_batch      = max( 5, min( 100, (int) ( $global_settings['batch_size'] ?? $default_batch ) ) );
		$push            = min( $push, $user_batch );
		$pull            = min( $pull, class_exists( 'SheetSync_Host_Profile', false ) ? SheetSync_Host_Profile::max_pull_batch() : 60 );

		$job_direction = (string) ( $job->direction ?? '' );
		$job_mode      = (string) ( $job->mode ?? '' );
		$host_push_cap = class_exists( 'SheetSync_Host_Profile', false )
			? SheetSync_Host_Profile::max_push_batch( $job_direction )
			: 15;
		if ( in_array( $job_direction, array( 'bootstrap', 'push' ), true ) ) {
			$push = min( $push, $host_push_cap );
		}
		if ( 'full' === $job_mode && in_array( $job_direction, array( 'bootstrap', 'push', 'two_way' ), true ) ) {
			$push = min( $push, max( 5, $user_batch ) );
		}
		if ( in_array( $job_direction, array( 'pull', 'two_way' ), true ) ) {
			$pull = min( $pull, max( 10, $user_batch ) );
		}
		if ( class_exists( 'SheetSync_Host_Profile', false ) && SheetSync_Host_Profile::is_tight() ) {
			$pull = min( $pull, 20 );
		}

		$total = max(
			(int) ( $job->total_estimate ?? 0 ),
			(int) ( $job->cursor_offset ?? 0 ),
			(int) ( $job->processed_count ?? 0 )
		);

		if ( $total >= 100000 ) {
			$pull = min( $pull, 75 );
			$push = min( $push, 40 );
		} elseif ( $total >= 50000 ) {
			$pull = min( $pull, 100 );
			$push = min( $push, 50 );
		} elseif ( $total >= 10000 ) {
			$pull = min( $pull, 150 );
			$push = min( $push, 75 );
		} elseif ( $total >= 5000 ) {
			$pull = min( $pull, 175 );
			$push = min( $push, 90 );
		}

		$min_pull = ( class_exists( 'SheetSync_Host_Profile', false ) && SheetSync_Host_Profile::is_tight() ) ? 10 : 15;

		return array(
			'pull' => max( $min_pull, min( 300, (int) apply_filters( 'sheetsync_pull_batch_size', $pull, $job ) ) ),
			'push' => max( 10, min( 150, (int) apply_filters( 'sheetsync_push_batch_size', max( 10, min( 100, $push ) ), $job ) ) ),
		);
	}
}

if ( ! function_exists( 'sheetsync_estimate_sheet_data_rows' ) ) {
	/**
	 * Estimate data rows on a Google Sheet tab (for pull progress / total_estimate).
	 */
	function sheetsync_estimate_sheet_data_rows( object $conn, bool $allow_api = true ): int {
		$header_row = max( 1, (int) ( $conn->header_row ?? 1 ) );
		$cache_key  = 'sheetsync_sheet_rows_' . md5(
			(string) ( $conn->spreadsheet_id ?? '' ) . '|' . (string) ( $conn->sheet_name ?? '' )
		);
		$cached     = get_transient( $cache_key );
		if ( is_numeric( $cached ) && (int) $cached > $header_row ) {
			return max( 0, (int) $cached - $header_row );
		}

		if ( ! $allow_api || ! class_exists( 'SheetSync_Sheets_Client', false ) ) {
			return 0;
		}

		try {
			$client = new SheetSync_Sheets_Client();
			$grid   = $client->get_sheet_grid_size(
				(string) $conn->spreadsheet_id,
				(string) $conn->sheet_name
			);
			$rows   = max( 1, (int) ( $grid['rows'] ?? 1 ) );
			set_transient( $cache_key, $rows, HOUR_IN_SECONDS );
			return max( 0, $rows - $header_row );
		} catch ( Throwable $e ) {
			return 0;
		}
	}
}

if ( ! function_exists( 'sheetsync_stale_job_close_seconds' ) ) {
	/**
	 * How long before a stale job is auto-closed (scales with catalog size).
	 */
	function sheetsync_stale_job_close_seconds( object $job ): int {
		$total  = max( (int) ( $job->total_estimate ?? 0 ), (int) ( $job->cursor_offset ?? 0 ) );
		$scaled = max( 600, min( 7200, (int) ceil( $total / 40 ) ) );
		return max( 600, min( 7200, (int) apply_filters( 'sheetsync_stale_job_close_seconds', $scaled, $job ) ) );
	}
}

if ( ! function_exists( 'sheetsync_job_progress_numbers' ) ) {
	/**
	 * Display-safe export progress (map-backed done count, capped at total).
	 *
	 * @return array{done: int, total: int, pct: int|null}
	 */
	function sheetsync_job_progress_numbers( object $job ): array {
		$connection_id = (int) ( $job->connection_id ?? 0 );
		$direction     = (string) ( $job->direction ?? '' );
		$phase         = (string) ( $job->phase ?? '' );
		$done          = (int) ( $job->processed_count ?? 0 );
		$total         = (int) ( $job->total_estimate ?? 0 );

		// Sheet → WooCommerce pull: show rows scanned, not export map count.
		if ( 'pull' === $direction || ( 'two_way' === $direction && 'pull' === $phase ) ) {
			$scanned = max(
				(int) ( $job->cursor_offset ?? 0 ),
				(int) ( $job->processed_count ?? 0 ) + (int) ( $job->skipped_count ?? 0 )
			);
			if ( $scanned > 0 ) {
				$done = $scanned;
			}
			if ( $total <= 0 && $connection_id > 0 ) {
				if ( function_exists( 'sheetsync_connection_row_breakdown' ) ) {
					$breakdown = sheetsync_connection_row_breakdown( $connection_id, 'import' );
					$total     = (int) ( $breakdown['sheet_rows'] ?? 0 );
				}
				if ( $total <= 0 && class_exists( 'SheetSync_Sync_Engine', false ) && function_exists( 'sheetsync_estimate_sheet_data_rows' ) ) {
					$conn = SheetSync_Sync_Engine::get_connection( $connection_id );
					if ( $conn ) {
						$total = sheetsync_estimate_sheet_data_rows( $conn, false );
					}
				}
				if ( $total <= 0 && class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
					$total = ( new SheetSync_Product_Map_Repository() )->count_for_connection( $connection_id );
				}
			}
		}

		if ( $total > 0 ) {
			$total = max( $total, $done );
			$done  = min( $done, $total );
			$pct   = min( 100, (int) round( ( $done / $total ) * 100 ) );
		} else {
			$pct = null;
		}

		return array(
			'done'  => $done,
			'total' => $total,
			'pct'   => $pct,
		);
	}
}

if ( ! function_exists( 'sheetsync_estimate_job_eta_minutes' ) ) {
	/**
	 * Rough ETA for large background sync jobs (conservative — API quota may add delay).
	 */
	function sheetsync_estimate_job_eta_minutes( object $job ): ?int {
		if ( ! function_exists( 'sheetsync_job_progress_numbers' ) ) {
			return null;
		}

		$progress  = sheetsync_job_progress_numbers( $job );
		$total     = (int) ( $progress['total'] ?? 0 );
		$done      = (int) ( $progress['done'] ?? 0 );
		$remaining = max( 0, $total - $done );

		if ( $remaining <= 0 ) {
			return null;
		}

		$direction = (string) ( $job->direction ?? '' );
		$phase     = (string) ( $job->phase ?? '' );

		if ( in_array( $direction, array( 'bootstrap', 'push' ), true )
			|| ( 'two_way' === $direction && 'push' === $phase ) ) {
			$rows_per_min = (int) apply_filters( 'sheetsync_eta_export_rows_per_minute', 55, $job );
		} elseif ( 'pull' === $direction || ( 'two_way' === $direction && 'pull' === $phase ) ) {
			$rows_per_min = (int) apply_filters( 'sheetsync_eta_pull_rows_per_minute', 130, $job );
		} elseif ( 'two_way' === $direction ) {
			$rows_per_min = (int) apply_filters( 'sheetsync_eta_two_way_rows_per_minute', 45, $job );
		} else {
			$rows_per_min = (int) apply_filters( 'sheetsync_eta_default_rows_per_minute', 90, $job );
		}

		$rows_per_min = max( 10, $rows_per_min );

		return max( 1, (int) ceil( $remaining / $rows_per_min ) );
	}
}

if ( ! function_exists( 'sheetsync_human_phase_label' ) ) {
	/**
	 * Merchant-friendly label for the current job phase.
	 */
	function sheetsync_human_phase_label( object $job ): string {
		$phase     = (string) ( $job->phase ?? '' );
		$direction = (string) ( $job->direction ?? '' );

		if ( 'finalize' === $phase ) {
			return __( 'Finishing up (headers & styling)', 'sheetsync-for-woocommerce' );
		}
		if ( 'pull' === $phase || 'pull' === $direction ) {
			return __( 'Updating WooCommerce from sheet', 'sheetsync-for-woocommerce' );
		}
		if ( 'push' === $phase || in_array( $direction, array( 'push', 'bootstrap' ), true ) ) {
			return __( 'Writing products to sheet', 'sheetsync-for-woocommerce' );
		}
		if ( str_starts_with( $phase, 'order_' ) || str_starts_with( $direction, 'order_' ) ) {
			return __( 'Syncing orders', 'sheetsync-for-woocommerce' );
		}
		if ( 'init' === $phase || '' === $phase ) {
			return __( 'Starting sync', 'sheetsync-for-woocommerce' );
		}

		return __( 'Processing', 'sheetsync-for-woocommerce' );
	}
}

if ( ! function_exists( 'sheetsync_job_rest_payload' ) ) {
	/**
	 * Normalized job payload for REST polling (progress + ETA).
	 *
	 * @return array<string, mixed>
	 */
	function sheetsync_job_rest_payload( object $job ): array {
		$progress = function_exists( 'sheetsync_job_progress_numbers' )
			? sheetsync_job_progress_numbers( $job )
			: array(
				'done'  => (int) ( $job->processed_count ?? 0 ),
				'total' => (int) ( $job->total_estimate ?? 0 ),
				'pct'   => null,
			);

		$pct = $progress['pct'];
		if ( in_array( (string) ( $job->status ?? '' ), array( 'completed', 'failed' ), true ) ) {
			$pct = 100;
		} elseif ( 'running' === ( $job->status ?? '' ) && null === $pct ) {
			$pct = 0;
		}

		$eta = function_exists( 'sheetsync_estimate_job_eta_minutes' )
			? sheetsync_estimate_job_eta_minutes( $job )
			: null;

		$scheduler_degraded = false;
		if ( function_exists( 'sheetsync_get_action_scheduler_health' ) ) {
			$scheduler_degraded = ! ( sheetsync_get_action_scheduler_health()['ok'] ?? true );
		}
		if ( $scheduler_degraded && null !== $eta && $eta > 0 ) {
			$eta = max( $eta, (int) ceil( $eta * 2.2 ) );
		}

		return array(
			'id'              => (int) ( $job->id ?? 0 ),
			'connection_id'   => (int) ( $job->connection_id ?? 0 ),
			'status'          => (string) ( $job->status ?? '' ),
			'phase'           => (string) ( $job->phase ?? '' ),
			'phase_label'     => function_exists( 'sheetsync_human_phase_label' )
				? sheetsync_human_phase_label( $job )
				: (string) ( $job->phase ?? '' ),
			'direction'       => (string) ( $job->direction ?? '' ),
			'mode'            => (string) ( $job->mode ?? '' ),
			'cursor_offset'   => (int) ( $job->cursor_offset ?? 0 ),
			'processed_count' => (int) ( $progress['done'] ?? 0 ),
			'skipped_count'   => (int) ( $job->skipped_count ?? 0 ),
			'error_count'     => (int) ( $job->error_count ?? 0 ),
			'last_error'      => $job->last_error ?? null,
			'total_estimate'  => (int) ( $progress['total'] ?? 0 ),
			'progress_pct'    => $pct,
			'eta_minutes'     => $eta,
			'updated_count'   => (int) ( $job->processed_count ?? 0 ),
			'scheduler_degraded' => $scheduler_degraded,
			'requires_tab_open'  => $scheduler_degraded
				&& in_array( (string) ( $job->status ?? '' ), array( 'pending', 'running' ), true ),
		);
	}
}

if ( ! function_exists( 'sheetsync_connection_sync_status_html' ) ) {
	/**
	 * Human-readable sync status for connection cards (list / health).
	 */
	function sheetsync_connection_sync_status_html( int $connection_id, ?string $last_sync_at ): string {
		if ( class_exists( 'SheetSync_Job_Repository', false ) ) {
			$running = ( new SheetSync_Job_Repository() )->get_running_for_connection( $connection_id );
			if ( $running ) {
				$progress = sheetsync_job_progress_numbers( $running );
				$done     = (int) $progress['done'];
				$total    = (int) $progress['total'];
				if ( $total > 0 ) {
					return sprintf(
						/* translators: 1: rows done, 2: total rows */
						esc_html__( 'Sync in progress: %1$d / %2$d rows', 'sheetsync-for-woocommerce' ),
						$done,
						$total
					);
				}
				return esc_html__( 'Sync in progress…', 'sheetsync-for-woocommerce' );
			}
		}

		if ( $last_sync_at ) {
			return esc_html( human_time_diff( strtotime( $last_sync_at ), time() ) . ' ' . __( 'ago', 'sheetsync-for-woocommerce' ) );
		}

		return '<em>' . esc_html__( 'Never synced', 'sheetsync-for-woocommerce' ) . '</em>';
	}
}

if ( ! function_exists( 'sheetsync_count_all_conflicts' ) ) {
	/**
	 * Total queued two-way conflicts across all product connections.
	 */
	function sheetsync_count_all_conflicts(): int {
		if ( ! class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			return 0;
		}
		global $wpdb;
		$table = SheetSync_Schema::table( 'product_map' );
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT COUNT(*) FROM {$table} WHERE sync_status = 'conflict'"
		);
	}
}

if ( ! function_exists( 'sheetsync_list_all_conflicts' ) ) {
	/**
	 * @return object[]
	 */
	function sheetsync_list_all_conflicts( int $limit = 50 ): array {
		if ( ! class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			return array();
		}
		global $wpdb;
		$limit   = max( 1, min( 100, $limit ) );
		$map_tbl = SheetSync_Schema::table( 'product_map' );
		$conn_tbl = $wpdb->prefix . 'sheetsync_connections';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.*, c.name AS connection_name
				FROM {$map_tbl} pm
				INNER JOIN {$conn_tbl} c ON c.id = pm.connection_id
				WHERE pm.sync_status = 'conflict'
				ORDER BY pm.id DESC
				LIMIT %d",
				$limit
			)
		) ?: array();
	}
}

if ( ! function_exists( 'sheetsync_orchestrator_rest_url' ) ) {
	function sheetsync_orchestrator_rest_url( int $connection_id ): string {
		return rest_url( 'sheetsync/v1/connections/' . $connection_id . '/sync' );
	}
}

if ( ! function_exists( 'sheetsync_job_action_hooks' ) ) {
	/**
	 * Action Scheduler hooks used for sync jobs.
	 *
	 * @return string[]
	 */
	function sheetsync_job_action_hooks(): array {
		return array( 'sheetsync_run_job', 'sheetsync_batch_push', 'sheetsync_batch_pull' );
	}
}

if ( ! function_exists( 'sheetsync_job_has_scheduled_action' ) ) {
	/**
	 * True when an Action Scheduler task for this job is already queued.
	 */
	function sheetsync_job_has_scheduled_action( int $job_id ): bool {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return false;
		}
		foreach ( sheetsync_job_action_hooks() as $hook ) {
			if ( as_next_scheduled_action( $hook, array( 'job_id' => $job_id ), 'sheetsync' ) ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'sheetsync_reschedule_stuck_job' ) ) {
	/**
	 * Re-queue a job when sync_jobs is pending but Action Scheduler never picked it up.
	 */
	function sheetsync_reschedule_stuck_job( int $job_id, int $delay = 0 ): bool {
		if ( $job_id < 1 || ! function_exists( 'sheetsync_schedule_job_action' ) ) {
			return false;
		}
		if ( sheetsync_job_has_scheduled_action( $job_id ) ) {
			return false;
		}
		sheetsync_schedule_job_action( $job_id, 'sheetsync_run_job', max( 0, $delay ) );
		return true;
	}
}

if ( ! function_exists( 'sheetsync_kick_background_queue' ) ) {
	/**
	 * Run pending SheetSync Action Scheduler tasks immediately (manual sync / admin).
	 */
	function sheetsync_kick_background_queue( int $max_seconds = 12 ): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return;
		}

		$pending = as_get_scheduled_actions(
			array(
				'hook'     => sheetsync_job_action_hooks(),
				'status'   => 'pending',
				'group'    => 'sheetsync',
				'per_page' => 5,
			),
			'ids'
		);

		if ( empty( $pending ) ) {
			return;
		}

		if ( class_exists( 'ActionScheduler_QueueRunner', false ) ) {
			try {
				ActionScheduler_QueueRunner::instance()->run( max( 3, $max_seconds ) );
			} catch ( Throwable $e ) {
				// Non-fatal — WP-Cron may pick up later.
			}
		}
	}
}

if ( ! function_exists( 'sheetsync_acquire_job_mutex' ) ) {
	/**
	 * Prevent concurrent Action Scheduler ticks for the same job_id.
	 */
	function sheetsync_acquire_job_mutex( int $job_id, int $ttl_seconds = 120 ): bool {
		if ( $job_id < 1 ) {
			return false;
		}
		$key = 'sheetsync_job_mutex_' . $job_id;
		$ttl = max( 30, $ttl_seconds );
		if ( wp_cache_add( $key, 1, 'sheetsync', $ttl ) ) {
			return true;
		}

		$opt_key = '_sheetsync_jm_' . $job_id;
		$now     = time();
		$held    = get_option( $opt_key );
		if ( is_string( $held ) && $held !== '' && ( $now - (int) $held ) < $ttl ) {
			return false;
		}
		if ( false !== $held ) {
			delete_option( $opt_key );
		}
		if ( add_option( $opt_key, (string) $now, '', 'no' ) ) {
			return true;
		}

		return false;
	}
}

if ( ! function_exists( 'sheetsync_release_job_mutex' ) ) {
	function sheetsync_release_job_mutex( int $job_id ): void {
		if ( $job_id < 1 ) {
			return;
		}
		$key = 'sheetsync_job_mutex_' . $job_id;
		wp_cache_delete( $key, 'sheetsync' );
		delete_transient( $key );
		delete_option( '_sheetsync_jm_' . $job_id );
	}
}

if ( ! function_exists( 'sheetsync_bulk_import_lock_key' ) ) {
	function sheetsync_bulk_import_lock_key( int $connection_id ): string {
		return 'sheetsync_bulk_import_' . max( 0, $connection_id );
	}
}

if ( ! function_exists( 'sheetsync_acquire_bulk_import_session' ) ) {
	/**
	 * Prevent concurrent multi-batch AJAX imports for the same connection.
	 *
	 * @return array{ok: bool, session_id: string}
	 */
	function sheetsync_acquire_bulk_import_session( int $connection_id, string $session_id, bool $is_first_batch ): array {
		if ( $connection_id < 1 ) {
			return array(
				'ok'         => false,
				'session_id' => '',
			);
		}
		$key = sheetsync_bulk_import_lock_key( $connection_id );
		if ( $is_first_batch ) {
			$opt_key    = '_sheetsync_bi_' . $connection_id;
			$session_id = $session_id !== '' ? $session_id : wp_generate_uuid4();
			if ( ! add_option( $opt_key, $session_id, '', 'no' ) ) {
				$existing = get_option( $opt_key );
				if ( is_string( $existing ) && $existing !== '' ) {
					return array(
						'ok'         => false,
						'session_id' => '',
					);
				}
				delete_option( $opt_key );
				if ( ! add_option( $opt_key, $session_id, '', 'no' ) ) {
					return array(
						'ok'         => false,
						'session_id' => '',
					);
				}
			}
			set_transient( $key, $session_id, HOUR_IN_SECONDS );
			return array(
				'ok'         => true,
				'session_id' => $session_id,
			);
		}
		if ( $session_id === '' ) {
			return array(
				'ok'         => false,
				'session_id' => '',
			);
		}
		$stored = get_transient( $key );
		if ( ! is_string( $stored ) || ! hash_equals( $stored, $session_id ) ) {
			return array(
				'ok'         => false,
				'session_id' => $session_id,
			);
		}
		set_transient( $key, $session_id, HOUR_IN_SECONDS );
		return array(
			'ok'         => true,
			'session_id' => $session_id,
		);
	}
}

if ( ! function_exists( 'sheetsync_release_bulk_import_session' ) ) {
	function sheetsync_release_bulk_import_session( int $connection_id ): void {
		if ( $connection_id < 1 ) {
			return;
		}
		delete_transient( sheetsync_bulk_import_lock_key( $connection_id ) );
		delete_option( '_sheetsync_bi_' . $connection_id );
	}
}

if ( ! function_exists( 'sheetsync_is_bulk_import_active' ) ) {
	/**
	 * True when a multi-batch AJAX import holds the connection.
	 */
	function sheetsync_is_bulk_import_active( int $connection_id ): bool {
		if ( $connection_id < 1 ) {
			return false;
		}
		$stored = get_transient( sheetsync_bulk_import_lock_key( $connection_id ) );
		return is_string( $stored ) && $stored !== '';
	}
}

if ( ! function_exists( 'sheetsync_schedule_job_action' ) ) {
	/**
	 * Schedule an Action Scheduler job (inline immediately when draining manual sync).
	 */
	function sheetsync_schedule_job_action( int $job_id, string $hook, int $delay = 0 ): void {
		$args = array( 'job_id' => $job_id );

		if ( sheetsync_is_inline_job_drain() ) {
			do_action( $hook, $job_id );
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( in_array( $hook, sheetsync_job_action_hooks(), true )
				&& function_exists( 'sheetsync_job_has_scheduled_action' )
				&& sheetsync_job_has_scheduled_action( $job_id ) ) {
				return;
			}
			if ( as_next_scheduled_action( $hook, $args, 'sheetsync' ) ) {
				return;
			}
			$delay = max( $delay, (int) apply_filters( 'sheetsync_job_schedule_delay_seconds', 0 ) );
			as_schedule_single_action( time() + $delay, $hook, $args, 'sheetsync' );
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( in_array( $hook, sheetsync_job_action_hooks(), true )
				&& function_exists( 'sheetsync_job_has_scheduled_action' )
				&& sheetsync_job_has_scheduled_action( $job_id ) ) {
				return;
			}
			as_enqueue_async_action( $hook, $args, 'sheetsync' );
			return;
		}
		do_action( $hook, $job_id );
	}
}

if ( ! function_exists( 'sheetsync_process_job_inline' ) ) {
	/**
	 * Run job phases in the current request (manual sync) so push runs without waiting for cron.
	 */
	function sheetsync_process_job_inline( int $job_id, int $max_seconds = 28 ): void {
		if ( ! class_exists( 'SheetSync_Job_Runner', false ) ) {
			return;
		}

		add_filter( 'sheetsync_inline_job_drain', '__return_true', 99 );
		add_filter( 'sheetsync_skip_per_batch_cell_clip', '__return_true', 99 );

		$runner   = new SheetSync_Job_Runner();
		$repo     = new SheetSync_Job_Repository();
		$deadline = time() + $max_seconds;
		$loops    = 0;
		$default_loops = class_exists( 'SheetSync_Host_Profile', false )
			? SheetSync_Host_Profile::inline_drain_max_loops()
			: 10;
		$max_loops = max( 1, (int) apply_filters( 'sheetsync_inline_job_max_loops', $default_loops ) );

		while ( time() < $deadline && $loops < $max_loops ) {
			++$loops;
			$job = $repo->get( $job_id );
			if ( ! $job || in_array( $job->status, array( 'completed', 'failed', 'cancelled' ), true ) ) {
				break;
			}
			$runner->tick( $job_id );
		}

		remove_filter( 'sheetsync_skip_per_batch_cell_clip', '__return_true', 99 );
		remove_filter( 'sheetsync_inline_job_drain', '__return_true', 99 );
	}
}

if ( ! function_exists( 'sheetsync_mark_product_dirty' ) ) {
	function sheetsync_mark_product_dirty( int $connection_id, int $product_id ): void {
		if ( $product_id < 1 ) {
			return;
		}
		$key   = 'sheetsync_dirty_products_' . $connection_id;
		$dirty = get_transient( $key );
		if ( ! is_array( $dirty ) ) {
			$dirty = array();
		}
		$dirty[ $product_id ] = time();
		set_transient( $key, $dirty, 15 * MINUTE_IN_SECONDS );

		if ( function_exists( 'as_next_scheduled_action' ) ) {
			if ( ! as_next_scheduled_action( 'sheetsync_deferred_push', array( $connection_id ), 'sheetsync' ) ) {
				as_schedule_single_action( time() + 5, 'sheetsync_deferred_push', array( $connection_id ), 'sheetsync' );
			}
		}
	}
}

if ( ! function_exists( 'sheetsync_legacy_two_way_full_push' ) ) {
	function sheetsync_legacy_two_way_full_push(): bool {
		return (bool) get_option( 'sheetsync_legacy_two_way_full_push', false );
	}
}

if ( ! function_exists( 'sheetsync_connection_allows_push' ) ) {
	function sheetsync_connection_allows_push( object $conn ): bool {
		return in_array( (string) ( $conn->sync_direction ?? '' ), array( 'two_way', 'wc_to_sheets' ), true );
	}
}

if ( ! function_exists( 'sheetsync_connection_allows_pull' ) ) {
	function sheetsync_connection_allows_pull( object $conn ): bool {
		return in_array( (string) ( $conn->sync_direction ?? '' ), array( 'two_way', 'sheets_to_wc' ), true );
	}
}

if ( ! function_exists( 'sheetsync_is_sync_locked' ) ) {
	function sheetsync_is_sync_locked( int $connection_id ): bool {
		if ( ! class_exists( 'SheetSync_Sync_State_Repository', false ) ) {
			return false;
		}
		return ( new SheetSync_Sync_State_Repository() )->is_locked( $connection_id );
	}
}

if ( ! function_exists( 'sheetsync_realtime_sync_enabled' ) ) {
	function sheetsync_realtime_sync_enabled( int $connection_id ): bool {
		$auto_sync = get_option( 'sheetsync_auto_sync_settings', array() );
		return is_array( $auto_sync ) && ! empty( $auto_sync[ $connection_id ] );
	}
}

if ( ! function_exists( 'sheetsync_automatic_sync_option_key' ) ) {
	function sheetsync_automatic_sync_option_key( int $connection_id ): string {
		return 'sheetsync_automatic_sync_' . max( 0, $connection_id );
	}
}

if ( ! function_exists( 'sheetsync_automatic_sync_prefs_key' ) ) {
	function sheetsync_automatic_sync_prefs_key( int $connection_id ): string {
		return 'sheetsync_automatic_sync_prefs_' . max( 0, $connection_id );
	}
}

if ( ! function_exists( 'sheetsync_connection_pulls_from_sheet' ) ) {
	function sheetsync_connection_pulls_from_sheet( $connection ): bool {
		$direction = is_object( $connection ) ? (string) ( $connection->sync_direction ?? 'sheets_to_wc' ) : '';
		return in_array( $direction, array( 'sheets_to_wc', 'two_way' ), true );
	}
}

if ( ! function_exists( 'sheetsync_connection_pushes_to_sheet' ) ) {
	function sheetsync_connection_pushes_to_sheet( $connection ): bool {
		$direction = is_object( $connection ) ? (string) ( $connection->sync_direction ?? 'sheets_to_wc' ) : '';
		return in_array( $direction, array( 'wc_to_sheets', 'two_way' ), true );
	}
}

if ( ! function_exists( 'sheetsync_schedule_interval_label' ) ) {
	function sheetsync_schedule_interval_label( string $interval ): string {
		$labels = array(
			'sheetsync_15min' => __( 'Every 15 min', 'sheetsync-for-woocommerce' ),
			'sheetsync_30min' => __( 'Every 30 min', 'sheetsync-for-woocommerce' ),
			'sheetsync_1hour' => __( 'Every hour', 'sheetsync-for-woocommerce' ),
			'twicedaily'      => __( 'Twice daily', 'sheetsync-for-woocommerce' ),
			'daily'           => __( 'Once daily', 'sheetsync-for-woocommerce' ),
		);
		return $labels[ $interval ] ?? $interval;
	}
}

if ( ! function_exists( 'sheetsync_is_automatic_sync_enabled' ) ) {
	/**
	 * Merchant-facing master flag for automatic sync (poll + schedule + on-save).
	 */
	function sheetsync_is_automatic_sync_enabled( int $connection_id ): bool {
		$master = get_option( sheetsync_automatic_sync_option_key( $connection_id ), null );
		if ( null !== $master ) {
			return (bool) $master;
		}
		if ( sheetsync_realtime_sync_enabled( $connection_id ) ) {
			return true;
		}
		if ( get_option( 'sheetsync_auto_on_save_' . $connection_id, false ) ) {
			return true;
		}
		return ! empty( get_option( 'sheetsync_schedule_' . $connection_id, '' ) );
	}
}

if ( ! function_exists( 'sheetsync_set_realtime_sync' ) ) {
	function sheetsync_set_realtime_sync( int $connection_id, bool $enabled ): void {
		$auto_sync_settings = get_option( 'sheetsync_auto_sync_settings', array() );
		if ( ! is_array( $auto_sync_settings ) ) {
			$auto_sync_settings = array();
		}
		$auto_sync_settings[ $connection_id ] = $enabled;
		update_option( 'sheetsync_auto_sync_settings', $auto_sync_settings );

		if ( class_exists( 'SheetSync_Order_Sheet_Poller', false ) ) {
			SheetSync_Order_Sheet_Poller::sync_schedule();
		}
		if ( class_exists( 'SheetSync_Product_Sheet_Poller', false ) ) {
			SheetSync_Product_Sheet_Poller::sync_schedule();
		}
		if ( ! $enabled && class_exists( 'SheetSync_Product_Sheet_Poller', false ) ) {
			SheetSync_Product_Sheet_Poller::reset_connection_cursor( $connection_id );
		}
		if ( $enabled ) {
			delete_option( 'sheetsync_webhook_verified_' . $connection_id );
		}
	}
}

if ( ! function_exists( 'sheetsync_automatic_sync_status_lines' ) ) {
	/**
	 * Human-readable status for the unified automatic sync control.
	 *
	 * @return string[]
	 */
	function sheetsync_automatic_sync_status_lines( int $connection_id, $connection = null ): array {
		if ( ! sheetsync_is_automatic_sync_enabled( $connection_id ) ) {
			return array();
		}

		if ( null === $connection && class_exists( 'SheetSync_Sync_Engine', false ) ) {
			$connection = SheetSync_Sync_Engine::get_connection( $connection_id );
		}
		if ( ! $connection ) {
			return array();
		}

		$lines     = array();
		$is_orders = class_exists( 'SheetSync_Sync_Engine', false )
			&& SheetSync_Sync_Engine::is_orders_type( (string) ( $connection->connection_type ?? 'products' ) );
		$pulls     = sheetsync_connection_pulls_from_sheet( $connection );
		$pushes    = sheetsync_connection_pushes_to_sheet( $connection );

		if ( $pulls && sheetsync_realtime_sync_enabled( $connection_id ) ) {
			$rt_status = class_exists( 'SheetSync_Webhook_Handler', false )
				? SheetSync_Webhook_Handler::get_realtime_status( $connection_id )
				: array();
			if ( ! empty( $rt_status['verified'] ) ) {
				$lines[] = __( 'Sheet edits: instant webhook (verified)', 'sheetsync-for-woocommerce' );
			} else {
				if ( $is_orders && class_exists( 'SheetSync_Order_Sheet_Poller', false ) ) {
					$poll = SheetSync_Order_Sheet_Poller::get_interval_label();
				} elseif ( class_exists( 'SheetSync_Product_Sheet_Poller', false ) ) {
					$poll = SheetSync_Product_Sheet_Poller::get_interval_label();
				} else {
					$poll = __( 'every few minutes', 'sheetsync-for-woocommerce' );
				}
				$lines[] = sprintf(
					/* translators: %s: poll frequency e.g. every 3 minutes */
					__( 'Sheet edits: Smart Poll %s', 'sheetsync-for-woocommerce' ),
					$poll
				);
				if ( $is_orders && class_exists( 'SheetSync_Order_Sheet_Poller', false ) ) {
					$last_poll = SheetSync_Order_Sheet_Poller::get_last_run_label();
					if ( $last_poll ) {
						$lines[] = $last_poll;
					}
					$next_poll = SheetSync_Order_Sheet_Poller::get_next_run_label();
					if ( $next_poll ) {
						$lines[] = sprintf(
							/* translators: %s: next scheduled poll datetime */
							__( 'Next sheet check: %s', 'sheetsync-for-woocommerce' ),
							$next_poll
						);
					}
				}
			}
		}

		if ( $pushes && ! $is_orders && get_option( 'sheetsync_auto_on_save_' . $connection_id, false ) ) {
			$lines[] = __( 'WooCommerce saves: push to sheet', 'sheetsync-for-woocommerce' );
		}

		$schedule = (string) get_option( 'sheetsync_schedule_' . $connection_id, '' );
		if ( $schedule ) {
			$label = sheetsync_schedule_interval_label( $schedule );
			$next  = class_exists( 'SheetSync_Cron_Manager', false )
				? SheetSync_Cron_Manager::get_next_run( $connection_id )
				: '';
			if ( $next ) {
				$lines[] = sprintf(
					/* translators: 1: schedule label, 2: next run datetime */
					__( 'Scheduled sync: %1$s (next: %2$s)', 'sheetsync-for-woocommerce' ),
					$label,
					$next
				);
			} else {
				$lines[] = sprintf(
					/* translators: %s: schedule label */
					__( 'Scheduled sync: %s', 'sheetsync-for-woocommerce' ),
					$label
				);
			}
		}

		return $lines;
	}
}

if ( ! function_exists( 'sheetsync_order_has_companion_connection' ) ) {
	/**
	 * Whether another order tab connection exists for row moves (e.g. Processing + Completed).
	 */
	function sheetsync_order_has_companion_connection( string $connection_type ): bool {
		$companions = array(
			'orders_pending'    => array( 'orders_processing', 'orders_completed', 'orders_on-hold' ),
			'orders_processing' => array( 'orders_completed', 'orders_pending' ),
			'orders_on-hold'    => array( 'orders_processing', 'orders_completed' ),
			'orders_completed'  => array( 'orders_processing' ),
			'orders_cancelled'  => array( 'orders_processing', 'orders_completed' ),
			'orders_refunded'   => array( 'orders_completed' ),
			'orders_failed'     => array( 'orders_processing' ),
		);

		$targets = $companions[ $connection_type ] ?? array();
		if ( empty( $targets ) ) {
			return true;
		}

		if ( ! class_exists( 'SheetSync_Sync_Engine', false ) ) {
			return false;
		}

		foreach ( SheetSync_Sync_Engine::get_active_connections( 'orders' ) as $conn ) {
			if ( in_array( (string) ( $conn->connection_type ?? '' ), $targets, true ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'sheetsync_order_connection_type_label' ) ) {
	/**
	 * Merchant-friendly label for an order connection type slug.
	 */
	function sheetsync_order_connection_type_label( string $connection_type ): string {
		$labels = array(
			'orders'            => __( 'All orders', 'sheetsync-for-woocommerce' ),
			'orders_pending'    => __( 'Pending', 'sheetsync-for-woocommerce' ),
			'orders_processing' => __( 'Processing', 'sheetsync-for-woocommerce' ),
			'orders_on-hold'    => __( 'On hold', 'sheetsync-for-woocommerce' ),
			'orders_completed'  => __( 'Completed', 'sheetsync-for-woocommerce' ),
			'orders_cancelled'    => __( 'Cancelled', 'sheetsync-for-woocommerce' ),
			'orders_refunded'   => __( 'Refunded', 'sheetsync-for-woocommerce' ),
			'orders_failed'     => __( 'Failed', 'sheetsync-for-woocommerce' ),
		);

		return $labels[ $connection_type ] ?? $connection_type;
	}
}

if ( ! function_exists( 'sheetsync_apply_automatic_sync' ) ) {
	/**
	 * Enable or disable all automatic sync transports for a connection.
	 *
	 * @return array{success:bool,enabled?:bool,status?:string[],message?:string}
	 */
	function sheetsync_apply_automatic_sync( int $connection_id, bool $enabled ): array {
		if ( $enabled && ! sheetsync_is_pro() ) {
			return array(
				'success' => false,
				'message' => __( 'Pro required for automatic sync.', 'sheetsync-for-woocommerce' ),
			);
		}

		if ( ! class_exists( 'SheetSync_Sync_Engine', false ) ) {
			return array(
				'success' => false,
				'message' => __( 'Sync engine unavailable.', 'sheetsync-for-woocommerce' ),
			);
		}

		$connection = SheetSync_Sync_Engine::get_connection( $connection_id );
		if ( ! $connection ) {
			return array(
				'success' => false,
				'message' => __( 'Connection not found.', 'sheetsync-for-woocommerce' ),
			);
		}

		$is_orders = SheetSync_Sync_Engine::is_orders_type( (string) ( $connection->connection_type ?? 'products' ) );
		$pulls     = sheetsync_connection_pulls_from_sheet( $connection );
		$pushes    = sheetsync_connection_pushes_to_sheet( $connection );

		update_option( sheetsync_automatic_sync_option_key( $connection_id ), $enabled ? 1 : 0, false );

		if ( $enabled ) {
			$prefs = get_option( sheetsync_automatic_sync_prefs_key( $connection_id ), array() );
			if ( ! is_array( $prefs ) ) {
				$prefs = array();
			}

			if ( $pulls ) {
				sheetsync_set_realtime_sync( $connection_id, true );
				if ( $is_orders && class_exists( 'SheetSync_Order_Sheet_Poller', false ) ) {
					if ( class_exists( 'SheetSync_Order_Sync', false ) ) {
						( new SheetSync_Order_Sync() )->ensure_sheet_user_experience( $connection );
					}
					( new SheetSync_Order_Sheet_Poller() )->run();
				}
			}

			if ( $pushes && ! $is_orders ) {
				update_option( 'sheetsync_auto_on_save_' . $connection_id, 1, false );
			}

			$schedule = (string) get_option( 'sheetsync_schedule_' . $connection_id, '' );
			if ( '' === $schedule ) {
				$schedule = ! empty( $prefs['schedule'] ) ? (string) $prefs['schedule'] : 'daily';
				update_option( 'sheetsync_schedule_' . $connection_id, $schedule, false );
				if ( class_exists( 'SheetSync_Cron_Manager', false ) ) {
					SheetSync_Cron_Manager::schedule( $connection_id, $schedule );
				}
			}
		} else {
			$schedule = (string) get_option( 'sheetsync_schedule_' . $connection_id, '' );
			if ( $schedule ) {
				$prefs = get_option( sheetsync_automatic_sync_prefs_key( $connection_id ), array() );
				if ( ! is_array( $prefs ) ) {
					$prefs = array();
				}
				$prefs['schedule'] = $schedule;
				update_option( sheetsync_automatic_sync_prefs_key( $connection_id ), $prefs, false );
			}

			sheetsync_set_realtime_sync( $connection_id, false );
			update_option( 'sheetsync_auto_on_save_' . $connection_id, 0, false );
			update_option( 'sheetsync_schedule_' . $connection_id, '', false );
			if ( class_exists( 'SheetSync_Cron_Manager', false ) ) {
				SheetSync_Cron_Manager::schedule( $connection_id, '' );
			}
		}

		return array(
			'success' => true,
			'enabled' => $enabled,
			'status'  => sheetsync_automatic_sync_status_lines( $connection_id, $connection ),
			'message' => $enabled
				? __( 'Automatic sync enabled.', 'sheetsync-for-woocommerce' )
				: __( 'Automatic sync disabled.', 'sheetsync-for-woocommerce' ),
		);
	}
}

if ( ! function_exists( 'sheetsync_webhook_secret_option_key' ) ) {
	/**
	 * Option name for a per-connection webhook secret.
	 */
	function sheetsync_webhook_secret_option_key( int $connection_id ): string {
		return 'sheetsync_webhook_secret_' . max( 0, $connection_id );
	}
}

if ( ! function_exists( 'sheetsync_ensure_connection_webhook_secret' ) ) {
	/**
	 * Ensure every connection has its own webhook secret (generated on first use).
	 */
	function sheetsync_ensure_connection_webhook_secret( int $connection_id ): string {
		if ( $connection_id < 1 ) {
			return '';
		}

		$key    = sheetsync_webhook_secret_option_key( $connection_id );
		$secret = (string) get_option( $key, '' );
		if ( $secret !== '' ) {
			return $secret;
		}

		$secret = wp_generate_password( 32, false );
		update_option( $key, $secret, false );

		return $secret;
	}
}

if ( ! function_exists( 'sheetsync_migrate_connection_webhook_secrets' ) ) {
	/**
	 * Backfill per-connection webhook secrets for existing installs.
	 */
	function sheetsync_migrate_connection_webhook_secrets(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'sheetsync_connections';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( "SELECT id FROM {$table}" );
		if ( ! is_array( $ids ) ) {
			return;
		}

		foreach ( $ids as $id ) {
			sheetsync_ensure_connection_webhook_secret( (int) $id );
		}
	}
}

if ( ! function_exists( 'sheetsync_resolve_webhook_secret' ) ) {
	/**
	 * Effective webhook secret for a connection (always per-connection when ID is set).
	 */
	function sheetsync_resolve_webhook_secret( int $connection_id ): string {
		if ( $connection_id > 0 ) {
			return sheetsync_ensure_connection_webhook_secret( $connection_id );
		}

		return (string) get_option( 'sheetsync_webhook_secret', '' );
	}
}

if ( ! function_exists( 'sheetsync_verify_webhook_secret' ) ) {
	/**
	 * Verify X-SheetSync-Secret against the connection secret (no cross-connection global fallback).
	 */
	function sheetsync_verify_webhook_secret( string $header, int $connection_id = 0 ): bool {
		if ( $header === '' ) {
			return false;
		}

		if ( $connection_id > 0 ) {
			$per_conn = (string) get_option( sheetsync_webhook_secret_option_key( $connection_id ), '' );
			return $per_conn !== '' && hash_equals( $per_conn, $header );
		}

		$global = (string) get_option( 'sheetsync_webhook_secret', '' );
		return $global !== '' && hash_equals( $global, $header );
	}
}

if ( ! function_exists( 'sheetsync_set_connection_webhook_secret' ) ) {
	/**
	 * Store a per-connection webhook secret (optional hardening).
	 */
	function sheetsync_set_connection_webhook_secret( int $connection_id, string $secret ): void {
		if ( $connection_id < 1 || $secret === '' ) {
			return;
		}
		update_option( sheetsync_webhook_secret_option_key( $connection_id ), $secret, false );
	}
}

if ( ! function_exists( 'sheetsync_delete_connection_webhook_secret' ) ) {
	function sheetsync_delete_connection_webhook_secret( int $connection_id ): void {
		if ( $connection_id < 1 ) {
			return;
		}
		delete_option( sheetsync_webhook_secret_option_key( $connection_id ) );
	}
}

if ( ! function_exists( 'sheetsync_order_in_webhook_scope' ) ) {
	/**
	 * Whether a WooCommerce order may be updated via webhook for this connection.
	 */
	function sheetsync_order_in_webhook_scope( WC_Order $order, object $conn ): bool {
		if ( ! $order instanceof WC_Order || ! isset( $conn->id ) ) {
			return false;
		}

		$connection_id = (int) $conn->id;
		$date_type     = (string) get_option( 'sheetsync_date_filter_type_' . $connection_id, 'all' );
		$created       = $order->get_date_created();

		if ( $date_type !== 'all' && $created ) {
			$ts = $created->getTimestamp();

			if ( 'single' === $date_type ) {
				$single = (string) get_option( 'sheetsync_date_filter_single_' . $connection_id, '' );
				if ( $single !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $single ) ) {
					$start = strtotime( $single . ' 00:00:00' );
					$end   = strtotime( $single . ' 23:59:59' );
					if ( $ts < $start || $ts > $end ) {
						return false;
					}
				}
			} elseif ( 'range' === $date_type ) {
				$from = (string) get_option( 'sheetsync_date_filter_from_' . $connection_id, '' );
				$to   = (string) get_option( 'sheetsync_date_filter_to_' . $connection_id, '' );
				if ( $from !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
					if ( $ts < strtotime( $from . ' 00:00:00' ) ) {
						return false;
					}
				}
				if ( $to !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
					if ( $ts > strtotime( $to . ' 23:59:59' ) ) {
						return false;
					}
				}
			}
		}

		return true;
	}
}

if ( ! function_exists( 'sheetsync_is_safe_remote_url' ) ) {
	/**
	 * Block SSRF to private/reserved networks (image probe + sideload).
	 */
	function sheetsync_is_safe_remote_url( string $url ): bool {
		$url = esc_url_raw( trim( $url ) );
		if ( $url === '' || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( $host === '' ) {
			return false;
		}

		$blocked_hosts = array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' );
		if ( in_array( $host, $blocked_hosts, true ) ) {
			return false;
		}
		if ( str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' ) ) {
			return false;
		}

		$ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			$resolved = gethostbyname( $host );
			if ( is_string( $resolved ) && $resolved !== $host && filter_var( $resolved, FILTER_VALIDATE_IP ) ) {
				$ips[] = $resolved;
			}
		}

		if ( empty( $ips ) ) {
			return false;
		}

		foreach ( $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}

		return true;
	}
}

if ( ! function_exists( 'sheetsync_webhook_is_duplicate' ) ) {
	/**
	 * Skip rapid duplicate webhook payloads for the same sheet row.
	 */
	function sheetsync_webhook_is_duplicate( int $connection_id, string $dedupe_key, ?int $ttl_seconds = null ): bool {
		return ! sheetsync_webhook_claim_dedupe( $connection_id, $dedupe_key, $ttl_seconds );
	}
}

if ( ! function_exists( 'sheetsync_webhook_claim_dedupe' ) ) {
	/**
	 * Atomically claim a webhook dedupe key (true = first delivery, process it).
	 */
	function sheetsync_webhook_claim_dedupe( int $connection_id, string $dedupe_key, ?int $ttl_seconds = null ): bool {
		if ( $connection_id < 1 || $dedupe_key === '' ) {
			return true;
		}
		if ( null === $ttl_seconds && class_exists( 'SheetSync_Sync_State_Repository', false ) ) {
			$settings    = ( new SheetSync_Sync_State_Repository() )->get_settings( $connection_id );
			$ttl_seconds = max( 1, (int) ( $settings['webhook_dedup_seconds'] ?? 3 ) );
		}
		$ttl_seconds = max( 1, (int) ( $ttl_seconds ?? 3 ) );
		$cache_key   = 'sheetsync_wh_dedup_' . $connection_id . '_' . md5( $dedupe_key );
		if ( wp_cache_add( $cache_key, 1, 'sheetsync', $ttl_seconds ) ) {
			set_transient( $cache_key, 1, $ttl_seconds );
			return true;
		}
		$opt_key = '_sheetsync_wh_' . md5( $cache_key );
		if ( add_option( $opt_key, '1', '', 'no' ) ) {
			set_transient( $cache_key, 1, $ttl_seconds );
			return true;
		}
		return false;
	}
}

if ( ! function_exists( 'sheetsync_get_product_modified_gmt' ) ) {
	/**
	 * Product last-modified time in GMT (works on all WC versions and product types).
	 *
	 * WC_Product does not provide get_date_modified_gmt(); use post_modified_gmt or get_date_modified().
	 */
	function sheetsync_get_product_modified_gmt( WC_Product $product ): ?string {
		$product_id = $product->get_id();
		if ( ! $product_id ) {
			return null;
		}

		$post = get_post( $product_id );
		if ( $post && ! empty( $post->post_modified_gmt ) && '0000-00-00 00:00:00' !== $post->post_modified_gmt ) {
			return $post->post_modified_gmt;
		}

		if ( method_exists( $product, 'get_date_modified' ) ) {
			$date = $product->get_date_modified();
			if ( $date && is_object( $date ) && is_callable( array( $date, 'getTimestamp' ) ) ) {
				return gmdate( 'Y-m-d H:i:s', $date->getTimestamp() );
			}
		}

		return null;
	}
}

if ( ! function_exists( 'sheetsync_normalize_sheet_title' ) ) {
	/**
	 * Trim and sanitize a product title from sheet data.
	 */
	function sheetsync_normalize_sheet_title( string $title ): string {
		$title = wp_strip_all_tags( $title );
		$title = preg_replace( '/[\x00-\x1F\x7F]/u', '', $title ) ?? $title;
		$title = preg_replace( '/\s+/u', ' ', $title ) ?? $title;
		return trim( $title );
	}
}

if ( ! function_exists( 'sheetsync_title_match_key' ) ) {
	/**
	 * Case-insensitive fingerprint for title duplicate detection and external keys.
	 */
	function sheetsync_title_match_key( string $title ): string {
		$title = sheetsync_normalize_sheet_title( $title );
		if ( $title === '' ) {
			return '';
		}
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $title, 'UTF-8' ) : strtolower( $title );
	}
}

if ( ! function_exists( 'sheetsync_normalize_sheet_sku' ) ) {
	/**
	 * Trim and sanitize a SKU from sheet data without stripping common punctuation.
	 */
	function sheetsync_normalize_sheet_sku( string $sku ): string {
		$sku = wp_strip_all_tags( $sku );
		$sku = preg_replace( '/[\x00-\x1F\x7F]/u', '', $sku ) ?? $sku;
		$sku = preg_replace( '/\s+/u', ' ', $sku ) ?? $sku;
		return trim( $sku );
	}
}

if ( ! function_exists( 'sheetsync_sku_format_skip_reason' ) ) {
	/**
	 * Reject only unsafe or unusable SKUs — allow spaces, slashes, unicode, and typical retail formats.
	 *
	 * @return string|null Error message, or null when the SKU may be imported.
	 */
	function sheetsync_sku_format_skip_reason( string $sku ): ?string {
		$sku = sheetsync_normalize_sheet_sku( $sku );
		if ( $sku === '' ) {
			return null;
		}

		$max_len = max( 32, (int) apply_filters( 'sheetsync_sku_max_length', 191 ) );
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $sku ) > $max_len : strlen( $sku ) > $max_len ) {
			return sprintf(
				/* translators: 1: SKU value, 2: maximum length */
				__( 'SKU "%1$s" is too long (maximum %2$d characters).', 'sheetsync-for-woocommerce' ),
				$sku,
				$max_len
			);
		}

		if ( preg_match( '/[\x00-\x1F\x7F]/u', $sku ) ) {
			return sprintf(
				/* translators: %s: SKU value from the sheet */
				__( 'SKU "%s" contains invalid control characters.', 'sheetsync-for-woocommerce' ),
				$sku
			);
		}

		if ( preg_match( '/^[=+@]/u', $sku ) ) {
			return sprintf(
				/* translators: %s: SKU value from the sheet */
				__( 'SKU "%s" cannot start with a spreadsheet formula character (=, +, @).', 'sheetsync-for-woocommerce' ),
				$sku
			);
		}

		/**
		 * Last-chance SKU validation before import. Return a string to reject the row.
		 *
		 * @param string|null $reject  Null to allow, or an error message.
		 * @param string      $sku     Normalized SKU.
		 */
		$custom = apply_filters( 'sheetsync_sku_format_skip_reason', null, $sku );
		if ( is_string( $custom ) && $custom !== '' ) {
			return $custom;
		}

		return null;
	}
}

if ( ! function_exists( 'sheetsync_sheet_validation_max_rows' ) ) {
	/**
	 * Max product rows to scan during pre-sync sheet validation.
	 */
	function sheetsync_sheet_validation_max_rows(): int {
		$is_pro = ( function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro() )
			|| ( defined( 'SHEETSYNC_IS_PRO' ) && SHEETSYNC_IS_PRO );
		$default = $is_pro ? 10000 : 500;
		return max( 100, (int) apply_filters( 'sheetsync_sheet_validation_max_rows', $default, $is_pro ) );
	}
}

if ( ! function_exists( 'sheetsync_product_row_has_price' ) ) {
	/**
	 * Whether a mapped import row or product has a usable price.
	 *
	 * @param array<string, string>|null $data Mapped row data (optional).
	 */
	function sheetsync_product_row_has_price( WC_Product $product, ?array $data = null ): bool {
		if ( $product->is_type( 'external' ) || $product->is_type( 'grouped' ) ) {
			return true;
		}
		if ( is_array( $data ) ) {
			$regular = trim( (string) ( $data['_regular_price'] ?? '' ) );
			$sale    = trim( (string) ( $data['_sale_price'] ?? '' ) );
			if ( $regular !== '' || $sale !== '' ) {
				return true;
			}
		}
		$regular = trim( (string) $product->get_regular_price( 'edit' ) );
		$sale    = trim( (string) $product->get_sale_price( 'edit' ) );
		return $regular !== '' || $sale !== '';
	}
}

if ( ! function_exists( 'sheetsync_normalize_sheet_price' ) ) {
	/**
	 * Normalize a price cell from Google Sheets (currency symbols, spaces, comma decimals).
	 */
	function sheetsync_normalize_sheet_price( string $raw ): string {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return '';
		}

		$clean = preg_replace( '/[^\d.,\-]/', '', $raw );
		if ( ! is_string( $clean ) || $clean === '' || $clean === '-' ) {
			return '';
		}

		// European-style 1.234,56 → 1234.56 when comma is the last separator.
		if ( str_contains( $clean, ',' ) && str_contains( $clean, '.' ) ) {
			if ( strrpos( $clean, ',' ) > strrpos( $clean, '.' ) ) {
				$clean = str_replace( '.', '', $clean );
				$clean = str_replace( ',', '.', $clean );
			} else {
				$clean = str_replace( ',', '', $clean );
			}
		} elseif ( str_contains( $clean, ',' ) && ! str_contains( $clean, '.' ) ) {
			$clean = str_replace( ',', '.', $clean );
		}

		if ( ! is_numeric( $clean ) ) {
			return '';
		}

		return function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $clean ) : (string) $clean;
	}
}

if ( ! function_exists( 'sheetsync_apply_import_price_policy' ) ) {
	/**
	 * Prevent publishing catalog products with no price (sheet import safety).
	 *
	 * @param array<string, string> $data Mapped row values.
	 * @return bool True when status was downgraded to draft.
	 */
	function sheetsync_apply_import_price_policy( WC_Product $product, array $data, bool $is_new = false ): bool {
		unset( $is_new );
		if ( ! empty( $data['parent_sku'] ) || $product->is_type( 'variation' ) ) {
			return false;
		}
		if ( ! apply_filters( 'sheetsync_enforce_price_before_publish', true, $product, $data ) ) {
			return false;
		}
		if ( 'publish' !== $product->get_status( 'edit' ) ) {
			return false;
		}
		if ( sheetsync_product_row_has_price( $product, $data ) ) {
			return false;
		}
		$product->set_status( 'draft' );
		return true;
	}
}

if ( ! function_exists( 'sheetsync_connection_requires_sku' ) ) {
	/**
	 * Whether sheet import rows must include a SKU (recommended for production).
	 */
	function sheetsync_connection_requires_sku( int $connection_id ): bool {
		if ( $connection_id <= 0 || ! class_exists( 'SheetSync_Sync_State_Repository', false ) ) {
			return (bool) apply_filters( 'sheetsync_require_sku_default', true, $connection_id );
		}
		$settings = ( new SheetSync_Sync_State_Repository() )->get_settings( $connection_id );
		return ! empty( $settings['require_sku'] );
	}
}

if ( ! function_exists( 'sheetsync_google_api_ready' ) ) {
	/**
	 * Whether Google service account credentials are configured.
	 */
	function sheetsync_google_api_ready(): bool {
		$encrypted = get_option( 'sheetsync_service_account', '' );
		if ( is_string( $encrypted ) && $encrypted !== '' ) {
			return true;
		}
		$progress = function_exists( 'sheetsync_get_setup_progress' )
			? sheetsync_get_setup_progress()
			: array();
		return ! empty( $progress['google_connected'] );
	}
}

if ( ! function_exists( 'sheetsync_validate_sheet_import_sync' ) ) {
	/**
	 * Pre-flight checks before Sheet → WooCommerce or Two-Way pull starts.
	 *
	 * @return array{ok: bool, errors: string[], message: string}
	 */
	function sheetsync_validate_sheet_import_sync(
		int $connection_id,
		object $conn,
		string $job_direction,
		string $mode = 'incremental',
		string $pull_mode = 'default'
	): array {
		$errors = array();

		if ( ! in_array( $job_direction, array( 'pull', 'two_way' ), true ) ) {
			return array(
				'ok'      => true,
				'errors'  => array(),
				'message' => '',
			);
		}

		if ( function_exists( 'sheetsync_connection_allows_pull' ) && ! sheetsync_connection_allows_pull( $conn ) ) {
			$errors[] = __(
				'This connection is export-only (WooCommerce → Sheets). Change Direction to Sheets → WooCommerce or Two-Way before importing sheet edits.',
				'sheetsync-for-woocommerce'
			);
		}

		if ( ! sheetsync_google_api_ready() ) {
			$errors[] = __(
				'Google API credentials are missing. Connect Google under SheetSync Settings before syncing.',
				'sheetsync-for-woocommerce'
			);
		}

		$maps = class_exists( 'SheetSync_Field_Mapper', false )
			? SheetSync_Field_Mapper::get_maps_for_sync( $connection_id, $conn )
			: array();
		if ( empty( $maps ) ) {
			$errors[] = __(
				'Field mapping is required. Map at least SKU or Product ID and the columns you edit in the sheet.',
				'sheetsync-for-woocommerce'
			);
		} elseif ( empty( $maps['_sku']['sheet_column'] ?? '' )
			&& empty( $maps['product_id']['sheet_column'] ?? '' ) ) {
			$errors[] = SheetSync_Field_Mapper::identity_requirement_message( $maps );
		} elseif ( sheetsync_google_api_ready()
			&& ! empty( $conn->spreadsheet_id )
			&& ! empty( $conn->sheet_name )
			&& class_exists( 'SheetSync_Sheets_Client', false ) ) {
			try {
				$client         = new SheetSync_Sheets_Client();
				$header_row_num = max( 1, (int) ( $conn->header_row ?? 1 ) );
				$last_col       = SheetSync_Field_Mapper::max_column_letter( $maps );
				$range          = "{$conn->sheet_name}!A{$header_row_num}:{$last_col}{$header_row_num}";
				$rows           = $client->get_rows( $conn->spreadsheet_id, $range );
				$actual         = $rows[0] ?? array();
				$headers_trimmed  = array_map( 'trim', array_map( 'strval', $actual ) );
				if ( array() !== $headers_trimmed
					&& array_filter( $headers_trimmed, static fn( $cell ) => $cell !== '' ) ) {
					$check = SheetSync_Field_Mapper::verify_header_row_matches_maps( $maps, $actual );
					if ( ! $check['ok'] ) {
						$max_show = 5;
						foreach ( array_slice( $check['mismatches'], 0, $max_show ) as $mm ) {
							$detected_hint = ( $mm['detected'] ?? '' ) !== ''
								? sprintf(
									/* translators: %s: detected column label */
									__( ' (looks like "%s")', 'sheetsync-for-woocommerce' ),
									$mm['detected']
								)
								: '';
							$errors[] = sprintf(
								/* translators: 1: column letter, 2: expected header, 3: actual header, 4: detected hint */
								__( 'Column %1$s is mapped to "%2$s" but the sheet header is "%3$s"%4$s. Open Field Mapping → Import Headers and realign columns.', 'sheetsync-for-woocommerce' ),
								$mm['column'],
								$mm['expected'],
								$mm['actual'],
								$detected_hint
							);
						}
						$extra = count( $check['mismatches'] ) - $max_show;
						if ( $extra > 0 ) {
							$errors[] = sprintf(
								/* translators: %d: additional mismatch count */
								__( '…and %d more column header mismatches.', 'sheetsync-for-woocommerce' ),
								$extra
							);
						}
					}
				}
			} catch ( Exception $e ) {
				if ( class_exists( 'SheetSync_Logger', false ) ) {
					SheetSync_Logger::error( 'SheetSync: header verification failed — ' . $e->getMessage() );
				}
			}
		}

		if ( 'incremental' === $mode
			&& class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			$mapped = ( new SheetSync_Product_Map_Repository() )->count_for_connection( $connection_id );
			if ( $mapped < 1 && 'update_only' === $pull_mode ) {
				$errors[] = __(
					'No products are linked yet — update-only sync needs at least one prior import. Run Import from Google Sheet first, or use Add new products only.',
					'sheetsync-for-woocommerce'
				);
			}
		}

		$errors = array_values( array_filter( array_map( 'strval', $errors ) ) );

		return array(
			'ok'      => empty( $errors ),
			'errors'  => $errors,
			'message' => implode( ' ', $errors ),
		);
	}
}

if ( ! function_exists( 'sheetsync_empty_cell_policy' ) ) {
	/**
	 * How blank sheet cells affect WooCommerce on import.
	 *
	 * @return string ignore|clear
	 */
	function sheetsync_empty_cell_policy(): string {
		$settings = get_option( 'sheetsync_settings', array() );
		$policy   = (string) ( $settings['empty_cell_policy'] ?? 'ignore' );
		$policy   = (string) apply_filters( 'sheetsync_empty_cell_policy', $policy );
		return in_array( $policy, array( 'ignore', 'clear' ), true ) ? $policy : 'ignore';
	}
}

if ( ! function_exists( 'sheetsync_is_sheet_tab_error' ) ) {
	/**
	 * True when Google Sheets could not resolve the configured workbook tab.
	 */
	function sheetsync_is_sheet_tab_error( string $message ): bool {
		$message = strtolower( $message );
		return str_contains( $message, 'not found in spreadsheet' )
			|| str_contains( $message, 'sheet tab' );
	}
}

if ( ! function_exists( 'sheetsync_verify_sheet_tab' ) ) {
	/**
	 * Fresh workbook + tab check (same data path as Test Connection, not stale cache).
	 *
	 * @return array{
	 *   ok: bool,
	 *   spreadsheet_title: string,
	 *   sheet_name: string,
	 *   tab_found: bool,
	 *   tabs: string[],
	 *   message: string,
	 *   errors: string[],
	 *   fix_url: string
	 * }
	 */
	function sheetsync_verify_sheet_tab( int $connection_id, ?object $conn = null, bool $create_if_missing = true ): array {
		$connection_id = absint( $connection_id );
		$empty         = array(
			'ok'                => false,
			'spreadsheet_title' => '',
			'sheet_name'        => '',
			'tab_found'         => false,
			'tabs'              => array(),
			'message'           => '',
			'errors'            => array(),
			'fix_url'           => '',
		);

		if ( ! $conn && class_exists( 'SheetSync_Sync_Engine', false ) ) {
			$conn = SheetSync_Sync_Engine::get_connection( $connection_id );
		}
		if ( ! $conn ) {
			$empty['errors'][] = __( 'Connection not found.', 'sheetsync-for-woocommerce' );
			$empty['message']  = $empty['errors'][0];
			return $empty;
		}

		$spreadsheet_id = trim( (string) ( $conn->spreadsheet_id ?? '' ) );
		$sheet_name     = trim( (string) ( $conn->sheet_name ?? '' ) );
		$fix_url        = admin_url(
			'admin.php?page=sheetsync&sheetsync_action=edit&connection_id=' . $connection_id . '#tab-connection'
		);

		if ( $spreadsheet_id === '' || $sheet_name === '' ) {
			$msg = __( 'Add your Google Sheet URL and tab name on the Connection tab, then save.', 'sheetsync-for-woocommerce' );
			return array_merge(
				$empty,
				array(
					'sheet_name' => $sheet_name,
					'errors'     => array( $msg ),
					'message'    => $msg,
					'fix_url'    => $fix_url,
				)
			);
		}

		if ( ! sheetsync_google_api_ready() ) {
			$msg = __( 'Connect Google under SheetSync → Settings before syncing.', 'sheetsync-for-woocommerce' );
			return array_merge(
				$empty,
				array(
					'sheet_name' => $sheet_name,
					'errors'     => array( $msg ),
					'message'    => $msg,
					'fix_url'    => admin_url( 'admin.php?page=sheetsync-settings' ),
				)
			);
		}

		if ( class_exists( 'SheetSync_Sheets_Client', false ) ) {
			( new SheetSync_Sheets_Client() )->invalidate_sheet_grid_cache( $spreadsheet_id );
		}

		$title = '';
		$tabs  = array();
		if ( class_exists( 'SheetSync_Google_Auth', false ) ) {
			$meta = SheetSync_Google_Auth::test_connection( $spreadsheet_id );
			if ( empty( $meta['success'] ) ) {
				$msg = (string) ( $meta['message'] ?? __( 'Could not reach Google Sheets.', 'sheetsync-for-woocommerce' ) );
				return array_merge(
					$empty,
					array(
						'sheet_name' => $sheet_name,
						'errors'     => array( $msg ),
						'message'    => $msg,
						'fix_url'    => $fix_url,
					)
				);
			}
			$title = (string) ( $meta['title'] ?? '' );
			$tabs  = array_values( array_filter( array_map( 'strval', (array) ( $meta['sheets'] ?? array() ) ) ) );
		}

		$tab_found = in_array( $sheet_name, $tabs, true );

		if ( ! $tab_found && $create_if_missing && class_exists( 'SheetSync_Sheets_Client', false ) ) {
			try {
				( new SheetSync_Sheets_Client() )->ensure_sheet_exists( $spreadsheet_id, $sheet_name );
				( new SheetSync_Sheets_Client() )->invalidate_sheet_grid_cache( $spreadsheet_id );
				if ( class_exists( 'SheetSync_Google_Auth', false ) ) {
					$meta = SheetSync_Google_Auth::test_connection( $spreadsheet_id );
					if ( ! empty( $meta['success'] ) ) {
						$title = (string) ( $meta['title'] ?? $title );
						$tabs  = array_values( array_filter( array_map( 'strval', (array) ( $meta['sheets'] ?? array() ) ) ) );
						$tab_found = in_array( $sheet_name, $tabs, true );
					}
				}
			} catch ( Exception $e ) {
				// Fall through to merchant message below.
			}
		}

		if ( $tab_found ) {
			$workbook = $title !== '' ? $title : __( 'your spreadsheet', 'sheetsync-for-woocommerce' );
			$message  = sprintf(
				/* translators: 1: sheet tab name, 2: workbook title */
				__( 'Ready to sync — tab “%1$s” in workbook “%2$s”.', 'sheetsync-for-woocommerce' ),
				$sheet_name,
				$workbook
			);
			return array(
				'ok'                => true,
				'spreadsheet_title' => $title,
				'sheet_name'        => $sheet_name,
				'tab_found'         => true,
				'tabs'              => $tabs,
				'message'           => $message,
				'errors'            => array(),
				'fix_url'           => $fix_url,
			);
		}

		$tab_hint = empty( $tabs )
			? __( '(no tabs found)', 'sheetsync-for-woocommerce' )
			: implode(
				__( '”, “', 'sheetsync-for-woocommerce' ),
				array_slice( $tabs, 0, 8 )
			) . ( count( $tabs ) > 8 ? '…' : '' );

		$workbook = $title !== '' ? $title : __( 'this spreadsheet', 'sheetsync-for-woocommerce' );
		$message  = sprintf(
			/* translators: 1: configured tab, 2: workbook title, 3: comma-separated tab names in workbook */
			__( 'We could not write to tab “%1$s” in “%2$s”. Tabs in this workbook: “%3$s”. Open the Connection tab, pick the correct tab (or fix the name), then Save Connection.', 'sheetsync-for-woocommerce' ),
			$sheet_name,
			$workbook,
			$tab_hint
		);

		return array(
			'ok'                => false,
			'spreadsheet_title' => $title,
			'sheet_name'        => $sheet_name,
			'tab_found'         => false,
			'tabs'              => $tabs,
			'message'           => $message,
			'errors'            => array( $message ),
			'fix_url'           => $fix_url,
		);
	}
}

if ( ! function_exists( 'sheetsync_format_sheet_error_for_connection' ) ) {
	function sheetsync_format_sheet_error_for_connection( Throwable $e, int $connection_id ): string {
		if ( ! sheetsync_is_sheet_tab_error( $e->getMessage() ) ) {
			return $e->getMessage();
		}
		$verify = sheetsync_verify_sheet_tab( $connection_id, null, false );
		return (string) ( $verify['message'] ?? $e->getMessage() );
	}
}

if ( ! function_exists( 'sheetsync_validate_sheet_export_sync' ) ) {
	/**
	 * Pre-flight checks before WooCommerce → Google Sheets export starts.
	 *
	 * @return array{ok: bool, errors: string[], message: string, sheet_verify?: array<string, mixed>}
	 */
	function sheetsync_validate_sheet_export_sync( int $connection_id, object $conn ): array {
		$errors = array();

		$maps = class_exists( 'SheetSync_Field_Mapper', false )
			? SheetSync_Field_Mapper::get_maps( $connection_id )
			: array();
		if ( empty( $maps ) ) {
			$errors[] = __(
				'Field mapping is required before export. Map your columns on the Field Mapping tab.',
				'sheetsync-for-woocommerce'
			);
		}

		$verify = sheetsync_verify_sheet_tab( $connection_id, $conn, true );
		if ( ! $verify['ok'] ) {
			$errors = array_merge( $errors, (array) ( $verify['errors'] ?? array() ) );
		}

		$errors = array_values( array_filter( array_map( 'strval', $errors ) ) );

		return array(
			'ok'           => empty( $errors ),
			'errors'       => $errors,
			'message'      => implode( ' ', $errors ),
			'sheet_verify' => $verify,
		);
	}
}

if ( ! function_exists( 'sheetsync_resume_active_sync_jobs' ) ) {
	/**
	 * Kick Action Scheduler and optionally inline-drain stuck jobs (shared hosting safety net).
	 */
	function sheetsync_resume_active_sync_jobs( int $inline_seconds = 0, int $max_jobs = 3 ): void {
		if ( ! sheetsync_use_job_engine() || ! class_exists( 'SheetSync_Job_Repository', false ) ) {
			return;
		}

		$repo = new SheetSync_Job_Repository();
		$jobs = $repo->get_all_active( max( 1, min( 8, $max_jobs ) ) );
		if ( empty( $jobs ) ) {
			return;
		}

		if ( function_exists( 'sheetsync_kick_background_queue' ) ) {
			sheetsync_kick_background_queue( 12 );
		}

		if ( $inline_seconds < 1 || ! function_exists( 'sheetsync_process_job_inline' ) ) {
			return;
		}

		$drained = 0;
		foreach ( $jobs as $job ) {
			if ( $drained >= $max_jobs ) {
				break;
			}
			$job_id = (int) ( $job->id ?? 0 );
			if ( $job_id < 1 ) {
				continue;
			}
			if ( function_exists( 'sheetsync_reschedule_stuck_job' ) ) {
				sheetsync_reschedule_stuck_job( $job_id, 0 );
			}
			sheetsync_process_job_inline( $job_id, $inline_seconds );
			++$drained;
		}

		if ( function_exists( 'sheetsync_kick_background_queue' ) ) {
			sheetsync_kick_background_queue( 6 );
		}
	}
}

if ( ! function_exists( 'sheetsync_get_export_confirm_context' ) ) {
	/**
	 * Context for full-export confirmation dialogs (admin UI).
	 *
	 * @return array<string, mixed>
	 */
	function sheetsync_get_export_confirm_context( int $connection_id ): array {
		$conn = class_exists( 'SheetSync_Sync_Engine', false )
			? SheetSync_Sync_Engine::get_connection( $connection_id )
			: null;
		$direction = $conn ? (string) ( $conn->sync_direction ?? 'sheets_to_wc' ) : 'sheets_to_wc';
		$row_context = in_array( $direction, array( 'wc_to_sheets' ), true ) ? 'export' : 'import';
		$breakdown = function_exists( 'sheetsync_connection_row_breakdown' )
			? sheetsync_connection_row_breakdown( $connection_id, $row_context )
			: ( function_exists( 'sheetsync_count_exportable_breakdown' )
				? sheetsync_count_exportable_breakdown( $connection_id )
				: array() );
		$parents   = (int) ( $breakdown['parent_products'] ?? 0 );
		$vars      = (int) ( $breakdown['variations'] ?? 0 );
		$total     = (int) ( $breakdown['sheet_rows'] ?? ( $parents + $vars ) );
		$eta       = null;
		if ( $total > 0 && function_exists( 'sheetsync_estimate_job_eta_minutes' ) ) {
			$eta = sheetsync_estimate_job_eta_minutes(
				(object) array(
					'connection_id'   => $connection_id,
					'total_estimate'  => $total,
					'processed_count' => 0,
					'cursor_offset'   => 0,
					'direction'       => 'bootstrap',
					'phase'           => 'push',
					'status'          => 'pending',
				)
			);
		}
		return array(
			'sheet_rows'      => $total,
			'parent_products' => $parents,
			'variations'      => $vars,
			'is_two_way'      => 'two_way' === $direction,
			'sync_direction'  => $direction,
			'eta_minutes'     => $eta,
			'gate_threshold'  => function_exists( 'sheetsync_large_sync_gate_threshold' )
				? sheetsync_large_sync_gate_threshold()
				: 200,
			'scheduler_ok'    => function_exists( 'sheetsync_get_action_scheduler_health' )
				? (bool) ( sheetsync_get_action_scheduler_health()['ok'] ?? true )
				: true,
			'needs_soft_gate' => $total >= ( function_exists( 'sheetsync_large_sync_gate_threshold' )
				? sheetsync_large_sync_gate_threshold()
				: 200 )
				&& function_exists( 'sheetsync_get_action_scheduler_health' )
				&& ! ( sheetsync_get_action_scheduler_health()['ok'] ?? true ),
		);
	}
}

if ( ! function_exists( 'sheetsync_large_sync_gate_threshold' ) ) {
	/**
	 * Row count at which unhealthy Action Scheduler triggers an explicit slow-sync choice.
	 */
	function sheetsync_large_sync_gate_threshold(): int {
		return max( 50, (int) apply_filters( 'sheetsync_large_sync_gate_threshold', 200 ) );
	}
}

if ( ! function_exists( 'sheetsync_large_sync_scheduler_threshold' ) ) {
	/**
	 * Row count for “large catalog” ETA hints in the UI (defaults higher than soft gate).
	 */
	function sheetsync_large_sync_scheduler_threshold(): int {
		return max( 100, (int) apply_filters( 'sheetsync_large_sync_scheduler_threshold', 500 ) );
	}
}

if ( ! function_exists( 'sheetsync_connection_needs_scheduler_gate' ) ) {
	/**
	 * Whether a manual sync should prompt fix-first vs slow-sync for this connection.
	 *
	 * @return array{needs_gate: bool, sheet_rows: int, scheduler_ok: bool}
	 */
	function sheetsync_connection_needs_scheduler_gate( int $connection_id ): array {
		$conn = class_exists( 'SheetSync_Sync_Engine', false )
			? SheetSync_Sync_Engine::get_connection( $connection_id )
			: null;
		$direction   = $conn ? (string) ( $conn->sync_direction ?? 'sheets_to_wc' ) : 'sheets_to_wc';
		$row_context = 'wc_to_sheets' === $direction ? 'export' : 'import';
		$breakdown   = function_exists( 'sheetsync_connection_row_breakdown' )
			? sheetsync_connection_row_breakdown( $connection_id, $row_context )
			: ( function_exists( 'sheetsync_count_exportable_breakdown' )
				? sheetsync_count_exportable_breakdown( $connection_id )
				: array() );
		$rows      = (int) ( $breakdown['sheet_rows'] ?? 0 );
		$health    = function_exists( 'sheetsync_get_action_scheduler_health' )
			? sheetsync_get_action_scheduler_health()
			: array( 'ok' => true );
		$threshold = sheetsync_large_sync_gate_threshold();
		$ok        = (bool) ( $health['ok'] ?? true );

		return array(
			'needs_gate'    => $rows >= $threshold && ! $ok,
			'sheet_rows'    => $rows,
			'scheduler_ok'  => $ok,
			'gate_threshold'=> $threshold,
		);
	}
}

if ( ! function_exists( 'sheetsync_importable_product_row_skip_reason' ) ) {
	/**
	 * Why a mapped sheet row is not importable, or null when it may be imported.
	 *
	 * @param array<string, string> $data Mapped row from extract_data().
	 */
	function sheetsync_importable_product_row_skip_reason( array $data ): ?string {
		$sku    = sheetsync_normalize_sheet_sku( (string) ( $data['_sku'] ?? '' ) );
		$parent = sheetsync_normalize_sheet_sku( (string) ( $data['parent_sku'] ?? '' ) );
		$title  = sanitize_text_field( $data['post_title'] ?? '' );

		$is_pro = ( function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro() )
			|| ( defined( 'SHEETSYNC_IS_PRO' ) && SHEETSYNC_IS_PRO );

		if ( ! $is_pro ) {
			if ( class_exists( 'SheetSync_Variation_Sync', false ) ) {
				if ( SheetSync_Variation_Sync::is_variation_row( $data ) || SheetSync_Variation_Sync::is_variable_parent_row( $data ) ) {
					return __( 'Variable products require SheetSync Pro (parent + variation rows). Upgrade or remove Product Type / Parent SKU columns from the sheet.', 'sheetsync-for-woocommerce' );
				}
			}
			$type = strtolower( trim( (string) ( $data['_product_type'] ?? '' ) ) );
			if ( in_array( $type, array( 'variable', 'grouped', 'external' ), true ) ) {
				return sprintf(
					/* translators: %s: product type slug */
					__( 'Product type “%s” requires SheetSync Pro. Free tier imports simple products only.', 'sheetsync-for-woocommerce' ),
					$type
				);
			}
		}

		if ( $parent === '' && $sku !== '' && class_exists( 'SheetSync_Product_Map_Repository', false )
			&& SheetSync_Product_Map_Repository::sku_is_ambiguous_in_catalog( $sku )
			&& empty( $data['product_id'] ) ) {
			if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
				$ambiguous = SheetSync_Import_Row_Service::ambiguous_sku_import_message( $data );
				if ( is_string( $ambiguous ) && $ambiguous !== '' ) {
					return $ambiguous;
				}
			}
		}

		if ( $parent !== '' ) {
			if ( $sku === '' && $title === '' ) {
				return __( 'Variation row is missing SKU and title.', 'sheetsync-for-woocommerce' );
			}
			$parent_sku_reason = sheetsync_sku_format_skip_reason( $parent );
			if ( is_string( $parent_sku_reason ) && $parent_sku_reason !== '' ) {
				return $parent_sku_reason;
			}
			if ( $sku !== '' ) {
				$sku_reason = sheetsync_sku_format_skip_reason( $sku );
				if ( is_string( $sku_reason ) && $sku_reason !== '' ) {
					return $sku_reason;
				}
			}
			return null;
		}

		$connection_id = 0;
		if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
			$connection_id = SheetSync_Import_Row_Service::get_current_connection_id();
		}
		if ( $connection_id > 0 && sheetsync_connection_requires_sku( $connection_id ) && $sku === '' ) {
			return __( 'SKU is required for this connection — add a unique SKU or disable “Require SKU” in Sync options.', 'sheetsync-for-woocommerce' );
		}

		if ( $sku === '' && $title === '' ) {
			return __( 'Row is empty or missing both SKU and title.', 'sheetsync-for-woocommerce' );
		}

		if ( $sku !== '' ) {
			$sku_reason = sheetsync_sku_format_skip_reason( $sku );
			if ( is_string( $sku_reason ) && $sku_reason !== '' ) {
				return $sku_reason;
			}
		}

		return null;
	}
}

if ( ! function_exists( 'sheetsync_is_importable_product_row' ) ) {
	/**
	 * Skip instruction/banner rows (e.g. guide text accidentally placed in the SKU column).
	 *
	 * @param array<string, string> $data Mapped row from extract_data().
	 */
	function sheetsync_is_importable_product_row( array $data ): bool {
		return null === sheetsync_importable_product_row_skip_reason( $data );
	}
}

if ( ! function_exists( 'sheetsync_get_sheet_rows_with_retry' ) ) {
	/**
	 * Read a bounded sheet range with transient-aware retries (AJAX + background).
	 *
	 * @return array<int, array<int, string>>
	 */
	function sheetsync_get_sheet_rows_with_retry(
		SheetSync_Sheets_Client $client,
		string $spreadsheet_id,
		string $range,
		?object $limiter = null
	): array {
		$is_ajax    = ( defined( 'DOING_AJAX' ) && DOING_AJAX );
		$default    = $is_ajax ? 4 : 3;
		$attempts   = max( 1, min( 5, (int) apply_filters( 'sheetsync_sheet_read_attempts', $default ) ) );
		$last_error = null;

		for ( $try = 0; $try < $attempts; ++$try ) {
			try {
				if ( $limiter && method_exists( $limiter, 'acquire_read' ) ) {
					$limiter->acquire_read();
				}
				return $client->get_rows( $spreadsheet_id, $range );
			} catch ( Exception $e ) {
				$last_error = $e;
				if ( $try < $attempts - 1 ) {
					$delay = min( 8, 2 * ( $try + 1 ) );
					sleep( $delay );
				}
			}
		}

		if ( $last_error instanceof Exception ) {
			throw $last_error;
		}

		throw new RuntimeException( __( 'Could not read Google Sheet.', 'sheetsync-for-woocommerce' ) );
	}
}

if ( ! function_exists( 'sheetsync_api_error_is_retryable' ) ) {
	/**
	 * Transient Google/network failures worth retrying in AJAX import batches.
	 */
	function sheetsync_api_error_is_retryable( string $message ): bool {
		if ( function_exists( 'sheetsync_api_error_is_quota' ) && sheetsync_api_error_is_quota( $message ) ) {
			return true;
		}
		$message = strtolower( $message );
		foreach ( array( 'timeout', 'timed out', 'connection', 'curl', '503', '502', '504', 'could not read google sheet' ) as $needle ) {
			if ( str_contains( $message, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'sheetsync_resolve_product_ids_from_sheet_list' ) ) {
	/**
	 * Resolve comma-separated SKUs or id:123 tokens to WooCommerce product IDs.
	 *
	 * @return int[]
	 */
	function sheetsync_resolve_product_ids_from_sheet_list( string $raw ): array {
		return sheetsync_analyze_grouped_child_list( $raw )['ids'];
	}
}

if ( ! function_exists( 'sheetsync_analyze_grouped_child_list' ) ) {
	/**
	 * Resolve grouped-child SKUs / id: tokens; report unresolved tokens for retry passes.
	 *
	 * @return array{ids: int[], total_tokens: int, resolved_count: int, pending: bool}
	 */
	function sheetsync_analyze_grouped_child_list( string $raw ): array {
		$tokens = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		$ids    = array();

		foreach ( $tokens as $token ) {
			if ( preg_match( '/^id:(\d+)$/i', $token, $m ) ) {
				$pid = absint( $m[1] );
				if ( $pid > 0 && wc_get_product( $pid ) ) {
					$ids[] = $pid;
				}
				continue;
			}

			$sku = function_exists( 'sheetsync_normalize_sheet_sku' )
				? sheetsync_normalize_sheet_sku( $token )
				: sanitize_text_field( $token );
			if ( $sku === '' ) {
				continue;
			}
			$pid = (int) wc_get_product_id_by_sku( $sku );
			if ( $pid > 0 ) {
				$ids[] = $pid;
			}
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );

		return array(
			'ids'             => $ids,
			'total_tokens'    => count( $tokens ),
			'resolved_count'  => count( $ids ),
			'pending'         => count( $tokens ) > count( $ids ),
		);
	}
}

if ( ! function_exists( 'sheetsync_probe_remote_image_url' ) ) {
	/**
	 * Check whether the server can download an image URL (HEAD, then GET if needed).
	 *
	 * Many CDNs (e.g. picsum.photos) return HTTP 405 for HEAD but allow GET — sync uses GET.
	 *
	 * @return array{ok: bool, message: string, content_type?: string, method?: string}
	 */
	function sheetsync_probe_remote_image_url( string $url ): array {
		$raw = trim( $url );
		if ( class_exists( 'SheetSync_Sheet_Image_Resolver', false ) ) {
			if ( preg_match( '/^\d+$/', $raw ) ) {
				$att_id = (int) $raw;
				if ( 'attachment' === get_post_type( $att_id ) ) {
					return array(
						'ok'           => true,
						'message'      => __( 'Media Library attachment found. Sync will use this image directly.', 'sheetsync-for-woocommerce' ),
						'content_type' => (string) get_post_mime_type( $att_id ),
						'method'       => 'attachment',
					);
				}
				return array(
					'ok'      => false,
					'message' => __( 'That ID is not a Media Library image.', 'sheetsync-for-woocommerce' ),
				);
			}
			$url = SheetSync_Sheet_Image_Resolver::normalize_image_url( $raw );
		} else {
			$url = esc_url_raw( $raw );
		}

		if ( $url === '' || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Enter a valid image URL, Media Library link, or attachment ID.', 'sheetsync-for-woocommerce' ),
			);
		}

		if ( class_exists( 'SheetSync_Sheet_Image_Resolver', false ) ) {
			$att_id = SheetSync_Sheet_Image_Resolver::resolve_attachment_id( $url );
			if ( $att_id > 0 ) {
				return array(
					'ok'           => true,
					'message'      => __( 'Media Library image found. Sync will reuse it (no duplicate upload).', 'sheetsync-for-woocommerce' ),
					'content_type' => (string) get_post_mime_type( $att_id ),
					'method'       => 'library',
				);
			}
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Only http:// and https:// image URLs are allowed.', 'sheetsync-for-woocommerce' ),
			);
		}

		if ( ! sheetsync_is_safe_remote_url( $url ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'This URL points to a private or reserved network address and cannot be fetched.', 'sheetsync-for-woocommerce' ),
			);
		}

		$args = array(
			'timeout'     => 15,
			'redirection' => 5,
		);

		$head = wp_remote_head( $url, $args );
		if ( ! is_wp_error( $head ) ) {
			$code = (int) wp_remote_retrieve_response_code( $head );
			if ( $code >= 200 && $code < 400 ) {
				$type = (string) wp_remote_retrieve_header( $head, 'content-type' );
				return array(
					'ok'           => true,
					'message'      => __( 'URL reachable. Sync can download this image.', 'sheetsync-for-woocommerce' ),
					'content_type' => $type,
					'method'       => 'HEAD',
				);
			}
		}

		$get = wp_remote_get(
			$url,
			array_merge(
				$args,
				array(
					'headers' => array( 'Range' => 'bytes=0-8191' ),
				)
			)
		);

		if ( is_wp_error( $get ) ) {
			return array(
				'ok'      => false,
				'message' => $get->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $get );
		$type = (string) wp_remote_retrieve_header( $get, 'content-type' );

		if ( $code >= 200 && $code < 400 ) {
			$head_code = is_wp_error( $head ) ? 0 : (int) wp_remote_retrieve_response_code( $head );
			$note      = ( 405 === $head_code )
				? ' ' . __( '(HEAD returned 405; GET works — normal for picsum.photos.)', 'sheetsync-for-woocommerce' )
				: '';
			return array(
				'ok'           => true,
				'message'      => __( 'URL reachable via GET. Sync can download this image.', 'sheetsync-for-woocommerce' ) . $note,
				'content_type' => $type,
				'method'       => 'GET',
			);
		}

		return array(
			'ok'      => false,
			'message' => sprintf(
				/* translators: %d: HTTP status code */
				__( 'HTTP %d — server cannot download this image. Try another URL or upload images in WooCommerce.', 'sheetsync-for-woocommerce' ),
				$code
			),
		);
	}
}

if ( ! function_exists( 'sheetsync_decode_sheet_text' ) ) {
	/**
	 * Plain text for Google Sheets (fixes &amp; &lt; etc. from WooCommerce/HTML storage).
	 */
	function sheetsync_decode_sheet_text( string $text ): string {
		if ( $text === '' ) {
			return '';
		}
		return html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}

if ( ! function_exists( 'sheetsync_format_term_names_for_sheet' ) ) {
	/**
	 * Comma-separated taxonomy term names safe for sheet cells and filter dropdowns.
	 *
	 * @param array<int, WP_Term>|WP_Term[] $terms
	 */
	function sheetsync_format_term_names_for_sheet( array $terms ): string {
		$names = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term && $term->name !== '' ) {
				$names[] = sheetsync_decode_sheet_text( $term->name );
			}
		}
		return implode( ', ', $names );
	}
}

if ( ! function_exists( 'sheetsync_resolve_term_by_name' ) ) {
	/**
	 * Match a taxonomy term from sheet text (handles &amp; vs & in stored names).
	 */
	function sheetsync_resolve_term_by_name( string $name, string $taxonomy ): ?WP_Term {
		$name = trim( $name );
		if ( $name === '' ) {
			return null;
		}
		$decoded = sheetsync_decode_sheet_text( $name );
		$term    = get_term_by( 'name', $decoded, $taxonomy );
		if ( $term instanceof WP_Term ) {
			return $term;
		}
		if ( $decoded !== $name ) {
			$term = get_term_by( 'name', $name, $taxonomy );
			if ( $term instanceof WP_Term ) {
				return $term;
			}
		}
		return null;
	}
}

if ( ! function_exists( 'sheetsync_classify_sync_row' ) ) {
	/**
	 * @param array<string, string> $data Mapped row.
	 * @return string simple|parent|variation
	 */
	function sheetsync_classify_sync_row( array $data ): string {
		if ( ! class_exists( 'SheetSync_Variation_Sync', false ) ) {
			return 'simple';
		}
		if ( SheetSync_Variation_Sync::is_variation_row( $data ) ) {
			return 'variation';
		}
		if ( SheetSync_Variation_Sync::is_variable_parent_row( $data ) ) {
			return 'parent';
		}
		return 'simple';
	}
}

if ( ! function_exists( 'sheetsync_format_sync_result_message' ) ) {
	/**
	 * Human-readable sync summary (sheet rows, not “product families”).
	 *
	 * @param array{simple?: int, parent?: int, variation?: int} $row_stats
	 */
	function sheetsync_format_sync_result_message(
		int $processed,
		int $skipped,
		int $errors,
		array $row_stats,
		string $pull_mode = 'default',
		array $partial = array()
	): string {
		$parts = array();

		if ( $processed > 0 ) {
			$breakdown = array();
			if ( ! empty( $row_stats['simple'] ) ) {
				$breakdown[] = sprintf(
					/* translators: %d: count */
					_n( '%d simple row', '%d simple rows', (int) $row_stats['simple'], 'sheetsync-for-woocommerce' ),
					(int) $row_stats['simple']
				);
			}
			if ( ! empty( $row_stats['parent'] ) ) {
				$breakdown[] = sprintf(
					/* translators: %d: count */
					_n( '%d variable parent', '%d variable parents', (int) $row_stats['parent'], 'sheetsync-for-woocommerce' ),
					(int) $row_stats['parent']
				);
			}
			if ( ! empty( $row_stats['variation'] ) ) {
				$breakdown[] = sprintf(
					/* translators: %d: count */
					_n( '%d variation row', '%d variation rows', (int) $row_stats['variation'], 'sheetsync-for-woocommerce' ),
					(int) $row_stats['variation']
				);
			}
			$line = sprintf(
				/* translators: %d: number of sheet rows updated */
				_n( '%d sheet row updated', '%d sheet rows updated', $processed, 'sheetsync-for-woocommerce' ),
				$processed
			);
			if ( ! empty( $breakdown ) ) {
				$line .= ' (' . implode( ', ', $breakdown ) . ')';
			}
			$parts[] = $line;
		}

		if ( $skipped > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: count */
				_n( '%d row unchanged (skipped)', '%d rows unchanged (skipped)', $skipped, 'sheetsync-for-woocommerce' ),
				$skipped
			);
		}

		if ( $errors > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: count */
				_n( '%d error', '%d errors', $errors, 'sheetsync-for-woocommerce' ),
				$errors
			);
		}

		if ( empty( $parts ) ) {
			return __( 'Sync finished — no rows needed changes.', 'sheetsync-for-woocommerce' );
		}

		$hint = '';
		if ( 'update_only' === $pull_mode && $processed > 1 ) {
			$hint = ' ' . __( 'Tip: Each sheet row counts separately (a variable product often uses 2+ rows).', 'sheetsync-for-woocommerce' );
		}

		if ( ! empty( $partial['timed_out'] ) ) {
			$next_row = (int) ( $partial['next_row'] ?? 0 );
			if ( $next_row > 0 ) {
				$hint .= ' ' . sprintf(
					/* translators: 1: next sheet row number, 2: rows already processed in this run */
					__( 'Partial sync — stopped at time limit after %2$d row(s). Run Sync again to continue from sheet row ~%1$d.', 'sheetsync-for-woocommerce' ),
					$next_row,
					$processed
				);
			} else {
				$hint .= ' ' . __( 'Partial sync — stopped at time limit. Run Sync again to continue.', 'sheetsync-for-woocommerce' );
			}
		}

		return implode( '. ', $parts ) . '.' . $hint;
	}
}

if ( ! function_exists( 'sheetsync_flush_dirty_products_after_sync' ) ) {
	/**
	 * Push WooCommerce edits that were queued while a background sync held the connection lock.
	 */
	function sheetsync_flush_dirty_products_after_sync( int $connection_id ): void {
		if ( $connection_id <= 0 ) {
			return;
		}
		$key   = 'sheetsync_dirty_products_' . $connection_id;
		$dirty = get_transient( $key );
		if ( ! is_array( $dirty ) || empty( $dirty ) ) {
			return;
		}
		if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
			if ( ! as_next_scheduled_action( 'sheetsync_deferred_push', array( $connection_id ), 'sheetsync' ) ) {
				as_schedule_single_action( time() + 2, 'sheetsync_deferred_push', array( $connection_id ), 'sheetsync' );
			}
		}
	}
}

if ( ! function_exists( 'sheetsync_google_sheets_cell_char_limit' ) ) {
	function sheetsync_google_sheets_cell_char_limit(): int {
		return (int) apply_filters( 'sheetsync_google_sheets_cell_char_limit', 50000 );
	}
}

if ( ! function_exists( 'sheetsync_sanitize_import_cell_text' ) ) {
	/**
	 * Trim sheet text to Google cell limits (import path — full text stays in WooCommerce when truncated).
	 */
	function sheetsync_sanitize_import_cell_text( string $text, string $field = '' ): string {
		$text = trim( $text );
		if ( $text === '' ) {
			return '';
		}
		$max = sheetsync_google_sheets_cell_char_limit();
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $max ) {
			return mb_substr( $text, 0, max( 1, $max - 3 ) ) . '...';
		}
		if ( strlen( $text ) > $max ) {
			return substr( $text, 0, max( 1, $max - 3 ) ) . '...';
		}
		return $text;
	}
}

add_filter(
	'sheetsync_row_mapped_data',
	static function ( array $data, array $row, array $maps ): array {
		unset( $row, $maps );
		$long_fields = array( 'post_content', 'post_excerpt', '_gallery_images' );
		foreach ( $long_fields as $field ) {
			if ( empty( $data[ $field ] ) ) {
				continue;
			}
			$original = (string) $data[ $field ];
			$trimmed  = sheetsync_sanitize_import_cell_text( $original, $field );
			if ( $trimmed !== $original ) {
				$data[ $field ] = $trimmed;
			}
		}
		return $data;
	},
	5,
	3
);

if ( ! function_exists( 'sheetsync_guess_sideload_filename' ) ) {
	/**
	 * Build a filename with extension for CDN URLs that omit one (e.g. picsum.photos).
	 */
	function sheetsync_guess_sideload_filename( string $url, string $tmp_path ): string {
		$mime = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $tmp_path ) : '';
		if ( ! is_string( $mime ) || $mime === '' ) {
			$mime = (string) wp_check_filetype( $tmp_path )['type'];
		}
		$ext_map = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		);
		$ext     = $ext_map[ strtolower( $mime ) ] ?? 'jpg';
		$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base    = basename( $path );
		if ( $base !== '' && preg_match( '/\.(jpe?g|png|gif|webp)$/i', $base ) ) {
			return sanitize_file_name( $base );
		}
		if ( preg_match( '#/id/(\d+)#', $url, $matches ) ) {
			return sanitize_file_name( 'image-' . $matches[1] . '.' . $ext );
		}
		return sanitize_file_name( 'sheetsync-' . substr( md5( $url ), 0, 12 ) . '.' . $ext );
	}
}

if ( ! function_exists( 'sheetsync_sideload_image_from_url' ) ) {
	/**
	 * Sideload a remote image; fallback for URLs without file extensions (picsum.photos, etc.).
	 *
	 * @return int|\WP_Error Attachment ID or error.
	 */
	function sheetsync_sideload_image_from_url( string $url, int $parent_post_id = 0 ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$url = trim( $url );
		if ( $url === '' || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error( 'invalid_url', __( 'Invalid image URL.', 'sheetsync-for-woocommerce' ) );
		}

		if ( function_exists( 'sheetsync_is_safe_remote_url' ) && ! sheetsync_is_safe_remote_url( $url ) ) {
			return new WP_Error( 'unsafe_url', __( 'Image URL blocked (private/reserved host).', 'sheetsync-for-woocommerce' ) );
		}

		$max_attempts = max( 1, min( 4, (int) apply_filters( 'sheetsync_sideload_attempts', 2 ) ) );
		$allow_sleep  = ( defined( 'SHEETSYNC_BG_SYNC' ) && SHEETSYNC_BG_SYNC )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() );
		$last_error   = null;

		for ( $try = 0; $try < $max_attempts; ++$try ) {
			$attachment_id = media_sideload_image( $url, $parent_post_id, null, 'id' );
			if ( ! is_wp_error( $attachment_id ) ) {
				return (int) $attachment_id;
			}

			$last_error    = $attachment_id;
			$error_message = strtolower( (string) $attachment_id->get_error_message() );
			if ( ! str_contains( $error_message, 'invalid image url' ) ) {
				if ( $try < $max_attempts - 1 && $allow_sleep ) {
					sleep( min( 3, $try + 1 ) );
					continue;
				}
				return $attachment_id;
			}

			$tmp = download_url( $url, 20 );
			if ( is_wp_error( $tmp ) ) {
				$last_error = $tmp;
				if ( $try < $max_attempts - 1 && $allow_sleep ) {
					sleep( min( 3, $try + 1 ) );
					continue;
				}
				return $tmp;
			}

			$file_array = array(
				'name'     => sheetsync_guess_sideload_filename( $url, $tmp ),
				'tmp_name' => $tmp,
			);

			$sideload_id = media_handle_sideload( $file_array, $parent_post_id );
			if ( is_wp_error( $sideload_id ) ) {
				if ( file_exists( $tmp ) ) {
					wp_delete_file( $tmp );
				}
				$last_error = $sideload_id;
				if ( $try < $max_attempts - 1 && $allow_sleep ) {
					sleep( min( 3, $try + 1 ) );
					continue;
				}
				return $sideload_id;
			}

			return (int) $sideload_id;
		}

		return $last_error instanceof WP_Error
			? $last_error
			: new WP_Error( 'sideload_failed', __( 'Image download failed.', 'sheetsync-for-woocommerce' ) );
	}
}

if ( ! function_exists( 'sheetsync_should_defer_image_sideload' ) ) {
	/**
	 * Whether product image sideload should be deferred to the background queue during import.
	 */
	function sheetsync_should_defer_image_sideload(): bool {
		return (bool) apply_filters( 'sheetsync_defer_image_sideload', true );
	}
}

if ( ! function_exists( 'sheetsync_media_queue_maybe_process_inline' ) ) {
	/**
	 * Drain a few queued images inline when Action Scheduler is unavailable.
	 */
	function sheetsync_media_queue_maybe_process_inline( int $connection_id = 0 ): void {
		// Never download images during admin AJAX — avoids gateway timeouts on shared hosting.
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}
		if ( ! class_exists( 'SheetSync_Media_Queue', false ) ) {
			return;
		}
		SheetSync_Media_Queue::maybe_process_inline( $connection_id );
	}
}

if ( ! function_exists( 'sheetsync_row_needs_media_sync' ) ) {
	/**
	 * Re-sync when the sheet has image URLs but WooCommerce has no featured/gallery images yet.
	 *
	 * @param array<string, string> $data Mapped row.
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps Field maps.
	 */
	function sheetsync_row_needs_media_sync( array $data, WC_Product $product, array $maps ): bool {
		if ( ! empty( $maps['_product_image']['sheet_column'] ) ) {
			$url = trim( (string) ( $data['_product_image'] ?? '' ) );
			if ( $url !== '' && filter_var( $url, FILTER_VALIDATE_URL ) && ! $product->get_image_id() ) {
				return true;
			}
		}
		if ( ! empty( $maps['_gallery_images']['sheet_column'] ) && ! $product instanceof WC_Product_Variation ) {
			$gallery = trim( (string) ( $data['_gallery_images'] ?? '' ) );
			if ( $gallery !== '' && empty( $product->get_gallery_image_ids() ) ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'sheetsync_log_skipped_export_products' ) ) {
	/**
	 * Log products that were queued for export but no longer exist in WooCommerce.
	 *
	 * @param int[] $missing_ids Product IDs.
	 */
	function sheetsync_log_skipped_export_products( int $connection_id, array $missing_ids ): void {
		$missing_ids = array_values( array_unique( array_filter( array_map( 'intval', $missing_ids ) ) ) );
		if ( empty( $missing_ids ) || ! class_exists( 'SheetSync_Logger', false ) ) {
			return;
		}

		$preview = array_slice( $missing_ids, 0, 10 );
		$labels  = array_map(
			static fn( int $id ): string => '#' . $id,
			$preview
		);
		$more    = count( $missing_ids ) > 10
			? sprintf( ' (+%d more)', count( $missing_ids ) - 10 )
			: '';

		SheetSync_Logger::log(
			$connection_id,
			'export',
			'partial',
			0,
			count( $missing_ids ),
			sprintf(
				/* translators: 1: count, 2: product id list, 3: optional “(+N more)” */
				__( 'Skipped %1$d product(s) not found in WooCommerce (may have been deleted): %2$s%3$s', 'sheetsync-for-woocommerce' ),
				count( $missing_ids ),
				implode( ', ', $labels ),
				$more
			),
			0
		);
	}
}

if ( ! function_exists( 'sheetsync_log_stale_product_maps' ) ) {
	/**
	 * Warn when product_map rows point at deleted WooCommerce products; optionally purge them.
	 */
	function sheetsync_log_stale_product_maps( int $connection_id ): void {
		if ( ! class_exists( 'SheetSync_Product_Map_Repository', false ) || ! class_exists( 'SheetSync_Logger', false ) ) {
			return;
		}

		$repo  = new SheetSync_Product_Map_Repository();
		$total = $repo->count_orphaned_maps( $connection_id );
		if ( $total < 1 ) {
			return;
		}

		$purged = 0;
		if ( apply_filters( 'sheetsync_auto_purge_orphaned_product_maps', true ) ) {
			$batch  = (int) apply_filters( 'sheetsync_orphaned_product_map_purge_batch', 200 );
			$purged = $repo->delete_orphaned_maps( $connection_id, $batch );
			$total  = $repo->count_orphaned_maps( $connection_id );
		}

		$stale  = $repo->get_orphaned_map_product_ids( $connection_id, 15 );
		$labels = array_map( static fn( int $id ): string => '#' . $id, $stale );
		$more   = $total > count( $stale )
			? sprintf( ' (+%d more)', $total - count( $stale ) )
			: '';

		$message = $purged > 0
			? sprintf(
				/* translators: 1: purged count, 2: remaining count, 3: product id list, 4: optional “(+N more)” */
				__( 'Removed %1$d stale product map row(s); %2$d still point at deleted products (%3$s%4$s). Run Full Sync to refresh the sheet.', 'sheetsync-for-woocommerce' ),
				$purged,
				$total,
				implode( ', ', $labels ),
				$more
			)
			: sprintf(
				/* translators: 1: count, 2: product id list, 3: optional “(+N more)” */
				__( '%1$d mapped product(s) no longer exist in WooCommerce (%2$s%3$s). Run Full Sync to refresh the sheet.', 'sheetsync-for-woocommerce' ),
				$total,
				implode( ', ', $labels ),
				$more
			);

		SheetSync_Logger::log(
			$connection_id,
			'export',
			$total > 0 ? 'partial' : 'success',
			$purged,
			$total,
			$message,
			0
		);
	}
}

if ( ! function_exists( 'sheetsync_handle_product_delete_for_maps' ) ) {
	/**
	 * Drop product_map rows when a product or variation is deleted.
	 */
	function sheetsync_handle_product_delete_for_maps( int $post_id, $post ): void {
		if ( ! $post instanceof WP_Post ) {
			$post = get_post( $post_id );
		}
		if ( ! $post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return;
		}
		if ( ! class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			return;
		}

		( new SheetSync_Product_Map_Repository() )->delete_by_product_id( $post_id );
	}
}

if ( ! function_exists( 'sheetsync_sanitize_multisite_stock_targets' ) ) {
	/**
	 * Validate and normalize multisite stock target JSON from settings.
	 */
	function sheetsync_sanitize_multisite_stock_targets( string $raw ): string {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return '';
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}

		$clean = array();
		foreach ( $decoded as $row ) {
			if ( ! is_array( $row ) || empty( $row['url'] ) || empty( $row['secret'] ) ) {
				continue;
			}
			$url = esc_url_raw( (string) $row['url'] );
			if ( $url === '' ) {
				continue;
			}
			$clean[] = array(
				'url'    => $url,
				'secret' => trim( sanitize_text_field( (string) $row['secret'] ) ),
			);
		}

		return empty( $clean ) ? '' : wp_json_encode( $clean );
	}
}

if ( ! function_exists( 'sheetsync_cleanup_legacy_rate_limit_options' ) ) {
	/**
	 * Remove legacy per-minute rate limit rows from wp_options (pre-2.0.6).
	 */
	function sheetsync_cleanup_legacy_rate_limit_options(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_sheetsync_rl_%'"
		);
	}
}

if ( ! function_exists( 'sheetsync_product_category_filter_join_sql' ) ) {
	/**
	 * Category filter join for product + variation queries.
	 * Variations inherit their parent product's categories (matches full export).
	 *
	 * @param array<int|string> $params Populated for prepare().
	 * @return string SQL join fragment (leading space) or empty.
	 */
	function sheetsync_product_category_filter_join_sql( int $connection_id, array &$params, string $alias = 'p' ): string {
		$cat_raw = (string) get_option( 'sheetsync_sync_category_ids_' . $connection_id, '' );
		if ( '' === trim( $cat_raw ) ) {
			return '';
		}
		$term_ids = array_filter( array_map( 'absint', explode( ',', str_replace( ' ', '', $cat_raw ) ) ) );
		if ( empty( $term_ids ) ) {
			return '';
		}
		global $wpdb;
		$cat_ph = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
		foreach ( $term_ids as $tid ) {
			$params[] = $tid;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $alias is a fixed posts-table alias from the caller.
		return " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = IF( {$alias}.post_type = 'product_variation', {$alias}.post_parent, {$alias}.ID )
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				AND tt.taxonomy = 'product_cat' AND tt.term_id IN ({$cat_ph})";
	}
}

if ( ! function_exists( 'sheetsync_count_query_join_for_connection' ) ) {
	/**
	 * Optional category filter join (parents only).
	 *
	 * @param array<int|string> $params Populated for prepare().
	 * @return string SQL join fragment (leading space) or empty.
	 */
	function sheetsync_count_query_join_for_connection( int $connection_id, array &$params ): string {
		$cat_raw = (string) get_option( 'sheetsync_sync_category_ids_' . $connection_id, '' );
		if ( '' === trim( $cat_raw ) ) {
			return '';
		}
		$term_ids = array_filter( array_map( 'absint', explode( ',', str_replace( ' ', '', $cat_raw ) ) ) );
		if ( empty( $term_ids ) ) {
			return '';
		}
		global $wpdb;
		$cat_ph = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
		foreach ( $term_ids as $tid ) {
			$params[] = $tid;
		}
		return " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				AND tt.taxonomy = 'product_cat' AND tt.term_id IN ({$cat_ph})";
	}
}

if ( ! function_exists( 'sheetsync_api_error_is_grid_limits' ) ) {
	/**
	 * Google Sheets rejected a range outside the tab grid (default tabs are often 1000×26).
	 */
	function sheetsync_api_error_is_grid_limits( string $message ): bool {
		$message = strtolower( $message );
		return str_contains( $message, 'exceeds grid limits' )
			|| str_contains( $message, 'max rows' )
			|| str_contains( $message, 'max columns' );
	}
}

if ( ! function_exists( 'sheetsync_api_error_is_quota' ) ) {
	/**
	 * Google Sheets API write/read quota (HTTP 429 or quota message in body).
	 */
	function sheetsync_api_error_is_quota( string $message ): bool {
		$message = strtolower( $message );
		return str_contains( $message, 'quota exceeded' )
			|| str_contains( $message, 'rate limit' )
			|| str_contains( $message, '429' );
	}
}

if ( ! function_exists( 'sheetsync_pause_for_sheets_quota' ) ) {
	/**
	 * Back off before retrying writes (background jobs only).
	 */
	function sheetsync_pause_for_sheets_quota(): void {
		if ( defined( 'SHEETSYNC_BG_SYNC' ) && SHEETSYNC_BG_SYNC ) {
			sleep( (int) apply_filters( 'sheetsync_quota_pause_seconds', 60 ) );
		}
	}
}

if ( ! function_exists( 'sheetsync_export_batch_delay_seconds' ) ) {
	/**
	 * Delay before the next export batch (Action Scheduler) — longer after quota/errors.
	 */
	function sheetsync_export_batch_delay_seconds( int $errors = 0, string $direction = '', ?object $job = null ): int {
		if ( $errors > 0 ) {
			return (int) apply_filters( 'sheetsync_export_batch_delay_on_error', 60 );
		}
		if ( 'bootstrap' !== $direction ) {
			return (int) apply_filters( 'sheetsync_export_batch_delay_default', 10 );
		}

		$total = $job ? max( 0, (int) ( $job->total_estimate ?? 0 ) ) : 0;
		if ( $total > 0 && $total <= 200 ) {
			return (int) apply_filters( 'sheetsync_export_batch_delay_bootstrap_small', 2 );
		}
		if ( $total > 500 ) {
			return (int) apply_filters( 'sheetsync_export_batch_delay_bootstrap_large', 15 );
		}

		return (int) apply_filters( 'sheetsync_export_batch_delay_bootstrap', 5 );
	}
}

if ( ! function_exists( 'sheetsync_count_exportable_products' ) ) {
	/**
	 * Count sheet rows for export (parent products + variation rows).
	 */
	function sheetsync_count_exportable_products( int $connection_id ): int {
		$breakdown = sheetsync_count_exportable_breakdown( $connection_id );
		return (int) ( $breakdown['sheet_rows'] ?? 0 );
	}
}

if ( ! function_exists( 'sheetsync_load_products_batch' ) ) {
	/**
	 * Batch-load WooCommerce products (single query instead of N× wc_get_product).
	 *
	 * @param int[] $product_ids
	 * @return array<int, WC_Product>
	 */
	function sheetsync_load_products_batch( array $product_ids ): array {
		$ids = array_values(
			array_unique(
				array_filter( array_map( 'absint', $product_ids ) )
			)
		);
		if ( empty( $ids ) ) {
			return array();
		}

		$by_id = array();
		if ( ! function_exists( 'wc_get_products' ) ) {
			return $by_id;
		}

		$statuses = class_exists( 'SheetSync_Export_Order', false )
			? SheetSync_Export_Order::export_post_statuses()
			: array( 'publish', 'draft', 'private', 'pending', 'future' );

		$products = wc_get_products(
			array(
				'include' => $ids,
				'limit'   => count( $ids ),
				'status'  => $statuses,
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

		return $by_id;
	}
}

if ( ! function_exists( 'sheetsync_export_catalog_revision' ) ) {
	/**
	 * Lightweight fingerprint for exportable catalog size (invalidates breakdown cache on change).
	 */
	function sheetsync_export_catalog_revision( int $connection_id ): string {
		global $wpdb;

		$statuses = class_exists( 'SheetSync_Export_Order', false )
			? SheetSync_Export_Order::export_post_statuses()
			: array( 'publish', 'draft', 'private', 'pending', 'future' );
		$status_ph = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$params    = $statuses;

		$join = function_exists( 'sheetsync_count_query_join_for_connection' )
			? sheetsync_count_query_join_for_connection( $connection_id, $params )
			: '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT MAX(p.post_modified_gmt), COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$join}
			WHERE p.post_type IN ('product','product_variation') AND p.post_status IN ({$status_ph})";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( sheetsync_wpdb_prepare( $sql, $params ), ARRAY_N );
		$cat = (string) get_option( 'sheetsync_sync_category_ids_' . $connection_id, '' );

		return md5(
			implode(
				'|',
				array(
					(string) ( $row[0] ?? '' ),
					(string) ( $row[1] ?? '0' ),
					$cat,
					implode( ',', $statuses ),
				)
			)
		);
	}
}

if ( ! function_exists( 'sheetsync_invalidate_export_breakdown_cache' ) ) {
	/**
	 * Clear cached export row counts after catalog changes.
	 */
	function sheetsync_invalidate_export_breakdown_cache( int $connection_id ): void {
		delete_transient( 'sheetsync_export_breakdown_' . max( 0, $connection_id ) );
		delete_transient( 'sheetsync_export_dup_audit_' . max( 0, $connection_id ) );
	}
}

if ( ! function_exists( 'sheetsync_count_exportable_breakdown' ) ) {
	/**
	 * Sheet row count = parents + variations (variable products export multiple rows).
	 *
	 * @return array{sheet_rows: int, parent_products: int, variations: int, trashed_parents: int}
	 */
	function sheetsync_count_exportable_breakdown( int $connection_id ): array {
		$revision  = function_exists( 'sheetsync_export_catalog_revision' )
			? sheetsync_export_catalog_revision( $connection_id )
			: '';
		$cache_key = 'sheetsync_export_breakdown_' . $connection_id;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached )
			&& isset( $cached['data'] )
			&& is_array( $cached['data'] )
			&& (string) ( $cached['revision'] ?? '' ) === $revision
			&& $revision !== '' ) {
			return $cached['data'];
		}

		global $wpdb;

		$active_statuses = class_exists( 'SheetSync_Export_Order', false )
			? SheetSync_Export_Order::export_post_statuses()
			: array( 'publish', 'draft', 'private', 'pending', 'future' );
		$active_ph       = implode( ',', array_fill( 0, count( $active_statuses ), '%s' ) );

		$count_type = static function ( string $post_type, string $status_sql, array $statuses, int $connection_id ) use ( $wpdb ): int {
			$params = array_merge( array( $post_type ), $statuses );
			$join   = sheetsync_count_query_join_for_connection( $connection_id, $params );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$join}
				WHERE p.post_type = %s AND p.post_status IN ({$status_sql})";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( sheetsync_wpdb_prepare( $sql, $params ) );
		};

		$parents = $count_type( 'product', $active_ph, $active_statuses, $connection_id );

		// Variations: only those with a valid parent product (same rule as export).
		$var_params  = array_merge( array( 'product' ), $active_statuses );
		$parent_join = sheetsync_count_query_join_for_connection( $connection_id, $var_params );
		$parent_join = str_replace( 'tr.object_id = p.ID', 'tr.object_id = parent.ID', $parent_join );
		$var_params  = array_merge( $var_params, array( 'product_variation' ), $active_statuses );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$var_sql = "SELECT COUNT(DISTINCT v.ID) FROM {$wpdb->posts} v
			INNER JOIN {$wpdb->posts} parent ON parent.ID = v.post_parent
				AND parent.post_type = %s AND parent.post_status IN ({$active_ph})
			{$parent_join}
			WHERE v.post_type = %s AND v.post_status IN ({$active_ph})";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$variations = (int) $wpdb->get_var( sheetsync_wpdb_prepare( $var_sql, $var_params ) );

		$trashed = $count_type( 'product', '%s', array( 'trash' ), $connection_id );

		// Orphan variations (no valid parent) — not exported; shown for diagnostics only.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orphan_sql = "SELECT COUNT(*) FROM {$wpdb->posts} v
			LEFT JOIN {$wpdb->posts} parent ON parent.ID = v.post_parent AND parent.post_type = 'product'
				AND parent.post_status IN ({$active_ph})
			WHERE v.post_type = 'product_variation' AND v.post_status IN ({$active_ph})
			AND parent.ID IS NULL";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$orphan_variations = (int) $wpdb->get_var(
			sheetsync_wpdb_prepare( $orphan_sql, array_merge( $active_statuses, $active_statuses ) )
		);

		$audit = array( 'total' => 0, 'unique' => 0, 'duplicate_ids' => 0 );
		if ( class_exists( 'SheetSync_Export_Order', false ) ) {
			$sheet_rows = $parents + $variations;
			$audit_threshold = (int) apply_filters( 'sheetsync_export_dup_audit_max_rows', 3000 );
			if ( $sheet_rows <= $audit_threshold ) {
				$audit_cache_key = 'sheetsync_export_dup_audit_' . $connection_id;
				$cached_audit    = get_transient( $audit_cache_key );
				if ( is_array( $cached_audit ) ) {
					$audit = $cached_audit;
				} else {
					$audit = SheetSync_Export_Order::audit_export_id_uniqueness( $connection_id );
					set_transient( $audit_cache_key, $audit, 15 * MINUTE_IN_SECONDS );
				}
			}
		}

		$result = array(
			'sheet_rows'          => $parents + $variations,
			'parent_products'     => $parents,
			'variations'          => $variations,
			'trashed_parents'     => $trashed,
			'orphan_variations'   => $orphan_variations,
			'export_duplicate_ids' => (int) ( $audit['duplicate_ids'] ?? 0 ),
		);

		if ( $revision !== '' ) {
			set_transient(
				$cache_key,
				array(
					'revision' => $revision,
					'data'     => $result,
				),
				(int) apply_filters( 'sheetsync_export_breakdown_cache_ttl', HOUR_IN_SECONDS )
			);
		}

		return $result;
	}
}

if ( ! function_exists( 'sheetsync_cache_sheet_row_stats' ) ) {
	/**
	 * Cache sheet row breakdown from the latest "Check sheet" validation run.
	 *
	 * @param array{simple?: int, variable_parents?: int, variations?: int, total_rows?: int, rows_checked?: int} $stats
	 */
	function sheetsync_cache_sheet_row_stats( int $connection_id, array $stats ): void {
		if ( $connection_id <= 0 ) {
			return;
		}
		$simple   = max( 0, (int) ( $stats['simple'] ?? 0 ) );
		$parents  = max( 0, (int) ( $stats['variable_parents'] ?? 0 ) );
		$vars     = max( 0, (int) ( $stats['variations'] ?? 0 ) );
		$total    = max( 0, (int) ( $stats['total_rows'] ?? ( $simple + $parents + $vars ) ) );
		if ( $total <= 0 ) {
			return;
		}
		set_transient(
			'sheetsync_sheet_row_stats_' . $connection_id,
			array(
				'simple'            => $simple,
				'variable_parents'  => $parents,
				'variations'        => $vars,
				'total_rows'        => $total,
				'rows_checked'      => max( 0, (int) ( $stats['rows_checked'] ?? 0 ) ),
				'cached_at'         => time(),
			),
			(int) apply_filters( 'sheetsync_sheet_row_stats_cache_ttl', DAY_IN_SECONDS )
		);
	}
}

if ( ! function_exists( 'sheetsync_get_cached_sheet_row_stats' ) ) {
	/**
	 * @return array{simple: int, variable_parents: int, variations: int, total_rows: int, rows_checked?: int, cached_at?: int}|null
	 */
	function sheetsync_get_cached_sheet_row_stats( int $connection_id ): ?array {
		if ( $connection_id <= 0 ) {
			return null;
		}
		$cached = get_transient( 'sheetsync_sheet_row_stats_' . $connection_id );
		return is_array( $cached ) && (int) ( $cached['total_rows'] ?? 0 ) > 0 ? $cached : null;
	}
}

if ( ! function_exists( 'sheetsync_connection_row_breakdown' ) ) {
	/**
	 * Row counts for UI and import estimates — sheet-first for import/display, WC catalog for export.
	 *
	 * @param string $context export|import|display|pull
	 * @return array{
	 *   sheet_rows: int,
	 *   parent_products: int,
	 *   variations: int,
	 *   simple?: int,
	 *   variable_parents?: int,
	 *   source: string,
	 *   trashed_parents?: int,
	 *   orphan_variations?: int,
	 *   export_duplicate_ids?: int
	 * }
	 */
	function sheetsync_connection_row_breakdown( int $connection_id, string $context = 'display' ): array {
		$context = strtolower( trim( $context ) );
		if ( 'export' === $context || 'bootstrap' === $context || 'push' === $context ) {
			$wc = sheetsync_count_exportable_breakdown( $connection_id );
			return array_merge(
				$wc,
				array( 'source' => 'wc_catalog' )
			);
		}

		$empty = array(
			'sheet_rows'      => 0,
			'parent_products' => 0,
			'variations'      => 0,
			'simple'          => 0,
			'variable_parents'=> 0,
			'source'          => 'unknown',
		);

		if ( $connection_id <= 0 ) {
			return $empty;
		}

		$from_stats = static function ( array $stats, string $source ): array {
			$simple  = max( 0, (int) ( $stats['simple'] ?? 0 ) );
			$parents = max( 0, (int) ( $stats['variable_parents'] ?? 0 ) );
			$vars    = max( 0, (int) ( $stats['variations'] ?? 0 ) );
			$total   = max( 0, (int) ( $stats['total_rows'] ?? ( $simple + $parents + $vars ) ) );
			return array(
				'sheet_rows'       => $total,
				'parent_products'  => $simple + $parents,
				'variations'       => $vars,
				'simple'           => $simple,
				'variable_parents' => $parents,
				'source'           => $source,
			);
		};

		$cached = sheetsync_get_cached_sheet_row_stats( $connection_id );
		if ( is_array( $cached ) ) {
			return $from_stats( $cached, 'validation' );
		}

		if ( class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			$mapped = ( new SheetSync_Product_Map_Repository() )->count_for_connection( $connection_id );
			if ( $mapped > 0 ) {
				return array(
					'sheet_rows'      => $mapped,
					'parent_products' => $mapped,
					'variations'      => 0,
					'simple'          => 0,
					'variable_parents'=> 0,
					'source'          => 'product_map',
				);
			}
		}

		if ( class_exists( 'SheetSync_Sync_Engine', false ) && function_exists( 'sheetsync_estimate_sheet_data_rows' ) ) {
			$conn = SheetSync_Sync_Engine::get_connection( $connection_id );
			if ( $conn ) {
				$estimate = sheetsync_estimate_sheet_data_rows( $conn, false );
				if ( $estimate > 0 ) {
					return array(
						'sheet_rows'      => $estimate,
						'parent_products' => $estimate,
						'variations'      => 0,
						'simple'          => 0,
						'variable_parents'=> 0,
						'source'          => 'sheet_api',
					);
				}
			}
		}

		$wc = sheetsync_count_exportable_breakdown( $connection_id );
		return array_merge(
			$wc,
			array(
				'source' => 'wc_catalog',
			)
		);
	}
}

if ( ! function_exists( 'sheetsync_count_importable_sheet_rows' ) ) {
	/**
	 * Sheet row estimate for Sheet → WooCommerce sync UI and job totals.
	 */
	function sheetsync_count_importable_sheet_rows( int $connection_id ): int {
		$breakdown = sheetsync_connection_row_breakdown( $connection_id, 'import' );
		return (int) ( $breakdown['sheet_rows'] ?? 0 );
	}
}

if ( ! function_exists( 'sheetsync_get_setup_progress_defaults' ) ) {
	/**
	 * Default setup wizard / checklist step flags.
	 *
	 * @return array<string, bool>
	 */
	function sheetsync_get_setup_progress_defaults(): array {
		return array(
			'google_connected'   => false,
			'sheet_shared'       => false,
			'connection_created' => false,
			'template_written'   => false,
			'first_sync_done'    => false,
			'realtime_enabled'   => false,
		);
	}
}

if ( ! function_exists( 'sheetsync_parse_spreadsheet_id' ) ) {
	/**
	 * Extract spreadsheet ID from a full Google Sheets URL or return the input if already an ID.
	 */
	function sheetsync_parse_spreadsheet_id( string $input ): string {
		$input = trim( $input );
		if ( $input === '' ) {
			return '';
		}
		if ( preg_match( '#spreadsheets/d/([a-zA-Z0-9_-]+)#', $input, $matches ) ) {
			return $matches[1];
		}
		return sanitize_text_field( $input );
	}
}

if ( ! function_exists( 'sheetsync_has_verified_realtime_sync' ) ) {
	/**
	 * True when Apps Script / webhook has successfully reached the site for an enabled connection.
	 */
	function sheetsync_has_verified_realtime_sync(): bool {
		$auto_sync = get_option( 'sheetsync_auto_sync_settings', array() );
		if ( ! is_array( $auto_sync ) ) {
			return false;
		}
		foreach ( $auto_sync as $conn_id => $enabled ) {
			if ( ! $enabled || (int) $conn_id < 1 ) {
				continue;
			}
			if ( (int) get_option( 'sheetsync_webhook_verified_' . (int) $conn_id, 0 ) > 0 ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'sheetsync_refresh_setup_progress' ) ) {
	/**
	 * Merge stored flags with detectable state from the database and options.
	 *
	 * @return array<string, bool>
	 */
	function sheetsync_refresh_setup_progress(): array {
		global $wpdb;

		$stored   = get_option( 'sheetsync_setup_progress', array() );
		$progress = array_merge(
			sheetsync_get_setup_progress_defaults(),
			is_array( $stored ) ? $stored : array()
		);

		if ( class_exists( 'SheetSync_Google_Auth', false ) && SheetSync_Google_Auth::get_account_email() !== '' ) {
			$progress['google_connected'] = true;
		}

		$conn_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sheetsync_connections" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $conn_count > 0 ) {
			$progress['connection_created'] = true;
		}

		$synced_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sheetsync_connections WHERE last_sync_at IS NOT NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $synced_count > 0 ) {
			$progress['first_sync_done'] = true;
		}

		$progress['realtime_enabled'] = function_exists( 'sheetsync_has_verified_realtime_sync' )
			? sheetsync_has_verified_realtime_sync()
			: false;

		$template_written = get_option( 'sheetsync_template_written_connections', array() );
		if ( is_array( $template_written ) && ! empty( $template_written ) ) {
			$progress['template_written'] = true;
		}

		update_option( 'sheetsync_setup_progress', $progress, false );

		return $progress;
	}
}

if ( ! function_exists( 'sheetsync_get_setup_progress' ) ) {
	/**
	 * Central setup progress for wizard, checklist, and progress bar.
	 *
	 * @return array<string, bool>
	 */
	function sheetsync_get_setup_progress(): array {
		return sheetsync_refresh_setup_progress();
	}
}

if ( ! function_exists( 'sheetsync_update_setup_progress' ) ) {
	/**
	 * Update a single setup step flag.
	 */
	function sheetsync_update_setup_progress( string $step, bool $value = true ): void {
		$allowed = array_keys( sheetsync_get_setup_progress_defaults() );
		if ( ! in_array( $step, $allowed, true ) ) {
			return;
		}

		$progress         = sheetsync_get_setup_progress();
		$progress[ $step ] = $value;
		update_option( 'sheetsync_setup_progress', $progress, false );
	}
}

if ( ! function_exists( 'sheetsync_get_setup_progress_percent' ) ) {
	function sheetsync_get_setup_progress_percent( ?array $progress = null ): int {
		$progress = $progress ?? sheetsync_get_setup_progress();
		$steps    = sheetsync_get_setup_progress_defaults();
		$done     = 0;
		foreach ( array_keys( $steps ) as $key ) {
			if ( ! empty( $progress[ $key ] ) ) {
				++$done;
			}
		}
		return (int) round( ( $done / max( 1, count( $steps ) ) ) * 100 );
	}
}

if ( ! function_exists( 'sheetsync_get_setup_steps_config' ) ) {
	/**
	 * Ordered setup steps with labels and admin URLs for the progress UI.
	 *
	 * @return array<int, array{key: string, label: string, url: string, optional?: bool}>
	 */
	function sheetsync_get_setup_steps_config(): array {
		$first_conn_id = 0;
		global $wpdb;
		$first_conn_id = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}sheetsync_connections ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$edit_url = $first_conn_id
			? admin_url( "admin.php?page=sheetsync&sheetsync_action=edit&connection_id={$first_conn_id}" )
			: admin_url( 'admin.php?page=sheetsync&sheetsync_action=new' );

		return array(
			array(
				'key'   => 'google_connected',
				'label' => __( 'Connect Google', 'sheetsync-for-woocommerce' ),
				'url'   => admin_url( 'admin.php?page=sheetsync-settings' ),
			),
			array(
				'key'   => 'sheet_shared',
				'label' => __( 'Share your sheet', 'sheetsync-for-woocommerce' ),
				'url'   => $edit_url . '#tab-connection',
			),
			array(
				'key'   => 'connection_created',
				'label' => __( 'Create connection', 'sheetsync-for-woocommerce' ),
				'url'   => admin_url( 'admin.php?page=sheetsync&sheetsync_action=new' ),
			),
			array(
				'key'   => 'template_written',
				'label' => __( 'Set up sheet template', 'sheetsync-for-woocommerce' ),
				'url'   => $edit_url . '#tab-sync',
			),
			array(
				'key'   => 'first_sync_done',
				'label' => __( 'Run Sync now once', 'sheetsync-for-woocommerce' ),
				'url'   => $edit_url . '#tab-sync',
			),
			array(
				'key'      => 'realtime_enabled',
				'label'    => __( 'Optional: automatic sheet updates', 'sheetsync-for-woocommerce' ),
				'url'      => $edit_url . '#tab-sync',
				'optional' => true,
			),
		);
	}
}

if ( ! function_exists( 'sheetsync_get_next_setup_step' ) ) {
	/**
	 * First incomplete required step (skips optional realtime until required steps are done).
	 *
	 * @return array{key: string, label: string, url: string}|null
	 */
	function sheetsync_get_next_setup_step( ?array $progress = null ): ?array {
		$progress = $progress ?? sheetsync_get_setup_progress();

		foreach ( sheetsync_get_setup_steps_config() as $step ) {
			if ( ! empty( $step['optional'] ) ) {
				continue;
			}
			if ( empty( $progress[ $step['key'] ] ) ) {
				return $step;
			}
		}

		foreach ( sheetsync_get_setup_steps_config() as $step ) {
			if ( ! empty( $step['optional'] ) && empty( $progress[ $step['key'] ] ) ) {
				return $step;
			}
		}

		return null;
	}
}

if ( ! function_exists( 'sheetsync_mark_template_written' ) ) {
	function sheetsync_mark_template_written( int $connection_id ): void {
		$written = get_option( 'sheetsync_template_written_connections', array() );
		if ( ! is_array( $written ) ) {
			$written = array();
		}
		$written[ $connection_id ] = true;
		update_option( 'sheetsync_template_written_connections', $written, false );
		sheetsync_update_setup_progress( 'template_written', true );
	}
}

if ( ! function_exists( 'sheetsync_get_wizard_state' ) ) {
	/**
	 * @return array<string, mixed>
	 */
	function sheetsync_get_wizard_state( ?int $user_id = null ): array {
		$user_id = $user_id ?? get_current_user_id();
		$stored  = get_user_meta( $user_id, 'sheetsync_wizard_state', true );
		$defaults = array(
			'step'            => 1,
			'skipped'         => false,
			'completed'       => false,
			'sync_direction'  => 'sheets_to_wc',
			'connection_id'   => 0,
			'spreadsheet_id'  => '',
			'sheet_name'      => 'Sheet1',
			'connection_name' => '',
			'sheet_mode'      => 'new',
		);
		return array_merge( $defaults, is_array( $stored ) ? $stored : array() );
	}
}

if ( ! function_exists( 'sheetsync_save_wizard_state' ) ) {
	/**
	 * @param array<string, mixed> $patch
	 */
	function sheetsync_save_wizard_state( array $patch, ?int $user_id = null ): array {
		$user_id = $user_id ?? get_current_user_id();
		$state   = array_merge( sheetsync_get_wizard_state( $user_id ), $patch );
		update_user_meta( $user_id, 'sheetsync_wizard_state', $state );
		return $state;
	}
}

if ( ! function_exists( 'sheetsync_wizard_is_complete' ) ) {
	function sheetsync_wizard_is_complete( ?int $user_id = null ): bool {
		$state = sheetsync_get_wizard_state( $user_id );
		if ( ! empty( $state['skipped'] ) || ! empty( $state['completed'] ) ) {
			return true;
		}
		$progress = sheetsync_get_setup_progress();
		return ! empty( $progress['first_sync_done'] );
	}
}

if ( ! function_exists( 'sheetsync_user_can_access_admin' ) ) {
	/**
	 * Whether the current user may access SheetSync admin screens.
	 */
	function sheetsync_user_can_access_admin(): bool {
		foreach ( array( 'manage_woocommerce', 'manage_options', 'edit_shop_orders' ) as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'sheetsync_should_redirect_to_wizard' ) ) {
	function sheetsync_should_redirect_to_wizard(): bool {
		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( ! sheetsync_user_can_access_admin() ) {
			return false;
		}
		if ( sheetsync_wizard_is_complete() ) {
			return false;
		}
		return (bool) get_option( 'sheetsync_redirect_to_wizard', false );
	}
}

if ( ! function_exists( 'sheetsync_get_action_scheduler_health' ) ) {
	/**
	 * Detect Action Scheduler backlog (past-due pending actions).
	 *
	 * @return array{ok: bool, past_due: int, message: string, tools_url: string}
	 */
	function sheetsync_get_action_scheduler_health(): array {
		$tools_url = admin_url( 'admin.php?page=wc-status&tab=action-scheduler' );
		$past_due  = 0;

		if ( function_exists( 'as_get_scheduled_actions' ) && function_exists( 'as_get_datetime_object' ) ) {
			$now = as_get_datetime_object();
			// Site-wide past-due (matches WooCommerce → Status warning).
			$all_past = as_get_scheduled_actions(
				array(
					'status'       => 'pending',
					'date'         => $now,
					'date_compare' => '<',
					'per_page'     => 100,
				),
				'ids'
			);
			$past_due = is_array( $all_past ) ? count( $all_past ) : 0;
		} elseif ( class_exists( 'ActionScheduler', false ) && method_exists( ActionScheduler::store(), 'extra_action_counts' ) ) {
			$extra    = ActionScheduler::store()->extra_action_counts();
			$past_due = (int) ( $extra['past-due'] ?? 0 );
		}

		$ok = $past_due < 5;

		if ( $past_due >= 5 ) {
			$message = sprintf(
				/* translators: 1: number of past-due actions */
				__( '%1$d background tasks are overdue. Large exports may stall until Action Scheduler runs. Open WooCommerce → Status → Scheduled Actions and run pending actions, or fix WP-Cron.', 'sheetsync-for-woocommerce' ),
				$past_due
			);
		} else {
			$message = __( 'Background task queue looks healthy.', 'sheetsync-for-woocommerce' );
		}

		return array(
			'ok'        => $ok,
			'past_due'  => $past_due,
			'message'   => $message,
			'tools_url' => $tools_url,
		);
	}
}

if ( ! function_exists( 'sheetsync_get_setup_health_summary' ) ) {
	/**
	 * Site-wide setup health for agencies / multi-connection stores.
	 *
	 * @return array{connections: int, setup_percent: int, scheduler_ok: bool, google_connected: bool}
	 */
	function sheetsync_get_setup_health_summary(): array {
		global $wpdb;

		$conn_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sheetsync_connections" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$progress   = function_exists( 'sheetsync_get_setup_progress' ) ? sheetsync_get_setup_progress() : array();
		$percent    = function_exists( 'sheetsync_get_setup_progress_percent' )
			? sheetsync_get_setup_progress_percent( $progress )
			: 0;
		$as         = sheetsync_get_action_scheduler_health();

		return array(
			'connections'       => $conn_count,
			'setup_percent'     => (int) $percent,
			'scheduler_ok'      => (bool) ( $as['ok'] ?? true ),
			'google_connected'  => ! empty( $progress['google_connected'] ),
		);
	}
}

if ( ! function_exists( 'sheetsync_get_setup_health_traffic_lights' ) ) {
	/**
	 * Traffic-light checks for wizard completion, connections list, and settings.
	 *
	 * @return array<int, array{key: string, label: string, ok: bool, fix_url: string, fix_label: string}>
	 */
	function sheetsync_get_setup_health_traffic_lights(): array {
		$progress   = function_exists( 'sheetsync_get_setup_progress' ) ? sheetsync_get_setup_progress() : array();
		$google_ok  = ! empty( $progress['google_connected'] )
			|| ( class_exists( 'SheetSync_Google_Auth', false ) && SheetSync_Google_Auth::get_account_email() );
		$sheet_ok   = ! empty( $progress['sheet_shared'] ) || ! empty( $progress['connection_created'] );
		$sync_ok    = ! empty( $progress['first_sync_done'] );
		$as         = sheetsync_get_action_scheduler_health();
		$cron_ok    = (bool) ( $as['ok'] ?? true );

		global $wpdb;
		$first_conn_id = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}sheetsync_connections ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$conn_edit     = $first_conn_id
			? admin_url( "admin.php?page=sheetsync&sheetsync_action=edit&connection_id={$first_conn_id}" )
			: admin_url( 'admin.php?page=sheetsync&sheetsync_action=new' );

		return array(
			array(
				'key'       => 'google',
				'label'     => __( 'Google connected', 'sheetsync-for-woocommerce' ),
				'ok'        => $google_ok,
				'fix_url'   => admin_url( 'admin.php?page=sheetsync-settings#tab-settings-google' ),
				'fix_label' => __( 'Connect Google', 'sheetsync-for-woocommerce' ),
			),
			array(
				'key'       => 'sheet',
				'label'     => __( 'Sheet shared', 'sheetsync-for-woocommerce' ),
				'ok'        => $sheet_ok,
				'fix_url'   => $conn_edit . '#tab-connection',
				'fix_label' => __( 'Share sheet', 'sheetsync-for-woocommerce' ),
			),
			array(
				'key'       => 'sync',
				'label'     => __( 'First sync done', 'sheetsync-for-woocommerce' ),
				'ok'        => $sync_ok,
				'fix_url'   => $conn_edit . '#tab-sync',
				'fix_label' => __( 'Run sync', 'sheetsync-for-woocommerce' ),
			),
			array(
				'key'       => 'cron',
				'label'     => __( 'Background tasks healthy', 'sheetsync-for-woocommerce' ),
				'ok'        => $cron_ok,
				'fix_url'   => admin_url( 'admin.php?page=sheetsync-settings#tab-settings-cron' ),
				'fix_label' => __( 'Set up Background Cron', 'sheetsync-for-woocommerce' ),
			),
		);
	}
}

if ( ! function_exists( 'sheetsync_setup_health_all_ok' ) ) {
	function sheetsync_setup_health_all_ok(): bool {
		foreach ( sheetsync_get_setup_health_traffic_lights() as $light ) {
			if ( empty( $light['ok'] ) ) {
				return false;
			}
		}
		return true;
	}
}

if ( ! function_exists( 'sheetsync_get_plugin_health' ) ) {
	/**
	 * Operational health snapshot for monitoring / REST health endpoint.
	 *
	 * @return array<string, mixed>
	 */
	function sheetsync_get_plugin_health(): array {
		$as    = sheetsync_get_action_scheduler_health();
		$setup = sheetsync_get_setup_health_summary();
		$jobs  = array();

		if ( class_exists( 'SheetSync_Job_Repository', false ) ) {
			$jobs = ( new SheetSync_Job_Repository() )->count_by_status();
		}

		$settings     = get_option( 'sheetsync_settings', array() );
		$failed_jobs  = (int) ( $jobs['failed'] ?? 0 );
		$scheduler_ok = (bool) ( $as['ok'] ?? true );

		return array(
			'ok'             => $scheduler_ok && $failed_jobs < 10,
			'plugin_version' => defined( 'SHEETSYNC_VERSION' ) ? SHEETSYNC_VERSION : '',
			'schema_version' => (string) get_option( 'sheetsync_schema_version', '' ),
			'log_level'      => (string) ( $settings['log_level'] ?? 'info' ),
			'connections'    => (int) ( $setup['connections'] ?? 0 ),
			'action_scheduler' => $as,
			'setup'          => $setup,
			'jobs'           => $jobs,
			'flags'          => array(
				'job_engine'       => sheetsync_use_job_engine(),
				'order_job_engine' => sheetsync_order_job_engine(),
			),
		);
	}
}

if ( ! function_exists( 'sheetsync_help_tip' ) ) {
	/**
	 * Render a small (?) help tooltip for admin fields.
	 */
	function sheetsync_help_tip( string $text ): string {
		return '<span class="ss-help-tip" tabindex="0" role="button" '
			. 'aria-label="' . esc_attr__( 'Help', 'sheetsync-for-woocommerce' ) . '" '
			. 'data-tip="' . esc_attr( $text ) . '">?</span>';
	}
}

if ( ! function_exists( 'sheetsync_get_wizard_step_videos' ) ) {
	/**
	 * @return array<int, string> step => embed URL
	 */
	function sheetsync_get_wizard_step_videos(): array {
		$defaults = array(
			1 => 'https://www.youtube.com/embed/8qA5bJ0rA0M',
			2 => '',
			3 => '',
			4 => '',
			5 => '',
			6 => '',
			7 => '',
		);
		$custom = get_option( 'sheetsync_wizard_video_urls', array() );
		if ( ! is_array( $custom ) ) {
			$custom = array();
		}
		$settings = get_option( 'sheetsync_settings', array() );
		if ( ! empty( $settings['setup_video_url'] ) ) {
			$defaults[1] = (string) $settings['setup_video_url'];
		}
		return array_merge( $defaults, $custom );
	}
}

if ( ! function_exists( 'sheetsync_get_wizard_branding' ) ) {
	/**
	 * @return array{title: string, logo_url: string, hide_support: bool}
	 */
	function sheetsync_get_wizard_branding(): array {
		$stored = get_option( 'sheetsync_wizard_branding', array() );
		return wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array(
				'title'        => '',
				'logo_url'     => '',
				'hide_support' => false,
			)
		);
	}
}

if ( ! function_exists( 'sheetsync_assign_wizard_ab_variant' ) ) {
	function sheetsync_assign_wizard_ab_variant( ?int $user_id = null ): string {
		$user_id = $user_id ?? get_current_user_id();
		$existing = get_user_meta( $user_id, 'sheetsync_wizard_ab_variant', true );
		if ( $existing === 'a' || $existing === 'b' ) {
			return (string) $existing;
		}
		$variant = wp_rand( 0, 1 ) === 0 ? 'a' : 'b';
		update_user_meta( $user_id, 'sheetsync_wizard_ab_variant', $variant );
		$counts = get_option( 'sheetsync_wizard_ab_counts', array( 'a' => 0, 'b' => 0 ) );
		if ( ! is_array( $counts ) ) {
			$counts = array( 'a' => 0, 'b' => 0 );
		}
		$counts[ $variant ] = (int) ( $counts[ $variant ] ?? 0 ) + 1;
		update_option( 'sheetsync_wizard_ab_counts', $counts, false );
		return $variant;
	}
}

if ( ! function_exists( 'sheetsync_get_wizard_ab_stats' ) ) {
	/**
	 * @return array{a: int, b: int}
	 */
	function sheetsync_get_wizard_ab_stats(): array {
		$counts = get_option( 'sheetsync_wizard_ab_counts', array( 'a' => 0, 'b' => 0 ) );
		if ( ! is_array( $counts ) ) {
			$counts = array( 'a' => 0, 'b' => 0 );
		}
		return array(
			'a' => (int) ( $counts['a'] ?? 0 ),
			'b' => (int) ( $counts['b'] ?? 0 ),
		);
	}
}

if ( ! function_exists( 'sheetsync_admin_menu_capability' ) ) {
	/**
	 * Capability for SheetSync admin menus and permission checks.
	 */
	function sheetsync_admin_menu_capability(): string {
		$allowed_caps = array( 'manage_woocommerce', 'manage_options', 'edit_shop_orders' );
		$preferred    = apply_filters( 'sheetsync_admin_capability', 'manage_woocommerce' );
		if ( ! in_array( $preferred, $allowed_caps, true ) ) {
			$preferred = 'manage_woocommerce';
		}

		foreach ( array_unique( array_merge( array( $preferred ), $allowed_caps ) ) as $cap ) {
			if ( current_user_can( $cap ) ) {
				return $cap;
			}
		}

		return 'manage_woocommerce';
	}
}
