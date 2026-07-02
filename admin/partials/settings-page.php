<?php defined( 'ABSPATH' ) || exit;

$sheetsync_past_due  = (int) ( $sheetsync_as_health['past_due'] ?? 0 );
$sheetsync_sched_ok  = ! empty( $sheetsync_as_health['ok'] );
$sheetsync_has_cron  = class_exists( 'SheetSync_External_Cron', false );
$sheetsync_dash_products_per = function_exists( 'sheetsync_dashboard_products_per_page' ) ? sheetsync_dashboard_products_per_page() : 500;
$sheetsync_dash_products_max = function_exists( 'sheetsync_dashboard_products_max_pages' ) ? sheetsync_dashboard_products_max_pages() : 200;
$sheetsync_dash_orders_per   = function_exists( 'sheetsync_dashboard_orders_per_page' ) ? sheetsync_dashboard_orders_per_page() : 500;
$sheetsync_dash_orders_max   = function_exists( 'sheetsync_dashboard_orders_max_pages' ) ? sheetsync_dashboard_orders_max_pages() : 400;
$sheetsync_dash_product_cap  = $sheetsync_dash_products_per * $sheetsync_dash_products_max;
$sheetsync_dash_order_cap   = $sheetsync_dash_orders_per * $sheetsync_dash_orders_max;
?>

<div class="sheetsync-wrap sheetsync-settings-wrap">
    <?php require __DIR__ . '/header.php'; ?>

    <div class="ss-settings-status-strip" role="status" aria-label="<?php esc_attr_e( 'SheetSync status overview', 'sheetsync-for-woocommerce' ); ?>">
        <div class="ss-settings-status-item <?php echo $account_email ? 'is-ok' : 'is-warn'; ?>">
            <span class="ss-settings-status-icon dashicons <?php echo $account_email ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
            <div class="ss-settings-status-text">
                <span class="ss-settings-status-label"><?php esc_html_e( 'Google API', 'sheetsync-for-woocommerce' ); ?></span>
                <span class="ss-settings-status-value">
                    <?php echo $account_email ? esc_html( $account_email ) : esc_html__( 'Not connected', 'sheetsync-for-woocommerce' ); ?>
                </span>
            </div>
        </div>
        <?php if ( ! $sheetsync_sched_ok && $sheetsync_has_cron ) : ?>
        <a href="#tab-settings-cron" class="ss-settings-status-item ss-settings-status-link is-warn">
            <span class="ss-settings-status-icon dashicons dashicons-clock" aria-hidden="true"></span>
            <div class="ss-settings-status-text">
                <span class="ss-settings-status-label"><?php esc_html_e( 'Background jobs', 'sheetsync-for-woocommerce' ); ?></span>
                <span class="ss-settings-status-value">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: %d: overdue task count */
                        __( '%d overdue — set up cron', 'sheetsync-for-woocommerce' ),
                        $sheetsync_past_due
                    ) );
                    ?>
                </span>
            </div>
        </a>
        <?php else : ?>
        <div class="ss-settings-status-item <?php echo $sheetsync_sched_ok ? 'is-ok' : 'is-warn'; ?>">
            <span class="ss-settings-status-icon dashicons <?php echo $sheetsync_sched_ok ? 'dashicons-yes-alt' : 'dashicons-clock'; ?>" aria-hidden="true"></span>
            <div class="ss-settings-status-text">
                <span class="ss-settings-status-label"><?php esc_html_e( 'Background jobs', 'sheetsync-for-woocommerce' ); ?></span>
                <span class="ss-settings-status-value">
                    <?php
                    if ( $sheetsync_sched_ok ) {
                        esc_html_e( 'Healthy', 'sheetsync-for-woocommerce' );
                    } else {
                        echo esc_html( sprintf(
                            /* translators: %d: overdue task count */
                            __( '%d overdue', 'sheetsync-for-woocommerce' ),
                            $sheetsync_past_due
                        ) );
                    }
                    ?>
                </span>
            </div>
        </div>
        <?php endif; ?>
        <?php if ( function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro() ) : ?>
        <div class="ss-settings-status-item is-ok">
            <span class="ss-settings-status-icon dashicons dashicons-star-filled" aria-hidden="true"></span>
            <div class="ss-settings-status-text">
                <span class="ss-settings-status-label"><?php esc_html_e( 'License', 'sheetsync-for-woocommerce' ); ?></span>
                <span class="ss-settings-status-value">Pro</span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="sheetsync-card ss-settings-scope-card">
        <p class="description" style="margin:0;">
            <span class="dashicons dashicons-info" style="color:var(--ss-green);"></span>
            <strong><?php esc_html_e( 'Site-wide settings only', 'sheetsync-for-woocommerce' ); ?></strong>
            —
            <?php esc_html_e( 'Google account, background cron, and logging live here. Sync direction, field mapping, conflict rules, scheduled sync, and Apps Script webhooks are configured on each connection (Connections → Edit → tabs).', 'sheetsync-for-woocommerce' ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync' ) ); ?>"><?php esc_html_e( 'Open connections', 'sheetsync-for-woocommerce' ); ?></a>
        </p>
    </div>

    <?php if ( ! $sheetsync_sched_ok && $sheetsync_has_cron ) : ?>
    <div class="notice notice-warning inline sheetsync-card ss-settings-cron-promo" style="margin-bottom:16px; border-left:4px solid #d97706;">
        <p style="margin:0;">
            <span class="dashicons dashicons-clock"></span>
            <strong><?php esc_html_e( 'Large catalogs need Background Cron', 'sheetsync-for-woocommerce' ); ?></strong>
            —
            <?php
            echo esc_html( sprintf(
                /* translators: %d: overdue task count */
                __( '%d background tasks are overdue. Set up the cron URL so sync does not depend on keeping a browser tab open.', 'sheetsync-for-woocommerce' ),
                (int) $sheetsync_past_due
            ) );
            ?>
            <a href="#tab-settings-cron"><?php esc_html_e( 'Set up Background Cron', 'sheetsync-for-woocommerce' ); ?></a>
            ·
            <a href="<?php echo esc_url( (string) ( $sheetsync_as_health['tools_url'] ?? admin_url( 'admin.php?page=wc-status&tab=action-scheduler' ) ) ); ?>" target="_blank" rel="noopener">
                <?php esc_html_e( 'Scheduled Actions', 'sheetsync-for-woocommerce' ); ?>
            </a>
        </p>
    </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ss-settings-form" id="ss-settings-form">
        <?php wp_nonce_field( 'sheetsync_save_settings' ); ?>
        <input type="hidden" name="action" value="sheetsync_save_settings">

        <div class="ss-settings-shell">
            <nav class="ss-tabs ss-settings-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'sheetsync-for-woocommerce' ); ?>">
                <a href="#tab-settings-google" class="ss-tab active">
                    <span class="dashicons dashicons-google" aria-hidden="true"></span>
                    <?php esc_html_e( 'Google & API', 'sheetsync-for-woocommerce' ); ?>
                </a>
                <a href="#tab-settings-sync" class="ss-tab">
                    <span class="dashicons dashicons-update" aria-hidden="true"></span>
                    <?php esc_html_e( 'Sync & Logs', 'sheetsync-for-woocommerce' ); ?>
                </a>
                <?php if ( $sheetsync_has_cron ) : ?>
                <a href="#tab-settings-cron" class="ss-tab<?php echo ! $sheetsync_sched_ok ? ' ss-tab-warn' : ''; ?>">
                    <span class="dashicons dashicons-clock" aria-hidden="true"></span>
                    <?php esc_html_e( 'Background Cron', 'sheetsync-for-woocommerce' ); ?>
                    <?php if ( ! $sheetsync_sched_ok ) : ?>
                        <span class="ss-tab-badge" title="<?php esc_attr_e( 'Background tasks need attention', 'sheetsync-for-woocommerce' ); ?>">!</span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <a href="#tab-settings-webhooks" class="ss-tab">
                    <span class="dashicons dashicons-rest-api" aria-hidden="true"></span>
                    <?php esc_html_e( 'Webhooks', 'sheetsync-for-woocommerce' ); ?>
                </a>
                <a href="#tab-settings-advanced" class="ss-tab">
                    <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                    <?php esc_html_e( 'Advanced', 'sheetsync-for-woocommerce' ); ?>
                </a>
                <a href="#tab-settings-help" class="ss-tab">
                    <span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
                    <?php esc_html_e( 'Help', 'sheetsync-for-woocommerce' ); ?>
                </a>
            </nav>

            <div class="ss-settings-tab-body">

                <!-- ── Google & API ── -->
                <div id="tab-settings-google" class="ss-tab-panel ss-settings-panel">
                    <div class="ss-settings-panel-header">
                        <h2><?php esc_html_e( 'Google API Connection', 'sheetsync-for-woocommerce' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Connect your Google Cloud Service Account to read and write spreadsheets.', 'sheetsync-for-woocommerce' ); ?></p>
                    </div>

                    <?php if ( $account_email ) : ?>
                        <div class="ss-settings-callout ss-settings-callout--success">
                            <div class="ss-settings-callout-main">
                                <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                                <div>
                                    <strong><?php esc_html_e( 'Connected as', 'sheetsync-for-woocommerce' ); ?></strong>
                                    <code id="ss-sa-email-display" class="ss-settings-email-code"><?php echo esc_html( $account_email ); ?></code>
                                </div>
                            </div>
                            <button type="button" class="button ss-copy-email-btn" data-email="<?php echo esc_attr( $account_email ); ?>">
                                <?php esc_html_e( 'Copy email', 'sheetsync-for-woocommerce' ); ?>
                            </button>
                        </div>

                        <div class="ss-share-instructions-card">
                            <h3><?php esc_html_e( 'Share your Google Sheet', 'sheetsync-for-woocommerce' ); ?></h3>
                            <p class="description"><?php esc_html_e( 'Your sheet must be shared with this service account before sync will work:', 'sheetsync-for-woocommerce' ); ?></p>
                            <code class="ss-share-email-code"><?php echo esc_html( $account_email ); ?></code>
                            <ol class="ss-share-steps">
                                <?php foreach ( SheetSync_Google_Auth::get_share_instructions( $account_email ) as $step ) : ?>
                                    <li><?php echo esc_html( $step ); ?></li>
                                <?php endforeach; ?>
                            </ol>
                        </div>

                        <p class="ss-settings-inline-actions">
                            <button type="button" class="button button-secondary" id="ss-test-google-auth">
                                <?php esc_html_e( 'Test Google Connection', 'sheetsync-for-woocommerce' ); ?>
                            </button>
                            <span id="ss-test-google-auth-result" class="ss-inline-test-result" aria-live="polite"></span>
                        </p>
                    <?php else : ?>
                        <div class="ss-settings-callout ss-settings-callout--warn">
                            <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                            <p><?php esc_html_e( 'No Google Service Account configured. Follow the steps below to connect.', 'sheetsync-for-woocommerce' ); ?></p>
                        </div>
                    <?php endif; ?>

                    <details class="ss-details-help ss-settings-setup-guide">
                        <summary class="ss-details-summary">
                            <?php esc_html_e( 'How to get your Service Account JSON key', 'sheetsync-for-woocommerce' ); ?>
                        </summary>
                        <ol class="ss-help-steps">
                            <li><?php esc_html_e( 'Go to console.cloud.google.com and create a new project.', 'sheetsync-for-woocommerce' ); ?></li>
                            <li><?php esc_html_e( 'Enable the Google Sheets API under APIs &amp; Services → Library.', 'sheetsync-for-woocommerce' ); ?></li>
                            <li><?php esc_html_e( 'Go to IAM &amp; Admin → Service Accounts → Create Service Account.', 'sheetsync-for-woocommerce' ); ?></li>
                            <li><?php esc_html_e( 'Click the service account → Keys tab → Add Key → Create new key → JSON.', 'sheetsync-for-woocommerce' ); ?></li>
                            <li><?php esc_html_e( 'Download the JSON file and paste its contents below.', 'sheetsync-for-woocommerce' ); ?></li>
                            <li><?php esc_html_e( 'Share your Google Sheet with the service account email (Editor role).', 'sheetsync-for-woocommerce' ); ?></li>
                        </ol>
                    </details>

                    <div class="ss-settings-section" id="ss-json-credentials-block">
                        <h3 class="ss-settings-section-title"><?php esc_html_e( 'Service Account credentials', 'sheetsync-for-woocommerce' ); ?></h3>

                        <div class="ss-json-upload-zone" id="ss-json-upload-zone" tabindex="0"
                             role="group"
                             aria-label="<?php esc_attr_e( 'Upload or paste Service Account JSON', 'sheetsync-for-woocommerce' ); ?>">
                            <span class="dashicons dashicons-upload" aria-hidden="true"></span>
                            <div class="ss-json-upload-copy">
                                <span class="ss-json-upload-label"><?php esc_html_e( 'Drop JSON file here, or paste with Ctrl+V', 'sheetsync-for-woocommerce' ); ?></span>
                                <span class="ss-json-upload-sub"><?php esc_html_e( 'Click this box, then paste — or use Browse to pick a file', 'sheetsync-for-woocommerce' ); ?></span>
                            </div>
                            <button type="button" class="button button-secondary" id="ss-json-browse-btn">
                                <?php esc_html_e( 'Browse file', 'sheetsync-for-woocommerce' ); ?>
                            </button>
                            <input type="file" id="ss-json-file-input" accept=".json,application/json,text/json" class="ss-json-file-input" hidden>
                        </div>

                        <p class="ss-json-or-divider" aria-hidden="true"><span><?php esc_html_e( 'or paste JSON below', 'sheetsync-for-woocommerce' ); ?></span></p>

                        <label for="ss-service-account-json" class="ss-settings-field-label"><?php esc_html_e( 'JSON key contents', 'sheetsync-for-woocommerce' ); ?></label>
                        <textarea name="service_account_json" id="ss-service-account-json" class="ss-json-textarea"
                                  placeholder='{"type":"service_account","project_id":"...","private_key":"...","client_email":"...@....iam.gserviceaccount.com",...}'></textarea>
                        <p id="ss-json-paste-status" class="ss-json-paste-status" aria-live="polite" hidden></p>

                        <p class="description ss-security-note">
                            <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                            <?php esc_html_e( 'Stored encrypted. The field is blank on reload for security — paste again only when updating credentials.', 'sheetsync-for-woocommerce' ); ?>
                        </p>
                    </div>

                    <?php if ( class_exists( 'SheetSync_Google_OAuth', false ) ) : ?>
                    <div class="ss-settings-section ss-settings-section--bordered">
                        <h3 class="ss-settings-section-title">
                            <?php esc_html_e( 'Sign in with Google (OAuth)', 'sheetsync-for-woocommerce' ); ?>
                            <?php echo function_exists( 'sheetsync_help_tip' ) ? wp_kses_post( sheetsync_help_tip( __( 'Optional. Create OAuth credentials in Google Cloud Console → APIs & Credentials → OAuth client ID (Web application). Add the redirect URI shown below.', 'sheetsync-for-woocommerce' ) ) ) : ''; ?>
                        </h3>
                        <?php
                        $sheetsync_oauth_email = SheetSync_Google_OAuth::get_connected_email();
                        if ( SheetSync_Google_OAuth::is_connected() && $sheetsync_oauth_email ) :
                            ?>
                            <p class="ss-settings-oauth-status">✅ <?php esc_html_e( 'OAuth connected as:', 'sheetsync-for-woocommerce' ); ?> <strong><?php echo esc_html( $sheetsync_oauth_email ); ?></strong></p>
                            <p>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=sheetsync-settings&sheetsync_oauth=disconnect' ), 'sheetsync_oauth_disconnect' ) ); ?>" class="button">
                                    <?php esc_html_e( 'Disconnect OAuth', 'sheetsync-for-woocommerce' ); ?>
                                </a>
                            </p>
                        <?php elseif ( SheetSync_Google_OAuth::is_configured() ) : ?>
                            <p>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync-settings&sheetsync_oauth=start' ) ); ?>" class="button button-primary">
                                    <?php esc_html_e( 'Connect with Google', 'sheetsync-for-woocommerce' ); ?>
                                </a>
                            </p>
                        <?php endif; ?>
                        <p class="description"><code class="ss-settings-code-inline"><?php echo esc_html( SheetSync_Google_OAuth::get_redirect_uri() ); ?></code></p>

                        <table class="form-table sheetsync-settings-form ss-settings-field-table">
                            <tr>
                                <th scope="row"><label for="ss-oauth-client-id"><?php esc_html_e( 'OAuth Client ID', 'sheetsync-for-woocommerce' ); ?></label></th>
                                <td>
                                    <input type="text" name="oauth_client_id" id="ss-oauth-client-id" class="large-text" value="<?php echo esc_attr( SheetSync_Google_OAuth::get_client_id() ); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ss-oauth-client-secret"><?php esc_html_e( 'OAuth Client Secret', 'sheetsync-for-woocommerce' ); ?></label></th>
                                <td>
                                    <input type="password" name="oauth_client_secret" id="ss-oauth-client-secret" class="regular-text" value="" placeholder="••••••••" autocomplete="new-password">
                                    <p class="description"><?php esc_html_e( 'Leave blank to keep the current secret.', 'sheetsync-for-woocommerce' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ss-auth-method"><?php esc_html_e( 'Auth method', 'sheetsync-for-woocommerce' ); ?></label></th>
                                <td>
                                    <select name="sheetsync_auth_method" id="ss-auth-method">
                                        <option value="service_account" <?php selected( get_option( 'sheetsync_auth_method', 'service_account' ), 'service_account' ); ?>><?php esc_html_e( 'Service Account (recommended)', 'sheetsync-for-woocommerce' ); ?></option>
                                        <option value="oauth" <?php selected( get_option( 'sheetsync_auth_method', 'service_account' ), 'oauth' ); ?>><?php esc_html_e( 'OAuth (Sign in with Google)', 'sheetsync-for-woocommerce' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ── Sync & Logs ── -->
                <div id="tab-settings-sync" class="ss-tab-panel ss-settings-panel" style="display:none;">
                    <div class="ss-settings-panel-header">
                        <h2><?php esc_html_e( 'Sync & Logs', 'sheetsync-for-woocommerce' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Global logging and optional template link. Per-connection sync strategy, auto-sync, and schedules are on each connection’s Sync tab.', 'sheetsync-for-woocommerce' ); ?></p>
                    </div>

                    <div class="ss-settings-callout ss-settings-callout--info" style="margin-bottom:16px;">
                        <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
                        <p class="description" style="margin:0;">
                            <?php esc_html_e( 'To change sync strategy, conflict policy, or scheduled sync: open Connections → your connection → Sync tab → Advanced settings.', 'sheetsync-for-woocommerce' ); ?>
                        </p>
                    </div>

                    <div class="ss-settings-section">
                        <label for="ss-log-retention" class="ss-settings-field-label"><?php esc_html_e( 'Log Retention', 'sheetsync-for-woocommerce' ); ?></label>
                        <div class="ss-settings-input-group">
                            <input type="number" name="log_retention_days" id="ss-log-retention" class="small-text" min="1" max="365"
                                   value="<?php echo esc_attr( $settings['log_retention_days'] ?? 30 ); ?>">
                            <span><?php esc_html_e( 'days', 'sheetsync-for-woocommerce' ); ?></span>
                        </div>
                    </div>

                    <div class="ss-settings-section">
                        <label for="ss-log-level" class="ss-settings-field-label"><?php esc_html_e( 'Log Level', 'sheetsync-for-woocommerce' ); ?></label>
                        <?php
                        $sheetsync_log_level = (string) ( $settings['log_level'] ?? 'info' );
                        if ( ! in_array( $sheetsync_log_level, array( 'error', 'info', 'debug' ), true ) ) {
                            $sheetsync_log_level = 'info';
                        }
                        ?>
                        <select name="log_level" id="ss-log-level">
                            <option value="error" <?php selected( $sheetsync_log_level, 'error' ); ?>><?php esc_html_e( 'Errors only', 'sheetsync-for-woocommerce' ); ?></option>
                            <option value="info" <?php selected( $sheetsync_log_level, 'info' ); ?>><?php esc_html_e( 'Info (default)', 'sheetsync-for-woocommerce' ); ?></option>
                            <option value="debug" <?php selected( $sheetsync_log_level, 'debug' ); ?>><?php esc_html_e( 'Debug (verbose)', 'sheetsync-for-woocommerce' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'Controls how much sync activity is stored in Sync Logs.', 'sheetsync-for-woocommerce' ); ?></p>
                    </div>

                    <div class="ss-settings-section">
                        <label for="ss-template-url" class="ss-settings-field-label"><?php esc_html_e( 'Google Sheet template URL', 'sheetsync-for-woocommerce' ); ?></label>
                        <input type="url" name="google_template_url" id="ss-template-url" class="large-text"
                               value="<?php echo esc_attr( $settings['google_template_url'] ?? '' ); ?>"
                               placeholder="https://docs.google.com/spreadsheets/d/.../copy">
                        <p class="description"><?php esc_html_e( 'Optional “Make a copy” link shown on the connection Sync tab.', 'sheetsync-for-woocommerce' ); ?></p>
                    </div>

                    <div class="ss-settings-section">
                        <label for="ss-test-image-url" class="ss-settings-field-label"><?php esc_html_e( 'Test image URL', 'sheetsync-for-woocommerce' ); ?></label>
                        <div class="ss-settings-input-row">
                            <input type="url" id="ss-test-image-url" class="large-text" placeholder="https://example.com/image.jpg">
                            <button type="button" class="button" id="ss-test-image-url-btn"><?php esc_html_e( 'Test', 'sheetsync-for-woocommerce' ); ?></button>
                            <span id="ss-test-image-url-result" class="ss-inline-test-result"></span>
                        </div>
                        <p class="description"><?php esc_html_e( 'Test Media Library, attachment ID, or external https image URLs.', 'sheetsync-for-woocommerce' ); ?></p>
                    </div>

                    <div class="ss-settings-section ss-settings-section--bordered">
                        <h3 class="ss-settings-section-title"><?php esc_html_e( 'Email notifications & reports', 'sheetsync-for-woocommerce' ); ?></h3>
                        <?php if ( function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro() ) : ?>
                            <label class="ss-settings-checkbox-row">
                                <input type="checkbox" name="email_notifications" value="1"
                                       <?php checked( ! empty( $settings['email_notifications'] ) ); ?>>
                                <?php esc_html_e( 'Email me when each sync completes or fails', 'sheetsync-for-woocommerce' ); ?>
                            </label>
                            <p class="description" style="margin:4px 0 16px 24px;">
                                <?php esc_html_e( 'Instant per-sync summary — useful for manual syncs, off-peak publish, and scheduled jobs.', 'sheetsync-for-woocommerce' ); ?>
                            </p>

                            <label for="ss-email-report-interval" class="ss-settings-field-label"><?php esc_html_e( 'Email reports (digest)', 'sheetsync-for-woocommerce' ); ?></label>
                            <select name="email_report_interval" id="ss-email-report-interval">
                                <?php
                                $sheetsync_report_interval = (string) ( $settings['email_report_interval'] ?? '' );
                                ?>
                                <option value="" <?php selected( $sheetsync_report_interval, '' ); ?>><?php esc_html_e( 'Disabled', 'sheetsync-for-woocommerce' ); ?></option>
                                <option value="daily" <?php selected( $sheetsync_report_interval, 'daily' ); ?>><?php esc_html_e( 'Daily (8:00 AM)', 'sheetsync-for-woocommerce' ); ?></option>
                                <option value="weekly" <?php selected( $sheetsync_report_interval, 'weekly' ); ?>><?php esc_html_e( 'Weekly — Mondays at 8:00 AM', 'sheetsync-for-woocommerce' ); ?></option>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'Summary of sync activity, conflicts, and system health. Preview anytime under SheetSync → Sync Reports.', 'sheetsync-for-woocommerce' ); ?>
                            </p>

                            <label for="ss-notification-email" class="ss-settings-field-label" style="margin-top:12px;"><?php esc_html_e( 'Notification email', 'sheetsync-for-woocommerce' ); ?></label>
                            <input type="email" name="notification_email" id="ss-notification-email" class="regular-text"
                                   value="<?php echo esc_attr( $settings['notification_email'] ?? get_option( 'admin_email' ) ); ?>">
                        <?php else : ?>
                            <?php SheetSync_Admin::render_pro_gate( __( 'Email Notifications & Reports', 'sheetsync-for-woocommerce' ) ); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ( $sheetsync_has_cron ) : ?>
                <!-- ── Background Cron (form field only; UI below form) ── -->
                <div id="tab-settings-cron" class="ss-tab-panel ss-settings-panel" style="display:none;">
                    <div class="ss-settings-panel-header">
                        <h2><?php esc_html_e( 'Background Cron URL', 'sheetsync-for-woocommerce' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Keeps Smart Poll, imports, and image downloads running when WP-Cron is unreliable.', 'sheetsync-for-woocommerce' ); ?></p>
                    </div>

                    <div class="ss-settings-callout <?php echo $sheetsync_sched_ok ? 'ss-settings-callout--success' : 'ss-settings-callout--warn'; ?>">
                        <span class="dashicons <?php echo $sheetsync_sched_ok ? 'dashicons-yes-alt' : 'dashicons-clock'; ?>" aria-hidden="true"></span>
                        <div>
                            <strong><?php esc_html_e( 'Scheduler status:', 'sheetsync-for-woocommerce' ); ?></strong>
                            <?php if ( $sheetsync_sched_ok ) : ?>
                                <?php esc_html_e( 'Healthy', 'sheetsync-for-woocommerce' ); ?>
                            <?php else : ?>
                                <?php echo esc_html( sprintf(
                                    __( '%d overdue background tasks', 'sheetsync-for-woocommerce' ),
                                    $sheetsync_past_due
                                ) ); ?>
                            <?php endif; ?>
                            —
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=action-scheduler' ) ); ?>" target="_blank" rel="noopener">
                                <?php esc_html_e( 'WooCommerce → Scheduled Actions', 'sheetsync-for-woocommerce' ); ?>
                            </a>
                        </div>
                    </div>

                    <label class="ss-settings-checkbox-row ss-settings-checkbox-row--prominent">
                        <input type="checkbox" name="external_cron_enabled" value="1" <?php checked( ! empty( $sheetsync_external_cron_enabled ) ); ?>>
                        <span>
                            <strong><?php esc_html_e( 'Allow background cron URL', 'sheetsync-for-woocommerce' ); ?></strong>
                            <span class="description" style="display:block;margin-top:4px;"><?php esc_html_e( 'Required for server cron or cron-job.org to call your site. Save settings after enabling.', 'sheetsync-for-woocommerce' ); ?></span>
                        </span>
                    </label>

                    <p class="description ss-settings-doc-hint">
                        <?php esc_html_e( 'Documentation: docs/BACKGROUND-SYNC-AND-CRON.md in the plugin folder.', 'sheetsync-for-woocommerce' ); ?>
                    </p>
                </div>
                <?php endif; ?>

                <!-- ── Webhooks (placeholder in form — content rendered after form) ── -->
                <div id="tab-settings-webhooks" class="ss-tab-panel ss-settings-panel" style="display:none;">
                    <div class="ss-settings-panel-header">
                        <h2><?php esc_html_e( 'Webhook Configuration', 'sheetsync-for-woocommerce' ); ?> <span class="sheetsync-pro-badge">PRO</span></h2>
                        <p class="description"><?php esc_html_e( 'Global webhook URL and secret (shown below). Install the Apps Script trigger on each connection — open Connections → Edit → Sync tab → Real-time updates.', 'sheetsync-for-woocommerce' ); ?></p>
                    </div>
                    <p class="description"><?php esc_html_e( 'Webhook URL and secret are shown below when Pro is active.', 'sheetsync-for-woocommerce' ); ?></p>
                </div>

                <!-- ── Advanced ── -->
                <div id="tab-settings-advanced" class="ss-tab-panel ss-settings-panel" style="display:none;">
                    <div class="ss-settings-panel-header">
                        <h2><?php esc_html_e( 'Advanced', 'sheetsync-for-woocommerce' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Performance tuning, wizard branding, custom meta, multi-site stock, and Store Reports limits (Pro).', 'sheetsync-for-woocommerce' ); ?></p>
                    </div>

                    <div class="ss-settings-section ss-settings-section--bordered">
                        <h3 class="ss-settings-section-title"><?php esc_html_e( 'Performance', 'sheetsync-for-woocommerce' ); ?></h3>
                        <label for="ss-batch-size" class="ss-settings-field-label"><?php esc_html_e( 'Batch size (rows per request)', 'sheetsync-for-woocommerce' ); ?></label>
                        <input type="number" name="batch_size" id="ss-batch-size" class="small-text" min="1" max="500"
                               value="<?php echo esc_attr( $settings['batch_size'] ?? ( class_exists( 'SheetSync_Host_Profile', false ) ? SheetSync_Host_Profile::default_batch_size() : 25 ) ); ?>">
                        <p class="description"><?php esc_html_e( 'Site-wide default. SheetSync auto-tunes for your server (25 rows). Increase only on fast VPS hosting — affects all connections.', 'sheetsync-for-woocommerce' ); ?></p>
                    </div>

                    <?php if ( function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro() ) : ?>
                    <div class="ss-settings-section ss-settings-section--bordered" id="ss-dashboard-limits-card">
                        <h3 class="ss-settings-section-title"><?php esc_html_e( 'Store Reports limits (Pro)', 'sheetsync-for-woocommerce' ); ?></h3>
                        <p class="description">
                            <?php esc_html_e( 'Sales and Inventory dashboards paginate WooCommerce data in the background. Sync can handle 100k+ products; reporting uses these caps to keep admin pages fast.', 'sheetsync-for-woocommerce' ); ?>
                        </p>
                        <dl class="ss-settings-dl">
                            <dt><?php esc_html_e( 'Inventory & product filters', 'sheetsync-for-woocommerce' ); ?></dt>
                            <dd>
                                <?php
                                printf(
                                    esc_html__( 'Up to %1$s products (%2$d per page × %3$d pages max).', 'sheetsync-for-woocommerce' ),
                                    number_format_i18n( $sheetsync_dash_product_cap ),
                                    (int) $sheetsync_dash_products_per,
                                    (int) $sheetsync_dash_products_max
                                );
                                ?>
                            </dd>
                            <dt><?php esc_html_e( 'Sales & order reports', 'sheetsync-for-woocommerce' ); ?></dt>
                            <dd>
                                <?php
                                printf(
                                    esc_html__( 'Up to %1$s orders per query (%2$d per page × %3$d pages max).', 'sheetsync-for-woocommerce' ),
                                    number_format_i18n( $sheetsync_dash_order_cap ),
                                    (int) $sheetsync_dash_orders_per,
                                    (int) $sheetsync_dash_orders_max
                                );
                                ?>
                            </dd>
                        </dl>
                        <p class="description">
                            <?php esc_html_e( 'When a cap is reached, the dashboard shows a truncation warning. Raise limits for large catalogs with WordPress filters in a small custom plugin or your theme functions.php:', 'sheetsync-for-woocommerce' ); ?>
                        </p>
                        <pre class="ss-cron-example" style="margin-top:8px;"><code>add_filter( 'sheetsync_dashboard_products_max_pages', fn() => 400 );
add_filter( 'sheetsync_dashboard_orders_max_pages', fn() => 800 );</code></pre>
                        <p class="description">
                            <?php esc_html_e( 'Optional per-page filters: sheetsync_dashboard_products_per_page, sheetsync_dashboard_orders_per_page (100–1000).', 'sheetsync-for-woocommerce' ); ?>
                        </p>
                    </div>
                    <?php endif; ?>

                    <div class="ss-settings-section">
                        <label for="ss-setup-video" class="ss-settings-field-label"><?php esc_html_e( 'Setup video (embed URL)', 'sheetsync-for-woocommerce' ); ?></label>
                        <input type="url" name="setup_video_url" id="ss-setup-video" class="large-text"
                               value="<?php echo esc_attr( $settings['setup_video_url'] ?? '' ); ?>"
                               placeholder="https://www.youtube.com/embed/…">
                        <p class="description"><?php esc_html_e( 'Shown in Setup Wizard Step 1. Use a YouTube embed URL.', 'sheetsync-for-woocommerce' ); ?></p>
                    </div>

                    <?php if ( current_user_can( 'manage_options' ) ) : ?>
                    <div class="ss-settings-section ss-settings-section--bordered">
                        <h3 class="ss-settings-section-title"><?php esc_html_e( 'Wizard branding (Pro/agency)', 'sheetsync-for-woocommerce' ); ?></h3>
                        <?php $sheetsync_wb = function_exists( 'sheetsync_get_wizard_branding' ) ? sheetsync_get_wizard_branding() : array(); ?>
                        <label for="ss-wizard-title" class="ss-settings-field-label"><?php esc_html_e( 'Custom wizard title', 'sheetsync-for-woocommerce' ); ?></label>
                        <input type="text" name="wizard_brand_title" id="ss-wizard-title" class="regular-text"
                               value="<?php echo esc_attr( $sheetsync_wb['title'] ?? '' ); ?>">
                        <label for="ss-wizard-logo" class="ss-settings-field-label" style="margin-top:12px;"><?php esc_html_e( 'Logo URL', 'sheetsync-for-woocommerce' ); ?></label>
                        <input type="url" name="wizard_brand_logo" id="ss-wizard-logo" class="large-text"
                               value="<?php echo esc_attr( $sheetsync_wb['logo_url'] ?? '' ); ?>">
                        <label class="ss-settings-checkbox-row" style="margin-top:12px;">
                            <input type="checkbox" name="wizard_hide_support" value="1" <?php checked( ! empty( $sheetsync_wb['hide_support'] ) ); ?>>
                            <?php esc_html_e( 'Hide support link in wizard', 'sheetsync-for-woocommerce' ); ?>
                        </label>
                    </div>
                    <?php endif; ?>

                    <?php if ( defined( 'SHEETSYNC_DEV_PRO' ) && SHEETSYNC_DEV_PRO ) : ?>
                    <div class="ss-settings-section">
                        <label class="ss-settings-checkbox-row">
                            <input type="checkbox" name="pro_test_mode" value="1" <?php checked( get_option( 'sheetsync_pro_test_mode', false ) ); ?>>
                            <?php esc_html_e( 'Enable developer Pro bypass (requires SHEETSYNC_DEV_PRO in wp-config).', 'sheetsync-for-woocommerce' ); ?>
                        </label>
                    </div>
                    <?php endif; ?>

                    <?php if ( function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro() ) : ?>
                    <div class="ss-settings-section ss-settings-section--bordered">
                        <label for="ss-meta-keys" class="ss-settings-field-label"><?php esc_html_e( 'Allowed custom meta keys', 'sheetsync-for-woocommerce' ); ?></label>
                        <textarea name="sheetsync_allowed_product_meta_keys" id="ss-meta-keys" class="large-text" rows="5"
                            placeholder="my_scf_field&#10;another_meta_key"><?php echo esc_textarea( $sheetsync_allowed_meta_keys ?? '' ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'One meta key per line. In Field Mapping use meta__your_key.', 'sheetsync-for-woocommerce' ); ?></p>

                        <label for="ss-stock-targets" class="ss-settings-field-label" style="margin-top:16px;"><?php esc_html_e( 'Remote stock sync (outbound)', 'sheetsync-for-woocommerce' ); ?></label>
                        <textarea name="sheetsync_multisite_stock_targets" id="ss-stock-targets" class="large-text code" rows="4"
                            placeholder='[{"url":"https://store-b.example/wp-json/sheetsync/v1/remote-stock","secret":"shared-secret"}]'><?php echo esc_textarea( $sheetsync_multisite_stock_targets ?? '' ); ?></textarea>

                        <label for="ss-stock-secret" class="ss-settings-field-label" style="margin-top:12px;"><?php esc_html_e( 'Remote stock incoming secret', 'sheetsync-for-woocommerce' ); ?></label>
                        <input type="password" name="sheetsync_remote_stock_incoming_secret" id="ss-stock-secret" class="regular-text" autocomplete="new-password"
                            value="<?php echo esc_attr( $sheetsync_remote_stock_incoming_secret ?? '' ); ?>">
                        <p class="description">
                            <?php esc_html_e( 'Endpoint:', 'sheetsync-for-woocommerce' ); ?>
                            <code><?php echo esc_html( rest_url( 'sheetsync/v1/remote-stock' ) ); ?></code>
                        </p>
                    </div>
                    <?php else : ?>
                    <div class="ss-settings-section">
                        <?php SheetSync_Admin::render_pro_gate( __( 'Custom meta keys and multi-site stock sync', 'sheetsync-for-woocommerce' ) ); ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ── Help ── -->
                <div id="tab-settings-help" class="ss-tab-panel ss-settings-panel" style="display:none;">
                    <div class="ss-settings-panel-header">
                        <h2><?php esc_html_e( 'Help & Troubleshooting', 'sheetsync-for-woocommerce' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Common issues and where to find full documentation.', 'sheetsync-for-woocommerce' ); ?></p>
                    </div>

                    <div class="ss-settings-help-grid">
                        <details class="ss-troubleshoot-item ss-settings-help-card">
                            <summary><?php esc_html_e( '403 / Permission denied when testing sheet', 'sheetsync-for-woocommerce' ); ?></summary>
                            <p><?php esc_html_e( 'Share your Google Sheet with the service account email (Editor role). Use the copy button on the Google & API tab.', 'sheetsync-for-woocommerce' ); ?></p>
                        </details>
                        <details class="ss-troubleshoot-item ss-settings-help-card">
                            <summary><?php esc_html_e( 'Invalid Service Account JSON', 'sheetsync-for-woocommerce' ); ?></summary>
                            <p><?php esc_html_e( 'Download a fresh JSON key from Google Cloud → IAM → Service Accounts → Keys. Paste the entire file contents.', 'sheetsync-for-woocommerce' ); ?></p>
                        </details>
                        <details class="ss-troubleshoot-item ss-settings-help-card">
                            <summary><?php esc_html_e( 'Sync stuck or slow', 'sheetsync-for-woocommerce' ); ?></summary>
                            <p><?php esc_html_e( 'Use Background Cron (every 5 min), or Sync Now with the browser tab open. Check WooCommerce → Scheduled Actions.', 'sheetsync-for-woocommerce' ); ?></p>
                        </details>
                        <details class="ss-troubleshoot-item ss-settings-help-card">
                            <summary><?php esc_html_e( 'Real-time sync not firing', 'sheetsync-for-woocommerce' ); ?></summary>
                            <p><?php esc_html_e( 'Open your connection → Sync tab. Install the Apps Script trigger and run Test webhook.', 'sheetsync-for-woocommerce' ); ?></p>
                        </details>
                        <?php if ( class_exists( 'SheetSync_Google_OAuth', false ) && ! SheetSync_Google_OAuth::is_available() ) : ?>
                        <details class="ss-troubleshoot-item ss-settings-help-card">
                            <summary><?php esc_html_e( 'Sign in with Google (coming soon)', 'sheetsync-for-woocommerce' ); ?></summary>
                            <p><?php esc_html_e( 'OAuth login without JSON keys is planned for a future release. Service Account remains the recommended method.', 'sheetsync-for-woocommerce' ); ?></p>
                        </details>
                        <?php endif; ?>
                    </div>

                    <?php if ( current_user_can( 'manage_options' ) && ! empty( $sheetsync_freemius_status ) ) : ?>
                    <div class="ss-settings-section ss-settings-section--bordered" style="margin-top:24px;">
                        <h3 class="ss-settings-section-title"><?php esc_html_e( 'License status', 'sheetsync-for-woocommerce' ); ?></h3>
                        <dl class="ss-settings-dl">
                            <dt><?php esc_html_e( 'Current plan', 'sheetsync-for-woocommerce' ); ?></dt>
                            <dd><strong><?php echo esc_html( $sheetsync_freemius_status['plan'] ?? '' ); ?></strong></dd>
                            <dt><?php esc_html_e( 'Freemius connected', 'sheetsync-for-woocommerce' ); ?></dt>
                            <dd><?php echo ! empty( $sheetsync_freemius_status['connected'] ) ? '✅' : '❌'; ?></dd>
                            <dt><?php esc_html_e( 'Trial active', 'sheetsync-for-woocommerce' ); ?></dt>
                            <dd><?php echo ! empty( $sheetsync_freemius_status['trial'] ) ? esc_html__( 'Yes', 'sheetsync-for-woocommerce' ) : esc_html__( 'No', 'sheetsync-for-woocommerce' ); ?></dd>
                            <dt><?php esc_html_e( 'Paid license', 'sheetsync-for-woocommerce' ); ?></dt>
                            <dd><?php echo ! empty( $sheetsync_freemius_status['licensed'] ) ? '✅' : '❌'; ?></dd>
                        </dl>
                        <?php if ( function_exists( 'sfw_fs' ) && is_object( sfw_fs() ) && method_exists( sfw_fs(), 'get_account_url' ) ) : ?>
                            <p><a href="<?php echo esc_url( sfw_fs()->get_account_url() ); ?>" class="button button-secondary"><?php esc_html_e( 'Open Account', 'sheetsync-for-woocommerce' ); ?></a></p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

            </div><!-- .ss-settings-tab-body -->

            <div class="ss-settings-save-bar">
                <p class="ss-settings-save-hint description"><?php esc_html_e( 'Changes apply to all tabs — save once when finished.', 'sheetsync-for-woocommerce' ); ?></p>
                <button type="submit" class="button button-primary button-hero ss-settings-save-btn">
                    <span class="dashicons dashicons-saved" aria-hidden="true"></span>
                    <?php esc_html_e( 'Save Settings', 'sheetsync-for-woocommerce' ); ?>
                </button>
            </div>
        </div><!-- .ss-settings-shell -->
    </form>

    <?php if ( $sheetsync_has_cron ) : ?>
    <div class="ss-settings-cron-tools ss-tab-panel-external" id="ss-external-cron-card" data-ss-settings-panel="tab-settings-cron" style="display:none;">
        <div class="sheetsync-card ss-settings-tools-card">
            <h2><?php esc_html_e( 'Cron URLs & testing', 'sheetsync-for-woocommerce' ); ?></h2>

            <div class="ss-settings-url-field">
                <label class="ss-settings-field-label"><?php esc_html_e( 'Cron endpoint (full queue run)', 'sheetsync-for-woocommerce' ); ?></label>
                <div class="ss-settings-url-row">
                    <code id="ss-external-cron-url" class="ss-settings-url-code"><?php echo esc_html( $sheetsync_external_cron_url ); ?></code>
                    <button type="button" class="button ss-copy-btn" data-target="#ss-external-cron-url"><?php esc_html_e( 'Copy', 'sheetsync-for-woocommerce' ); ?></button>
                </div>
            </div>

            <div class="ss-settings-url-field">
                <label class="ss-settings-field-label"><?php esc_html_e( 'Cron token (send as HTTP header)', 'sheetsync-for-woocommerce' ); ?></label>
                <div class="ss-settings-url-row">
                    <code id="ss-external-cron-token" class="ss-settings-url-code"><?php echo esc_html( $sheetsync_external_cron_token ); ?></code>
                    <button type="button" class="button ss-copy-btn" data-target="#ss-external-cron-token"><?php esc_html_e( 'Copy', 'sheetsync-for-woocommerce' ); ?></button>
                </div>
                <p class="description"><?php esc_html_e( 'Header name:', 'sheetsync-for-woocommerce' ); ?> <code>X-SheetSync-Cron-Token</code></p>
            </div>

            <div class="ss-settings-url-field">
                <label class="ss-settings-field-label"><?php esc_html_e( 'Ping endpoint (health check only)', 'sheetsync-for-woocommerce' ); ?></label>
                <div class="ss-settings-url-row">
                    <code id="ss-external-cron-ping-url" class="ss-settings-url-code"><?php echo esc_html( $sheetsync_external_cron_ping_url ); ?></code>
                    <button type="button" class="button ss-copy-btn" data-target="#ss-external-cron-ping-url"><?php esc_html_e( 'Copy', 'sheetsync-for-woocommerce' ); ?></button>
                </div>
            </div>

            <p class="ss-settings-inline-actions">
                <button type="button" class="button button-primary" id="ss-test-external-cron">
                    <?php esc_html_e( 'Run queue now (test)', 'sheetsync-for-woocommerce' ); ?>
                </button>
                <span id="ss-test-external-cron-result" class="ss-inline-test-result" aria-live="polite"></span>
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ss-settings-inline-form">
                <?php wp_nonce_field( 'sheetsync_regenerate_cron_token' ); ?>
                <input type="hidden" name="action" value="sheetsync_regenerate_cron_token">
                <button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Regenerate token? Update your server cron job with the new header token.', 'sheetsync-for-woocommerce' ) ); ?>');">
                    <?php esc_html_e( 'Regenerate token', 'sheetsync-for-woocommerce' ); ?>
                </button>
            </form>

            <details class="ss-details-help ss-settings-cron-examples">
                <summary class="ss-details-summary"><?php esc_html_e( 'Server cron examples (every 5 minutes)', 'sheetsync-for-woocommerce' ); ?></summary>
                <p><strong>cURL</strong></p>
                <pre class="ss-cron-example"><code>*/5 * * * * <?php echo esc_html( $sheetsync_external_cron_curl ); ?> &gt;/dev/null</code></pre>
                <p><strong><?php esc_html_e( 'WP-CLI (VPS/agency)', 'sheetsync-for-woocommerce' ); ?></strong></p>
                <pre class="ss-cron-example"><code>*/5 * * * * cd <?php echo esc_html( str_replace( '\\', '/', ABSPATH ) ); ?> && wp sheetsync run-queue --seconds=45</code></pre>
                <p class="description"><?php esc_html_e( 'EasyCron, cron-job.org, or your host panel can call the endpoint on a schedule. Use the header token — do not put the token in the URL (it may appear in server logs).', 'sheetsync-for-woocommerce' ); ?></p>
            </details>

            <?php if ( ! empty( $sheetsync_external_cron_last['started_at'] ) ) : ?>
            <p class="description ss-settings-last-run">
                <?php
                printf(
                    esc_html__( 'Last external run: %1$s — past-due %2$d → %3$d', 'sheetsync-for-woocommerce' ),
                    esc_html( (string) $sheetsync_external_cron_last['started_at'] ),
                    (int) ( $sheetsync_external_cron_last['scheduler_before']['past_due'] ?? 0 ),
                    (int) ( $sheetsync_external_cron_last['scheduler_after']['past_due'] ?? 0 )
                );
                ?>
            </p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="ss-settings-webhook-tools ss-tab-panel-external" id="ss-settings-webhook-tools" data-ss-settings-panel="tab-settings-webhooks" style="display:none;">
        <?php if ( function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro() ) : ?>
        <div class="sheetsync-card ss-settings-tools-card">
            <h2><?php esc_html_e( 'Webhook credentials', 'sheetsync-for-woocommerce' ); ?> <span class="sheetsync-pro-badge">PRO</span></h2>

            <div class="ss-settings-url-field">
                <label class="ss-settings-field-label"><?php esc_html_e( 'Webhook URL', 'sheetsync-for-woocommerce' ); ?></label>
                <div class="ss-settings-url-row">
                    <code id="webhook-endpoint" class="ss-settings-url-code"><?php echo esc_html( $webhook_endpoint ); ?></code>
                    <button type="button" class="button ss-copy-btn" data-target="#webhook-endpoint"><?php esc_html_e( 'Copy', 'sheetsync-for-woocommerce' ); ?></button>
                </div>
            </div>

            <div class="ss-settings-url-field">
                <label class="ss-settings-field-label"><?php esc_html_e( 'Webhook Secret', 'sheetsync-for-woocommerce' ); ?></label>
                <div class="ss-settings-secret-row">
                    <input type="password"
                           id="webhook-secret-field"
                           class="regular-text"
                           readonly
                           value=""
                           placeholder="<?php echo $webhook_secret_configured ? esc_attr__( '••••••••••••••••', 'sheetsync-for-woocommerce' ) : esc_attr__( 'Not configured', 'sheetsync-for-woocommerce' ); ?>"
                           data-configured="<?php echo $webhook_secret_configured ? '1' : '0'; ?>">
                    <button type="button" class="button" id="ss-reveal-secret" <?php disabled( ! $webhook_secret_configured ); ?>>
                        <?php esc_html_e( 'Reveal', 'sheetsync-for-woocommerce' ); ?>
                    </button>
                    <button type="button" class="button" id="ss-copy-secret" <?php disabled( ! $webhook_secret_configured ); ?>>
                        <?php esc_html_e( 'Copy', 'sheetsync-for-woocommerce' ); ?>
                    </button>
                </div>
                <p class="description"><?php esc_html_e( 'Click Reveal to load the secret securely.', 'sheetsync-for-woocommerce' ); ?></p>
            </div>
        </div>
        <?php else : ?>
        <div class="sheetsync-card ss-settings-tools-card">
            <?php SheetSync_Admin::render_pro_gate( __( 'Real-time webhook sync', 'sheetsync-for-woocommerce' ) ); ?>
        </div>
        <?php endif; ?>
    </div>

</div>
