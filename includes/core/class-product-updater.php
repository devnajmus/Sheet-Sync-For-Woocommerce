<?php
/**
 * Applies data from a Google Sheets row to a WooCommerce product.
 * @package SheetSync_For_WooCommerce
 * @since   1.0.0
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Product_Updater' ) ) :

class SheetSync_Product_Updater {

    /** @var bool */
    private static bool $internal_update = false;

    /** @var array<string, array{sheet_column: string, is_key_field: bool}> */
    private array $maps;

    public static function is_internal_update(): bool {
        return self::$internal_update
            || ( defined( 'SHEETSYNC_DOING_PRODUCT_UPDATE' ) && SHEETSYNC_DOING_PRODUCT_UPDATE );
    }

    public static function flag_internal_update( bool $flag ): void {
        self::$internal_update = $flag;
        if ( $flag && ! defined( 'SHEETSYNC_DOING_PRODUCT_UPDATE' ) ) {
            define( 'SHEETSYNC_DOING_PRODUCT_UPDATE', true );
        }
    }

    /** @var array<string, int> Per-request SKU → product ID cache (lazy lookups). */
    private array $sku_lookup_cache = array();

    public function __construct( array $maps ) {
        $this->maps = $maps;
    }

    /**
     * @return array<string, array{sheet_column: string, is_key_field: bool}>
     */
    public function get_maps(): array {
        return $this->maps;
    }

    /**
     * Resolve product ID from SKU (batch cache + ambiguity guard).
     *
     * @param array<string, string> $data Mapped row values.
     */
    public function lookup_product_id_by_sku( string $sku, array $data ): int {
        return $this->resolve_product_id_by_sku( $sku, $data );
    }

    /**
     * Resolve product ID from SKU, respecting duplicate-SKU catalogs and sheet product_id.
     *
     * @param array<string, string> $data Mapped row values.
     */
    private function resolve_product_id_by_sku( string $sku, array $data ): int {
        $sku = sanitize_text_field( $sku );
        if ( $sku === '' ) {
            return 0;
        }

        if ( class_exists( 'SheetSync_Product_Map_Repository', false )
            && SheetSync_Product_Map_Repository::sku_is_ambiguous_in_catalog( $sku ) ) {
            if ( ! empty( $data['product_id'] ) ) {
                $pid = absint( $data['product_id'] );
                if ( $pid > 0 ) {
                    return $pid;
                }
            }
            return 0;
        }

        if ( isset( $this->sku_lookup_cache[ $sku ] ) ) {
            $cached = (int) $this->sku_lookup_cache[ $sku ];
            $ambiguous = class_exists( 'SheetSync_Product_Resolver', false )
                ? SheetSync_Product_Resolver::SKU_AMBIGUOUS
                : -1;
            if ( $ambiguous === $cached ) {
                if ( ! empty( $data['product_id'] ) ) {
                    $pid = absint( $data['product_id'] );
                    if ( $pid > 0 ) {
                        return $pid;
                    }
                }
                return 0;
            }
            return $cached;
        }

        $id = (int) wc_get_product_id_by_sku( $sku );
        $this->sku_lookup_cache[ $sku ] = $id > 0 ? $id : 0;

        return $this->sku_lookup_cache[ $sku ];
    }

    /**
     * Preload SKU → product ID mappings for a batch (one DB query).
     *
     * @param string[] $skus
     */
    public function warm_sku_lookup_cache( array $skus ): void {
        global $wpdb;

        $skus = array_unique(
            array_filter(
                array_map(
                    static fn( $sku ): string => sanitize_text_field( (string) $sku ),
                    $skus
                )
            )
        );
        $missing = array();
        foreach ( $skus as $sku ) {
            if ( ! isset( $this->sku_lookup_cache[ $sku ] ) ) {
                $missing[] = $sku;
            }
        }
        if ( empty( $missing ) ) {
            return;
        }

        $placeholders = implode( ',', array_fill( 0, count( $missing ), '%s' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT meta_value AS sku, post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_sku' AND meta_value IN ({$placeholders})";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( sheetsync_wpdb_prepare( $sql, $missing ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $by_sku = array();
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $sku = sanitize_text_field( (string) ( $row->sku ?? '' ) );
                $pid = (int) ( $row->post_id ?? 0 );
                if ( $sku === '' || $pid <= 0 ) {
                    continue;
                }
                if ( ! isset( $by_sku[ $sku ] ) ) {
                    $by_sku[ $sku ] = array();
                }
                $by_sku[ $sku ][] = $pid;
            }
        }
        foreach ( $missing as $sku ) {
            $ids = $by_sku[ $sku ] ?? array();
            if ( count( $ids ) > 1 ) {
                $this->sku_lookup_cache[ $sku ] = class_exists( 'SheetSync_Product_Resolver', false )
                    ? SheetSync_Product_Resolver::SKU_AMBIGUOUS
                    : -1;
            } elseif ( 1 === count( $ids ) ) {
                $this->sku_lookup_cache[ $sku ] = (int) $ids[0];
            } else {
                $this->sku_lookup_cache[ $sku ] = 0;
            }
        }
    }

    /**
     * Process one sheet row. Returns 'updated'|'skipped'|'queued'|'error'.
     *
     * @param array<int, string> $row Raw sheet row.
     */
    public function update( array $row, int $sheet_row = 0 ): string {
        // Extract data using field maps
        $data = $this->extract_data( $row );
        if ( empty( $data ) ) {
            return 'skipped';
        }

        /**
         * Allow add-ons (e.g. SheetSync Pro) to handle a row before the default
         * simple-product path (variations, custom row types). Return one of:
         * updated|skipped|queued|error to short-circuit, or null to continue.
         *
         * @param string|null            $handled Early return value.
         * @param array<string, string> $data    Mapped column values.
         * @param array<int, string>    $row     Raw sheet row.
         * @param array<string, mixed>  $maps    Field maps for this connection.
         */
        $handled = apply_filters( 'sheetsync_product_row_handled', null, $data, $row, $this->maps, $sheet_row );
        if ( is_string( $handled ) && in_array( $handled, array( 'updated', 'skipped', 'queued', 'error' ), true ) ) {
            return $handled;
        }

        // Safety net: never turn variation / variable-parent rows into simple products.
        if ( class_exists( 'SheetSync_Variation_Sync', false ) && function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro() ) {
            if ( SheetSync_Variation_Sync::is_variation_row( $data ) ) {
                $sync = new SheetSync_Variation_Sync();
                return $sync->sync_variation( $data, $this->maps, $row, $sheet_row );
            }
            if ( SheetSync_Variation_Sync::is_variable_parent_row( $data ) ) {
                $sync = new SheetSync_Variation_Sync();
                return $sync->sync_variable_parent( $data, $this->maps, $row );
            }
        }

        // Find the WooCommerce product — create new if not found.
        if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
            $conn_id = SheetSync_Import_Row_Service::get_current_connection_id();
            $sku_msg = SheetSync_Import_Row_Service::ambiguous_sku_import_message( $data, $conn_id );
            if ( is_string( $sku_msg ) && $sku_msg !== '' ) {
                if ( $sheet_row > 0 ) {
                    SheetSync_Import_Row_Service::log_row_result( $conn_id, $sheet_row, $data, 'error', $sku_msg );
                }
                return 'error';
            }
            if ( SheetSync_Import_Row_Service::is_ambiguous_title_match( $data, $conn_id ) ) {
                if ( $sheet_row > 0 ) {
                    SheetSync_Import_Row_Service::log_row_result(
                        $conn_id,
                        $sheet_row,
                        $data,
                        'error',
                        __( 'Multiple WooCommerce products share this title — map Product ID or use a unique SKU.', 'sheetsync-for-woocommerce' )
                    );
                }
                return 'error';
            }
        }

        $product = $this->find_product( $data, $sheet_row );
        $is_new  = false;
        if ( ! $product ) {
            // Skip if no SKU or Title.
            if ( empty( $data['_sku'] ) && empty( $data['post_title'] ) ) {
                return 'skipped';
            }
            $product = self::create_product_for_import_row( $data );
            $is_new  = true;
        }

        $connection_id = class_exists( 'SheetSync_Import_Row_Service', false )
            ? SheetSync_Import_Row_Service::get_current_connection_id()
            : 0;
        $old_sku       = $is_new ? '' : (string) $product->get_sku();

        // Skip when the sheet row already matches WooCommerce (compare BEFORE apply_updates).
        if ( ! $is_new && $this->row_matches_product( $row, $product ) ) {
            return 'skipped';
        }

        // Apply updates
        try {
            $product_data = $data;
            $image_data   = $this->pull_image_fields( $product_data );
            $clear_fields = class_exists( 'SheetSync_Import_Rules', false )
                ? SheetSync_Import_Rules::fields_to_clear_in_wc( $this->empty_mapped_fields( $row ), $data )
                : array();
            $this->apply_updates( $product, $product_data, $clear_fields );

            // Set default status for new product if not provided.
            if ( $is_new && empty( $data['post_status'] ) ) {
                $product->set_status( 'publish' );
            }

            if ( function_exists( 'sheetsync_apply_import_price_policy' )
                && sheetsync_apply_import_price_policy( $product, $data, $is_new ) ) {
                $data['_price_downgraded'] = '1';
            }

            // BUG FIX: New products with no mapped/populated title were saved
            // with the WooCommerce default name "Product". Set the SKU as the
            // fallback name so the product is at least identifiable.
            if ( $is_new && '' === $product->get_name() ) {
                $fallback_name = ! empty( $data['_sku'] ) ? $data['_sku'] : __( 'Imported Product', 'sheetsync-for-woocommerce' );
                $product->set_name( sanitize_text_field( $fallback_name ) );
            }

            self::flag_internal_update( true );
            $this->save_product_with_retry( $product );
            self::flag_internal_update( false );

            $this->apply_images_to_product( $product, $image_data );

            if ( ! $is_new && $connection_id > 0 && class_exists( 'SheetSync_Product_Resolver', false ) ) {
                SheetSync_Product_Resolver::rekey_on_sku_change(
                    $connection_id,
                    (int) $product->get_id(),
                    $old_sku,
                    (string) $product->get_sku()
                );
            }

            if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
                SheetSync_Import_Row_Service::log_import_quality_warnings(
                    SheetSync_Import_Row_Service::get_current_connection_id(),
                    $sheet_row,
                    $data,
                    $is_new
                );
            }

            if ( ! empty( $data['grouped_child_skus'] )
                && function_exists( 'sheetsync_analyze_grouped_child_list' )
                && class_exists( 'SheetSync_Import_Row_Service', false ) ) {
                $analysis = sheetsync_analyze_grouped_child_list( (string) $data['grouped_child_skus'] );
                if ( ! empty( $analysis['pending'] ) && $product->is_type( 'grouped' ) && $product->get_id() > 0 ) {
                    $conn_id = SheetSync_Import_Row_Service::get_current_connection_id();
                    if ( $conn_id > 0 ) {
                        SheetSync_Import_Row_Service::queue_grouped_link_retry(
                            $conn_id,
                            (int) $product->get_id(),
                            (string) $data['grouped_child_skus']
                        );
                    }
                }
            }

            return 'updated';
        } catch ( Exception $e ) {
            $connection_id = class_exists( 'SheetSync_Import_Row_Service', false )
                ? SheetSync_Import_Row_Service::get_current_connection_id()
                : 0;
            $context = $sheet_row > 0
                ? sprintf( 'Row %d: %s', $sheet_row, $e->getMessage() )
                : $e->getMessage();
            SheetSync_Logger::log( $connection_id > 0 ? $connection_id : null, 'import', 'error', 0, 0, $context, 1 );
            return 'error';
        }
    }

    /**
     * Apply pre-merged field data to an existing product (two-way conflict merge).
     *
     * @param WC_Product            $product   Target product.
     * @param array<string, string> $data      Merged field values.
     * @param int                   $sheet_row 1-based sheet row for logging.
     * @param array<int, string>    $raw_row   Optional raw sheet row (empty-cell clear policy).
     * @return string updated|skipped|error
     */
    public function update_from_data( WC_Product $product, array $data, int $sheet_row = 0, array $raw_row = array() ): string {
        $clear_fields = class_exists( 'SheetSync_Import_Rules', false ) && ! empty( $raw_row )
            ? SheetSync_Import_Rules::fields_to_clear_in_wc( $this->empty_mapped_fields( $raw_row ), $data )
            : array();

        if ( empty( $data ) && empty( $clear_fields ) ) {
            return 'skipped';
        }

        try {
            $product_data = $data;
            $image_data   = $this->pull_image_fields( $product_data );
            $this->apply_updates( $product, $product_data, $clear_fields );

            if ( function_exists( 'sheetsync_apply_import_price_policy' )
                && sheetsync_apply_import_price_policy( $product, $data, false ) ) {
                $data['_price_downgraded'] = '1';
            }

            self::flag_internal_update( true );
            $this->save_product_with_retry( $product );
            self::flag_internal_update( false );

            $this->apply_images_to_product( $product, $image_data );

            return 'updated';
        } catch ( Exception $e ) {
            $connection_id = class_exists( 'SheetSync_Import_Row_Service', false )
                ? SheetSync_Import_Row_Service::get_current_connection_id()
                : 0;
            $context = $sheet_row > 0
                ? sprintf( 'Row %d: %s', $sheet_row, $e->getMessage() )
                : $e->getMessage();
            SheetSync_Logger::log( $connection_id > 0 ? $connection_id : null, 'import', 'error', 0, 0, $context, 1 );
            return 'error';
        }
    }

    /**
     * Mapped field values from a WooCommerce product.
     *
     * @param WC_Product    $product Product to read.
     * @param string[]|null $fields  Optional field keys; defaults to all mapped fields.
     * @return array<string, string>
     */
    public function extract_product_data( WC_Product $product, ?array $fields = null ): array {
        $keys = $fields ?? array_keys( $this->maps );
        $data = array();
        foreach ( $keys as $field ) {
            if ( ! isset( $this->maps[ $field ] ) ) {
                continue;
            }
            $data[ $field ] = (string) $this->get_product_field_value( $product, $field );
        }
        return $data;
    }

    /**
     * Create the correct WooCommerce product object for a new import row.
     *
     * @param array<string, string> $data Mapped row values.
     * @return WC_Product
     */
    public static function create_product_for_import_row( array $data ): WC_Product {
        $type = strtolower( trim( (string) ( $data['_product_type'] ?? 'simple' ) ) );
        if ( function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro() ) {
            if ( 'grouped' === $type && class_exists( 'WC_Product_Grouped', false ) ) {
                return new WC_Product_Grouped();
            }
            if ( 'external' === $type && class_exists( 'WC_Product_External', false ) ) {
                return new WC_Product_External();
            }
            if ( 'variable' === $type && class_exists( 'WC_Product_Variable', false ) ) {
                return new WC_Product_Variable();
            }
        }
        return new WC_Product_Simple();
    }

    /**
     * Save with short retry/backoff for transient DB / lock errors.
     */
    private function save_product_with_retry( WC_Product $product ): void {
        $attempts = max( 1, min( 5, (int) apply_filters( 'sheetsync_product_save_attempts', 3 ) ) );
        $last     = null;
        for ( $i = 0; $i < $attempts; ++$i ) {
            try {
                $product->save();
                return;
            } catch ( Exception $e ) {
                $last = $e;
                if ( $i < $attempts - 1 ) {
                    usleep( 100000 * ( $i + 1 ) );
                }
            }
        }
        if ( $last ) {
            throw $last;
        }
    }

    /**
     * @param array<string, string> $data Mapped row data (by reference).
     * @return array<string, string> Image fields removed from $data.
     */
    private function pull_image_fields( array &$data ): array {
        $image_data = array();
        foreach ( array( '_product_image', '_gallery_images' ) as $field ) {
            if ( isset( $data[ $field ] ) && $data[ $field ] !== '' ) {
                $image_data[ $field ] = $data[ $field ];
                unset( $data[ $field ] );
            }
        }
        return $image_data;
    }

    /**
     * Apply image fields immediately or enqueue for deferred background sideload.
     *
     * @param array<string, string> $image_data
     */
    public function apply_images_to_product( WC_Product $product, array $image_data ): void {
        if ( empty( $image_data ) ) {
            return;
        }

        $defer = function_exists( 'sheetsync_should_defer_image_sideload' )
            && sheetsync_should_defer_image_sideload();

        if ( $defer && class_exists( 'SheetSync_Media_Queue', false ) ) {
            if ( $product->get_id() <= 0 ) {
                self::flag_internal_update( true );
                $this->save_product_with_retry( $product );
                self::flag_internal_update( false );
            }
            $connection_id = class_exists( 'SheetSync_Import_Row_Service', false )
                ? SheetSync_Import_Row_Service::get_current_connection_id()
                : 0;
            if ( $product->get_id() > 0 ) {
                SheetSync_Media_Queue::enqueue_product_images( $connection_id, (int) $product->get_id(), $image_data );
                if ( function_exists( 'sheetsync_media_queue_maybe_process_inline' ) ) {
                    sheetsync_media_queue_maybe_process_inline( $connection_id );
                }
                return;
            }
        }

        $this->apply_updates( $product, $image_data );
        self::flag_internal_update( true );
        $this->save_product_with_retry( $product );
        self::flag_internal_update( false );
    }

    /**
     * Extract mapped data from a raw row.
     *
     * @return array<string, string>
     */
    /**
     * Public wrapper so sync filters (category limits, etc.) can inspect mapped values without duplicating logic.
     *
     * @param array<int, string> $row Raw sheet row.
     * @return array<string, string>
     */
    public function extract_data( array $row ): array {
        // Strip export-only title prefixes (↳ / ▸) before sync.
        $data = array();
        foreach ( $this->maps as $wc_field => $map_info ) {
            $col_index = SheetSync_Field_Mapper::col_to_index( $map_info['sheet_column'] );
            $value     = $row[ $col_index ] ?? '';
            if ( $value !== '' ) {
                $data[ $wc_field ] = $value;
            }
        }

        if ( ! empty( $data['post_title'] ) && class_exists( 'SheetSync_Export_Order', false ) ) {
            $data['post_title'] = SheetSync_Export_Order::sanitize_import_title( $data['post_title'] );
        }

        /**
         * @param array<string, string> $data
         * @param array<int, string>    $row
         * @param array<string, mixed>  $maps
         */
        return apply_filters( 'sheetsync_row_mapped_data', $data, $row, $this->maps );
    }

    /**
     * Mapped fields whose sheet cells are blank on this row.
     *
     * @param array<int, string> $row Raw sheet row.
     * @return list<string>
     */
    public function empty_mapped_fields( array $row ): array {
        $empty = array();
        foreach ( $this->maps as $wc_field => $map_info ) {
            $col_index = SheetSync_Field_Mapper::col_to_index( $map_info['sheet_column'] );
            $value     = trim( (string) ( $row[ $col_index ] ?? '' ) );
            if ( $value === '' ) {
                $empty[] = $wc_field;
            }
        }
        return $empty;
    }

    /**
     * Find a WooCommerce product matching the row's key field(s).
     *
     * BUG FIX: Previously find_product() only checked $data['_sku'] hardcoded.
     * If _sku was not the key field (or not mapped), products were never found
     * and new duplicates were created on every sync.
     *
     * Now: first checks the field marked as is_key_field in field maps,
     * then falls back to _sku lookup, then falls back to title match.
     */
    private function find_product( array $data, int $sheet_row = 0 ): ?WC_Product {
        $connection_id = class_exists( 'SheetSync_Import_Row_Service', false )
            ? SheetSync_Import_Row_Service::get_current_connection_id()
            : 0;

        if ( class_exists( 'SheetSync_Product_Resolver', false ) ) {
            return SheetSync_Product_Resolver::resolve_for_import(
                $connection_id,
                $data,
                $this->maps,
                $sheet_row,
                $this
            );
        }

        return null;
    }

    /**
     * Apply all mapped field updates to the product object.
     * Does NOT call $product->save() — caller handles that.
     *
     * @param list<string> $clear_fields Mapped fields to clear when sheet cells are blank (clear policy).
     */
    public function apply_updates( WC_Product $product, array $data, array $clear_fields = array() ): void {
        foreach ( $data as $field => $value ) {
            $this->apply_field( $product, $field, $value );
        }
        foreach ( $clear_fields as $field ) {
            if ( ! isset( $data[ $field ] ) ) {
                $this->apply_field( $product, $field, '', true );
            }
        }
    }

    /**
     * Apply a single field update.
     */
    private function apply_field( WC_Product $product, string $field, string $value, bool $clear_if_empty = false ): void {
        switch ( $field ) {
            case 'sheet_row_role':
            case 'primary_category':
            case 'sheet_product_group':
            case 'sheet_option_summary':
            case 'sheet_belongs_to':
            case 'product_id':
                break;

            case 'grouped_child_skus':
                break;

            // ── Free fields ───────────────────────────────────────────
            case 'post_title':
                $product->set_name( class_exists( 'SheetSync_Export_Order', false )
                    ? SheetSync_Export_Order::sanitize_import_title( $value )
                    : sanitize_text_field( $value ) );
                break;

            case '_sku':
                $product->set_sku( sanitize_text_field( $value ) );
                break;

            case '_regular_price':
                $raw_price = trim( $value );
                if ( $raw_price === '' ) {
                    if ( $clear_if_empty ) {
                        $product->set_regular_price( '' );
                        if ( '' === $product->get_sale_price() ) {
                            $product->set_price( '' );
                        }
                    }
                    break;
                }
                if ( $product->is_type( 'variable' )
                    && class_exists( 'SheetSync_Field_Mapper', false )
                    && SheetSync_Field_Mapper::is_display_price_range( $raw_price ) ) {
                    break;
                }
                $price = function_exists( 'sheetsync_normalize_sheet_price' )
                    ? sheetsync_normalize_sheet_price( $raw_price )
                    : wc_format_decimal( $raw_price );
                if ( $price !== '' && is_numeric( $price ) ) {
                    $product->set_regular_price( $price );
                    // If no sale price is active, set_price must also be updated
                    // so the product's displayed price reflects the new regular price.
                    if ( '' === $product->get_sale_price() ) {
                        $product->set_price( $price );
                    }
                } elseif ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
                    $conn_id = SheetSync_Import_Row_Service::get_current_connection_id();
                    if ( $conn_id > 0 ) {
                        SheetSync_Import_Row_Service::log_import_issue(
                            $conn_id,
                            'INVALID_REGULAR_PRICE',
                            sprintf(
                                /* translators: 1: raw price cell, 2: SKU */
                                __( 'Invalid regular price "%1$s" for SKU %2$s — value ignored.', 'sheetsync-for-woocommerce' ),
                                $raw_price,
                                $product->get_sku() ?: __( '(no SKU)', 'sheetsync-for-woocommerce' )
                            ),
                            'warn',
                            0,
                            $product->get_sku() ?: $raw_price
                        );
                    }
                }
                break;

            case '_stock':
                if ( trim( $value ) === '' ) {
                    if ( $clear_if_empty ) {
                        $product->set_manage_stock( true );
                        $product->set_stock_quantity( 0 );
                        if ( ! isset( $this->maps['_stock_status'] ) ) {
                            $product->set_stock_status( 'outofstock' );
                        }
                    }
                    break;
                }
                $qty = (int) $value;
                $product->set_manage_stock( true );
                $product->set_stock_quantity( $qty );
                // Only auto-set stock status if _stock_status is NOT also mapped
                // (prevents conflict when both fields are mapped — _stock_status takes priority)
                if ( ! isset( $this->maps['_stock_status'] ) ) {
                    $product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
                }
                break;

            case 'post_status':
                $status = strtolower( trim( $value ) );
                if ( in_array( $status, array( 'publish', 'draft', 'private' ), true ) ) {
                    $product->set_status( $status );
                }
                break;

            case 'menu_order':
                $order = (int) $value;
                $product->set_menu_order( $order );
                break;

            // ── Pro fields (no-op in Free; Pro hooks `sheetsync_handle_premium_field`) ──
            case '_sale_price':
            case 'post_excerpt':
            case '_stock_status':
            case '_weight':
            case '_length':
            case '_width':
            case '_height':
            case 'post_content':
            case '_product_type':
            case '_product_url':
            case '_product_cats':
            case '_product_tags':
                if ( $clear_if_empty && trim( $value ) === '' ) {
                    $this->clear_product_field( $product, $field );
                    break;
                }
                if ( apply_filters( 'sheetsync_handle_premium_field', false, $product, $field, $value, $this->maps ) ) {
                    break;
                }
                break;

            case '_product_image':
            case '_gallery_images':
                if ( ! apply_filters( 'sheetsync_handle_premium_field', false, $product, $field, $value, $this->maps )
                    && class_exists( 'SheetSync_Sheet_Image_Resolver', false ) ) {
                    SheetSync_Sheet_Image_Resolver::apply_to_product( $product, $field, $value );
                }
                break;

            default:
                /**
                 * Let Pro (or another add-on) persist arbitrary meta / SCF-style keys.
                 * Return true if the field was handled.
                 *
                 * @param bool          $handled False by default.
                 * @param WC_Product    $product Product object.
                 * @param string        $field   Map key from the sheet (e.g. meta__my_key).
                 * @param string        $value   Cell value.
                 * @param array<string, mixed> $maps Field maps.
                 */
                if ( apply_filters( 'sheetsync_apply_product_meta_field', false, $product, $field, $value, $this->maps ) ) {
                    break;
                }
                break;
        }
    }

    /**
     * Clear a WooCommerce field when the sheet cell is intentionally blank (clear policy).
     */
    private function clear_product_field( WC_Product $product, string $field ): void {
        switch ( $field ) {
            case '_sale_price':
                $product->set_sale_price( '' );
                $product->set_price( $product->get_regular_price() );
                break;
            case 'post_excerpt':
                $product->set_short_description( '' );
                break;
            case 'post_content':
                $product->set_description( '' );
                break;
            case '_stock_status':
                $product->set_stock_status( 'instock' );
                break;
            default:
                if ( apply_filters( 'sheetsync_handle_premium_field', false, $product, $field, '', $this->maps ) ) {
                    break;
                }
                break;
        }
    }

    /**
     * Gallery images — set from comma-separated URLs.
     *
     * FIX H-3: Each sideloaded attachment is MIME-validated before use.
     * Files that are not safe raster images are deleted immediately.
     *
     * @param WC_Product $product     Product to update.
     * @param string     $urls_string Comma-separated list of image URLs.
     * @return void
     */
    private function set_gallery_images_from_urls( WC_Product $product, string $urls_string ): void {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $allowed_mimes = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
        $urls          = array_filter( array_map( 'trim', explode( ',', $urls_string ) ) );
        $attach_ids    = array();

        foreach ( $urls as $url ) {
            if ( ! self::is_safe_image_import_url( $url ) ) {
                continue;
            }

            // Optional HEAD size check — do not skip download when HEAD fails (CDN blocks HEAD).
            $head           = wp_remote_head( $url, array( 'timeout' => 8, 'redirection' => 5 ) );
            $content_length = is_wp_error( $head ) ? 0 : (int) wp_remote_retrieve_header( $head, 'content-length' );
            if ( $content_length > 5 * MB_IN_BYTES ) {
                continue; // Skip files > 5 MB.
            }

            $id = function_exists( 'sheetsync_sideload_image_from_url' )
                ? sheetsync_sideload_image_from_url( $url, $product->get_id() ?: 0 )
                : media_sideload_image( $url, $product->get_id() ?: 0, null, 'id' );
            if ( is_wp_error( $id ) ) {
                SheetSync_Logger::error( 'SheetSync gallery sideload failed: ' . $id->get_error_message() . ' — ' . $url );
                continue;
            }

            // FIX H-3: Validate MIME type — reject anything that isn't a safe raster image.
            $mime = (string) get_post_mime_type( $id );
            if ( ! in_array( $mime, $allowed_mimes, true ) ) {
                wp_delete_attachment( $id, true );
                continue;
            }

            $attach_ids[] = $id;
        }

        if ( ! empty( $attach_ids ) ) {
            $product->set_gallery_image_ids( $attach_ids );
        }
    }

    /**
     * Import an image from a URL and set it as the product thumbnail.
     *
     * FIX H-3: After sideloading, the attachment MIME type is validated.
     * If the file is not a safe raster image (e.g. PHP script, SVG with JS),
     * the attachment is deleted immediately and the product image is not updated.
     *
     * @param WC_Product $product Product to update.
     * @param string     $url     Pre-validated remote image URL.
     * @return void
     */
    private function set_product_image_from_url( WC_Product $product, string $url ): void {
        if ( ! self::is_safe_image_import_url( $url ) ) {
            SheetSync_Logger::error( 'SheetSync image blocked (private/reserved URL) — ' . $url );
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $head = wp_remote_head( $url, array( 'timeout' => 8, 'redirection' => 5 ) );
        if ( ! is_wp_error( $head ) ) {
            $content_length = (int) wp_remote_retrieve_header( $head, 'content-length' );
            if ( $content_length > 5 * MB_IN_BYTES ) {
                return;
            }
        }

        $attachment_id = function_exists( 'sheetsync_sideload_image_from_url' )
            ? sheetsync_sideload_image_from_url( $url, $product->get_id() ?: 0 )
            : media_sideload_image( $url, $product->get_id() ?: 0, null, 'id' );
        if ( is_wp_error( $attachment_id ) ) {
            SheetSync_Logger::error( 'SheetSync image sideload failed: ' . $attachment_id->get_error_message() . ' — ' . $url );
            return;
        }

        // FIX H-3: Validate MIME type — only safe raster formats accepted.
        $allowed_mimes = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
        $mime          = (string) get_post_mime_type( $attachment_id );
        if ( ! in_array( $mime, $allowed_mimes, true ) ) {
            wp_delete_attachment( $attachment_id, true );
            SheetSync_Logger::error(
                /* translators: 1: MIME type, 2: URL */
                sprintf( __( 'Rejected image with disallowed MIME type "%1$s" from: %2$s', 'sheetsync-for-woocommerce' ), $mime, $url )
            );
            return;
        }

        $product->set_image_id( $attachment_id );
    }

    /**
     * Block SSRF — private/reserved hosts (same guard as SheetSync_Sheet_Image_Resolver).
     */
    private static function is_safe_image_import_url( string $url ): bool {
        $url = trim( $url );
        if ( $url === '' || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return false;
        }
        if ( function_exists( 'sheetsync_is_safe_remote_url' ) ) {
            return sheetsync_is_safe_remote_url( $url );
        }
        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        return in_array( $scheme, array( 'http', 'https' ), true );
    }

    /**
     * Set product categories from comma-separated string.
     */
    private function set_product_categories( WC_Product $product, string $cats_string ): void {
        $cat_names = array_filter( array_map( 'trim', explode( ',', $cats_string ) ) );
        $term_ids  = array();

        foreach ( $cat_names as $name ) {
            $term = function_exists( 'sheetsync_resolve_term_by_name' )
                ? sheetsync_resolve_term_by_name( $name, 'product_cat' )
                : get_term_by( 'name', $name, 'product_cat' );
            if ( ! $term ) {
                $clean = function_exists( 'sheetsync_decode_sheet_text' )
                    ? sheetsync_decode_sheet_text( $name )
                    : $name;
                $result = wp_insert_term( $clean, 'product_cat' );
                if ( ! is_wp_error( $result ) ) {
                    $term_ids[] = $result['term_id'];
                }
            } else {
                $term_ids[] = $term->term_id;
            }
        }

        if ( ! empty( $term_ids ) ) {
            $product->set_category_ids( $term_ids );
        }
    }

    /**
     * Set product tags from comma-separated string.
     */
    private function set_product_tags( WC_Product $product, string $tags_string ): void {
        $tag_names = array_filter( array_map( 'trim', explode( ',', $tags_string ) ) );
        $term_ids  = array();

        foreach ( $tag_names as $name ) {
            $term = function_exists( 'sheetsync_resolve_term_by_name' )
                ? sheetsync_resolve_term_by_name( $name, 'product_tag' )
                : get_term_by( 'name', $name, 'product_tag' );
            if ( ! $term ) {
                $clean  = function_exists( 'sheetsync_decode_sheet_text' )
                    ? sheetsync_decode_sheet_text( $name )
                    : $name;
                $result = wp_insert_term( $clean, 'product_tag' );
                if ( ! is_wp_error( $result ) ) {
                    $term_ids[] = $result['term_id'];
                }
            } else {
                $term_ids[] = $term->term_id;
            }
        }

        if ( ! empty( $term_ids ) ) {
            $product->set_tag_ids( $term_ids );
        }
    }

    /**
     * Whether a sheet row already matches the WooCommerce product (same rules as export hash).
     */
    public function row_matches_product( array $row, WC_Product $product ): bool {
        $hasher = new SheetSync_Hash_Normalizer();
        return $hasher->sheet_hash( $row, $this->maps ) === $hasher->sheet_hash( $this->product_to_row( $product ), $this->maps );
    }

    /**
     * Build a row array for writing a product TO Google Sheets (two-way sync).
     */
    public function product_to_row( WC_Product $product ): array {
        // Find max column index needed
        $max_col = 0;
        foreach ( $this->maps as $map_info ) {
            $idx     = SheetSync_Field_Mapper::col_to_index( $map_info['sheet_column'] );
            $max_col = max( $max_col, $idx );
        }

        $row = array_fill( 0, $max_col + 1, '' );

        foreach ( $this->maps as $field => $map_info ) {
            $idx       = SheetSync_Field_Mapper::col_to_index( $map_info['sheet_column'] );
            $row[$idx] = $this->get_product_field_value( $product, $field );
        }

        return $row;
    }

    /**
     * Truncate a string for Google Sheets display.
     */
    private static function truncate_for_sheet( string $text, string $field = '' ): string {
        $text = trim( $text );
        /**
         * Max characters written to a single sheet cell for long text fields.
         *
         * @param int    $max   Default 320.
         * @param string $field Field key (e.g. post_content).
         * @param string $text  Full text before truncation.
         */
        $max = (int) apply_filters( 'sheetsync_sheet_text_max_length', 320, $field, $text );
        $max = max( 50, min( 5000, $max ) );
        if ( mb_strlen( $text ) <= $max ) {
            return $text;
        }
        return mb_substr( $text, 0, $max - 3 ) . '...';
    }

    /**
     * Stock quantity for export (variable parents sum managed variation stock).
     */
    private static function export_stock_quantity( WC_Product $product ): string {
        if ( $product->is_type( 'variable' ) && $product instanceof WC_Product_Variable ) {
            $total       = 0;
            $has_managed = false;
            foreach ( $product->get_children() as $child_id ) {
                $child = wc_get_product( (int) $child_id );
                if ( ! $child || ! $child->managing_stock() ) {
                    continue;
                }
                $has_managed = true;
                $qty         = $child->get_stock_quantity();
                if ( null !== $qty && '' !== $qty ) {
                    $total += (int) $qty;
                }
            }
            return $has_managed ? (string) $total : '';
        }

        $qty = $product->get_stock_quantity();
        return null !== $qty && '' !== $qty ? (string) $qty : '';
    }

    /**
     * Regular price for export (variable parents show min–max when prices differ).
     */
    private static function export_regular_price( WC_Product $product ): string {
        if ( $product->is_type( 'variable' ) && $product instanceof WC_Product_Variable ) {
            $min = (string) $product->get_variation_regular_price( 'min', true );
            $max = (string) $product->get_variation_regular_price( 'max', true );
            if ( $max !== '' && $min !== '' && $max !== $min ) {
                return $min . ' – ' . $max;
            }
            return $min;
        }
        return (string) $product->get_regular_price();
    }

    /**
     * Sale price for export (variable parents show min–max when sale prices differ).
     */
    private static function export_sale_price( WC_Product $product ): string {
        if ( $product->is_type( 'variable' ) && $product instanceof WC_Product_Variable ) {
            $min = (string) $product->get_variation_sale_price( 'min', true );
            $max = (string) $product->get_variation_sale_price( 'max', true );
            if ( $min === '' ) {
                return '';
            }
            if ( $max !== '' && $max !== $min ) {
                return $min . ' – ' . $max;
            }
            return $min;
        }
        return (string) $product->get_sale_price();
    }

    /**
     * Comma-separated child SKUs (or id:123) for grouped products.
     */
    public static function export_grouped_child_skus( WC_Product $product ): string {
        if ( ! $product->is_type( 'grouped' ) ) {
            return '';
        }

        $parts = array();
        foreach ( $product->get_children() as $child_id ) {
            $child = wc_get_product( (int) $child_id );
            if ( ! $child ) {
                continue;
            }
            $sku = (string) $child->get_sku();
            $parts[] = $sku !== '' ? $sku : 'id:' . $child->get_id();
        }

        return implode( ', ', $parts );
    }

    /**
     * First category name — used for sheet filters (variations inherit parent category).
     */
    public static function export_primary_category( WC_Product $product ): string {
        if ( $product instanceof WC_Product_Variation ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent ) {
                $product = $parent;
            }
        }
        $terms = get_the_terms( $product->get_id(), 'product_cat' );
        if ( ! $terms || is_wp_error( $terms ) ) {
            return '';
        }
        $term = reset( $terms );
        return $term ? sheetsync_decode_sheet_text( (string) $term->name ) : '';
    }

    /**
     * Read a field value from a WC product.
     */
    private function get_product_field_value( WC_Product $product, string $field ): string {
        return match ( $field ) {
            '_sku'            => (string) $product->get_sku(),
            'product_id'      => (string) $product->get_id(),
            'post_title'      => class_exists( 'SheetSync_Export_Order', false )
                ? SheetSync_Export_Order::sheet_export_title( $product )
                : (string) $product->get_name(),
            'sheet_row_role'      => class_exists( 'SheetSync_Export_Order', false )
                ? SheetSync_Export_Order::sheet_row_role_label( $product )
                : '',
            'sheet_product_group' => class_exists( 'SheetSync_Export_Order', false )
                ? SheetSync_Export_Order::sheet_product_group_key( $product )
                : '',
            'sheet_option_summary' => class_exists( 'SheetSync_Export_Order', false )
                ? SheetSync_Export_Order::sheet_option_summary( $product )
                : '',
            'sheet_belongs_to'    => class_exists( 'SheetSync_Export_Order', false )
                ? SheetSync_Export_Order::sheet_belongs_to_label( $product )
                : '',
            '_regular_price'  => self::export_regular_price( $product ),
            '_sale_price'     => self::export_sale_price( $product ),
            '_stock'          => self::export_stock_quantity( $product ),
            'post_status'     => (string) $product->get_status(),
            'menu_order'      => (string) $product->get_menu_order(),
            'post_content'    => self::truncate_for_sheet( wp_strip_all_tags( (string) $product->get_description() ), 'post_content' ),
            'post_excerpt'    => self::truncate_for_sheet( wp_strip_all_tags( (string) $product->get_short_description() ), 'post_excerpt' ),
            '_stock_status'   => (string) $product->get_stock_status(),
            '_weight'         => (string) $product->get_weight(),
            '_length'         => (string) $product->get_length(),
            '_width'          => (string) $product->get_width(),
            '_height'         => (string) $product->get_height(),
            '_product_type'   => (string) $product->get_type(),
            '_product_image'  => (string) wp_get_attachment_url( $product->get_image_id() ),
            '_gallery_images' => implode( ', ', array_filter( array_map(
                'wp_get_attachment_url', $product->get_gallery_image_ids()
            ) ) ),
            '_product_cats'   => sheetsync_format_term_names_for_sheet(
                get_the_terms( $product->get_id(), 'product_cat' ) ?: array()
            ),
            'primary_category' => self::export_primary_category( $product ),
            '_product_tags'   => sheetsync_format_term_names_for_sheet(
                get_the_terms( $product->get_id(), 'product_tag' ) ?: array()
            ),
            'parent_sku'      => class_exists( 'SheetSync_Variation_Sync', false )
                ? SheetSync_Variation_Sync::export_parent_sku( $product )
                : '',
            'variation_attrs' => class_exists( 'SheetSync_Variation_Sync', false )
                ? SheetSync_Variation_Sync::export_variation_attrs( $product )
                : '',
            'grouped_child_skus' => self::export_grouped_child_skus( $product ),
            default           => (string) apply_filters( 'sheetsync_product_field_export_value', '', $product, $field ),
        };
    }
}

endif; // class_exists SheetSync_Product_Updater
