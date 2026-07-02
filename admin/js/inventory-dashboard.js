/**
 * SheetSync Inventory Dashboard — WooAnalytics PRO UI.
 */
(function ($) {
    'use strict';

    var charts = {};
    var currencySymbol = '$';
    var palette = ['#6c63ff', '#22d3a5', '#38bdf8', '#f472b6', '#fbbf24', '#ff5e7a', '#a78bfa', '#10b981'];
    var i18n = window.ssInvDashI18n || {};
    var loaded = false;
    var chartGrid = 'rgba(255,255,255,0.06)';
    var chartText = '#8891aa';

    function trimCompactDecimals(num, decimals) {
        return Number(num).toFixed(decimals).replace(/\.0$/, '');
    }

    function fmtCompactCore(n) {
        var num = parseFloat(n) || 0;
        var sign = num < 0 ? '-' : '';
        n = Math.abs(num);
        if (n >= 1e9) return sign + trimCompactDecimals(n / 1e9, 1) + 'B';
        if (n >= 1e6) return sign + trimCompactDecimals(n / 1e6, 1) + 'M';
        if (n >= 1e4) return sign + trimCompactDecimals(n / 1e3, 1) + 'K';
        return null;
    }

    function fmtMoney(v, compact) {
        var n = parseFloat(v) || 0;
        if (compact !== false) {
            var compactVal = fmtCompactCore(n);
            if (compactVal) return currencySymbol + compactVal;
        }
        return currencySymbol + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function fmtNum(v, compact) {
        var n = parseInt(v, 10) || 0;
        if (compact !== false) {
            var compactVal = fmtCompactCore(n);
            if (compactVal) return compactVal;
        }
        return n.toLocaleString();
    }

    function kpiMoney(v) {
        var n = parseFloat(v) || 0;
        var full = currencySymbol + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        var compactVal = fmtCompactCore(n);
        return { value: compactVal ? currencySymbol + compactVal : full, title: full, compact: !!compactVal };
    }

    /** Stock value KPI: ৳317.9M / ৳12.5K with full amount on hover. */
    function kpiMoneyStock(v) {
        var n = parseFloat(v) || 0;
        var full = currencySymbol + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        var compactVal = fmtCompactCore(n);
        if (!compactVal) {
            return { html: esc(full), title: full, compact: false, sub: '' };
        }
        var match = String(compactVal).match(/^(-?)([\d.]+)([KMB])$/);
        var numPart = currencySymbol + (match ? match[1] + match[2] : compactVal);
        var html = '<span class="ss-wa-kpi-money-wrap">';
        html += '<span class="ss-wa-kpi-money-num">' + esc(numPart) + '</span>';
        if (match && match[3]) {
            html += '<span class="ss-wa-kpi-unit">' + esc(match[3]) + '</span>';
        }
        html += '</span>';
        return {
            html    : html,
            title   : full,
            compact : true,
            sub     : i18n.hoverForFullAmount || 'Hover for full amount',
        };
    }

    function kpiNum(v) {
        var n = parseInt(v, 10) || 0;
        var full = n.toLocaleString();
        var compactVal = fmtCompactCore(n);
        return { value: compactVal || full, title: full, compact: !!compactVal };
    }

    function esc(str) {
        return $('<div>').text(str || '').html();
    }

    function isLightTheme() {
        return window.ssWaTheme ? window.ssWaTheme.isLight() : $('.sheetsync-wrap.ss-wa-theme').hasClass('ss-wa-theme-light');
    }

    function updateChartThemeColors() {
        if (isLightTheme()) {
            chartGrid = 'rgba(15,23,42,0.08)';
            chartText = '#64748b';
            return;
        }
        chartGrid = 'rgba(255,255,255,0.06)';
        chartText = '#8891aa';
    }

    function destroyCharts() {
        Object.keys(charts).forEach(function (key) {
            if (charts[key]) {
                charts[key].destroy();
                charts[key] = null;
            }
        });
        charts = {};
    }

    function statusLabel(status) {
        var map = {
            instock: i18n.inStock || 'In Stock',
            low_stock: i18n.lowStock || 'Low Stock',
            outofstock: i18n.outOfStock || 'Out of Stock'
        };
        return map[status] || status;
    }

    function statusClass(status) {
        if (status === 'low_stock') return 'pending';
        if (status === 'outofstock') return 'cancelled';
        return 'completed';
    }

    function kpiHelpHtml(key) {
        var tips = window.ssKpiHelp || {};
        if (!key || !tips[key]) {
            return '';
        }
        return '<span class="ss-wa-kpi-help" tabindex="0" role="button" aria-label="Help">'
            + '<span class="ss-wa-kpi-help-icon">?</span>'
            + '<span class="ss-wa-kpi-help-tip">' + esc(tips[key]) + '</span></span>';
    }

    function kpiCard(opts) {
        var valueTitle = opts.valueTitle ? ' title="' + esc(opts.valueTitle) + '"' : '';
        var compactCls = opts.compact ? ' ss-wa-kpi-value--compact' : '';
        var moneyCls   = opts.moneyCompact ? ' ss-wa-kpi-value--money-compact' : '';
        var valueInner = opts.valueHtml != null ? opts.valueHtml : esc(opts.value || '');
        return '<div class="ss-wa-kpi fade-up" id="' + esc(opts.id || '') + '" style="--kpi-color:' + esc(opts.color || 'var(--wa-accent)') + '">'
            + '<div class="ss-wa-kpi-head">'
            + '<div class="ss-wa-kpi-icon" style="background:' + esc(opts.bg) + ';color:' + esc(opts.color) + '">' + opts.icon + '</div>'
            + '</div>'
            + '<div class="ss-wa-kpi-label">' + esc(opts.label) + kpiHelpHtml(opts.helpKey) + '</div>'
            + '<div class="ss-wa-kpi-value' + compactCls + moneyCls + '"' + valueTitle + '>' + valueInner + '</div>'
            + (opts.sub ? '<div class="ss-wa-kpi-sub">' + esc(opts.sub) + '</div>' : '')
            + '</div>';
    }

    function tableScrollClass(n) {
        return window.ssWaTableScroll ? window.ssWaTableScroll.wrapClass(n) : '';
    }

    function tableScrollFoot(n, unit) {
        return window.ssWaTableScroll ? window.ssWaTableScroll.foot(n, unit) : '';
    }

    function renderReorderTable(items) {
        var html = '<div class="ss-wa-card fade-up" id="ss-inv-sec-reorder">'
            + '<div class="ss-wa-card-head"><div><div class="ss-wa-card-title">📋 ' + esc(i18n.reorderSuggestions || 'Reorder Suggestions') + '</div>'
            + '<div class="ss-wa-card-sub">' + esc(i18n.reorderSub || 'Suggested qty based on 30-day sales velocity & stock level') + '</div></div></div>';

        if (!items.length) {
            return html + '<p class="ss-wa-empty">' + esc(i18n.noReorder || 'No reorder suggestions right now.') + '</p></div>';
        }

        html += '<div class="ss-wa-table-wrap' + tableScrollClass(items.length) + '"><table class="ss-wa-table"><thead><tr>'
            + '<th>' + esc(i18n.product || 'Product') + '</th><th>' + esc(i18n.currentStock || 'Current stock') + '</th>'
            + '<th>' + esc(i18n.sold30d || 'Sold (30d)') + '</th><th>' + esc(i18n.suggestedQty || 'Suggested reorder') + '</th>'
            + '</tr></thead><tbody>';

        items.forEach(function (row) {
            var nameCell = row.edit_url && row.edit_url !== '#'
                ? '<a class="ss-wa-order-id" href="' + esc(row.edit_url) + '" target="_blank">' + esc(row.name) + '</a>'
                : esc(row.name);
            html += '<tr><td>' + nameCell + '<br><small><code>' + esc(row.sku || '—') + '</code></small></td>'
                + '<td>' + fmtNum(row.current_stock) + '</td><td>' + fmtNum(row.sold_30d) + '</td>'
                + '<td><strong class="ss-wa-reorder-qty">' + fmtNum(row.suggested_reorder_qty) + '</strong></td></tr>';
        });

        return html + '</tbody></table></div>' + tableScrollFoot(items.length, i18n.product || 'products') + '</div>';
    }

    function renderProductTable(products, emptyMsg) {
        if (!products || !products.length) {
            return '<p class="ss-wa-empty">' + esc(emptyMsg || i18n.noData) + '</p>';
        }

        var html = '<div class="ss-wa-table-wrap' + tableScrollClass(products.length) + '"><table class="ss-wa-table ss-inv-products-table"><thead><tr>'
            + '<th>' + esc(i18n.product || 'Product') + '</th>'
            + '<th>' + esc(i18n.sku || 'SKU') + '</th>'
            + '<th>' + esc(i18n.category || 'Category') + '</th>'
            + '<th>' + esc(i18n.price || 'Price') + '</th>'
            + '<th>' + esc(i18n.qty || 'Qty') + '</th>'
            + '<th>' + esc(i18n.status || 'Status') + '</th>'
            + '</tr></thead><tbody>';

        products.forEach(function (p) {
            var nameCell = p.edit_url
                ? '<a class="ss-wa-order-id" href="' + esc(p.edit_url) + '" target="_blank">' + esc(p.name) + '</a>'
                : esc(p.name);
            html += '<tr>'
                + '<td>' + nameCell + '</td>'
                + '<td><code>' + esc(p.sku || '—') + '</code></td>'
                + '<td><small>' + esc(p.category || '—') + '</small></td>'
                + '<td><strong>' + fmtMoney(p.price) + '</strong></td>'
                + '<td>' + esc(String(p.stock_qty)) + '</td>'
                + '<td><span class="ss-wa-status ' + esc(statusClass(p.status)) + '">' + esc(statusLabel(p.status)) + '</span></td>'
                + '</tr>';
        });

        return html + '</tbody></table></div>' + tableScrollFoot(products.length, i18n.product || 'products');
    }

    function renderVariationTable(items, meta) {
        meta = meta || {};
        var html = '<div class="ss-wa-card fade-up" id="ss-inv-sec-variations">'
            + '<div class="ss-wa-card-head"><div><div class="ss-wa-card-title">🎨 ' + esc(i18n.variationInventory || 'Variation Inventory') + '</div>'
            + kpiHelpHtml('variations')
            + '<div class="ss-wa-card-sub">' + esc(i18n.variationInventorySub || 'Per-variation stock levels for variable products') + '</div></div></div>';

        if (meta.truncated) {
            html += '<div class="ss-wa-calc-note"><strong>' + esc(i18n.variationTruncated || 'Showing first') + ' ' + fmtNum(meta.max_rows || items.length) + '</strong> '
                + esc(i18n.variationTruncatedSub || 'variations. Use filters or export for the full catalog.') + '</div>';
        }

        if (!items || !items.length) {
            return html + '<p class="ss-wa-empty">' + esc(i18n.noVariations || 'No product variations found.') + '</p></div>';
        }

        html += '<div class="ss-wa-table-controls"><div class="ss-wa-search-wrap">'
            + '<input type="text" class="ss-wa-search-input" id="ss_inv_variation_search" placeholder="' + esc(i18n.searchVariations || 'Search variation, SKU, parent…') + '" />'
            + '</div></div>';
        html += '<div class="ss-wa-table-wrap' + tableScrollClass(items.length) + '"><table class="ss-wa-table ss-inv-variations-table"><thead><tr>'
            + '<th>' + esc(i18n.parentProduct || 'Parent') + '</th>'
            + '<th>' + esc(i18n.attributes || 'Attributes') + '</th>'
            + '<th>' + esc(i18n.sku || 'SKU') + '</th>'
            + '<th>' + esc(i18n.qty || 'Qty') + '</th>'
            + '<th>' + esc(i18n.cogs || 'COGS') + '</th>'
            + '<th>' + esc(i18n.status || 'Status') + '</th>'
            + '</tr></thead><tbody>';

        items.forEach(function (v) {
            var nameCell = v.edit_url && v.edit_url !== '#'
                ? '<a class="ss-wa-order-id" href="' + esc(v.edit_url) + '" target="_blank">' + esc(v.parent_name || v.name) + '</a>'
                : esc(v.parent_name || v.name);
            html += '<tr><td>' + nameCell + '</td><td><small>' + esc(v.attributes || '—') + '</small></td>'
                + '<td><code>' + esc(v.sku || '—') + '</code></td><td>' + esc(String(v.stock_qty)) + '</td>'
                + '<td>' + (v.cogs > 0 ? fmtMoney(v.cogs) : '—') + '</td>'
                + '<td><span class="ss-wa-status ' + esc(statusClass(v.status)) + '">' + esc(statusLabel(v.status)) + '</span></td></tr>';
        });

        return html + '</tbody></table></div>' + tableScrollFoot(items.length, i18n.variationInventory || 'variations') + '</div>';
    }

    function renderDashboard(data) {
        var s = data.summary || {};
        var threshold = data.low_stock_threshold || parseInt($('#ss_inv_low_stock').val(), 10) || 5;

        var kStockVal = kpiMoneyStock(s.total_stock_value);
        var kTotal = kpiNum(s.total_products);
        var html = '<div class="ss-wa-kpi-grid" id="ss-inv-sec-kpi">';
        html += kpiCard({ id: 'ss-inv-kpi-total', icon: '📦', label: i18n.totalProducts || 'Total Products', helpKey: 'inv_total', value: kTotal.value, valueTitle: kTotal.title, compact: kTotal.compact, bg: 'rgba(56,189,248,.15)', color: '#38bdf8' });
        html += kpiCard({ id: 'ss-inv-kpi-in', icon: '✅', label: i18n.inStock || 'In Stock', helpKey: 'inv_instock', value: fmtNum(s.in_stock), bg: 'rgba(34,211,165,.15)', color: '#22d3a5' });
        html += kpiCard({ id: 'ss-inv-kpi-low', icon: '⚠️', label: i18n.lowStock || 'Low Stock', helpKey: 'inv_low', value: fmtNum(s.low_stock), sub: '≤ ' + threshold, bg: 'rgba(255,159,67,.15)', color: '#ff9f43' });
        html += kpiCard({ id: 'ss-inv-kpi-out', icon: '🚫', label: i18n.outOfStock || 'Out of Stock', helpKey: 'inv_out', value: fmtNum(s.out_of_stock), bg: 'rgba(255,94,122,.15)', color: '#ff5e7a' });
        html += kpiCard({ id: 'ss-inv-kpi-value', icon: '💰', label: i18n.stockValue || 'Est. Stock Value', helpKey: 'inv_value', valueHtml: kStockVal.html, valueTitle: kStockVal.title, compact: kStockVal.compact, moneyCompact: true, sub: kStockVal.sub, bg: 'rgba(108,99,255,.15)', color: '#6c63ff' });
        html += '</div>';

        html += '<div class="ss-wa-calc-note fade-up">'
            + '<strong>' + esc(i18n.generatedAt || 'Generated') + ':</strong> ' + esc(data.generated_at || '')
            + ' · ' + esc(i18n.thresholdNote || 'Low stock threshold') + ': <strong>' + threshold + '</strong>'
            + ' · ' + esc(i18n.stockValueFormula || 'Est. Stock Value = sum of (active price × stock qty); variable products include all variations.')
            + '</div>';

        if (data.products_meta && data.products_meta.truncated) {
            var pm = data.products_meta;
            html += '<div class="ss-wa-calc-note fade-up"><strong>'
                + esc(i18n.productsTruncated || 'Showing first')
                + ' ' + fmtNum(pm.max_products || pm.products_loaded || 0)
                + '</strong> '
                + esc(i18n.productsTruncatedSub || 'products. Use export for the full catalog.')
                + '</div>';
        }

        html += '<div class="ss-wa-charts-2">';
        html += '<div class="ss-wa-card fade-up" id="ss-inv-sec-status">'
            + '<div class="ss-wa-card-head"><div><div class="ss-wa-card-title">' + esc(i18n.stockStatus || 'Stock Status') + '</div>'
            + '<div class="ss-wa-card-sub">' + esc(i18n.stockStatusSub || 'In stock vs low vs out of stock') + '</div></div></div>'
            + '<div class="ss-wa-split"><div class="ss-wa-chart-wrap sm"><canvas id="ss_inv_chart_status"></canvas></div>'
            + '<div class="ss-wa-inv-grid" style="grid-template-columns:1fr;">'
            + '<div class="ss-wa-inv-stat"><div class="ss-wa-inv-val green">' + fmtNum(s.in_stock) + '</div><div class="ss-wa-inv-label">' + esc(i18n.inStock) + '</div></div>'
            + '<div class="ss-wa-inv-stat"><div class="ss-wa-inv-val orange">' + fmtNum(s.low_stock) + '</div><div class="ss-wa-inv-label">' + esc(i18n.lowStock) + '</div></div>'
            + '<div class="ss-wa-inv-stat"><div class="ss-wa-inv-val red">' + fmtNum(s.out_of_stock) + '</div><div class="ss-wa-inv-label">' + esc(i18n.outOfStock) + '</div></div>'
            + '</div></div></div>';

        html += '<div class="ss-wa-card fade-up" id="ss-inv-sec-categories">'
            + '<div class="ss-wa-card-head"><div><div class="ss-wa-card-title">' + esc(i18n.categoryBreakdown || 'Category Breakdown') + '</div>'
            + '<div class="ss-wa-card-sub">' + esc(i18n.categoryBreakdownSub || 'Products per category by stock status') + '</div></div></div>'
            + '<div class="ss-wa-chart-wrap"><canvas id="ss_inv_chart_categories"></canvas></div>'
            + '</div></div>';

        html += renderReorderTable(data.reorder_suggestions || []);

        html += renderVariationTable(data.variation_inventory || [], data.variation_inventory_meta || {});

        html += '<div class="ss-wa-card fade-up" id="ss-inv-sec-low">'
            + '<div class="ss-wa-card-head"><div><div class="ss-wa-card-title">⚠️ ' + esc(i18n.lowStockAlerts || 'Low Stock Alerts') + '</div>'
            + '<div class="ss-wa-card-sub">' + fmtNum((data.low_stock || []).length) + ' ' + esc(i18n.productsNeedAttention || 'products need attention') + '</div></div></div>'
            + renderProductTable(data.low_stock, i18n.noLowStock || 'No low stock products.')
            + '</div>';

        html += '<div class="ss-wa-card fade-up" id="ss-inv-sec-out">'
            + '<div class="ss-wa-card-head"><div><div class="ss-wa-card-title">🚫 ' + esc(i18n.outOfStockItems || 'Out of Stock Items') + '</div>'
            + '<div class="ss-wa-card-sub">' + fmtNum((data.out_of_stock || []).length) + ' ' + esc(i18n.productsUnavailable || 'products unavailable') + '</div></div></div>'
            + renderProductTable(data.out_of_stock, i18n.noOutOfStock || 'No out-of-stock products.')
            + '</div>';

        html += '<div class="ss-wa-card fade-up" id="ss-inv-sec-products">'
            + '<div class="ss-wa-card-head"><div><div class="ss-wa-card-title">' + esc(i18n.allProducts || 'All Products') + '</div>'
            + '<div class="ss-wa-card-sub">' + fmtNum(s.total_products) + ' ' + esc(i18n.totalInCatalog || 'total in catalog') + '</div></div></div>'
            + '<div class="ss-wa-table-controls"><div class="ss-wa-search-wrap">'
            + '<input type="text" class="ss-wa-search-input" id="ss_inv_product_search" placeholder="' + esc(i18n.searchProducts || 'Search products, SKU, category…') + '" />'
            + '</div></div>'
            + renderProductTable(data.all_products, i18n.noData)
            + '</div>';

        $('#ss_inventory_dashboard_root').html(html);
        window.ssInvDashData = data;
        updateSidebarBadges(s);
        buildCharts(data);
    }

    function updateSidebarBadges(summary) {
        var low = parseInt(summary.low_stock, 10) || 0;
        var out = parseInt(summary.out_of_stock, 10) || 0;
        var $low = $('#ss_inv_low_badge');
        var $out = $('#ss_inv_out_badge');
        if (low > 0) {
            $low.text(low).show();
        } else {
            $low.hide();
        }
        if (out > 0) {
            $out.text(out).show();
        } else {
            $out.hide();
        }
    }

    function buildCharts(data) {
        if (typeof Chart === 'undefined') {
            return;
        }

        updateChartThemeColors();
        var s = data.summary || {};

        var statusCtx = document.getElementById('ss_inv_chart_status');
        if (statusCtx) {
            charts.status = new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: [i18n.inStock || 'In Stock', i18n.lowStock || 'Low Stock', i18n.outOfStock || 'Out of Stock'],
                    datasets: [{
                        data: [s.in_stock || 0, s.low_stock || 0, s.out_of_stock || 0],
                        backgroundColor: ['#22d3a5', '#ff9f43', '#ff5e7a'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '68%',
                    plugins: { legend: { position: 'bottom', labels: { color: chartText, boxWidth: 10 } } }
                }
            });
        }

        var catCtx = document.getElementById('ss_inv_chart_categories');
        var cats = (data.categories || []).slice(0, 8);
        if (catCtx && cats.length) {
            charts.categories = new Chart(catCtx, {
                type: 'bar',
                data: {
                    labels: cats.map(function (c) { return c.name; }),
                    datasets: [
                        { label: i18n.inStock || 'In Stock', data: cats.map(function (c) { return c.in_stock; }), backgroundColor: '#22d3a5', borderRadius: 4 },
                        { label: i18n.lowStock || 'Low Stock', data: cats.map(function (c) { return c.low_stock; }), backgroundColor: '#ff9f43', borderRadius: 4 },
                        { label: i18n.outOfStock || 'Out of Stock', data: cats.map(function (c) { return c.out_of_stock; }), backgroundColor: '#ff5e7a', borderRadius: 4 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { labels: { color: chartText } } },
                    scales: {
                        x: { stacked: true, grid: { display: false }, ticks: { color: chartText, maxRotation: 45 } },
                        y: { stacked: true, grid: { color: chartGrid }, ticks: { color: chartText, precision: 0 } }
                    }
                }
            });
        }
    }

    function showLoading() {
        $('#ss_inventory_dashboard_root').html(
            '<div class="ss-wa-loading"><span class="dashicons dashicons-update ss-spin"></span><p>' + esc(i18n.loading) + '</p></div>'
        );
    }

    function getThreshold() {
        return parseInt($('#ss_inv_low_stock').val(), 10) || 5;
    }

    function loadInventory(force) {
        if (loaded && !force) {
            return;
        }

        var AJAX = (typeof sheetsync !== 'undefined') ? sheetsync.ajax_url : ajaxurl;
        var NONCE = (typeof sheetsync !== 'undefined') ? sheetsync.nonce : '';

        showLoading();
        destroyCharts();

        $.post(AJAX, {
            action: 'sheetsync_get_inventory_preview',
            nonce: NONCE,
            low_stock_threshold: getThreshold()
        })
            .done(function (r) {
                if (!r.success) {
                    $('#ss_inventory_dashboard_root').html('<div class="ss-wa-empty">' + esc((r.data && r.data.message) || i18n.error) + '</div>');
                    return;
                }
                if (r.data.currency_symbol) {
                    currencySymbol = r.data.currency_symbol;
                }
                loaded = true;
                renderDashboard(r.data);
                if (typeof window.ssApplyDashboardBranding === 'function') {
                    window.ssApplyDashboardBranding(r.data.branding || null);
                }
            })
            .fail(function () {
                $('#ss_inventory_dashboard_root').html('<div class="ss-wa-empty">' + esc(i18n.requestFailed) + '</div>');
            });
    }

    function showRes($el, html, type) {
        $el.attr('class', 'ss-dash-result ss-res-' + type).html(html).show();
    }

    function btnLoad($b, on) {
        if (on) {
            $b.data('oh', $b.html()).prop('disabled', true);
        } else {
            $b.prop('disabled', false).html($b.data('oh') || $b.html());
        }
    }

    function scrollToSection(id) {
        var $el = $('#' + id);
        if (!$el.length) {
            return;
        }
        var $main = $('#ss-dash-panel-inventory .ss-wa-main');
        if ($main.length && window.innerWidth > 900) {
            var mainEl = $main.get(0);
            var elTop = $el.get(0).getBoundingClientRect().top;
            var mainTop = mainEl.getBoundingClientRect().top;
            $main.animate({ scrollTop: mainEl.scrollTop + (elTop - mainTop) - 72 }, 400);
            return;
        }
        $('html, body').animate({ scrollTop: $el.offset().top - 80 }, 400);
    }

    function initMobileNav() {
        var $panel = $('#ss-dash-panel-inventory');
        $panel.off('click.invNav', '#ss_inv_hamburger, #ss_inv_overlay');
        $panel.on('click.invNav', '#ss_inv_hamburger, #ss_inv_overlay', function () {
            $('#ss_inv_sidebar').toggleClass('open');
            $('#ss_inv_overlay').toggleClass('open');
            $('body').toggleClass('ss-wa-menu-open');
        });
    }

    $(function () {
        if (!$('#ss_inventory_dashboard_root').length) {
            return;
        }

        if (typeof sheetsync !== 'undefined' && sheetsync.currency_symbol) {
            currencySymbol = sheetsync.currency_symbol;
        }

        updateChartThemeColors();
        initMobileNav();
        window.ssInvDashLoad = function (force) { loadInventory(!!force); };
        window.ssInvDashRebuildCharts = function () {
            updateChartThemeColors();
            if (window.ssInvDashData) {
                destroyCharts();
                buildCharts(window.ssInvDashData);
            }
        };

        $(document).on('click', '#ss_inv_refresh_btn, #ss_inv_apply_threshold', function () {
            var $b = $(this);
            btnLoad($b, true);
            loaded = false;
            loadInventory(true);
            setTimeout(function () { btnLoad($b, false); }, 600);
        });

        $(document).on('click', '#ss-dash-panel-inventory .ss-wa-nav-item', function (e) {
            e.preventDefault();
            var section = $(this).data('section');
            $('#ss-dash-panel-inventory .ss-wa-nav-item').removeClass('active');
            $(this).addClass('active');
            if (window.innerWidth <= 900) {
                $('#ss_inv_sidebar').removeClass('open');
                $('#ss_inv_overlay').removeClass('open');
                $('body').removeClass('ss-wa-menu-open');
            }
            scrollToSection(section);
        });

        $(document).on('input', '#ss_inv_product_search', function () {
            var q = $(this).val().toLowerCase();
            $('#ss-inv-sec-products .ss-inv-products-table tbody tr').each(function () {
                $(this).toggle($(this).text().toLowerCase().indexOf(q) >= 0);
            });
        });

        $(document).on('input', '#ss_inv_variation_search', function () {
            var q = $(this).val().toLowerCase();
            $('.ss-inv-variations-table tbody tr').each(function () {
                $(this).toggle($(this).text().toLowerCase().indexOf(q) >= 0);
            });
        });

        $(document).on('click', '#ss_inv_export_btn', function () {
            var $b = $(this), $res = $('#ss_inv_result');
            if ($('#ss_demo_mode_toggle').is(':checked')) {
                showRes($res, '&#10060; ' + esc(i18n.demoExportBlocked || 'Turn off Demo Mode to export live store data.'), 'error');
                return;
            }
            var AJAX = (typeof sheetsync !== 'undefined') ? sheetsync.ajax_url : ajaxurl;
            var NONCE = (typeof sheetsync !== 'undefined') ? sheetsync.nonce : '';
            var sid = $.trim($('#ss_inv_spreadsheet_id').val());
            if (!sid) {
                showRes($res, '&#10060; ' + (i18n.spreadsheetRequired || 'Spreadsheet ID required.'), 'error');
                scrollToSection('ss-inv-sec-export');
                return;
            }
            btnLoad($b, true);
            showRes($res, '<span class="dashicons dashicons-update ss-spin"></span> ' + (i18n.exporting || 'Exporting…'), 'loading');
            $.post(AJAX, {
                action: 'sheetsync_export_inventory_dashboard',
                nonce: NONCE,
                spreadsheet_id: sid,
                sheet_name: $('#ss_inv_sheet_name').val() || 'Inventory Status',
                low_stock_threshold: getThreshold()
            })
                .done(function (r) {
                    btnLoad($b, false);
                    if (r.success) {
                        var extra = r.data.sheet_url ? '<br><a href="' + r.data.sheet_url + '" target="_blank" rel="noopener">' + esc(i18n.openSheet || 'Open in Google Sheets') + ' →</a>' : '';
                        showRes($res, '&#9989; ' + esc(r.data.message) + '<br><small>' + fmtNum(r.data.total) + ' products · ⚠ ' + fmtNum(r.data.low_stock) + ' low · ✗ ' + fmtNum(r.data.out_of_stock) + ' out</small>' + extra, 'success');
                    } else {
                        showRes($res, '&#10060; ' + esc((r.data && r.data.message) || i18n.exportFailed), 'error');
                    }
                })
                .fail(function () {
                    btnLoad($b, false);
                    showRes($res, '&#10060; ' + esc(i18n.requestFailed), 'error');
                });
        });
    });
})(jQuery);
