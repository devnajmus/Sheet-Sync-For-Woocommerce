<?php
/**
 * PRO: Sales Dashboard — WooCommerce analytics + Google Sheets export.
 *
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class SheetSync_Sales_Dashboard {

    /** @var array<int, WC_Order> */
    private array $order_cache = array();

    /** @var array<string, array<string, true>> */
    private array $customer_keys_before_cache = array();

    /** @var array<string, int[]> Dedupe identical wc_get_orders range queries per dashboard load. */
    private array $order_ids_range_cache = array();

    private bool $orders_range_truncated = false;

    private int $orders_range_loaded = 0;

    private int $orders_range_max = 0;

    /**
     * Current site timestamp (respects WordPress timezone).
     */
    private function site_timestamp(): int {
        return (int) current_time( 'timestamp' );
    }

    /**
     * Format a datetime in the store timezone.
     */
    private function site_date( string $format, ?int $timestamp = null ): string {
        return wp_date( $format, $timestamp ?? $this->site_timestamp() );
    }

    /**
     * Local datetime string relative to today (e.g. "-7 days", "monday this week").
     */
    private function site_relative_datetime( string $relative, string $time = '00:00:00' ): string {
        $date = wp_date( 'Y-m-d', strtotime( $relative, $this->site_timestamp() ) );
        return $date . ' ' . $time;
    }

    public function __construct() {
        add_action( 'wp_ajax_sheetsync_export_sales_dashboard', array( $this, 'ajax_export_sales_dashboard' ) );
        add_action( 'wp_ajax_sheetsync_get_sales_preview',      array( $this, 'ajax_get_sales_preview' ) );
        add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'bust_dashboard_cache' ), 20, 0 );
        add_action( 'woocommerce_update_product', array( __CLASS__, 'bust_inventory_cache' ), 20, 0 );
    }

    public static function bust_inventory_cache(): void {
        $version = (int) get_option( 'sheetsync_dashboard_cache_version', 1 );
        update_option( 'sheetsync_dashboard_cache_version', $version + 1, false );
    }

    /**
     * Current dashboard cache generation (incremented on bust).
     */
    public static function dashboard_cache_version(): int {
        return max( 1, (int) get_option( 'sheetsync_dashboard_cache_version', 1 ) );
    }

    // ─── AJAX ─────────────────────────────────────────────────────────────────

    public function ajax_export_sales_dashboard(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( class_exists( 'SheetSync_Dashboard_Phase2', false ) ) {
            SheetSync_Dashboard_Phase2::require_dashboard( SheetSync_Dashboard_Phase2::DASH_SALES );
        } elseif ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $spreadsheet_id = sanitize_text_field( wp_unslash( $_POST['spreadsheet_id'] ?? '' ) );
        $sheet_name     = sanitize_text_field( wp_unslash( $_POST['sheet_name'] ?? 'Sales Dashboard' ) );
        $period         = sanitize_text_field( wp_unslash( $_POST['period'] ?? '12months' ) );
        $filters        = $this->parse_dashboard_filters( $_POST );

        if ( empty( $spreadsheet_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Spreadsheet ID is required.', 'sheetsync-for-woocommerce' ) ) );
        }

        if ( class_exists( 'SheetSync_Dashboard_Phase2', false ) ) {
            SheetSync_Dashboard_Phase2::reject_demo_export();
        }

        try {
            $result = $this->export_to_sheets( $spreadsheet_id, $sheet_name, $period, $filters );
            if ( class_exists( 'SheetSync_Dashboard_Enhancements', false ) ) {
                SheetSync_Dashboard_Enhancements::log_export( 'sales', true, $result['message'] ?? '', $result );
            }
            wp_send_json_success( $result );
        } catch ( \Throwable $e ) {
            if ( class_exists( 'SheetSync_Dashboard_Enhancements', false ) ) {
                SheetSync_Dashboard_Enhancements::log_export( 'sales', false, $e->getMessage(), array() );
            }
            wp_send_json_error( array( 'message' => esc_html( $e->getMessage() ) ) );
        }
    }

    public function ajax_get_sales_preview(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( class_exists( 'SheetSync_Dashboard_Phase2', false ) ) {
            SheetSync_Dashboard_Phase2::require_dashboard( SheetSync_Dashboard_Phase2::DASH_SALES );
        } elseif ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        if ( function_exists( 'wc_set_time_limit' ) ) {
            wc_set_time_limit( 120 );
        } elseif ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        $period  = sanitize_text_field( wp_unslash( $_POST['period'] ?? '12months' ) );
        $filters = $this->parse_dashboard_filters( $_POST );
        $force   = ! empty( $_POST['force_refresh'] );

        try {
            if ( class_exists( 'SheetSync_Dashboard_Phase2', false ) && SheetSync_Dashboard_Phase2::is_demo_mode() ) {
                $data = SheetSync_Dashboard_Phase2::get_demo_sales_data( $period );
            } else {
                $data = $this->get_dashboard_data( $period, $filters, ! $force );
            }
            if ( class_exists( 'SheetSync_Dashboard_Phase2', false ) ) {
                $data = SheetSync_Dashboard_Phase2::enrich_sales_response( $data );
            }
            if ( class_exists( 'SheetSync_Dashboard_Phase3', false ) ) {
                $data = SheetSync_Dashboard_Phase3::enrich_sales_response( $data, $period );
            }
            wp_send_json_success( $data );
        } catch ( \Throwable $e ) {
            wp_send_json_error( array( 'message' => esc_html( $e->getMessage() ) ) );
        }
    }

    // ─── Public helpers (Phase 3 multisite / COGS) ────────────────────────────

    /** @return array{start: string, end: string, label: string} */
    public function get_period_range_public( string $period ): array {
        return $this->get_period_range( $period );
    }

    /** @return int[] */
    public function get_order_ids_in_range_public( string $start, string $end, ?array $statuses = null ): array {
        return $this->get_order_ids_in_range( $start, $end, $statuses );
    }

    /** @return string[] */
    public function get_paid_statuses_public(): array {
        return $this->get_paid_statuses();
    }

    /** @return string[] */
    public function get_reportable_statuses_public(): array {
        return $this->get_reportable_statuses();
    }

    /** @return array<string, float|int> */
    public function aggregate_order_metrics_public( array $order_ids ): array {
        return $this->aggregate_order_metrics( $order_ids );
    }

    // ─── Data Collection ──────────────────────────────────────────────────────

    /**
     * Collect all dashboard data for UI and export.
     *
     * @param array<string, string|int> $filters
     */
    public function get_dashboard_data( string $period = '12months', array $filters = array(), bool $use_cache = true ): array {
        if ( $use_cache ) {
            $cache_key = $this->dashboard_cache_key( $period, $filters );
            $cached    = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                $cached['cached_at'] = $cached['generated_at'] ?? '';
                return $cached;
            }
        }

        $this->order_cache            = array();
        $this->order_ids_range_cache  = array();
        $this->orders_range_truncated = false;
        $this->orders_range_loaded    = 0;
        $this->orders_range_max       = 0;

        $range         = $this->get_period_range( $period );
        $base_paid     = $this->get_order_ids_in_range( $range['start'], $range['end'], $this->get_paid_statuses() );
        $base_all      = $this->get_order_ids_in_range( $range['start'], $range['end'], $this->get_reportable_statuses() );
        $order_ids_revenue = $this->filter_order_ids( $base_paid, $filters );
        $order_ids_all     = $this->filter_order_ids( $base_all, $filters );

        $daily_days  = in_array( $period, array( '7days', '30days' ), true )
            ? ( $period === '7days' ? 7 : 30 )
            : 30;
        $daily        = $this->get_daily_sales( $daily_days, $filters );
        $months       = $this->get_monthly_sales( $period, $filters );
        $summary      = $this->get_summary_stats( $period, $filters, $months );
        $comparison   = $this->get_period_comparison( $period, $filters );
        $top_products = $this->get_top_products( 10, $period, $filters );
        $top_categories = $this->get_top_categories( $order_ids_revenue, 8 );
        $customer_stats = $this->get_customer_counts( $order_ids_all, $range['start'] );
        $summary        = array_merge( $summary, $customer_stats );

        $user = wp_get_current_user();

        $geo_sales  = $this->get_geo_sales( $order_ids_revenue, 8 );
        $geo_cities = $this->get_geo_cities( $order_ids_revenue, 30 );

        $data = array(
            'monthly'           => $months,
            'daily'             => $daily,
            'weekly'            => $this->get_weekly_sales( $daily ),
            'yearly'            => $this->get_yearly_sales( $months ),
            'top_products'      => $top_products,
            'summary'           => $summary,
            'comparison'        => $comparison,
            'quick_stats'       => $this->get_quick_stats( $filters ),
            'top_categories'    => $top_categories,
            'payment_methods'   => $this->get_payment_methods( $order_ids_revenue ),
            'order_statuses'    => $this->get_order_status_breakdown( $order_ids_all ),
            'recent_orders'     => $this->get_recent_orders( $order_ids_all, 50 ),
            'geo_sales'         => $geo_sales,
            'geo_cities'        => $geo_cities,
            'geo_summary'       => $this->get_geo_summary( $order_ids_revenue, $geo_sales, $geo_cities ),
            'customer_trend'    => $this->get_customer_trend( $period, $filters ),
            'insights'          => $this->get_smart_insights( $summary, $top_products, $top_categories, $comparison, $daily ),
            'sparklines'        => $this->get_sparkline_series( $daily ),
            'forecast'          => $this->get_sales_forecast( $daily ),
            'funnel'            => $this->get_order_funnel( $order_ids_all ),
            'inventory'         => $this->get_inventory_snapshot(),
            'filters'           => $this->get_filter_options( $base_paid, $base_all ),
            'active_filters'    => $filters,
            'currency_symbol'   => html_entity_decode( get_woocommerce_currency_symbol() ),
            'currency'          => get_woocommerce_currency(),
            'generated_at'      => $this->site_date( 'M j, Y g:i A' ),
            'period'            => $period,
            'user'              => array(
                'name'     => $user->display_name ?: $user->user_login,
                'role'     => ! empty( $user->roles[0] ) ? ucwords( str_replace( '_', ' ', $user->roles[0] ) ) : __( 'Administrator', 'sheetsync-for-woocommerce' ),
                'initials' => $this->customer_initials( $user->display_name ?: 'A' ),
            ),
            'pending_orders'    => $this->count_pending_orders(),
            'orders_meta'       => array(
                'truncated'     => $this->orders_range_truncated,
                'orders_loaded' => $this->orders_range_loaded,
                'max_orders'    => $this->orders_range_max,
            ),
        );

        if ( $use_cache ) {
            set_transient( $this->dashboard_cache_key( $period, $filters ), $data, $this->dashboard_cache_ttl() );
        }

        return $data;
    }

    private function dashboard_cache_ttl(): int {
        return (int) apply_filters( 'sheetsync_sales_dashboard_cache_ttl', 600 );
    }

  /**
     * @param array<string, string|int> $filters
     */
    private function dashboard_cache_key( string $period, array $filters ): string {
        return 'sheetsync_sd_' . md5(
            wp_json_encode(
                array(
                    'period'  => $period,
                    'filters' => $filters,
                    'blog'    => get_current_blog_id(),
                    'ver'     => self::dashboard_cache_version(),
                )
            )
        );
    }

    public static function bust_dashboard_cache(): void {
        $version = self::dashboard_cache_version();
        update_option( 'sheetsync_dashboard_cache_version', $version + 1, false );
    }

    private function get_low_stock_threshold(): int {
        if ( class_exists( 'SheetSync_Dashboard_Enhancements', false ) ) {
            return max( 1, absint( SheetSync_Dashboard_Enhancements::get_settings()['inv_low_stock'] ?? 5 ) );
        }
        return 5;
    }

    /**
     * Paid order statuses used for sales/revenue reporting.
     *
     * @return string[]
     */
    private function get_paid_statuses(): array {
        if ( function_exists( 'wc_get_is_paid_statuses' ) ) {
            return wc_get_is_paid_statuses();
        }

        return array( 'completed', 'processing' );
    }

    /**
     * All WooCommerce order statuses shown in admin (matches Orders → All).
     *
     * @return string[]
     */
    private function get_reportable_statuses(): array {
        static $statuses = null;

        if ( $statuses === null ) {
            $statuses = array_map(
                static fn( $status ) => str_replace( 'wc-', '', $status ),
                array_keys( wc_get_order_statuses() )
            );
        }

        return $statuses;
    }

    /**
     * Unique customer identifier — registered users by ID; guests by name/phone/email.
     */
    private function customer_key( WC_Order $order ): string {
        $customer_id = (int) $order->get_customer_id();
        if ( $customer_id > 0 ) {
            return 'u:' . $customer_id;
        }

        $first = strtolower( trim( (string) $order->get_billing_first_name() ) );
        $last  = strtolower( trim( (string) $order->get_billing_last_name() ) );
        $name  = trim( $first . ' ' . $last );
        if ( $name === '' ) {
            $name = strtolower( trim( wp_strip_all_tags( $order->get_formatted_billing_full_name() ) ) );
        }

        $phone = preg_replace( '/\D+/', '', (string) $order->get_billing_phone() );
        if ( $name !== '' ) {
            return 'g:' . ( $phone !== '' ? $name . '|' . $phone : $name );
        }

        $email = strtolower( trim( (string) $order->get_billing_email() ) );
        if ( $email !== '' && is_email( $email ) ) {
            return 'e:' . $email;
        }

        return 'o:' . $order->get_id();
    }

    /**
     * @return int[]
     */
    private function get_order_ids_in_range( string $start, string $end, ?array $statuses = null ): array {
        $statuses = $statuses ?? $this->get_paid_statuses();
        sort( $statuses );
        $cache_key = md5( $start . '|' . $end . '|' . implode( ',', $statuses ) );
        if ( isset( $this->order_ids_range_cache[ $cache_key ] ) ) {
            return $this->order_ids_range_cache[ $cache_key ];
        }

        $all_ids  = array();
        $page     = 1;
        $per_page = sheetsync_dashboard_orders_per_page();
        $max_page = sheetsync_dashboard_orders_max_pages();
        $this->orders_range_max = $per_page * $max_page;

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
            $this->orders_range_loaded = count( $all_ids );

            if ( count( $batch ) < $per_page ) {
                break;
            }

            ++$page;
            if ( $page > $max_page ) {
                $this->orders_range_truncated = true;
                break;
            }
        } while ( true );

        $this->order_ids_range_cache[ $cache_key ] = $all_ids;

        return $all_ids;
    }

    /**
     * Parse dashboard filter values from a request array.
     *
     * @param array<string, mixed> $source
     * @return array<string, string|int>
     */
    private function parse_dashboard_filters( array $source ): array {
        return array(
            'category' => sanitize_text_field( wp_unslash( $source['filter_category'] ?? '' ) ),
            'product'  => absint( $source['filter_product'] ?? 0 ),
            'status'   => sanitize_text_field( wp_unslash( $source['filter_status'] ?? '' ) ),
            'payment'  => sanitize_text_field( wp_unslash( $source['filter_payment'] ?? '' ) ),
            'country'  => sanitize_text_field( wp_unslash( $source['filter_country'] ?? '' ) ),
        );
    }

    /**
     * @param array<string, string|int> $filters
     */
    private function filters_are_active( array $filters ): bool {
        return ( $filters['category'] ?? '' ) !== ''
            || (int) ( $filters['product'] ?? 0 ) > 0
            || ( $filters['status'] ?? '' ) !== ''
            || ( $filters['payment'] ?? '' ) !== ''
            || ( $filters['country'] ?? '' ) !== '';
    }

    /**
     * @param array<string, string|int> $filters
     * @param int[]                     $order_ids
     * @return int[]
     */
    private function filter_order_ids( array $order_ids, array $filters ): array {
        if ( ! $this->filters_are_active( $filters ) ) {
            return $order_ids;
        }

        $filtered = array();
        foreach ( $order_ids as $order_id ) {
            if ( $this->order_matches_filters( (int) $order_id, $filters ) ) {
                $filtered[] = (int) $order_id;
            }
        }

        return $filtered;
    }

    /**
     * @param array<string, string|int> $filters
     */
    private function order_matches_filters( int $order_id, array $filters ): bool {
        $order = $this->get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        if ( ( $filters['status'] ?? '' ) !== '' ) {
            $wanted = str_replace( 'wc-', '', (string) $filters['status'] );
            if ( $order->get_status() !== $wanted ) {
                return false;
            }
        }

        if ( ( $filters['payment'] ?? '' ) !== '' ) {
            $payment = $order->get_payment_method_title() ?: __( 'Unknown', 'sheetsync-for-woocommerce' );
            if ( $payment !== $filters['payment'] ) {
                return false;
            }
        }

        if ( ( $filters['country'] ?? '' ) !== '' ) {
            $code = strtoupper( (string) $order->get_billing_country() );
            if ( $code === '' ) {
                $code = 'XX';
            }
            if ( strtoupper( (string) $filters['country'] ) !== $code ) {
                return false;
            }
        }

        $product_filter  = (int) ( $filters['product'] ?? 0 );
        $category_filter = (string) ( $filters['category'] ?? '' );
        if ( $product_filter > 0 || $category_filter !== '' ) {
            $line_match = false;
            foreach ( $order->get_items() as $item ) {
                if ( ! $item instanceof WC_Order_Item_Product ) {
                    continue;
                }
                $product_id = (int) $item->get_product_id();
                if ( $product_id <= 0 ) {
                    continue;
                }
                if ( $product_filter > 0 && $this->line_item_matches_product_filter( $item, $product_filter ) ) {
                    $line_match = true;
                    break;
                }
                if ( $category_filter !== '' && $this->product_matches_category_filter( $product_id, $category_filter ) ) {
                    $line_match = true;
                    break;
                }
            }
            if ( ! $line_match ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Match a line item against a product filter (simple, variable parent, or variation ID).
     */
    private function line_item_matches_product_filter( WC_Order_Item_Product $item, int $product_filter ): bool {
        if ( $product_filter <= 0 ) {
            return false;
        }

        $product_id   = (int) $item->get_product_id();
        $variation_id = (int) $item->get_variation_id();

        if ( $product_id === $product_filter ) {
            return true;
        }

        return $variation_id > 0 && $variation_id === $product_filter;
    }

    private function product_matches_category_filter( int $product_id, string $category_filter ): bool {
        if ( $category_filter === '' ) {
            return true;
        }

        if ( ctype_digit( $category_filter ) ) {
            return has_term( (int) $category_filter, 'product_cat', $product_id );
        }

        $terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
        if ( is_wp_error( $terms ) ) {
            return false;
        }

        return in_array( $category_filter, $terms, true );
    }

    /**
     * @return array{start: string, end: string, months: int, label: string}
     */
    private function get_period_range( string $period ): array {
        $end = current_time( 'Y-m-d 23:59:59' );

        if ( $period === '7days' ) {
            $start = $this->site_relative_datetime( '-7 days' );
        } elseif ( $period === '30days' ) {
            $start = $this->site_relative_datetime( '-30 days' );
        } else {
            $months = $period === '6months' ? 6 : 12;
            $start  = $this->site_relative_datetime( "-{$months} months" );
        }

        return array(
            'start'  => $start,
            'end'    => $end,
            'months' => $this->period_to_months( $period ),
            'label'  => $this->period_label( $period ),
        );
    }

    private function period_to_months( string $period ): int {
        return match ( $period ) {
            '7days'   => 0,
            '30days'  => 1,
            '6months' => 6,
            default   => 12,
        };
    }

    private function period_label( string $period ): string {
        return match ( $period ) {
            '7days'   => __( 'Last 7 Days', 'sheetsync-for-woocommerce' ),
            '30days'  => __( 'Last 30 Days', 'sheetsync-for-woocommerce' ),
            '6months' => __( 'Last 6 Months', 'sheetsync-for-woocommerce' ),
            default   => __( 'Last 12 Months', 'sheetsync-for-woocommerce' ),
        };
    }

    private function get_order( int $order_id ): ?WC_Order {
        if ( ! isset( $this->order_cache[ $order_id ] ) ) {
            $order = wc_get_order( $order_id );
            $this->order_cache[ $order_id ] = $order instanceof WC_Order ? $order : null;
        }

        return $this->order_cache[ $order_id ];
    }

    /**
     * Aggregate financial metrics from a set of orders.
     *
     * @param int[] $order_ids
     * @return array<string, float|int>
     */
    private function aggregate_order_metrics( array $order_ids ): array {
        $metrics = array(
            'gross_sales'     => 0.0,
            'net_sales'       => 0.0,
            'total_refunds'   => 0.0,
            'total_tax'       => 0.0,
            'total_shipping'  => 0.0,
            'total_discounts' => 0.0,
            'total_items'     => 0,
            'total_orders'    => count( $order_ids ),
        );

        foreach ( $order_ids as $order_id ) {
            $order = $this->get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            $gross    = (float) $order->get_total();
            $refunded = (float) $order->get_total_refunded();

            $metrics['gross_sales']     += $gross;
            $metrics['total_refunds']   += $refunded;
            $metrics['net_sales']       += max( 0, $gross - $refunded );
            $metrics['total_tax']       += (float) $order->get_total_tax();
            $metrics['total_shipping']  += (float) $order->get_shipping_total();
            $metrics['total_discounts'] += (float) $order->get_discount_total();

            foreach ( $order->get_items() as $item ) {
                $metrics['total_items'] += (int) $item->get_quantity();
            }
        }

        $metrics['avg_order_value'] = $metrics['total_orders'] > 0
            ? round( $metrics['net_sales'] / $metrics['total_orders'], 2 )
            : 0.0;

        foreach ( array( 'gross_sales', 'net_sales', 'total_refunds', 'total_tax', 'total_shipping', 'total_discounts' ) as $key ) {
            $metrics[ $key ] = round( (float) $metrics[ $key ], 2 );
        }

        return $metrics;
    }

    /**
     * Summary stats for the selected period.
     *
     * @param array<int, array<string, mixed>>|null $months_precomputed Avoid re-querying monthly/daily sales for best month.
     */
    private function get_summary_stats( string $period, array $filters = array(), ?array $months_precomputed = null ): array {
        $range           = $this->get_period_range( $period );
        $revenue_ids     = $this->filter_order_ids(
            $this->get_order_ids_in_range( $range['start'], $range['end'], $this->get_paid_statuses() ),
            $filters
        );
        $all_ids         = $this->filter_order_ids(
            $this->get_order_ids_in_range( $range['start'], $range['end'], $this->get_reportable_statuses() ),
            $filters
        );
        $metrics         = $this->aggregate_order_metrics( $revenue_ids );
        $metrics['total_orders'] = count( $all_ids );
        $metrics['paid_orders']  = count( $revenue_ids );
        $metrics['avg_order_value'] = $metrics['paid_orders'] > 0
            ? round( $metrics['net_sales'] / $metrics['paid_orders'], 2 )
            : 0.0;

        $revenue_lookup = array_fill_keys( $revenue_ids, true );
        $pending_revenue = 0.0;
        foreach ( $all_ids as $order_id ) {
            if ( isset( $revenue_lookup[ $order_id ] ) ) {
                continue;
            }
            $order = $this->get_order( $order_id );
            if ( ! $order || in_array( $order->get_status(), array( 'cancelled', 'failed', 'refunded' ), true ) ) {
                continue;
            }
            $pending_revenue += max( 0, (float) $order->get_total() - (float) $order->get_total_refunded() );
        }
        $metrics['pending_revenue'] = round( $pending_revenue, 2 );
        $metrics['gross_order_value'] = round( $metrics['net_sales'] + $metrics['pending_revenue'], 2 );

        $months_for_best = is_array( $months_precomputed ) ? $months_precomputed : $this->get_monthly_sales( $period, $filters );
        $best_month = '';
        $best_rev   = 0.0;
        foreach ( $months_for_best as $month ) {
            if ( $month['revenue'] > $best_rev ) {
                $best_rev   = $month['revenue'];
                $best_month = $month['month'];
            }
        }

        return array_merge( $metrics, array(
            'total_revenue'        => $metrics['net_sales'],
            'net_profit'           => round( max( 0, $metrics['net_sales'] - $metrics['total_tax'] - $metrics['total_shipping'] ), 2 ),
            'period_label'           => $range['label'],
            'best_month'             => $best_month,
            'best_month_rev'         => round( $best_rev, 2 ),
            'new_customers'          => $this->count_new_customers( $all_ids, $range['start'] ),
        ) );
    }

    /**
     * @param int[] $order_ids
     * @return array<string, int|float>
     */
    private function get_customer_counts( array $order_ids, string $period_start ): array {
        $known_before = $this->get_customer_keys_before( $period_start );
        $unique       = array();
        $returning    = array();

        foreach ( $order_ids as $order_id ) {
            $order = $this->get_order( $order_id );
            if ( ! $order ) {
                continue;
            }
            $key = $this->customer_key( $order );
            $unique[ $key ] = true;
            if ( isset( $known_before[ $key ] ) ) {
                $returning[ $key ] = true;
            }
        }

        $total_unique     = count( $unique );
        $returning_unique = count( $returning );
        $repeat_rate      = $total_unique > 0 ? round( ( $returning_unique / $total_unique ) * 100, 1 ) : 0;
        $revenue_ids      = array_filter(
            $order_ids,
            function ( $id ) {
                $order = $this->get_order( $id );
                return $order && $order->is_paid();
            }
        );
        $avg_clv          = $total_unique > 0
            ? round( (float) $this->aggregate_order_metrics( array_values( $revenue_ids ) )['net_sales'] / $total_unique, 2 )
            : 0;

        return array(
            'total_customers'      => $total_unique,
            'returning_customers'  => $returning_unique,
            'repeat_purchase_rate' => $repeat_rate,
            'avg_clv'              => $avg_clv,
            'conversion_rate'      => $repeat_rate,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $daily
     */
    private function get_weekly_sales( array $daily ): array {
        $weeks = array();
        foreach ( $daily as $row ) {
            $key = gmdate( 'o-W', strtotime( $row['full_date'] ?? $row['date'] ?? 'now' ) );
            if ( ! isset( $weeks[ $key ] ) ) {
                $weeks[ $key ] = array( 'label' => 'W' . substr( $key, -2 ), 'orders' => 0, 'revenue' => 0.0, 'profit' => 0.0, 'expenses' => 0.0 );
            }
            $weeks[ $key ]['orders']   += (int) $row['orders'];
            $weeks[ $key ]['revenue']  += (float) $row['revenue'];
            $weeks[ $key ]['profit']   += (float) ( $row['profit'] ?? 0 );
            $weeks[ $key ]['expenses'] += (float) ( $row['expenses'] ?? 0 );
        }
        return array_values( array_map(
            fn( $w ) => array(
                'label'    => $w['label'],
                'orders'   => $w['orders'],
                'revenue'  => round( $w['revenue'], 2 ),
                'profit'   => round( $w['profit'], 2 ),
                'expenses' => round( $w['expenses'], 2 ),
            ),
            $weeks
        ) );
    }

    /**
     * @param array<int, array<string, mixed>> $monthly
     */
    private function get_yearly_sales( array $monthly ): array {
        $years = array();
        foreach ( $monthly as $row ) {
            $parts = explode( ' ', (string) ( $row['month'] ?? '' ) );
            $year  = end( $parts ) ?: gmdate( 'Y' );
            if ( ! isset( $years[ $year ] ) ) {
                $years[ $year ] = array( 'label' => $year, 'orders' => 0, 'revenue' => 0.0, 'profit' => 0.0, 'expenses' => 0.0 );
            }
            $years[ $year ]['orders']   += (int) $row['orders'];
            $years[ $year ]['revenue']  += (float) $row['revenue'];
            $years[ $year ]['profit']   += (float) ( $row['profit'] ?? 0 );
            $years[ $year ]['expenses'] += (float) ( $row['expenses'] ?? 0 );
        }
        if ( empty( $years ) ) {
            $y = gmdate( 'Y' );
            $years[ $y ] = array( 'label' => $y, 'orders' => 0, 'revenue' => 0.0, 'profit' => 0.0, 'expenses' => 0.0 );
        }
        return array_values( array_map(
            fn( $y ) => array(
                'label'    => $y['label'],
                'orders'   => $y['orders'],
                'revenue'  => round( $y['revenue'], 2 ),
                'profit'   => round( $y['profit'], 2 ),
                'expenses' => round( $y['expenses'], 2 ),
            ),
            $years
        ) );
    }

    /**
     * @param array<int, array<string, mixed>> $daily
     */
    private function get_sales_forecast( array $daily ): array {
        $slice   = array_slice( $daily, -7 );
        $avg_rev = count( $slice ) > 0 ? array_sum( array_column( $slice, 'revenue' ) ) / count( $slice ) : 0;
        $historical = array();
        $predicted  = array();
        $labels     = array();

        foreach ( array_slice( $daily, -7 ) as $d ) {
            $labels[]     = $d['date'] ?? '';
            $historical[] = (float) $d['revenue'];
            $predicted[]  = null;
        }
        for ( $i = 1; $i <= 7; $i++ ) {
            $labels[]     = wp_date( 'M j', strtotime( '+' . $i . ' days', $this->site_timestamp() ) );
            $historical[] = null;
            $predicted[]  = round( $avg_rev * ( 1 + ( $i * 0.02 ) ), 2 );
        }

        return array(
            'labels'     => $labels,
            'historical' => $historical,
            'predicted'  => $predicted,
        );
    }

    /**
     * @param int[] $order_ids
     */
    private function get_order_funnel( array $order_ids ): array {
        $statuses = $this->get_order_status_breakdown( $order_ids );
        if ( empty( $statuses ) ) {
            return array();
        }

        $total = array_sum( array_column( $statuses, 'count' ) );
        $steps = array();
        $colors = array( '#6c63ff', '#38bdf8', '#22d3a5', '#fbbf24', '#6c63ff' );

        foreach ( array_values( $statuses ) as $i => $st ) {
            $steps[] = array(
                'label'  => $st['label'],
                'value'  => $st['count'],
                'pct'    => $total > 0 ? round( ( $st['count'] / $total ) * 100, 1 ) : 0,
                'color'  => $colors[ $i % count( $colors ) ],
                'revenue'=> $st['revenue'],
            );
        }

        return $steps;
    }

    private function get_inventory_snapshot(): array {
        $cache_key = 'sheetsync_sd_inv_' . get_current_blog_id() . '_v' . self::dashboard_cache_version();
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $threshold = $this->get_low_stock_threshold();
        $snapshot  = $this->query_inventory_snapshot_sql( $threshold );
        if ( null === $snapshot ) {
            $snapshot = $this->query_inventory_snapshot_legacy( $threshold );
        }

        set_transient( $cache_key, $snapshot, (int) apply_filters( 'sheetsync_sales_inventory_cache_ttl', 600 ) );

        return $snapshot;
    }

    /**
     * Fast stock counts via SQL (avoids loading thousands of WC_Product objects).
     *
     * @return array<string, mixed>|null
     */
    private function query_inventory_snapshot_sql( int $threshold ): ?array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_type = 'product' AND post_status = 'publish'"
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title, CAST(stock.meta_value AS SIGNED) AS qty
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} ms ON p.ID = ms.post_id AND ms.meta_key = '_manage_stock' AND ms.meta_value = 'yes'
				INNER JOIN {$wpdb->postmeta} stock ON p.ID = stock.post_id AND stock.meta_key = '_stock'
				WHERE p.post_status = 'publish'
				AND p.post_type IN ('product', 'product_variation')
				AND CAST(stock.meta_value AS SIGNED) <= %d",
                $threshold
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) ) {
            return null;
        }

        $low_stock = 0;
        $out_stock = 0;
        $alerts    = array();

        foreach ( $rows as $row ) {
            $qty  = (int) ( $row['qty'] ?? 0 );
            $name = (string) ( $row['post_title'] ?? '' );
            if ( $qty <= 0 ) {
                ++$out_stock;
                if ( count( $alerts ) < 5 ) {
                    $alerts[] = array( 'name' => $name, 'qty' => $qty, 'status' => 'outofstock' );
                }
            } else {
                ++$low_stock;
                if ( count( $alerts ) < 5 ) {
                    $alerts[] = array( 'name' => $name, 'qty' => $qty, 'status' => 'low_stock' );
                }
            }
        }

        return array(
            'low_stock' => $low_stock,
            'out_stock' => $out_stock,
            'total'     => $total,
            'alerts'    => $alerts,
            'threshold' => $threshold,
        );
    }

    /**
     * Legacy product walk when SQL snapshot is unavailable.
     *
     * @return array<string, mixed>
     */
    private function query_inventory_snapshot_legacy( int $threshold ): array {
        $low_stock = 0;
        $out_stock = 0;
        $alerts    = array();

        $page     = 1;
        $per_page = sheetsync_dashboard_products_per_page();
        $max_page = sheetsync_dashboard_products_max_pages();
        $total    = 0;

        do {
            $products = wc_get_products(
                array(
                    'status' => 'publish',
                    'limit'  => $per_page,
                    'page'   => $page,
                    'return' => 'objects',
                )
            );

            if ( empty( $products ) ) {
                break;
            }

            $total += count( $products );

            foreach ( $products as $product ) {
                if ( ! $product instanceof WC_Product || ! $product->managing_stock() ) {
                    continue;
                }
                $qty = (int) $product->get_stock_quantity();
                if ( $qty <= 0 ) {
                    ++$out_stock;
                    if ( count( $alerts ) < 5 ) {
                        $alerts[] = array( 'name' => $product->get_name(), 'qty' => $qty, 'status' => 'outofstock' );
                    }
                } elseif ( $qty <= $threshold ) {
                    ++$low_stock;
                    if ( count( $alerts ) < 5 ) {
                        $alerts[] = array( 'name' => $product->get_name(), 'qty' => $qty, 'status' => 'low_stock' );
                    }
                }
            }

            if ( count( $products ) < $per_page ) {
                break;
            }

            ++$page;
        } while ( $page <= $max_page );

        return array(
            'low_stock' => $low_stock,
            'out_stock' => $out_stock,
            'total'     => $total,
            'alerts'    => $alerts,
            'threshold' => $threshold,
        );
    }

    /**
     * @param int[] $base_paid
     * @param int[] $base_all
     */
    private function get_filter_options( array $base_paid, array $base_all ): array {
        $categories = array( array( 'value' => '', 'label' => __( 'All Categories', 'sheetsync-for-woocommerce' ) ) );
        $products   = array( array( 'value' => '', 'label' => __( 'All Products', 'sheetsync-for-woocommerce' ) ) );
        $countries  = array( array( 'value' => '', 'label' => __( 'All Countries', 'sheetsync-for-woocommerce' ) ) );
        $payments   = array( array( 'value' => '', 'label' => __( 'All Payments', 'sheetsync-for-woocommerce' ) ) );

        $category_map = array();
        $product_map  = array();

        foreach ( $base_paid as $order_id ) {
            $order = $this->get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            foreach ( $order->get_items() as $item ) {
                if ( ! $item instanceof WC_Order_Item_Product ) {
                    continue;
                }

                $product_id   = (int) $item->get_product_id();
                $variation_id = (int) $item->get_variation_id();
                if ( $product_id <= 0 && $variation_id <= 0 ) {
                    continue;
                }

                if ( $product_id > 0 && ! isset( $product_map[ $product_id ] ) ) {
                    $product = wc_get_product( $product_id );
                    $product_map[ $product_id ] = $product ? $product->get_name() : $item->get_name();
                }

                if ( $variation_id > 0 && ! isset( $product_map[ $variation_id ] ) ) {
                    $variation = wc_get_product( $variation_id );
                    $product_map[ $variation_id ] = $variation ? $variation->get_name() : $item->get_name();
                }

                $category_product_id = $product_id > 0 ? $product_id : $variation_id;
                $terms = wp_get_post_terms( $category_product_id, 'product_cat', array( 'fields' => 'all' ) );
                if ( is_wp_error( $terms ) || empty( $terms ) ) {
                    $category_map['0'] = __( 'Uncategorized', 'sheetsync-for-woocommerce' );
                    continue;
                }
                foreach ( $terms as $term ) {
                    $category_map[ (string) $term->term_id ] = $term->name;
                }
            }
        }

        asort( $category_map );
        foreach ( $category_map as $term_id => $name ) {
            $categories[] = array( 'value' => (string) $term_id, 'label' => $name );
        }

        asort( $product_map );
        foreach ( $product_map as $product_id => $name ) {
            $products[] = array( 'value' => (string) $product_id, 'label' => $name );
        }

        foreach ( $this->get_geo_sales( $base_paid, 20 ) as $g ) {
            $countries[] = array( 'value' => $g['code'], 'label' => $g['name'] );
        }

        foreach ( $this->get_payment_methods( $base_paid ) as $m ) {
            $payments[] = array( 'value' => $m['name'], 'label' => $m['name'] );
        }

        $statuses = array( array( 'value' => '', 'label' => __( 'All Statuses', 'sheetsync-for-woocommerce' ) ) );
        $seen     = array();
        foreach ( $base_all as $order_id ) {
            $order = $this->get_order( $order_id );
            if ( ! $order ) {
                continue;
            }
            $slug = $order->get_status();
            if ( isset( $seen[ $slug ] ) ) {
                continue;
            }
            $seen[ $slug ] = true;
            $label         = wc_get_order_status_name( $slug );
            $statuses[]    = array( 'value' => 'wc-' . $slug, 'label' => $label );
        }

        return array(
            'categories' => $categories,
            'products'   => $products,
            'statuses'   => $statuses,
            'countries'  => $countries,
            'payments'   => $payments,
        );
    }

    private function count_pending_orders(): int {
        $needs_action = array( 'pending', 'on-hold' );

        if ( function_exists( 'wc_orders_count' ) ) {
            $total = 0;
            foreach ( $needs_action as $status ) {
                $total += (int) wc_orders_count( 'wc-' . $status );
            }
            return $total;
        }

        return count(
            $this->get_order_ids_in_range_paginated(
                array(
                    'status' => $needs_action,
                    'return' => 'ids',
                )
            )
        );
    }

    /**
     * @param array<string, mixed> $args
     * @return int[]
     */
    private function get_order_ids_in_range_paginated( array $args ): array {
        $all_ids  = array();
        $page     = 1;
        $per_page = sheetsync_dashboard_orders_per_page();
        $max_page = sheetsync_dashboard_orders_max_pages();

        do {
            $batch = wc_get_orders(
                array_merge(
                    $args,
                    array(
                        'limit'   => $per_page,
                        'page'    => $page,
                        'orderby' => 'date',
                        'order'   => 'DESC',
                    )
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
     * Compare current period vs previous period of equal length.
     */
    private function get_period_comparison( string $period, array $filters = array() ): array {
        $months = max( 1, $this->period_to_months( $period ) );
        if ( $period === '7days' ) {
            $current_start = $this->site_relative_datetime( '-7 days' );
            $current_end   = current_time( 'Y-m-d 23:59:59' );
            $prev_start    = $this->site_relative_datetime( '-14 days' );
            $prev_end      = $this->site_relative_datetime( '-8 days', '23:59:59' );
        } elseif ( $period === '30days' ) {
            $current_start = $this->site_relative_datetime( '-30 days' );
            $current_end   = current_time( 'Y-m-d 23:59:59' );
            $prev_start    = $this->site_relative_datetime( '-60 days' );
            $prev_end      = $this->site_relative_datetime( '-31 days', '23:59:59' );
        } else {
            $current_start = $this->site_relative_datetime( "-{$months} months" );
            $current_end   = current_time( 'Y-m-d 23:59:59' );
            $prev_start    = $this->site_relative_datetime( '-' . ( $months * 2 ) . ' months' );
            $prev_end      = wp_date( 'Y-m-d 23:59:59', strtotime( "-{$months} months -1 day", $this->site_timestamp() ) );
        }

        $current_revenue  = $this->filter_order_ids(
            $this->get_order_ids_in_range( $current_start, $current_end, $this->get_paid_statuses() ),
            $filters
        );
        $previous_revenue = $this->filter_order_ids(
            $this->get_order_ids_in_range( $prev_start, $prev_end, $this->get_paid_statuses() ),
            $filters
        );
        $current_all      = $this->filter_order_ids(
            $this->get_order_ids_in_range( $current_start, $current_end, $this->get_reportable_statuses() ),
            $filters
        );
        $previous_all     = $this->filter_order_ids(
            $this->get_order_ids_in_range( $prev_start, $prev_end, $this->get_reportable_statuses() ),
            $filters
        );

        $current  = $this->aggregate_order_metrics( $current_revenue );
        $previous = $this->aggregate_order_metrics( $previous_revenue );
        $current['avg_order_value']  = count( $current_revenue ) > 0
            ? round( $current['net_sales'] / count( $current_revenue ), 2 )
            : 0.0;
        $previous['avg_order_value'] = count( $previous_revenue ) > 0
            ? round( $previous['net_sales'] / count( $previous_revenue ), 2 )
            : 0.0;

        $cur_cust  = $this->get_customer_counts( $current_all, $current_start );
        $prev_cust = $this->get_customer_counts( $previous_all, $prev_start );

        return array(
            'net_sales'           => $this->percent_change( (float) $previous['net_sales'], (float) $current['net_sales'] ),
            'gross_sales'         => $this->percent_change( (float) $previous['gross_sales'], (float) $current['gross_sales'] ),
            'total_orders'        => $this->percent_change( (float) count( $previous_all ), (float) count( $current_all ) ),
            'total_items'         => $this->percent_change( (float) $previous['total_items'], (float) $current['total_items'] ),
            'avg_order_value'     => $this->percent_change( (float) $previous['avg_order_value'], (float) $current['avg_order_value'] ),
            'total_refunds'       => $this->percent_change( (float) $previous['total_refunds'], (float) $current['total_refunds'] ),
            'net_profit'          => $this->percent_change(
                (float) $previous['net_sales'] - (float) $previous['total_tax'] - (float) $previous['total_shipping'],
                (float) $current['net_sales'] - (float) $current['total_tax'] - (float) $current['total_shipping']
            ),
            'total_customers'     => $this->percent_change( (float) $prev_cust['total_customers'], (float) $cur_cust['total_customers'] ),
            'returning_customers' => $this->percent_change( (float) $prev_cust['returning_customers'], (float) $cur_cust['returning_customers'] ),
            'new_customers'       => $this->percent_change(
                (float) $this->count_new_customers( $previous_all, $prev_start ),
                (float) $this->count_new_customers( $current_all, $current_start )
            ),
            'conversion_rate'     => $this->percent_change( (float) $prev_cust['repeat_purchase_rate'], (float) $cur_cust['repeat_purchase_rate'] ),
        );
    }

    private function percent_change( float $previous, float $current ): array {
        if ( $previous <= 0 ) {
            return array(
                'previous' => round( $previous, 2 ),
                'current'  => round( $current, 2 ),
                'change'   => $current > 0 ? 100.0 : 0.0,
                'trend'    => $current >= $previous ? 'up' : 'down',
            );
        }

        $change = ( ( $current - $previous ) / $previous ) * 100;

        return array(
            'previous' => round( $previous, 2 ),
            'current'  => round( $current, 2 ),
            'change'   => round( $change, 1 ),
            'trend'    => $change >= 0 ? 'up' : 'down',
        );
    }

    /**
     * Today, this week, this month quick stats.
     */
    private function get_quick_stats( array $filters = array() ): array {
        $today_end   = current_time( 'Y-m-d 23:59:59' );
        $week_start  = $this->site_relative_datetime( 'monday this week' );
        $month_start = $this->site_relative_datetime( 'first day of this month' );
        $today_date  = current_time( 'Y-m-d' );
        $week_ts     = strtotime( $week_start );

        $paid_ids = $this->filter_order_ids(
            $this->get_order_ids_in_range( $month_start, $today_end, $this->get_paid_statuses() ),
            $filters
        );
        $all_ids = $this->filter_order_ids(
            $this->get_order_ids_in_range( $month_start, $today_end, $this->get_reportable_statuses() ),
            $filters
        );

        $buckets = array(
            'today' => array( 'paid_ids' => array(), 'all_ids' => array() ),
            'week'  => array( 'paid_ids' => array(), 'all_ids' => array() ),
            'month' => array( 'paid_ids' => array(), 'all_ids' => array() ),
        );

        foreach ( $paid_ids as $order_id ) {
            $order = $this->get_order( (int) $order_id );
            if ( ! $order ) {
                continue;
            }
            $created_ts = $order->get_date_created()->getTimestamp();
            $buckets['month']['paid_ids'][] = (int) $order_id;
            if ( $created_ts >= $week_ts ) {
                $buckets['week']['paid_ids'][] = (int) $order_id;
            }
            if ( wp_date( 'Y-m-d', $created_ts ) === $today_date ) {
                $buckets['today']['paid_ids'][] = (int) $order_id;
            }
        }

        foreach ( $all_ids as $order_id ) {
            $order = $this->get_order( (int) $order_id );
            if ( ! $order ) {
                continue;
            }
            $created_ts = $order->get_date_created()->getTimestamp();
            $buckets['month']['all_ids'][] = (int) $order_id;
            if ( $created_ts >= $week_ts ) {
                $buckets['week']['all_ids'][] = (int) $order_id;
            }
            if ( wp_date( 'Y-m-d', $created_ts ) === $today_date ) {
                $buckets['today']['all_ids'][] = (int) $order_id;
            }
        }

        $stats = array();
        foreach ( $buckets as $key => $bucket ) {
            $metrics       = $this->aggregate_order_metrics( $bucket['paid_ids'] );
            $stats[ $key ] = array(
                'revenue'     => $metrics['net_sales'],
                'orders'      => count( $bucket['all_ids'] ),
                'paid_orders' => count( $bucket['paid_ids'] ),
                'items'       => $metrics['total_items'],
            );
        }

        return $stats;
    }

    /**
     * Customer keys that already had an order before the given datetime.
     *
     * @return array<string, true>
     */
    private function get_customer_keys_before( string $before ): array {
        if ( isset( $this->customer_keys_before_cache[ $before ] ) ) {
            return $this->customer_keys_before_cache[ $before ];
        }

        $cache_key = 'sheetsync_sd_cust_before_' . get_current_blog_id() . '_' . md5( $before );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            $this->customer_keys_before_cache[ $before ] = $cached;
            return $cached;
        }

        $keys = $this->query_customer_keys_before( $before );

        $this->customer_keys_before_cache[ $before ] = $keys;
        set_transient( $cache_key, $keys, (int) apply_filters( 'sheetsync_sales_customer_cache_ttl', 900 ) );

        return $keys;
    }

    /**
     * Build customer identity keys for orders created before a date (lightweight batch reads).
     *
     * @return array<string, true>
     */
    private function query_customer_keys_before( string $before ): array {
        $keys     = array();
        $page     = 1;
        $per_page = sheetsync_dashboard_orders_per_page();
        $max_page = (int) apply_filters( 'sheetsync_dashboard_customer_history_max_pages', 80 );

        do {
            $order_ids = wc_get_orders(
                array(
                    'status'       => $this->get_reportable_statuses(),
                    'date_created' => '<' . $before,
                    'limit'        => $per_page,
                    'page'         => $page,
                    'return'       => 'ids',
                    'orderby'      => 'date',
                    'order'        => 'DESC',
                )
            );

            if ( empty( $order_ids ) ) {
                break;
            }

            foreach ( $order_ids as $order_id ) {
                $order = $this->get_order( (int) $order_id );
                if ( ! $order ) {
                    continue;
                }
                $keys[ $this->customer_key( $order ) ] = true;
            }

            if ( count( $order_ids ) < $per_page ) {
                break;
            }

            ++$page;
        } while ( $page <= $max_page );

        return $keys;
    }

    /**
     * @deprecated Use get_customer_keys_before().
     * @return array<string, true>
     */
    private function get_customer_emails_before( string $before ): array {
        return $this->get_customer_keys_before( $before );
    }

    /**
     * @param int[] $order_ids
     */
    private function count_new_customers( array $order_ids, string $period_start ): int {
        $known     = $this->get_customer_keys_before( $period_start );
        $new_count = 0;

        foreach ( $order_ids as $order_id ) {
            $order = $this->get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            $key = $this->customer_key( $order );
            if ( isset( $known[ $key ] ) ) {
                continue;
            }

            $known[ $key ] = true;
            $new_count++;
        }

        return $new_count;
    }

    /**
     * Monthly sales: orders count + revenue for each month.
     */
    private function get_monthly_sales( string $period, array $filters = array() ): array {
        if ( in_array( $period, array( '7days', '30days' ), true ) ) {
            return $this->get_daily_sales( $period === '7days' ? 7 : 30, $filters );
        }

        $months_count = $period === '6months' ? 6 : 12;
        $results      = array();

        for ( $i = $months_count - 1; $i >= 0; $i-- ) {
            $timestamp  = strtotime( "-{$i} months", $this->site_timestamp() );
            $year       = (int) wp_date( 'Y', $timestamp );
            $month      = (int) wp_date( 'n', $timestamp );
            $month_name = wp_date( 'M', $timestamp );

            $start = wp_date( 'Y-m-01 00:00:00', $timestamp );
            $end   = wp_date( 'Y-m-t 23:59:59', $timestamp );

            $paid_ids = $this->filter_order_ids(
                $this->get_order_ids_in_range( $start, $end, $this->get_paid_statuses() ),
                $filters
            );
            $all_ids  = $this->filter_order_ids(
                $this->get_order_ids_in_range( $start, $end, $this->get_reportable_statuses() ),
                $filters
            );
            $metrics  = $this->aggregate_order_metrics( $paid_ids );

            $profit   = max( 0, (float) $metrics['net_sales'] - (float) $metrics['total_tax'] - (float) $metrics['total_shipping'] );
            $expenses = (float) $metrics['total_tax'] + (float) $metrics['total_shipping'] + (float) $metrics['total_refunds'];

            $results[] = array(
                'month'    => $month_name . ' ' . $year,
                'label'    => $month_name,
                'orders'   => count( $all_ids ),
                'paid_orders' => count( $paid_ids ),
                'revenue'  => $metrics['net_sales'],
                'profit'   => round( $profit, 2 ),
                'expenses' => round( $expenses, 2 ),
            );
        }

        return $results;
    }

    /**
     * Daily sales for last N days.
     */
    private function get_daily_sales( int $days = 30, array $filters = array() ): array {
        $day_keys = array();
        for ( $i = $days - 1; $i >= 0; $i-- ) {
            $timestamp = strtotime( "-{$i} days", $this->site_timestamp() );
            $day_keys[] = array(
                'key'       => wp_date( 'Y-m-d', $timestamp ),
                'timestamp' => $timestamp,
            );
        }

        if ( empty( $day_keys ) ) {
            return array();
        }

        $start = $day_keys[0]['key'] . ' 00:00:00';
        $end   = $day_keys[ count( $day_keys ) - 1 ]['key'] . ' 23:59:59';

        $paid_ids = $this->filter_order_ids(
            $this->get_order_ids_in_range( $start, $end, $this->get_paid_statuses() ),
            $filters
        );
        $all_ids = $this->filter_order_ids(
            $this->get_order_ids_in_range( $start, $end, $this->get_reportable_statuses() ),
            $filters
        );

        $paid_by_day = array();
        $all_by_day  = array();
        foreach ( $day_keys as $day ) {
            $paid_by_day[ $day['key'] ] = array();
            $all_by_day[ $day['key'] ]  = array();
        }

        foreach ( $paid_ids as $order_id ) {
            $order = $this->get_order( (int) $order_id );
            if ( ! $order ) {
                continue;
            }
            $date_key = wp_date( 'Y-m-d', $order->get_date_created()->getTimestamp() );
            if ( isset( $paid_by_day[ $date_key ] ) ) {
                $paid_by_day[ $date_key ][] = (int) $order_id;
            }
        }

        foreach ( $all_ids as $order_id ) {
            $order = $this->get_order( (int) $order_id );
            if ( ! $order ) {
                continue;
            }
            $date_key = wp_date( 'Y-m-d', $order->get_date_created()->getTimestamp() );
            if ( isset( $all_by_day[ $date_key ] ) ) {
                $all_by_day[ $date_key ][] = (int) $order_id;
            }
        }

        $results = array();
        foreach ( $day_keys as $day ) {
            $timestamp = $day['timestamp'];
            $date_key  = $day['key'];
            $paid_day  = $paid_by_day[ $date_key ];
            $all_day   = $all_by_day[ $date_key ];
            $metrics   = $this->aggregate_order_metrics( $paid_day );
            $avg_aov   = count( $paid_day ) > 0
                ? round( $metrics['net_sales'] / count( $paid_day ), 2 )
                : 0.0;

            $results[] = array(
                'date'            => wp_date( 'M j', $timestamp ),
                'full_date'       => wp_date( 'M j, Y', $timestamp ),
                'month'           => wp_date( 'M j', $timestamp ),
                'label'           => wp_date( 'M j', $timestamp ),
                'orders'          => count( $all_day ),
                'paid_orders'     => count( $paid_day ),
                'revenue'         => $metrics['net_sales'],
                'profit'          => round( max( 0, (float) $metrics['net_sales'] - (float) $metrics['total_tax'] - (float) $metrics['total_shipping'] ), 2 ),
                'expenses'        => round( (float) $metrics['total_tax'] + (float) $metrics['total_shipping'] + (float) $metrics['total_refunds'], 2 ),
                'avg_order_value' => $avg_aov,
            );
        }

        return $results;
    }

    /**
     * Top products by quantity sold.
     */
    private function get_top_products( int $limit = 10, string $period = '12months', array $filters = array() ): array {
        $range     = $this->get_period_range( $period );
        $order_ids = $this->filter_order_ids(
            $this->get_order_ids_in_range( $range['start'], $range['end'], $this->get_paid_statuses() ),
            $filters
        );
        $product_sales = array();

        foreach ( $order_ids as $order_id ) {
            $order = $this->get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            foreach ( $order->get_items() as $item ) {
                $product_id = $item->get_product_id();
                $product    = wc_get_product( $product_id );
                $name       = $product ? $product->get_name() : $item->get_name();
                $qty        = (int) $item->get_quantity();
                $revenue    = (float) $item->get_total();

                if ( ! isset( $product_sales[ $product_id ] ) ) {
                    $product_sales[ $product_id ] = array(
                        'product_id' => $product_id,
                        'name'     => $name,
                        'quantity' => 0,
                        'revenue'  => 0.0,
                        'image'    => $product ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '',
                    );
                }
                $product_sales[ $product_id ]['quantity'] += $qty;
                $product_sales[ $product_id ]['revenue']  += $revenue;
            }
        }

        uasort( $product_sales, fn( $a, $b ) => $b['revenue'] <=> $a['revenue'] );

        $ranked = array();
        $rank   = 1;
        $max_rev = 0.0;
        foreach ( array_slice( $product_sales, 0, $limit, true ) as $product ) {
            if ( $rank === 1 ) {
                $max_rev = $product['revenue'];
            }
            $ranked[] = array(
                'rank'       => $rank++,
                'product_id' => (int) ( $product['product_id'] ?? 0 ),
                'name'       => $product['name'],
                'quantity'   => $product['quantity'],
                'revenue'    => round( $product['revenue'], 2 ),
                'image'      => $product['image'] ?: '',
                'share'      => $max_rev > 0 ? round( ( $product['revenue'] / $max_rev ) * 100, 1 ) : 0,
            );
        }

        return $ranked;
    }

    /**
     * @param int[] $order_ids
     */
    private function get_top_categories( array $order_ids, int $limit = 8 ): array {
        $categories = array();

        foreach ( $order_ids as $order_id ) {
            $order = $this->get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            foreach ( $order->get_items() as $item ) {
                $product = wc_get_product( $item->get_product_id() );
                if ( ! $product ) {
                    continue;
                }

                $terms = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'all' ) );
                if ( is_wp_error( $terms ) || empty( $terms ) ) {
                    $key = '0';
                    if ( ! isset( $categories[ $key ] ) ) {
                        $categories[ $key ] = array(
                            'term_id'   => 0,
                            'name'      => __( 'Uncategorized', 'sheetsync-for-woocommerce' ),
                            'quantity'  => 0,
                            'revenue'   => 0.0,
                            'order_ids' => array(),
                        );
                    }
                    $categories[ $key ]['quantity'] += (int) $item->get_quantity();
                    $categories[ $key ]['revenue']  += (float) $item->get_total();
                    $categories[ $key ]['order_ids'][ $order_id ] = true;
                    continue;
                }

                foreach ( $terms as $term ) {
                    $key = (string) $term->term_id;
                    if ( ! isset( $categories[ $key ] ) ) {
                        $categories[ $key ] = array(
                            'term_id'   => (int) $term->term_id,
                            'name'      => $term->name,
                            'quantity'  => 0,
                            'revenue'   => 0.0,
                            'order_ids' => array(),
                        );
                    }
                    $categories[ $key ]['quantity'] += (int) $item->get_quantity();
                    $categories[ $key ]['revenue']  += (float) $item->get_total();
                    $categories[ $key ]['order_ids'][ $order_id ] = true;
                }
            }
        }

        uasort( $categories, fn( $a, $b ) => $b['revenue'] <=> $a['revenue'] );

        $total_rev = array_sum( array_column( $categories, 'revenue' ) );
        $ranked    = array();
        $rank      = 1;
        foreach ( array_slice( $categories, 0, $limit, true ) as $cat ) {
            $ranked[] = array(
                'rank'     => $rank++,
                'term_id'  => (int) ( $cat['term_id'] ?? 0 ),
                'name'     => $cat['name'],
                'quantity' => $cat['quantity'],
                'orders'   => count( $cat['order_ids'] ?? array() ),
                'revenue'  => round( $cat['revenue'], 2 ),
                'share'    => $total_rev > 0 ? round( ( $cat['revenue'] / $total_rev ) * 100, 1 ) : 0,
            );
        }

        return $ranked;
    }

    /**
     * @param int[] $order_ids
     */
    private function get_payment_methods( array $order_ids ): array {
        $methods = array();

        foreach ( $order_ids as $order_id ) {
            $order = $this->get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            $label = $order->get_payment_method_title() ?: __( 'Unknown', 'sheetsync-for-woocommerce' );
            if ( ! isset( $methods[ $label ] ) ) {
                $methods[ $label ] = array( 'name' => $label, 'orders' => 0, 'revenue' => 0.0 );
            }

            $methods[ $label ]['orders']++;
            $methods[ $label ]['revenue'] += max( 0, (float) $order->get_total() - (float) $order->get_total_refunded() );
        }

        uasort( $methods, fn( $a, $b ) => $b['revenue'] <=> $a['revenue'] );

        $total_rev = array_sum( array_column( $methods, 'revenue' ) );
        $result    = array();

        foreach ( $methods as $method ) {
            $result[] = array(
                'name'    => $method['name'],
                'orders'  => $method['orders'],
                'revenue' => round( $method['revenue'], 2 ),
                'share'   => $total_rev > 0 ? round( ( $method['revenue'] / $total_rev ) * 100, 1 ) : 0,
            );
        }

        return $result;
    }

    /**
     * @param int[] $order_ids
     */
    private function get_order_status_breakdown( array $order_ids ): array {
        $statuses = wc_get_order_statuses();
        $counts   = array();

        foreach ( $order_ids as $order_id ) {
            $order = $this->get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            $status = $order->get_status();
            $label  = $statuses[ 'wc-' . $status ] ?? ucfirst( $status );

            if ( ! isset( $counts[ $status ] ) ) {
                $counts[ $status ] = array( 'status' => $status, 'label' => $label, 'count' => 0, 'revenue' => 0.0 );
            }

            $counts[ $status ]['count']++;
            $counts[ $status ]['revenue'] += max( 0, (float) $order->get_total() - (float) $order->get_total_refunded() );
        }

        uasort( $counts, fn( $a, $b ) => $b['count'] <=> $a['count'] );

        return array_values( array_map(
            fn( $row ) => array(
                'status'  => $row['status'],
                'label'   => $row['label'],
                'count'   => $row['count'],
                'revenue' => round( $row['revenue'], 2 ),
            ),
            $counts
        ) );
    }

    /**
     * @param int[] $order_ids
     */
    private function get_recent_orders( array $order_ids, int $limit = 8 ): array {
        $recent = array_slice( $order_ids, 0, $limit );
        $rows   = array();

        foreach ( $recent as $order_id ) {
            $order = $this->get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            $items = array();
            foreach ( $order->get_items() as $item ) {
                $items[] = $item->get_name() . ' × ' . $item->get_quantity();
            }

            $location = $this->parse_order_location( $order );

            $rows[] = array(
                'id'           => $order->get_id(),
                'number'       => $order->get_order_number(),
                'date'         => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'M j, Y g:i A' ) : '',
                'customer'     => trim( $order->get_formatted_billing_full_name() ) ?: __( 'Guest', 'sheetsync-for-woocommerce' ),
                'email'        => $order->get_billing_email(),
                'status'       => wc_get_order_status_name( $order->get_status() ),
                'status_slug'  => $order->get_status(),
                'total'        => round( max( 0, (float) $order->get_total() - (float) $order->get_total_refunded() ), 2 ),
                'items'        => implode( ', ', array_slice( $items, 0, 2 ) ) . ( count( $items ) > 2 ? '…' : '' ),
                'payment'      => $order->get_payment_method_title() ?: '—',
                'country'      => $order->get_billing_country() ?: '',
                'country_name' => $location['country_name'],
                'city'         => $location['city'],
                'state'        => $location['state_code'],
                'state_name'   => $location['state_name'],
                'location'     => $location['label'],
                'edit_url'     => $order->get_edit_order_url(),
                'initials'     => $this->customer_initials( trim( $order->get_formatted_billing_full_name() ) ?: 'Guest' ),
            );
        }

        return $rows;
    }

    /**
     * @param int[] $order_ids
     */
    private function get_geo_sales( array $order_ids, int $limit = 8 ): array {
        $countries = array();
        $total_rev = 0.0;

        foreach ( $order_ids as $order_id ) {
            $order = $this->get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            $code = strtoupper( (string) $order->get_billing_country() );
            if ( $code === '' ) {
                $code = 'XX';
            }

            $rev = max( 0, (float) $order->get_total() - (float) $order->get_total_refunded() );

            if ( ! isset( $countries[ $code ] ) ) {
                $countries[ $code ] = array(
                    'code'   => $code,
                    'name'   => $code === 'XX' ? __( 'Unknown', 'sheetsync-for-woocommerce' ) : $this->get_country_name( $code ),
                    'orders' => 0,
                    'revenue'=> 0.0,
                );
            }

            $countries[ $code ]['orders']++;
            $countries[ $code ]['revenue'] += $rev;
            $total_rev += $rev;
        }

        uasort( $countries, fn( $a, $b ) => $b['revenue'] <=> $a['revenue'] );

        $ranked = array();
        $rank   = 1;
        foreach ( array_slice( $countries, 0, $limit, true ) as $row ) {
            $pos = $this->resolve_map_position( '', '', $row['code'] );

            $ranked[] = array(
                'rank'     => $rank++,
                'code'     => $row['code'],
                'name'     => $row['name'],
                'orders'   => $row['orders'],
                'revenue'  => round( $row['revenue'], 2 ),
                'share'    => $total_rev > 0 ? round( ( $row['revenue'] / $total_rev ) * 100, 1 ) : 0,
                'map_left' => $pos['left'],
                'map_top'  => $pos['top'],
            );
        }

        return $ranked;
    }

    /**
     * Revenue and orders grouped by billing city (with state & country).
     *
     * @param int[] $order_ids
     */
    private function get_geo_cities( array $order_ids, int $limit = 10 ): array {
        $cities    = array();
        $total_rev = 0.0;

        foreach ( $order_ids as $order_id ) {
            $order = $this->get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            $loc = $this->parse_order_location( $order );
            $rev = max( 0, (float) $order->get_total() - (float) $order->get_total_refunded() );

            if ( ! isset( $cities[ $loc['key'] ] ) ) {
                $pos = $this->resolve_map_position( $loc['city'], $loc['state_code'], $loc['country_code'] );

                $cities[ $loc['key'] ] = array(
                    'city'         => $loc['city'],
                    'state'        => $loc['state_code'],
                    'state_name'   => $loc['state_name'],
                    'country_code' => $loc['country_code'],
                    'country_name' => $loc['country_name'],
                    'label'        => $loc['label'],
                    'short_label'  => $loc['short_label'],
                    'orders'       => 0,
                    'revenue'      => 0.0,
                    'map_left'     => $pos['left'],
                    'map_top'      => $pos['top'],
                );
            }

            $cities[ $loc['key'] ]['orders']++;
            $cities[ $loc['key'] ]['revenue'] += $rev;
            $total_rev += $rev;
        }

        uasort( $cities, fn( $a, $b ) => $b['revenue'] <=> $a['revenue'] );

        $ranked = array();
        $rank   = 1;

        foreach ( array_slice( $cities, 0, $limit, true ) as $row ) {
            $ranked[] = array(
                'rank'         => $rank++,
                'city'         => $row['city'],
                'state'        => $row['state'],
                'state_name'   => $row['state_name'],
                'country_code' => $row['country_code'],
                'country_name' => $row['country_name'],
                'label'        => $row['label'],
                'short_label'  => $row['short_label'],
                'orders'       => $row['orders'],
                'revenue'      => round( $row['revenue'], 2 ),
                'share'        => $total_rev > 0 ? round( ( $row['revenue'] / $total_rev ) * 100, 1 ) : 0,
                'map_left'     => $row['map_left'],
                'map_top'      => $row['map_top'],
            );
        }

        return $this->spread_map_pins( $ranked );
    }

    /**
     * @param int[] $order_ids
     * @param array<int, array<string, mixed>> $geo_sales
     * @param array<int, array<string, mixed>> $geo_cities
     */
    private function get_geo_summary( array $order_ids, array $geo_sales, array $geo_cities ): array {
        $countries = array();
        $city_keys = array();

        foreach ( $order_ids as $order_id ) {
            $order = $this->get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            $loc = $this->parse_order_location( $order );
            $countries[ $loc['country_code'] ] = true;

            if ( $loc['city'] !== '' || $loc['state_code'] !== '' ) {
                $city_keys[ $loc['key'] ] = true;
            }
        }

        return array(
            'countries'   => count( $countries ),
            'cities'      => count( $city_keys ),
            'top_country' => $geo_sales[0] ?? null,
            'top_city'    => $geo_cities[0] ?? null,
        );
    }

    /**
     * @return array{key:string,city:string,state_code:string,state_name:string,country_code:string,country_name:string,label:string,short_label:string}
     */
    private function parse_order_location( WC_Order $order ): array {
        $city         = trim( (string) $order->get_billing_city() );
        $state_code   = trim( (string) $order->get_billing_state() );
        $country_code = strtoupper( (string) $order->get_billing_country() );

        if ( $country_code === '' ) {
            $country_code = 'XX';
        }

        $country_name = $this->get_country_name( $country_code );
        $state_name   = $this->get_state_name( $country_code, $state_code );

        $parts = array();
        if ( $city !== '' ) {
            $parts[] = $city;
        }
        if ( $state_name !== '' ) {
            $parts[] = $state_name;
        } elseif ( $state_code !== '' ) {
            $parts[] = $state_code;
        }
        $parts[] = $country_name;

        $label       = implode( ', ', $parts );
        $short_label = $city !== '' ? $city : ( $state_name !== '' ? $state_name : $country_name );
        $key         = strtolower( $country_code . '|' . $state_code . '|' . $city );

        if ( $city === '' && $state_code === '' ) {
            $key = 'country:' . $country_code;
        }

        return array(
            'key'          => $key,
            'city'         => $city,
            'state_code'   => $state_code,
            'state_name'   => $state_name,
            'country_code' => $country_code,
            'country_name' => $country_name,
            'label'        => $label,
            'short_label'  => $short_label,
        );
    }

    private function get_state_name( string $country_code, string $state_code ): string {
        if ( $country_code === '' || $state_code === '' ) {
            return '';
        }

        if ( function_exists( 'WC' ) && WC()->countries ) {
            $states = WC()->countries->get_states( $country_code );
            return $states[ $state_code ] ?? $state_code;
        }

        return $state_code;
    }

    /**
     * Resolve billing location to map pin coordinates (global).
     *
     * @return array{left:float,top:float}
     */
    private function resolve_map_position( string $city, string $state, string $country_code ): array {
        if ( ! class_exists( 'SheetSync_Geo_Resolver', false ) ) {
            return array( 'left' => 50.0, 'top' => 50.0 );
        }

        $coords = SheetSync_Geo_Resolver::resolve(
            $city,
            $state,
            $country_code,
            fn( string $code ) => $this->get_country_name( $code )
        );

        return SheetSync_Geo_Resolver::to_map_percent( $coords['lat'], $coords['lon'] );
    }

    /**
     * Push overlapping pins apart so each city remains visible on the map.
     *
     * @param array<int, array<string, mixed>> $pins
     * @return array<int, array<string, mixed>>
     */
    private function spread_map_pins( array $pins, float $min_distance = 6.0 ): array {
        $count = count( $pins );

        for ( $i = 1; $i < $count; $i++ ) {
            for ( $attempt = 0; $attempt < 10; $attempt++ ) {
                $adjusted = false;

                for ( $j = 0; $j < $i; $j++ ) {
                    $dx   = (float) $pins[ $i ]['map_left'] - (float) $pins[ $j ]['map_left'];
                    $dy   = (float) $pins[ $i ]['map_top'] - (float) $pins[ $j ]['map_top'];
                    $dist = sqrt( ( $dx * $dx ) + ( $dy * $dy ) );

                    if ( $dist >= $min_distance ) {
                        continue;
                    }

                    $angle = atan2( $dy ?: 0.15, $dx ?: 0.15 ) + ( M_PI / 5 );
                    $push  = ( $min_distance - $dist ) + 1.2;

                    $pins[ $i ]['map_left'] = max( 4.0, min( 96.0, (float) $pins[ $i ]['map_left'] + ( cos( $angle ) * $push ) ) );
                    $pins[ $i ]['map_top']  = max( 4.0, min( 96.0, (float) $pins[ $i ]['map_top'] + ( sin( $angle ) * $push ) ) );
                    $adjusted               = true;
                }

                if ( ! $adjusted ) {
                    break;
                }
            }

            $pins[ $i ]['map_left'] = round( (float) $pins[ $i ]['map_left'], 1 );
            $pins[ $i ]['map_top']  = round( (float) $pins[ $i ]['map_top'], 1 );
        }

        return $pins;
    }

    /**
     * New vs returning customers per day in the active period window.
     */
    private function get_customer_trend( string $period, array $filters = array() ): array {
        $days = in_array( $period, array( '7days', '30days' ), true )
            ? ( $period === '7days' ? 7 : 30 )
            : 14;

        $window_start = $this->site_relative_datetime( '-' . ( $days - 1 ) . ' days' );
        $known        = $this->get_customer_keys_before( $window_start );
        $results      = array();

        for ( $i = $days - 1; $i >= 0; $i-- ) {
            $timestamp = strtotime( "-{$i} days", $this->site_timestamp() );
            $date      = wp_date( 'Y-m-d', $timestamp );
            $start     = $date . ' 00:00:00';
            $end       = $date . ' 23:59:59';
            $order_ids = $this->filter_order_ids(
                $this->get_order_ids_in_range( $start, $end, $this->get_reportable_statuses() ),
                $filters
            );
            $new       = 0;
            $returning = 0;
            $seen_day  = array();

            foreach ( $order_ids as $order_id ) {
                $order = $this->get_order( $order_id );
                if ( ! $order ) {
                    continue;
                }

                $key = $this->customer_key( $order );
                if ( isset( $seen_day[ $key ] ) ) {
                    continue;
                }
                $seen_day[ $key ] = true;

                if ( isset( $known[ $key ] ) ) {
                    $returning++;
                } else {
                    $new++;
                    $known[ $key ] = true;
                }
            }

            $results[] = array(
                'label'     => wp_date( 'M j', $timestamp ),
                'new'       => $new,
                'returning' => $returning,
            );
        }

        return $results;
    }

    /**
     * Sparkline data derived from recent daily sales.
     *
     * @param array<int, array<string, mixed>> $daily
     */
    private function get_sparkline_series( array $daily ): array {
        $slice = array_slice( $daily, -14 );

        return array(
            'revenue' => array_column( $slice, 'revenue' ),
            'orders'  => array_column( $slice, 'orders' ),
        );
    }

    /**
     * Auto-generated insights from real store data.
     */
    private function get_smart_insights( array $summary, array $top_products, array $top_categories, array $comparison, array $daily ): array {
        $insights = array();

        if ( ! empty( $top_products[0] ) ) {
            $p = $top_products[0];
            $insights[] = array(
                'type'  => 'purple',
                'icon'  => '🏆',
                'title' => __( 'Best Selling Product', 'sheetsync-for-woocommerce' ),
                'body'  => sprintf(
                    /* translators: 1: product name 2: revenue amount */
                    __( '%1$s leads with %2$s revenue in this period.', 'sheetsync-for-woocommerce' ),
                    $p['name'],
                    $this->sheet_money( (float) $p['revenue'] )
                ),
            );
        }

        if ( ! empty( $top_categories[0] ) ) {
            $c = $top_categories[0];
            $insights[] = array(
                'type'  => 'blue',
                'icon'  => '⭐',
                'title' => __( 'Top Category', 'sheetsync-for-woocommerce' ),
                'body'  => sprintf(
                    __( '%1$s is your top category with %2$s in sales.', 'sheetsync-for-woocommerce' ),
                    $c['name'],
                    $this->sheet_money( (float) $c['revenue'] )
                ),
            );
        }

        $best_day = null;
        $best_rev = 0.0;
        foreach ( $daily as $d ) {
            if ( (float) $d['revenue'] > $best_rev ) {
                $best_rev = (float) $d['revenue'];
                $best_day = $d['full_date'] ?? $d['date'];
            }
        }
        if ( $best_day ) {
            $insights[] = array(
                'type'  => 'green',
                'icon'  => '📅',
                'title' => __( 'Best Performing Day', 'sheetsync-for-woocommerce' ),
                'body'  => sprintf(
                    __( '%1$s generated the highest revenue at %2$s.', 'sheetsync-for-woocommerce' ),
                    $best_day,
                    $this->sheet_money( $best_rev )
                ),
            );
        }

        if ( isset( $comparison['net_sales'] ) ) {
            $chg = $comparison['net_sales']['change'];
            $trend = $comparison['net_sales']['trend'];
            $insights[] = array(
                'type'  => $trend === 'up' ? 'green' : 'orange',
                'icon'  => $trend === 'up' ? '📈' : '📉',
                'title' => __( 'Revenue Trend', 'sheetsync-for-woocommerce' ),
                'body'  => sprintf(
                    __( 'Net sales are %1$s%2$s%% compared to the previous period.', 'sheetsync-for-woocommerce' ),
                    $chg >= 0 ? '+' : '',
                    $chg
                ),
            );
        }

        if ( (float) $summary['total_refunds'] > 0 ) {
            $insights[] = array(
                'type'  => 'red',
                'icon'  => '↩️',
                'title' => __( 'Refund Alert', 'sheetsync-for-woocommerce' ),
                'body'  => sprintf(
                    __( 'Total refunds this period: %s. Review refunded orders to reduce churn.', 'sheetsync-for-woocommerce' ),
                    $this->sheet_money( (float) $summary['total_refunds'] )
                ),
            );
        }

        if ( (int) $summary['new_customers'] > 0 ) {
            $insights[] = array(
                'type'  => 'pink',
                'icon'  => '👥',
                'title' => __( 'New Customers', 'sheetsync-for-woocommerce' ),
                'body'  => sprintf(
                    __( 'You acquired %d new customers during this period.', 'sheetsync-for-woocommerce' ),
                    (int) $summary['new_customers']
                ),
            );
        }

        return array_slice( $insights, 0, 6 );
    }

    private function get_country_name( string $code ): string {
        if ( $code === '' ) {
            return __( 'Unknown', 'sheetsync-for-woocommerce' );
        }

        if ( function_exists( 'WC' ) && WC()->countries ) {
            $countries = WC()->countries->get_countries();
            return $countries[ $code ] ?? $code;
        }

        return $code;
    }

    private function customer_initials( string $name ): string {
        $parts = preg_split( '/\s+/', trim( $name ) ) ?: array();
        $initials = '';
        foreach ( array_slice( $parts, 0, 2 ) as $part ) {
            $initials .= strtoupper( substr( $part, 0, 1 ) );
        }
        return $initials ?: 'G';
    }

    // ─── Google Sheets Export ─────────────────────────────────────────────────

    /**
     * Format a number as store currency for sheet export.
     */
    private function sheet_money( float $amount ): string {
        $symbol = html_entity_decode( get_woocommerce_currency_symbol() );
        return $symbol . number_format( $amount, 2 );
    }

    /**
     * Format period-over-period change for sheet cells.
     *
     * @param array<string, mixed>|null $comp
     */
    private function sheet_trend( ?array $comp ): string {
        if ( empty( $comp ) || ! isset( $comp['change'] ) ) {
            return '—';
        }

        $arrow = ( $comp['trend'] ?? 'up' ) === 'up' ? '↑' : '↓';
        $sign  = (float) $comp['change'] >= 0 ? '+' : '';

        return $arrow . ' ' . $sign . $comp['change'] . '%';
    }

    /**
     * @param array<string, mixed>|null $comp
     */
    private function sheet_prev_value( ?array $comp, callable $formatter ): string {
        if ( empty( $comp ) || ! isset( $comp['previous'] ) ) {
            return '—';
        }

        return $formatter( (float) $comp['previous'] );
    }

    /**
     * Export the full sales dashboard to a Google Sheet with full styling.
     */
    public function export_to_sheets( string $spreadsheet_id, string $sheet_name, string $period, array $filters = array() ): array {
        $client = new SheetSync_Sheets_Client();
        $data   = $this->get_dashboard_data( $period, $filters );

        $client->ensure_sheet_exists( $spreadsheet_id, $sheet_name );

        $rows = array();
        $map  = array();
        $s    = $data['summary'];
        $qs   = $data['quick_stats'];
        $cmp  = $data['comparison'];
        $inv  = $data['inventory'];

        $rows[] = array( '📊 SheetSync PRO — Sales Dashboard', '', '', '', '', '', '', '' );
        $rows[] = array(
            'Generated: ' . gmdate( 'M j, Y g:i A' ) . ' UTC',
            '',
            'Period: ' . $s['period_label'],
            '',
            'Currency: ' . get_woocommerce_currency(),
            '',
            'Store: ' . get_bloginfo( 'name' ),
            '',
        );
        $map['legend'] = count( $rows );
        $rows[] = array(
            'ℹ️ How to read: Revenue & profit = PAID orders only (Processing + Completed). '
            . 'Total Orders = all WooCommerce admin statuses (includes Cancelled). '
            . 'Pending revenue (On Hold + Pending): '
            . $this->sheet_money( (float) ( $s['pending_revenue'] ?? 0 ) )
            . ' | Paid: ' . (int) ( $s['paid_orders'] ?? 0 )
            . ' / All: ' . (int) $s['total_orders']
            . ' | Customers: ' . (int) ( $s['total_customers'] ?? 0 ),
            '', '', '', '', '', '', '',
        );
        $rows[] = array();

        // 12 KPIs — matches dashboard cards
        $map['kpi_header'] = count( $rows );
        $rows[] = array( '📈 KEY PERFORMANCE INDICATORS', '', '', '', '', '', '', '' );
        $map['kpi_col_header'] = count( $rows );
        $rows[] = array( 'Metric', 'Current Value', 'Previous Period', 'Change', 'Notes', '', '', '' );
        $map['kpi_data_start'] = count( $rows );

        $kpi_rows = array(
            array( 'Total Revenue', $this->sheet_money( (float) $s['net_sales'] ), $cmp['net_sales'] ?? null, 'Paid order revenue' ),
            array( 'Net Profit', $this->sheet_money( (float) $s['net_profit'] ), $cmp['net_profit'] ?? null, 'Revenue minus tax & shipping' ),
            array( 'Total Orders', (string) $s['total_orders'], $cmp['total_orders'] ?? null, 'All active statuses' ),
            array( 'Avg. Order Value', $this->sheet_money( (float) $s['avg_order_value'] ), $cmp['avg_order_value'] ?? null, 'Based on paid orders' ),
            array( 'Total Customers', (string) $s['total_customers'], $cmp['total_customers'] ?? null, 'Unique buyers in period' ),
            array( 'Returning Customers', (string) $s['returning_customers'], $cmp['returning_customers'] ?? null, 'Bought before this period' ),
            array( 'Repeat Purchase Rate', $s['repeat_purchase_rate'] . '%', $cmp['conversion_rate'] ?? null, 'Returning ÷ total customers' ),
            array( 'Refund Amount', $this->sheet_money( (float) $s['total_refunds'] ), $cmp['total_refunds'] ?? null, 'Total refunded' ),
            array( 'Products Sold', (string) $s['total_items'], $cmp['total_items'] ?? null, 'Line item quantity' ),
            array( 'Revenue Growth', $this->sheet_trend( $cmp['net_sales'] ?? null ), null, 'vs previous period' ),
            array( 'Profit Growth', $this->sheet_trend( $cmp['net_profit'] ?? null ), null, 'vs previous period' ),
            array( 'Customer Growth', $this->sheet_trend( $cmp['total_customers'] ?? null ), null, 'vs previous period' ),
        );

        foreach ( $kpi_rows as $kpi ) {
            $comp = $kpi[2];
            $prev = '—';
            if ( is_array( $comp ) && isset( $comp['previous'] ) ) {
                if ( str_contains( $kpi[0], 'Rate' ) || str_contains( $kpi[0], 'Growth' ) ) {
                    $prev = str_contains( $kpi[0], 'Rate' )
                        ? $comp['previous'] . '%'
                        : $this->sheet_trend( $comp );
                } elseif ( str_contains( $kpi[0], 'Orders' ) || str_contains( $kpi[0], 'Customers' ) || str_contains( $kpi[0], 'Sold' ) ) {
                    $prev = (string) (int) $comp['previous'];
                } else {
                    $prev = $this->sheet_money( (float) $comp['previous'] );
                }
            }

            $change = is_array( $comp ) ? $this->sheet_trend( $comp ) : ( $kpi[1] ?? '—' );
            if ( str_contains( $kpi[0], 'Growth' ) ) {
                $prev   = '—';
                $change = $kpi[1];
            }

            $rows[] = array( $kpi[0], $kpi[1], $prev, $change, $kpi[3], '', '', '' );
        }
        $map['kpi_data_end'] = count( $rows ) - 1;
        $rows[] = array();

        // Customer analytics
        $map['customer_header'] = count( $rows );
        $rows[] = array( '👥 CUSTOMER ANALYTICS', '', '', '', '', '', '', '' );
        $map['customer_col_header'] = count( $rows );
        $rows[] = array( 'Metric', 'Value', 'Description', '', '', '', '', '' );
        $map['customer_data_start'] = count( $rows );
        $rows[] = array( 'Total Customers', $s['total_customers'], 'Unique customers who placed orders', '', '', '', '', '' );
        $rows[] = array( 'New Customers', $s['new_customers'], 'First-time buyers in this period', '', '', '', '', '' );
        $rows[] = array( 'Returning Customers', $s['returning_customers'], 'Customers who ordered before this period', '', '', '', '', '' );
        $rows[] = array( 'Repeat Purchase Rate', $s['repeat_purchase_rate'] . '%', 'Percentage of returning customers', '', '', '', '', '' );
        $rows[] = array( 'Avg. Customer Lifetime Value', $this->sheet_money( (float) $s['avg_clv'] ), 'Average revenue per customer (paid orders)', '', '', '', '', '' );
        $map['customer_data_end'] = count( $rows ) - 1;
        $rows[] = array();

        // SUMMARY (detailed breakdown)
        $map['summary_header'] = count( $rows );
        $rows[] = array( '💰 REVENUE BREAKDOWN', '', '', '', '', '', '', '' );
        $map['summary_col_header'] = count( $rows );
        $rows[] = array( 'Net Sales', 'Gross Sales', 'Total Orders', 'Paid Orders', 'Avg Order Value', 'Items Sold', 'Refunds', 'Pending Revenue' );
        $map['summary_data'] = count( $rows );
        $rows[] = array(
            $this->sheet_money( (float) $s['net_sales'] ),
            $this->sheet_money( (float) $s['gross_sales'] ),
            $s['total_orders'],
            $s['paid_orders'] ?? 0,
            $this->sheet_money( (float) $s['avg_order_value'] ),
            $s['total_items'],
            $this->sheet_money( (float) $s['total_refunds'] ),
            $this->sheet_money( (float) ( $s['pending_revenue'] ?? 0 ) ),
        );
        $map['summary_data2'] = count( $rows );
        $rows[] = array(
            'Discounts: ' . $this->sheet_money( (float) $s['total_discounts'] ),
            'Tax: ' . $this->sheet_money( (float) $s['total_tax'] ),
            'Shipping: ' . $this->sheet_money( (float) $s['total_shipping'] ),
            'Best Month: ' . ( $s['best_month'] ?: '—' ),
            $this->sheet_money( (float) $s['best_month_rev'] ),
            'Net Profit: ' . $this->sheet_money( (float) $s['net_profit'] ),
            '', '',
        );
        $rows[] = array();

        // QUICK STATS
        $map['quick_header'] = count( $rows );
        $rows[] = array( '⚡ QUICK STATS', '', '', '', '', '', '', '' );
        $map['quick_col_header'] = count( $rows );
        $rows[] = array( 'Period', 'Revenue', 'Orders', 'Items Sold', '', '', '', '' );
        $map['quick_data_start'] = count( $rows );
        foreach ( array( 'today' => 'Today', 'week' => 'This Week', 'month' => 'This Month' ) as $key => $label ) {
            $stat = $qs[ $key ] ?? array( 'revenue' => 0, 'orders' => 0, 'items' => 0 );
            $rows[] = array(
                $label,
                $this->sheet_money( (float) $stat['revenue'] ),
                $stat['orders'],
                $stat['items'],
                '', '', '', '',
            );
        }
        $map['quick_data_end'] = count( $rows ) - 1;
        $rows[] = array();

        // AI INSIGHTS
        if ( ! empty( $data['insights'] ) ) {
            $map['insights_header'] = count( $rows );
            $rows[] = array( '💡 AI INSIGHTS', '', '', '', '', '', '', '' );
            $map['insights_col_header'] = count( $rows );
            $rows[] = array( 'Insight', 'Details', '', '', '', '', '', '' );
            $map['insights_data_start'] = count( $rows );
            foreach ( $data['insights'] as $insight ) {
                $rows[] = array(
                    ( $insight['icon'] ?? '•' ) . ' ' . ( $insight['title'] ?? '' ),
                    $insight['body'] ?? '',
                    '', '', '', '', '', '',
                );
            }
            $map['insights_data_end'] = count( $rows ) - 1;
            $rows[] = array();
        }

        // GEOGRAPHIC SALES
        if ( ! empty( $data['geo_sales'] ) ) {
            $map['geo_header'] = count( $rows );
            $rows[] = array( '🌍 GEOGRAPHIC SALES', '', '', '', '', '', '', '' );
            $map['geo_col_header'] = count( $rows );
            $rows[] = array( '#', 'Country', 'Orders', 'Revenue', 'Share %', '', '', '' );
            $map['geo_data_start'] = count( $rows );
            foreach ( $data['geo_sales'] as $g ) {
                $rows[] = array(
                    $g['rank'],
                    $g['name'] . ' (' . $g['code'] . ')',
                    $g['orders'],
                    $this->sheet_money( (float) $g['revenue'] ),
                    $g['share'] . '%',
                    '', '', '',
                );
            }
            $map['geo_data_end'] = count( $rows ) - 1;
            $rows[] = array();
        }

        if ( ! empty( $data['geo_cities'] ) ) {
            $map['geo_cities_header'] = count( $rows );
            $rows[] = array( '📍 TOP CITIES & LOCATIONS', '', '', '', '', '', '', '' );
            $map['geo_cities_col_header'] = count( $rows );
            $rows[] = array( '#', 'Location', 'City', 'State', 'Country', 'Orders', 'Revenue', 'Share %' );
            $map['geo_cities_data_start'] = count( $rows );
            foreach ( $data['geo_cities'] as $c ) {
                $rows[] = array(
                    $c['rank'],
                    $c['label'],
                    $c['city'] ?: '—',
                    $c['state_name'] ?: ( $c['state'] ?: '—' ),
                    $c['country_name'] . ' (' . $c['country_code'] . ')',
                    $c['orders'],
                    $this->sheet_money( (float) $c['revenue'] ),
                    $c['share'] . '%',
                );
            }
            $map['geo_cities_data_end'] = count( $rows ) - 1;
            $rows[] = array();
        }

        // ORDER FUNNEL
        if ( ! empty( $data['funnel'] ) ) {
            $map['funnel_header'] = count( $rows );
            $rows[] = array( '🔽 ORDER FUNNEL', '', '', '', '', '', '', '' );
            $map['funnel_col_header'] = count( $rows );
            $rows[] = array( 'Status', 'Orders', 'Share %', 'Revenue', '', '', '', '' );
            $map['funnel_data_start'] = count( $rows );
            foreach ( $data['funnel'] as $step ) {
                $rows[] = array(
                    $step['label'],
                    $step['value'],
                    $step['pct'] . '%',
                    $this->sheet_money( (float) $step['revenue'] ),
                    '', '', '', '',
                );
            }
            $map['funnel_data_end'] = count( $rows ) - 1;
            $rows[] = array();
        }

        // SALES FORECAST
        $forecast = $data['forecast'];
        if ( ! empty( $forecast['labels'] ) ) {
            $map['forecast_header'] = count( $rows );
            $rows[] = array( '🔮 SALES FORECAST (Next 7 Days)', '', '', '', '', '', '', '' );
            $map['forecast_col_header'] = count( $rows );
            $rows[] = array( 'Date', 'Actual Revenue', 'Predicted Revenue', '', '', '', '', '' );
            $map['forecast_data_start'] = count( $rows );
            foreach ( $forecast['labels'] as $i => $label ) {
                $actual    = $forecast['historical'][ $i ] ?? null;
                $predicted = $forecast['predicted'][ $i ] ?? null;
                $rows[] = array(
                    $label,
                    $actual !== null ? $this->sheet_money( (float) $actual ) : '—',
                    $predicted !== null ? $this->sheet_money( (float) $predicted ) : '—',
                    '', '', '', '', '',
                );
            }
            $map['forecast_data_end'] = count( $rows ) - 1;
            $rows[] = array();
        }

        // INVENTORY SNAPSHOT
        $map['inventory_header'] = count( $rows );
        $rows[] = array( '📦 INVENTORY SNAPSHOT', '', '', '', '', '', '', '' );
        $map['inventory_col_header'] = count( $rows );
        $rows[] = array( 'Total Products', 'Low Stock (≤5)', 'Out of Stock', 'Alert Product', 'Qty', 'Status', '', '' );
        $map['inventory_data_start'] = count( $rows );
        $rows[] = array(
            $inv['total'] ?? 0,
            $inv['low_stock'] ?? 0,
            $inv['out_stock'] ?? 0,
            '', '', '', '', '',
        );
        foreach ( $inv['alerts'] ?? array() as $alert ) {
            $rows[] = array(
                '',
                '',
                '',
                $alert['name'],
                $alert['qty'],
                $alert['status'] === 'outofstock' ? 'Out of Stock' : 'Low Stock',
                '', '',
            );
        }
        $map['inventory_data_end'] = count( $rows ) - 1;
        $rows[] = array();

        // SALES TREND
        $map['monthly_header'] = count( $rows );
        $rows[] = array( '📅 SALES TREND', '', '', '', '', '', '', '' );
        $map['monthly_col_header'] = count( $rows );
        $rows[] = array( 'Period', 'Orders', 'Revenue', '', '', '', '', '' );
        $map['monthly_data_start'] = count( $rows );
        $trend = in_array( $period, array( '7days', '30days' ), true ) ? $data['daily'] : $data['monthly'];
        foreach ( $trend as $m ) {
            $label = $m['month'] ?? $m['full_date'] ?? $m['date'];
            $rows[] = array( $label, $m['orders'], $this->sheet_money( (float) $m['revenue'] ), '', '', '', '', '' );
        }
        $map['monthly_data_end'] = count( $rows ) - 1;
        $rows[] = array();

        // DAILY SALES
        $map['daily_header'] = count( $rows );
        $rows[] = array( '📆 DAILY SALES (Last 30 Days)', '', '', '', '', '', '', '' );
        $map['daily_col_header'] = count( $rows );
        $rows[] = array( 'Date', 'Orders', 'Revenue', 'Avg Order Value', '', '', '', '' );
        $map['daily_data_start'] = count( $rows );
        $total_orders = 0;
        $total_rev    = 0;
        foreach ( $data['daily'] as $d ) {
            $total_orders += $d['orders'];
            $total_rev    += $d['revenue'];
            $rows[] = array(
                $d['full_date'] ?? $d['date'],
                $d['orders'],
                $this->sheet_money( (float) $d['revenue'] ),
                $this->sheet_money( (float) $d['avg_order_value'] ),
                '', '', '', '',
            );
        }
        $map['daily_data_end'] = count( $rows ) - 1;
        $map['daily_total'] = count( $rows );
        $rows[] = array(
            'TOTAL',
            $total_orders,
            $this->sheet_money( $total_rev ),
            $total_orders > 0 ? $this->sheet_money( $total_rev / $total_orders ) : $this->sheet_money( 0 ),
            '', '', '', '',
        );
        $rows[] = array();

        // TOP PRODUCTS
        $map['products_header'] = count( $rows );
        $rows[] = array( '🏆 TOP PRODUCTS', '', '', '', '', '', '', '' );
        $map['products_col_header'] = count( $rows );
        $rows[] = array( '#', 'Product', 'Quantity Sold', 'Revenue', '', '', '', '' );
        $map['products_data_start'] = count( $rows );
        foreach ( $data['top_products'] as $p ) {
            $rows[] = array(
                $p['rank'],
                $p['name'],
                $p['quantity'],
                $this->sheet_money( (float) $p['revenue'] ),
                '', '', '', '',
            );
        }
        $map['products_data_end'] = count( $rows ) - 1;
        $rows[] = array();

        // TOP CATEGORIES
        $map['categories_header'] = count( $rows );
        $rows[] = array( '📂 TOP CATEGORIES', '', '', '', '', '', '', '' );
        $map['categories_col_header'] = count( $rows );
        $rows[] = array( '#', 'Category', 'Quantity Sold', 'Revenue', '', '', '', '' );
        $map['categories_data_start'] = count( $rows );
        foreach ( $data['top_categories'] as $c ) {
            $rows[] = array(
                $c['rank'],
                $c['name'],
                $c['quantity'],
                $this->sheet_money( (float) $c['revenue'] ),
                '', '', '', '',
            );
        }
        $map['categories_data_end'] = count( $rows ) - 1;
        $rows[] = array();

        // PAYMENT METHODS
        $map['payments_header'] = count( $rows );
        $rows[] = array( '💳 PAYMENT METHODS', '', '', '', '', '', '', '' );
        $map['payments_col_header'] = count( $rows );
        $rows[] = array( 'Method', 'Orders', 'Revenue', 'Share %', '', '', '', '' );
        $map['payments_data_start'] = count( $rows );
        foreach ( $data['payment_methods'] as $pm ) {
            $rows[] = array(
                $pm['name'],
                $pm['orders'],
                $this->sheet_money( (float) $pm['revenue'] ),
                $pm['share'] . '%',
                '', '', '', '',
            );
        }
        $map['payments_data_end'] = count( $rows ) - 1;
        $rows[] = array();

        // ORDER STATUS
        $map['status_header'] = count( $rows );
        $rows[] = array( '📋 ORDER STATUS', '', '', '', '', '', '', '' );
        $map['status_col_header'] = count( $rows );
        $rows[] = array( 'Status', 'Orders', 'Revenue', '', '', '', '', '' );
        $map['status_data_start'] = count( $rows );
        foreach ( $data['order_statuses'] as $st ) {
            $rows[] = array(
                $st['label'],
                $st['count'],
                $this->sheet_money( (float) $st['revenue'] ),
                '', '', '', '', '',
            );
        }
        $map['status_data_end'] = count( $rows ) - 1;
        $rows[] = array();

        // RECENT ORDERS
        $map['recent_header'] = count( $rows );
        $rows[] = array( '🧾 RECENT ORDERS', '', '', '', '', '', '', '' );
        $map['recent_col_header'] = count( $rows );
        $rows[] = array( 'Order #', 'Date', 'Customer', 'Email', 'Status', 'Total', 'Products', 'Payment', 'Country' );
        $map['recent_data_start'] = count( $rows );
        foreach ( $data['recent_orders'] as $o ) {
            $rows[] = array(
                $o['number'],
                $o['date'],
                $o['customer'],
                $o['email'],
                $o['status'],
                $this->sheet_money( (float) $o['total'] ),
                $o['items'] ?? '',
                $o['payment'] ?? '—',
                $o['country_name'] ?? '',
            );
        }
        $map['recent_data_end'] = count( $rows ) - 1;
        $map['total_rows']      = count( $rows );

        $client->reset_sheet_for_dashboard_export( $spreadsheet_id, $sheet_name, count( $rows ) );

        $range = $sheet_name . '!A1';
        $client->set_rows( $spreadsheet_id, $range, $rows );

        try {
            $sheet_id = $client->get_sheet_id( $spreadsheet_id, $sheet_name );
            $this->apply_dashboard_formatting( $client, $spreadsheet_id, $sheet_id, $map );
        } catch ( \Exception $e ) {
            SheetSync_Logger::info( 'Sales Dashboard styling skipped: ' . $e->getMessage(), null, 0 );
        }

        SheetSync_Logger::info(
            sprintf(
                'Sales Dashboard exported: %d KPIs, %d trend, %d daily, %d products, %d orders.',
                12,
                count( $trend ),
                count( $data['daily'] ),
                count( $data['top_products'] ),
                count( $data['recent_orders'] )
            ),
            null,
            count( $rows )
        );

        return array(
            'message'          => __( 'Full Sales Dashboard exported to Google Sheets!', 'sheetsync-for-woocommerce' ),
            'rows_written'     => count( $rows ),
            'monthly_count'    => count( $trend ),
            'daily_count'      => count( $data['daily'] ),
            'products_count'   => count( $data['top_products'] ),
            'categories_count' => count( $data['top_categories'] ),
            'orders_count'     => count( $data['recent_orders'] ),
            'sheet_url'        => 'https://docs.google.com/spreadsheets/d/' . rawurlencode( $spreadsheet_id ) . '/edit',
        );
    }

    /**
     * Apply all formatting requests via batchUpdate (WooAnalytics purple theme).
     */
    private function apply_dashboard_formatting( SheetSync_Sheets_Client $client, string $spreadsheet_id, int $sheet_id, array $map ): void {
        $requests = array();
        $max_cols = 9;

        $color = fn( float $r, float $g, float $b ) => array(
            'red' => $r, 'green' => $g, 'blue' => $b, 'alpha' => 1.0,
        );
        $range_req = fn( int $sr, int $er, int $sc = 0, ?int $ec = null ) => array(
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

        // Title row — purple banner
        $requests[] = array(
            'mergeCells' => array(
                'range'      => $range_req( 0, 0, 0, $max_cols ),
                'mergeType'  => 'MERGE_ALL',
            ),
        );
        $requests[] = array(
            'repeatCell' => array(
                'range'  => $range_req( 0, 0, 0, $max_cols ),
                'cell'   => array( 'userEnteredFormat' => array(
                    'backgroundColor' => $purple,
                    'textFormat'      => array( 'bold' => true, 'fontSize' => 16, 'foregroundColor' => $white ),
                    'horizontalAlignment' => 'CENTER',
                    'verticalAlignment'   => 'MIDDLE',
                ) ),
                'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment,verticalAlignment)',
            ),
        );

        // Meta row
        $requests[] = array(
            'repeatCell' => array(
                'range'  => $range_req( 1, 1, 0, $max_cols ),
                'cell'   => array( 'userEnteredFormat' => array(
                    'backgroundColor' => $purple_light,
                    'textFormat'      => array( 'fontSize' => 10, 'foregroundColor' => $purple_dark ),
                ) ),
                'fields' => 'userEnteredFormat(backgroundColor,textFormat)',
            ),
        );

        // Legend row
        if ( isset( $map['legend'] ) ) {
            $requests[] = array(
                'mergeCells' => array(
                    'range'     => $range_req( $map['legend'], $map['legend'], 0, $max_cols ),
                    'mergeType' => 'MERGE_ALL',
                ),
            );
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => $range_req( $map['legend'], $map['legend'], 0, $max_cols ),
                    'cell'   => array( 'userEnteredFormat' => array(
                        'backgroundColor' => $color( 1.0, 0.98, 0.94 ),
                        'textFormat'      => array( 'italic' => true, 'fontSize' => 9, 'foregroundColor' => $color( 0.4, 0.35, 0.5 ) ),
                        'wrapStrategy'    => 'WRAP',
                    ) ),
                    'fields' => 'userEnteredFormat(backgroundColor,textFormat,wrapStrategy)',
                ),
            );
        }

        $section_rows = array(
            $map['kpi_header'] ?? null,
            $map['customer_header'] ?? null,
            $map['summary_header'],
            $map['quick_header'] ?? null,
            $map['insights_header'] ?? null,
            $map['geo_header'] ?? null,
            $map['geo_cities_header'] ?? null,
            $map['funnel_header'] ?? null,
            $map['forecast_header'] ?? null,
            $map['inventory_header'] ?? null,
            $map['monthly_header'],
            $map['daily_header'],
            $map['products_header'],
            $map['categories_header'] ?? null,
            $map['payments_header'] ?? null,
            $map['status_header'] ?? null,
            $map['recent_header'] ?? null,
        );
        foreach ( array_filter( $section_rows ) as $sr ) {
            $requests[] = array(
                'mergeCells' => array(
                    'range'     => $range_req( $sr, $sr, 0, $max_cols ),
                    'mergeType' => 'MERGE_ALL',
                ),
            );
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => $range_req( $sr, $sr, 0, $max_cols ),
                    'cell'   => array( 'userEnteredFormat' => array(
                        'backgroundColor' => $purple_dark,
                        'textFormat'      => array( 'bold' => true, 'fontSize' => 12, 'foregroundColor' => $white ),
                    ) ),
                    'fields' => 'userEnteredFormat(backgroundColor,textFormat)',
                ),
            );
        }

        $col_header_rows = array(
            $map['kpi_col_header'] ?? null,
            $map['customer_col_header'] ?? null,
            $map['summary_col_header'],
            $map['quick_col_header'] ?? null,
            $map['insights_col_header'] ?? null,
            $map['geo_col_header'] ?? null,
            $map['geo_cities_col_header'] ?? null,
            $map['funnel_col_header'] ?? null,
            $map['forecast_col_header'] ?? null,
            $map['inventory_col_header'] ?? null,
            $map['monthly_col_header'],
            $map['daily_col_header'],
            $map['products_col_header'],
            $map['categories_col_header'] ?? null,
            $map['payments_col_header'] ?? null,
            $map['status_col_header'] ?? null,
            $map['recent_col_header'] ?? null,
        );
        foreach ( array_filter( $col_header_rows ) as $cr ) {
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => $range_req( $cr, $cr, 0, $max_cols ),
                    'cell'   => array( 'userEnteredFormat' => array(
                        'backgroundColor' => $purple,
                        'textFormat'      => array( 'bold' => true, 'foregroundColor' => $white ),
                        'horizontalAlignment' => 'CENTER',
                    ) ),
                    'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment)',
                ),
            );
        }

        // KPI highlight — first row only (alternating handled below).
        if ( isset( $map['kpi_data_start'] ) ) {
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => $range_req( $map['kpi_data_start'], $map['kpi_data_start'], 0, 5 ),
                    'cell'   => array( 'userEnteredFormat' => array(
                        'textFormat' => array( 'bold' => true ),
                    ) ),
                    'fields' => 'userEnteredFormat(textFormat)',
                ),
            );
        }

        if ( isset( $map['summary_data'] ) ) {
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => $range_req( $map['summary_data'], $map['summary_data2'] ?? $map['summary_data'], 0, $max_cols ),
                    'cell'   => array( 'userEnteredFormat' => array(
                        'backgroundColor' => $color( 0.85, 1.0, 0.95 ),
                        'textFormat'      => array( 'bold' => true, 'fontSize' => 11 ),
                        'horizontalAlignment' => 'CENTER',
                    ) ),
                    'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment)',
                ),
            );
        }

        $alt_ranges = array(
            array( 'kpi_data_start', 'kpi_data_end', 5 ),
            array( 'customer_data_start', 'customer_data_end', 3 ),
            array( 'insights_data_start', 'insights_data_end', 2 ),
            array( 'geo_data_start', 'geo_data_end', 5 ),
            array( 'geo_cities_data_start', 'geo_cities_data_end', 8 ),
            array( 'funnel_data_start', 'funnel_data_end', 4 ),
            array( 'forecast_data_start', 'forecast_data_end', 3 ),
            array( 'inventory_data_start', 'inventory_data_end', 6 ),
            array( 'monthly_data_start', 'monthly_data_end', 4 ),
            array( 'quick_data_start', 'quick_data_end', 4 ),
            array( 'daily_data_start', 'daily_data_end', 4 ),
            array( 'products_data_start', 'products_data_end', 4 ),
            array( 'categories_data_start', 'categories_data_end', 4 ),
            array( 'payments_data_start', 'payments_data_end', 4 ),
            array( 'status_data_start', 'status_data_end', 4 ),
            array( 'recent_data_start', 'recent_data_end', $max_cols ),
        );

        foreach ( $alt_ranges as $range_def ) {
            $start_key = $range_def[0];
            $end_key   = $range_def[1];
            $cols      = $range_def[2];
            if ( ! isset( $map[ $start_key ], $map[ $end_key ] ) ) {
                continue;
            }
            for ( $i = $map[ $start_key ]; $i <= $map[ $end_key ]; $i++ ) {
                $bg = ( ( $i - $map[ $start_key ] ) % 2 === 0 )
                    ? $color( 0.97, 0.97, 0.99 )
                    : $white;
                $requests[] = array(
                    'repeatCell' => array(
                        'range'  => $range_req( $i, $i, 0, $cols ),
                        'cell'   => array( 'userEnteredFormat' => array( 'backgroundColor' => $bg ) ),
                        'fields' => 'userEnteredFormat(backgroundColor)',
                    ),
                );
            }
        }

        if ( isset( $map['products_data_start'], $map['products_data_end'] )
            && $map['products_data_start'] <= $map['products_data_end'] ) {
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => $range_req( $map['products_data_start'], $map['products_data_start'], 0, 4 ),
                    'cell'   => array( 'userEnteredFormat' => array(
                        'backgroundColor' => $color( 1.0, 0.94, 0.60 ),
                        'textFormat'      => array( 'bold' => true ),
                    ) ),
                    'fields' => 'userEnteredFormat(backgroundColor,textFormat)',
                ),
            );
        }

        if ( isset( $map['daily_total'] ) ) {
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => $range_req( $map['daily_total'], $map['daily_total'], 0, 4 ),
                    'cell'   => array( 'userEnteredFormat' => array(
                        'backgroundColor' => $color( 0.85, 1.0, 0.95 ),
                        'textFormat'      => array( 'bold' => true ),
                    ) ),
                    'fields' => 'userEnteredFormat(backgroundColor,textFormat)',
                ),
            );
        }

        $col_widths = array( 180, 150, 150, 120, 300, 140, 200, 150, 130 );
        foreach ( $col_widths as $ci => $px ) {
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

        // Row heights — title, meta, section headers.
        $row_heights = array(
            0 => 46,
            1 => 28,
        );
        foreach ( array_filter( $section_rows ) as $sr ) {
            $row_heights[ $sr ] = 32;
        }
        foreach ( array_filter( $col_header_rows ) as $cr ) {
            $row_heights[ $cr ] = 26;
        }
        foreach ( $row_heights as $ri => $px ) {
            $requests[] = array(
                'updateDimensionProperties' => array(
                    'range'      => array(
                        'sheetId'    => $sheet_id,
                        'dimension'  => 'ROWS',
                        'startIndex' => $ri,
                        'endIndex'   => $ri + 1,
                    ),
                    'properties' => array( 'pixelSize' => $px ),
                    'fields'     => 'pixelSize',
                ),
            );
        }

        // Light borders around each data table (header row through last data row).
        $border_color = $color( 0.82, 0.82, 0.88 );
        $border_style = array( 'style' => 'SOLID', 'width' => 1, 'color' => $border_color );
        $table_ranges = array(
            array( 'kpi_col_header', 'kpi_data_end', 5 ),
            array( 'customer_col_header', 'customer_data_end', 3 ),
            array( 'summary_col_header', 'summary_data2', 8 ),
            array( 'quick_col_header', 'quick_data_end', 4 ),
            array( 'insights_col_header', 'insights_data_end', 2 ),
            array( 'geo_col_header', 'geo_data_end', 5 ),
            array( 'geo_cities_col_header', 'geo_cities_data_end', 8 ),
            array( 'funnel_col_header', 'funnel_data_end', 4 ),
            array( 'forecast_col_header', 'forecast_data_end', 3 ),
            array( 'inventory_col_header', 'inventory_data_end', 6 ),
            array( 'monthly_col_header', 'monthly_data_end', 4 ),
            array( 'daily_col_header', 'daily_data_end', 4 ),
            array( 'products_col_header', 'products_data_end', 4 ),
            array( 'categories_col_header', 'categories_data_end', 4 ),
            array( 'payments_col_header', 'payments_data_end', 4 ),
            array( 'status_col_header', 'status_data_end', 4 ),
            array( 'recent_col_header', 'recent_data_end', $max_cols ),
        );
        foreach ( $table_ranges as $table_def ) {
            $start_key = $table_def[0];
            $end_key   = $table_def[1];
            $cols      = $table_def[2];
            if ( ! isset( $map[ $start_key ], $map[ $end_key ] ) || $map[ $start_key ] > $map[ $end_key ] ) {
                continue;
            }
            $requests[] = array(
                'updateBorders' => array(
                    'range'  => $range_req( $map[ $start_key ], $map[ $end_key ], 0, $cols ),
                    'top'    => $border_style,
                    'bottom' => $border_style,
                    'left'   => $border_style,
                    'right'  => $border_style,
                    'innerHorizontal' => $border_style,
                    'innerVertical'   => $border_style,
                ),
            );
        }

        // Wrap long text in notes / product lists / insight details.
        if ( isset( $map['kpi_data_start'], $map['kpi_data_end'] ) ) {
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => $range_req( $map['kpi_data_start'], $map['kpi_data_end'], 4, 5 ),
                    'cell'   => array( 'userEnteredFormat' => array( 'wrapStrategy' => 'WRAP' ) ),
                    'fields' => 'userEnteredFormat.wrapStrategy',
                ),
            );
        }
        if ( isset( $map['insights_data_start'], $map['insights_data_end'] ) ) {
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => $range_req( $map['insights_data_start'], $map['insights_data_end'], 1, 2 ),
                    'cell'   => array( 'userEnteredFormat' => array( 'wrapStrategy' => 'WRAP' ) ),
                    'fields' => 'userEnteredFormat.wrapStrategy',
                ),
            );
        }
        if ( isset( $map['recent_data_start'], $map['recent_data_end'] ) ) {
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => $range_req( $map['recent_data_start'], $map['recent_data_end'], 6, 7 ),
                    'cell'   => array( 'userEnteredFormat' => array( 'wrapStrategy' => 'WRAP' ) ),
                    'fields' => 'userEnteredFormat.wrapStrategy',
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

        // Strip leftover rows from older, longer exports.
        if ( ! empty( $map['total_rows'] ) ) {
            $tail_start = (int) $map['total_rows'];
            $tail_end   = min( $tail_start + 200, 2000 );
            if ( $tail_start < $tail_end ) {
                $requests[] = array(
                    'repeatCell' => array(
                        'range'  => array(
                            'sheetId'          => $sheet_id,
                            'startRowIndex'    => $tail_start,
                            'endRowIndex'      => $tail_end,
                            'startColumnIndex' => 0,
                            'endColumnIndex'   => $max_cols,
                        ),
                        'cell'   => array(
                            'userEnteredFormat' => array(
                                'backgroundColor' => $white,
                                'textFormat'      => array(
                                    'bold'            => false,
                                    'foregroundColor' => $color( 0, 0, 0 ),
                                ),
                            ),
                        ),
                        'fields' => 'userEnteredFormat(backgroundColor,textFormat)',
                    ),
                );
                $requests[] = array(
                    'updateCells' => array(
                        'range'  => array(
                            'sheetId'          => $sheet_id,
                            'startRowIndex'    => $tail_start,
                            'endRowIndex'      => $tail_end,
                            'startColumnIndex' => 0,
                            'endColumnIndex'   => $max_cols,
                        ),
                        'fields' => 'userEnteredValue',
                    ),
                );
            }
        }

        $client->batch_update_requests( $spreadsheet_id, $requests );
    }
}
