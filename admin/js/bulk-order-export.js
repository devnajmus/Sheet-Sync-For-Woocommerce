/**
 * SheetSync Bulk Order Export — WooAnalytics PRO UI.
 */
(function ($) {
    'use strict';

    var charts = {};
    var currencySymbol = '$';
    var i18n = window.ssBoeDashI18n || {};
    var loaded = false;
    var chartGrid = 'rgba(255,255,255,0.06)';
    var chartText = '#8891aa';
    var palette = ['#6c63ff', '#22d3a5', '#38bdf8', '#f472b6', '#fbbf24', '#ff5e7a'];

    function fmtMoney(v) {
        var n = parseFloat(v) || 0;
        return currencySymbol + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function fmtNum(v) {
        return (parseInt(v, 10) || 0).toLocaleString();
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

    function boePayload(extra) {
        var statuses = [];
        $('input[name="boe_statuses[]"]:checked').each(function () {
            statuses.push($(this).val());
        });
        return $.extend({
            nonce: (typeof sheetsync !== 'undefined') ? sheetsync.nonce : '',
            statuses: statuses,
            date_from: $('#ss_boe_date_from').val(),
            date_to: $('#ss_boe_date_to').val(),
            customer: $('#ss_boe_customer').val(),
            min_total: $('#ss_boe_min_total').val(),
            max_total: $('#ss_boe_max_total').val(),
            fields: (typeof window.ssBoeSelectedFields === 'function') ? window.ssBoeSelectedFields() : []
        }, extra || {});
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
        return '<div class="ss-wa-kpi fade-up">'
            + '<div class="ss-wa-kpi-head"><div class="ss-wa-kpi-icon" style="background:' + esc(opts.bg) + ';color:' + esc(opts.color) + '">' + opts.icon + '</div></div>'
            + '<div class="ss-wa-kpi-label">' + esc(opts.label) + kpiHelpHtml(opts.helpKey) + '</div>'
            + '<div class="ss-wa-kpi-value">' + opts.value + '</div>'
            + (opts.sub ? '<div class="ss-wa-kpi-sub">' + opts.sub + '</div>' : '')
            + '</div>';
    }

    function renderExportFields(fields) {
        var html = '<div class="ss-wa-chips ss-boe-field-chips">';
        Object.keys(fields || {}).forEach(function (key) {
            html += '<span class="ss-wa-chip">✓ ' + esc(fields[key]) + '</span>';
        });
        return html + '</div>';
    }

    function renderDashboard(data) {
        var html = '<div class="ss-wa-kpi-grid" id="ss-boe-sec-kpi">';
        html += kpiCard({ icon: '🛒', label: i18n.matchingOrders || 'Matching Orders', helpKey: 'boe_matching', value: fmtNum(data.count), bg: 'rgba(108,99,255,.15)', color: '#6c63ff' });
        html += kpiCard({ icon: '💰', label: i18n.totalRevenue || 'Total Revenue', helpKey: 'boe_revenue', value: fmtMoney(data.total_revenue), bg: 'rgba(34,211,165,.15)', color: '#22d3a5' });
        html += kpiCard({ icon: '📊', label: i18n.avgOrder || 'Avg. Order', helpKey: 'boe_avg', value: fmtMoney(data.avg_order), bg: 'rgba(56,189,248,.15)', color: '#38bdf8' });
        html += kpiCard({ icon: '📋', label: i18n.exportFields || 'Export Fields', helpKey: 'boe_fields', value: fmtNum(Object.keys(data.export_fields || {}).length), sub: i18n.columnsIncluded || 'columns included', bg: 'rgba(251,191,36,.15)', color: '#fbbf24' });
        html += '</div>';

        html += '<div class="ss-wa-calc-note fade-up">'
            + '<strong>' + esc(i18n.generatedAt || 'Generated') + ':</strong> ' + esc(data.generated_at || '')
            + ' · ' + esc(i18n.previewNote || 'Apply filters above, then export to CSV or Google Sheets.')
            + '</div>';

        html += '<div class="ss-wa-charts-2">';
        html += '<div class="ss-wa-card fade-up">'
            + '<div class="ss-wa-card-head"><div><div class="ss-wa-card-title">' + esc(i18n.statusBreakdown || 'Status Breakdown') + '</div>'
            + '<div class="ss-wa-card-sub">' + esc(i18n.statusBreakdownSub || 'Orders by status for current filters') + '</div></div></div>'
            + '<div class="ss-wa-chart-wrap sm"><canvas id="ss_boe_chart_status"></canvas></div>'
            + '</div>';

        html += '<div class="ss-wa-card fade-up" id="ss-boe-sec-fields">'
            + '<div class="ss-wa-card-head"><div><div class="ss-wa-card-title">' + esc(i18n.exportIncludes || 'Export Includes') + '</div>'
            + '<div class="ss-wa-card-sub">' + esc(i18n.exportIncludesSub || 'Columns included in CSV and Google Sheets export') + '</div></div></div>'
            + renderExportFields(data.export_fields)
            + '</div></div>';

        html += '<div class="ss-wa-card fade-up" id="ss-boe-sec-preview">'
            + '<div class="ss-wa-card-head"><div><div class="ss-wa-card-title">' + esc(i18n.orderPreview || 'Order Preview') + '</div>'
            + '<div class="ss-wa-card-sub">' + esc(i18n.showingFirst || 'Showing first') + ' ' + Math.min((data.orders || []).length, 25) + ' ' + esc(i18n.ofTotal || 'of') + ' ' + fmtNum(data.count) + '</div></div></div>'
            + '<div class="ss-wa-table-controls"><div class="ss-wa-search-wrap">'
            + '<input type="text" class="ss-wa-search-input" id="ss_boe_order_search" placeholder="' + esc(i18n.searchOrders || 'Search orders, customers…') + '" />'
            + '</div></div>';

        if (data.orders && data.orders.length) {
            var boeCount = data.orders.length;
            html += '<div class="ss-wa-table-wrap' + (window.ssWaTableScroll ? window.ssWaTableScroll.wrapClass(boeCount) : '') + '"><table class="ss-wa-table" id="ss_boe_orders_table"><thead><tr>'
                + '<th>' + esc(i18n.order || 'Order') + '</th><th>' + esc(i18n.customer || 'Customer') + '</th>'
                + '<th>' + esc(i18n.products || 'Products') + '</th><th>' + esc(i18n.total || 'Total') + '</th>'
                + '<th>' + esc(i18n.payment || 'Payment') + '</th><th>' + esc(i18n.status || 'Status') + '</th>'
                + '<th>' + esc(i18n.date || 'Date') + '</th>'
                + '</tr></thead><tbody>';
            data.orders.forEach(function (o) {
                html += '<tr>'
                    + '<td><a class="ss-wa-order-id" href="' + esc(o.edit_url) + '" target="_blank">#' + esc(o.number) + '</a></td>'
                    + '<td><div><strong>' + esc(o.customer) + '</strong><br><small>' + esc(o.email) + '</small></div></td>'
                    + '<td><small>' + esc(o.items) + '</small></td>'
                    + '<td><strong>' + fmtMoney(o.total) + '</strong></td>'
                    + '<td>' + esc(o.payment) + '</td>'
                    + '<td><span class="ss-wa-status ' + esc(o.status_slug) + '">' + esc(o.status) + '</span></td>'
                    + '<td>' + esc(o.date) + '</td>'
                    + '</tr>';
            });
            html += '</tbody></table></div>'
                + (window.ssWaTableScroll ? window.ssWaTableScroll.foot(boeCount, i18n.orders || 'orders') : '')
                + '';
        } else {
            html += '<p class="ss-wa-empty">' + esc(i18n.noOrders || 'No orders match your filters.') + '</p>';
        }
        html += '</div>';

        $('#ss_boe_dashboard_root').html(html);
        window.ssBoeDashData = data;
        buildCharts(data);
    }

    function buildCharts(data) {
        if (typeof Chart === 'undefined') {
            return;
        }
        updateChartThemeColors();
        var breakdown = data.status_breakdown || [];
        var ctx = document.getElementById('ss_boe_chart_status');
        if (!ctx || !breakdown.length) {
            return;
        }

        charts.status = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: breakdown.map(function (s) { return s.label; }),
                datasets: [{
                    data: breakdown.map(function (s) { return s.count; }),
                    backgroundColor: palette.slice(0, breakdown.length),
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: { legend: { position: 'bottom', labels: { color: chartText, boxWidth: 10 } } }
            }
        });
    }

    function showLoading() {
        $('#ss_boe_dashboard_root').html(
            '<div class="ss-wa-loading"><span class="dashicons dashicons-update ss-spin"></span><p>' + esc(i18n.loading) + '</p></div>'
        );
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
        var $main = $('#ss-dash-panel-orders .ss-wa-main');
        if ($main.length && window.innerWidth > 900) {
            var mainEl = $main.get(0);
            var elTop = $el.get(0).getBoundingClientRect().top;
            var mainTop = mainEl.getBoundingClientRect().top;
            $main.animate({ scrollTop: mainEl.scrollTop + (elTop - mainTop) - 72 }, 400);
            return;
        }
        $('html, body').animate({ scrollTop: $el.offset().top - 80 }, 400);
    }

    function loadPreview(force) {
        if (loaded && !force) {
            return;
        }

        var AJAX = (typeof sheetsync !== 'undefined') ? sheetsync.ajax_url : ajaxurl;
        showLoading();
        destroyCharts();

        $.post(AJAX, $.extend({ action: 'sheetsync_bulk_order_preview' }, boePayload()))
            .done(function (r) {
                if (!r.success) {
                    $('#ss_boe_dashboard_root').html('<div class="ss-wa-empty">' + esc((r.data && r.data.message) || i18n.error) + '</div>');
                    return;
                }
                if (r.data.currency_symbol) {
                    currencySymbol = r.data.currency_symbol;
                }
                loaded = true;
                renderDashboard(r.data);
            })
            .fail(function () {
                $('#ss_boe_dashboard_root').html('<div class="ss-wa-empty">' + esc(i18n.requestFailed) + '</div>');
            });
    }

    function resetFilters() {
        $('input[name="boe_statuses[]"]').prop('checked', false);
        ['wc-completed', 'wc-processing', 'wc-on-hold'].forEach(function (st) {
            $('input[name="boe_statuses[]"][value="' + st + '"]').prop('checked', true);
        });
        $('#ss_boe_date_from, #ss_boe_date_to, #ss_boe_customer, #ss_boe_min_total, #ss_boe_max_total').val('');
        loaded = false;
        loadPreview(true);
    }

    $(function () {
        if (!$('#ss_boe_dashboard_root').length) {
            return;
        }

        if (typeof sheetsync !== 'undefined' && sheetsync.currency_symbol) {
            currencySymbol = sheetsync.currency_symbol;
        }

        window.ssBoeDashLoad = function (force) { loadPreview(!!force); };
        window.ssBoeDashRebuildCharts = function () {
            updateChartThemeColors();
            if (window.ssBoeDashData) {
                destroyCharts();
                buildCharts(window.ssBoeDashData);
            }
        };

        $('#ss_boe_sidebar, #ss_boe_overlay').off('click.boeNav');
        $(document).on('click', '#ss_boe_hamburger, #ss_boe_overlay', function () {
            $('#ss_boe_sidebar').toggleClass('open');
            $('#ss_boe_overlay').toggleClass('open');
            $('body').toggleClass('ss-wa-menu-open');
        });

        $(document).on('click', '#ss-dash-panel-orders .ss-wa-nav-item', function (e) {
            e.preventDefault();
            var section = $(this).data('section');
            $('#ss-dash-panel-orders .ss-wa-nav-item').removeClass('active');
            $(this).addClass('active');
            if (window.innerWidth <= 900) {
                $('#ss_boe_sidebar').removeClass('open');
                $('#ss_boe_overlay').removeClass('open');
                $('body').removeClass('ss-wa-menu-open');
            }
            scrollToSection(section);
        });

        $(document).on('click', '#ss_boe_filter_apply', function () {
            loaded = false;
            loadPreview(true);
        });

        $(document).on('click', '#ss_boe_filter_reset', resetFilters);

        $(document).on('input', '#ss_boe_order_search', function () {
            var q = $(this).val().toLowerCase();
            $('#ss_boe_orders_table tbody tr').each(function () {
                $(this).toggle($(this).text().toLowerCase().indexOf(q) >= 0);
            });
        });

        $(document).on('click', '#ss_boe_count_btn', function () {
            var $b = $(this), $res = $('#ss_boe_result');
            var AJAX = (typeof sheetsync !== 'undefined') ? sheetsync.ajax_url : ajaxurl;
            btnLoad($b, true);
            showRes($res, '<span class="dashicons dashicons-update ss-spin"></span> ' + esc(i18n.counting || 'Counting…'), 'loading');
            $.post(AJAX, boePayload({ action: 'sheetsync_bulk_order_count' }))
                .done(function (r) {
                    btnLoad($b, false);
                    if (r.success) {
                        showRes($res, '&#9989; <strong>' + fmtNum(r.data.count) + '</strong> ' + esc(i18n.ordersMatch || 'orders match your filters'), 'success');
                    } else {
                        showRes($res, '&#10060; ' + esc((r.data && r.data.message) || i18n.error), 'error');
                    }
                })
                .fail(function () {
                    btnLoad($b, false);
                    showRes($res, '&#10060; ' + esc(i18n.requestFailed), 'error');
                });
        });

        $(document).on('click', '#ss_boe_csv_btn', function () {
            var $b = $(this), $res = $('#ss_boe_result');
            var AJAX = (typeof sheetsync !== 'undefined') ? sheetsync.ajax_url : ajaxurl;
            btnLoad($b, true);
            showRes($res, '<span class="dashicons dashicons-update ss-spin"></span> ' + esc(i18n.generatingCsv || 'Generating CSV…'), 'loading');
            $.post(AJAX, boePayload({ action: 'sheetsync_bulk_order_export_csv' }))
                .done(function (r) {
                    btnLoad($b, false);
                    if (r.success) {
                        var blob = new Blob([r.data.csv], { type: 'text/csv;charset=utf-8;' });
                        var url = URL.createObjectURL(blob);
                        var a = document.createElement('a');
                        a.href = url;
                        a.download = r.data.filename || 'orders-export.csv';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);
                        showRes($res, '&#9989; ' + esc(i18n.csvDownloaded || 'CSV downloaded!') + ' (' + fmtNum(r.data.order_count) + ' ' + esc(i18n.orders || 'orders') + ')', 'success');
                    } else {
                        showRes($res, '&#10060; ' + esc((r.data && r.data.message) || i18n.exportFailed), 'error');
                    }
                })
                .fail(function () {
                    btnLoad($b, false);
                    showRes($res, '&#10060; ' + esc(i18n.requestFailed), 'error');
                });
        });

        $(document).on('click', '#ss_boe_sheets_btn', function () {
            var $b = $(this), $res = $('#ss_boe_result');
            var AJAX = (typeof sheetsync !== 'undefined') ? sheetsync.ajax_url : ajaxurl;
            var sid = $.trim($('#ss_boe_spreadsheet_id').val());
            if (!sid) {
                showRes($res, '&#10060; ' + esc(i18n.spreadsheetRequired || 'Spreadsheet ID required.'), 'error');
                scrollToSection('ss-boe-sec-export');
                return;
            }
            btnLoad($b, true);
            showRes($res, '<span class="dashicons dashicons-update ss-spin"></span> ' + esc(i18n.exporting || 'Exporting…'), 'loading');
            $.post(AJAX, boePayload({
                action: 'sheetsync_bulk_order_export_sheets',
                spreadsheet_id: sid,
                sheet_name: $('#ss_boe_sheet_name').val() || 'Orders Export'
            }))
                .done(function (r) {
                    btnLoad($b, false);
                    if (r.success) {
                        var extra = (r.data.sheet_url) ? '<br><a href="' + r.data.sheet_url + '" target="_blank" rel="noopener">' + esc(i18n.openSheet || 'Open in Google Sheets') + ' →</a>' : (typeof window.ssDashOpenSheetLink === 'function' ? window.ssDashOpenSheetLink(sid) : '');
                        showRes($res, '&#9989; ' + esc(r.data.message) + '<br><small>' + fmtNum(r.data.order_count) + ' ' + esc(i18n.orders || 'orders') + ' · ' + fmtNum(r.data.rows_written) + ' rows</small>' + extra, 'success');
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
