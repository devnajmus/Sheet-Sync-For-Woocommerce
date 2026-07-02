<?php
/**
 * External cron / queue runner for hosts where WP-Cron is unreliable.
 *
 * Hit via REST (secret token) or WP-CLI so Smart Poll, jobs, and media keep moving.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_External_Cron', false ) ) :

class SheetSync_External_Cron {

	public const OPTION_TOKEN   = 'sheetsync_external_cron_token';
	public const OPTION_ENABLED = 'sheetsync_external_cron_enabled';
	public const OPTION_LAST_RUN = 'sheetsync_external_cron_last_result';
	public const HTTP_HEADER    = 'X-SheetSync-Cron-Token';

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_generate_token' ), 5 );
	}

	public static function is_enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, false );
	}

	public static function set_enabled( bool $enabled ): void {
		update_option( self::OPTION_ENABLED, $enabled, false );
	}

	public static function maybe_generate_token(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( (string) get_option( self::OPTION_TOKEN, '' ) === '' ) {
			self::regenerate_token();
		}
	}

	public static function get_token(): string {
		$token = (string) get_option( self::OPTION_TOKEN, '' );
		if ( $token === '' ) {
			$token = self::regenerate_token();
		}
		return $token;
	}

	public static function regenerate_token(): string {
		$token = wp_generate_password( 32, false, false );
		update_option( self::OPTION_TOKEN, $token, false );
		return $token;
	}

	/**
	 * REST endpoint URL (no token — pass token via {@see self::HTTP_HEADER}).
	 */
	public static function get_rest_endpoint_url(): string {
		return rest_url( 'sheetsync/v1/queue-run' );
	}

	/**
	 * Ping URL (health check only, no token in query string).
	 */
	public static function get_rest_ping_url(): string {
		return add_query_arg( 'ping', '1', self::get_rest_endpoint_url() );
	}

	/**
	 * @deprecated 2.0.5 Use get_rest_endpoint_url() and send token in header.
	 */
	public static function get_rest_url(): string {
		return self::get_rest_endpoint_url();
	}

	/**
	 * Example cURL command for server cron (token in header, not URL).
	 */
	public static function get_example_curl( bool $ping = false ): string {
		$url = $ping ? self::get_rest_ping_url() : self::get_rest_endpoint_url();
		return sprintf(
			'curl -fsS -m 60 -H "%s: %s" "%s"',
			self::HTTP_HEADER,
			self::get_token(),
			$url
		);
	}

	/**
	 * @param string $token Token from query string or header.
	 */
	public static function verify_token( string $token ): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}
		$token    = trim( $token );
		$expected = (string) get_option( self::OPTION_TOKEN, '' );
		if ( $token === '' || $expected === '' ) {
			return false;
		}
		return hash_equals( $expected, $token );
	}

	/**
	 * Run background maintenance: Action Scheduler, stuck jobs, Smart Poll, media queue.
	 *
	 * @return array<string, mixed>
	 */
	public static function run( int $max_seconds = 25 ): array {
		$max_seconds = max( 5, min( 60, $max_seconds ) );
		$started     = time();
		$deadline    = $started + $max_seconds;

		$before = function_exists( 'sheetsync_get_action_scheduler_health' )
			? sheetsync_get_action_scheduler_health()
			: array( 'past_due' => 0, 'ok' => true );

		$steps = array();

		if ( class_exists( 'ActionScheduler_QueueRunner', false ) ) {
			try {
				$budget = max( 3, min( 20, $deadline - time() ) );
				ActionScheduler_QueueRunner::instance()->run( $budget );
				$steps['action_scheduler'] = array(
					'status'  => 'ran',
					'seconds' => $budget,
				);
			} catch ( Throwable $e ) {
				$steps['action_scheduler'] = array(
					'status' => 'error',
					'error'  => $e->getMessage(),
				);
			}
		} else {
			$steps['action_scheduler'] = array( 'status' => 'unavailable' );
		}

		if ( function_exists( 'sheetsync_kick_background_queue' ) ) {
			sheetsync_kick_background_queue( max( 3, min( 12, $deadline - time() ) ) );
			$steps['sheetsync_jobs'] = array( 'status' => 'kicked' );
		}

		if ( function_exists( 'sheetsync_resume_active_sync_jobs' ) && time() < $deadline ) {
			$inline = max( 5, min( 15, $deadline - time() ) );
			sheetsync_resume_active_sync_jobs( $inline, 2 );
			$steps['resume_jobs'] = array(
				'status'  => 'ran',
				'seconds' => $inline,
			);
		}

		if ( time() < $deadline
			&& class_exists( 'SheetSync_Product_Sheet_Poller', false )
			&& SheetSync_Product_Sheet_Poller::is_needed() ) {
			( new SheetSync_Product_Sheet_Poller() )->run();
			$steps['sheet_poll'] = array( 'status' => 'ran' );
		}

		if ( time() < $deadline
			&& class_exists( 'SheetSync_Order_Sheet_Poller', false )
			&& SheetSync_Order_Sheet_Poller::is_needed() ) {
			( new SheetSync_Order_Sheet_Poller() )->run();
			$steps['order_sheet_poll'] = array( 'status' => 'ran' );
		}

		if ( time() < $deadline && class_exists( 'SheetSync_Media_Queue', false ) ) {
			$media = SheetSync_Media_Queue::process_batch( 0, 5, $deadline );
			$steps['media_queue'] = array(
				'status'    => 'ran',
				'processed' => (int) ( $media['processed'] ?? 0 ),
				'errors'    => (int) ( $media['errors'] ?? 0 ),
			);
		}

		$after = function_exists( 'sheetsync_get_action_scheduler_health' )
			? sheetsync_get_action_scheduler_health()
			: array( 'past_due' => 0, 'ok' => true );

		$result = array(
			'ok'               => true,
			'started_at'       => gmdate( 'c', $started ),
			'elapsed_seconds'  => time() - $started,
			'scheduler_before' => array(
				'past_due' => (int) ( $before['past_due'] ?? 0 ),
				'ok'       => (bool) ( $before['ok'] ?? true ),
			),
			'scheduler_after'  => array(
				'past_due' => (int) ( $after['past_due'] ?? 0 ),
				'ok'       => (bool) ( $after['ok'] ?? true ),
			),
			'steps'            => $steps,
		);

		update_option( self::OPTION_LAST_RUN, $result, false );

		return $result;
	}

	/**
	 * Lightweight ping (no queue processing).
	 *
	 * @return array<string, mixed>
	 */
	public static function ping(): array {
		$health = function_exists( 'sheetsync_get_action_scheduler_health' )
			? sheetsync_get_action_scheduler_health()
			: array( 'past_due' => 0, 'ok' => true );

		return array(
			'ok'      => true,
			'ping'    => true,
			'enabled' => self::is_enabled(),
			'scheduler' => $health,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_last_run(): array {
		$last = get_option( self::OPTION_LAST_RUN, array() );
		return is_array( $last ) ? $last : array();
	}

	/**
	 * True when external cron is enabled and has run successfully within the last hour.
	 */
	public static function is_operational(): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}
		$last = self::get_last_run();
		if ( empty( $last['started_at'] ) || empty( $last['ok'] ) ) {
			return false;
		}
		$ts = strtotime( (string) $last['started_at'] );
		if ( ! $ts ) {
			return false;
		}
		return ( time() - $ts ) < HOUR_IN_SECONDS;
	}
}

endif;
