<?php
defined( 'ABSPATH' ) || exit;

$sheetsync_conflicts = function_exists( 'sheetsync_list_all_conflicts' )
    ? sheetsync_list_all_conflicts( 100 )
    : array();
$sheetsync_conflict_total = function_exists( 'sheetsync_count_all_conflicts' )
    ? sheetsync_count_all_conflicts()
    : count( $sheetsync_conflicts );
?>
<div class="sheetsync-wrap">
    <?php require __DIR__ . '/header.php'; ?>

    <div class="sheetsync-card">
        <h2 class="ss-card-title-flush">
            <?php esc_html_e( 'Sync Conflicts', 'sheetsync-for-woocommerce' ); ?>
            <?php if ( $sheetsync_conflict_total > 0 ) : ?>
                <span class="ss-status-badge ss-status-warning"><?php echo esc_html( number_format_i18n( $sheetsync_conflict_total ) ); ?></span>
            <?php endif; ?>
        </h2>
        <p class="description">
            <?php esc_html_e( 'When the same product was edited in Google Sheets and WooCommerce since the last sync, conflicts appear here for review (when your connection uses “Queue for manual review”).', 'sheetsync-for-woocommerce' ); ?>
        </p>

        <?php if ( empty( $sheetsync_conflicts ) ) : ?>
        <div class="ss-empty-state" style="padding:24px 0;">
            <p><?php esc_html_e( 'No conflicts waiting — you are all caught up.', 'sheetsync-for-woocommerce' ); ?></p>
        </div>
        <?php else : ?>
        <table class="widefat striped ss-conflicts-table ss-conflicts-inbox-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Connection', 'sheetsync-for-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Sheet row', 'sheetsync-for-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Product', 'sheetsync-for-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Changed fields', 'sheetsync-for-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'sheetsync-for-woocommerce' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $sheetsync_conflicts as $sheetsync_conflict ) :
                $sheetsync_conn_id   = (int) ( $sheetsync_conflict->connection_id ?? 0 );
                $sheetsync_conn_name = (string) ( $sheetsync_conflict->connection_name ?? '' );
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
                $sheetsync_edit_product_url = $sheetsync_conflict_pid > 0
                    ? get_edit_post_link( $sheetsync_conflict_pid, 'raw' )
                    : '';
                $sheetsync_conn_edit_url = admin_url(
                    'admin.php?page=sheetsync&sheetsync_action=edit&connection_id=' . $sheetsync_conn_id . '#tab-field-mapping'
                );
                ?>
                <tr data-map-id="<?php echo esc_attr( (int) $sheetsync_conflict->id ); ?>" data-connection-id="<?php echo esc_attr( (string) $sheetsync_conn_id ); ?>">
                    <td>
                        <a href="<?php echo esc_url( $sheetsync_conn_edit_url ); ?>"><?php echo esc_html( $sheetsync_conn_name ?: '#' . $sheetsync_conn_id ); ?></a>
                    </td>
                    <td><?php echo esc_html( (string) (int) ( $sheetsync_conflict->sheet_row ?? ( $sheetsync_conflict_data['sheet_row'] ?? 0 ) ) ); ?></td>
                    <td>
                        <?php echo esc_html( $sheetsync_conflict_label !== '' ? $sheetsync_conflict_label : '—' ); ?>
                        <?php if ( $sheetsync_edit_product_url ) : ?>
                            <br><a href="<?php echo esc_url( $sheetsync_edit_product_url ); ?>" target="_blank" rel="noopener" class="ss-conflict-edit-link"><?php esc_html_e( 'Edit in WC', 'sheetsync-for-woocommerce' ); ?></a>
                        <?php endif; ?>
                    </td>
                    <td class="ss-conflict-fields">
                        <?php
                        if ( ! empty( $sheetsync_changed_fields ) ) {
                            echo esc_html( implode( ', ', array_slice( $sheetsync_changed_fields, 0, 6 ) ) );
                        } else {
                            echo '—';
                        }
                        ?>
                    </td>
                    <td class="ss-conflict-actions">
                        <button type="button" class="button button-small ss-resolve-conflict" data-resolution="apply_sheet"
                            data-connection-id="<?php echo esc_attr( (string) $sheetsync_conn_id ); ?>"
                            data-map-id="<?php echo esc_attr( (string) (int) $sheetsync_conflict->id ); ?>">
                            <?php esc_html_e( 'Use sheet', 'sheetsync-for-woocommerce' ); ?>
                        </button>
                        <button type="button" class="button button-small ss-resolve-conflict" data-resolution="apply_wc"
                            data-connection-id="<?php echo esc_attr( (string) $sheetsync_conn_id ); ?>"
                            data-map-id="<?php echo esc_attr( (string) (int) $sheetsync_conflict->id ); ?>">
                            <?php esc_html_e( 'Use WC', 'sheetsync-for-woocommerce' ); ?>
                        </button>
                        <button type="button" class="button button-small ss-resolve-conflict" data-resolution="dismiss"
                            data-connection-id="<?php echo esc_attr( (string) $sheetsync_conn_id ); ?>"
                            data-map-id="<?php echo esc_attr( (string) (int) $sheetsync_conflict->id ); ?>">
                            <?php esc_html_e( 'Dismiss', 'sheetsync-for-woocommerce' ); ?>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
