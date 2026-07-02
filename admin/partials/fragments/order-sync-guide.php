<?php
/**
 * Order sync quick guide — shown on the Sync tab for order connections.
 *
 * Expects: $sheetsync_conn_id, $connection, $sheetsync_sync_direction,
 *          $sheetsync_automatic_sync_enabled (optional).
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

$sheetsync_order_type = (string) ( $connection->connection_type ?? 'orders' );
$sheetsync_order_label = function_exists( 'sheetsync_order_connection_type_label' )
    ? sheetsync_order_connection_type_label( $sheetsync_order_type )
    : $sheetsync_order_type;
$sheetsync_has_companion = function_exists( 'sheetsync_order_has_companion_connection' )
    ? sheetsync_order_has_companion_connection( $sheetsync_order_type )
    : true;
$sheetsync_pulls_from_sheet = in_array( $sheetsync_sync_direction, array( 'sheets_to_wc', 'two_way' ), true );
$sheetsync_sheet_url = ! empty( $connection->spreadsheet_id )
    ? 'https://docs.google.com/spreadsheets/d/' . rawurlencode( (string) $connection->spreadsheet_id ) . '/edit'
    : '';
$sheetsync_tab_name = (string) ( $connection->sheet_name ?? '' );
$sheetsync_auto_on = ! empty( $sheetsync_automatic_sync_enabled );
$sheetsync_last_poll = ( class_exists( 'SheetSync_Order_Sheet_Poller', false ) )
    ? SheetSync_Order_Sheet_Poller::get_last_run_label()
    : '';
?>
<div class="sheetsync-card ss-order-sync-guide" id="ss-order-sync-guide">
    <h2 class="ss-order-sync-guide-title">
        <span class="dashicons dashicons-cart" aria-hidden="true"></span>
        <?php esc_html_e( 'How order sync works', 'sheetsync-for-woocommerce' ); ?>
    </h2>
    <p class="description ss-order-sync-guide-lead">
        <?php
        printf(
            /* translators: 1: tab name e.g. order processing, 2: connection label e.g. Processing */
            esc_html__( 'This connection keeps the "%1$s" tab in sync with %2$s orders in WooCommerce.', 'sheetsync-for-woocommerce' ),
            esc_html( $sheetsync_tab_name ?: __( 'your sheet tab', 'sheetsync-for-woocommerce' ) ),
            esc_html( strtolower( $sheetsync_order_label ) )
        );
        ?>
    </p>

    <ol class="ss-order-sync-steps">
        <li>
            <strong><?php esc_html_e( 'Change status in the sheet', 'sheetsync-for-woocommerce' ); ?></strong>
            <span class="description">
                <?php esc_html_e( 'Open column C (Status) and pick a value from the dropdown — for example change processing to completed.', 'sheetsync-for-woocommerce' ); ?>
            </span>
        </li>
        <li>
            <strong><?php esc_html_e( 'Let SheetSync pick it up', 'sheetsync-for-woocommerce' ); ?></strong>
            <span class="description">
                <?php if ( $sheetsync_auto_on && $sheetsync_pulls_from_sheet ) : ?>
                    <?php
                    $poll_label = class_exists( 'SheetSync_Order_Sheet_Poller', false )
                        ? SheetSync_Order_Sheet_Poller::get_interval_label()
                        : __( 'every minute', 'sheetsync-for-woocommerce' );
                    printf(
                        /* translators: %s: poll interval e.g. every 1 minute */
                        esc_html__( 'Automatic sync checks your sheet %s. Opening this page also triggers a quick check.', 'sheetsync-for-woocommerce' ),
                        esc_html( $poll_label )
                    );
                    ?>
                <?php else : ?>
                    <?php esc_html_e( 'Click Sync now below (or turn on Automatic sync). Sheet edits are not applied until sync runs.', 'sheetsync-for-woocommerce' ); ?>
                <?php endif; ?>
            </span>
        </li>
        <li>
            <strong><?php esc_html_e( 'Row moves to the right tab', 'sheetsync-for-woocommerce' ); ?></strong>
            <span class="description">
                <?php esc_html_e( 'WooCommerce updates to the new status and the row disappears from this tab — it appears on the matching tab (e.g. order completed).', 'sheetsync-for-woocommerce' ); ?>
            </span>
        </li>
    </ol>

    <?php if ( ! $sheetsync_has_companion ) : ?>
    <div class="ss-order-sync-tip ss-order-sync-tip-warn">
        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
        <?php esc_html_e( 'Tip: create a second connection for the destination status (e.g. Completed) with Two-Way sync so rows can move between tabs.', 'sheetsync-for-woocommerce' ); ?>
    </div>
    <?php endif; ?>

    <?php if ( 'two_way' !== $sheetsync_sync_direction && $sheetsync_pulls_from_sheet ) : ?>
    <div class="ss-order-sync-tip">
        <span class="dashicons dashicons-info" aria-hidden="true"></span>
        <?php esc_html_e( 'Two-Way sync is recommended: sheet status edits apply to WooCommerce and new orders still export to the sheet.', 'sheetsync-for-woocommerce' ); ?>
    </div>
    <?php endif; ?>

    <?php if ( $sheetsync_last_poll && $sheetsync_auto_on ) : ?>
    <p class="description ss-order-sync-meta"><?php echo esc_html( $sheetsync_last_poll ); ?></p>
    <?php endif; ?>

    <?php if ( $sheetsync_sheet_url ) : ?>
    <p class="ss-order-sync-actions">
        <a href="<?php echo esc_url( $sheetsync_sheet_url ); ?>" class="button button-secondary" target="_blank" rel="noopener">
            <span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span>
            <?php esc_html_e( 'Open Google Sheet', 'sheetsync-for-woocommerce' ); ?>
        </a>
    </p>
    <?php endif; ?>
</div>
