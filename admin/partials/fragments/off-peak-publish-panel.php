<?php
/**
 * Off-peak full publish — schedule large catalog exports for low-traffic hours.
 */
defined( 'ABSPATH' ) || exit;

$sheetsync_off_peak_supports = class_exists( 'SheetSync_Off_Peak_Publish', false )
    && SheetSync_Off_Peak_Publish::connection_supports_off_peak( $connection ?? null );
if ( ! $sheetsync_off_peak_supports ) {
    return;
}

$sheetsync_off_peak_settings = class_exists( 'SheetSync_Off_Peak_Publish', false )
    ? SheetSync_Off_Peak_Publish::get_settings( $sheetsync_conn_id )
    : array( 'enabled' => false, 'hour' => 2, 'minute' => 0, 'recurring' => true );
$sheetsync_off_peak_next_run = class_exists( 'SheetSync_Off_Peak_Publish', false )
    ? SheetSync_Off_Peak_Publish::get_next_run_label( $sheetsync_conn_id )
    : null;
$sheetsync_off_peak_time_label = class_exists( 'SheetSync_Off_Peak_Publish', false )
    ? SheetSync_Off_Peak_Publish::format_time_label( (int) $sheetsync_off_peak_settings['hour'], (int) $sheetsync_off_peak_settings['minute'] )
    : '';
$sheetsync_email_notifications = ! empty( get_option( 'sheetsync_settings', array() )['email_notifications'] ?? false );
?>
<div class="sheetsync-card ss-off-peak-publish-card" id="ss-off-peak-publish-panel">
    <div class="ss-off-peak-publish-header">
        <h3 class="ss-off-peak-publish-title">
            <span class="dashicons dashicons-moon" aria-hidden="true"></span>
            <?php esc_html_e( 'Off-peak full publish', 'sheetsync-for-woocommerce' ); ?>
            <?php if ( $sheetsync_is_pro ) : ?>
                <span class="sheetsync-pro-badge">PRO</span>
            <?php endif; ?>
        </h3>
        <p class="description">
            <?php esc_html_e( 'Publish your full catalog in the background during quiet hours — no browser tab required. Ideal for large stores when Action Scheduler is healthy.', 'sheetsync-for-woocommerce' ); ?>
        </p>
    </div>

    <?php if ( $sheetsync_is_pro ) : ?>
        <?php if ( ! empty( $sheetsync_needs_scheduler_gate ) ) : ?>
        <div class="ss-off-peak-publish-cta">
            <button type="button"
                    class="button button-primary ss-schedule-off-peak-btn"
                    data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>"
                    data-one-shot="1"
                    data-hour="2"
                    data-minute="0">
                <span class="dashicons dashicons-clock" aria-hidden="true"></span>
                <?php esc_html_e( 'Schedule full publish for off-peak (2:00 AM)', 'sheetsync-for-woocommerce' ); ?>
            </button>
            <p class="description ss-off-peak-publish-cta-hint">
                <?php esc_html_e( 'Recommended instead of slow browser sync. Runs via Action Scheduler — close this tab after scheduling.', 'sheetsync-for-woocommerce' ); ?>
            </p>
        </div>
        <?php endif; ?>

        <div class="ss-off-peak-publish-status" id="ss-off-peak-publish-status"<?php echo ( $sheetsync_off_peak_settings['enabled'] && $sheetsync_off_peak_next_run ) ? '' : ' hidden'; ?>>
            <?php if ( $sheetsync_off_peak_settings['enabled'] && $sheetsync_off_peak_next_run ) : ?>
                <p class="ss-off-peak-publish-next-run">
                    <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
                    <?php
                    printf(
                        /* translators: %s: localized datetime */
                        esc_html__( 'Next full publish: %s', 'sheetsync-for-woocommerce' ),
                        esc_html( $sheetsync_off_peak_next_run )
                    );
                    ?>
                    <?php if ( ! $sheetsync_off_peak_settings['recurring'] ) : ?>
                        <em><?php esc_html_e( '(one time)', 'sheetsync-for-woocommerce' ); ?></em>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        <p class="ss-off-peak-publish-feedback description" id="ss-off-peak-publish-feedback" aria-live="polite"></p>

        <?php if ( $sheetsync_email_notifications ) : ?>
            <p class="description ss-off-peak-email-hint">
                <?php esc_html_e( 'You will receive an email when the publish completes (enabled in SheetSync → Settings).', 'sheetsync-for-woocommerce' ); ?>
            </p>
        <?php else : ?>
            <p class="description ss-off-peak-email-hint">
                <?php
                printf(
                    /* translators: %s: settings URL */
                    esc_html__( 'Enable email notifications in %s to get a completion summary.', 'sheetsync-for-woocommerce' ),
                    '<a href="' . esc_url( admin_url( 'admin.php?page=sheetsync-settings' ) ) . '">' . esc_html__( 'SheetSync Settings', 'sheetsync-for-woocommerce' ) . '</a>'
                );
                ?>
            </p>
        <?php endif; ?>

        <details class="ss-off-peak-publish-options" <?php echo $sheetsync_off_peak_settings['enabled'] ? 'open' : ''; ?>>
            <summary><?php esc_html_e( 'Off-peak schedule options', 'sheetsync-for-woocommerce' ); ?></summary>
            <div class="ss-off-peak-publish-options-body">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'sheetsync_save_off_peak_publish' ); ?>
                    <input type="hidden" name="action" value="sheetsync_save_off_peak_publish">
                    <input type="hidden" name="connection_id" value="<?php echo esc_attr( $sheetsync_conn_id ); ?>">

                    <label class="ss-toggle-label" style="margin-bottom:12px;">
                        <input type="checkbox" name="off_peak_enabled" value="1" <?php checked( $sheetsync_off_peak_settings['enabled'] ); ?>>
                        <span class="ss-toggle-switch" aria-hidden="true"></span>
                        <strong><?php esc_html_e( 'Enable off-peak full publish', 'sheetsync-for-woocommerce' ); ?></strong>
                    </label>

                    <div class="ss-off-peak-time-row">
                        <label for="ss-off-peak-hour"><?php esc_html_e( 'Run at', 'sheetsync-for-woocommerce' ); ?></label>
                        <select name="off_peak_hour" id="ss-off-peak-hour">
                            <?php for ( $h = 0; $h < 24; $h++ ) : ?>
                                <option value="<?php echo esc_attr( (string) $h ); ?>" <?php selected( (int) $sheetsync_off_peak_settings['hour'], $h ); ?>>
                                    <?php
                                    echo esc_html(
                                        class_exists( 'SheetSync_Off_Peak_Publish', false )
                                            ? SheetSync_Off_Peak_Publish::format_time_label( $h, (int) $sheetsync_off_peak_settings['minute'] )
                                            : sprintf( '%02d:%02d', $h, (int) $sheetsync_off_peak_settings['minute'] )
                                    );
                                    ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <select name="off_peak_minute" id="ss-off-peak-minute" aria-label="<?php esc_attr_e( 'Minute', 'sheetsync-for-woocommerce' ); ?>">
                            <?php foreach ( array( 0, 15, 30, 45 ) as $m ) : ?>
                                <option value="<?php echo esc_attr( (string) $m ); ?>" <?php selected( (int) $sheetsync_off_peak_settings['minute'], $m ); ?>>
                                    <?php echo esc_html( sprintf( '%02d', $m ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="description"><?php echo esc_html( wp_timezone_string() ); ?></span>
                    </div>

                    <label style="display:flex;align-items:center;gap:8px;margin:12px 0;">
                        <input type="checkbox" name="off_peak_recurring" value="1" <?php checked( $sheetsync_off_peak_settings['recurring'] ); ?>>
                        <?php esc_html_e( 'Repeat daily', 'sheetsync-for-woocommerce' ); ?>
                    </label>

                    <p>
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Save off-peak schedule', 'sheetsync-for-woocommerce' ); ?></button>
                    </p>
                </form>
            </div>
        </details>
    <?php else : ?>
        <?php SheetSync_Admin::render_pro_gate( __( 'Off-Peak Full Publish', 'sheetsync-for-woocommerce' ) ); ?>
    <?php endif; ?>
</div>
