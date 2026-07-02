<?php
/**
 * Unified automatic sync toggle — merchant-facing control on the Sync tab.
 */
defined( 'ABSPATH' ) || exit;

$sheetsync_automatic_sync_enabled = function_exists( 'sheetsync_is_automatic_sync_enabled' )
    ? sheetsync_is_automatic_sync_enabled( $sheetsync_conn_id )
    : (bool) ( $sheetsync_auto_sync_enabled ?? false );
$sheetsync_automatic_status_lines = function_exists( 'sheetsync_automatic_sync_status_lines' )
    ? sheetsync_automatic_sync_status_lines( $sheetsync_conn_id, $connection ?? null )
    : array();
?>
<div class="sheetsync-card ss-automatic-sync-unified-card" id="ss-automatic-sync-panel">
    <div class="ss-automatic-sync-unified-header">
        <h3 class="ss-automatic-sync-unified-title">
            <span class="dashicons dashicons-update" aria-hidden="true"></span>
            <?php esc_html_e( 'Automatic sync', 'sheetsync-for-woocommerce' ); ?>
            <?php if ( $sheetsync_is_pro ) : ?>
                <span class="sheetsync-pro-badge">PRO</span>
            <?php endif; ?>
        </h3>
        <p class="description ss-automatic-sync-unified-lead">
            <?php esc_html_e( 'One switch keeps this connection updated. SheetSync picks Smart Poll, webhooks, scheduled sync, and product-save push based on your direction.', 'sheetsync-for-woocommerce' ); ?>
        </p>
    </div>

    <?php if ( $sheetsync_is_pro ) : ?>
        <div class="ss-automatic-sync-unified-control">
            <label class="ss-toggle-label ss-automatic-sync-toggle-label">
                <input type="checkbox"
                       class="ss-automatic-sync-toggle"
                       data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>"
                       <?php checked( $sheetsync_automatic_sync_enabled ); ?>>
                <span class="ss-toggle-switch" aria-hidden="true"></span>
                <span class="ss-automatic-sync-toggle-text">
                    <strong><?php esc_html_e( 'Keep this connection in sync automatically', 'sheetsync-for-woocommerce' ); ?></strong>
                </span>
            </label>
            <span class="ss-automatic-sync-saving" aria-live="polite" hidden><?php esc_html_e( 'Saving…', 'sheetsync-for-woocommerce' ); ?></span>
        </div>

        <ul class="ss-automatic-sync-status" id="ss-automatic-sync-status"<?php echo empty( $sheetsync_automatic_status_lines ) ? ' hidden' : ''; ?>>
            <?php foreach ( $sheetsync_automatic_status_lines as $sheetsync_status_line ) : ?>
                <li><?php echo esc_html( $sheetsync_status_line ); ?></li>
            <?php endforeach; ?>
        </ul>

        <p class="description ss-automatic-sync-off-hint"<?php echo $sheetsync_automatic_sync_enabled ? ' hidden' : ''; ?>>
            <?php esc_html_e( 'Use Sync now above for manual updates. Turn on automatic sync to enable background updates.', 'sheetsync-for-woocommerce' ); ?>
        </p>
    <?php else : ?>
        <?php SheetSync_Admin::render_pro_gate( __( 'Automatic Sync', 'sheetsync-for-woocommerce' ) ); ?>
        <p class="description" style="margin-top:10px;">
            <?php esc_html_e( 'Pro enables Smart Poll for sheet edits, scheduled sync, and push on product save — no Apps Script required.', 'sheetsync-for-woocommerce' ); ?>
        </p>
    <?php endif; ?>
</div>
