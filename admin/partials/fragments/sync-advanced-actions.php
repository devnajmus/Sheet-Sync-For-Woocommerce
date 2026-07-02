<?php
/**
 * Power-user sync buttons (collapsed under Advanced sync actions).
 *
 * Expects sync-tab variables from edit-connection.php.
 */
defined( 'ABSPATH' ) || exit;

if ( 'wc_to_sheets' === $sheetsync_sync_direction ) : ?>
    <div class="ss-sync-actions ss-sync-export-actions">
        <button type="button" class="button button-primary button-hero ss-sync-btn"
                data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>"
                data-sync-strategy="smart"
                <?php disabled( ! $sheetsync_has_maps ); ?>>
            <span class="dashicons dashicons-upload"></span>
            <?php esc_html_e( 'Export to Google Sheet', 'sheetsync-for-woocommerce' ); ?>
        </button>
        <p class="ss-sync-action-hint">
            <?php esc_html_e( 'Publishes WooCommerce changes to the sheet — full catalog on the first run, then only changed products.', 'sheetsync-for-woocommerce' ); ?>
        </p>

        <button type="button" class="button ss-sync-btn"
                data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>"
                data-sync-strategy="full"
                <?php disabled( ! $sheetsync_has_maps ); ?>>
            <span class="dashicons dashicons-image-rotate"></span>
            <?php esc_html_e( 'Export entire catalog again', 'sheetsync-for-woocommerce' ); ?>
        </button>
        <p class="ss-sync-action-hint">
            <?php esc_html_e( 'Re-writes every product row (slower). Use after mapping changes or if the sheet looks out of date.', 'sheetsync-for-woocommerce' ); ?>
        </p>
    </div>

    <div class="notice notice-warning inline" style="margin:12px 0;padding:12px 14px;border-left-width:4px;">
        <p style="margin:0;">
            <strong><?php esc_html_e( 'Sheet edits will not update WooCommerce on this connection.', 'sheetsync-for-woocommerce' ); ?></strong>
            <?php esc_html_e( 'This is export-only. To import sheet changes, set direction to Sheet → WooCommerce or Both ways on the Sync tab.', 'sheetsync-for-woocommerce' ); ?>
        </p>
    </div>

<?php elseif ( 'sheets_to_wc' === $sheetsync_sync_direction ) : ?>
    <div class="ss-sync-actions">
        <button type="button" class="button button-primary button-hero ss-sync-btn"
                data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>"
                data-sync-strategy="smart"
                data-sync-pull-mode="default"
                <?php disabled( ! $sheetsync_has_maps ); ?>>
            <span class="dashicons dashicons-download"></span>
            <?php echo esc_html__( 'Import from Google Sheet', 'sheetsync-for-woocommerce' ); ?>
        </button>
        <p class="ss-sync-action-hint">
            <?php esc_html_e( 'Applies your sheet edits to WooCommerce — updates existing products and adds new rows when needed.', 'sheetsync-for-woocommerce' ); ?>
        </p>

        <button type="button" class="button ss-sync-btn"
                data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>"
                data-sync-strategy="smart"
                data-sync-pull-mode="update_only"
                <?php disabled( ! $sheetsync_has_maps ); ?>>
            <span class="dashicons dashicons-edit"></span>
            <?php esc_html_e( 'Update existing products only', 'sheetsync-for-woocommerce' ); ?>
        </button>
        <p class="ss-sync-action-hint">
            <?php esc_html_e( 'Pushes sheet edits into WooCommerce for products already linked by SKU or Product ID — skips new sheet rows.', 'sheetsync-for-woocommerce' ); ?>
        </p>

        <button type="button" class="button ss-sync-btn"
                data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>"
                data-sync-strategy="smart"
                data-sync-pull-mode="create_new"
                <?php disabled( ! $sheetsync_has_maps ); ?>>
            <span class="dashicons dashicons-plus-alt"></span>
            <?php esc_html_e( 'Add new products only', 'sheetsync-for-woocommerce' ); ?>
        </button>
        <p class="ss-sync-action-hint">
            <?php esc_html_e( 'Creates WooCommerce products for new sheet rows only — skips rows that already match a SKU, Product ID, or linked title.', 'sheetsync-for-woocommerce' ); ?>
        </p>

        <details class="ss-sync-more-options">
            <summary><?php esc_html_e( 'Advanced import options', 'sheetsync-for-woocommerce' ); ?></summary>
            <div class="ss-sync-more-inner">
                <button type="button" class="button ss-sync-btn"
                        data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>"
                        data-sync-strategy="full"
                        data-sync-job-direction="pull"
                        data-sync-pull-mode="default"
                        <?php disabled( ! $sheetsync_has_maps ); ?>>
                    <span class="dashicons dashicons-image-rotate"></span>
                    <?php esc_html_e( 'Full re-import from sheet', 'sheetsync-for-woocommerce' ); ?>
                </button>
                <p class="ss-sync-action-hint"><?php esc_html_e( 'Re-processes every row (slower). Use if images or fields did not apply correctly.', 'sheetsync-for-woocommerce' ); ?></p>
            </div>
        </details>
    </div>

<?php elseif ( 'two_way' === $sheetsync_sync_direction ) : ?>
    <div class="ss-sync-actions">
        <button type="button" class="button button-primary button-hero ss-sync-btn"
                data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>"
                data-sync-strategy="smart"
                data-sync-job-direction="two_way"
                data-sync-pull-mode="default"
                <?php disabled( ! $sheetsync_has_maps ); ?>>
            <span class="dashicons dashicons-randomize"></span>
            <?php esc_html_e( 'Keep both sides in sync', 'sheetsync-for-woocommerce' ); ?>
        </button>
        <p class="ss-sync-action-hint">
            <?php esc_html_e( 'Applies sheet edits, then WooCommerce edits in one run (phase order is in Advanced settings).', 'sheetsync-for-woocommerce' ); ?>
        </p>

        <button type="button" class="button ss-sync-btn"
                data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>"
                data-sync-strategy="smart"
                data-sync-job-direction="pull"
                data-sync-pull-mode="default"
                <?php disabled( ! $sheetsync_has_maps ); ?>>
            <span class="dashicons dashicons-download"></span>
            <?php esc_html_e( 'Apply sheet changes only', 'sheetsync-for-woocommerce' ); ?>
        </button>
        <p class="ss-sync-action-hint">
            <?php esc_html_e( 'Sheet → WooCommerce only — does not push WooCommerce edits back to the sheet.', 'sheetsync-for-woocommerce' ); ?>
        </p>

        <button type="button" class="button ss-sync-btn"
                data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>"
                data-sync-strategy="smart"
                data-sync-job-direction="pull"
                data-sync-pull-mode="update_only"
                <?php disabled( ! $sheetsync_has_maps ); ?>>
            <span class="dashicons dashicons-edit"></span>
            <?php esc_html_e( 'Update existing products only', 'sheetsync-for-woocommerce' ); ?>
        </button>
        <p class="ss-sync-action-hint">
            <?php esc_html_e( 'Sheet → WooCommerce updates for linked rows only — skips new sheet rows.', 'sheetsync-for-woocommerce' ); ?>
        </p>

        <button type="button" class="button ss-sync-btn"
                data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>"
                data-sync-strategy="smart"
                data-sync-job-direction="pull"
                data-sync-pull-mode="create_new"
                <?php disabled( ! $sheetsync_has_maps ); ?>>
            <span class="dashicons dashicons-plus-alt"></span>
            <?php esc_html_e( 'Add new products only', 'sheetsync-for-woocommerce' ); ?>
        </button>
        <p class="ss-sync-action-hint">
            <?php esc_html_e( 'Creates WooCommerce products from new sheet rows — skips existing linked products.', 'sheetsync-for-woocommerce' ); ?>
        </p>

        <button type="button" class="button ss-sync-btn"
                data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>"
                data-sync-strategy="smart"
                data-sync-job-direction="push"
                <?php disabled( ! $sheetsync_has_maps ); ?>>
            <span class="dashicons dashicons-upload"></span>
            <?php esc_html_e( 'Push WooCommerce changes only', 'sheetsync-for-woocommerce' ); ?>
        </button>
        <p class="ss-sync-action-hint">
            <?php esc_html_e( 'WooCommerce → Sheet only — updates the sheet for products you changed in WooCommerce admin.', 'sheetsync-for-woocommerce' ); ?>
        </p>

        <button type="button" class="button ss-sync-btn"
                data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>"
                data-sync-strategy="full"
                <?php disabled( ! $sheetsync_has_maps ); ?>>
            <span class="dashicons dashicons-image-rotate"></span>
            <?php esc_html_e( 'Export entire catalog again', 'sheetsync-for-woocommerce' ); ?>
        </button>
        <p class="ss-sync-action-hint">
            <?php esc_html_e( 'Re-writes every product row to the sheet (bootstrap). Use after mapping changes or if rows are out of sync.', 'sheetsync-for-woocommerce' ); ?>
        </p>

        <details class="ss-sync-more-options">
            <summary><?php esc_html_e( 'Advanced two-way options', 'sheetsync-for-woocommerce' ); ?></summary>
            <div class="ss-sync-more-inner">
                <button type="button" class="button ss-sync-btn"
                        data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>"
                        data-sync-strategy="full"
                        data-sync-job-direction="pull"
                        data-sync-pull-mode="default"
                        <?php disabled( ! $sheetsync_has_maps ); ?>>
                    <span class="dashicons dashicons-image-rotate"></span>
                    <?php esc_html_e( 'Full re-import from sheet', 'sheetsync-for-woocommerce' ); ?>
                </button>
                <p class="ss-sync-action-hint"><?php esc_html_e( 'Re-processes every sheet row in WooCommerce (slower). Use if images or fields did not apply correctly.', 'sheetsync-for-woocommerce' ); ?></p>
            </div>
        </details>
    </div>
<?php endif; ?>
