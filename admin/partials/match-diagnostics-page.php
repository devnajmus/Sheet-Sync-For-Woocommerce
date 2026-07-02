<?php
/**
 * Match Diagnostics — product ↔ sheet matching health.
 */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'sheetsync_render_match_findings_list' ) ) {
	/**
	 * @param array<int, array<string, mixed>> $findings
	 */
	function sheetsync_render_match_findings_list( array $findings ): void {
		if ( empty( $findings ) ) {
			echo '<p class="description">' . esc_html__( 'No issues found.', 'sheetsync-for-woocommerce' ) . '</p>';
			return;
		}
		echo '<ul class="ss-match-diag-findings-list">';
		foreach ( $findings as $finding ) {
			$severity = (string) ( $finding['severity'] ?? 'info' );
			$icon     = 'error' === $severity ? '❌' : ( 'warn' === $severity ? '⚠️' : ( 'ok' === $severity ? '✅' : 'ℹ️' ) );
			$row      = (int) ( $finding['row'] ?? 0 );
			echo '<li class="ss-match-diag-finding ss-match-diag-' . esc_attr( $severity ) . '">';
			echo esc_html( $icon ) . ' ';
			echo '<span class="ss-match-diag-cat">[' . esc_html( ucfirst( (string) ( $finding['category'] ?? '' ) ) ) . ']</span> ';
			echo esc_html( (string) ( $finding['message'] ?? '' ) );
			if ( $row > 0 ) {
				printf( ' <em>(%s %d)</em>', esc_html__( 'Row', 'sheetsync-for-woocommerce' ), $row );
			}
			if ( ! empty( $finding['action_url'] ) && ! empty( $finding['action_label'] ) ) {
				echo ' <a href="' . esc_url( (string) $finding['action_url'] ) . '">' . esc_html( (string) $finding['action_label'] ) . '</a>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}
}

global $wpdb;

$sheetsync_diag_conn_id = absint( wp_unslash( $_GET['connection_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$sheetsync_connections  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	"SELECT id, name, connection_type, sync_direction FROM {$wpdb->prefix}sheetsync_connections WHERE status = 'active' ORDER BY name ASC"
);

if ( ! $sheetsync_diag_conn_id && ! empty( $sheetsync_connections ) ) {
	$sheetsync_diag_conn_id = (int) $sheetsync_connections[0]->id;
}

$sheetsync_diag_report = ( $sheetsync_diag_conn_id > 0 && class_exists( 'SheetSync_Match_Diagnostics', false ) )
	? SheetSync_Match_Diagnostics::run( $sheetsync_diag_conn_id, false )
	: array();

$sheetsync_diag_score = (string) ( $sheetsync_diag_report['score'] ?? 'healthy' );
$sheetsync_diag_links = is_array( $sheetsync_diag_report['links'] ?? null ) ? $sheetsync_diag_report['links'] : array();
$sheetsync_diag_identity = is_array( $sheetsync_diag_report['identity'] ?? null ) ? $sheetsync_diag_report['identity'] : array();
?>
<div class="sheetsync-wrap sheetsync-match-diagnostics-wrap">
    <h1>
        <span class="dashicons dashicons-admin-links" style="color:var(--ss-green);"></span>
        <?php esc_html_e( 'Match Diagnostics', 'sheetsync-for-woocommerce' ); ?>
    </h1>
    <p class="description">
        <?php esc_html_e( 'See how sheet rows link to WooCommerce products, whether identity columns are set up correctly, and what to fix before syncing.', 'sheetsync-for-woocommerce' ); ?>
    </p>

    <div class="sheetsync-card ss-match-diag-toolbar">
        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ss-match-diag-select-form">
            <input type="hidden" name="page" value="sheetsync-match-diagnostics">
            <label for="ss-match-diag-connection"><?php esc_html_e( 'Connection', 'sheetsync-for-woocommerce' ); ?></label>
            <select name="connection_id" id="ss-match-diag-connection" onchange="this.form.submit()">
                <?php foreach ( (array) $sheetsync_connections as $sheetsync_diag_conn ) : ?>
                    <option value="<?php echo esc_attr( (string) $sheetsync_diag_conn->id ); ?>" <?php selected( $sheetsync_diag_conn_id, (int) $sheetsync_diag_conn->id ); ?>>
                        <?php echo esc_html( (string) $sheetsync_diag_conn->name ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ( $sheetsync_diag_conn_id > 0 ) : ?>
            <button type="button" class="button button-primary ss-run-match-diagnostics-btn"
                    data-connection-id="<?php echo esc_attr( (string) $sheetsync_diag_conn_id ); ?>"
                    data-scan-sheet="0">
                <?php esc_html_e( 'Refresh diagnostics', 'sheetsync-for-woocommerce' ); ?>
            </button>
            <button type="button" class="button ss-run-match-diagnostics-btn"
                    data-connection-id="<?php echo esc_attr( (string) $sheetsync_diag_conn_id ); ?>"
                    data-scan-sheet="1">
                <?php esc_html_e( 'Include sheet scan', 'sheetsync-for-woocommerce' ); ?>
            </button>
            <span class="ss-match-diag-status description" aria-live="polite"></span>
        <?php endif; ?>
    </div>

    <?php if ( empty( $sheetsync_connections ) ) : ?>
        <div class="sheetsync-card">
            <p><?php esc_html_e( 'Create a connection first, then return here to review matching health.', 'sheetsync-for-woocommerce' ); ?></p>
            <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync-setup' ) ); ?>"><?php esc_html_e( 'Setup Wizard', 'sheetsync-for-woocommerce' ); ?></a>
        </div>
    <?php else : ?>
        <div class="ss-match-diag-score-banner ss-match-diag-score-<?php echo esc_attr( $sheetsync_diag_score ); ?>" id="ss-match-diag-score-banner">
            <?php
            if ( 'healthy' === $sheetsync_diag_score ) {
                esc_html_e( 'Matching looks healthy for this connection.', 'sheetsync-for-woocommerce' );
            } elseif ( 'attention' === $sheetsync_diag_score ) {
                esc_html_e( 'Some matching issues need attention before large syncs.', 'sheetsync-for-woocommerce' );
            } else {
                esc_html_e( 'Critical matching issues — fix identity mapping before syncing.', 'sheetsync-for-woocommerce' );
            }
            ?>
        </div>

        <div class="ss-match-diag-stats-grid" id="ss-match-diag-stats">
            <div class="sheetsync-card ss-match-diag-stat">
                <span class="ss-match-diag-stat-value"><?php echo esc_html( (string) (int) ( $sheetsync_diag_links['mapped_rows'] ?? 0 ) ); ?></span>
                <span class="ss-match-diag-stat-label"><?php esc_html_e( 'Linked rows', 'sheetsync-for-woocommerce' ); ?></span>
            </div>
            <div class="sheetsync-card ss-match-diag-stat">
                <span class="ss-match-diag-stat-value"><?php echo esc_html( (string) (int) ( $sheetsync_diag_links['orphaned_maps'] ?? 0 ) ); ?></span>
                <span class="ss-match-diag-stat-label"><?php esc_html_e( 'Stale links', 'sheetsync-for-woocommerce' ); ?></span>
            </div>
            <div class="sheetsync-card ss-match-diag-stat">
                <span class="ss-match-diag-stat-value"><?php echo esc_html( (string) (int) ( $sheetsync_diag_links['open_conflicts'] ?? 0 ) ); ?></span>
                <span class="ss-match-diag-stat-label"><?php esc_html_e( 'Conflicts', 'sheetsync-for-woocommerce' ); ?></span>
            </div>
            <div class="sheetsync-card ss-match-diag-stat">
                <span class="ss-match-diag-stat-value"><?php echo esc_html( (string) (int) ( $sheetsync_diag_links['unlinked_wc_estimate'] ?? 0 ) ); ?></span>
                <span class="ss-match-diag-stat-label"><?php esc_html_e( 'Unlinked WC (est.)', 'sheetsync-for-woocommerce' ); ?></span>
            </div>
        </div>

        <div class="sheetsync-card ss-match-diag-identity-card">
            <h2><?php esc_html_e( 'Identity contract', 'sheetsync-for-woocommerce' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Each synced row should include stable IDs so SheetSync can match reliably in both directions.', 'sheetsync-for-woocommerce' ); ?></p>
            <table class="widefat striped ss-match-diag-identity-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Column', 'sheetsync-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Purpose', 'sheetsync-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Mapped', 'sheetsync-for-woocommerce' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>SKU</code></td>
                        <td><?php esc_html_e( 'Primary match key', 'sheetsync-for-woocommerce' ); ?></td>
                        <td><?php echo esc_html( (string) ( $sheetsync_diag_identity['sku_column'] ?? '—' ) ); ?></td>
                    </tr>
                    <tr>
                        <td><code>product_id</code></td>
                        <td><?php esc_html_e( 'WooCommerce ID (written on export)', 'sheetsync-for-woocommerce' ); ?></td>
                        <td><?php echo esc_html( (string) ( $sheetsync_diag_identity['product_id_column'] ?? '—' ) ); ?></td>
                    </tr>
                </tbody>
            </table>
            <p class="description" style="margin-top:12px;">
                <strong><?php esc_html_e( 'Match order:', 'sheetsync-for-woocommerce' ); ?></strong>
                <?php
                echo esc_html( implode( ' → ', (array) ( $sheetsync_diag_identity['resolver_order'] ?? array() ) ) );
                ?>
            </p>
            <?php if ( ! empty( $sheetsync_diag_report['mapping_url'] ) ) : ?>
                <p><a class="button" href="<?php echo esc_url( (string) $sheetsync_diag_report['mapping_url'] ); ?>"><?php esc_html_e( 'Edit field mapping', 'sheetsync-for-woocommerce' ); ?></a></p>
            <?php endif; ?>
        </div>

        <div class="sheetsync-card" id="ss-match-diag-findings-card">
            <h2><?php esc_html_e( 'Findings', 'sheetsync-for-woocommerce' ); ?></h2>
            <div id="ss-match-diag-findings">
                <?php sheetsync_render_match_findings_list( (array) ( $sheetsync_diag_report['findings'] ?? array() ) ); ?>
            </div>
        </div>

        <?php if ( ! empty( $sheetsync_diag_links['orphaned_samples'] ) ) : ?>
        <div class="sheetsync-card">
            <h2><?php esc_html_e( 'Stale link samples', 'sheetsync-for-woocommerce' ); ?></h2>
            <ul class="ss-match-diag-orphan-list">
                <?php foreach ( (array) $sheetsync_diag_links['orphaned_samples'] as $sheetsync_orphan ) : ?>
                    <li>
                        <?php
                        printf(
                            /* translators: %d: product ID */
                            esc_html__( 'Product #%d deleted from WooCommerce', 'sheetsync-for-woocommerce' ),
                            (int) ( $sheetsync_orphan['product_id'] ?? 0 )
                        );
                        ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
