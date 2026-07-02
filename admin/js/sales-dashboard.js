/**
 * SheetSync Sales Dashboard — WooAnalytics PRO dark enterprise UI.
 */
(function ($) {
    'use strict';

    var charts = {};
    var currencySymbol = '$';
    var palette = ['#6c63ff', '#22d3a5', '#38bdf8', '#f472b6', '#fbbf24', '#a78bfa', '#f97316', '#10b981'];
    var avatarColors = ['#6c63ff', '#22d3a5', '#38bdf8', '#f472b6', '#fbbf24', '#a78bfa', '#f97316'];
    var i18n = window.ssSalesDashI18n || {};
    var THEME_STORAGE_KEY = 'ss_wa_dashboard_theme';
    var SESSION_CACHE_KEY = 'ss_sd_dashboard_cache';
    var SESSION_CACHE_MAX_AGE_MS = 10 * 60 * 1000;
    var dashboardXhr = null;
    var dashboardLoadSeq = 0;
    var dashboardDisplayedFingerprint = '';

    var chartGrid = 'rgba(255,255,255,0.06)';
    var chartText = '#8891aa';
    var TABLE_SCROLL_VISIBLE = 6;

    /** Scrollable table wrapper — default rows visible, rest scroll. Search stays above. */
    window.ssWaTableScroll = {
        visible: TABLE_SCROLL_VISIBLE,
        wrapClass: function (rowCount) {
            return rowCount > TABLE_SCROLL_VISIBLE ? ' ss-wa-table-scroll' : '';
        },
        foot: function (rowCount, unitLabel) {
            if (rowCount <= TABLE_SCROLL_VISIBLE) {
                return '';
            }
            var hint = i18n.tableScrollHint || 'Showing {total} {unit} — scroll for more';
            return '<div class="ss-wa-table-scroll-foot">' + esc(hint.replace('{total}', fmtNum(rowCount)).replace('{unit}', unitLabel || '')) + '</div>';
        }
    };

    function tableScrollClass(n) {
        return window.ssWaTableScroll.wrapClass(n);
    }

    function tableScrollFoot(n, unit) {
        return window.ssWaTableScroll.foot(n, unit);
    }

    function isLightTheme() {
        return $('.sheetsync-wrap.ss-wa-theme').hasClass('ss-wa-theme-light');
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

    function updateThemeToggleUi(theme) {
        var light = theme === 'light';
        $('.ss-wa-theme-toggle').each(function () {
            var $btn = $(this);
            $btn.toggleClass('is-light', light);
            $btn.attr('title', light ? (i18n.darkMode || 'Switch to dark mode') : (i18n.lightMode || 'Switch to light mode'));
        });
    }

    function refreshThemeVisuals() {
        var $map = $('.ss-wa-world-svg');
        if ($map.length) {
            $map.html(renderWorldMapSvg());
        }
    }

    function destroySparklines() {
        if (typeof Chart === 'undefined') {
            return;
        }
        $('.ss-wa-sparkline').each(function () {
            var inst = Chart.getChart(this);
            if (inst) {
                inst.destroy();
            }
        });
    }

    function applyDashboardTheme(theme, rebuildCharts) {
        var $wrap = $('.sheetsync-wrap.ss-wa-theme');
        if (!$wrap.length) {
            return;
        }

        if (theme === 'light') {
            $wrap.addClass('ss-wa-theme-light');
        } else {
            $wrap.removeClass('ss-wa-theme-light');
            theme = 'dark';
        }

        try {
            localStorage.setItem(THEME_STORAGE_KEY, theme);
        } catch (e) {
            /* ignore */
        }

        updateThemeToggleUi(theme);
        updateChartThemeColors();
        refreshThemeVisuals();

        if (rebuildCharts && window.ssSalesDashData) {
            destroyCharts();
            destroySparklines();
            buildCharts(window.ssSalesDashData);
            buildSparklines(window.ssSalesDashData.sparklines || {});
        }

        if (typeof window.ssInvDashRebuildCharts === 'function') {
            window.ssInvDashRebuildCharts();
        }

        if (typeof window.ssBoeDashRebuildCharts === 'function') {
            window.ssBoeDashRebuildCharts();
        }
    }

    function initDashboardTheme() {
        var saved = 'dark';
        try {
            saved = localStorage.getItem(THEME_STORAGE_KEY) || 'dark';
        } catch (e) {
            saved = 'dark';
        }
        applyDashboardTheme(saved === 'light' ? 'light' : 'dark', false);
    }

    function toggleDashboardTheme() {
        applyDashboardTheme(isLightTheme() ? 'dark' : 'light', true);
    }

    function trimCompactDecimals(num, decimals) {
        return Number(num).toFixed(decimals).replace(/\.0$/, '');
    }

    function fmtCompactCore(n, opts) {
        opts = opts || {};
        n = parseFloat(n) || 0;
        var sign = n < 0 ? '-' : '';
        n = Math.abs(n);
        var d = opts.decimals !== undefined ? opts.decimals : 1;
        if (n >= 1e9) {
            return sign + trimCompactDecimals(n / 1e9, d) + 'B';
        }
        if (n >= 1e6) {
            return sign + trimCompactDecimals(n / 1e6, d) + 'M';
        }
        if (n >= 1e4) {
            return sign + trimCompactDecimals(n / 1e3, d) + 'K';
        }
        return null;
    }

    function fmtMoney(v, compact) {
        var n = parseFloat(v) || 0;
        if (compact !== false) {
            var compactVal = fmtCompactCore(n, { money: true });
            if (compactVal) {
                return currencySymbol + compactVal;
            }
        }
        return currencySymbol + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function fmtNum(v, compact) {
        var n = parseInt(v, 10) || 0;
        if (compact !== false) {
            var compactVal = fmtCompactCore(n, {});
            if (compactVal) {
                return compactVal;
            }
        }
        return n.toLocaleString();
    }

    function kpiMoney(v) {
        var n = parseFloat(v) || 0;
        var full = currencySymbol + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        var compactVal = fmtCompactCore(n, { money: true });
        var display = compactVal ? currencySymbol + compactVal : full;
        var match = compactVal ? String(compactVal).match(/^(-?)([\d.]+)([KMB])$/) : null;
        var html = '<span class="ss-wa-kpi-money-wrap">';
        if (match) {
            html += '<span class="ss-wa-kpi-money-num">' + esc(currencySymbol + match[1] + match[2]) + '</span>';
            html += '<span class="ss-wa-kpi-money-unit">' + esc(match[3]) + '</span>';
        } else {
            html += '<span class="ss-wa-kpi-money-num">' + esc(display) + '</span>';
        }
        html += '</span>';
        return {
            value: display,
            html: html,
            title: full,
            compact: !!compactVal
        };
    }

    function kpiNum(v) {
        var n = parseInt(v, 10) || 0;
        var full = n.toLocaleString();
        var compactVal = fmtCompactCore(n, {});
        return {
            value: compactVal || full,
            title: full,
            compact: !!compactVal
        };
    }

    function fmtPct(v) {
        var n = parseFloat(v) || 0;
        return (n >= 0 ? '+' : '') + n.toFixed(1) + '%';
    }

    function esc(str) {
        return $('<div>').text(str || '').html();
    }

    function isDemoMode() {
        return $('#ss_demo_mode_toggle').is(':checked');
    }

    function demoExportBlocked($res) {
        if (!isDemoMode()) {
            return false;
        }
        var msg = i18n.demoExportBlocked || 'Turn off Demo Mode to export live store data.';
        if ($res && $res.length) {
            showRes($res, '&#10060; ' + msg, 'error');
        } else {
            alert(msg);
        }
        return true;
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

    function trendBadge(comp) {
        if (!comp || typeof comp.change === 'undefined') {
            return '';
        }
        var cls = comp.trend === 'up' ? 'up' : 'down';
        var icon = comp.trend === 'up' ? '▲' : '▼';
        return '<div class="ss-wa-kpi-badge ' + cls + '">' + icon + ' ' + fmtPct(comp.change) + '</div>';
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
        var valueInner = opts.valueHtml || opts.value;
        return '<div class="ss-wa-kpi fade-up" style="--kpi-color:' + opts.color + '">'
            + '<div class="ss-wa-kpi-head">'
            + '<div class="ss-wa-kpi-icon" style="background:' + opts.bg + ';color:' + opts.color + '">' + opts.icon + '</div>'
            + (opts.trend || '')
            + '</div>'
            + '<div class="ss-wa-kpi-label">' + esc(opts.label) + kpiHelpHtml(opts.helpKey) + '</div>'
            + '<div class="ss-wa-kpi-body">'
            + '<div class="ss-wa-kpi-value' + compactCls + '"' + valueTitle + '>' + valueInner + '</div>'
            + '<div class="ss-wa-spark-wrap"><canvas class="ss-wa-sparkline" data-series="' + esc(opts.sparkKey || 'revenue') + '"></canvas></div>'
            + '</div>'
            + '<div class="ss-wa-kpi-sub">' + esc(i18n.vsPrev || 'vs') + ' <span' + (opts.prevTitle ? ' title="' + esc(opts.prevTitle) + '"' : '') + '>' + (opts.prev || '—') + '</span> ' + esc(i18n.prevPeriod || 'prev period') + '</div>'
            + '</div>';
    }

    function payLegend(items) {
        if (!items || !items.length) {
            return '<p class="ss-wa-empty">' + esc(i18n.noData) + '</p>';
        }
        var html = '<div class="ss-wa-pay-list">';
        items.forEach(function (item, idx) {
            html += '<div class="ss-wa-pay-item">'
                + '<div class="ss-wa-pay-dot" style="background:' + palette[idx % palette.length] + '"></div>'
                + '<div class="ss-wa-pay-info">'
                + '<div class="ss-wa-pay-name">' + esc(item.name) + '</div>'
                + '<div class="ss-wa-pay-detail">' + fmtMoney(item.revenue) + ' · ' + fmtNum(item.orders || 0) + ' ' + esc(i18n.orders || 'orders')
                + (item.quantity != null && item.quantity !== item.orders ? ' · ' + fmtNum(item.quantity) + ' ' + esc(i18n.itemsSold || 'items') : '')
                + '</div>'
                + '</div>'
                + '<div class="ss-wa-pay-pct">' + (item.share || 0) + '%</div>'
                + '</div>';
        });
        return html + '</div>';
    }

    function renderFunnel(steps) {
        if (!steps || !steps.length) {
            return '<p class="ss-wa-empty">' + esc(i18n.noData) + '</p>';
        }
        var html = '<div class="ss-wa-funnel">';
        steps.forEach(function (step, i) {
            var width = Math.max(35, step.pct);
            html += '<div class="ss-wa-funnel-step">'
                + '<div class="ss-wa-funnel-bar" style="width:' + width + '%;background:linear-gradient(90deg,' + step.color + ',rgba(108,99,255,.3))">'
                + '<span class="ss-wa-funnel-label">' + esc(step.label) + '</span>'
                + '<span class="ss-wa-funnel-pct">' + step.pct + '% · ' + fmtNum(step.value) + '</span>'
                + '</div>';
            if (i < steps.length - 1) {
                html += '<div class="ss-wa-funnel-drop">▼</div>';
            }
            html += '</div>';
        });
        return html + '</div>';
    }

    function countryFlag(code) {
        if (!code || code.length !== 2 || code === 'XX') {
            return '🌍';
        }
        return String.fromCodePoint.apply(null, code.toUpperCase().split('').map(function (c) {
            return 127397 + c.charCodeAt(0);
        }));
    }

    function renderWorldMapSvg() {
        var light = isLightTheme();
        var svg = '<rect width="1000" height="500" fill="' + (light ? '#eef2f9' : '#12182a') + '" rx="12"/>';
        var grid = light ? 'rgba(15,23,42,.08)' : 'rgba(255,255,255,.06)';
        var meridian = light ? 'rgba(108,99,255,.22)' : 'rgba(108,99,255,.18)';
        var equator = light ? 'rgba(34,211,165,.22)' : 'rgba(34,211,165,.18)';
        var i;

        for (i = -150; i <= 150; i += 30) {
            svg += '<line x1="' + ((i + 180) / 360 * 1000) + '" y1="0" x2="' + ((i + 180) / 360 * 1000) + '" y2="500" stroke="' + grid + '" stroke-width="1"/>';
        }
        for (i = -60; i <= 60; i += 30) {
            svg += '<line x1="0" y1="' + ((90 - i) / 180 * 500) + '" x2="1000" y2="' + ((90 - i) / 180 * 500) + '" stroke="' + grid + '" stroke-width="1"/>';
        }

        svg += '<line x1="500" y1="0" x2="500" y2="500" stroke="' + meridian + '" stroke-width="1.2"/>';
        svg += '<line x1="0" y1="250" x2="1000" y2="250" stroke="' + equator + '" stroke-width="1.2"/>';

        var land = light ? 'rgba(108,99,255,.12)' : 'rgba(108,99,255,.16)';
        var land2 = light ? 'rgba(34,211,165,.10)' : 'rgba(34,211,165,.12)';

        svg += '<path d="M120,95 L220,75 L280,110 L300,170 L260,230 L190,250 L130,210 L95,150 Z" fill="' + land + '"/>';
        svg += '<path d="M150,260 L220,245 L250,320 L230,390 L170,410 L130,340 Z" fill="' + land2 + '"/>';
        svg += '<path d="M430,85 L520,70 L540,120 L510,160 L450,155 L420,115 Z" fill="' + land + '"/>';
        svg += '<path d="M420,170 L500,160 L530,250 L500,340 L430,330 L400,240 Z" fill="' + land2 + '"/>';
        svg += '<path d="M470,55 L760,45 L820,120 L790,210 L650,200 L520,150 L470,95 Z" fill="' + land + '"/>';
        svg += '<path d="M640,220 L760,210 L790,300 L730,380 L620,360 L600,280 Z" fill="' + land2 + '"/>';
        svg += '<path d="M520,360 L610,350 L640,420 L580,450 L520,430 Z" fill="' + land + '"/>';
        svg += '<path d="M780,300 L860,290 L880,360 L820,390 L770,370 Z" fill="' + land2 + '"/>';

        return svg;
    }

    function renderGeoSection(data) {
        var geo = data.geo_sales || [];
        var cities = data.geo_cities || [];
        var summary = data.geo_summary || {};
        var pins = cities.length ? cities : geo;
        var pinHtml = '';

        (pins || []).slice(0, 10).forEach(function (item, i) {
            var left = item.map_left != null ? item.map_left : 50;
            var top = item.map_top != null ? item.map_top : 50;
            var size = i === 0 ? ' lg' : '';
            var name = item.short_label || item.name || item.label || '';
            var rev = fmtMoney(item.revenue);
            var orders = fmtNum(item.orders || 0);
            var rank = item.rank || (i + 1);

            pinHtml += '<div class="ss-wa-geo-pin" style="left:' + left + '%;top:' + top + '%" data-rank="' + rank + '">'
                + '<div class="ss-wa-geo-pin-dot' + size + '" style="background:' + palette[i % palette.length] + '"></div>'
                + '<div class="ss-wa-geo-pin-tooltip">'
                + '<strong>' + esc(name) + '</strong>'
                + '<span>' + (item.country_name ? esc(item.country_name) + ' · ' : '')
                + rev + ' · ' + orders + ' ' + esc(i18n.orders || 'orders') + '</span>'
                + '</div>'
                + '</div>';
        });

        var statsHtml = '<div class="ss-wa-geo-summary">'
            + '<div class="ss-wa-geo-stat"><span class="ss-wa-geo-stat-val">' + fmtNum(summary.countries || geo.length || 0) + '</span><span class="ss-wa-geo-stat-label">' + esc(i18n.countriesCount || 'Countries') + '</span></div>'
            + '<div class="ss-wa-geo-stat"><span class="ss-wa-geo-stat-val">' + fmtNum(summary.cities || cities.length || 0) + '</span><span class="ss-wa-geo-stat-label">' + esc(i18n.citiesCount || 'Cities') + '</span></div>'
            + '<div class="ss-wa-geo-stat highlight"><span class="ss-wa-geo-stat-val">' + esc((summary.top_city && summary.top_city.short_label) || (summary.top_country && summary.top_country.name) || '—') + '</span><span class="ss-wa-geo-stat-label">' + esc(i18n.topLocation || 'Top Location') + '</span></div>'
            + '</div>';

        var citiesHtml = '';
        if (cities.length) {
            citiesHtml = '<div class="ss-wa-geo-cities">'
                + '<div class="ss-wa-country-list-title">' + esc(i18n.topCities || 'Top Cities & Order Locations') + '</div>'
                + '<div class="ss-wa-geo-city-table-wrap' + tableScrollClass(cities.length) + '"><table class="ss-wa-geo-city-table">'
                + '<thead><tr>'
                + '<th>#</th><th>' + esc(i18n.location || 'Location') + '</th>'
                + '<th>' + esc(i18n.orders || 'Orders') + '</th><th>' + esc(i18n.revenue || 'Revenue') + '</th>'
                + '<th>' + esc(i18n.share || 'Share') + '</th>'
                + '</tr></thead><tbody>';
            cities.forEach(function (c) {
                var sub = [];
                if (c.state_name || c.state) {
                    sub.push(c.state_name || c.state);
                }
                sub.push(c.country_name);
                citiesHtml += '<tr data-rank="' + c.rank + '">'
                    + '<td class="ss-wa-geo-rank">' + c.rank + '</td>'
                    + '<td><div class="ss-wa-geo-city-cell">'
                    + '<span class="ss-wa-geo-city-flag">' + countryFlag(c.country_code) + '</span>'
                    + '<div><div class="ss-wa-geo-city-name">' + esc(c.short_label || c.label) + '</div>'
                    + '<div class="ss-wa-geo-city-sub">' + esc(sub.join(', ')) + '</div></div></div></td>'
                    + '<td>' + fmtNum(c.orders) + '</td>'
                    + '<td><strong>' + fmtMoney(c.revenue) + '</strong></td>'
                    + '<td><div class="ss-wa-geo-share-wrap"><div class="ss-wa-geo-share-bar" style="width:' + (c.share || 0) + '%"></div><span>' + (c.share || 0) + '%</span></div></td>'
                    + '</tr>';
            });
            citiesHtml += '</tbody></table></div>' + tableScrollFoot(cities.length, i18n.locations || 'locations') + '</div>';
        }

        return '<div class="ss-wa-geo-advanced">'
            + statsHtml
            + '<div class="ss-wa-geo-grid">'
            + '<div class="ss-wa-geo-map">'
            + '<svg viewBox="0 0 1000 500" class="ss-wa-world-svg" xmlns="http://www.w3.org/2000/svg">'
            + renderWorldMapSvg()
            + '</svg>'
            + '<div class="ss-wa-geo-pins">' + pinHtml + '</div>'
            + '</div>'
            + '<div class="ss-wa-country-list-wrap">'
            + '<div class="ss-wa-country-list-title">' + esc(i18n.topCountries || 'Top Countries') + '</div>'
            + renderCountryList(geo)
            + '</div></div>'
            + citiesHtml
            + '</div>';
    }

    function renderGeoMap(geo) {
        return renderGeoSection({ geo_sales: geo, geo_cities: [], geo_summary: {} });
    }

    function renderCountryList(geo) {
        if (!geo || !geo.length) {
            return '<p class="ss-wa-empty">' + esc(i18n.noData) + '</p>';
        }
        var html = '<div class="ss-wa-country-list">';
        geo.forEach(function (c) {
            html += '<div class="ss-wa-country-item">'
                + '<div class="ss-wa-country-name">' + esc(c.name) + '</div>'
                + '<div class="ss-wa-country-bar-wrap"><div class="ss-wa-country-bar" style="width:' + (c.share || 0) + '%"></div></div>'
                + '<div class="ss-wa-country-val">' + fmtMoney(c.revenue) + ' · ' + fmtNum(c.orders || 0) + '</div>'
                + '</div>';
        });
        return html + '</div>';
    }

    function renderInventory(inv) {
        inv = inv || {};
        var html = '<div class="ss-wa-inv-grid">'
            + '<div class="ss-wa-inv-stat"><div class="ss-wa-inv-val orange">' + fmtNum(inv.low_stock) + '</div><div class="ss-wa-inv-label">' + esc(i18n.lowStock) + '</div></div>'
            + '<div class="ss-wa-inv-stat"><div class="ss-wa-inv-val red">' + fmtNum(inv.out_stock) + '</div><div class="ss-wa-inv-label">' + esc(i18n.outOfStock) + '</div></div>'
            + '<div class="ss-wa-inv-stat"><div class="ss-wa-inv-val blue">' + fmtNum(inv.total) + '</div><div class="ss-wa-inv-label">' + esc(i18n.totalProducts) + '</div></div>'
            + '<div class="ss-wa-inv-stat"><div class="ss-wa-inv-val green">' + fmtNum((inv.total || 0) - (inv.low_stock || 0) - (inv.out_stock || 0)) + '</div><div class="ss-wa-inv-label">' + esc(i18n.itemsSold || 'In Stock') + '</div></div>'
            + '</div>';

        if (inv.alerts && inv.alerts.length) {
            html += '<div class="ss-wa-stock-list-title">' + esc(i18n.stockAlerts) + '</div><div class="ss-wa-stock-list">';
            inv.alerts.forEach(function (a) {
                var cls = a.status === 'outofstock' ? 'critical' : 'low';
                var stCls = a.status === 'outofstock' ? 'out' : 'low';
                html += '<div class="ss-wa-stock-item">'
                    + '<div class="ss-wa-stock-img">📦</div>'
                    + '<div class="ss-wa-stock-name">' + esc(a.name) + '</div>'
                    + '<div class="ss-wa-stock-qty ' + cls + '">' + fmtNum(a.qty) + ' left</div>'
                    + '<div class="ss-wa-stock-status ' + stCls + '">' + (a.status === 'outofstock' ? esc(i18n.outOfStock) : esc(i18n.lowStock)) + '</div>'
                    + '</div>';
            });
            html += '</div>';
        }
        return html;
    }

    function renderQuickActions() {
        var actions = [
            { icon: '📥', label: i18n.exportBtn || 'Export Sheet', id: 'ss_qa_export' },
            { icon: '🔄', label: i18n.refresh || 'Refresh', id: 'ss_qa_refresh' },
            { icon: '📦', label: i18n.inventoryIntel || 'Inventory', section: 'ss-wa-sec-inventory' },
            { icon: '🛒', label: i18n.recentOrders || 'Orders', section: 'ss-wa-sec-orders' },
            { icon: '📊', label: i18n.analyticsTitle || 'Analytics', section: 'ss-wa-sec-revenue' },
            { icon: '🗺️', label: i18n.geoSales || 'Geographic', section: 'ss-wa-sec-geo' }
        ];
        var html = '<div class="ss-wa-qa-grid">';
        actions.forEach(function (a) {
            var attrs = a.id ? ' data-action="' + a.id + '"' : ' data-section="' + a.section + '"';
            html += '<button type="button" class="ss-wa-qa-btn"' + attrs + '>'
                + '<span class="ss-wa-qa-icon">' + a.icon + '</span>'
                + '<span class="ss-wa-qa-label">' + esc(a.label) + '</span>'
                + '</button>';
        });
        return html + '</div>';
    }

    function getActiveFilters() {
        return {
            filter_category: $('select[name="ss_filter_category"]').val() || '',
            filter_product: $('select[name="ss_filter_product"]').val() || '',
            filter_status: $('select[name="ss_filter_status"]').val() || '',
            filter_payment: $('select[name="ss_filter_payment"]').val() || '',
            filter_country: $('select[name="ss_filter_country"]').val() || ''
        };
    }

    function restoreFilterSelects(saved) {
        if (!saved) return;
        $('select[name="ss_filter_category"]').val(saved.filter_category || '');
        $('select[name="ss_filter_product"]').val(saved.filter_product || '');
        $('select[name="ss_filter_status"]').val(saved.filter_status || '');
        $('select[name="ss_filter_payment"]').val(saved.filter_payment || '');
        $('select[name="ss_filter_country"]').val(saved.filter_country || '');
    }

    function renderFilterSelects(filters, saved) {
        filters = filters || {};
        var groups = [
            { key: 'categories', name: 'ss_filter_category' },
            { key: 'products', name: 'ss_filter_product' },
            { key: 'statuses', name: 'ss_filter_status' },
            { key: 'payments', name: 'ss_filter_payment' },
            { key: 'countries', name: 'ss_filter_country' }
        ];
        var html = '';
        groups.forEach(function (g) {
            var opts = filters[g.key] || [];
            html += '<select class="ss-wa-filter-select" name="' + g.name + '">';
            opts.forEach(function (o) {
                html += '<option value="' + esc(o.value) + '">' + esc(o.label) + '</option>';
            });
            html += '</select>';
        });
        $('#ss_wa_filter_selects').html(html);
        restoreFilterSelects(saved || {});
    }

    function updateSidebar(data) {
        var user = data.user || {};
        $('#ss_wa_user_name').text(user.name || '');
        $('#ss_wa_user_role').text(user.role || '');
        $('#ss_wa_user_avatar').text(user.initials || '—');

        var pending = parseInt(data.pending_orders, 10) || 0;
        var $badge = $('#ss_wa_pending_badge');
        if (pending > 0) {
            $badge.text(pending).show();
        } else {
            $badge.hide();
        }
    }

    function renderDashboard(data, savedFilters, renderOpts) {
        renderOpts = renderOpts || {};
        destroyCharts();
        destroySparklines();

        var s = data.summary || {};
        var cmp = data.comparison || {};
        var sparks = data.sparklines || { revenue: [], orders: [] };
        var html = '';

        // 12 KPI cards
        var kRev = kpiMoney(s.net_sales);
        var kProfit = kpiMoney(s.net_profit);
        var kOrders = kpiNum(s.total_orders);
        var kAov = kpiMoney(s.avg_order_value);
        var kCust = kpiNum(s.total_customers);
        var kRet = kpiNum(s.returning_customers);
        var kRefund = kpiMoney(s.total_refunds);
        var kItems = kpiNum(s.total_items);
        var pRev = cmp.net_sales ? kpiMoney(cmp.net_sales.previous) : { value: '—', title: '' };
        var pProfit = cmp.net_profit ? kpiMoney(cmp.net_profit.previous) : { value: '—', title: '' };
        var pOrders = cmp.total_orders ? kpiNum(cmp.total_orders.previous) : { value: '—', title: '' };
        var pAov = cmp.avg_order_value ? kpiMoney(cmp.avg_order_value.previous) : { value: '—', title: '' };
        var pCust = cmp.total_customers ? kpiNum(cmp.total_customers.previous) : { value: '—', title: '' };
        var pRet = cmp.returning_customers ? kpiNum(cmp.returning_customers.previous) : { value: '—', title: '' };
        var pRefund = cmp.total_refunds ? kpiMoney(cmp.total_refunds.previous) : { value: '—', title: '' };
        var pItems = cmp.total_items ? kpiNum(cmp.total_items.previous) : { value: '—', title: '' };

        html += '<div class="ss-wa-kpi-grid" id="ss-wa-sec-kpi">';
        html += kpiCard({ icon: '💰', label: i18n.totalRevenue || 'Total Revenue', helpKey: 'total_revenue', valueHtml: kRev.html, valueTitle: kRev.title, compact: kRev.compact, trend: trendBadge(cmp.net_sales), prev: pRev.value, prevTitle: pRev.title, bg: 'rgba(108,99,255,.15)', color: '#6c63ff', sparkKey: 'revenue' });
        html += kpiCard({ icon: '📈', label: i18n.netProfit || 'Net Profit', helpKey: 'net_profit', valueHtml: kProfit.html, valueTitle: kProfit.title, compact: kProfit.compact, trend: trendBadge(cmp.net_profit), prev: pProfit.value, prevTitle: pProfit.title, bg: 'rgba(34,211,165,.15)', color: '#22d3a5', sparkKey: 'revenue' });
        html += kpiCard({ icon: '🛒', label: i18n.totalOrders, helpKey: 'total_orders', value: kOrders.value, valueTitle: kOrders.title, compact: kOrders.compact, trend: trendBadge(cmp.total_orders), prev: pOrders.value, prevTitle: pOrders.title, bg: 'rgba(56,189,248,.15)', color: '#38bdf8', sparkKey: 'orders' });
        html += kpiCard({ icon: '🧾', label: i18n.avgOrder, helpKey: 'avg_order', valueHtml: kAov.html, valueTitle: kAov.title, compact: kAov.compact, trend: trendBadge(cmp.avg_order_value), prev: pAov.value, prevTitle: pAov.title, bg: 'rgba(244,114,182,.15)', color: '#f472b6', sparkKey: 'revenue' });
        html += kpiCard({ icon: '👥', label: i18n.totalCustomers || 'Total Customers', helpKey: 'total_customers', value: kCust.value, valueTitle: kCust.title, compact: kCust.compact, trend: trendBadge(cmp.total_customers), prev: pCust.value, prevTitle: pCust.title, bg: 'rgba(251,191,36,.15)', color: '#fbbf24', sparkKey: 'orders' });
        html += kpiCard({ icon: '🔄', label: i18n.returningCustomers || 'Returning Customers', helpKey: 'returning_customers', value: kRet.value, valueTitle: kRet.title, compact: kRet.compact, trend: trendBadge(cmp.returning_customers), prev: pRet.value, prevTitle: pRet.title, bg: 'rgba(167,139,250,.15)', color: '#a78bfa', sparkKey: 'orders' });
        html += kpiCard({ icon: '🎯', label: i18n.conversionRate || 'Conversion Rate', helpKey: 'conversion_rate', value: (s.repeat_purchase_rate || 0) + '%', trend: trendBadge(cmp.conversion_rate), prev: (cmp.conversion_rate && cmp.conversion_rate.previous ? cmp.conversion_rate.previous + '%' : '—'), bg: 'rgba(52,211,153,.15)', color: '#34d399', sparkKey: 'orders' });
        html += kpiCard({ icon: '↩️', label: i18n.refundAmount || 'Refund Amount', helpKey: 'refunds', valueHtml: kRefund.html, valueTitle: kRefund.title, compact: kRefund.compact, trend: trendBadge(cmp.total_refunds), prev: pRefund.value, prevTitle: pRefund.title, bg: 'rgba(255,94,122,.15)', color: '#ff5e7a', sparkKey: 'revenue' });
        html += kpiCard({ icon: '📦', label: i18n.productsSold || 'Products Sold', helpKey: 'products_sold', value: kItems.value, valueTitle: kItems.title, compact: kItems.compact, trend: trendBadge(cmp.total_items), prev: pItems.value, prevTitle: pItems.title, bg: 'rgba(96,165,250,.15)', color: '#60a5fa', sparkKey: 'orders' });
        html += kpiCard({ icon: '🚀', label: i18n.revenueGrowth || 'Revenue Growth', value: fmtPct(cmp.net_sales && cmp.net_sales.change), trend: trendBadge(cmp.net_sales), prev: fmtPct(cmp.net_sales && cmp.net_sales.previous ? 0 : 0), bg: 'rgba(249,115,22,.15)', color: '#f97316', sparkKey: 'revenue' });
        html += kpiCard({ icon: '💹', label: i18n.profitGrowth || 'Profit Growth', value: fmtPct(cmp.net_profit && cmp.net_profit.change), trend: trendBadge(cmp.net_profit), prev: '—', bg: 'rgba(236,72,153,.15)', color: '#ec4899', sparkKey: 'revenue' });
        html += kpiCard({ icon: '📊', label: i18n.customerGrowth || 'Customer Growth', value: fmtPct(cmp.total_customers && cmp.total_customers.change), trend: trendBadge(cmp.total_customers), prev: '—', bg: 'rgba(16,185,129,.15)', color: '#10b981', sparkKey: 'orders' });
        html += '</div>';

        if (data.orders_meta && data.orders_meta.truncated) {
            var om = data.orders_meta;
            html += '<div class="ss-wa-calc-note fade-up"><strong>'
                + esc(i18n.ordersTruncated || 'Showing first')
                + ' ' + fmtNum(om.orders_loaded || om.max_orders || 0)
                + '</strong> '
                + esc(i18n.ordersTruncatedSub || 'orders for this period. Export to Google Sheets for the full dataset.')
                + '</div>';
        }

        if (data.monthly_goal_progress) {
            var g = data.monthly_goal_progress;
            html += '<div class="ss-wa-goal-card fade-up" id="ss-wa-monthly-goal">'
                + '<div class="ss-wa-goal-head"><span>🎯 ' + esc(i18n.goalProgress || 'Monthly Goal Progress') + '</span>'
                + kpiHelpHtml('monthly_goal')
                + '<strong>' + esc(g.month || '') + '</strong></div>'
                + '<div class="ss-wa-goal-stats"><span>' + fmtMoney(g.actual) + ' / ' + fmtMoney(g.goal) + '</span>'
                + '<span class="ss-wa-goal-pct">' + g.pct + '%</span></div>'
                + '<div class="ss-wa-goal-bar"><div class="ss-wa-goal-bar-fill" style="width:' + Math.min(100, g.pct) + '%"></div></div>'
                + '<div class="ss-wa-kpi-sub">' + fmtMoney(g.remaining) + ' ' + esc(i18n.goalRemaining || 'remaining') + '</div>'
                + '</div>';
        }

        if (data.cogs && (data.cogs.total_cogs > 0 || data.cogs.gross_profit > 0 || data.is_demo)) {
            var cg = data.cogs;
            var kGross = kpiMoney(cg.gross_profit);
            var kCogs = kpiMoney(cg.total_cogs);
            html += '<div class="ss-wa-kpi-grid ss-wa-cogs-grid fade-up" id="ss-wa-sec-cogs">';
            html += kpiCard({ icon: '💵', label: i18n.grossProfit || 'Gross Profit (COGS)', helpKey: 'gross_profit', valueHtml: kGross.html, valueTitle: kGross.title, compact: kGross.compact, bg: 'rgba(34,211,165,.15)', color: '#22d3a5' });
            html += kpiCard({ icon: '🏭', label: i18n.totalCogs || 'Total COGS', helpKey: 'total_cogs', valueHtml: kCogs.html, valueTitle: kCogs.title, compact: kCogs.compact, bg: 'rgba(255,159,67,.15)', color: '#ff9f43' });
            html += kpiCard({ icon: '📐', label: i18n.marginPct || 'Gross Margin', helpKey: 'margin_pct', value: (cg.margin_pct || 0) + '%', bg: 'rgba(108,99,255,.15)', color: '#6c63ff' });
            if (cg.missing_cogs_lines > 0) {
                html += kpiCard({ icon: '⚠️', label: i18n.missingCogs || 'Lines missing COGS', value: fmtNum(cg.missing_cogs_lines), bg: 'rgba(255,94,122,.15)', color: '#ff5e7a', sub: esc(i18n.missingCogsSub || 'Add cost on product edit screen') });
            }
            html += '</div>';

            if (cg.products_profit && cg.products_profit.length) {
                html += '<div class="ss-wa-card fade-up"><div class="ss-wa-card-head"><div><div class="ss-wa-card-title">💹 ' + esc(i18n.profitByProduct || 'Profit by Product') + '</div>'
                    + '<div class="ss-wa-card-sub">' + esc(i18n.profitByProductSub || 'Revenue, COGS and margin for top sellers') + '</div></div></div>'
                    + '<div class="ss-wa-table-wrap' + tableScrollClass(cg.products_profit.length) + '"><table class="ss-wa-table"><thead><tr>'
                    + '<th>' + esc(i18n.product || 'Product') + '</th><th>' + esc(i18n.revenue || 'Revenue') + '</th>'
                    + '<th>' + esc(i18n.totalCogs || 'COGS') + '</th><th>' + esc(i18n.profit || 'Profit') + '</th><th>' + esc(i18n.marginPct || 'Margin') + '</th>'
                    + '</tr></thead><tbody>';
                cg.products_profit.forEach(function (p) {
                    html += '<tr><td>' + esc(p.name) + '</td><td>' + fmtMoney(p.revenue) + '</td><td>' + fmtMoney(p.cogs) + '</td><td><strong>' + fmtMoney(p.profit) + '</strong></td><td>' + (p.margin || 0) + '%</td></tr>';
                });
                html += '</tbody></table></div>' + tableScrollFoot(cg.products_profit.length, i18n.productsSold || 'products') + '</div>';
            }
        }

        if (data.multistore_rollup && data.multistore_rollup.enabled && data.multistore_rollup.stores && data.multistore_rollup.stores.length) {
            var ms = data.multistore_rollup;
            html += '<div class="ss-wa-card fade-up" id="ss-wa-sec-multistore"><div class="ss-wa-card-head"><div><div class="ss-wa-card-title">🌐 ' + esc(i18n.multistoreRollup || 'Multi-store Rollup') + '</div>'
                + kpiHelpHtml('multistore')
                + '<div class="ss-wa-card-sub">' + esc(i18n.multistoreSub || 'Combined network store performance') + ' · ' + fmtMoney(ms.totals.net_sales) + '</div></div></div>'
                + '<div class="ss-wa-table-wrap"><table class="ss-wa-table"><thead><tr><th>' + esc(i18n.store || 'Store') + '</th><th>' + esc(i18n.revenue || 'Revenue') + '</th><th>' + esc(i18n.totalOrders || 'Orders') + '</th></tr></thead><tbody>';
            ms.stores.forEach(function (st) {
                html += '<tr><td>' + esc(st.name) + '</td><td>' + fmtMoney(st.net_sales) + '</td><td>' + fmtNum(st.total_orders) + '</td></tr>';
            });
            html += '</tbody></table></div></div>';
        }

        // Calculation legend — matches WooCommerce admin
        html += '<div class="ss-wa-calc-note">'
            + '<strong>' + esc(i18n.calcLegend || 'How numbers are calculated') + ':</strong> '
            + esc(i18n.calcLegendBody || 'Revenue from paid orders (Processing + Completed). Total Orders matches WooCommerce admin (includes Cancelled).')
            + ' <span class="ss-wa-calc-pills">'
            + '<span class="ss-wa-calc-pill">' + fmtNum(s.paid_orders || 0) + ' ' + esc(i18n.paidOrders || 'paid') + '</span>'
            + '<span class="ss-wa-calc-pill">' + fmtNum(s.total_orders || 0) + ' ' + esc(i18n.totalOrders || 'total') + '</span>'
            + '<span class="ss-wa-calc-pill">' + fmtMoney(s.pending_revenue || 0) + ' ' + esc(i18n.pendingRevenue || 'pending') + '</span>'
            + '<span class="ss-wa-calc-pill">' + fmtNum(s.total_customers || 0) + ' ' + esc(i18n.totalCustomers || 'customers') + '</span>'
            + '</span></div>';

        // Revenue Analytics
        html += '<div class="ss-wa-card fade-up" id="ss-wa-sec-revenue">'
            + '<div class="ss-wa-card-head"><div><div class="ss-wa-card-title">' + esc(i18n.revenueAnalytics) + '</div>'
            + '<div class="ss-wa-card-sub">' + esc(i18n.revenueExpenses || 'Revenue, Profit & Expenses over time') + '</div></div>'
            + '<div class="ss-wa-chart-tabs" id="ss_rev_tabs">'
            + '<button type="button" class="ss-wa-chart-tab active" data-mode="daily">' + esc(i18n.daily) + '</button>'
            + '<button type="button" class="ss-wa-chart-tab" data-mode="weekly">' + esc(i18n.weekly || 'Weekly') + '</button>'
            + '<button type="button" class="ss-wa-chart-tab" data-mode="monthly">' + esc(i18n.monthly) + '</button>'
            + '<button type="button" class="ss-wa-chart-tab" data-mode="yearly">' + esc(i18n.yearly || 'Yearly') + '</button>'
            + '</div></div>'
            + '<div class="ss-wa-legend">'
            + '<div class="ss-wa-legend-item"><div class="ss-wa-legend-line" style="background:#6c63ff"></div>' + esc(i18n.revenue) + '</div>'
            + '<div class="ss-wa-legend-item"><div class="ss-wa-legend-line" style="background:#22d3a5"></div>' + esc(i18n.profit || 'Profit') + '</div>'
            + '<div class="ss-wa-legend-item"><div class="ss-wa-legend-line" style="background:#ff5e7a;opacity:.7"></div>' + esc(i18n.expenses || 'Expenses') + '</div>'
            + '</div>'
            + '<div class="ss-wa-chart-wrap"><canvas id="ss_chart_revenue_main"></canvas></div>'
            + '</div>';

        // Orders vs Revenue + Category
        html += '<div class="ss-wa-charts-2">';
        html += '<div class="ss-wa-card fade-up"><div class="ss-wa-card-head"><div><div class="ss-wa-card-title">' + esc(i18n.ordersVsRevenue) + '</div><div class="ss-wa-card-sub">' + esc(i18n.dualAxis) + '</div></div></div>'
            + '<div class="ss-wa-chart-wrap"><canvas id="ss_chart_orders_rev"></canvas></div></div>';
        html += '<div class="ss-wa-card fade-up"><div class="ss-wa-card-head"><div><div class="ss-wa-card-title">' + esc(i18n.topCategories) + '</div><div class="ss-wa-card-sub">' + esc(i18n.categoryShare) + '</div></div></div>'
            + '<div class="ss-wa-split">';
        if (data.top_categories && data.top_categories.length) {
            html += '<div class="ss-wa-chart-wrap sm"><canvas id="ss_chart_category"></canvas></div>';
            html += payLegend(data.top_categories.map(function (c) {
                return {
                    name: c.name,
                    revenue: c.revenue,
                    orders: c.orders || 0,
                    quantity: c.quantity || 0,
                    share: c.share || 0
                };
            }));
        } else {
            html += '<p class="ss-wa-empty">' + esc(i18n.noData) + '</p>';
        }
        html += '</div></div></div>';

        // Forecast
        html += '<div class="ss-wa-card fade-up" id="ss-wa-sec-forecast">'
            + '<div class="ss-wa-card-head"><div><div class="ss-wa-card-title">🤖 ' + esc(i18n.salesForecast || 'AI-Powered Sales Forecast') + '</div>'
            + '<div class="ss-wa-card-sub">' + esc(i18n.forecastSub) + (data.forecast && data.forecast.method ? ' · ' + esc(data.forecast.method) : '') + '</div></div>'
            + (data.forecast && data.forecast.confidence ? '<span class="ss-wa-forecast-badge" title="' + esc(i18n.forecastConf || 'Model confidence') + '">' + data.forecast.confidence + '% ' + esc(i18n.confidence || 'confidence') + '</span>' : '')
            + '</div>'
            + '<div class="ss-wa-legend">'
            + '<div class="ss-wa-legend-item"><div class="ss-wa-legend-line" style="background:#6c63ff"></div>' + esc(i18n.historical || 'Historical') + '</div>'
            + '<div class="ss-wa-legend-item"><div class="ss-wa-legend-line dashed" style="border-color:#fbbf24"></div>' + esc(i18n.predicted || 'Predicted') + '</div>'
            + '</div>'
            + '<div class="ss-wa-chart-wrap"><canvas id="ss_chart_forecast"></canvas></div>'
            + '</div>';

        // Top Products
        html += '<div class="ss-wa-card fade-up" id="ss-wa-sec-products">'
            + '<div class="ss-wa-card-head"><div><div class="ss-wa-card-title">' + esc(i18n.topSelling || i18n.topProducts) + '</div>'
            + '<div class="ss-wa-card-sub">' + esc(i18n.byRevenue) + '</div></div></div>'
            + '<div class="ss-wa-chart-wrap ss-wa-product-bar-wrap"><canvas id="ss_chart_top_products"></canvas></div></div>';

        // Customer + Funnel
        html += '<div class="ss-wa-charts-2">';
        html += '<div class="ss-wa-card fade-up" id="ss-wa-sec-customers">'
            + '<div class="ss-wa-card-head"><div><div class="ss-wa-card-title">' + esc(i18n.customerAnalytics) + '</div>'
            + '<div class="ss-wa-card-sub">' + esc(i18n.newVsReturning) + ' & CLV</div></div></div>'
            + '<div class="ss-wa-legend">'
            + '<div class="ss-wa-legend-item"><div class="ss-wa-legend-dot" style="background:#6c63ff"></div>' + esc(i18n.newCustomers) + '</div>'
            + '<div class="ss-wa-legend-item"><div class="ss-wa-legend-dot" style="background:#22d3a5"></div>' + esc(i18n.returningCustomers || 'Returning') + '</div>'
            + '</div>'
            + '<div class="ss-wa-chart-wrap"><canvas id="ss_chart_customers"></canvas></div>'
            + '<div class="ss-wa-clv-grid">'
            + '<div class="ss-wa-inv-stat"><div class="ss-wa-inv-val blue">' + fmtMoney(s.avg_clv) + '</div><div class="ss-wa-inv-label">' + esc(i18n.avgClv) + '</div></div>'
            + '<div class="ss-wa-inv-stat"><div class="ss-wa-inv-val green">' + (s.repeat_purchase_rate || 0) + '%</div><div class="ss-wa-inv-label">' + esc(i18n.repeatPurchase) + '</div></div>'
            + '</div></div>';
        html += '<div class="ss-wa-card fade-up">'
            + '<div class="ss-wa-card-head"><div><div class="ss-wa-card-title">' + esc(i18n.conversionFunnel) + '</div>'
            + '<div class="ss-wa-card-sub">' + esc(i18n.funnelSub) + '</div></div></div>'
            + renderFunnel(data.funnel)
            + '</div></div>';

        // Geo + Payments
        html += '<div class="ss-wa-charts-2">';
        html += '<div class="ss-wa-card fade-up ss-wa-geo-card" id="ss-wa-sec-geo">'
            + '<div class="ss-wa-card-head"><div><div class="ss-wa-card-title">' + esc(i18n.geoSales) + '</div>'
            + '<div class="ss-wa-card-sub">' + esc(i18n.geoDistAdvanced || i18n.geoDist) + '</div></div></div>'
            + ((data.geo_sales && data.geo_sales.length) || (data.geo_cities && data.geo_cities.length)
                ? renderGeoSection(data)
                : '<p class="ss-wa-empty">' + esc(i18n.noData) + '</p>')
            + '</div>';
        html += '<div class="ss-wa-card fade-up" id="ss-wa-sec-payments">'
            + '<div class="ss-wa-card-head"><div><div class="ss-wa-card-title">' + esc(i18n.paymentMethods) + '</div>'
            + '<div class="ss-wa-card-sub">' + esc(i18n.paySub) + '</div></div></div>'
            + '<div class="ss-wa-split">';
        if (data.payment_methods && data.payment_methods.length) {
            html += '<div class="ss-wa-chart-wrap sm"><canvas id="ss_chart_payments"></canvas></div>';
            html += payLegend(data.payment_methods);
        } else {
            html += '<p class="ss-wa-empty">' + esc(i18n.noData) + '</p>';
        }
        html += '</div></div></div>';

        // Recent Orders
        html += '<div class="ss-wa-card fade-up" id="ss-wa-sec-orders">'
            + '<div class="ss-wa-card-head"><div><div class="ss-wa-card-title">' + esc(i18n.recentOrders) + '</div>'
            + '<div class="ss-wa-card-sub">' + fmtNum(s.total_orders) + ' ' + esc(i18n.ordersInPeriod) + '</div></div></div>'
            + '<div class="ss-wa-table-controls">'
            + '<div class="ss-wa-search-wrap"><input type="text" class="ss-wa-search-input" id="ss_order_search" placeholder="' + esc(i18n.searchOrders) + '" /></div>'
            + '</div>';
        if (data.recent_orders && data.recent_orders.length) {
            var orderCount = data.recent_orders.length;
            html += '<div class="ss-wa-table-wrap' + tableScrollClass(orderCount) + '"><table class="ss-wa-table" id="ss_orders_table"><thead><tr>'
                + '<th>' + esc(i18n.order) + '</th><th>' + esc(i18n.customer) + '</th>'
                + '<th>' + esc(i18n.products) + '</th><th>' + esc(i18n.total) + '</th>'
                + '<th>' + esc(i18n.paymentMethods) + '</th><th>' + esc(i18n.status) + '</th>'
                + '<th>' + esc(i18n.location || i18n.country) + '</th><th>' + esc(i18n.date) + '</th>'
                + '</tr></thead><tbody>';
            data.recent_orders.forEach(function (o, idx) {
                var avColor = avatarColors[idx % avatarColors.length];
                html += '<tr>'
                    + '<td><a class="ss-wa-order-id" href="' + esc(o.edit_url) + '" target="_blank">#' + esc(o.number) + '</a></td>'
                    + '<td><div class="ss-wa-customer"><div class="ss-wa-avatar" style="background:' + avColor + '">' + esc(o.initials) + '</div><span>' + esc(o.customer) + '</span></div></td>'
                    + '<td><small>' + esc(o.items) + '</small></td>'
                    + '<td><strong>' + fmtMoney(o.total) + '</strong></td>'
                    + '<td>' + esc(o.payment) + '</td>'
                    + '<td><span class="ss-wa-status ' + esc(o.status_slug) + '">' + esc(o.status) + '</span></td>'
                    + '<td><div class="ss-wa-order-location">' + esc(o.location || o.country_name || o.country) + '</div></td>'
                    + '<td>' + esc(o.date) + '</td>'
                    + '</tr>';
            });
            html += '</tbody></table></div>' + tableScrollFoot(orderCount, i18n.orders || 'orders');
        } else {
            html += '<p class="ss-wa-empty">' + esc(i18n.noOrders) + '</p>';
        }
        html += '</div>';

        // Inventory
        html += '<div class="ss-wa-card fade-up" id="ss-wa-sec-inventory">'
            + '<div class="ss-wa-card-head"><div><div class="ss-wa-card-title">' + esc(i18n.inventoryIntel) + '</div>'
            + '<div class="ss-wa-card-sub">' + esc(i18n.inventorySub) + '</div></div></div>'
            + renderInventory(data.inventory)
            + '</div>';

        // Insights
        if (data.insights && data.insights.length) {
            html += '<div class="ss-wa-card fade-up" id="ss-wa-sec-insights">'
                + '<div class="ss-wa-card-head"><div><div class="ss-wa-card-title">🤖 ' + esc(i18n.smartInsights) + '</div></div></div>'
                + '<div class="ss-wa-insights">';
            data.insights.forEach(function (ins) {
                html += '<div class="ss-wa-insight ' + esc(ins.type) + '">'
                    + '<div class="ss-wa-insight-icon">' + ins.icon + '</div>'
                    + '<div class="ss-wa-insight-title">' + esc(ins.title) + '</div>'
                    + '<div class="ss-wa-insight-body">' + esc(ins.body) + '</div>'
                    + '</div>';
            });
            html += '</div></div>';
        }

        // Quick Actions
        html += '<div class="ss-wa-card fade-up">'
            + '<div class="ss-wa-card-head"><div><div class="ss-wa-card-title">' + esc(i18n.quickActions) + '</div></div></div>'
            + renderQuickActions()
            + '</div>';

        $('#ss_sales_dashboard_root')
            .toggleClass('ss-wa-dash-stable', renderOpts.animate === false)
            .html(html);

        window.ssSalesDashData = data;
        renderFilterSelects(data.filters, savedFilters || data.active_filters || {});
        updateSidebar(data);
        bindGeoMapInteractions();

        requestAnimationFrame(function () {
            buildCharts(data);
            buildSparklines(sparks);
        });
    }

    function bindGeoMapInteractions() {
        var $root = $('#ss_sales_dashboard_root');

        $root.off('mouseenter.ssGeoPin mouseleave.ssGeoPin', '.ss-wa-geo-pin');
        $root.on('mouseenter.ssGeoPin', '.ss-wa-geo-pin', function () {
            var rank = $(this).data('rank');
            $root.find('.ss-wa-geo-city-table tr').removeClass('is-highlighted');
            $root.find('.ss-wa-geo-city-table tr[data-rank="' + rank + '"]').addClass('is-highlighted');
        });
        $root.on('mouseleave.ssGeoPin', '.ss-wa-geo-pins', function () {
            $root.find('.ss-wa-geo-city-table tr').removeClass('is-highlighted');
        });
    }

    function chartLabels(rows) {
        return rows.map(function (r) { return r.label || r.month || r.date; });
    }

    function baseScales() {
        return {
            x: { grid: { color: chartGrid }, ticks: { color: chartText, font: { size: 11 }, maxTicksLimit: 10 } },
            y: { grid: { color: chartGrid }, ticks: { color: chartText, callback: function (v) { return currencySymbol + Number(v).toLocaleString(); } } }
        };
    }

    function buildSparklines(sparks) {
        if (typeof Chart === 'undefined') return;
        $('.ss-wa-spark-wrap').each(function () {
            var canvas = $(this).find('canvas.ss-wa-sparkline')[0];
            if (!canvas) return;
            var existing = Chart.getChart(canvas);
            if (existing) {
                existing.destroy();
            }
            var key = $(canvas).data('series') || 'revenue';
            var series = sparks[key] || [];
            if (!series.length) return;
            var color = key === 'orders' ? '#38bdf8' : '#6c63ff';
            new Chart(canvas, {
                type: 'line',
                data: {
                    labels: series.map(function (_, i) { return i; }),
                    datasets: [{ data: series, borderColor: color, borderWidth: 2, pointRadius: 0, fill: true, backgroundColor: color + '25', tension: 0.4 }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: 0 },
                    plugins: { legend: { display: false }, tooltip: { enabled: false } },
                    scales: { x: { display: false }, y: { display: false } }
                }
            });
        });
    }

    function buildCharts(data) {
        if (typeof Chart === 'undefined') return;

        var daily = data.daily || [];
        var monthly = data.monthly || [];
        var trend = daily.length ? daily : monthly;

        var revCtx = document.getElementById('ss_chart_revenue_main');
        if (revCtx) {
            charts.revenueMain = new Chart(revCtx, {
                type: 'line',
                data: {
                    labels: chartLabels(trend),
                    datasets: [
                        { label: 'Revenue', data: trend.map(function (r) { return r.revenue; }), borderColor: '#6c63ff', backgroundColor: 'rgba(108,99,255,.12)', borderWidth: 2.5, pointRadius: 3, fill: true, tension: 0.4 },
                        { label: 'Profit', data: trend.map(function (r) { return r.profit || r.revenue; }), borderColor: '#22d3a5', backgroundColor: 'rgba(34,211,165,.08)', borderWidth: 2, pointRadius: 3, fill: true, tension: 0.4 },
                        { label: 'Expenses', data: trend.map(function (r) { return r.expenses || 0; }), borderColor: '#ff5e7a', backgroundColor: 'rgba(255,94,122,.08)', borderWidth: 2, pointRadius: 3, fill: true, tension: 0.4 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { display: false }, tooltip: { backgroundColor: '#1a2035', titleColor: '#e8eaf2', bodyColor: '#8891aa' } },
                    scales: baseScales()
                }
            });
        }

        var orCtx = document.getElementById('ss_chart_orders_rev');
        if (orCtx && daily.length) {
            charts.ordersRev = new Chart(orCtx, {
                type: 'bar',
                data: {
                    labels: chartLabels(daily),
                    datasets: [
                        { label: 'Revenue', data: daily.map(function (d) { return d.revenue; }), backgroundColor: 'rgba(108,99,255,.75)', borderRadius: 4, yAxisID: 'y' },
                        { label: 'Paid Orders', data: daily.map(function (d) { return d.paid_orders != null ? d.paid_orders : d.orders; }), backgroundColor: 'rgba(34,211,165,.35)', borderRadius: 4, yAxisID: 'y1' }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: chartText, maxTicksLimit: 8 } },
                        y: { position: 'left', grid: { color: chartGrid }, ticks: { color: chartText, callback: function (v) { return currencySymbol + v; } } },
                        y1: { position: 'right', grid: { display: false }, ticks: { color: chartText } }
                    }
                }
            });
        }

        if (data.top_categories && data.top_categories.length) {
            var catCtx = document.getElementById('ss_chart_category');
            if (catCtx) {
                charts.category = new Chart(catCtx, {
                    type: 'doughnut',
                    data: {
                        labels: data.top_categories.map(function (c) { return c.name; }),
                        datasets: [{ data: data.top_categories.map(function (c) { return c.revenue; }), backgroundColor: palette, borderWidth: 0 }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, cutout: '72%', plugins: { legend: { display: false } } }
                });
            }
        }

        if (data.forecast) {
            var fcCtx = document.getElementById('ss_chart_forecast');
            if (fcCtx) {
                var fc = data.forecast;
                charts.forecast = new Chart(fcCtx, {
                    type: 'line',
                    data: {
                        labels: fc.labels || [],
                        datasets: [
                            { label: 'Historical', data: fc.historical || [], borderColor: '#6c63ff', borderWidth: 2.5, pointRadius: 3, tension: 0.4, spanGaps: false },
                            { label: 'Predicted', data: fc.predicted || [], borderColor: '#fbbf24', borderWidth: 2, borderDash: [6, 4], pointRadius: 3, tension: 0.4, spanGaps: false }
                        ]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: baseScales()
                    }
                });
            }
        }

        if (data.top_products && data.top_products.length) {
            var tpCtx = document.getElementById('ss_chart_top_products');
            if (tpCtx) {
                charts.topProducts = new Chart(tpCtx, {
                    type: 'bar',
                    data: {
                        labels: data.top_products.map(function (p) { return p.name; }),
                        datasets: [{ data: data.top_products.map(function (p) { return p.revenue; }), backgroundColor: palette, borderRadius: 6 }]
                    },
                    options: {
                        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return fmtMoney(c.raw); } } } },
                        scales: {
                            x: { grid: { color: chartGrid }, ticks: { color: chartText, callback: function (v) { return currencySymbol + v; } } },
                            y: { grid: { display: false }, ticks: { color: chartText } }
                        }
                    }
                });
            }
        }

        if (data.customer_trend && data.customer_trend.length) {
            var cuCtx = document.getElementById('ss_chart_customers');
            if (cuCtx) {
                charts.customers = new Chart(cuCtx, {
                    type: 'bar',
                    data: {
                        labels: data.customer_trend.map(function (r) { return r.label; }),
                        datasets: [
                            { label: 'New', data: data.customer_trend.map(function (r) { return r.new; }), backgroundColor: 'rgba(108,99,255,.75)', borderRadius: 4, stack: 'a' },
                            { label: 'Returning', data: data.customer_trend.map(function (r) { return r.returning; }), backgroundColor: 'rgba(34,211,165,.75)', borderRadius: 4, stack: 'a' }
                        ]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { labels: { color: chartText } } },
                        scales: {
                            x: { stacked: true, grid: { display: false }, ticks: { color: chartText } },
                            y: { stacked: true, grid: { color: chartGrid }, ticks: { color: chartText } }
                        }
                    }
                });
            }
        }

        if (data.payment_methods && data.payment_methods.length) {
            var payCtx = document.getElementById('ss_chart_payments');
            if (payCtx) {
                charts.payments = new Chart(payCtx, {
                    type: 'doughnut',
                    data: {
                        labels: data.payment_methods.map(function (m) { return m.name; }),
                        datasets: [{ data: data.payment_methods.map(function (m) { return m.revenue; }), backgroundColor: palette, borderWidth: 0 }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, cutout: '72%', plugins: { legend: { display: false } } }
                });
            }
        }
    }

    function switchRevTab(mode) {
        var data = window.ssSalesDashData;
        if (!data || !charts.revenueMain) return;
        var rows;
        if (mode === 'weekly') rows = data.weekly || [];
        else if (mode === 'monthly') rows = data.monthly || [];
        else if (mode === 'yearly') rows = data.yearly || [];
        else rows = data.daily || [];
        if (!rows.length) rows = data.daily || data.monthly || [];
        charts.revenueMain.data.labels = chartLabels(rows);
        charts.revenueMain.data.datasets[0].data = rows.map(function (r) { return r.revenue; });
        charts.revenueMain.data.datasets[1].data = rows.map(function (r) { return r.profit || r.revenue; });
        charts.revenueMain.data.datasets[2].data = rows.map(function (r) { return r.expenses || 0; });
        charts.revenueMain.update();
    }

    function showLoading() {
        $('#ss_sales_dashboard_root')
            .removeClass('ss-wa-dash-stable ss-wa-is-fetching')
            .html(
                '<div class="ss-wa-loading"><span class="dashicons dashicons-update ss-spin"></span><p>' + esc(i18n.loading) + '</p></div>'
            );
    }

    function setDashboardFetching(on) {
        var $root = $('#ss_sales_dashboard_root');
        if (!$root.find('#ss-wa-sec-kpi').length) {
            return;
        }
        $root.toggleClass('ss-wa-is-fetching', !!on);
    }

    function dashboardFingerprint(data, period, filters) {
        try {
            return JSON.stringify({
                period: period || data.period || '',
                filters: filters || {},
                summary: data.summary || {},
                comparison: data.comparison || {},
                daily: (data.daily || []).length,
                monthly: (data.monthly || []).length,
                cogs: data.cogs ? {
                    gross: data.cogs.gross_profit,
                    total: data.cogs.total_cogs,
                    margin: data.cogs.margin_pct
                } : null,
                goal: data.monthly_goal_progress ? data.monthly_goal_progress.pct : null
            });
        } catch (e) {
            return String(Date.now());
        }
    }

    function dashboardCacheKey(period, filters) {
        return String(period || '7days') + '|' + JSON.stringify(filters || {});
    }

    function readDashboardCache(period, filters) {
        try {
            var raw = sessionStorage.getItem(SESSION_CACHE_KEY);
            if (!raw) {
                return null;
            }
            var store = JSON.parse(raw);
            var entry = store[dashboardCacheKey(period, filters)];
            if (!entry || !entry.data) {
                return null;
            }
            if (Date.now() - entry.ts > SESSION_CACHE_MAX_AGE_MS) {
                return null;
            }
            return entry.data;
        } catch (e) {
            return null;
        }
    }

    function writeDashboardCache(period, filters, data) {
        try {
            var raw = sessionStorage.getItem(SESSION_CACHE_KEY);
            var store = raw ? JSON.parse(raw) : {};
            store[dashboardCacheKey(period, filters)] = { ts: Date.now(), data: data };
            sessionStorage.setItem(SESSION_CACHE_KEY, JSON.stringify(store));
        } catch (e) {}
    }

    function loadDashboard(period, forceRefresh, loadOpts) {
        loadOpts = loadOpts || {};
        var allowInstantCache = !!loadOpts.instantCache && !forceRefresh;
        var AJAX = (typeof sheetsync !== 'undefined') ? sheetsync.ajax_url : ajaxurl;
        var NONCE = (typeof sheetsync !== 'undefined') ? sheetsync.nonce : '';
        var savedFilters = getActiveFilters();
        var payload = {
            action: 'sheetsync_get_sales_preview',
            nonce: NONCE,
            period: period
        };
        if (forceRefresh) {
            payload.force_refresh = 1;
        }

        var seq = ++dashboardLoadSeq;
        if (dashboardXhr && dashboardXhr.readyState !== 4) {
            dashboardXhr.abort();
        }

        var hasVisibleDashboard = $('#ss_sales_dashboard_root #ss-wa-sec-kpi').length > 0;
        var cached = allowInstantCache ? readDashboardCache(period, savedFilters) : null;

        if (cached && !hasVisibleDashboard) {
            try {
                if (cached.currency_symbol) {
                    currencySymbol = cached.currency_symbol;
                }
                renderDashboard(cached, savedFilters, { animate: true });
                dashboardDisplayedFingerprint = dashboardFingerprint(cached, period, savedFilters);
                if (typeof window.ssApplyDashboardBranding === 'function') {
                    window.ssApplyDashboardBranding(cached.branding || null);
                }
            } catch (e) {
                showLoading();
                hasVisibleDashboard = false;
            }
        } else if (hasVisibleDashboard) {
            setDashboardFetching(true);
        } else {
            showLoading();
        }

        $('#ss_sd_period_hidden').val(period);
        $('#ss_sd_period').val(period);

        dashboardXhr = $.ajax({
            url: AJAX,
            type: 'POST',
            data: $.extend(payload, savedFilters),
            timeout: 120000
        })
            .done(function (r) {
                if (seq !== dashboardLoadSeq) {
                    return;
                }
                setDashboardFetching(false);

                if (!r.success) {
                    if (!hasVisibleDashboard && !cached) {
                        $('#ss_sales_dashboard_root').html('<div class="ss-wa-empty">' + esc((r.data && r.data.message) || i18n.error) + '</div>');
                    }
                    return;
                }

                try {
                    var fingerprint = dashboardFingerprint(r.data, period, savedFilters);
                    writeDashboardCache(period, savedFilters, r.data);

                    if (fingerprint === dashboardDisplayedFingerprint && $('#ss_sales_dashboard_root #ss-wa-sec-kpi').length) {
                        if (r.data.currency_symbol) {
                            currencySymbol = r.data.currency_symbol;
                        }
                        window.ssSalesDashData = r.data;
                        return;
                    }

                    if (r.data.currency_symbol) {
                        currencySymbol = r.data.currency_symbol;
                    }
                    var shouldAnimate = !$('#ss_sales_dashboard_root #ss-wa-sec-kpi').length;
                    renderDashboard(r.data, savedFilters, { animate: shouldAnimate });
                    dashboardDisplayedFingerprint = fingerprint;
                    if (typeof window.ssApplyDashboardBranding === 'function') {
                        window.ssApplyDashboardBranding(r.data.branding || null);
                    }
                } catch (err) {
                    setDashboardFetching(false);
                    if (!hasVisibleDashboard && !cached) {
                        $('#ss_sales_dashboard_root').html(
                            '<div class="ss-wa-empty">' + esc(i18n.renderFailed || 'Could not render dashboard.') + ' ' + esc(err.message || String(err)) + '</div>'
                        );
                    }
                }
            })
            .fail(function (xhr, statusText) {
                if (seq !== dashboardLoadSeq || statusText === 'abort') {
                    return;
                }
                setDashboardFetching(false);
                if (cached || hasVisibleDashboard) {
                    return;
                }
                var msg = i18n.requestFailed;
                if (statusText === 'timeout') {
                    msg = i18n.timeout || 'Request timed out — try a shorter period or click Refresh.';
                } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                }
                $('#ss_sales_dashboard_root').html('<div class="ss-wa-empty">' + esc(msg) + '</div>');
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

    function updateFilterSummary() {
        var $summary = $('#ss_sd_filter_summary');
        if (!$summary.length) {
            return;
        }
        var label = $('.ss-wa-date-pill.active').first().text();
        $summary.text($.trim(label) || 'Last 7 Days');
    }

    function toggleSalesFilters(open) {
        var $body = $('#ss-wa-sec-export');
        var $btn = $('#ss_sd_filter_toggle');
        if (!$body.length || !$btn.length) {
            return;
        }
        var isOpen = !$body.prop('hidden');
        var show = typeof open === 'boolean' ? open : !isOpen;
        $body.prop('hidden', !show);
        $btn.attr('aria-expanded', show ? 'true' : 'false').toggleClass('is-open', show);
        try {
            if (show) {
                sessionStorage.setItem('ss_sd_filters_open', '1');
            } else {
                sessionStorage.removeItem('ss_sd_filters_open');
            }
        } catch (e) {}
    }

    window.ssToggleSalesFilters = toggleSalesFilters;

    function setPeriod(period) {
        $('.ss-wa-date-pill').removeClass('active');
        $('.ss-wa-date-pill[data-period="' + period + '"]').addClass('active');
        updateFilterSummary();
        loadDashboard(period);
        if (typeof window.ssScheduleDashboardSave === 'function') {
            window.ssScheduleDashboardSave();
        }
    }

    function scrollToSection(id) {
        if (id === 'ss-wa-sec-export') {
            toggleSalesFilters(true);
            id = 'ss_sd_filter_wrap';
        }
        var $el = $('#' + id);
        if (!$el.length) {
            return;
        }

        var $main = $('.ss-wa-main');
        if ($main.length && window.innerWidth > 900) {
            var mainEl = $main.get(0);
            var elTop = $el.get(0).getBoundingClientRect().top;
            var mainTop = mainEl.getBoundingClientRect().top;
            var nextScroll = mainEl.scrollTop + (elTop - mainTop) - 72;
            $main.animate({ scrollTop: Math.max(0, nextScroll) }, 400);
            return;
        }

        $('html, body').animate({ scrollTop: $el.offset().top - 80 }, 400);
    }

    $(function () {
        if (!$('#ss_sales_dashboard_root').length) return;

        if (typeof sheetsync !== 'undefined' && sheetsync.currency_symbol) {
            currencySymbol = sheetsync.currency_symbol;
        }

        initDashboardTheme();
        updateFilterSummary();

        try {
            if (sessionStorage.getItem('ss_sd_filters_open') === '1') {
                toggleSalesFilters(true);
            }
        } catch (e) {}

        var initial = $('#ss_sd_period_hidden').val() || '7days';
        loadDashboard(initial, false, { instantCache: true });
        window.ssSalesDashReload = function (period, forceRefresh) {
            loadDashboard(period || ($('#ss_sd_period').val() || '7days'), forceRefresh, { instantCache: false });
        };

        $(document).on('click', '#ss_sd_filter_toggle', function () {
            toggleSalesFilters();
        });

        $(document).on('click', '#ss_wa_theme_toggle, #ss_inv_theme_toggle, #ss_boe_theme_toggle', function () {
            toggleDashboardTheme();
        });

        $(document).on('click', '.ss-wa-date-pill', function () {
            setPeriod($(this).data('period'));
        });

        $(document).on('click', '#ss_sd_refresh_btn, [data-action="ss_qa_refresh"]', function () {
            var $b = $(this);
            btnLoad($b, true);
            loadDashboard($('#ss_sd_period').val() || '7days', true, { instantCache: false });
            setTimeout(function () { btnLoad($b, false); }, 800);
        });

        $(document).on('click', '#ss_rev_tabs .ss-wa-chart-tab', function () {
            $('#ss_rev_tabs .ss-wa-chart-tab').removeClass('active');
            $(this).addClass('active');
            switchRevTab($(this).data('mode'));
        });

        $(document).on('click', '#ss_sd_export_btn, [data-action="ss_qa_export"]', function () {
            var $b = $(this), $res = $('#ss_sd_result');
            if (demoExportBlocked($res)) {
                return;
            }
            var AJAX = (typeof sheetsync !== 'undefined') ? sheetsync.ajax_url : ajaxurl;
            var NONCE = (typeof sheetsync !== 'undefined') ? sheetsync.nonce : '';
            var sid = $.trim($('#ss_sd_spreadsheet_id').val());
            if (!sid) {
                showRes($res, '&#10060; ' + i18n.spreadsheetRequired, 'error');
                scrollToSection('ss-wa-sec-export');
                return;
            }
            btnLoad($b, true);
            showRes($res, '<span class="dashicons dashicons-update ss-spin"></span> ' + i18n.exporting, 'loading');
            $.post(AJAX, $.extend({
                action: 'sheetsync_export_sales_dashboard',
                nonce: NONCE,
                spreadsheet_id: sid,
                sheet_name: $('#ss_sd_sheet_name').val() || 'Sales Dashboard',
                period: $('#ss_sd_period').val() || '7days'
            }, getActiveFilters())).done(function (r) {
                btnLoad($b, false);
                if (r.success) {
                    var msg = '&#9989; ' + r.data.message;
                    if (r.data.sheet_url) {
                        msg += '<br><a href="' + r.data.sheet_url + '" target="_blank" rel="noopener">' + i18n.openSheet + ' &rarr;</a>';
                    }
                    showRes($res, msg, 'success');
                } else {
                    showRes($res, '&#10060; ' + ((r.data && r.data.message) || i18n.exportFailed), 'error');
                }
            }).fail(function () {
                btnLoad($b, false);
                showRes($res, '&#10060; ' + i18n.requestFailed, 'error');
            });
        });

        $(document).on('input change', '#ss_sd_spreadsheet_id,#ss_sd_sheet_name', function () {
            if (typeof window.ssScheduleDashboardSave === 'function') {
                window.ssScheduleDashboardSave();
            }
        });

        $(document).on('click', '.ss-wa-nav-item[data-section]', function (e) {
            e.preventDefault();
            var section = $(this).data('section');
            $('.ss-wa-nav-item').removeClass('active');
            $(this).addClass('active');
            scrollToSection(section);
            $('#ss_wa_sidebar').removeClass('open');
            $('#ss_wa_overlay').removeClass('open');
            $('body').removeClass('ss-wa-menu-open');
        });

        $(document).on('click', '.ss-wa-qa-btn[data-section]', function () {
            scrollToSection($(this).data('section'));
        });

        $(document).on('click', '#ss_wa_hamburger', function () {
            $('#ss_wa_sidebar').toggleClass('open');
            $('#ss_wa_overlay').toggleClass('open');
            $('body').toggleClass('ss-wa-menu-open', $('#ss_wa_sidebar').hasClass('open'));
        });

        $(document).on('click', '#ss_wa_overlay', function () {
            $('#ss_wa_sidebar').removeClass('open');
            $(this).removeClass('open');
            $('body').removeClass('ss-wa-menu-open');
        });

        $(window).on('resize', function () {
            if (window.innerWidth > 900) {
                $('#ss_wa_sidebar').removeClass('open');
                $('#ss_wa_overlay').removeClass('open');
                $('body').removeClass('ss-wa-menu-open');
            }
        });

        $(document).on('input', '#ss_order_search', function () {
            var q = $(this).val().toLowerCase();
            $('#ss_orders_table tbody tr').each(function () {
                $(this).toggle($(this).text().toLowerCase().indexOf(q) >= 0);
            });
        });

        $(document).on('click', '#ss_wa_filter_reset', function () {
            $('#ss_wa_filter_selects select').val('');
            loadDashboard($('#ss_sd_period').val() || '7days', false, { instantCache: false });
        });

        $(document).on('click', '#ss_wa_filter_apply', function () {
            loadDashboard($('#ss_sd_period').val() || '7days', false, { instantCache: false });
        });

        window.ssWaTheme = {
            isLight: isLightTheme,
            apply: applyDashboardTheme,
            toggle: toggleDashboardTheme
        };
    });
})(jQuery);
