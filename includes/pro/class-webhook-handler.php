<?php
/**
 * PRO: Webhook handler — provides the Apps Script snippet and manages the secret.
 * REST routes live in the free plugin class `SheetSync_REST_API`; Pro registers extra routes separately.
 */

/**
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 * @copyright 2024 MD Najmus Shadat
 */

defined( 'ABSPATH' ) || exit;

class SheetSync_Webhook_Handler {

    public function __construct() {
        add_action( 'admin_post_sheetsync_regenerate_secret', array( $this, 'regenerate_secret' ) );
    }

    /**
     * Regenerate the webhook secret.
     * Webhook routes are registered by the free plugin (`SheetSync_REST_API`); Pro extends behaviour via hooks.
     */
    public function regenerate_secret(): void {
        check_admin_referer( 'sheetsync_regenerate_secret' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Forbidden', 'sheetsync-for-woocommerce' ), '', array( 'response' => 403 ) );
        }

        update_option( 'sheetsync_webhook_secret', wp_generate_password( 32, false ) );
        wp_safe_redirect( admin_url( 'admin.php?page=sheetsync-settings#webhook' ) );
        exit;
    }

    /**
     * Generate Google Apps Script for a connection (multi-tab when several connections share one spreadsheet).
     *
     * @param int $connection_id Connection ID (used to resolve spreadsheet and enabled tabs).
     */
    public static function get_apps_script( int $connection_id ): string {
        $bindings = self::get_webhook_bindings_for_connection( $connection_id );
        if ( empty( $bindings ) ) {
            return self::build_legacy_single_connection_script( $connection_id );
        }

        return self::build_multi_connection_script( $bindings );
    }

    /**
     * Real-time setup status for admin UI.
     *
     * @return array{enabled: bool, verified: bool, label: string, verified_at: int, poll_next: string, is_orders: bool}
     */
    public static function get_realtime_status( int $connection_id ): array {
        $auto_sync = get_option( 'sheetsync_auto_sync_settings', array() );
        $enabled   = is_array( $auto_sync ) && ! empty( $auto_sync[ $connection_id ] );
        $verified  = (int) get_option( 'sheetsync_webhook_verified_' . $connection_id, 0 );
        $label     = __( 'Inactive', 'sheetsync-for-woocommerce' );
        $poll_next = '';

        $conn      = SheetSync_Sync_Engine::get_connection( $connection_id );
        $is_orders = $conn && SheetSync_Sync_Engine::is_orders_type( (string) $conn->connection_type );

        if ( $enabled && $is_orders && class_exists( 'SheetSync_Order_Sheet_Poller', false ) ) {
            $poll_next = SheetSync_Order_Sheet_Poller::get_next_run_label();
            if ( $verified > 0 ) {
                $label = __( 'Active — Smart Poll + instant webhook', 'sheetsync-for-woocommerce' );
            } else {
                $interval_label = SheetSync_Order_Sheet_Poller::get_interval_label();
                $label          = $poll_next
                    ? sprintf(
                        /* translators: 1: poll frequency e.g. every 1 minute, 2: next run datetime */
                        __( 'Active — Smart Poll (%1$s, next: %2$s)', 'sheetsync-for-woocommerce' ),
                        $interval_label,
                        $poll_next
                    )
                    : sprintf(
                        /* translators: %s: poll frequency e.g. every 1 minute */
                        __( 'Active — Smart Poll (%s)', 'sheetsync-for-woocommerce' ),
                        $interval_label
                    );
            }
        } elseif ( $enabled && ! $is_orders && class_exists( 'SheetSync_Product_Sheet_Poller', false ) ) {
            $poll_next = SheetSync_Product_Sheet_Poller::get_next_run_label();
            if ( $verified > 0 ) {
                $label = __( 'Active — Smart Poll + instant webhook', 'sheetsync-for-woocommerce' );
            } else {
                $interval_label = SheetSync_Product_Sheet_Poller::get_interval_label();
                $label          = $poll_next
                    ? sprintf(
                        /* translators: 1: poll frequency e.g. every 3 minutes, 2: next run datetime */
                        __( 'Active — Smart Poll (%1$s, next: %2$s)', 'sheetsync-for-woocommerce' ),
                        $interval_label,
                        $poll_next
                    )
                    : sprintf(
                        /* translators: %s: poll frequency e.g. every 3 minutes */
                        __( 'Active — Smart Poll (%s)', 'sheetsync-for-woocommerce' ),
                        $interval_label
                    );
            }
        } elseif ( $enabled && $verified > 0 ) {
            $label = __( 'Active — instant webhook', 'sheetsync-for-woocommerce' );
        } elseif ( $enabled ) {
            $label = __( 'Enabled — install Apps Script for instant sync', 'sheetsync-for-woocommerce' );
        }

        return array(
            'enabled'     => $enabled,
            'verified'    => $verified > 0,
            'label'       => $label,
            'verified_at' => $verified,
            'poll_next'   => $poll_next,
            'is_orders'   => $is_orders,
        );
    }

    /**
     * POST a test payload to the local webhook REST route (admin connectivity check).
     *
     * @return array{success: bool, message: string}
     */
    public static function test_webhook_from_admin( int $connection_id ): array {
        if ( $connection_id < 1 ) {
            return array(
                'success' => false,
                'message' => __( 'Invalid connection ID.', 'sheetsync-for-woocommerce' ),
            );
        }

        $secret = function_exists( 'sheetsync_resolve_webhook_secret' )
            ? sheetsync_resolve_webhook_secret( $connection_id )
            : (string) get_option( 'sheetsync_webhook_secret', '' );
        if ( $secret === '' ) {
            return array(
                'success' => false,
                'message' => __( 'Webhook secret is not configured. Open SheetSync → Settings.', 'sheetsync-for-woocommerce' ),
            );
        }

        $url  = rest_url( 'sheetsync/v1/webhook' );
        $body = wp_json_encode(
            array(
                'test'          => true,
                'connection_id' => $connection_id,
            )
        );

        $response = wp_remote_post(
            $url,
            array(
                'timeout' => 20,
                'headers' => array(
                    'Content-Type'       => 'application/json',
                    'X-SheetSync-Secret' => $secret,
                ),
                'body'    => $body,
            )
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'message' => $response->get_error_message(),
            );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 200 && $code < 300 && is_array( $data ) && ( $data['result'] ?? '' ) === 'test_ok' ) {
            update_option( 'sheetsync_webhook_verified_' . $connection_id, time(), false );
            if ( function_exists( 'sheetsync_update_setup_progress' ) ) {
                sheetsync_update_setup_progress( 'realtime_enabled', true );
            }
            return array(
                'success' => true,
                'message' => (string) ( $data['message'] ?? __( 'Webhook endpoint is reachable and secret is valid.', 'sheetsync-for-woocommerce' ) ),
            );
        }

        $err = is_array( $data ) ? (string) ( $data['error'] ?? '' ) : '';
        return array(
            'success' => false,
            'message' => $err !== '' ? $err : sprintf(
                /* translators: %d: HTTP status code */
                __( 'Webhook test failed (HTTP %d).', 'sheetsync-for-woocommerce' ),
                $code
            ),
        );
    }

    /**
     * All webhook-enabled connections on the same spreadsheet as $connection_id.
     *
     * @return array<int, array{id:int,sheet_name:string,data_start_row:int,is_orders:bool}>
     */
    public static function get_webhook_bindings_for_connection( int $connection_id ): array {
        global $wpdb;

        $conn = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT id, spreadsheet_id, sheet_name, header_row, connection_type, sync_direction
             FROM {$wpdb->prefix}sheetsync_connections WHERE id = %d",
            $connection_id
        ) );

        if ( ! $conn || empty( $conn->spreadsheet_id ) ) {
            return array();
        }

        $auto_sync = get_option( 'sheetsync_auto_sync_settings', array() );
        if ( ! is_array( $auto_sync ) ) {
            $auto_sync = array();
        }

        $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT id, sheet_name, header_row, connection_type, sync_direction
             FROM {$wpdb->prefix}sheetsync_connections
             WHERE status = 'active' AND spreadsheet_id = %s",
            $conn->spreadsheet_id
        ) );

        $bindings = array();
        foreach ( $rows as $row ) {
            if ( empty( $auto_sync[ (int) $row->id ] ) ) {
                continue;
            }
            if ( ! in_array( $row->sync_direction, array( 'sheets_to_wc', 'two_way' ), true ) ) {
                continue;
            }

            $is_orders = SheetSync_Sync_Engine::is_orders_type( (string) $row->connection_type );
            $bindings[] = array(
                'id'             => (int) $row->id,
                'sheet_name'     => (string) $row->sheet_name,
                'data_start_row' => $is_orders
                    ? SheetSync_Order_Sync::ORDER_DATA_START_ROW
                    : max( 1, (int) $row->header_row ) + 1,
                'is_orders'      => $is_orders,
            );
        }

        return $bindings;
    }

    /**
     * @param array<int, array{id:int,sheet_name:string,data_start_row:int,is_orders:bool}> $bindings
     */
    private static function build_multi_connection_script( array $bindings ): string {
        $endpoint  = rest_url( 'sheetsync/v1/webhook' );
        $first_id  = ! empty( $bindings ) ? (int) ( $bindings[0]['id'] ?? 0 ) : 0;
        $secret    = function_exists( 'sheetsync_resolve_webhook_secret' )
            ? sheetsync_resolve_webhook_secret( $first_id )
            : (string) get_option( 'sheetsync_webhook_secret', '' );

        $js_bindings = array();
        foreach ( $bindings as $b ) {
            $binding_secret = function_exists( 'sheetsync_resolve_webhook_secret' )
                ? sheetsync_resolve_webhook_secret( (int) $b['id'] )
                : $secret;
            $js_bindings[] = sprintf(
                '  { id: %d, sheetName: %s, dataStartRow: %d, isOrders: %s, statusCol: %d, secret: %s }',
                (int) $b['id'],
                wp_json_encode( $b['sheet_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ),
                (int) $b['data_start_row'],
                ! empty( $b['is_orders'] ) ? 'true' : 'false',
                ! empty( $b['is_orders'] ) ? 3 : 0,
                wp_json_encode( $binding_secret, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT )
            );
        }
        $connections_js = "[\n" . implode( ",\n", $js_bindings ) . "\n]";

        $tab_list = implode(
            ', ',
            array_map(
                static fn( $b ) => '"' . $b['sheet_name'] . '"',
                $bindings
            )
        );

        return
            '// ==============================================' . "\n" .
            '// SheetSync Auto Sync — Google Apps Script' . "\n" .
            '// Supports ALL enabled connections in this spreadsheet.' . "\n" .
            '// ==============================================' . "\n" .
            '// HOW TO INSTALL:' . "\n" .
            '//  1. Google Sheets → Extensions → Apps Script' . "\n" .
            '//  2. Delete any existing code, paste ALL of this code' . "\n" .
            '//  3. Save → Run → setupTrigger (grant permission)' . "\n" .
            '//  4. Run verifyTriggers() — must show 1 onSheetEdit trigger' . "\n" .
            '//  5. Re-run setupTrigger whenever you enable a new tab in SheetSync.' . "\n" .
            '//  6. Orders: edit column C (Status) only — row moves automatically.' . "\n" .
            '// ==============================================' . "\n\n" .
            'const SHEETSYNC_ENDPOINT    = ' . wp_json_encode( $endpoint ) . ";\n" .
            'const SHEETSYNC_SECRET      = ' . wp_json_encode( $secret ) . ";\n" .
            'const SHEETSYNC_CONNECTIONS = ' . $connections_js . ";\n\n" .
            'function setupTrigger() {' . "\n" .
            '  ScriptApp.getProjectTriggers().forEach(function(t) {' . "\n" .
            '    if (t.getHandlerFunction() === \'onSheetEdit\') ScriptApp.deleteTrigger(t);' . "\n" .
            '  });' . "\n" .
            '  ScriptApp.newTrigger(\'onSheetEdit\')' . "\n" .
            '    .forSpreadsheet(SpreadsheetApp.getActive())' . "\n" .
            '    .onEdit()' . "\n" .
            '    .create();' . "\n" .
            '  var count = ScriptApp.getProjectTriggers().filter(function(t) {' . "\n" .
            '    return t.getHandlerFunction() === \'onSheetEdit\';' . "\n" .
            '  }).length;' . "\n" .
            '  SpreadsheetApp.getUi().alert(' . "\n" .
            '    count > 0' . "\n" .
            '      ? \'✅ SheetSync trigger installed (\' + count + \'). Tabs: ' . esc_js( $tab_list ) . '. Edit Status column C to sync.\'' . "\n" .
            '      : \'❌ Trigger failed. Run setupTrigger again and grant all permissions.\'' . "\n" .
            '  );' . "\n" .
            '}' . "\n\n" .
            'function verifyTriggers() {' . "\n" .
            '  var triggers = ScriptApp.getProjectTriggers().filter(function(t) {' . "\n" .
            '    return t.getHandlerFunction() === \'onSheetEdit\';' . "\n" .
            '  });' . "\n" .
            '  if (triggers.length === 0) {' . "\n" .
            '    SpreadsheetApp.getUi().alert(\'❌ No onSheetEdit trigger. Run setupTrigger first.\');' . "\n" .
            '    return;' . "\n" .
            '  }' . "\n" .
            '  var tabs = SHEETSYNC_CONNECTIONS.map(function(c) { return c.sheetName; }).join(\', \');' . "\n" .
            '  SpreadsheetApp.getUi().alert(\'✅ \' + triggers.length + \' trigger(s) active.\\n\\nWatching tabs: \' + tabs + \'\\n\\nEdit Status (column C) on row 3+ to sync.\');' . "\n" .
            '}' . "\n\n" .
            'function findBinding(tabName) {' . "\n" .
            '  var key = String(tabName || \'\').trim().toLowerCase();' . "\n" .
            '  return SHEETSYNC_CONNECTIONS.find(function(c) {' . "\n" .
            '    return String(c.sheetName || \'\').trim().toLowerCase() === key;' . "\n" .
            '  });' . "\n" .
            '}' . "\n\n" .
            'function postOrderRow(binding, editRow, rowData, statusRaw) {' . "\n" .
            '  rowData[2] = statusRaw;' . "\n" .
            '  rowData = rowData.map(function(cell) {' . "\n" .
            '    if (cell instanceof Date) return Utilities.formatDate(cell, Session.getScriptTimeZone(), "yyyy-MM-dd HH:mm:ss");' . "\n" .
            '    return cell;' . "\n" .
            '  });' . "\n" .
            '  var status = String(statusRaw || \'\').trim().toLowerCase().replace(/^wc-/, \'\');' . "\n" .
            '  var payload = JSON.stringify({' . "\n" .
            '    connection_id: binding.id,' . "\n" .
            '    sheet_row: editRow,' . "\n" .
            '    row: rowData,' . "\n" .
            '    status: status,' . "\n" .
            '    source: \'onEdit\'' . "\n" .
            '  });' . "\n" .
            '  var options = {' . "\n" .
            '    method: \'post\',' . "\n" .
            '    contentType: \'application/json\',' . "\n" .
            '    headers: { \'X-SheetSync-Secret\': (binding.secret || SHEETSYNC_SECRET) },' . "\n" .
            '    payload: payload,' . "\n" .
            '    muteHttpExceptions: true' . "\n" .
            '  };' . "\n" .
            '  var lastErr = null;' . "\n" .
            '  for (var attempt = 1; attempt <= 3; attempt++) {' . "\n" .
            '    try {' . "\n" .
            '      var response = UrlFetchApp.fetch(SHEETSYNC_ENDPOINT, options);' . "\n" .
            '      var code = response.getResponseCode();' . "\n" .
            '      var text = response.getContentText() || \'{}\';' . "\n" .
            '      var body = {};' . "\n" .
            '      try { body = JSON.parse(text); } catch (ignore) {}' . "\n" .
            '      return { code: code, body: body, text: text, attempt: attempt };' . "\n" .
            '    } catch (err) {' . "\n" .
            '      lastErr = err;' . "\n" .
            '      if (attempt < 3) Utilities.sleep(1000 * attempt);' . "\n" .
            '    }' . "\n" .
            '  }' . "\n" .
            '  throw lastErr || new Error(\'Webhook request failed after 3 attempts.\');' . "\n" .
            '}' . "\n\n" .
            'function showSyncResult(tabName, orderId, status, result) {' . "\n" .
            '  var ss = SpreadsheetApp.getActiveSpreadsheet();' . "\n" .
            '  var body = result.body || {};' . "\n" .
            '  if (result.code >= 200 && result.code < 300 && (body.result === \'updated\' || body.result === \'reconciled\')) {' . "\n" .
            '    ss.toast(\'Order \' + orderId + \' → \' + status + \' (\' + body.result + \')\', \'SheetSync ✅\', 8);' . "\n" .
            '    return;' . "\n" .
            '  }' . "\n" .
            '  var msg = body.error || body.reason || body.message || result.text || (\'HTTP \' + result.code);' . "\n" .
            '  ss.toast(String(msg).substring(0, 120), \'SheetSync ❌\', 10);' . "\n" .
            '  console.warn(\'SheetSync [\' + tabName + \']:\', result);' . "\n" .
            '}' . "\n\n" .
            '/** Manual test: click a data row, then Run → testOrderRowSync */' . "\n" .
            'function testOrderRowSync() {' . "\n" .
            '  var sheet = SpreadsheetApp.getActiveSheet();' . "\n" .
            '  var row = sheet.getActiveCell().getRow();' . "\n" .
            '  var binding = findBinding(sheet.getName());' . "\n" .
            '  if (!binding) {' . "\n" .
            '    SpreadsheetApp.getUi().alert(\'❌ Tab "\' + sheet.getName() + \'" not in SheetSync.\\nConfigured: \' + SHEETSYNC_CONNECTIONS.map(function(c){return c.sheetName;}).join(\', \'));' . "\n" .
            '    return;' . "\n" .
            '  }' . "\n" .
            '  if (!binding.isOrders) {' . "\n" .
            '    SpreadsheetApp.getUi().alert(\'This tab is not an orders connection.\');' . "\n" .
            '    return;' . "\n" .
            '  }' . "\n" .
            '  if (row < binding.dataStartRow) {' . "\n" .
            '    SpreadsheetApp.getUi().alert(\'Select a data row (row \' + binding.dataStartRow + \'+), then run again.\');' . "\n" .
            '    return;' . "\n" .
            '  }' . "\n" .
            '  var rowData = sheet.getRange("A" + row + ":L" + row).getValues()[0];' . "\n" .
            '  var statusRaw = sheet.getRange(row, binding.statusCol).getValue();' . "\n" .
            '  var result = postOrderRow(binding, row, rowData, statusRaw);' . "\n" .
            '  showSyncResult(sheet.getName(), rowData[0], statusRaw, result);' . "\n" .
            '  SpreadsheetApp.getUi().alert(\'HTTP \' + result.code + \': \' + (result.text || \'\').substring(0, 300));' . "\n" .
            '}' . "\n\n" .
            'function onSheetEdit(e) {' . "\n" .
            '  if (!e || !e.range) return;' . "\n" .
            '  var sheet   = e.range.getSheet();' . "\n" .
            '  var editRow = e.range.getRow();' . "\n" .
            '  var editCol = e.range.getColumn();' . "\n" .
            '  var tabName = sheet.getName();' . "\n" .
            '  var binding = findBinding(tabName);' . "\n" .
            '  if (!binding) return;' . "\n" .
            '  if (editRow < binding.dataStartRow) return;' . "\n" .
            '  if (binding.isOrders && editCol !== binding.statusCol) return;' . "\n" .
            '  var rowData = sheet.getRange("A" + editRow + ":L" + editRow).getValues()[0];' . "\n" .
            '  if (String(rowData[0] || \'\').toUpperCase() === \'TOTAL\') return;' . "\n" .
            '  var isEmpty = rowData.every(function(cell) { return cell === \'\' || cell === null || cell === undefined; });' . "\n" .
            '  if (isEmpty) return;' . "\n" .
            '  var statusRaw = binding.isOrders && editCol === binding.statusCol' . "\n" .
            '    ? (e.value !== undefined && e.value !== null ? e.value : e.range.getValue())' . "\n" .
            '    : rowData[2];' . "\n" .
            '  try {' . "\n" .
            '    var result = postOrderRow(binding, editRow, rowData, statusRaw);' . "\n" .
            '    showSyncResult(tabName, rowData[0], statusRaw, result);' . "\n" .
            '  } catch (err) {' . "\n" .
            '    SpreadsheetApp.getActiveSpreadsheet().toast(err.message, \'SheetSync ❌\', 10);' . "\n" .
            '    console.error(\'SheetSync error:\', err.message);' . "\n" .
            '  }' . "\n" .
            '}' . "\n\n" .
            '/** Run once from Apps Script to verify webhook URL + secret (no sheet edit needed). */' . "\n" .
            'function testWebhook() {' . "\n" .
            '  var conn = SHEETSYNC_CONNECTIONS[0];' . "\n" .
            '  if (!conn) { SpreadsheetApp.getUi().alert(\'No connections in script.\'); return; }' . "\n" .
            '  var options = {' . "\n" .
            '    method: \'post\',' . "\n" .
            '    contentType: \'application/json\',' . "\n" .
            '    headers: { \'X-SheetSync-Secret\': (conn.secret || SHEETSYNC_SECRET) },' . "\n" .
            '    muteHttpExceptions: true' . "\n" .
            '  };' . "\n" .
            '  var res = UrlFetchApp.fetch(SHEETSYNC_ENDPOINT, options);' . "\n" .
            '  SpreadsheetApp.getUi().alert(\'SheetSync test: \' + res.getContentText());' . "\n" .
            '}' . "\n";
    }

    /**
     * Fallback when no auto-sync bindings exist yet (single connection snippet).
     */
    private static function build_legacy_single_connection_script( int $connection_id ): string {
        global $wpdb;

        $endpoint = rest_url( 'sheetsync/v1/webhook' );
        $secret   = function_exists( 'sheetsync_resolve_webhook_secret' )
            ? sheetsync_resolve_webhook_secret( $connection_id )
            : (string) get_option( 'sheetsync_webhook_secret', '' );

        $conn = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT header_row, sheet_name, connection_type FROM {$wpdb->prefix}sheetsync_connections WHERE id = %d",
            $connection_id
        ) );

        $sheet_name     = $conn ? (string) $conn->sheet_name : '';
        $is_orders      = $conn && SheetSync_Sync_Engine::is_orders_type( (string) $conn->connection_type );
        $data_start_row = $is_orders
            ? SheetSync_Order_Sync::ORDER_DATA_START_ROW
            : ( $conn ? max( 1, (int) $conn->header_row ) + 1 : 2 );

        return self::build_multi_connection_script(
            array(
                array(
                    'id'             => $connection_id,
                    'sheet_name'     => $sheet_name,
                    'data_start_row' => $data_start_row,
                    'is_orders'      => $is_orders,
                ),
            )
        );
    }
}
