<?php
defined( 'ABSPATH' ) || exit;

$sheetsync_wizard_state = function_exists( 'sheetsync_get_wizard_state' ) ? sheetsync_get_wizard_state() : array();
$sheetsync_wizard_step  = max( 1, min( 7, (int) ( $sheetsync_wizard_state['step'] ?? 1 ) ) );
$sheetsync_account_email = class_exists( 'SheetSync_Google_Auth', false ) ? SheetSync_Google_Auth::get_account_email() : '';
$sheetsync_support_url   = '';
if ( function_exists( 'sfw_fs' ) && is_object( sfw_fs() ) && method_exists( sfw_fs(), 'contact_url' ) ) {
    $sheetsync_support_url = sfw_fs()->contact_url();
}
$sheetsync_branding = function_exists( 'sheetsync_get_wizard_branding' ) ? sheetsync_get_wizard_branding() : array();
$sheetsync_videos    = function_exists( 'sheetsync_get_wizard_step_videos' ) ? sheetsync_get_wizard_step_videos() : array();
$sheetsync_step_video = (string) ( $sheetsync_videos[ $sheetsync_wizard_step ] ?? $sheetsync_videos[1] ?? '' );
$sheetsync_is_pro     = function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro();
$sheetsync_upgrade    = function_exists( 'sheetsync_upgrade_url' ) ? sheetsync_upgrade_url() : '#';
$sheetsync_settings_url = admin_url( 'admin.php?page=sheetsync-settings' );
$sheetsync_wizard_direction = (string) ( $sheetsync_wizard_state['sync_direction'] ?? 'sheets_to_wc' );
if ( ! $sheetsync_is_pro && in_array( $sheetsync_wizard_direction, array( 'wc_to_sheets', 'two_way' ), true ) ) {
    $sheetsync_wizard_direction = 'sheets_to_wc';
}
$sheetsync_wizard_conn_id = (int) ( $sheetsync_wizard_state['connection_id'] ?? 0 );
$sheetsync_wizard_mapped  = ( $sheetsync_wizard_conn_id > 0 && class_exists( 'SheetSync_Product_Map_Repository', false ) )
    ? ( new SheetSync_Product_Map_Repository() )->count_for_connection( $sheetsync_wizard_conn_id )
    : 0;
$sheetsync_wizard_first_link = $sheetsync_wizard_mapped < 1;
$sheetsync_wizard_job_direction = 'pull';
if ( 'wc_to_sheets' === $sheetsync_wizard_direction ) {
    $sheetsync_wizard_job_direction = 'push';
} elseif ( 'two_way' === $sheetsync_wizard_direction ) {
    $sheetsync_wizard_job_direction = $sheetsync_wizard_first_link ? 'push' : 'two_way';
}
$sheetsync_wizard_intent = 'apply_sheet';
if ( 'wc_to_sheets' === $sheetsync_wizard_direction ) {
    $sheetsync_wizard_intent = $sheetsync_wizard_first_link ? 'first_publish' : 'publish_wc';
} elseif ( 'two_way' === $sheetsync_wizard_direction ) {
    $sheetsync_wizard_intent = $sheetsync_wizard_first_link ? 'first_publish' : 'both_ways';
}
$sheetsync_wizard_first_sync_label = __( 'Run first sync', 'sheetsync-for-woocommerce' );
if ( 'sheets_to_wc' === $sheetsync_wizard_direction ) {
    $sheetsync_wizard_first_sync_desc = __( 'Links your sheet rows to WooCommerce products. Add products to the sheet first, or skip and sync later from the connection.', 'sheetsync-for-woocommerce' );
    $sheetsync_wizard_first_sync_label = __( 'Link sheet to store', 'sheetsync-for-woocommerce' );
} elseif ( 'wc_to_sheets' === $sheetsync_wizard_direction ) {
    $sheetsync_wizard_first_sync_desc = __( 'Publishes your WooCommerce catalog to the sheet. Large catalogs may take a few minutes — progress appears on the connection Sync tab.', 'sheetsync-for-woocommerce' );
    $sheetsync_wizard_first_sync_label = __( 'Publish catalog to sheet', 'sheetsync-for-woocommerce' );
} else {
    $sheetsync_wizard_first_sync_desc = $sheetsync_wizard_first_link
        ? __( 'First step: publish WooCommerce to the sheet so both sides share the same rows. Daily sync keeps both in step from the Sync tab.', 'sheetsync-for-woocommerce' )
        : __( 'Applies sheet and WooCommerce changes in one run. You can change this anytime on the connection Sync tab.', 'sheetsync-for-woocommerce' );
    $sheetsync_wizard_first_sync_label = $sheetsync_wizard_first_link
        ? __( 'Publish catalog (first time)', 'sheetsync-for-woocommerce' )
        : __( 'Keep both sides in sync', 'sheetsync-for-woocommerce' );
}
?>
<div class="sheetsync-wrap sheetsync-wizard-wrap" role="main">
    <?php require __DIR__ . '/header.php'; ?>

    <div class="ss-wizard-header">
        <?php if ( ! empty( $sheetsync_branding['logo_url'] ) ) : ?>
            <img src="<?php echo esc_url( $sheetsync_branding['logo_url'] ); ?>" alt="" class="ss-wizard-brand-logo">
        <?php endif; ?>
        <h1><?php echo esc_html( $sheetsync_branding['title'] !== '' ? $sheetsync_branding['title'] : __( 'Setup Wizard', 'sheetsync-for-woocommerce' ) ); ?></h1>
        <p class="description"><?php esc_html_e( 'Connect Google Sheets to WooCommerce in a few guided steps.', 'sheetsync-for-woocommerce' ); ?></p>
        <div class="ss-wizard-actions-top">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync' ) ); ?>" class="button" id="ss-wizard-resume-later">
                <?php esc_html_e( 'Resume later', 'sheetsync-for-woocommerce' ); ?>
            </a>
            <button type="button" class="button" id="ss-wizard-skip-all">
                <?php esc_html_e( 'Skip wizard', 'sheetsync-for-woocommerce' ); ?>
            </button>
        </div>
    </div>

    <nav class="ss-wizard-stepper" aria-label="<?php esc_attr_e( 'Setup steps', 'sheetsync-for-woocommerce' ); ?>">
        <?php
        $sheetsync_steps = array(
            1 => __( 'Google', 'sheetsync-for-woocommerce' ),
            2 => __( 'Sheet', 'sheetsync-for-woocommerce' ),
            3 => __( 'Direction', 'sheetsync-for-woocommerce' ),
            4 => __( 'Connection', 'sheetsync-for-woocommerce' ),
            5 => __( 'Setup sheet', 'sheetsync-for-woocommerce' ),
            6 => __( 'First sync', 'sheetsync-for-woocommerce' ),
            7 => __( 'Auto updates', 'sheetsync-for-woocommerce' ),
        );
        foreach ( $sheetsync_steps as $num => $label ) :
            $is_active = ( $num === $sheetsync_wizard_step );
            $is_done   = $num < $sheetsync_wizard_step;
            ?>
            <button type="button" class="ss-wizard-step-pill <?php echo $is_active ? 'is-active' : ''; ?> <?php echo $is_done ? 'is-done' : ''; ?>"
                    data-step="<?php echo esc_attr( (string) $num ); ?>" <?php disabled( $num > $sheetsync_wizard_step ); ?>>
                <span class="ss-wizard-step-num"><?php echo esc_html( (string) $num ); ?></span>
                <?php echo esc_html( $label ); ?>
            </button>
        <?php endforeach; ?>
    </nav>

    <div class="sheetsync-card ss-wizard-panel" data-step="1" <?php echo 1 === $sheetsync_wizard_step ? '' : 'style="display:none;"'; ?> role="region" aria-labelledby="ss-wizard-step1-title">
        <h2 id="ss-wizard-step1-title"><?php esc_html_e( 'Step 1 — Connect Google', 'sheetsync-for-woocommerce' ); ?></h2>
        <?php
        $sheetsync_video_1 = (string) ( $sheetsync_videos[1] ?? '' );
        if ( $sheetsync_video_1 !== '' ) :
            ?>
        <div class="ss-wizard-video-wrap">
            <iframe src="<?php echo esc_url( $sheetsync_video_1 ); ?>" title="<?php esc_attr_e( 'Setup video', 'sheetsync-for-woocommerce' ); ?>" allowfullscreen loading="lazy"></iframe>
        </div>
        <?php endif; ?>
        <p class="description">
            <?php esc_html_e( 'Upload your Google Service Account JSON key below — this is the recommended setup for most stores.', 'sheetsync-for-woocommerce' ); ?>
        </p>
        <p class="description">
            <?php esc_html_e( 'Prefer Google sign-in instead? Use OAuth on SheetSync → Settings → Google & API.', 'sheetsync-for-woocommerce' ); ?>
            <a href="<?php echo esc_url( $sheetsync_settings_url ); ?>"><?php esc_html_e( 'Open Google settings', 'sheetsync-for-woocommerce' ); ?></a>
        </p>
        <?php if ( $sheetsync_account_email ) : ?>
            <p class="ss-wizard-connected">✅ <?php esc_html_e( 'Connected as:', 'sheetsync-for-woocommerce' ); ?> <strong><?php echo esc_html( $sheetsync_account_email ); ?></strong></p>
        <?php endif; ?>
        <div class="ss-json-upload-zone" id="ss-wizard-json-zone" tabindex="0">
            <span class="dashicons dashicons-upload"></span>
            <span><?php esc_html_e( 'Drop JSON key file or click to browse', 'sheetsync-for-woocommerce' ); ?></span>
            <input type="file" id="ss-wizard-json-file" accept=".json" hidden>
        </div>
        <textarea id="ss-wizard-json" class="ss-json-textarea" rows="6" placeholder='{"type":"service_account",...}'></textarea>
        <p>
            <button type="button" class="button button-primary" id="ss-wizard-save-google"><?php esc_html_e( 'Save & test Google', 'sheetsync-for-woocommerce' ); ?></button>
            <span id="ss-wizard-google-result" class="ss-inline-test-result" aria-live="polite"></span>
        </p>
        <?php if ( $sheetsync_account_email ) : ?>
        <div class="ss-share-instructions-card">
            <h3><?php esc_html_e( 'Share your sheet with', 'sheetsync-for-woocommerce' ); ?></h3>
            <code><?php echo esc_html( $sheetsync_account_email ); ?></code>
            <button type="button" class="button ss-copy-email-btn" data-email="<?php echo esc_attr( $sheetsync_account_email ); ?>"><?php esc_html_e( 'Copy email', 'sheetsync-for-woocommerce' ); ?></button>
        </div>
        <?php endif; ?>
    </div>

    <div class="sheetsync-card ss-wizard-panel" data-step="2" <?php echo 2 === $sheetsync_wizard_step ? '' : 'style="display:none;"'; ?> role="region" aria-labelledby="ss-wizard-step2-title">
        <h2 id="ss-wizard-step2-title"><?php esc_html_e( 'Step 2 — Connect your Sheet', 'sheetsync-for-woocommerce' ); ?></h2>
        <p>
            <button type="button" class="button" id="ss-wizard-create-sheet"><?php esc_html_e( 'Create new sheet for me', 'sheetsync-for-woocommerce' ); ?></button>
            <span id="ss-wizard-create-sheet-result" class="ss-inline-test-result" aria-live="polite"></span>
        </p>
        <p class="description"><?php esc_html_e( 'Or paste an existing Google Sheet URL below.', 'sheetsync-for-woocommerce' ); ?></p>
        <table class="form-table">
            <tr>
                <th><label for="ss-wizard-sheet-url"><?php esc_html_e( 'Google Sheet URL', 'sheetsync-for-woocommerce' ); ?></label></th>
                <td>
                    <input type="url" id="ss-wizard-sheet-url" class="large-text" placeholder="https://docs.google.com/spreadsheets/d/…/edit"
                           value="<?php
                           $sheetsync_wiz_sid = $sheetsync_wizard_state['spreadsheet_id'] ?? '';
                           echo esc_attr( $sheetsync_wiz_sid ? 'https://docs.google.com/spreadsheets/d/' . $sheetsync_wiz_sid . '/edit' : '' );
                           ?>">
                    <p class="description"><?php esc_html_e( 'Paste the full URL from your browser.', 'sheetsync-for-woocommerce' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="ss-wizard-sheet-id"><?php esc_html_e( 'Spreadsheet ID', 'sheetsync-for-woocommerce' ); ?></label></th>
                <td>
                    <input type="text" id="ss-wizard-sheet-id" class="regular-text" readonly>
                </td>
            </tr>
            <tr>
                <th><label for="ss-wizard-sheet-tab"><?php esc_html_e( 'Sheet tab', 'sheetsync-for-woocommerce' ); ?></label></th>
                <td>
                    <select id="ss-wizard-sheet-tab" class="regular-text" data-current="<?php echo esc_attr( $sheetsync_wizard_state['sheet_name'] ?? 'Sheet1' ); ?>">
                        <option value="Sheet1">Sheet1</option>
                    </select>
                    <input type="hidden" id="ss-wizard-sheet-tab-value" value="<?php echo esc_attr( $sheetsync_wizard_state['sheet_name'] ?? 'Sheet1' ); ?>">
                    <details class="ss-wizard-tab-custom" style="margin-top:10px;">
                        <summary><?php esc_html_e( 'Tab not in the list?', 'sheetsync-for-woocommerce' ); ?></summary>
                        <p class="description"><?php esc_html_e( 'Type the exact tab name as it appears in Google Sheets (case-sensitive).', 'sheetsync-for-woocommerce' ); ?></p>
                        <input type="text" id="ss-wizard-sheet-tab-custom" class="regular-text"
                               placeholder="<?php esc_attr_e( 'e.g. Products', 'sheetsync-for-woocommerce' ); ?>">
                    </details>
                </td>
            </tr>
        </table>
        <p>
            <button type="button" class="button button-primary" id="ss-wizard-test-sheet"><?php esc_html_e( 'Test sheet access', 'sheetsync-for-woocommerce' ); ?></button>
            <span id="ss-wizard-sheet-result" class="ss-inline-test-result" aria-live="polite"></span>
        </p>
    </div>

    <div class="sheetsync-card ss-wizard-panel" data-step="3" <?php echo 3 === $sheetsync_wizard_step ? '' : 'style="display:none;"'; ?>>
        <h2><?php esc_html_e( 'Step 3 — Choose direction', 'sheetsync-for-woocommerce' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Where should product changes flow? You can change this later on the connection Sync tab.', 'sheetsync-for-woocommerce' ); ?></p>
        <div class="ss-wizard-workflow-grid">
            <label class="ss-wizard-workflow-card <?php echo 'sheets_to_wc' === $sheetsync_wizard_direction ? 'is-selected' : ''; ?>">
                <input type="radio" name="ss_wizard_direction" value="sheets_to_wc" <?php checked( $sheetsync_wizard_direction, 'sheets_to_wc' ); ?>>
                <strong><?php esc_html_e( 'Sheet → WooCommerce', 'sheetsync-for-woocommerce' ); ?></strong>
                <span><?php esc_html_e( 'Edit products in Google Sheets — changes apply to your store.', 'sheetsync-for-woocommerce' ); ?></span>
                <span class="ss-wizard-plan-badge is-free"><?php esc_html_e( 'Free', 'sheetsync-for-woocommerce' ); ?></span>
            </label>
            <label class="ss-wizard-workflow-card <?php echo 'wc_to_sheets' === $sheetsync_wizard_direction ? 'is-selected' : ''; ?> <?php echo $sheetsync_is_pro ? '' : 'is-pro-locked'; ?>">
                <input type="radio" name="ss_wizard_direction" value="wc_to_sheets" <?php checked( $sheetsync_wizard_direction, 'wc_to_sheets' ); ?> <?php disabled( ! $sheetsync_is_pro ); ?>>
                <strong><?php esc_html_e( 'WooCommerce → Sheet', 'sheetsync-for-woocommerce' ); ?></strong>
                <span><?php esc_html_e( 'Publish store products to a Google Sheet.', 'sheetsync-for-woocommerce' ); ?></span>
                <span class="ss-wizard-plan-badge"><?php echo $sheetsync_is_pro ? esc_html__( 'Pro', 'sheetsync-for-woocommerce' ) : esc_html__( 'Pro required', 'sheetsync-for-woocommerce' ); ?></span>
            </label>
            <label class="ss-wizard-workflow-card <?php echo 'two_way' === $sheetsync_wizard_direction ? 'is-selected' : ''; ?> <?php echo $sheetsync_is_pro ? '' : 'is-pro-locked'; ?>">
                <input type="radio" name="ss_wizard_direction" value="two_way" <?php checked( $sheetsync_wizard_direction, 'two_way' ); ?> <?php disabled( ! $sheetsync_is_pro ); ?>>
                <strong><?php esc_html_e( 'Both ways', 'sheetsync-for-woocommerce' ); ?></strong>
                <span><?php esc_html_e( 'Edit in either place — keep sheet and store aligned.', 'sheetsync-for-woocommerce' ); ?></span>
                <span class="ss-wizard-plan-badge"><?php echo $sheetsync_is_pro ? esc_html__( 'Pro', 'sheetsync-for-woocommerce' ) : esc_html__( 'Pro required', 'sheetsync-for-woocommerce' ); ?></span>
            </label>
        </div>
        <?php if ( ! $sheetsync_is_pro ) : ?>
        <p class="description ss-wizard-pro-hint">
            <?php
            printf(
                /* translators: %s: upgrade URL */
                wp_kses_post( __( 'WooCommerce → Sheet and Both ways need <a href="%s">SheetSync Pro</a>. Sheet → WooCommerce works on the free plan.', 'sheetsync-for-woocommerce' ) ),
                esc_url( $sheetsync_upgrade )
            );
            ?>
        </p>
        <?php endif; ?>
    </div>

    <div class="sheetsync-card ss-wizard-panel" data-step="4" <?php echo 4 === $sheetsync_wizard_step ? '' : 'style="display:none;"'; ?>>
        <h2><?php esc_html_e( 'Step 4 — Create connection', 'sheetsync-for-woocommerce' ); ?></h2>
        <p>
            <label for="ss-wizard-conn-name"><?php esc_html_e( 'Connection name', 'sheetsync-for-woocommerce' ); ?></label><br>
            <input type="text" id="ss-wizard-conn-name" class="regular-text" value="<?php echo esc_attr( $sheetsync_wizard_state['connection_name'] ?? __( 'My products sheet', 'sheetsync-for-woocommerce' ) ); ?>">
        </p>
        <p>
            <button type="button" class="button button-primary" id="ss-wizard-create-connection"><?php esc_html_e( 'Create connection', 'sheetsync-for-woocommerce' ); ?></button>
            <span id="ss-wizard-conn-result" class="ss-inline-test-result" aria-live="polite"></span>
        </p>
    </div>

    <div class="sheetsync-card ss-wizard-panel" data-step="5" <?php echo 5 === $sheetsync_wizard_step ? '' : 'style="display:none;"'; ?>>
        <h2><?php esc_html_e( 'Step 5 — Setup sheet', 'sheetsync-for-woocommerce' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Automatically write headers, example rows, and field mapping — or skip if your sheet is already set up.', 'sheetsync-for-woocommerce' ); ?></p>
        <p>
            <button type="button" class="button button-primary ss-bootstrap-sheet-btn" id="ss-wizard-bootstrap"
                    data-connection-id="<?php echo esc_attr( (string) (int) ( $sheetsync_wizard_state['connection_id'] ?? 0 ) ); ?>">
                <?php esc_html_e( 'Setup My Sheet Automatically', 'sheetsync-for-woocommerce' ); ?>
            </button>
            <button type="button" class="button" id="ss-wizard-skip-sheet-setup"><?php esc_html_e( 'Use existing sheet', 'sheetsync-for-woocommerce' ); ?></button>
        </p>
        <div id="ss-wizard-bootstrap-output"></div>
    </div>

    <div class="sheetsync-card ss-wizard-panel" data-step="6" <?php echo 6 === $sheetsync_wizard_step ? '' : 'style="display:none;"'; ?>>
        <h2><?php esc_html_e( 'Step 6 — First sync', 'sheetsync-for-woocommerce' ); ?></h2>
        <p class="description"><?php echo esc_html( $sheetsync_wizard_first_sync_desc ); ?></p>
        <p>
            <button type="button" class="button button-primary ss-sync-btn" id="ss-wizard-first-sync"
                    data-connection-id="<?php echo esc_attr( (string) (int) ( $sheetsync_wizard_state['connection_id'] ?? 0 ) ); ?>"
                    data-sync-strategy="smart"
                    data-sync-intent="<?php echo esc_attr( $sheetsync_wizard_intent ); ?>"
                    data-sync-job-direction="<?php echo esc_attr( $sheetsync_wizard_job_direction ); ?>"
                    data-wizard-direction="<?php echo esc_attr( $sheetsync_wizard_direction ); ?>">
                <?php echo esc_html( $sheetsync_wizard_first_sync_label ); ?>
            </button>
            <button type="button" class="button" id="ss-wizard-skip-first-sync"><?php esc_html_e( 'Skip for now', 'sheetsync-for-woocommerce' ); ?></button>
        </p>
        <p class="description"><?php esc_html_e( 'SheetSync links rows automatically on the first run when needed — no engine settings required.', 'sheetsync-for-woocommerce' ); ?></p>
        <div id="ss-wizard-sync-output"></div>
    </div>

    <div class="sheetsync-card ss-wizard-panel" data-step="7" <?php echo 7 === $sheetsync_wizard_step ? '' : 'style="display:none;"'; ?>>
        <h2><?php esc_html_e( 'Step 7 — Automatic updates (optional)', 'sheetsync-for-woocommerce' ); ?></h2>

        <?php
        $sheetsync_lights_title   = __( 'Setup health check', 'sheetsync-for-woocommerce' );
        $sheetsync_lights_compact = false;
        require __DIR__ . '/fragments/setup-health-lights.php';
        ?>

        <p class="description"><?php esc_html_e( 'Optional: turn on Automatic sync on the Sync tab after you finish — one switch enables Smart Poll, schedule, and push-on-save for your direction.', 'sheetsync-for-woocommerce' ); ?></p>
        <?php if ( sheetsync_is_pro() ) : ?>
            <label><input type="checkbox" id="ss-wizard-enable-realtime"> <?php esc_html_e( 'Enable automatic sync for this connection', 'sheetsync-for-woocommerce' ); ?></label>
            <div id="ss-wizard-realtime-hint" class="ss-share-instructions-card" style="display:none; margin-top:12px;">
                <p><?php esc_html_e( 'Automatic sync is on. For instant webhook updates, open Advanced settings → Apps Script checklist.', 'sheetsync-for-woocommerce' ); ?></p>
            </div>
        <?php else : ?>
            <p><?php esc_html_e( 'Automatic background updates are a Pro feature. You can enable them later from the connection Sync tab.', 'sheetsync-for-woocommerce' ); ?></p>
        <?php endif; ?>
        <?php
        $sheetsync_as_health = function_exists( 'sheetsync_get_action_scheduler_health' )
            ? sheetsync_get_action_scheduler_health()
            : array( 'ok' => true, 'past_due' => 0 );
        $sheetsync_wizard_needs_cron = (int) ( $sheetsync_as_health['past_due'] ?? 0 ) >= 5
            && class_exists( 'SheetSync_External_Cron', false );
        if ( $sheetsync_wizard_needs_cron ) :
            ?>
        <div class="notice notice-warning inline ss-wizard-cron-hint" style="margin:16px 0;padding:12px 14px;">
            <p style="margin:0 0 8px;">
                <strong><?php esc_html_e( 'Background tasks need help', 'sheetsync-for-woocommerce' ); ?></strong> —
                <?php esc_html_e( 'When you finish, we will enable Background Cron and show a server URL to copy. Large syncs work best without keeping a browser tab open.', 'sheetsync-for-woocommerce' ); ?>
            </p>
            <?php if ( SheetSync_External_Cron::is_enabled() ) : ?>
            <p class="description" style="margin:0;">
                <?php esc_html_e( 'Cron URL:', 'sheetsync-for-woocommerce' ); ?>
                <code><?php echo esc_html( SheetSync_External_Cron::get_rest_endpoint_url() ); ?></code>
            </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <p style="margin-top:20px;">
            <button type="button" class="button button-primary" id="ss-wizard-finish"><?php esc_html_e( 'Finish setup', 'sheetsync-for-woocommerce' ); ?></button>
            <button type="button" class="button" id="ss-wizard-add-another"><?php esc_html_e( 'Add another connection', 'sheetsync-for-woocommerce' ); ?></button>
        </p>
        <?php if ( $sheetsync_support_url && empty( $sheetsync_branding['hide_support'] ) ) : ?>
            <p class="description"><a href="<?php echo esc_url( $sheetsync_support_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Need help? Contact support', 'sheetsync-for-woocommerce' ); ?></a></p>
        <?php endif; ?>
    </div>

    <div class="ss-wizard-nav" role="navigation" aria-label="<?php esc_attr_e( 'Wizard navigation', 'sheetsync-for-woocommerce' ); ?>">
        <button type="button" class="button" id="ss-wizard-prev" <?php disabled( 1 === $sheetsync_wizard_step ); ?>><?php esc_html_e( '← Back', 'sheetsync-for-woocommerce' ); ?></button>
        <button type="button" class="button button-primary" id="ss-wizard-next"><?php esc_html_e( 'Continue →', 'sheetsync-for-woocommerce' ); ?></button>
    </div>
</div>
