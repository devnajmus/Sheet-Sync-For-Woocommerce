<?php
/**
 * Resolves and caches field mappings for a connection.
 * @package SheetSync_For_WooCommerce
 * @since   1.0.0
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Field_Mapper' ) ) :

class SheetSync_Field_Mapper {

    /** Free fields available without Pro */
    public const FREE_FIELDS = array(
        '_sku'           => 'SKU (Product Key)',
        'product_id'     => 'Product ID (WooCommerce)',
        'post_title'     => 'Product Title',
        '_regular_price' => 'Regular Price',
        '_stock'         => 'Stock Quantity',
        'post_status'    => 'Product Status (publish/draft)',
        'menu_order'     => 'Product Order',
    );

    /** Preset profile keys (shared by import, export, headers, and admin UI). */
    public const PROFILE_FULL    = 'full';
    public const PROFILE_MINIMAL = 'minimal';

    /**
     * Fields auto-added on export when missing from saved maps (fixed columns from full schema).
     *
     * @var list<string>
     */
    public const EXPORT_SUPPLEMENT_FIELDS = array(
        'product_id',
        'primary_category',
        'grouped_child_skus',
        'sheet_row_role',
        'sheet_product_group',
        'sheet_option_summary',
        'sheet_belongs_to',
        '_stock_status',
        'post_status',
    );

    /**
     * Sheet helper / identity columns written on export but never applied to WooCommerce on import.
     *
     * @var list<string>
     */
    public const IMPORT_SKIP_FIELDS = array(
        'product_id',
        'sheet_row_role',
        'primary_category',
        'sheet_product_group',
        'sheet_option_summary',
        'sheet_belongs_to',
    );

    /**
     * Variable parent rows export price ranges for display; real prices live on variation rows.
     *
     * @var list<string>
     */
    public const VARIABLE_PARENT_IMPORT_SKIP_FIELDS = array(
        '_regular_price',
        '_sale_price',
    );

    /**
     * When empty_cell_policy is "clear", blank sheet cells remove these WooCommerce fields.
     *
     * @var list<string>
     */
    public const EMPTY_CELL_CLEAR_FIELDS = array(
        '_regular_price',
        '_sale_price',
        '_stock',
        'post_excerpt',
        'post_content',
    );

    /**
     * Canonical product column schema — single source for presets, headers, import, and export.
     *
     * @return list<array{field: string, column: string, key: bool, label: string}>
     */
    public static function get_product_schema( string $profile = self::PROFILE_FULL ): array {
        if ( self::PROFILE_MINIMAL === $profile ) {
            return array(
                array( 'field' => '_sku', 'column' => 'A', 'key' => true, 'label' => 'SKU' ),
                array( 'field' => 'product_id', 'column' => 'B', 'key' => false, 'label' => 'Product ID' ),
                array( 'field' => 'post_title', 'column' => 'C', 'key' => false, 'label' => 'Product Title' ),
                array( 'field' => '_regular_price', 'column' => 'D', 'key' => false, 'label' => 'Regular Price' ),
                array( 'field' => '_stock', 'column' => 'E', 'key' => false, 'label' => 'Stock Qty' ),
                array( 'field' => '_product_image', 'column' => 'F', 'key' => false, 'label' => 'Featured Image URL' ),
            );
        }

        return array(
            array( 'field' => '_sku', 'column' => 'A', 'key' => true, 'label' => 'SKU' ),
            array( 'field' => 'product_id', 'column' => 'B', 'key' => false, 'label' => 'Product ID' ),
            array( 'field' => 'post_title', 'column' => 'C', 'key' => false, 'label' => 'Product Title' ),
            array( 'field' => '_regular_price', 'column' => 'D', 'key' => false, 'label' => 'Regular Price' ),
            array( 'field' => '_stock', 'column' => 'E', 'key' => false, 'label' => 'Stock Qty' ),
            array( 'field' => 'post_status', 'column' => 'F', 'key' => false, 'label' => 'Status' ),
            array( 'field' => 'menu_order', 'column' => 'G', 'key' => false, 'label' => 'Sort Order' ),
            array( 'field' => '_sale_price', 'column' => 'H', 'key' => false, 'label' => 'Sale Price' ),
            array( 'field' => 'post_excerpt', 'column' => 'I', 'key' => false, 'label' => 'Short Description' ),
            array( 'field' => '_stock_status', 'column' => 'J', 'key' => false, 'label' => 'Stock Status' ),
            array( 'field' => '_weight', 'column' => 'K', 'key' => false, 'label' => 'Weight (kg)' ),
            array( 'field' => '_length', 'column' => 'L', 'key' => false, 'label' => 'Length (cm)' ),
            array( 'field' => '_width', 'column' => 'M', 'key' => false, 'label' => 'Width (cm)' ),
            array( 'field' => '_height', 'column' => 'N', 'key' => false, 'label' => 'Height (cm)' ),
            array( 'field' => 'post_content', 'column' => 'O', 'key' => false, 'label' => 'Description' ),
            array( 'field' => '_product_image', 'column' => 'P', 'key' => false, 'label' => 'Featured Image URL' ),
            array( 'field' => '_gallery_images', 'column' => 'Q', 'key' => false, 'label' => 'Gallery Image URLs' ),
            array( 'field' => '_product_type', 'column' => 'R', 'key' => false, 'label' => 'Product Type' ),
            array( 'field' => '_product_cats', 'column' => 'S', 'key' => false, 'label' => 'Categories' ),
            array( 'field' => '_product_tags', 'column' => 'T', 'key' => false, 'label' => 'Tags' ),
            array( 'field' => 'parent_sku', 'column' => 'U', 'key' => false, 'label' => 'Parent SKU' ),
            array( 'field' => 'variation_attrs', 'column' => 'V', 'key' => false, 'label' => 'Variation Attributes' ),
            array( 'field' => 'sheet_color', 'column' => 'W', 'key' => false, 'label' => 'Color' ),
            array( 'field' => 'sheet_size', 'column' => 'X', 'key' => false, 'label' => 'Size' ),
            array( 'field' => 'sheet_row_role', 'column' => 'Y', 'key' => false, 'label' => 'Row Type' ),
            array( 'field' => 'primary_category', 'column' => 'Z', 'key' => false, 'label' => 'Primary Category' ),
            array( 'field' => 'grouped_child_skus', 'column' => 'AA', 'key' => false, 'label' => 'Grouped Child SKUs' ),
            array( 'field' => 'sheet_product_group', 'column' => 'AB', 'key' => false, 'label' => 'Product Group (SKU)' ),
            array( 'field' => 'sheet_option_summary', 'column' => 'AC', 'key' => false, 'label' => 'Options / Notes' ),
            array( 'field' => 'sheet_belongs_to', 'column' => 'AD', 'key' => false, 'label' => 'Belongs To (parent)' ),
        );
    }

    /**
     * Convert a preset schema to the admin/DB field_map shape.
     *
     * @return array<string, array{column: string, key: int}>
     */
    public static function preset_to_field_map( string $profile = self::PROFILE_FULL ): array {
        $out = array();
        foreach ( self::get_product_schema( $profile ) as $entry ) {
            $out[ $entry['field'] ] = array(
                'column' => $entry['column'],
                'key'    => ! empty( $entry['key'] ) ? 1 : 0,
            );
        }
        return $out;
    }

    /**
     * Convert a preset schema to DB/sync maps shape.
     *
     * @return array<string, array{sheet_column: string, is_key_field: bool}>
     */
    public static function preset_to_maps( string $profile = self::PROFILE_FULL ): array {
        $out = array();
        foreach ( self::get_product_schema( $profile ) as $entry ) {
            $out[ $entry['field'] ] = array(
                'sheet_column' => $entry['column'],
                'is_key_field' => ! empty( $entry['key'] ),
            );
        }
        return $out;
    }

    /**
     * Full recommended layout (A–AD) for Field Mapping presets and sample CSVs.
     *
     * @return array<string, array{column: string, key: bool}>
     */
    public static function get_recommended_product_columns(): array {
        return self::schema_to_preset_columns( self::PROFILE_FULL );
    }

    /**
     * Minimal layout (A–F) for beginners.
     *
     * @return array<string, array{column: string, key: bool}>
     */
    public static function get_minimal_product_columns(): array {
        return self::schema_to_preset_columns( self::PROFILE_MINIMAL );
    }

    /**
     * @return array<string, array{column: string, key: bool}>
     */
    private static function schema_to_preset_columns( string $profile ): array {
        $out = array();
        foreach ( self::get_product_schema( $profile ) as $entry ) {
            $out[ $entry['field'] ] = array(
                'column' => $entry['column'],
                'key'    => ! empty( $entry['key'] ),
            );
        }
        return $out;
    }

    /**
     * Human-readable header labels (import, export, template writer, validation).
     *
     * @return array<string, string> WC field key => column title
     */
    public static function get_product_sheet_header_labels(): array {
        $labels = array();
        foreach ( self::get_product_schema( self::PROFILE_FULL ) as $entry ) {
            $labels[ $entry['field'] ] = $entry['label'];
        }
        return $labels;
    }

    /**
     * Header label for one field (schema first, then admin field list).
     */
    public static function header_label_for_field( string $field ): string {
        $labels = self::get_product_sheet_header_labels();
        if ( isset( $labels[ $field ] ) ) {
            return $labels[ $field ];
        }
        $all = self::get_available_fields( true );
        return $all[ $field ] ?? ucwords( str_replace( array( '_', '-' ), ' ', $field ) );
    }

    /**
     * Build a header row array aligned to mapped column letters.
     *
     * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
     * @return list<string>
     */
    public static function build_header_row_from_maps( array $maps ): array {
        if ( empty( $maps ) ) {
            return array();
        }
        $max_col = self::col_to_index( self::max_column_letter( $maps ) );
        $header  = array_fill( 0, $max_col + 1, '' );
        foreach ( $maps as $field => $info ) {
            $col = self::sanitize_column_letter( (string) ( $info['sheet_column'] ?? '' ) );
            if ( $col === '' ) {
                continue;
            }
            $header[ self::col_to_index( $col ) ] = self::header_label_for_field( $field );
        }
        return $header;
    }

    /**
     * True when a sheet cell holds an exported min–max price range (not a single WooCommerce price).
     */
    public static function is_display_price_range( string $value ): bool {
        $value = trim( $value );
        if ( $value === '' ) {
            return false;
        }
        return (bool) preg_match( '/\d[\d.,]*\s*[–—\-]\s*[\d.,]+/u', $value );
    }

    /**
     * Fields that must not be written to WooCommerce for this mapped row.
     *
     * @param array<string, string> $data Mapped row values.
     * @return list<string>
     */
    public static function import_excluded_fields_for_row( array $data ): array {
        $skip = self::IMPORT_SKIP_FIELDS;
        if ( class_exists( 'SheetSync_Variation_Sync', false )
            && SheetSync_Variation_Sync::is_variable_parent_row( $data ) ) {
            $skip = array_merge( $skip, self::VARIABLE_PARENT_IMPORT_SKIP_FIELDS );
        }
        return array_values( array_unique( $skip ) );
    }

    /**
     * Remove export-only and row-type-specific fields before apply_updates().
     *
     * @param array<string, string> $data Mapped row values.
     */
    public static function strip_non_importable_fields( array $data, ?bool $is_variable_parent = null ): array {
        if ( $is_variable_parent === null
            && class_exists( 'SheetSync_Variation_Sync', false ) ) {
            $is_variable_parent = SheetSync_Variation_Sync::is_variable_parent_row( $data );
        }
        foreach ( self::IMPORT_SKIP_FIELDS as $field ) {
            unset( $data[ $field ] );
        }
        if ( $is_variable_parent ) {
            foreach ( self::VARIABLE_PARENT_IMPORT_SKIP_FIELDS as $field ) {
                unset( $data[ $field ] );
            }
        }
        return $data;
    }

    /**
     * Whether a physical sheet header cell matches the field this connection maps to that column.
     */
    public static function header_cell_matches_mapped_field( string $header, string $field ): bool {
        $header = trim( $header );
        if ( $header === '' ) {
            return true;
        }
        if ( self::detect_wc_field_from_header( $header ) === $field ) {
            return true;
        }
        return strtolower( $header ) === strtolower( trim( self::header_label_for_field( $field ) ) );
    }

    /**
     * Compare saved field maps against the live Google Sheet header row.
     *
     * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
     * @param array<int, string>                                            $actual_header_row
     * @return array{ok: bool, mismatches: list<array{column: string, field: string, expected: string, actual: string, detected: string}>}
     */
    public static function verify_header_row_matches_maps( array $maps, array $actual_header_row ): array {
        $mismatches = array();
        foreach ( $maps as $field => $info ) {
            $col = self::sanitize_column_letter( (string) ( $info['sheet_column'] ?? '' ) );
            if ( $col === '' ) {
                continue;
            }
            $idx    = self::col_to_index( $col );
            $actual = trim( (string) ( $actual_header_row[ $idx ] ?? '' ) );
            if ( $actual === '' || self::header_cell_matches_mapped_field( $actual, $field ) ) {
                continue;
            }
            $detected = self::detect_wc_field_from_header( $actual );
            $mismatches[] = array(
                'column'   => $col,
                'field'    => $field,
                'expected' => self::header_label_for_field( $field ),
                'actual'   => $actual,
                'detected' => $detected !== '' ? self::header_label_for_field( $detected ) : '',
            );
        }

        return array(
            'ok'         => empty( $mismatches ),
            'mismatches' => $mismatches,
        );
    }

    /**
     * Build a sheet data row from field values using resolved maps.
     *
     * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
     * @param array<string, string>                                        $values
     * @return list<string>
     */
    public static function build_row_from_field_values( array $maps, array $values ): array {
        if ( empty( $maps ) ) {
            return array();
        }
        $max_col = self::col_to_index( self::max_column_letter( $maps ) );
        $row     = array_fill( 0, $max_col + 1, '' );
        foreach ( $values as $field => $value ) {
            $col = self::sanitize_column_letter( (string) ( $maps[ $field ]['sheet_column'] ?? '' ) );
            if ( $col === '' ) {
                continue;
            }
            $row[ self::col_to_index( $col ) ] = (string) $value;
        }
        return $row;
    }

    /**
     * Add export supplement columns at fixed schema positions when not already mapped.
     *
     * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
     * @return array<string, array{sheet_column: string, is_key_field: bool}>
     */
    public static function ensure_export_maps( array $maps ): array {
        $schema_by_field = array();
        foreach ( self::get_product_schema( self::PROFILE_FULL ) as $entry ) {
            $schema_by_field[ $entry['field'] ] = $entry;
        }

        $used_columns = array();
        foreach ( $maps as $info ) {
            $col = self::sanitize_column_letter( (string) ( $info['sheet_column'] ?? '' ) );
            if ( $col !== '' ) {
                $used_columns[ $col ] = true;
            }
        }

        foreach ( self::EXPORT_SUPPLEMENT_FIELDS as $field ) {
            $existing = trim( (string) ( $maps[ $field ]['sheet_column'] ?? '' ) );
            if ( $existing !== '' ) {
                continue;
            }
            if ( ! isset( $schema_by_field[ $field ] ) ) {
                continue;
            }
            $target = $schema_by_field[ $field ]['column'];
            if ( isset( $used_columns[ $target ] ) ) {
                continue;
            }
            $maps[ $field ] = array(
                'sheet_column' => $target,
                'is_key_field' => false,
            );
            $used_columns[ $target ] = true;
        }

        return $maps;
    }

    /**
     * Maps for writing rows to Google Sheets (DB maps + fixed export supplements).
     *
     * @return array<string, array{sheet_column: string, is_key_field: bool}>
     */
    public static function get_maps_for_export( int $connection_id ): array {
        return self::ensure_export_maps( self::get_maps( $connection_id ) );
    }

    /**
     * Human-readable identity-column requirement for validators and admin notices.
     *
     * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
     */
    public static function identity_requirement_message( array $maps ): string {
        $sku_col = trim( (string) ( $maps['_sku']['sheet_column'] ?? '' ) );
        $pid_col = trim( (string) ( $maps['product_id']['sheet_column'] ?? '' ) );

        if ( $sku_col !== '' && $pid_col !== '' ) {
            return sprintf(
                /* translators: 1: SKU column letter, 2: Product ID column letter */
                __( 'Map SKU (column %1$s) or Product ID (column %2$s) on the Field Mapping tab and save before checking the sheet.', 'sheetsync-for-woocommerce' ),
                $sku_col,
                $pid_col
            );
        }
        if ( $sku_col !== '' ) {
            return sprintf(
                /* translators: %s: SKU column letter */
                __( 'Map SKU (column %s) on the Field Mapping tab and save before checking the sheet.', 'sheetsync-for-woocommerce' ),
                $sku_col
            );
        }
        if ( $pid_col !== '' ) {
            return sprintf(
                /* translators: %s: Product ID column letter */
                __( 'Map Product ID (column %s) on the Field Mapping tab and save before checking the sheet.', 'sheetsync-for-woocommerce' ),
                $pid_col
            );
        }

        return __( 'Map SKU or Product ID on the Field Mapping tab and save before checking the sheet.', 'sheetsync-for-woocommerce' );
    }

    /** Order fields for order sync sheet */
    public const ORDER_FIELDS = array(
        'order_id'           => 'Order ID',
        'order_date'         => 'Order Date',
        'order_status'       => 'Order Status',
        'customer_name'      => 'Customer Name',
        'billing_email'      => 'Billing Email',
        'billing_phone'      => 'Billing Phone',
        'billing_address'    => 'Billing Address',
        'order_total'        => 'Order Total',
        'payment_method'     => 'Payment Method',
        'items_summary'      => 'Items Summary',
        'shipping_method'    => 'Shipping Method',
        'customer_note'      => 'Customer Note',
    );

    /**
     * Get field maps for a connection, keyed by wc_field.
     *
     * @return array<string, array{sheet_column: string, is_key_field: bool}>
     */
    public static function get_maps( int $connection_id ): array {
        global $wpdb;

        $cache_key = "sheetsync_maps_{$connection_id}";
        $cached    = wp_cache_get( $cache_key, 'sheetsync' ); // FIX: added 'sheetsync' group
        if ( $cached !== false ) return $cached;

        $rows = $wpdb->get_results( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT wc_field, sheet_column, is_key_field
             FROM {$wpdb->prefix}sheetsync_field_maps
             WHERE connection_id = %d",
            $connection_id
        ), ARRAY_A );

        $maps = array();
        foreach ( $rows as $row ) {
            $maps[ $row['wc_field'] ] = array(
                'sheet_column' => strtoupper( $row['sheet_column'] ),
                'is_key_field' => (bool) $row['is_key_field'],
            );
        }

        /**
         * Filter resolved field maps before they are cached (e.g. Pro may hide sheet columns).
         *
         * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
         * @param int                                                             $connection_id
         */
        $maps = apply_filters( 'sheetsync_field_maps', $maps, $connection_id );

        $maps = self::ensure_matching_identity_maps( $maps );

        wp_cache_set( $cache_key, $maps, 'sheetsync', 5 * MINUTE_IN_SECONDS ); // FIX: added 'sheetsync' group
        return $maps;
    }

    /**
     * Ensure Product ID is mapped when the sheet has that column (read-only identity helper).
     *
     * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
     * @return array<string, array{sheet_column: string, is_key_field: bool}>
     */
    public static function ensure_matching_identity_maps( array $maps ): array {
        if ( isset( $maps['product_id']['sheet_column'] ) && $maps['product_id']['sheet_column'] !== '' ) {
            $maps['product_id']['is_key_field'] = false;
            return $maps;
        }
        return $maps;
    }

    /**
     * Build a column-letter → array-index map from a header row.
     *
     * @param array $header_row  Raw row from Sheets e.g. ['SKU','Title','Price']
     * @return array<string, int>
     */
    public static function build_col_index( array $header_row ): array {
        $index = array();
        foreach ( $header_row as $i => $label ) {
            // Map by column letter (A, B, C…)
            $letter = self::index_to_col( $i );
            $index[ $letter ] = $i;
        }
        return $index;
    }

    /**
     * Convert 0-based column index to letter (0→A, 25→Z, 26→AA…).
     */
    public static function index_to_col( int $index ): string {
        $letter = '';
        $index++;
        while ( $index > 0 ) {
            $index--;
            $letter = chr( 65 + ( $index % 26 ) ) . $letter;
            $index  = intdiv( $index, 26 );
        }
        return $letter;
    }

    /**
     * Keep only A–Z letters for a sheet column token (e.g. " a1 " → "A").
     */
    public static function sanitize_column_letter( string $col ): string {
        return strtoupper( preg_replace( '/[^A-Za-z]/', '', $col ) );
    }

    /**
     * Whether the string is a non-empty column letter run (A…Z, AA…).
     */
    public static function is_valid_sheet_column( string $col ): bool {
        $col = self::sanitize_column_letter( $col );
        return $col !== '' && (bool) preg_match( '/^[A-Z]+$/', $col );
    }

    /**
     * Convert column letter to 0-based index (A→0, B→1, AA→26…).
     */
    public static function col_to_index( string $col ): int {
        $col = strtoupper( trim( $col ) );
        if ( $col === '' ) {
            return 0;
        }
        $n = 0;
        foreach ( str_split( $col ) as $c ) {
            if ( $c < 'A' || $c > 'Z' ) {
                return 0;
            }
            $n = $n * 26 + ( ord( $c ) - ord( 'A' ) + 1 );
        }
        return max( 0, $n - 1 );
    }

    /**
     * Fields available for mapping. Free tier uses FREE_FIELDS only.
     * Pro extends the list via the `sheetsync_field_map_all_fields` filter (registered from the Pro plugin).
     */
    public static function get_available_fields( bool $include_pro = false ): array {
        if ( ! $include_pro ) {
            return self::FREE_FIELDS;
        }
        return (array) apply_filters( 'sheetsync_field_map_all_fields', self::FREE_FIELDS );
    }

    /**
     * Invalidate the cache for a connection's maps.
     */
    public static function invalidate_cache( int $connection_id ): void {
        wp_cache_delete( "sheetsync_maps_{$connection_id}", 'sheetsync' ); // FIX: added 'sheetsync' group
    }

    /**
     * Highest mapped column letter for API ranges.
     *
     * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
     */
    public static function max_column_letter( array $maps ): string {
        $max = 0;
        foreach ( $maps as $info ) {
            $max = max( $max, self::col_to_index( $info['sheet_column'] ?? 'A' ) );
        }
        return self::index_to_col( max( 0, $max ) );
    }

    /**
     * Map a sheet header label to an internal WooCommerce field key.
     */
    public static function detect_wc_field_from_header( string $header ): string {
        $h = strtolower( trim( $header ) );
        if ( $h === '' ) {
            return '';
        }

        foreach ( self::get_product_sheet_header_labels() as $field => $label ) {
            if ( strtolower( trim( $label ) ) === $h ) {
                return $field;
            }
        }

        $all = self::get_available_fields( true );
        $all = array_merge(
            $all,
            array(
                'parent_sku'      => 'Parent SKU',
                'variation_attrs' => 'Variation Attributes',
            )
        );
        if ( isset( $all[ $h ] ) ) {
            return $h;
        }

        $aliases = array(
            'sku'              => '_sku',
            'product key'      => '_sku',
            'sku (product key)' => '_sku',
            'product id'       => 'product_id',
            'woocommerce product id' => 'product_id',
            'wc product id'    => 'product_id',
            'product title'    => 'post_title',
            'title'            => 'post_title',
            'regular price'    => '_regular_price',
            'stock quantity'   => '_stock',
            'stock qty'        => '_stock',
            'status'           => 'post_status',
            'sort order'       => 'menu_order',
            'description'      => 'post_content',
            'featured image url' => '_product_image',
            'gallery image urls' => '_gallery_images',
            'parent sku'       => 'parent_sku',
            'product status (publish/draft)' => 'post_status',
            'product status'   => 'post_status',
            'product order'    => 'menu_order',
            'sale price'       => '_sale_price',
            'short description' => 'post_excerpt',
            'stock status'     => '_stock_status',
            'long description' => 'post_content',
            'main image (url)' => '_product_image',
            'gallery images (comma-separated urls)' => '_gallery_images',
            'product type'     => '_product_type',
            'categories'       => '_product_cats',
            'tags'             => '_product_tags',
            'parent sku (variable products)' => 'parent_sku',
            'variation attributes' => 'variation_attrs',
            'color'            => 'sheet_color',
            'size'             => 'sheet_size',
        );
        if ( isset( $aliases[ $h ] ) ) {
            return $aliases[ $h ];
        }

        if ( 'color' === $h || 0 === strpos( $h, 'color ' ) ) {
            return 'sheet_color';
        }
        if ( 'size' === $h || 0 === strpos( $h, 'size ' ) ) {
            return 'sheet_size';
        }

        if ( str_contains( $h, 'row' ) && str_contains( $h, 'type' ) ) {
            return 'sheet_row_role';
        }
        if ( str_contains( $h, 'product' ) && str_contains( $h, 'group' ) ) {
            return 'sheet_product_group';
        }
        if ( str_contains( $h, 'option' ) || ( str_contains( $h, 'note' ) && ! str_contains( $h, 'customer' ) ) ) {
            return 'sheet_option_summary';
        }
        if ( str_contains( $h, 'belong' ) || ( str_contains( $h, 'parent' ) && str_contains( $h, 'to' ) ) ) {
            return 'sheet_belongs_to';
        }
        if ( str_contains( $h, 'primary' ) && str_contains( $h, 'categor' ) ) {
            return 'primary_category';
        }
        if ( str_contains( $h, 'grouped' ) && str_contains( $h, 'child' ) ) {
            return 'grouped_child_skus';
        }
        if ( str_contains( $h, 'parent' ) && str_contains( $h, 'sku' ) ) {
            return 'parent_sku';
        }
        if ( str_contains( $h, 'variation' ) && str_contains( $h, 'attr' ) ) {
            return 'variation_attrs';
        }
        if ( str_contains( $h, 'product' ) && str_contains( $h, 'id' ) && ! str_contains( $h, 'order' ) ) {
            return 'product_id';
        }
        if ( str_contains( $h, 'sku' ) && ! str_contains( $h, 'parent' ) ) {
            return '_sku';
        }
        if ( str_contains( $h, 'product type' ) ) {
            return '_product_type';
        }
        if ( str_contains( $h, 'gallery' ) ) {
            return '_gallery_images';
        }
        if ( str_contains( $h, 'main image' ) || ( str_contains( $h, 'image' ) && ! str_contains( $h, 'gallery' ) ) ) {
            return '_product_image';
        }
        if ( str_contains( $h, 'stock' ) && str_contains( $h, 'status' ) ) {
            return '_stock_status';
        }
        if ( str_contains( $h, 'product status' ) || ( str_contains( $h, 'status' ) && ! str_contains( $h, 'stock' ) ) ) {
            return 'post_status';
        }
        if ( str_contains( $h, 'regular' ) && str_contains( $h, 'price' ) ) {
            return '_regular_price';
        }
        if ( str_contains( $h, 'sale' ) && str_contains( $h, 'price' ) ) {
            return '_sale_price';
        }
        if ( str_contains( $h, 'quantity' ) || ( str_contains( $h, 'stock' ) && ! str_contains( $h, 'status' ) ) ) {
            return '_stock';
        }
        if ( str_contains( $h, 'title' ) || $h === 'name' ) {
            return 'post_title';
        }

        return '';
    }

    /**
     * Fill missing maps (especially variable-product columns) from the sheet header row.
     *
     * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
     * @param array<int, string>                                            $header_row
     * @return array<string, array{sheet_column: string, is_key_field: bool}>
     */
    public static function merge_maps_from_header_row( array $maps, array $header_row ): array {
        $assigned = array();
        foreach ( $maps as $info ) {
            $col = strtoupper( $info['sheet_column'] ?? '' );
            if ( $col !== '' ) {
                $assigned[ $col ] = true;
            }
        }

        foreach ( $header_row as $idx => $label ) {
            $wc_field = self::detect_wc_field_from_header( (string) $label );
            if ( $wc_field === '' ) {
                continue;
            }
            $letter = self::index_to_col( (int) $idx );
            if ( isset( $maps[ $wc_field ]['sheet_column'] ) && $maps[ $wc_field ]['sheet_column'] !== '' ) {
                continue;
            }
            if ( isset( $assigned[ $letter ] ) ) {
                continue;
            }
            $maps[ $wc_field ] = array(
                'sheet_column' => $letter,
                'is_key_field' => ( '_sku' === $wc_field ),
            );
            $assigned[ $letter ] = true;
        }

        return $maps;
    }

    /**
     * Load maps and auto-detect missing columns from the connection's header row.
     *
     * @return array<string, array{sheet_column: string, is_key_field: bool}>
     */
    public static function get_maps_for_sync( int $connection_id, ?object $conn = null ): array {
        $maps = self::get_maps( $connection_id );
        if ( $connection_id <= 0 ) {
            return $maps;
        }

        if ( ! $conn && class_exists( 'SheetSync_Sync_Engine', false ) ) {
            $conn = SheetSync_Sync_Engine::get_connection( $connection_id );
        }
        if ( ! $conn || empty( $conn->spreadsheet_id ) || empty( $conn->sheet_name ) ) {
            return $maps;
        }

        $needs_variable   = ! isset( $maps['parent_sku'] ) || ! isset( $maps['variation_attrs'] ) || ! isset( $maps['_product_type'] )
            || ! isset( $maps['sheet_color'] ) || ! isset( $maps['sheet_size'] );
        $needs_gallery    = ! isset( $maps['_gallery_images'] );
        $needs_product_id = ! isset( $maps['product_id'] );
        if ( ! $needs_variable && ! $needs_gallery && ! $needs_product_id ) {
            return $maps;
        }

        try {
            $client = new SheetSync_Sheets_Client();
            $row    = (int) ( $conn->header_row ?? 1 );
            $range  = "{$conn->sheet_name}!A{$row}:AG{$row}";
            $rows   = $client->get_rows( $conn->spreadsheet_id, $range );
            $header = $rows[0] ?? array();
            if ( ! empty( $header ) ) {
                $maps = self::merge_maps_from_header_row( $maps, $header );
                return self::ensure_matching_identity_maps( $maps );
            }
        } catch ( Exception $e ) {
            SheetSync_Logger::error( 'SheetSync: could not read header row for map detection — ' . $e->getMessage() );
        }

        return $maps;
    }
}

endif; // class_exists SheetSync_Field_Mapper
