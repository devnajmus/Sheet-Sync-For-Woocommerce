<?php
/**
 * PRO: Email notifications for sync results.
 */

/**
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 * @copyright 2024 MD Najmus Shadat
 */

defined( 'ABSPATH' ) || exit;

class SheetSync_Email_Notifier {

    public function __construct() {
        // No hooks needed — called directly from cron manager
    }

    /**
     * Send a sync result email.
     */
    public static function send_sync_result( array $result, int $connection_id ): void {
        $settings = get_option( 'sheetsync_settings', array() );
        if ( empty( $settings['email_notifications'] ) ) {
            return;
        }

        $to = sanitize_email( $settings['notification_email'] ?? get_option( 'admin_email' ) );
        if ( empty( $to ) ) {
            return;
        }

        $conn    = SheetSync_Sync_Engine::get_connection( $connection_id );
        $name    = $conn ? $conn->name : "Connection #{$connection_id}";
        $status  = esc_html( $result['success'] ? __( 'Success', 'sheetsync-for-woocommerce' ) : __( 'Failed', 'sheetsync-for-woocommerce' ) );
        $subject = sprintf( __( '[SheetSync] Sync %1$s — %2$s', 'sheetsync-for-woocommerce' ), $status, $name );

        $body = self::build_email_body( $result, $name, $status );
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        wp_mail( $to, $subject, $body, $headers );
    }

    /**
     * Human label for triggered_by job meta.
     */
    public static function triggered_by_label( string $triggered_by ): string {
        $map = array(
            'manual'   => __( 'Manual (Sync now)', 'sheetsync-for-woocommerce' ),
            'cron'     => __( 'Scheduled sync', 'sheetsync-for-woocommerce' ),
            'off_peak' => __( 'Off-peak full publish', 'sheetsync-for-woocommerce' ),
            'webhook'  => __( 'Sheet webhook', 'sheetsync-for-woocommerce' ),
            'poll'     => __( 'Smart Poll', 'sheetsync-for-woocommerce' ),
            'deferred' => __( 'Product save', 'sheetsync-for-woocommerce' ),
        );
        return $map[ $triggered_by ] ?? $triggered_by;
    }

    /**
     * Send an error alert.
     */
    public static function send_error( string $message, int $connection_id ): void {
        $settings = get_option( 'sheetsync_settings', array() );
        if ( empty( $settings['email_notifications'] ) ) return;

        $to      = sanitize_email( $settings['notification_email'] ?? get_option( 'admin_email' ) );
        $subject = __( '[SheetSync] Sync Error', 'sheetsync-for-woocommerce' );

        wp_mail( $to, $subject, wpautop( esc_html( $message ) ) );
    }

    /**
     * Build the HTML email body.
     *
     * @param array  $result    Sync result data.
     * @param string $conn_name Connection display name.
     * @param string $status    Status label (already translated).
     * @return string Safe HTML email body.
     */
    private static function build_email_body( array $result, string $conn_name, string $status ): string {
        $processed = (int) ( $result['processed'] ?? 0 );
        $skipped   = (int) ( $result['skipped']   ?? 0 );
        $errors    = (int) ( $result['errors']     ?? 0 );

        $esc_conn   = esc_html( $conn_name );
        $esc_status = esc_html( $status );
        $esc_site   = esc_html( get_bloginfo( 'name' ) );
        $esc_time   = esc_html( current_time( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );

        $triggered_by = (string) ( $result['triggered_by'] ?? '' );
        $trigger_line = '';
        if ( $triggered_by !== '' ) {
            $trigger_line = '<br><strong>' . esc_html__( 'Triggered by', 'sheetsync-for-woocommerce' ) . ':</strong> '
                . esc_html( self::triggered_by_label( $triggered_by ) );
        }

        $summary = trim( (string) ( $result['message'] ?? '' ) );
        $summary_block = '';
        if ( $summary !== '' ) {
            $summary_block = '<p style="margin:12px 0;padding:12px;background:#f9fafb;border-radius:6px;border:1px solid #e5e7eb;">'
                . esc_html( $summary ) . '</p>';
        }

        $logs_url = esc_url( admin_url( 'admin.php?page=sheetsync-logs' ) );
        $reports_url = esc_url( admin_url( 'admin.php?page=sheetsync-reports' ) );

        $icon = $result['success'] ? '&#9989;' : '&#10060;';
        $errors_color = $errors > 0 ? '#dc2626' : '#065f46';

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- all vars escaped above
        return "
        <html><body style='font-family:sans-serif;color:#1f2937;'>
        <div style='max-width:520px;margin:0 auto;'>
            <div style='background:#1e6e42;padding:20px 24px;border-radius:8px 8px 0 0;'>
                <h1 style='color:#fff;margin:0;font-size:18px;'>SheetSync for WooCommerce</h1>
            </div>
            <div style='padding:24px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;'>
                <h2 style='margin-top:0;'>{$icon} Sync {$esc_status}</h2>
                <p><strong>Store:</strong> {$esc_site}<br>
                   <strong>Connection:</strong> {$esc_conn}<br>
                   <strong>Time:</strong> {$esc_time}{$trigger_line}</p>
                {$summary_block}
                <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
                    <tr style='background:#f0f7f3;'>
                        <td style='padding:10px;border:1px solid #e5e7eb;font-weight:600;'>" . esc_html__( 'Rows updated', 'sheetsync-for-woocommerce' ) . "</td>
                        <td style='padding:10px;border:1px solid #e5e7eb;color:#065f46;font-weight:700;'>{$processed}</td>
                    </tr>
                    <tr>
                        <td style='padding:10px;border:1px solid #e5e7eb;font-weight:600;'>" . esc_html__( 'Skipped', 'sheetsync-for-woocommerce' ) . "</td>
                        <td style='padding:10px;border:1px solid #e5e7eb;'>{$skipped}</td>
                    </tr>
                    <tr style='background:#f0f7f3;'>
                        <td style='padding:10px;border:1px solid #e5e7eb;font-weight:600;'>" . esc_html__( 'Errors', 'sheetsync-for-woocommerce' ) . "</td>
                        <td style='padding:10px;border:1px solid #e5e7eb;color:{$errors_color};font-weight:700;'>{$errors}</td>
                    </tr>
                </table>
                <p>
                    <a href='{$reports_url}' style='display:inline-block;background:#1e6e42;color:#fff;padding:10px 16px;text-decoration:none;border-radius:6px;margin-right:8px;'>" . esc_html__( 'Sync Reports', 'sheetsync-for-woocommerce' ) . "</a>
                    <a href='{$logs_url}' style='display:inline-block;background:#fff;color:#1e6e42;padding:10px 16px;text-decoration:none;border-radius:6px;border:1px solid #1e6e42;'>" . esc_html__( 'View logs', 'sheetsync-for-woocommerce' ) . "</a>
                </p>
            </div>
            <p style='font-size:11px;color:#9ca3af;text-align:center;margin-top:16px;'>" . esc_html__( 'Per-sync emails can be disabled in SheetSync → Settings. Schedule daily/weekly digests under Email reports.', 'sheetsync-for-woocommerce' ) . "</p>
        </div>
        </body></html>";
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
