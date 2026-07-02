<?php
/**
 * PRO: Order sync — writes new orders and status changes to a Google Sheet.
 */

/**
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 * @copyright 2024 MD Najmus Shadat
 */

defined( 'ABSPATH' ) || exit;

class SheetSync_Order_Sync {

    /** First row containing order data (row 1 = headers, row 2 = filter info). */
    public const ORDER_DATA_START_ROW = 3;

    /**
     * Valid WooCommerce order status slugs for sheet column C.
     *
     * @return list<string>
     */
    public static function allowed_status_slugs(): array {
        return array(
            'pending',
            'processing',
            'on-hold',
            'completed',
            'cancelled',
            'refunded',
            'failed',
            'draft',
        );
    }

    public function __construct() {
        // BUG FIX: Use HPOS (Custom Order Tables) compatible hooks.
        // 'woocommerce_new_order' does not always fire in HPOS mode.
        // 'woocommerce_checkout_order_created' works in all modes.
        add_action( 'woocommerce_checkout_order_created',  array( $this, 'on_new_order_object' ), 10, 1 );
        add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_new_order_object' ), 10, 1 );
        // Legacy fallback (non-HPOS)
        add_action( 'woocommerce_new_order',            array( $this, 'on_new_order' ),     10, 1 );
        add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_change' ), 10, 3 );
        add_action( 'woocommerce_order_refunded',       array( $this, 'on_order_refunded' ), 10, 2 );
        add_action( 'woocommerce_refund_deleted',       array( $this, 'on_refund_deleted' ), 10, 2 );
        add_action( 'woocommerce_before_trash_order',   array( $this, 'on_order_trashed' ), 10, 2 );
        add_action( 'woocommerce_before_delete_order',  array( $this, 'on_order_deleted' ), 10, 2 );
    }

    /**
     * HPOS-compatible: fires with WC_Order object directly.
     * Duplicate guard to prevent the same order from syncing twice.
     */
    public function on_new_order_object( WC_Order $order ): void {
        // Prevent duplicate from legacy hook.
        if ( get_transient( 'sheetsync_order_synced_' . $order->get_id() ) ) return;
        set_transient( 'sheetsync_order_synced_' . $order->get_id(), 'hpos', 60 );

        $this->process_new_order( $order );
    }

    public function on_new_order( int $order_id ): void {
        // Duplicate guard: skip if HPOS hook already fired (transient value = 'hpos').
        $existing = get_transient( 'sheetsync_order_synced_' . $order_id );
        if ( $existing === 'hpos' ) return;
        if ( $existing ) return; // already synced by legacy hook too
        set_transient( 'sheetsync_order_synced_' . $order_id, 'legacy', 60 );

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $this->process_new_order( $order );
    }

    /**
     * Shared logic for both new-order hooks.
     */
    private function process_new_order( WC_Order $order ): void {

        $connections = SheetSync_Sync_Engine::get_active_connections( 'orders' );
        foreach ( $connections as $conn ) {
            if ( ! in_array( $conn->sync_direction, array( 'wc_to_sheets', 'two_way' ), true ) ) continue;

            // Status filter: if connection targets a specific status, only sync matching orders.
            $status_filter = SheetSync_Sync_Engine::get_order_status_filter( $conn->connection_type );
            if ( $status_filter !== null && $order->get_status() !== $status_filter ) continue;

            try {
                $this->append_order( $order, $conn );
                $this->update_total_row( $conn );
            } catch ( Exception $e ) {
                SheetSync_Logger::error( 'Order sync failed: ' . $e->getMessage(), $conn->id );
            }
        }
    }

    /**
     * Normalize WooCommerce status slugs (strip wc- prefix).
     */
    private function normalize_status( string $status ): string {
        return str_replace( 'wc-', '', strtolower( trim( $status ) ) );
    }

    public function on_status_change( int $order_id, string $old_status, string $new_status ): void {
        $old_status = $this->normalize_status( $old_status );
        $new_status = $this->normalize_status( $new_status );

        if ( $old_status === $new_status ) {
            return;
        }

        $guard = get_transient( 'sheetsync_status_changing_' . $order_id );

        /*
         * Transient values:
         * - 'sheet' : status was changed from Google Sheets (webhook / manual pull).
         *             Caller runs cross-sheet moves; skip here to avoid duplicate work.
         * - 'wc'    : status change already being synced from WooCommerce → Sheets.
         *             Skip to avoid duplicate API writes / loops.
         */
        if ( 'wc' === $guard ) {
            return;
        }

        if ( 'sheet' === $guard ) {
            return;
        }

        if ( ! $guard ) {
            set_transient( 'sheetsync_status_changing_' . $order_id, 'wc', 30 );
        }

        $this->sync_order_rows_for_status( $order_id, $new_status, null );

        delete_transient( 'sheetsync_status_changing_' . $order_id );
    }

    /**
     * Sync net order total to sheet column H after a refund is created.
     *
     * @param int $order_id  Parent order ID.
     * @param int $refund_id Refund ID (unused; required by hook signature).
     */
    public function on_order_refunded( int $order_id, int $refund_id ): void {
        unset( $refund_id );
        $this->sync_order_total_to_sheets( $order_id, 'refund' );
    }

    /**
     * Re-sync net total when an admin deletes a refund from an order.
     *
     * @param int $refund_id Deleted refund ID.
     * @param int $order_id  Parent order ID.
     */
    public function on_refund_deleted( int $refund_id, int $order_id ): void {
        unset( $refund_id );
        $this->sync_order_total_to_sheets( $order_id, 'refund_deleted' );
    }

    /**
     * Remove a trashed order from every WC→Sheets order connection.
     *
     * @param int           $order_id Order ID.
     * @param WC_Order|null $order    Order object when available (HPOS-compatible hooks).
     */
    public function on_order_trashed( int $order_id, $order = null ): void {
        $this->remove_order_from_all_sheets( $order_id, $order instanceof WC_Order ? $order : null, 'trashed' );
    }

    /**
     * Remove a permanently deleted order from every WC→Sheets order connection.
     *
     * @param int           $order_id Order ID.
     * @param WC_Order|null $order    Order object when available (HPOS-compatible hooks).
     */
    public function on_order_deleted( int $order_id, $order = null ): void {
        $this->remove_order_from_all_sheets( $order_id, $order instanceof WC_Order ? $order : null, 'deleted' );
    }

    /**
     * Delete sheet rows for an order across all active order connections.
     */
    private function remove_order_from_all_sheets( int $order_id, ?WC_Order $order, string $context ): void {
        if ( ! $order ) {
            $order = wc_get_order( $order_id );
        }

        $connections = SheetSync_Sync_Engine::get_active_connections( 'orders' );
        if ( empty( $connections ) ) {
            return;
        }

        $client = new SheetSync_Sheets_Client();

        foreach ( $connections as $conn ) {
            if ( ! in_array( $conn->sync_direction, array( 'wc_to_sheets', 'two_way' ), true ) ) {
                continue;
            }

            $row_num = $order
                ? $this->resolve_order_sheet_row( $order, $conn, $client )
                : $this->find_order_row_in_sheet( $conn, $order_id, $client );

            if ( ! $row_num ) {
                continue;
            }

            try {
                if ( $order ) {
                    $this->delete_order_row_from_connection( $order, $conn, $client, $row_num );
                } else {
                    $this->delete_order_row_by_id( $conn, $client, $row_num );
                }
                SheetSync_Logger::log(
                    $conn->id,
                    'order_delete',
                    'success',
                    1,
                    0,
                    sprintf(
                        /* translators: 1: order ID, 2: context slug e.g. trashed or deleted */
                        __( 'Order #%1$d removed from sheet (order %2$s).', 'sheetsync-for-woocommerce' ),
                        $order_id,
                        $context
                    )
                );
            } catch ( Exception $e ) {
                SheetSync_Logger::error(
                    sprintf( 'Order %s sheet removal failed: %s', $context, $e->getMessage() ),
                    $conn->id
                );
            }
        }
    }

    /**
     * Delete a sheet row when the WooCommerce order object is no longer available.
     */
    private function delete_order_row_by_id( object $conn, SheetSync_Sheets_Client $client, int $row_num ): void {
        $client->ensure_sheet_exists( $conn->spreadsheet_id, $conn->sheet_name );
        $client->delete_row( $conn->spreadsheet_id, $conn->sheet_name, $row_num );
        $this->rebuild_row_meta( $conn, $client, $row_num );
        $this->update_total_row( $conn, self::ORDER_DATA_START_ROW );
    }

    /**
     * Net order total for sheet column H (gross minus refunds).
     */
    private function format_order_net_total( WC_Order $order ): string {
        $net = max( 0.0, (float) $order->get_total() - (float) $order->get_total_refunded() );
        if ( function_exists( 'wc_format_decimal' ) ) {
            return wc_format_decimal( $net, wc_get_price_decimals() );
        }
        return (string) $net;
    }

    /**
     * Update column H and TOTAL row for every WC→Sheets order connection that has this order.
     */
    private function sync_order_total_to_sheets( int $order_id, string $context ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $connections = SheetSync_Sync_Engine::get_active_connections( 'orders' );
        if ( empty( $connections ) ) {
            return;
        }

        $client    = new SheetSync_Sheets_Client();
        $net_total = $this->format_order_net_total( $order );

        foreach ( $connections as $conn ) {
            if ( ! in_array( $conn->sync_direction, array( 'wc_to_sheets', 'two_way' ), true ) ) {
                continue;
            }

            $row_num = $this->resolve_order_sheet_row( $order, $conn, $client );
            if ( ! $row_num ) {
                continue;
            }

            try {
                $client->ensure_sheet_exists( $conn->spreadsheet_id, $conn->sheet_name );
                $client->update_cell( $conn->spreadsheet_id, $conn->sheet_name, $row_num, 'H', $net_total );
                $this->update_total_row( $conn );
                SheetSync_Logger::log(
                    $conn->id,
                    'order_refund',
                    'success',
                    1,
                    0,
                    sprintf(
                        /* translators: 1: order ID, 2: net total, 3: context slug */
                        __( 'Order #%1$d total updated to %2$s (%3$s).', 'sheetsync-for-woocommerce' ),
                        $order_id,
                        $net_total,
                        $context
                    )
                );
            } catch ( Exception $e ) {
                SheetSync_Logger::error( 'Order refund total sync failed: ' . $e->getMessage(), $conn->id );
            }
        }
    }

    /**
     * Move order rows between status sheets after a status change (WooCommerce or Google Sheet).
     *
     * @param int      $order_id             WooCommerce order ID.
     * @param string   $new_status           Target status slug (e.g. failed, completed).
     * @param int|null $source_connection_id Connection for the sheet tab where the user edited (webhook).
     */
    public function sync_order_rows_for_status( int $order_id, string $new_status, ?int $source_connection_id = null ): void {
        $new_status = $this->normalize_status( $new_status );

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $all_connections = SheetSync_Sync_Engine::get_active_connections( 'orders' );
        if ( empty( $all_connections ) ) {
            return;
        }

        $client = new SheetSync_Sheets_Client();

        foreach ( $all_connections as $conn ) {
            if ( ! in_array( $conn->sync_direction, array( 'wc_to_sheets', 'two_way', 'sheets_to_wc' ), true ) ) {
                continue;
            }

            $status_filter = SheetSync_Sync_Engine::get_order_status_filter( $conn->connection_type );

            // ── "Orders (All)" connection: update status cell only ──
            if ( $status_filter === null ) {
                $row_num = $this->resolve_order_sheet_row( $order, $conn, $client );
                if ( ! $row_num ) {
                    continue;
                }

                try {
                    $client->ensure_sheet_exists( $conn->spreadsheet_id, $conn->sheet_name );
                    $client->update_cell( $conn->spreadsheet_id, $conn->sheet_name, $row_num, 'C', $new_status );
                    SheetSync_Logger::log( $conn->id, 'order_status', 'success', 1, 0, "Order #{$order_id} status → {$new_status}" );
                } catch ( Exception $e ) {
                    SheetSync_Logger::error( 'Order status update failed: ' . $e->getMessage(), $conn->id );
                }
                continue;
            }

            $row_on_tab = $this->resolve_order_sheet_row( $order, $conn, $client );

            if ( $new_status === $status_filter ) {
                if ( ! $row_on_tab ) {
                    try {
                        $this->append_order( $order, $conn );
                        $this->update_total_row( $conn, self::ORDER_DATA_START_ROW );
                        SheetSync_Logger::log( $conn->id, 'order_status', 'success', 1, 0,
                            "Order #{$order_id} added to {$conn->connection_type} sheet." );
                    } catch ( Exception $e ) {
                        SheetSync_Logger::error( 'Order status move-in failed: ' . $e->getMessage(), $conn->id );
                    }
                } else {
                    try {
                        $client->ensure_sheet_exists( $conn->spreadsheet_id, $conn->sheet_name );
                        $client->update_cell( $conn->spreadsheet_id, $conn->sheet_name, $row_on_tab, 'C', $new_status );
                        $order->update_meta_data( '_sheetsync_row_num_' . $conn->id, $row_on_tab );
                        $order->save_meta_data();
                    } catch ( Exception $e ) {
                        SheetSync_Logger::error( 'Order status cell update failed: ' . $e->getMessage(), $conn->id );
                    }
                }
                continue;
            }

            if ( $row_on_tab ) {
                try {
                    $this->delete_order_row_from_connection( $order, $conn, $client, $row_on_tab );
                    SheetSync_Logger::log( $conn->id, 'order_status', 'success', 1, 0,
                        "Order #{$order_id} removed from {$conn->connection_type} sheet (now {$new_status})." );
                } catch ( Exception $e ) {
                    SheetSync_Logger::error( 'Order row delete failed: ' . $e->getMessage(), $conn->id );
                }
            }
        }
    }

    /**
     * Read status edits from every sheet→WC / two-way order connection.
     *
     * @return array{processed:int,skipped:int,errors:int}
     */
    public function pull_all_order_statuses_from_sheets(): array {
        $totals = array(
            'processed' => 0,
            'skipped'   => 0,
            'errors'    => 0,
        );

        foreach ( SheetSync_Sync_Engine::get_active_connections( 'orders' ) as $conn ) {
            if ( ! in_array( $conn->sync_direction, array( 'sheets_to_wc', 'two_way' ), true ) ) {
                continue;
            }
            $result = $this->sync_order_statuses_from_sheet( $conn );
            $totals['processed'] += (int) ( $result['processed'] ?? 0 );
            $totals['skipped']   += (int) ( $result['skipped'] ?? 0 );
            $totals['errors']    += (int) ( $result['errors'] ?? 0 );
        }

        return $totals;
    }

    /**
     * Pull status edits only from connections with real-time (auto-sync) enabled.
     *
     * @return array{processed:int,skipped:int,errors:int}
     */
    public function pull_realtime_order_statuses_from_sheets(): array {
        $totals = array(
            'processed' => 0,
            'skipped'   => 0,
            'errors'    => 0,
        );

        if ( ! class_exists( 'SheetSync_Order_Sheet_Poller', false ) ) {
            return $this->pull_all_order_statuses_from_sheets();
        }

        foreach ( SheetSync_Order_Sheet_Poller::order_realtime_connections() as $conn ) {
            $result = $this->sync_order_statuses_from_sheet( $conn );
            $totals['processed'] += (int) ( $result['processed'] ?? 0 );
            $totals['skipped']   += (int) ( $result['skipped'] ?? 0 );
            $totals['errors']    += (int) ( $result['errors'] ?? 0 );
        }

        return $totals;
    }

    /**
     * Find a 1-based row number for an order ID in column A (skips header / filter / TOTAL rows).
     */
    private function find_order_row_in_sheet( object $conn, int $order_id, SheetSync_Sheets_Client $client ): int {
        try {
            $all_rows = $client->get_rows(
                $conn->spreadsheet_id,
                SheetSync_Sheets_Client::tab_range( $conn->sheet_name, 'A:A' )
            );
        } catch ( Exception $e ) {
            return 0;
        }

        foreach ( $all_rows as $i => $r ) {
            $row_1based = $i + 1;
            if ( $row_1based < self::ORDER_DATA_START_ROW ) {
                continue;
            }
            $cell = $r[0] ?? '';
            if ( strtoupper( (string) $cell ) === 'TOTAL' ) {
                continue;
            }
            if ( absint( $cell ) === $order_id ) {
                return $row_1based;
            }
        }

        return 0;
    }

    /**
     * Resolve 1-based sheet row for an order — order meta first, column scan only as fallback.
     */
    public function resolve_order_sheet_row( WC_Order $order, object $conn, SheetSync_Sheets_Client $client ): int {
        $order_id = (int) $order->get_id();
        $conn_id  = (int) $conn->id;

        $row_num = (int) $order->get_meta( '_sheetsync_row_num_' . $conn_id, true );
        if ( $row_num > 0 ) {
            return $row_num;
        }

        $legacy_conn = (int) $order->get_meta( '_sheetsync_conn_id', true );
        if ( $legacy_conn === $conn_id ) {
            $row_num = (int) $order->get_meta( '_sheetsync_row_num', true );
            if ( $row_num > 0 ) {
                return $row_num;
            }
        }

        $row_num = $this->find_order_row_in_sheet( $conn, $order_id, $client );
        if ( $row_num > 0 ) {
            $order->update_meta_data( '_sheetsync_row_num_' . $conn_id, $row_num );
            $order->update_meta_data( '_sheetsync_row_num', $row_num );
            $order->update_meta_data( '_sheetsync_conn_id', $conn_id );
            $order->save_meta_data();
        }

        return $row_num;
    }

    /**
     * Delete one order row from a connection sheet and fix row meta for remaining orders.
     */
    private function delete_order_row_from_connection(
        WC_Order $order,
        object $conn,
        SheetSync_Sheets_Client $client,
        int $row_num
    ): void {
        $client->ensure_sheet_exists( $conn->spreadsheet_id, $conn->sheet_name );
        $client->delete_row( $conn->spreadsheet_id, $conn->sheet_name, $row_num );

        $order->delete_meta_data( '_sheetsync_row_num_' . $conn->id );
        $order->delete_meta_data( '_sheetsync_row_num' );
        $order->save_meta_data();

        $this->rebuild_row_meta( $conn, $client, $row_num );
        $this->update_total_row( $conn, self::ORDER_DATA_START_ROW );
    }

    /**
     * Ensure the Google Sheet tab exists and has order headers (used on save + sync).
     */
    public function bootstrap_order_sheet( object $conn ): void {
        $client = new SheetSync_Sheets_Client();
        $this->prepare_order_sheet( $conn, $client );
        $this->ensure_sheet_user_experience( $conn, $client );
    }

    /**
     * Dropdown on column C + helpful info row (safe to run repeatedly).
     */
    public function ensure_sheet_user_experience( object $conn, ?SheetSync_Sheets_Client $client = null ): void {
        if ( empty( $conn->spreadsheet_id ) || empty( $conn->sheet_name ) ) {
            return;
        }

        $client = $client ?? new SheetSync_Sheets_Client();
        try {
            $client->ensure_sheet_exists( $conn->spreadsheet_id, $conn->sheet_name );
            $this->refresh_sheet_info_row( $conn, $client );
            $client->apply_order_status_dropdown(
                $conn->spreadsheet_id,
                $conn->sheet_name,
                self::allowed_status_slugs(),
                self::ORDER_DATA_START_ROW,
                500
            );
        } catch ( Exception $e ) {
            SheetSync_Logger::log(
                (int) ( $conn->id ?? 0 ),
                'order',
                'partial',
                0,
                0,
                'Order sheet UX setup: ' . $e->getMessage()
            );
        }
    }

    /**
     * Ensure the Google Sheet tab exists and has order headers before read/write.
     */
    private function prepare_order_sheet( object $conn, SheetSync_Sheets_Client $client ): void {
        $client->ensure_sheet_exists( $conn->spreadsheet_id, $conn->sheet_name );
        $this->ensure_headers( $conn, $client );
    }

    private function append_order( WC_Order $order, object $conn ): void {
        $client = new SheetSync_Sheets_Client();
        $this->prepare_order_sheet( $conn, $client );

        $existing_row = $this->resolve_order_sheet_row( $order, $conn, $client );
        if ( $existing_row > 0 ) {
            $this->update_order_row( $order, $conn, $client, $existing_row );
            return;
        }

        $row = $this->order_to_sheet_row( $order );

        // Read current sheet to find where TOTAL row is (if any).
        $all_rows   = $client->get_rows( $conn->spreadsheet_id, "{$conn->sheet_name}!A:A" );
        $total_row_num = null;
        foreach ( $all_rows as $i => $r ) {
            if ( ( $r[0] ?? '' ) === 'TOTAL' ) {
                $total_row_num = $i + 1; // 1-based
                break;
            }
        }

        if ( $total_row_num ) {
            // Insert the new order row just ABOVE the TOTAL row.
            // Use insertDimension to push TOTAL down, then write the new row.
            $sheet_id = $client->get_sheet_id( $conn->spreadsheet_id, $conn->sheet_name );

            $insert_url  = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $conn->spreadsheet_id ) . ':batchUpdate';
            $insert_body = array(
                'requests' => array(
                    array(
                        'insertDimension' => array(
                            'range' => array(
                                'sheetId'    => $sheet_id,
                                'dimension'  => 'ROWS',
                                'startIndex' => $total_row_num - 1, // 0-based, insert before TOTAL
                                'endIndex'   => $total_row_num,
                            ),
                            'inheritFromBefore' => false,
                        ),
                    ),
                ),
            );
            SheetSync_Google_Auth::api_post( $insert_url, $insert_body );

            // Write data into the newly inserted row.
            $client->set_rows(
                $conn->spreadsheet_id,
                "{$conn->sheet_name}!A{$total_row_num}:L{$total_row_num}",
                array( $row )
            );

            $new_row_num = $total_row_num; // The inserted row IS the new data row.
        } else {
            // No TOTAL row yet — just append normally.
            $client->append_rows( $conn->spreadsheet_id, "{$conn->sheet_name}!A:L", array( $row ) );

            // Determine the row number of what we just appended.
            $refreshed   = $client->get_rows( $conn->spreadsheet_id, "{$conn->sheet_name}!A:A" );
            $new_row_num = count( $refreshed );
            // Walk backwards to find last non-empty, non-TOTAL row.
            foreach ( array_reverse( $refreshed, true ) as $i => $r ) {
                $cell = $r[0] ?? '';
                if ( $cell !== '' && $cell !== 'TOTAL' ) {
                    $new_row_num = $i + 1;
                    break;
                }
            }
        }

        $order->update_meta_data( '_sheetsync_row_num_' . $conn->id, $new_row_num );
        $order->update_meta_data( '_sheetsync_row_num', $new_row_num );
        $order->update_meta_data( '_sheetsync_conn_id', $conn->id );
        $order->save_meta_data();

        SheetSync_Logger::log( $conn->id, 'order', 'success', 1, 0,
            "Order #{$order->get_id()} synced to row {$new_row_num}" );
    }

    /**
     * Overwrite an existing sheet row when the order is already mapped.
     */
    private function update_order_row(
        WC_Order $order,
        object $conn,
        SheetSync_Sheets_Client $client,
        int $row_num
    ): void {
        $client->set_rows(
            $conn->spreadsheet_id,
            "{$conn->sheet_name}!A{$row_num}:L{$row_num}",
            array( $this->order_to_sheet_row( $order ) )
        );

        $order->update_meta_data( '_sheetsync_row_num_' . $conn->id, $row_num );
        $order->update_meta_data( '_sheetsync_row_num', $row_num );
        $order->update_meta_data( '_sheetsync_conn_id', $conn->id );
        $order->save_meta_data();

        SheetSync_Logger::log(
            $conn->id,
            'order',
            'success',
            1,
            0,
            sprintf(
                /* translators: 1: order ID, 2: sheet row number */
                __( 'Order #%1$d updated at row %2$d (duplicate sync prevented).', 'sheetsync-for-woocommerce' ),
                $order->get_id(),
                $row_num
            )
        );
    }

    /**
     * Write or update the TOTAL row at the bottom of an order sheet.
     * Deletes any existing TOTAL row first, then appends a fresh one.
     * SUM covers only actual data rows (row 2 to last data row).
     */
    public function update_total_row( object $conn, int $data_start_row = self::ORDER_DATA_START_ROW ): void {
        try {
            $client = new SheetSync_Sheets_Client();
            $client->ensure_sheet_exists( $conn->spreadsheet_id, $conn->sheet_name );
            $all_rows = $client->get_rows( $conn->spreadsheet_id, "{$conn->sheet_name}!A:A" );

            if ( empty( $all_rows ) ) return;

            // Step 1: Remove any existing TOTAL rows.
            $total_rows_to_delete = array();
            foreach ( $all_rows as $i => $r ) {
                if ( ( $r[0] ?? '' ) === 'TOTAL' ) {
                    $total_rows_to_delete[] = $i + 1; // 1-based
                }
            }
            rsort( $total_rows_to_delete );
            foreach ( $total_rows_to_delete as $del_row ) {
                $client->delete_row( $conn->spreadsheet_id, $conn->sheet_name, $del_row );
            }

            // Step 2: Re-read column A to find actual last data row after deletions.
            $refreshed    = $client->get_rows( $conn->spreadsheet_id, "{$conn->sheet_name}!A:A" );
            $data_end_row = $data_start_row - 1; // fallback: no data rows yet
            foreach ( $refreshed as $i => $r ) {
                $row_1based = $i + 1;
                if ( $row_1based < $data_start_row ) continue; // skip header/info rows
                $cell = $r[0] ?? '';
                if ( $cell !== '' && $cell !== 'TOTAL' ) {
                    $data_end_row = $row_1based;
                }
            }

            // SUM covers only actual data rows.
            $sum_formula = $data_end_row >= $data_start_row
                ? "=SUM(H{$data_start_row}:H{$data_end_row})"
                : '0';

            $total_row_num = $data_end_row + 1;
            $total_row     = array( 'TOTAL', '', '', '', '', '', '', $sum_formula, '', '', '', '' );

            $client->set_rows(
                $conn->spreadsheet_id,
                "{$conn->sheet_name}!A{$total_row_num}:L{$total_row_num}",
                array( $total_row )
            );
        } catch ( Exception $e ) {
            SheetSync_Logger::error( 'Total row update failed: ' . $e->getMessage(), $conn->id );
        }
    }

    /**
     * @return array{date_type: string, date_single: string, date_from: string, date_to: string}
     */
    private function get_order_date_filters( object $conn ): array {
        return array(
            'date_type'   => (string) get_option( 'sheetsync_date_filter_type_' . $conn->id, 'all' ),
            'date_single' => (string) get_option( 'sheetsync_date_filter_single_' . $conn->id, '' ),
            'date_from'   => (string) get_option( 'sheetsync_date_filter_from_' . $conn->id, '' ),
            'date_to'     => (string) get_option( 'sheetsync_date_filter_to_' . $conn->id, '' ),
        );
    }

    /**
     * WooCommerce order query for a connection's filters.
     *
     * @param array{date_type?: string, date_single?: string, date_from?: string, date_to?: string} $filters
     * @return array<string, mixed>
     */
    public function build_wc_order_query_args( object $conn, array $filters = array() ): array {
        if ( empty( $filters ) ) {
            $filters = $this->get_order_date_filters( $conn );
        }

        $date_type   = (string) ( $filters['date_type'] ?? 'all' );
        $date_single = (string) ( $filters['date_single'] ?? '' );
        $date_from   = (string) ( $filters['date_from'] ?? '' );
        $date_to     = (string) ( $filters['date_to'] ?? '' );

        $base_args = array( 'orderby' => 'date', 'order' => 'DESC' );

        $status_filter = SheetSync_Sync_Engine::get_order_status_filter( $conn->connection_type );
        if ( $status_filter !== null ) {
            $base_args['status'] = array( 'wc-' . $status_filter, $status_filter );
        } else {
            $base_args['status'] = array_keys( wc_get_order_statuses() );
        }

        if ( 'single' === $date_type && $date_single !== '' ) {
            $base_args['date_created'] = $date_single . '...' . $date_single;
            $base_args['date_after']   = $date_single . 'T00:00:00';
            $base_args['date_before']  = $date_single . 'T23:59:59';
        } elseif ( 'range' === $date_type && ( $date_from !== '' || $date_to !== '' ) ) {
            $range_start               = $date_from !== '' ? $date_from : '2000-01-01';
            $range_end                 = $date_to !== '' ? $date_to : gmdate( 'Y-m-d' );
            $base_args['date_created'] = $range_start . '...' . $range_end;
            $base_args['date_after']   = $range_start . 'T00:00:00';
            $base_args['date_before']  = $range_end . 'T23:59:59';
        }

        return $base_args;
    }

    /**
     * Count orders matching connection filters (for job estimates).
     */
    public function count_orders_for_connection( object $conn ): int {
        $result = wc_get_orders(
            array_merge(
                $this->build_wc_order_query_args( $conn ),
                array(
                    'limit'    => 1,
                    'paginate' => true,
                    'return'   => 'ids',
                )
            )
        );

        if ( is_array( $result ) && isset( $result['total'] ) ) {
            return (int) $result['total'];
        }

        return 0;
    }

    /**
     * @return array<int, string>
     */
    public function order_to_sheet_row( WC_Order $order ): array {
        $items_summary = implode(
            ', ',
            array_map(
                static fn( $item ) => $item->get_name() . ' ×' . $item->get_quantity(),
                $order->get_items()
            )
        );
        $billing_address = wp_strip_all_tags(
            str_replace( array( '<br/>', '<br />', '<br>' ), ', ', $order->get_formatted_billing_address() )
        );

        return array(
            (string) $order->get_id(),
            $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
            $order->get_status(),
            trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            $order->get_billing_email(),
            $order->get_billing_phone(),
            $billing_address,
            $this->format_order_net_total( $order ),
            $order->get_payment_method_title(),
            $items_summary,
            $order->get_shipping_method(),
            $order->get_customer_note(),
        );
    }

    /**
     * Clear tab and write header + filter info rows for a full order export.
     *
     * @param array{date_type?: string, date_single?: string, date_from?: string, date_to?: string} $filters
     */
    public function reset_order_sheet_for_export( object $conn, SheetSync_Sheets_Client $client, array $filters = array() ): void {
        if ( empty( $filters ) ) {
            $filters = $this->get_order_date_filters( $conn );
        }

        $client->ensure_sheet_exists( $conn->spreadsheet_id, $conn->sheet_name );
        $client->clear_sheet( $conn->spreadsheet_id, $conn->sheet_name );

        $header_labels = array(
            'Order ID', 'Date', 'Status', 'Customer Name', 'Email', 'Phone',
            'Billing Address', 'Total', 'Payment Method', 'Items', 'Shipping', 'Note',
        );
        $client->write_styled_headers( $conn->spreadsheet_id, $conn->sheet_name, 1, $header_labels );

        $filter_label = $this->build_filter_label(
            (string) $conn->connection_type,
            (string) ( $filters['date_type'] ?? 'all' ),
            (string) ( $filters['date_single'] ?? '' ),
            (string) ( $filters['date_from'] ?? '' ),
            (string) ( $filters['date_to'] ?? '' )
        );
        $info_row    = array_fill( 0, 12, '' );
        $info_row[0] = $filter_label;
        $client->set_rows( $conn->spreadsheet_id, "{$conn->sheet_name}!A2:L2", array( $info_row ) );

        try {
            $sheet_id = $client->get_sheet_id( $conn->spreadsheet_id, $conn->sheet_name );
            SheetSync_Google_Auth::api_post(
                'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $conn->spreadsheet_id ) . ':batchUpdate',
                array(
                    'requests' => array(
                        array(
                            'mergeCells' => array(
                                'range'     => array(
                                    'sheetId'          => $sheet_id,
                                    'startRowIndex'    => 1,
                                    'endRowIndex'      => 2,
                                    'startColumnIndex' => 0,
                                    'endColumnIndex'   => 12,
                                ),
                                'mergeType' => 'MERGE_ALL',
                            ),
                        ),
                        array(
                            'repeatCell' => array(
                                'range'  => array(
                                    'sheetId'          => $sheet_id,
                                    'startRowIndex'    => 1,
                                    'endRowIndex'      => 2,
                                    'startColumnIndex' => 0,
                                    'endColumnIndex'   => 12,
                                ),
                                'cell'   => array(
                                    'userEnteredFormat' => array(
                                        'backgroundColor'     => array( 'red' => 0.851, 'green' => 0.918, 'blue' => 0.965 ),
                                        'textFormat'          => array(
                                            'bold'            => true,
                                            'italic'          => true,
                                            'fontSize'        => 10,
                                            'foregroundColor' => array( 'red' => 0.063, 'green' => 0.329, 'blue' => 0.529 ),
                                        ),
                                        'horizontalAlignment' => 'CENTER',
                                        'verticalAlignment'   => 'MIDDLE',
                                        'wrapStrategy'        => 'CLIP',
                                    ),
                                ),
                                'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment,verticalAlignment,wrapStrategy)',
                            ),
                        ),
                    ),
                )
            );
        } catch ( Exception $e ) {
            SheetSync_Logger::error( 'Info row styling failed: ' . $e->getMessage(), $conn->id );
        }
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function persist_order_rows_meta( object $conn, array $rows, int $start_row ): void {
        foreach ( $rows as $i => $row_data ) {
            $order_id = (int) ( $row_data[0] ?? 0 );
            if ( $order_id <= 0 ) {
                continue;
            }
            $meta_order = wc_get_order( $order_id );
            if ( ! $meta_order ) {
                continue;
            }
            $row_num = $start_row + $i;
            $meta_order->update_meta_data( '_sheetsync_row_num_' . $conn->id, $row_num );
            $meta_order->update_meta_data( '_sheetsync_row_num', $row_num );
            $meta_order->update_meta_data( '_sheetsync_conn_id', $conn->id );
            $meta_order->save_meta_data();
        }
    }

    /**
     * Finish order sheet export styling after all rows are written.
     */
    public function finalize_order_sheet_export( object $conn, int $processed_rows ): void {
        $this->update_total_row( $conn, self::ORDER_DATA_START_ROW );
        try {
            $client = new SheetSync_Sheets_Client();
            $this->ensure_sheet_user_experience( $conn, $client );
            if ( $processed_rows > 0 ) {
                $client->apply_row_colors(
                    $conn->spreadsheet_id,
                    $conn->sheet_name,
                    self::ORDER_DATA_START_ROW,
                    $processed_rows,
                    12
                );
            }
        } catch ( Exception $e ) {
            // Non-fatal.
        }
    }

    /**
     * Background job batch: push orders to sheet without loading the full catalog into memory.
     *
     * @return array{done: bool, processed: int, skipped: int, errors: int}
     */
    public function process_push_batch( object $job, object $conn, int $batch_size, int $deadline ): array {
        $job_repo = new SheetSync_Job_Repository();
        $meta     = $job_repo->get_cursor_meta( $job );
        $client   = new SheetSync_Sheets_Client();

        $wc_page     = max( 1, (int) ( $meta['order_wc_page'] ?? 1 ) );
        $sheet_row   = max( self::ORDER_DATA_START_ROW, (int) ( $meta['order_sheet_row'] ?? self::ORDER_DATA_START_ROW ) );
        $initialized = ! empty( $meta['order_sheet_initialized'] );
        $per_page    = max( 10, min( 100, $batch_size ) );
        $processed   = 0;
        $errors      = 0;
        $done        = false;

        if ( ! $initialized ) {
            try {
                $this->reset_order_sheet_for_export( $conn, $client );
                $meta['order_sheet_initialized'] = true;
                $meta['order_sheet_row']         = self::ORDER_DATA_START_ROW;
                $meta['order_wc_page']           = 1;
                $sheet_row                       = self::ORDER_DATA_START_ROW;
                $wc_page                         = 1;
                $job_repo->update_cursor( (int) $job->id, (int) $job->cursor_offset, $meta );
            } catch ( Exception $e ) {
                SheetSync_Logger::error( 'Order sheet reset failed: ' . $e->getMessage(), $conn->id );
                return array(
                    'done'      => true,
                    'processed' => 0,
                    'skipped'   => 0,
                    'errors'    => 1,
                );
            }
        }

        $base_args = $this->build_wc_order_query_args( $conn );

        while ( time() < $deadline ) {
            $orders = wc_get_orders(
                array_merge(
                    $base_args,
                    array(
                        'limit' => $per_page,
                        'page'  => $wc_page,
                    )
                )
            );

            if ( empty( $orders ) ) {
                $done = true;
                break;
            }

            $rows = array();
            foreach ( $orders as $order ) {
                try {
                    $rows[] = $this->order_to_sheet_row( $order );
                } catch ( Exception $e ) {
                    ++$errors;
                    SheetSync_Logger::error( "Order #{$order->get_id()} row build failed: " . $e->getMessage(), $conn->id );
                }
            }

            if ( ! empty( $rows ) ) {
                $end_row = $sheet_row + count( $rows ) - 1;
                try {
                    $client->set_rows(
                        $conn->spreadsheet_id,
                        "{$conn->sheet_name}!A{$sheet_row}:L{$end_row}",
                        $rows
                    );
                    $this->persist_order_rows_meta( $conn, $rows, $sheet_row );
                    $processed += count( $rows );
                    $sheet_row  = $end_row + 1;
                } catch ( Exception $e ) {
                    $errors += count( $rows );
                    SheetSync_Logger::error( 'Order batch write failed: ' . $e->getMessage(), $conn->id );
                }
            }

            if ( count( $orders ) < $per_page ) {
                $done = true;
                break;
            }

            ++$wc_page;
        }

        $meta['order_wc_page']     = $wc_page;
        $meta['order_sheet_row']   = $sheet_row;
        $meta['order_rows_written'] = (int) ( $meta['order_rows_written'] ?? 0 ) + $processed;
        $job_repo->update_cursor( (int) $job->id, (int) $job->cursor_offset, $meta );

        if ( $done ) {
            $total_written = (int) ( $meta['order_rows_written'] ?? $processed );
            $this->finalize_order_sheet_export( $conn, $total_written );
        }

        return array(
            'done'      => $done,
            'processed' => $processed,
            'skipped'   => 0,
            'errors'    => $errors,
        );
    }

    /**
     * WooCommerce → Sheet: Push all orders to Sheet (Manual Sync).
     * Filters by connection type (e.g. orders_processing → only processing orders).
     */
    public function sync_orders_to_sheet( object $conn ): array {
        $filters     = $this->get_order_date_filters( $conn );
        $cursor_key  = 'sheetsync_order_push_cursor_' . (int) $conn->id;
        $cursor      = get_transient( $cursor_key );
        $client      = new SheetSync_Sheets_Client();
        $per_page    = (int) apply_filters( 'sheetsync_order_push_batch_size', 100 );
        $per_page    = max( 10, min( 100, $per_page ) );
        $deadline    = time() + (int) apply_filters( 'sheetsync_order_push_deadline_seconds', 240 );
        $timed_out   = false;

        if ( ! is_array( $cursor ) ) {
            $cursor = array(
                'wc_page'     => 1,
                'sheet_row'   => self::ORDER_DATA_START_ROW,
                'initialized' => false,
                'processed'   => 0,
                'errors'      => 0,
            );
        }

        try {
            $client->ensure_sheet_exists( $conn->spreadsheet_id, $conn->sheet_name );
        } catch ( Exception $e ) {
            SheetSync_Logger::error( 'ensure_sheet_exists failed: ' . $e->getMessage(), $conn->id );
            return array(
                'processed'       => 0,
                'skipped'         => 0,
                'errors'          => 1,
                'connection_type' => $conn->connection_type,
                'date_type'       => $filters['date_type'],
                'date_single'     => $filters['date_single'],
                'date_from'       => $filters['date_from'],
                'date_to'         => $filters['date_to'],
            );
        }

        if ( empty( $cursor['initialized'] ) ) {
            try {
                $this->reset_order_sheet_for_export( $conn, $client, $filters );
                $cursor['initialized'] = true;
            } catch ( Exception $e ) {
                SheetSync_Logger::error( 'Order sheet reset failed: ' . $e->getMessage(), $conn->id );
                return array(
                    'processed'       => 0,
                    'skipped'         => 0,
                    'errors'          => 1,
                    'connection_type' => $conn->connection_type,
                    'date_type'       => $filters['date_type'],
                    'date_single'     => $filters['date_single'],
                    'date_from'       => $filters['date_from'],
                    'date_to'         => $filters['date_to'],
                );
            }
        }

        $base_args = $this->build_wc_order_query_args( $conn, $filters );
        $done      = false;

        while ( time() < $deadline ) {
            $orders = wc_get_orders(
                array_merge(
                    $base_args,
                    array(
                        'limit' => $per_page,
                        'page'  => (int) $cursor['wc_page'],
                    )
                )
            );

            if ( empty( $orders ) ) {
                $done = true;
                break;
            }

            $rows = array();
            foreach ( $orders as $order ) {
                try {
                    $rows[] = $this->order_to_sheet_row( $order );
                } catch ( Exception $e ) {
                    ++$cursor['errors'];
                    SheetSync_Logger::error( "Order #{$order->get_id()} row build failed: " . $e->getMessage(), $conn->id );
                }
            }

            if ( ! empty( $rows ) ) {
                $start_row = (int) $cursor['sheet_row'];
                $end_row   = $start_row + count( $rows ) - 1;
                try {
                    $client->set_rows(
                        $conn->spreadsheet_id,
                        "{$conn->sheet_name}!A{$start_row}:L{$end_row}",
                        $rows
                    );
                    $this->persist_order_rows_meta( $conn, $rows, $start_row );
                    $cursor['processed'] += count( $rows );
                    $cursor['sheet_row']  = $end_row + 1;
                } catch ( Exception $e ) {
                    $cursor['errors'] += count( $rows );
                    SheetSync_Logger::error( 'Order batch write failed: ' . $e->getMessage(), $conn->id );
                }
            }

            if ( count( $orders ) < $per_page ) {
                $done = true;
                break;
            }

            ++$cursor['wc_page'];
        }

        if ( ! $done ) {
            $timed_out = true;
            set_transient( $cursor_key, $cursor, HOUR_IN_SECONDS );
        } else {
            delete_transient( $cursor_key );
            $this->finalize_order_sheet_export( $conn, (int) $cursor['processed'] );
        }

        return array(
            'processed'       => (int) $cursor['processed'],
            'skipped'         => 0,
            'errors'          => (int) $cursor['errors'],
            'partial'         => $timed_out,
            'timed_out'       => $timed_out,
            'connection_type' => $conn->connection_type,
            'date_type'       => $filters['date_type'],
            'date_single'     => $filters['date_single'],
            'date_from'       => $filters['date_from'],
            'date_to'         => $filters['date_to'],
        );
    }

    /**
     * When Sync Now is clicked, updates all order statuses from Sheet to WooCommerce.
     *
     * Sheet layout for orders:
     *   Row 1 = Headers (Order ID, Date, Status, ...)
     *   Row 2 = Filter info row (merged, non-data)
     *   Row 3+ = Actual order data
     *
     * This method skips both the header and the filter info row.
     */
    public function sync_order_statuses_from_sheet( object $conn ): array {
        $processed = 0;
        $skipped   = 0;
        $errors    = 0;

        $client = new SheetSync_Sheets_Client();
        try {
            $client->ensure_sheet_exists( $conn->spreadsheet_id, $conn->sheet_name );
        } catch ( Exception $e ) {
            SheetSync_Logger::error( 'ensure_sheet_exists failed: ' . $e->getMessage(), $conn->id );
            return array( 'processed' => 0, 'skipped' => 0, 'errors' => 1 );
        }

        $all_rows = $client->get_rows( $conn->spreadsheet_id, "{$conn->sheet_name}!A:L" );

        if ( empty( $all_rows ) ) {
            return array( 'processed' => 0, 'skipped' => 0, 'errors' => 0 );
        }

        // Data starts at row 3 (index 2): row 1 = header, row 2 = filter info.
        $data_start_index = 2;
        $data_rows        = array_slice( $all_rows, $data_start_index );

        $allowed_statuses = self::allowed_status_slugs();

        foreach ( $data_rows as $row ) {
            $order_id = absint( $row[0] ?? 0 ); // Column A = Order ID
            $status   = $this->normalize_status( (string) ( $row[2] ?? '' ) ); // Column C = Status

            if ( ! $order_id || empty( $status ) ) {
                $skipped++;
                continue;
            }

            // Skip TOTAL row or non-numeric order IDs.
            if ( strtoupper( $row[0] ?? '' ) === 'TOTAL' ) {
                continue;
            }

            // Check if status is valid.
            if ( ! in_array( $status, $allowed_statuses, true ) ) {
                $skipped++;
                continue;
            }

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                $skipped++;
                continue;
            }

            // Status already matches WooCommerce — still reconcile rows (order may be on wrong tab).
            if ( $order->get_status() === $status ) {
                try {
                    $this->sync_order_rows_for_status( $order_id, $status, (int) $conn->id );
                    $processed++;
                } catch ( Exception $e ) {
                    $errors++;
                    SheetSync_Logger::error( "Order #{$order_id} reconcile failed: " . $e->getMessage(), $conn->id );
                }
                continue;
            }

            try {
                set_transient( 'sheetsync_status_changing_' . $order_id, 'sheet', 30 );

                $order->update_status( $status, 'Updated by SheetSync.', true );

                // Always move rows between status tabs after a sheet → WC update.
                $this->sync_order_rows_for_status( $order_id, $status, (int) $conn->id );
                delete_transient( 'sheetsync_status_changing_' . $order_id );

                $processed++;
                SheetSync_Logger::log( $conn->id, 'order_status', 'success', 1, 0,
                    "Order #{$order_id} status → {$status}" );
            } catch ( Exception $e ) {
                delete_transient( 'sheetsync_status_changing_' . $order_id );
                $errors++;
                SheetSync_Logger::error( "Order #{$order_id} update failed: " . $e->getMessage(), $conn->id );
            }
        }

        return array( 'processed' => $processed, 'skipped' => $skipped, 'errors' => $errors );
    }

    /**
     * After a row is deleted from the sheet, all orders that were below
     * the deleted row have shifted up by 1. Re-read the sheet and
     * update _sheetsync_row_num_{conn_id} for each affected order.
     *
     * @param object                    $conn       Connection object.
     * @param SheetSync_Sheets_Client   $client     Sheets client.
     * @param int                       $deleted_row 1-based row number that was just deleted.
     */
    private function rebuild_row_meta( object $conn, SheetSync_Sheets_Client $client, int $deleted_row ): void {
        // Read column A to get current order IDs and their new row positions.
        $all_rows = $client->get_rows( $conn->spreadsheet_id, "{$conn->sheet_name}!A:A" );

        foreach ( $all_rows as $i => $r ) {
            $cell    = $r[0] ?? '';
            $row_num = $i + 1; // 1-based

            // Skip header, filter info row, empty cells, TOTAL row.
            if ( $row_num <= 2 || $cell === '' || $cell === 'TOTAL' ) continue;

            $order_id = absint( $cell );
            if ( ! $order_id ) continue;

            // HPOS-compatible: use WC_Order meta methods.
            $meta_order = wc_get_order( $order_id );
            if ( $meta_order ) {
                $meta_order->update_meta_data( '_sheetsync_row_num_' . $conn->id, $row_num );
                $meta_order->update_meta_data( '_sheetsync_row_num', $row_num );
                $meta_order->save_meta_data();
            }
        }
    }

    /**
     * Build a human-readable label for the info row in the sheet.
     * Shows exactly what connection type + date filter is active.
     * e.g. "⚙️ Processing Orders — 📅 Date: 2026-03-11 | Last synced: 11 Mar 2026, 15:30"
     */
    private function build_filter_label(
        string $connection_type,
        string $date_type,
        string $date_single,
        string $date_from,
        string $date_to
    ): string {
        $type_labels = array(
            'orders'            => '📋 All Orders',
            'orders_pending'    => '⏳ Pending Payment Orders',
            'orders_processing' => '⚙️ Processing Orders',
            'orders_on-hold'    => '🔔 On Hold Orders',
            'orders_completed'  => '✅ Completed Orders',
            'orders_cancelled'  => '❌ Cancelled Orders',
            'orders_refunded'   => '↩️ Refunded Orders',
            'orders_failed'     => '🚫 Failed Orders',
            'orders_draft'      => '📝 Draft Orders',
        );

        $type_label = $type_labels[ $connection_type ] ?? ucwords( str_replace( '_', ' ', $connection_type ) );

        if ( $date_type === 'single' && $date_single ) {
            $date_label = ' — 📅 Date: ' . $date_single;
        } elseif ( $date_type === 'range' ) {
            $from       = $date_from ?: '(start)';
            $to         = $date_to   ?: '(today)';
            $date_label = ' — 📅 Date Range: ' . $from . ' → ' . $to;
        } else {
            $date_label = ' — All Dates';
        }

        return $type_label . $date_label . ' | Last synced: ' . current_time( 'd M Y, H:i' );
    }

    private function ensure_headers( object $conn, SheetSync_Sheets_Client $client ): void {
        $existing   = $client->get_rows( $conn->spreadsheet_id, "{$conn->sheet_name}!1:2" );
        $first_cell = $existing[0][0] ?? '';

        // Header already in place — nothing to do.
        if ( $first_cell === 'Order ID' ) return;

        // Sheet has unexpected content — warn and bail.
        if ( ! empty( $first_cell ) ) {
            SheetSync_Logger::log(
                $conn->id, 'order', 'error', 0, 0,
                'Sheet tab "' . $conn->sheet_name . '" has unexpected data in row 1. Use a dedicated tab for order sync.'
            );
            throw new Exception( 'Wrong sheet tab — row 1 already contains data. Please use a dedicated Sheet tab for order sync.' );
        }

        $header_labels = array(
            'Order ID', 'Date', 'Status', 'Customer Name', 'Email', 'Phone',
            'Billing Address', 'Total', 'Payment Method', 'Items', 'Shipping', 'Note',
        );

        // Use styled headers (dark green bg, bold white text, frozen row)
        $client->write_styled_headers(
            $conn->spreadsheet_id,
            $conn->sheet_name,
            1,
            $header_labels
        );

        $this->refresh_sheet_info_row( $conn, $client );
    }

    /**
     * Row 2: connection filter summary + short how-to for column C.
     */
    private function refresh_sheet_info_row( object $conn, SheetSync_Sheets_Client $client ): void {
        $date_type   = (string) ( $conn->order_date_type ?? 'all' );
        $date_single = (string) ( $conn->order_date_single ?? '' );
        $date_from   = (string) ( $conn->order_date_from ?? '' );
        $date_to     = (string) ( $conn->order_date_to ?? '' );

        $filter_label = $this->build_filter_label(
            (string) ( $conn->connection_type ?? 'orders' ),
            $date_type,
            $date_single,
            $date_from,
            $date_to
        );
        $hint = __( 'Edit column C (Status) — choose completed from the dropdown to update WooCommerce.', 'sheetsync-for-woocommerce' );

        $info_row    = array_fill( 0, 12, '' );
        $info_row[0] = $filter_label . ' | ' . $hint;
        $client->set_rows( $conn->spreadsheet_id, "{$conn->sheet_name}!A2:L2", array( $info_row ) );
    }
}
