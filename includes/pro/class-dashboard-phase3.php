<?php
/**
 * Dashboard Phase 3: COGS, variation inventory, ML forecast, multisite, webhooks, white-label, PWA.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Dashboard_Phase3' ) ) :

class SheetSync_Dashboard_Phase3 {

    public const COGS_META = '_sheetsync_cogs';

    public function __construct() {
        add_action( 'woocommerce_product_options_pricing', array( $this, 'render_product_cogs_field' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_cogs_field' ) );
        add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render_variation_cogs_field' ), 10, 3 );
        add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_cogs_field' ), 10, 2 );

        add_action( 'sheetsync_export_logged', array( $this, 'dispatch_export_webhooks' ), 10, 4 );

        add_action( 'wp_ajax_sheetsync_pwa_snapshot', array( $this, 'ajax_pwa_snapshot' ) );
        add_action( 'wp_ajax_sheetsync_pwa_manifest', array( $this, 'ajax_pwa_manifest' ) );
        add_action( 'wp_ajax_sheetsync_test_export_webhook', array( $this, 'ajax_test_export_webhook' ) );
        add_action( 'template_redirect', array( $this, 'maybe_render_pwa_widget' ) );

        add_action( 'init', array( $this, 'register_pwa_rewrite' ) );
        add_action( 'init', array( $this, 'maybe_flush_pwa_rewrite' ), 99 );
    }

    public function maybe_flush_pwa_rewrite(): void {
        if ( get_option( 'sheetsync_pwa_rewrite_flushed' ) === '1' ) {
            return;
        }
        flush_rewrite_rules( false );
        update_option( 'sheetsync_pwa_rewrite_flushed', '1', false );
    }

    /**
     * @return array<string, mixed>
     */
    public static function settings_defaults(): array {
        return array(
            'cogs_enabled'           => '1',
            'multistore_enabled'     => '0',
            'multistore_site_ids'    => '',
            'webhook_export_enabled' => '0',
            'webhook_export_urls'    => '',
            'webhook_export_secret'  => '',
            'wl_app_name'            => '',
            'wl_logo_url'            => '',
            'wl_primary_color'       => '#6c63ff',
            'wl_accent_color'        => '#22d3a5',
            'wl_hide_pro_badge'      => '0',
            'pwa_enabled'            => '1',
        );
    }

    /**
     * @param array<string, mixed> $incoming
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    public static function sanitize_settings( array $incoming, array $current ): array {
        $webhooks = self::normalize_webhook_urls( (string) ( $incoming['webhook_export_urls'] ?? $current['webhook_export_urls'] ?? '' ) );

        return array(
            'cogs_enabled'           => ! empty( $incoming['cogs_enabled'] ) ? '1' : '0',
            'multistore_enabled'     => ! empty( $incoming['multistore_enabled'] ) ? '1' : '0',
            'multistore_site_ids'    => sanitize_text_field( $incoming['multistore_site_ids'] ?? $current['multistore_site_ids'] ?? '' ),
            'webhook_export_enabled' => ! empty( $incoming['webhook_export_enabled'] ) ? '1' : '0',
            'webhook_export_urls'    => $webhooks['urls'],
            'webhook_export_secret'  => sanitize_text_field( $incoming['webhook_export_secret'] ?? $current['webhook_export_secret'] ?? '' ),
            'wl_app_name'            => sanitize_text_field( $incoming['wl_app_name'] ?? $current['wl_app_name'] ?? '' ),
            'wl_logo_url'            => esc_url_raw( $incoming['wl_logo_url'] ?? $current['wl_logo_url'] ?? '' ),
            'wl_primary_color'       => sanitize_hex_color( $incoming['wl_primary_color'] ?? $current['wl_primary_color'] ?? '#6c63ff' ) ?: '#6c63ff',
            'wl_accent_color'        => sanitize_hex_color( $incoming['wl_accent_color'] ?? $current['wl_accent_color'] ?? '#22d3a5' ) ?: '#22d3a5',
            'wl_hide_pro_badge'      => ! empty( $incoming['wl_hide_pro_badge'] ) ? '1' : '0',
            'pwa_enabled'            => ! empty( $incoming['pwa_enabled'] ) ? '1' : '0',
        );
    }

    /**
     * @param array<string, mixed> $settings
     * @return list<string>
     */
    public static function get_persistent_notices( array $settings ): array {
        $notices = array();
        $parsed  = self::normalize_webhook_urls( (string) ( $settings['webhook_export_urls'] ?? '' ) );
        if ( ! empty( $parsed['invalid_count'] ) ) {
            $notices[] = sprintf(
                /* translators: %d: number of invalid webhook URLs removed */
                _n(
                    '%d invalid webhook URL was removed. Use a full https:// address.',
                    '%d invalid webhook URLs were removed. Use full https:// addresses.',
                    (int) $parsed['invalid_count'],
                    'sheetsync-for-woocommerce'
                ),
                (int) $parsed['invalid_count']
            );
        }
        if ( ! empty( $settings['webhook_export_enabled'] ) && $settings['webhook_export_enabled'] === '1' && trim( (string) ( $settings['webhook_export_urls'] ?? '' ) ) === '' ) {
            $notices[] = __( 'Webhook export is enabled but no valid webhook URLs are saved.', 'sheetsync-for-woocommerce' );
        }
        return $notices;
    }

    /**
     * @return array{urls: string, invalid_count: int}
     */
    public static function normalize_webhook_urls( string $raw ): array {
        $lines   = preg_split( '/\r\n|\r|\n/', $raw ) ?: array();
        $valid   = array();
        $invalid = 0;

        foreach ( $lines as $line ) {
            $line = trim( (string) $line );
            if ( $line === '' ) {
                continue;
            }
            if ( wp_http_validate_url( $line ) ) {
                $valid[] = esc_url_raw( $line );
            } else {
                ++$invalid;
            }
        }

        return array(
            'urls'          => implode( "\n", array_unique( $valid ) ),
            'invalid_count' => $invalid,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_settings(): array {
        $base = SheetSync_Dashboard_Enhancements::get_settings();
        return wp_parse_args( $base, self::settings_defaults() );
    }

    /**
     * @return array<string, mixed>
     */
    public static function enrich_sales_response( array $data, string $period = '7days' ): array {
        $settings = self::get_settings();

        if ( ! empty( $settings['cogs_enabled'] ) && $settings['cogs_enabled'] === '1' && empty( $data['is_demo'] ) ) {
            $data = self::apply_cogs_to_sales( $data, $period );
        } elseif ( ! empty( $data['is_demo'] ) ) {
            $data['cogs'] = self::demo_cogs_block( $data['summary'] ?? array() );
        }

        $data['forecast'] = self::ml_sales_forecast( $data['daily'] ?? array(), 7 );
        $data['forecast']['method'] = __( 'Holt linear exponential smoothing', 'sheetsync-for-woocommerce' );

        if ( ! empty( $settings['multistore_enabled'] ) && $settings['multistore_enabled'] === '1' ) {
            $data['multistore_rollup'] = self::get_multistore_rollup( $period );
        }

        $data['branding'] = self::get_branding_for_ui();
        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function enrich_inventory_response( array $data, int $threshold ): array {
        if ( ! empty( $data['is_demo'] ) ) {
            $data['variation_inventory'] = self::demo_variation_inventory( $threshold );
            $data['variation_inventory_meta'] = array(
                'total'     => count( $data['variation_inventory'] ),
                'truncated' => false,
                'max_rows'  => 0,
            );
        } else {
            $variation = self::get_variation_inventory( $threshold );
            $data['variation_inventory']      = $variation['items'];
            $data['variation_inventory_meta'] = array(
                'total'     => $variation['total'],
                'truncated' => $variation['truncated'],
                'max_rows'  => $variation['max_rows'],
            );
        }
        $data['branding'] = self::get_branding_for_ui();
        return $data;
    }

    // ─── COGS ─────────────────────────────────────────────────────────────────

    public function render_product_cogs_field(): void {
        woocommerce_wp_text_input(
            array(
                'id'          => self::COGS_META,
                'label'       => __( 'Cost of goods (COGS)', 'sheetsync-for-woocommerce' ) . ' (' . get_woocommerce_currency_symbol() . ')',
                'desc_tip'    => true,
                'description' => __( 'Unit cost used for profit tracking in SheetSync dashboards.', 'sheetsync-for-woocommerce' ),
                'type'        => 'number',
                'custom_attributes' => array(
                    'step' => '0.01',
                    'min'  => '0',
                ),
            )
        );
    }

    public function save_product_cogs_field( int $post_id ): void {
        if ( isset( $_POST[ self::COGS_META ] ) ) {
            $val = wc_format_decimal( wp_unslash( $_POST[ self::COGS_META ] ) );
            update_post_meta( $post_id, self::COGS_META, $val );
        }
    }

    /**
     * @param int     $loop           Variation loop index.
     * @param array   $variation_data Variation data.
     * @param WP_Post $variation      Variation post.
     */
    public function render_variation_cogs_field( int $loop, array $variation_data, $variation ): void {
        $vid = (int) $variation->ID;
        woocommerce_wp_text_input(
            array(
                'id'            => self::COGS_META . '[' . $loop . ']',
                'name'          => self::COGS_META . '[' . $loop . ']',
                'value'         => get_post_meta( $vid, self::COGS_META, true ),
                'label'         => __( 'COGS', 'sheetsync-for-woocommerce' ) . ' (' . get_woocommerce_currency_symbol() . ')',
                'wrapper_class' => 'form-row form-row-first',
                'type'          => 'number',
                'custom_attributes' => array(
                    'step' => '0.01',
                    'min'  => '0',
                ),
            )
        );
    }

    public function save_variation_cogs_field( int $variation_id, int $loop ): void {
        if ( isset( $_POST[ self::COGS_META ][ $loop ] ) ) {
            $val = wc_format_decimal( wp_unslash( $_POST[ self::COGS_META ][ $loop ] ) );
            update_post_meta( $variation_id, self::COGS_META, $val );
        }
    }

    public static function get_product_unit_cogs( $product ): float {
        if ( ! $product instanceof WC_Product ) {
            $product = wc_get_product( $product );
        }
        if ( ! $product ) {
            return 0.0;
        }

        $keys = array( self::COGS_META, '_wc_cog_cost', '_cost_of_goods', '_purchase_price' );
        foreach ( $keys as $key ) {
            $raw = $product->get_meta( $key, true );
            if ( $raw !== '' && is_numeric( $raw ) ) {
                return max( 0, (float) $raw );
            }
        }

        $pid = $product->get_id();
        foreach ( $keys as $key ) {
            $raw = get_post_meta( $pid, $key, true );
            if ( $raw !== '' && is_numeric( $raw ) ) {
                return max( 0, (float) $raw );
            }
        }

        return 0.0;
    }

    /**
     * @param int[] $order_ids
     * @return array{total_cogs: float, gross_profit: float, margin_pct: float, missing_cogs_lines: int}
     */
    public static function calculate_cogs_for_orders( array $order_ids ): array {
        $total_cogs         = 0.0;
        $net_sales          = 0.0;
        $missing_cogs_lines = 0;

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                continue;
            }
            $net_sales += max( 0, (float) $order->get_total() - (float) $order->get_total_refunded() );

            foreach ( $order->get_items() as $item ) {
                $product = $item->get_product();
                $qty     = (int) $item->get_quantity();
                $cogs    = $product ? self::get_product_unit_cogs( $product ) : 0.0;
                if ( $cogs <= 0 ) {
                    ++$missing_cogs_lines;
                    continue;
                }
                $total_cogs += $cogs * $qty;
            }
        }

        $gross_profit = max( 0, $net_sales - $total_cogs );
        $margin_pct   = $net_sales > 0 ? round( ( $gross_profit / $net_sales ) * 100, 1 ) : 0.0;

        return array(
            'total_cogs'           => round( $total_cogs, 2 ),
            'gross_profit'         => round( $gross_profit, 2 ),
            'margin_pct'           => $margin_pct,
            'missing_cogs_lines'   => $missing_cogs_lines,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function apply_cogs_to_sales( array $data, string $period ): array {
        if ( ! class_exists( 'SheetSync_Sales_Dashboard', false ) ) {
            return $data;
        }

        $dash  = new SheetSync_Sales_Dashboard();
        $range = $dash->get_period_range_public( $period );
        $paid  = $dash->get_order_ids_in_range_public( $range['start'], $range['end'], $dash->get_paid_statuses_public() );
        $cogs  = self::calculate_cogs_for_orders( $paid );

        $net_sales = (float) ( $data['summary']['net_sales'] ?? $data['summary']['total_revenue'] ?? 0 );
        $data['cogs'] = array_merge(
            $cogs,
            array(
                'net_sales'       => round( $net_sales, 2 ),
                'products_profit' => self::get_product_profit_breakdown( $paid, 10 ),
            )
        );

        if ( $cogs['total_cogs'] > 0 ) {
            $data['summary']['gross_profit'] = $cogs['gross_profit'];
            $data['summary']['total_cogs']   = $cogs['total_cogs'];
            $data['summary']['margin_pct']   = $cogs['margin_pct'];
            $data['summary']['net_profit']   = $cogs['gross_profit'];
        }

        return $data;
    }

    /**
     * @param int[] $order_ids
     * @return array<int, array<string, mixed>>
     */
    private static function get_product_profit_breakdown( array $order_ids, int $limit = 10 ): array {
        $map = array();

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                continue;
            }
            foreach ( $order->get_items() as $item ) {
                $pid     = (int) $item->get_product_id();
                $product = $item->get_product();
                $name    = $product ? $product->get_name() : $item->get_name();
                $qty     = (int) $item->get_quantity();
                $revenue = (float) $item->get_total();
                $unit    = $product ? self::get_product_unit_cogs( $product ) : 0.0;
                $cost    = $unit * $qty;

                if ( ! isset( $map[ $pid ] ) ) {
                    $map[ $pid ] = array(
                        'product_id' => $pid,
                        'name'       => $name,
                        'quantity'   => 0,
                        'revenue'    => 0.0,
                        'cogs'       => 0.0,
                        'profit'     => 0.0,
                    );
                }
                $map[ $pid ]['quantity'] += $qty;
                $map[ $pid ]['revenue']  += $revenue;
                $map[ $pid ]['cogs']     += $cost;
                $map[ $pid ]['profit']   += max( 0, $revenue - $cost );
            }
        }

        $rows = array_values( $map );
        usort(
            $rows,
            static function ( $a, $b ) {
                return $b['profit'] <=> $a['profit'];
            }
        );

        foreach ( $rows as &$row ) {
            $row['revenue'] = round( $row['revenue'], 2 );
            $row['cogs']    = round( $row['cogs'], 2 );
            $row['profit']  = round( $row['profit'], 2 );
            $row['margin']  = $row['revenue'] > 0 ? round( ( $row['profit'] / $row['revenue'] ) * 100, 1 ) : 0;
        }
        unset( $row );

        return array_slice( $rows, 0, $limit );
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private static function demo_cogs_block( array $summary ): array {
        $net = (float) ( $summary['net_sales'] ?? 10000 );
        $cogs = round( $net * 0.42, 2 );
        return array(
            'total_cogs'         => $cogs,
            'gross_profit'       => round( $net - $cogs, 2 ),
            'margin_pct'         => 58.0,
            'missing_cogs_lines' => 0,
            'net_sales'          => $net,
            'products_profit'    => array(
                array( 'name' => 'Demo Hoodie', 'quantity' => 24, 'revenue' => 2400, 'cogs' => 960, 'profit' => 1440, 'margin' => 60.0 ),
                array( 'name' => 'Demo Cap', 'quantity' => 18, 'revenue' => 900, 'cogs' => 360, 'profit' => 540, 'margin' => 60.0 ),
            ),
        );
    }

    // ─── Variation inventory ──────────────────────────────────────────────────

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, truncated: bool, max_rows: int}
     */
    public static function get_variation_inventory( int $threshold ): array {
        $rows     = array();
        $page     = 1;
        $per_page = (int) apply_filters( 'sheetsync_variation_inventory_per_page', 500 );
        $per_page = max( 100, min( 1000, $per_page ) );
        $max_page = (int) apply_filters( 'sheetsync_variation_inventory_max_pages', 40 );
        $truncated = false;

        do {
            $query = wc_get_products(
                array(
                    'type'   => 'variation',
                    'status' => 'publish',
                    'limit'  => $per_page,
                    'page'   => $page,
                    'return' => 'objects',
                )
            );

            if ( empty( $query ) ) {
                break;
            }

            foreach ( $query as $variation ) {
                if ( ! $variation instanceof WC_Product_Variation ) {
                    continue;
                }

                $parent = wc_get_product( $variation->get_parent_id() );
                $manage = $variation->get_manage_stock();
                $qty    = $manage ? (int) $variation->get_stock_quantity() : null;
                $status = self::stock_status_for_qty( $qty, $manage, $variation->get_stock_status(), $threshold );

                $attrs = array();
                foreach ( $variation->get_attributes() as $key => $val ) {
                    $attrs[] = wc_attribute_label( str_replace( 'attribute_', '', $key ), $variation ) . ': ' . $val;
                }

                $rows[] = array(
                    'id'           => $variation->get_id(),
                    'parent_id'    => $variation->get_parent_id(),
                    'parent_name'  => $parent ? $parent->get_name() : '',
                    'name'         => $parent ? $parent->get_name() . ' — ' . implode( ', ', $attrs ) : implode( ', ', $attrs ),
                    'attributes'   => implode( ', ', $attrs ),
                    'sku'          => $variation->get_sku(),
                    'stock_qty'    => $manage ? (int) $qty : 'N/A',
                    'manage_stock' => $manage,
                    'status'       => $status,
                    'price'        => (float) $variation->get_regular_price(),
                    'cogs'         => self::get_product_unit_cogs( $variation ),
                    'edit_url'     => get_edit_post_link( $variation->get_id(), 'raw' ) ?: '',
                );
            }

            if ( count( $query ) < $per_page ) {
                break;
            }

            if ( $page >= $max_page ) {
                $truncated = true;
                break;
            }

            ++$page;
        } while ( $page <= $max_page );

        usort(
            $rows,
            static function ( $a, $b ) {
                $prio = array( 'outofstock' => 3, 'low_stock' => 2, 'instock' => 1 );
                $pa   = $prio[ $a['status'] ] ?? 0;
                $pb   = $prio[ $b['status'] ] ?? 0;
                if ( $pa !== $pb ) {
                    return $pb <=> $pa;
                }
                return strcmp( $a['parent_name'], $b['parent_name'] );
            }
        );

        return array(
            'items'     => $rows,
            'total'     => count( $rows ),
            'truncated' => $truncated,
            'max_rows'  => $per_page * $max_page,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function demo_variation_inventory( int $threshold ): array {
        return array(
            array(
                'id' => 901, 'parent_name' => 'Demo Hoodie', 'name' => 'Demo Hoodie — Size: M, Color: Blue',
                'attributes' => 'Size: M, Color: Blue', 'sku' => 'HD-M-BLU', 'stock_qty' => 2,
                'status' => 'low_stock', 'price' => 49.99, 'cogs' => 18.5, 'edit_url' => '#',
            ),
            array(
                'id' => 902, 'parent_name' => 'Demo Hoodie', 'name' => 'Demo Hoodie — Size: L, Color: Red',
                'attributes' => 'Size: L, Color: Red', 'sku' => 'HD-L-RED', 'stock_qty' => 0,
                'status' => 'outofstock', 'price' => 49.99, 'cogs' => 18.5, 'edit_url' => '#',
            ),
        );
    }

    private static function stock_status_for_qty( ?int $qty, bool $manage, string $stock_status, int $threshold ): string {
        if ( ! $manage ) {
            return $stock_status === 'instock' ? 'instock' : 'outofstock';
        }
        if ( $qty === null ) {
            return 'instock';
        }
        if ( $qty <= 0 ) {
            return 'outofstock';
        }
        if ( $qty <= $threshold ) {
            return 'low_stock';
        }
        return 'instock';
    }

    // ─── ML forecast (Holt linear exponential smoothing) ──────────────────────

    /**
     * @param array<int, array<string, mixed>> $daily
     * @return array<string, mixed>
     */
    public static function ml_sales_forecast( array $daily, int $horizon = 7 ): array {
        $series = array();
        foreach ( $daily as $d ) {
            $series[] = (float) ( $d['revenue'] ?? 0 );
        }

        if ( count( $series ) < 3 ) {
            return self::fallback_forecast( $daily, $horizon );
        }

        $train     = array_slice( $series, -min( 30, count( $series ) ) );
        $alpha     = 0.35;
        $beta      = 0.15;
        $level     = $train[0];
        $trend     = $train[1] - $train[0];

        for ( $i = 1, $n = count( $train ); $i < $n; $i++ ) {
            $y         = $train[ $i ];
            $prev_level = $level;
            $level     = $alpha * $y + ( 1 - $alpha ) * ( $level + $trend );
            $trend     = $beta * ( $level - $prev_level ) + ( 1 - $beta ) * $trend;
        }

        $hist_len   = min( 7, count( $daily ) );
        $hist_slice = array_slice( $daily, -$hist_len );
        $labels     = array();
        $historical = array();
        $predicted  = array();

        foreach ( $hist_slice as $d ) {
            $labels[]     = $d['date'] ?? '';
            $historical[] = (float) ( $d['revenue'] ?? 0 );
            $predicted[]  = null;
        }

        $residuals = array();
        for ( $i = 1, $n = count( $train ); $i < $n; $i++ ) {
            $residuals[] = abs( $train[ $i ] - $train[ $i - 1 ] );
        }
        $noise = ! empty( $residuals ) ? array_sum( $residuals ) / count( $residuals ) : 0;

        for ( $h = 1; $h <= $horizon; $h++ ) {
            $forecast_val = max( 0, $level + ( $h * $trend ) );
            $labels[]     = wp_date( 'M j', strtotime( '+' . $h . ' days' ) );
            $historical[] = null;
            $predicted[]  = round( $forecast_val, 2 );
        }

        $confidence = max( 55, min( 95, 90 - (int) round( $noise / max( 1, $level ) * 100 ) ) );

        return array(
            'labels'     => $labels,
            'historical' => $historical,
            'predicted'  => $predicted,
            'confidence' => $confidence,
            'trend'      => round( $trend, 2 ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $daily
     * @return array<string, mixed>
     */
    private static function fallback_forecast( array $daily, int $horizon ): array {
        $slice   = array_slice( $daily, -7 );
        $avg_rev = count( $slice ) > 0 ? array_sum( array_column( $slice, 'revenue' ) ) / count( $slice ) : 0;
        $labels  = array();
        $historical = array();
        $predicted  = array();

        foreach ( $slice as $d ) {
            $labels[]     = $d['date'] ?? '';
            $historical[] = (float) ( $d['revenue'] ?? 0 );
            $predicted[]  = null;
        }
        for ( $i = 1; $i <= $horizon; $i++ ) {
            $labels[]     = wp_date( 'M j', strtotime( '+' . $i . ' days' ) );
            $historical[] = null;
            $predicted[]  = round( $avg_rev, 2 );
        }

        return array(
            'labels'     => $labels,
            'historical' => $historical,
            'predicted'  => $predicted,
            'confidence' => 60,
            'trend'      => 0,
        );
    }

    // ─── Multi-store rollup ───────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public static function get_multistore_rollup( string $period ): array {
        $settings = self::get_settings();
        $sites    = self::resolve_multistore_blog_ids( $settings['multistore_site_ids'] ?? '' );
        $stores   = array();
        $totals   = array(
            'net_sales'    => 0.0,
            'total_orders' => 0,
            'stores_count' => 0,
        );

        if ( empty( $sites ) ) {
            return array(
                'enabled'  => true,
                'stores'   => array(),
                'totals'   => $totals,
                'message'  => __( 'No additional stores configured.', 'sheetsync-for-woocommerce' ),
            );
        }

        foreach ( $sites as $blog_id ) {
            $blog_id = (int) $blog_id;
            if ( $blog_id === get_current_blog_id() ) {
                continue;
            }

            $snapshot = self::fetch_store_snapshot( $blog_id, $period );
            if ( empty( $snapshot ) ) {
                continue;
            }

            $stores[] = $snapshot;
            $totals['net_sales']    += (float) ( $snapshot['net_sales'] ?? 0 );
            $totals['total_orders'] += (int) ( $snapshot['total_orders'] ?? 0 );
            ++$totals['stores_count'];
        }

        $totals['net_sales'] = round( $totals['net_sales'], 2 );

        return array(
            'enabled' => true,
            'stores'  => $stores,
            'totals'  => $totals,
        );
    }

    /**
     * @return int[]
     */
    private static function resolve_multistore_blog_ids( string $raw ): array {
        if ( is_multisite() ) {
            if ( trim( $raw ) === '' ) {
                return array_map(
                    'intval',
                    get_sites(
                        array(
                            'fields' => 'ids',
                            'number' => 100,
                        )
                    )
                );
            }
            return array_filter( array_map( 'absint', explode( ',', $raw ) ) );
        }

        return array_filter( array_map( 'absint', explode( ',', $raw ) ) );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fetch_store_snapshot( int $blog_id, string $period ): ?array {
        if ( ! is_multisite() ) {
            return null;
        }

        switch_to_blog( $blog_id );
        try {
            if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'SheetSync_Sales_Dashboard', false ) ) {
                restore_current_blog();
                return null;
            }

            $dash  = new SheetSync_Sales_Dashboard();
            $range = $dash->get_period_range_public( $period );
            $paid  = $dash->get_order_ids_in_range_public( $range['start'], $range['end'], $dash->get_paid_statuses_public() );
            $all   = $dash->get_order_ids_in_range_public( $range['start'], $range['end'], $dash->get_reportable_statuses_public() );
            $metrics = $dash->aggregate_order_metrics_public( $paid );

            $details = get_blog_details( $blog_id );
            $name    = $details ? $details->blogname : ( 'Store #' . $blog_id );
            $url     = get_site_url( $blog_id );

            restore_current_blog();

            return array(
                'blog_id'      => $blog_id,
                'name'         => $name,
                'url'          => $url,
                'net_sales'    => round( (float) ( $metrics['net_sales'] ?? 0 ), 2 ),
                'total_orders' => count( $all ),
                'paid_orders'  => count( $paid ),
            );
        } catch ( Throwable $e ) {
            restore_current_blog();
            return null;
        }
    }

    // ─── Webhooks / Zapier ────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $meta
     */
    public function dispatch_export_webhooks( string $type, bool $success, string $message, array $meta = array() ): void {
        $settings = self::get_settings();
        if ( empty( $settings['webhook_export_enabled'] ) || $settings['webhook_export_enabled'] !== '1' ) {
            return;
        }

        $urls = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) ( $settings['webhook_export_urls'] ?? '' ) ) ) );
        if ( empty( $urls ) ) {
            return;
        }

        $payload = array(
            'event'     => 'sheetsync.export.complete',
            'type'      => $type,
            'success'   => $success,
            'message'   => $message,
            'meta'      => $meta,
            'site_url'  => home_url(),
            'timestamp' => gmdate( 'c' ),
        );

        $secret = (string) ( $settings['webhook_export_secret'] ?? '' );
        $body   = wp_json_encode( $payload );
        $headers = array(
            'Content-Type' => 'application/json',
            'User-Agent'   => 'SheetSync-Dashboard/1.3',
        );
        if ( $secret !== '' ) {
            $headers['X-SheetSync-Signature'] = hash_hmac( 'sha256', (string) $body, $secret );
        }

        foreach ( $urls as $url ) {
            if ( ! wp_http_validate_url( $url ) ) {
                continue;
            }
            wp_remote_post(
                $url,
                array(
                    'timeout' => 8,
                    'headers' => $headers,
                    'body'    => $body,
                )
            );
        }
    }

    public function ajax_test_export_webhook(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $settings = self::get_settings();
        $parsed   = self::normalize_webhook_urls( (string) ( $settings['webhook_export_urls'] ?? '' ) );
        $urls     = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $parsed['urls'] ) ?: array() ) );

        if ( empty( $urls ) ) {
            wp_send_json_error( array( 'message' => __( 'Add at least one valid webhook URL first.', 'sheetsync-for-woocommerce' ) ) );
        }

        $payload = array(
            'event'     => 'sheetsync.export.complete',
            'type'      => 'test',
            'success'   => true,
            'message'   => __( 'SheetSync webhook test ping.', 'sheetsync-for-woocommerce' ),
            'meta'      => array( 'test' => true ),
            'site_url'  => home_url(),
            'timestamp' => gmdate( 'c' ),
        );

        $secret  = (string) ( $settings['webhook_export_secret'] ?? '' );
        $body    = wp_json_encode( $payload );
        $headers = array(
            'Content-Type' => 'application/json',
            'User-Agent'   => 'SheetSync-Dashboard/1.3',
        );
        if ( $secret !== '' ) {
            $headers['X-SheetSync-Signature'] = hash_hmac( 'sha256', (string) $body, $secret );
        }

        $results = array();
        foreach ( $urls as $url ) {
            $response = wp_remote_post(
                $url,
                array(
                    'timeout' => 10,
                    'headers' => $headers,
                    'body'    => $body,
                )
            );
            if ( is_wp_error( $response ) ) {
                $results[] = array(
                    'url'     => $url,
                    'success' => false,
                    'message' => $response->get_error_message(),
                );
                continue;
            }
            $code = (int) wp_remote_retrieve_response_code( $response );
            $results[] = array(
                'url'     => $url,
                'success' => $code >= 200 && $code < 300,
                'message' => sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'HTTP %d', 'sheetsync-for-woocommerce' ),
                    $code
                ),
            );
        }

        $ok = array_filter( $results, static fn( $row ) => ! empty( $row['success'] ) );
        if ( empty( $ok ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Webhook test failed for all URLs.', 'sheetsync-for-woocommerce' ),
                    'results' => $results,
                )
            );
        }

        wp_send_json_success(
            array(
                'message' => sprintf(
                    /* translators: %1$d succeeded, %2$d total */
                    __( 'Webhook test sent: %1$d of %2$d succeeded.', 'sheetsync-for-woocommerce' ),
                    count( $ok ),
                    count( $results )
                ),
                'results' => $results,
            )
        );
    }

    // ─── White-label ──────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public static function get_branding_for_ui(): array {
        $s = self::get_settings();
        return array(
            'app_name'      => sanitize_text_field( $s['wl_app_name'] ?? '' ),
            'logo_url'      => esc_url_raw( $s['wl_logo_url'] ?? '' ),
            'primary_color' => sanitize_hex_color( $s['wl_primary_color'] ?? '' ) ?: '#6c63ff',
            'accent_color'  => sanitize_hex_color( $s['wl_accent_color'] ?? '' ) ?: '#22d3a5',
            'hide_pro_badge'=> ! empty( $s['wl_hide_pro_badge'] ) && $s['wl_hide_pro_badge'] === '1',
        );
    }

    public static function branding_css_vars(): string {
        $b = self::get_branding_for_ui();
        return sprintf(
            '--ss-wl-primary:%1$s;--ss-wl-accent:%2$s;',
            esc_attr( $b['primary_color'] ),
            esc_attr( $b['accent_color'] )
        );
    }

    // ─── PWA widget ───────────────────────────────────────────────────────────

    public function register_pwa_rewrite(): void {
        add_rewrite_rule( '^sheetsync-pwa/?$', 'index.php?sheetsync_pwa=1', 'top' );
        add_rewrite_tag( '%sheetsync_pwa%', '1' );
    }

    public function maybe_render_pwa_widget(): void {
        if ( ! get_query_var( 'sheetsync_pwa' ) ) {
            return;
        }

        if ( ! is_user_logged_in() || ! current_user_can( 'manage_woocommerce' ) ) {
            auth_redirect();
        }

        $settings = self::get_settings();
        if ( empty( $settings['pwa_enabled'] ) || $settings['pwa_enabled'] !== '1' ) {
            wp_die( esc_html__( 'PWA widget is disabled.', 'sheetsync-for-woocommerce' ) );
        }

        $branding = self::get_branding_for_ui();
        $title    = $branding['app_name'] ?: __( 'SheetSync Dashboard', 'sheetsync-for-woocommerce' );
        $manifest = add_query_arg(
            array(
                'action' => 'sheetsync_pwa_manifest',
                'nonce'  => wp_create_nonce( 'sheetsync_nonce' ),
            ),
            admin_url( 'admin-ajax.php' )
        );
        $sw       = SHEETSYNC_PRO_URL . 'admin/pwa/sw.js';
        $ajax     = admin_url( 'admin-ajax.php' );
        $nonce    = wp_create_nonce( 'sheetsync_nonce' );

        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=utf-8' );

        include SHEETSYNC_PRO_DIR . 'admin/pwa/widget-page.php';
        exit;
    }

    public function ajax_pwa_snapshot(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $period = '7days';
        if ( class_exists( 'SheetSync_Dashboard_Phase2', false ) && SheetSync_Dashboard_Phase2::is_demo_mode() ) {
            $data = SheetSync_Dashboard_Phase2::get_demo_sales_data( $period );
        } elseif ( class_exists( 'SheetSync_Sales_Dashboard', false ) ) {
            $dash = new SheetSync_Sales_Dashboard();
            $data = $dash->get_dashboard_data( $period, array() );
        } else {
            wp_send_json_error();
        }

        if ( class_exists( 'SheetSync_Dashboard_Phase3', false ) ) {
            $data = self::enrich_sales_response( $data, $period );
        }

        $s = $data['summary'] ?? array();
        wp_send_json_success(
            array(
                'generated_at' => wp_date( 'M j, Y g:i A' ),
                'currency'     => html_entity_decode( get_woocommerce_currency_symbol() ),
                'net_sales'    => $s['net_sales'] ?? 0,
                'total_orders' => $s['total_orders'] ?? 0,
                'net_profit'   => $s['net_profit'] ?? 0,
                'gross_profit' => $s['gross_profit'] ?? null,
                'margin_pct'   => $s['margin_pct'] ?? null,
                'pending'      => $data['pending_orders'] ?? 0,
                'branding'     => self::get_branding_for_ui(),
            )
        );
    }

    public function ajax_pwa_manifest(): void {
        $nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'sheetsync_nonce' ) ) {
            status_header( 403 );
            exit;
        }

        if ( ! is_user_logged_in() || ! current_user_can( 'manage_woocommerce' ) ) {
            status_header( 403 );
            exit;
        }

        $branding = self::get_branding_for_ui();
        $name     = $branding['app_name'] ?: 'SheetSync Dashboard';

        wp_send_json(
            array(
                'name'             => $name,
                'short_name'       => mb_substr( $name, 0, 12 ),
                'start_url'        => home_url( '/sheetsync-pwa/' ),
                'display'          => 'standalone',
                'background_color' => '#0f1117',
                'theme_color'      => $branding['primary_color'],
                'icons'            => array(
                    array(
                        'src'   => $branding['logo_url'] ?: ( SHEETSYNC_PRO_URL . 'admin/pwa/icon-192.png' ),
                        'sizes' => '192x192',
                        'type'  => 'image/png',
                    ),
                ),
            )
        );
    }

    /**
     * @return array<string, string>
     */
    public static function kpi_help_texts(): array {
        return array(
            'gross_profit'    => __( 'Net sales minus product COGS (cost of goods). Set COGS on each product under Pricing.', 'sheetsync-for-woocommerce' ),
            'total_cogs'      => __( 'Sum of unit COGS × quantity sold for products with a cost entered.', 'sheetsync-for-woocommerce' ),
            'margin_pct'      => __( 'Gross profit ÷ net sales × 100.', 'sheetsync-for-woocommerce' ),
            'forecast_conf'   => __( 'Model confidence based on recent revenue volatility (Holt exponential smoothing).', 'sheetsync-for-woocommerce' ),
            'multistore'      => __( 'Combined revenue from other sites on this WordPress network.', 'sheetsync-for-woocommerce' ),
            'variations'      => __( 'Stock levels for each product variation (size, color, etc.).', 'sheetsync-for-woocommerce' ),
        );
    }
}

endif;
