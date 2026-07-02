<?php
/**
 * PRO: Bulk Order Export — export filtered WooCommerce orders to Google Sheets or CSV.
 *
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class SheetSync_Bulk_Order_Export {

    public function __construct() {
        add_action( 'wp_ajax_sheetsync_bulk_order_export_sheets', array( $this, 'ajax_export_to_sheets' ) );
        add_action( 'wp_ajax_sheetsync_bulk_order_export_csv',    array( $this, 'ajax_export_csv' ) );
        add_action( 'wp_ajax_sheetsync_bulk_order_count',         array( $this, 'ajax_get_order_count' ) );
        add_action( 'wp_ajax_sheetsync_bulk_order_preview',      array( $this, 'ajax_get_order_preview' ) );
    }

    // ─── AJAX handlers ────────────────────────────────────────────────────────

    public function ajax_export_to_sheets(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( class_exists( 'SheetSync_Dashboard_Phase2', false ) ) {
            SheetSync_Dashboard_Phase2::require_dashboard( SheetSync_Dashboard_Phase2::DASH_ORDERS );
            SheetSync_Dashboard_Phase2::reject_demo_export();
        } elseif ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $spreadsheet_id = sanitize_text_field( wp_unslash( $_POST['spreadsheet_id'] ?? '' ) );
        $sheet_name     = sanitize_text_field( wp_unslash( $_POST['sheet_name'] ?? 'Orders Export' ) );
        $filters        = $this->parse_filters();

        if ( empty( $spreadsheet_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Spreadsheet ID is required.', 'sheetsync-for-woocommerce' ) ) );
        }

        try {
            $result = $this->export_orders_to_sheets( $spreadsheet_id, $sheet_name, $filters );
            if ( class_exists( 'SheetSync_Dashboard_Enhancements', false ) ) {
                SheetSync_Dashboard_Enhancements::log_export( 'orders', true, $result['message'] ?? '', $result );
            }
            wp_send_json_success( $result );
        } catch ( Exception $e ) {
            if ( class_exists( 'SheetSync_Dashboard_Enhancements', false ) ) {
                SheetSync_Dashboard_Enhancements::log_export( 'orders', false, $e->getMessage(), array() );
            }
            wp_send_json_error( array( 'message' => esc_html( $e->getMessage() ) ) );
        }
    }

    public function ajax_export_csv(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( class_exists( 'SheetSync_Dashboard_Phase2', false ) ) {
            SheetSync_Dashboard_Phase2::require_dashboard( SheetSync_Dashboard_Phase2::DASH_ORDERS );
        } elseif ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $filters = $this->parse_filters();

        try {
            $orders = $this->get_filtered_orders( $filters );
            $rows   = $this->build_order_rows( $orders, $filters['fields'] ?? array() );
            $csv    = $this->rows_to_csv( $rows );

            wp_send_json_success( array(
                'csv'         => $csv,
                'order_count' => count( $orders ),
                'filename'    => 'orders-export-' . gmdate( 'Y-m-d' ) . '.csv',
            ) );
        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => esc_html( $e->getMessage() ) ) );
        }
    }

    public function ajax_get_order_count(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( class_exists( 'SheetSync_Dashboard_Phase2', false ) ) {
            SheetSync_Dashboard_Phase2::require_dashboard( SheetSync_Dashboard_Phase2::DASH_ORDERS );
        } elseif ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $filters = $this->parse_filters();

        try {
            $orders = $this->get_filtered_orders( $filters, true );
            wp_send_json_success( array( 'count' => count( $orders ) ) );
        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => esc_html( $e->getMessage() ) ) );
        }
    }

    public function ajax_get_order_preview(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( class_exists( 'SheetSync_Dashboard_Phase2', false ) ) {
            SheetSync_Dashboard_Phase2::require_dashboard( SheetSync_Dashboard_Phase2::DASH_ORDERS );
        } elseif ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        try {
            wp_send_json_success( $this->get_export_preview( $this->parse_filters() ) );
        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => esc_html( $e->getMessage() ) ) );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function get_export_preview( array $filters ): array {
        $orders        = $this->get_filtered_orders( $filters );
        $total_revenue = 0.0;
        $status_counts = array();
        $preview       = array();

        foreach ( $orders as $order ) {
            if ( ! $order instanceof WC_Order ) {
                continue;
            }

            $net_total = $this->order_net_total( $order );
            $total_revenue += $net_total;

            $status_slug = $order->get_status();
            if ( ! isset( $status_counts[ $status_slug ] ) ) {
                $status_counts[ $status_slug ] = array(
                    'slug'  => $status_slug,
                    'label' => wc_get_order_status_name( $status_slug ),
                    'count' => 0,
                );
            }
            $status_counts[ $status_slug ]['count']++;

            if ( count( $preview ) >= 25 ) {
                continue;
            }

            $items = array();
            foreach ( $order->get_items() as $item ) {
                $items[] = $item->get_name() . ' × ' . $item->get_quantity();
            }

            $preview[] = array(
                'id'           => $order->get_id(),
                'number'       => $order->get_order_number(),
                'date'         => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'M j, Y g:i A' ) : '',
                'customer'     => trim( $order->get_formatted_billing_full_name() ) ?: __( 'Guest', 'sheetsync-for-woocommerce' ),
                'email'        => $order->get_billing_email(),
                'status'       => wc_get_order_status_name( $status_slug ),
                'status_slug'  => $status_slug,
                'total'        => round( $net_total, 2 ),
                'items'        => implode( ', ', array_slice( $items, 0, 2 ) ) . ( count( $items ) > 2 ? '…' : '' ),
                'payment'      => $order->get_payment_method_title() ?: '—',
                'edit_url'     => $order->get_edit_order_url(),
            );
        }

        $count = count( $orders );

        return array(
            'count'             => $count,
            'total_revenue'     => round( $total_revenue, 2 ),
            'avg_order'         => $count > 0 ? round( $total_revenue / $count, 2 ) : 0.0,
            'status_breakdown'  => array_values( $status_counts ),
            'orders'            => $preview,
            'export_fields'     => $this->get_fields_for_display( $filters['fields'] ?? array() ),
            'currency_symbol'   => html_entity_decode( get_woocommerce_currency_symbol() ),
            'generated_at'      => wp_date( 'M j, Y g:i A' ),
        );
    }

    /**
     * @return array<string, string>
     */
    public function get_export_field_labels_public(): array {
        return $this->get_export_field_labels();
    }

    /**
     * @param array<int, string> $fields
     * @return array<string, string>
     */
    private function get_fields_for_display( array $fields ): array {
        $labels = $this->get_export_field_labels();
        if ( empty( $fields ) ) {
            $fields = $this->get_default_fields();
        }
        $out = array();
        foreach ( $fields as $key ) {
            if ( isset( $labels[ $key ] ) ) {
                $out[ $key ] = $labels[ $key ];
            }
        }
        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function get_export_field_labels(): array {
        return array(
            'order_id'         => __( 'Order ID', 'sheetsync-for-woocommerce' ),
            'date'             => __( 'Date & Time', 'sheetsync-for-woocommerce' ),
            'status'           => __( 'Order Status', 'sheetsync-for-woocommerce' ),
            'customer_name'    => __( 'Customer Name', 'sheetsync-for-woocommerce' ),
            'customer_email'   => __( 'Email', 'sheetsync-for-woocommerce' ),
            'billing_address'  => __( 'Billing Address', 'sheetsync-for-woocommerce' ),
            'shipping_address' => __( 'Shipping Address', 'sheetsync-for-woocommerce' ),
            'phone'            => __( 'Phone', 'sheetsync-for-woocommerce' ),
            'products'         => __( 'Products Ordered', 'sheetsync-for-woocommerce' ),
            'items_count'      => __( 'Items Count', 'sheetsync-for-woocommerce' ),
            'subtotal'         => __( 'Subtotal', 'sheetsync-for-woocommerce' ),
            'shipping_cost'    => __( 'Shipping Cost', 'sheetsync-for-woocommerce' ),
            'tax'              => __( 'Tax', 'sheetsync-for-woocommerce' ),
            'discount'         => __( 'Discount', 'sheetsync-for-woocommerce' ),
            'total'            => __( 'Order Total', 'sheetsync-for-woocommerce' ),
            'payment_method'   => __( 'Payment Method', 'sheetsync-for-woocommerce' ),
            'transaction_id'   => __( 'Transaction ID', 'sheetsync-for-woocommerce' ),
            'customer_note'    => __( 'Customer Note', 'sheetsync-for-woocommerce' ),
        );
    }

    // ─── Filter Parsing ───────────────────────────────────────────────────────

    private function parse_filters(): array {
        $raw_statuses = $_POST['statuses'] ?? array();
        $statuses = array();
        if ( is_array( $raw_statuses ) ) {
            foreach ( $raw_statuses as $s ) {
                $statuses[] = sanitize_key( $s );
            }
        }

        return array(
            'statuses'   => ! empty( $statuses ) ? $statuses : array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
            'date_from'  => sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) ),
            'date_to'    => sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) ),
            'customer'   => sanitize_text_field( wp_unslash( $_POST['customer'] ?? '' ) ),
            'min_total'  => (float) ( $_POST['min_total'] ?? 0 ),
            'max_total'  => (float) ( $_POST['max_total'] ?? 0 ),
            'product_id' => absint( $_POST['product_id'] ?? 0 ),
            'fields'     => isset( $_POST['fields'] ) && is_array( $_POST['fields'] )
                ? array_map( 'sanitize_key', $_POST['fields'] )
                : $this->get_default_fields(),
        );
    }

    private function get_default_fields(): array {
        return array(
            'order_id', 'date', 'status', 'customer_name', 'customer_email',
            'billing_address', 'shipping_address', 'products', 'items_count',
            'subtotal', 'shipping_cost', 'tax', 'total', 'payment_method',
        );
    }

    /**
     * Normalize filter arrays for scheduled/cron exports.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function normalize_export_filters( array $filters ): array {
        $statuses = array();
        foreach ( (array) ( $filters['statuses'] ?? array() ) as $status ) {
            $status = sanitize_key( (string) $status );
            if ( $status === '' ) {
                continue;
            }
            if ( ! str_starts_with( $status, 'wc-' ) ) {
                $status = 'wc-' . $status;
            }
            $statuses[] = $status;
        }

        $fields = isset( $filters['fields'] ) && is_array( $filters['fields'] )
            ? array_values( array_filter( array_map( 'sanitize_key', $filters['fields'] ) ) )
            : array();

        return array(
            'statuses'   => ! empty( $statuses ) ? $statuses : array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
            'date_from'  => sanitize_text_field( (string) ( $filters['date_from'] ?? '' ) ),
            'date_to'    => sanitize_text_field( (string) ( $filters['date_to'] ?? '' ) ),
            'customer'   => sanitize_text_field( (string) ( $filters['customer'] ?? '' ) ),
            'min_total'  => (float) ( $filters['min_total'] ?? 0 ),
            'max_total'  => (float) ( $filters['max_total'] ?? 0 ),
            'product_id' => absint( $filters['product_id'] ?? 0 ),
            'fields'     => ! empty( $fields ) ? $fields : $this->get_default_fields(),
        );
    }

    // ─── Order Fetching ───────────────────────────────────────────────────────

    private function get_filtered_orders( array $filters, bool $ids_only = false ): array {
        $all       = array();
        $page      = 1;
        $per_page  = (int) apply_filters( 'sheetsync_boe_orders_per_page', 500 );
        $per_page  = max( 100, min( 1000, $per_page ) );
        $max_page  = (int) apply_filters( 'sheetsync_boe_orders_max_pages', 400 );

        do {
            $args = array(
                'status'  => $filters['statuses'],
                'limit'   => $per_page,
                'page'    => $page,
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => $ids_only ? 'ids' : 'objects',
            );

            // Date range
            if ( ! empty( $filters['date_from'] ) && ! empty( $filters['date_to'] ) ) {
                $args['date_created'] = $filters['date_from'] . ' 00:00:00...' . $filters['date_to'] . ' 23:59:59';
            } elseif ( ! empty( $filters['date_from'] ) ) {
                $args['date_created'] = '>=' . $filters['date_from'] . ' 00:00:00';
            } elseif ( ! empty( $filters['date_to'] ) ) {
                $args['date_created'] = '<=' . $filters['date_to'] . ' 23:59:59';
            }

            // Customer email search
            if ( ! empty( $filters['customer'] ) ) {
                $args['billing_email'] = $filters['customer'];
            }

            $batch = wc_get_orders( $args );

            if ( empty( $batch ) ) {
                break;
            }

            $all = array_merge( $all, $batch );

            if ( count( $batch ) < $per_page ) {
                break;
            }

            ++$page;
        } while ( $page <= $max_page );

        $orders = $all;

        // Post-filter: min/max total, product filter
        if ( ! $ids_only && ( $filters['min_total'] > 0 || $filters['max_total'] > 0 || $filters['product_id'] > 0 ) ) {
            $orders = array_filter( $orders, function( $order ) use ( $filters ) {
                $total = $this->order_net_total( $order );
                if ( $filters['min_total'] > 0 && $total < $filters['min_total'] ) return false;
                if ( $filters['max_total'] > 0 && $total > $filters['max_total'] ) return false;
                if ( $filters['product_id'] > 0 ) {
                    $product_match = false;
                    foreach ( $order->get_items() as $item ) {
                        if ( ! $item instanceof WC_Order_Item_Product ) {
                            continue;
                        }
                        $product_id   = (int) $item->get_product_id();
                        $variation_id = (int) $item->get_variation_id();
                        if ( $filters['product_id'] === $product_id
                            || ( $variation_id > 0 && $filters['product_id'] === $variation_id ) ) {
                            $product_match = true;
                            break;
                        }
                    }
                    if ( ! $product_match ) {
                        return false;
                    }
                }
                return true;
            } );
        }

        return array_values( $orders );
    }

    // ─── Row Building ─────────────────────────────────────────────────────────

    private function build_order_rows( array $orders, array $fields = array() ): array {
        $field_labels = $this->get_export_field_labels();
        if ( empty( $fields ) ) {
            $fields = $this->get_default_fields();
        }

        $header = array();
        foreach ( $fields as $key ) {
            if ( isset( $field_labels[ $key ] ) ) {
                $header[] = $field_labels[ $key ];
            }
        }

        $rows = array( $header );

        foreach ( $orders as $order ) {
            $row = array();

            // Products list
            $product_names = array();
            foreach ( $order->get_items() as $item ) {
                $product_names[] = $item->get_name() . ' x' . $item->get_quantity();
            }

            $billing  = $order->get_address( 'billing' );
            $shipping = $order->get_address( 'shipping' );

            $billing_str  = implode( ', ', array_filter( array(
                $billing['address_1'] ?? '',
                $billing['city'] ?? '',
                $billing['state'] ?? '',
                $billing['postcode'] ?? '',
                $billing['country'] ?? '',
            ) ) );

            $shipping_str = implode( ', ', array_filter( array(
                $shipping['address_1'] ?? '',
                $shipping['city'] ?? '',
                $shipping['state'] ?? '',
                $shipping['postcode'] ?? '',
                $shipping['country'] ?? '',
            ) ) );

            $all_fields = array(
                'order_id'         => '#' . $order->get_id(),
                'date'             => $order->get_date_created() ? $order->get_date_created()->date( 'M j, Y g:i A' ) : '',
                'status'           => ucfirst( str_replace( 'wc-', '', $order->get_status() ) ),
                'customer_name'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                'customer_email'   => $order->get_billing_email(),
                'billing_address'  => $billing_str,
                'shipping_address' => ! empty( $shipping['address_1'] ) ? $shipping_str : $billing_str,
                'phone'            => $order->get_billing_phone(),
                'products'         => implode( '; ', $product_names ),
                'items_count'      => $order->get_item_count(),
                'subtotal'         => $this->sheet_money( (float) $order->get_subtotal() ),
                'shipping_cost'    => $this->sheet_money( (float) $order->get_shipping_total() ),
                'tax'              => $this->sheet_money( (float) $order->get_total_tax() ),
                'discount'         => $this->sheet_money( (float) $order->get_discount_total() ),
                'total'            => $this->sheet_money( $this->order_net_total( $order ) ),
                'payment_method'   => $order->get_payment_method_title(),
                'transaction_id'   => $order->get_transaction_id(),
                'customer_note'    => $order->get_customer_note(),
            );

            foreach ( $fields as $key ) {
                $row[] = $all_fields[ $key ] ?? '';
            }

            $rows[] = $row;
        }

        return $rows;
    }

    // ─── CSV Helper ───────────────────────────────────────────────────────────

    private function rows_to_csv( array $rows ): string {
        $csv = '';
        foreach ( $rows as $row ) {
            $escaped = array_map( function( $cell ) {
                $cell = (string) $cell;
                if ( str_contains( $cell, ',' ) || str_contains( $cell, '"' ) || str_contains( $cell, "\n" ) ) {
                    $cell = '"' . str_replace( '"', '""', $cell ) . '"';
                }
                return $cell;
            }, $row );
            $csv .= implode( ',', $escaped ) . "\n";
        }
        return $csv;
    }

    // ─── Google Sheets Export ─────────────────────────────────────────────────

    /**
     * Net order total (gross minus refunds) for preview, filters, and export rows.
     */
    private function order_net_total( WC_Order $order ): float {
        return max( 0.0, (float) $order->get_total() - (float) $order->get_total_refunded() );
    }

    private function sheet_money( float $amount ): string {
        $sym = html_entity_decode( get_woocommerce_currency_symbol() );
        return $sym . number_format( $amount, 2 );
    }

    /**
     * @return array<int, string>
     */
    private function pad_export_row( string $label, int $cols ): array {
        $row = array( $label );
        while ( count( $row ) < max( 1, $cols ) ) {
            $row[] = '';
        }
        return $row;
    }

    public function export_orders_to_sheets( string $spreadsheet_id, string $sheet_name, array $filters ): array {
        $client = new SheetSync_Sheets_Client();
        $orders = $this->get_filtered_orders( $filters );

        if ( empty( $orders ) ) {
            throw new Exception( __( 'No orders found matching the selected filters.', 'sheetsync-for-woocommerce' ) );
        }

        $fields     = $filters['fields'] ?? array();
        if ( empty( $fields ) ) {
            $fields = $this->get_default_fields();
        }
        $order_rows = $this->build_order_rows( $orders, $fields );
        $header     = array_shift( $order_rows );
        $col_count  = max( 6, count( $header ) );
        $map        = array();
        $rows       = array();

        $total_revenue = 0.0;
        foreach ( $orders as $order ) {
            if ( $order instanceof WC_Order ) {
                $total_revenue += $this->order_net_total( $order );
            }
        }

        $map['title'] = count( $rows );
        $rows[]       = $this->pad_export_row( 'SheetSync — Bulk Order Export', $col_count );
        $map['meta']  = count( $rows );
        $rows[]       = $this->pad_export_row(
            'Generated: ' . wp_date( 'M j, Y g:i A' ) . ' · Total Orders: ' . count( $orders ) . ' · Revenue: ' . $this->sheet_money( $total_revenue ),
            $col_count
        );
        $rows[] = $this->pad_export_row( '', $col_count );

        $map['summary_header'] = count( $rows );
        $rows[]                = $this->pad_export_row( 'EXPORT SUMMARY', $col_count );
        $map['summary_col_header'] = count( $rows );
        $rows[]                = $this->pad_export_row( 'Total Orders', $col_count );
        $rows[ count( $rows ) - 1 ][1] = 'Total Revenue';
        $rows[ count( $rows ) - 1 ][2] = 'Avg. Order Value';
        $map['summary_data']   = count( $rows );
        $avg                   = count( $orders ) > 0 ? $total_revenue / count( $orders ) : 0;
        $rows[]                = array(
            count( $orders ),
            $this->sheet_money( $total_revenue ),
            $this->sheet_money( $avg ),
        );
        while ( count( $rows[ count( $rows ) - 1 ] ) < $col_count ) {
            $rows[ count( $rows ) - 1 ][] = '';
        }
        $rows[] = $this->pad_export_row( '', $col_count );

        $map['orders_header']     = count( $rows );
        $rows[]                   = $this->pad_export_row( 'EXPORTED ORDERS', $col_count );
        $map['orders_col_header'] = count( $rows );
        $rows[]                   = $header;
        while ( count( $rows[ count( $rows ) - 1 ] ) < $col_count ) {
            $rows[ count( $rows ) - 1 ][] = '';
        }

        $map['orders_data_start']  = count( $rows );
        $map['order_row_status']   = array();
        $status_col                = array_search( 'status', $fields, true );

        foreach ( $order_rows as $i => $row ) {
            while ( count( $row ) < $col_count ) {
                $row[] = '';
            }
            $rows[] = $row;
            if ( isset( $orders[ $i ] ) && $orders[ $i ] instanceof WC_Order ) {
                $map['order_row_status'][ count( $rows ) - 1 ] = array(
                    'slug' => $orders[ $i ]->get_status(),
                    'col'  => $status_col !== false ? (int) $status_col : -1,
                );
            }
        }
        $map['orders_data_end'] = count( $rows ) - 1;
        $map['col_count']       = $col_count;
        $map['status_col']      = $status_col;

        $range = $sheet_name . '!A1';
        $client->ensure_sheet_exists( $spreadsheet_id, $sheet_name );
        $client->clear_sheet( $spreadsheet_id, $sheet_name );
        $client->set_rows( $spreadsheet_id, $range, $rows );

        try {
            $sheet_id = $client->get_sheet_id( $spreadsheet_id, $sheet_name );
            $this->apply_order_export_formatting( $client, $spreadsheet_id, $sheet_id, $map );
        } catch ( Exception $e ) {
            SheetSync_Logger::info( 'Bulk Order Export styling skipped: ' . $e->getMessage(), null, 0 );
        }

        SheetSync_Logger::info(
            sprintf( 'Bulk Order Export: %d orders exported to sheet "%s".', count( $orders ), $sheet_name ),
            null,
            count( $rows )
        );

        return array(
            'message'      => sprintf(
                /* translators: %d order count */
                __( '%d orders exported successfully!', 'sheetsync-for-woocommerce' ),
                count( $orders )
            ),
            'order_count'  => count( $orders ),
            'rows_written' => count( $rows ),
            'sheet_url'    => class_exists( 'SheetSync_Dashboard_Enhancements', false )
                ? SheetSync_Dashboard_Enhancements::sheets_url( $spreadsheet_id )
                : 'https://docs.google.com/spreadsheets/d/' . rawurlencode( $spreadsheet_id ) . '/edit',
        );
    }

    /**
     * Apply WooAnalytics-style formatting to bulk order export sheet.
     *
     * @param array<string, mixed> $map
     */
    private function apply_order_export_formatting( SheetSync_Sheets_Client $client, string $spreadsheet_id, int $sheet_id, array $map ): void {
        $requests  = array();
        $max_cols  = max( 6, (int) ( $map['col_count'] ?? 6 ) );

        $color = static fn( float $r, float $g, float $b ) => array(
            'red'   => $r,
            'green' => $g,
            'blue'  => $b,
            'alpha' => 1.0,
        );
        $range_req = static fn( int $sr, int $er, int $sc = 0, ?int $ec = null ) => array(
            'sheetId'          => $sheet_id,
            'startRowIndex'    => $sr,
            'endRowIndex'      => $er + 1,
            'startColumnIndex' => $sc,
            'endColumnIndex'   => $ec ?? $max_cols,
        );

        $purple       = $color( 0.424, 0.388, 1.0 );
        $purple_dark  = $color( 0.165, 0.133, 0.333 );
        $purple_light = $color( 0.93, 0.91, 1.0 );
        $white        = $color( 1, 1, 1 );
        $green_light  = $color( 0.85, 1.0, 0.95 );
        $yellow_light = $color( 1.0, 0.96, 0.85 );
        $red_light    = $color( 1.0, 0.9, 0.9 );

        $requests[] = array(
            'mergeCells' => array(
                'range'     => $range_req( $map['title'], $map['title'], 0, $max_cols ),
                'mergeType' => 'MERGE_ALL',
            ),
        );
        $requests[] = array(
            'repeatCell' => array(
                'range'  => $range_req( $map['title'], $map['title'], 0, $max_cols ),
                'cell'   => array(
                    'userEnteredFormat' => array(
                        'backgroundColor'     => $purple,
                        'textFormat'          => array(
                            'bold'            => true,
                            'fontSize'        => 16,
                            'foregroundColor' => $white,
                        ),
                        'horizontalAlignment' => 'CENTER',
                        'verticalAlignment'   => 'MIDDLE',
                    ),
                ),
                'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment,verticalAlignment)',
            ),
        );

        $requests[] = array(
            'repeatCell' => array(
                'range'  => $range_req( $map['meta'], $map['meta'], 0, $max_cols ),
                'cell'   => array(
                    'userEnteredFormat' => array(
                        'backgroundColor' => $purple_light,
                        'textFormat'      => array(
                            'fontSize'        => 10,
                            'foregroundColor' => $purple_dark,
                        ),
                    ),
                ),
                'fields' => 'userEnteredFormat(backgroundColor,textFormat)',
            ),
        );

        foreach ( array( 'summary_header', 'orders_header' ) as $key ) {
            if ( ! isset( $map[ $key ] ) ) {
                continue;
            }
            $sr = (int) $map[ $key ];
            $requests[] = array(
                'mergeCells' => array(
                    'range'     => $range_req( $sr, $sr, 0, $max_cols ),
                    'mergeType' => 'MERGE_ALL',
                ),
            );
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => $range_req( $sr, $sr, 0, $max_cols ),
                    'cell'   => array(
                        'userEnteredFormat' => array(
                            'backgroundColor' => $purple_dark,
                            'textFormat'      => array(
                                'bold'            => true,
                                'fontSize'        => 12,
                                'foregroundColor' => $white,
                            ),
                        ),
                    ),
                    'fields' => 'userEnteredFormat(backgroundColor,textFormat)',
                ),
            );
        }

        foreach ( array( 'summary_col_header', 'orders_col_header' ) as $key ) {
            if ( ! isset( $map[ $key ] ) ) {
                continue;
            }
            $cr = (int) $map[ $key ];
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => $range_req( $cr, $cr, 0, $max_cols ),
                    'cell'   => array(
                        'userEnteredFormat' => array(
                            'backgroundColor'     => $purple,
                            'textFormat'          => array(
                                'bold'            => true,
                                'foregroundColor' => $white,
                            ),
                            'horizontalAlignment' => 'CENTER',
                        ),
                    ),
                    'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment)',
                ),
            );
        }

        if ( isset( $map['summary_data'] ) ) {
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => $range_req( (int) $map['summary_data'], (int) $map['summary_data'], 0, 3 ),
                    'cell'   => array(
                        'userEnteredFormat' => array(
                            'backgroundColor'     => $green_light,
                            'textFormat'          => array( 'bold' => true, 'fontSize' => 11 ),
                            'horizontalAlignment' => 'CENTER',
                        ),
                    ),
                    'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment)',
                ),
            );
        }

        if ( isset( $map['orders_data_start'], $map['orders_data_end'] )
            && $map['orders_data_start'] <= $map['orders_data_end'] ) {
            for ( $i = (int) $map['orders_data_start']; $i <= (int) $map['orders_data_end']; $i++ ) {
                $bg = ( ( $i - (int) $map['orders_data_start'] ) % 2 === 0 )
                    ? $color( 0.97, 0.97, 0.99 )
                    : $white;
                $requests[] = array(
                    'repeatCell' => array(
                        'range'  => $range_req( $i, $i, 0, $max_cols ),
                        'cell'   => array( 'userEnteredFormat' => array( 'backgroundColor' => $bg ) ),
                        'fields' => 'userEnteredFormat(backgroundColor)',
                    ),
                );
            }
        }

        if ( ! empty( $map['order_row_status'] ) && is_array( $map['order_row_status'] ) ) {
            foreach ( $map['order_row_status'] as $row_idx => $meta ) {
                $col = (int) ( $meta['col'] ?? -1 );
                if ( $col < 0 ) {
                    continue;
                }
                $slug = (string) ( $meta['slug'] ?? '' );
                $bg   = $white;
                if ( in_array( $slug, array( 'completed', 'processing' ), true ) ) {
                    $bg = $green_light;
                } elseif ( in_array( $slug, array( 'on-hold', 'pending' ), true ) ) {
                    $bg = $yellow_light;
                } elseif ( in_array( $slug, array( 'cancelled', 'failed', 'refunded', 'trash' ), true ) ) {
                    $bg = $red_light;
                }
                $requests[] = array(
                    'repeatCell' => array(
                        'range'  => $range_req( (int) $row_idx, (int) $row_idx, $col, $col + 1 ),
                        'cell'   => array(
                            'userEnteredFormat' => array(
                                'backgroundColor'     => $bg,
                                'textFormat'          => array( 'bold' => true ),
                                'horizontalAlignment' => 'CENTER',
                            ),
                        ),
                        'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment)',
                    ),
                );
            }
        }

        $default_widths = array( 90, 160, 140, 220, 220, 120, 120, 100, 120, 140, 120, 120, 120, 100, 160 );
        for ( $ci = 0; $ci < $max_cols; $ci++ ) {
            $px = $default_widths[ $ci ] ?? 120;
            $requests[] = array(
                'updateDimensionProperties' => array(
                    'range'      => array(
                        'sheetId'    => $sheet_id,
                        'dimension'  => 'COLUMNS',
                        'startIndex' => $ci,
                        'endIndex'   => $ci + 1,
                    ),
                    'properties' => array( 'pixelSize' => $px ),
                    'fields'     => 'pixelSize',
                ),
            );
        }

        $requests[] = array(
            'updateSheetProperties' => array(
                'properties' => array(
                    'sheetId'        => $sheet_id,
                    'gridProperties' => array( 'frozenRowCount' => 1 ),
                ),
                'fields' => 'gridProperties.frozenRowCount',
            ),
        );

        if ( ! empty( $requests ) ) {
            $client->batch_update_requests( $spreadsheet_id, $requests );
        }
    }
}
