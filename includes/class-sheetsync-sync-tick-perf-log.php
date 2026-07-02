<?php
/**
 * Performance instrumentation for admin-ajax sync ticks (opt-in via SHEETSYNC_PERF_LOG).
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Sync_Tick_Perf_Log', false ) ) :

/**
 * Request-scoped counters for sheetsync_sync_tick profiling.
 */
class SheetSync_Sync_Tick_Perf_Log {

	private static bool $session_active = false;

	private static float $started_at = 0.0;

	private static int $job_id = 0;

	private static int $processed_before = 0;

	private static int $google_api_calls = 0;

	private static bool $deadline_exit = false;

	public static function is_enabled(): bool {
		return function_exists( 'sheetsync_perf_log_enabled' ) && sheetsync_perf_log_enabled();
	}

	public static function is_session_active(): bool {
		return self::$session_active;
	}

	public static function begin( int $job_id ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		self::$session_active   = true;
		self::$started_at       = microtime( true );
		self::$job_id           = max( 0, $job_id );
		self::$processed_before = 0;
		self::$google_api_calls = 0;
		self::$deadline_exit    = false;

		if ( self::$job_id > 0 && class_exists( 'SheetSync_Job_Repository', false ) ) {
			$job = ( new SheetSync_Job_Repository() )->get( self::$job_id );
			if ( $job ) {
				self::$processed_before = (int) ( $job->processed_count ?? 0 );
			}
		}
	}

	public static function note_google_api_call(): void {
		if ( self::$session_active ) {
			++self::$google_api_calls;
		}
	}

	public static function note_deadline_exit(): void {
		if ( self::$session_active ) {
			self::$deadline_exit = true;
		}
	}

	/**
	 * @param array<string, mixed> $tick_result
	 */
	public static function end( array $tick_result = array() ): void {
		if ( ! self::is_enabled() || ! self::$session_active ) {
			return;
		}

		$elapsed = microtime( true ) - self::$started_at;
		$job_id  = self::$job_id;

		if ( $job_id < 1 && ! empty( $tick_result['job']['id'] ) ) {
			$job_id = (int) $tick_result['job']['id'];
		}

		$processed_after = self::$processed_before;

		if ( $job_id > 0 && class_exists( 'SheetSync_Job_Repository', false ) ) {
			$job = ( new SheetSync_Job_Repository() )->get( $job_id );
			if ( $job ) {
				$processed_after = (int) ( $job->processed_count ?? 0 );
			}
		}

		$rows_processed = max( 0, $processed_after - self::$processed_before );
		$exit_reason    = self::$deadline_exit ? 'job_deadline' : 'normal';
		$peak_memory    = memory_get_peak_usage( true );
		$drained        = ! empty( $tick_result['drained'] ) ? 'yes' : 'no';

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'[SheetSync sync_tick perf] job_id=%d elapsed_sec=%.3f rows_processed=%d google_api_calls=%d peak_memory_bytes=%d exit=%s inline_drain=%s',
				$job_id,
				$elapsed,
				$rows_processed,
				self::$google_api_calls,
				$peak_memory,
				$exit_reason,
				$drained
			)
		);

		self::$session_active = false;
	}
}

endif;
