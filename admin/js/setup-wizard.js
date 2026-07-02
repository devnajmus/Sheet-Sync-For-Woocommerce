( function( $ ) {
    'use strict';

    if ( typeof sheetsyncWizard === 'undefined' ) {
        return;
    }

    const W = sheetsyncWizard;
    let currentStep = Math.max( 1, Math.min( 7, parseInt( W.initial_step, 10 ) || 1 ) );
    let connectionId = parseInt( W.connection_id, 10 ) || 0;
    let googleReady = !!( W.account_email && W.account_email !== '' );
    let sheetTested = false;

    function ssExtractSpreadsheetId( input ) {
        const raw = ( input || '' ).trim();
        if ( ! raw ) {
            return '';
        }
        const match = raw.match( /spreadsheets\/d\/([a-zA-Z0-9_-]+)/ );
        return match ? match[1] : raw;
    }

    function getSheetTabName() {
        const custom = ( $( '#ss-wizard-sheet-tab-custom' ).val() || '' ).trim();
        if ( custom ) {
            return custom;
        }
        return $( '#ss-wizard-sheet-tab' ).val() || $( '#ss-wizard-sheet-tab-value' ).val() || 'Sheet1';
    }

    function setSheetTabName( name ) {
        const tab = ( name || 'Sheet1' ).trim() || 'Sheet1';
        $( '#ss-wizard-sheet-tab-value' ).val( tab );
        const $sel = $( '#ss-wizard-sheet-tab' );
        if ( $sel.find( 'option[value="' + tab.replace( /"/g, '\\"' ) + '"]' ).length ) {
            $sel.val( tab );
        }
    }

    function getSelectedDirection() {
        const $checked = $( 'input[name="ss_wizard_direction"]:checked' );
        if ( ! $checked.length || $checked.prop( 'disabled' ) ) {
            return 'sheets_to_wc';
        }
        return $checked.val() || 'sheets_to_wc';
    }

    function saveState( patch ) {
        return $.post( W.ajax_url, {
            action : 'sheetsync_save_wizard_state',
            nonce  : W.nonce,
            state  : patch,
        } );
    }

    function showStep( step ) {
        currentStep = Math.max( 1, Math.min( 7, step ) );
        $( '.ss-wizard-panel' ).hide();
        $( '.ss-wizard-panel[data-step="' + currentStep + '"]' ).show();
        $( '.ss-wizard-step-pill' ).removeClass( 'is-active' );
        $( '.ss-wizard-step-pill[data-step="' + currentStep + '"]' ).addClass( 'is-active' );
        $( '#ss-wizard-prev' ).prop( 'disabled', currentStep <= 1 );
        $( '#ss-wizard-next' ).toggle( currentStep < 7 );
        saveState( { step: currentStep } );
    }

    function collectStepData() {
        const data = { step: currentStep };
        if ( currentStep >= 2 ) {
            data.spreadsheet_id = $( '#ss-wizard-sheet-id' ).val() || ssExtractSpreadsheetId( $( '#ss-wizard-sheet-url' ).val() );
            data.sheet_name     = getSheetTabName();
        }
        if ( currentStep >= 3 ) {
            data.sync_direction = getSelectedDirection();
        }
        if ( currentStep >= 4 ) {
            data.connection_name = $( '#ss-wizard-conn-name' ).val() || '';
        }
        return data;
    }

    function validateBeforeNext() {
        if ( currentStep === 1 && ! googleReady ) {
            alert( W.i18n.google_required || 'Connect Google first — click “Save & test Google” or use OAuth in Settings.' );
            return false;
        }
        if ( currentStep === 2 ) {
            const spreadsheetId = $( '#ss-wizard-sheet-id' ).val() || ssExtractSpreadsheetId( $( '#ss-wizard-sheet-url' ).val() );
            if ( ! spreadsheetId ) {
                alert( W.i18n.paste_url_or_id || 'Paste your Google Sheet URL or create a new sheet.' );
                return false;
            }
            if ( ! sheetTested ) {
                alert( W.i18n.sheet_test_required || 'Click “Test sheet access” to confirm SheetSync can reach your sheet.' );
                return false;
            }
        }
        if ( currentStep === 3 && ! W.is_pro ) {
            const dir = getSelectedDirection();
            if ( dir === 'wc_to_sheets' || dir === 'two_way' ) {
                alert( W.i18n.pro_direction_required || 'Export and two-way sync require SheetSync Pro. Choose Import or upgrade.' );
                return false;
            }
        }
        return true;
    }

    function ssLoadJsonFile( file ) {
        if ( ! file || ! /\.json$/i.test( file.name ) ) {
            alert( W.i18n.invalid_json || 'Invalid JSON file' );
            return;
        }
        const reader = new FileReader();
        reader.onload = function( e ) {
            $( '#ss-wizard-json' ).val( e.target.result );
        };
        reader.readAsText( file );
    }

    // JSON upload zone
    $( document ).on( 'click', '#ss-wizard-json-zone', function() {
        $( '#ss-wizard-json-file' ).trigger( 'click' );
    } );

    $( document ).on( 'change', '#ss-wizard-json-file', function() {
        if ( this.files && this.files[0] ) {
            ssLoadJsonFile( this.files[0] );
        }
    } );

    $( document ).on( 'dragover dragenter', '#ss-wizard-json-zone', function( e ) {
        e.preventDefault();
        $( this ).addClass( 'is-dragover' );
    } );

    $( document ).on( 'dragleave dragend drop', '#ss-wizard-json-zone', function( e ) {
        e.preventDefault();
        $( this ).removeClass( 'is-dragover' );
    } );

    $( document ).on( 'drop', '#ss-wizard-json-zone', function( e ) {
        const files = e.originalEvent && e.originalEvent.dataTransfer ? e.originalEvent.dataTransfer.files : null;
        if ( files && files[0] ) {
            ssLoadJsonFile( files[0] );
        }
    } );

    // Sheet URL → ID
    $( document ).on( 'input paste', '#ss-wizard-sheet-url', function() {
        const id = ssExtractSpreadsheetId( $( this ).val() );
        if ( id ) {
            $( '#ss-wizard-sheet-id' ).val( id );
            sheetTested = false;
        }
    } );

    $( document ).on( 'change input', '#ss-wizard-sheet-tab, #ss-wizard-sheet-tab-custom', function() {
        setSheetTabName( getSheetTabName() );
        sheetTested = false;
    } );

    // Workflow cards
    $( document ).on( 'change', 'input[name="ss_wizard_direction"]', function() {
        if ( $( this ).prop( 'disabled' ) ) {
            $( 'input[name="ss_wizard_direction"][value="sheets_to_wc"]' ).prop( 'checked', true );
            return;
        }
        $( '.ss-wizard-workflow-card' ).removeClass( 'is-selected' );
        $( this ).closest( '.ss-wizard-workflow-card' ).addClass( 'is-selected' );
    } );

    $( document ).on( 'click', '.ss-wizard-workflow-card.is-pro-locked', function( e ) {
        if ( ! W.is_pro && W.upgrade_url ) {
            e.preventDefault();
            if ( confirm( W.i18n.pro_direction_confirm || 'Export and two-way sync require SheetSync Pro. Open the upgrade page?' ) ) {
                window.open( W.upgrade_url, '_blank', 'noopener' );
            }
        }
    } );

    // Step 1: Save Google
    $( document ).on( 'click', '#ss-wizard-save-google', function() {
        const $btn = $( this );
        const $out = $( '#ss-wizard-google-result' );
        const json = $( '#ss-wizard-json' ).val().trim();
        $btn.prop( 'disabled', true );
        $out.removeClass( 'is-error is-success' ).text( W.i18n.testing || 'Testing…' );

        $.post( W.ajax_url, {
            action : 'sheetsync_wizard_save_google',
            nonce  : W.nonce,
            service_account_json : json,
        } )
        .done( function( res ) {
            if ( res.success ) {
                googleReady = true;
                const email = res.data.email || '';
                $out.addClass( 'is-success' ).text( '✅ ' + ( res.data.message || W.i18n.test_auth_success ) );
                if ( email ) {
                    $( '.ss-wizard-panel[data-step="1"] .ss-share-instructions-card' ).remove();
                    const $card = $( '<div class="ss-share-instructions-card"><h3></h3><code></code> <button type="button" class="button ss-copy-email-btn"></button></div>' );
                    $card.find( 'h3' ).text( W.i18n.share_sheet_title || 'Share with' );
                    $card.find( 'code' ).text( email );
                    $card.find( '.ss-copy-email-btn' ).attr( 'data-email', email ).text( W.i18n.copy_email || 'Copy' );
                    $( '#ss-wizard-save-google' ).parent().after( $card );
                }
            } else {
                googleReady = false;
                $out.addClass( 'is-error' ).text( '✗ ' + ( ( res.data && res.data.message ) || W.i18n.test_auth_failed ) );
            }
        } )
        .fail( function() {
            googleReady = false;
            $out.addClass( 'is-error' ).text( '✗ ' + ( W.i18n.test_auth_failed || 'Failed' ) );
        } )
        .always( function() {
            $btn.prop( 'disabled', false );
        } );
    } );

    // Step 2: Test sheet
    $( document ).on( 'click', '#ss-wizard-test-sheet', function() {
        const $btn = $( this );
        const $out = $( '#ss-wizard-sheet-result' );
        const spreadsheetId = $( '#ss-wizard-sheet-id' ).val() || ssExtractSpreadsheetId( $( '#ss-wizard-sheet-url' ).val() );
        const sheetName = getSheetTabName();

        if ( ! spreadsheetId ) {
            $out.addClass( 'is-error' ).text( W.i18n.paste_url_or_id || 'Paste URL or ID' );
            return;
        }

        $btn.prop( 'disabled', true );
        $out.removeClass( 'is-error is-success' ).text( W.i18n.testing || 'Testing…' );

        $.post( W.ajax_url, {
            action         : 'sheetsync_test_connection',
            nonce          : W.nonce,
            spreadsheet_id : spreadsheetId,
            sheet_name     : sheetName,
        } )
        .done( function( res ) {
            if ( res.success ) {
                sheetTested = true;
                $out.addClass( 'is-success' ).html( '✅ ' + ( res.data.message || 'OK' ) );
                if ( res.data.sheets && res.data.sheets.length ) {
                    const $sel = $( '#ss-wizard-sheet-tab' );
                    const cur  = getSheetTabName();
                    $sel.empty();
                    res.data.sheets.forEach( function( tab ) {
                        $sel.append( $( '<option>' ).val( tab ).text( tab ) );
                    } );
                    if ( res.data.sheets.indexOf( cur ) >= 0 ) {
                        $sel.val( cur );
                    } else if ( res.data.sheet_tab_found === false && res.data.sheet_tab_warning ) {
                        $out.append( '<br><span class="description">' + res.data.sheet_tab_warning + '</span>' );
                    } else {
                        $sel.val( res.data.sheets[0] );
                    }
                    setSheetTabName( $sel.val() );
                }
                saveState( { spreadsheet_id: spreadsheetId, sheet_name: getSheetTabName() } );
            } else {
                sheetTested = false;
                let html = '✗ ' + ( ( res.data && res.data.message ) || 'Failed' );
                const email = ( res.data && res.data.share_email ) || W.account_email || '';
                if ( email ) {
                    html += '<br><code>' + email + '</code> <button type="button" class="button ss-copy-email-btn" data-email="' + email + '">' + ( W.i18n.copy_email || 'Copy' ) + '</button>';
                }
                $out.addClass( 'is-error' ).html( html );
            }
        } )
        .fail( function() {
            sheetTested = false;
            $out.addClass( 'is-error' ).text( '✗ Failed' );
        } )
        .always( function() {
            $btn.prop( 'disabled', false );
        } );
    } );

    // Step 4: Create connection
    $( document ).on( 'click', '#ss-wizard-create-connection', function() {
        const $btn = $( this );
        const $out = $( '#ss-wizard-conn-result' );
        $btn.prop( 'disabled', true );
        $out.text( W.i18n.creating || 'Creating…' );

        $.post( W.ajax_url, {
            action          : 'sheetsync_wizard_create_connection',
            nonce           : W.nonce,
            connection_name : $( '#ss-wizard-conn-name' ).val(),
            spreadsheet_id  : $( '#ss-wizard-sheet-id' ).val() || ssExtractSpreadsheetId( $( '#ss-wizard-sheet-url' ).val() ),
            sheet_name      : getSheetTabName(),
            sync_direction  : getSelectedDirection(),
        } )
        .done( function( res ) {
            if ( res.success ) {
                connectionId = parseInt( res.data.connection_id, 10 ) || 0;
                $( '#ss-wizard-bootstrap, #ss-wizard-first-sync' ).attr( 'data-connection-id', connectionId );
                $out.addClass( 'is-success' ).text( '✅ ' + ( res.data.message || 'Created' ) );
                showStep( 5 );
            } else {
                $out.addClass( 'is-error' ).text( '✗ ' + ( ( res.data && res.data.message ) || 'Failed' ) );
            }
        } )
        .fail( function() {
            $out.addClass( 'is-error' ).text( '✗ Failed' );
        } )
        .always( function() {
            $btn.prop( 'disabled', false );
        } );
    } );

    // Step 5: Bootstrap
    $( document ).on( 'click', '#ss-wizard-bootstrap', function() {
        const connId = $( this ).data( 'connection-id' ) || connectionId;
        const $out = $( '#ss-wizard-bootstrap-output' );
        $out.html( '<p>' + ( W.i18n.bootstrap_title || 'Setting up…' ) + '</p><ul class="ss-wizard-bootstrap-steps"></ul>' );
        const $list = $out.find( 'ul' );

        $.post( W.ajax_url, {
            action        : 'sheetsync_bootstrap_connection',
            nonce         : W.nonce,
            connection_id : connId,
        } )
        .done( function( res ) {
            const steps = ( res.data && res.data.steps ) || [];
            $list.empty();
            steps.forEach( function( step, i ) {
                const icon = step.status === 'ok' ? '✅' : ( step.status === 'warn' ? '⚠️' : '❌' );
                $list.append( $( '<li>' ).text( icon + ' ' + ( i + 1 ) + '/4 ' + ( step.label || '' ) + ' — ' + ( step.message || '' ) ) );
            } );
            if ( res.success ) {
                showStep( 6 );
            }
        } );
    } );

    $( document ).on( 'click', '#ss-wizard-skip-sheet-setup', function() {
        saveState( { sheet_mode: 'existing' } );
        showStep( 6 );
    } );

    function handleFirstSyncResponse( res, $out ) {
        const d = ( res && res.data ) ? res.data : {};
        if ( res.success && ( d.async || d.show_progress || d.job_id ) ) {
            let html = '<p class="is-success">✅ ' + ( W.i18n.sync_started || 'Sync started.' ) + '</p>';
            if ( d.message ) {
                html += '<p class="description">' + $( '<span>' ).text( d.message ).html() + '</p>';
            }
            html += '<p class="description">' + ( W.i18n.sync_background_hint || 'You can continue setup. Open your connection Sync tab to watch progress.' ) + '</p>';
            $out.html( html );
            showStep( 7 );
            return;
        }
        if ( res.success ) {
            $out.html( '<p class="is-success">✅ ' + ( W.i18n.sync_complete || 'Sync complete' ) + '</p>' );
            showStep( 7 );
            return;
        }
        $out.html( '<p class="is-error">✗ ' + ( d.message || W.i18n.sync_error || 'Failed' ) + '</p>' );
    }

    // Step 6: First sync (intent-driven — smart strategy + job direction from button data)
    $( document ).on( 'click', '#ss-wizard-first-sync', function() {
        const $btn   = $( this );
        const connId = $btn.data( 'connection-id' ) || connectionId;
        const $out   = $( '#ss-wizard-sync-output' );
        $out.html( '<p>' + ( W.i18n.syncing || 'Syncing…' ) + '</p>' );

        $.post( W.ajax_url, {
            action             : 'sheetsync_manual_sync',
            nonce              : W.nonce,
            connection_id      : connId,
            intent             : $btn.data( 'sync-intent' ) || '',
            sync_strategy      : $btn.data( 'sync-strategy' ) || 'smart',
            sync_job_direction : $btn.data( 'sync-job-direction' ) || '',
        } )
        .done( function( res ) {
            handleFirstSyncResponse( res, $out );
        } )
        .fail( function() {
            $out.html( '<p class="is-error">✗ Failed</p>' );
        } );
    } );

    $( document ).on( 'click', '#ss-wizard-skip-first-sync', function() {
        showStep( 7 );
    } );

    // Step 7: Finish
    $( document ).on( 'change', '#ss-wizard-enable-realtime', function() {
        $( '#ss-wizard-realtime-hint' ).toggle( $( this ).is( ':checked' ) );
    } );

    $( document ).on( 'click', '#ss-wizard-finish', function() {
        const $btn = $( this );
        $btn.prop( 'disabled', true );
        $.post( W.ajax_url, {
            action              : 'sheetsync_wizard_finish',
            nonce               : W.nonce,
            connection_id       : connectionId,
            enable_realtime     : $( '#ss-wizard-enable-realtime' ).is( ':checked' ) ? 1 : 0,
        } )
        .done( function( res ) {
            if ( res.success && res.data.redirect ) {
                if ( res.data.cron_curl ) {
                    window.alert(
                        ( W.i18n.cron_enabled_hint || 'Background Cron enabled. Add this to your server cron:' ) +
                        '\n\n' + res.data.cron_curl
                    );
                }
                window.location.href = res.data.redirect;
            } else {
                window.location.href = W.connections_url;
            }
        } )
        .fail( function() {
            window.location.href = W.connections_url;
        } );
    } );

    // Skip / resume
    $( document ).on( 'click', '#ss-wizard-skip-all', function() {
        if ( ! confirm( W.i18n.skip_confirm || 'Skip the setup wizard?' ) ) {
            return;
        }
        $.post( W.ajax_url, { action: 'sheetsync_wizard_skip', nonce: W.nonce } )
            .always( function() {
                window.location.href = W.connections_url;
            } );
    } );

    // Navigation
    $( document ).on( 'click', '#ss-wizard-next', function() {
        if ( ! validateBeforeNext() ) {
            return;
        }
        saveState( collectStepData() );
        if ( currentStep === 4 && ! connectionId ) {
            $( '#ss-wizard-create-connection' ).trigger( 'click' );
            return;
        }
        showStep( currentStep + 1 );
    } );

    $( document ).on( 'click', '#ss-wizard-prev', function() {
        showStep( currentStep - 1 );
    } );

    // Init sheet ID from URL on load
    if ( $( '#ss-wizard-sheet-url' ).val() ) {
        const id = ssExtractSpreadsheetId( $( '#ss-wizard-sheet-url' ).val() );
        if ( id ) {
            $( '#ss-wizard-sheet-id' ).val( id );
        }
    } else if ( W.spreadsheet_id ) {
        $( '#ss-wizard-sheet-id' ).val( W.spreadsheet_id );
    }

    if ( W.spreadsheet_id && W.sheet_name ) {
        setSheetTabName( W.sheet_name );
        sheetTested = true;
    }

    // Create new spreadsheet (Drive API)
    $( document ).on( 'click', '#ss-wizard-create-sheet', function() {
        const $btn = $( this );
        const $out = $( '#ss-wizard-create-sheet-result' );
        $btn.prop( 'disabled', true );
        $out.text( W.i18n.creating_sheet || 'Creating…' );

        $.post( W.ajax_url, {
            action : 'sheetsync_create_spreadsheet',
            nonce  : W.nonce,
            title  : W.i18n.default_sheet_title || 'SheetSync Products',
        } )
        .done( function( res ) {
            if ( res.success && res.data.spreadsheet_id ) {
                const url = res.data.url || ( 'https://docs.google.com/spreadsheets/d/' + res.data.spreadsheet_id + '/edit' );
                $( '#ss-wizard-sheet-url' ).val( url );
                $( '#ss-wizard-sheet-id' ).val( res.data.spreadsheet_id );
                sheetTested = false;
                $out.addClass( 'is-success' ).text( '✅ ' + ( res.data.message || 'Created' ) );
                saveState( { spreadsheet_id: res.data.spreadsheet_id } );
            } else {
                $out.addClass( 'is-error' ).text( '✗ ' + ( ( res.data && res.data.message ) || 'Failed' ) );
            }
        } )
        .fail( function() {
            $out.addClass( 'is-error' ).text( '✗ Failed' );
        } )
        .always( function() {
            $btn.prop( 'disabled', false );
        } );
    } );

    $( document ).on( 'click', '#ss-wizard-add-another', function() {
        $.post( W.ajax_url, { action: 'sheetsync_wizard_add_another', nonce: W.nonce } )
            .done( function( res ) {
                if ( res.success && res.data.redirect ) {
                    window.location.href = res.data.redirect;
                }
            } );
    } );

    // Keyboard: arrow keys on stepper, Escape closes tooltips
    $( document ).on( 'keydown', '.ss-wizard-step-pill:not(:disabled)', function( e ) {
        if ( e.key === 'Enter' || e.key === ' ' ) {
            const step = parseInt( $( this ).data( 'step' ), 10 );
            if ( step && step <= currentStep ) {
                showStep( step );
            }
        }
    } );

    $( document ).on( 'keydown', '.sheetsync-wizard-wrap', function( e ) {
        if ( e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' ) {
            return;
        }
        if ( e.key === 'ArrowRight' && currentStep < 7 ) {
            $( '#ss-wizard-next' ).trigger( 'click' );
        } else if ( e.key === 'ArrowLeft' && currentStep > 1 ) {
            $( '#ss-wizard-prev' ).trigger( 'click' );
        }
    } );

    showStep( currentStep );

}( jQuery ) );
