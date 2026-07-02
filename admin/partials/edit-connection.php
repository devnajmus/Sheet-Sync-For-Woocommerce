<?php
defined( 'ABSPATH' ) || exit;

$sheetsync_conn_id     = $connection ? (int) $connection->id : 0;
$sheetsync_is_new      = ! $sheetsync_conn_id;
$sheetsync_free_fields = SheetSync_Field_Mapper::FREE_FIELDS;
// Build pro-only fields from the Field Mapper API. The Free plugin no
// longer ships a `PRO_FIELDS` constant; the Pro add-on should derive
// premium fields at runtime. Merge via the API and subtract free fields
// so templates iterating pro fields get only the premium set.
$all_fields = SheetSync_Field_Mapper::get_available_fields( function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro() );
$sheetsync_pro_fields = array_diff_key( $all_fields, $sheetsync_free_fields );
$sheetsync_is_pro = function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro();

// Date filter settings — always initialized (safe for new connections)
$sheetsync_date_type   = $sheetsync_conn_id ? get_option( 'sheetsync_date_filter_type_'   . $sheetsync_conn_id, 'all' ) : 'all';
$sheetsync_date_single = $sheetsync_conn_id ? get_option( 'sheetsync_date_filter_single_' . $sheetsync_conn_id, ''    ) : '';
$sheetsync_date_from   = $sheetsync_conn_id ? get_option( 'sheetsync_date_filter_from_'   . $sheetsync_conn_id, ''    ) : '';
$sheetsync_date_to     = $sheetsync_conn_id ? get_option( 'sheetsync_date_filter_to_'     . $sheetsync_conn_id, ''    ) : '';
$sheetsync_category_filter = $sheetsync_conn_id ? (string) get_option( 'sheetsync_sync_category_ids_' . $sheetsync_conn_id, '' ) : '';
$sheetsync_hidden_sheet_columns = $sheetsync_conn_id ? (string) get_option( 'sheetsync_hidden_sheet_columns_' . $sheetsync_conn_id, '' ) : '';
$sheetsync_category_block_unknown = $sheetsync_conn_id && get_option( 'sheetsync_category_block_unknown_' . $sheetsync_conn_id, '' ) === '1';
$sheetsync_conn_type              = is_object( $connection ) ? ( $connection->connection_type ?? 'products' ) : 'products';
$sheetsync_is_orders_conn         = SheetSync_Sync_Engine::is_orders_type( $sheetsync_conn_type );
$sheetsync_product_sheet_mode     = $sheetsync_conn_id ? (string) get_option( 'sheetsync_product_sheet_mode_' . $sheetsync_conn_id, 'full' ) : 'full';
$sheetsync_variable_field_keys    = array( 'parent_sku', 'variation_attrs', 'sheet_color', 'sheet_size', 'sheet_row_role', 'sheet_product_group', 'sheet_option_summary', 'sheet_belongs_to' );
$sheetsync_pricing_field_keys     = array( '_sale_price', '_stock_status', '_weight', '_length', '_width', '_height' );
$sheetsync_media_taxonomy_keys    = array( '_product_image', '_gallery_images', '_product_cats', '_product_tags', 'primary_category' );
$sheetsync_settings_url           = admin_url( 'admin.php?page=sheetsync-settings' );
$sheetsync_account_email          = class_exists( 'SheetSync_Google_Auth', false ) ? SheetSync_Google_Auth::get_account_email() : '';
$sheetsync_sheet_ready            = ( $sheetsync_conn_id > 0 && is_object( $connection ) && ! empty( $connection->spreadsheet_id ) && function_exists( 'sheetsync_verify_sheet_tab' ) )
	? sheetsync_verify_sheet_tab( $sheetsync_conn_id, $connection, false )
	: array( 'ok' => false, 'message' => '', 'tabs' => array(), 'fix_url' => '' );
?>
<div class="sheetsync-wrap">

    <?php require __DIR__ . '/header.php'; ?>

    <p>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync' ) ); ?>">
            ← <?php esc_html_e( 'All Connections', 'sheetsync-for-woocommerce' ); ?>
        </a>
    </p>

    <!-- Tabs -->
    <div class="ss-tabs">
        <a href="#tab-connection" class="ss-tab active"><?php esc_html_e( 'Connection', 'sheetsync-for-woocommerce' ); ?></a>
        <?php if ( ! $sheetsync_is_new ) : ?>
            <a href="#tab-field-mapping" class="ss-tab"><?php esc_html_e( 'Field Mapping', 'sheetsync-for-woocommerce' ); ?></a>
            <a href="#tab-sync" class="ss-tab"><?php esc_html_e( 'Sync', 'sheetsync-for-woocommerce' ); ?></a>
        <?php endif; ?>
    </div>

    <!-- Tab: Connection -->
    <div id="tab-connection" class="ss-tab-panel">
        <div class="sheetsync-card">
            <h2><?php echo $sheetsync_is_new ? esc_html__( 'New Connection', 'sheetsync-for-woocommerce' ) : esc_html__( 'Edit Connection', 'sheetsync-for-woocommerce' ); ?></h2>

            <?php if ( $sheetsync_account_email ) : ?>
            <div class="notice notice-info inline ss-sa-reminder" style="margin:0 0 16px;">
                <p>
                    <?php esc_html_e( 'Remember to share your Google Sheet with your service account (Editor):', 'sheetsync-for-woocommerce' ); ?>
                    <code><?php echo esc_html( $sheetsync_account_email ); ?></code>
                    <button type="button" class="button button-small ss-copy-email-btn"
                            data-email="<?php echo esc_attr( $sheetsync_account_email ); ?>">
                        <?php esc_html_e( 'Copy', 'sheetsync-for-woocommerce' ); ?>
                    </button>
                </p>
            </div>
            <?php elseif ( ! $sheetsync_account_email ) : ?>
            <div class="notice notice-warning inline" style="margin:0 0 16px;">
                <p>
                    <?php esc_html_e( 'Google is not connected yet.', 'sheetsync-for-woocommerce' ); ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync-settings' ) ); ?>">
                        <?php esc_html_e( 'Connect in Settings →', 'sheetsync-for-woocommerce' ); ?>
                    </a>
                </p>
            </div>
            <?php endif; ?>

            <?php if ( ! $sheetsync_is_new && ! empty( $connection->spreadsheet_id ) ) : ?>
                <?php require __DIR__ . '/fragments/sheet-readiness-card.php'; ?>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'sheetsync_save_connection' ); ?>
                <input type="hidden" name="action" value="sheetsync_save_connection">
                <input type="hidden" name="connection_id" value="<?php echo esc_attr( $sheetsync_conn_id ); ?>">

                <table class="form-table sheetsync-settings-form">
                    <tr>
                        <th><?php esc_html_e( 'Connection Name', 'sheetsync-for-woocommerce' ); ?></th>
                        <td>
                            <input type="text" name="connection_name" class="regular-text"
                                   value="<?php echo esc_attr( $connection->name ?? '' ); ?>"
                                   placeholder="e.g. Products Inventory Sheet">
                            <p class="description"><?php esc_html_e( 'A friendly name to identify this connection.', 'sheetsync-for-woocommerce' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Google Sheet URL', 'sheetsync-for-woocommerce' ); ?> <?php echo function_exists( 'sheetsync_help_tip' ) ? wp_kses_post( sheetsync_help_tip( __( 'Copy the full URL from your browser address bar while the sheet is open.', 'sheetsync-for-woocommerce' ) ) ) : ''; ?></th>
                        <td>
                            <input type="url" id="spreadsheet_url" name="spreadsheet_url" class="large-text"
                                   placeholder="https://docs.google.com/spreadsheets/d/…/edit"
                                   value="">
                            <p class="description"><?php esc_html_e( 'Paste the full URL from your browser — the Spreadsheet ID below will be filled automatically.', 'sheetsync-for-woocommerce' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Spreadsheet ID', 'sheetsync-for-woocommerce' ); ?> <?php echo function_exists( 'sheetsync_help_tip' ) ? wp_kses_post( sheetsync_help_tip( __( 'Auto-filled from the URL. You can also paste the ID directly.', 'sheetsync-for-woocommerce' ) ) ) : ''; ?></th>
                        <td>
                            <div class="ss-sheet-id-group">
                                <input type="text" id="spreadsheet_id" name="spreadsheet_id"
                                       class="regular-text"
                                       value="<?php echo esc_attr( $connection->spreadsheet_id ?? '' ); ?>"
                                       placeholder="1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgVE2upms">
                                <button type="button" id="ss-test-connection" class="button ss-connection-test">
                                    <?php esc_html_e( 'Test Connection', 'sheetsync-for-woocommerce' ); ?>
                                </button>
                            </div>
                            <p class="description">
                                <?php esc_html_e( 'Found in the Google Sheets URL: docs.google.com/spreadsheets/d/', 'sheetsync-for-woocommerce' ); ?>
                                <strong>[SPREADSHEET_ID]</strong>/edit
                            </p>
                            <div id="ss-test-result" style="display:none;" class="ss-test-result"></div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Sheet Tab Name', 'sheetsync-for-woocommerce' ); ?> <?php echo function_exists( 'sheetsync_help_tip' ) ? wp_kses_post( sheetsync_help_tip( __( 'Must match the tab name at the bottom of Google Sheets exactly (case-sensitive).', 'sheetsync-for-woocommerce' ) ) ) : ''; ?></th>
                        <td>
                            <div class="ss-sheet-select-row" style="display:none;">
                                <select id="sheet_name_select" name="sheet_name_select"
                                        data-current="<?php echo esc_attr( $connection->sheet_name ?? 'Sheet1' ); ?>"
                                        onchange="document.getElementById('sheet_name').value = this.value;">
                                </select>
                                <p class="description"><?php esc_html_e( 'Or type the name manually:', 'sheetsync-for-woocommerce' ); ?></p>
                            </div>
                            <input type="text" id="sheet_name" name="sheet_name" class="regular-text"
                                   value="<?php echo esc_attr( $connection->sheet_name ?? 'Sheet1' ); ?>"
                                   placeholder="Sheet1">
                            <p class="description">
                                <?php esc_html_e( 'The tab name at the bottom of your Google Sheet. Must match exactly (spaces and spelling).', 'sheetsync-for-woocommerce' ); ?>
                                <?php if ( $sheetsync_is_orders_conn ) : ?>
                                    <?php esc_html_e( 'For order connections, the tab is created automatically when you save if it does not exist yet.', 'sheetsync-for-woocommerce' ); ?>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Header Row', 'sheetsync-for-woocommerce' ); ?></th>
                        <td>
                            <input type="number" name="header_row" class="small-text" min="1" max="10"
                                   value="<?php echo esc_attr( $connection->header_row ?? 1 ); ?>">
                            <p class="description">
                                <?php esc_html_e( 'Row number for column titles (SKU, Title, Price…). Product data is written starting on the next row. If row 1 already has product data, set Header Row to 1 and run “Write styled headers” on the Sync tab, then export again.', 'sheetsync-for-woocommerce' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Connection Type', 'sheetsync-for-woocommerce' ); ?></th>
                        <td>
                            <?php
                            $sheetsync_order_statuses = array(
                                'orders'            => __( 'Orders (All)', 'sheetsync-for-woocommerce' ),
                                'orders_pending'    => __( 'Pending Payment', 'sheetsync-for-woocommerce' ),
                                'orders_processing' => __( 'Processing', 'sheetsync-for-woocommerce' ),
                                'orders_on-hold'    => __( 'On Hold', 'sheetsync-for-woocommerce' ),
                                'orders_completed'  => __( 'Completed', 'sheetsync-for-woocommerce' ),
                                'orders_cancelled'  => __( 'Cancelled', 'sheetsync-for-woocommerce' ),
                                'orders_refunded'   => __( 'Refunded', 'sheetsync-for-woocommerce' ),
                                'orders_failed'     => __( 'Failed', 'sheetsync-for-woocommerce' ),
                                'orders_draft'      => __( 'Draft', 'sheetsync-for-woocommerce' ),
                            );
                            $sheetsync_current_type = $connection->connection_type ?? 'products';
                            ?>
                            <select name="connection_type" id="sheetsync_connection_type">
                                <option value="products" <?php selected( $sheetsync_current_type, 'products' ); ?>>
                                    <?php esc_html_e( 'Products', 'sheetsync-for-woocommerce' ); ?>
                                </option>
                                <optgroup label="--- Orders ---">
                                <?php foreach ( $sheetsync_order_statuses as $sheetsync_type_val => $sheetsync_type_label ) :
                                    // Orders (All) requires Pro. Status-filtered options also require Pro.
                                    // CRITICAL FIX: Never disable the currently-selected option.
                                    // Browsers (Chrome/Firefox) ignore a disabled+selected option and
                                    // fall back to the first enabled option. This causes jQuery .val()
                                    // to return 'products' even when 'orders' is visually selected,
                                    // which makes the JS hide the Date Filter row incorrectly.
                                    $sheetsync_is_currently_selected = ( $sheetsync_current_type === $sheetsync_type_val );
                                    $sheetsync_option_disabled = ! $sheetsync_is_pro && ! $sheetsync_is_currently_selected;
                                ?>
                                    <option value="<?php echo esc_attr( $sheetsync_type_val ); ?>"
                                        <?php selected( $sheetsync_current_type, $sheetsync_type_val ); ?>
                                        <?php disabled( $sheetsync_option_disabled ); ?>>
                                        <?php echo esc_html( $sheetsync_type_label ); ?>
                                        <?php if ( $sheetsync_option_disabled ) echo ' (Pro)'; ?>
                                    </option>
                                <?php endforeach; ?>
                                </optgroup>
                            </select>

                            
                        </td>
                    </tr>

                    <!-- ── Date Filter Row (shows only for Order connection types) ── -->
                    <tr id="sheetsync-date-filter-row" style="<?php echo SheetSync_Sync_Engine::is_orders_type( $sheetsync_current_type ) ? '' : 'display:none;'; ?>">
                        <th><?php esc_html_e( 'Date Filter', 'sheetsync-for-woocommerce' ); ?></th>
                        <td>
                            <select id="sheetsync_date_type" name="order_date_type" style="margin-bottom:10px; min-width:200px;">
                                <option value="all"    <?php selected( $sheetsync_date_type, 'all'    ); ?>><?php esc_html_e( 'All Dates (no filter)',  'sheetsync-for-woocommerce' ); ?></option>
                                <option value="single" <?php selected( $sheetsync_date_type, 'single' ); ?>><?php esc_html_e( 'Specific Date',          'sheetsync-for-woocommerce' ); ?></option>
                                <option value="range"  <?php selected( $sheetsync_date_type, 'range'  ); ?>><?php esc_html_e( 'Date Range (From → To)', 'sheetsync-for-woocommerce' ); ?></option>
                            </select>

                            <div id="sheetsync-date-single" style="<?php echo $sheetsync_date_type === 'single' ? '' : 'display:none;'; ?> margin-top:4px;">
                                <input type="date" name="order_date_single"
                                       value="<?php echo esc_attr( $sheetsync_date_single ); ?>"
                                       style="padding:5px 8px; border:1px solid #8c8f94; border-radius:4px;">
                                <p class="description" style="margin-top:4px;"><?php esc_html_e( 'Only orders placed on this date will be synced.', 'sheetsync-for-woocommerce' ); ?></p>
                            </div>

                            <div id="sheetsync-date-range" style="<?php echo $sheetsync_date_type === 'range' ? '' : 'display:none;'; ?> margin-top:4px;">
                                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                    <div>
                                        <label style="display:block; font-size:12px; color:#6b7280; margin-bottom:3px;"><?php esc_html_e( 'From', 'sheetsync-for-woocommerce' ); ?></label>
                                        <input type="date" name="order_date_from" value="<?php echo esc_attr( $sheetsync_date_from ); ?>"
                                               style="padding:5px 8px; border:1px solid #8c8f94; border-radius:4px;">
                                    </div>
                                    <span style="color:#9ca3af; font-size:18px; padding-top:18px;">→</span>
                                    <div>
                                        <label style="display:block; font-size:12px; color:#6b7280; margin-bottom:3px;"><?php esc_html_e( 'To', 'sheetsync-for-woocommerce' ); ?></label>
                                        <input type="date" name="order_date_to" value="<?php echo esc_attr( $sheetsync_date_to ); ?>"
                                               style="padding:5px 8px; border:1px solid #8c8f94; border-radius:4px;">
                                    </div>
                                </div>
                                <p class="description" style="margin-top:6px;"><?php esc_html_e( 'Only orders placed between these dates (inclusive) will be synced.', 'sheetsync-for-woocommerce' ); ?></p>
                            </div>

                            <?php if ( $sheetsync_date_type !== 'all' ) : ?>
                                <p style="margin-top:8px; color:#059669; font-size:12px;">
                                    <span class="dashicons dashicons-calendar-alt" style="font-size:14px;"></span>
                                    <?php
                                    if ( $sheetsync_date_type === 'single' && $sheetsync_date_single ) {
                                        printf( esc_html__( 'Active: %s', 'sheetsync-for-woocommerce' ), esc_html( $sheetsync_date_single ) );
                                    } elseif ( $sheetsync_date_type === 'range' ) {
                                        printf( esc_html__( 'Active: %1$s → %2$s', 'sheetsync-for-woocommerce' ),
                                            esc_html( $sheetsync_date_from ?: __('any', 'sheetsync-for-woocommerce') ),
                                            esc_html( $sheetsync_date_to   ?: __('any', 'sheetsync-for-woocommerce') )
                                        );
                                    }
                                    ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th><?php esc_html_e( 'Sync Direction', 'sheetsync-for-woocommerce' ); ?></th>
                        <td>
                            <?php
                            $sheetsync_saved_direction = $connection->sync_direction ?? 'sheets_to_wc';
                            ?>
                            <select name="sync_direction">
                                <option value="sheets_to_wc" <?php selected( $sheetsync_saved_direction, 'sheets_to_wc' ); ?>>
                                    <?php esc_html_e( 'Google Sheets → WooCommerce', 'sheetsync-for-woocommerce' ); ?>
                                </option>
                                <?php
                                // Never disable the option that matches the saved direction: disabled+selected
                                // options are omitted from POST in Chrome/Firefox, which reverts WC→Sheet to Sheet→WC.
                                $sheetsync_dir_wc = ( 'wc_to_sheets' === $sheetsync_saved_direction );
                                $sheetsync_dir_tw = ( 'two_way' === $sheetsync_saved_direction );
                                ?>
                                <option value="wc_to_sheets" <?php selected( $sheetsync_saved_direction, 'wc_to_sheets' ); ?>
                                    <?php disabled( ! $sheetsync_is_pro && ! $sheetsync_dir_wc ); ?>>
                                    <?php esc_html_e( 'WooCommerce → Google Sheets', 'sheetsync-for-woocommerce' ); ?>
                                    <?php if ( ! $sheetsync_is_pro && ! $sheetsync_dir_wc ) : ?> (Pro)<?php endif; ?>
                                </option>
                                <option value="two_way" <?php selected( $sheetsync_saved_direction, 'two_way' ); ?>
                                    <?php disabled( ! $sheetsync_is_pro && ! $sheetsync_dir_tw ); ?>>
                                    <?php esc_html_e( 'Two-Way Sync', 'sheetsync-for-woocommerce' ); ?>
                                    <?php if ( ! $sheetsync_is_pro && ! $sheetsync_dir_tw ) : ?> (Pro)<?php endif; ?>
                                </option>
                            </select>
                            <?php if ( ! $sheetsync_is_pro ) : ?>
                                <p class="description"><?php SheetSync_Admin::render_pro_gate( __( 'WooCommerce → Sheets and Both ways', 'sheetsync-for-woocommerce' ) ); ?></p>
                            <?php endif; ?>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: Sync tab link */
                                    esc_html__( 'You can also change direction on the %s tab (saves immediately).', 'sheetsync-for-woocommerce' ),
                                    '<a href="#tab-sync" class="ss-tab-link">' . esc_html__( 'Sync', 'sheetsync-for-woocommerce' ) . '</a>'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <?php if ( $sheetsync_is_pro && ! SheetSync_Sync_Engine::is_orders_type( $connection->connection_type ?? 'products' ) ) : ?>
                    <tr>
                        <th><?php esc_html_e( 'Sheet mode', 'sheetsync-for-woocommerce' ); ?></th>
                        <td>
                            <select name="product_sheet_mode">
                                <option value="full" <?php selected( $sheetsync_product_sheet_mode, 'full' ); ?>>
                                    <?php esc_html_e( 'Full — simple + variable products (Color/Size columns)', 'sheetsync-for-woocommerce' ); ?>
                                </option>
                                <option value="simple" <?php selected( $sheetsync_product_sheet_mode, 'simple' ); ?>>
                                    <?php esc_html_e( 'Simple only — no variations (6 core columns)', 'sheetsync-for-woocommerce' ); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'Use Full when your sheet has variable parents and variation rows (Parent SKU + Color/Size). Simple mode is for flat catalogs only.', 'sheetsync-for-woocommerce' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Category filter (optional)', 'sheetsync-for-woocommerce' ); ?></th>
                        <td>
                            <input type="text" name="sheetsync_sync_category_ids" class="large-text"
                                   value="<?php echo esc_attr( $sheetsync_category_filter ); ?>"
                                   placeholder="<?php esc_attr_e( 'e.g. 12, 34, 56 — product_cat term IDs', 'sheetsync-for-woocommerce' ); ?>">
                            <p class="description">
                                <?php esc_html_e( 'When set, sheet rows are applied only to products that belong to at least one of these categories. Leave empty to sync all products.', 'sheetsync-for-woocommerce' ); ?>
                            </p>
                            <label style="display:block;margin-top:10px;">
                                <input type="checkbox" name="sheetsync_category_block_unknown" value="1" <?php checked( $sheetsync_category_block_unknown ); ?>>
                                <?php esc_html_e( 'When category filter is set, skip rows for unknown SKUs/titles (do not create new products outside the filter).', 'sheetsync-for-woocommerce' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Hidden sheet columns (optional)', 'sheetsync-for-woocommerce' ); ?></th>
                        <td>
                            <input type="text" name="sheetsync_hidden_sheet_columns" class="large-text"
                                   value="<?php echo esc_attr( $sheetsync_hidden_sheet_columns ); ?>"
                                   placeholder="<?php esc_attr_e( 'e.g. B, D, F — column letters excluded from sync', 'sheetsync-for-woocommerce' ); ?>">
                            <p class="description">
                                <?php esc_html_e( 'Mapped fields in these columns are ignored on pull/push for this connection. Do not hide your key (SKU) column.', 'sheetsync-for-woocommerce' ); ?>
                            </p>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th><?php esc_html_e( 'Status', 'sheetsync-for-woocommerce' ); ?></th>
                        <td>
                            <select name="status">
                                <option value="active"   <?php selected( $connection->status ?? 'active', 'active' ); ?>><?php esc_html_e( 'Active', 'sheetsync-for-woocommerce' ); ?></option>
                                <option value="inactive" <?php selected( $connection->status ?? '', 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'sheetsync-for-woocommerce' ); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="submit" class="button button-primary">
                        <?php echo $sheetsync_is_new ? esc_html__( 'Create Connection', 'sheetsync-for-woocommerce' ) : esc_html__( 'Save Connection', 'sheetsync-for-woocommerce' ); ?>
                    </button>
                </p>
            </form>
        </div>
    </div>

    <?php if ( ! $sheetsync_is_new ) : ?>

    <!-- Tab: Field Mapping -->
    <div id="tab-field-mapping" class="ss-tab-panel" style="display:none;">
        <?php
        $sheetsync_map_profile = $sheetsync_is_orders_conn
            ? 'custom'
            : (string) get_option( 'sheetsync_map_profile_' . $sheetsync_conn_id, 'full' );
        ?>
        <div class="sheetsync-card">
            <h2><?php esc_html_e( 'Field Mapping', 'sheetsync-for-woocommerce' ); ?></h2>
            <p><?php esc_html_e( 'Choose a preset for quick setup, or use Custom for full control.', 'sheetsync-for-woocommerce' ); ?></p>

            <?php if ( ! $sheetsync_is_orders_conn ) : ?>
            <div class="ss-map-profile-grid" role="group" aria-label="<?php esc_attr_e( 'Mapping profile', 'sheetsync-for-woocommerce' ); ?>">
                <label class="ss-map-profile-card <?php echo 'simple' === $sheetsync_map_profile ? 'is-selected' : ''; ?>">
                    <input type="radio" name="ss_map_profile_ui" value="simple" <?php checked( $sheetsync_map_profile, 'simple' ); ?>>
                    <strong><?php esc_html_e( 'Simple', 'sheetsync-for-woocommerce' ); ?></strong>
                    <span><?php esc_html_e( '6 columns — SKU, Product ID, Title, Price, Stock, Image', 'sheetsync-for-woocommerce' ); ?></span>
                </label>
                <label class="ss-map-profile-card <?php echo 'full' === $sheetsync_map_profile ? 'is-selected' : ''; ?>">
                    <input type="radio" name="ss_map_profile_ui" value="full" <?php checked( $sheetsync_map_profile, 'full' ); ?>>
                    <strong><?php esc_html_e( 'Full', 'sheetsync-for-woocommerce' ); ?></strong>
                    <span><?php esc_html_e( 'A=SKU, B=Product ID, C–Y catalog fields + Color/Size', 'sheetsync-for-woocommerce' ); ?></span>
                </label>
                <label class="ss-map-profile-card <?php echo 'custom' === $sheetsync_map_profile ? 'is-selected' : ''; ?>">
                    <input type="radio" name="ss_map_profile_ui" value="custom" <?php checked( $sheetsync_map_profile, 'custom' ); ?>>
                    <strong><?php esc_html_e( 'Custom', 'sheetsync-for-woocommerce' ); ?></strong>
                    <span><?php esc_html_e( 'Edit every field manually', 'sheetsync-for-woocommerce' ); ?></span>
                </label>
            </div>
            <p class="ss-advanced-mapping-wrap" style="<?php echo 'custom' === $sheetsync_map_profile ? 'display:none;' : ''; ?>">
                <button type="button" class="button-link ss-toggle-advanced-mapping" id="ss-toggle-advanced-mapping">
                    <?php esc_html_e( 'Advanced mapping →', 'sheetsync-for-woocommerce' ); ?>
                </button>
            </p>
            <?php endif; ?>

            <?php if ( ! $sheetsync_is_orders_conn ) : ?>
            <div class="notice notice-info inline ss-product-matching-guide" style="margin:16px 0; border-left:4px solid var(--ss-green);">
                <p style="margin:0.5em 0;">
                    <span class="dashicons dashicons-info" style="color:var(--ss-green);"></span>
                    <strong><?php esc_html_e( 'How rows match WooCommerce products', 'sheetsync-for-woocommerce' ); ?></strong>
                </p>
                <ol style="margin:8px 0 8px 1.5em; list-style:decimal;">
                    <li><?php esc_html_e( 'Product ID (column B) — most reliable for updates after your first export or import.', 'sheetsync-for-woocommerce' ); ?></li>
                    <li><?php esc_html_e( 'SKU (column A) — best for new products and day-to-day edits.', 'sheetsync-for-woocommerce' ); ?></li>
                    <li><?php esc_html_e( 'Product title — fallback only when SKU and Product ID are empty.', 'sheetsync-for-woocommerce' ); ?></li>
                </ol>
                <p class="description" style="margin-bottom:0;">
                    <?php esc_html_e( 'Map at least SKU or Product ID. Avoid relying on sheet row numbers alone — they can change when rows are inserted or sorted.', 'sheetsync-for-woocommerce' ); ?>
                </p>
            </div>
            <details class="ss-variable-products-guide" style="margin:0 0 16px; border:1px solid var(--ss-gray-200); border-radius:6px; padding:12px 16px; background:#fafafa;">
                <summary style="cursor:pointer; font-weight:600;">
                    <span class="dashicons dashicons-screenoptions" style="color:var(--ss-green); vertical-align:middle;"></span>
                    <?php esc_html_e( 'Variable products (Color / Size options)', 'sheetsync-for-woocommerce' ); ?>
                    <?php if ( ! $sheetsync_is_pro ) : ?>
                        <span class="ss-pro-badge">Pro</span>
                    <?php endif; ?>
                </summary>
                <div style="margin-top:12px; font-size:13px;">
                    <p><?php esc_html_e( 'One variable product in WooCommerce = multiple sheet rows:', 'sheetsync-for-woocommerce' ); ?></p>
                    <table class="widefat striped ss-variable-layout-table" style="max-width:640px; margin:8px 0;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Row type', 'sheetsync-for-woocommerce' ); ?></th>
                                <th><?php esc_html_e( 'Product Type (R)', 'sheetsync-for-woocommerce' ); ?></th>
                                <th><?php esc_html_e( 'Parent SKU (U)', 'sheetsync-for-woocommerce' ); ?></th>
                                <th><?php esc_html_e( 'Color (W) / Size (X)', 'sheetsync-for-woocommerce' ); ?></th>
                                <th><?php esc_html_e( 'Price (D)', 'sheetsync-for-woocommerce' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?php esc_html_e( 'Parent', 'sheetsync-for-woocommerce' ); ?></td>
                                <td><code>variable</code></td>
                                <td><?php esc_html_e( 'empty', 'sheetsync-for-woocommerce' ); ?></td>
                                <td><code>red,black</code> · <code>s,m</code></td>
                                <td><?php esc_html_e( 'empty (normal)', 'sheetsync-for-woocommerce' ); ?></td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e( 'Variation', 'sheetsync-for-woocommerce' ); ?></td>
                                <td><?php esc_html_e( 'empty', 'sheetsync-for-woocommerce' ); ?></td>
                                <td><?php esc_html_e( "parent's SKU", 'sheetsync-for-woocommerce' ); ?></td>
                                <td><code>red</code> · <code>s</code></td>
                                <td><?php esc_html_e( 'required', 'sheetsync-for-woocommerce' ); ?></td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="description">
                        <?php esc_html_e( 'Easy mode: fill Color and Size columns — you do not need to write Variation Attributes (V) unless you use custom attribute slugs.', 'sheetsync-for-woocommerce' ); ?>
                        <?php if ( ! $sheetsync_is_pro ) : ?>
                            <?php esc_html_e( 'Variable products require SheetSync Pro.', 'sheetsync-for-woocommerce' ); ?>
                        <?php endif; ?>
                    </p>
                </div>
            </details>
            <details class="ss-catalog-data-guide" style="margin:0 0 16px; border:1px solid var(--ss-gray-200); border-radius:6px; padding:12px 16px; background:#fafafa;">
                <summary style="cursor:pointer; font-weight:600;">
                    <span class="dashicons dashicons-cart" style="color:var(--ss-green); vertical-align:middle;"></span>
                    <?php esc_html_e( 'Pricing, stock, images, categories & tags', 'sheetsync-for-woocommerce' ); ?>
                </summary>
                <div style="margin-top:12px; font-size:13px;">
                    <table class="widefat striped ss-catalog-layout-table" style="max-width:720px; margin:8px 0;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'What you edit', 'sheetsync-for-woocommerce' ); ?></th>
                                <th><?php esc_html_e( 'Column (Full preset)', 'sheetsync-for-woocommerce' ); ?></th>
                                <th><?php esc_html_e( 'Sheet format', 'sheetsync-for-woocommerce' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?php esc_html_e( 'Regular / Sale price', 'sheetsync-for-woocommerce' ); ?></td>
                                <td>D / H</td>
                                <td><?php esc_html_e( 'Numbers only (e.g. 29.99). Sale price is Pro.', 'sheetsync-for-woocommerce' ); ?></td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e( 'Stock quantity / status', 'sheetsync-for-woocommerce' ); ?></td>
                                <td>E / J</td>
                                <td><?php esc_html_e( 'Qty = whole number. Status = instock, outofstock, or onbackorder (Pro).', 'sheetsync-for-woocommerce' ); ?></td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e( 'Featured / Gallery images', 'sheetsync-for-woocommerce' ); ?></td>
                                <td>P / Q</td>
                                <td><?php esc_html_e( 'URL, Media Library ID, or comma-separated gallery URLs (Pro). Downloads in background after sync.', 'sheetsync-for-woocommerce' ); ?></td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e( 'Categories / Tags', 'sheetsync-for-woocommerce' ); ?></td>
                                <td>S / T</td>
                                <td><?php esc_html_e( 'Comma-separated names — missing terms are created automatically (Pro).', 'sheetsync-for-woocommerce' ); ?></td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="description" style="margin-bottom:8px;">
                        <?php esc_html_e( 'Only mapped columns sync. Empty cells do not erase existing WooCommerce data on update.', 'sheetsync-for-woocommerce' ); ?>
                    </p>
                    <p class="description ss-catalog-image-test" style="margin:0;">
                        <label for="ss-conn-test-image-url"><?php esc_html_e( 'Test an image URL:', 'sheetsync-for-woocommerce' ); ?></label>
                        <input type="url" id="ss-conn-test-image-url" class="regular-text" placeholder="https://example.com/image.jpg" style="max-width:320px; margin-left:6px;">
                        <button type="button" class="button button-small ss-conn-test-image-url-btn"><?php esc_html_e( 'Test', 'sheetsync-for-woocommerce' ); ?></button>
                        <span id="ss-conn-test-image-url-result" class="ss-inline-test-result"></span>
                        <a href="<?php echo esc_url( $sheetsync_settings_url ); ?>" style="margin-left:8px;"><?php esc_html_e( 'More image tools in Settings', 'sheetsync-for-woocommerce' ); ?></a>
                    </p>
                </div>
            </details>
            <?php endif; ?>

            <p class="description ss-field-map-intro"><?php esc_html_e( 'Enter the column letter (A, B, C…) from your Google Sheet for each WooCommerce field. SKU is the key field for linking new rows; Product ID is matched automatically when present.', 'sheetsync-for-woocommerce' ); ?></p>

            <!-- ── Import Header Row ──────────────────────────────────── -->
            <div class="ss-import-header-box">
                <h3 style="margin-top:0;">
                    <span class="dashicons dashicons-download" style="color:var(--ss-green);"></span>
                    <?php esc_html_e( 'Import Headers from Sheet', 'sheetsync-for-woocommerce' ); ?>
                </h3>
                <p class="description">
                    <?php if ( $sheetsync_is_pro ) : ?>
                        <?php esc_html_e( 'Automatically fills ALL column mappings from your Google Sheet. If your sheet is empty, all fields (including Pro fields) will be written to the sheet with beautiful styling. No need to fill manually below!', 'sheetsync-for-woocommerce' ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'Automatically fills ALL column mappings from your Google Sheet. If your sheet is empty, all fields (including Pro fields) will be written to the sheet with beautiful styling. No need to fill manually below!', 'sheetsync-for-woocommerce' ); ?>
                    <?php endif; ?>
                </p>

                <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                    <button type="button" class="button button-primary ss-import-headers-btn"
                            data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'sheetsync_nonce' ) ); ?>">
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e( 'Import Headers', 'sheetsync-for-woocommerce' ); ?>
                    </button>
                    <?php if ( ! $sheetsync_is_orders_conn ) : ?>
                    <button type="button" class="button ss-apply-recommended-map-btn" data-map-preset="full">
                        <span class="dashicons dashicons-admin-settings"></span>
                        <?php esc_html_e( 'Recommended (A–W + Color/Size)', 'sheetsync-for-woocommerce' ); ?>
                    </button>
                    <button type="button" class="button ss-apply-recommended-map-btn" data-map-preset="minimal">
                        <?php esc_html_e( 'Minimal (6 columns)', 'sheetsync-for-woocommerce' ); ?>
                    </button>
                    <?php endif; ?>
                    <span class="ss-import-result" style="margin-left:10px;"></span>
                </div>
                <?php if ( ! $sheetsync_is_orders_conn ) : ?>
                <p class="description" style="margin-top:8px;">
                    <?php esc_html_e( 'Recommended layout: A=SKU (key), B=Product ID, C=Title, D=Price, … R=Type, U=Parent SKU, V=Variation Attributes, W–Y=Color/Size/Row Type, Z–AD=optional helpers. Click a preset above then Save Field Mapping.', 'sheetsync-for-woocommerce' ); ?>
                </p>
                <?php endif; ?>

                <div id="ss-header-preview" style="display:none; margin-top:12px;">
                    <p><strong>✅ Found in Sheet:</strong></p>
                    <div id="ss-header-list" class="ss-header-chips"></div>
                    <p class="description" style="margin-top:8px; color:#059669;">
                        The columns below have been automatically matched. Adjust manually if needed.
                    </p>
                </div>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'sheetsync_save_field_map' ); ?>
                <input type="hidden" name="action" value="sheetsync_save_field_map">
                <input type="hidden" name="connection_id" value="<?php echo esc_attr( $sheetsync_conn_id ); ?>">
                <?php if ( ! $sheetsync_is_orders_conn ) : ?>
                <input type="hidden" name="sheetsync_map_profile" id="sheetsync_map_profile" value="<?php echo esc_attr( $sheetsync_map_profile ); ?>">
                <?php endif; ?>

                <table class="sheetsync-mapping-table ss-mapping-table-advanced" id="ss-mapping-table-advanced"
                       style="<?php echo ( ! $sheetsync_is_orders_conn && 'custom' !== ( $sheetsync_map_profile ?? 'full' ) ) ? 'display:none;' : ''; ?>">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'WooCommerce Field', 'sheetsync-for-woocommerce' ); ?></th>
                            <th style="width:100px;"><?php esc_html_e( 'Sheet Column', 'sheetsync-for-woocommerce' ); ?></th>
                            <th style="width:80px;"><?php esc_html_e( 'Key Field', 'sheetsync-for-woocommerce' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Free fields -->
                        <?php foreach ( $sheetsync_free_fields as $sheetsync_key => $sheetsync_label ) :
                            $sheetsync_is_auto_identity = ( 'product_id' === $sheetsync_key );
                            ?>
                            <tr class="<?php echo $sheetsync_is_auto_identity ? 'ss-identity-row' : ''; ?>">
                                <td>
                                    <span class="ss-field-label"><?php echo esc_html( $sheetsync_label ); ?></span>
                                    <span class="ss-field-key"><?php echo esc_html( $sheetsync_key ); ?></span>
                                    <?php if ( $sheetsync_is_auto_identity ) : ?>
                                        <span class="ss-identity-badge"><?php esc_html_e( 'Auto-match', 'sheetsync-for-woocommerce' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <input type="text" name="field_map[<?php echo esc_attr( $sheetsync_key ); ?>][column]"
                                           class="column-input"
                                           value="<?php echo esc_attr( $field_maps[ $sheetsync_key ]['sheet_column'] ?? '' ); ?>"
                                           maxlength="3" placeholder="e.g. A or B">
                                </td>
                                <td style="text-align:center;">
                                    <?php if ( $sheetsync_is_auto_identity ) : ?>
                                        <span class="ss-identity-auto" title="<?php esc_attr_e( 'Matched by WooCommerce product ID — not a key field.', 'sheetsync-for-woocommerce' ); ?>">—</span>
                                        <input type="hidden" name="field_map[product_id][key]" value="0">
                                    <?php else : ?>
                                    <input type="checkbox"
                                           name="field_map[<?php echo esc_attr( $sheetsync_key ); ?>][key]"
                                           value="1"
                                           <?php
                                           // Default: SKU is key field if no mapping saved yet
                                           $sheetsync_is_key = ! empty( $field_maps[ $sheetsync_key ]['is_key_field'] );
                                           if ( ! $sheetsync_is_key && $sheetsync_key === '_sku' && empty( $field_maps ) ) {
                                               $sheetsync_is_key = true;
                                           }
                                           checked( $sheetsync_is_key );
                                           ?>>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <!-- Pro fields -->
                        <?php
                        $sheetsync_var_section_shown     = false;
                        $sheetsync_pricing_section_shown = false;
                        $sheetsync_media_section_shown   = false;
                        foreach ( $sheetsync_pro_fields as $sheetsync_key => $sheetsync_label ) :
                            if ( ! $sheetsync_pricing_section_shown && in_array( $sheetsync_key, $sheetsync_pricing_field_keys, true ) ) :
                                $sheetsync_pricing_section_shown = true;
                                ?>
                            <tr class="ss-mapping-section-row ss-mapping-section-pricing">
                                <td colspan="3">
                                    <strong><?php esc_html_e( 'Pricing, inventory & dimensions', 'sheetsync-for-woocommerce' ); ?></strong>
                                    <span class="description"><?php esc_html_e( 'Regular price & stock qty are in the free fields above. Map sale price, stock status, and weight/size here.', 'sheetsync-for-woocommerce' ); ?></span>
                                </td>
                            </tr>
                                <?php
                            endif;
                            if ( ! $sheetsync_media_section_shown && in_array( $sheetsync_key, $sheetsync_media_taxonomy_keys, true ) ) :
                                $sheetsync_media_section_shown = true;
                                ?>
                            <tr class="ss-mapping-section-row ss-mapping-section-media">
                                <td colspan="3">
                                    <strong><?php esc_html_e( 'Images, categories & tags', 'sheetsync-for-woocommerce' ); ?></strong>
                                    <span class="description"><?php esc_html_e( 'Image URLs download after sync. Categories and tags use comma-separated names.', 'sheetsync-for-woocommerce' ); ?></span>
                                </td>
                            </tr>
                                <?php
                            endif;
                            if ( ! $sheetsync_var_section_shown && in_array( $sheetsync_key, $sheetsync_variable_field_keys, true ) ) :
                                $sheetsync_var_section_shown = true;
                                ?>
                            <tr class="ss-mapping-section-row">
                                <td colspan="3">
                                    <strong><?php esc_html_e( 'Variable products & variations', 'sheetsync-for-woocommerce' ); ?></strong>
                                    <span class="description"><?php esc_html_e( 'Map Parent SKU + Color/Size (recommended) or Variation Attributes for advanced attribute slugs.', 'sheetsync-for-woocommerce' ); ?></span>
                                </td>
                            </tr>
                                <?php
                            endif;
                            $sheetsync_is_var_field = in_array( $sheetsync_key, $sheetsync_variable_field_keys, true );
                            ?>
                            <tr class="<?php echo $sheetsync_is_var_field ? 'ss-variable-field-row' : ''; ?>">
                                <td>
                                    <span class="ss-field-label"><?php echo esc_html( $sheetsync_label ); ?></span>
                                    <span class="ss-field-key"><?php echo esc_html( $sheetsync_key ); ?></span>
                                    <?php if ( in_array( $sheetsync_key, array( 'sheet_color', 'sheet_size' ), true ) ) : ?>
                                        <span class="ss-identity-badge ss-easy-badge"><?php esc_html_e( 'Easy', 'sheetsync-for-woocommerce' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <input type="text" name="field_map[<?php echo esc_attr( $sheetsync_key ); ?>][column]"
                                           class="column-input"
                                           value="<?php echo esc_attr( $field_maps[ $sheetsync_key ]['sheet_column'] ?? '' ); ?>"
                                           maxlength="3" placeholder="A">
                                </td>
                                <td style="text-align:center;">
                                    <input type="checkbox"
                                           name="field_map[<?php echo esc_attr( $sheetsync_key ); ?>][key]"
                                           value="1"
                                           <?php checked( ! empty( $field_maps[ $sheetsync_key ]['is_key_field'] ) ); ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                

                <p style="margin-top:16px;">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e( 'Save Field Mapping', 'sheetsync-for-woocommerce' ); ?>
                    </button>
                </p>
            </form>
        </div>
    </div>

    <!-- Tab: Sync -->
    <div id="tab-sync" class="ss-tab-panel" style="display:none;">
        <?php
        $sheetsync_has_maps     = SheetSync_Sync_Engine::is_orders_type( $connection->connection_type ) || ! empty( SheetSync_Field_Mapper::get_maps( $sheetsync_conn_id ) );
        $sheetsync_strategy     = get_option( 'sheetsync_sync_strategy_' . $sheetsync_conn_id, 'smart' );
        $sheetsync_auto_on_save = (bool) get_option( 'sheetsync_auto_on_save_' . $sheetsync_conn_id, false );
        $sheetsync_schedule     = get_option( 'sheetsync_schedule_' . $sheetsync_conn_id, '' );
        $sheetsync_next_run = class_exists( 'SheetSync_Cron_Manager', false )
            ? SheetSync_Cron_Manager::get_next_run( $sheetsync_conn_id )
            : '';
        $sheetsync_auto_sync_map     = get_option( 'sheetsync_auto_sync_settings', array() );
        $sheetsync_auto_sync_enabled = (bool) ( $sheetsync_auto_sync_map[ $sheetsync_conn_id ] ?? false );
        $sheetsync_automatic_sync_enabled = function_exists( 'sheetsync_is_automatic_sync_enabled' )
            ? sheetsync_is_automatic_sync_enabled( $sheetsync_conn_id )
            : ( $sheetsync_auto_sync_enabled || $sheetsync_auto_on_save || ! empty( $sheetsync_schedule ) );
        $sheetsync_sync_direction    = $connection->sync_direction ?? 'sheets_to_wc';
        $sheetsync_is_orders_conn    = SheetSync_Sync_Engine::is_orders_type( $connection->connection_type ?? 'products' );
        $sheetsync_eta_minutes       = null;
        $sheetsync_row_context      = ( 'wc_to_sheets' === $sheetsync_sync_direction ) ? 'export' : 'import';
        $sheetsync_export_breakdown = ( ! $sheetsync_is_orders_conn && function_exists( 'sheetsync_connection_row_breakdown' ) )
            ? sheetsync_connection_row_breakdown( $sheetsync_conn_id, $sheetsync_row_context )
            : ( ( ! $sheetsync_is_orders_conn && function_exists( 'sheetsync_count_exportable_breakdown' ) )
                ? sheetsync_count_exportable_breakdown( $sheetsync_conn_id )
                : array() );
        $sheetsync_wc_export_breakdown = ( ! $sheetsync_is_orders_conn && 'wc_to_sheets' === $sheetsync_sync_direction
            && function_exists( 'sheetsync_count_exportable_breakdown' ) )
            ? sheetsync_count_exportable_breakdown( $sheetsync_conn_id )
            : $sheetsync_export_breakdown;
        $sheetsync_export_total      = (int) ( $sheetsync_export_breakdown['sheet_rows'] ?? 0 );
        $sheetsync_as_health         = function_exists( 'sheetsync_get_action_scheduler_health' )
            ? sheetsync_get_action_scheduler_health()
            : array( 'ok' => true, 'past_due' => 0, 'message' => '', 'tools_url' => '' );
        $sheetsync_sync_settings     = class_exists( 'SheetSync_Sync_State_Repository', false )
            ? ( new SheetSync_Sync_State_Repository() )->get_settings( $sheetsync_conn_id )
            : array();
        $sheetsync_phase_order       = (string) ( $sheetsync_sync_settings['two_way_phase_order'] ?? 'pull_push' );
        $sheetsync_on_conflict       = (string) ( $sheetsync_sync_settings['on_conflict'] ?? 'merge' );
        $sheetsync_field_policies    = is_array( $sheetsync_sync_settings['field_policies'] ?? null )
            ? $sheetsync_sync_settings['field_policies']
            : ( class_exists( 'SheetSync_Sync_State_Repository', false )
                ? ( SheetSync_Sync_State_Repository::default_settings()['field_policies'] ?? array() )
                : array() );
        $sheetsync_conflict_policy_labels = array(
            'merge' => __( 'Merge by field rules', 'sheetsync-for-woocommerce' ),
            'queue' => __( 'Queue for manual review', 'sheetsync-for-woocommerce' ),
            'sheet' => __( 'Google Sheet wins', 'sheetsync-for-woocommerce' ),
            'wc'    => __( 'WooCommerce wins', 'sheetsync-for-woocommerce' ),
        );
        $sheetsync_conflict_policy_label = $sheetsync_conflict_policy_labels[ $sheetsync_on_conflict ] ?? $sheetsync_on_conflict;
        $sheetsync_require_sku       = ! empty( $sheetsync_sync_settings['require_sku'] );
        $sheetsync_conflict_rows     = ( ! $sheetsync_is_orders_conn && class_exists( 'SheetSync_Product_Map_Repository', false ) )
            ? ( new SheetSync_Product_Map_Repository() )->list_conflicts( $sheetsync_conn_id )
            : array();
        $sheetsync_conflict_count    = count( $sheetsync_conflict_rows );
        $sheetsync_mapped_count      = ( ! $sheetsync_is_orders_conn && class_exists( 'SheetSync_Product_Map_Repository', false ) )
            ? ( new SheetSync_Product_Map_Repository() )->count_for_connection( $sheetsync_conn_id )
            : 0;
        $sheetsync_orphaned_maps     = ( ! $sheetsync_is_orders_conn && class_exists( 'SheetSync_Product_Map_Repository', false ) )
            ? ( new SheetSync_Product_Map_Repository() )->count_orphaned_maps( $sheetsync_conn_id )
            : 0;
        $sheetsync_sheet_row_total   = (int) $sheetsync_export_total;
        $sheetsync_import_incomplete = ! $sheetsync_is_orders_conn
            && $sheetsync_sheet_row_total > 0
            && $sheetsync_mapped_count > 0
            && $sheetsync_mapped_count < $sheetsync_sheet_row_total;
        $sheetsync_is_first_import   = ! $sheetsync_is_orders_conn && $sheetsync_mapped_count < 1;
        $sheetsync_is_first_export   = ! $sheetsync_is_orders_conn && $sheetsync_mapped_count < 1;
        $sheetsync_field_maps        = SheetSync_Field_Mapper::get_maps( $sheetsync_conn_id );
        $sheetsync_has_image_map     = ! empty( $sheetsync_field_maps['_product_image']['sheet_column'] ?? '' )
            || ! empty( $sheetsync_field_maps['_gallery_images']['sheet_column'] ?? '' );
        $sheetsync_large_threshold   = function_exists( 'sheetsync_large_sync_scheduler_threshold' )
            ? sheetsync_large_sync_scheduler_threshold()
            : (int) apply_filters( 'sheetsync_large_sync_scheduler_threshold', 500 );
        $sheetsync_gate_threshold    = function_exists( 'sheetsync_large_sync_gate_threshold' )
            ? sheetsync_large_sync_gate_threshold()
            : 200;
        $sheetsync_large_catalog     = $sheetsync_export_total >= $sheetsync_large_threshold;
        $sheetsync_needs_scheduler_gate = ! $sheetsync_is_orders_conn
            && $sheetsync_export_total >= $sheetsync_gate_threshold
            && ! ( $sheetsync_as_health['ok'] ?? true );
        if ( $sheetsync_export_total > 0 && function_exists( 'sheetsync_estimate_job_eta_minutes' ) ) {
            $sheetsync_eta_direction = in_array( $sheetsync_sync_direction, array( 'wc_to_sheets', 'two_way' ), true )
                && 'full' === $sheetsync_strategy
                ? 'bootstrap'
                : 'pull';
            $sheetsync_eta_minutes = sheetsync_estimate_job_eta_minutes(
                (object) array(
                    'connection_id'   => $sheetsync_conn_id,
                    'total_estimate'  => $sheetsync_export_total,
                    'processed_count' => 0,
                    'cursor_offset'   => 0,
                    'direction'       => $sheetsync_eta_direction,
                    'phase'           => 'bootstrap' === $sheetsync_eta_direction ? 'push' : 'pull',
                    'status'          => 'running',
                )
            );
        }
        $sheetsync_degraded_eta = null;
        if ( null !== $sheetsync_eta_minutes && $sheetsync_eta_minutes > 0 && $sheetsync_needs_scheduler_gate ) {
            $sheetsync_degraded_eta = max( $sheetsync_eta_minutes, (int) ceil( $sheetsync_eta_minutes * 2.2 ) );
        }
        ?>

        <?php if ( ! $sheetsync_is_orders_conn && $sheetsync_needs_scheduler_gate ) : ?>
        <div class="sheetsync-card ss-large-catalog-gate" id="ss-large-catalog-gate">
            <h2>
                <span class="dashicons dashicons-performance" style="color:#d97706;"></span>
                <?php esc_html_e( 'Large catalog — choose how to sync', 'sheetsync-for-woocommerce' ); ?>
            </h2>
            <p class="description">
                <?php
                if ( null !== $sheetsync_degraded_eta && $sheetsync_export_total > 0 ) {
                    printf(
                        /* translators: 1: row count, 2: healthy ETA, 3: slow ETA */
                        esc_html__( 'About %1$d sheet rows. Background tasks are overdue — healthy queue ~%2$d min; slow browser mode ~%3$d min with this tab open.', 'sheetsync-for-woocommerce' ),
                        (int) $sheetsync_export_total,
                        (int) $sheetsync_eta_minutes,
                        (int) $sheetsync_degraded_eta
                    );
                } else {
                    printf(
                        /* translators: %d: row count */
                        esc_html__( 'About %d catalog rows. Background tasks are overdue — fix them first for the fastest sync.', 'sheetsync-for-woocommerce' ),
                        (int) $sheetsync_export_total
                    );
                }
                ?>
            </p>
            <div class="ss-large-catalog-gate-actions">
                <a class="button button-primary"
                   href="<?php echo esc_url( (string) ( $sheetsync_as_health['tools_url'] ?? admin_url( 'admin.php?page=wc-status&tab=action-scheduler' ) ) ); ?>"
                   target="_blank" rel="noopener">
                    <?php esc_html_e( 'Fix Scheduled Actions (recommended)', 'sheetsync-for-woocommerce' ); ?>
                </a>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync-settings#tab-settings-cron' ) ); ?>">
                    <?php esc_html_e( 'Set up Background Cron', 'sheetsync-for-woocommerce' ); ?>
                </a>
                <button type="button" class="button ss-sync-btn ss-slow-sync-btn"
                        data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>"
                        data-force-degraded="1"
                        <?php disabled( ! $sheetsync_has_maps ); ?>>
                    <?php esc_html_e( 'Slow sync — keep this tab open', 'sheetsync-for-woocommerce' ); ?>
                </button>
            </div>
            <p class="description ss-large-catalog-gate-foot">
                <?php esc_html_e( 'Prefer off-peak full publish below — it runs in the background without keeping this tab open. Slow sync is only for emergencies.', 'sheetsync-for-woocommerce' ); ?>
            </p>
        </div>
        <?php elseif ( ! $sheetsync_is_orders_conn && ! ( $sheetsync_as_health['ok'] ?? true ) ) : ?>
        <div class="notice notice-warning inline sheetsync-card ss-scheduler-warning" style="margin-bottom:16px; border-left:4px solid #d97706;">
            <p>
                <span class="dashicons dashicons-warning"></span>
                <strong><?php esc_html_e( 'Background tasks overdue', 'sheetsync-for-woocommerce' ); ?></strong>
                — <?php echo esc_html( (string) ( $sheetsync_as_health['message'] ?? '' ) ); ?>
                <?php if ( $sheetsync_large_catalog ) : ?>
                    <?php
                    printf(
                        ' %s',
                        esc_html(
                            sprintf(
                                /* translators: %d: row count threshold */
                                __( 'Syncs with %d+ catalog rows may run slowly until Scheduled Actions is fixed. You can still start sync in degraded mode.', 'sheetsync-for-woocommerce' ),
                                (int) $sheetsync_large_threshold
                            )
                        )
                    );
                    ?>
                <?php endif; ?>
                <a href="<?php echo esc_url( (string) ( $sheetsync_as_health['tools_url'] ?? admin_url( 'admin.php?page=wc-status&tab=action-scheduler' ) ) ); ?>" target="_blank" rel="noopener">
                    <?php esc_html_e( 'WooCommerce → Status → Scheduled Actions', 'sheetsync-for-woocommerce' ); ?>
                </a>
            </p>
        </div>
        <?php endif; ?>

        <?php
        if ( ! $sheetsync_is_orders_conn && ! empty( $connection->spreadsheet_id ) ) {
            require __DIR__ . '/fragments/sheet-readiness-card.php';
        }
        ?>

        <?php
        $sheetsync_recent_issues = class_exists( 'SheetSync_Logger', false )
            ? SheetSync_Logger::get_recent_issues( $sheetsync_conn_id, 3 )
            : array();
        if ( ! empty( $sheetsync_recent_issues ) ) :
            $sheetsync_logs_url = admin_url( 'admin.php?page=sheetsync-logs&connection_id=' . $sheetsync_conn_id );
            ?>
        <div class="sheetsync-card ss-recent-issues-card" id="ss-recent-issues-panel">
            <h2>
                <span class="dashicons dashicons-warning" style="color:var(--ss-red);"></span>
                <?php esc_html_e( 'Recent sync issues', 'sheetsync-for-woocommerce' ); ?>
            </h2>
            <p class="description">
                <?php esc_html_e( 'The latest problems for this connection. Each entry includes a suggested fix — full history is in Sync Logs.', 'sheetsync-for-woocommerce' ); ?>
                <a href="<?php echo esc_url( $sheetsync_logs_url ); ?>"><?php esc_html_e( 'View all logs', 'sheetsync-for-woocommerce' ); ?></a>
            </p>
            <ul class="ss-recent-issues-list">
                <?php foreach ( $sheetsync_recent_issues as $sheetsync_issue ) :
                    $sheetsync_issue_cat     = SheetSync_Logger::human_category( $sheetsync_issue );
                    $sheetsync_issue_actions = SheetSync_Logger::recovery_actions( $sheetsync_issue );
                    $sheetsync_issue_msg     = SheetSync_Logger::display_message( (string) ( $sheetsync_issue['message'] ?? '' ) );
                    $sheetsync_issue_ts      = SheetSync_Logger::log_timestamp( (string) ( $sheetsync_issue['created_at'] ?? '' ) );
                    ?>
                <li class="ss-recent-issue ss-recent-issue-<?php echo esc_attr( (string) ( $sheetsync_issue['status'] ?? 'error' ) ); ?>">
                    <div class="ss-recent-issue-head">
                        <span class="ss-log-category ss-log-category-<?php echo esc_attr( $sheetsync_issue_cat ); ?>">
                            <?php echo esc_html( SheetSync_Logger::human_category_label( $sheetsync_issue ) ); ?>
                        </span>
                        <?php if ( $sheetsync_issue_ts > 0 ) : ?>
                            <span class="ss-recent-issue-time"><?php echo esc_html( human_time_diff( $sheetsync_issue_ts, (int) current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'sheetsync-for-woocommerce' ) ); ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="ss-recent-issue-message"><?php echo esc_html( $sheetsync_issue_msg ); ?></p>
                    <?php if ( ! empty( $sheetsync_issue_actions ) ) : ?>
                    <p class="ss-recent-issue-actions">
                        <?php
                        $sheetsync_primary = $sheetsync_issue_actions[0];
                        ?>
                        <a href="<?php echo esc_url( (string) ( $sheetsync_primary['url'] ?? '' ) ); ?>"
                            <?php echo ! empty( $sheetsync_primary['external'] ) ? ' target="_blank" rel="noopener"' : ''; ?>>
                            <?php echo esc_html( (string) ( $sheetsync_primary['label'] ?? '' ) ); ?>
                        </a>
                    </p>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ( $sheetsync_is_orders_conn ) : ?>
        <?php require SHEETSYNC_PRO_DIR . 'admin/partials/fragments/order-sync-guide.php'; ?>
        <?php endif; ?>

        <?php if ( ! $sheetsync_is_orders_conn && in_array( $sheetsync_sync_direction, array( 'wc_to_sheets', 'two_way' ), true ) ) : ?>
        <div class="sheetsync-card ss-bootstrap-card">
            <h2>
                <span class="dashicons dashicons-admin-generic" style="color:var(--ss-green);"></span>
                <?php esc_html_e( 'One-click sheet setup', 'sheetsync-for-woocommerce' ); ?>
            </h2>
            <p class="description">
                <?php esc_html_e( 'Writes export headers (including Product ID), applies field mapping, and validates your sheet in one go — then run Export below.', 'sheetsync-for-woocommerce' ); ?>
            </p>
            <button type="button" class="button button-primary ss-bootstrap-sheet-btn"
                    data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>">
                <?php esc_html_e( 'Setup My Sheet Automatically', 'sheetsync-for-woocommerce' ); ?>
            </button>
        </div>
        <?php endif; ?>

        <div id="ss-bootstrap-modal" class="ss-bootstrap-modal" style="display:none;" role="dialog" aria-modal="true"
             aria-labelledby="ss-bootstrap-modal-title">
            <div class="ss-bootstrap-modal-inner">
                <h3 id="ss-bootstrap-modal-title"><?php esc_html_e( 'Setting up your sheet…', 'sheetsync-for-woocommerce' ); ?></h3>
                <ol class="ss-bootstrap-steps" id="ss-bootstrap-steps"></ol>
                <div id="ss-bootstrap-fixes" class="ss-bootstrap-fixes" style="display:none;"></div>
                <p class="ss-bootstrap-modal-actions">
                    <button type="button" class="button" id="ss-bootstrap-modal-close"><?php esc_html_e( 'Close', 'sheetsync-for-woocommerce' ); ?></button>
                </p>
            </div>
        </div>

        <?php if ( ! $sheetsync_has_maps ) : ?>
        <div class="notice notice-warning inline sheetsync-card" style="margin-bottom:16px;">
            <p>
                <span class="dashicons dashicons-warning"></span>
                <?php esc_html_e( 'No field mappings yet.', 'sheetsync-for-woocommerce' ); ?>
                <a href="<?php echo esc_url( admin_url( "admin.php?page=sheetsync&sheetsync_action=edit&connection_id={$sheetsync_conn_id}#tab-field-mapping" ) ); ?>">
                    <?php esc_html_e( 'Set up Field Mapping first →', 'sheetsync-for-woocommerce' ); ?>
                </a>
            </p>
        </div>
        <?php endif; ?>

        <?php if ( ! $sheetsync_is_orders_conn && $sheetsync_has_maps ) :
            $sheetsync_sku_col = trim( (string) ( $sheetsync_field_maps['_sku']['sheet_column'] ?? '' ) );
            $sheetsync_pid_col = trim( (string) ( $sheetsync_field_maps['product_id']['sheet_column'] ?? '' ) );
            ?>
        <div class="notice notice-info inline sheetsync-card ss-identity-summary" style="margin-bottom:16px; border-left:4px solid var(--ss-green);">
            <p style="margin:0;">
                <span class="dashicons dashicons-admin-links" style="color:var(--ss-green);"></span>
                <strong><?php esc_html_e( 'Product matching', 'sheetsync-for-woocommerce' ); ?></strong>
                —
                <?php if ( $sheetsync_sku_col !== '' && $sheetsync_pid_col !== '' ) : ?>
                    <?php
                    printf(
                        /* translators: 1: SKU column letter, 2: Product ID column letter */
                        esc_html__( 'SKU column %1$s and Product ID column %2$s are mapped. Rows match by Product ID first, then SKU.', 'sheetsync-for-woocommerce' ),
                        esc_html( $sheetsync_sku_col ),
                        esc_html( $sheetsync_pid_col )
                    );
                    ?>
                <?php elseif ( $sheetsync_pid_col !== '' ) : ?>
                    <?php
                    printf(
                        /* translators: %s: column letter */
                        esc_html__( 'Product ID column %s is mapped. SKU is not mapped — add column A for new products.', 'sheetsync-for-woocommerce' ),
                        esc_html( $sheetsync_pid_col )
                    );
                    ?>
                <?php elseif ( $sheetsync_sku_col !== '' ) : ?>
                    <?php
                    printf(
                        /* translators: %s: column letter */
                        esc_html__( 'SKU column %s is mapped. Product ID column B is recommended after your first export for reliable updates.', 'sheetsync-for-woocommerce' ),
                        esc_html( $sheetsync_sku_col )
                    );
                    ?>
                <?php else : ?>
                    <?php esc_html_e( 'No identity columns mapped — open Field Mapping and map SKU (A) or Product ID (B).', 'sheetsync-for-woocommerce' ); ?>
                <?php endif; ?>
                <?php if ( $sheetsync_mapped_count > 0 ) : ?>
                    <?php
                    printf(
                        ' %s',
                        esc_html(
                            sprintf(
                                /* translators: %d: number of linked products */
                                __( '%d products linked to sheet rows.', 'sheetsync-for-woocommerce' ),
                                (int) $sheetsync_mapped_count
                            )
                        )
                    );
                    ?>
                <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>

        <?php if ( ! $sheetsync_is_orders_conn && $sheetsync_has_maps ) :
            $sheetsync_catalog_lines = array();
            $sheetsync_catalog_map   = array(
                '_regular_price'  => __( 'Regular price', 'sheetsync-for-woocommerce' ),
                '_sale_price'     => __( 'Sale price', 'sheetsync-for-woocommerce' ),
                '_stock'          => __( 'Stock qty', 'sheetsync-for-woocommerce' ),
                '_stock_status'   => __( 'Stock status', 'sheetsync-for-woocommerce' ),
                '_product_image'  => __( 'Featured image', 'sheetsync-for-woocommerce' ),
                '_gallery_images' => __( 'Gallery', 'sheetsync-for-woocommerce' ),
                '_product_cats'   => __( 'Categories', 'sheetsync-for-woocommerce' ),
                '_product_tags'   => __( 'Tags', 'sheetsync-for-woocommerce' ),
            );
            foreach ( $sheetsync_catalog_map as $sheetsync_cf_key => $sheetsync_cf_label ) {
                $sheetsync_cf_col = trim( (string) ( $sheetsync_field_maps[ $sheetsync_cf_key ]['sheet_column'] ?? '' ) );
                if ( $sheetsync_cf_col !== '' ) {
                    $sheetsync_catalog_lines[] = sprintf(
                        '%s (%s)',
                        $sheetsync_cf_label,
                        $sheetsync_cf_col
                    );
                }
            }
            ?>
        <div class="notice notice-info inline sheetsync-card ss-catalog-summary" style="margin-bottom:16px; border-left:4px solid #6366f1;">
            <p style="margin:0;">
                <span class="dashicons dashicons-list-view" style="color:#6366f1;"></span>
                <strong><?php esc_html_e( 'Catalog data syncing', 'sheetsync-for-woocommerce' ); ?></strong>
                —
                <?php if ( ! empty( $sheetsync_catalog_lines ) ) : ?>
                    <?php echo esc_html( implode( ' · ', $sheetsync_catalog_lines ) ); ?>.
                    <?php if ( $sheetsync_has_image_map && in_array( $sheetsync_sync_direction, array( 'sheets_to_wc', 'two_way' ), true ) ) : ?>
                        <?php esc_html_e( 'Images queue in the background after product data saves.', 'sheetsync-for-woocommerce' ); ?>
                    <?php endif; ?>
                <?php else : ?>
                    <?php esc_html_e( 'Only identity columns are mapped — map price (D), stock (E), images (P/Q), or categories (S) on Field Mapping to sync catalog data.', 'sheetsync-for-woocommerce' ); ?>
                <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>

        <?php
        $sheetsync_settings      = get_option( 'sheetsync_settings', array() );
        $sheetsync_template_url  = esc_url( (string) ( $sheetsync_settings['google_template_url'] ?? '' ) );
        ?>
        <?php if ( ! $sheetsync_is_orders_conn && 'sheets_to_wc' === $sheetsync_sync_direction ) : ?>
        <div class="sheetsync-card ss-quickstart-card">
            <h2>
                <span class="dashicons dashicons-welcome-learn-more" style="color:var(--ss-green);"></span>
                <?php esc_html_e( 'Product import quick start', 'sheetsync-for-woocommerce' ); ?>
            </h2>
            <ol class="ss-quickstart-steps">
                <li>
                    <button type="button" class="button button-secondary ss-write-template-btn"
                            data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>">
                        <?php esc_html_e( 'Write template to Google Sheet', 'sheetsync-for-woocommerce' ); ?>
                    </button>
                    <?php esc_html_e( '— styled headers, 3 example rows (with images), Help tab, and recommended field mapping.', 'sheetsync-for-woocommerce' ); ?>
                </li>
                <?php if ( $sheetsync_template_url ) : ?>
                <li>
                    <a href="<?php echo esc_url( $sheetsync_template_url ); ?>" target="_blank" rel="noopener">
                        <?php esc_html_e( 'Open public Google template (Make a copy)', 'sheetsync-for-woocommerce' ); ?>
                    </a>
                </li>
                <?php endif; ?>
                <li>
                    <a href="<?php echo esc_url( plugins_url( 'sample-import-10-products.csv', SHEETSYNC_PRO_FILE ) ); ?>" download>CSV (full)</a>
                    ·
                    <a href="<?php echo esc_url( plugins_url( 'sample-import-simple-5-columns.csv', SHEETSYNC_PRO_FILE ) ); ?>" download><?php esc_html_e( 'CSV (simple 6 columns)', 'sheetsync-for-woocommerce' ); ?></a>
                </li>
                <li>
                    <a href="<?php echo esc_url( admin_url( "admin.php?page=sheetsync&sheetsync_action=edit&connection_id={$sheetsync_conn_id}#tab-field-mapping" ) ); ?>">Field Mapping</a>
                    <?php esc_html_e( '→ confirm Recommended columns are saved.', 'sheetsync-for-woocommerce' ); ?>
                </li>
                <li><?php esc_html_e( 'Run Check sheet below, then Sync now on the Sync tab (first run links all rows).', 'sheetsync-for-woocommerce' ); ?></li>
                <li><?php esc_html_e( 'Selling Color/Size options? Use the Full mapping preset — one variable product = parent row + variation rows (see Field Mapping guide).', 'sheetsync-for-woocommerce' ); ?></li>
            </ol>
        </div>

        <div class="sheetsync-card ss-sheet-check-card">
            <h2>
                <span class="dashicons dashicons-yes-alt" style="color:var(--ss-green);"></span>
                <?php esc_html_e( 'Check sheet before sync', 'sheetsync-for-woocommerce' ); ?>
            </h2>
            <p class="description">
                <?php esc_html_e( 'Validates SKUs, variable parents, Color/Size values, image mapping, and duplicate products before you sync. Reports simple vs variable parent vs variation row counts.', 'sheetsync-for-woocommerce' ); ?>
            </p>
            <button type="button" class="button button-secondary ss-check-sheet-btn"
                    data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>"
                    <?php disabled( ! $sheetsync_has_maps ); ?>>
                <span class="dashicons dashicons-search"></span>
                <?php esc_html_e( 'Check sheet', 'sheetsync-for-woocommerce' ); ?>
            </button>
            <div id="ss-sheet-check-results" class="ss-sheet-check-results" style="display:none;" aria-live="polite"></div>
            <p class="description" style="margin-top:12px;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync-match-diagnostics&connection_id=' . (int) $sheetsync_conn_id ) ); ?>">
                    <?php esc_html_e( 'Open full Match Diagnostics →', 'sheetsync-for-woocommerce' ); ?>
                </a>
            </p>
        </div>
        <?php endif; ?>

        <?php if ( ! $sheetsync_is_orders_conn && 'wc_to_sheets' === $sheetsync_sync_direction ) : ?>
        <div class="sheetsync-card ss-quickstart-card">
            <h2>
                <span class="dashicons dashicons-upload" style="color:var(--ss-green);"></span>
                <?php esc_html_e( 'Export WooCommerce catalog to Google Sheet', 'sheetsync-for-woocommerce' ); ?>
            </h2>
            <?php if ( $sheetsync_export_total > 0 ) : ?>
            <p class="description">
                <?php
                printf(
                    /* translators: 1: sheet rows, 2: parent products, 3: variation rows, 4: trashed */
                    esc_html__( 'Sheet rows to export: about %1$d (= %2$d products in Products → All + %3$d variation rows under each variable product). Trash (%4$d) is not exported.', 'sheetsync-for-woocommerce' ),
                    (int) $sheetsync_export_total,
                    (int) ( $sheetsync_wc_export_breakdown['parent_products'] ?? 0 ),
                    (int) ( $sheetsync_wc_export_breakdown['variations'] ?? 0 ),
                    (int) ( $sheetsync_wc_export_breakdown['trashed_parents'] ?? 0 )
                );
                $sheetsync_dup_ids = (int) ( $sheetsync_wc_export_breakdown['export_duplicate_ids'] ?? 0 );
                $sheetsync_orphans = (int) ( $sheetsync_wc_export_breakdown['orphan_variations'] ?? 0 );
                if ( $sheetsync_dup_ids > 0 || $sheetsync_orphans > 0 ) {
                    echo ' ';
                    if ( $sheetsync_dup_ids > 0 ) {
                        printf(
                            /* translators: %d: duplicate product IDs in export walk */
                            esc_html__( 'Audit: %d duplicate export IDs detected — contact support.', 'sheetsync-for-woocommerce' ),
                            $sheetsync_dup_ids
                        );
                    }
                    if ( $sheetsync_orphans > 0 ) {
                        printf(
                            /* translators: %d: orphan variation posts */
                            esc_html__( ' %d orphan variations (no parent) are excluded from export.', 'sheetsync-for-woocommerce' ),
                            $sheetsync_orphans
                        );
                    }
                } else {
                    echo ' ';
                    esc_html_e( 'Export audit: each WooCommerce product ID is exported once (no duplicate rows from the plugin).', 'sheetsync-for-woocommerce' );
                }
                ?>
                <?php esc_html_e( 'Large catalogs run in batches — keep this page open or check Sync Logs.', 'sheetsync-for-woocommerce' ); ?>
            </p>
            <?php endif; ?>
            <ol class="ss-quickstart-steps">
                <li><?php esc_html_e( 'Connection tab → Header Row must match your sheet (usually 1). Data always starts on the next row.', 'sheetsync-for-woocommerce' ); ?></li>
                <li><?php esc_html_e( 'Field Mapping → save Recommended columns (SKU, Product ID, Title, Price, Stock, Images).', 'sheetsync-for-woocommerce' ); ?></li>
                <li>
                    <button type="button" class="button button-secondary ss-prepare-sheet-headers-btn"
                            data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>"
                            <?php disabled( ! $sheetsync_has_maps ); ?>>
                        <?php esc_html_e( 'Write styled headers to sheet', 'sheetsync-for-woocommerce' ); ?>
                    </button>
                    <?php esc_html_e( '— color headers, column widths, and filter row.', 'sheetsync-for-woocommerce' ); ?>
                </li>
                <li>
                    <button type="button" class="button button-secondary ss-apply-sheet-formatting-btn"
                            data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>"
                            <?php disabled( ! $sheetsync_has_maps ); ?>>
                        <?php esc_html_e( 'Apply filters & row styling', 'sheetsync-for-woocommerce' ); ?>
                    </button>
                    <?php esc_html_e( '— header filters, green banding, parent/variation colors, and collapsible variation groups (run after the first catalog publish if the sheet looks plain).', 'sheetsync-for-woocommerce' ); ?>
                </li>
                <li><?php esc_html_e( 'Click Export to Google Sheet — the first run writes your full catalog automatically.', 'sheetsync-for-woocommerce' ); ?></li>
                <li><?php esc_html_e( 'Open your sheet tab — row 1 = headers, row 2+ = products (Product ID in column B).', 'sheetsync-for-woocommerce' ); ?></li>
            </ol>
        </div>
        <?php endif; ?>

        <?php if ( ! $sheetsync_is_orders_conn && 'two_way' === $sheetsync_sync_direction ) : ?>
        <div class="sheetsync-card ss-quickstart-card">
            <h2>
                <span class="dashicons dashicons-randomize" style="color:var(--ss-green);"></span>
                <?php esc_html_e( 'Two-Way sync — how it works', 'sheetsync-for-woocommerce' ); ?>
            </h2>
            <table class="widefat striped ss-two-way-table" style="max-width:720px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'When', 'sheetsync-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'What happens', 'sheetsync-for-woocommerce' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e( 'First sync', 'sheetsync-for-woocommerce' ); ?></strong></td>
                        <td><?php esc_html_e( 'Publishes your WooCommerce catalog to the sheet and links each row. Does not import empty sheet rows into WooCommerce.', 'sheetsync-for-woocommerce' ); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Daily sync', 'sheetsync-for-woocommerce' ); ?></strong></td>
                        <td>
                            <?php esc_html_e( 'Default: apply sheet changes, then publish WooCommerce changes. Order can be changed under Advanced settings on the Sync tab.', 'sheetsync-for-woocommerce' ); ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Orders vs products', 'sheetsync-for-woocommerce' ); ?></strong></td>
                        <td><?php esc_html_e( 'Product two-way uses pull→push by default. Order two-way uses pull→push (read sheet status edits first, then refresh the tab from WooCommerce).', 'sheetsync-for-woocommerce' ); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Daily edits', 'sheetsync-for-woocommerce' ); ?></strong></td>
                        <td><?php esc_html_e( 'Edited the sheet → Apply changes from Google Sheet. Edited WooCommerce → Publish changes to sheet. Edited both → Keep both sides in sync.', 'sheetsync-for-woocommerce' ); ?></td>
                    </tr>
                </tbody>
            </table>
            <div class="notice notice-warning inline" style="margin:16px 0 0;padding:12px 14px;border-left-width:4px;">
                <p style="margin:0;">
                    <span class="dashicons dashicons-warning"></span>
                    <?php esc_html_e( 'While sync is running, avoid editing the same product in WooCommerce admin and the sheet at the same time. If both sides change, use the conflict policy below (merge, queue, or pick a winner).', 'sheetsync-for-woocommerce' ); ?>
                </p>
            </div>
        </div>
        <?php endif; ?>

        <?php if ( ! $sheetsync_is_orders_conn && 'two_way' === $sheetsync_sync_direction && $sheetsync_has_maps ) : ?>
        <div class="notice notice-info inline sheetsync-card ss-conflict-policy-summary" style="margin-bottom:16px; border-left:4px solid #d97706;">
            <p style="margin:0 0 8px;">
                <span class="dashicons dashicons-randomize" style="color:#d97706;"></span>
                <strong><?php esc_html_e( 'Conflict policy', 'sheetsync-for-woocommerce' ); ?></strong>
                —
                <?php echo esc_html( $sheetsync_conflict_policy_label ); ?>.
                <?php if ( 'merge' === $sheetsync_on_conflict ) : ?>
                    <?php esc_html_e( 'Price and stock usually come from the sheet; titles and images from WooCommerce; other fields use the newest edit.', 'sheetsync-for-woocommerce' ); ?>
                <?php elseif ( 'queue' === $sheetsync_on_conflict ) : ?>
                    <?php esc_html_e( 'Rows edited on both sides are held for you to pick Sheet or WooCommerce below.', 'sheetsync-for-woocommerce' ); ?>
                <?php elseif ( 'sheet' === $sheetsync_on_conflict ) : ?>
                    <?php esc_html_e( 'The sheet row overwrites WooCommerce when both sides changed.', 'sheetsync-for-woocommerce' ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'WooCommerce is kept on pull; sheet updates on the next export.', 'sheetsync-for-woocommerce' ); ?>
                <?php endif; ?>
                <a href="#ss-sync-strategy-panel"><?php esc_html_e( 'Change policy', 'sheetsync-for-woocommerce' ); ?></a>
            </p>
            <?php if ( 'queue' === $sheetsync_on_conflict && $sheetsync_conflict_count < 1 ) : ?>
            <p class="description" style="margin:0;">
                <?php esc_html_e( 'No conflicts waiting — you will see them here after a two-way sync when the same product was edited in both places.', 'sheetsync-for-woocommerce' ); ?>
            </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ( ! $sheetsync_is_orders_conn && 'two_way' === $sheetsync_sync_direction && $sheetsync_conflict_count > 0 ) : ?>
        <div class="sheetsync-card ss-conflicts-card" id="ss-conflicts-panel" data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>">
            <h2>
                <span class="dashicons dashicons-warning" style="color:#d97706;"></span>
                <?php
                printf(
                    /* translators: %d: conflict count */
                    esc_html__( 'Sync conflicts (%d)', 'sheetsync-for-woocommerce' ),
                    (int) $sheetsync_conflict_count
                );
                ?>
            </h2>
            <p class="description">
                <?php esc_html_e( 'These rows were edited in both the sheet and WooCommerce before the last sync. Pick which version to keep, or dismiss to clear the flag without syncing.', 'sheetsync-for-woocommerce' ); ?>
            </p>
            <table class="widefat striped ss-conflicts-table" style="max-width:100%;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Row', 'sheetsync-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Product', 'sheetsync-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Different fields', 'sheetsync-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Queued', 'sheetsync-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'sheetsync-for-woocommerce' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $sheetsync_conflict_rows as $sheetsync_conflict ) :
                    $sheetsync_conflict_data = json_decode( (string) ( $sheetsync_conflict->conflict_json ?? '' ), true );
                    $sheetsync_conflict_label = '';
                    $sheetsync_conflict_pid   = (int) ( $sheetsync_conflict->product_id ?? 0 );
                    if ( is_array( $sheetsync_conflict_data ) ) {
                        $sheetsync_conflict_label = ! empty( $sheetsync_conflict_data['sku'] )
                            ? (string) $sheetsync_conflict_data['sku']
                            : ( ! empty( $sheetsync_conflict_data['title'] ) ? (string) $sheetsync_conflict_data['title'] : '' );
                        if ( $sheetsync_conflict_pid < 1 && ! empty( $sheetsync_conflict_data['product_id'] ) ) {
                            $sheetsync_conflict_pid = (int) $sheetsync_conflict_data['product_id'];
                        }
                    }
                    if ( $sheetsync_conflict_label === '' && $sheetsync_conflict_pid > 0 ) {
                        $sheetsync_conflict_label = '#' . $sheetsync_conflict_pid;
                    }
                    $sheetsync_changed_fields = is_array( $sheetsync_conflict_data['changed_fields'] ?? null )
                        ? (array) $sheetsync_conflict_data['changed_fields']
                        : array();
                    $sheetsync_queued_at = is_array( $sheetsync_conflict_data ) ? (string) ( $sheetsync_conflict_data['at'] ?? '' ) : '';
                    if ( $sheetsync_queued_at !== '' ) {
                        $sheetsync_queued_at = date_i18n(
                            get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
                            strtotime( $sheetsync_queued_at . ' UTC' )
                        );
                    }
                    $sheetsync_edit_product_url = $sheetsync_conflict_pid > 0
                        ? get_edit_post_link( $sheetsync_conflict_pid, 'raw' )
                        : '';
                    ?>
                    <tr data-map-id="<?php echo esc_attr( (int) $sheetsync_conflict->id ); ?>">
                        <td><?php echo esc_html( (string) (int) ( $sheetsync_conflict->sheet_row ?? ( $sheetsync_conflict_data['sheet_row'] ?? 0 ) ) ); ?></td>
                        <td>
                            <?php echo esc_html( $sheetsync_conflict_label !== '' ? $sheetsync_conflict_label : '—' ); ?>
                            <?php if ( $sheetsync_edit_product_url ) : ?>
                                <a href="<?php echo esc_url( $sheetsync_edit_product_url ); ?>" target="_blank" rel="noopener" class="ss-conflict-edit-link"><?php esc_html_e( 'Edit in WC', 'sheetsync-for-woocommerce' ); ?></a>
                            <?php endif; ?>
                        </td>
                        <td class="ss-conflict-fields">
                            <?php
                            if ( ! empty( $sheetsync_changed_fields ) ) {
                                echo esc_html( implode( ', ', $sheetsync_changed_fields ) );
                            } else {
                                esc_html_e( 'Multiple fields (re-sync to refresh list)', 'sheetsync-for-woocommerce' );
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html( $sheetsync_queued_at !== '' ? $sheetsync_queued_at : '—' ); ?></td>
                        <td class="ss-conflict-actions">
                            <button type="button" class="button button-small ss-resolve-conflict" data-resolution="apply_sheet"
                                    title="<?php esc_attr_e( 'Overwrite WooCommerce with the current sheet row', 'sheetsync-for-woocommerce' ); ?>">
                                <?php esc_html_e( 'Use sheet', 'sheetsync-for-woocommerce' ); ?>
                            </button>
                            <button type="button" class="button button-small ss-resolve-conflict" data-resolution="apply_wc"
                                    title="<?php esc_attr_e( 'Push WooCommerce data to the sheet row', 'sheetsync-for-woocommerce' ); ?>">
                                <?php esc_html_e( 'Use WooCommerce', 'sheetsync-for-woocommerce' ); ?>
                            </button>
                            <button type="button" class="button button-small ss-resolve-conflict" data-resolution="dismiss"
                                    title="<?php esc_attr_e( 'Clear this flag without applying changes', 'sheetsync-for-woocommerce' ); ?>">
                                <?php esc_html_e( 'Dismiss', 'sheetsync-for-woocommerce' ); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ══ CARD 1: Manual Sync ══════════════════════════════════════════ -->
        <div class="sheetsync-card" id="ss-manual-sync-panel" data-connection-id="<?php echo esc_attr( (string) $sheetsync_conn_id ); ?>">
            <?php
            $sheetsync_running_job = class_exists( 'SheetSync_Job_Repository', false )
                ? ( new SheetSync_Job_Repository() )->get_running_for_connection( $sheetsync_conn_id )
                : null;
            if ( $sheetsync_running_job && function_exists( 'sheetsync_job_progress_numbers' ) ) :
                $sheetsync_job_progress = sheetsync_job_progress_numbers( $sheetsync_running_job );
                $sheetsync_job_done     = (int) ( $sheetsync_job_progress['done'] ?? 0 );
                $sheetsync_job_total    = (int) ( $sheetsync_job_progress['total'] ?? 0 );
                $sheetsync_job_pct      = null !== ( $sheetsync_job_progress['pct'] ?? null )
                    ? min( 100, (int) $sheetsync_job_progress['pct'] )
                    : ( $sheetsync_job_total > 0 ? min( 100, (int) round( ( $sheetsync_job_done / max( 1, $sheetsync_job_total ) ) * 100 ) ) : 2 );
                $sheetsync_job_eta = function_exists( 'sheetsync_estimate_job_eta_minutes' )
                    ? sheetsync_estimate_job_eta_minutes( $sheetsync_running_job )
                    : null;
                if ( ! ( $sheetsync_as_health['ok'] ?? true ) && null !== $sheetsync_job_eta && $sheetsync_job_eta > 0 ) {
                    $sheetsync_job_eta = max( $sheetsync_job_eta, (int) ceil( $sheetsync_job_eta * 2.2 ) );
                }
                $sheetsync_job_phase_label = function_exists( 'sheetsync_human_phase_label' )
                    ? sheetsync_human_phase_label( $sheetsync_running_job )
                    : (string) ( $sheetsync_running_job->phase ?? '' );
                $sheetsync_job_dir   = (string) ( $sheetsync_running_job->direction ?? '' );
                $sheetsync_job_phase = (string) ( $sheetsync_running_job->phase ?? '' );
                if ( 'pull' === $sheetsync_job_dir || 'pull' === $sheetsync_job_phase ) {
                    $sheetsync_job_line = sprintf(
                        /* translators: 1: job id, 2: rows scanned, 3: total rows, 4: updated count */
                        esc_html__( 'Job #%1$d | Scanned %2$d / %3$d rows · %4$d updated', 'sheetsync-for-woocommerce' ),
                        (int) $sheetsync_running_job->id,
                        $sheetsync_job_done,
                        max( $sheetsync_job_total, $sheetsync_job_done ),
                        (int) ( $sheetsync_running_job->processed_count ?? 0 )
                    );
                } else {
                    $sheetsync_job_line = sprintf(
                        /* translators: 1: job id, 2: rows done, 3: total rows */
                        esc_html__( 'Job #%1$d | Export in progress: %2$d / %3$d rows', 'sheetsync-for-woocommerce' ),
                        (int) $sheetsync_running_job->id,
                        min( $sheetsync_job_done, max( $sheetsync_job_total, $sheetsync_job_done ) ),
                        max( $sheetsync_job_total, $sheetsync_job_done )
                    );
                }
                ?>
            <div class="ss-sync-toast ss-toast-success ss-job-progress ss-job-progress-block">
                <div class="ss-progress-bar"><div class="ss-progress-fill" style="width:<?php echo esc_attr( (string) $sheetsync_job_pct ); ?>%"></div></div>
                <?php if ( $sheetsync_job_phase_label !== '' ) : ?>
                <div class="ss-phase-hint description" style="margin-bottom:4px;font-weight:600;">
                    <?php echo esc_html( $sheetsync_job_phase_label ); ?>
                </div>
                <?php endif; ?>
                <div class="ss-toast-stats"><?php echo esc_html( $sheetsync_job_line ); ?></div>
                <?php if ( null !== $sheetsync_job_eta && $sheetsync_job_eta > 0 ) : ?>
                <div class="ss-eta-hint description" style="margin-top:6px;">
                    <?php
                    if ( ! ( $sheetsync_as_health['ok'] ?? true ) ) {
                        printf(
                            /* translators: %d: estimated minutes remaining */
                            esc_html__( '~%d min remaining (background queue slow — keep this tab open)', 'sheetsync-for-woocommerce' ),
                            (int) $sheetsync_job_eta
                        );
                    } else {
                        printf(
                            /* translators: %d: estimated minutes remaining */
                            esc_html__( '~%d min remaining (estimate)', 'sheetsync-for-woocommerce' ),
                            (int) $sheetsync_job_eta
                        );
                    }
                    ?>
                </div>
                <?php endif; ?>
                <div class="ss-sync-tab-hint description" style="margin-top:6px;">
                    <?php esc_html_e( 'Keep this tab open until sync completes.', 'sheetsync-for-woocommerce' ); ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( ! $sheetsync_is_orders_conn ) : ?>
                <?php require SHEETSYNC_PRO_DIR . 'admin/partials/fragments/intent-sync-panel.php'; ?>

                <?php if ( $sheetsync_large_catalog && null !== $sheetsync_eta_minutes ) : ?>
                <div class="notice notice-info inline ss-large-catalog-hint" style="margin:12px 0;padding:12px 14px;border-left-width:4px;">
                    <p style="margin:0;">
                        <?php
                        if ( $sheetsync_needs_scheduler_gate && null !== $sheetsync_degraded_eta ) {
                            printf(
                                /* translators: 1: row count, 2: healthy ETA, 3: slow ETA */
                                esc_html__( 'Large catalog (~%1$d rows): ~%2$d min when background tasks are healthy, or ~%3$d min in slow browser mode.', 'sheetsync-for-woocommerce' ),
                                (int) $sheetsync_export_total,
                                (int) $sheetsync_eta_minutes,
                                (int) $sheetsync_degraded_eta
                            );
                        } else {
                            printf(
                                /* translators: 1: row count, 2: estimated minutes */
                                esc_html__( 'Large catalog (~%1$d sheet rows): full export may take ~%2$d minutes in the background. Progress and ETA appear below when sync starts.', 'sheetsync-for-woocommerce' ),
                                (int) $sheetsync_export_total,
                                (int) $sheetsync_eta_minutes
                            );
                        }
                        ?>
                    </p>
                </div>
                <?php endif; ?>

                <?php if ( $sheetsync_has_image_map && in_array( $sheetsync_sync_direction, array( 'sheets_to_wc', 'two_way' ), true ) ) : ?>
                <div class="notice notice-info inline ss-media-queue-hint" style="margin:12px 0;padding:12px 14px;border-left-width:4px;">
                    <p style="margin:0;">
                        <span class="dashicons dashicons-format-image"></span>
                        <?php esc_html_e( 'Images are applied after product data — featured and gallery URLs download in a background queue. Refresh the product page if images appear a minute later.', 'sheetsync-for-woocommerce' ); ?>
                    </p>
                </div>
                <?php endif; ?>


            <?php else : ?>
            <h2>
                <span class="dashicons dashicons-update" style="color:var(--ss-green);"></span>
                <?php esc_html_e( 'Manual Sync', 'sheetsync-for-woocommerce' ); ?>
            </h2>

            <p class="description">
                <?php if ( 'wc_to_sheets' === $sheetsync_sync_direction ) : ?>
                    <?php esc_html_e( 'Exports matching WooCommerce orders to this sheet tab (rebuilds headers and totals).', 'sheetsync-for-woocommerce' ); ?>
                <?php elseif ( 'two_way' === $sheetsync_sync_direction ) : ?>
                    <?php esc_html_e( 'Pulls status changes from column C into WooCommerce first, then refreshes this tab from WooCommerce (row moves between status tabs happen during pull).', 'sheetsync-for-woocommerce' ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'Reads order rows from the sheet and updates order status in WooCommerce (column C).', 'sheetsync-for-woocommerce' ); ?>
                <?php endif; ?>
            </p>

            <?php if ( $sheetsync_large_catalog && null !== $sheetsync_eta_minutes ) : ?>
            <div class="notice notice-info inline ss-large-catalog-hint" style="margin:12px 0;padding:12px 14px;border-left-width:4px;">
                <p style="margin:0;">
                    <?php
                    if ( $sheetsync_needs_scheduler_gate && null !== $sheetsync_degraded_eta ) {
                        printf(
                            /* translators: 1: row count, 2: healthy ETA, 3: slow ETA */
                            esc_html__( 'Large catalog (~%1$d rows): ~%2$d min when background tasks are healthy, or ~%3$d min in slow browser mode.', 'sheetsync-for-woocommerce' ),
                            (int) $sheetsync_export_total,
                            (int) $sheetsync_eta_minutes,
                            (int) $sheetsync_degraded_eta
                        );
                    } else {
                        printf(
                            /* translators: 1: row count, 2: estimated minutes */
                            esc_html__( 'Large catalog (~%1$d sheet rows): full export may take ~%2$d minutes in the background. Progress and ETA appear below when sync starts.', 'sheetsync-for-woocommerce' ),
                            (int) $sheetsync_export_total,
                            (int) $sheetsync_eta_minutes
                        );
                    }
                    ?>
                </p>
            </div>
            <?php endif; ?>

            <button class="button button-primary ss-sync-btn" data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>" <?php disabled( ! $sheetsync_has_maps ); ?>>
                <span class="dashicons dashicons-update"></span>
                <?php esc_html_e( 'Sync Now', 'sheetsync-for-woocommerce' ); ?>
            </button>
            <?php endif; ?>
        </div>

        <?php
        if ( class_exists( 'SheetSync_Off_Peak_Publish', false )
            && SheetSync_Off_Peak_Publish::connection_supports_off_peak( $connection ) ) {
            require SHEETSYNC_PRO_DIR . 'admin/partials/fragments/off-peak-publish-panel.php';
        }
        ?>

        <?php require SHEETSYNC_PRO_DIR . 'admin/partials/fragments/automatic-sync-panel.php'; ?>

        <?php
        $sheetsync_advanced_open = ( 'full' === $sheetsync_strategy )
            || $sheetsync_automatic_sync_enabled
            || ( 'merge' !== $sheetsync_on_conflict && 'two_way' === $sheetsync_sync_direction );
        ?>
        <details class="ss-sync-advanced-settings sheetsync-card" id="ss-sync-advanced-settings"<?php echo $sheetsync_advanced_open ? ' open' : ''; ?>>
            <summary class="ss-sync-advanced-summary">
                <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                <span class="ss-sync-advanced-summary-text">
                    <strong><?php esc_html_e( 'Advanced settings', 'sheetsync-for-woocommerce' ); ?></strong>
                    <span class="description"><?php esc_html_e( 'Strategy, automation, and power-user sync', 'sheetsync-for-woocommerce' ); ?></span>
                </span>
            </summary>
            <div class="ss-sync-advanced-settings-body">
                <?php require SHEETSYNC_PRO_DIR . 'admin/partials/fragments/sync-advanced-settings.php'; ?>
            </div>
        </details>

    </div>

    <?php endif; ?>

</div>