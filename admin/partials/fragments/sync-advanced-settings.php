<?php
/**
 * Advanced sync settings — strategy, automation, power-user actions.
 *
 * Included inside #ss-sync-advanced-settings on the Sync tab.
 */
defined( 'ABSPATH' ) || exit;

if ( ! $sheetsync_is_orders_conn ) :
?>
<details class="ss-sync-advanced-actions">
    <summary><?php esc_html_e( 'Advanced sync actions', 'sheetsync-for-woocommerce' ); ?></summary>
    <div class="ss-sync-advanced-inner">
        <?php require SHEETSYNC_PRO_DIR . 'admin/partials/fragments/sync-advanced-actions.php'; ?>
    </div>
</details>
<?php endif; ?>

<section class="ss-advanced-section" id="ss-sync-strategy-panel">
            <h3 class="ss-advanced-section-title">
                <span class="dashicons dashicons-performance" style="color:var(--ss-green);"></span>
                <?php esc_html_e( 'Sync strategy', 'sheetsync-for-woocommerce' ); ?>
            </h3>
            <p class="description">
                <?php if ( $sheetsync_is_orders_conn ) : ?>
                    <?php esc_html_e( 'Order connections sync automatically when orders are placed or change status in WooCommerce. Use Sync now for a full refresh.', 'sheetsync-for-woocommerce' ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'Smart Diff is the default for Sync now above. Change strategy only if you need a full re-sync or conflict rules.', 'sheetsync-for-woocommerce' ); ?>
                <?php endif; ?>
            </p>

            <form id="ss-sync-options-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'sheetsync_save_sync_options' ); ?>
                <input type="hidden" name="action" value="sheetsync_save_sync_options">
                <input type="hidden" name="connection_id" value="<?php echo esc_attr( $sheetsync_conn_id ); ?>">

                <div class="ss-strategy-cards">

                    <!-- Smart Diff -->
                    <label class="ss-strategy-card <?php echo $sheetsync_strategy === 'smart' ? 'selected' : ''; ?>">
                        <input type="radio" name="sync_strategy" value="smart" <?php checked( $sheetsync_strategy, 'smart' ); ?>>
                        <div class="ss-strategy-icon">⚡</div>
                        <div>
                            <strong><?php esc_html_e( 'Smart Diff', 'sheetsync-for-woocommerce' ); ?></strong>
                            <?php
                            $sheetsync_first_sync_smart = ( 'smart' === $sheetsync_strategy ) && (
                                ( 'sheets_to_wc' === $sheetsync_sync_direction && $sheetsync_is_first_import )
                                || ( in_array( $sheetsync_sync_direction, array( 'wc_to_sheets', 'two_way' ), true ) && $sheetsync_is_first_export )
                            );
                            if ( $sheetsync_first_sync_smart ) :
                                ?>
                                <span class="ss-first-sync-badge"><?php esc_html_e( 'First sync (full link)', 'sheetsync-for-woocommerce' ); ?></span>
                            <?php endif; ?>
                            <p class="description" style="margin:4px 0 0;">
                                <?php if ( 'two_way' === $sheetsync_sync_direction ) : ?>
                                    <?php esc_html_e( 'Only syncs rows that changed on either side. Use with “Keep both sides in sync” or the sheet-only / WooCommerce-only intents above.', 'sheetsync-for-woocommerce' ); ?>
                                <?php elseif ( in_array( $sheetsync_sync_direction, array( 'wc_to_sheets', 'two_way' ), true ) ) : ?>
                                    <?php esc_html_e( 'Compares WooCommerce to the sheet, but only pushes products that changed. Best when you edit products in WooCommerce admin.', 'sheetsync-for-woocommerce' ); ?>
                                <?php else : ?>
                                    <?php esc_html_e( 'Compares every sheet row to your store, but only writes rows that changed. Best for day-to-day sheet edits (large catalogs still scan in the background).', 'sheetsync-for-woocommerce' ); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </label>

                    <!-- Full Sync -->
                    <label class="ss-strategy-card <?php echo $sheetsync_strategy === 'full' ? 'selected' : ''; ?>">
                        <input type="radio" name="sync_strategy" value="full" <?php checked( $sheetsync_strategy, 'full' ); ?>>
                        <div class="ss-strategy-icon">🔄</div>
                        <div>
                            <strong><?php esc_html_e( 'Full Sync', 'sheetsync-for-woocommerce' ); ?></strong>
                            <p class="description" style="margin:4px 0 0;">
                                <?php if ( 'sheets_to_wc' === $sheetsync_sync_direction ) : ?>
                                    <?php esc_html_e( 'Updates every sheet row in WooCommerce (slow). For editing one or a few products, use Smart Diff instead.', 'sheetsync-for-woocommerce' ); ?>
                                <?php elseif ( 'two_way' === $sheetsync_sync_direction ) : ?>
                                    <?php esc_html_e( 'First time: exports WooCommerce to the sheet (bootstrap). After that, use Smart Diff for normal two-way updates.', 'sheetsync-for-woocommerce' ); ?>
                                <?php else : ?>
                                    <?php esc_html_e( 'Always overwrite every exportable row (products + variations). Use for the first catalog export.', 'sheetsync-for-woocommerce' ); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </label>

                </div>

                <?php if ( ! $sheetsync_is_orders_conn ) : ?>
                <div style="margin-top:20px; padding-top:16px; border-top:1px solid #e5e7eb;">
                    <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
                        <input type="checkbox" name="require_sku" value="1"
                               <?php checked( $sheetsync_require_sku ); ?>
                               style="margin-top:3px; width:16px; height:16px; accent-color:var(--ss-green);">
                        <div>
                            <strong><?php esc_html_e( 'Require SKU on import (recommended)', 'sheetsync-for-woocommerce' ); ?></strong>
                            <p class="description" style="margin:2px 0 0;">
                                <?php esc_html_e( 'Sheet → WooCommerce rows without a SKU are skipped. Prevents duplicate products and makes two-way sync reliable.', 'sheetsync-for-woocommerce' ); ?>
                            </p>
                        </div>
                    </label>
                </div>
                <?php endif; ?>

                <?php if ( ! $sheetsync_is_orders_conn && 'two_way' === $sheetsync_sync_direction ) : ?>
                <div style="margin-top:20px; padding-top:16px; border-top:1px solid #e5e7eb;" id="ss-conflict-policy-settings">
                    <strong><?php esc_html_e( 'When both sides changed the same row', 'sheetsync-for-woocommerce' ); ?></strong>
                    <p class="description" style="margin:4px 0 8px;">
                        <?php esc_html_e( 'Only applies during Two-Way Sync when the same product was edited in Google Sheets and WooCommerce since the last successful sync.', 'sheetsync-for-woocommerce' ); ?>
                    </p>
                    <select name="on_conflict" id="ss-on-conflict-select" style="max-width:420px;">
                        <option value="merge" <?php selected( $sheetsync_on_conflict, 'merge' ); ?>><?php esc_html_e( 'Merge by field rules (recommended)', 'sheetsync-for-woocommerce' ); ?></option>
                        <option value="queue" <?php selected( $sheetsync_on_conflict, 'queue' ); ?>><?php esc_html_e( 'Queue for manual review', 'sheetsync-for-woocommerce' ); ?></option>
                        <option value="sheet" <?php selected( $sheetsync_on_conflict, 'sheet' ); ?>><?php esc_html_e( 'Google Sheet wins (full row)', 'sheetsync-for-woocommerce' ); ?></option>
                        <option value="wc" <?php selected( $sheetsync_on_conflict, 'wc' ); ?>><?php esc_html_e( 'WooCommerce wins (skip sheet pull)', 'sheetsync-for-woocommerce' ); ?></option>
                    </select>

                    <div id="ss-merge-policy-details" class="ss-merge-policy-details" style="margin-top:12px;<?php echo 'merge' !== $sheetsync_on_conflict ? ' display:none;' : ''; ?>">
                        <p class="description" style="margin:0 0 8px;">
                            <?php esc_html_e( 'Default merge rules (automatic — no setup required):', 'sheetsync-for-woocommerce' ); ?>
                        </p>
                        <table class="widefat striped ss-merge-policy-table" style="max-width:520px;">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Field', 'sheetsync-for-woocommerce' ); ?></th>
                                    <th><?php esc_html_e( 'Winner when both changed', 'sheetsync-for-woocommerce' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $sheetsync_policy_rows = array(
                                    '_stock'         => array(
                                        'label'  => __( 'Stock quantity', 'sheetsync-for-woocommerce' ),
                                        'policy' => (string) ( $sheetsync_field_policies['_stock'] ?? 'sheet' ),
                                    ),
                                    '_regular_price' => array(
                                        'label'  => __( 'Regular price', 'sheetsync-for-woocommerce' ),
                                        'policy' => (string) ( $sheetsync_field_policies['_regular_price'] ?? 'sheet' ),
                                    ),
                                    '_sale_price'    => array(
                                        'label'  => __( 'Sale price', 'sheetsync-for-woocommerce' ),
                                        'policy' => (string) ( $sheetsync_field_policies['_sale_price'] ?? 'sheet' ),
                                    ),
                                    'post_title'     => array(
                                        'label'  => __( 'Product title', 'sheetsync-for-woocommerce' ),
                                        'policy' => (string) ( $sheetsync_field_policies['post_title'] ?? 'wc' ),
                                    ),
                                    '_product_image' => array(
                                        'label'  => __( 'Featured image', 'sheetsync-for-woocommerce' ),
                                        'policy' => (string) ( $sheetsync_field_policies['_product_image'] ?? 'wc' ),
                                    ),
                                );
                                $sheetsync_policy_winner_labels = array(
                                    'sheet'  => __( 'Sheet', 'sheetsync-for-woocommerce' ),
                                    'wc'     => __( 'WooCommerce', 'sheetsync-for-woocommerce' ),
                                    'newest' => __( 'Newest edit', 'sheetsync-for-woocommerce' ),
                                );
                                foreach ( $sheetsync_policy_rows as $sheetsync_policy_row ) :
                                    $sheetsync_winner_key = $sheetsync_policy_row['policy'];
                                    ?>
                                <tr>
                                    <td><?php echo esc_html( $sheetsync_policy_row['label'] ); ?></td>
                                    <td><?php echo esc_html( $sheetsync_policy_winner_labels[ $sheetsync_winner_key ] ?? $sheetsync_winner_key ); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr>
                                    <td><?php esc_html_e( 'All other mapped fields', 'sheetsync-for-woocommerce' ); ?></td>
                                    <td><?php esc_html_e( 'Newest edit', 'sheetsync-for-woocommerce' ); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <details style="margin-top:16px;">
                        <summary style="cursor:pointer;font-weight:600;">
                            <?php esc_html_e( 'Advanced: sync phase order', 'sheetsync-for-woocommerce' ); ?>
                        </summary>
                        <p class="description" style="margin:8px 0 12px;">
                            <?php esc_html_e( 'Only applies to Two-Way Sync (not sheet-only or push-only buttons).', 'sheetsync-for-woocommerce' ); ?>
                        </p>
                        <label style="display:block; margin-bottom:8px;">
                            <input type="radio" name="two_way_phase_order" value="pull_push" <?php checked( $sheetsync_phase_order, 'pull_push' ); ?>>
                            <?php esc_html_e( 'Sheet first, then WooCommerce (recommended for spreadsheet editors)', 'sheetsync-for-woocommerce' ); ?>
                        </label>
                        <label style="display:block; margin-bottom:12px;">
                            <input type="radio" name="two_way_phase_order" value="push_pull" <?php checked( $sheetsync_phase_order, 'push_pull' ); ?>>
                            <?php esc_html_e( 'WooCommerce first, then sheet (recommended if you edit in WP admin)', 'sheetsync-for-woocommerce' ); ?>
                        </label>
                    </details>
                </div>
                <?php endif; ?>

                <p style="margin-top:16px;">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e( 'Save Options', 'sheetsync-for-woocommerce' ); ?>
                    </button>
                </p>
            </form>
        </section>

        <section class="ss-advanced-section ss-automatic-sync-options-section">
            <h3 class="ss-advanced-section-title">
                <span class="dashicons dashicons-admin-generic" style="color:var(--ss-green);"></span>
                <?php esc_html_e( 'Automatic sync options', 'sheetsync-for-woocommerce' ); ?>
            </h3>
            <p class="description">
                <?php esc_html_e( 'Fine-tune schedule and optional instant webhooks. The main Automatic sync toggle above enables the right methods for your direction.', 'sheetsync-for-woocommerce' ); ?>
            </p>

            <?php if ( $sheetsync_is_pro ) : ?>
            <details class="ss-auto-sync-panel ss-schedule-options-panel" <?php echo $sheetsync_schedule ? 'open' : ''; ?>>
                <summary><strong><?php esc_html_e( 'Schedule interval', 'sheetsync-for-woocommerce' ); ?></strong></summary>
                <div class="ss-auto-sync-panel-body">
                <p class="description">
                    <?php esc_html_e( 'Run a full smart sync on a recurring schedule (in addition to Smart Poll and product-save push).', 'sheetsync-for-woocommerce' ); ?>
                </p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'sheetsync_save_schedule' ); ?>
                    <input type="hidden" name="action" value="sheetsync_save_schedule">
                    <input type="hidden" name="connection_id" value="<?php echo esc_attr( $sheetsync_conn_id ); ?>">

                    <div class="ss-schedule-grid">
                        <?php
                        $sheetsync_schedules = array(
                            ''                 => array( 'label' => __( 'Disabled', 'sheetsync-for-woocommerce' ),       'icon' => '🚫' ),
                            'sheetsync_15min'  => array( 'label' => __( 'Every 15 min', 'sheetsync-for-woocommerce' ),   'icon' => '⚡' ),
                            'sheetsync_30min'  => array( 'label' => __( 'Every 30 min', 'sheetsync-for-woocommerce' ),   'icon' => '🕐' ),
                            'sheetsync_1hour'  => array( 'label' => __( 'Every hour', 'sheetsync-for-woocommerce' ),     'icon' => '🕑' ),
                            'twicedaily'       => array( 'label' => __( 'Twice daily', 'sheetsync-for-woocommerce' ),    'icon' => '📅' ),
                            'daily'            => array( 'label' => __( 'Once daily', 'sheetsync-for-woocommerce' ),     'icon' => '📆' ),
                        );
                        foreach ( $sheetsync_schedules as $sheetsync_val => $sheetsync_s ) : ?>
                            <label class="ss-schedule-option <?php echo $sheetsync_schedule === $sheetsync_val ? 'selected' : ''; ?>">
                                <input type="radio" name="sync_interval" value="<?php echo esc_attr( $sheetsync_val ); ?>"
                                       <?php checked( $sheetsync_schedule, $sheetsync_val ); ?>>
                                <span class="ss-schedule-icon"><?php echo $sheetsync_s['icon']; ?></span>
                                <span><?php echo esc_html( $sheetsync_s['label'] ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <?php if ( $sheetsync_next_run ) : ?>
                        <p class="description" style="margin-top:12px; color:#059669;">
                            <span class="dashicons dashicons-clock"></span>
                            <?php printf( esc_html__( 'Next run: %s', 'sheetsync-for-woocommerce' ), esc_html( $sheetsync_next_run ) ); ?>
                        </p>
                    <?php endif; ?>

                    <?php if ( $sheetsync_needs_scheduler_gate && $sheetsync_large_catalog ) : ?>
                    <div class="notice notice-info inline ss-schedule-night-hint" style="margin-top:12px;padding:10px 12px;">
                        <p style="margin:0;">
                            <?php esc_html_e( 'For large catalogs, use Off-peak full publish on the Sync tab — schedule a background full export during quiet hours instead of running it in your browser.', 'sheetsync-for-woocommerce' ); ?>
                        </p>
                    </div>
                    <?php endif; ?>

                    <p style="margin-top:16px;">
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e( 'Save Schedule', 'sheetsync-for-woocommerce' ); ?>
                        </button>
                        <?php if ( $sheetsync_schedule ) : ?>
                            <span style="margin-left:10px; color:#059669; font-weight:600;">
                                ✅ <?php esc_html_e( 'Active', 'sheetsync-for-woocommerce' ); ?>
                            </span>
                        <?php endif; ?>
                    </p>
                </form>
                </div>
            </details>

            <?php if ( sheetsync_connection_pulls_from_sheet( $connection ?? null ) ) : ?>
            <details class="ss-auto-sync-panel ss-realtime-panel" <?php echo $sheetsync_automatic_sync_enabled ? 'open' : ''; ?>>
                <summary><strong><?php esc_html_e( 'Instant sheet updates (optional Apps Script)', 'sheetsync-for-woocommerce' ); ?></strong></summary>
                <div class="ss-auto-sync-panel-body ss-realtime-card">

                <?php
                $sheetsync_rt_status = class_exists( 'SheetSync_Webhook_Handler', false )
                    ? SheetSync_Webhook_Handler::get_realtime_status( $sheetsync_conn_id )
                    : array( 'enabled' => false, 'verified' => false, 'label' => '' );
                $sheetsync_webhook_endpoint = rest_url( 'sheetsync/v1/webhook' );
                if ( $sheetsync_is_orders_conn && class_exists( 'SheetSync_Order_Sheet_Poller', false ) ) {
                    $sheetsync_poll_interval = SheetSync_Order_Sheet_Poller::get_interval_label();
                } elseif ( class_exists( 'SheetSync_Product_Sheet_Poller', false ) ) {
                    $sheetsync_poll_interval = SheetSync_Product_Sheet_Poller::get_interval_label();
                } else {
                    $sheetsync_poll_interval = __( 'every 3 minutes', 'sheetsync-for-woocommerce' );
                }
                $sheetsync_rt_badge_class = 'off';
                if ( ! empty( $sheetsync_rt_status['enabled'] ) ) {
                    $sheetsync_rt_badge_class = 'verified';
                }
                ?>
                <p class="ss-realtime-status-badge ss-rt-<?php echo esc_attr( $sheetsync_rt_badge_class ); ?>">
                    <?php echo esc_html( $sheetsync_rt_status['label'] ?? '' ); ?>
                </p>

                <p class="description">
                    <?php
                    printf(
                        /* translators: %s: poll frequency e.g. every 3 minutes */
                        esc_html__( 'Smart Poll runs automatically when Automatic sync is on (%s). Install Apps Script below only if you want instant webhook updates on hosts that allow them.', 'sheetsync-for-woocommerce' ),
                        esc_html( $sheetsync_poll_interval )
                    );
                    ?>
                </p>

                <div class="ss-realtime-setup-panel" <?php echo $sheetsync_automatic_sync_enabled ? '' : 'style="display:none;"'; ?>>
                    <?php if ( $sheetsync_is_orders_conn ) : ?>
                    <h3><?php esc_html_e( 'Optional: instant sync (Apps Script)', 'sheetsync-for-woocommerce' ); ?></h3>
                    <p class="description"><?php esc_html_e( 'For updates in seconds instead of Smart Poll. May not work if your host blocks Google webhooks.', 'sheetsync-for-woocommerce' ); ?></p>
                    <?php else : ?>
                    <h3><?php esc_html_e( 'Optional: instant sync (Apps Script)', 'sheetsync-for-woocommerce' ); ?></h3>
                    <p class="description"><?php esc_html_e( 'For updates in seconds instead of Smart Poll. May not work if your host blocks Google webhooks.', 'sheetsync-for-woocommerce' ); ?></p>
                    <?php endif; ?>
                    <ol class="ss-realtime-checklist">
                        <li>
                            <?php esc_html_e( 'Copy webhook secret from', 'sheetsync-for-woocommerce' ); ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync-settings' ) ); ?>"><?php esc_html_e( 'SheetSync → Settings', 'sheetsync-for-woocommerce' ); ?></a>
                            <?php esc_html_e( '(already embedded in the script below).', 'sheetsync-for-woocommerce' ); ?>
                        </li>
                        <li><?php esc_html_e( 'Google Sheet → Extensions → Apps Script', 'sheetsync-for-woocommerce' ); ?></li>
                        <li><?php esc_html_e( 'Delete old code → Paste the script below → Save', 'sheetsync-for-woocommerce' ); ?></li>
                        <li><?php esc_html_e( 'Run setupTrigger once (grant permissions)', 'sheetsync-for-woocommerce' ); ?></li>
                        <li><?php esc_html_e( 'Run verifyTriggers() — must show 1 active onSheetEdit trigger', 'sheetsync-for-woocommerce' ); ?></li>
                        <li><?php esc_html_e( 'Debug: select a row → Run testOrderRowSync() in Apps Script', 'sheetsync-for-woocommerce' ); ?></li>
                        <li>
                            <?php esc_html_e( 'Optional: run testWebhook() in Apps Script to verify', 'sheetsync-for-woocommerce' ); ?>
                            —
                            <button type="button" class="button button-small ss-test-webhook-btn"
                                    data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>">
                                <?php esc_html_e( 'Test from WordPress', 'sheetsync-for-woocommerce' ); ?>
                            </button>
                            <span class="ss-webhook-test-result" aria-live="polite"></span>
                        </li>
                    </ol>

                    <?php
                    $sheetsync_apps_script = SheetSync_Webhook_Handler::get_apps_script( $sheetsync_conn_id );
                    ?>
                    <div class="ss-apps-script-box">
                        <p><strong><?php esc_html_e( 'Apps Script (paste into your spreadsheet)', 'sheetsync-for-woocommerce' ); ?></strong></p>
                        <p class="description">
                            <?php esc_html_e( 'Webhook URL:', 'sheetsync-for-woocommerce' ); ?>
                            <code class="ss-webhook-url-inline"><?php echo esc_html( $sheetsync_webhook_endpoint ); ?></code>
                        </p>
                        <textarea class="ss-code-block ss-apps-script-code" readonly rows="16" onclick="this.select()"><?php echo esc_textarea( $sheetsync_apps_script ); ?></textarea>
                        <p style="margin-top:10px;">
                            <button type="button" class="button ss-copy-btn" data-target=".ss-apps-script-code"><?php esc_html_e( 'Copy script', 'sheetsync-for-woocommerce' ); ?></button>
                        </p>
                    </div>
                </div>

                <?php if ( ! $sheetsync_automatic_sync_enabled ) : ?>
                <div class="notice notice-info inline"><p><?php esc_html_e( 'Turn on Automatic sync above to activate Smart Poll and reveal the Apps Script setup.', 'sheetsync-for-woocommerce' ); ?></p></div>
                <?php endif; ?>

                </div>
            </details>
            <?php endif; ?>

            <?php else : ?>
                <?php SheetSync_Admin::render_pro_gate( __( 'Automatic Sync Options', 'sheetsync-for-woocommerce' ) ); ?>
            <?php endif; ?>
        </section>
