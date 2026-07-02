<?php
defined( 'ABSPATH' ) || exit;

$sheetsync_conn_count   = count( $connections );
$sheetsync_can_add_more = true;
?>

<div class="sheetsync-wrap">

    <?php require __DIR__ . '/header.php'; ?>

    <?php
    if ( isset( $percent ) && (int) $percent < 100 ) :
        ?>
    <div class="sheetsync-card ss-setup-progress-card">
        <div class="ss-setup-progress-header">
            <h2 class="ss-card-title-flush"><?php esc_html_e( 'Setup progress', 'sheetsync-for-woocommerce' ); ?></h2>
            <span class="ss-setup-progress-pct"><?php echo esc_html( (string) (int) $percent ); ?>%</span>
        </div>
        <div class="ss-setup-progress-bar" role="progressbar"
             aria-valuenow="<?php echo esc_attr( (string) (int) $percent ); ?>"
             aria-valuemin="0" aria-valuemax="100">
            <div class="ss-setup-progress-fill" style="width:<?php echo esc_attr( (string) (int) $percent ); ?>%;"></div>
        </div>
        <?php if ( ! empty( $steps ) && ! empty( $progress ) ) : ?>
        <ul class="ss-setup-steps-list">
            <?php foreach ( $steps as $sheetsync_step ) :
                $sheetsync_step_done = ! empty( $progress[ $sheetsync_step['key'] ] );
                ?>
                <li class="ss-setup-step <?php echo $sheetsync_step_done ? 'is-done' : 'is-pending'; ?><?php echo ! empty( $sheetsync_step['optional'] ) ? ' is-optional' : ''; ?>">
                    <span class="ss-setup-step-icon" aria-hidden="true"><?php echo $sheetsync_step_done ? '✓' : '○'; ?></span>
                    <?php echo esc_html( $sheetsync_step['label'] ); ?>
                    <?php if ( ! empty( $sheetsync_step['optional'] ) ) : ?>
                        <em class="ss-setup-optional-tag"><?php esc_html_e( 'optional', 'sheetsync-for-woocommerce' ); ?></em>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if ( ! empty( $next['url'] ) ) : ?>
            <p class="ss-setup-cta-wrap">
                <a href="<?php echo esc_url( $next['url'] ); ?>" class="button button-primary">
                    <?php
                    printf(
                        /* translators: %s: next setup step label */
                        esc_html__( 'Continue setup → %s', 'sheetsync-for-woocommerce' ),
                        esc_html( $next['label'] ?? '' )
                    );
                    ?>
                </a>
            </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php
    if ( function_exists( 'sheetsync_setup_health_all_ok' ) && ! sheetsync_setup_health_all_ok() ) :
        ?>
    <div class="sheetsync-card ss-health-lights-card">
        <?php
        $sheetsync_lights_title   = __( 'Setup health', 'sheetsync-for-woocommerce' );
        $sheetsync_lights_compact = true;
        require __DIR__ . '/fragments/setup-health-lights.php';
        ?>
    </div>
    <?php endif; ?>

    <?php
    $sheetsync_health = function_exists( 'sheetsync_get_setup_health_summary' )
        ? sheetsync_get_setup_health_summary()
        : array();
    if ( ( $sheetsync_health['connections'] ?? 0 ) >= 2 ) :
        ?>
    <div class="sheetsync-card ss-agency-health-wrap">
        <h2 class="ss-card-title-flush"><?php esc_html_e( 'Setup health', 'sheetsync-for-woocommerce' ); ?></h2>
        <div class="ss-agency-health-card">
            <div class="ss-agency-health-stat">
                <strong><?php echo esc_html( (string) (int) ( $sheetsync_health['connections'] ?? 0 ) ); ?></strong>
                <?php esc_html_e( 'Connections', 'sheetsync-for-woocommerce' ); ?>
            </div>
            <div class="ss-agency-health-stat">
                <strong><?php echo esc_html( (string) (int) ( $sheetsync_health['setup_percent'] ?? 0 ) ); ?>%</strong>
                <?php esc_html_e( 'Setup complete', 'sheetsync-for-woocommerce' ); ?>
            </div>
            <div class="ss-agency-health-stat">
                <strong><?php echo ! empty( $sheetsync_health['google_connected'] ) ? '✓' : '—'; ?></strong>
                <?php esc_html_e( 'Google', 'sheetsync-for-woocommerce' ); ?>
            </div>
            <div class="ss-agency-health-stat">
                <strong><?php echo ! empty( $sheetsync_health['scheduler_ok'] ) ? '✓' : '⚠'; ?></strong>
                <?php esc_html_e( 'Scheduler', 'sheetsync-for-woocommerce' ); ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="sheetsync-card">
        <div class="ss-card-header-row">
            <h2 class="ss-card-title-inline"><?php esc_html_e( 'Sheet Connections', 'sheetsync-for-woocommerce' ); ?></h2>

            <?php if ( $sheetsync_can_add_more ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync&sheetsync_action=new' ) ); ?>"
                   class="button button-primary">
                    + <?php esc_html_e( 'Add Connection', 'sheetsync-for-woocommerce' ); ?>
                </a>
            <?php else : ?>
                
            <?php endif; ?>
        </div>

        
    </div>

    <?php
    $sheetsync_as_health = function_exists( 'sheetsync_get_action_scheduler_health' )
        ? sheetsync_get_action_scheduler_health()
        : array( 'ok' => true );
    $sheetsync_gate_threshold = function_exists( 'sheetsync_large_sync_gate_threshold' )
        ? sheetsync_large_sync_gate_threshold()
        : 200;
    if ( ! ( $sheetsync_as_health['ok'] ?? true ) ) : ?>
    <div class="notice notice-warning inline sheetsync-card ss-notice-warning ss-large-catalog-site-hint">
        <p>
            <span class="dashicons dashicons-warning"></span>
            <?php echo esc_html( (string) ( $sheetsync_as_health['message'] ?? '' ) ); ?>
            <?php
            printf(
                ' %s',
                esc_html(
                    sprintf(
                        /* translators: %d: minimum row count for soft gate */
                        __( 'Catalogs with %d+ rows: fix Scheduled Actions first, or use Slow sync on the connection page and keep your browser tab open.', 'sheetsync-for-woocommerce' ),
                        (int) $sheetsync_gate_threshold
                    )
                )
            );
            ?>
            <a href="<?php echo esc_url( (string) ( $sheetsync_as_health['tools_url'] ?? admin_url( 'admin.php?page=wc-status&tab=action-scheduler' ) ) ); ?>">
                <?php esc_html_e( 'Fix scheduled actions', 'sheetsync-for-woocommerce' ); ?>
            </a>
            ·
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync-settings#tab-settings-cron' ) ); ?>">
                <?php esc_html_e( 'Background Cron', 'sheetsync-for-woocommerce' ); ?>
            </a>
        </p>
    </div>
    <?php endif; ?>

    <?php
    $sheetsync_wizard_done = function_exists( 'sheetsync_wizard_is_complete' ) && sheetsync_wizard_is_complete();
    if ( ! $sheetsync_wizard_done ) :
        ?>
    <div class="sheetsync-card ss-welcome-wizard ss-accent-card">
        <h2 class="ss-card-title-flush"><?php esc_html_e( 'New to SheetSync?', 'sheetsync-for-woocommerce' ); ?></h2>
        <p><?php esc_html_e( 'Follow our guided setup wizard to connect Google, share your sheet, and run your first sync in about 5–10 minutes.', 'sheetsync-for-woocommerce' ); ?></p>
        <p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync-setup' ) ); ?>" class="button button-primary button-hero">
                <?php esc_html_e( 'Start Setup Wizard', 'sheetsync-for-woocommerce' ); ?> →
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync&sheetsync_action=new' ) ); ?>" class="button ss-btn-spaced">
                <?php esc_html_e( 'Manual setup', 'sheetsync-for-woocommerce' ); ?>
            </a>
        </p>
    </div>
    <?php else : ?>
    <div class="sheetsync-card ss-welcome-wizard ss-accent-card">
        <h2 class="ss-card-title-flush"><?php esc_html_e( 'How SheetSync works', 'sheetsync-for-woocommerce' ); ?></h2>
        <div class="ss-workflow-grid">
            <div>
                <h3><?php esc_html_e( 'Sheet → WooCommerce', 'sheetsync-for-woocommerce' ); ?></h3>
                <ol class="ss-workflow-steps">
                    <li><?php esc_html_e( 'Set direction: Sheet → WooCommerce', 'sheetsync-for-woocommerce' ); ?></li>
                    <li><?php esc_html_e( 'Set up sheet template → Check sheet → Sync now', 'sheetsync-for-woocommerce' ); ?></li>
                    <li><?php esc_html_e( 'Daily: Apply changes from Google Sheet', 'sheetsync-for-woocommerce' ); ?></li>
                </ol>
            </div>
            <div>
                <h3><?php esc_html_e( 'WooCommerce → Sheet', 'sheetsync-for-woocommerce' ); ?></h3>
                <ol class="ss-workflow-steps">
                    <li><?php esc_html_e( 'Set direction: WooCommerce → Sheet', 'sheetsync-for-woocommerce' ); ?></li>
                    <li><?php esc_html_e( 'Field mapping → Sync now (first run publishes catalog)', 'sheetsync-for-woocommerce' ); ?></li>
                    <li><?php esc_html_e( 'Large stores: watch the progress bar on the Sync tab', 'sheetsync-for-woocommerce' ); ?></li>
                </ol>
            </div>
            <div>
                <h3><?php esc_html_e( 'Both ways', 'sheetsync-for-woocommerce' ); ?></h3>
                <ol class="ss-workflow-steps">
                    <li><?php esc_html_e( 'First: Publish catalog to sheet (links each row)', 'sheetsync-for-woocommerce' ); ?></li>
                    <li><?php esc_html_e( 'Edit sheet or store → Sync now', 'sheetsync-for-woocommerce' ); ?></li>
                    <li><?php esc_html_e( 'Optional: turn on Automatic sync on the Sync tab', 'sheetsync-for-woocommerce' ); ?></li>
                </ol>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ( empty( $connections ) ) : ?>
        <div class="sheetsync-card ss-empty-state">
            <span class="dashicons dashicons-table-col-after"></span>
            <h3><?php esc_html_e( 'No connections yet', 'sheetsync-for-woocommerce' ); ?></h3>
            <p><?php esc_html_e( 'Connect a Google Sheet to start syncing your WooCommerce products.', 'sheetsync-for-woocommerce' ); ?></p>
            <?php if ( ! $sheetsync_wizard_done ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync-setup' ) ); ?>"
               class="button button-primary button-hero">
                <?php esc_html_e( 'Start Setup Wizard', 'sheetsync-for-woocommerce' ); ?>
            </a>
            <?php else : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync&sheetsync_action=new' ) ); ?>"
               class="button button-primary button-hero">
                <?php esc_html_e( 'Add Your First Connection', 'sheetsync-for-woocommerce' ); ?>
            </a>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <div class="sheetsync-connections-grid">
            <?php foreach ( $connections as $sheetsync_conn ) :
                $sheetsync_list_is_orders = SheetSync_Sync_Engine::is_orders_type( $sheetsync_conn->connection_type ?? 'products' );
                $sheetsync_list_mapped    = ( ! $sheetsync_list_is_orders && class_exists( 'SheetSync_Product_Map_Repository', false ) )
                    ? ( new SheetSync_Product_Map_Repository() )->count_for_connection( (int) $sheetsync_conn->id )
                    : 0;
                $sheetsync_sync_tab_url   = admin_url(
                    'admin.php?page=sheetsync&sheetsync_action=edit&connection_id=' . (int) $sheetsync_conn->id
                ) . '#tab-sync';
                ?>
                <div class="sheetsync-connection-card">
                    <p class="ss-conn-name">
                        <?php echo esc_html( $sheetsync_conn->name ?: __( '(Unnamed Connection)', 'sheetsync-for-woocommerce' ) ); ?>
                        <span class="ss-status-badge ss-status-<?php echo esc_attr( $sheetsync_conn->status ); ?>">
                            <?php echo esc_html( $sheetsync_conn->status ); ?>
                        </span>
                    </p>
                    <div class="ss-conn-meta">
                        <strong><?php esc_html_e( 'Sheet:', 'sheetsync-for-woocommerce' ); ?></strong>
                        <?php echo esc_html( $sheetsync_conn->sheet_name ); ?><br>

                        <strong><?php esc_html_e( 'Type:', 'sheetsync-for-woocommerce' ); ?></strong>
                        <?php
                        $sheetsync_type_labels = array(
                            'products'          => __( 'Products', 'sheetsync-for-woocommerce' ),
                            'orders'            => __( 'Orders (All)', 'sheetsync-for-woocommerce' ),
                            'orders_pending'    => __( 'Orders: Pending Payment', 'sheetsync-for-woocommerce' ),
                            'orders_processing' => __( 'Orders: Processing', 'sheetsync-for-woocommerce' ),
                            'orders_on-hold'    => __( 'Orders: On Hold', 'sheetsync-for-woocommerce' ),
                            'orders_completed'  => __( 'Orders: Completed', 'sheetsync-for-woocommerce' ),
                            'orders_cancelled'  => __( 'Orders: Cancelled', 'sheetsync-for-woocommerce' ),
                            'orders_refunded'   => __( 'Orders: Refunded', 'sheetsync-for-woocommerce' ),
                            'orders_failed'     => __( 'Orders: Failed', 'sheetsync-for-woocommerce' ),
                            'orders_draft'      => __( 'Orders: Draft', 'sheetsync-for-woocommerce' ),
                        );
                        echo esc_html( $sheetsync_type_labels[ $sheetsync_conn->connection_type ] ?? ucfirst( str_replace( '_', ' ', $sheetsync_conn->connection_type ) ) );
                        ?><br>

                        <strong><?php esc_html_e( 'Direction:', 'sheetsync-for-woocommerce' ); ?></strong>
                        <?php
                        $sheetsync_directions = array(
                            'sheets_to_wc' => 'Sheets → WooCommerce',
                            'wc_to_sheets' => 'WooCommerce → Sheets',
                            'two_way'      => 'Two-Way',
                        );
                        echo esc_html( $sheetsync_directions[ $sheetsync_conn->sync_direction ] ?? $sheetsync_conn->sync_direction );
                        ?><br>

                        <strong><?php esc_html_e( 'Sync Status:', 'sheetsync-for-woocommerce' ); ?></strong>
                        <span class="ss-conn-sync-status" data-conn-id="<?php echo esc_attr( (string) $sheetsync_conn->id ); ?>">
                        <?php
                        echo wp_kses_post(
                            function_exists( 'sheetsync_connection_sync_status_html' )
                                ? sheetsync_connection_sync_status_html( (int) $sheetsync_conn->id, $sheetsync_conn->last_sync_at ?? null )
                                : ( $sheetsync_conn->last_sync_at
                                    ? esc_html( human_time_diff( strtotime( $sheetsync_conn->last_sync_at ), time() ) . ' ago' )
                                    : '<em>' . esc_html__( 'Never synced', 'sheetsync-for-woocommerce' ) . '</em>' )
                        );
                        ?>
                        </span>
                        <?php if ( ! $sheetsync_list_is_orders && $sheetsync_list_mapped > 0 ) : ?>
                        <br>
                        <strong><?php esc_html_e( 'Linked:', 'sheetsync-for-woocommerce' ); ?></strong>
                        <?php
                        printf(
                            /* translators: %s: product count */
                            esc_html__( '%s products', 'sheetsync-for-woocommerce' ),
                            esc_html( number_format_i18n( $sheetsync_list_mapped ) )
                        );
                        ?>
                        <?php endif; ?>
                        <br>
                    </div>

                    <div class="ss-conn-actions">
                        <button type="button" class="button button-primary ss-sync-btn ss-list-sync-btn"
                                data-connection-id="<?php echo esc_attr( $sheetsync_conn->id ); ?>"
                                title="<?php esc_attr_e( 'Runs sync using your saved options on the Sync tab', 'sheetsync-for-woocommerce' ); ?>">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e( 'Sync now', 'sheetsync-for-woocommerce' ); ?>
                        </button>

                        <a href="<?php echo esc_url( $sheetsync_sync_tab_url ); ?>"
                           class="button"
                           title="<?php esc_attr_e( 'Open sync options and intents', 'sheetsync-for-woocommerce' ); ?>">
                            <?php esc_html_e( 'Sync options', 'sheetsync-for-woocommerce' ); ?>
                        </a>

                        <a href="<?php echo esc_url( admin_url( "admin.php?page=sheetsync&sheetsync_action=edit&connection_id={$sheetsync_conn->id}" ) ); ?>"
                           class="button">
                            <?php esc_html_e( 'Edit', 'sheetsync-for-woocommerce' ); ?>
                        </a>

                        <a href="<?php echo esc_url( admin_url( "admin.php?page=sheetsync-logs&connection_id={$sheetsync_conn->id}" ) ); ?>"
                           class="button">
                            <?php esc_html_e( 'Logs', 'sheetsync-for-woocommerce' ); ?>
                        </a>

                        <?php if ( ! $sheetsync_list_is_orders ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync-match-diagnostics&connection_id=' . (int) $sheetsync_conn->id ) ); ?>"
                           class="button"
                           title="<?php esc_attr_e( 'Check how sheet rows link to WooCommerce products', 'sheetsync-for-woocommerce' ); ?>">
                            <?php esc_html_e( 'Match diagnostics', 'sheetsync-for-woocommerce' ); ?>
                        </a>
                        <?php endif; ?>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                              class="ss-delete-form ss-delete-form-inline">
                            <?php wp_nonce_field( 'sheetsync_delete_connection' ); ?>
                            <input type="hidden" name="action" value="sheetsync_delete_connection">
                            <input type="hidden" name="connection_id" value="<?php echo esc_attr( $sheetsync_conn->id ); ?>">
                            <button type="submit" class="button ss-btn-danger">
                                <?php esc_html_e( 'Delete', 'sheetsync-for-woocommerce' ); ?>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>