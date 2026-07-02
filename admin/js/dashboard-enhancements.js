/**
 * SheetSync Dashboard enhancements — onboarding, automation, presets, export log.
 */
(function ($) {
    'use strict';

    var i18n = window.ssDashEnhI18n || {};
    var fieldLabels = {};
    var templates = {};
    var presets = [];

    function ajaxUrl() {
        return (typeof sheetsync !== 'undefined') ? sheetsync.ajax_url : ajaxurl;
    }

    function nonce() {
        return (typeof sheetsync !== 'undefined') ? sheetsync.nonce : '';
    }

    function esc(str) {
        return $('<div>').text(str || '').html();
    }

    function sheetsUrl(id) {
        id = $.trim(id || '');
        if (!id) {
            return '';
        }
        return 'https://docs.google.com/spreadsheets/d/' + encodeURIComponent(id) + '/edit';
    }

    function selectedFields() {
        var fields = [];
        $('#ss_boe_field_picker input[type="checkbox"]:checked').each(function () {
            fields.push($(this).val());
        });
        return fields;
    }

    window.ssBoeSelectedFields = selectedFields;

    function renderFieldPicker(fields, selected) {
        selected = selected || [];
        var html = '<div class="ss-boe-field-picker-toolbar"><div class="ss-boe-field-picker-grid">';
        Object.keys(fields).forEach(function (key) {
            var checked = !selected.length || selected.indexOf(key) >= 0;
            html += '<label class="ss-boe-field-check"><input type="checkbox" name="boe_fields[]" value="' + esc(key) + '"' + (checked ? ' checked' : '') + ' /><span>' + esc(fields[key]) + '</span></label>';
        });
        html += '</div><button type="button" class="ss-wa-btn ss-boe-select-all" id="ss_boe_select_all">' + esc(i18n.selectAll || 'Select all') + '</button></div>';
        $('#ss_boe_field_picker').html(html);
        fieldLabels = fields;
    }

    function loadPresets() {
        $.post(ajaxUrl(), { action: 'sheetsync_get_boe_presets', nonce: nonce() }).done(function (r) {
            if (!r.success) {
                return;
            }
            templates = r.data.templates || {};
            presets = r.data.presets || [];
            if (r.data.field_labels) {
                fieldLabels = r.data.field_labels;
                renderFieldPicker(fieldLabels, []);
            }

            var $tpl = $('#ss_boe_template_select').empty().append('<option value="">' + esc(i18n.templates || 'Quick Templates') + '…</option>');
            Object.keys(templates).forEach(function (key) {
                $tpl.append('<option value="' + esc(key) + '">' + esc(templates[key].label || key) + '</option>');
            });

            var $pre = $('#ss_boe_preset_select').empty().append('<option value="">' + esc(i18n.presets || 'Saved Presets') + '…</option>');
            var $sched = $('#ss_boe_schedule_preset');
            var schedVal = $sched.val() || '';
            if ($sched.length) {
                $sched.empty().append('<option value="">' + esc(i18n.scheduleWindow || 'Use interval window above') + '</option>');
            }
            presets.forEach(function (p) {
                $pre.append('<option value="' + esc(p.id) + '">' + esc(p.name) + '</option>');
                if ($sched.length) {
                    $sched.append('<option value="' + esc(p.id) + '">' + esc(p.name) + '</option>');
                }
            });
            if ($sched.length && schedVal) {
                $sched.val(schedVal);
            }

            if (Object.keys(fieldLabels).length === 0 && templates.full) {
                renderFieldPicker(buildLabelsFromTemplate('full'), templates.full.fields || []);
            }
        });
    }

    function buildLabelsFromTemplate(key) {
        var tpl = templates[key];
        if (!tpl || !tpl.fields) {
            return fieldLabels;
        }
        var labels = {};
        tpl.fields.forEach(function (f) {
            if (fieldLabels[f]) {
                labels[f] = fieldLabels[f];
            } else {
                labels[f] = f;
            }
        });
        return labels;
    }

    function applyPreset(preset) {
        if (!preset) {
            return;
        }
        $('input[name="boe_statuses[]"]').prop('checked', false);
        (preset.statuses || []).forEach(function (st) {
            $('input[name="boe_statuses[]"][value="' + st + '"]').prop('checked', true);
        });
        $('#ss_boe_date_from').val(preset.date_from || '');
        $('#ss_boe_date_to').val(preset.date_to || '');
        $('#ss_boe_customer').val(preset.customer || '');
        $('#ss_boe_min_total').val(preset.min_total || '');
        $('#ss_boe_max_total').val(preset.max_total || '');
        if (preset.fields && preset.fields.length) {
            renderFieldPicker(fieldLabels, preset.fields);
        }
        if (typeof window.ssBoeDashLoad === 'function') {
            window.ssBoeDashLoad(true);
        }
    }

    function renderOnboarding(state) {
        if (!state || state.dismissed) {
            $('#ss_dash_onboarding').hide();
            return;
        }
        var steps = [
            { key: 'sheet_connected', label: i18n.stepSheet || 'Connect Spreadsheet' },
            { key: 'sales_exported', label: i18n.stepSales || 'Export Sales' },
            { key: 'inventory_viewed', label: i18n.stepInventory || 'View Inventory' },
            { key: 'orders_exported', label: i18n.stepOrders || 'Export Orders' }
        ];
        var done = 0;
        var pills = '';
        steps.forEach(function (s) {
            var ok = !!state.steps[s.key];
            if (ok) {
                done++;
            }
            pills += '<span class="ss-dash-onboarding-pill' + (ok ? ' done' : '') + '">' + (ok ? '✓' : '') + ' ' + esc(s.label) + '</span>';
        });
        var html = '<div class="ss-dash-onboarding-inner">'
            + '<span class="ss-dash-onboarding-badge">' + done + '/' + steps.length + '</span>'
            + '<span class="ss-dash-onboarding-title">' + esc(i18n.onboardingTitle || 'Get started') + '</span>'
            + '<div class="ss-dash-onboarding-pills">' + pills + '</div>'
            + '<button type="button" class="ss-dash-onboarding-dismiss" id="ss_onboarding_dismiss" title="' + esc(i18n.dismiss || 'Dismiss') + '">&times;</button>'
            + '</div>';
        $('#ss_dash_onboarding').html(html).show();
    }

    function toggleAutomation(open) {
        var $panel = $('#ss_dash_automation');
        var $backdrop = $('#ss_dash_automation_backdrop');
        var $btn = $('#ss_dash_automation_toggle');
        if (!$panel.length) {
            return;
        }
        var isOpen = $panel.hasClass('is-open');
        var show = typeof open === 'boolean' ? open : !isOpen;

        if (show) {
            $panel.prop('hidden', false);
            if ($backdrop.length) {
                $backdrop.prop('hidden', false).attr('aria-hidden', 'false');
            }
            requestAnimationFrame(function () {
                $panel.addClass('is-open');
                if ($backdrop.length) {
                    $backdrop.addClass('is-visible');
                }
            });
            $('body').addClass('ss-dash-automation-open');
            $btn.attr('aria-expanded', 'true').addClass('is-open');
            if (typeof loadExportLog === 'function') {
                loadExportLog();
            }
            return;
        }

        $panel.removeClass('is-open');
        if ($backdrop.length) {
            $backdrop.removeClass('is-visible').attr('aria-hidden', 'true');
        }
        $('body').removeClass('ss-dash-automation-open');
        $btn.attr('aria-expanded', 'false').removeClass('is-open');
        setTimeout(function () {
            if (!$panel.hasClass('is-open')) {
                $panel.prop('hidden', true);
                if ($backdrop.length) {
                    $backdrop.prop('hidden', true);
                }
            }
        }, 280);
    }

    function loadOnboarding() {
        $.post(ajaxUrl(), { action: 'sheetsync_get_onboarding_state', nonce: nonce() }).done(function (r) {
            if (r.success) {
                renderOnboarding(r.data);
            }
        });
    }

    function autoDetectOnboarding() {
        var hasSheet = $.trim($('#ss_sd_spreadsheet_id').val()) || $.trim($('#ss_inv_spreadsheet_id').val()) || $.trim($('#ss_boe_spreadsheet_id').val());
        if (hasSheet) {
            $.post(ajaxUrl(), { action: 'sheetsync_update_onboarding', nonce: nonce(), key: 'sheet_connected', value: '1' });
        }
    }

    function renderExportLog(log) {
        if (!log || !log.length) {
            $('#ss_dash_export_log').html('<p class="ss-dash-muted">' + esc(i18n.noLog || 'No exports yet.') + '</p>');
            return;
        }
        var html = '<table class="ss-dash-log-table"><thead><tr><th>Type</th><th>Status</th><th>Message</th><th>When</th><th>User</th></tr></thead><tbody>';
        log.forEach(function (row) {
            html += '<tr><td>' + esc(row.type) + '</td><td>' + (row.success ? '✅' : '❌') + '</td><td>' + esc(row.message) + '</td><td>' + esc(row.time) + '</td><td>' + esc(row.user_name) + '</td></tr>';
        });
        html += '</tbody></table>';
        $('#ss_dash_export_log').html(html);
    }

    function loadExportLog() {
        $.post(ajaxUrl(), { action: 'sheetsync_get_export_log', nonce: nonce() }).done(function (r) {
            if (r.success) {
                renderExportLog(r.data);
            }
        });
    }

    window.ssDashEnhApplySettings = function (s) {
        if (s && typeof window.ssApplyDashboardBranding === 'function') {
            window.ssApplyDashboardBranding({
                app_name: s.wl_app_name || '',
                logo_url: s.wl_logo_url || '',
                primary_color: s.wl_primary_color || '#6c63ff',
                accent_color: s.wl_accent_color || '#22d3a5',
                hide_pro_badge: s.wl_hide_pro_badge === '1'
            });
        }
    };

    window.ssDashEnhRefreshLog = loadExportLog;

    function openPdfReport(type, extra) {
        $.post(ajaxUrl(), $.extend({
            action: 'sheetsync_dashboard_pdf_report',
            nonce: nonce(),
            report_type: type
        }, extra || {})).done(function (r) {
            if (!r.success || !r.data.html) {
                return;
            }
            var w = window.open('', '_blank');
            if (!w) {
                return;
            }
            w.document.open();
            w.document.write(r.data.html);
            w.document.close();
        });
    }

    function downloadExportLogCsv() {
        $.post(ajaxUrl(), { action: 'sheetsync_download_export_log_csv', nonce: nonce() }).done(function (r) {
            if (!r.success || !r.data.csv) {
                return;
            }
            var blob = new Blob([r.data.csv], { type: 'text/csv;charset=utf-8;' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = r.data.filename || 'export-history.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });
    }

    function reloadAllDashboards() {
        if (typeof window.ssSalesDashReload === 'function') {
            window.ssSalesDashReload();
        }
        if (typeof window.ssInvDashLoad === 'function') {
            window.ssInvDashLoad(true);
        }
        if (typeof window.ssBoeDashLoad === 'function') {
            window.ssBoeDashLoad(true);
        }
    }

    var searchTimer;
    function showSearchBox(html) {
        var $box = $('#ss_dash_search_results');
        $box.html(html).removeAttr('hidden').addClass('is-open');
    }

    function hideSearchBox() {
        $('#ss_dash_search_results').attr('hidden', 'hidden').removeClass('is-open').empty();
    }

    function runGlobalSearch(q) {
        showSearchBox('<p class="ss-dash-search-loading">' + esc(i18n.searchLoading || 'Searching…') + '</p>');
        $.post(ajaxUrl(), { action: 'sheetsync_global_dashboard_search', nonce: nonce(), q: q })
            .done(function (r) {
                if (!r.success || !r.data.results || !r.data.results.length) {
                    showSearchBox('<p class="ss-dash-search-empty">' + esc(i18n.searchEmpty || 'No results found.') + '</p>');
                    return;
                }
                var html = '';
                r.data.results.forEach(function (item) {
                    html += '<button type="button" class="ss-dash-search-item" data-tab="' + esc(item.tab) + '" data-url="' + esc(item.url || '') + '">'
                        + '<span class="ss-dash-search-type">' + esc(item.type) + '</span>'
                        + '<span class="ss-dash-search-label">' + esc(item.label) + '</span>'
                        + '<span class="ss-dash-search-meta">' + esc(item.meta || '') + '</span></button>';
                });
                showSearchBox(html);
            })
            .fail(function () {
                showSearchBox('<p class="ss-dash-search-empty">' + esc(i18n.searchFailed || 'Search failed. Please try again.') + '</p>');
            });
    }

    $(function () {
        if (!$('.ss-dash-tab-nav').length) {
            return;
        }

        loadOnboarding();
        loadPresets();
        setTimeout(autoDetectOnboarding, 1200);

        var $activeTab = $('.ss-dash-tab-btn.ss-dash-tab-active:visible');
        if (!$activeTab.length) {
            var $firstTab = $('.ss-dash-tab-btn:visible').first();
            if ($firstTab.length) {
                $firstTab.trigger('click');
            }
        }

        $(document).on('click', '#ss_dash_automation_toggle', function () {
            toggleAutomation();
        });

        $(document).on('click', '#ss_dash_automation_close, #ss_dash_automation_backdrop', function () {
            toggleAutomation(false);
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $('#ss_dash_automation').hasClass('is-open')) {
                toggleAutomation(false);
            }
        });

        $(document).on('click', '#ss_onboarding_dismiss', function () {
            $.post(ajaxUrl(), { action: 'sheetsync_update_onboarding', nonce: nonce(), key: 'dismissed', value: '1' }).done(function () {
                $('#ss_dash_onboarding').slideUp();
            });
        });

        $(document).on('click', '.ss-dash-tab-btn[data-target="ss-dash-panel-inventory"]', function () {
            $.post(ajaxUrl(), { action: 'sheetsync_update_onboarding', nonce: nonce(), key: 'inventory_viewed', value: '1' });
        });

        $(document).on('change', '#ss_boe_template_select', function () {
            var key = $(this).val();
            if (!key || !templates[key]) {
                return;
            }
            renderFieldPicker(fieldLabels, templates[key].fields || []);
            if (typeof window.ssBoeDashLoad === 'function') {
                window.ssBoeDashLoad(true);
            }
        });

        $(document).on('change', '#ss_boe_preset_select', function () {
            var id = $(this).val();
            if (!id) {
                return;
            }
            var preset = presets.filter(function (p) { return p.id === id; })[0];
            applyPreset(preset);
        });

        $(document).on('click', '#ss_boe_save_preset', function () {
            var name = window.prompt(i18n.savePreset || 'Preset name');
            if (!name) {
                return;
            }
            var statuses = [];
            $('input[name="boe_statuses[]"]:checked').each(function () { statuses.push($(this).val()); });
            $.post(ajaxUrl(), {
                action: 'sheetsync_save_boe_preset',
                nonce: nonce(),
                name: name,
                statuses: statuses,
                date_from: $('#ss_boe_date_from').val(),
                date_to: $('#ss_boe_date_to').val(),
                customer: $('#ss_boe_customer').val(),
                min_total: $('#ss_boe_min_total').val(),
                max_total: $('#ss_boe_max_total').val(),
                fields: selectedFields()
            }).done(function (r) {
                if (r.success) {
                    presets = r.data.presets || [];
                    loadPresets();
                }
            });
        });

        $(document).on('click', '#ss_boe_delete_preset', function () {
            var id = $('#ss_boe_preset_select').val();
            if (!id) {
                return;
            }
            $.post(ajaxUrl(), { action: 'sheetsync_delete_boe_preset', nonce: nonce(), id: id }).done(function (r) {
                if (r.success) {
                    presets = r.data.presets || [];
                    loadPresets();
                }
            });
        });

        $(document).on('click', '#ss_boe_select_all', function () {
            $('#ss_boe_field_picker input[type="checkbox"]').prop('checked', true);
        });

        $(document).on('change', '#ss_boe_field_picker input', function () {
            if (typeof window.ssBoeDashLoad === 'function') {
                window.ssBoeDashLoad(true);
            }
        });

        // Patch export success handlers to refresh log + onboarding
        $(document).ajaxSuccess(function (_e, _xhr, opts) {
            if (!opts.data) {
                return;
            }
            var action = '';
            if (typeof opts.data === 'string' && opts.data.indexOf('action=') >= 0) {
                var m = opts.data.match(/action=([^&]+)/);
                action = m ? decodeURIComponent(m[1]) : '';
            } else if (opts.data.action) {
                action = opts.data.action;
            }
            if (action === 'sheetsync_export_sales_dashboard') {
                $.post(ajaxUrl(), { action: 'sheetsync_update_onboarding', nonce: nonce(), key: 'sales_exported', value: '1' });
                loadExportLog();
                loadOnboarding();
                if (typeof window.ssRefreshLastSynced === 'function') {
                    window.ssRefreshLastSynced();
                }
            }
            if (action === 'sheetsync_bulk_order_export_sheets') {
                $.post(ajaxUrl(), { action: 'sheetsync_update_onboarding', nonce: nonce(), key: 'orders_exported', value: '1' });
                loadExportLog();
                loadOnboarding();
                if (typeof window.ssRefreshLastSynced === 'function') {
                    window.ssRefreshLastSynced();
                }
            }
            if (action === 'sheetsync_export_inventory_dashboard') {
                loadExportLog();
                if (typeof window.ssRefreshLastSynced === 'function') {
                    window.ssRefreshLastSynced();
                }
            }
            if (action === 'sheetsync_save_dashboard_settings') {
                loadOnboarding();
            }
        });

        // Expose open-sheet helper for result areas
        window.ssDashOpenSheetLink = function (spreadsheetId) {
            var url = sheetsUrl(spreadsheetId);
            if (!url) {
                return '';
            }
            return ' <a href="' + url + '" target="_blank" rel="noopener">' + esc(i18n.openSheet || 'Open in Google Sheets') + ' →</a>';
        };

        $(document).on('input', '#ss_dash_global_search', function () {
            var q = $.trim($(this).val());
            clearTimeout(searchTimer);
            if (q.length < 2) {
                hideSearchBox();
                return;
            }
            searchTimer = setTimeout(function () { runGlobalSearch(q); }, 280);
        });

        $(document).on('focus', '#ss_dash_global_search', function () {
            var q = $.trim($(this).val());
            if (q.length >= 2 && !$('#ss_dash_search_results').hasClass('is-open')) {
                runGlobalSearch(q);
            }
        });

        $(document).on('click', '.ss-dash-search-item', function () {
            var tab = $(this).data('tab');
            var url = $(this).data('url');
            if (tab === 'orders') {
                $('.ss-dash-tab-btn[data-target="ss-dash-panel-orders"]').trigger('click');
            } else if (tab === 'inventory') {
                $('.ss-dash-tab-btn[data-target="ss-dash-panel-inventory"]').trigger('click');
            } else {
                $('.ss-dash-tab-btn[data-target="ss-dash-panel-sales"]').trigger('click');
            }
            if (url) {
                window.open(url, '_blank');
            }
            hideSearchBox();
        });

        $(document).on('change', '#ss_demo_mode_toggle', function () {
            var on = $(this).is(':checked');
            $.post(ajaxUrl(), { action: 'sheetsync_toggle_demo_mode', nonce: nonce(), enabled: on ? '1' : '0' }).done(function () {
                $('#ss_dash_demo_banner').toggle(on);
                if (typeof window.ssScheduleDashboardSave === 'function') {
                    window.ssScheduleDashboardSave();
                }
                reloadAllDashboards();
            });
        });

        $(document).on('click', '#ss_export_log_csv_btn', downloadExportLogCsv);

        $(document).on('click', '#ss_sd_pdf_btn', function () {
            openPdfReport('sales', {
                period: $('#ss_sd_period_hidden').val() || $('#ss_sd_period').val() || '7days'
            });
        });

        $(document).on('click', '#ss_inv_pdf_btn', function () {
            openPdfReport('inventory', {
                low_stock_threshold: $('#ss_inv_low_stock').val() || 5
            });
        });

        $(document).on('click', function (e) {
            if (!$(e.target).closest('.ss-dash-global-search-wrap').length) {
                hideSearchBox();
            }
        });

        window.ssApplyDashboardBranding = function (branding) {
            branding = branding || {};
            var primary = branding.primary_color || $('#ss_wl_primary_color').val() || '#6c63ff';
            var accent = branding.accent_color || $('#ss_wl_accent_color').val() || '#22d3a5';
            var $wrap = $('.sheetsync-wrap.ss-wa-theme');
            $wrap.css('--ss-wl-primary', primary).css('--ss-wl-accent', accent);
            document.documentElement.style.setProperty('--ss-wl-primary', primary);
            document.documentElement.style.setProperty('--ss-wl-accent', accent);

            if (branding.app_name) {
                $('.ss-wa-logo-text').each(function () {
                    var $t = $(this);
                    var badge = $t.find('.ss-wa-logo-badge');
                    $t.text(branding.app_name + ' ');
                    if (badge.length && !branding.hide_pro_badge) {
                        $t.append(badge);
                    }
                });
            }
            if (branding.logo_url) {
                $('.ss-wa-logo-icon').each(function () {
                    $(this).html('<img src="' + esc(branding.logo_url) + '" alt="" class="ss-wl-logo-img" />');
                });
            }
            if (branding.hide_pro_badge) {
                $('.ss-wa-logo-badge').hide();
            } else {
                $('.ss-wa-logo-badge').show();
            }
        };
    });
})(jQuery);
