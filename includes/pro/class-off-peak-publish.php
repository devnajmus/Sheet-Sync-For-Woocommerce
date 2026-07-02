<?php
/**
 * PRO: Schedule full catalog publish during off-peak hours (background / Action Scheduler).
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class SheetSync_Off_Peak_Publish {

	const HOOK = 'sheetsync_off_peak_full_publish';

	public function __construct() {
		add_action( self::HOOK, array( $this, 'run' ), 10, 1 );
	}

	public static function option_key( int $connection_id ): string {
		return 'sheetsync_off_peak_publish_' . max( 0, $connection_id );
	}

	/**
	 * @return array{enabled:bool,hour:int,minute:int,recurring:bool}
	 */
	public static function get_settings( int $connection_id ): array {
		$raw = get_option( self::option_key( $connection_id ), array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return array(
			'enabled'   => ! empty( $raw['enabled'] ),
			'hour'      => max( 0, min( 23, (int) ( $raw['hour'] ?? 2 ) ) ),
			'minute'    => max( 0, min( 59, (int) ( $raw['minute'] ?? 0 ) ) ),
			'recurring' => array_key_exists( 'recurring', $raw ) ? ! empty( $raw['recurring'] ) : true,
		);
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	public static function save_settings( int $connection_id, array $settings ): void {
		$current = self::get_settings( $connection_id );
		$merged  = array(
			'enabled'   => ! empty( $settings['enabled'] ),
			'hour'      => isset( $settings['hour'] ) ? max( 0, min( 23, (int) $settings['hour'] ) ) : $current['hour'],
			'minute'    => isset( $settings['minute'] ) ? max( 0, min( 59, (int) $settings['minute'] ) ) : $current['minute'],
			'recurring' => array_key_exists( 'recurring', $settings ) ? ! empty( $settings['recurring'] ) : $current['recurring'],
		);
		update_option( self::option_key( $connection_id ), $merged, false );
		self::sync_schedule( $connection_id );
	}

	public static function connection_supports_off_peak( $connection ): bool {
		if ( ! is_object( $connection ) ) {
			return false;
		}
		if ( class_exists( 'SheetSync_Sync_Engine', false )
			&& SheetSync_Sync_Engine::is_orders_type( (string) ( $connection->connection_type ?? 'products' ) ) ) {
			return false;
		}
		$direction = (string) ( $connection->sync_direction ?? 'sheets_to_wc' );
		return in_array( $direction, array( 'wc_to_sheets', 'two_way' ), true );
	}

	public static function unschedule( int $connection_id ): void {
		$timestamp = wp_next_scheduled( self::HOOK, array( $connection_id ) );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK, array( $connection_id ) );
			$timestamp = wp_next_scheduled( self::HOOK, array( $connection_id ) );
		}
	}

	public static function compute_next_timestamp( int $hour, int $minute ): int {
		$tz  = wp_timezone();
		$now = new DateTimeImmutable( 'now', $tz );
		$next = $now->setTime( $hour, $minute, 0 );
		if ( $next->getTimestamp() <= time() ) {
			$next = $next->modify( '+1 day' );
		}
		return $next->getTimestamp();
	}

	public static function sync_schedule( int $connection_id ): bool {
		self::unschedule( $connection_id );
		$settings = self::get_settings( $connection_id );
		if ( empty( $settings['enabled'] ) ) {
			return true;
		}
		$ts = self::compute_next_timestamp( (int) $settings['hour'], (int) $settings['minute'] );
		return wp_schedule_single_event( $ts, self::HOOK, array( $connection_id ) ) !== false;
	}

	public static function get_next_run_label( int $connection_id ): ?string {
		$ts = wp_next_scheduled( self::HOOK, array( $connection_id ) );
		if ( ! $ts ) {
			return null;
		}
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	public static function format_time_label( int $hour, int $minute ): string {
		$tz  = wp_timezone();
		$dt  = ( new DateTimeImmutable( 'today', $tz ) )->setTime( $hour, $minute );
		return date_i18n( get_option( 'time_format' ), $dt->getTimestamp() );
	}

	/**
	 * Queue one off-peak full publish (one-shot by default from large-catalog gate).
	 *
	 * @param array<string, mixed> $overrides hour, minute, recurring
	 */
	public static function schedule_publish( int $connection_id, array $overrides = array() ): array {
		if ( ! sheetsync_is_pro() ) {
			return array(
				'success' => false,
				'message' => __( 'Pro required for off-peak full publish.', 'sheetsync-for-woocommerce' ),
			);
		}

		$conn = class_exists( 'SheetSync_Sync_Engine', false )
			? SheetSync_Sync_Engine::get_connection( $connection_id )
			: null;
		if ( ! $conn || ! self::connection_supports_off_peak( $conn ) ) {
			return array(
				'success' => false,
				'message' => __( 'Off-peak full publish applies to WooCommerce → Sheet and two-way product connections.', 'sheetsync-for-woocommerce' ),
			);
		}

		$settings = self::get_settings( $connection_id );
		if ( isset( $overrides['hour'] ) ) {
			$settings['hour'] = (int) $overrides['hour'];
		}
		if ( isset( $overrides['minute'] ) ) {
			$settings['minute'] = (int) $overrides['minute'];
		}
		if ( array_key_exists( 'recurring', $overrides ) ) {
			$settings['recurring'] = ! empty( $overrides['recurring'] );
		}
		$settings['enabled'] = true;
		self::save_settings( $connection_id, $settings );

		$next = self::get_next_run_label( $connection_id );
		$time = self::format_time_label( (int) $settings['hour'], (int) $settings['minute'] );

		return array(
			'success'  => true,
			'message'  => $next
				? sprintf(
					/* translators: %s: localized datetime */
					__( 'Full publish scheduled for %s. Runs in the background — you can close this tab.', 'sheetsync-for-woocommerce' ),
					$next
				)
				: sprintf(
					/* translators: %s: time of day */
					__( 'Full publish scheduled around %s.', 'sheetsync-for-woocommerce' ),
					$time
				),
			'next_run' => $next,
			'settings' => self::get_settings( $connection_id ),
		);
	}

	public function run( int $connection_id ): void {
		$connection_id = absint( $connection_id );
		if ( $connection_id < 1 || ! sheetsync_is_pro() ) {
			return;
		}

		$settings  = self::get_settings( $connection_id );
		$recurring = ! empty( $settings['recurring'] );

		if ( $recurring ) {
			self::sync_schedule( $connection_id );
		} else {
			self::unschedule( $connection_id );
			$settings['enabled'] = false;
			update_option( self::option_key( $connection_id ), $settings, false );
		}

		$conn = SheetSync_Sync_Engine::get_connection( $connection_id );
		if ( ! $conn || ! self::connection_supports_off_peak( $conn ) ) {
			return;
		}

		if ( ! sheetsync_use_job_engine() || ! class_exists( 'SheetSync_Sync_Coordinator', false ) ) {
			SheetSync_Logger::log(
				$connection_id,
				'cron',
				'error',
				0,
				0,
				__( 'Off-peak full publish requires the background job engine.', 'sheetsync-for-woocommerce' )
			);
			return;
		}

		$result = ( new SheetSync_Sync_Coordinator() )->start(
			$connection_id,
			array(
				'triggered_by'        => 'off_peak',
				'mode'                => 'full',
				'sync_strategy'       => 'full',
				'confirm_full_export' => true,
			)
		);

		if ( empty( $result['success'] ) && empty( $result['async'] ) && empty( $result['job_id'] ) ) {
			$message = (string) ( $result['message'] ?? __( 'Off-peak full publish could not start.', 'sheetsync-for-woocommerce' ) );
			SheetSync_Logger::log( $connection_id, 'cron', 'error', 0, 0, $message );
			$email_settings = get_option( 'sheetsync_settings', array() );
			if ( ! empty( $email_settings['email_notifications'] ) && class_exists( 'SheetSync_Email_Notifier', false ) ) {
				SheetSync_Email_Notifier::send_error( $message, $connection_id );
			}
		}
	}
}
