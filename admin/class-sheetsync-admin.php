<?php
/**
 * Admin controller — menus, settings, AJAX handlers, asset enqueuing.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Pro_Admin', false ) ) :

class SheetSync_Pro_Admin {

    public function __construct() {
        if ( class_exists( 'SheetSync_Freemius_Integration', false ) ) {
            SheetSync_Freemius_Integration::on_before_admin_menu_init( array( $this, 'register_menus' ) );
        } else {
            add_action( 'admin_menu', array( $this, 'register_menus' ), 5 );
        }
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_post_sheetsync_save_settings',    array( $this, 'handle_save_settings' ) );
        add_action( 'admin_post_sheetsync_save_connection',  array( $this, 'handle_save_connection' ) );
        add_action( 'admin_post_sheetsync_delete_connection',array( $this, 'handle_delete_connection' ) );
        add_action( 'admin_post_sheetsync_save_field_map',   array( $this, 'handle_save_field_map' ) );
        add_action( 'admin_post_sheetsync_toggle_auto_sync',  array( $this, 'handle_toggle_auto_sync' ) );
        add_action( 'admin_post_sheetsync_save_sync_options', array( $this, 'handle_save_sync_options' ) );
        add_action( 'admin_post_sheetsync_save_schedule',     array( $this, 'handle_save_schedule_proxy' ) );
        add_action( 'admin_post_sheetsync_clear_logs',        array( $this, 'handle_clear_logs' ) );
        add_action( 'admin_post_sheetsync_regenerate_cron_token', array( $this, 'handle_regenerate_cron_token' ) );

        // AJAX
        add_action( 'wp_ajax_sheetsync_manual_sync',    array( $this, 'ajax_manual_sync' ) );
        add_action( 'wp_ajax_sheetsync_set_sync_direction', array( $this, 'ajax_set_sync_direction' ) );
        add_action( 'wp_ajax_sheetsync_toggle_automatic_sync', array( $this, 'ajax_toggle_automatic_sync' ) );
        add_action( 'wp_ajax_sheetsync_schedule_off_peak_publish', array( $this, 'ajax_schedule_off_peak_publish' ) );
        add_action( 'wp_ajax_sheetsync_send_email_report', array( $this, 'ajax_send_email_report' ) );
        add_action( 'wp_ajax_sheetsync_run_match_diagnostics', array( $this, 'ajax_run_match_diagnostics' ) );
        add_action( 'admin_post_sheetsync_save_off_peak_publish', array( $this, 'handle_save_off_peak_publish' ) );
        add_action( 'wp_ajax_sheetsync_sync_tick',     array( $this, 'ajax_sync_tick' ) );
        add_action( 'wp_ajax_sheetsync_drain_job',     array( $this, 'ajax_drain_job' ) );
        add_action( 'wp_ajax_sheetsync_note_host_timeout', array( $this, 'ajax_note_host_timeout' ) );
        add_action( 'wp_ajax_sheetsync_get_headers',     array( $this, 'ajax_get_headers' ) );
        add_action( 'wp_ajax_sheetsync_import_from_sheet', array( $this, 'ajax_import_from_sheet' ) );
        add_action( 'wp_ajax_sheetsync_test_connection',array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_sheetsync_test_google_auth', array( $this, 'ajax_test_google_auth' ) );
        add_action( 'wp_ajax_sheetsync_test_external_cron', array( $this, 'ajax_test_external_cron' ) );
        add_action( 'wp_ajax_sheetsync_import_headers',  array( $this, 'ajax_import_headers' ) );
        add_action( 'wp_ajax_sheetsync_write_export_headers', array( $this, 'ajax_write_export_headers' ) );
        add_action( 'wp_ajax_sheetsync_apply_sheet_formatting', array( $this, 'ajax_apply_sheet_formatting' ) );
        add_action( 'wp_ajax_sheetsync_check_sheet',     array( $this, 'ajax_check_sheet' ) );
        add_action( 'wp_ajax_sheetsync_write_template',  array( $this, 'ajax_write_template' ) );
        add_action( 'wp_ajax_sheetsync_bootstrap_connection', array( $this, 'ajax_bootstrap_connection' ) );
        add_action( 'wp_ajax_sheetsync_test_webhook', array( $this, 'ajax_test_webhook' ) );
        add_action( 'wp_ajax_sheetsync_resolve_conflict', array( $this, 'ajax_resolve_conflict' ) );
        add_action( 'wp_ajax_sheetsync_test_image_url',  array( $this, 'ajax_test_image_url' ) );
        add_action( 'wp_ajax_sheetsync_get_connection_maps', array( $this, 'ajax_get_connection_maps' ) );
        add_action( 'wp_ajax_sheetsync_reveal_webhook_secret', array( $this, 'ajax_reveal_webhook_secret' ) );

        // Setup wizard
        add_action( 'wp_ajax_sheetsync_save_wizard_state', array( $this, 'ajax_save_wizard_state' ) );
        add_action( 'wp_ajax_sheetsync_wizard_save_google', array( $this, 'ajax_wizard_save_google' ) );
        add_action( 'wp_ajax_sheetsync_wizard_create_connection', array( $this, 'ajax_wizard_create_connection' ) );
        add_action( 'wp_ajax_sheetsync_wizard_finish', array( $this, 'ajax_wizard_finish' ) );
        add_action( 'wp_ajax_sheetsync_wizard_skip', array( $this, 'ajax_wizard_skip' ) );
        add_action( 'wp_ajax_sheetsync_create_spreadsheet', array( $this, 'ajax_create_spreadsheet' ) );
        add_action( 'wp_ajax_sheetsync_wizard_add_another', array( $this, 'ajax_wizard_add_another' ) );

        // Dashboard settings persistence
        add_action( 'wp_ajax_sheetsync_save_dashboard_settings', array( $this, 'ajax_save_dashboard_settings' ) );
        add_action( 'wp_ajax_sheetsync_load_dashboard_settings', array( $this, 'ajax_load_dashboard_settings' ) );

        // Admin notices
        add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
    }

    // ─── Menus ────────────────────────────────────────────────────────────────

    public function register_menus(): void {
        $cap = function_exists( 'sheetsync_admin_menu_capability' )
            ? sheetsync_admin_menu_capability()
            : 'manage_woocommerce';

        add_menu_page(
            __( 'SheetSync', 'sheetsync-for-woocommerce' ),
            __( 'SheetSync', 'sheetsync-for-woocommerce' ),
            $cap,
            'sheetsync',
            array( $this, 'render_connections_page' ),
            'dashicons-table-col-after',
            56
        );

        add_submenu_page(
            'sheetsync',
            __( 'Connections', 'sheetsync-for-woocommerce' ),
            __( 'Connections', 'sheetsync-for-woocommerce' ),
            $cap,
            'sheetsync',
            array( $this, 'render_connections_page' )
        );

        add_submenu_page(
            'sheetsync',
            __( 'Setup Wizard', 'sheetsync-for-woocommerce' ),
            __( '✨ Setup Wizard', 'sheetsync-for-woocommerce' ),
            $cap,
            'sheetsync-setup',
            array( $this, 'render_setup_wizard_page' )
        );

        add_submenu_page(
            'sheetsync',
            __( 'Settings', 'sheetsync-for-woocommerce' ),
            __( 'Settings', 'sheetsync-for-woocommerce' ),
            $cap,
            'sheetsync-settings',
            array( $this, 'render_settings_page' )
        );

        add_submenu_page(
            'sheetsync',
            __( 'Sync Logs', 'sheetsync-for-woocommerce' ),
            __( 'Sync Logs', 'sheetsync-for-woocommerce' ),
            $cap,
            'sheetsync-logs',
            array( $this, 'render_logs_page' )
        );

        add_submenu_page(
            'sheetsync',
            __( 'Sync Reports', 'sheetsync-for-woocommerce' ),
            __( 'Sync Reports', 'sheetsync-for-woocommerce' ),
            $cap,
            'sheetsync-reports',
            array( $this, 'render_sync_reports_page' )
        );

        add_submenu_page(
            'sheetsync',
            __( 'Match Diagnostics', 'sheetsync-for-woocommerce' ),
            __( 'Match Diagnostics', 'sheetsync-for-woocommerce' ),
            $cap,
            'sheetsync-match-diagnostics',
            array( $this, 'render_match_diagnostics_page' )
        );

        $conflict_count = function_exists( 'sheetsync_count_all_conflicts' ) ? sheetsync_count_all_conflicts() : 0;
        $conflicts_label = __( 'Sync Conflicts', 'sheetsync-for-woocommerce' );
        if ( $conflict_count > 0 ) {
            $conflicts_label .= sprintf(
                ' <span class="awaiting-mod update-plugins count-%d"><span class="plugin-count">%d</span></span>',
                min( 99, $conflict_count ),
                min( 99, $conflict_count )
            );
        }
        add_submenu_page(
            'sheetsync',
            __( 'Sync Conflicts', 'sheetsync-for-woocommerce' ),
            $conflicts_label,
            $cap,
            'sheetsync-conflicts',
            array( $this, 'render_conflicts_inbox_page' )
        );

        if ( sheetsync_is_pro() ) {
            add_submenu_page(
                'sheetsync',
                __( 'Store Reports', 'sheetsync-for-woocommerce' ),
                __( '📊 Store Reports', 'sheetsync-for-woocommerce' ),
                $cap,
                'sheetsync-dashboards',
                array( $this, 'render_dashboards_page' )
            );
        }

        add_submenu_page(
            'sheetsync',
            __( 'Setup Health', 'sheetsync-for-woocommerce' ),
            __( '🩺 Setup Health', 'sheetsync-for-woocommerce' ),
            $cap,
            'sheetsync-setup-health',
            array( $this, 'render_setup_health_page' )
        );

    }

    // ─── Assets ───────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        $sheetsync_pages = array(
            'toplevel_page_sheetsync',
            'sheetsync_page_sheetsync-setup',
            'sheetsync_page_sheetsync-settings',
            'sheetsync_page_sheetsync-logs',
            'sheetsync_page_sheetsync-reports',
            'sheetsync_page_sheetsync-match-diagnostics',
            'sheetsync_page_sheetsync-conflicts',
            'sheetsync_page_sheetsync-import-export',
            'sheetsync_page_sheetsync-dashboards',
            'sheetsync_page_sheetsync-setup-health',
        );

        $is_sheetsync_page = in_array( $hook, $sheetsync_pages, true )
            || ( isset( $_GET['page'] ) && strpos( sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) ), 'sheetsync' ) !== false ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( ! $is_sheetsync_page ) return;

        $watch_connection_id = 0;
        if ( isset( $_GET['page'], $_GET['sheetsync_action'], $_GET['connection_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $page_slug = sanitize_text_field( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $action    = sanitize_text_field( wp_unslash( $_GET['sheetsync_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( 'sheetsync' === $page_slug && 'edit' === $action ) {
                $watch_connection_id = absint( wp_unslash( $_GET['connection_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            }
        }

        $export_confirm_context = null;
        if ( $watch_connection_id > 0 && function_exists( 'sheetsync_get_export_confirm_context' ) ) {
            $export_confirm_context = sheetsync_get_export_confirm_context( $watch_connection_id );
        }

        $scheduler_degraded = false;
        if ( function_exists( 'sheetsync_get_action_scheduler_health' ) ) {
            $scheduler_degraded = ! ( sheetsync_get_action_scheduler_health()['ok'] ?? true );
        }

        wp_enqueue_style(
            'sheetsync-admin',
            SHEETSYNC_PRO_URL . 'admin/css/admin-style.css',
            array(),
            filemtime( SHEETSYNC_PRO_DIR . 'admin/css/admin-style.css' )
        );

        wp_enqueue_script(
            'sheetsync-admin',
            SHEETSYNC_PRO_URL . 'admin/js/admin-script.js',
            array( 'jquery' ),
            filemtime( SHEETSYNC_PRO_DIR . 'admin/js/admin-script.js' ),
            true
        );

        // FIX H-5: All user-facing JS strings are localised here.
        // No hardcoded Bengali (or any other language) strings may appear in JS files.
        wp_localize_script( 'sheetsync-admin', 'sheetsync', array(
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'rest_url'    => esc_url_raw( rest_url( 'sheetsync/v1/' ) ),
            'rest_nonce'  => wp_create_nonce( 'wp_rest' ),
            'nonce'       => wp_create_nonce( 'sheetsync_nonce' ),
            'sync_poll_ms'  => class_exists( 'SheetSync_Host_Profile', false ) ? SheetSync_Host_Profile::admin_poll_interval_ms() : 2500,
            'sync_drain_ms' => class_exists( 'SheetSync_Host_Profile', false ) ? SheetSync_Host_Profile::admin_drain_interval_ms() : 3000,
            'sync_poll_ms_slow'  => (int) apply_filters( 'sheetsync_server_friendly_poll_ms', 4500 ),
            'sync_drain_ms_slow' => (int) apply_filters( 'sheetsync_server_friendly_drain_ms', 5500 ),
            'server_friendly'    => class_exists( 'SheetSync_Host_Profile', false ) && SheetSync_Host_Profile::is_server_friendly(),
            'is_pro'              => sheetsync_is_pro(),
            'upgrade_url'         => function_exists( 'sheetsync_upgrade_url' ) ? sheetsync_upgrade_url() : '#',
            'watch_connection_id' => $watch_connection_id,
            'logs_url'            => admin_url( 'admin.php?page=sheetsync-logs' ),
            'scheduler_degraded'  => $scheduler_degraded,
            'export_confirm'      => $export_confirm_context,
            'i18n'                => array(
                'syncing'               => __( 'Syncing…', 'sheetsync-for-woocommerce' ),
                'sync_queued'           => __( 'Starting sync… keep this tab open.', 'sheetsync-for-woocommerce' ),
                'sync_in_progress'      => __( 'Sync in progress: %1$d / %2$d rows', 'sheetsync-for-woocommerce' ),
                'sync_export_progress'  => __( 'Export in progress: %1$d / %2$d rows', 'sheetsync-for-woocommerce' ),
                'sync_pull_progress'    => __( 'Reading sheet: %1$d / %2$d rows (%3$d updated)', 'sheetsync-for-woocommerce' ),
                'sync_pull_scanned'     => __( 'Scanned %1$d / %2$d rows · %3$d updated', 'sheetsync-for-woocommerce' ),
                'sync_unchanged_rows'   => __( '%d unchanged', 'sheetsync-for-woocommerce' ),
                'sync_smart_diff_hint'  => __( 'Only changed rows are written. The first sync always links all rows when needed.', 'sheetsync-for-woocommerce' ),
                'first_sync_label'      => __( 'First sync (full link)', 'sheetsync-for-woocommerce' ),
                'progress_full_relink'  => __( 'Full re-link', 'sheetsync-for-woocommerce' ),
                'progress_changes_only' => __( 'Changes only', 'sheetsync-for-woocommerce' ),
                'requires_tab_open_hint' => __( 'Background tasks are slow — keep this tab open until sync finishes, or set up Background Cron in Settings.', 'sheetsync-for-woocommerce' ),
                'sync_background_hint'  => __( 'Sync continues in the background. You can close this tab — optional Background Cron in Settings speeds up large catalogs.', 'sheetsync-for-woocommerce' ),
                'sync_pull_rows'        => __( 'Importing from sheet', 'sheetsync-for-woocommerce' ),
                'sync_partial_done'     => __( 'Export mostly complete — a few rows may need another sync. See Sync Logs.', 'sheetsync-for-woocommerce' ),
                'sync_draining_tab'     => __( 'Processing batch in this tab…', 'sheetsync-for-woocommerce' ),
                'scheduler_stalled'     => __( 'Background queue stalled — this tab will keep retrying. You can also open WooCommerce → Status → Scheduled Actions, filter Group “sheetsync”, and click Run.', 'sheetsync-for-woocommerce' ),
                'sync_complete'         => __( 'Sync Complete!', 'sheetsync-for-woocommerce' ),
                'sync_error'            => __( 'Sync failed. Check logs.', 'sheetsync-for-woocommerce' ),
                'view_logs'             => __( 'View sync logs', 'sheetsync-for-woocommerce' ),
                'server_busy_hint'      => __( 'Your web server paused the request — usually temporary. Keep this tab open to retry, or set up Background Cron in Settings for large catalogs.', 'sheetsync-for-woocommerce' ),
                'testing'               => __( 'Testing…', 'sheetsync-for-woocommerce' ),
                'confirm_delete'        => __( 'Delete this connection and all its field maps?', 'sheetsync-for-woocommerce' ),
                'confirm_full_export'   => __( 'Full catalog export writes every WooCommerce product to the sheet and removes stale rows when it completes. Existing sheet rows may be cleared. Continue?', 'sheetsync-for-woocommerce' ),
                'confirm_full_export_detail' => __( 'Full export will write %1$d sheet rows (%2$d products + %3$d variations). Stale rows may be removed. Estimated time: ~%4$d min. Continue?', 'sheetsync-for-woocommerce' ),
                'confirm_pull_before_export' => __( 'Pull sheet changes into WooCommerce BEFORE overwriting the sheet? (Recommended for two-way sync)', 'sheetsync-for-woocommerce' ),
                'confirm_scheduler_degraded' => __( 'Background tasks are overdue. Sync may run slowly or stall. Start anyway in degraded mode?', 'sheetsync-for-woocommerce' ),
                'confirm_scheduler_degraded_large' => __( 'This catalog has ~%1$d rows and background tasks are overdue (~%2$d min when healthy, ~%3$d min in slow browser mode). Fix Scheduled Actions first (recommended), or run slow sync and keep this tab open until it finishes.', 'sheetsync-for-woocommerce' ),
                'direction_saving'    => __( 'Saving direction…', 'sheetsync-for-woocommerce' ),
                'direction_saved'     => __( 'Direction saved. Reloading…', 'sheetsync-for-woocommerce' ),
                'direction_pro_required' => __( 'Upgrade to Pro to use this sync direction.', 'sheetsync-for-woocommerce' ),
                'server_friendly_hint'  => __( 'Server-friendly mode: slower batches to reduce load while background tasks recover.', 'sheetsync-for-woocommerce' ),
                'sync_job_label'        => __( 'Job #%d', 'sheetsync-for-woocommerce' ),
                'sync_retry'            => __( 'Run Sync Again', 'sheetsync-for-woocommerce' ),
                'fix_connection'        => __( 'Fix connection', 'sheetsync-for-woocommerce' ),
                'sync_keep_tab_open'    => __( 'Keep this tab open while sync runs. SheetSync works in small automatic batches — no server setup required.', 'sheetsync-for-woocommerce' ),
                'sync_connection_timeout' => __( 'Brief connection pause — retrying automatically. Keep this tab open; sync will continue.', 'sheetsync-for-woocommerce' ),
                // H-5: Previously hardcoded in Bengali — now properly translatable.
                'field_map_required'    => __( 'Please enter a Sheet Column for at least one field (e.g. A, B, C).', 'sheetsync-for-woocommerce' ),
                'identity_column_required' => __( 'Map SKU (column A) or Product ID (column B) so each sheet row can match a WooCommerce product.', 'sheetsync-for-woocommerce' ),
                'key_field_empty_confirm' => __( 'A Key Field is checked but its Sheet Column is empty. Continue anyway?', 'sheetsync-for-woocommerce' ),
                'importing'             => __( 'Importing…', 'sheetsync-for-woocommerce' ),
                'fields_matched'        => __( 'field(s) matched!', 'sheetsync-for-woocommerce' ),
                'unmatched'             => __( 'unmatched', 'sheetsync-for-woocommerce' ),
                'checking_sheet'        => __( 'Checking sheet…', 'sheetsync-for-woocommerce' ),
                'check_passed'          => __( 'Ready to sync', 'sheetsync-for-woocommerce' ),
                'check_failed'          => __( 'Fix issues before sync', 'sheetsync-for-woocommerce' ),
                'check_stats_simple'    => __( 'simple', 'sheetsync-for-woocommerce' ),
                'check_stats_parents'   => __( 'variable parents', 'sheetsync-for-woocommerce' ),
                'check_stats_variations'=> __( 'variations', 'sheetsync-for-woocommerce' ),
                'row_label'             => __( 'Row', 'sheetsync-for-woocommerce' ),
                'check_summary_products' => __( 'Products', 'sheetsync-for-woocommerce' ),
                'check_summary_errors'   => __( 'Errors', 'sheetsync-for-woocommerce' ),
                'check_summary_warnings' => __( 'Warnings', 'sheetsync-for-woocommerce' ),
                'check_total_rows'       => __( 'Total rows', 'sheetsync-for-woocommerce' ),
                'check_invalid_prices'   => __( 'Invalid prices', 'sheetsync-for-woocommerce' ),
                'check_duplicate_sku'    => __( 'Duplicate SKU', 'sheetsync-for-woocommerce' ),
                'check_missing_required' => __( 'Missing required fields', 'sheetsync-for-woocommerce' ),
                'check_missing_attributes' => __( 'Missing attributes', 'sheetsync-for-woocommerce' ),
                'check_missing_terms'    => __( 'Missing attribute values', 'sheetsync-for-woocommerce' ),
                'check_column'           => __( 'Column', 'sheetsync-for-woocommerce' ),
                'check_value'            => __( 'Value', 'sheetsync-for-woocommerce' ),
                'check_expected'         => __( 'Expected', 'sheetsync-for-woocommerce' ),
                'diag_running'          => __( 'Running diagnostics…', 'sheetsync-for-woocommerce' ),
                'diag_done'             => __( 'Diagnostics updated.', 'sheetsync-for-woocommerce' ),
                'diag_failed'           => __( 'Diagnostics failed.', 'sheetsync-for-woocommerce' ),
                'diag_no_issues'        => __( 'No issues found.', 'sheetsync-for-woocommerce' ),
                'diag_score_healthy'    => __( 'Matching looks healthy for this connection.', 'sheetsync-for-woocommerce' ),
                'diag_score_attention'  => __( 'Some matching issues need attention before large syncs.', 'sheetsync-for-woocommerce' ),
                'diag_score_critical'   => __( 'Critical matching issues — fix identity mapping before syncing.', 'sheetsync-for-woocommerce' ),
                'phase_label'           => __( 'Phase', 'sheetsync-for-woocommerce' ),
                'rows_label'            => __( 'rows', 'sheetsync-for-woocommerce' ),
                'failed_rows_label'     => __( 'rows failed', 'sheetsync-for-woocommerce' ),
                'sync_eta_remaining'    => __( '~%d min remaining (estimate)', 'sheetsync-for-woocommerce' ),
                'sync_eta_degraded'     => __( '~%d min remaining (background queue slow — keep this tab open)', 'sheetsync-for-woocommerce' ),
                'scheduler_blocked'     => __( 'Background queue is not running — fix Scheduled Actions before large syncs.', 'sheetsync-for-woocommerce' ),
                'media_queue_notice'    => __( 'Product images download in the background after sync — refresh the product page in a minute if images are missing.', 'sheetsync-for-woocommerce' ),
                'two_way_race_warning'  => __( 'Avoid editing the same product in WooCommerce and the sheet while sync is running — use conflict settings below if both sides change.', 'sheetsync-for-woocommerce' ),
                'large_catalog_eta'     => __( 'Large catalog (~%1$d rows): background sync may take ~%2$d minutes. Keep this tab open or rely on Scheduled Actions.', 'sheetsync-for-woocommerce' ),
                'writing_headers'       => __( 'Writing headers…', 'sheetsync-for-woocommerce' ),
                'applying_formatting'   => __( 'Applying filters & styling…', 'sheetsync-for-woocommerce' ),
                'test_auth'             => __( 'Test Google Connection', 'sheetsync-for-woocommerce' ),
                'test_auth_success'     => __( 'Google authentication successful.', 'sheetsync-for-woocommerce' ),
                'test_auth_failed'      => __( 'Google authentication failed.', 'sheetsync-for-woocommerce' ),
                'copy_email'            => __( 'Copy email for sharing', 'sheetsync-for-woocommerce' ),
                'copied'                => __( 'Copied!', 'sheetsync-for-woocommerce' ),
                'reveal_secret'         => __( 'Reveal', 'sheetsync-for-woocommerce' ),
                'hide_secret'           => __( 'Hide', 'sheetsync-for-woocommerce' ),
                'secret_load_failed'    => __( 'Failed to load webhook secret.', 'sheetsync-for-woocommerce' ),
                'open_in_sheets'        => __( 'Open in Google Sheets', 'sheetsync-for-woocommerce' ),
                'paste_url_or_id'       => __( 'Paste a full Google Sheets URL or spreadsheet ID.', 'sheetsync-for-woocommerce' ),
                'drop_json_here'        => __( 'Drop your JSON key file here, or click to browse', 'sheetsync-for-woocommerce' ),
                'invalid_json_file'     => __( 'Please upload a valid Service Account JSON key file.', 'sheetsync-for-woocommerce' ),
                'invalid_json_paste'    => __( 'Clipboard does not contain valid Service Account JSON. Copy the full downloaded .json file contents.', 'sheetsync-for-woocommerce' ),
                'json_loaded'           => __( 'JSON loaded — click Save Settings below.', 'sheetsync-for-woocommerce' ),
                'test_connection'       => __( 'Test Connection', 'sheetsync-for-woocommerce' ),
                'share_sheet_title'     => __( 'Share your sheet with this email', 'sheetsync-for-woocommerce' ),
                'bootstrap_title'       => __( 'Setting up your sheet…', 'sheetsync-for-woocommerce' ),
                'bootstrap_btn'         => __( 'Setup My Sheet Automatically', 'sheetsync-for-woocommerce' ),
                'bootstrap_done'        => __( 'Sheet setup complete!', 'sheetsync-for-woocommerce' ),
                'bootstrap_failed'      => __( 'Setup stopped — see details below.', 'sheetsync-for-woocommerce' ),
                'webhook_test'          => __( 'Test webhook from WordPress', 'sheetsync-for-woocommerce' ),
                'webhook_test_ok'       => __( 'Webhook endpoint is reachable and secret is valid.', 'sheetsync-for-woocommerce' ),
                'webhook_verified_label' => __( 'Active — webhook ready (run setupTrigger in Apps Script)', 'sheetsync-for-woocommerce' ),
                'webhook_test_fail'     => __( 'Webhook test failed.', 'sheetsync-for-woocommerce' ),
                'map_profile_simple'    => __( 'Simple (5 columns)', 'sheetsync-for-woocommerce' ),
                'map_profile_full'      => __( 'Full (recommended)', 'sheetsync-for-woocommerce' ),
                'map_profile_custom'    => __( 'Custom', 'sheetsync-for-woocommerce' ),
                'automatic_sync_saving' => __( 'Saving automatic sync…', 'sheetsync-for-woocommerce' ),
                'automatic_sync_error'  => __( 'Could not update automatic sync.', 'sheetsync-for-woocommerce' ),
                'off_peak_scheduling'   => __( 'Scheduling…', 'sheetsync-for-woocommerce' ),
                'off_peak_scheduled'    => __( 'Off-peak full publish scheduled.', 'sheetsync-for-woocommerce' ),
                'off_peak_error'        => __( 'Could not schedule off-peak publish.', 'sheetsync-for-woocommerce' ),
                'email_report_sending'  => __( 'Sending report…', 'sheetsync-for-woocommerce' ),
                'email_report_sent'     => __( 'Report emailed.', 'sheetsync-for-woocommerce' ),
                'email_report_error'    => __( 'Could not send report email.', 'sheetsync-for-woocommerce' ),
            ),
            'webhook_endpoint' => esc_url_raw( rest_url( 'sheetsync/v1/webhook' ) ),
            'recommended_map' => SheetSync_Field_Mapper::get_recommended_product_columns(),
            'minimal_map'     => SheetSync_Field_Mapper::get_minimal_product_columns(),
            'sample_csv_url'  => esc_url_raw( plugins_url( 'sample-import-10-products.csv', SHEETSYNC_PRO_FILE ) ),
            'simple_csv_url'  => esc_url_raw( plugins_url( 'sample-import-simple-5-columns.csv', SHEETSYNC_PRO_FILE ) ),
            'google_template_url' => esc_url_raw( (string) ( get_option( 'sheetsync_settings', array() )['google_template_url'] ?? '' ) ),
            'currency_symbol' => html_entity_decode( get_woocommerce_currency_symbol() ),
            'account_email'   => SheetSync_Google_Auth::get_account_email(),
            'share_steps'     => SheetSync_Google_Auth::get_share_instructions( SheetSync_Google_Auth::get_account_email() ),
        ) );

        if ( $hook === 'sheetsync_page_sheetsync-setup' ) {
            wp_enqueue_style(
                'sheetsync-setup-wizard',
                SHEETSYNC_PRO_URL . 'admin/css/setup-wizard.css',
                array( 'sheetsync-admin' ),
                filemtime( SHEETSYNC_PRO_DIR . 'admin/css/setup-wizard.css' )
            );

            wp_enqueue_script(
                'sheetsync-setup-wizard',
                SHEETSYNC_PRO_URL . 'admin/js/setup-wizard.js',
                array( 'jquery', 'sheetsync-admin' ),
                filemtime( SHEETSYNC_PRO_DIR . 'admin/js/setup-wizard.js' ),
                true
            );

            $wizard_state = function_exists( 'sheetsync_get_wizard_state' ) ? sheetsync_get_wizard_state() : array();
            wp_localize_script( 'sheetsync-setup-wizard', 'sheetsyncWizard', array(
                'ajax_url'        => admin_url( 'admin-ajax.php' ),
                'nonce'           => wp_create_nonce( 'sheetsync_nonce' ),
                'connections_url' => admin_url( 'admin.php?page=sheetsync' ),
                'settings_url'    => admin_url( 'admin.php?page=sheetsync-settings' ),
                'upgrade_url'     => function_exists( 'sheetsync_upgrade_url' ) ? sheetsync_upgrade_url() : '#',
                'is_pro'          => sheetsync_is_pro(),
                'initial_step'    => (int) ( $wizard_state['step'] ?? 1 ),
                'connection_id'   => (int) ( $wizard_state['connection_id'] ?? 0 ),
                'spreadsheet_id'  => (string) ( $wizard_state['spreadsheet_id'] ?? '' ),
                'sheet_name'      => (string) ( $wizard_state['sheet_name'] ?? 'Sheet1' ),
                'account_email'   => SheetSync_Google_Auth::get_account_email(),
                'i18n'            => array(
                    'testing'           => __( 'Testing…', 'sheetsync-for-woocommerce' ),
                    'test_auth_success' => __( 'Google authentication successful.', 'sheetsync-for-woocommerce' ),
                    'test_auth_failed'  => __( 'Google authentication failed.', 'sheetsync-for-woocommerce' ),
                    'copy_email'        => __( 'Copy email', 'sheetsync-for-woocommerce' ),
                    'share_sheet_title' => __( 'Share your sheet with this email', 'sheetsync-for-woocommerce' ),
                    'paste_url_or_id'   => __( 'Paste a full Google Sheets URL or spreadsheet ID.', 'sheetsync-for-woocommerce' ),
                    'invalid_json'      => __( 'Please upload a valid .json Service Account key file.', 'sheetsync-for-woocommerce' ),
                    'creating'          => __( 'Creating connection…', 'sheetsync-for-woocommerce' ),
                    'bootstrap_title'   => __( 'Setting up your sheet…', 'sheetsync-for-woocommerce' ),
                    'syncing'           => __( 'Syncing…', 'sheetsync-for-woocommerce' ),
                    'sync_started'      => __( 'Sync started.', 'sheetsync-for-woocommerce' ),
                    'sync_complete'     => __( 'Sync complete!', 'sheetsync-for-woocommerce' ),
                    'sync_error'        => __( 'Sync failed. Check logs.', 'sheetsync-for-woocommerce' ),
                    'sync_background_hint' => __( 'You can continue setup. Open your connection → Sync tab to watch progress.', 'sheetsync-for-woocommerce' ),
                    'google_required'   => __( 'Connect Google first — click “Save & test Google” or use OAuth in Settings.', 'sheetsync-for-woocommerce' ),
                    'sheet_test_required' => __( 'Click “Test sheet access” to confirm SheetSync can reach your sheet.', 'sheetsync-for-woocommerce' ),
                    'pro_direction_required' => __( 'WooCommerce → Sheet and Both ways require SheetSync Pro. Choose Sheet → WooCommerce or upgrade.', 'sheetsync-for-woocommerce' ),
                    'pro_direction_confirm'  => __( 'WooCommerce → Sheet and Both ways require SheetSync Pro. Open the upgrade page?', 'sheetsync-for-woocommerce' ),
                    'skip_confirm'      => __( 'Skip the setup wizard? You can resume later from SheetSync → Setup Wizard.', 'sheetsync-for-woocommerce' ),
                    'cron_enabled_hint' => __( 'Background Cron enabled. Add this command to your server cron (cPanel, etc.):', 'sheetsync-for-woocommerce' ),
                    'creating_sheet'    => __( 'Creating spreadsheet…', 'sheetsync-for-woocommerce' ),
                    'default_sheet_title' => __( 'SheetSync Products', 'sheetsync-for-woocommerce' ),
                ),
            ) );
        }

        if ( $hook === 'sheetsync_page_sheetsync-dashboards' ) {
            wp_enqueue_style(
                'sheetsync-wa-fonts',
                'https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@400;500;600&display=swap',
                array(),
                null
            );

            wp_enqueue_style(
                'sheetsync-sales-analytics',
                SHEETSYNC_PRO_URL . 'admin/css/sales-analytics.css',
                array( 'sheetsync-admin', 'sheetsync-wa-fonts' ),
                filemtime( SHEETSYNC_PRO_DIR . 'admin/css/sales-analytics.css' )
            );

            wp_enqueue_script(
                'chartjs',
                'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
                array(),
                '4.4.1',
                true
            );

            wp_enqueue_script(
                'sheetsync-sales-dashboard',
                SHEETSYNC_PRO_URL . 'admin/js/sales-dashboard.js',
                array( 'jquery', 'chartjs', 'sheetsync-admin' ),
                filemtime( SHEETSYNC_PRO_DIR . 'admin/js/sales-dashboard.js' ),
                true
            );

            wp_localize_script( 'sheetsync-sales-dashboard', 'ssSalesDashI18n', array(
                'loading'            => __( 'Loading sales analytics…', 'sheetsync-for-woocommerce' ),
                'analyticsTitle'     => __( 'Live Sales Analytics', 'sheetsync-for-woocommerce' ),
                'grossSales'         => __( 'Gross Sales', 'sheetsync-for-woocommerce' ),
                'revenueAnalytics'   => __( 'Revenue Analytics', 'sheetsync-for-woocommerce' ),
                'daily'              => __( 'Daily', 'sheetsync-for-woocommerce' ),
                'monthly'            => __( 'Monthly', 'sheetsync-for-woocommerce' ),
                'ordersVsRevenue'    => __( 'Orders vs Revenue', 'sheetsync-for-woocommerce' ),
                'dualAxis'           => __( 'Dual-axis comparison', 'sheetsync-for-woocommerce' ),
                'categoryShare'      => __( 'Revenue share per category', 'sheetsync-for-woocommerce' ),
                'customerAnalytics'  => __( 'Customer Analytics', 'sheetsync-for-woocommerce' ),
                'newVsReturning'     => __( 'New vs returning customers', 'sheetsync-for-woocommerce' ),
                'geoSales'           => __( 'Geographic Sales', 'sheetsync-for-woocommerce' ),
                'byCountry'          => __( 'Revenue by billing country', 'sheetsync-for-woocommerce' ),
                'country'            => __( 'Country', 'sheetsync-for-woocommerce' ),
                'smartInsights'      => __( 'Smart Insights', 'sheetsync-for-woocommerce' ),
                'ordersInPeriod'     => __( 'orders in period', 'sheetsync-for-woocommerce' ),
                'vsPrev'             => __( 'vs', 'sheetsync-for-woocommerce' ),
                'prevPeriod'         => __( 'prev period', 'sheetsync-for-woocommerce' ),
                'error'              => __( 'Could not load sales data.', 'sheetsync-for-woocommerce' ),
                'requestFailed'      => __( 'Request failed. Please try again.', 'sheetsync-for-woocommerce' ),
                'refresh'            => __( 'Refresh', 'sheetsync-for-woocommerce' ),
                'last7'              => __( 'Last 7 Days', 'sheetsync-for-woocommerce' ),
                'last30'             => __( 'Last 30 Days', 'sheetsync-for-woocommerce' ),
                'last6m'             => __( 'Last 6 Months', 'sheetsync-for-woocommerce' ),
                'last12m'            => __( 'Last 12 Months', 'sheetsync-for-woocommerce' ),
                'today'              => __( 'Today', 'sheetsync-for-woocommerce' ),
                'thisWeek'           => __( 'This Week', 'sheetsync-for-woocommerce' ),
                'thisMonth'          => __( 'This Month', 'sheetsync-for-woocommerce' ),
                'orders'             => __( 'orders', 'sheetsync-for-woocommerce' ),
                'netSales'           => __( 'Net Sales', 'sheetsync-for-woocommerce' ),
                'totalOrders'        => __( 'Total Orders', 'sheetsync-for-woocommerce' ),
                'avgOrder'           => __( 'Avg. Order Value', 'sheetsync-for-woocommerce' ),
                'itemsSold'          => __( 'Items Sold', 'sheetsync-for-woocommerce' ),
                'refunds'            => __( 'Refunds', 'sheetsync-for-woocommerce' ),
                'discounts'          => __( 'Discounts', 'sheetsync-for-woocommerce' ),
                'newCustomers'       => __( 'New Customers', 'sheetsync-for-woocommerce' ),
                'bestMonth'          => __( 'Best Month', 'sheetsync-for-woocommerce' ),
                'revenueTrend'       => __( 'Revenue Trend', 'sheetsync-for-woocommerce' ),
                'ordersTrend'        => __( 'Orders Trend', 'sheetsync-for-woocommerce' ),
                'topProducts'        => __( 'Top Products', 'sheetsync-for-woocommerce' ),
                'topCategories'      => __( 'Top Categories', 'sheetsync-for-woocommerce' ),
                'paymentMethods'     => __( 'Payment Methods', 'sheetsync-for-woocommerce' ),
                'orderStatus'        => __( 'Order Status', 'sheetsync-for-woocommerce' ),
                'recentOrders'       => __( 'Recent Orders', 'sheetsync-for-woocommerce' ),
                'category'           => __( 'Category', 'sheetsync-for-woocommerce' ),
                'qty'                => __( 'Qty', 'sheetsync-for-woocommerce' ),
                'revenue'            => __( 'Revenue', 'sheetsync-for-woocommerce' ),
                'order'              => __( 'Order', 'sheetsync-for-woocommerce' ),
                'date'               => __( 'Date', 'sheetsync-for-woocommerce' ),
                'customer'           => __( 'Customer', 'sheetsync-for-woocommerce' ),
                'products'           => __( 'Products', 'sheetsync-for-woocommerce' ),
                'status'             => __( 'Status', 'sheetsync-for-woocommerce' ),
                'total'              => __( 'Total', 'sheetsync-for-woocommerce' ),
                'noData'             => __( 'No data for this period.', 'sheetsync-for-woocommerce' ),
                'noOrders'           => __( 'No orders found for this period.', 'sheetsync-for-woocommerce' ),
                'exportTitle'        => __( 'Export to Google Sheets', 'sheetsync-for-woocommerce' ),
                'exportDesc'         => __( 'Send this dashboard data to a Google Sheet tab for reporting and sharing.', 'sheetsync-for-woocommerce' ),
                'spreadsheetId'      => __( 'Spreadsheet ID', 'sheetsync-for-woocommerce' ),
                'spreadsheetHelp'    => __( 'From the Google Sheets URL: /spreadsheets/d/[SPREADSHEET_ID]/edit', 'sheetsync-for-woocommerce' ),
                'sheetTab'           => __( 'Sheet Tab Name', 'sheetsync-for-woocommerce' ),
                'exportBtn'          => __( 'Export to Google Sheets', 'sheetsync-for-woocommerce' ),
                'exporting'          => __( 'Exporting…', 'sheetsync-for-woocommerce' ),
                'exportFailed'       => __( 'Export failed.', 'sheetsync-for-woocommerce' ),
                'spreadsheetRequired'=> __( 'Please enter a Spreadsheet ID.', 'sheetsync-for-woocommerce' ),
                'openSheet'          => __( 'Open Google Sheet', 'sheetsync-for-woocommerce' ),
                'rowsWritten'        => __( 'rows written to sheet', 'sheetsync-for-woocommerce' ),
                'totalRevenue'       => __( 'Total Revenue', 'sheetsync-for-woocommerce' ),
                'netProfit'          => __( 'Net Profit', 'sheetsync-for-woocommerce' ),
                'totalCustomers'     => __( 'Total Customers', 'sheetsync-for-woocommerce' ),
                'calcLegend'         => __( 'How numbers are calculated', 'sheetsync-for-woocommerce' ),
                'calcLegendBody'     => __( 'Revenue from paid orders (Processing + Completed). Total Orders matches WooCommerce admin (includes Cancelled).', 'sheetsync-for-woocommerce' ),
                'paidOrders'         => __( 'paid', 'sheetsync-for-woocommerce' ),
                'pendingRevenue'     => __( 'pending', 'sheetsync-for-woocommerce' ),
                'returningCustomers' => __( 'Returning Customers', 'sheetsync-for-woocommerce' ),
                'conversionRate'     => __( 'Conversion Rate', 'sheetsync-for-woocommerce' ),
                'refundAmount'       => __( 'Refund Amount', 'sheetsync-for-woocommerce' ),
                'productsSold'       => __( 'Products Sold', 'sheetsync-for-woocommerce' ),
                'revenueGrowth'      => __( 'Revenue Growth', 'sheetsync-for-woocommerce' ),
                'profitGrowth'       => __( 'Profit Growth', 'sheetsync-for-woocommerce' ),
                'customerGrowth'     => __( 'Customer Growth', 'sheetsync-for-woocommerce' ),
                'weekly'             => __( 'Weekly', 'sheetsync-for-woocommerce' ),
                'yearly'             => __( 'Yearly', 'sheetsync-for-woocommerce' ),
                'profit'             => __( 'Profit', 'sheetsync-for-woocommerce' ),
                'expenses'           => __( 'Expenses', 'sheetsync-for-woocommerce' ),
                'salesForecast'      => __( 'AI-Powered Sales Forecast', 'sheetsync-for-woocommerce' ),
                'forecastSub'        => __( 'Historical revenue with predicted growth projections', 'sheetsync-for-woocommerce' ),
                'historical'         => __( 'Historical', 'sheetsync-for-woocommerce' ),
                'predicted'          => __( 'Predicted', 'sheetsync-for-woocommerce' ),
                'topSelling'         => __( 'Top Selling Products', 'sheetsync-for-woocommerce' ),
                'byRevenue'          => __( 'By revenue in selected period', 'sheetsync-for-woocommerce' ),
                'conversionFunnel'   => __( 'Conversion Funnel', 'sheetsync-for-woocommerce' ),
                'funnelSub'          => __( 'Order status journey in period', 'sheetsync-for-woocommerce' ),
                'avgClv'             => __( 'Avg. CLV', 'sheetsync-for-woocommerce' ),
                'repeatPurchase'     => __( 'Repeat Purchase', 'sheetsync-for-woocommerce' ),
                'geoDist'            => __( 'Revenue distribution by country', 'sheetsync-for-woocommerce' ),
                'geoDistAdvanced'    => __( 'Global revenue by country, city & order location', 'sheetsync-for-woocommerce' ),
                'topCountries'       => __( 'Top Countries', 'sheetsync-for-woocommerce' ),
                'topCities'          => __( 'Top Cities & Order Locations', 'sheetsync-for-woocommerce' ),
                'location'           => __( 'Location', 'sheetsync-for-woocommerce' ),
                'countriesCount'     => __( 'Countries', 'sheetsync-for-woocommerce' ),
                'citiesCount'        => __( 'Cities', 'sheetsync-for-woocommerce' ),
                'topLocation'        => __( 'Top Location', 'sheetsync-for-woocommerce' ),
                'share'              => __( 'Share', 'sheetsync-for-woocommerce' ),
                'orders'             => __( 'Orders', 'sheetsync-for-woocommerce' ),
                'revenue'            => __( 'Revenue', 'sheetsync-for-woocommerce' ),
                'paySub'             => __( 'Revenue & share by method', 'sheetsync-for-woocommerce' ),
                'searchOrders'       => __( 'Search orders, customers…', 'sheetsync-for-woocommerce' ),
                'inventoryIntel'     => __( 'Inventory Intelligence', 'sheetsync-for-woocommerce' ),
                'inventorySub'       => __( 'Stock levels and alerts', 'sheetsync-for-woocommerce' ),
                'lowStock'           => __( 'Low Stock', 'sheetsync-for-woocommerce' ),
                'outOfStock'         => __( 'Out of Stock', 'sheetsync-for-woocommerce' ),
                'totalProducts'      => __( 'Total Products', 'sheetsync-for-woocommerce' ),
                'stockAlerts'        => __( 'Critical Stock Alerts', 'sheetsync-for-woocommerce' ),
                'quickActions'       => __( 'Quick Actions', 'sheetsync-for-woocommerce' ),
                'revenueExpenses'    => __( 'Revenue, Profit & Expenses over time', 'sheetsync-for-woocommerce' ),
                'allCategories'      => __( 'All Categories', 'sheetsync-for-woocommerce' ),
                'allProducts'        => __( 'All Products', 'sheetsync-for-woocommerce' ),
                'allStatuses'        => __( 'All Statuses', 'sheetsync-for-woocommerce' ),
                'allCountries'       => __( 'All Countries', 'sheetsync-for-woocommerce' ),
                'allPayments'        => __( 'All Payments', 'sheetsync-for-woocommerce' ),
                'lightMode'          => __( 'Switch to light mode', 'sheetsync-for-woocommerce' ),
                'darkMode'           => __( 'Switch to dark mode', 'sheetsync-for-woocommerce' ),
                'goalProgress'       => __( 'Monthly Goal Progress', 'sheetsync-for-woocommerce' ),
                'goalRemaining'      => __( 'remaining', 'sheetsync-for-woocommerce' ),
                'pdfGenerating'      => __( 'Preparing PDF report…', 'sheetsync-for-woocommerce' ),
                'grossProfit'        => __( 'Gross Profit (COGS)', 'sheetsync-for-woocommerce' ),
                'totalCogs'          => __( 'Total COGS', 'sheetsync-for-woocommerce' ),
                'marginPct'          => __( 'Gross Margin', 'sheetsync-for-woocommerce' ),
                'missingCogs'        => __( 'Lines missing COGS', 'sheetsync-for-woocommerce' ),
                'missingCogsSub'     => __( 'Add cost on product edit screen', 'sheetsync-for-woocommerce' ),
                'profitByProduct'    => __( 'Profit by Product', 'sheetsync-for-woocommerce' ),
                'profitByProductSub' => __( 'Revenue, COGS and margin for top sellers', 'sheetsync-for-woocommerce' ),
                'product'            => __( 'Product', 'sheetsync-for-woocommerce' ),
                'multistoreRollup'   => __( 'Multi-store Rollup', 'sheetsync-for-woocommerce' ),
                'multistoreSub'      => __( 'Combined network store performance', 'sheetsync-for-woocommerce' ),
                'store'              => __( 'Store', 'sheetsync-for-woocommerce' ),
                'forecastConf'       => __( 'Model confidence', 'sheetsync-for-woocommerce' ),
                'confidence'         => __( 'confidence', 'sheetsync-for-woocommerce' ),
                'tableScrollHint'    => __( 'Showing {total} {unit} — scroll for more', 'sheetsync-for-woocommerce' ),
                'locations'          => __( 'locations', 'sheetsync-for-woocommerce' ),
            ) );

            if ( class_exists( 'SheetSync_Dashboard_Phase2', false ) ) {
                $kpi_help = SheetSync_Dashboard_Phase2::kpi_help_texts();
                if ( class_exists( 'SheetSync_Dashboard_Phase3', false ) ) {
                    $kpi_help = array_merge( $kpi_help, SheetSync_Dashboard_Phase3::kpi_help_texts() );
                }
                wp_localize_script( 'sheetsync-sales-dashboard', 'ssKpiHelp', $kpi_help );
            }

            wp_enqueue_script(
                'sheetsync-inventory-dashboard',
                SHEETSYNC_PRO_URL . 'admin/js/inventory-dashboard.js',
                array( 'jquery', 'chartjs', 'sheetsync-sales-dashboard' ),
                filemtime( SHEETSYNC_PRO_DIR . 'admin/js/inventory-dashboard.js' ),
                true
            );

            wp_localize_script( 'sheetsync-inventory-dashboard', 'ssInvDashI18n', array(
                'loading'              => __( 'Loading inventory analytics…', 'sheetsync-for-woocommerce' ),
                'error'                => __( 'Could not load inventory data.', 'sheetsync-for-woocommerce' ),
                'requestFailed'        => __( 'Request failed. Please try again.', 'sheetsync-for-woocommerce' ),
                'totalProducts'        => __( 'Total Products', 'sheetsync-for-woocommerce' ),
                'inStock'              => __( 'In Stock', 'sheetsync-for-woocommerce' ),
                'lowStock'             => __( 'Low Stock', 'sheetsync-for-woocommerce' ),
                'outOfStock'           => __( 'Out of Stock', 'sheetsync-for-woocommerce' ),
                'stockValue'           => __( 'Est. Stock Value', 'sheetsync-for-woocommerce' ),
                'stockValueFormula'    => __( 'Est. Stock Value = sum of (active price × stock qty); variable products include all variations.', 'sheetsync-for-woocommerce' ),
                'hoverForFullAmount'   => __( 'Hover for full amount', 'sheetsync-for-woocommerce' ),
                'stockStatus'          => __( 'Stock Status', 'sheetsync-for-woocommerce' ),
                'stockStatusSub'       => __( 'In stock vs low vs out of stock', 'sheetsync-for-woocommerce' ),
                'categoryBreakdown'    => __( 'Category Breakdown', 'sheetsync-for-woocommerce' ),
                'categoryBreakdownSub' => __( 'Products per category by stock status', 'sheetsync-for-woocommerce' ),
                'lowStockAlerts'       => __( 'Low Stock Alerts', 'sheetsync-for-woocommerce' ),
                'outOfStockItems'      => __( 'Out of Stock Items', 'sheetsync-for-woocommerce' ),
                'productsNeedAttention'=> __( 'products need attention', 'sheetsync-for-woocommerce' ),
                'productsUnavailable'  => __( 'products unavailable', 'sheetsync-for-woocommerce' ),
                'allProducts'          => __( 'All Products', 'sheetsync-for-woocommerce' ),
                'totalInCatalog'     => __( 'total in catalog', 'sheetsync-for-woocommerce' ),
                'noLowStock'           => __( 'No low stock products.', 'sheetsync-for-woocommerce' ),
                'noOutOfStock'         => __( 'No out-of-stock products.', 'sheetsync-for-woocommerce' ),
                'noData'               => __( 'No inventory data found.', 'sheetsync-for-woocommerce' ),
                'product'              => __( 'Product', 'sheetsync-for-woocommerce' ),
                'sku'                  => __( 'SKU', 'sheetsync-for-woocommerce' ),
                'category'             => __( 'Category', 'sheetsync-for-woocommerce' ),
                'price'                => __( 'Price', 'sheetsync-for-woocommerce' ),
                'qty'                  => __( 'Qty', 'sheetsync-for-woocommerce' ),
                'status'               => __( 'Status', 'sheetsync-for-woocommerce' ),
                'searchProducts'       => __( 'Search products, SKU, category…', 'sheetsync-for-woocommerce' ),
                'generatedAt'          => __( 'Generated', 'sheetsync-for-woocommerce' ),
                'thresholdNote'        => __( 'Low stock threshold', 'sheetsync-for-woocommerce' ),
                'spreadsheetRequired'  => __( 'Spreadsheet ID is required.', 'sheetsync-for-woocommerce' ),
                'exporting'            => __( 'Exporting to Google Sheets…', 'sheetsync-for-woocommerce' ),
                'exportFailed'         => __( 'Export failed.', 'sheetsync-for-woocommerce' ),
                'reorderSuggestions'   => __( 'Reorder Suggestions', 'sheetsync-for-woocommerce' ),
                'reorderSub'           => __( 'Suggested qty based on 30-day sales velocity & stock level', 'sheetsync-for-woocommerce' ),
                'sold30d'              => __( 'Sold (30d)', 'sheetsync-for-woocommerce' ),
                'suggestedQty'         => __( 'Suggested reorder', 'sheetsync-for-woocommerce' ),
                'currentStock'         => __( 'Current stock', 'sheetsync-for-woocommerce' ),
                'noReorder'            => __( 'No reorder suggestions right now.', 'sheetsync-for-woocommerce' ),
                'variationInventory'   => __( 'Variation Inventory', 'sheetsync-for-woocommerce' ),
                'variationInventorySub'=> __( 'Per-variation stock levels for variable products', 'sheetsync-for-woocommerce' ),
                'noVariations'         => __( 'No product variations found.', 'sheetsync-for-woocommerce' ),
                'searchVariations'     => __( 'Search variation, SKU, parent…', 'sheetsync-for-woocommerce' ),
                'parentProduct'        => __( 'Parent', 'sheetsync-for-woocommerce' ),
                'attributes'           => __( 'Attributes', 'sheetsync-for-woocommerce' ),
                'cogs'                 => __( 'COGS', 'sheetsync-for-woocommerce' ),
            ) );

            wp_enqueue_script(
                'sheetsync-bulk-order-export',
                SHEETSYNC_PRO_URL . 'admin/js/bulk-order-export.js',
                array( 'jquery', 'chartjs', 'sheetsync-sales-dashboard' ),
                filemtime( SHEETSYNC_PRO_DIR . 'admin/js/bulk-order-export.js' ),
                true
            );

            wp_localize_script( 'sheetsync-bulk-order-export', 'ssBoeDashI18n', array(
                'loading'            => __( 'Loading order export preview…', 'sheetsync-for-woocommerce' ),
                'error'              => __( 'Could not load order data.', 'sheetsync-for-woocommerce' ),
                'requestFailed'      => __( 'Request failed. Please try again.', 'sheetsync-for-woocommerce' ),
                'matchingOrders'     => __( 'Matching Orders', 'sheetsync-for-woocommerce' ),
                'totalRevenue'       => __( 'Total Revenue', 'sheetsync-for-woocommerce' ),
                'avgOrder'           => __( 'Avg. Order', 'sheetsync-for-woocommerce' ),
                'exportFields'       => __( 'Export Fields', 'sheetsync-for-woocommerce' ),
                'columnsIncluded'    => __( 'columns included', 'sheetsync-for-woocommerce' ),
                'generatedAt'        => __( 'Generated', 'sheetsync-for-woocommerce' ),
                'previewNote'        => __( 'Apply filters above, then export to CSV or Google Sheets.', 'sheetsync-for-woocommerce' ),
                'statusBreakdown'    => __( 'Status Breakdown', 'sheetsync-for-woocommerce' ),
                'statusBreakdownSub' => __( 'Orders by status for current filters', 'sheetsync-for-woocommerce' ),
                'exportIncludes'     => __( 'Export Includes', 'sheetsync-for-woocommerce' ),
                'exportIncludesSub'  => __( 'Columns included in CSV and Google Sheets export', 'sheetsync-for-woocommerce' ),
                'orderPreview'       => __( 'Order Preview', 'sheetsync-for-woocommerce' ),
                'showingFirst'       => __( 'Showing first', 'sheetsync-for-woocommerce' ),
                'ofTotal'            => __( 'of', 'sheetsync-for-woocommerce' ),
                'order'              => __( 'Order', 'sheetsync-for-woocommerce' ),
                'customer'           => __( 'Customer', 'sheetsync-for-woocommerce' ),
                'products'           => __( 'Products', 'sheetsync-for-woocommerce' ),
                'total'              => __( 'Total', 'sheetsync-for-woocommerce' ),
                'payment'            => __( 'Payment', 'sheetsync-for-woocommerce' ),
                'status'             => __( 'Status', 'sheetsync-for-woocommerce' ),
                'date'               => __( 'Date', 'sheetsync-for-woocommerce' ),
                'searchOrders'       => __( 'Search orders, customers…', 'sheetsync-for-woocommerce' ),
                'noOrders'           => __( 'No orders match your filters.', 'sheetsync-for-woocommerce' ),
                'counting'           => __( 'Counting…', 'sheetsync-for-woocommerce' ),
                'ordersMatch'        => __( 'orders match your filters', 'sheetsync-for-woocommerce' ),
                'generatingCsv'      => __( 'Generating CSV…', 'sheetsync-for-woocommerce' ),
                'csvDownloaded'      => __( 'CSV downloaded!', 'sheetsync-for-woocommerce' ),
                'orders'             => __( 'orders', 'sheetsync-for-woocommerce' ),
                'spreadsheetRequired'=> __( 'Spreadsheet ID is required.', 'sheetsync-for-woocommerce' ),
                'exporting'          => __( 'Exporting to Google Sheets…', 'sheetsync-for-woocommerce' ),
                'exportFailed'       => __( 'Export failed.', 'sheetsync-for-woocommerce' ),
            ) );

            wp_enqueue_script(
                'sheetsync-dashboard-enhancements',
                SHEETSYNC_PRO_URL . 'admin/js/dashboard-enhancements.js',
                array( 'jquery', 'sheetsync-sales-dashboard', 'sheetsync-inventory-dashboard', 'sheetsync-bulk-order-export' ),
                filemtime( SHEETSYNC_PRO_DIR . 'admin/js/dashboard-enhancements.js' ),
                true
            );

            wp_localize_script( 'sheetsync-dashboard-enhancements', 'ssDashEnhI18n', array(
                'onboardingTitle'    => __( 'Get started with SheetSync Dashboards', 'sheetsync-for-woocommerce' ),
                'stepSheet'          => __( 'Connect a Google Spreadsheet ID', 'sheetsync-for-woocommerce' ),
                'stepSales'          => __( 'Export Sales Dashboard once', 'sheetsync-for-woocommerce' ),
                'stepInventory'      => __( 'Open Inventory Dashboard', 'sheetsync-for-woocommerce' ),
                'stepOrders'         => __( 'Export orders to Google Sheets', 'sheetsync-for-woocommerce' ),
                'dismiss'            => __( 'Dismiss checklist', 'sheetsync-for-woocommerce' ),
                'automationTitle'    => __( 'Automation & Alerts', 'sheetsync-for-woocommerce' ),
                'scheduleSales'      => __( 'Auto-export Sales', 'sheetsync-for-woocommerce' ),
                'scheduleInventory'  => __( 'Auto-export Inventory', 'sheetsync-for-woocommerce' ),
                'scheduleOrders'     => __( 'Auto-export Orders', 'sheetsync-for-woocommerce' ),
                'notifyEmail'        => __( 'Notification email', 'sheetsync-for-woocommerce' ),
                'lowStockEmail'      => __( 'Email on low stock after inventory export', 'sheetsync-for-woocommerce' ),
                'monthlyGoal'        => __( 'Monthly revenue goal', 'sheetsync-for-woocommerce' ),
                'lastSyncSales'      => __( 'Last sales export', 'sheetsync-for-woocommerce' ),
                'lastSyncInv'        => __( 'Last inventory export', 'sheetsync-for-woocommerce' ),
                'lastSyncOrders'     => __( 'Last orders export', 'sheetsync-for-woocommerce' ),
                'never'              => __( 'Never', 'sheetsync-for-woocommerce' ),
                'exportLog'          => __( 'Export History', 'sheetsync-for-woocommerce' ),
                'noLog'              => __( 'No exports yet.', 'sheetsync-for-woocommerce' ),
                'openSheet'          => __( 'Open in Google Sheets', 'sheetsync-for-woocommerce' ),
                'presets'            => __( 'Saved Presets', 'sheetsync-for-woocommerce' ),
                'templates'          => __( 'Quick Templates', 'sheetsync-for-woocommerce' ),
                'savePreset'         => __( 'Save current filters', 'sheetsync-for-woocommerce' ),
                'deletePreset'       => __( 'Delete preset', 'sheetsync-for-woocommerce' ),
                'columns'            => __( 'Export Columns', 'sheetsync-for-woocommerce' ),
                'selectAll'          => __( 'Select all', 'sheetsync-for-woocommerce' ),
                'goalProgress'       => __( 'Monthly goal progress', 'sheetsync-for-woocommerce' ),
                'hourly'             => __( 'Hourly', 'sheetsync-for-woocommerce' ),
                'twicedaily'         => __( 'Twice daily', 'sheetsync-for-woocommerce' ),
                'daily'              => __( 'Daily', 'sheetsync-for-woocommerce' ),
                'weekly'             => __( 'Weekly', 'sheetsync-for-woocommerce' ),
                'searchPlaceholder'  => __( 'Search orders & products…', 'sheetsync-for-woocommerce' ),
                'searchEmpty'        => __( 'No results found.', 'sheetsync-for-woocommerce' ),
                'searchLoading'      => __( 'Searching…', 'sheetsync-for-woocommerce' ),
                'searchFailed'       => __( 'Search failed. Please try again.', 'sheetsync-for-woocommerce' ),
                'demoEnabled'        => __( 'Demo mode enabled', 'sheetsync-for-woocommerce' ),
                'downloadCsv'        => __( 'Download CSV', 'sheetsync-for-woocommerce' ),
            ) );
        }
    }

    // ─── Page renderers ───────────────────────────────────────────────────────

    /**
     * Capability check for admin page render callbacks (defense in depth).
     */
    private function require_admin_capability(): void {
        $cap = function_exists( 'sheetsync_admin_menu_capability' )
            ? sheetsync_admin_menu_capability()
            : apply_filters( 'sheetsync_admin_capability', 'manage_woocommerce' );
        if ( ! current_user_can( $cap ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'sheetsync-for-woocommerce' ), '', array( 'response' => 403 ) );
        }
    }

    public function render_connections_page(): void {
        // FIX M-1: Explicit capability check on the render path, not just the menu registration.
        $this->require_admin_capability();

        global $wpdb;

        $action  = sanitize_text_field( wp_unslash( $_GET['sheetsync_action'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $conn_id = absint( wp_unslash( $_GET['connection_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( $action === 'edit' && $conn_id ) {
            $connection = SheetSync_Sync_Engine::get_connection( $conn_id );
            $field_maps = SheetSync_Field_Mapper::get_maps( $conn_id );
            require SHEETSYNC_PRO_DIR . 'admin/partials/edit-connection.php';
            return;
        }

        if ( $action === 'new' ) {
            $connection = null;
            $field_maps = array();
            require SHEETSYNC_PRO_DIR . 'admin/partials/edit-connection.php';
            return;
        }

        // List all connections
        $cache_key   = 'sheetsync_all_connections';
        $connections = wp_cache_get( $cache_key, 'sheetsync' );
        if ( false === $connections ) {
            $connections = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                "SELECT * FROM {$wpdb->prefix}sheetsync_connections ORDER BY created_at DESC"
            );
            wp_cache_set( $cache_key, $connections, 'sheetsync', MINUTE_IN_SECONDS * 5 );
        }

        $setup_context = $this->get_connections_page_setup_context();
        $progress      = $setup_context['progress'];
        $percent       = $setup_context['percent'];
        $next          = $setup_context['next'];
        $steps         = $setup_context['steps'];

        require SHEETSYNC_PRO_DIR . 'admin/partials/connections-list.php';
    }

    /**
     * Pass setup progress data to connections list partial.
     */
    private function get_connections_page_setup_context(): array {
        $progress = function_exists( 'sheetsync_get_setup_progress' ) ? sheetsync_get_setup_progress() : array();
        $percent  = function_exists( 'sheetsync_get_setup_progress_percent' )
            ? sheetsync_get_setup_progress_percent( $progress )
            : 0;
        $next     = function_exists( 'sheetsync_get_next_setup_step' )
            ? sheetsync_get_next_setup_step( $progress )
            : null;
        $steps    = function_exists( 'sheetsync_get_setup_steps_config' )
            ? sheetsync_get_setup_steps_config()
            : array();

        return compact( 'progress', 'percent', 'next', 'steps' );
    }

    public function render_settings_page(): void {
        $this->require_admin_capability();
        $settings         = get_option( 'sheetsync_settings', array() );
        $account_email    = SheetSync_Google_Auth::get_account_email();
        $webhook_secret_configured = ( (string) get_option( 'sheetsync_webhook_secret', '' ) ) !== '';
        $webhook_endpoint = rest_url( 'sheetsync/v1/webhook' );
        $sheetsync_allowed_meta_keys         = (string) get_option( 'sheetsync_allowed_product_meta_keys', '' );
        $sheetsync_multisite_stock_targets   = (string) get_option( 'sheetsync_multisite_stock_targets', '' );
        $sheetsync_remote_stock_incoming_secret = (string) get_option( 'sheetsync_remote_stock_incoming_secret', '' );
        $sheetsync_freemius_status              = function_exists( 'sheetsync_get_freemius_status' ) ? sheetsync_get_freemius_status() : array();
        $sheetsync_as_health                    = function_exists( 'sheetsync_get_action_scheduler_health' ) ? sheetsync_get_action_scheduler_health() : array();
        $sheetsync_external_cron_enabled        = class_exists( 'SheetSync_External_Cron', false ) && SheetSync_External_Cron::is_enabled();
        $sheetsync_external_cron_url            = class_exists( 'SheetSync_External_Cron', false ) ? SheetSync_External_Cron::get_rest_endpoint_url() : '';
        $sheetsync_external_cron_ping_url       = class_exists( 'SheetSync_External_Cron', false ) ? SheetSync_External_Cron::get_rest_ping_url() : '';
        $sheetsync_external_cron_token          = class_exists( 'SheetSync_External_Cron', false ) ? SheetSync_External_Cron::get_token() : '';
        $sheetsync_external_cron_curl           = class_exists( 'SheetSync_External_Cron', false ) ? SheetSync_External_Cron::get_example_curl( false ) : '';
        $sheetsync_external_cron_ping_curl      = class_exists( 'SheetSync_External_Cron', false ) ? SheetSync_External_Cron::get_example_curl( true ) : '';
        $sheetsync_external_cron_last           = class_exists( 'SheetSync_External_Cron', false ) ? SheetSync_External_Cron::get_last_run() : array();
        require SHEETSYNC_PRO_DIR . 'admin/partials/settings-page.php';
    }

    public function render_setup_wizard_page(): void {
        $this->require_admin_capability();

        if ( function_exists( 'sheetsync_assign_wizard_ab_variant' ) ) {
            sheetsync_assign_wizard_ab_variant();
        }

        require SHEETSYNC_PRO_DIR . 'admin/partials/setup-wizard.php';
    }

    public function render_setup_health_page(): void {
        $this->require_admin_capability();

        require SHEETSYNC_PRO_DIR . 'admin/partials/setup-health-page.php';
    }

    public function render_logs_page(): void {
        $this->require_admin_capability();
        global $wpdb;

        $conn_id   = absint( wp_unslash( $_GET['connection_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $logs      = SheetSync_Logger::get_logs( 100, $conn_id ?: null );
        $log_total = SheetSync_Logger::count_logs( $conn_id ?: null );

        $cache_key   = 'sheetsync_all_connections';
        $connections = wp_cache_get( $cache_key, 'sheetsync' );
        if ( false === $connections ) {
            $connections = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                "SELECT id, name FROM {$wpdb->prefix}sheetsync_connections ORDER BY name ASC"
            );
            wp_cache_set( $cache_key, $connections, 'sheetsync', MINUTE_IN_SECONDS * 5 );
        }

        $tz_string = wp_timezone_string();
        require SHEETSYNC_PRO_DIR . 'admin/partials/logs-page.php';
    }

    public function render_sync_reports_page(): void {
        $this->require_admin_capability();
        require SHEETSYNC_PRO_DIR . 'admin/partials/sync-reports-page.php';
    }

    public function render_match_diagnostics_page(): void {
        $this->require_admin_capability();
        require SHEETSYNC_PRO_DIR . 'admin/partials/match-diagnostics-page.php';
    }

    public function handle_clear_logs(): void {
        check_admin_referer( 'sheetsync_clear_logs' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Forbidden', 'sheetsync-for-woocommerce' ), '', array( 'response' => 403 ) );
        }

        $conn_id = absint( wp_unslash( $_POST['connection_id'] ?? 0 ) );
        $deleted = SheetSync_Logger::clear_logs( $conn_id ?: null );

        $redirect = admin_url( 'admin.php?page=sheetsync-logs' );
        if ( $conn_id ) {
            $redirect = add_query_arg( 'connection_id', $conn_id, $redirect );
        }
        $redirect = add_query_arg( 'cleared', $deleted, $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

    public function render_import_export_page(): void {
        $this->require_admin_capability();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $legacy = isset( $_GET['legacy'] ) && $_GET['legacy'] === '1';

        if ( ! $legacy ) {
            global $wpdb;
            $conn = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}sheetsync_connections WHERE status = %s AND connection_type = %s ORDER BY id ASC LIMIT 1",
                    'active',
                    'products'
                )
            );
            if ( $conn ) {
                wp_safe_redirect(
                    admin_url(
                        'admin.php?page=sheetsync&sheetsync_action=edit&connection_id=' . (int) $conn->id . '&tab=sync&from=import-export'
                    )
                );
                exit;
            }
        }

        require SHEETSYNC_PRO_DIR . 'admin/partials/import-export-page.php';
    }

    public function render_conflicts_inbox_page(): void {
        $this->require_admin_capability();
        require SHEETSYNC_PRO_DIR . 'admin/partials/conflicts-inbox.php';
    }

    public function render_dashboards_page(): void {
        $this->require_admin_capability();

        if ( ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) {
            ?>
            <div class="sheetsync-wrap">
                <?php require SHEETSYNC_PRO_DIR . 'admin/partials/header.php'; ?>
                <div class="sheetsync-card">
                    <h2 style="margin-top:0;"><?php esc_html_e( 'Store Reports', 'sheetsync-for-woocommerce' ); ?></h2>
                    <?php
                    self::render_pro_gate(
                        __( 'Sales, inventory & order export reports (separate from product sheet sync)', 'sheetsync-for-woocommerce' )
                    );
                    ?>
                </div>
            </div>
            <?php
            return;
        }

        require SHEETSYNC_PRO_DIR . 'admin/partials/dashboards-page.php';
    }

    // ─── Form handlers ────────────────────────────────────────────────────────

    public function handle_save_settings(): void {
        check_admin_referer( 'sheetsync_save_settings' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'Forbidden', 'sheetsync-for-woocommerce' ), 403 );

        // Save Service Account JSON
        $json = sanitize_textarea_field( wp_unslash( $_POST['service_account_json'] ?? '' ) );
        if ( ! empty( $json ) ) {
            if ( ! SheetSync_Google_Auth::save_credentials( $json ) ) {
                $this->add_notice( 'error', __( 'Invalid Service Account JSON. Please check the file.', 'sheetsync-for-woocommerce' ) );
                wp_safe_redirect( admin_url( 'admin.php?page=sheetsync-settings' ) );
                exit;
            }
            sheetsync_update_setup_progress( 'google_connected', true );
        }

        // General settings
        $is_pro    = function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro();
        $log_level = sanitize_key( wp_unslash( $_POST['log_level'] ?? 'info' ) );
        if ( ! in_array( $log_level, array( 'error', 'info', 'debug' ), true ) ) {
            $log_level = 'info';
        }
        $empty_cell_policy = sanitize_key( wp_unslash( $_POST['empty_cell_policy'] ?? 'ignore' ) );
        if ( ! in_array( $empty_cell_policy, array( 'ignore', 'clear' ), true ) ) {
            $empty_cell_policy = 'ignore';
        }
        $report_interval = sanitize_key( wp_unslash( $_POST['email_report_interval'] ?? '' ) );
        if ( ! in_array( $report_interval, array( '', 'daily', 'weekly' ), true ) ) {
            $report_interval = '';
        }
        $existing = get_option( 'sheetsync_settings', array() );
        if ( ! is_array( $existing ) ) {
            $existing = array();
        }
        $settings = array_merge(
            $existing,
            array(
            'batch_size'            => max( 1, min( 500, absint( $_POST['batch_size'] ?? 50 ) ) ),
            'log_retention_days'    => max( 1, min( 365, absint( $_POST['log_retention_days'] ?? 30 ) ) ),
            'log_level'             => $log_level,
            'empty_cell_policy'     => $empty_cell_policy,
            'email_notifications'   => $is_pro && ! empty( $_POST['email_notifications'] ),
            'email_report_interval' => $is_pro ? $report_interval : '',
            'notification_email'    => sanitize_email( wp_unslash( $_POST['notification_email'] ?? get_option( 'admin_email' ) ) ),
            'google_template_url'   => esc_url_raw( wp_unslash( $_POST['google_template_url'] ?? '' ) ),
            'setup_video_url'       => esc_url_raw( wp_unslash( $_POST['setup_video_url'] ?? '' ) ),
            )
        );
        update_option( 'sheetsync_settings', $settings );

        if ( class_exists( 'SheetSync_Email_Reports', false ) ) {
            SheetSync_Email_Reports::sync_schedule();
        }

        if ( class_exists( 'SheetSync_External_Cron', false ) ) {
            SheetSync_External_Cron::set_enabled( ! empty( $_POST['external_cron_enabled'] ) );
        }

        if ( class_exists( 'SheetSync_Google_OAuth', false ) ) {
            $oauth_id     = sanitize_text_field( wp_unslash( $_POST['oauth_client_id'] ?? '' ) );
            $oauth_secret = sanitize_text_field( wp_unslash( $_POST['oauth_client_secret'] ?? '' ) );
            if ( $oauth_id !== '' || $oauth_secret !== '' ) {
                SheetSync_Google_OAuth::save_client_credentials( $oauth_id, $oauth_secret );
            }
            $auth_method = sanitize_key( wp_unslash( $_POST['sheetsync_auth_method'] ?? 'service_account' ) );
            if ( in_array( $auth_method, array( 'service_account', 'oauth' ), true ) ) {
                update_option( 'sheetsync_auth_method', $auth_method, false );
            }
        }

        if ( current_user_can( 'manage_options' ) ) {
            update_option(
                'sheetsync_wizard_branding',
                array(
                    'title'        => sanitize_text_field( wp_unslash( $_POST['wizard_brand_title'] ?? '' ) ),
                    'logo_url'     => esc_url_raw( wp_unslash( $_POST['wizard_brand_logo'] ?? '' ) ),
                    'hide_support' => ! empty( $_POST['wizard_hide_support'] ),
                ),
                false
            );
        }

        // Pro Test Mode toggle (dev builds only)
        if ( defined( 'SHEETSYNC_DEV_PRO' ) && SHEETSYNC_DEV_PRO ) {
            $test_mode = ! empty( $_POST['pro_test_mode'] );
            update_option( 'sheetsync_pro_test_mode', $test_mode );
        }

        if ( function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro() ) {
            update_option(
                'sheetsync_allowed_product_meta_keys',
                sanitize_textarea_field( wp_unslash( $_POST['sheetsync_allowed_product_meta_keys'] ?? '' ) ),
                false
            );
            update_option(
                'sheetsync_multisite_stock_targets',
                function_exists( 'sheetsync_sanitize_multisite_stock_targets' )
                    ? sheetsync_sanitize_multisite_stock_targets( (string) wp_unslash( $_POST['sheetsync_multisite_stock_targets'] ?? '' ) )
                    : sanitize_textarea_field( wp_unslash( $_POST['sheetsync_multisite_stock_targets'] ?? '' ) ),
                false
            );
            update_option(
                'sheetsync_remote_stock_incoming_secret',
                trim( sanitize_text_field( wp_unslash( $_POST['sheetsync_remote_stock_incoming_secret'] ?? '' ) ) ),
                false
            );
        }

        $saved_msg = __( 'Settings saved.', 'sheetsync-for-woocommerce' );
        $email     = SheetSync_Google_Auth::get_account_email();
        if ( class_exists( 'SheetSync_Google_OAuth', false ) && SheetSync_Google_OAuth::is_active() ) {
            $email = SheetSync_Google_OAuth::get_connected_email();
        }
        if ( $email !== '' ) {
            $saved_msg = sprintf(
                /* translators: %s: service account email */
                __( 'Settings saved. Connected as: %s', 'sheetsync-for-woocommerce' ),
                $email
            );
        }
        $this->add_notice( 'success', $saved_msg );
        wp_safe_redirect( admin_url( 'admin.php?page=sheetsync-settings' ) );
        exit;
    }

    public function handle_save_connection(): void {
        check_admin_referer( 'sheetsync_save_connection' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'Forbidden', 'sheetsync-for-woocommerce' ), 403 );

        $conn_id    = absint( $_POST['connection_id'] ?? 0 );
        $old_conn   = $conn_id ? SheetSync_Sync_Engine::get_connection( $conn_id ) : null;
        $old_tab    = $old_conn ? (string) ( $old_conn->sheet_name ?? '' ) : '';

        if ( ! sheetsync_is_pro() && ! $conn_id ) {
            global $wpdb;
            $connection_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sheetsync_connections" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            if ( $connection_count >= 1 ) {
                wp_die(
                    esc_html__( 'Free plan allows 1 connection. Upgrade to Pro for unlimited connections.', 'sheetsync-for-woocommerce' ) . ' <a href="' . esc_url( sheetsync_upgrade_url() ) . '">' . esc_html__( 'Upgrade', 'sheetsync-for-woocommerce' ) . '</a>',
                    esc_html__( 'Pro required', 'sheetsync-for-woocommerce' ),
                    array( 'response' => 403 )
                );
            }
        }

        $raw_spreadsheet = sanitize_text_field( wp_unslash( $_POST['spreadsheet_id'] ?? '' ) );
        if ( $raw_spreadsheet === '' ) {
            $raw_spreadsheet = sanitize_text_field( wp_unslash( $_POST['spreadsheet_url'] ?? '' ) );
        }
        $spreadsheet_id = function_exists( 'sheetsync_parse_spreadsheet_id' )
            ? sheetsync_parse_spreadsheet_id( $raw_spreadsheet )
            : $raw_spreadsheet;

        $data = array(
            'name'            => sanitize_text_field( wp_unslash( $_POST['connection_name'] ?? '' ) ),
            'spreadsheet_id'  => $spreadsheet_id,
            'sheet_name'      => trim( sanitize_text_field( wp_unslash( $_POST['sheet_name'] ?? 'Sheet1' ) ) ),
            'header_row'      => absint( $_POST['header_row'] ?? 1 ),
            'status'          => sanitize_text_field( wp_unslash( $_POST['status'] ?? 'active' ) ),
            'connection_type' => sanitize_text_field( wp_unslash( $_POST['connection_type'] ?? 'products' ) ),
            'sync_direction'  => sanitize_text_field( wp_unslash( $_POST['sync_direction'] ?? 'sheets_to_wc' ) ),
        );

        $saved_id = SheetSync_Sync_Engine::save_connection( $data, $conn_id ?: null );

        if ( $saved_id && function_exists( 'sheetsync_ensure_connection_webhook_secret' ) ) {
            sheetsync_ensure_connection_webhook_secret( (int) $saved_id );
        }

        // Ensure the target sheet tab exists (orders bootstrap headers; products create tab if missing).
        if (
            ! empty( $data['spreadsheet_id'] )
            && ! empty( $data['sheet_name'] )
            && class_exists( 'SheetSync_Sheets_Client', false )
        ) {
            try {
                if (
                    SheetSync_Sync_Engine::is_orders_type( $data['connection_type'] )
                    && class_exists( 'SheetSync_Order_Sync', false )
                ) {
                    $conn_obj = SheetSync_Sync_Engine::get_connection( $saved_id );
                    if ( $conn_obj ) {
                        ( new SheetSync_Order_Sync() )->bootstrap_order_sheet( $conn_obj );
                    }
                } else {
                    ( new SheetSync_Sheets_Client() )->ensure_sheet_exists(
                        (string) $data['spreadsheet_id'],
                        (string) $data['sheet_name']
                    );
                }
                ( new SheetSync_Sheets_Client() )->invalidate_sheet_grid_cache( (string) $data['spreadsheet_id'] );
            } catch ( Exception $e ) {
                $this->add_notice(
                    'warning',
                    sprintf(
                        /* translators: %s: error message */
                        __( 'Connection saved, but the sheet tab could not be prepared: %s', 'sheetsync-for-woocommerce' ),
                        $e->getMessage()
                    )
                );
            }
        }

        // ── Save date filter settings (stored in wp_options, not DB schema) ─
        if ( SheetSync_Sync_Engine::is_orders_type( $data['connection_type'] ) ) {
            $date_type   = sanitize_text_field( wp_unslash( $_POST['order_date_type']   ?? 'all' ) );
            $date_single = sanitize_text_field( wp_unslash( $_POST['order_date_single'] ?? '' ) );
            $date_from   = sanitize_text_field( wp_unslash( $_POST['order_date_from']   ?? '' ) );
            $date_to     = sanitize_text_field( wp_unslash( $_POST['order_date_to']     ?? '' ) );

            $date_type = in_array( $date_type, array( 'all', 'single', 'range' ), true ) ? $date_type : 'all';

            // Validate date format YYYY-MM-DD
            $date_single = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_single ) ? $date_single : '';
            $date_from   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from   ) ? $date_from   : '';
            $date_to     = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to     ) ? $date_to     : '';

            update_option( 'sheetsync_date_filter_type_'   . $saved_id, $date_type,   false );
            update_option( 'sheetsync_date_filter_single_' . $saved_id, $date_single, false );
            update_option( 'sheetsync_date_filter_from_'   . $saved_id, $date_from,   false );
            update_option( 'sheetsync_date_filter_to_'     . $saved_id, $date_to,     false );
        } else {
            // Products connection — clear any previous date filter options
            delete_option( 'sheetsync_date_filter_type_'   . $saved_id );
            delete_option( 'sheetsync_date_filter_single_' . $saved_id );
            delete_option( 'sheetsync_date_filter_from_'   . $saved_id );
            delete_option( 'sheetsync_date_filter_to_'     . $saved_id );
        }

        if ( function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro() ) {
            if ( SheetSync_Sync_Engine::is_orders_type( $data['connection_type'] ) ) {
                delete_option( 'sheetsync_sync_category_ids_' . $saved_id );
                delete_option( 'sheetsync_hidden_sheet_columns_' . $saved_id );
                delete_option( 'sheetsync_category_block_unknown_' . $saved_id );
            } else {
                $cat_raw = sanitize_text_field( wp_unslash( $_POST['sheetsync_sync_category_ids'] ?? '' ) );
                update_option( 'sheetsync_sync_category_ids_' . $saved_id, $cat_raw, false );

                $hidden_cols = sanitize_text_field( wp_unslash( $_POST['sheetsync_hidden_sheet_columns'] ?? '' ) );
                update_option( 'sheetsync_hidden_sheet_columns_' . $saved_id, $hidden_cols, false );

                $block_unknown = ! empty( $_POST['sheetsync_category_block_unknown'] ) ? '1' : '';
                update_option( 'sheetsync_category_block_unknown_' . $saved_id, $block_unknown, false );

                $sheet_mode = sanitize_text_field( wp_unslash( $_POST['product_sheet_mode'] ?? 'full' ) );
                $sheet_mode = in_array( $sheet_mode, array( 'simple', 'full' ), true ) ? $sheet_mode : 'full';
                update_option( 'sheetsync_product_sheet_mode_' . $saved_id, $sheet_mode, false );
            }
            SheetSync_Field_Mapper::invalidate_cache( $saved_id );

            if ( ! SheetSync_Sync_Engine::is_orders_type( $data['connection_type'] ) && empty( SheetSync_Field_Mapper::get_maps( $saved_id ) ) ) {
                self::apply_preset_field_maps( $saved_id, 'full' );
            }
        }

        // Invalidate connections list cache.
        wp_cache_delete( 'sheetsync_all_connections', 'sheetsync' );

        sheetsync_update_setup_progress( 'connection_created', true );

        $auto_sync_map = get_option( 'sheetsync_auto_sync_settings', array() );
        if (
            $old_tab !== ''
            && $old_tab !== $data['sheet_name']
            && ! empty( $auto_sync_map[ $saved_id ] )
        ) {
            $this->add_notice(
                'warning',
                __( 'Sheet tab changed while real-time sync is enabled. Open Google Apps Script and run setupTrigger again so the new tab is included.', 'sheetsync-for-woocommerce' )
            );
        }

        $this->add_notice( 'success', __( 'Connection saved.', 'sheetsync-for-woocommerce' ) );
        wp_safe_redirect( add_query_arg( array( 'page' => 'sheetsync', 'sheetsync_action' => 'edit', 'connection_id' => absint( $saved_id ) ), admin_url( 'admin.php' ) ) ); // FIX M-3
        exit;
    }

    public function handle_delete_connection(): void {
        check_admin_referer( 'sheetsync_delete_connection' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'Forbidden', 'sheetsync-for-woocommerce' ), 403 );

        $conn_id = absint( $_POST['connection_id'] ?? 0 );
        if ( $conn_id ) {
            SheetSync_Sync_Engine::delete_connection( $conn_id );
            if ( function_exists( 'sheetsync_delete_connection_webhook_secret' ) ) {
                sheetsync_delete_connection_webhook_secret( $conn_id );
            }
            delete_option( 'sheetsync_webhook_verified_' . $conn_id );
            delete_option( 'sheetsync_realtime_connection_' . $conn_id );
            delete_option( 'sheetsync_map_profile_' . $conn_id );
            wp_cache_delete( 'sheetsync_all_connections', 'sheetsync' );

            $this->add_notice( 'success', __( 'Connection deleted.', 'sheetsync-for-woocommerce' ) );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=sheetsync' ) );
        exit;
    }

    public function handle_save_field_map(): void {
        check_admin_referer( 'sheetsync_save_field_map' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'Forbidden', 'sheetsync-for-woocommerce' ), 403 );

        $conn_id       = absint( $_POST['connection_id'] ?? 0 );
        $map_profile   = sanitize_key( wp_unslash( $_POST['sheetsync_map_profile'] ?? '' ) );

        if ( $conn_id && in_array( $map_profile, array( 'simple', 'full' ), true ) ) {
            self::apply_preset_field_maps( $conn_id, 'simple' === $map_profile ? 'minimal' : 'full' );
            update_option( 'sheetsync_map_profile_' . $conn_id, $map_profile, false );
            $this->add_notice( 'success', __( 'Field mapping saved.', 'sheetsync-for-woocommerce' ) );
            wp_safe_redirect( add_query_arg( array( 'page' => 'sheetsync', 'sheetsync_action' => 'edit', 'connection_id' => absint( $conn_id ) ), admin_url( 'admin.php' ) ) . '#tab-field-mapping' );
            exit;
        }

        if ( $conn_id && 'custom' === $map_profile ) {
            update_option( 'sheetsync_map_profile_' . $conn_id, 'custom', false );
        }

        $raw_field_map = wp_unslash( $_POST['field_map'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        // BUG FIX: field_map is a nested array (field => [column, key]).
        // Using array_map( 'sanitize_text_field' ) flattens it — must sanitize each sub-array.
        $is_pro         = sheetsync_is_pro();
        $allowed_fields = array_fill_keys(
            array_merge(
                array_keys( SheetSync_Field_Mapper::get_available_fields( $is_pro ) ),
                array( 'parent_sku', 'variation_attrs', 'sheet_color', 'sheet_size' )
            ),
            true
        );
        $field_map = array();
        if ( is_array( $raw_field_map ) ) {
            foreach ( $raw_field_map as $wc_field => $map_data ) {
                if ( ! is_array( $map_data ) || ! is_string( $wc_field ) ) {
                    continue;
                }
                $wc_key = trim( $wc_field );
                if ( $wc_key === '' || ! isset( $allowed_fields[ $wc_key ] ) ) {
                    continue;
                }
                $field_map[ $wc_key ] = array(
                    'column' => sanitize_text_field( $map_data['column'] ?? '' ),
                    'key'    => ! empty( $map_data['key'] ) ? 1 : 0,
                );
            }
        }

        if ( $conn_id && ! empty( $field_map ) ) {
            if ( isset( $field_map['product_id'] ) ) {
                $field_map['product_id']['key'] = 0;
            }

            // If no key field is set at all, default SKU as the key field
            $has_key_field = false;
            foreach ( $field_map as $data ) {
                if ( ! empty( $data['key'] ) ) {
                    $has_key_field = true;
                    break;
                }
            }
            if ( ! $has_key_field && isset( $field_map['_sku'] ) && ! empty( $field_map['_sku']['column'] ) ) {
                $field_map['_sku']['key'] = 1;
            }

            $sku_col = trim( (string) ( $field_map['_sku']['column'] ?? '' ) );
            $pid_col = trim( (string) ( $field_map['product_id']['column'] ?? '' ) );
            if ( $sku_col === '' && $pid_col === '' ) {
                $this->add_notice(
                    'warning',
                    __( 'No product identity column mapped — map SKU (column A) or Product ID (column B) so rows match WooCommerce reliably.', 'sheetsync-for-woocommerce' )
                );
            }

            SheetSync_Sync_Engine::save_field_maps( $conn_id, $field_map );
            $this->add_notice( 'success', __( 'Field mapping saved.', 'sheetsync-for-woocommerce' ) );
        }

        wp_safe_redirect( add_query_arg( array( 'page' => 'sheetsync', 'sheetsync_action' => 'edit', 'connection_id' => absint( $conn_id ) ), admin_url( 'admin.php' ) ) . '#tab-field-mapping' ); // FIX M-3
        exit;
    }

    public function handle_toggle_auto_sync(): void {
        check_admin_referer( 'sheetsync_toggle_auto_sync' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'Forbidden', 'sheetsync-for-woocommerce' ), 403 );
        if ( ! sheetsync_is_pro() ) wp_die( esc_html__( 'Pro required.', 'sheetsync-for-woocommerce' ), 403 );

        $conn_id = absint( $_POST['connection_id'] ?? 0 );
        $enabled = ! empty( $_POST['auto_sync_enabled'] );

        $result = function_exists( 'sheetsync_apply_automatic_sync' )
            ? sheetsync_apply_automatic_sync( $conn_id, $enabled )
            : array( 'success' => false, 'message' => __( 'Automatic sync unavailable.', 'sheetsync-for-woocommerce' ) );

        if ( empty( $result['success'] ) ) {
            $this->add_notice( 'error', (string) ( $result['message'] ?? __( 'Could not update automatic sync.', 'sheetsync-for-woocommerce' ) ) );
        } else {
            $this->add_notice( 'success', (string) ( $result['message'] ?? '' ) );
        }

        wp_safe_redirect( add_query_arg( array( 'page' => 'sheetsync', 'sheetsync_action' => 'edit', 'connection_id' => absint( $conn_id ) ), admin_url( 'admin.php' ) ) . '#tab-sync' );
        exit;
    }

    public function ajax_toggle_automatic_sync(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $conn_id = absint( $_POST['connection_id'] ?? 0 );
        $enabled = ! empty( $_POST['enabled'] );

        if ( ! function_exists( 'sheetsync_apply_automatic_sync' ) ) {
            wp_send_json_error( array( 'message' => __( 'Automatic sync unavailable.', 'sheetsync-for-woocommerce' ) ) );
        }

        $result = sheetsync_apply_automatic_sync( $conn_id, $enabled );
        if ( empty( $result['success'] ) ) {
            wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? __( 'Could not update automatic sync.', 'sheetsync-for-woocommerce' ) ) ) );
        }

        wp_send_json_success(
            array(
                'enabled' => ! empty( $result['enabled'] ),
                'status'  => $result['status'] ?? array(),
                'message' => (string) ( $result['message'] ?? '' ),
            )
        );
    }

    public function ajax_schedule_off_peak_publish(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $conn_id = absint( $_POST['connection_id'] ?? 0 );
        if ( ! class_exists( 'SheetSync_Off_Peak_Publish', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Off-peak publish unavailable.', 'sheetsync-for-woocommerce' ) ) );
        }

        $overrides = array(
            'hour'      => isset( $_POST['hour'] ) ? (int) $_POST['hour'] : 2,
            'minute'    => isset( $_POST['minute'] ) ? (int) $_POST['minute'] : 0,
            'recurring' => ! empty( $_POST['recurring'] ),
        );
        if ( ! empty( $_POST['one_shot'] ) ) {
            $overrides['recurring'] = false;
        }

        $result = SheetSync_Off_Peak_Publish::schedule_publish( $conn_id, $overrides );
        if ( empty( $result['success'] ) ) {
            wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? __( 'Could not schedule off-peak publish.', 'sheetsync-for-woocommerce' ) ) ) );
        }

        wp_send_json_success(
            array(
                'message'  => (string) ( $result['message'] ?? '' ),
                'next_run' => $result['next_run'] ?? null,
                'settings' => $result['settings'] ?? array(),
            )
        );
    }

    public function ajax_send_email_report(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden', 'sheetsync-for-woocommerce' ) ), 403 );
        }
        if ( ! sheetsync_is_pro() || ! class_exists( 'SheetSync_Email_Reports', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Email reports require Pro.', 'sheetsync-for-woocommerce' ) ) );
        }

        $period = sanitize_key( wp_unslash( $_POST['period'] ?? 'daily' ) );
        if ( ! in_array( $period, array( 'daily', 'weekly' ), true ) ) {
            $period = 'daily';
        }

        $sent = SheetSync_Email_Reports::send_report( $period, true );
        if ( ! $sent ) {
            wp_send_json_error( array( 'message' => __( 'Could not send report — check the notification email in Settings.', 'sheetsync-for-woocommerce' ) ) );
        }

        $settings = get_option( 'sheetsync_settings', array() );
        $to       = sanitize_email( $settings['notification_email'] ?? get_option( 'admin_email' ) );
        wp_send_json_success(
            array(
                'message' => sprintf(
                    /* translators: %s: email address */
                    __( 'Report sent to %s.', 'sheetsync-for-woocommerce' ),
                    $to
                ),
            )
        );
    }

    public function ajax_run_match_diagnostics(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $connection_id = absint( wp_unslash( $_POST['connection_id'] ?? 0 ) );
        if ( $connection_id <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid connection ID.', 'sheetsync-for-woocommerce' ) ) );
        }

        if ( ! class_exists( 'SheetSync_Match_Diagnostics', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Match diagnostics not available.', 'sheetsync-for-woocommerce' ) ) );
        }

        $scan_sheet = ! empty( $_POST['scan_sheet'] ) && '1' === (string) wp_unslash( $_POST['scan_sheet'] );
        $report     = SheetSync_Match_Diagnostics::run( $connection_id, $scan_sheet );
        wp_send_json_success( $report );
    }

    public function handle_save_off_peak_publish(): void {
        check_admin_referer( 'sheetsync_save_off_peak_publish' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Forbidden', 'sheetsync-for-woocommerce' ), 403 );
        }
        if ( ! sheetsync_is_pro() || ! class_exists( 'SheetSync_Off_Peak_Publish', false ) ) {
            wp_die( esc_html__( 'Pro required.', 'sheetsync-for-woocommerce' ), 403 );
        }

        $conn_id = absint( $_POST['connection_id'] ?? 0 );
        $enabled = ! empty( $_POST['off_peak_enabled'] );
        $hour    = isset( $_POST['off_peak_hour'] ) ? (int) $_POST['off_peak_hour'] : 2;
        $minute  = isset( $_POST['off_peak_minute'] ) ? (int) $_POST['off_peak_minute'] : 0;
        $recurring = ! empty( $_POST['off_peak_recurring'] );

        SheetSync_Off_Peak_Publish::save_settings(
            $conn_id,
            array(
                'enabled'   => $enabled,
                'hour'      => $hour,
                'minute'    => $minute,
                'recurring' => $recurring,
            )
        );

        $msg = $enabled
            ? __( 'Off-peak full publish saved.', 'sheetsync-for-woocommerce' )
            : __( 'Off-peak full publish disabled.', 'sheetsync-for-woocommerce' );
        $this->add_notice( 'success', $msg );
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'            => 'sheetsync',
                    'sheetsync_action' => 'edit',
                    'connection_id'   => $conn_id,
                ),
                admin_url( 'admin.php' )
            ) . '#tab-sync'
        );
        exit;
    }

    /**
     * Save sync strategy + auto-sync-on-save toggle (Free + Pro).
     */
    public function handle_save_sync_options(): void {
        check_admin_referer( 'sheetsync_save_sync_options' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'Forbidden', 'sheetsync-for-woocommerce' ), 403 );

        $conn_id  = absint( $_POST['connection_id'] ?? 0 );
        $strategy = sanitize_text_field( wp_unslash( $_POST['sync_strategy'] ?? 'smart' ) );
        $strategy = in_array( $strategy, array( 'smart', 'full' ), true ) ? $strategy : 'smart';

        update_option( 'sheetsync_sync_strategy_' . $conn_id, $strategy, false );

        if ( class_exists( 'SheetSync_Sync_State_Repository', false ) ) {
            $state_settings = array();
            if ( ! empty( $_POST['two_way_phase_order'] ) ) {
                $phase = sanitize_key( wp_unslash( $_POST['two_way_phase_order'] ) );
                if ( in_array( $phase, array( 'pull_push', 'push_pull' ), true ) ) {
                    $state_settings['two_way_phase_order'] = $phase;
                }
            }
            if ( ! empty( $_POST['on_conflict'] ) ) {
                $on_conflict = sanitize_key( wp_unslash( $_POST['on_conflict'] ) );
                if ( in_array( $on_conflict, array( 'merge', 'queue', 'sheet', 'wc' ), true ) ) {
                    $state_settings['on_conflict'] = $on_conflict;
                }
            }
            $state_settings['require_sku'] = ! empty( $_POST['require_sku'] );
            ( new SheetSync_Sync_State_Repository() )->update_settings( $conn_id, $state_settings );
        }

        $this->add_notice( 'success', __( 'Sync options saved.', 'sheetsync-for-woocommerce' ) );
        wp_safe_redirect( add_query_arg(
            array( 'page' => 'sheetsync', 'sheetsync_action' => 'edit', 'connection_id' => $conn_id ),
            admin_url( 'admin.php' )
        ) . '#tab-sync' );
        exit;
    }

    /**
     * Proxy for schedule save — delegates to SheetSync_Cron_Manager (Pro).
     */
    public function handle_save_schedule_proxy(): void {
        check_admin_referer( 'sheetsync_save_schedule' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'Forbidden', 'sheetsync-for-woocommerce' ), 403 );
        if ( ! sheetsync_is_pro() ) wp_die( esc_html__( 'Pro required.', 'sheetsync-for-woocommerce' ), 403 );

        $conn_id  = absint( $_POST['connection_id'] ?? 0 );
        $interval = sanitize_text_field( wp_unslash( $_POST['sync_interval'] ?? '' ) );
        $allowed  = array( '', 'sheetsync_15min', 'sheetsync_30min', 'sheetsync_1hour', 'twicedaily', 'daily' );
        if ( ! in_array( $interval, $allowed, true ) ) $interval = '';

        update_option( 'sheetsync_schedule_' . $conn_id, $interval, false );
        if ( class_exists( 'SheetSync_Cron_Manager', false ) ) {
            SheetSync_Cron_Manager::schedule( $conn_id, $interval );
        }

        $msg = $interval
            ? __( 'Schedule saved.', 'sheetsync-for-woocommerce' )
            : __( 'Schedule disabled.', 'sheetsync-for-woocommerce' );
        $this->add_notice( 'success', $msg );
        wp_safe_redirect( add_query_arg(
            array( 'page' => 'sheetsync', 'sheetsync_action' => 'edit', 'connection_id' => $conn_id ),
            admin_url( 'admin.php' )
        ) . '#tab-sync' );
        exit;
    }

    // ─── AJAX handlers ────────────────────────────────────────────────────────

    public function ajax_check_sheet(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $connection_id = absint( $_POST['connection_id'] ?? 0 );
        if ( ! $connection_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid connection ID.', 'sheetsync-for-woocommerce' ) ) );
        }

        if ( ! class_exists( 'SheetSync_Sheet_Validator', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Sheet validator not available.', 'sheetsync-for-woocommerce' ) ) );
        }

        $validator = new SheetSync_Sheet_Validator();
        $report    = $validator->validate_connection( $connection_id );
        wp_send_json_success( $report );
    }

    /**
     * Save recommended or minimal column layout for a connection.
     *
     * @param string $preset full|minimal
     */
    public static function apply_preset_field_maps( int $connection_id, string $preset = 'full' ): void {
        if ( $connection_id <= 0 ) {
            return;
        }
        $preset = 'minimal' === $preset ? SheetSync_Field_Mapper::PROFILE_MINIMAL : SheetSync_Field_Mapper::PROFILE_FULL;
        $field_map = SheetSync_Field_Mapper::preset_to_field_map( $preset );
        if ( ! empty( $field_map ) ) {
            SheetSync_Sync_Engine::save_field_maps( $connection_id, $field_map );
            SheetSync_Field_Mapper::invalidate_cache( $connection_id );
        }
    }

    public function ajax_get_connection_maps(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $connection_id = absint( $_POST['connection_id'] ?? 0 );
        $conn          = SheetSync_Sync_Engine::get_connection( $connection_id );
        if ( ! $conn ) {
            wp_send_json_error( array( 'message' => __( 'Connection not found.', 'sheetsync-for-woocommerce' ) ) );
        }

        $maps = SheetSync_Field_Mapper::get_maps_for_sync( $connection_id, $conn );
        $out  = array();
        foreach ( $maps as $wc_field => $info ) {
            if ( ! empty( $info['sheet_column'] ) ) {
                $out[ $wc_field ] = strtoupper( (string) $info['sheet_column'] );
            }
        }

        wp_send_json_success(
            array(
                'field_map'  => $out,
                'sync_url'   => admin_url( "admin.php?page=sheetsync&sheetsync_action=edit&connection_id={$connection_id}#tab-sync" ),
                'has_maps'   => ! empty( $out ),
            )
        );
    }

    public function ajax_bootstrap_connection(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $connection_id = absint( $_POST['connection_id'] ?? 0 );
        $conn          = SheetSync_Sync_Engine::get_connection( $connection_id );
        if ( ! $conn || empty( $conn->spreadsheet_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Connection not found or spreadsheet ID missing.', 'sheetsync-for-woocommerce' ) ) );
        }

        $steps = array();
        $fixes = array();
        $fatal = false;

        if ( SheetSync_Sync_Engine::is_orders_type( $conn->connection_type ?? 'products' ) ) {
            $steps[] = $this->run_bootstrap_step(
                'order_sheet',
                __( 'Prepare order sheet tab', 'sheetsync-for-woocommerce' ),
                static function () use ( $conn ) {
                    if ( ! class_exists( 'SheetSync_Order_Sync', false ) ) {
                        return array(
                            'success' => false,
                            'message' => __( 'Order sync module not available.', 'sheetsync-for-woocommerce' ),
                        );
                    }
                    try {
                        ( new SheetSync_Order_Sync() )->bootstrap_order_sheet( $conn );
                        return array(
                            'success' => true,
                            'message' => __( 'Order sheet tab is ready.', 'sheetsync-for-woocommerce' ),
                        );
                    } catch ( Exception $e ) {
                        return array( 'success' => false, 'message' => $e->getMessage() );
                    }
                }
            );
            if ( 'error' === ( $steps[ count( $steps ) - 1 ]['status'] ?? '' ) ) {
                $fatal = true;
            }
        } elseif ( 'wc_to_sheets' === ( $conn->sync_direction ?? '' ) ) {
            $sheet_mode = (string) get_option( 'sheetsync_product_sheet_mode_' . $connection_id, 'full' );
            $preset     = ( 'simple' === $sheet_mode ) ? 'minimal' : 'full';

            $steps[] = $this->run_bootstrap_step(
                'maps',
                __( 'Apply field mapping preset', 'sheetsync-for-woocommerce' ),
                static function () use ( $connection_id, $preset ) {
                    SheetSync_Pro_Admin::apply_preset_field_maps( $connection_id, $preset );
                    return array(
                        'success' => true,
                        'message' => 'minimal' === $preset
                            ? __( 'Minimal 5-column mapping saved.', 'sheetsync-for-woocommerce' )
                            : __( 'Full recommended mapping saved.', 'sheetsync-for-woocommerce' ),
                    );
                }
            );

            $steps[] = $this->run_bootstrap_step(
                'headers',
                __( 'Write export headers to sheet', 'sheetsync-for-woocommerce' ),
                static function () use ( $connection_id ) {
                    if ( ! class_exists( 'SheetSync_Bulk_Processor', false ) ) {
                        return array(
                            'success' => false,
                            'message' => __( 'Export module not available.', 'sheetsync-for-woocommerce' ),
                        );
                    }
                    $maps = SheetSync_Bulk_Processor::maps_for_sheet_export( $connection_id );
                    if ( empty( $maps ) ) {
                        return array(
                            'success' => false,
                            'message' => __( 'No field maps found after preset step.', 'sheetsync-for-woocommerce' ),
                        );
                    }
                    try {
                        SheetSync_Bulk_Processor::prepare_export_sheet_headers( $connection_id, $maps );
                        sheetsync_mark_template_written( $connection_id );
                        return array(
                            'success' => true,
                            'message' => __( 'Styled headers written to your sheet.', 'sheetsync-for-woocommerce' ),
                        );
                    } catch ( Exception $e ) {
                        return array( 'success' => false, 'message' => $e->getMessage() );
                    }
                }
            );
            if ( 'error' === ( $steps[ count( $steps ) - 1 ]['status'] ?? '' ) ) {
                $fatal = true;
            }
        } else {
            $steps[] = $this->run_bootstrap_step(
                'template',
                __( 'Write template to Google Sheet', 'sheetsync-for-woocommerce' ),
                static function () use ( $connection_id ) {
                    if ( ! class_exists( 'SheetSync_Sheet_Template_Writer', false ) ) {
                        return array(
                            'success' => false,
                            'message' => __( 'Template writer not available.', 'sheetsync-for-woocommerce' ),
                        );
                    }
                    $writer = new SheetSync_Sheet_Template_Writer();
                    $out    = $writer->write_to_connection( $connection_id );
                    if ( ! empty( $out['success'] ) ) {
                        sheetsync_mark_template_written( $connection_id );
                    }
                    return $out;
                }
            );
            if ( 'error' === ( $steps[ count( $steps ) - 1 ]['status'] ?? '' ) ) {
                $fatal = true;
                $fixes[] = array(
                    'message' => __( 'Share your Google Sheet with the service account (Editor), then try again.', 'sheetsync-for-woocommerce' ),
                    'url'     => admin_url( 'admin.php?page=sheetsync-settings' ),
                );
            }
        }

        if ( ! $fatal && ! SheetSync_Sync_Engine::is_orders_type( $conn->connection_type ?? 'products' ) ) {
            $check_step = $this->run_bootstrap_step(
                'check',
                __( 'Validate sheet data', 'sheetsync-for-woocommerce' ),
                static function () use ( $connection_id, &$fixes ) {
                    if ( ! class_exists( 'SheetSync_Sheet_Validator', false ) ) {
                        return array(
                            'success' => true,
                            'message' => __( 'Skipped — validator not loaded.', 'sheetsync-for-woocommerce' ),
                        );
                    }
                    if ( empty( SheetSync_Field_Mapper::get_maps( $connection_id ) ) ) {
                        return array(
                            'success' => false,
                            'message' => __( 'No field mapping — run template or mapping step first.', 'sheetsync-for-woocommerce' ),
                        );
                    }
                    $report = ( new SheetSync_Sheet_Validator() )->validate_connection( $connection_id );
                    if ( ! empty( $report['issues'] ) ) {
                        foreach ( $report['issues'] as $issue ) {
                            if ( ( $issue['level'] ?? '' ) === 'error' ) {
                                $fixes[] = array(
                                    'message' => (string) ( $issue['message'] ?? '' ),
                                    'row'     => (int) ( $issue['row'] ?? 0 ),
                                );
                            }
                        }
                    }
                    return array(
                        'success' => ! empty( $report['ok'] ),
                        'message' => ! empty( $report['ok'] )
                            ? sprintf(
                                /* translators: %d: number of rows checked */
                                __( 'Sheet looks good (%d rows checked).', 'sheetsync-for-woocommerce' ),
                                (int) ( $report['rows_checked'] ?? 0 )
                            )
                            : __( 'Sheet has issues — review the list below before syncing.', 'sheetsync-for-woocommerce' ),
                        'warn'    => empty( $report['ok'] ),
                    );
                }
            );
            if ( ! empty( $check_step['warn'] ) ) {
                $check_step['status'] = 'warn';
                unset( $check_step['warn'] );
            }
            $steps[] = $check_step;
        }

        $has_error = false;
        foreach ( $steps as $step ) {
            if ( 'error' === ( $step['status'] ?? '' ) ) {
                $has_error = true;
                break;
            }
        }

        if ( $has_error ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Automatic setup could not finish.', 'sheetsync-for-woocommerce' ),
                    'steps'   => $steps,
                    'fixes'   => $fixes,
                )
            );
        }

        wp_send_json_success(
            array(
                'message' => __( 'Your sheet is ready. Run your first sync when you are ready.', 'sheetsync-for-woocommerce' ),
                'steps'   => $steps,
                'fixes'   => $fixes,
            )
        );
    }

    /**
     * @param callable(): array{success: bool, message: string, warn?: bool} $runner
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function run_bootstrap_step( string $id, string $label, callable $runner ): array {
        try {
            $result = $runner();
        } catch ( Exception $e ) {
            $result = array(
                'success' => false,
                'message' => $e->getMessage(),
            );
        }

        $status = ! empty( $result['success'] ) ? 'ok' : 'error';
        if ( ! empty( $result['warn'] ) ) {
            $status = 'warn';
        }

        return array(
            'id'      => $id,
            'label'   => $label,
            'status'  => $status,
            'message' => (string) ( $result['message'] ?? '' ),
        );
    }

    public function ajax_test_webhook(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        if ( ! sheetsync_is_pro() ) {
            wp_send_json_error( array( 'message' => __( 'Pro required for webhook testing.', 'sheetsync-for-woocommerce' ) ) );
        }

        $connection_id = absint( $_POST['connection_id'] ?? 0 );
        $result        = SheetSync_Webhook_Handler::test_webhook_from_admin( $connection_id );

        if ( ! $result['success'] ) {
            wp_send_json_error( $result );
        }

        wp_send_json_success( $result );
    }

    public function ajax_resolve_conflict(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $connection_id = absint( $_POST['connection_id'] ?? 0 );
        $map_id        = absint( $_POST['map_id'] ?? 0 );
        $resolution    = sanitize_key( wp_unslash( $_POST['resolution'] ?? '' ) );

        if ( ! $connection_id || ! $map_id || ! class_exists( 'SheetSync_Conflict_Resolver', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request.', 'sheetsync-for-woocommerce' ) ) );
        }

        $result = SheetSync_Conflict_Resolver::resolve_queued_conflict( $connection_id, $map_id, $resolution );
        if ( ! $result['success'] ) {
            wp_send_json_error( $result );
        }
        wp_send_json_success( $result );
    }

    public function ajax_write_template(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $connection_id = absint( $_POST['connection_id'] ?? 0 );
        if ( ! $connection_id || ! class_exists( 'SheetSync_Sheet_Template_Writer', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request.', 'sheetsync-for-woocommerce' ) ) );
        }

        $writer = new SheetSync_Sheet_Template_Writer();
        $out    = $writer->write_to_connection( $connection_id );
        if ( $out['success'] ) {
            sheetsync_mark_template_written( $connection_id );
            wp_send_json_success( $out );
        }
        wp_send_json_error( $out );
    }

    public function ajax_reveal_webhook_secret(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $connection_id = absint( $_POST['connection_id'] ?? 0 );
        $secret        = function_exists( 'sheetsync_resolve_webhook_secret' )
            ? sheetsync_resolve_webhook_secret( $connection_id )
            : (string) get_option( 'sheetsync_webhook_secret', '' );

        if ( $secret === '' ) {
            wp_send_json_error( array( 'message' => __( 'Webhook secret is not configured.', 'sheetsync-for-woocommerce' ) ) );
        }

        wp_send_json_success( array( 'secret' => $secret ) );
    }

    public function ajax_test_image_url(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $url    = esc_url_raw( wp_unslash( $_POST['image_url'] ?? '' ) );
        $result = sheetsync_probe_remote_image_url( $url );

        if ( ! $result['ok'] ) {
            wp_send_json_error( array( 'message' => $result['message'] ) );
        }

        wp_send_json_success(
            array(
                'message'      => $result['message'],
                'content_type' => $result['content_type'] ?? '',
                'method'       => $result['method'] ?? '',
            )
        );
    }

    public function ajax_set_sync_direction(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $connection_id = absint( $_POST['connection_id'] ?? 0 );
        $direction     = sanitize_text_field( wp_unslash( $_POST['sync_direction'] ?? '' ) );

        if ( ! $connection_id || ! in_array( $direction, array( 'sheets_to_wc', 'wc_to_sheets', 'two_way' ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request.', 'sheetsync-for-woocommerce' ) ) );
        }

        $conn = SheetSync_Sync_Engine::get_connection( $connection_id );
        if ( ! $conn ) {
            wp_send_json_error( array( 'message' => __( 'Connection not found.', 'sheetsync-for-woocommerce' ) ) );
        }

        if ( ! sheetsync_is_pro() && in_array( $direction, array( 'wc_to_sheets', 'two_way' ), true ) ) {
            wp_send_json_error(
                array(
                    'message'     => __( 'Upgrade to Pro to change sync direction.', 'sheetsync-for-woocommerce' ),
                    'upgrade_url' => function_exists( 'sheetsync_upgrade_url' ) ? sheetsync_upgrade_url() : '',
                ),
                403
            );
        }

        SheetSync_Sync_Engine::save_connection(
            array(
                'name'             => $conn->name ?? '',
                'spreadsheet_id'   => $conn->spreadsheet_id ?? '',
                'sheet_name'       => $conn->sheet_name ?? 'Sheet1',
                'header_row'       => (int) ( $conn->header_row ?? 1 ),
                'status'           => $conn->status ?? 'active',
                'connection_type'  => $conn->connection_type ?? 'products',
                'sync_direction'   => $direction,
            ),
            $connection_id
        );

        wp_send_json_success(
            array(
                'sync_direction' => $direction,
                'message'        => __( 'Sync direction saved.', 'sheetsync-for-woocommerce' ),
            )
        );
    }

    public function ajax_manual_sync(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }

        $connection_id = absint( $_POST['connection_id'] ?? 0 );
        if ( ! $connection_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid connection ID.', 'sheetsync-for-woocommerce' ) ) );
        }

        $intent = sanitize_key( wp_unslash( $_POST['intent'] ?? '' ) );

        $options = array(
            'triggered_by'        => 'manual',
            'confirm_full_export' => ! empty( $_POST['confirm_full_export'] ),
            'pull_before_export'  => ! empty( $_POST['pull_before_export'] ),
            'force_degraded_sync' => ! empty( $_POST['force_degraded_sync'] ),
            'sync_strategy'       => sanitize_text_field( wp_unslash( $_POST['sync_strategy'] ?? '' ) ),
            'sync_job_direction'  => sanitize_text_field( wp_unslash( $_POST['sync_job_direction'] ?? '' ) ),
            'sync_pull_mode'      => sanitize_text_field( wp_unslash( $_POST['sync_pull_mode'] ?? 'default' ) ),
        );

        if ( ! class_exists( 'SheetSync_Sync_Orchestrator', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Sync orchestrator unavailable.', 'sheetsync-for-woocommerce' ) ) );
        }

        try {
            $result = SheetSync_Sync_Orchestrator::start( $connection_id, $intent, $options );
        } catch ( \Throwable $e ) {
            $error_msg = $e->getMessage();
            if ( function_exists( 'sheetsync_debug_log' ) ) {
                $trace = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? ' | Trace: ' . $e->getTraceAsString() : '';
                sheetsync_debug_log( 'AJAX Sync Error: ' . $error_msg . $trace );
            }
            SheetSync_Logger::log( $connection_id, 'manual', 'error', 0, 0, $error_msg );
            wp_send_json_error( array( 'message' => esc_html( $error_msg ) ) );
            return;
        }

        if ( $result['success'] || ( ! empty( $result['job_id'] ) && ( ! empty( $result['async'] ) || ! empty( $result['show_progress'] ) || (int) ( $result['processed'] ?? 0 ) > 0 ) ) ) {
            wp_send_json_success( $result );
        } else {
            $msg = (string) ( $result['message'] ?? __( 'Sync failed.', 'sheetsync-for-woocommerce' ) );
            if ( function_exists( 'sheetsync_debug_log' ) ) {
                sheetsync_debug_log( 'Sync Failed (Non-Fatal): ' . $msg );
            }
            SheetSync_Logger::error( $msg, $connection_id, (int) ( $result['job_id'] ?? 0 ) );
            wp_send_json_error( array_merge( $result, array( 'message' => $msg ) ) );
        }
    }

    public function ajax_note_host_timeout(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }
        if ( function_exists( 'sheetsync_record_host_timeout' ) ) {
            sheetsync_record_host_timeout();
        }
        wp_send_json_success( array( 'recorded' => true ) );
    }

    public function ajax_sync_tick(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $connection_id = absint( $_POST['connection_id'] ?? 0 );
        $job_id        = absint( $_POST['job_id'] ?? 0 );
        $inline_drain  = ! empty( $_POST['inline_drain'] );

        if ( ! class_exists( 'SheetSync_Sync_Orchestrator', false ) ) {
            wp_send_json_success( array( 'job' => null, 'drained' => false ) );
        }

        if ( class_exists( 'SheetSync_Sync_Tick_Perf_Log', false ) ) {
            SheetSync_Sync_Tick_Perf_Log::begin( $job_id );
        }

        $tick_result = SheetSync_Sync_Orchestrator::tick( $connection_id, $job_id, $inline_drain );

        if ( class_exists( 'SheetSync_Sync_Tick_Perf_Log', false ) ) {
            SheetSync_Sync_Tick_Perf_Log::end( $tick_result );
        }

        wp_send_json_success( $tick_result );
    }

    public function ajax_drain_job(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }

        $connection_id = absint( $_POST['connection_id'] ?? 0 );
        $job_id        = absint( $_POST['job_id'] ?? 0 );

        if ( ! class_exists( 'SheetSync_Job_Repository', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Job engine not available.', 'sheetsync-for-woocommerce' ) ) );
        }

        $repo = new SheetSync_Job_Repository();
        if ( $job_id < 1 && $connection_id > 0 ) {
            $running = $repo->get_running_for_connection( $connection_id );
            $job_id  = $running ? (int) $running->id : 0;
        }

        $job = $job_id > 0 ? $repo->get( $job_id ) : null;
        if ( ! $job || in_array( (string) $job->status, array( 'completed', 'failed', 'cancelled' ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'No active sync job.', 'sheetsync-for-woocommerce' ) ) );
        }

        if ( $connection_id < 1 ) {
            $connection_id = (int) $job->connection_id;
        }

        if ( function_exists( 'sheetsync_reschedule_stuck_job' ) ) {
            sheetsync_reschedule_stuck_job( $job_id, 0 );
        } elseif ( function_exists( 'sheetsync_schedule_job_action' ) ) {
            sheetsync_schedule_job_action( $job_id, 'sheetsync_run_job', 0 );
        }

        // Stay under typical shared-host gateway limits (~10–30s connection timeout).
        $seconds = function_exists( 'sheetsync_admin_request_budget_seconds' )
            ? sheetsync_admin_request_budget_seconds()
            : (int) apply_filters( 'sheetsync_admin_drain_seconds', 4 );
        if ( function_exists( 'sheetsync_process_job_inline' ) ) {
            sheetsync_process_job_inline( $job_id, max( 3, $seconds ) );
        }

        $job = $repo->get( $job_id );
        $payload = function_exists( 'sheetsync_job_rest_payload' )
            ? sheetsync_job_rest_payload( $job )
            : array(
                'id'              => (int) $job->id,
                'status'          => $job->status,
                'processed_count' => (int) ( $job->processed_count ?? 0 ),
                'total_estimate'  => (int) ( $job->total_estimate ?? 0 ),
            );

        wp_send_json_success(
            array(
                'job' => $payload,
            )
        );
    }

    public function ajax_test_connection(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $spreadsheet_id = sanitize_text_field( wp_unslash( $_POST['spreadsheet_id'] ?? '' ) );
        $sheet_name     = trim( sanitize_text_field( wp_unslash( $_POST['sheet_name'] ?? '' ) ) );
        if ( empty( $spreadsheet_id ) ) {
            wp_send_json_error( array( 'message' => __( 'No spreadsheet ID provided.', 'sheetsync-for-woocommerce' ) ) );
        }

        if ( function_exists( 'sheetsync_parse_spreadsheet_id' ) ) {
            $spreadsheet_id = sheetsync_parse_spreadsheet_id( $spreadsheet_id );
        }

        $result = SheetSync_Google_Auth::test_connection( $spreadsheet_id );
        if ( ! $result['success'] ) {
            wp_send_json_error( $result );
        }

        sheetsync_update_setup_progress( 'sheet_shared', true );

        if ( class_exists( 'SheetSync_Sheets_Client', false ) ) {
            ( new SheetSync_Sheets_Client() )->invalidate_sheet_grid_cache( $spreadsheet_id );
        }

        if ( $sheet_name !== '' && ! empty( $result['sheets'] ) ) {
            $result['sheet_tab_found'] = in_array( $sheet_name, $result['sheets'], true );
            if ( ! $result['sheet_tab_found'] ) {
                $result['sheet_tab_warning'] = sprintf(
                    /* translators: %s: sheet tab name */
                    __( 'Tab "%s" was not found. Save the connection to create it automatically, or add the tab manually in Google Sheets.', 'sheetsync-for-woocommerce' ),
                    $sheet_name
                );
            }
        }

        $connection_id = absint( $_POST['connection_id'] ?? 0 );
        if ( $connection_id > 0 && function_exists( 'sheetsync_verify_sheet_tab' ) ) {
            $verify = sheetsync_verify_sheet_tab( $connection_id, null, false );
            $result['merchant_ready_message'] = (string) ( $verify['message'] ?? '' );
            $result['sheet_tab_ready']        = ! empty( $verify['ok'] );
            if ( ! empty( $verify['fix_url'] ) ) {
                $result['fix_url'] = (string) $verify['fix_url'];
            }
        }

        wp_send_json_success( $result );
    }

    public function ajax_test_external_cron(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }
        if ( ! class_exists( 'SheetSync_External_Cron', false ) ) {
            wp_send_json_error( array( 'message' => __( 'External cron is not available.', 'sheetsync-for-woocommerce' ) ) );
        }

        $result = SheetSync_External_Cron::run( 15 );
        $health = function_exists( 'sheetsync_get_action_scheduler_health' )
            ? sheetsync_get_action_scheduler_health()
            : array();

        wp_send_json_success(
            array(
                'result'    => $result,
                'scheduler' => $health,
                'message'   => sprintf(
                    /* translators: 1: past-due count before, 2: past-due count after */
                    __( 'Queue run finished. Past-due tasks: %1$d → %2$d.', 'sheetsync-for-woocommerce' ),
                    (int) ( $result['scheduler_before']['past_due'] ?? 0 ),
                    (int) ( $result['scheduler_after']['past_due'] ?? 0 )
                ),
            )
        );
    }

    public function handle_regenerate_cron_token(): void {
        check_admin_referer( 'sheetsync_regenerate_cron_token' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Forbidden', 'sheetsync-for-woocommerce' ), 403 );
        }
        if ( class_exists( 'SheetSync_External_Cron', false ) ) {
            SheetSync_External_Cron::regenerate_token();
        }
        $this->add_notice( 'success', __( 'Cron token regenerated. Update your server cron job with the new header token.', 'sheetsync-for-woocommerce' ) );
        wp_safe_redirect( admin_url( 'admin.php?page=sheetsync-settings' ) );
        exit;
    }

    public function ajax_test_google_auth(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $result = SheetSync_Google_Auth::test_auth();
        if ( ! $result['success'] ) {
            wp_send_json_error( $result );
        }

        sheetsync_update_setup_progress( 'google_connected', true );
        wp_send_json_success( $result );
    }

    // ─── Setup wizard AJAX ────────────────────────────────────────────────────

    public function ajax_save_wizard_state(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $raw   = wp_unslash( $_POST['state'] ?? array() );
        $patch = is_array( $raw ) ? $raw : array();

        $allowed = array( 'step', 'sync_direction', 'spreadsheet_id', 'sheet_name', 'connection_name', 'connection_id', 'sheet_mode' );
        $clean   = array();
        foreach ( $allowed as $key ) {
            if ( ! array_key_exists( $key, $patch ) ) {
                continue;
            }
            if ( $key === 'step' || $key === 'connection_id' ) {
                $clean[ $key ] = absint( $patch[ $key ] );
            } elseif ( $key === 'spreadsheet_id' ) {
                $val = sanitize_text_field( (string) $patch[ $key ] );
                $clean[ $key ] = function_exists( 'sheetsync_parse_spreadsheet_id' )
                    ? sheetsync_parse_spreadsheet_id( $val )
                    : $val;
            } elseif ( $key === 'sync_direction' ) {
                $dir = sanitize_text_field( (string) $patch[ $key ] );
                if ( ! in_array( $dir, array( 'sheets_to_wc', 'wc_to_sheets', 'two_way' ), true ) ) {
                    $dir = 'sheets_to_wc';
                }
                if ( ! sheetsync_is_pro() && in_array( $dir, array( 'wc_to_sheets', 'two_way' ), true ) ) {
                    $dir = 'sheets_to_wc';
                }
                $clean[ $key ] = $dir;
            } else {
                $clean[ $key ] = sanitize_text_field( (string) $patch[ $key ] );
            }
        }

        $state = function_exists( 'sheetsync_save_wizard_state' )
            ? sheetsync_save_wizard_state( $clean )
            : $clean;

        wp_send_json_success( array( 'state' => $state ) );
    }

    public function ajax_wizard_save_google(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $json = sanitize_textarea_field( wp_unslash( $_POST['service_account_json'] ?? '' ) );
        if ( $json === '' ) {
            $result = SheetSync_Google_Auth::test_auth();
            if ( ! $result['success'] ) {
                wp_send_json_error( $result );
            }
            wp_send_json_success( $result );
        }

        if ( ! SheetSync_Google_Auth::save_credentials( $json ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid Service Account JSON. Please check the file.', 'sheetsync-for-woocommerce' ) ) );
        }

        $result = SheetSync_Google_Auth::test_auth();
        if ( ! $result['success'] ) {
            wp_send_json_error( $result );
        }

        sheetsync_update_setup_progress( 'google_connected', true );
        $result['email'] = SheetSync_Google_Auth::get_account_email();
        wp_send_json_success( $result );
    }

    public function ajax_wizard_create_connection(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        if ( ! sheetsync_is_pro() ) {
            global $wpdb;
            $connection_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sheetsync_connections" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            if ( $connection_count >= 1 ) {
                wp_send_json_error( array(
                    'message' => __( 'Free plan allows 1 connection. Upgrade to Pro for unlimited connections.', 'sheetsync-for-woocommerce' ),
                ) );
            }
        }

        $raw_spreadsheet = sanitize_text_field( wp_unslash( $_POST['spreadsheet_id'] ?? '' ) );
        $spreadsheet_id    = function_exists( 'sheetsync_parse_spreadsheet_id' )
            ? sheetsync_parse_spreadsheet_id( $raw_spreadsheet )
            : $raw_spreadsheet;

        if ( $spreadsheet_id === '' ) {
            wp_send_json_error( array( 'message' => __( 'No spreadsheet ID provided.', 'sheetsync-for-woocommerce' ) ) );
        }

        $data = array(
            'name'            => sanitize_text_field( wp_unslash( $_POST['connection_name'] ?? __( 'My products sheet', 'sheetsync-for-woocommerce' ) ) ),
            'spreadsheet_id'  => $spreadsheet_id,
            'sheet_name'      => sanitize_text_field( wp_unslash( $_POST['sheet_name'] ?? 'Sheet1' ) ),
            'header_row'      => 1,
            'status'          => 'active',
            'connection_type' => 'products',
            'sync_direction'  => sanitize_text_field( wp_unslash( $_POST['sync_direction'] ?? 'sheets_to_wc' ) ),
        );

        if ( ! sheetsync_is_pro() && in_array( $data['sync_direction'], array( 'wc_to_sheets', 'two_way' ), true ) ) {
            $data['sync_direction'] = 'sheets_to_wc';
        }

        $saved_id = SheetSync_Sync_Engine::save_connection( $data, null );

        if ( function_exists( 'sheetsync_save_wizard_state' ) ) {
            sheetsync_save_wizard_state( array(
                'connection_id'   => $saved_id,
                'connection_name' => $data['name'],
                'spreadsheet_id'  => $spreadsheet_id,
                'sheet_name'      => $data['sheet_name'],
                'sync_direction'  => $data['sync_direction'],
                'step'            => 5,
            ) );
        }

        sheetsync_update_setup_progress( 'connection_created', true );
        sheetsync_update_setup_progress( 'sheet_shared', true );

        wp_send_json_success( array(
            'connection_id' => $saved_id,
            'message'       => __( 'Connection created.', 'sheetsync-for-woocommerce' ),
        ) );
    }

    public function ajax_wizard_finish(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $conn_id = absint( $_POST['connection_id'] ?? 0 );
        if ( ! $conn_id && function_exists( 'sheetsync_get_wizard_state' ) ) {
            $state   = sheetsync_get_wizard_state();
            $conn_id = (int) ( $state['connection_id'] ?? 0 );
        }

        if ( sheetsync_is_pro() && ! empty( $_POST['enable_realtime'] ) && $conn_id && function_exists( 'sheetsync_apply_automatic_sync' ) ) {
            sheetsync_apply_automatic_sync( $conn_id, true );
        }

        if ( function_exists( 'sheetsync_save_wizard_state' ) ) {
            sheetsync_save_wizard_state( array(
                'completed' => true,
                'step'      => 7,
            ) );
        }

        delete_option( 'sheetsync_redirect_to_wizard' );
        sheetsync_refresh_setup_progress();

        $cron_payload = array();
        if ( function_exists( 'sheetsync_get_action_scheduler_health' )
            && class_exists( 'SheetSync_External_Cron', false ) ) {
            $health = sheetsync_get_action_scheduler_health();
            if ( (int) ( $health['past_due'] ?? 0 ) >= 5 ) {
                SheetSync_External_Cron::set_enabled( true );
                SheetSync_External_Cron::get_token();
                $cron_payload = array(
                    'cron_enabled'    => true,
                    'cron_url'        => SheetSync_External_Cron::get_rest_endpoint_url(),
                    'cron_curl'       => SheetSync_External_Cron::get_example_curl(),
                    'scheduler_unhealthy' => true,
                );
            }
        }

        $redirect = $conn_id
            ? admin_url( 'admin.php?page=sheetsync&sheetsync_action=edit&connection_id=' . $conn_id . '#tab-sync' )
            : admin_url( 'admin.php?page=sheetsync' );

        wp_send_json_success( array_merge(
            array(
                'redirect' => $redirect,
                'message'  => __( 'Setup complete!', 'sheetsync-for-woocommerce' ),
            ),
            $cron_payload
        ) );
    }

    public function ajax_wizard_skip(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        if ( function_exists( 'sheetsync_save_wizard_state' ) ) {
            sheetsync_save_wizard_state( array( 'skipped' => true ) );
        }
        delete_option( 'sheetsync_redirect_to_wizard' );

        wp_send_json_success();
    }

    public function ajax_create_spreadsheet(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $title  = sanitize_text_field( wp_unslash( $_POST['title'] ?? __( 'SheetSync Products', 'sheetsync-for-woocommerce' ) ) );
        $result = SheetSync_Drive_Client::create_spreadsheet( $title );

        if ( ! $result['success'] ) {
            wp_send_json_error( $result );
        }

        wp_send_json_success( $result );
    }

    public function ajax_wizard_add_another(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        if ( function_exists( 'sheetsync_save_wizard_state' ) ) {
            sheetsync_save_wizard_state( array(
                'step'            => 2,
                'connection_id'   => 0,
                'spreadsheet_id'  => '',
                'sheet_name'      => 'Sheet1',
                'connection_name' => '',
                'completed'       => false,
            ) );
        }

        wp_send_json_success( array(
            'redirect' => admin_url( 'admin.php?page=sheetsync-setup' ),
        ) );
    }

    // ─── Admin notices ────────────────────────────────────────────────────────

    public function show_admin_notices(): void {
        if ( ! $this->is_sheetsync_admin_screen() ) {
            return;
        }

        $conflict_count = function_exists( 'sheetsync_count_all_conflicts' ) ? sheetsync_count_all_conflicts() : 0;
        if ( $conflict_count > 0 ) {
            $conflicts_url = admin_url( 'admin.php?page=sheetsync-conflicts' );
            echo '<div class="notice notice-warning"><p><strong>SheetSync:</strong> ';
            echo wp_kses_post(
                sprintf(
                    /* translators: 1: conflict count, 2: inbox URL */
                    _n(
                        '%1$d sync conflict needs review. <a href="%2$s">Open Sync Conflicts</a>',
                        '%1$d sync conflicts need review. <a href="%2$s">Open Sync Conflicts</a>',
                        $conflict_count,
                        'sheetsync-for-woocommerce'
                    ),
                    number_format_i18n( $conflict_count ),
                    esc_url( $conflicts_url )
                )
            );
            echo '</p></div>';
        }

        if ( class_exists( 'SheetSync_External_Cron', false )
            && SheetSync_External_Cron::is_enabled()
            && ! SheetSync_External_Cron::is_operational() ) {
            $settings_url = admin_url( 'admin.php?page=sheetsync-settings' );
            echo '<div class="notice notice-warning"><p><strong>SheetSync:</strong> ';
            echo wp_kses_post(
                sprintf(
                    /* translators: %s: settings URL */
                    __( 'Background Cron is enabled in settings but your server has not called the cron URL recently. Sync still works with the browser tab open. Add the cPanel cron job from <a href="%s">Settings → Background Cron</a>, or uncheck the option if you only use manual sync.', 'sheetsync-for-woocommerce' ),
                    esc_url( $settings_url )
                )
            );
            echo '</p></div>';
        }

        $notices = get_transient( 'sheetsync_admin_notices_' . get_current_user_id() );
        if ( ! $notices ) {
            return;
        }

        foreach ( $notices as $notice ) {
            $type    = sanitize_key( $notice['type'] ?? 'info' );
            $message = $notice['message'] ?? '';
            if ( $message === '' ) {
                continue;
            }
            if ( ! in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ) {
                $type = 'info';
            }
            echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . wp_kses_post( $message ) . '</p></div>';
        }

        delete_transient( 'sheetsync_admin_notices_' . get_current_user_id() );
    }

    /**
     * Limit SheetSync flash notices to SheetSync admin pages (avoids clutter on unrelated screens).
     */
    private function is_sheetsync_admin_screen(): bool {
        if ( ! is_admin() ) {
            return false;
        }
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return $page !== '' && str_contains( $page, 'sheetsync' );
    }

    private function add_notice( string $type, string $message ): void {
        $key     = 'sheetsync_admin_notices_' . get_current_user_id();
        $notices = get_transient( $key ) ?: array();
        $notices[] = array( 'type' => $type, 'message' => $message );
        set_transient( $key, $notices, 60 );
    }

    public function ajax_import_headers(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( array( 'message' => __( 'Forbidden', 'sheetsync-for-woocommerce' ) ), 403 );

        $conn_id = absint( $_POST['connection_id'] ?? 0 );
        $conn    = SheetSync_Sync_Engine::get_connection( $conn_id );
        if ( ! $conn ) wp_send_json_error( array( 'message' => __( 'Connection not found.', 'sheetsync-for-woocommerce' ) ) );

        $is_pro = sheetsync_is_pro();

        try {
            $client  = new SheetSync_Sheets_Client();
            $range   = "{$conn->sheet_name}!A{$conn->header_row}:Z{$conn->header_row}";
            $rows    = $client->get_rows( $conn->spreadsheet_id, $range );
            $headers = $rows[0] ?? array();

            // Treat an all-blank header row as "no headers" so we seed maps + write labels
            // (PHP empty( [ '' ] ) is false, which previously skipped the empty-sheet branch).
            $headers_trimmed = array_map( 'trim', array_map( 'strval', $headers ) );
            if ( array() === $headers_trimmed || ! array_filter( $headers_trimmed, static fn( $c ) => $c !== '' ) ) {
                $headers = array();
            }

            // ── If Sheet has no headers → apply canonical preset + write styled headers ──
            if ( empty( $headers ) ) {
                $preset = $is_pro ? SheetSync_Field_Mapper::PROFILE_FULL : SheetSync_Field_Mapper::PROFILE_MINIMAL;
                self::apply_preset_field_maps( $conn_id, $preset );

                $maps = $is_pro && class_exists( 'SheetSync_Bulk_Processor', false )
                    ? SheetSync_Bulk_Processor::maps_for_sheet_export( $conn_id )
                    : SheetSync_Field_Mapper::get_maps( $conn_id );

                if ( class_exists( 'SheetSync_Bulk_Processor', false ) ) {
                    SheetSync_Bulk_Processor::prepare_export_sheet_headers( $conn_id, $maps );
                } else {
                    $header_labels = SheetSync_Field_Mapper::build_header_row_from_maps( $maps );
                    $client->write_styled_headers(
                        $conn->spreadsheet_id,
                        $conn->sheet_name,
                        (int) $conn->header_row,
                        $header_labels
                    );
                }

                $matched = array();
                foreach ( $maps as $wc_field => $info ) {
                    $col = trim( (string) ( $info['sheet_column'] ?? '' ) );
                    if ( $col === '' ) {
                        continue;
                    }
                    $label     = SheetSync_Field_Mapper::header_label_for_field( $wc_field );
                    $matched[] = array(
                        'wc_field'   => $wc_field,
                        'col_letter' => $col,
                        'header'     => $label,
                        'label'      => $label,
                        'auto'       => true,
                    );
                }

                $notice = $is_pro
                    ? __( 'All fields written to your Google Sheet with styling! Column mapping applied automatically.', 'sheetsync-for-woocommerce' )
                    : __( 'Free fields written to your Google Sheet with styling! Upgrade to Pro to add more fields.', 'sheetsync-for-woocommerce' );

                wp_send_json_success( array(
                    'matched'        => $matched,
                    'unmatched'      => array(),
                    'headers'        => SheetSync_Field_Mapper::build_header_row_from_maps( $maps ),
                    'auto_generated' => true,
                    'headers_written'=> true,
                    'notice'         => $notice,
                ) );
                return;
            }

            // ── Sheet already has headers → auto-detect and match ─────────
            // Also re-style existing headers.
            $client->write_styled_headers(
                $conn->spreadsheet_id,
                $conn->sheet_name,
                (int) $conn->header_row,
                $headers
            );

            $matched   = array();
            $unmatched = array();

            // Track already-assigned WC fields to prevent duplicates
            $assigned_fields = array();

            foreach ( $headers as $idx => $header ) {
                $letter = SheetSync_Field_Mapper::index_to_col( $idx );
                $found  = SheetSync_Field_Mapper::detect_wc_field_from_header( (string) $header );

                // For free users, only allow matching to FREE_FIELDS
                if ( $found && ! $is_pro && ! isset( SheetSync_Field_Mapper::FREE_FIELDS[ $found ] ) ) {
                    $found = '';
                }

                // Prevent duplicate field assignments (first occurrence wins)
                if ( $found && isset( $assigned_fields[ $found ] ) ) {
                    $found = '';
                }

                if ( $found ) {
                    $assigned_fields[ $found ] = true;
                    $matched[] = array(
                        'wc_field'   => $found,
                        'col_letter' => $letter,
                        'header'     => $header,
                        'label'      => SheetSync_Field_Mapper::header_label_for_field( $found ),
                    );
                } else {
                    $unmatched[] = array( 'col_letter' => $letter, 'header' => $header );
                }
            }

            // ── Server-side save: persist matched field maps immediately ──
            $field_map_to_save = array();
            foreach ( $matched as $m ) {
                $wc_field = $m['wc_field'];
                $field_map_to_save[ $wc_field ] = array(
                    'column' => $m['col_letter'],
                    'key'    => ( $wc_field === '_sku' ) ? 1 : 0,
                );
            }
            if ( ! empty( $field_map_to_save ) ) {
                SheetSync_Sync_Engine::save_field_maps( $conn_id, $field_map_to_save );
            }

            wp_send_json_success( array(
                'matched'        => $matched,
                'unmatched'      => $unmatched,
                'headers'        => $headers,
                'headers_written'=> true,
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => esc_html( $e->getMessage() ) ) );
        }
    }

    /**
     * Force-write styled export headers from field mapping (WC → Sheet).
     */
    public function ajax_apply_sheet_formatting(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $conn_id = absint( $_POST['connection_id'] ?? 0 );
        if ( ! $conn_id || ! class_exists( 'SheetSync_Bulk_Processor', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request.', 'sheetsync-for-woocommerce' ) ) );
        }

        $out = SheetSync_Bulk_Processor::apply_sheet_formatting_for_connection( $conn_id );
        if ( ! empty( $out['success'] ) ) {
            wp_send_json_success( array( 'message' => (string) ( $out['message'] ?? '' ) ) );
        }
        wp_send_json_error( array( 'message' => (string) ( $out['message'] ?? __( 'Could not apply formatting.', 'sheetsync-for-woocommerce' ) ) ) );
    }

    public function ajax_write_export_headers(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $conn_id = absint( $_POST['connection_id'] ?? 0 );
        $conn    = SheetSync_Sync_Engine::get_connection( $conn_id );
        if ( ! $conn ) {
            wp_send_json_error( array( 'message' => __( 'Connection not found.', 'sheetsync-for-woocommerce' ) ) );
        }

        $maps = SheetSync_Bulk_Processor::maps_for_sheet_export( $conn_id );
        if ( empty( $maps ) ) {
            wp_send_json_error( array( 'message' => __( 'Save Field Mapping first.', 'sheetsync-for-woocommerce' ) ) );
        }

        if ( ! class_exists( 'SheetSync_Bulk_Processor', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Export module not available.', 'sheetsync-for-woocommerce' ) ) );
        }

        try {
            SheetSync_Bulk_Processor::prepare_export_sheet_headers( $conn_id, $maps );
            wp_send_json_success(
                array(
                    'message' => sprintf(
                        /* translators: 1: header row number, 2: sheet tab name */
                        __( 'Professional template styling applied to row %1$d on “%2$s” (same look as Sheet → WooCommerce template). Export products starting on the next row.', 'sheetsync-for-woocommerce' ),
                        max( 1, (int) $conn->header_row ),
                        $conn->sheet_name
                    ),
                )
            );
        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => esc_html( $e->getMessage() ) ) );
        }
    }

    public function ajax_get_headers(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( array( 'message' => __( 'Forbidden', 'sheetsync-for-woocommerce' ) ), 403 );

        $conn_id = absint( $_POST['connection_id'] ?? 0 );
        $conn    = SheetSync_Sync_Engine::get_connection( $conn_id );
        if ( ! $conn ) wp_send_json_error( array( 'message' => __( 'Connection not found.', 'sheetsync-for-woocommerce' ) ) );

        try {
            $client   = new SheetSync_Sheets_Client();
            $range    = "{$conn->sheet_name}!A{$conn->header_row}:Z{$conn->header_row}";
            $rows     = $client->get_rows( $conn->spreadsheet_id, $range );
            $headers  = $rows[0] ?? array();
            wp_send_json_success( array( 'headers' => $headers ) );
        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => esc_html( $e->getMessage() ) ) );
        }
    }

    public function ajax_import_from_sheet(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $is_pro = function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro();

        if ( function_exists( 'set_time_limit' ) ) {
            set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.DiscouragedFunctions.discouraged
        }
        wp_raise_memory_limit( 'admin' );

        $conn_id       = absint( $_POST['connection_id'] ?? 0 );
        $raw_field_map = wp_unslash( $_POST['field_map'] ?? '{}' );
        $field_map     = is_array( $raw_field_map ) ? $raw_field_map : json_decode( (string) $raw_field_map, true );
        if ( ! is_array( $field_map ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid field map.', 'sheetsync-for-woocommerce' ) ) );
        }

        $skip_existing = ! empty( $_POST['skip_existing'] );
        $create_new     = ! empty( $_POST['create_new'] );

        $conn = SheetSync_Sync_Engine::get_connection( $conn_id );
        if ( ! $conn ) {
            wp_send_json_error( array( 'message' => __( 'Connection not found.', 'sheetsync-for-woocommerce' ) ) );
        }

        $allowed_fields = array_keys( SheetSync_Field_Mapper::get_available_fields( $is_pro ) );
        if ( $is_pro ) {
            $allowed_fields = array_merge( $allowed_fields, array( 'parent_sku', 'variation_attrs', 'sheet_color', 'sheet_size' ) );
        }

        $maps = SheetSync_Field_Mapper::get_maps_for_sync( $conn_id, $conn );
        foreach ( $field_map as $wc_field => $col_letter ) {
            $wcf = is_string( $wc_field ) ? trim( $wc_field ) : '';
            $col = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $col_letter ) );
            if ( '' === $wcf || '' === $col || ! in_array( $wcf, $allowed_fields, true ) ) {
                continue;
            }
            $maps[ $wcf ] = array(
                'sheet_column' => $col,
                'is_key_field' => ( '_sku' === $wcf ),
            );
        }
        if ( empty( $maps ) ) {
            wp_send_json_error( array( 'message' => __( 'No valid field map.', 'sheetsync-for-woocommerce' ) ) );
        }
        SheetSync_Field_Mapper::invalidate_cache( $conn_id );

        // Always read a bounded window — never load the full sheet into memory.
        $batch_offset = isset( $_POST['batch_offset'] ) ? max( 0, absint( $_POST['batch_offset'] ) ) : 0;
        $batch_size   = isset( $_POST['batch_size'] ) ? max( 1, min( 80, absint( $_POST['batch_size'] ) ) ) : 35;
        $import_session = sanitize_text_field( wp_unslash( $_POST['import_session_id'] ?? '' ) );
        $is_first_batch = ( 0 === $batch_offset );

        if ( function_exists( 'sheetsync_acquire_bulk_import_session' ) ) {
            $lock = sheetsync_acquire_bulk_import_session( $conn_id, $import_session, $is_first_batch );
            if ( empty( $lock['ok'] ) ) {
                wp_send_json_error(
                    array( 'message' => __( 'Another import is already running for this connection.', 'sheetsync-for-woocommerce' ) )
                );
            }
            $import_session = (string) ( $lock['session_id'] ?? '' );
        }

        $first_data_row_1based = (int) $conn->header_row + 1;
        $start_row             = $first_data_row_1based + $batch_offset;
        $end_row               = $start_row + $batch_size - 1;

        try {
            $client   = new SheetSync_Sheets_Client();
            $last_col = SheetSync_Field_Mapper::max_column_letter( $maps );
            $range    = "{$conn->sheet_name}!A{$start_row}:{$last_col}{$end_row}";
            $data_rows = function_exists( 'sheetsync_get_sheet_rows_with_retry' )
                ? sheetsync_get_sheet_rows_with_retry( $client, $conn->spreadsheet_id, $range )
                : $client->get_rows( $conn->spreadsheet_id, $range );
        } catch ( Exception $e ) {
            $payload = array( 'message' => esc_html( $e->getMessage() ) );
            if ( function_exists( 'sheetsync_api_error_is_retryable' )
                && sheetsync_api_error_is_retryable( $e->getMessage() ) ) {
                $payload['retryable'] = true;
            }
            wp_send_json_error( $payload );
        }

        if ( empty( $data_rows ) ) {
            if ( 0 === $batch_offset ) {
                wp_send_json_error( array( 'message' => __( 'Sheet is empty.', 'sheetsync-for-woocommerce' ) ) );
            }
            wp_send_json_success(
                array(
                    'created'     => 0,
                    'updated'     => 0,
                    'skipped'     => 0,
                    'log'         => array(),
                    'next_offset' => $batch_offset,
                    'done'        => true,
                    'batch_size'  => $batch_size,
                )
            );
            return;
        }

        if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
            if ( $is_first_batch ) {
                SheetSync_Import_Row_Service::begin_import_run( $conn_id );
            } else {
                SheetSync_Import_Row_Service::continue_import_run( $conn_id );
            }
        }

        $updater = new SheetSync_Product_Updater( $maps );
        $map_repo = class_exists( 'SheetSync_Product_Map_Repository', false )
            ? new SheetSync_Product_Map_Repository()
            : null;
        $tagged  = array();
        foreach ( $data_rows as $i => $row ) {
            $tagged[] = array(
                'sheet_row' => $start_row + $i,
                'row'       => $row,
            );
        }
        if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
            $tagged = SheetSync_Import_Row_Service::prepare_tagged_rows_for_import( $tagged, $updater, $start_row );
            SheetSync_Import_Row_Service::warm_sku_lookup_for_tagged_rows( $updater, $tagged );
        } elseif ( class_exists( 'SheetSync_Variation_Sync', false ) ) {
            $tagged = SheetSync_Variation_Sync::sort_rows_parents_first( $data_rows, $updater, $start_row );
        }
        $created = $updated = $skipped = 0;
        $log     = array();

        if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
            SheetSync_Import_Row_Service::begin_batch();
        }

        foreach ( $tagged as $item ) {
            $row           = $item['row'];
            $sheet_row_num = (int) $item['sheet_row'];
            if ( empty( array_filter( $row, fn( $v ) => $v !== '' && $v !== null ) ) ) {
                continue;
            }

            $peek    = $updater->extract_data( $row );
            $sku     = sanitize_text_field( $peek['_sku'] ?? '' );
            $title   = sanitize_text_field( $peek['post_title'] ?? '' );
            $display = $sku !== '' ? $sku : ( $title !== '' ? $title : 'Row' );

            $pid_before = $sku !== '' ? (int) wc_get_product_id_by_sku( $sku ) : 0;
            if ( ! $pid_before && $title !== '' && class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
                $normalized_title = function_exists( 'sheetsync_normalize_sheet_title' )
                    ? sheetsync_normalize_sheet_title( $title )
                    : $title;
                $pid_before = SheetSync_Product_Map_Repository::resolve_product_id_by_title( $normalized_title, $peek, $conn_id );
            }

            if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
                $result = SheetSync_Import_Row_Service::import_sheet_row(
                    $conn,
                    $conn_id,
                    $maps,
                    $row,
                    $sheet_row_num,
                    $updater,
                    $map_repo,
                    null,
                    $create_new,
                    $skip_existing
                );
            } else {
                $result = $updater->update( $row, $sheet_row_num );
            }

            if ( 'created' === $result ) {
                ++$created;
                $log[] = array( 'type' => 'created', 'msg' => "✅ Created: {$display}" );
                continue;
            }

            if ( 'updated' === $result ) {
                if ( ! $pid_before ) {
                    ++$created;
                    $log[] = array( 'type' => 'created', 'msg' => "✅ Created: {$display}" );
                } else {
                    ++$updated;
                    $log[] = array( 'type' => 'updated', 'msg' => "🔄 Updated: {$display}" );
                }
                continue;
            }

            if ( 'queued' === $result ) {
                ++$skipped;
                $reason = class_exists( 'SheetSync_Variation_Sync', false )
                    ? SheetSync_Variation_Sync::consume_last_message()
                    : null;
                $log[] = array(
                    'type' => 'skipped',
                    'msg'  => $reason
                        ? "⏳ {$display}: {$reason}"
                        : "⏳ {$display}: " . __( 'Deferred — will retry when parent/child rows are ready.', 'sheetsync-for-woocommerce' ),
                );
                continue;
            }

            if ( 'skipped' === $result ) {
                ++$skipped;
                if ( $skip_existing && $pid_before ) {
                    $log[] = array( 'type' => 'skipped', 'msg' => "⏭️ Skip: {$display} (already exists)" );
                    continue;
                }
                $reason = class_exists( 'SheetSync_Variation_Sync', false )
                    ? SheetSync_Variation_Sync::consume_last_message()
                    : null;
                if ( $reason ) {
                    $log[] = array( 'type' => 'skipped', 'msg' => "⏭️ {$display}: {$reason}" );
                }
                continue;
            }

            if ( 'error' === $result ) {
                $reason = class_exists( 'SheetSync_Variation_Sync', false )
                    ? SheetSync_Variation_Sync::consume_last_message()
                    : null;
                $log[] = array(
                    'type' => 'skipped',
                    'msg'  => $reason ? "❌ {$display}: {$reason}" : "❌ {$display}",
                );
                ++$skipped;
                continue;
            }

            ++$skipped;
        }

        if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
            SheetSync_Import_Row_Service::end_batch();
        }

        if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
            SheetSync_Import_Row_Service::run_mid_batch_deferred_retries( $conn_id, $maps );
            SheetSync_Import_Row_Service::flush_import_run_state( $conn_id );
        }

        $fetched = count( $data_rows );
        $done    = ( $fetched < $batch_size );
        $next    = $batch_offset + $fetched;

        if ( $done && class_exists( 'SheetSync_Import_Row_Service', false ) ) {
            $retry   = SheetSync_Import_Row_Service::retry_deferred_import_passes( $conn_id, $maps, true, $updater );
            $updated += (int) ( $retry['processed'] ?? 0 );
            $skipped += (int) ( $retry['skipped'] ?? 0 );
            SheetSync_Import_Row_Service::end_import_run( $conn_id );
        }

        wp_send_json_success(
            array(
                'created'           => $created,
                'updated'           => $updated,
                'skipped'           => $skipped,
                'log'               => $log,
                'next_offset'       => $next,
                'done'              => $done,
                'batch_size'        => $batch_size,
                'import_session_id' => $import_session,
            )
        );
    }

    /**
     * Render a Pro upgrade gate block.
     */
    public static function render_pro_gate( string $feature_name ): void {
        if ( function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro() ) {
            return;
        }

        $upgrade_url = function_exists( 'sheetsync_upgrade_url' ) ? sheetsync_upgrade_url() : '#';
        ?>
        <div class="sheetsync-pro-gate" style="margin:12px 0;padding:16px;border:1px dashed #c3c4c7;border-radius:6px;background:#f6f7f7;">
            <p style="margin:0 0 10px;">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %s: feature name */
                        __( '%s is available in SheetSync Pro.', 'sheetsync-for-woocommerce' ),
                        $feature_name
                    )
                );
                ?>
            </p>
            <a href="<?php echo esc_url( $upgrade_url ); ?>" class="button button-primary">
                <?php esc_html_e( 'Upgrade to Pro', 'sheetsync-for-woocommerce' ); ?>
            </a>
        </div>
        <?php
    }

    // ─── Dashboard Settings Persistence ──────────────────────────────────────

    /**
     * AJAX: Save dashboard field values to a WordPress option so they survive page reloads.
     */
    public function ajax_save_dashboard_settings(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        }
        if ( ! sheetsync_is_pro() ) {
            wp_send_json_error( array( 'message' => __( 'Pro required.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $raw      = wp_unslash( $_POST['settings'] ?? array() );
        $settings = is_array( $raw ) ? $raw : array();

        if ( class_exists( 'SheetSync_Dashboard_Enhancements', false ) ) {
            $clean = SheetSync_Dashboard_Enhancements::sanitize_settings( $settings );
            wp_send_json_success( $clean );
            return;
        }

        $clean = array(
            'sd_spreadsheet_id'  => sanitize_text_field( $settings['sd_spreadsheet_id']  ?? '' ),
            'sd_sheet_name'      => sanitize_text_field( $settings['sd_sheet_name']      ?? 'Sales Dashboard' ),
            'sd_period'          => sanitize_text_field( $settings['sd_period']          ?? '7days' ),
            'inv_spreadsheet_id' => sanitize_text_field( $settings['inv_spreadsheet_id'] ?? '' ),
            'inv_sheet_name'     => sanitize_text_field( $settings['inv_sheet_name']     ?? 'Inventory Status' ),
            'inv_low_stock'      => absint( $settings['inv_low_stock'] ?? 5 ),
            'boe_spreadsheet_id' => sanitize_text_field( $settings['boe_spreadsheet_id'] ?? '' ),
            'boe_sheet_name'     => sanitize_text_field( $settings['boe_sheet_name']     ?? 'Orders Export' ),
        );

        update_option( 'sheetsync_dashboard_settings', $clean );
        wp_send_json_success( $clean );
    }

    /**
     * AJAX: Load saved dashboard settings.
     */
    public function ajax_load_dashboard_settings(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        }
        if ( ! sheetsync_is_pro() ) {
            wp_send_json_error( array( 'message' => __( 'Pro required.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        if ( class_exists( 'SheetSync_Dashboard_Enhancements', false ) ) {
            wp_send_json_success( SheetSync_Dashboard_Enhancements::settings_for_client() );
            return;
        }

        $defaults = array(
            'sd_spreadsheet_id'  => '',
            'sd_sheet_name'      => 'Sales Dashboard',
            'sd_period'          => '7days',
            'inv_spreadsheet_id' => '',
            'inv_sheet_name'     => 'Inventory Status',
            'inv_low_stock'      => 5,
            'boe_spreadsheet_id' => '',
            'boe_sheet_name'     => 'Orders Export',
        );

        $saved = get_option( 'sheetsync_dashboard_settings', array() );
        wp_send_json_success( wp_parse_args( $saved, $defaults ) );
    }
}

endif; // class_exists SheetSync_Pro_Admin

if ( ! class_exists( 'SheetSync_Admin', false ) ) {
	class SheetSync_Admin extends SheetSync_Pro_Admin {}
}
