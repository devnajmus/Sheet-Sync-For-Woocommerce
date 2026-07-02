<?php
/**
 * PRO: WP-Cron based scheduled sync manager.
 */

/**
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 * @copyright 2024 MD Najmus Shadat
 */

defined( 'ABSPATH' ) || exit;

class SheetSync_Cron_Manager {

    const HOOK = 'sheetsync_scheduled_sync';

    public function __construct() {
        add_action( self::HOOK, array( $this, 'run_scheduled_sync' ) );
    }

    /**
     * Execute a scheduled sync.
     */
    public function run_scheduled_sync( int $connection_id ): void {
        if ( ! sheetsync_is_pro() ) {
            return;
        }

        $conn = SheetSync_Sync_Engine::get_connection( $connection_id );
        if ( $conn && SheetSync_Sync_Engine::is_orders_type( (string) $conn->connection_type ) ) {
            if ( sheetsync_order_job_engine() && class_exists( 'SheetSync_Sync_Coordinator', false ) ) {
                ( new SheetSync_Sync_Coordinator() )->start(
                    $connection_id,
                    array(
                        'triggered_by' => 'cron',
                    )
                );
                return;
            }

            $engine = new SheetSync_Sync_Engine();
            $result = $engine->run( $connection_id );

            $settings = get_option( 'sheetsync_settings', array() );
            if ( ! empty( $settings['email_notifications'] ) && empty( $result['async'] ) ) {
                SheetSync_Email_Notifier::send_sync_result( $result, $connection_id );
            }
            return;
        }

        if ( sheetsync_use_job_engine() && class_exists( 'SheetSync_Sync_Coordinator', false ) ) {
            ( new SheetSync_Sync_Coordinator() )->start(
                $connection_id,
                array(
                    'triggered_by' => 'cron',
                )
            );
            return;
        }

        $engine = new SheetSync_Sync_Engine();
        $result = $engine->run( $connection_id );

        $settings = get_option( 'sheetsync_settings', array() );
        if ( ! empty( $settings['email_notifications'] ) && empty( $result['async'] ) ) {
            SheetSync_Email_Notifier::send_sync_result( $result, $connection_id );
        }
    }

    /**
     * Schedule a connection's auto-sync.
     */
    public static function schedule( int $connection_id, string $interval ): bool {
        if ( empty( $interval ) ) {
            self::unschedule( $connection_id );
            return true;
        }

        // Remove existing schedule first
        self::unschedule( $connection_id );

        $scheduled = wp_schedule_event( time(), $interval, self::HOOK, array( $connection_id ) );
        return $scheduled !== false;
    }

    /**
     * Remove the schedule for a connection.
     */
    public static function unschedule( int $connection_id ): void {
        $timestamp = wp_next_scheduled( self::HOOK, array( $connection_id ) );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK, array( $connection_id ) );
        }
    }

    /**
     * Get next scheduled run for a connection.
     */
    public static function get_next_run( int $connection_id ): ?string {
        $ts = wp_next_scheduled( self::HOOK, array( $connection_id ) );
        if ( ! $ts ) return null;
        return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
    }

}
