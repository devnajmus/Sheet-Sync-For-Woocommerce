<?php
/**
 * Dashboards page — Sales Dashboard, Inventory Dashboard, Bulk Order Export.
 * Pro only.
 */
defined( 'ABSPATH' ) || exit;

$dash_access = class_exists( 'SheetSync_Dashboard_Phase2', false )
    ? SheetSync_Dashboard_Phase2::access_map_for_current_user()
    : array(
        'sales'     => true,
        'inventory' => true,
        'orders'    => true,
    );
?>
<div class="sheetsync-wrap ss-wa-theme ss-dash-fullpage"<?php echo class_exists( 'SheetSync_Dashboard_Phase3', false ) ? ' style="' . esc_attr( SheetSync_Dashboard_Phase3::branding_css_vars() ) . '"' : ''; ?>>
    <?php require __DIR__ . '/header.php'; ?>

    <div class="notice notice-info inline sheetsync-card ss-dash-scope-banner" style="margin-bottom:16px; border-left:4px solid var(--ss-green);">
        <p style="margin:0;">
            <span class="dashicons dashicons-chart-line" style="color:var(--ss-green);"></span>
            <strong><?php esc_html_e( 'Store Reports — not product sheet sync', 'sheetsync-for-woocommerce' ); ?></strong>
            —
            <?php esc_html_e( 'Sales, inventory, and order exports from WooCommerce data. To sync products with Google Sheets, use Connections.', 'sheetsync-for-woocommerce' ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync' ) ); ?>"><?php esc_html_e( 'Product sync → Connections', 'sheetsync-for-woocommerce' ); ?></a>
        </p>
    </div>

    <div class="ss-dash-chrome">
        <div class="ss-dash-tab-nav">
            <button class="ss-dash-tab-btn ss-dash-tab-active" data-target="ss-dash-panel-sales" data-dash="sales" <?php echo empty( $dash_access['sales'] ) ? 'style="display:none"' : ''; ?>>
                <span class="dashicons dashicons-chart-line"></span>
                <?php esc_html_e( 'Sales Dashboard', 'sheetsync-for-woocommerce' ); ?>
            </button>
            <button class="ss-dash-tab-btn" data-target="ss-dash-panel-inventory" data-dash="inventory" <?php echo empty( $dash_access['inventory'] ) ? 'style="display:none"' : ''; ?>>
                <span class="dashicons dashicons-archive"></span>
                <?php esc_html_e( 'Inventory Dashboard', 'sheetsync-for-woocommerce' ); ?>
            </button>
            <button class="ss-dash-tab-btn" data-target="ss-dash-panel-orders" data-dash="orders" <?php echo empty( $dash_access['orders'] ) ? 'style="display:none"' : ''; ?>>
                <span class="dashicons dashicons-download"></span>
                <?php esc_html_e( 'Bulk Order Export', 'sheetsync-for-woocommerce' ); ?>
            </button>
            <button type="button" class="ss-dash-automation-toggle" id="ss_dash_automation_toggle" aria-expanded="false" aria-controls="ss_dash_automation">
                <span class="dashicons dashicons-admin-generic"></span>
                <?php esc_html_e( 'Automation', 'sheetsync-for-woocommerce' ); ?>
            </button>
        </div>

        <div class="ss-dash-toolbar">
            <div class="ss-dash-global-search-wrap">
                <span class="dashicons dashicons-search"></span>
                <input type="search" id="ss_dash_global_search" class="ss-dash-global-search" placeholder="<?php esc_attr_e( 'Search orders & products…', 'sheetsync-for-woocommerce' ); ?>" autocomplete="off" />
                <div id="ss_dash_search_results" class="ss-dash-search-results" hidden></div>
            </div>
            <label class="ss-dash-demo-toggle" title="<?php esc_attr_e( 'Show sample data for demos', 'sheetsync-for-woocommerce' ); ?>">
                <input type="checkbox" id="ss_demo_mode_toggle" />
                <?php esc_html_e( 'Demo data', 'sheetsync-for-woocommerce' ); ?>
            </label>
        </div>

        <div id="ss_dash_demo_banner" class="ss-dash-demo-banner" style="display:none;" aria-live="polite">
            <?php esc_html_e( 'Demo mode — sample data is shown. Turn off in the toolbar to see live store data.', 'sheetsync-for-woocommerce' ); ?>
        </div>

        <div id="ss_dash_onboarding" class="ss-dash-onboarding" style="display:none;" aria-live="polite"></div>
    </div>

    <div class="ss-dash-automation-backdrop" id="ss_dash_automation_backdrop" hidden aria-hidden="true"></div>
    <div class="ss-dash-automation-panel ss-dash-automation-drawer" id="ss_dash_automation" hidden role="dialog" aria-modal="true" aria-labelledby="ss_dash_automation_title">
            <div class="ss-dash-automation-head">
                <h3 id="ss_dash_automation_title"><?php esc_html_e( 'Automation, Alerts & Export History', 'sheetsync-for-woocommerce' ); ?></h3>
                <div class="ss-dash-automation-head-actions">
                    <span class="ss-dash-automation-save-status" id="ss_dash_automation_save_status" aria-live="polite"></span>
                    <button type="button" class="ss-dash-automation-close" id="ss_dash_automation_close" aria-label="<?php esc_attr_e( 'Close', 'sheetsync-for-woocommerce' ); ?>">&times;</button>
                </div>
            </div>
            <div class="ss-dash-automation-notices" id="ss_dash_automation_notices" hidden></div>
            <div class="ss-dash-automation-inner">
                <p class="ss-dash-cron-strip" id="ss_dash_cron_note" hidden></p>
                <div class="ss-dash-automation-grid">
                    <div class="ss-dash-auto-row ss-dash-auto-row--schedule">
                        <div class="ss-dash-automation-block ss-dash-auto-card">
                            <h4><span class="ss-dash-auto-dot" aria-hidden="true"></span><?php esc_html_e( 'Scheduled Auto-Export', 'sheetsync-for-woocommerce' ); ?></h4>
                        <div class="ss-dash-schedule-list">
                            <div class="ss-dash-schedule-item">
                                <div class="ss-dash-schedule-main">
                                    <span class="ss-dash-schedule-name"><?php esc_html_e( 'Sales', 'sheetsync-for-woocommerce' ); ?></span>
                                    <select id="ss_sd_schedule_interval" class="ss-dash-input ss-dash-select">
                                        <option value="hourly"><?php esc_html_e( 'Hourly', 'sheetsync-for-woocommerce' ); ?></option>
                                        <option value="twicedaily"><?php esc_html_e( 'Twice daily', 'sheetsync-for-woocommerce' ); ?></option>
                                        <option value="daily" selected><?php esc_html_e( 'Daily', 'sheetsync-for-woocommerce' ); ?></option>
                                        <option value="weekly"><?php esc_html_e( 'Weekly', 'sheetsync-for-woocommerce' ); ?></option>
                                    </select>
                                    <input type="checkbox" id="ss_sd_schedule_enabled" class="ss-dash-schedule-checkbox" hidden />
                                    <button type="button" class="ss-dash-schedule-toggle" data-target="#ss_sd_schedule_enabled" aria-pressed="false" aria-label="<?php esc_attr_e( 'Toggle sales auto-export', 'sheetsync-for-woocommerce' ); ?>">
                                        <span class="ss-dash-schedule-toggle-track" aria-hidden="true"><span class="ss-dash-schedule-toggle-thumb"></span></span>
                                        <span class="ss-dash-schedule-toggle-text"><?php esc_html_e( 'Off', 'sheetsync-for-woocommerce' ); ?></span>
                                    </button>
                                </div>
                                <span class="ss-dash-schedule-hint" id="ss_schedule_hint_sales"></span>
                            </div>
                            <div class="ss-dash-schedule-item">
                                <div class="ss-dash-schedule-main">
                                    <span class="ss-dash-schedule-name"><?php esc_html_e( 'Inventory', 'sheetsync-for-woocommerce' ); ?></span>
                                    <select id="ss_inv_schedule_interval" class="ss-dash-input ss-dash-select">
                                        <option value="hourly"><?php esc_html_e( 'Hourly', 'sheetsync-for-woocommerce' ); ?></option>
                                        <option value="twicedaily"><?php esc_html_e( 'Twice daily', 'sheetsync-for-woocommerce' ); ?></option>
                                        <option value="daily" selected><?php esc_html_e( 'Daily', 'sheetsync-for-woocommerce' ); ?></option>
                                        <option value="weekly"><?php esc_html_e( 'Weekly', 'sheetsync-for-woocommerce' ); ?></option>
                                    </select>
                                    <input type="checkbox" id="ss_inv_schedule_enabled" class="ss-dash-schedule-checkbox" hidden />
                                    <button type="button" class="ss-dash-schedule-toggle" data-target="#ss_inv_schedule_enabled" aria-pressed="false" aria-label="<?php esc_attr_e( 'Toggle inventory auto-export', 'sheetsync-for-woocommerce' ); ?>">
                                        <span class="ss-dash-schedule-toggle-track" aria-hidden="true"><span class="ss-dash-schedule-toggle-thumb"></span></span>
                                        <span class="ss-dash-schedule-toggle-text"><?php esc_html_e( 'Off', 'sheetsync-for-woocommerce' ); ?></span>
                                    </button>
                                </div>
                                <span class="ss-dash-schedule-hint" id="ss_schedule_hint_inv"></span>
                            </div>
                            <div class="ss-dash-schedule-item">
                                <div class="ss-dash-schedule-main">
                                    <span class="ss-dash-schedule-name"><?php esc_html_e( 'Orders', 'sheetsync-for-woocommerce' ); ?></span>
                                    <select id="ss_boe_schedule_interval" class="ss-dash-input ss-dash-select">
                                        <option value="hourly"><?php esc_html_e( 'Hourly', 'sheetsync-for-woocommerce' ); ?></option>
                                        <option value="twicedaily"><?php esc_html_e( 'Twice daily', 'sheetsync-for-woocommerce' ); ?></option>
                                        <option value="daily"><?php esc_html_e( 'Daily', 'sheetsync-for-woocommerce' ); ?></option>
                                        <option value="weekly" selected><?php esc_html_e( 'Weekly', 'sheetsync-for-woocommerce' ); ?></option>
                                    </select>
                                    <input type="checkbox" id="ss_boe_schedule_enabled" class="ss-dash-schedule-checkbox" hidden />
                                    <button type="button" class="ss-dash-schedule-toggle" data-target="#ss_boe_schedule_enabled" aria-pressed="false" aria-label="<?php esc_attr_e( 'Toggle orders auto-export', 'sheetsync-for-woocommerce' ); ?>">
                                        <span class="ss-dash-schedule-toggle-track" aria-hidden="true"><span class="ss-dash-schedule-toggle-thumb"></span></span>
                                        <span class="ss-dash-schedule-toggle-text"><?php esc_html_e( 'Off', 'sheetsync-for-woocommerce' ); ?></span>
                                    </button>
                                </div>
                                <span class="ss-dash-schedule-hint" id="ss_schedule_hint_boe"></span>
                            </div>
                        </div>
                        <label class="ss-dash-field-label" for="ss_boe_schedule_preset"><?php esc_html_e( 'Order preset (optional)', 'sheetsync-for-woocommerce' ); ?></label>
                        <select id="ss_boe_schedule_preset" class="ss-dash-input ss-dash-select wide">
                            <option value=""><?php esc_html_e( 'Use interval window above', 'sheetsync-for-woocommerce' ); ?></option>
                        </select>
                        </div>
                        <div class="ss-dash-automation-block ss-dash-auto-card">
                        <h4><span class="ss-dash-auto-dot" aria-hidden="true"></span><?php esc_html_e( 'Last Synced', 'sheetsync-for-woocommerce' ); ?></h4>
                        <ul class="ss-dash-last-sync">
                            <li><span class="ss-dash-sync-label"><?php esc_html_e( 'Sales', 'sheetsync-for-woocommerce' ); ?></span> <strong class="ss-dash-sync-val" id="ss_last_export_sales"><?php esc_html_e( 'Never', 'sheetsync-for-woocommerce' ); ?></strong></li>
                            <li><span class="ss-dash-sync-label"><?php esc_html_e( 'Inventory', 'sheetsync-for-woocommerce' ); ?></span> <strong class="ss-dash-sync-val" id="ss_last_export_inventory"><?php esc_html_e( 'Never', 'sheetsync-for-woocommerce' ); ?></strong></li>
                            <li><span class="ss-dash-sync-label"><?php esc_html_e( 'Orders', 'sheetsync-for-woocommerce' ); ?></span> <strong class="ss-dash-sync-val" id="ss_last_export_orders"><?php esc_html_e( 'Never', 'sheetsync-for-woocommerce' ); ?></strong></li>
                        </ul>
                        </div>
                    </div>
                    <div class="ss-dash-auto-row ss-dash-auto-row--2">
                        <div class="ss-dash-automation-block ss-dash-auto-card">
                        <h4><span class="ss-dash-auto-dot" aria-hidden="true"></span><?php esc_html_e( 'Email Alerts', 'sheetsync-for-woocommerce' ); ?></h4>
                        <label class="ss-dash-field-label" for="ss_export_notify_email"><?php esc_html_e( 'Send export notifications to', 'sheetsync-for-woocommerce' ); ?></label>
                        <input type="email" id="ss_export_notify_email" class="ss-dash-input wide" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
                        <label class="ss-dash-check ss-dash-check-block"><input type="checkbox" id="ss_inv_email_low_stock" checked="checked" /> <span><?php esc_html_e( 'Low stock alert after inventory export', 'sheetsync-for-woocommerce' ); ?></span></label>
                        <label class="ss-dash-field-label" for="ss_sd_monthly_goal"><?php esc_html_e( 'Monthly revenue goal (optional)', 'sheetsync-for-woocommerce' ); ?></label>
                        <input type="number" id="ss_sd_monthly_goal" class="ss-dash-input" min="0" step="0.01" placeholder="50000" />
                        </div>
                        <div class="ss-dash-automation-block ss-dash-auto-card">
                        <h4><span class="ss-dash-auto-dot" aria-hidden="true"></span><?php esc_html_e( 'Dashboard Access', 'sheetsync-for-woocommerce' ); ?></h4>
                        <label class="ss-dash-field-label" for="ss_access_sales_roles"><?php esc_html_e( 'Sales roles', 'sheetsync-for-woocommerce' ); ?></label>
                        <input type="text" id="ss_access_sales_roles" class="ss-dash-input wide" value="administrator,shop_manager" />
                        <label class="ss-dash-field-label" for="ss_access_inventory_roles"><?php esc_html_e( 'Inventory roles', 'sheetsync-for-woocommerce' ); ?></label>
                        <input type="text" id="ss_access_inventory_roles" class="ss-dash-input wide" value="administrator,shop_manager" />
                        <label class="ss-dash-field-label" for="ss_access_orders_roles"><?php esc_html_e( 'Bulk export roles', 'sheetsync-for-woocommerce' ); ?></label>
                        <input type="text" id="ss_access_orders_roles" class="ss-dash-input wide" value="administrator,shop_manager" />
                        <p class="ss-dash-muted ss-dash-help-note"><?php esc_html_e( 'Comma-separated WordPress roles. Administrators always have full access.', 'sheetsync-for-woocommerce' ); ?></p>
                        </div>
                    </div>
                    <div class="ss-dash-auto-row ss-dash-auto-row--1">
                        <div class="ss-dash-automation-block ss-dash-auto-card">
                        <h4><span class="ss-dash-auto-dot" aria-hidden="true"></span><?php esc_html_e( 'Webhooks / Zapier', 'sheetsync-for-woocommerce' ); ?></h4>
                        <label class="ss-dash-check ss-dash-check-block"><input type="checkbox" id="ss_webhook_export_enabled" /> <span><?php esc_html_e( 'POST on export complete', 'sheetsync-for-woocommerce' ); ?></span></label>
                        <label class="ss-dash-field-label" for="ss_webhook_export_urls"><?php esc_html_e( 'Webhook URLs (one per line)', 'sheetsync-for-woocommerce' ); ?></label>
                        <textarea id="ss_webhook_export_urls" class="ss-dash-input wide ss-dash-input-compact" rows="2" placeholder="https://hooks.zapier.com/hooks/catch/…"></textarea>
                        <label class="ss-dash-field-label" for="ss_webhook_export_secret"><?php esc_html_e( 'Signing secret (optional)', 'sheetsync-for-woocommerce' ); ?></label>
                        <input type="text" id="ss_webhook_export_secret" class="ss-dash-input wide" autocomplete="off" />
                        <div class="ss-dash-webhook-actions">
                            <button type="button" class="ss-dash-action-btn ss-dash-action-btn-outline" id="ss_webhook_test_btn"><?php esc_html_e( 'Test webhook', 'sheetsync-for-woocommerce' ); ?></button>
                            <span class="ss-dash-webhook-test-status" id="ss_webhook_test_status" aria-live="polite"></span>
                        </div>
                        <p class="ss-dash-muted ss-dash-help-note"><?php esc_html_e( 'Event: sheetsync.export.complete', 'sheetsync-for-woocommerce' ); ?></p>
                        </div>
                    </div>
                    <div class="ss-dash-auto-row ss-dash-auto-row--3">
                        <div class="ss-dash-automation-block ss-dash-auto-card">
                        <h4><span class="ss-dash-auto-dot" aria-hidden="true"></span><?php esc_html_e( 'White-Label', 'sheetsync-for-woocommerce' ); ?></h4>
                        <label class="ss-dash-field-label" for="ss_wl_app_name"><?php esc_html_e( 'App name', 'sheetsync-for-woocommerce' ); ?></label>
                        <input type="text" id="ss_wl_app_name" class="ss-dash-input wide" placeholder="SheetSync" />
                        <label class="ss-dash-field-label" for="ss_wl_logo_url"><?php esc_html_e( 'Logo URL', 'sheetsync-for-woocommerce' ); ?></label>
                        <input type="url" id="ss_wl_logo_url" class="ss-dash-input wide" placeholder="https://…" />
                        <div class="ss-dash-color-row">
                            <div class="ss-dash-color-field">
                                <label class="ss-dash-field-label" for="ss_wl_primary_color"><?php esc_html_e( 'Primary', 'sheetsync-for-woocommerce' ); ?></label>
                                <input type="color" id="ss_wl_primary_color" class="ss-dash-color-input" value="#6c63ff" />
                            </div>
                            <div class="ss-dash-color-field">
                                <label class="ss-dash-field-label" for="ss_wl_accent_color"><?php esc_html_e( 'Accent', 'sheetsync-for-woocommerce' ); ?></label>
                                <input type="color" id="ss_wl_accent_color" class="ss-dash-color-input" value="#22d3a5" />
                            </div>
                        </div>
                        <label class="ss-dash-check ss-dash-check-block"><input type="checkbox" id="ss_wl_hide_pro_badge" /> <span><?php esc_html_e( 'Hide PRO badge', 'sheetsync-for-woocommerce' ); ?></span></label>
                        </div>
                        <div class="ss-dash-automation-block ss-dash-auto-card">
                        <h4><span class="ss-dash-auto-dot" aria-hidden="true"></span><?php esc_html_e( 'Multi-Store', 'sheetsync-for-woocommerce' ); ?></h4>
                        <label class="ss-dash-check ss-dash-check-block"><input type="checkbox" id="ss_multistore_enabled" /> <span><?php esc_html_e( 'Show network store totals on Sales Dashboard', 'sheetsync-for-woocommerce' ); ?></span></label>
                        <label class="ss-dash-field-label" for="ss_multistore_site_ids"><?php esc_html_e( 'Site IDs', 'sheetsync-for-woocommerce' ); ?></label>
                        <input type="text" id="ss_multistore_site_ids" class="ss-dash-input wide" placeholder="2, 3, 4" />
                        <p class="ss-dash-muted ss-dash-help-note"><?php esc_html_e( 'Multisite only.', 'sheetsync-for-woocommerce' ); ?></p>
                        </div>
                        <div class="ss-dash-automation-block ss-dash-auto-card">
                        <h4><span class="ss-dash-auto-dot" aria-hidden="true"></span><?php esc_html_e( 'Profit & Mobile', 'sheetsync-for-woocommerce' ); ?></h4>
                        <label class="ss-dash-check"><input type="checkbox" id="ss_cogs_enabled" checked="checked" /> <span><?php esc_html_e( 'Track profit (COGS)', 'sheetsync-for-woocommerce' ); ?></span></label>
                        <label class="ss-dash-check"><input type="checkbox" id="ss_pwa_enabled" checked="checked" /> <span><?php esc_html_e( 'Mobile PWA snapshot', 'sheetsync-for-woocommerce' ); ?></span></label>
                        <div class="ss-dash-card-footer">
                            <a href="<?php echo esc_url( home_url( '/sheetsync-pwa/' ) ); ?>" class="ss-dash-action-btn ss-dash-action-btn-outline" id="ss_pwa_open_link" target="_blank" rel="noopener"><?php esc_html_e( 'Open Mobile Widget', 'sheetsync-for-woocommerce' ); ?></a>
                        </div>
                        </div>
                    </div>
                </div>
                <div class="ss-dash-export-log-wrap ss-dash-auto-card">
                    <div class="ss-dash-export-log-head">
                        <h4><span class="ss-dash-auto-dot" aria-hidden="true"></span><?php esc_html_e( 'Export History', 'sheetsync-for-woocommerce' ); ?> <span class="ss-dash-auto-sub"><?php esc_html_e( 'Last 50', 'sheetsync-for-woocommerce' ); ?></span></h4>
                        <button type="button" class="ss-dash-action-btn ss-dash-action-btn-outline" id="ss_export_log_csv_btn"><?php esc_html_e( 'Download CSV', 'sheetsync-for-woocommerce' ); ?></button>
                    </div>
                    <div id="ss_dash_export_log" class="ss-dash-export-log"><p class="ss-dash-muted"><?php esc_html_e( 'Loading…', 'sheetsync-for-woocommerce' ); ?></p></div>
                </div>
            </div>
        </div>

    <div id="ss-dash-panel-sales" class="ss-dash-panel ss-sales-dashboard-panel">
        <input type="hidden" id="ss_sd_period_hidden" value="7days" />

        <div class="ss-wa-app">
            <aside class="ss-wa-sidebar" id="ss_wa_sidebar">
                <div class="ss-wa-sidebar-logo">
                    <div class="ss-wa-logo-icon"><span class="dashicons dashicons-chart-area"></span></div>
                    <div class="ss-wa-logo-text"><?php esc_html_e( 'SheetSync', 'sheetsync-for-woocommerce' ); ?> <span class="ss-wa-logo-badge">PRO</span></div>
                </div>
                <nav class="ss-wa-sidebar-nav">
                    <div class="ss-wa-nav-section">
                        <div class="ss-wa-nav-label"><?php esc_html_e( 'Overview', 'sheetsync-for-woocommerce' ); ?></div>
                        <a href="#ss-wa-sec-kpi" class="ss-wa-nav-item active" data-section="ss-wa-sec-kpi"><span class="ss-wa-nav-icon">📊</span> <?php esc_html_e( 'Dashboard', 'sheetsync-for-woocommerce' ); ?></a>
                        <a href="#ss-wa-sec-revenue" class="ss-wa-nav-item" data-section="ss-wa-sec-revenue"><span class="ss-wa-nav-icon">📈</span> <?php esc_html_e( 'Analytics', 'sheetsync-for-woocommerce' ); ?></a>
                        <a href="#ss-wa-sec-forecast" class="ss-wa-nav-item" data-section="ss-wa-sec-forecast"><span class="ss-wa-nav-icon">🎯</span> <?php esc_html_e( 'Sales Forecast', 'sheetsync-for-woocommerce' ); ?></a>
                    </div>
                    <div class="ss-wa-nav-section">
                        <div class="ss-wa-nav-label"><?php esc_html_e( 'Commerce', 'sheetsync-for-woocommerce' ); ?></div>
                        <a href="#ss-wa-sec-orders" class="ss-wa-nav-item" data-section="ss-wa-sec-orders"><span class="ss-wa-nav-icon">🛒</span> <?php esc_html_e( 'Orders', 'sheetsync-for-woocommerce' ); ?> <span class="ss-wa-nav-badge" id="ss_wa_pending_badge" style="display:none;">0</span></a>
                        <a href="#ss-wa-sec-products" class="ss-wa-nav-item" data-section="ss-wa-sec-products"><span class="ss-wa-nav-icon">📦</span> <?php esc_html_e( 'Products', 'sheetsync-for-woocommerce' ); ?></a>
                        <a href="#ss-wa-sec-customers" class="ss-wa-nav-item" data-section="ss-wa-sec-customers"><span class="ss-wa-nav-icon">👥</span> <?php esc_html_e( 'Customers', 'sheetsync-for-woocommerce' ); ?></a>
                    </div>
                    <div class="ss-wa-nav-section">
                        <div class="ss-wa-nav-label"><?php esc_html_e( 'Insights', 'sheetsync-for-woocommerce' ); ?></div>
                        <a href="#ss-wa-sec-geo" class="ss-wa-nav-item" data-section="ss-wa-sec-geo"><span class="ss-wa-nav-icon">🗺️</span> <?php esc_html_e( 'Geographic', 'sheetsync-for-woocommerce' ); ?></a>
                        <a href="#ss-wa-sec-inventory" class="ss-wa-nav-item" data-section="ss-wa-sec-inventory"><span class="ss-wa-nav-icon">🏪</span> <?php esc_html_e( 'Inventory', 'sheetsync-for-woocommerce' ); ?></a>
                        <a href="#ss-wa-sec-payments" class="ss-wa-nav-item" data-section="ss-wa-sec-payments"><span class="ss-wa-nav-icon">💳</span> <?php esc_html_e( 'Payments', 'sheetsync-for-woocommerce' ); ?></a>
                        <a href="#ss-wa-sec-insights" class="ss-wa-nav-item" data-section="ss-wa-sec-insights"><span class="ss-wa-nav-icon">🤖</span> <?php esc_html_e( 'AI Insights', 'sheetsync-for-woocommerce' ); ?></a>
                    </div>
                    <div class="ss-wa-nav-section">
                        <div class="ss-wa-nav-label"><?php esc_html_e( 'Settings', 'sheetsync-for-woocommerce' ); ?></div>
                        <a href="#ss-wa-sec-export" class="ss-wa-nav-item" data-section="ss-wa-sec-export"><span class="ss-wa-nav-icon">📥</span> <?php esc_html_e( 'Export Reports', 'sheetsync-for-woocommerce' ); ?></a>
                    </div>
                </nav>
                <div class="ss-wa-sidebar-footer">
                    <div class="ss-wa-sidebar-user">
                        <div class="ss-wa-user-avatar" id="ss_wa_user_avatar">—</div>
                        <div class="ss-wa-user-info">
                            <div class="ss-wa-user-name" id="ss_wa_user_name"><?php echo esc_html( wp_get_current_user()->display_name ); ?></div>
                            <div class="ss-wa-user-role" id="ss_wa_user_role"><?php esc_html_e( 'Store Administrator', 'sheetsync-for-woocommerce' ); ?></div>
                        </div>
                    </div>
                </div>
            </aside>

            <div class="ss-wa-main">
                <div class="ss-wa-topbar">
                    <button type="button" class="ss-wa-hamburger" id="ss_wa_hamburger" aria-label="<?php esc_attr_e( 'Menu', 'sheetsync-for-woocommerce' ); ?>">
                        <span></span><span></span><span></span>
                    </button>
                    <h2 class="ss-wa-topbar-title"><?php esc_html_e( 'Sales Dashboard', 'sheetsync-for-woocommerce' ); ?></h2>
                    <span class="ss-wa-live"><?php esc_html_e( 'Live', 'sheetsync-for-woocommerce' ); ?></span>
                    <div class="ss-wa-topbar-right">
                        <button type="button" id="ss_wa_theme_toggle" class="ss-wa-btn ss-wa-theme-toggle" title="<?php esc_attr_e( 'Switch to light mode', 'sheetsync-for-woocommerce' ); ?>" aria-label="<?php esc_attr_e( 'Toggle dashboard theme', 'sheetsync-for-woocommerce' ); ?>">
                            <span class="ss-wa-theme-icon ss-wa-theme-icon-dark" aria-hidden="true">🌙</span>
                            <span class="ss-wa-theme-icon ss-wa-theme-icon-light" aria-hidden="true">☀️</span>
                        </button>
                        <button type="button" id="ss_sd_refresh_btn" class="ss-wa-btn" title="<?php esc_attr_e( 'Refresh', 'sheetsync-for-woocommerce' ); ?>">
                            <span class="dashicons dashicons-update"></span>
                        </button>
                        <button type="button" id="ss_sd_pdf_btn" class="ss-wa-btn" title="<?php esc_attr_e( 'Print / save as PDF', 'sheetsync-for-woocommerce' ); ?>">
                            <span class="dashicons dashicons-media-document"></span>
                            <?php esc_html_e( 'PDF', 'sheetsync-for-woocommerce' ); ?>
                        </button>
                        <button type="button" id="ss_sd_export_btn" class="ss-wa-btn primary">
                            <span class="dashicons dashicons-cloud-upload"></span>
                            <?php esc_html_e( 'Export to Sheet', 'sheetsync-for-woocommerce' ); ?>
                        </button>
                    </div>
                </div>

                <div class="ss-wa-page">
                    <div class="ss-wa-filter-collapse" id="ss_sd_filter_wrap">
                        <button type="button" class="ss-wa-filter-collapse-trigger" id="ss_sd_filter_toggle" aria-expanded="false" aria-controls="ss-wa-sec-export">
                            <span class="dashicons dashicons-filter ss-wa-filter-collapse-icon" aria-hidden="true"></span>
                            <span class="ss-wa-filter-collapse-title"><?php esc_html_e( 'Filters & Export', 'sheetsync-for-woocommerce' ); ?></span>
                            <span class="ss-wa-filter-collapse-summary" id="ss_sd_filter_summary"><?php esc_html_e( 'Last 7 Days', 'sheetsync-for-woocommerce' ); ?></span>
                            <span class="dashicons dashicons-arrow-down-alt2 ss-wa-filter-collapse-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="ss-wa-filter-bar ss-wa-filter-collapse-body" id="ss-wa-sec-export" hidden>
                        <div class="ss-wa-filter-row">
                            <span class="ss-wa-filter-label"><?php esc_html_e( 'Period', 'sheetsync-for-woocommerce' ); ?></span>
                            <div class="ss-wa-date-pills" id="ss_wa_date_pills">
                                <button type="button" class="ss-wa-date-pill active" data-period="7days"><?php esc_html_e( 'Last 7 Days', 'sheetsync-for-woocommerce' ); ?></button>
                                <button type="button" class="ss-wa-date-pill" data-period="30days"><?php esc_html_e( 'Last 30 Days', 'sheetsync-for-woocommerce' ); ?></button>
                                <button type="button" class="ss-wa-date-pill" data-period="6months"><?php esc_html_e( 'Last 6 Months', 'sheetsync-for-woocommerce' ); ?></button>
                                <button type="button" class="ss-wa-date-pill" data-period="12months"><?php esc_html_e( 'Last 12 Months', 'sheetsync-for-woocommerce' ); ?></button>
                            </div>
                        </div>
                        <div class="ss-wa-filter-divider"></div>
                        <div class="ss-wa-filter-row">
                            <span class="ss-wa-filter-label"><?php esc_html_e( 'Filters', 'sheetsync-for-woocommerce' ); ?></span>
                            <div class="ss-wa-filter-selects" id="ss_wa_filter_selects"></div>
                            <div class="ss-wa-filter-actions">
                                <button type="button" class="ss-wa-btn" id="ss_wa_filter_reset"><?php esc_html_e( 'Reset', 'sheetsync-for-woocommerce' ); ?></button>
                                <button type="button" class="ss-wa-btn primary" id="ss_wa_filter_apply"><?php esc_html_e( 'Apply Filters', 'sheetsync-for-woocommerce' ); ?></button>
                            </div>
                        </div>
                        <div class="ss-wa-filter-divider"></div>
                        <div class="ss-wa-filter-row">
                            <span class="ss-wa-filter-label"><?php esc_html_e( 'Google Sheet', 'sheetsync-for-woocommerce' ); ?></span>
                            <div class="ss-wa-export-row" style="flex:1;">
                                <div class="ss-wa-field">
                                    <label for="ss_sd_spreadsheet_id"><?php esc_html_e( 'Spreadsheet ID', 'sheetsync-for-woocommerce' ); ?></label>
                                    <input type="text" id="ss_sd_spreadsheet_id" class="regular-text" placeholder="1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgVE2upms" />
                                </div>
                                <div class="ss-wa-field">
                                    <label for="ss_sd_sheet_name"><?php esc_html_e( 'Sheet Tab', 'sheetsync-for-woocommerce' ); ?></label>
                                    <input type="text" id="ss_sd_sheet_name" class="regular-text" value="Sales Dashboard" />
                                </div>
                                <input type="hidden" id="ss_sd_period" value="7days" />
                            </div>
                        </div>
                        <div id="ss_sd_result" class="ss-dash-result" style="display:none;"></div>
                        </div>
                    </div>

                    <div id="ss_sales_dashboard_root">
                        <div class="ss-wa-loading">
                            <span class="dashicons dashicons-update ss-spin"></span>
                            <p><?php esc_html_e( 'Loading sales analytics…', 'sheetsync-for-woocommerce' ); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="ss-wa-sidebar-overlay" id="ss_wa_overlay"></div>
    </div>

    <div id="ss-dash-panel-inventory" class="ss-dash-panel ss-inventory-dashboard-panel" style="display:none;">
        <div class="ss-wa-app">
            <aside class="ss-wa-sidebar" id="ss_inv_sidebar">
                <div class="ss-wa-sidebar-logo">
                    <div class="ss-wa-logo-icon"><span class="dashicons dashicons-archive"></span></div>
                    <div class="ss-wa-logo-text"><?php esc_html_e( 'SheetSync', 'sheetsync-for-woocommerce' ); ?> <span class="ss-wa-logo-badge">PRO</span></div>
                </div>
                <nav class="ss-wa-sidebar-nav">
                    <div class="ss-wa-nav-section">
                        <div class="ss-wa-nav-label"><?php esc_html_e( 'Overview', 'sheetsync-for-woocommerce' ); ?></div>
                        <a href="#ss-inv-sec-kpi" class="ss-wa-nav-item active" data-section="ss-inv-sec-kpi"><span class="ss-wa-nav-icon">📊</span> <?php esc_html_e( 'Dashboard', 'sheetsync-for-woocommerce' ); ?></a>
                        <a href="#ss-inv-sec-status" class="ss-wa-nav-item" data-section="ss-inv-sec-status"><span class="ss-wa-nav-icon">📈</span> <?php esc_html_e( 'Stock Status', 'sheetsync-for-woocommerce' ); ?></a>
                        <a href="#ss-inv-sec-categories" class="ss-wa-nav-item" data-section="ss-inv-sec-categories"><span class="ss-wa-nav-icon">🏷️</span> <?php esc_html_e( 'Categories', 'sheetsync-for-woocommerce' ); ?></a>
                    </div>
                    <div class="ss-wa-nav-section">
                        <div class="ss-wa-nav-label"><?php esc_html_e( 'Alerts', 'sheetsync-for-woocommerce' ); ?></div>
                        <a href="#ss-inv-sec-low" class="ss-wa-nav-item" data-section="ss-inv-sec-low"><span class="ss-wa-nav-icon">⚠️</span> <?php esc_html_e( 'Low Stock', 'sheetsync-for-woocommerce' ); ?> <span class="ss-wa-nav-badge" id="ss_inv_low_badge" style="display:none;">0</span></a>
                        <a href="#ss-inv-sec-out" class="ss-wa-nav-item" data-section="ss-inv-sec-out"><span class="ss-wa-nav-icon">🚫</span> <?php esc_html_e( 'Out of Stock', 'sheetsync-for-woocommerce' ); ?> <span class="ss-wa-nav-badge" id="ss_inv_out_badge" style="display:none;">0</span></a>
                    </div>
                    <div class="ss-wa-nav-section">
                        <div class="ss-wa-nav-label"><?php esc_html_e( 'Catalog', 'sheetsync-for-woocommerce' ); ?></div>
                        <a href="#ss-inv-sec-variations" class="ss-wa-nav-item" data-section="ss-inv-sec-variations"><span class="ss-wa-nav-icon">🎨</span> <?php esc_html_e( 'Variations', 'sheetsync-for-woocommerce' ); ?></a>
                        <a href="#ss-inv-sec-products" class="ss-wa-nav-item" data-section="ss-inv-sec-products"><span class="ss-wa-nav-icon">📦</span> <?php esc_html_e( 'All Products', 'sheetsync-for-woocommerce' ); ?></a>
                        <a href="#ss-inv-sec-export" class="ss-wa-nav-item" data-section="ss-inv-sec-export"><span class="ss-wa-nav-icon">📥</span> <?php esc_html_e( 'Export', 'sheetsync-for-woocommerce' ); ?></a>
                    </div>
                </nav>
                <div class="ss-wa-sidebar-footer">
                    <div class="ss-wa-sidebar-user">
                        <div class="ss-wa-user-avatar" id="ss_inv_user_avatar">—</div>
                        <div class="ss-wa-user-info">
                            <div class="ss-wa-user-name"><?php echo esc_html( wp_get_current_user()->display_name ); ?></div>
                            <div class="ss-wa-user-role"><?php esc_html_e( 'Inventory Manager', 'sheetsync-for-woocommerce' ); ?></div>
                        </div>
                    </div>
                </div>
            </aside>

            <div class="ss-wa-main">
                <div class="ss-wa-topbar">
                    <button type="button" class="ss-wa-hamburger" id="ss_inv_hamburger" aria-label="<?php esc_attr_e( 'Menu', 'sheetsync-for-woocommerce' ); ?>">
                        <span></span><span></span><span></span>
                    </button>
                    <h2 class="ss-wa-topbar-title"><?php esc_html_e( 'Inventory Dashboard', 'sheetsync-for-woocommerce' ); ?></h2>
                    <span class="ss-wa-live"><?php esc_html_e( 'Live', 'sheetsync-for-woocommerce' ); ?></span>
                    <div class="ss-wa-topbar-right">
                        <button type="button" class="ss-wa-btn ss-wa-theme-toggle" id="ss_inv_theme_toggle" title="<?php esc_attr_e( 'Toggle dashboard theme', 'sheetsync-for-woocommerce' ); ?>" aria-label="<?php esc_attr_e( 'Toggle dashboard theme', 'sheetsync-for-woocommerce' ); ?>">
                            <span class="ss-wa-theme-icon ss-wa-theme-icon-dark" aria-hidden="true">🌙</span>
                            <span class="ss-wa-theme-icon ss-wa-theme-icon-light" aria-hidden="true">☀️</span>
                        </button>
                        <button type="button" id="ss_inv_refresh_btn" class="ss-wa-btn" title="<?php esc_attr_e( 'Refresh', 'sheetsync-for-woocommerce' ); ?>">
                            <span class="dashicons dashicons-update"></span>
                        </button>
                        <button type="button" id="ss_inv_pdf_btn" class="ss-wa-btn" title="<?php esc_attr_e( 'Print / save as PDF', 'sheetsync-for-woocommerce' ); ?>">
                            <span class="dashicons dashicons-media-document"></span>
                            <?php esc_html_e( 'PDF', 'sheetsync-for-woocommerce' ); ?>
                        </button>
                        <button type="button" id="ss_inv_export_btn" class="ss-wa-btn primary">
                            <span class="dashicons dashicons-cloud-upload"></span>
                            <?php esc_html_e( 'Export to Sheet', 'sheetsync-for-woocommerce' ); ?>
                        </button>
                    </div>
                </div>

                <div class="ss-wa-page">
                    <div class="ss-wa-filter-bar" id="ss-inv-sec-export">
                        <div class="ss-wa-filter-row">
                            <span class="ss-wa-filter-label"><?php esc_html_e( 'Settings', 'sheetsync-for-woocommerce' ); ?></span>
                            <div class="ss-wa-filter-selects">
                                <div class="ss-wa-field ss-wa-field-inline">
                                    <label for="ss_inv_low_stock"><?php esc_html_e( 'Low Stock Threshold', 'sheetsync-for-woocommerce' ); ?></label>
                                    <input type="number" id="ss_inv_low_stock" class="ss-wa-filter-input small" value="5" min="1" max="999" />
                                </div>
                            </div>
                            <div class="ss-wa-filter-actions">
                                <button type="button" class="ss-wa-btn primary" id="ss_inv_apply_threshold"><?php esc_html_e( 'Apply', 'sheetsync-for-woocommerce' ); ?></button>
                            </div>
                        </div>
                        <div class="ss-wa-filter-divider"></div>
                        <div class="ss-wa-filter-row">
                            <span class="ss-wa-filter-label"><?php esc_html_e( 'Google Sheet', 'sheetsync-for-woocommerce' ); ?></span>
                            <div class="ss-wa-export-row" style="flex:1;">
                                <div class="ss-wa-field">
                                    <label for="ss_inv_spreadsheet_id"><?php esc_html_e( 'Spreadsheet ID', 'sheetsync-for-woocommerce' ); ?></label>
                                    <input type="text" id="ss_inv_spreadsheet_id" class="regular-text" placeholder="1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgVE2upms" />
                                </div>
                                <div class="ss-wa-field">
                                    <label for="ss_inv_sheet_name"><?php esc_html_e( 'Sheet Tab', 'sheetsync-for-woocommerce' ); ?></label>
                                    <input type="text" id="ss_inv_sheet_name" class="regular-text" value="Inventory Status" />
                                </div>
                            </div>
                        </div>
                        <div id="ss_inv_result" class="ss-dash-result" style="display:none;"></div>
                    </div>

                    <div id="ss_inventory_dashboard_root">
                        <div class="ss-wa-loading">
                            <span class="dashicons dashicons-update ss-spin"></span>
                            <p><?php esc_html_e( 'Loading inventory analytics…', 'sheetsync-for-woocommerce' ); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="ss-wa-sidebar-overlay" id="ss_inv_overlay"></div>
    </div>

    <div id="ss-dash-panel-orders" class="ss-dash-panel ss-boe-dashboard-panel" style="display:none;">
        <div class="ss-wa-app">
            <aside class="ss-wa-sidebar" id="ss_boe_sidebar">
                <div class="ss-wa-sidebar-logo">
                    <div class="ss-wa-logo-icon"><span class="dashicons dashicons-download"></span></div>
                    <div class="ss-wa-logo-text"><?php esc_html_e( 'SheetSync', 'sheetsync-for-woocommerce' ); ?> <span class="ss-wa-logo-badge">PRO</span></div>
                </div>
                <nav class="ss-wa-sidebar-nav">
                    <div class="ss-wa-nav-section">
                        <div class="ss-wa-nav-label"><?php esc_html_e( 'Overview', 'sheetsync-for-woocommerce' ); ?></div>
                        <a href="#ss-boe-sec-kpi" class="ss-wa-nav-item active" data-section="ss-boe-sec-kpi"><span class="ss-wa-nav-icon">📊</span> <?php esc_html_e( 'Summary', 'sheetsync-for-woocommerce' ); ?></a>
                        <a href="#ss-boe-sec-preview" class="ss-wa-nav-item" data-section="ss-boe-sec-preview"><span class="ss-wa-nav-icon">🛒</span> <?php esc_html_e( 'Order Preview', 'sheetsync-for-woocommerce' ); ?></a>
                    </div>
                    <div class="ss-wa-nav-section">
                        <div class="ss-wa-nav-label"><?php esc_html_e( 'Export', 'sheetsync-for-woocommerce' ); ?></div>
                        <a href="#ss-boe-sec-filters" class="ss-wa-nav-item" data-section="ss-boe-sec-filters"><span class="ss-wa-nav-icon">🔍</span> <?php esc_html_e( 'Filters', 'sheetsync-for-woocommerce' ); ?></a>
                        <a href="#ss-boe-sec-fields" class="ss-wa-nav-item" data-section="ss-boe-sec-fields"><span class="ss-wa-nav-icon">📋</span> <?php esc_html_e( 'Export Fields', 'sheetsync-for-woocommerce' ); ?></a>
                        <a href="#ss-boe-sec-export" class="ss-wa-nav-item" data-section="ss-boe-sec-export"><span class="ss-wa-nav-icon">📥</span> <?php esc_html_e( 'Google Sheet', 'sheetsync-for-woocommerce' ); ?></a>
                    </div>
                </nav>
                <div class="ss-wa-sidebar-footer">
                    <div class="ss-wa-sidebar-user">
                        <div class="ss-wa-user-avatar">📤</div>
                        <div class="ss-wa-user-info">
                            <div class="ss-wa-user-name"><?php echo esc_html( wp_get_current_user()->display_name ); ?></div>
                            <div class="ss-wa-user-role"><?php esc_html_e( 'Order Export', 'sheetsync-for-woocommerce' ); ?></div>
                        </div>
                    </div>
                </div>
            </aside>

            <div class="ss-wa-main">
                <div class="ss-wa-topbar">
                    <button type="button" class="ss-wa-hamburger" id="ss_boe_hamburger" aria-label="<?php esc_attr_e( 'Menu', 'sheetsync-for-woocommerce' ); ?>">
                        <span></span><span></span><span></span>
                    </button>
                    <h2 class="ss-wa-topbar-title"><?php esc_html_e( 'Bulk Order Export', 'sheetsync-for-woocommerce' ); ?></h2>
                    <span class="ss-wa-live"><?php esc_html_e( 'Live', 'sheetsync-for-woocommerce' ); ?></span>
                    <div class="ss-wa-topbar-right">
                        <button type="button" class="ss-wa-btn ss-wa-theme-toggle" id="ss_boe_theme_toggle" title="<?php esc_attr_e( 'Toggle dashboard theme', 'sheetsync-for-woocommerce' ); ?>" aria-label="<?php esc_attr_e( 'Toggle dashboard theme', 'sheetsync-for-woocommerce' ); ?>">
                            <span class="ss-wa-theme-icon ss-wa-theme-icon-dark" aria-hidden="true">🌙</span>
                            <span class="ss-wa-theme-icon ss-wa-theme-icon-light" aria-hidden="true">☀️</span>
                        </button>
                        <button type="button" id="ss_boe_count_btn" class="ss-wa-btn" title="<?php esc_attr_e( 'Count Orders', 'sheetsync-for-woocommerce' ); ?>">
                            <span class="dashicons dashicons-search"></span>
                            <?php esc_html_e( 'Count', 'sheetsync-for-woocommerce' ); ?>
                        </button>
                        <button type="button" id="ss_boe_csv_btn" class="ss-wa-btn">
                            <span class="dashicons dashicons-media-spreadsheet"></span>
                            <?php esc_html_e( 'CSV', 'sheetsync-for-woocommerce' ); ?>
                        </button>
                        <button type="button" id="ss_boe_sheets_btn" class="ss-wa-btn primary">
                            <span class="dashicons dashicons-cloud-upload"></span>
                            <?php esc_html_e( 'Export to Sheet', 'sheetsync-for-woocommerce' ); ?>
                        </button>
                    </div>
                </div>

                <div class="ss-wa-page">
                    <div class="ss-wa-filter-bar" id="ss-boe-sec-filters">
                        <div class="ss-wa-filter-row ss-boe-filter-row">
                            <span class="ss-wa-filter-label"><?php esc_html_e( 'Status', 'sheetsync-for-woocommerce' ); ?></span>
                            <div class="ss-boe-status-grid">
                                <?php foreach ( wc_get_order_statuses() as $sk => $sl ) :
                                    $chk = in_array( $sk, array( 'wc-completed', 'wc-processing', 'wc-on-hold' ), true ); ?>
                                    <label class="ss-boe-status-check">
                                        <input type="checkbox" name="boe_statuses[]" value="<?php echo esc_attr( $sk ); ?>" <?php checked( $chk ); ?> />
                                        <span><?php echo esc_html( $sl ); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="ss-wa-filter-divider"></div>
                        <div class="ss-wa-filter-row">
                            <span class="ss-wa-filter-label"><?php esc_html_e( 'Date Range', 'sheetsync-for-woocommerce' ); ?></span>
                            <div class="ss-boe-date-row">
                                <input type="date" id="ss_boe_date_from" class="ss-wa-filter-input" />
                                <span class="ss-boe-date-sep"><?php esc_html_e( 'to', 'sheetsync-for-woocommerce' ); ?></span>
                                <input type="date" id="ss_boe_date_to" class="ss-wa-filter-input" />
                            </div>
                        </div>
                        <div class="ss-wa-filter-divider"></div>
                        <div class="ss-wa-filter-row">
                            <span class="ss-wa-filter-label"><?php esc_html_e( 'Customer', 'sheetsync-for-woocommerce' ); ?></span>
                            <input type="email" id="ss_boe_customer" class="regular-text ss-boe-customer-input" placeholder="<?php esc_attr_e( 'Filter by email (optional)', 'sheetsync-for-woocommerce' ); ?>" />
                        </div>
                        <div class="ss-wa-filter-divider"></div>
                        <div class="ss-wa-filter-row" id="ss_boe_preset_row">
                            <span class="ss-wa-filter-label"><?php esc_html_e( 'Presets', 'sheetsync-for-woocommerce' ); ?></span>
                            <div class="ss-boe-preset-row">
                                <select id="ss_boe_template_select" class="ss-wa-filter-input"><option value=""><?php esc_html_e( 'Quick template…', 'sheetsync-for-woocommerce' ); ?></option></select>
                                <select id="ss_boe_preset_select" class="ss-wa-filter-input"><option value=""><?php esc_html_e( 'Saved preset…', 'sheetsync-for-woocommerce' ); ?></option></select>
                                <button type="button" class="ss-wa-btn" id="ss_boe_save_preset"><?php esc_html_e( 'Save', 'sheetsync-for-woocommerce' ); ?></button>
                                <button type="button" class="ss-wa-btn" id="ss_boe_delete_preset"><?php esc_html_e( 'Delete', 'sheetsync-for-woocommerce' ); ?></button>
                            </div>
                        </div>
                        <div class="ss-wa-filter-divider"></div>
                        <div class="ss-wa-filter-row" id="ss_boe_field_picker_row">
                            <span class="ss-wa-filter-label"><?php esc_html_e( 'Columns', 'sheetsync-for-woocommerce' ); ?></span>
                            <div id="ss_boe_field_picker" class="ss-boe-field-picker"></div>
                        </div>
                        <div class="ss-wa-filter-divider"></div>
                        <div class="ss-wa-filter-row">
                            <span class="ss-wa-filter-label"><?php esc_html_e( 'Order Total', 'sheetsync-for-woocommerce' ); ?></span>
                            <div class="ss-boe-date-row">
                                <input type="number" id="ss_boe_min_total" class="ss-wa-filter-input small" placeholder="<?php esc_attr_e( 'Min', 'sheetsync-for-woocommerce' ); ?>" min="0" step="0.01" />
                                <span class="ss-boe-date-sep"><?php esc_html_e( 'to', 'sheetsync-for-woocommerce' ); ?></span>
                                <input type="number" id="ss_boe_max_total" class="ss-wa-filter-input small" placeholder="<?php esc_attr_e( 'Max', 'sheetsync-for-woocommerce' ); ?>" min="0" step="0.01" />
                            </div>
                            <div class="ss-wa-filter-actions">
                                <button type="button" class="ss-wa-btn" id="ss_boe_filter_reset"><?php esc_html_e( 'Reset', 'sheetsync-for-woocommerce' ); ?></button>
                                <button type="button" class="ss-wa-btn primary" id="ss_boe_filter_apply"><?php esc_html_e( 'Apply Filters', 'sheetsync-for-woocommerce' ); ?></button>
                            </div>
                        </div>
                        <div class="ss-wa-filter-divider"></div>
                        <div class="ss-wa-filter-row" id="ss-boe-sec-export">
                            <span class="ss-wa-filter-label"><?php esc_html_e( 'Google Sheet', 'sheetsync-for-woocommerce' ); ?></span>
                            <div class="ss-wa-export-row" style="flex:1;">
                                <div class="ss-wa-field">
                                    <label for="ss_boe_spreadsheet_id"><?php esc_html_e( 'Spreadsheet ID', 'sheetsync-for-woocommerce' ); ?></label>
                                    <input type="text" id="ss_boe_spreadsheet_id" class="regular-text" placeholder="1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgVE2upms" />
                                </div>
                                <div class="ss-wa-field">
                                    <label for="ss_boe_sheet_name"><?php esc_html_e( 'Sheet Tab', 'sheetsync-for-woocommerce' ); ?></label>
                                    <input type="text" id="ss_boe_sheet_name" class="regular-text" value="Orders Export" />
                                </div>
                            </div>
                        </div>
                        <div id="ss_boe_result" class="ss-dash-result" style="display:none;"></div>
                    </div>

                    <div id="ss_boe_dashboard_root">
                        <div class="ss-wa-loading">
                            <span class="dashicons dashicons-update ss-spin"></span>
                            <p><?php esc_html_e( 'Loading order export preview…', 'sheetsync-for-woocommerce' ); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="ss-wa-sidebar-overlay" id="ss_boe_overlay"></div>
    </div>

</div><!-- .sheetsync-wrap -->

<script type="text/javascript">
jQuery(function($){
    'use strict';
    var AJAX = (typeof sheetsync !== 'undefined') ? sheetsync.ajax_url : (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
    var NONCE = (typeof sheetsync !== 'undefined') ? sheetsync.nonce : '';

    // ── Load saved settings on page open ────────────────────────────────────
    function setCheckbox($el, val){
        $el.prop('checked', val === '1' || val === true);
    }
    function renderScheduleStatus(status){
        if(!status) return;
        var map = {
            sales: '#ss_schedule_hint_sales',
            inventory: '#ss_schedule_hint_inv',
            orders: '#ss_schedule_hint_boe'
        };
        Object.keys(map).forEach(function(key){
            var row = status[key] || {};
            var $hint = $(map[key]);
            var $item = $hint.closest('.ss-dash-schedule-item');
            if(!$hint.length) return;
            if(!row.enabled){
                $hint.text('').removeClass('is-active');
                $item.removeClass('is-enabled');
                return;
            }
            var txt = row.next_run
                ? ('<?php echo esc_js( __( 'Next:', 'sheetsync-for-woocommerce' ) ); ?> ' + row.next_run)
                : '<?php echo esc_js( __( 'Queued', 'sheetsync-for-woocommerce' ) ); ?>';
            $hint.text(txt).addClass('is-active');
            $item.addClass('is-enabled');
        });
        syncScheduleItemVisuals();
        var $cron = $('#ss_dash_cron_note');
        if(!$cron.length) return;
        var html = '';
        if(status.scheduler === 'action_scheduler'){
            html = '<?php echo esc_js( __( 'Using WooCommerce Action Scheduler — reliable background exports for your customers.', 'sheetsync-for-woocommerce' ) ); ?>';
            $cron.removeClass('is-warning');
        } else if(status.wp_cron_enabled === false){
            html = '<?php echo esc_js( __( 'WP-Cron is disabled on this server. Add a system cron job so scheduled exports run on time:', 'sheetsync-for-woocommerce' ) ); ?>';
            if(status.cron_command){
                html += ' <code class="ss-dash-cron-cmd">' + status.cron_command + '</code>';
                html += ' <button type="button" class="ss-dash-cron-copy" data-copy="' + status.cron_command.replace(/"/g, '&quot;') + '"><?php echo esc_js( __( 'Copy', 'sheetsync-for-woocommerce' ) ); ?></button>';
            }
            $cron.addClass('is-warning');
        } else {
            html = '<?php echo esc_js( __( 'Using WP-Cron. For production stores, we recommend WooCommerce (Action Scheduler) or a server cron job.', 'sheetsync-for-woocommerce' ) ); ?>';
            $cron.removeClass('is-warning');
        }
        $cron.html(html).removeAttr('hidden');
    }
    function syncLastSyncedStyles(){
        $('.ss-dash-sync-val').each(function(){
            var $el = $(this);
            var isNever = !$el.text() || $el.text().toLowerCase() === 'never';
            $el.toggleClass('is-never', isNever);
        });
    }
    function syncPwaLink(){
        var on = $('#ss_pwa_enabled').is(':checked');
        $('#ss_pwa_open_link').toggleClass('is-disabled', !on).attr('aria-disabled', on ? 'false' : 'true');
    }
    function previewBranding(){
        if(typeof window.ssApplyDashboardBranding !== 'function') return;
        window.ssApplyDashboardBranding({
            app_name: $('#ss_wl_app_name').val() || '',
            logo_url: $('#ss_wl_logo_url').val() || '',
            primary_color: $('#ss_wl_primary_color').val() || '#6c63ff',
            accent_color: $('#ss_wl_accent_color').val() || '#22d3a5',
            hide_pro_badge: $('#ss_wl_hide_pro_badge').is(':checked')
        });
    }
    window.ssRefreshLastSynced = function(){
        $.post(AJAX,{action:'sheetsync_load_dashboard_settings',nonce:NONCE}).done(function(r){
            if(!r.success || !r.data) return;
            $('#ss_last_export_sales').text(r.data.last_export_sales || 'Never');
            $('#ss_last_export_inventory').text(r.data.last_export_inventory || 'Never');
            $('#ss_last_export_orders').text(r.data.last_export_orders || 'Never');
            syncLastSyncedStyles();
            renderScheduleStatus(r.data.schedule_status);
        });
    };
    function applySettingsFromServer(s){
        if(!s) return;
        if(s.sd_spreadsheet_id !== undefined) $('#ss_sd_spreadsheet_id').val(s.sd_spreadsheet_id);
        if(s.sd_sheet_name) $('#ss_sd_sheet_name').val(s.sd_sheet_name);
        if(s.sd_period) {
            $('#ss_sd_period_hidden').val(s.sd_period);
            $('#ss_sd_period').val(s.sd_period);
            $('.ss-wa-date-pill').removeClass('active');
            $('.ss-wa-date-pill[data-period="'+s.sd_period+'"]').addClass('active');
            if(typeof window.ssSalesDashReload === 'function') window.ssSalesDashReload(s.sd_period);
        }
        if(s.inv_spreadsheet_id !== undefined) $('#ss_inv_spreadsheet_id').val(s.inv_spreadsheet_id);
        if(s.inv_sheet_name) $('#ss_inv_sheet_name').val(s.inv_sheet_name);
        if(s.inv_low_stock !== undefined && s.inv_low_stock !== '') $('#ss_inv_low_stock').val(s.inv_low_stock);
        if(s.boe_spreadsheet_id !== undefined) $('#ss_boe_spreadsheet_id').val(s.boe_spreadsheet_id);
        if(s.boe_sheet_name) $('#ss_boe_sheet_name').val(s.boe_sheet_name);
        if(s.sd_schedule_enabled !== undefined) setCheckbox($('#ss_sd_schedule_enabled'), s.sd_schedule_enabled);
        if(s.sd_schedule_interval) $('#ss_sd_schedule_interval').val(s.sd_schedule_interval);
        if(s.inv_schedule_enabled !== undefined) setCheckbox($('#ss_inv_schedule_enabled'), s.inv_schedule_enabled);
        if(s.inv_schedule_interval) $('#ss_inv_schedule_interval').val(s.inv_schedule_interval);
        if(s.boe_schedule_enabled !== undefined) setCheckbox($('#ss_boe_schedule_enabled'), s.boe_schedule_enabled);
        if(s.boe_schedule_interval) $('#ss_boe_schedule_interval').val(s.boe_schedule_interval);
        if(s.boe_schedule_preset_id !== undefined) $('#ss_boe_schedule_preset').val(s.boe_schedule_preset_id);
        if(s.export_notify_email !== undefined) $('#ss_export_notify_email').val(s.export_notify_email);
        if(s.inv_email_low_stock !== undefined) setCheckbox($('#ss_inv_email_low_stock'), s.inv_email_low_stock);
        if(s.sd_monthly_goal !== undefined) $('#ss_sd_monthly_goal').val(s.sd_monthly_goal);
        if(s.access_sales_roles) $('#ss_access_sales_roles').val(s.access_sales_roles);
        if(s.access_inventory_roles) $('#ss_access_inventory_roles').val(s.access_inventory_roles);
        if(s.access_orders_roles) $('#ss_access_orders_roles').val(s.access_orders_roles);
        if(s.webhook_export_enabled !== undefined) setCheckbox($('#ss_webhook_export_enabled'), s.webhook_export_enabled);
        if(s.webhook_export_urls !== undefined) $('#ss_webhook_export_urls').val(s.webhook_export_urls);
        if(s.webhook_export_secret !== undefined) $('#ss_webhook_export_secret').val(s.webhook_export_secret);
        if(s.wl_app_name !== undefined) $('#ss_wl_app_name').val(s.wl_app_name);
        if(s.wl_logo_url !== undefined) $('#ss_wl_logo_url').val(s.wl_logo_url);
        if(s.wl_primary_color) $('#ss_wl_primary_color').val(s.wl_primary_color);
        if(s.wl_accent_color) $('#ss_wl_accent_color').val(s.wl_accent_color);
        if(s.wl_hide_pro_badge !== undefined) setCheckbox($('#ss_wl_hide_pro_badge'), s.wl_hide_pro_badge);
        if(s.multistore_enabled !== undefined) setCheckbox($('#ss_multistore_enabled'), s.multistore_enabled);
        if(s.multistore_site_ids !== undefined) $('#ss_multistore_site_ids').val(s.multistore_site_ids);
        if(s.cogs_enabled !== undefined) setCheckbox($('#ss_cogs_enabled'), s.cogs_enabled);
        if(s.pwa_enabled !== undefined) setCheckbox($('#ss_pwa_enabled'), s.pwa_enabled);
        setCheckbox($('#ss_demo_mode_toggle'), s.demo_mode);
        $('#ss_dash_demo_banner').toggle(s.demo_mode === '1');
        $('#ss_last_export_sales').text(s.last_export_sales || 'Never');
        $('#ss_last_export_inventory').text(s.last_export_inventory || 'Never');
        $('#ss_last_export_orders').text(s.last_export_orders || 'Never');
        syncLastSyncedStyles();
        renderScheduleStatus(s.schedule_status);
        showAutomationNotices(s.notices || []);
        syncPwaLink();
        if(typeof window.ssDashEnhApplySettings === 'function') window.ssDashEnhApplySettings(s);
        previewBranding();
    }
    function loadSavedSettings(){
        $.post(AJAX,{action:'sheetsync_load_dashboard_settings',nonce:NONCE}).done(function(r){
            if(!r.success) return;
            applySettingsFromServer(r.data);
        });
    }
    loadSavedSettings();

    // ── Auto-save settings when any field changes ────────────────────────────
    var saveTimer;
    var saveStatusTimer;
    function showAutomationSaveStatus(state, message){
        var $status = $('#ss_dash_automation_save_status');
        $status.removeClass('is-saving is-saved is-error').addClass('is-' + state).text(message || '');
        clearTimeout(saveStatusTimer);
        if(state === 'saved'){
            saveStatusTimer = setTimeout(function(){ $status.removeClass('is-saved').text(''); }, 2800);
        }
    }
    function showAutomationNotices(notices){
        var $box = $('#ss_dash_automation_notices');
        if(!notices || !notices.length){
            $box.attr('hidden', true).empty();
            return;
        }
        var html = '<ul class="ss-dash-automation-notice-list">';
        notices.forEach(function(n){ html += '<li>' + $('<div>').text(n).html() + '</li>'; });
        html += '</ul>';
        $box.html(html).removeAttr('hidden');
    }
    window.ssScheduleDashboardSave = function scheduleSettingsSave(){
        clearTimeout(saveTimer);
        showAutomationSaveStatus('saving', '<?php echo esc_js( __( 'Saving…', 'sheetsync-for-woocommerce' ) ); ?>');
        saveTimer = setTimeout(function(){
            var period = $('#ss_sd_period').val() || $('#ss_sd_period_hidden').val();
            $.post(AJAX,{
                action:'sheetsync_save_dashboard_settings',
                nonce:NONCE,
                'settings[sd_spreadsheet_id]':  $('#ss_sd_spreadsheet_id').val() || '',
                'settings[sd_sheet_name]':       $('#ss_sd_sheet_name').val() || 'Sales Dashboard',
                'settings[sd_period]':           period || '6months',
                'settings[inv_spreadsheet_id]':  $('#ss_inv_spreadsheet_id').val(),
                'settings[inv_sheet_name]':      $('#ss_inv_sheet_name').val(),
                'settings[inv_low_stock]':       $('#ss_inv_low_stock').val(),
                'settings[boe_spreadsheet_id]':  $('#ss_boe_spreadsheet_id').val(),
                'settings[boe_sheet_name]':      $('#ss_boe_sheet_name').val(),
                'settings[sd_schedule_enabled]':   $('#ss_sd_schedule_enabled').is(':checked') ? '1' : '0',
                'settings[sd_schedule_interval]':  $('#ss_sd_schedule_interval').val(),
                'settings[inv_schedule_enabled]':  $('#ss_inv_schedule_enabled').is(':checked') ? '1' : '0',
                'settings[inv_schedule_interval]': $('#ss_inv_schedule_interval').val(),
                'settings[boe_schedule_enabled]':  $('#ss_boe_schedule_enabled').is(':checked') ? '1' : '0',
                'settings[boe_schedule_interval]': $('#ss_boe_schedule_interval').val(),
                'settings[boe_schedule_preset_id]': $('#ss_boe_schedule_preset').val() || '',
                'settings[export_notify_email]':   $('#ss_export_notify_email').val(),
                'settings[inv_email_low_stock]':   $('#ss_inv_email_low_stock').is(':checked') ? '1' : '0',
                'settings[sd_monthly_goal]':       $('#ss_sd_monthly_goal').val(),
                'settings[demo_mode]':             $('#ss_demo_mode_toggle').is(':checked') ? '1' : '0',
                'settings[access_sales_roles]':     $('#ss_access_sales_roles').val(),
                'settings[access_inventory_roles]': $('#ss_access_inventory_roles').val(),
                'settings[access_orders_roles]':    $('#ss_access_orders_roles').val(),
                'settings[webhook_export_enabled]': $('#ss_webhook_export_enabled').is(':checked') ? '1' : '0',
                'settings[webhook_export_urls]':    $('#ss_webhook_export_urls').val(),
                'settings[webhook_export_secret]':  $('#ss_webhook_export_secret').val(),
                'settings[wl_app_name]':            $('#ss_wl_app_name').val(),
                'settings[wl_logo_url]':            $('#ss_wl_logo_url').val(),
                'settings[wl_primary_color]':       $('#ss_wl_primary_color').val(),
                'settings[wl_accent_color]':        $('#ss_wl_accent_color').val(),
                'settings[wl_hide_pro_badge]':     $('#ss_wl_hide_pro_badge').is(':checked') ? '1' : '0',
                'settings[multistore_enabled]':     $('#ss_multistore_enabled').is(':checked') ? '1' : '0',
                'settings[multistore_site_ids]':    $('#ss_multistore_site_ids').val(),
                'settings[cogs_enabled]':           $('#ss_cogs_enabled').is(':checked') ? '1' : '0',
                'settings[pwa_enabled]':            $('#ss_pwa_enabled').is(':checked') ? '1' : '0'
            }).done(function(r){
                if(r.success && r.data){
                    applySettingsFromServer(r.data);
                    showAutomationSaveStatus('saved', '<?php echo esc_js( __( 'Saved', 'sheetsync-for-woocommerce' ) ); ?>');
                    if(typeof window.ssSalesDashReload === 'function'){
                        window.ssSalesDashReload($('#ss_sd_period_hidden').val() || '7days', true);
                    }
                } else {
                    showAutomationSaveStatus('error', '<?php echo esc_js( __( 'Save failed', 'sheetsync-for-woocommerce' ) ); ?>');
                }
            }).fail(function(){
                showAutomationSaveStatus('error', '<?php echo esc_js( __( 'Save failed', 'sheetsync-for-woocommerce' ) ); ?>');
            });
        }, 800);
    };
    $('#ss_sd_spreadsheet_id,#ss_sd_sheet_name,#ss_sd_period,#ss_inv_spreadsheet_id,#ss_inv_sheet_name,#ss_inv_low_stock,#ss_boe_spreadsheet_id,#ss_boe_sheet_name,#ss_sd_schedule_enabled,#ss_sd_schedule_interval,#ss_inv_schedule_enabled,#ss_inv_schedule_interval,#ss_boe_schedule_enabled,#ss_boe_schedule_interval,#ss_boe_schedule_preset,#ss_export_notify_email,#ss_inv_email_low_stock,#ss_sd_monthly_goal,#ss_access_sales_roles,#ss_access_inventory_roles,#ss_access_orders_roles,#ss_webhook_export_enabled,#ss_webhook_export_urls,#ss_webhook_export_secret,#ss_wl_app_name,#ss_wl_logo_url,#ss_wl_primary_color,#ss_wl_accent_color,#ss_wl_hide_pro_badge,#ss_multistore_enabled,#ss_multistore_site_ids,#ss_cogs_enabled,#ss_pwa_enabled').on('input change', window.ssScheduleDashboardSave);

    $('#ss_wl_app_name,#ss_wl_logo_url,#ss_wl_primary_color,#ss_wl_accent_color,#ss_wl_hide_pro_badge').on('input change', previewBranding);
    $('#ss_pwa_enabled').on('change', syncPwaLink);

    function syncScheduleItemVisuals(){
        $('.ss-dash-schedule-toggle').each(function(){
            var $btn = $(this);
            var $cb = $($btn.data('target'));
            if(!$cb.length) return;
            var on = $cb.is(':checked');
            $btn.attr('aria-pressed', on ? 'true' : 'false').toggleClass('is-on', on);
            $btn.find('.ss-dash-schedule-toggle-text').text(on
                ? '<?php echo esc_js( __( 'On', 'sheetsync-for-woocommerce' ) ); ?>'
                : '<?php echo esc_js( __( 'Off', 'sheetsync-for-woocommerce' ) ); ?>');
            $btn.closest('.ss-dash-schedule-item').toggleClass('is-enabled', on);
        });
    }
    $(document).on('click', '.ss-dash-schedule-toggle', function(e){
        e.preventDefault();
        var $cb = $($(this).data('target'));
        if(!$cb.length) return;
        $cb.prop('checked', !$cb.is(':checked')).trigger('change');
    });
    $(document).on('change', '#ss_sd_schedule_enabled,#ss_inv_schedule_enabled,#ss_boe_schedule_enabled', syncScheduleItemVisuals);
    $(document).on('click', '.ss-dash-cron-copy', function(){
        var cmd = $(this).data('copy') || '';
        if(!cmd) return;
        if(navigator.clipboard && navigator.clipboard.writeText){
            navigator.clipboard.writeText(cmd);
        } else {
            var $tmp = $('<textarea>').val(cmd).appendTo('body').select();
            document.execCommand('copy');
            $tmp.remove();
        }
        $(this).text('<?php echo esc_js( __( 'Copied!', 'sheetsync-for-woocommerce' ) ); ?>');
        var $btn = $(this);
        setTimeout(function(){ $btn.text('<?php echo esc_js( __( 'Copy', 'sheetsync-for-woocommerce' ) ); ?>'); }, 2000);
    });

    $(document).on('click', '#ss_webhook_test_btn', function(){
        var $btn = $(this);
        var $status = $('#ss_webhook_test_status');
        $btn.prop('disabled', true);
        $status.text('<?php echo esc_js( __( 'Sending test…', 'sheetsync-for-woocommerce' ) ); ?>').removeClass('is-error is-ok');
        if(typeof window.ssScheduleDashboardSave === 'function'){
            window.ssScheduleDashboardSave();
        }
        setTimeout(function(){
            $.post(AJAX, { action: 'sheetsync_test_export_webhook', nonce: NONCE })
                .done(function(r){
                    if(r.success){
                        $status.addClass('is-ok').text(r.data.message || '<?php echo esc_js( __( 'Webhook test sent.', 'sheetsync-for-woocommerce' ) ); ?>');
                    } else {
                        $status.addClass('is-error').text((r.data && r.data.message) ? r.data.message : '<?php echo esc_js( __( 'Webhook test failed.', 'sheetsync-for-woocommerce' ) ); ?>');
                    }
                })
                .fail(function(){
                    $status.addClass('is-error').text('<?php echo esc_js( __( 'Webhook test failed.', 'sheetsync-for-woocommerce' ) ); ?>');
                })
                .always(function(){ $btn.prop('disabled', false); });
        }, 900);
    });

    // Tab switching
    $(document).on('click', '.ss-dash-tab-btn', function(){
        var t = $(this).data('target');
        $('.ss-dash-tab-btn').removeClass('ss-dash-tab-active');
        $(this).addClass('ss-dash-tab-active');
        $('.ss-dash-panel').hide();
        $('#' + t).show();
        if (t === 'ss-dash-panel-inventory' && typeof window.ssInvDashLoad === 'function') {
            window.ssInvDashLoad(false);
        }
        if (t === 'ss-dash-panel-orders' && typeof window.ssBoeDashLoad === 'function') {
            window.ssBoeDashLoad(false);
        }
    });

    function fmtMoney(v){ return '$' + (parseFloat(v)||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    function showRes($el,html,type){ $el.attr('class','ss-dash-result ss-res-'+type).html(html).show(); }
    function btnLoad($b,on){
        if(on){ $b.data('oh',$b.html()).prop('disabled',true).html('<span class="dashicons dashicons-update ss-spin"></span> Loading&hellip;'); }
        else { $b.prop('disabled',false).html($b.data('oh')||$b.html()); }
    }

    // SALES DASHBOARD — handled by sales-dashboard.js
    // INVENTORY DASHBOARD — handled by inventory-dashboard.js
    // BULK ORDER EXPORT — handled by bulk-order-export.js
});
</script>
