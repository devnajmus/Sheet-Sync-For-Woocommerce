<?php
defined( 'ABSPATH' ) || exit;

$sheetsync_health   = function_exists( 'sheetsync_get_setup_health_summary' ) ? sheetsync_get_setup_health_summary() : array();
$sheetsync_progress = function_exists( 'sheetsync_get_setup_progress' ) ? sheetsync_get_setup_progress() : array();
$sheetsync_ab       = function_exists( 'sheetsync_get_wizard_ab_stats' ) ? sheetsync_get_wizard_ab_stats() : array( 'a' => 0, 'b' => 0 );

global $wpdb;
$sheetsync_connections = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    "SELECT id, name, status, sync_direction, last_sync_at FROM {$wpdb->prefix}sheetsync_connections ORDER BY name ASC"
);
?>
<div class="sheetsync-wrap">
    <?php require __DIR__ . '/header.php'; ?>

    <div class="sheetsync-card">
        <h2 style="margin-top:0;"><?php esc_html_e( 'Setup health dashboard', 'sheetsync-for-woocommerce' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Overview for agencies managing multiple sheet connections on this site.', 'sheetsync-for-woocommerce' ); ?></p>

        <div class="ss-agency-health-card">
            <div class="ss-agency-health-stat">
                <strong><?php echo esc_html( (string) (int) ( $sheetsync_health['connections'] ?? 0 ) ); ?></strong>
                <?php esc_html_e( 'Connections', 'sheetsync-for-woocommerce' ); ?>
            </div>
            <div class="ss-agency-health-stat">
                <strong><?php echo esc_html( (string) (int) ( $sheetsync_health['setup_percent'] ?? 0 ) ); ?>%</strong>
                <?php esc_html_e( 'Setup complete', 'sheetsync-for-woocommerce' ); ?>
            </div>
        </div>
        <?php
        $sheetsync_lights_title   = '';
        $sheetsync_lights_compact = true;
        require __DIR__ . '/fragments/setup-health-lights.php';
        ?>
    </div>

    <div class="sheetsync-card">
        <h2><?php esc_html_e( 'Setup checklist', 'sheetsync-for-woocommerce' ); ?></h2>
        <ul class="ss-setup-steps-list">
            <?php
            $sheetsync_steps = function_exists( 'sheetsync_get_setup_steps_config' ) ? sheetsync_get_setup_steps_config() : array();
            foreach ( $sheetsync_steps as $sheetsync_step ) :
                $done = ! empty( $sheetsync_progress[ $sheetsync_step['key'] ] );
                ?>
                <li class="ss-setup-step <?php echo $done ? 'is-done' : 'is-pending'; ?>">
                    <span class="ss-setup-step-icon" aria-hidden="true"><?php echo $done ? '✓' : '○'; ?></span>
                    <?php echo esc_html( $sheetsync_step['label'] ); ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <?php if ( class_exists( 'SheetSync_External_Cron', false ) ) : ?>
    <?php
    $sheetsync_as_health_page = function_exists( 'sheetsync_get_action_scheduler_health' )
        ? sheetsync_get_action_scheduler_health()
        : array();
    ?>
    <div class="sheetsync-card">
        <h2><?php esc_html_e( 'Background cron', 'sheetsync-for-woocommerce' ); ?></h2>
        <p class="description"><?php esc_html_e( 'If Scheduled Actions shows overdue tasks, set up the cron URL on Settings → Background cron URL.', 'sheetsync-for-woocommerce' ); ?></p>
        <p>
            <strong><?php esc_html_e( 'Past-due tasks:', 'sheetsync-for-woocommerce' ); ?></strong>
            <?php echo esc_html( (string) (int) ( $sheetsync_as_health_page['past_due'] ?? 0 ) ); ?>
            <?php if ( ! empty( $sheetsync_as_health_page['ok'] ) ) : ?>
                <span style="color:#15803d;"> — <?php esc_html_e( 'OK', 'sheetsync-for-woocommerce' ); ?></span>
            <?php else : ?>
                <span style="color:#b45309;"> — <?php esc_html_e( 'Needs attention', 'sheetsync-for-woocommerce' ); ?></span>
            <?php endif; ?>
        </p>
        <p>
            <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync-settings#ss-external-cron-card' ) ); ?>">
                <?php esc_html_e( 'Open cron URL settings', 'sheetsync-for-woocommerce' ); ?>
            </a>
            <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=action-scheduler' ) ); ?>" target="_blank" rel="noopener">
                <?php esc_html_e( 'Scheduled Actions', 'sheetsync-for-woocommerce' ); ?>
            </a>
        </p>
    </div>
    <?php endif; ?>

    <?php if ( ! empty( $sheetsync_connections ) ) : ?>
    <div class="sheetsync-card">
        <h2><?php esc_html_e( 'Connections', 'sheetsync-for-woocommerce' ); ?></h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'sheetsync-for-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'sheetsync-for-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Direction', 'sheetsync-for-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Last sync', 'sheetsync-for-woocommerce' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $sheetsync_connections as $sheetsync_conn ) : ?>
                <tr>
                    <td>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync&sheetsync_action=edit&connection_id=' . (int) $sheetsync_conn->id ) ); ?>">
                            <?php echo esc_html( $sheetsync_conn->name ); ?>
                        </a>
                    </td>
                    <td><?php echo esc_html( $sheetsync_conn->status ); ?></td>
                    <td><?php echo esc_html( $sheetsync_conn->sync_direction ); ?></td>
                    <td><?php echo $sheetsync_conn->last_sync_at ? esc_html( $sheetsync_conn->last_sync_at ) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    <div class="sheetsync-card">
        <h2><?php esc_html_e( 'Wizard A/B test (internal)', 'sheetsync-for-woocommerce' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Tracks how many users were assigned variant A vs B on first wizard visit.', 'sheetsync-for-woocommerce' ); ?></p>
        <p>
            <strong>A:</strong> <?php echo esc_html( (string) (int) $sheetsync_ab['a'] ); ?>
            &nbsp;|&nbsp;
            <strong>B:</strong> <?php echo esc_html( (string) (int) $sheetsync_ab['b'] ); ?>
        </p>
    </div>
    <?php endif; ?>
</div>
