<?php
/**
 * Host-adaptive sync limits — works on shared hosting without manual server setup.
 *
 * Manual sync is designed to complete via short admin AJAX requests (tab drain).
 * Action Scheduler and External Cron are optional accelerators, not requirements.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Host_Profile', false ) ) :

class SheetSync_Host_Profile {

	public const OPTION_STATE = 'sheetsync_host_profile_state';

	/**
	 * @return array{mode: string, timeout_events: int, last_timeout_at: int}
	 */
	public static function get_state(): array {
		$state = get_option( self::OPTION_STATE, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		return array(
			'mode'             => (string) ( $state['mode'] ?? 'standard' ),
			'timeout_events'   => max( 0, (int) ( $state['timeout_events'] ?? 0 ) ),
			'last_timeout_at'  => max( 0, (int) ( $state['last_timeout_at'] ?? 0 ) ),
		);
	}

	public static function is_tight(): bool {
		if ( (bool) apply_filters( 'sheetsync_force_tight_host_profile', false ) ) {
			return true;
		}
		$state = self::get_state();
		if ( 'tight' === $state['mode'] ) {
			return true;
		}
		if ( $state['timeout_events'] >= 2 ) {
			return true;
		}
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return true;
		}
		return (bool) apply_filters( 'sheetsync_is_tight_host', false );
	}

	/**
	 * Record a gateway/connection timeout so future requests use smaller budgets automatically.
	 */
	public static function record_timeout(): void {
		$state = self::get_state();
		++$state['timeout_events'];
		$state['last_timeout_at'] = time();
		if ( $state['timeout_events'] >= 2 ) {
			$state['mode'] = 'tight';
		}
		update_option( self::OPTION_STATE, $state, false );
	}

	public static function is_server_friendly(): bool {
		if ( self::is_tight() ) {
			return true;
		}
		if ( function_exists( 'sheetsync_get_action_scheduler_health' ) ) {
			return ! ( sheetsync_get_action_scheduler_health()['ok'] ?? true );
		}
		return (bool) apply_filters( 'sheetsync_server_friendly_mode', false );
	}

	/**
	 * Max seconds of job work per admin-ajax request (tab drain).
	 */
	public static function admin_request_budget_seconds(): int {
		$default = self::is_tight() ? 3 : 4;
		$seconds = (int) apply_filters( 'sheetsync_admin_request_budget_seconds', $default );
		return max( 2, min( self::is_tight() ? 5 : 8, $seconds ) );
	}

	/**
	 * Max rows per export push batch on typical shared hosting.
	 */
	public static function max_push_batch( string $job_direction = '' ): int {
		$base = self::is_tight() ? 10 : 15;
		if ( in_array( $job_direction, array( 'bootstrap', 'push' ), true ) ) {
			$base = min( $base, self::is_tight() ? 8 : 12 );
		}
		return max( 5, (int) apply_filters( 'sheetsync_host_max_push_batch', $base, $job_direction ) );
	}

	/**
	 * Max rows per sheet pull batch.
	 */
	public static function max_pull_batch(): int {
		$base = self::is_tight() ? 20 : 50;
		return max( 15, (int) apply_filters( 'sheetsync_host_max_pull_batch', $base ) );
	}

	/**
	 * Admin JS poll interval while a job is running (ms).
	 */
	public static function admin_poll_interval_ms(): int {
		if ( self::is_server_friendly() ) {
			return max( 3500, (int) apply_filters( 'sheetsync_server_friendly_poll_ms', 4500 ) );
		}
		return self::is_tight() ? 2000 : 2500;
	}

	/**
	 * Minimum ms between tab drain AJAX calls.
	 */
	public static function admin_drain_interval_ms(): int {
		if ( self::is_server_friendly() ) {
			return max( 4500, (int) apply_filters( 'sheetsync_server_friendly_drain_ms', 5500 ) );
		}
		return self::is_tight() ? 2000 : 3000;
	}

	/**
	 * Default batch size for new installs and unmigrated sites.
	 */
	public static function default_batch_size(): int {
		return max( 10, min( 50, (int) apply_filters( 'sheetsync_default_batch_size', 25 ) ) );
	}

	/**
	 * Inline job loop cap during admin tab drain (prevents one HTTP request doing too much).
	 */
	public static function inline_drain_max_loops(): int {
		return max( 4, min( 16, (int) apply_filters( 'sheetsync_inline_drain_max_loops', self::is_tight() ? 6 : 10 ) ) );
	}
}

endif;
