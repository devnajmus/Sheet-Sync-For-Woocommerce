<?php
/**
 * PRO: Poll Google Sheets for order status edits (no inbound webhook required).
 *
 * Works on any host — WordPress calls Google (outbound only). Ideal for commercial
 * deployments where firewalls block Google Apps Script → wp-json webhooks.
 *
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class SheetSync_Order_Sheet_Poller {

	public const HOOK          = 'sheetsync_order_sheet_poll';
	public const GROUP         = 'sheetsync';
	public const DEFAULT_INTERVAL = 60; // 1 minute.
	public const MIN_INTERVAL  = 30;
	public const MAX_INTERVAL  = 300;

	public static function get_last_run_label(): string {
		$ts = (int) get_option( 'sheetsync_order_poll_last_run', 0 );
		if ( $ts < 1 ) {
			return '';
		}
		return sprintf(
			/* translators: %s: human-readable time ago e.g. 2 minutes ago */
			__( 'Last sheet check: %s', 'sheetsync-for-woocommerce' ),
			human_time_diff( $ts, (int) current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'sheetsync-for-woocommerce' )
		);
	}

	/**
	 * Backup poll when a merchant opens an order connection (Action Scheduler may be delayed).
	 */
	public static function maybe_poll_on_admin_visit(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$page = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'sheetsync' !== $page ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_GET['sheetsync_action'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'edit' !== $action ) {
			return;
		}

		$conn_id = absint( wp_unslash( $_GET['connection_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $conn_id < 1 || ! class_exists( 'SheetSync_Sync_Engine', false ) ) {
			return;
		}

		$conn = SheetSync_Sync_Engine::get_connection( $conn_id );
		if ( ! $conn || ! SheetSync_Sync_Engine::is_orders_type( (string) ( $conn->connection_type ?? '' ) ) ) {
			return;
		}

		if ( ! function_exists( 'sheetsync_is_automatic_sync_enabled' ) || ! sheetsync_is_automatic_sync_enabled( $conn_id ) ) {
			return;
		}

		if ( ! in_array( (string) ( $conn->sync_direction ?? '' ), array( 'sheets_to_wc', 'two_way' ), true ) ) {
			return;
		}

		$throttle_key = 'sheetsync_order_admin_poll_' . $conn_id;
		if ( get_transient( $throttle_key ) ) {
			return;
		}
		set_transient( $throttle_key, 1, 45 );

		if ( ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) {
			return;
		}

		( new self() )->run();
	}

	public static function init(): void {
		new self();
		add_action( 'init', array( __CLASS__, 'maybe_sync_schedule' ), 25 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_poll_on_admin_visit' ), 50 );
	}

	public function __construct() {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );
	}

	/**
	 * Poll interval in seconds (filterable for power users / agencies).
	 */
	public static function get_interval_seconds(): int {
		$interval = (int) apply_filters( 'sheetsync_order_poll_interval_seconds', self::DEFAULT_INTERVAL );
		return max( self::MIN_INTERVAL, min( self::MAX_INTERVAL, $interval ) );
	}

	/**
	 * Human-readable interval for admin UI.
	 */
	public static function get_interval_label(): string {
		$seconds = self::get_interval_seconds();
		if ( $seconds < 60 ) {
			return sprintf(
				/* translators: %d: number of seconds */
				_n( 'every %d second', 'every %d seconds', $seconds, 'sheetsync-for-woocommerce' ),
				$seconds
			);
		}
		if ( 0 === $seconds % 60 ) {
			$minutes = (int) ( $seconds / 60 );
			return sprintf(
				/* translators: %d: number of minutes */
				_n( 'every %d minute', 'every %d minutes', $minutes, 'sheetsync-for-woocommerce' ),
				$minutes
			);
		}
		return sprintf(
			/* translators: %d: number of seconds */
			__( 'every %d seconds', 'sheetsync-for-woocommerce' ),
			$seconds
		);
	}

	/**
	 * @param array<string, array{interval:int,display:string}> $schedules
	 * @return array<string, array{interval:int,display:string}>
	 */
	public function add_cron_intervals( array $schedules ): array {
		$interval = self::get_interval_seconds();
		$schedules['sheetsync_order_poll'] = array(
			'interval' => $interval,
			'display'  => sprintf(
				/* translators: %s: human interval e.g. every 1 minute */
				__( 'SheetSync order poll (%s)', 'sheetsync-for-woocommerce' ),
				self::get_interval_label()
			),
		);
		return $schedules;
	}

	/**
	 * Order connections with real-time (auto-sync) enabled and sheet → WC direction.
	 *
	 * @return array<int, object>
	 */
	public static function order_realtime_connections(): array {
		$connections = array();
		foreach ( SheetSync_Sync_Engine::get_active_connections( 'orders' ) as $conn ) {
			$conn_id = (int) $conn->id;
			if ( $conn_id < 1 ) {
				continue;
			}
			if ( function_exists( 'sheetsync_is_automatic_sync_enabled' ) ) {
				if ( ! sheetsync_is_automatic_sync_enabled( $conn_id ) ) {
					continue;
				}
			} else {
				$auto_sync = get_option( 'sheetsync_auto_sync_settings', array() );
				if ( ! is_array( $auto_sync ) || empty( $auto_sync[ $conn_id ] ) ) {
					continue;
				}
			}
			if ( ! in_array( $conn->sync_direction, array( 'sheets_to_wc', 'two_way' ), true ) ) {
				continue;
			}
			$connections[] = $conn;
		}

		return $connections;
	}

	public static function is_needed(): bool {
		return ! empty( self::order_realtime_connections() );
	}

	/**
	 * Start or stop the poll schedule based on current real-time settings.
	 */
	public static function sync_schedule(): void {
		if ( ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) {
			self::clear_schedule();
			return;
		}

		if ( self::is_needed() ) {
			self::clear_schedule();
			update_option( 'sheetsync_order_poll_interval', self::get_interval_seconds(), false );
			self::ensure_scheduled();
		} else {
			self::clear_schedule();
		}
	}

	public static function maybe_sync_schedule(): void {
		$wanted = self::get_interval_seconds();
		$stored = (int) get_option( 'sheetsync_order_poll_interval', 0 );

		if ( self::is_needed() ) {
			if ( ! self::is_scheduled() || $stored !== $wanted ) {
				self::clear_schedule();
				update_option( 'sheetsync_order_poll_interval', $wanted, false );
				self::ensure_scheduled();
			}
		} elseif ( self::is_scheduled() ) {
			self::clear_schedule();
		}
	}

	public static function is_scheduled(): bool {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			if ( as_next_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
				return true;
			}
		}
		return (bool) wp_next_scheduled( self::HOOK );
	}

	public static function get_next_run_timestamp(): ?int {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( self::HOOK, array(), self::GROUP );
			if ( $next ) {
				return (int) $next;
			}
		}
		$wp = wp_next_scheduled( self::HOOK );
		return $wp ? (int) $wp : null;
	}

	public static function get_next_run_label(): string {
		$ts = self::get_next_run_timestamp();
		if ( ! $ts ) {
			return '';
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	private static function ensure_scheduled(): void {
		$interval = self::get_interval_seconds();

		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			as_schedule_recurring_action(
				time() + $interval,
				$interval,
				self::HOOK,
				array(),
				self::GROUP
			);
			return;
		}

		wp_schedule_event( time() + $interval, 'sheetsync_order_poll', self::HOOK );
	}

	public static function clear_schedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
		delete_option( 'sheetsync_order_poll_interval' );
	}

	/**
	 * Pull status edits from all real-time order sheets.
	 */
	public function run(): void {
		if ( ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) {
			self::clear_schedule();
			return;
		}

		if ( ! self::is_needed() ) {
			self::clear_schedule();
			return;
		}

		if ( ! class_exists( 'SheetSync_Order_Sync', false ) ) {
			return;
		}

		$order_sync = new SheetSync_Order_Sync();
		$result     = $order_sync->pull_realtime_order_statuses_from_sheets();

		if ( (int) ( $result['processed'] ?? 0 ) > 0 ) {
			foreach ( self::order_realtime_connections() as $conn ) {
				SheetSync_Sync_Engine::touch_connection_sync_time( (int) $conn->id );
			}
		}

		update_option( 'sheetsync_order_poll_last_run', time(), false );
		update_option( 'sheetsync_order_poll_last_result', $result, false );

		if ( (int) ( $result['errors'] ?? 0 ) > 0 ) {
			SheetSync_Logger::error(
				sprintf(
					'Order sheet poll: processed %d, errors %d',
					(int) ( $result['processed'] ?? 0 ),
					(int) ( $result['errors'] ?? 0 )
				)
			);
		}
	}
}
