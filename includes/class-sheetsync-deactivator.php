<?php
/**
 * Plugin deactivator — clears cron jobs and transients.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Deactivator' ) ) :

class SheetSync_Deactivator {

    public static function deactivate(): void {
        // Clear all scheduled cron events
        $timestamp = wp_next_scheduled( 'sheetsync_scheduled_sync' );
        if ( $timestamp ) {
            wp_clear_scheduled_hook( 'sheetsync_scheduled_sync' );
        }
        wp_clear_scheduled_hook( 'sheetsync_email_report' );

        // FIX M-7: Delete transients explicitly using the WP API instead of a
        // LIKE query. The LIKE pattern requires a full wp_options table scan which
        // is slow on large sites and violates PHPCS direct-query rules.
        $known_transients = array(
            'sheetsync_admin_notices',
            'sheetsync_all_connections',
            'sheetsync_active_product_connections',
            'sheetsync_google_token',
        );
        foreach ( $known_transients as $transient ) {
            delete_transient( $transient );
        }

        // Clear per-connection transients and rate-limit buckets stored in options.
        // These are keyed by connection ID (numeric) or IP hash (32-char hex).
        // We still need a targeted query here but use a tighter prefix to minimise scan.
        global $wpdb;
        $wpdb->query(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_sheetsync_conn_' ) . '%',
                $wpdb->esc_like( '_transient_sheetsync_rl_' ) . '%'
            )
        );

        flush_rewrite_rules();

        if ( class_exists( 'SheetSync_Onboarding', false ) ) {
            SheetSync_Onboarding::unschedule();
        } else {
            wp_clear_scheduled_hook( 'sheetsync_onboarding_email' );
        }

        if ( class_exists( 'SheetSync_Order_Sheet_Poller', false ) ) {
            SheetSync_Order_Sheet_Poller::clear_schedule();
        }
        if ( class_exists( 'SheetSync_Product_Sheet_Poller', false ) ) {
            SheetSync_Product_Sheet_Poller::clear_schedule();
        }
    }
}

endif; // class_exists SheetSync_Deactivator
