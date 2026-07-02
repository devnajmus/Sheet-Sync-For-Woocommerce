<?php
/**
 * Onboarding email reminders (Phase 6).
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Onboarding', false ) ) :

class SheetSync_Onboarding {

    public const CRON_HOOK = 'sheetsync_onboarding_email';

    public static function init(): void {
        add_action( self::CRON_HOOK, array( __CLASS__, 'maybe_send_reminder' ) );
    }

    public static function schedule(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
    }

    public static function unschedule(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    public static function maybe_send_reminder(): void {
        if ( function_exists( 'sheetsync_wizard_is_complete' ) && sheetsync_wizard_is_complete() ) {
            return;
        }

        $progress = function_exists( 'sheetsync_get_setup_progress' ) ? sheetsync_get_setup_progress() : array();
        if ( ! empty( $progress['first_sync_done'] ) ) {
            return;
        }

        $activated = (int) get_option( 'sheetsync_activated_at', 0 );
        if ( $activated <= 0 ) {
            return;
        }

        $days    = (int) floor( ( time() - $activated ) / DAY_IN_SECONDS );
        $sent    = get_option( 'sheetsync_onboarding_emails_sent', array() );
        if ( ! is_array( $sent ) ) {
            $sent = array();
        }

        $milestones = array( 1, 3, 7 );
        if ( ! in_array( $days, $milestones, true ) || in_array( $days, $sent, true ) ) {
            return;
        }

        $email = get_option( 'admin_email' );
        if ( ! is_email( $email ) ) {
            return;
        }

        $wizard_url = admin_url( 'admin.php?page=sheetsync-setup' );
        $subject    = sprintf(
            /* translators: %d: day number */
            __( '[SheetSync] Finish setup (day %d reminder)', 'sheetsync-for-woocommerce' ),
            $days
        );
        $body       = sprintf(
            /* translators: %s: wizard URL */
            __( "Hi,\n\nYou started SheetSync but haven't finished setup yet.\n\nContinue here: %s\n\n— SheetSync for WooCommerce", 'sheetsync-for-woocommerce' ),
            $wizard_url
        );

        wp_mail( $email, $subject, $body );

        $sent[] = $days;
        update_option( 'sheetsync_onboarding_emails_sent', $sent, false );
    }
}

endif;
