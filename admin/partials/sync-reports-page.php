<?php
/**
 * Sync Reports — merchant-facing activity summary (mirrors email digests).
 */
defined( 'ABSPATH' ) || exit;

$sheetsync_report_interval = class_exists( 'SheetSync_Email_Reports', false )
	? SheetSync_Email_Reports::interval()
	: '';
$sheetsync_report_view = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $sheetsync_report_view, array( 'daily', 'weekly' ), true ) ) {
	$sheetsync_report_view = in_array( $sheetsync_report_interval, array( 'daily', 'weekly' ), true )
		? $sheetsync_report_interval
		: 'daily';
}

$sheetsync_report = class_exists( 'SheetSync_Email_Reports', false )
	? SheetSync_Email_Reports::build_report( $sheetsync_report_view )
	: array();
$sheetsync_report_stats = is_array( $sheetsync_report['stats'] ?? null ) ? $sheetsync_report['stats'] : array();
$sheetsync_is_pro       = function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro();
?>
<div class="sheetsync-wrap sheetsync-reports-wrap">
    <h1>
        <span class="dashicons dashicons-email-alt" style="color:var(--ss-green);"></span>
        <?php esc_html_e( 'Sync Reports', 'sheetsync-for-woocommerce' ); ?>
    </h1>
    <p class="description">
        <?php esc_html_e( 'Activity summary for your sheet connections — same data sent in scheduled email reports.', 'sheetsync-for-woocommerce' ); ?>
    </p>

    <div class="sheetsync-card ss-reports-toolbar">
        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ss-reports-period-form">
            <input type="hidden" name="page" value="sheetsync-reports">
            <label for="ss-reports-period"><?php esc_html_e( 'Period', 'sheetsync-for-woocommerce' ); ?></label>
            <select name="period" id="ss-reports-period" onchange="this.form.submit()">
                <option value="daily" <?php selected( $sheetsync_report_view, 'daily' ); ?>><?php esc_html_e( 'Last 24 hours', 'sheetsync-for-woocommerce' ); ?></option>
                <option value="weekly" <?php selected( $sheetsync_report_view, 'weekly' ); ?>><?php esc_html_e( 'Last 7 days', 'sheetsync-for-woocommerce' ); ?></option>
            </select>
            <span class="description">
                <?php
                printf(
                    /* translators: %s: generated datetime */
                    esc_html__( 'Generated %s', 'sheetsync-for-woocommerce' ),
                    esc_html( (string) ( $sheetsync_report['generated_at'] ?? '' ) )
                );
                ?>
            </span>
        </form>

        <?php if ( $sheetsync_is_pro ) : ?>
            <button type="button" class="button ss-send-report-email-btn" data-period="<?php echo esc_attr( $sheetsync_report_view ); ?>">
                <?php esc_html_e( 'Email me this report', 'sheetsync-for-woocommerce' ); ?>
            </button>
            <span class="ss-send-report-result description" aria-live="polite"></span>
        <?php else : ?>
            <?php SheetSync_Admin::render_pro_gate( __( 'Email Reports', 'sheetsync-for-woocommerce' ) ); ?>
        <?php endif; ?>
    </div>

    <div class="ss-reports-grid">
        <div class="sheetsync-card ss-reports-stat-card">
            <span class="ss-reports-stat-value"><?php echo esc_html( (string) (int) ( $sheetsync_report_stats['runs'] ?? 0 ) ); ?></span>
            <span class="ss-reports-stat-label"><?php esc_html_e( 'Sync runs', 'sheetsync-for-woocommerce' ); ?></span>
        </div>
        <div class="sheetsync-card ss-reports-stat-card ss-reports-stat-card--ok">
            <span class="ss-reports-stat-value"><?php echo esc_html( (string) (int) ( $sheetsync_report_stats['success'] ?? 0 ) ); ?></span>
            <span class="ss-reports-stat-label"><?php esc_html_e( 'Successful', 'sheetsync-for-woocommerce' ); ?></span>
        </div>
        <div class="sheetsync-card ss-reports-stat-card ss-reports-stat-card--warn">
            <span class="ss-reports-stat-value"><?php echo esc_html( (string) (int) ( $sheetsync_report_stats['partial'] ?? 0 ) ); ?></span>
            <span class="ss-reports-stat-label"><?php esc_html_e( 'Partial', 'sheetsync-for-woocommerce' ); ?></span>
        </div>
        <div class="sheetsync-card ss-reports-stat-card ss-reports-stat-card--error">
            <span class="ss-reports-stat-value"><?php echo esc_html( (string) (int) ( $sheetsync_report_stats['error'] ?? 0 ) ); ?></span>
            <span class="ss-reports-stat-label"><?php esc_html_e( 'Failed', 'sheetsync-for-woocommerce' ); ?></span>
        </div>
        <div class="sheetsync-card ss-reports-stat-card">
            <span class="ss-reports-stat-value"><?php echo esc_html( number_format_i18n( (int) ( $sheetsync_report_stats['rows_processed'] ?? 0 ) ) ); ?></span>
            <span class="ss-reports-stat-label"><?php esc_html_e( 'Rows updated', 'sheetsync-for-woocommerce' ); ?></span>
        </div>
        <div class="sheetsync-card ss-reports-stat-card">
            <span class="ss-reports-stat-value"><?php echo esc_html( (string) (int) ( $sheetsync_report['open_conflicts'] ?? 0 ) ); ?></span>
            <span class="ss-reports-stat-label"><?php esc_html_e( 'Open conflicts', 'sheetsync-for-woocommerce' ); ?></span>
        </div>
    </div>

    <div class="sheetsync-card ss-reports-health-card">
        <h2><?php esc_html_e( 'System health', 'sheetsync-for-woocommerce' ); ?></h2>
        <ul class="ss-reports-health-list">
            <li>
                <?php if ( ! empty( $sheetsync_report['scheduler_ok'] ) ) : ?>
                    <span class="ss-health-dot ss-health-dot--ok"></span>
                    <?php esc_html_e( 'Background tasks healthy', 'sheetsync-for-woocommerce' ); ?>
                <?php else : ?>
                    <span class="ss-health-dot ss-health-dot--warn"></span>
                    <?php echo esc_html( (string) ( $sheetsync_report['scheduler_message'] ?? __( 'Background tasks overdue', 'sheetsync-for-woocommerce' ) ) ); ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=action-scheduler' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Fix Scheduled Actions', 'sheetsync-for-woocommerce' ); ?></a>
                <?php endif; ?>
            </li>
            <li>
                <span class="ss-health-dot ss-health-dot--ok"></span>
                <?php
                printf(
                    /* translators: %d: connection count */
                    esc_html__( '%d active connections', 'sheetsync-for-woocommerce' ),
                    (int) ( $sheetsync_report['active_connections'] ?? 0 )
                );
                ?>
            </li>
            <?php if ( (int) ( $sheetsync_report['open_conflicts'] ?? 0 ) > 0 ) : ?>
            <li>
                <span class="ss-health-dot ss-health-dot--warn"></span>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync-conflicts' ) ); ?>">
                    <?php
                    printf(
                        /* translators: %d: conflict count */
                        esc_html__( '%d conflicts need review', 'sheetsync-for-woocommerce' ),
                        (int) $sheetsync_report['open_conflicts']
                    );
                    ?>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </div>

    <div class="sheetsync-card">
        <h2><?php esc_html_e( 'Activity by connection', 'sheetsync-for-woocommerce' ); ?></h2>
        <?php if ( empty( $sheetsync_report['connections'] ) ) : ?>
            <p class="description"><?php esc_html_e( 'No sync activity in this period.', 'sheetsync-for-woocommerce' ); ?></p>
        <?php else : ?>
        <table class="widefat striped ss-reports-connections-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Connection', 'sheetsync-for-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Runs', 'sheetsync-for-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Rows updated', 'sheetsync-for-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Errors', 'sheetsync-for-woocommerce' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( (array) $sheetsync_report['connections'] as $sheetsync_conn_row ) : ?>
                <tr>
                    <td>
                        <?php if ( ! empty( $sheetsync_conn_row['connection_id'] ) ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync&sheetsync_action=edit&connection_id=' . (int) $sheetsync_conn_row['connection_id'] ) ); ?>">
                                <?php echo esc_html( (string) ( $sheetsync_conn_row['connection_name'] ?? '' ) ); ?>
                            </a>
                        <?php else : ?>
                            <?php echo esc_html( (string) ( $sheetsync_conn_row['connection_name'] ?? '' ) ); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( (string) (int) ( $sheetsync_conn_row['runs'] ?? 0 ) ); ?></td>
                    <td><?php echo esc_html( number_format_i18n( (int) ( $sheetsync_conn_row['rows_processed'] ?? 0 ) ) ); ?></td>
                    <td><?php echo esc_html( (string) (int) ( $sheetsync_conn_row['errors'] ?? 0 ) ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php if ( ! empty( $sheetsync_report['issues'] ) ) : ?>
    <div class="sheetsync-card ss-reports-issues-card">
        <h2><?php esc_html_e( 'Needs attention', 'sheetsync-for-woocommerce' ); ?></h2>
        <ul class="ss-reports-issues-list">
            <?php foreach ( (array) $sheetsync_report['issues'] as $sheetsync_issue ) : ?>
            <li>
                <strong><?php echo esc_html( (string) ( $sheetsync_issue['connection_name'] ?? '' ) ); ?></strong>
                — <?php echo esc_html( class_exists( 'SheetSync_Logger', false ) ? SheetSync_Logger::display_message( (string) ( $sheetsync_issue['message'] ?? '' ) ) : (string) ( $sheetsync_issue['message'] ?? '' ) ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync-logs&connection_id=' . (int) ( $sheetsync_issue['connection_id'] ?? 0 ) ) ); ?>"><?php esc_html_e( 'View log', 'sheetsync-for-woocommerce' ); ?></a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <p class="description">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync-settings' ) ); ?>"><?php esc_html_e( 'Configure daily or weekly email reports in Settings', 'sheetsync-for-woocommerce' ); ?></a>
        ·
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync-logs' ) ); ?>"><?php esc_html_e( 'Full sync logs', 'sheetsync-for-woocommerce' ); ?></a>
    </p>
</div>
