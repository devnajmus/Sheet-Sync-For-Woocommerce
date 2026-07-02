<?php
/**
 * Google Sheet readiness — merchant-friendly connection health.
 *
 * Expects: $sheetsync_sheet_ready (array from sheetsync_verify_sheet_tab)
 */
defined( 'ABSPATH' ) || exit;

$sheetsync_ready = is_array( $sheetsync_sheet_ready ?? null ) ? $sheetsync_sheet_ready : array();
$sheetsync_ready_ok = ! empty( $sheetsync_ready['ok'] );
$sheetsync_ready_msg = (string) ( $sheetsync_ready['message'] ?? '' );
$sheetsync_ready_fix = (string) ( $sheetsync_ready['fix_url'] ?? '' );
$sheetsync_ready_tabs = is_array( $sheetsync_ready['tabs'] ?? null ) ? $sheetsync_ready['tabs'] : array();

if ( $sheetsync_ready_msg === '' ) {
	return;
}
?>
<div class="sheetsync-card ss-sheet-readiness-card ss-sheet-readiness-<?php echo $sheetsync_ready_ok ? 'ok' : 'warn'; ?>" id="ss-sheet-readiness-card">
	<h2 class="ss-sheet-readiness-title">
		<span class="dashicons dashicons-<?php echo $sheetsync_ready_ok ? 'yes-alt' : 'warning'; ?>" aria-hidden="true"></span>
		<?php esc_html_e( 'Google Sheet status', 'sheetsync-for-woocommerce' ); ?>
	</h2>
	<p class="ss-sheet-readiness-message"><?php echo esc_html( $sheetsync_ready_msg ); ?></p>
	<?php if ( ! $sheetsync_ready_ok && ! empty( $sheetsync_ready_tabs ) ) : ?>
	<p class="description ss-sheet-readiness-tabs">
		<strong><?php esc_html_e( 'Tabs in this workbook:', 'sheetsync-for-woocommerce' ); ?></strong>
		<?php echo esc_html( implode( ', ', array_slice( $sheetsync_ready_tabs, 0, 12 ) ) ); ?>
	</p>
	<?php endif; ?>
	<?php if ( ! $sheetsync_ready_ok && $sheetsync_ready_fix !== '' ) : ?>
	<p class="ss-sheet-readiness-actions">
		<a class="button button-primary" href="<?php echo esc_url( $sheetsync_ready_fix ); ?>">
			<?php esc_html_e( 'Fix connection', 'sheetsync-for-woocommerce' ); ?>
		</a>
		<?php if ( function_exists( 'sheetsync_verify_sheet_tab' ) && ! empty( $sheetsync_conn_id ) ) : ?>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync-match-diagnostics&connection_id=' . (int) $sheetsync_conn_id ) ); ?>">
			<?php esc_html_e( 'Match diagnostics', 'sheetsync-for-woocommerce' ); ?>
		</a>
		<?php endif; ?>
	</p>
	<?php elseif ( $sheetsync_ready_ok && ! empty( $sheetsync_ready['spreadsheet_title'] ) ) : ?>
	<p class="description ss-sheet-readiness-meta">
		<?php
		printf(
			/* translators: %s: workbook title */
			esc_html__( 'Workbook: %s', 'sheetsync-for-woocommerce' ),
			esc_html( (string) $sheetsync_ready['spreadsheet_title'] )
		);
		?>
	</p>
	<?php endif; ?>
</div>
