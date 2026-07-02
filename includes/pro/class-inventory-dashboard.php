<?php
/**
 * PRO: Inventory Dashboard — exports WooCommerce inventory status to Google Sheets.
 * Includes stock levels, low stock alerts, out-of-stock items, and category breakdown.
 *
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class SheetSync_Inventory_Dashboard {

    private bool $products_range_truncated = false;

    private int $products_range_max = 0;

    public function __construct() {
        add_action( 'wp_ajax_sheetsync_export_inventory_dashboard', array( $this, 'ajax_export_inventory' ) );
        add_action( 'wp_ajax_sheetsync_get_inventory_preview',      array( $this, 'ajax_get_inventory_preview' ) );
        add_action( 'woocommerce_update_product', array( __CLASS__, 'bust_inventory_cache' ), 20, 0 );
    }

    public static function bust_inventory_cache(): void {
        if ( class_exists( 'SheetSync_Sales_Dashboard', false ) ) {
            SheetSync_Sales_Dashboard::bust_inventory_cache();
            return;
        }
        $version = (int) get_option( 'sheetsync_inv_cache_v', 1 );
        update_option( 'sheetsync_inv_cache_v', $version + 1, false );
    }

    private function inventory_cache_key( int $low_stock_threshold ): string {
        $version = class_exists( 'SheetSync_Sales_Dashboard', false )
            ? SheetSync_Sales_Dashboard::dashboard_cache_version()
            : (int) get_option( 'sheetsync_inv_cache_v', 1 );

        return 'sheetsync_inv_data_' . get_current_blog_id() . '_' . $version . '_' . $low_stock_threshold;
    }

    // ─── AJAX handlers ────────────────────────────────────────────────────────

    public function ajax_export_inventory(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( class_exists( 'SheetSync_Dashboard_Phase2', false ) ) {
            SheetSync_Dashboard_Phase2::require_dashboard( SheetSync_Dashboard_Phase2::DASH_INVENTORY );
        } elseif ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $spreadsheet_id    = sanitize_text_field( wp_unslash( $_POST['spreadsheet_id'] ?? '' ) );
        $sheet_name        = sanitize_text_field( wp_unslash( $_POST['sheet_name'] ?? 'Inventory Status' ) );
        $low_stock_threshold = absint( $_POST['low_stock_threshold'] ?? 5 );

        if ( empty( $spreadsheet_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Spreadsheet ID is required.', 'sheetsync-for-woocommerce' ) ) );
        }

        if ( class_exists( 'SheetSync_Dashboard_Phase2', false ) ) {
            SheetSync_Dashboard_Phase2::reject_demo_export();
        }

        try {
            $result = $this->export_to_sheets( $spreadsheet_id, $sheet_name, $low_stock_threshold );
            if ( class_exists( 'SheetSync_Dashboard_Enhancements', false ) ) {
                SheetSync_Dashboard_Enhancements::log_export( 'inventory', true, $result['message'] ?? '', $result );
            }
            wp_send_json_success( $result );
        } catch ( Exception $e ) {
            if ( class_exists( 'SheetSync_Dashboard_Enhancements', false ) ) {
                SheetSync_Dashboard_Enhancements::log_export( 'inventory', false, $e->getMessage(), array() );
            }
            wp_send_json_error( array( 'message' => esc_html( $e->getMessage() ) ) );
        }
    }

    public function ajax_get_inventory_preview(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( class_exists( 'SheetSync_Dashboard_Phase2', false ) ) {
            SheetSync_Dashboard_Phase2::require_dashboard( SheetSync_Dashboard_Phase2::DASH_INVENTORY );
        } elseif ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $low_stock_threshold = absint( $_POST['low_stock_threshold'] ?? 5 );

        try {
            if ( class_exists( 'SheetSync_Dashboard_Phase2', false ) && SheetSync_Dashboard_Phase2::is_demo_mode() ) {
                $data = SheetSync_Dashboard_Phase2::get_demo_inventory_data( $low_stock_threshold );
            } else {
                $data = $this->get_inventory_data( $low_stock_threshold );
            }
            if ( class_exists( 'SheetSync_Dashboard_Phase2', false ) ) {
                $data = SheetSync_Dashboard_Phase2::enrich_inventory_response( $data, $low_stock_threshold );
            }
            if ( class_exists( 'SheetSync_Dashboard_Phase3', false ) ) {
                $data = SheetSync_Dashboard_Phase3::enrich_inventory_response( $data, $low_stock_threshold );
            }
            wp_send_json_success( $data );
        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => esc_html( $e->getMessage() ) ) );
        }
    }

    // ─── Data Collection ──────────────────────────────────────────────────────

    public function get_inventory_data( int $low_stock_threshold = 5 ): array {
        $cache_key = $this->inventory_cache_key( $low_stock_threshold );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $all_products  = $this->get_all_products_inventory( $low_stock_threshold );
        $summary       = $this->get_inventory_summary( $all_products );
        $low_stock     = array_values( array_filter( $all_products, fn( $p ) => $p['status'] === 'low_stock' ) );
        $out_of_stock  = array_values( array_filter( $all_products, fn( $p ) => $p['status'] === 'outofstock' ) );
        $category_data = $this->get_category_breakdown( $all_products );

        $data = array(
            'all_products' => $all_products,
            'low_stock'    => $low_stock,
            'out_of_stock' => $out_of_stock,
            'categories'   => $category_data,
            'summary'      => $summary,
            'currency_symbol' => html_entity_decode( get_woocommerce_currency_symbol() ),
            'generated_at'    => wp_date( 'M j, Y g:i A' ),
            'low_stock_threshold' => $low_stock_threshold,
            'products_meta'   => array(
                'truncated'      => $this->products_range_truncated,
                'products_loaded' => count( $all_products ),
                'max_products'   => $this->products_range_max,
            ),
        );

        set_transient( $cache_key, $data, (int) apply_filters( 'sheetsync_inventory_dashboard_cache_ttl', 600 ) );

        return $data;
    }

    /**
     * Get all products with inventory data.
     */
    private function get_all_products_inventory( int $low_stock_threshold ): array {
        $result   = array();
        $page     = 1;
        $per_page = sheetsync_dashboard_products_per_page();
        $max_page = sheetsync_dashboard_products_max_pages();
        $this->products_range_truncated = false;
        $this->products_range_max       = $per_page * $max_page;

        do {
            $products = wc_get_products(
                array(
                    'status'  => 'publish',
                    'limit'   => $per_page,
                    'page'    => $page,
                    'type'    => array( 'simple', 'variation' ),
                    'orderby' => 'title',
                    'order'   => 'ASC',
                )
            );

            if ( empty( $products ) ) {
                break;
            }

            foreach ( $products as $product ) {
                $result[] = $this->build_inventory_row( $product, $low_stock_threshold );
            }

            if ( count( $products ) < $per_page ) {
                break;
            }

            ++$page;
            if ( $page > $max_page ) {
                $this->products_range_truncated = true;
                break;
            }
        } while ( true );

        return $result;
    }

    /**
     * Build one inventory row for a simple product or variation (stock-bearing SKU).
     *
     * @return array<string, mixed>
     */
    private function build_inventory_row( WC_Product $product, int $low_stock_threshold ): array {
        $stock_qty    = $product->get_stock_quantity();
        $manage_stock = $product->get_manage_stock();
        $stock_status = $product->get_stock_status();

        if ( ! $manage_stock ) {
            $status = $stock_status === 'instock' ? 'instock' : 'outofstock';
        } elseif ( $stock_qty === null ) {
            $status = 'instock';
        } elseif ( $stock_qty <= 0 ) {
            $status = 'outofstock';
        } elseif ( $stock_qty <= $low_stock_threshold ) {
            $status = 'low_stock';
        } else {
            $status = 'instock';
        }

        $categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
        if ( ( ! is_array( $categories ) || empty( $categories ) ) && $product->is_type( 'variation' ) ) {
            $parent_id = $product->get_parent_id();
            if ( $parent_id ) {
                $categories = wp_get_post_terms( $parent_id, 'product_cat', array( 'fields' => 'names' ) );
            }
        }
        $cat_string = is_array( $categories ) ? implode( ', ', $categories ) : '';

        $display_price = (float) $product->get_price();
        if ( $display_price <= 0 ) {
            $display_price = (float) $product->get_regular_price();
        }

        return array(
            'id'               => $product->get_id(),
            'parent_id'        => $product->is_type( 'variation' ) ? $product->get_parent_id() : 0,
            'sku'              => $product->get_sku(),
            'name'             => $product->get_name(),
            'category'         => $cat_string,
            'price'            => $display_price,
            'sale_price'       => (float) $product->get_sale_price(),
            'stock_qty'        => $manage_stock ? (int) $stock_qty : 'N/A',
            'manage_stock'     => $manage_stock,
            'status'           => $status,
            'type'             => $product->get_type(),
            'stock_line_value' => $this->product_line_stock_value( $product ),
            'edit_url'         => get_edit_post_link( $product->get_id(), 'raw' ) ?: '',
        );
    }

    /**
     * Price × qty for one catalog row (variable products sum their variations).
     */
    private function product_line_stock_value( WC_Product $product ): float {
        if ( $product->is_type( 'variable' ) ) {
            $total = 0.0;
            foreach ( $product->get_children() as $child_id ) {
                $variation = wc_get_product( (int) $child_id );
                if ( ! $variation || ! $variation->get_manage_stock() ) {
                    continue;
                }
                $qty = $variation->get_stock_quantity();
                if ( ! is_numeric( $qty ) || (int) $qty <= 0 ) {
                    continue;
                }
                $price = (float) $variation->get_price();
                if ( $price <= 0 ) {
                    $price = (float) $variation->get_regular_price();
                }
                $total += $price * (int) $qty;
            }
            return $total;
        }

        if ( ! $product->get_manage_stock() ) {
            return 0.0;
        }

        $qty = $product->get_stock_quantity();
        if ( ! is_numeric( $qty ) || (int) $qty <= 0 ) {
            return 0.0;
        }

        $price = (float) $product->get_price();
        if ( $price <= 0 ) {
            $price = (float) $product->get_regular_price();
        }

        return $price * (int) $qty;
    }

    /**
     * Summary counts.
     */
    private function get_inventory_summary( array $products ): array {
        $total       = count( $products );
        $in_stock    = count( array_filter( $products, fn( $p ) => $p['status'] === 'instock' ) );
        $low_stock   = count( array_filter( $products, fn( $p ) => $p['status'] === 'low_stock' ) );
        $out         = count( array_filter( $products, fn( $p ) => $p['status'] === 'outofstock' ) );
        $total_value = array_sum(
            array_map(
                static fn( $p ) => (float) ( $p['stock_line_value'] ?? 0 ),
                $products
            )
        );

        return array(
            'total_products'   => $total,
            'in_stock'         => $in_stock,
            'low_stock'        => $low_stock,
            'out_of_stock'     => $out,
            'total_stock_value'=> round( $total_value, 2 ),
        );
    }

    /**
     * Category breakdown with stock counts.
     */
    private function get_category_breakdown( array $products ): array {
        $cats = array();

        foreach ( $products as $p ) {
            $cat_list = ! empty( $p['category'] ) ? explode( ', ', $p['category'] ) : array( 'Uncategorized' );
            foreach ( $cat_list as $cat ) {
                $cat = trim( $cat );
                if ( ! isset( $cats[ $cat ] ) ) {
                    $cats[ $cat ] = array( 'name' => $cat, 'total' => 0, 'in_stock' => 0, 'low_stock' => 0, 'out_of_stock' => 0 );
                }
                $cats[ $cat ]['total']++;
                if ( $p['status'] === 'instock' )    $cats[ $cat ]['in_stock']++;
                if ( $p['status'] === 'low_stock' )  $cats[ $cat ]['low_stock']++;
                if ( $p['status'] === 'outofstock' ) $cats[ $cat ]['out_of_stock']++;
            }
        }

        uasort( $cats, fn( $a, $b ) => $b['total'] <=> $a['total'] );
        return array_values( $cats );
    }

    // ─── Google Sheets Export ─────────────────────────────────────────────────

    private function sheet_money( float $amount ): string {
        $sym = html_entity_decode( get_woocommerce_currency_symbol() );
        return $sym . number_format( $amount, 2 );
    }

    public function export_to_sheets( string $spreadsheet_id, string $sheet_name, int $low_stock_threshold = 5 ): array {
        $client = new SheetSync_Sheets_Client();
        $data   = $this->get_inventory_data( $low_stock_threshold );
        $rows   = array();
        $map    = array();

        // ── Title ──
        $map['title'] = count( $rows );
        $rows[] = array( 'SheetSync Inventory Dashboard', '', '', '', '', '' );
        $map['meta'] = count( $rows );
        $rows[] = array(
            'Generated: ' . wp_date( 'M j, Y g:i A' ),
            '',
            '',
            'Low Stock Threshold: ' . $low_stock_threshold,
            '',
            '',
        );
        $rows[] = array();

        // ── Summary ──
        $s = $data['summary'];
        $map['summary_header'] = count( $rows );
        $rows[] = array( 'INVENTORY SUMMARY', '', '', '', '', '' );
        $map['summary_col_header'] = count( $rows );
        $rows[] = array( 'Total Products', 'In Stock', 'Low Stock', 'Out of Stock', 'Est. Stock Value', '' );
        $map['summary_data'] = count( $rows );
        $rows[] = array(
            $s['total_products'],
            $s['in_stock'],
            $s['low_stock'],
            $s['out_of_stock'],
            $this->sheet_money( (float) $s['total_stock_value'] ),
            '',
        );
        $rows[] = array();

        // ── All Products ──
        $map['products_header'] = count( $rows );
        $rows[] = array( 'ALL PRODUCTS', '', '', '', '', '' );
        $map['products_col_header'] = count( $rows );
        $rows[] = array( 'SKU', 'Product Name', 'Category', 'Price', 'Stock Qty', 'Status' );
        $map['products_data_start'] = count( $rows );
        $map['product_row_status']  = array();
        foreach ( $data['all_products'] as $p ) {
            $status_label = match ( $p['status'] ) {
                'instock'    => 'In Stock',
                'low_stock'  => 'Low Stock',
                'outofstock' => 'Out of Stock',
                default      => $p['status'],
            };
            $rows[] = array(
                $p['sku'] ?: '-',
                $p['name'],
                $p['category'] ?: 'Uncategorized',
                $this->sheet_money( (float) $p['price'] ),
                $p['stock_qty'],
                $status_label,
            );
            $map['product_row_status'][ count( $rows ) - 1 ] = $p['status'];
        }
        $map['products_data_end'] = count( $rows ) - 1;
        $rows[] = array();

        // ── Low Stock Alert ──
        if ( ! empty( $data['low_stock'] ) ) {
            $map['low_header'] = count( $rows );
            $rows[] = array( 'LOW STOCK ALERT', '', '', '', '', '' );
            $map['low_col_header'] = count( $rows );
            $rows[] = array( 'SKU', 'Product Name', 'Category', 'Price', 'Stock Qty', '' );
            $map['low_data_start'] = count( $rows );
            foreach ( $data['low_stock'] as $p ) {
                $rows[] = array(
                    $p['sku'] ?: '-',
                    $p['name'],
                    $p['category'],
                    $this->sheet_money( (float) $p['price'] ),
                    $p['stock_qty'],
                    '',
                );
            }
            $map['low_data_end'] = count( $rows ) - 1;
            $rows[] = array();
        }

        // ── Out of Stock ──
        if ( ! empty( $data['out_of_stock'] ) ) {
            $map['out_header'] = count( $rows );
            $rows[] = array( 'OUT OF STOCK', '', '', '', '', '' );
            $map['out_col_header'] = count( $rows );
            $rows[] = array( 'SKU', 'Product Name', 'Category', 'Price', '', '' );
            $map['out_data_start'] = count( $rows );
            foreach ( $data['out_of_stock'] as $p ) {
                $rows[] = array(
                    $p['sku'] ?: '-',
                    $p['name'],
                    $p['category'],
                    $this->sheet_money( (float) $p['price'] ),
                    '',
                    '',
                );
            }
            $map['out_data_end'] = count( $rows ) - 1;
            $rows[] = array();
        }

        // ── Category Breakdown ──
        if ( ! empty( $data['categories'] ) ) {
            $map['category_header'] = count( $rows );
            $rows[] = array( 'CATEGORY BREAKDOWN', '', '', '', '', '' );
            $map['category_col_header'] = count( $rows );
            $rows[] = array( 'Category', 'Total Products', 'In Stock', 'Low Stock', 'Out of Stock', '' );
            $map['category_data_start'] = count( $rows );
            foreach ( $data['categories'] as $c ) {
                $rows[] = array( $c['name'], $c['total'], $c['in_stock'], $c['low_stock'], $c['out_of_stock'], '' );
            }
            $map['category_data_end'] = count( $rows ) - 1;
        }

        $range = $sheet_name . '!A1';
        $client->ensure_sheet_exists( $spreadsheet_id, $sheet_name );
        $map['total_rows'] = count( $rows );
        $client->reset_sheet_for_dashboard_export( $spreadsheet_id, $sheet_name, count( $rows ) );
        $client->set_rows( $spreadsheet_id, $range, $rows );

        try {
            $sheet_id = $client->get_sheet_id( $spreadsheet_id, $sheet_name );
            $this->apply_inventory_formatting( $client, $spreadsheet_id, $sheet_id, $map );
        } catch ( Exception $e ) {
            SheetSync_Logger::info( 'Inventory Dashboard styling skipped: ' . $e->getMessage(), null, 0 );
        }

        SheetSync_Logger::info(
            sprintf(
                'Inventory Dashboard exported: %d products (%d low stock, %d out of stock).',
                $s['total_products'],
                $s['low_stock'],
                $s['out_of_stock']
            ),
            null,
            count( $rows )
        );

        return array(
            'message'       => __( 'Inventory Dashboard exported successfully!', 'sheetsync-for-woocommerce' ),
            'rows_written'  => count( $rows ),
            'total'         => $s['total_products'],
            'low_stock'     => $s['low_stock'],
            'out_of_stock'  => $s['out_of_stock'],
            'sheet_url'     => class_exists( 'SheetSync_Dashboard_Enhancements', false )
                ? SheetSync_Dashboard_Enhancements::sheets_url( $spreadsheet_id )
                : 'https://docs.google.com/spreadsheets/d/' . rawurlencode( $spreadsheet_id ) . '/edit',
        );
    }

    /**
     * Apply WooAnalytics-style formatting to inventory sheet export.
     *
     * @param array<string, int> $map Row index map (0-based).
     */
    private function apply_inventory_formatting( SheetSync_Sheets_Client $client, string $spreadsheet_id, int $sheet_id, array $map ): void {
        $requests = array();
        $max_cols = 6;

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

        // Title banner
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

        // Meta row
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

        $section_keys = array(
            'summary_header',
            'products_header',
            'low_header',
            'out_header',
            'category_header',
        );
        foreach ( $section_keys as $key ) {
            if ( ! isset( $map[ $key ] ) ) {
                continue;
            }
            $sr = $map[ $key ];
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

        $col_header_keys = array(
            'summary_col_header',
            'products_col_header',
            'low_col_header',
            'out_col_header',
            'category_col_header',
        );
        foreach ( $col_header_keys as $key ) {
            if ( ! isset( $map[ $key ] ) ) {
                continue;
            }
            $cr = $map[ $key ];
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

        // Summary values row highlight
        if ( isset( $map['summary_data'] ) ) {
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => $range_req( $map['summary_data'], $map['summary_data'], 0, 5 ),
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

        $alt_ranges = array(
            array( 'products_data_start', 'products_data_end', 6 ),
            array( 'low_data_start', 'low_data_end', 6 ),
            array( 'out_data_start', 'out_data_end', 5 ),
            array( 'category_data_start', 'category_data_end', 5 ),
        );
        foreach ( $alt_ranges as $range_def ) {
            $start_key = $range_def[0];
            $end_key   = $range_def[1];
            $cols      = $range_def[2];
            if ( ! isset( $map[ $start_key ], $map[ $end_key ] ) || $map[ $start_key ] > $map[ $end_key ] ) {
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

        // Status column highlight (products table)
        if ( ! empty( $map['product_row_status'] ) && is_array( $map['product_row_status'] ) ) {
            foreach ( $map['product_row_status'] as $row_idx => $status ) {
                $bg = $white;
                if ( $status === 'low_stock' ) {
                    $bg = $yellow_light;
                } elseif ( $status === 'outofstock' ) {
                    $bg = $red_light;
                } elseif ( $status === 'instock' ) {
                    $bg = $green_light;
                }
                $requests[] = array(
                    'repeatCell' => array(
                        'range'  => $range_req( (int) $row_idx, (int) $row_idx, 5, 6 ),
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

        $col_widths = array( 120, 260, 160, 100, 100, 120 );
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
