<?php defined( 'ABSPATH' ) || exit; ?>

<div class="sheetsync-wrap">
    <?php require __DIR__ . '/header.php'; ?>

    <!-- Import from Sheet -->
    <div class="sheetsync-card">
        <h2>📥 <?php esc_html_e( 'Import from Google Sheet', 'sheetsync-for-woocommerce' ); ?></h2>
        <div class="notice notice-info inline" style="margin:0 0 16px;">
            <p>
                <strong><?php esc_html_e( 'Easier way:', 'sheetsync-for-woocommerce' ); ?></strong>
                <?php esc_html_e( 'Use Connections → your product connection → Sync tab: map fields, check the sheet, then Sync now. This page is for advanced one-off imports.', 'sheetsync-for-woocommerce' ); ?>
            </p>
        </div>
        <?php if ( ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) : ?>
        <div class="notice notice-warning inline" style="margin:0 0 16px;">
            <p>
                <strong><?php esc_html_e( 'Free tier:', 'sheetsync-for-woocommerce' ); ?></strong>
                <?php esc_html_e( 'You can import simple products here (SKU, title, price, stock, status). Variable, grouped, and external rows are skipped until you upgrade to Pro.', 'sheetsync-for-woocommerce' ); ?>
            </p>
        </div>
        <?php endif; ?>
        <div class="notice notice-info inline" style="margin:0 0 16px;">
            <p>
                <span class="dashicons dashicons-format-image"></span>
                <?php esc_html_e( 'If you map Featured Image or Gallery columns, product data imports first and images download in a background queue afterward. Refresh the product page if images appear a minute later.', 'sheetsync-for-woocommerce' ); ?>
            </p>
        </div>
        <p><?php esc_html_e( 'Click Import Headers to see your Sheet headers. Then map each column to a WooCommerce field and click Import to add products.', 'sheetsync-for-woocommerce' ); ?></p>

        <!-- Step 1: Connection select -->
        <div class="ss-import-step" id="ss-step-1">
            <h3>Step 1 — Select a Connection</h3>
            <?php
            global $wpdb;
            // FIX M-2: Use $wpdb->prepare() and wp_cache to satisfy PHPCS and WP.org reviewers.
            $cache_key_ie          = 'sheetsync_active_product_connections';
            $sheetsync_connections = wp_cache_get( $cache_key_ie, 'sheetsync' );
            if ( false === $sheetsync_connections ) {
                $sheetsync_connections = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}sheetsync_connections WHERE status = %s AND connection_type = %s ORDER BY name ASC",
                        'active',
                        'products'
                    )
                );
                wp_cache_set( $cache_key_ie, $sheetsync_connections, 'sheetsync', MINUTE_IN_SECONDS * 5 );
            }
            ?>
            <?php if ( empty( $sheetsync_connections ) ) : ?>
                <div class="notice notice-warning inline">
                    <p>No active Products connection found. <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync&sheetsync_action=new' ) ); ?>">Create a Connection</a> first.</p>
                </div>
            <?php else : ?>
                <select id="ss-import-conn" class="regular-text">
                    <option value="">— Select a Connection —</option>
                    <?php foreach ( $sheetsync_connections as $sheetsync_conn ) : ?>
                        <option value="<?php echo esc_attr( $sheetsync_conn->id ); ?>"
                                data-spreadsheet="<?php echo esc_attr( $sheetsync_conn->spreadsheet_id ); ?>"
                                data-sheet="<?php echo esc_attr( $sheetsync_conn->sheet_name ); ?>"
                                data-header="<?php echo esc_attr( $sheetsync_conn->header_row ); ?>">
                            <?php echo esc_html( $sheetsync_conn->name ); ?> — <?php echo esc_html( $sheetsync_conn->sheet_name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button id="ss-load-headers" class="button button-primary" style="margin-left:10px;">
                    📋 View Import Headers
                </button>
                <button type="button" id="ss-use-saved-maps" class="button" style="margin-left:8px;" disabled>
                    ✅ Use saved connection mapping
                </button>
                <a id="ss-open-sync-tab" href="#" class="button button-secondary" style="margin-left:8px; display:none;" target="_blank">→ Sync tab (recommended)</a>
                <span id="ss-header-loader" style="display:none; margin-left:10px;">⏳ Loading...</span>
                <p id="ss-saved-maps-hint" class="description" style="margin-top:8px; display:none;"></p>
            <?php endif; ?>
        </div>

        <!-- Step 2: Column Mapping -->
        <div class="ss-import-step" id="ss-step-2" style="display:none; margin-top:24px;">
            <h3>Step 2 — View Sheet Headers and Map Fields</h3>
            <p class="description">Specify which WooCommerce field each Sheet column maps to. <strong>SKU must be mapped.</strong></p>

            <table class="sheetsync-mapping-table" id="ss-header-mapping-table">
                <thead>
                    <tr>
                        <th>Sheet Column</th>
                        <th>Sheet Header</th>
                        <th>WooCommerce Field</th>
                    </tr>
                </thead>
                <tbody id="ss-header-rows">
                    <!-- Generated by JS -->
                </tbody>
            </table>

            <div style="margin-top:16px;">
                <label style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
                    <input type="checkbox" id="ss-skip-existing" checked>
                    <span>If a product with the same SKU already exists, <strong>skip</strong> it (do not update)</span>
                </label>
                <label style="display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" id="ss-create-new" checked>
                    <span><strong>Create</strong> new products (those in Sheet but not in WooCommerce)</span>
                </label>
            </div>

            <div style="margin-top:20px;">
                <button id="ss-start-import" class="button button-primary button-large">
                    🚀 Start Import
                </button>
                <button id="ss-back-step1" class="button" style="margin-left:10px;">← Go Back</button>
            </div>
        </div>

        <!-- Step 3: Progress -->
        <div class="ss-import-step" id="ss-step-3" style="display:none; margin-top:24px;">
            <h3>Step 3 — Importing...</h3>
            <div class="ss-progress-bar-wrap">
                <div class="ss-progress-bar" id="ss-progress-bar" style="width:0%"></div>
            </div>
            <p id="ss-progress-text">Preparing...</p>
            <div id="ss-import-log" class="ss-import-log"></div>
        </div>

        <!-- Step 4: Done -->
        <div class="ss-import-step" id="ss-step-4" style="display:none; margin-top:24px;">
            <div class="notice notice-success inline">
                <h3>✅ Import Complete!</h3>
                <p id="ss-import-summary"></p>
            </div>
            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>" class="button button-primary">
                View WooCommerce Products →
            </a>
            <button id="ss-import-again" class="button" style="margin-left:10px;">Import Again</button>
        </div>
    </div>

    <!-- Export to Sheet -->
    <div class="sheetsync-card">
        <h2>📤 WooCommerce → Google Sheet Export</h2>
        <p>Export all WooCommerce products to Google Sheet. Go to the Connection, set <strong>Sync Direction: WooCommerce → Google Sheets</strong>, then click <strong>Sync Now</strong>.</p>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync' ) ); ?>" class="button button-primary">
            Go to Connections →
        </a>
    </div>
</div>

<style>
.ss-import-step h3 { margin-bottom: 12px; }
.ss-progress-bar-wrap {
    background: #e5e7eb;
    border-radius: 8px;
    height: 24px;
    margin-bottom: 12px;
    overflow: hidden;
}
.ss-progress-bar {
    background: linear-gradient(90deg, #16a34a, #22c55e);
    height: 100%;
    border-radius: 8px;
    transition: width 0.4s ease;
}
.ss-import-log {
    max-height: 200px;
    overflow-y: auto;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 10px;
    font-size: 12px;
    font-family: monospace;
}
.ss-import-log .log-ok   { color: #16a34a; }
.ss-import-log .log-skip { color: #d97706; }
.ss-import-log .log-err  { color: #dc2626; }
#ss-header-mapping-table select { min-width: 200px; }
#ss-header-mapping-table td { padding: 8px 12px; }
</style>

<script>
jQuery(function($) {
    const wc_fields = {
        ''                  : '— Skip —',
        '_sku'              : 'SKU (Product Key) ⭐',
        'product_id'        : 'Product ID (WooCommerce)',
        'post_title'        : 'Product Title',
        '_regular_price'    : 'Regular Price',
        '_sale_price'       : 'Sale Price',
        '_stock'            : 'Stock Quantity',
        'post_status'       : 'Product Status (publish/draft)',
        'menu_order'        : 'Product Order',
        'post_excerpt'      : 'Short Description',
        '_stock_status'     : 'Stock Status',
        '_weight'           : 'Weight',
        '_length'           : 'Length',
        '_width'            : 'Width',
        '_height'           : 'Height',
        'post_content'      : 'Long Description',
        '_product_image'    : 'Main Image (URL)',
        '_gallery_images'   : 'Gallery Images',
        '_product_type'     : 'Product Type',
        '_product_cats'     : 'Categories',
        '_product_tags'     : 'Tags',
        'parent_sku'        : 'Parent SKU (variable)',
        'variation_attrs'   : 'Variation Attributes',
        'sheet_color'       : 'Color (easy)',
        'sheet_size'        : 'Size (easy)',
        'sheet_row_role'    : 'Row Type',
    };

    // Auto-detect — order matters (specific headers before generic ones).
    function autoDetect(header) {
        const h = header.toLowerCase().trim();
        if (h.includes('parent') && h.includes('sku'))           return 'parent_sku';
        if (h.includes('variation') && h.includes('attr'))       return 'variation_attrs';
        if (h === 'color' || h.startsWith('color '))             return 'sheet_color';
        if (h === 'size' || h.startsWith('size '))               return 'sheet_size';
        if (h.includes('row') && h.includes('type'))             return 'sheet_row_role';
        if (h.includes('product') && h.includes('group'))          return 'sheet_product_group';
        if (h.includes('belong'))                                  return 'sheet_belongs_to';
        if (h.includes('primary') && h.includes('categor'))        return 'primary_category';
        if (h === 'sku (product key)' || (h.includes('sku') && !h.includes('parent'))) return '_sku';
        if (h.includes('product id') || h === 'woocommerce product id') return 'product_id';
        if (h.includes('product type') || h === 'type')          return '_product_type';
        if (h.includes('gallery'))                               return '_gallery_images';
        if (h.includes('main image') || (h.includes('image') && !h.includes('gallery'))) return '_product_image';
        if (h.includes('long') && h.includes('desc'))            return 'post_content';
        if (h.includes('short') && h.includes('desc'))             return 'post_excerpt';
        if (h.includes('stock') && h.includes('status'))         return '_stock_status';
        if (h.includes('product status') || (h.includes('status') && !h.includes('stock'))) return 'post_status';
        if (h.includes('menu') && h.includes('order'))           return 'menu_order';
        if (h.includes('product order') || h === 'order')        return 'menu_order';
        if (h.includes('title') || h === 'name')                 return 'post_title';
        if (h.includes('regular') && h.includes('price'))        return '_regular_price';
        if (h.includes('sale') && h.includes('price'))           return '_sale_price';
        if (h.includes('stock') || h.includes('quantity'))       return '_stock';
        if (h.includes('weight'))                                return '_weight';
        if (h.includes('length'))                                return '_length';
        if (h.includes('width') && !h.includes('length'))        return '_width';
        if (h.includes('height'))                                return '_height';
        if (h.includes('categor'))                               return '_product_cats';
        if (h.includes('tag') && !h.includes('stock'))           return '_product_tags';
        if (h.includes('order id') || h.includes('customer') || h.includes('billing')) return '';
        return '';
    }

    // Column index to letter
    function colLetter(idx) {
        let letter = '';
        let n = idx + 1;
        while (n > 0) {
            n--;
            letter = String.fromCharCode(65 + (n % 26)) + letter;
            n = Math.floor(n / 26);
        }
        return letter;
    }

    let sheetHeaders = [];
    let connData = {};

    let savedFieldMap = {};

    $('#ss-import-conn').on('change', function() {
        const connId = $(this).val();
        $('#ss-use-saved-maps').prop('disabled', !connId);
        $('#ss-saved-maps-hint').hide();
        $('#ss-open-sync-tab').hide();
        savedFieldMap = {};
        if (!connId) return;
        $.post(sheetsync.ajax_url, {
            action: 'sheetsync_get_connection_maps',
            nonce: sheetsync.nonce,
            connection_id: connId
        }).done(function(res) {
            if (res.success && res.data) {
                savedFieldMap = res.data.field_map || {};
                if (res.data.has_maps) {
                    $('#ss-saved-maps-hint').html('💡 This connection has saved mapping (' + Object.keys(savedFieldMap).length + ' fields). Click <strong>Use saved connection mapping</strong> after loading headers.').show();
                }
                if (res.data.sync_url) {
                    $('#ss-open-sync-tab').attr('href', res.data.sync_url).show();
                }
            }
        });
    });

    $('#ss-use-saved-maps').on('click', function() {
        if (!sheetHeaders.length) {
            alert('Load headers first (View Import Headers).');
            return;
        }
        buildMappingTable(sheetHeaders);
        $('.ss-field-select').each(function() {
            const $sel = $(this);
            const col  = $sel.data('col');
            Object.keys(savedFieldMap).forEach(function(field) {
                if ( savedFieldMap[ field ] === col ) {
                    $sel.val( field );
                }
            });
        });
        alert('Applied saved mapping from connection. Review dropdowns, then Start Import.');
    });

    // Load headers
    $('#ss-load-headers').on('click', function() {
        const $sel = $('#ss-import-conn');
        const connId = $sel.val();
        if (!connId) {
            // FIX L-2: inline notice instead of alert()
            $('#ss-step-1').find('.ss-inline-err').remove();
            $('#ss-import-conn').after('<p class="ss-inline-err" style="color:#dc2626;margin:4px 0 0;">Please select a connection first.</p>');
            return;
        }

        const opt = $sel.find('option:selected');
        connData = {
            id: connId,
            spreadsheet: opt.data('spreadsheet'),
            sheet: opt.data('sheet'),
            header: opt.data('header')
        };

        $('#ss-header-loader').show();
        $(this).prop('disabled', true);

        $.post(sheetsync.ajax_url, {
            action       : 'sheetsync_get_headers',
            nonce        : sheetsync.nonce,
            connection_id: connId,
        })
        .done(function(res) {
            if (res.success && res.data.headers) {
                sheetHeaders = res.data.headers;
                buildMappingTable(sheetHeaders);
                $('#ss-step-1').hide();
                $('#ss-step-2').show();
            } else {
                // FIX L-2: inline notice
                $('#ss-step-1').find('.ss-inline-err').remove();
                $('#ss-import-conn').after('<p class="ss-inline-err" style="color:#dc2626;margin:4px 0 0;">Failed to load headers: ' + $('<span>').text(res.data?.message || 'Unknown error').html() + '</p>');
            }
        })
        .fail(function() { $('#ss-progress-text').text('Request failed.'); })
        .always(function() {
            $('#ss-header-loader').hide();
            $('#ss-load-headers').prop('disabled', false);
        });
    });

    function buildMappingTable(headers) {
        const $tbody = $('#ss-header-rows').empty();
        headers.forEach(function(header, idx) {
            const col    = colLetter(idx);
            const auto   = autoDetect(header);
            let options  = '';
            $.each(wc_fields, function(val, label) {
                const sel = val === auto ? ' selected' : '';
                options += `<option value="${val}"${sel}>${label}</option>`;
            });
            $tbody.append(`
                <tr>
                    <td><strong>${col}</strong></td>
                    <td>${header || '<em style="color:#9ca3af">empty</em>'}</td>
                    <td><select class="ss-field-select" data-col="${col}" data-idx="${idx}">${options}</select></td>
                </tr>
            `);
        });
    }

    // Back button
    $('#ss-back-step1').on('click', function() {
        $('#ss-step-2').hide();
        $('#ss-step-1').show();
    });

    // Start import (chunked Google reads + SheetSync_Product_Updater for full Pro field parity)
    $('#ss-start-import').on('click', async function() {
        const fieldMap = {};
        let hasSku = false;
        $('.ss-field-select').each(function() {
            const val = $(this).val();
            if (!val) return;
            const col = $(this).data('col');
            fieldMap[val] = col;
            if (val === '_sku') hasSku = true;
        });

        if (!hasSku) {
            $('#ss-step-2 .ss-sku-warning').remove();
            $('#ss-start-import').before('<p class="ss-sku-warning" style="color:#dc2626;font-weight:600;">&#9888; Please map the SKU column — otherwise duplicate products will be created!</p>');
            return;
        }

        let hasProductType = false, hasParentSku = false, hasVariationAttrs = false;
        Object.keys(fieldMap).forEach(function(k) {
            if (k === '_product_type') hasProductType = true;
            if (k === 'parent_sku') hasParentSku = true;
            if (k === 'variation_attrs') hasVariationAttrs = true;
        });
        $('#ss-step-2 .ss-var-warning').remove();
        const hasColor = Object.prototype.hasOwnProperty.call(fieldMap, 'sheet_color');
        const hasSize  = Object.prototype.hasOwnProperty.call(fieldMap, 'sheet_size');
        if (!hasProductType || !hasParentSku || (!hasVariationAttrs && !(hasColor && hasSize))) {
            $('#ss-start-import').before(
                '<p class="ss-var-warning" style="color:#b45309;font-weight:600;">&#9888; Variable products: map <strong>Product Type</strong>, <strong>Parent SKU</strong>, and either <strong>Variation Attributes</strong> (U) or <strong>Color + Size</strong> (V, W).</p>'
            );
        }

        $('#ss-step-2').hide();
        $('#ss-step-3').show();
        $('#ss-progress-bar').css('width', '5%');
        $('#ss-progress-text').text('Starting import...');

        const batchSize = 35;
        let offset = 0;
        let totalCreated = 0, totalUpdated = 0, totalSkipped = 0;
        const $log = $('#ss-import-log').empty();

        async function postImportBatch(payload, attempt) {
            attempt = attempt || 0;
            function isRetryable(msg, data) {
                if (data && data.retryable) {
                    return true;
                }
                if (!msg) {
                    return false;
                }
                msg = String(msg).toLowerCase();
                return /timeout|timed out|connection|503|502|504|could not read google sheet|quota|curl/.test(msg);
            }
            try {
                const res = await $.post(sheetsync.ajax_url, payload);
                if (!res.success && attempt < 2) {
                    const msg = res.data && res.data.message ? res.data.message : '';
                    if (isRetryable(msg, res.data)) {
                        await new Promise(function(r) { setTimeout(r, 1500 * (attempt + 1)); });
                        return postImportBatch(payload, attempt + 1);
                    }
                }
                return res;
            } catch (err) {
                if (attempt < 2) {
                    await new Promise(function(r) { setTimeout(r, 1500 * (attempt + 1)); });
                    return postImportBatch(payload, attempt + 1);
                }
                throw err;
            }
        }

        try {
            let importSessionId = '';
            while (true) {
                const payload = {
                    action         : 'sheetsync_import_from_sheet',
                    nonce          : sheetsync.nonce,
                    connection_id  : connData.id,
                    field_map      : JSON.stringify(fieldMap),
                    skip_existing  : $('#ss-skip-existing').is(':checked') ? 1 : 0,
                    create_new     : $('#ss-create-new').is(':checked') ? 1 : 0,
                    batch_offset   : offset,
                    batch_size     : batchSize
                };
                if (importSessionId) {
                    payload.import_session_id = importSessionId;
                }
                const res = await postImportBatch(payload);

                if (!res.success) {
                    $('#ss-progress-text').text('❌ Error: ' + (res.data && res.data.message ? res.data.message : 'Import failed'));
                    return;
                }

                const d = res.data;
                if (d.import_session_id) {
                    importSessionId = d.import_session_id;
                }
                totalCreated += d.created || 0;
                totalUpdated += d.updated || 0;
                totalSkipped += d.skipped || 0;

                if (d.log && d.log.length) {
                    d.log.forEach(function(line) {
                        const cls = line.type === 'created' ? 'log-ok' : line.type === 'skipped' ? 'log-skip' : 'log-err';
                        $log.append('<div class="' + cls + '">' + line.msg + '</div>');
                    });
                }

                offset = typeof d.next_offset === 'number' ? d.next_offset : offset + batchSize;
                const pct = d.done ? 100 : Math.min(95, 5 + (offset / Math.max(offset, 1)) * 90);
                $('#ss-progress-bar').css('width', pct + '%');
                $('#ss-progress-text').text('Importing… rows processed: ' + offset);

                if (d.done) break;
            }

            $('#ss-progress-bar').css('width', '100%');
            $('#ss-progress-text').text('Done.');
            setTimeout(function() {
                $('#ss-step-3').hide();
                $('#ss-import-summary').html(
                    '✅ <strong>' + totalCreated + '</strong> new product(s) created | ' +
                    '🔄 <strong>' + totalUpdated + '</strong> updated | ' +
                    '⏭️ <strong>' + totalSkipped + '</strong> skipped'
                );
                $('#ss-step-4').show();
            }, 400);
        } catch (e) {
            $('#ss-progress-text').text('❌ Request failed.');
        }
    });

    // Import again
    $('#ss-import-again').on('click', function() {
        $('#ss-step-4').hide();
        $('#ss-step-1').show();
        $('#ss-import-log').empty();
        $('#ss-progress-bar').css('width', '0%');
    });
});
</script>