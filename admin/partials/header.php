<?php defined( 'ABSPATH' ) || exit; ?>
<header class="sheetsync-header" role="banner">
    <div class="sheetsync-header-brand">
        <div class="sheetsync-logo" aria-hidden="true">
            <span class="sheetsync-logo-icon">
                <span class="dashicons dashicons-table-col-after"></span>
            </span>
        </div>
        <div class="sheetsync-header-titles">
            <h1 class="sheetsync-header-title">
                <?php esc_html_e( 'SheetSync for WooCommerce', 'sheetsync-for-woocommerce' ); ?>
            </h1>
            <p class="sheetsync-header-subtitle">
                <?php esc_html_e( 'Sync products & orders between WooCommerce and Google Sheets', 'sheetsync-for-woocommerce' ); ?>
            </p>
        </div>
    </div>
    <div class="sheetsync-header-meta">
        <?php if ( function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro() ) : ?>
            <span class="sheetsync-pro-badge" aria-label="<?php esc_attr_e( 'Pro license active', 'sheetsync-for-woocommerce' ); ?>">PRO</span>
        <?php endif; ?>
        <span class="sheetsync-version">v<?php echo esc_html( SHEETSYNC_VERSION ); ?></span>
    </div>
</header>
