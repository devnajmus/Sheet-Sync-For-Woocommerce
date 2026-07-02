/* global sheetsync, jQuery */
( function( $ ) {
    'use strict';

    // ── Manual Sync ────────────────────────────────────────────────────────────
    const jobWatchers = {};

    function isServerBusyMessage( msg ) {
        if ( ! msg ) {
            return false;
        }
        return /503|service unavailable|timeout|connection pause|max execution|memory limit/i.test( String( msg ) );
    }

    function logsUrlForConnection( connId ) {
        const base = sheetsync.logs_url || '';
        if ( ! base ) {
            return '';
        }
        if ( ! connId ) {
            return base;
        }
        return base + ( base.indexOf( '?' ) >= 0 ? '&' : '?' ) + 'connection_id=' + encodeURIComponent( connId );
    }

    function isSheetTabError( msg ) {
        if ( ! msg ) {
            return false;
        }
        const m = String( msg ).toLowerCase();
        return m.indexOf( 'not found in spreadsheet' ) >= 0 || m.indexOf( 'sheet tab' ) >= 0;
    }

    function buildSyncErrorDetails( msg, connId, schedulerUrl, opts ) {
        opts = opts || {};
        let html = '';
        if ( isServerBusyMessage( msg ) && sheetsync.i18n.server_busy_hint ) {
            html += '<div class="ss-toast-detail">' + $( '<span>' ).text( sheetsync.i18n.server_busy_hint ).html() + '</div>';
        }
        const validationErrors = opts.validation_errors || [];
        if ( validationErrors.length ) {
            html += '<ul class="ss-sync-error-list">';
            validationErrors.forEach( function( err ) {
                html += '<li>' + $( '<span>' ).text( err ).html() + '</li>';
            } );
            html += '</ul>';
        }
        const fixUrl = opts.fix_url || '';
        const sheetNotReady = !! opts.sheet_not_ready || isSheetTabError( msg );
        if ( fixUrl ) {
            html += '<div class="ss-toast-detail"><a class="button button-small button-primary" href="' +
                fixUrl + '">' + ( sheetsync.i18n.fix_connection || 'Fix connection' ) + '</a></div>';
        } else if ( sheetNotReady && connId ) {
            const connFix = 'admin.php?page=sheetsync&sheetsync_action=edit&connection_id=' +
                encodeURIComponent( connId ) + '#tab-connection';
            html += '<div class="ss-toast-detail"><a class="button button-small button-primary" href="' +
                connFix + '">' + ( sheetsync.i18n.fix_connection || 'Fix connection' ) + '</a></div>';
        }
        const logsUrl = logsUrlForConnection( connId );
        if ( logsUrl ) {
            html += '<div class="ss-toast-detail"><a href="' + logsUrl + '">' +
                ( sheetsync.i18n.view_logs || 'View sync logs' ) + '</a></div>';
        }
        if ( schedulerUrl ) {
            html += '<div class="ss-toast-detail"><a href="' + schedulerUrl + '" target="_blank" rel="noopener">' +
                ( sheetsync.i18n.scheduler_blocked || 'Fix Scheduled Actions' ) + '</a></div>';
        }
        if ( ! sheetNotReady ) {
            html += '<div class="ss-toast-detail"><button type="button" class="button button-small ss-sync-retry-btn">' +
                ( sheetsync.i18n.sync_retry || 'Run Sync Again' ) + '</button></div>';
        }
        return html;
    }

    function buildSyncErrorHtml( msg, connId, schedulerUrl, opts ) {
        return '❌ ' + $( '<span>' ).text( msg || sheetsync.i18n.sync_error ).html() +
            buildSyncErrorDetails( msg, connId, schedulerUrl, opts );
    }

    function buildSchedulerGateMessage( d ) {
        const ctx  = sheetsync.export_confirm || {};
        const rows = parseInt( d.total_estimate, 10 ) || parseInt( ctx.sheet_rows, 10 ) || 0;
        const eta  = parseInt( ctx.eta_minutes, 10 ) || 0;
        let tpl    = sheetsync.i18n.confirm_scheduler_degraded_large || sheetsync.i18n.confirm_scheduler_degraded
            || 'Background tasks are overdue. Fix Scheduled Actions first, or run slow sync and keep this tab open.';
        if ( rows > 0 ) {
            tpl = tpl.replace( /%1\$d/g, String( rows ) );
        }
        if ( eta > 0 ) {
            const slowEta = Math.max( eta, Math.ceil( eta * 2.2 ) );
            tpl = tpl.replace( /%2\$d/g, String( eta ) ).replace( /%3\$d/g, String( slowEta ) );
        }
        return tpl;
    }

    function effectivePollMs( job ) {
        const base = parseInt( sheetsync.sync_poll_ms, 10 ) || 2500;
        if ( job && ( job.scheduler_degraded || sheetsync.scheduler_degraded ) ) {
            return Math.max( base, parseInt( sheetsync.sync_poll_ms_slow, 10 ) || 4500 );
        }
        return base;
    }

    function effectiveDrainMs( job ) {
        const base = parseInt( sheetsync.sync_drain_ms, 10 ) || 3000;
        if ( job && ( job.scheduler_degraded || sheetsync.scheduler_degraded ) ) {
            return Math.max( base, parseInt( sheetsync.sync_drain_ms_slow, 10 ) || 5500 );
        }
        return base;
    }

    function buildFullExportConfirmMessage() {
        const ctx = sheetsync.export_confirm || {};
        const total = parseInt( ctx.sheet_rows, 10 ) || 0;
        const parents = parseInt( ctx.parent_products, 10 ) || 0;
        const vars = parseInt( ctx.variations, 10 ) || 0;
        const eta = parseInt( ctx.eta_minutes, 10 ) || 0;
        const detailTpl = sheetsync.i18n.confirm_full_export_detail;
        if ( total > 0 && detailTpl ) {
            return detailTpl
                .replace( '%1$d', total )
                .replace( '%2$d', parents )
                .replace( '%3$d', vars )
                .replace( '%4$d', eta > 0 ? eta : '?' );
        }
        return sheetsync.i18n.confirm_full_export
            || 'Full catalog export writes every product to the sheet and removes stale rows when it completes. Continue?';
    }

    function confirmFullExportOptions( syncStrategy ) {
        if ( syncStrategy !== 'full' ) {
            return { confirmed: true, pullBefore: false };
        }
        if ( ! window.confirm( buildFullExportConfirmMessage() ) ) {
            return { confirmed: false, pullBefore: false };
        }
        let pullBefore = false;
        const ctx = sheetsync.export_confirm || {};
        if ( ctx.is_two_way && sheetsync.i18n.confirm_pull_before_export ) {
            pullBefore = window.confirm( sheetsync.i18n.confirm_pull_before_export );
        }
        return { confirmed: true, pullBefore: pullBefore };
    }

    function orchestratorSyncUrl( connId ) {
        const base = ( sheetsync.rest_url || '' ).replace( /\/$/, '' );
        return base + '/connections/' + connId + '/sync';
    }

    function runManualSync( $btn, extraOpts ) {
        extraOpts = extraOpts || {};

        const connId   = $btn.data( 'connection-id' );
        if ( ! extraOpts.forceDegraded && String( $btn.data( 'force-degraded' ) ) === '1' ) {
            extraOpts.forceDegraded = true;
            extraOpts.skipConfirm   = true;
        }
        let $result  = $btn.next( '.ss-sync-result' );
        if ( ! $result.length ) {
            $btn.after( '<span class="ss-sync-result"></span>' );
            $result = $btn.next( '.ss-sync-result' );
        }
        const origHtml = $btn.data( 'ss-orig-html' ) || $btn.html();
        $btn.data( 'ss-orig-html', origHtml );

        $btn.addClass( 'loading' ).html(
            '<span class="dashicons dashicons-update ss-spin"></span> ' + sheetsync.i18n.syncing
        );
        $btn.siblings( '.ss-sync-toast' ).remove();
        $result.remove();
        $( '#ss-manual-sync-panel .ss-sync-toast.ss-toast-error' ).remove();
        syncFeedbackAnchor( $btn ).siblings( '.ss-job-progress' ).remove();
        $( '.ss-sync-btn' ).not( $btn ).prop( 'disabled', true );

        let syncStrategy = extraOpts.syncStrategy || $btn.data( 'sync-strategy' ) || '';
        if ( ! syncStrategy ) {
            const $strategyInput = $( 'input[name="sync_strategy"]:checked' );
            if ( $strategyInput.length ) {
                syncStrategy = $strategyInput.val();
            } else {
                const $selectedCard = $( '.ss-strategy-card.selected input[name="sync_strategy"]' );
                if ( $selectedCard.length ) {
                    syncStrategy = $selectedCard.val();
                }
            }
        }

        const pullMode = extraOpts.pullMode || $btn.data( 'sync-pull-mode' ) || 'default';
        const jobDirection = extraOpts.jobDirection || $btn.data( 'sync-job-direction' ) || '';
        const intent       = extraOpts.intent || $btn.data( 'sync-intent' ) || '';
        const exportOpts = extraOpts.skipConfirm
            ? { confirmed: true, pullBefore: !! extraOpts.pullBeforeExport }
            : confirmFullExportOptions( syncStrategy );
        if ( ! exportOpts.confirmed ) {
            $btn.removeClass( 'loading' ).html( origHtml );
            $( '.ss-sync-btn' ).prop( 'disabled', false );
            return;
        }

        const $anchor   = syncFeedbackAnchor( $btn );
        const $progress = showProgressPlaceholder( $anchor );
        $progress.find( '.ss-toast-stats' ).after( editPanelProgressHintsHtml() );
        const watch     = startJobWatch( {
            connId   : connId,
            jobId    : 0,
            $btn     : $btn,
            origHtml : origHtml,
            $toast   : $progress,
            requiresTabOpen: false,
        } );

        const syncPayload = {
            intent               : intent,
            sync_strategy        : syncStrategy,
            sync_job_direction   : jobDirection,
            sync_pull_mode       : pullMode,
            confirm_full_export  : syncStrategy === 'full' ? 1 : 0,
            pull_before_export   : exportOpts.pullBefore ? 1 : 0,
            force_degraded_sync  : extraOpts.forceDegraded ? 1 : 0,
        };

        const useOrchestrator = !!( sheetsync.rest_url && sheetsync.rest_nonce );

        $.ajax( {
            url        : useOrchestrator ? orchestratorSyncUrl( connId ) : sheetsync.ajax_url,
            type       : useOrchestrator ? 'POST' : 'POST',
            timeout    : 28000,
            dataType   : 'json',
            contentType: useOrchestrator ? 'application/json' : 'application/x-www-form-urlencoded; charset=UTF-8',
            data       : useOrchestrator
                ? JSON.stringify( syncPayload )
                : Object.assign( { action: 'sheetsync_manual_sync', nonce: sheetsync.nonce, connection_id: connId }, syncPayload ),
            beforeSend : useOrchestrator
                ? function( xhr ) {
                    xhr.setRequestHeader( 'X-WP-Nonce', sheetsync.rest_nonce );
                }
                : undefined,
        } )
        .done( function( response ) {
            watch.setAjaxSettled( true );
            const d = useOrchestrator ? ( response || {} ) : ( response.data || {} );
            const ok = useOrchestrator ? ( response.job_id || response.success ) : response.success;

            if ( d.requires_tab_open ) {
                watch.setRequiresTabOpen( true );
                if ( $progress && sheetsync.i18n.requires_tab_open_hint ) {
                    $progress.find( '.ss-tab-open-hint' ).remove();
                    $progress.append(
                        '<div class="ss-toast-detail ss-tab-open-hint description">ℹ️ ' +
                        $( '<span>' ).text( sheetsync.i18n.requires_tab_open_hint ).html() +
                        '</div>'
                    );
                }
            }

            if ( d.scheduler_blocked && ! extraOpts.forceDegraded ) {
                watch.stop();
                $progress.remove();
                $btn.removeClass( 'loading' ).html( origHtml );
                $( '.ss-sync-btn' ).prop( 'disabled', false );
                const degradeMsg = buildSchedulerGateMessage( d );
                if ( window.confirm( degradeMsg ) ) {
                    runManualSync( $btn, {
                        intent         : intent,
                        syncStrategy   : syncStrategy,
                        pullMode       : pullMode,
                        jobDirection   : jobDirection,
                        skipConfirm    : true,
                        pullBeforeExport: exportOpts.pullBefore,
                        forceDegraded  : true,
                    } );
                } else {
                    const errHtml = buildSyncErrorHtml( d.message, connId, d.scheduler_tools_url || '' );
                    $btn.after( '<div class="ss-sync-toast ss-toast-error">' + errHtml + '</div>' );
                }
                return;
            }

            if ( d.job_id ) {
                watch.setJobId( d.job_id );
                watch.kick();
            }

            if ( ok && ! d.async && ! d.show_progress && ! d.job_id ) {
                watch.stop();
                if ( $progress ) {
                    $progress.remove();
                }
                $( '.ss-sync-btn' ).prop( 'disabled', false );
                renderSyncSuccessToast( $btn, d );
                return;
            }

            if ( ok || d.job_id || d.async || d.show_progress ) {
                if ( ( d.scheduler_warning || d.scheduler_degraded ) && d.scheduler_message && $progress ) {
                    $progress.find( '.ss-scheduler-hint' ).remove();
                    $progress.append(
                        '<div class="ss-toast-detail ss-scheduler-hint">⚠️ ' +
                        $( '<span>' ).text( d.scheduler_message ).html() + '</div>'
                    );
                }
                if ( ( d.scheduler_degraded || d.scheduler_soft_gate || sheetsync.server_friendly ) && $progress
                    && sheetsync.i18n.server_friendly_hint
                    && ! $progress.find( '.ss-server-friendly-hint' ).length ) {
                    $progress.append(
                        '<div class="ss-toast-detail ss-server-friendly-hint">ℹ️ ' +
                        $( '<span>' ).text( sheetsync.i18n.server_friendly_hint ).html() + '</div>'
                    );
                }
                return;
            }

            if ( ! watch.hasJob() ) {
                watch.stop();
                if ( $progress ) {
                    $progress.remove();
                }
                $( '.ss-sync-btn' ).prop( 'disabled', false );
                const msg = d.message || sheetsync.i18n.sync_error;
                const errOpts = {
                    fix_url           : d.fix_url || '',
                    sheet_not_ready   : !! d.sheet_not_ready,
                    validation_errors : d.validation_errors || [],
                };
                const errHtml = buildSyncErrorHtml( msg, connId, d.scheduler_tools_url || '', errOpts );
                const $err = $( '<div class="ss-sync-toast ss-toast-error">' + errHtml + '</div>' );
                $btn.after( $err );
                if ( ! errOpts.sheet_not_ready && ! isSheetTabError( msg ) ) {
                    $err.find( '.ss-sync-retry-btn' ).on( 'click', function() {
                        $err.remove();
                        runManualSync( $btn, {
                            intent         : intent,
                            syncStrategy   : syncStrategy,
                            pullMode       : pullMode,
                            jobDirection   : jobDirection,
                            skipConfirm    : syncStrategy !== 'full',
                            pullBeforeExport: exportOpts.pullBefore,
                            forceDegraded  : !! extraOpts.forceDegraded,
                        } );
                    } );
                }
            }
        } )
        .fail( function( xhr ) {
            watch.setAjaxSettled( true );
            const d = ( xhr && xhr.responseJSON ) ? xhr.responseJSON : {};
            if ( d.scheduler_blocked && ! extraOpts.forceDegraded ) {
                watch.stop();
                $progress.remove();
                $btn.removeClass( 'loading' ).html( origHtml );
                $( '.ss-sync-btn' ).prop( 'disabled', false );
                const degradeMsg = buildSchedulerGateMessage( d );
                if ( window.confirm( degradeMsg ) ) {
                    runManualSync( $btn, {
                        intent         : intent,
                        syncStrategy   : syncStrategy,
                        pullMode       : pullMode,
                        jobDirection   : jobDirection,
                        skipConfirm    : true,
                        pullBeforeExport: exportOpts.pullBefore,
                        forceDegraded  : true,
                    } );
                }
                return;
            }
            watch.stop();
            $( '.ss-sync-btn' ).prop( 'disabled', false );
            $btn.removeClass( 'loading' ).html( origHtml );
            if ( $progress ) {
                $progress.remove();
            }
            const failData = d.data || d;
            const failMsg  = failData.message || ( xhr.statusText || sheetsync.i18n.sync_error );
            const failOpts = {
                fix_url           : failData.fix_url || '',
                sheet_not_ready   : !! failData.sheet_not_ready,
                validation_errors : failData.validation_errors || [],
            };
            if ( failMsg && ( failOpts.sheet_not_ready || ! watch.hasJob() ) ) {
                const errHtml = buildSyncErrorHtml( failMsg, connId, failData.scheduler_tools_url || '', failOpts );
                $btn.after( '<div class="ss-sync-toast ss-toast-error">' + errHtml + '</div>' );
            }
        } )
        .always( function() {
            if ( ! $btn.data( 'ss-polling' ) ) {
                $btn.removeClass( 'loading' ).html( origHtml );
                $( '.ss-sync-btn' ).prop( 'disabled', false );
            }
        } );
    }

    $( document ).on( 'click', '.ss-sync-btn', function( e ) {
        if ( 'ss-sync-now-btn' === this.id ) {
            return;
        }
        e.preventDefault();
        runManualSync( $( this ) );
    } );

    $( document ).on( 'click', '#ss-sync-now-btn', function( e ) {
        e.preventDefault();
        const $intent = $( 'input[name="sync_intent"]:checked' );
        if ( ! $intent.length ) {
            return;
        }
        runManualSync( $( this ), {
            intent: $intent.val() || '',
            syncStrategy: $intent.attr( 'data-strategy' ) || 'smart',
            jobDirection: $intent.attr( 'data-job-direction' ) || '',
            pullMode:     $intent.attr( 'data-pull-mode' ) || 'default',
        } );
    } );

    $( document ).on( 'change', 'input[name="sync_intent"]', function() {
        $( '.ss-intent-card' ).removeClass( 'is-selected' );
        $( this ).closest( '.ss-intent-card' ).addClass( 'is-selected' );
    } );

    $( document ).on( 'click', '.ss-direction-pill:not(.is-active)', function( e ) {
        e.preventDefault();
        const $pill = $( this );
        if ( $pill.hasClass( 'is-locked' ) ) {
            const msg = sheetsync.i18n.direction_pro_required || '';
            if ( sheetsync.upgrade_url && window.confirm( msg ) ) {
                window.open( sheetsync.upgrade_url, '_blank', 'noopener' );
            }
            return;
        }

        const direction = $pill.data( 'direction' );
        const connId    = $pill.closest( '.ss-intent-sync-panel' ).data( 'connection-id' );
        if ( ! direction || ! connId ) {
            return;
        }

        const $hint = $pill.closest( '.ss-intent-sync-panel' ).find( '.ss-direction-save-hint' );
        const hintOrig = $hint.text();
        $pill.closest( '.ss-direction-pills' ).find( '.ss-direction-pill' ).prop( 'disabled', true );
        $pill.addClass( 'is-saving' );
        if ( sheetsync.i18n.direction_saving ) {
            $hint.text( sheetsync.i18n.direction_saving );
        }

        $.post( sheetsync.ajax_url, {
            action         : 'sheetsync_set_sync_direction',
            nonce          : sheetsync.nonce,
            connection_id  : connId,
            sync_direction : direction,
        } ).done( function( response ) {
            if ( response.success ) {
                if ( sheetsync.i18n.direction_saved ) {
                    $hint.text( sheetsync.i18n.direction_saved );
                }
                window.location.reload();
                return;
            }
            const msg = ( response.data && response.data.message ) ? response.data.message : sheetsync.i18n.sync_error;
            window.alert( msg );
            $pill.removeClass( 'is-saving' );
            $pill.closest( '.ss-direction-pills' ).find( '.ss-direction-pill' ).prop( 'disabled', false );
            $hint.text( hintOrig );
        } ).fail( function() {
            window.alert( sheetsync.i18n.sync_error );
            $pill.removeClass( 'is-saving' );
            $pill.closest( '.ss-direction-pills' ).find( '.ss-direction-pill' ).prop( 'disabled', false );
            $hint.text( hintOrig );
        } );
    } );

    $( document ).on( 'click', '.ss-tab-link', function( e ) {
        e.preventDefault();
        const href = $( this ).attr( 'href' );
        if ( ! href ) {
            return;
        }
        const $tab = $( '.ss-tab[href="' + href + '"]' );
        if ( $tab.length ) {
            $tab.trigger( 'click' );
        }
    } );

    function syncFeedbackAnchor( $btn ) {
        const $actions = $btn.closest( '.ss-sync-actions' );
        if ( $actions.length ) {
            return $actions;
        }
        const $card = $btn.closest( '.sheetsync-card' );
        return $card.length ? $card : $btn;
    }

    function progressToastHtml() {
        return (
            '<div class="ss-sync-toast ss-toast-success ss-job-progress ss-job-progress-block">' +
            '<div class="ss-progress-bar"><div class="ss-progress-fill" style="width:2%"></div></div>' +
            '<div class="ss-toast-stats">' + ( sheetsync.i18n.sync_queued || 'Processing…' ) + '</div></div>'
        );
    }

    function showProgressPlaceholder( $anchor ) {
        const $panel = $( '#ss-manual-sync-panel' );
        if ( $panel.length ) {
            $panel.find( '.ss-job-progress' ).remove();
            $panel.prepend( progressToastHtml() );
            return $panel.find( '.ss-job-progress' ).first();
        }
        $anchor.siblings( '.ss-job-progress' ).remove();
        $anchor.after( progressToastHtml() );
        return $anchor.next( '.ss-job-progress' );
    }

    function formatJobProgressLine( job, opts ) {
        opts = opts || {};
        const done      = parseInt( job.processed_count, 10 ) || 0;
        let total       = parseInt( job.total_estimate, 10 ) || 0;
        const direction = job.direction || '';
        const phase     = job.phase || '';
        const updated   = parseInt( job.updated_count, 10 );
        const jobId     = parseInt( job.id, 10 ) || 0;
        const jobPrefix = ( ! opts.hideJobId && jobId > 0 )
            ? ( sheetsync.i18n.sync_job_label || 'Job #%d' ).replace( '%d', jobId ) + ' | '
            : '';

        if ( direction === 'pull' || phase === 'pull' ) {
            if ( total > 0 ) {
                total = Math.max( total, done );
                const displayDone  = Math.min( done, total );
                const updatedCount = ! isNaN( updated ) ? updated : 0;
                const skipped      = parseInt( job.skipped_count, 10 ) || 0;
                const tpl          = sheetsync.i18n.sync_pull_scanned || 'Scanned %1$d / %2$d rows · %3$d updated';
                let line           = jobPrefix + tpl
                    .replace( '%1$d', displayDone )
                    .replace( '%2$d', total )
                    .replace( '%3$d', updatedCount );
                if ( skipped > 0 && job.mode !== 'full' ) {
                    line += ' · ' + ( sheetsync.i18n.sync_unchanged_rows || '%d unchanged' ).replace( '%d', skipped );
                }
                return line;
            }
            const upd = ! isNaN( updated ) ? updated : done;
            return jobPrefix + ( sheetsync.i18n.sync_pull_rows || 'Importing from sheet' ) + ': ' + upd + ' ' + ( sheetsync.i18n.rows_label || 'rows' );
        }

        if ( total > 0 ) {
            total = Math.max( total, done );
            const displayDone = Math.min( done, total );
            const tpl = sheetsync.i18n.sync_export_progress || sheetsync.i18n.sync_in_progress || 'Export in progress: %1$d / %2$d rows';
            return jobPrefix + tpl.replace( '%1$d', displayDone ).replace( '%2$d', total );
        }

        const modeLabel = job.mode === 'full' ? 'Full' : 'Smart';
        const totalLabel = total > 0 ? ' / ' + total : '';
        let line = jobPrefix + modeLabel + ' | ' + ( sheetsync.i18n.phase_label || 'Phase' ) + ': ' + ( job.phase || '…' ) +
            ' | ✅ ' + done + totalLabel + ' ' + ( sheetsync.i18n.rows_label || 'rows' );
        if ( ( job.error_count || 0 ) > 0 ) {
            line += ' | ⚠️ ' + job.error_count + ' ' + ( sheetsync.i18n.failed_rows_label || 'rows failed' );
        }
        return line;
    }

    function formatConnectionCardProgress( job ) {
        const done  = parseInt( job.processed_count, 10 ) || 0;
        let total   = parseInt( job.total_estimate, 10 ) || 0;
        if ( total > 0 ) {
            total = Math.max( total, done );
            const tpl = sheetsync.i18n.sync_in_progress || 'Sync in progress: %1$d / %2$d rows';
            return tpl.replace( '%1$d', Math.min( done, total ) ).replace( '%2$d', total );
        }
        return sheetsync.i18n.sync_queued || 'Sync in progress…';
    }

    function activeJobRestUrl( connId ) {
        return sheetsync.rest_url + 'connections/' + connId + '/active-job?_=' + Date.now();
    }

    function jobRestUrl( jobId ) {
        return sheetsync.rest_url + 'jobs/' + jobId + '?_=' + Date.now();
    }

    function refreshAllProgressUi( connId, job ) {
        if ( ! job || ! job.id ) {
            return;
        }
        $( '.ss-conn-sync-status[data-conn-id="' + connId + '"]' ).text( formatConnectionCardProgress( job ) );
        const $panel = $( '#ss-manual-sync-panel' );
        if ( $panel.length && parseInt( $panel.data( 'connection-id' ), 10 ) === connId ) {
            const $blocks = $panel.find( '.ss-job-progress' );
            if ( $blocks.length > 1 ) {
                $blocks.slice( 1 ).remove();
            }
            $panel.find( '.ss-job-progress' ).each( function() {
                updateJobProgressToast( $( this ), job );
            } );
        }
    }

    function editPanelProgressHintsHtml( backgroundOnly ) {
        const tabHint = backgroundOnly
            ? ( sheetsync.i18n.sync_background_hint || '' )
            : ( sheetsync.i18n.sync_keep_tab_open || 'Keep this tab open until sync completes.' );
        return (
            '<div class="ss-sync-tab-hint description" style="margin-top:6px;">' +
            $( '<span>' ).text( tabHint ).html() +
            '</div>' +
            '<div class="ss-smart-diff-hint description" style="margin-top:4px;">' +
            $( '<span>' ).text( sheetsync.i18n.sync_smart_diff_hint || '' ).html() +
            '</div>'
        );
    }

    function ensureEditPanelProgressBlock( connId ) {
        const $panel = $( '#ss-manual-sync-panel' );
        if ( ! $panel.length || parseInt( $panel.data( 'connection-id' ), 10 ) !== connId ) {
            return $();
        }
        let $progress = $panel.find( '.ss-job-progress' ).first();
        if ( ! $progress.length ) {
            $panel.find( '.ss-job-progress' ).remove();
            $panel.prepend( progressToastHtml() );
            $progress = $panel.find( '.ss-job-progress' ).first();
            $progress.find( '.ss-toast-stats' ).after( editPanelProgressHintsHtml() );
        } else if ( $panel.find( '.ss-job-progress' ).length > 1 ) {
            $panel.find( '.ss-job-progress' ).slice( 1 ).remove();
        }
        if ( $progress.length && ! $progress.find( '.ss-sync-tab-hint' ).length ) {
            $progress.find( '.ss-toast-stats' ).after( editPanelProgressHintsHtml() );
        }
        return $progress;
    }

    function ensureJobWatcherForConn( connId, job ) {
        const key = 'conn_' + connId;
        if ( jobWatchers[ key ] || ! job || ! job.id ) {
            return;
        }
        const $progress = ensureEditPanelProgressBlock( connId );
        if ( ! $progress.length ) {
            return;
        }
        const watcher = startJobWatch( {
            connId   : connId,
            jobId    : job.id,
            $btn     : null,
            origHtml : '',
            $toast   : $progress,
        } );
        watcher.setAjaxSettled( true );
    }

    function collectProgressConnIds() {
        const ids = {};
        $( '.ss-conn-sync-status[data-conn-id]' ).each( function() {
            const connId = parseInt( $( this ).data( 'conn-id' ), 10 );
            if ( connId ) {
                ids[ connId ] = true;
            }
        } );
        const $panel = $( '#ss-manual-sync-panel' );
        if ( $panel.length ) {
            const connId = parseInt( $panel.data( 'connection-id' ), 10 );
            if ( connId ) {
                ids[ connId ] = true;
            }
        }
        return Object.keys( ids ).map( function( id ) {
            return parseInt( id, 10 );
        } );
    }

    function fetchActiveJob( connId ) {
        return $.ajax( {
            url        : activeJobRestUrl( connId ),
            method     : 'GET',
            cache      : false,
            beforeSend : function( xhr ) {
                xhr.setRequestHeader( 'X-WP-Nonce', sheetsync.rest_nonce );
            },
        } );
    }

    function handleActiveJobPollResult( connId, job ) {
        if ( ! job || ! job.id || job.status === 'completed' || job.status === 'failed' ) {
            return;
        }
        $( '#ss-manual-sync-panel .ss-sync-toast.ss-toast-error' ).remove();
        ensureEditPanelProgressBlock( connId );
        refreshAllProgressUi( connId, job );
        ensureJobWatcherForConn( connId, job );
    }

    function updateJobProgressToast( $toast, job ) {
        if ( ! $toast || ! $toast.length ) {
            return;
        }
        $toast.find( '.ss-phase-hint' ).remove();
        const phaseLabel = job.phase_label || '';
        if ( phaseLabel && ( job.status === 'running' || job.status === 'pending' || ! job.status ) ) {
            $toast.find( '.ss-toast-stats' ).before(
                '<div class="ss-phase-hint description" style="margin-bottom:4px;font-weight:600;">' +
                $( '<span>' ).text( phaseLabel ).html() +
                '</div>'
            );
        }
        $toast.find( '.ss-toast-stats' ).text( formatJobProgressLine( job ) );
        const done  = parseInt( job.processed_count, 10 ) || 0;
        let total = parseInt( job.total_estimate, 10 ) || 0;
        if ( total > 0 ) {
            total = Math.max( total, done );
        }
        const pct   = job.progress_pct != null
            ? Math.min( 100, job.progress_pct )
            : ( total > 0 ? Math.min( 100, Math.round( ( Math.min( done, total ) / total ) * 100 ) ) : null );
        if ( pct != null ) {
            $toast.find( '.ss-progress-fill' ).css( 'width', pct + '%' );
        }
        const eta = parseInt( job.eta_minutes, 10 );
        $toast.find( '.ss-eta-hint' ).remove();
        if ( eta > 0 && ( job.status === 'running' || ! job.status ) ) {
            const degraded = job.scheduler_degraded || sheetsync.scheduler_degraded;
            const etaTpl = degraded
                ? ( sheetsync.i18n.sync_eta_degraded || sheetsync.i18n.sync_eta_remaining )
                : ( sheetsync.i18n.sync_eta_remaining || '~%d min remaining (estimate)' );
            $toast.find( '.ss-toast-stats' ).after(
                '<div class="ss-eta-hint description" style="margin-top:6px;">' +
                $( '<span>' ).text( etaTpl.replace( '%d', eta ) ).html() +
                '</div>'
            );
        }
    }

    function jobExportMostlyComplete( job ) {
        return false;
    }

    function startJobWatch( opts ) {
        const connId = opts.connId;
        const key    = 'conn_' + connId;
        if ( jobWatchers[ key ] ) {
            jobWatchers[ key ].stop();
        }

        const state = {
            jobId         : opts.jobId || 0,
            ajaxSettled   : false,
            stopped       : false,
            stallTicks    : 0,
            lastDone      : -1,
            emptyTicks    : 0,
            pollTimer     : null,
            drainInFlight : false,
            tickInFlight  : false,
            lastDrainAt   : 0,
            drainAttempts : 0,
            backgroundOnly: !! opts.backgroundOnly,
            pollMs        : opts.backgroundOnly ? 6000 : effectivePollMs( null ),
            drainMs       : effectiveDrainMs( null ),
            $btn          : opts.$btn || null,
            origHtml      : opts.origHtml || '',
            $toast        : opts.$toast || null,
            requiresTabOpen: !!opts.requiresTabOpen,
        };

        function setDrainHint( show ) {
            if ( ! state.$toast || ! state.$toast.length ) {
                return;
            }
            state.$toast.find( '.ss-drain-hint' ).remove();
            if ( show ) {
                state.$toast.append(
                    '<div class="ss-toast-detail ss-drain-hint description" style="margin-top:6px;">' +
                    $( '<span>' ).text( sheetsync.i18n.sync_draining_tab || 'Processing batch in this tab…' ).html() +
                    '</div>'
                );
            }
        }

        function noteHostTimeout() {
            $.post( sheetsync.ajax_url, {
                action: 'sheetsync_note_host_timeout',
                nonce : sheetsync.nonce,
            } );
        }

        function shouldInlineDrain( job, force ) {
            if ( state.stopped ) {
                return !! force;
            }
            if ( force ) {
                return true;
            }
            if ( document.visibilityState !== 'visible' ) {
                return false;
            }

            const tabRequired = state.requiresTabOpen
                || ( job && job.requires_tab_open )
                || sheetsync.scheduler_degraded;
            if ( job && job.requires_tab_open === false && ! state.requiresTabOpen && ! sheetsync.scheduler_degraded ) {
                return false;
            }
            if ( ! tabRequired ) {
                return false;
            }

            const now      = Date.now();
            const done     = parseInt( ( job && job.processed_count ) || 0, 10 ) || 0;
            const hasJob   = !!( job && job.id );
            const stalled  = state.stallTicks >= 2;
            const atStart  = done < 1 && state.drainAttempts < 1;
            const connWait = state.ajaxSettled && connId && ! hasJob && state.drainAttempts < 2;
            const periodic = state.backgroundOnly
                ? ( now - state.lastDrainAt ) > 45000
                : ( now - state.lastDrainAt ) > ( state.drainMs || 3000 );

            if ( ! hasJob ) {
                return connWait || stalled;
            }

            const status = job.status || '';
            if ( status !== 'pending' && status !== 'running' ) {
                return false;
            }
            if ( ! stalled && ! atStart && ! periodic ) {
                return false;
            }
            if ( done > 0 && ! stalled && ! periodic ) {
                return false;
            }
            return true;
        }

        function maybeDrainJob( job, force ) {
            if ( state.stopped || state.tickInFlight ) {
                return;
            }
            if ( shouldInlineDrain( job, force ) ) {
                tick( force );
            }
        }

        function clearPollTimer() {
            if ( state.pollTimer ) {
                clearInterval( state.pollTimer );
                state.pollTimer = null;
            }
        }

        function schedulePoll( delayMs ) {
            if ( state.stopped ) {
                return;
            }
            if ( ! state.pollTimer ) {
                state.pollTimer = setInterval( tick, state.pollMs || 2500 );
            }
            if ( delayMs === 0 ) {
                tick();
            }
        }

        function fetchJobById( jobId, onSuccess, onFail ) {
            $.ajax( {
                url        : jobRestUrl( jobId ),
                method     : 'GET',
                cache      : false,
                beforeSend : function( xhr ) {
                    xhr.setRequestHeader( 'X-WP-Nonce', sheetsync.rest_nonce );
                },
            } ).done( onSuccess ).fail( onFail || function() {
                /* interval keeps trying */
            } );
        }

        if ( state.$btn ) {
            state.$btn.data( 'ss-polling', true );
        }

        function finishPoll( success, job, partial ) {
            if ( state.stopped ) {
                return;
            }
            state.stopped = true;
            clearPollTimer();
            delete jobWatchers[ key ];

            if ( state.$btn ) {
                state.$btn.removeData( 'ss-polling' ).removeClass( 'loading' ).html( state.origHtml );
            }
            $( '.ss-sync-btn' ).prop( 'disabled', false );

            if ( ! state.$toast || ! state.$toast.length ) {
                return;
            }

            if ( success ) {
                if ( job && job.progress_pct != null ) {
                    state.$toast.find( '.ss-progress-fill' ).css( 'width', '100%' );
                }
                const suffix = partial
                    ? ( sheetsync.i18n.sync_partial_done || 'Export mostly complete.' )
                    : ( sheetsync.i18n.sync_complete || 'Sync Complete!' );
                state.$toast.find( '.ss-toast-stats' ).append( ' — ' + suffix );
                if ( partial ) {
                    state.$toast.append(
                        '<div class="ss-toast-detail">⚠️ ' + $( '<span>' ).text( suffix ).html() + '</div>'
                    );
                }
            } else {
                state.$toast.removeClass( 'ss-toast-success' ).addClass( 'ss-toast-error' );
                const errText = ( job && job.last_error ) ? job.last_error : sheetsync.i18n.sync_error;
                state.$toast.find( '.ss-toast-stats' ).text( errText );
                const errOpts = { sheet_not_ready: isSheetTabError( errText ) };
                state.$toast.append( buildSyncErrorDetails( errText, connId, '', errOpts ) );
                if ( ! errOpts.sheet_not_ready ) {
                    state.$toast.find( '.ss-sync-retry-btn' ).on( 'click', function() {
                        if ( state.$btn && state.$btn.length ) {
                            state.$toast.fadeOut( 200, function() { $( this ).remove(); } );
                            runManualSync( state.$btn, { skipConfirm: true } );
                        }
                    } );
                }
            }

            if ( success ) {
                setTimeout( function() {
                    state.$toast.fadeOut( 400, function() { $( this ).remove(); } );
                }, 12000 );
            }
        }

        function handleJobTick( job ) {
            const status = job.status || '';
            const done   = parseInt( job.processed_count, 10 ) || 0;

            if ( job.scheduler_degraded && state.pollMs < effectivePollMs( job ) ) {
                state.pollMs  = effectivePollMs( job );
                state.drainMs = effectiveDrainMs( job );
                clearPollTimer();
                state.pollTimer = setInterval( tick, state.pollMs );
            }

            refreshAllProgressUi( connId, job );

            if ( done === state.lastDone && ( status === 'pending' || status === 'running' ) ) {
                ++state.stallTicks;
            } else {
                state.stallTicks = 0;
                state.drainAttempts = 0;
                state.lastDone   = done;
                state.$toast.find( '.ss-scheduler-hint' ).remove();
            }

            if ( state.stallTicks >= 12 && state.drainAttempts >= 2 && done < 1 && state.$toast ) {
                if ( ! state.$toast.find( '.ss-scheduler-hint' ).length ) {
                    state.$toast.append(
                        '<div class="ss-toast-detail ss-scheduler-hint">⚠️ ' +
                        ( sheetsync.i18n.scheduler_stalled || 'Queue stalled — run pending actions under WooCommerce → Status → Scheduled Actions.' ) +
                        '</div>'
                    );
                }
                state.stallTicks = 0;
            }

            if ( status === 'completed' ) {
                finishPoll( true, job, false );
            } else if ( status === 'failed' ) {
                finishPoll( false, job, false );
            } else {
                /* setInterval continues polling */
            }
        }

        function tick( forceDrain ) {
            if ( state.stopped || ! connId || state.tickInFlight ) {
                return;
            }

            const jobStub     = {
                id              : state.jobId,
                status          : 'running',
                processed_count : state.lastDone,
            };
            const inlineDrain = shouldInlineDrain( jobStub, !! forceDrain );

            if ( inlineDrain ) {
                state.lastDrainAt = Date.now();
                ++state.drainAttempts;
                setDrainHint( true );
            }

            state.tickInFlight = true;
            const useRestTick = !!( sheetsync.rest_url && sheetsync.rest_nonce );
            const tickRequest = useRestTick
                ? {
                    url        : orchestratorSyncUrl( connId ) + '?job_id=' + ( state.jobId || 0 ) + '&inline_drain=' + ( inlineDrain ? 'true' : 'false' ),
                    type       : 'GET',
                    timeout    : 28000,
                    dataType   : 'json',
                    cache      : false,
                    beforeSend : function( xhr ) {
                        xhr.setRequestHeader( 'X-WP-Nonce', sheetsync.rest_nonce );
                    },
                }
                : {
                    url     : sheetsync.ajax_url,
                    type    : 'POST',
                    timeout : 28000,
                    dataType: 'json',
                    data    : {
                        action        : 'sheetsync_sync_tick',
                        nonce         : sheetsync.nonce,
                        connection_id : connId,
                        job_id        : state.jobId || 0,
                        inline_drain  : inlineDrain ? 1 : 0,
                    },
                };

            $.ajax( tickRequest ).done( function( response ) {
                if ( useRestTick ) {
                    if ( ! response ) {
                        return;
                    }
                    const job = response.job || null;
                    if ( job && job.id ) {
                        state.jobId      = job.id;
                        state.emptyTicks = 0;
                        if ( job.requires_tab_open ) {
                            state.requiresTabOpen = true;
                        }
                        if ( inlineDrain ) {
                            const newDone = parseInt( job.processed_count, 10 ) || 0;
                            if ( newDone > state.lastDone ) {
                                state.stallTicks    = 0;
                                state.drainAttempts = 0;
                                state.$toast && state.$toast.find( '.ss-scheduler-hint' ).remove();
                            }
                        }
                        handleJobTick( job );
                    } else {
                        ++state.emptyTicks;
                    }
                    return;
                }

                if ( ! response.success ) {
                    return;
                }
                const job = ( response.data && response.data.job ) ? response.data.job : null;
                if ( job && job.id ) {
                    state.jobId      = job.id;
                    state.emptyTicks = 0;
                    if ( inlineDrain ) {
                        const newDone = parseInt( job.processed_count, 10 ) || 0;
                        if ( newDone > state.lastDone ) {
                            state.stallTicks    = 0;
                            state.drainAttempts = 0;
                            state.$toast.find( '.ss-scheduler-hint' ).remove();
                        }
                    }
                    handleJobTick( job );
                    return;
                }

                ++state.emptyTicks;
                if ( state.ajaxSettled && state.jobId && state.emptyTicks >= 4 && state.emptyTicks % 4 === 0 ) {
                    tick( true );
                }
                if ( state.ajaxSettled && state.emptyTicks >= 25 ) {
                    if ( state.$toast ) {
                        state.$toast.removeClass( 'ss-toast-success' ).addClass( 'ss-toast-error' );
                        state.$toast.find( '.ss-toast-stats' ).text(
                            sheetsync.i18n.scheduler_stalled || 'Background queue did not start.'
                        );
                    }
                    finishPoll( false, null, false );
                }
            } ).fail( function( xhr, textStatus ) {
                if ( state.stopped || ! state.jobId ) {
                    return;
                }
                const httpStatus = xhr && xhr.status ? parseInt( xhr.status, 10 ) : 0;
                const isServerBusy = httpStatus === 503 || httpStatus === 502 || httpStatus === 504;
                const retryable    = isServerBusy || textStatus === 'timeout' || textStatus === 'error';
                if ( ! retryable ) {
                    return;
                }
                noteHostTimeout();
                if ( state.$toast && state.$toast.length && sheetsync.i18n.sync_connection_timeout ) {
                    state.$toast.find( '.ss-timeout-hint' ).remove();
                    state.$toast.append(
                        '<div class="ss-toast-detail ss-timeout-hint description" style="margin-top:6px;">⚠️ ' +
                        $( '<span>' ).text( sheetsync.i18n.sync_connection_timeout ).html() +
                        '</div>'
                    );
                }
                setTimeout( function() {
                    if ( ! state.stopped && state.jobId ) {
                        tick( true );
                    }
                }, isServerBusy ? 5000 : 2500 );
            } ).always( function() {
                state.tickInFlight = false;
                setDrainHint( false );
            } );
        }

        const api = {
            setJobId( id ) {
                if ( id ) {
                    state.jobId = id;
                }
            },
            setAjaxSettled( settled ) {
                state.ajaxSettled = !! settled;
            },
            setRequiresTabOpen( enabled ) {
                state.requiresTabOpen = !! enabled;
            },
            setBackgroundOnly( enabled ) {
                state.backgroundOnly = !! enabled;
                state.pollMs         = state.backgroundOnly ? 6000 : 2500;
                clearPollTimer();
                if ( ! state.stopped ) {
                    schedulePoll( 0 );
                }
            },
            hasJob() {
                return !! state.jobId;
            },
            kick() {
                schedulePoll( 0 );
                if ( state.jobId ) {
                    tick( true );
                }
            },
            stop() {
                state.stopped = true;
                clearPollTimer();
                delete jobWatchers[ key ];
                if ( state.$btn ) {
                    state.$btn.removeData( 'ss-polling' );
                }
            },
        };

        jobWatchers[ key ] = api;
        schedulePoll( 0 );
        return api;
    }

    function renderSyncSuccessToast( $btn, d ) {
        const typeLabels = {
            'orders'            : '📋 All Orders',
            'orders_pending'    : '⏳ Pending Payment',
            'orders_processing' : '⚙️ Processing',
            'orders_on-hold'    : '🔔 On Hold',
            'orders_completed'  : '✅ Completed',
            'orders_cancelled'  : '❌ Cancelled',
            'orders_refunded'   : '↩️ Refunded',
            'orders_failed'     : '🚫 Failed',
            'orders_draft'      : '📝 Draft',
            'products'          : '📦 Products',
        };
        const connType  = d.connection_type || '';
        const typeLabel = typeLabels[ connType ] || connType;

        let dateBadge = '';
        if ( d.date_type === 'single' && d.date_single ) {
            dateBadge = '<span class="ss-badge ss-badge-date">📅 ' + d.date_single + '</span>';
        } else if ( d.date_type === 'range' ) {
            const from = d.date_from || '…';
            const to   = d.date_to   || '…';
            dateBadge = '<span class="ss-badge ss-badge-date">📅 ' + from + ' → ' + to + '</span>';
        }

        let parts = [];
        if ( d.mode === 'full' ) {
            parts.push( '<span class="ss-stat ss-stat-skip">🔄 ' + ( sheetsync.i18n.progress_full_relink || 'Full re-link' ) + '</span>' );
        } else if ( d.direction === 'bootstrap' || d.bootstrap ) {
            parts.push( '<span class="ss-stat ss-stat-skip">🔗 ' + ( sheetsync.i18n.first_sync_label || 'First sync (full link)' ) + '</span>' );
        } else if ( d.mode ) {
            parts.push( '<span class="ss-stat ss-stat-skip">⚡ ' + ( sheetsync.i18n.progress_changes_only || 'Changes only' ) + '</span>' );
        }
        if ( typeof d.push_processed === 'number' && ( d.push_processed > 0 || d.pull_processed > 0 ) ) {
            parts.push( '<span class="ss-stat ss-stat-ok">✅ ' + d.push_processed + ' → sheet</span>' );
            if ( d.pull_processed > 0 ) {
                parts.push( '<span class="ss-stat ss-stat-ok">✅ ' + d.pull_processed + ' ← sheet</span>' );
            }
        } else if ( d.processed > 0 ) {
            parts.push( '<span class="ss-stat ss-stat-ok">✅ ' + d.processed + ' row' + ( d.processed === 1 ? '' : 's' ) + ' updated</span>' );
        }
        if ( d.skipped   > 0 ) parts.push( '<span class="ss-stat ss-stat-skip">⏭ ' + d.skipped   + ' unchanged</span>' );
        if ( d.errors    > 0 ) parts.push( '<span class="ss-stat ss-stat-err">⚠️ ' + d.errors + ' row(s) failed</span>' );
        if ( parts.length === 0 ) parts.push( '<span class="ss-stat ss-stat-ok">✅ ' + sheetsync.i18n.sync_complete + '</span>' );

        const detail = d.user_message
            ? '<div class="ss-toast-detail">' + $( '<span>' ).text( d.user_message ).html() + '</div>'
            : '';

        const html =
            '<div class="ss-sync-toast ss-toast-success">' +
                '<div class="ss-toast-header">' +
                    ( typeLabel ? '<span class="ss-badge ss-badge-type">' + typeLabel + '</span>' : '' ) +
                    dateBadge +
                '</div>' +
                '<div class="ss-toast-stats">' + parts.join( '' ) + '</div>' +
                detail +
            '</div>';

        $btn.after( html );
    }

    let activeJobPollTimer = null;

    function pollAllActiveJobs() {
        if ( ! sheetsync.rest_url ) {
            return;
        }
        const connIds = collectProgressConnIds();
        if ( ! connIds.length ) {
            return;
        }
        connIds.forEach( function( connId ) {
            fetchActiveJob( connId ).done( function( res ) {
                handleActiveJobPollResult( connId, res.job || {} );
            } );
        } );
    }

    function scheduleActiveJobPoll() {
        if ( activeJobPollTimer ) {
            clearInterval( activeJobPollTimer );
            activeJobPollTimer = null;
        }
        if ( ! collectProgressConnIds().length ) {
            return;
        }
        pollAllActiveJobs();
        activeJobPollTimer = setInterval( pollAllActiveJobs, 2500 );
    }

    scheduleActiveJobPoll();

    document.addEventListener( 'visibilitychange', function() {
        if ( document.visibilityState !== 'visible' ) {
            return;
        }
        Object.keys( jobWatchers ).forEach( function( key ) {
            const watcher = jobWatchers[ key ];
            if ( watcher && typeof watcher.kick === 'function' ) {
                watcher.kick();
            }
        } );
        pollAllActiveJobs();
    } );

    // ── Check sheet before sync ────────────────────────────────────────────────
    $( document ).on( 'click', '.ss-check-sheet-btn', function( e ) {
        e.preventDefault();
        const $btn    = $( this );
        const connId  = $btn.data( 'connection-id' );
        const $panel  = $( '#ss-sheet-check-results' );
        const orig    = $btn.html();

        $btn.prop( 'disabled', true ).html(
            '<span class="dashicons dashicons-update ss-spin"></span> ' + ( sheetsync.i18n.checking_sheet || 'Checking…' )
        );
        $panel.show().html( '<p class="ss-check-loading">' + ( sheetsync.i18n.checking_sheet || 'Checking…' ) + '</p>' );

        $.post( sheetsync.ajax_url, {
            action        : 'sheetsync_check_sheet',
            nonce         : sheetsync.nonce,
            connection_id : connId,
        } )
        .done( function( response ) {
            if ( ! response.success || ! response.data ) {
                $panel.html( '<p class="ss-check-item ss-check-error">❌ ' + ( response.data && response.data.message ? response.data.message : 'Check failed' ) + '</p>' );
                return;
            }
            const d      = response.data;
            const issues = d.issues || [];
            const stats  = d.stats || {};
            const summary = d.summary || {};
            const products = summary.products || {};
            const counts = summary.counts || {};
            const totalRows = parseInt( products.total_rows, 10 ) || parseInt( d.rows_checked, 10 ) || 0;
            const ready = summary.ready === true || d.ok === true;

            let html = '<div class="ss-check-summary ' + ( ready ? 'ss-check-ok' : 'ss-check-bad' ) + '">' +
                ( ready ? '✅ ' : '⚠️ ' ) +
                ( ready ? ( sheetsync.i18n.check_passed || 'Ready to import' ) : ( sheetsync.i18n.check_failed || 'Fix issues before sync' ) ) +
                '</div>';

            html += '<div class="ss-check-summary-grid">' +
                '<div class="ss-check-summary-block"><strong>' + ( sheetsync.i18n.check_summary_products || 'Products' ) + '</strong><ul>' +
                '<li>' + ( sheetsync.i18n.check_stats_simple || 'Simple' ) + ': ' + ( parseInt( products.simple, 10 ) || parseInt( stats.simple, 10 ) || 0 ) + '</li>' +
                '<li>' + ( sheetsync.i18n.check_stats_parents || 'Variable parents' ) + ': ' + ( parseInt( products.variable_parents, 10 ) || parseInt( stats.variable_parents, 10 ) || 0 ) + '</li>' +
                '<li>' + ( sheetsync.i18n.check_stats_variations || 'Variations' ) + ': ' + ( parseInt( products.variations, 10 ) || parseInt( stats.variations, 10 ) || 0 ) + '</li>' +
                '<li>' + ( sheetsync.i18n.check_total_rows || 'Total rows' ) + ': ' + totalRows + '</li>' +
                '</ul></div>';

            html += '<div class="ss-check-summary-block"><strong>' + ( sheetsync.i18n.check_summary_errors || 'Errors' ) + '</strong><ul>' +
                '<li>' + ( sheetsync.i18n.check_invalid_prices || 'Invalid prices' ) + ': ' + ( parseInt( counts.invalid_prices, 10 ) || 0 ) + '</li>' +
                '<li>' + ( sheetsync.i18n.check_duplicate_sku || 'Duplicate SKU' ) + ': ' + ( parseInt( counts.duplicate_sku, 10 ) || 0 ) + '</li>' +
                '<li>' + ( sheetsync.i18n.check_missing_required || 'Missing required fields' ) + ': ' + ( parseInt( counts.missing_required, 10 ) || 0 ) + '</li>' +
                '</ul></div>';

            html += '<div class="ss-check-summary-block"><strong>' + ( sheetsync.i18n.check_summary_warnings || 'Warnings' ) + '</strong><ul>' +
                '<li>' + ( sheetsync.i18n.check_missing_attributes || 'Missing attributes' ) + ': ' + ( parseInt( counts.missing_attributes, 10 ) || 0 ) + '</li>' +
                '<li>' + ( sheetsync.i18n.check_missing_terms || 'Missing attribute values' ) + ': ' + ( parseInt( counts.missing_attribute_terms, 10 ) || 0 ) + '</li>' +
                '</ul></div></div>';

            html += '<ul class="ss-check-list">';
            issues.forEach( function( item ) {
                const icon = item.level === 'error' ? '❌' : ( item.level === 'warn' ? '⚠️' : '✅' );
                let link = '';
                if ( item.edit_url ) {
                    link = ' <a href="' + item.edit_url + '" target="_blank" rel="noopener">Edit product</a>';
                }
                let detail = '';
                if ( item.column || item.value || item.expected ) {
                    detail = '<div class="ss-check-detail">';
                    if ( item.column ) {
                        detail += '<span class="ss-check-detail-line"><strong>' + ( sheetsync.i18n.check_column || 'Column' ) + ':</strong> ' + $( '<span>' ).text( item.column ).html() + '</span>';
                    }
                    if ( item.value ) {
                        detail += '<span class="ss-check-detail-line"><strong>' + ( sheetsync.i18n.check_value || 'Value' ) + ':</strong> ' + $( '<span>' ).text( item.value ).html() + '</span>';
                    }
                    if ( item.expected ) {
                        detail += '<span class="ss-check-detail-line"><strong>' + ( sheetsync.i18n.check_expected || 'Expected' ) + ':</strong> ' + $( '<span>' ).text( item.expected ).html() + '</span>';
                    }
                    detail += '</div>';
                }
                html += '<li class="ss-check-item ss-check-' + item.level + '">' + icon + ' ' +
                    $( '<span>' ).text( item.message || '' ).html() + link + detail + '</li>';
            } );
            html += '</ul>';
            $panel.html( html );
        } )
        .fail( function() {
            $panel.html( '<p class="ss-check-item ss-check-error">❌ Request failed.</p>' );
        } )
        .always( function() {
            $btn.prop( 'disabled', false ).html( orig );
        } );
    } );

    // ── Match diagnostics page ─────────────────────────────────────────────────
    function ssRenderMatchFindings( findings ) {
        if ( ! findings || ! findings.length ) {
            return '<p class="description">' + ( sheetsync.i18n.diag_no_issues || 'No issues found.' ) + '</p>';
        }
        let html = '<ul class="ss-match-diag-findings-list">';
        findings.forEach( function( item ) {
            const sev  = item.severity || 'info';
            const icon = sev === 'error' ? '❌' : ( sev === 'warn' ? '⚠️' : ( sev === 'ok' ? '✅' : 'ℹ️' ) );
            const row  = parseInt( item.row, 10 ) > 0
                ? ' <em>(' + ( sheetsync.i18n.row_label || 'Row' ) + ' ' + item.row + ')</em>'
                : '';
            let link = '';
            if ( item.action_url && item.action_label ) {
                link = ' <a href="' + item.action_url + '">' + $( '<span>' ).text( item.action_label ).html() + '</a>';
            }
            const cat = item.category || '';
            const catLabel = cat.charAt( 0 ).toUpperCase() + cat.slice( 1 );
            html += '<li class="ss-match-diag-finding ss-match-diag-' + sev + '">' + icon + ' ' +
                '<span class="ss-match-diag-cat">[' + $( '<span>' ).text( catLabel ).html() + ']</span> ' +
                $( '<span>' ).text( item.message || '' ).html() + row + link + '</li>';
        } );
        html += '</ul>';
        return html;
    }

    function ssUpdateMatchDiagnostics( data ) {
        const score = data.score || 'healthy';
        const links = data.links || {};
        const identity = data.identity || {};
        const $banner = $( '#ss-match-diag-score-banner' );
        $banner.removeClass( 'ss-match-diag-score-healthy ss-match-diag-score-attention ss-match-diag-score-critical' )
            .addClass( 'ss-match-diag-score-' + score );
        if ( score === 'healthy' ) {
            $banner.text( sheetsync.i18n.diag_score_healthy || 'Matching looks healthy for this connection.' );
        } else if ( score === 'attention' ) {
            $banner.text( sheetsync.i18n.diag_score_attention || 'Some matching issues need attention before large syncs.' );
        } else {
            $banner.text( sheetsync.i18n.diag_score_critical || 'Critical matching issues — fix identity mapping before syncing.' );
        }

        $( '#ss-match-diag-stats .ss-match-diag-stat' ).eq( 0 ).find( '.ss-match-diag-stat-value' ).text( links.mapped_rows || 0 );
        $( '#ss-match-diag-stats .ss-match-diag-stat' ).eq( 1 ).find( '.ss-match-diag-stat-value' ).text( links.orphaned_maps || 0 );
        $( '#ss-match-diag-stats .ss-match-diag-stat' ).eq( 2 ).find( '.ss-match-diag-stat-value' ).text( links.open_conflicts || 0 );
        $( '#ss-match-diag-stats .ss-match-diag-stat' ).eq( 3 ).find( '.ss-match-diag-stat-value' ).text( links.unlinked_wc_estimate || 0 );

        $( '.ss-match-diag-identity-table tbody tr' ).eq( 0 ).find( 'td' ).last().text( identity.sku_column || '—' );
        $( '.ss-match-diag-identity-table tbody tr' ).eq( 1 ).find( 'td' ).last().text( identity.product_id_column || '—' );

        $( '#ss-match-diag-findings' ).html( ssRenderMatchFindings( data.findings || [] ) );
    }

    $( document ).on( 'click', '.ss-run-match-diagnostics-btn', function( e ) {
        e.preventDefault();
        const $btn      = $( this );
        const connId    = $btn.data( 'connection-id' );
        const scanSheet = String( $btn.data( 'scan-sheet' ) || '0' );
        const $status   = $( '.ss-match-diag-status' );
        const orig      = $btn.html();

        $btn.prop( 'disabled', true ).html(
            '<span class="dashicons dashicons-update ss-spin"></span> ' + ( sheetsync.i18n.diag_running || 'Running…' )
        );
        $status.text( sheetsync.i18n.diag_running || 'Running diagnostics…' );

        $.post( sheetsync.ajax_url, {
            action        : 'sheetsync_run_match_diagnostics',
            nonce         : sheetsync.nonce,
            connection_id : connId,
            scan_sheet    : scanSheet,
        } )
        .done( function( response ) {
            if ( ! response.success || ! response.data ) {
                $status.text( response.data && response.data.message ? response.data.message : ( sheetsync.i18n.diag_failed || 'Diagnostics failed.' ) );
                return;
            }
            ssUpdateMatchDiagnostics( response.data );
            $status.text( sheetsync.i18n.diag_done || 'Diagnostics updated.' );
        } )
        .fail( function() {
            $status.text( sheetsync.i18n.diag_failed || 'Request failed.' );
        } )
        .always( function() {
            $btn.prop( 'disabled', false ).html( orig );
        } );
    } );

    // ── Recommended column layout (A–U) ───────────────────────────────────────
    $( document ).on( 'click', '.ss-write-template-btn', function( e ) {
        e.preventDefault();
        const $btn   = $( this );
        const connId = $btn.data( 'connection-id' );
        const orig   = $btn.html();
        $btn.prop( 'disabled', true ).text( 'Writing…' );
        $.post( sheetsync.ajax_url, {
            action        : 'sheetsync_write_template',
            nonce         : sheetsync.nonce,
            connection_id : connId,
        } )
        .done( function( res ) {
            if ( res.success ) {
                alert( res.data.message || 'Done' );
            } else {
                alert( ( res.data && res.data.message ) || 'Failed' );
            }
        } )
        .fail( function() { alert( 'Request failed' ); } )
        .always( function() { $btn.prop( 'disabled', false ).html( orig ); } );
    } );

    $( document ).on( 'click', '.ss-apply-recommended-map-btn', function( e ) {
        e.preventDefault();
        const preset = $( this ).data( 'map-preset' ) || 'full';
        const map = preset === 'minimal'
            ? ( sheetsync.minimal_map || {} )
            : ( sheetsync.recommended_map || {} );
        Object.keys( map ).forEach( function( field ) {
            const cfg = map[ field ];
            const $col = $( 'input[name="field_map[' + field + '][column]"]' );
            const $key = $( 'input[name="field_map[' + field + '][key]"]' );
            if ( $col.length && cfg.column ) {
                $col.val( cfg.column );
            }
            if ( $key.length ) {
                $key.prop( 'checked', field === 'product_id' ? false : !! cfg.key );
            }
        } );
        $( '.ss-apply-recommended-map-btn' ).after(
            '<span class="ss-recommended-applied" style="color:#059669;margin-left:8px;">✅ Applied — click Save Field Mapping.</span>'
        );
        setTimeout( function() {
            $( '.ss-recommended-applied' ).fadeOut( function() { $( this ).remove(); } );
        }, 6000 );
    } );

    $( document ).on( 'click', '#ss-test-image-url-btn, .ss-conn-test-image-url-btn', function( e ) {
        e.preventDefault();
        const $btn  = $( this );
        const $conn = $btn.closest( '.ss-catalog-image-test' );
        const url   = ( $conn.length ? $conn.find( '#ss-conn-test-image-url' ).val() : $( '#ss-test-image-url' ).val() || '' ).trim();
        const $out  = $conn.length ? $conn.find( '#ss-conn-test-image-url-result' ) : $( '#ss-test-image-url-result' );
        $out.text( '…' );
        $.post( sheetsync.ajax_url, {
            action    : 'sheetsync_test_image_url',
            nonce     : sheetsync.nonce,
            image_url : url,
        } )
        .done( function( res ) {
            if ( res.success ) {
                $out.css( 'color', '#065f46' ).text( '✅ ' + ( res.data.message || 'OK' ) );
            } else {
                $out.css( 'color', '#991b1b' ).text( '❌ ' + ( res.data && res.data.message ? res.data.message : 'Failed' ) );
            }
        } )
        .fail( function() { $out.css( 'color', '#991b1b' ).text( '❌ Request failed' ); } );
    } );

    // ── Spreadsheet URL → ID ───────────────────────────────────────────────────
    function ssExtractSpreadsheetId( input ) {
        const raw = ( input || '' ).trim();
        if ( ! raw ) {
            return '';
        }
        const match = raw.match( /spreadsheets\/d\/([a-zA-Z0-9_-]+)/ );
        return match ? match[1] : raw;
    }

    function ssApplySpreadsheetUrl( $source, $target ) {
        const id = ssExtractSpreadsheetId( $source.val() );
        if ( id ) {
            $target.val( id );
        }
    }

    $( document ).on( 'input paste', '#spreadsheet_url', function() {
        ssApplySpreadsheetUrl( $( this ), $( '#spreadsheet_id' ) );
    } );

    $( document ).on( 'blur', '#spreadsheet_id', function() {
        const id = ssExtractSpreadsheetId( $( this ).val() );
        if ( id && id !== $( this ).val().trim() ) {
            $( this ).val( id );
        }
    } );

    // ── Service Account JSON file upload / paste ───────────────────────────────
    function ssValidateServiceAccountJson( text ) {
        const trimmed = String( text || '' ).trim();
        if ( ! trimmed || trimmed.charAt( 0 ) !== '{' ) {
            return false;
        }
        try {
            const parsed = JSON.parse( trimmed );
            return parsed && parsed.type === 'service_account' && !! parsed.client_email;
        } catch ( err ) {
            return false;
        }
    }

    function ssJsonPasteStatus( message, isError ) {
        const $status = $( '#ss-json-paste-status' );
        if ( ! $status.length ) {
            return;
        }
        if ( ! message ) {
            $status.prop( 'hidden', true ).removeClass( 'is-error is-success' ).text( '' );
            return;
        }
        $status
            .prop( 'hidden', false )
            .removeClass( 'is-error is-success' )
            .addClass( isError ? 'is-error' : 'is-success' )
            .text( message );
    }

    function ssApplyServiceAccountJson( text, source ) {
        const trimmed = String( text || '' ).trim();
        if ( ! ssValidateServiceAccountJson( trimmed ) ) {
            ssJsonPasteStatus(
                sheetsync.i18n.invalid_json_paste || 'Clipboard does not contain valid Service Account JSON.',
                true
            );
            return false;
        }
        const $ta = $( '#ss-service-account-json' );
        if ( $ta.length ) {
            $ta.val( trimmed ).trigger( 'input' );
        }
        $( '#ss-json-upload-zone' ).addClass( 'is-filled' );
        const loadedMsg = sheetsync.i18n.json_loaded || 'JSON loaded — click Save Settings below.';
        ssJsonPasteStatus( '✅ ' + loadedMsg, false );
        if ( source === 'paste' && $ta.length ) {
            $ta.focus();
        }
        return true;
    }

    function ssLoadJsonFile( file ) {
        if ( ! file ) {
            return;
        }
        const reader = new FileReader();
        reader.onload = function( ev ) {
            if ( ! ssApplyServiceAccountJson( ev.target.result, 'file' ) ) {
                alert( sheetsync.i18n.invalid_json_file || 'Please upload a valid Service Account JSON key file.' );
            }
        };
        reader.onerror = function() {
            alert( sheetsync.i18n.invalid_json_file || 'Please upload a valid Service Account JSON key file.' );
        };
        reader.readAsText( file );
    }

    $( document ).on( 'click', '#ss-json-browse-btn', function( e ) {
        e.preventDefault();
        e.stopPropagation();
        $( '#ss-json-file-input' ).trigger( 'click' );
    } );

    $( document ).on( 'click', '#ss-json-upload-zone', function( e ) {
        if ( $( e.target ).closest( '#ss-json-browse-btn, #ss-json-file-input' ).length ) {
            return;
        }
        $( this ).focus();
    } );

    $( document ).on( 'paste', '#ss-json-upload-zone', function( e ) {
        const clip = e.originalEvent && e.originalEvent.clipboardData;
        if ( ! clip ) {
            return;
        }
        e.preventDefault();
        e.stopPropagation();

        const items = clip.items;
        if ( items && items.length ) {
            for ( let i = 0; i < items.length; i++ ) {
                if ( items[ i ].kind === 'file' ) {
                    const file = items[ i ].getAsFile();
                    if ( file ) {
                        ssLoadJsonFile( file );
                        return;
                    }
                }
            }
        }

        const text = clip.getData( 'text/plain' );
        if ( text ) {
            ssApplyServiceAccountJson( text, 'paste' );
        }
    } );

    $( document ).on( 'input', '#ss-service-account-json', function() {
        const val = $( this ).val();
        if ( val && ssValidateServiceAccountJson( val ) ) {
            $( '#ss-json-upload-zone' ).addClass( 'is-filled' );
            ssJsonPasteStatus( '✅ ' + ( sheetsync.i18n.json_loaded || 'JSON loaded — click Save Settings below.' ), false );
        } else if ( ! val ) {
            $( '#ss-json-upload-zone' ).removeClass( 'is-filled' );
            ssJsonPasteStatus( '', false );
        }
    } );

    $( document ).on( 'change', '#ss-json-file-input', function() {
        if ( this.files && this.files[0] ) {
            ssLoadJsonFile( this.files[0] );
        }
    } );

    $( document ).on( 'dragover dragenter', '#ss-json-upload-zone', function( e ) {
        e.preventDefault();
        $( this ).addClass( 'is-dragover' );
    } );

    $( document ).on( 'dragleave dragend drop', '#ss-json-upload-zone', function( e ) {
        e.preventDefault();
        $( this ).removeClass( 'is-dragover' );
    } );

    $( document ).on( 'drop', '#ss-json-upload-zone', function( e ) {
        const files = e.originalEvent && e.originalEvent.dataTransfer
            ? e.originalEvent.dataTransfer.files
            : null;
        if ( files && files[0] ) {
            ssLoadJsonFile( files[0] );
        }
    } );

    // ── Copy service account email ─────────────────────────────────────────────
    $( document ).on( 'click', '.ss-copy-email-btn', function() {
        const email = $( this ).data( 'email' ) || sheetsync.account_email || '';
        if ( ! email ) {
            return;
        }
        const $btn = $( this );
        navigator.clipboard.writeText( email ).then( function() {
            const orig = $btn.text();
            $btn.text( sheetsync.i18n.copied || 'Copied!' );
            setTimeout( function() { $btn.text( orig ); }, 2000 );
        } );
    } );

    // ── Test Google auth (token only) ──────────────────────────────────────────
    $( document ).on( 'click', '#ss-test-google-auth', function() {
        const $btn = $( this );
        const $out = $( '#ss-test-google-auth-result' );
        $btn.prop( 'disabled', true );
        $out.removeClass( 'is-error is-success' ).text( sheetsync.i18n.testing || 'Testing…' );

        $.post( sheetsync.ajax_url, {
            action : 'sheetsync_test_google_auth',
            nonce  : sheetsync.nonce,
        } )
        .done( function( res ) {
            if ( res.success ) {
                const msg = res.data.message || sheetsync.i18n.test_auth_success;
                $out.addClass( 'is-success' ).text( '✅ ' + msg );
            } else {
                const msg = ( res.data && res.data.message ) || sheetsync.i18n.test_auth_failed;
                $out.addClass( 'is-error' ).text( '✗ ' + msg );
            }
        } )
        .fail( function() {
            $out.addClass( 'is-error' ).text( '✗ ' + ( sheetsync.i18n.test_auth_failed || 'Failed' ) );
        } )
        .always( function() {
            $btn.prop( 'disabled', false );
        } );
    } );

    $( document ).on( 'click', '#ss-test-external-cron', function() {
        const $btn = $( this );
        const $out = $( '#ss-test-external-cron-result' );
        $btn.prop( 'disabled', true );
        $out.removeClass( 'is-error is-success' ).text( sheetsync.i18n.testing || 'Running…' );

        $.post( sheetsync.ajax_url, {
            action : 'sheetsync_test_external_cron',
            nonce  : sheetsync.nonce,
        } )
        .done( function( res ) {
            if ( res.success ) {
                const msg = ( res.data && res.data.message ) || 'OK';
                $out.addClass( 'is-success' ).text( '✅ ' + msg );
            } else {
                const msg = ( res.data && res.data.message ) || 'Failed';
                $out.addClass( 'is-error' ).text( '✗ ' + msg );
            }
        } )
        .fail( function() {
            $out.addClass( 'is-error' ).text( '✗ Failed' );
        } )
        .always( function() {
            $btn.prop( 'disabled', false );
        } );
    } );

    function ssBuildShareErrorHtml( data ) {
        const $wrap = $( '<div>' ).addClass( 'ss-share-error-detail' );
        $wrap.append( $( '<p>' ).text( data.message || '' ) );

        const email = data.share_email || sheetsync.account_email || '';
        if ( email ) {
            const $emailRow = $( '<p>' );
            $emailRow.append( $( '<code>' ).text( email ) );
            $emailRow.append( ' ' );
            const $copy = $( '<button>', {
                type  : 'button',
                class : 'button button-small ss-copy-email-btn',
                text  : sheetsync.i18n.copy_email || 'Copy email',
            } ).attr( 'data-email', email );
            $emailRow.append( $copy );
            $wrap.append( $emailRow );
        }

        const steps = data.share_steps || sheetsync.share_steps || [];
        if ( steps.length ) {
            const $ol = $( '<ol>' );
            steps.forEach( function( step ) {
                $ol.append( $( '<li>' ).text( step ) );
            } );
            $wrap.append( $ol );
        }
        return $wrap;
    }

    // ── Test Connection ────────────────────────────────────────────────────────
    $( document ).on( 'click', '#ss-test-connection', function( e ) {
        e.preventDefault();

        const $btn = $( this );
        let spreadsheetId = $( '#spreadsheet_id' ).val().trim();
        const sheetName = $( '#sheet_name' ).val().trim();
        const $result = $( '#ss-test-result' );

        if ( $( '#spreadsheet_url' ).length && ! spreadsheetId ) {
            ssApplySpreadsheetUrl( $( '#spreadsheet_url' ), $( '#spreadsheet_id' ) );
            spreadsheetId = $( '#spreadsheet_id' ).val().trim();
        }

        spreadsheetId = ssExtractSpreadsheetId( spreadsheetId );
        if ( spreadsheetId ) {
            $( '#spreadsheet_id' ).val( spreadsheetId );
        }

        if ( ! spreadsheetId ) {
            $result.attr( 'class', 'ss-test-result error' )
                .text( sheetsync.i18n.paste_url_or_id || 'Please enter a Spreadsheet ID or URL first.' )
                .show();
            return;
        }

        $btn.text( sheetsync.i18n.testing ).prop( 'disabled', true );
        $result.hide().empty();

        $.post( sheetsync.ajax_url, {
            action         : 'sheetsync_test_connection',
            nonce          : sheetsync.nonce,
            spreadsheet_id : spreadsheetId,
            sheet_name     : sheetName,
        } )
        .done( function( response ) {
            if ( response.success ) {
                const d = response.data;

                const $strong = $( '<strong>' ).text( '\u2713 Connected to: ' );
                const $title  = $( document.createTextNode( d.title ) );
                const $sheets = $( document.createTextNode(
                    ' \u2014 Sheets: ' + ( d.sheets ? d.sheets.length : 0 )
                ) );
                $result.attr( 'class', 'ss-test-result success' )
                       .empty()
                       .append( $strong, $title, $( '<br>' ), $sheets );

                const sheetUrl = 'https://docs.google.com/spreadsheets/d/' + encodeURIComponent( spreadsheetId ) + '/edit';
                const $openLink = $( '<a>', {
                    href   : sheetUrl,
                    target : '_blank',
                    rel    : 'noopener noreferrer',
                    class  : 'button button-small',
                    text   : sheetsync.i18n.open_in_sheets || 'Open in Google Sheets',
                } );
                $result.append( $( '<p>' ).css( 'marginTop', '8px' ).append( $openLink ) );

                if ( d.sheet_tab_warning ) {
                    $result.append( $( '<p>' ).css( { color: '#b45309', marginTop: '8px' } ).text( d.sheet_tab_warning ) );
                } else if ( d.sheet_tab_found && sheetName ) {
                    $result.append( $( '<p>' ).css( { color: '#059669', marginTop: '8px' } ).text( '\u2713 Tab "' + sheetName + '" found.' ) );
                }

                if ( d.sheets && $( '#sheet_name_select' ).length ) {
                    const $sel = $( '#sheet_name_select' );
                    const cur  = $sel.data( 'current' );
                    $sel.empty();
                    d.sheets.forEach( function( s ) {
                        $sel.append( $( '<option>', { value: s, text: s } ).prop( 'selected', s === cur ) );
                    } );
                    $sel.closest( '.ss-sheet-select-row' ).show();
                }

                $result.show();
            } else {
                const d = response.data || {};
                $result.attr( 'class', 'ss-test-result error' ).empty();
                if ( d.error_type === 'share_required' ) {
                    $result.append( ssBuildShareErrorHtml( d ) );
                } else {
                    $result.text( '\u2717 ' + ( d.message || 'Connection failed.' ) );
                }
                $result.show();
            }
        } )
        .fail( function() {
            $result.attr( 'class', 'ss-test-result error' ).text( '\u2717 Request failed.' ).show();
        } )
        .always( function() {
            $btn.text( sheetsync.i18n.test_connection || 'Test Connection' ).prop( 'disabled', false );
        } );
    } );

    // ── Copy to clipboard ──────────────────────────────────────────────────────
    $( document ).on( 'click', '.ss-copy-btn', function() {
        const target = $( this ).data( 'target' );
        const text   = $( target ).text();
        navigator.clipboard.writeText( text ).then( () => {
            const $btn = $( this );
            $btn.text( 'Copied!' );
            setTimeout( () => $btn.text( 'Copy' ), 2000 );
        } );
    } );

    // ── Field Mapping form validation ─────────────────────────────────────────
    $( document ).on( 'submit', 'form:has(.column-input)', function( e ) {
        let hasMapping = false;
        let hasIdentity = false;
        let keyFieldSet = false;
        let keyFieldHasColumn = true;

        $( '.column-input:enabled', this ).each( function() {
            const val = $( this ).val().trim();
            const $row = $( this ).closest( 'tr' );
            const isKey = $row.find( 'input[type="checkbox"]' ).is( ':checked' );
            const nameMatch = ( $( this ).attr( 'name' ) || '' ).match( /field_map\[([^\]]+)\]/ );
            const fieldKey = nameMatch ? nameMatch[1] : '';

            if ( val !== '' ) {
                hasMapping = true;
                if ( fieldKey === '_sku' || fieldKey === 'product_id' ) {
                    hasIdentity = true;
                }
            }
            if ( isKey ) {
                keyFieldSet = true;
                if ( val === '' ) keyFieldHasColumn = false;
            }
        } );

        if ( ! hasMapping ) {
            e.preventDefault();
            alert( sheetsync.i18n.field_map_required );
            return false;
        }

        if ( hasMapping && ! hasIdentity ) {
            e.preventDefault();
            alert( sheetsync.i18n.identity_column_required || 'Map SKU or Product ID.' );
            return false;
        }

        if ( keyFieldSet && ! keyFieldHasColumn ) {
            // Warn if key field has no column, but do not block save
            if ( ! window.confirm( sheetsync.i18n.key_field_empty_confirm ) ) {
                e.preventDefault();
                return false;
            }
        }
    } );
    $( document ).on( 'input', '.column-input', function() {
        this.value = this.value.toUpperCase().replace( /[^A-Z]/g, '' );
    } );

    // ── Delete connection confirmation ────────────────────────────────────────
    $( document ).on( 'submit', '.ss-delete-form', function() {
        return window.confirm( sheetsync.i18n.confirm_delete );
    } );

    // ── Apply filters, banding, row groups (WC → Sheet) ───────────────────────
    $( document ).on( 'click', '.ss-apply-sheet-formatting-btn', function( e ) {
        e.preventDefault();
        const $btn   = $( this );
        const connId = $btn.data( 'connection-id' );
        const orig   = $btn.html();

        $btn.prop( 'disabled', true ).html(
            '<span class="dashicons dashicons-update ss-spin"></span> ' + ( sheetsync.i18n.applying_formatting || 'Applying filters & styling…' )
        );

        $.post( sheetsync.ajax_url, {
            action        : 'sheetsync_apply_sheet_formatting',
            nonce         : sheetsync.nonce,
            connection_id : connId,
        } )
        .done( function( response ) {
            const msg = response.success
                ? ( response.data.message || 'OK' )
                : ( response.data && response.data.message ) || sheetsync.i18n.sync_error;
            syncFeedbackAnchor( $btn ).after(
                '<div class="ss-sync-toast ' + ( response.success ? 'ss-toast-success' : 'ss-toast-error' ) + '">' +
                $( '<span>' ).text( msg ).html() + '</div>'
            );
        } )
        .fail( function() {
            syncFeedbackAnchor( $btn ).after(
                '<div class="ss-sync-toast ss-toast-error">' + sheetsync.i18n.sync_error + '</div>'
            );
        } )
        .always( function() {
            $btn.prop( 'disabled', false ).html( orig );
        } );
    } );

    // ── Write export headers (WC → Sheet) ─────────────────────────────────────
    $( document ).on( 'click', '.ss-prepare-sheet-headers-btn', function( e ) {
        e.preventDefault();
        const $btn   = $( this );
        const connId = $btn.data( 'connection-id' );
        const orig   = $btn.html();

        $btn.prop( 'disabled', true ).html(
            '<span class="dashicons dashicons-update ss-spin"></span> ' + ( sheetsync.i18n.writing_headers || 'Writing headers…' )
        );

        $.post( sheetsync.ajax_url, {
            action        : 'sheetsync_write_export_headers',
            nonce         : sheetsync.nonce,
            connection_id : connId,
        } )
        .done( function( response ) {
            const msg = response.success
                ? ( response.data.message || 'OK' )
                : ( response.data && response.data.message ) || sheetsync.i18n.sync_error;
            syncFeedbackAnchor( $btn ).after(
                '<div class="ss-sync-toast ' + ( response.success ? 'ss-toast-success' : 'ss-toast-error' ) + '">' +
                $( '<span>' ).text( msg ).html() + '</div>'
            );
        } )
        .fail( function() {
            syncFeedbackAnchor( $btn ).after(
                '<div class="ss-sync-toast ss-toast-error">' + sheetsync.i18n.sync_error + '</div>'
            );
        } )
        .always( function() {
            $btn.prop( 'disabled', false ).html( orig );
        } );
    } );

    // ── Import Headers from Sheet ─────────────────────────────────────────────
    $( document ).on( 'click', '.ss-import-headers-btn', function( e ) {
        e.preventDefault();

        const $btn      = $( this );
        const connId    = $btn.data( 'connection-id' );
        const nonce     = $btn.data( 'nonce' );
        const $result   = $btn.siblings( '.ss-import-result' );
        const origText  = $btn.text();

        $btn.text( sheetsync.i18n.importing ).prop( 'disabled', true );
        $result.text( '' ).removeClass( 'success error' );

        $.post( sheetsync.ajax_url, {
            action        : 'sheetsync_import_headers',
            nonce         : nonce,
            connection_id : connId,
        } )
        .done( function( response ) {
            if ( response.success ) {
                const d = response.data;

                // Apply matched fields to inputs
                d.matched.forEach( function( m ) {
                    const $input = $( 'input[name="field_map[' + m.wc_field + '][column]"]' );
                    if ( $input.length ) {
                        // Remove disabled so value is included in form submit
                        $input.prop( 'disabled', false );
                        const highlight = m.wc_field === 'product_id' ? '#dbeafe' : '#dcfce7';
                        $input.val( m.col_letter ).css( 'background', highlight ).trigger( 'change' );
                        const $row = $input.closest( 'tr' );
                        if ( m.wc_field === 'product_id' ) {
                            $row.addClass( 'ss-identity-matched' );
                        }
                        // Enable checkbox (Pro fields may start disabled)
                        const $cb = $row.find( 'input[type="checkbox"]' );
                        $cb.prop( 'disabled', false );
                        // Auto-check key field when SKU is matched; Product ID is never a key field
                        if ( m.wc_field === '_sku' ) {
                            $cb.prop( 'checked', true );
                        } else if ( m.wc_field === 'product_id' ) {
                            $cb.prop( 'checked', false );
                        }
                    }
                } );

                let msg;
                if ( d.auto_generated ) {
                    // Headers were written to the Sheet with styling
                    const sheetIcon = d.headers_written ? '🎨 ' : '⚠️ ';
                    msg = sheetIcon + ( d.notice || 'Headers written to Google Sheet! Column mapping applied (A, B, C…).' );
                    $result.text( msg ).css( 'color', d.headers_written ? '#059669' : '#d97706' );
                } else {
                    msg = '✅ ' + d.matched.length + ' ' + sheetsync.i18n.fields_matched;
                    if ( d.headers_written ) {
                        msg += ' · 🎨 Sheet headers styled!';
                    }
                    if ( d.unmatched && d.unmatched.length ) {
                        msg += ' (' + d.unmatched.length + ' ' + sheetsync.i18n.unmatched + ': ';
                        msg += d.unmatched.map( c => c.header + '=' + c.col_letter ).join( ', ' ) + ')';
                    }
                    $result.text( msg ).css( 'color', '#16a34a' );
                }

                // Server-side already saved the field maps via AJAX.
                // Reload the page to show the saved column letters (green inputs)
                // without depending on disabled-input form submit.
                setTimeout( function() {
                    window.location.reload();
                }, 1000 );

            } else {
                const msg = ( response.data && response.data.message ) || 'Import failed.';
                $result.text( '❌ ' + msg ).css( 'color', '#dc2626' );
            }
        } )
        .fail( function( jqXHR ) {
            let errMsg = '❌ Request failed. Please try again.';
            try {
                const resp = JSON.parse( jqXHR.responseText );
                if ( resp && resp.data && resp.data.message ) {
                    errMsg = '❌ ' + resp.data.message;
                }
            } catch ( e ) {}
            // Quota / rate-limit hint
            if ( jqXHR.status === 429 || errMsg.toLowerCase().indexOf( 'quota' ) !== -1 ) {
                errMsg = '⏳ Google API quota exceeded. Please wait 30–60 seconds and try again.';
            }
            $result.text( errMsg ).css( 'color', '#dc2626' );
        } )
        .always( function() {
            $btn.text( origText ).prop( 'disabled', false );
        } );
    } );

    // ── Tab navigation ────────────────────────────────────────────────────────
    function ssSettingsSyncExternalPanels( target ) {
        const $wrap = $( '.sheetsync-settings-wrap' );
        if ( ! $wrap.length ) {
            return;
        }
        $wrap.find( '.ss-tab-panel-external' ).hide();
        if ( ! target ) {
            return;
        }
        const panelId = String( target ).replace( '#', '' );
        $wrap.find( '.ss-tab-panel-external[data-ss-settings-panel="' + panelId + '"]' ).show();
    }

    $( document ).on( 'click', '.ss-tab', function( e ) {
        e.preventDefault();
        const target = $( this ).attr( 'href' );

        $( '.ss-tab' ).removeClass( 'active' );
        $( this ).addClass( 'active' );

        $( '.ss-tab-panel' ).hide();
        $( target ).show();

        ssSettingsSyncExternalPanels( target );

        // If showing connection tab, init date filter visibility
        if ( target === '#tab-connection' ) {
            setTimeout( ss_date_filter_init, 30 );
        }

        // Update URL hash without scrolling
        history.replaceState( null, '', target );
    } );

    // Activate tab from URL hash on load
    // Supports both #tab-field-mapping and legacy #field-mapping formats
    const hash = window.location.hash;
    let activated = false;
    if ( hash ) {
        // Try exact match first
        let $tabLink = $( '.ss-tab[href="' + hash + '"]' );
        // Try adding 'tab-' prefix if no exact match (e.g. #field-mapping → #tab-field-mapping)
        if ( ! $tabLink.length ) {
            const prefixed = hash.replace( '#', '#tab-' );
            $tabLink = $( '.ss-tab[href="' + prefixed + '"]' );
        }
        if ( $tabLink.length ) {
            $tabLink.trigger( 'click' );
            activated = true;
        }
    }
    if ( ! activated ) {
        $( '.ss-tab:first' ).addClass( 'active' );
        $( '.ss-tab-panel:first' ).show();
        // After making the first panel visible, run date filter init
        const firstHref = $( '.ss-tab:first' ).attr( 'href' );
        if ( firstHref === '#tab-connection' ) {
            ss_date_filter_init();
        }
        ssSettingsSyncExternalPanels( firstHref );
    } else {
        ssSettingsSyncExternalPanels( hash );
    }

    // ── Spin animation for dashicons ──────────────────────────────────────────
    const spinStyle = document.createElement( 'style' );
    spinStyle.textContent = '.ss-spin { animation: ss-rotate 1s linear infinite; }' +
                            '@keyframes ss-rotate { to { transform: rotate(360deg); } }';
    document.head.appendChild( spinStyle );

    // ── Sync Strategy Cards — visual selection ────────────────────────────
    $( document ).on( 'change', '.ss-strategy-card input[type="radio"]', function() {
        $( '.ss-strategy-card' ).removeClass( 'selected' );
        $( this ).closest( '.ss-strategy-card' ).addClass( 'selected' );
    } );

    // ── Schedule Options — visual selection ──────────────────────────────
    $( document ).on( 'change', '.ss-schedule-option input[type="radio"]', function() {
        $( '.ss-schedule-option' ).removeClass( 'selected' );
        $( this ).closest( '.ss-schedule-option' ).addClass( 'selected' );
    } );

    // ── Date Filter ───────────────────────────────────────────────────────

    function ss_date_filter_init() {
        const $type_select = $( '#sheetsync_connection_type' );
        if ( ! $type_select.length ) return;

        // Read value from the selected option directly — more reliable than .val()
        // because .val() can return null/wrong value when the selected option is disabled.
        const val      = $type_select.find( 'option:selected' ).val() || $type_select.val() || '';
        const is_order = val !== '' && val !== 'products';

        if ( is_order ) {
            $( '#sheetsync-date-filter-row' ).show();
        } else {
            $( '#sheetsync-date-filter-row' ).hide();
        }

        // Sub-fields
        const dtype = $( '#sheetsync_date_type' ).val() || 'all';
        if ( dtype === 'single' ) {
            $( '#sheetsync-date-single' ).show();
            $( '#sheetsync-date-range'  ).hide();
        } else if ( dtype === 'range' ) {
            $( '#sheetsync-date-single' ).hide();
            $( '#sheetsync-date-range'  ).show();
        } else {
            $( '#sheetsync-date-single' ).hide();
            $( '#sheetsync-date-range'  ).hide();
        }
    }

    // Connection Type change → instant show/hide, no reload
    $( document ).on( 'change', '#sheetsync_connection_type', function() {
        const val      = $( this ).find( 'option:selected' ).val() || $( this ).val() || '';
        const is_order = val !== '' && val !== 'products';
        if ( is_order ) {
            $( '#sheetsync-date-filter-row' ).show();
        } else {
            $( '#sheetsync-date-filter-row' ).hide();
            $( '#sheetsync-date-single' ).hide();
            $( '#sheetsync-date-range'  ).hide();
        }
    } );

    // Date sub-type change
    $( document ).on( 'change', '#sheetsync_date_type', function() {
        $( '#sheetsync-date-single' ).toggle( $( this ).val() === 'single' );
        $( '#sheetsync-date-range'  ).toggle( $( this ).val() === 'range'  );
    } );

    // Run init only if the connection tab is already visible on page load.
    // PHP already renders the correct show/hide state via inline style,
    // so we only re-run if JS tab switching has not yet occurred.
    if ( $( '#tab-connection' ).is( ':visible' ) ) {
        ss_date_filter_init();
    }

    // ── One-click bootstrap ────────────────────────────────────────────────────
    function ssRenderBootstrapSteps( $list, steps, fixes ) {
        $list.empty();
        ( steps || [] ).forEach( function( step, i ) {
            const icon = step.status === 'ok' ? '✅' : ( step.status === 'warn' ? '⚠️' : '❌' );
            const $li = $( '<li>' ).addClass( 'ss-bootstrap-step ss-bs-' + ( step.status || 'pending' ) );
            $li.append( $( '<span>' ).addClass( 'ss-bs-num' ).text( ( i + 1 ) + '/4' ) );
            $li.append( $( '<strong>' ).text( step.label || '' ) );
            $li.append( $( '<span>' ).addClass( 'ss-bs-msg' ).text( step.message || '' ) );
            $li.prepend( document.createTextNode( icon + ' ' ) );
            $list.append( $li );
        } );
        const $fixes = $( '#ss-bootstrap-fixes' );
        if ( fixes && fixes.length ) {
            let html = '<h4>' + ( sheetsync.i18n.bootstrap_failed || 'Fix suggestions' ) + '</h4><ul>';
            fixes.forEach( function( f ) {
                html += '<li>' + $( '<span>' ).text( f.message || '' ).html() + '</li>';
            } );
            html += '</ul>';
            $fixes.html( html ).show();
        } else {
            $fixes.hide().empty();
        }
    }

    $( document ).on( 'click', '.ss-bootstrap-sheet-btn', function( e ) {
        e.preventDefault();
        const $btn   = $( this );
        const connId = $btn.data( 'connection-id' );
        const $modal = $( '#ss-bootstrap-modal' );
        const $list  = $( '#ss-bootstrap-steps' );
        const orig   = $btn.text();

        $modal.show();
        $list.html( '<li class="ss-bs-pending">' + ( sheetsync.i18n.bootstrap_title || 'Setting up…' ) + '</li>' );
        $( '#ss-bootstrap-fixes' ).hide().empty();
        $btn.prop( 'disabled', true ).text( sheetsync.i18n.bootstrap_title || 'Setting up…' );

        $.post( sheetsync.ajax_url, {
            action        : 'sheetsync_bootstrap_connection',
            nonce         : sheetsync.nonce,
            connection_id : connId,
        } )
        .done( function( res ) {
            const data = res.data || {};
            ssRenderBootstrapSteps( $list, data.steps, data.fixes );
            if ( res.success ) {
                $( '#ss-bootstrap-modal-title' ).text( sheetsync.i18n.bootstrap_done || 'Done' );
            }
        } )
        .fail( function( xhr ) {
            const data = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
            ssRenderBootstrapSteps( $list, data.steps || [], data.fixes || [] );
        } )
        .always( function() {
            $btn.prop( 'disabled', false ).text( orig );
        } );
    } );

    $( document ).on( 'click', '#ss-bootstrap-modal-close', function() {
        $( '#ss-bootstrap-modal' ).hide();
    } );

    // ── Webhook test from WP admin ─────────────────────────────────────────────
    $( document ).on( 'click', '.ss-test-webhook-btn', function() {
        const $btn   = $( this );
        const connId = $btn.data( 'connection-id' );
        const $out   = $btn.siblings( '.ss-webhook-test-result' );
        $out.text( '…' );
        $.post( sheetsync.ajax_url, {
            action        : 'sheetsync_test_webhook',
            nonce         : sheetsync.nonce,
            connection_id : connId,
        } )
        .done( function( res ) {
            if ( res.success ) {
                $out.css( 'color', '#065f46' ).text( '✅ ' + ( res.data.message || sheetsync.i18n.webhook_test_ok ) );
                const $badge = $( '.ss-realtime-status-badge' ).first();
                if ( $badge.length ) {
                    $badge.removeClass( 'ss-rt-pending ss-rt-off' ).addClass( 'ss-rt-verified' );
                    $badge.text( sheetsync.i18n.webhook_verified_label || 'Active — trigger verified' );
                }
            } else {
                $out.css( 'color', '#991b1b' ).text( '❌ ' + ( res.data && res.data.message ? res.data.message : sheetsync.i18n.webhook_test_fail ) );
            }
        } )
        .fail( function() {
            $out.css( 'color', '#991b1b' ).text( '❌ ' + ( sheetsync.i18n.webhook_test_fail || 'Failed' ) );
        } );
    } );

    // ── Mapping profile cards ──────────────────────────────────────────────────
    function ssApplyMapPreset( preset ) {
        const map = preset === 'simple' || preset === 'minimal'
            ? ( sheetsync.minimal_map || {} )
            : ( sheetsync.recommended_map || {} );
        Object.keys( map ).forEach( function( field ) {
            const cfg = map[ field ];
            const $col = $( 'input[name="field_map[' + field + '][column]"]' );
            const $key = $( 'input[name="field_map[' + field + '][key]"]' );
            if ( $col.length && cfg.column ) {
                $col.val( cfg.column );
            }
            if ( $key.length ) {
                $key.prop( 'checked', field === 'product_id' ? false : !! cfg.key );
            }
        } );
        $( '#sheetsync_map_profile' ).val( preset === 'simple' ? 'simple' : ( preset === 'custom' ? 'custom' : 'full' ) );
    }

    $( document ).on( 'change', 'input[name="ss_map_profile_ui"]', function() {
        const preset = $( this ).val();
        $( '.ss-map-profile-card' ).removeClass( 'is-selected' );
        $( this ).closest( '.ss-map-profile-card' ).addClass( 'is-selected' );
        if ( preset === 'custom' ) {
            $( '#ss-mapping-table-advanced' ).show();
            $( '.ss-advanced-mapping-wrap' ).hide();
            $( '#sheetsync_map_profile' ).val( 'custom' );
        } else {
            ssApplyMapPreset( preset );
            $( '#ss-mapping-table-advanced' ).hide();
            $( '.ss-advanced-mapping-wrap' ).show();
        }
    } );

    $( document ).on( 'click', '#ss-toggle-advanced-mapping, .ss-toggle-advanced-mapping', function( e ) {
        e.preventDefault();
        $( '#ss-mapping-table-advanced' ).slideToggle();
        $( 'input[name="ss_map_profile_ui"][value="custom"]' ).prop( 'checked', true );
        $( '.ss-map-profile-card' ).removeClass( 'is-selected' );
        $( 'input[name="ss_map_profile_ui"][value="custom"]' ).closest( '.ss-map-profile-card' ).addClass( 'is-selected' );
        $( '#sheetsync_map_profile' ).val( 'custom' );
    } );

    // Email sync report (Sync Reports page)
    $( document ).on( 'click', '.ss-send-report-email-btn', function() {
        const $btn = $( this );
        const $out = $btn.siblings( '.ss-send-report-result' );
        $btn.prop( 'disabled', true );
        $out.text( sheetsync.i18n.email_report_sending || 'Sending…' );

        $.post( sheetsync.ajax_url, {
            action : 'sheetsync_send_email_report',
            nonce  : sheetsync.nonce,
            period : $btn.data( 'period' ) || 'daily',
        } )
        .done( function( res ) {
            if ( res.success ) {
                $out.css( 'color', '#065f46' ).text( res.data.message || sheetsync.i18n.email_report_sent );
            } else {
                $out.css( 'color', '#991b1b' ).text(
                    ( res.data && res.data.message ) ? res.data.message : ( sheetsync.i18n.email_report_error || 'Failed' )
                );
            }
        } )
        .fail( function() {
            $out.css( 'color', '#991b1b' ).text( sheetsync.i18n.email_report_error || 'Failed' );
        } )
        .always( function() {
            $btn.prop( 'disabled', false );
        } );
    } );

    // Off-peak full publish (AJAX quick schedule)
    $( document ).on( 'click', '.ss-schedule-off-peak-btn', function() {
        const $btn   = $( this );
        const connId = $btn.data( 'connection-id' );
        const $card  = $btn.closest( '.ss-off-peak-publish-card' );
        const $feedback = $card.find( '#ss-off-peak-publish-feedback' );
        const $status   = $card.find( '#ss-off-peak-publish-status' );

        $btn.prop( 'disabled', true );
        $feedback.text( sheetsync.i18n.off_peak_scheduling || 'Scheduling…' );

        $.post( sheetsync.ajax_url, {
            action        : 'sheetsync_schedule_off_peak_publish',
            nonce         : sheetsync.nonce,
            connection_id : connId,
            hour          : $btn.data( 'hour' ) || 2,
            minute        : $btn.data( 'minute' ) || 0,
            one_shot      : $btn.data( 'one-shot' ) ? 1 : 0,
            recurring     : $btn.data( 'one-shot' ) ? 0 : 1,
        } )
        .done( function( res ) {
            if ( res.success ) {
                $feedback.css( 'color', '#065f46' ).text( res.data.message || sheetsync.i18n.off_peak_scheduled );
                if ( res.data.next_run ) {
                    const oneTime = $btn.data( 'one-shot' ) ? ' <em>(one time)</em>' : '';
                    $status.removeAttr( 'hidden' ).html(
                        '<p class="ss-off-peak-publish-next-run">' +
                        '<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span> ' +
                        $( '<span>' ).text( res.data.next_run ).html() +
                        oneTime +
                        '</p>'
                    );
                }
            } else {
                $feedback.css( 'color', '#991b1b' ).text(
                    ( res.data && res.data.message ) ? res.data.message : ( sheetsync.i18n.off_peak_error || 'Could not schedule.' )
                );
            }
        } )
        .fail( function() {
            $feedback.css( 'color', '#991b1b' ).text( sheetsync.i18n.off_peak_error || 'Could not schedule.' );
        } )
        .always( function() {
            $btn.prop( 'disabled', false );
        } );
    } );

    // Unified automatic sync toggle (AJAX)
    $( document ).on( 'change', '.ss-automatic-sync-toggle', function() {
        const $toggle = $( this );
        const connId  = $toggle.data( 'connection-id' );
        const enabled = $toggle.is( ':checked' );
        const $card   = $toggle.closest( '.ss-automatic-sync-unified-card' );
        const $saving = $card.find( '.ss-automatic-sync-saving' );
        const $status = $( '#ss-automatic-sync-status' );
        const $hint   = $card.find( '.ss-automatic-sync-off-hint' );
        const $setup  = $( '.ss-realtime-setup-panel' );

        $toggle.prop( 'disabled', true );
        $saving.removeAttr( 'hidden' );

        $.post( sheetsync.ajax_url, {
            action        : 'sheetsync_toggle_automatic_sync',
            nonce         : sheetsync.nonce,
            connection_id : connId,
            enabled       : enabled ? 1 : 0,
        } )
        .done( function( res ) {
            if ( res.success ) {
                const lines = ( res.data && res.data.status ) ? res.data.status : [];
                $status.empty();
                if ( lines.length ) {
                    lines.forEach( function( line ) {
                        $status.append( $( '<li>' ).text( line ) );
                    } );
                    $status.removeAttr( 'hidden' );
                    $hint.attr( 'hidden', 'hidden' );
                } else {
                    $status.attr( 'hidden', 'hidden' );
                    $hint.removeAttr( 'hidden' );
                }
                $setup.toggle( enabled );
            } else {
                $toggle.prop( 'checked', ! enabled );
                window.alert( ( res.data && res.data.message ) ? res.data.message : ( sheetsync.i18n.automatic_sync_error || 'Could not update automatic sync.' ) );
            }
        } )
        .fail( function() {
            $toggle.prop( 'checked', ! enabled );
            window.alert( sheetsync.i18n.automatic_sync_error || 'Could not update automatic sync.' );
        } )
        .always( function() {
            $toggle.prop( 'disabled', false );
            $saving.attr( 'hidden', 'hidden' );
        } );
    } );

    // Real-time panel visibility when legacy toggle form submits (fallback)
    $( document ).on( 'change', '.ss-realtime-toggle-form input[type="checkbox"]', function() {
        $( '.ss-realtime-setup-panel' ).toggle( $( this ).is( ':checked' ) );
    } );

    // Two-way conflict resolution
    $( document ).on( 'change', '#ss-on-conflict-select', function() {
        const isMerge = $( this ).val() === 'merge';
        $( '#ss-merge-policy-details' ).toggle( isMerge );
    } );

    $( document ).on( 'click', '.ss-resolve-conflict', function( e ) {
        e.preventDefault();
        const $btn   = $( this );
        const $row   = $btn.closest( 'tr' );
        const $panel = $btn.closest( '#ss-conflicts-panel' );
        const connId = parseInt( $btn.data( 'connection-id' ) || $row.data( 'connection-id' ) || $panel.data( 'connection-id' ), 10 );
        const mapId  = parseInt( $btn.data( 'map-id' ) || $row.data( 'map-id' ), 10 );
        const resolution = $btn.data( 'resolution' );

        if ( ! connId || ! mapId || ! resolution ) {
            return;
        }

        $btn.prop( 'disabled', true );

        $.post( sheetsync.ajax_url, {
            action        : 'sheetsync_resolve_conflict',
            nonce         : sheetsync.nonce,
            connection_id : connId,
            map_id        : mapId,
            resolution    : resolution,
        } )
        .done( function( response ) {
            if ( response.success ) {
                const msg = response.data && response.data.message ? response.data.message : 'OK';
                $row.fadeOut( 200, function() {
                    $( this ).remove();
                    if ( ! $( '#ss-conflicts-panel tbody tr' ).length ) {
                        $( '#ss-conflicts-panel' ).remove();
                    }
                    if ( ! $( '.ss-conflicts-inbox-table tbody tr' ).length ) {
                        $( '.ss-conflicts-inbox-table' ).closest( '.sheetsync-card' ).find( '.ss-empty-state' ).show();
                    }
                } );
                if ( msg ) {
                    syncFeedbackAnchor( $btn ).after(
                        '<div class="ss-sync-toast ss-toast-success">' + $( '<span>' ).text( msg ).html() + '</div>'
                    );
                }
            } else {
                window.alert( response.data && response.data.message ? response.data.message : 'Error' );
                $btn.prop( 'disabled', false );
            }
        } )
        .fail( function() {
            window.alert( 'Request failed.' );
            $btn.prop( 'disabled', false );
        } );
    } );

    // ── Settings: webhook secret (never embedded in page HTML) ─────────────────
    let ssWebhookSecretCache = '';

    $( document ).on( 'click', '#ss-reveal-secret', function() {
        const $field = $( '#webhook-secret-field' );
        const $btn   = $( this );
        if ( ! $field.length || $field.data( 'configured' ) !== 1 ) {
            return;
        }
        if ( $field.attr( 'type' ) === 'text' && ssWebhookSecretCache ) {
            $field.attr( 'type', 'password' ).val( '' );
            $btn.text( sheetsync.i18n.reveal_secret || 'Reveal' );
            return;
        }
        $btn.prop( 'disabled', true );
        $.post( sheetsync.ajax_url, {
            action        : 'sheetsync_reveal_webhook_secret',
            nonce         : sheetsync.nonce,
            connection_id : 0,
        } )
        .done( function( res ) {
            if ( res.success && res.data && res.data.secret ) {
                ssWebhookSecretCache = res.data.secret;
                $field.attr( 'type', 'text' ).val( res.data.secret );
                $btn.text( sheetsync.i18n.hide_secret || 'Hide' );
            } else {
                window.alert( res.data && res.data.message ? res.data.message : ( sheetsync.i18n.secret_load_failed || 'Failed to load secret.' ) );
            }
        } )
        .fail( function() {
            window.alert( sheetsync.i18n.secret_load_failed || 'Failed to load secret.' );
        } )
        .always( function() {
            $btn.prop( 'disabled', false );
        } );
    } );

    $( document ).on( 'click', '#ss-copy-secret', function() {
        const $copyBtn = $( this );
        const $field   = $( '#webhook-secret-field' );
        if ( ! $field.length || $field.data( 'configured' ) !== 1 ) {
            return;
        }
        const copySecret = function( secret ) {
            navigator.clipboard.writeText( secret ).then( function() {
                const orig = $copyBtn.text();
                $copyBtn.text( sheetsync.i18n.copied || 'Copied!' );
                setTimeout( function() { $copyBtn.text( orig ); }, 2000 );
            } );
        };
        if ( ssWebhookSecretCache ) {
            copySecret( ssWebhookSecretCache );
            return;
        }
        $copyBtn.prop( 'disabled', true );
        $.post( sheetsync.ajax_url, {
            action        : 'sheetsync_reveal_webhook_secret',
            nonce         : sheetsync.nonce,
            connection_id : 0,
        } )
        .done( function( res ) {
            if ( res.success && res.data && res.data.secret ) {
                ssWebhookSecretCache = res.data.secret;
                copySecret( res.data.secret );
            }
        } )
        .always( function() {
            $copyBtn.prop( 'disabled', false );
        } );
    } );

} )( jQuery );
