<?php
/**
 * Dashboard Phase 2: tooltips data, PDF, search, roles, goal, demo, reorder.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Dashboard_Phase2' ) ) :

class SheetSync_Dashboard_Phase2 {

    public const DASH_SALES     = 'sales';
    public const DASH_INVENTORY = 'inventory';
    public const DASH_ORDERS    = 'orders';

    public function __construct() {
        add_action( 'wp_ajax_sheetsync_global_dashboard_search', array( $this, 'ajax_global_search' ) );
        add_action( 'wp_ajax_sheetsync_download_export_log_csv', array( $this, 'ajax_download_export_log_csv' ) );
        add_action( 'wp_ajax_sheetsync_dashboard_pdf_report', array( $this, 'ajax_pdf_report' ) );
        add_action( 'wp_ajax_sheetsync_toggle_demo_mode', array( $this, 'ajax_toggle_demo_mode' ) );
    }

    /**
     * @return array<string, mixed>
     */
    public static function settings_defaults(): array {
        return array(
            'demo_mode'              => '0',
            'access_sales_roles'     => 'administrator,shop_manager',
            'access_inventory_roles' => 'administrator,shop_manager',
            'access_orders_roles'    => 'administrator,shop_manager',
        );
    }

    public static function is_demo_mode(): bool {
        $settings = SheetSync_Dashboard_Enhancements::get_settings();
        return ! empty( $settings['demo_mode'] ) && $settings['demo_mode'] === '1';
    }

    /**
     * Block Google Sheets / CSV exports while demo preview is active.
     */
    public static function reject_demo_export(): void {
        if ( ! self::is_demo_mode() ) {
            return;
        }

        wp_send_json_error(
            array(
                'message'   => __( 'Turn off Demo Mode to export live store data. Demo preview is for on-screen display only.', 'sheetsync-for-woocommerce' ),
                'demo_mode' => true,
            ),
            400
        );
    }

    /**
     * @return string[]
     */
    public static function allowed_roles_for( string $dashboard ): array {
        $settings = SheetSync_Dashboard_Enhancements::get_settings();
        $key      = 'access_' . sanitize_key( $dashboard ) . '_roles';
        $raw      = $settings[ $key ] ?? self::settings_defaults()[ $key ] ?? 'administrator,shop_manager';
        $roles    = array_map( 'trim', explode( ',', (string) $raw ) );
        return array_values( array_filter( array_map( 'sanitize_key', $roles ) ) );
    }

    public static function user_can( string $dashboard ): bool {
        if ( ! is_user_logged_in() ) {
            return false;
        }

        if ( user_can( get_current_user_id(), 'manage_options' ) ) {
            return true;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_shop_orders' ) ) {
            return false;
        }

        $user  = wp_get_current_user();
        $allow = self::allowed_roles_for( $dashboard );

        foreach ( (array) $user->roles as $role ) {
            if ( in_array( $role, $allow, true ) ) {
                return true;
            }
        }

        return false;
    }

    public static function require_dashboard( string $dashboard ): void {
        if ( ! self::user_can( $dashboard ) ) {
            wp_send_json_error(
                array( 'message' => __( 'You do not have permission to access this dashboard.', 'sheetsync-for-woocommerce' ) ),
                403
            );
        }
    }

    /**
     * @return array<string, bool>
     */
    public static function access_map_for_current_user(): array {
        return array(
            self::DASH_SALES     => self::user_can( self::DASH_SALES ),
            self::DASH_INVENTORY => self::user_can( self::DASH_INVENTORY ),
            self::DASH_ORDERS    => self::user_can( self::DASH_ORDERS ),
        );
    }

    /**
     * @return array<string, string|null>|null
     */
    public static function get_monthly_goal_progress(): ?array {
        $settings = SheetSync_Dashboard_Enhancements::get_settings();
        $goal     = (float) ( $settings['sd_monthly_goal'] ?? 0 );
        if ( $goal <= 0 ) {
            return null;
        }

        if ( self::is_demo_mode() ) {
            $actual = round( $goal * 0.62, 2 );
            return self::format_goal_progress( $goal, $actual );
        }

        $start = wp_date( 'Y-m-01 00:00:00' );
        $end   = wp_date( 'Y-m-t 23:59:59' );
        $ids   = self::get_paid_order_ids_in_range( $start, $end );

        $actual = 0.0;
        foreach ( $ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                continue;
            }
            $actual += max( 0, (float) $order->get_total() - (float) $order->get_total_refunded() );
        }

        return self::format_goal_progress( $goal, round( $actual, 2 ) );
    }

    /**
     * @return array<string, string|float>
     */
    private static function format_goal_progress( float $goal, float $actual ): array {
        $pct = $goal > 0 ? min( 100, round( ( $actual / $goal ) * 100, 1 ) ) : 0;

        return array(
            'goal'      => $goal,
            'actual'    => $actual,
            'pct'       => $pct,
            'remaining' => max( 0, round( $goal - $actual, 2 ) ),
            'month'     => wp_date( 'F Y' ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function enrich_sales_response( array $data ): array {
        $data['monthly_goal_progress'] = self::get_monthly_goal_progress();
        $data['is_demo']               = self::is_demo_mode();
        $data['dashboard_access']      = self::access_map_for_current_user();
        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function enrich_inventory_response( array $data, int $threshold ): array {
        $data['reorder_suggestions'] = self::get_reorder_suggestions( $data['all_products'] ?? array(), $threshold );
        $data['is_demo']             = self::is_demo_mode();
        $data['dashboard_access']    = self::access_map_for_current_user();
        return $data;
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>>
     */
    public static function get_reorder_suggestions( array $products, int $threshold ): array {
        $sold_map = self::get_product_sold_counts_30d();
        $out      = array();

        foreach ( $products as $p ) {
            $status = $p['status'] ?? '';
            if ( ! in_array( $status, array( 'low_stock', 'outofstock' ), true ) ) {
                continue;
            }

            $current   = (int) ( $p['stock_qty'] ?? 0 );
            $sold_30   = (int) ( $sold_map[ (int) ( $p['id'] ?? 0 ) ] ?? 0 );
            $velocity  = max( 1, (int) ceil( $sold_30 / 30 * 14 ) );
            $target    = max( $threshold * 2, $velocity );
            $suggested = max( 1, $target - max( 0, $current ) );

            if ( $status === 'outofstock' ) {
                $suggested = max( $suggested, $threshold * 2 );
            }

            $out[] = array(
                'id'                    => (int) ( $p['id'] ?? 0 ),
                'name'                  => $p['name'] ?? '',
                'sku'                   => $p['sku'] ?? '',
                'category'              => $p['category'] ?? '',
                'current_stock'         => $current,
                'sold_30d'              => $sold_30,
                'suggested_reorder_qty' => $suggested,
                'status'                => $status,
                'edit_url'              => $p['edit_url'] ?? '',
                'priority'              => $status === 'outofstock' ? 2 : 1,
            );
        }

        usort(
            $out,
            function ( $a, $b ) {
                if ( $a['priority'] !== $b['priority'] ) {
                    return $b['priority'] <=> $a['priority'];
                }
                return $b['sold_30d'] <=> $a['sold_30d'];
            }
        );

        return array_slice( $out, 0, 25 );
    }

    /**
     * @return array<int, int> product_id => qty sold
     */
    private static function get_product_sold_counts_30d(): array {
        $cache_key = 'ss_dash_sold_30d_' . wp_date( 'Y-m-d' );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $from = wp_date( 'Y-m-d 00:00:00', strtotime( '-30 days' ) );
        $to   = wp_date( 'Y-m-d 23:59:59' );
        $ids  = self::get_paid_order_ids_in_range( $from, $to );

        $map = array();
        foreach ( $ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                continue;
            }
            foreach ( $order->get_items() as $item ) {
                $pid = (int) $item->get_product_id();
                if ( $pid <= 0 ) {
                    continue;
                }
                $map[ $pid ] = ( $map[ $pid ] ?? 0 ) + (int) $item->get_quantity();
            }
        }

        set_transient( $cache_key, $map, 15 * MINUTE_IN_SECONDS );
        return $map;
    }

    /**
     * @return int[]
     */
    private static function get_paid_order_ids_in_range( string $start, string $end ): array {
        $statuses = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'completed', 'processing' );
        $all_ids  = array();
        $page     = 1;
        $per_page = (int) apply_filters( 'sheetsync_dashboard_orders_per_page', 500 );
        $per_page = max( 100, min( 1000, $per_page ) );
        $max_page = (int) apply_filters( 'sheetsync_dashboard_orders_max_pages', 400 );

        do {
            $batch = wc_get_orders(
                array(
                    'status'       => $statuses,
                    'date_created' => $start . '...' . $end,
                    'limit'        => $per_page,
                    'page'         => $page,
                    'return'       => 'ids',
                    'orderby'      => 'date',
                    'order'        => 'DESC',
                )
            );

            if ( empty( $batch ) ) {
                break;
            }

            $all_ids = array_merge( $all_ids, $batch );

            if ( count( $batch ) < $per_page ) {
                break;
            }

            ++$page;
        } while ( $page <= $max_page );

        return $all_ids;
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_demo_sales_data( string $period = '7days' ): array {
        $sym = html_entity_decode( get_woocommerce_currency_symbol() );

        $daily = array();
        for ( $i = 6; $i >= 0; $i-- ) {
            $rev = 1200 + ( $i * 180 ) + wp_rand( 50, 400 );
            $daily[] = array(
                'date'    => wp_date( 'M j', strtotime( "-{$i} days" ) ),
                'revenue' => (float) $rev,
                'orders'  => wp_rand( 3, 12 ),
                'profit'  => round( $rev * 0.35, 2 ),
            );
        }

        $summary = array(
            'net_sales'            => 12450.00,
            'total_revenue'        => 12450.00,
            'net_profit'           => 4357.50,
            'total_orders'         => 48,
            'paid_orders'          => 42,
            'avg_order_value'      => 296.43,
            'total_customers'      => 36,
            'returning_customers'  => 14,
            'repeat_purchase_rate' => 38.9,
            'total_refunds'        => 320.00,
            'total_items'          => 96,
            'pending_revenue'      => 890.00,
            'gross_order_value'    => 13340.00,
            'period_label'         => __( 'Demo period', 'sheetsync-for-woocommerce' ),
        );

        $cmp = function ( $cur, $prev ) {
            return array( 'current' => $cur, 'previous' => $prev, 'change' => 12.5, 'trend' => 'up' );
        };

        return array(
            'monthly'           => array(),
            'daily'             => $daily,
            'weekly'            => array(),
            'yearly'            => array(),
            'top_products'      => array(
                array( 'name' => 'Demo Hoodie', 'quantity' => 18, 'revenue' => 3600 ),
                array( 'name' => 'Demo T-Shirt', 'quantity' => 24, 'revenue' => 2400 ),
            ),
            'summary'           => $summary,
            'comparison'        => array(
                'net_sales'       => $cmp( 12450, 11080 ),
                'net_profit'      => $cmp( 4357, 3900 ),
                'total_orders'    => $cmp( 48, 44 ),
                'avg_order_value' => $cmp( 296, 280 ),
                'total_customers' => $cmp( 36, 30 ),
                'returning_customers' => $cmp( 14, 11 ),
                'conversion_rate' => $cmp( 38.9, 34.2 ),
                'total_refunds'   => $cmp( 320, 410 ),
                'total_items'     => $cmp( 96, 88 ),
            ),
            'quick_stats'       => array(),
            'top_categories'    => array(
                array( 'name' => 'Clothing', 'revenue' => 8200, 'orders' => 32, 'share' => 66 ),
            ),
            'payment_methods'   => array(
                array( 'name' => 'Cash on Delivery', 'revenue' => 7200, 'orders' => 28, 'share' => 58 ),
                array( 'name' => 'bKash', 'revenue' => 5250, 'orders' => 20, 'share' => 42 ),
            ),
            'order_statuses'    => array(),
            'recent_orders'     => array(
                array(
                    'id'       => 1001,
                    'number'   => '1001',
                    'date'     => wp_date( 'M j, Y g:i A' ),
                    'customer' => 'Demo Customer',
                    'products' => 'Demo Hoodie × 1',
                    'status'   => 'Completed',
                    'total'    => 450,
                ),
            ),
            'geo_sales'         => array(
                array( 'country_code' => 'BD', 'name' => 'Bangladesh', 'revenue' => 9800, 'orders' => 38, 'share' => 79 ),
            ),
            'geo_cities'        => array(),
            'geo_summary'       => array( 'countries' => 1, 'cities' => 2 ),
            'customer_trend'    => array(),
            'insights'          => array(
                array( 'type' => 'positive', 'text' => __( 'Demo: Revenue is up 12.5% vs previous period.', 'sheetsync-for-woocommerce' ) ),
            ),
            'sparklines'        => array(
                'revenue' => array_column( $daily, 'revenue' ),
                'orders'  => array_column( $daily, 'orders' ),
            ),
            'forecast'          => array( 'historical' => array(), 'predicted' => array() ),
            'funnel'            => array(),
            'inventory'         => array( 'low_stock' => 3, 'out_of_stock' => 1, 'total_products' => 42 ),
            'filters'           => array( 'categories' => array(), 'products' => array(), 'countries' => array(), 'payments' => array(), 'statuses' => array() ),
            'active_filters'    => array(),
            'currency_symbol'   => $sym,
            'currency'          => get_woocommerce_currency(),
            'generated_at'      => wp_date( 'M j, Y g:i A' ),
            'period'            => $period,
            'user'              => array(
                'name'     => wp_get_current_user()->display_name,
                'role'     => 'Demo',
                'initials' => 'DM',
            ),
            'pending_orders'    => 2,
            'is_demo'           => true,
            'monthly_goal_progress' => self::get_monthly_goal_progress(),
            'dashboard_access'  => self::access_map_for_current_user(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_demo_inventory_data( int $threshold = 5 ): array {
        $products = array(
            array( 'id' => 901, 'name' => 'Demo Hoodie', 'sku' => 'DEMO-H01', 'category' => 'Clothing', 'price' => 1200, 'stock_qty' => 2, 'status' => 'low_stock', 'edit_url' => '#' ),
            array( 'id' => 902, 'name' => 'Demo Mug', 'sku' => 'DEMO-M01', 'category' => 'Accessories', 'price' => 350, 'stock_qty' => 0, 'status' => 'outofstock', 'edit_url' => '#' ),
            array( 'id' => 903, 'name' => 'Demo Cap', 'sku' => 'DEMO-C01', 'category' => 'Clothing', 'price' => 450, 'stock_qty' => 18, 'status' => 'instock', 'edit_url' => '#' ),
        );

        $summary = array(
            'total_products'   => 3,
            'in_stock'         => 1,
            'low_stock'        => 1,
            'out_of_stock'     => 1,
            'total_stock_value'=> 9600,
        );

        $data = array(
            'all_products'        => $products,
            'low_stock'           => array( $products[0] ),
            'out_of_stock'        => array( $products[1] ),
            'categories'          => array(
                array( 'name' => 'Clothing', 'total' => 2, 'in_stock' => 1, 'low_stock' => 1, 'out_of_stock' => 0 ),
            ),
            'summary'             => $summary,
            'currency_symbol'     => html_entity_decode( get_woocommerce_currency_symbol() ),
            'generated_at'        => wp_date( 'M j, Y g:i A' ),
            'low_stock_threshold' => $threshold,
            'is_demo'             => true,
        );

        $data['reorder_suggestions'] = self::get_reorder_suggestions( $products, $threshold );
        $data['dashboard_access']    = self::access_map_for_current_user();
        return $data;
    }

    public function ajax_global_search(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        self::require_dashboard( self::DASH_SALES );

        $q = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
        if ( strlen( $q ) < 2 ) {
            wp_send_json_success( array( 'results' => array() ) );
        }

        wp_send_json_success( array( 'results' => $this->run_global_search( $q ) ) );
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function run_global_search( string $q ): array {
        $results = array();

        if ( self::user_can( self::DASH_ORDERS ) ) {
            $order_args = array(
                'limit'   => 6,
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'objects',
            );

            if ( is_numeric( $q ) ) {
                $order_args['include'] = array( absint( $q ) );
            } elseif ( str_contains( $q, '@' ) ) {
                $order_args['billing_email'] = $q;
            } else {
                $order_args['search'] = $q;
            }

            $orders = wc_get_orders( $order_args );
            foreach ( $orders as $order ) {
                if ( ! $order instanceof WC_Order ) {
                    continue;
                }
                $results[] = array(
                    'type'  => 'order',
                    'tab'   => self::DASH_ORDERS,
                    'label' => sprintf( '#%s — %s', $order->get_order_number(), trim( $order->get_formatted_billing_full_name() ) ?: __( 'Guest', 'sheetsync-for-woocommerce' ) ),
                    'meta'  => wc_get_order_status_name( $order->get_status() ) . ' · ' . wp_strip_all_tags( wc_price( $order->get_total() ) ),
                    'url'   => $order->get_edit_order_url(),
                );
            }
        }

        if ( self::user_can( self::DASH_INVENTORY ) ) {
            $products = wc_get_products(
                array(
                    'limit'  => 6,
                    'status' => 'publish',
                    'search' => $q,
                )
            );

            foreach ( $products as $product ) {
                $results[] = array(
                    'type'  => 'product',
                    'tab'   => self::DASH_INVENTORY,
                    'label' => $product->get_name(),
                    'meta'  => ( $product->get_sku() ?: __( 'No SKU', 'sheetsync-for-woocommerce' ) ) . ' · Qty ' . ( $product->get_stock_quantity() ?? '—' ),
                    'url'   => get_edit_post_link( $product->get_id(), 'raw' ) ?: '',
                );
            }
        }

        return array_slice( $results, 0, 12 );
    }

    public function ajax_download_export_log_csv(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $log = get_option( SheetSync_Dashboard_Enhancements::LOG_OPTION, array() );
        if ( ! is_array( $log ) ) {
            $log = array();
        }

        $rows   = array( array( 'Type', 'Success', 'Message', 'User', 'Time' ) );
        foreach ( $log as $entry ) {
            $rows[] = array(
                $entry['type'] ?? '',
                ! empty( $entry['success'] ) ? 'yes' : 'no',
                $entry['message'] ?? '',
                $entry['user_name'] ?? '',
                $entry['time'] ?? '',
            );
        }

        $csv = '';
        foreach ( $rows as $row ) {
            $csv .= implode(
                ',',
                array_map(
                    static function ( $cell ) {
                        $cell = (string) $cell;
                        if ( str_contains( $cell, ',' ) || str_contains( $cell, '"' ) || str_contains( $cell, "\n" ) ) {
                            $cell = '"' . str_replace( '"', '""', $cell ) . '"';
                        }
                        return $cell;
                    },
                    $row
                )
            ) . "\n";
        }

        wp_send_json_success(
            array(
                'csv'      => $csv,
                'filename' => 'sheetsync-export-history-' . wp_date( 'Y-m-d' ) . '.csv',
            )
        );
    }

    public function ajax_pdf_report(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );

        $type   = sanitize_key( wp_unslash( $_POST['report_type'] ?? 'sales' ) );
        $period = sanitize_text_field( wp_unslash( $_POST['period'] ?? '7days' ) );

        if ( $type === 'inventory' ) {
            self::require_dashboard( self::DASH_INVENTORY );
            $threshold = absint( $_POST['low_stock_threshold'] ?? 5 );
            if ( self::is_demo_mode() ) {
                $data = self::get_demo_inventory_data( $threshold );
            } else {
                $data = ( new SheetSync_Inventory_Dashboard() )->get_inventory_data( $threshold );
            }
            $html = self::build_inventory_pdf_html( $data );
        } else {
            self::require_dashboard( self::DASH_SALES );
            if ( self::is_demo_mode() ) {
                $data = self::get_demo_sales_data( $period );
            } else {
                $filters = array();
                if ( class_exists( 'SheetSync_Sales_Dashboard', false ) ) {
                    $dash = new SheetSync_Sales_Dashboard();
                    $data = $dash->get_dashboard_data( $period, $filters );
                } else {
                    wp_send_json_error( array( 'message' => __( 'Sales dashboard unavailable.', 'sheetsync-for-woocommerce' ) ) );
                }
            }
            $html = self::build_sales_pdf_html( $data, $period );
        }

        wp_send_json_success(
            array(
                'html'     => $html,
                'filename' => 'sheetsync-' . $type . '-report-' . wp_date( 'Y-m-d' ) . '.html',
            )
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function build_sales_pdf_html( array $data, string $period ): string {
        $s   = $data['summary'] ?? array();
        $sym = $data['currency_symbol'] ?? '$';

        ob_start();
        ?>
        <!DOCTYPE html>
        <html><head><meta charset="utf-8"><title><?php esc_html_e( 'Sales Report', 'sheetsync-for-woocommerce' ); ?></title>
        <style>
            body{font-family:Arial,sans-serif;color:#111;padding:24px;font-size:13px}
            h1{margin:0 0 4px;font-size:22px} .sub{color:#666;margin-bottom:20px}
            table{width:100%;border-collapse:collapse;margin:16px 0} th,td{border:1px solid #ddd;padding:8px;text-align:left}
            th{background:#f5f5f5}.kpi{display:inline-block;width:23%;margin:0 1% 12px 0;padding:12px;border:1px solid #eee;border-radius:8px;vertical-align:top}
            .kpi strong{display:block;font-size:18px;margin-top:4px} @media print{body{padding:0}}
        </style></head><body onload="window.print()">
        <h1><?php esc_html_e( 'SheetSync Sales Report', 'sheetsync-for-woocommerce' ); ?></h1>
        <div class="sub"><?php echo esc_html( wp_date( 'M j, Y g:i A' ) ); ?> · <?php echo esc_html( $period ); ?><?php echo ! empty( $data['is_demo'] ) ? ' · DEMO' : ''; ?></div>
        <div>
            <?php
            $kpis = array(
                __( 'Net Sales', 'sheetsync-for-woocommerce' )       => $sym . number_format( (float) ( $s['net_sales'] ?? 0 ), 2 ),
                __( 'Total Orders', 'sheetsync-for-woocommerce' )     => (int) ( $s['total_orders'] ?? 0 ),
                __( 'Avg. Order', 'sheetsync-for-woocommerce' )       => $sym . number_format( (float) ( $s['avg_order_value'] ?? 0 ), 2 ),
                __( 'Customers', 'sheetsync-for-woocommerce' )       => (int) ( $s['total_customers'] ?? 0 ),
            );
            foreach ( $kpis as $label => $val ) {
                echo '<div class="kpi"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( (string) $val ) . '</strong></div>';
            }
            ?>
        </div>
        <h2><?php esc_html_e( 'Top Products', 'sheetsync-for-woocommerce' ); ?></h2>
        <table><thead><tr><th><?php esc_html_e( 'Product', 'sheetsync-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Qty', 'sheetsync-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Revenue', 'sheetsync-for-woocommerce' ); ?></th></tr></thead><tbody>
        <?php foreach ( array_slice( $data['top_products'] ?? array(), 0, 10 ) as $p ) : ?>
            <tr><td><?php echo esc_html( $p['name'] ?? '' ); ?></td><td><?php echo esc_html( (string) ( $p['quantity'] ?? 0 ) ); ?></td><td><?php echo esc_html( $sym . number_format( (float) ( $p['revenue'] ?? 0 ), 2 ) ); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        </body></html>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function build_inventory_pdf_html( array $data ): string {
        $s   = $data['summary'] ?? array();
        $sym = $data['currency_symbol'] ?? '$';

        ob_start();
        ?>
        <!DOCTYPE html>
        <html><head><meta charset="utf-8"><title><?php esc_html_e( 'Inventory Report', 'sheetsync-for-woocommerce' ); ?></title>
        <style>
            body{font-family:Arial,sans-serif;color:#111;padding:24px;font-size:13px}
            h1{margin:0 0 4px;font-size:22px} .sub{color:#666;margin-bottom:20px}
            table{width:100%;border-collapse:collapse;margin:16px 0} th,td{border:1px solid #ddd;padding:8px;text-align:left}
            th{background:#f5f5f5} @media print{body{padding:0}}
        </style></head><body onload="window.print()">
        <h1><?php esc_html_e( 'SheetSync Inventory Report', 'sheetsync-for-woocommerce' ); ?></h1>
        <div class="sub"><?php echo esc_html( wp_date( 'M j, Y g:i A' ) ); ?><?php echo ! empty( $data['is_demo'] ) ? ' · DEMO' : ''; ?></div>
        <p><?php esc_html_e( 'Total products', 'sheetsync-for-woocommerce' ); ?>: <strong><?php echo esc_html( (string) ( $s['total_products'] ?? 0 ) ); ?></strong> ·
        <?php esc_html_e( 'Low stock', 'sheetsync-for-woocommerce' ); ?>: <strong><?php echo esc_html( (string) ( $s['low_stock'] ?? 0 ) ); ?></strong> ·
        <?php esc_html_e( 'Out of stock', 'sheetsync-for-woocommerce' ); ?>: <strong><?php echo esc_html( (string) ( $s['out_of_stock'] ?? 0 ) ); ?></strong></p>
        <h2><?php esc_html_e( 'Reorder Suggestions', 'sheetsync-for-woocommerce' ); ?></h2>
        <table><thead><tr><th><?php esc_html_e( 'Product', 'sheetsync-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Stock', 'sheetsync-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Sold 30d', 'sheetsync-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Suggested Qty', 'sheetsync-for-woocommerce' ); ?></th></tr></thead><tbody>
        <?php foreach ( array_slice( $data['reorder_suggestions'] ?? array(), 0, 15 ) as $r ) : ?>
            <tr><td><?php echo esc_html( $r['name'] ?? '' ); ?></td><td><?php echo esc_html( (string) ( $r['current_stock'] ?? 0 ) ); ?></td><td><?php echo esc_html( (string) ( $r['sold_30d'] ?? 0 ) ); ?></td><td><?php echo esc_html( (string) ( $r['suggested_reorder_qty'] ?? 0 ) ); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        </body></html>
        <?php
        return (string) ob_get_clean();
    }

    public function ajax_toggle_demo_mode(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $enabled  = ! empty( $_POST['enabled'] );
        $current  = SheetSync_Dashboard_Enhancements::get_settings();
        $settings = SheetSync_Dashboard_Enhancements::sanitize_settings(
            array_merge(
                $current,
                array( 'demo_mode' => $enabled ? '1' : '0' )
            )
        );
        update_option( 'sheetsync_dashboard_settings', $settings );

        wp_send_json_success( array( 'demo_mode' => $enabled ) );
    }

    /**
     * @return array<string, string>
     */
    public static function kpi_help_texts(): array {
        return array(
            'total_revenue'       => __( 'Net sales from paid orders (Processing + Completed) in the selected period.', 'sheetsync-for-woocommerce' ),
            'net_profit'          => __( 'Net sales minus tax and shipping costs (estimate, not COGS).', 'sheetsync-for-woocommerce' ),
            'total_orders'        => __( 'All reportable orders in period, including cancelled (matches WooCommerce admin count).', 'sheetsync-for-woocommerce' ),
            'avg_order'           => __( 'Net sales divided by number of paid orders.', 'sheetsync-for-woocommerce' ),
            'total_customers'     => __( 'Unique customers (email or user ID) who placed orders in this period.', 'sheetsync-for-woocommerce' ),
            'returning_customers' => __( 'Customers who ordered before this period and ordered again now.', 'sheetsync-for-woocommerce' ),
            'conversion_rate'     => __( 'Repeat purchase rate: returning customers ÷ unique customers.', 'sheetsync-for-woocommerce' ),
            'refunds'             => __( 'Total refunded amount for orders in this period.', 'sheetsync-for-woocommerce' ),
            'products_sold'       => __( 'Sum of line item quantities on paid orders.', 'sheetsync-for-woocommerce' ),
            'inv_total'           => __( 'Published simple and variable products in your catalog.', 'sheetsync-for-woocommerce' ),
            'inv_instock'         => __( 'Products with stock above your low-stock threshold.', 'sheetsync-for-woocommerce' ),
            'inv_low'             => __( 'Products at or below the low-stock threshold.', 'sheetsync-for-woocommerce' ),
            'inv_out'             => __( 'Products marked out of stock or zero quantity.', 'sheetsync-for-woocommerce' ),
            'inv_value'           => __( 'Estimated value: active price × stock quantity. Variable products sum all variation rows.', 'sheetsync-for-woocommerce' ),
            'boe_matching'        => __( 'Orders matching your current export filters.', 'sheetsync-for-woocommerce' ),
            'boe_revenue'         => __( 'Sum of order totals (minus refunds) for filtered orders.', 'sheetsync-for-woocommerce' ),
            'boe_avg'             => __( 'Total revenue ÷ number of matching orders.', 'sheetsync-for-woocommerce' ),
            'boe_fields'          => __( 'Columns that will be included in CSV / Google Sheets export.', 'sheetsync-for-woocommerce' ),
            'monthly_goal'        => __( 'Current calendar month paid revenue vs your goal set in Automation.', 'sheetsync-for-woocommerce' ),
        );
    }
}

endif;
