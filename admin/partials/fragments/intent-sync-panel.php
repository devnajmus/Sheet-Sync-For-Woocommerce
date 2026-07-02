<?php
/**
 * Intent-driven sync UI — merchants choose outcomes, not engine modes.
 *
 * Expects: $sheetsync_conn_id, $connection, $sheetsync_sync_direction, $sheetsync_has_maps,
 *          $sheetsync_mapped_count, $sheetsync_is_first_export, $sheetsync_is_pro,
 *          $sheetsync_is_first_import, $sheetsync_sheet_row_total, $sheetsync_import_incomplete,
 *          $sheetsync_orphaned_maps
 */
defined( 'ABSPATH' ) || exit;

$sheetsync_direction_labels = array(
	'sheets_to_wc' => __( 'Sheet → WooCommerce', 'sheetsync-for-woocommerce' ),
	'wc_to_sheets' => __( 'WooCommerce → Sheet', 'sheetsync-for-woocommerce' ),
	'two_way'      => __( 'Both ways', 'sheetsync-for-woocommerce' ),
);

$sheetsync_intents = array();

if ( 'sheets_to_wc' === $sheetsync_sync_direction ) {
	$sheetsync_intents['import_all_sheet'] = array(
		'label'         => $sheetsync_is_first_import
			? __( 'Import all products from Google Sheet (first time)', 'sheetsync-for-woocommerce' )
			: __( 'Import all products from Google Sheet', 'sheetsync-for-woocommerce' ),
		'description'   => $sheetsync_is_first_import
			? __( 'Creates every product row in WooCommerce from your sheet. Use when the store is empty or you deleted products.', 'sheetsync-for-woocommerce' )
			: __( 'Re-processes every sheet row (Full Sync). Use after deleting products or if a previous import stopped halfway.', 'sheetsync-for-woocommerce' ),
		'strategy'      => 'full',
		'job_direction' => '',
		'pull_mode'     => 'default',
		'recommended'   => $sheetsync_is_first_import || (int) ( $sheetsync_orphaned_maps ?? 0 ) > 0,
	);
	$sheetsync_intents['apply_sheet'] = array(
		'label'         => __( 'Apply sheet changes (update + add new)', 'sheetsync-for-woocommerce' ),
		'description'   => __( 'Day-to-day sync: updates linked products and adds new sheet rows. Skips rows that did not change (Smart Diff).', 'sheetsync-for-woocommerce' ),
		'strategy'      => 'smart',
		'job_direction' => '',
		'pull_mode'     => 'default',
		'recommended'   => ! $sheetsync_is_first_import && empty( $sheetsync_import_incomplete ),
	);
	$sheetsync_intents['add_new_sheet'] = array(
		'label'         => __( 'Add new products only', 'sheetsync-for-woocommerce' ),
		'description'   => __( 'Creates WooCommerce products for sheet rows that are not linked yet. Does not change existing products.', 'sheetsync-for-woocommerce' ),
		'strategy'      => 'smart',
		'job_direction' => '',
		'pull_mode'     => 'create_new',
		'recommended'   => ! empty( $sheetsync_import_incomplete ),
	);
	$sheetsync_intents['update_sheet'] = array(
		'label'         => __( 'Update existing products only', 'sheetsync-for-woocommerce' ),
		'description'   => __( 'Pushes sheet edits into products already linked by SKU or Product ID. Skips new sheet rows.', 'sheetsync-for-woocommerce' ),
		'strategy'      => 'smart',
		'job_direction' => '',
		'pull_mode'     => 'update_only',
		'recommended'   => false,
	);
} elseif ( in_array( $sheetsync_sync_direction, array( 'sheets_to_wc', 'two_way' ), true ) ) {
	$sheetsync_intents['apply_sheet'] = array(
		'label'         => __( 'Apply changes from Google Sheet', 'sheetsync-for-woocommerce' ),
		'description'   => __( 'Updates existing products and adds new rows from your sheet.', 'sheetsync-for-woocommerce' ),
		'strategy'      => 'smart',
		'job_direction' => 'two_way' === $sheetsync_sync_direction ? 'pull' : '',
		'pull_mode'     => 'default',
		'recommended'   => true,
	);
}

if ( in_array( $sheetsync_sync_direction, array( 'wc_to_sheets', 'two_way' ), true ) ) {
	$sheetsync_intents['publish_wc'] = array(
		'label'         => $sheetsync_is_first_export
			? __( 'Publish WooCommerce to Google Sheet (first time)', 'sheetsync-for-woocommerce' )
			: __( 'Publish WooCommerce changes to Google Sheet', 'sheetsync-for-woocommerce' ),
		'description'   => $sheetsync_is_first_export
			? __( 'Writes your full catalog and links each row (Product ID in column B).', 'sheetsync-for-woocommerce' )
			: __( 'Pushes products you changed in WooCommerce to the sheet.', 'sheetsync-for-woocommerce' ),
		'strategy'      => 'smart',
		'job_direction' => 'two_way' === $sheetsync_sync_direction ? 'push' : '',
		'pull_mode'     => 'default',
		'recommended'   => ! empty( $sheetsync_is_first_export ),
	);
}

if ( 'two_way' === $sheetsync_sync_direction ) {
	$sheetsync_intents['both_ways'] = array(
		'label'         => __( 'Keep both sides in sync', 'sheetsync-for-woocommerce' ),
		'description'   => __( 'Applies sheet edits and WooCommerce edits in one run (recommended for daily use).', 'sheetsync-for-woocommerce' ),
		'strategy'      => 'smart',
		'job_direction' => 'two_way',
		'pull_mode'     => 'default',
		'recommended'   => ! $sheetsync_is_first_export && empty( $sheetsync_is_first_import ),
	);
}

$sheetsync_default_intent = 'apply_sheet';
if ( 'sheets_to_wc' === $sheetsync_sync_direction ) {
	if ( ! empty( $sheetsync_is_first_import ) || (int) ( $sheetsync_orphaned_maps ?? 0 ) > 0 ) {
		$sheetsync_default_intent = 'import_all_sheet';
	} elseif ( ! empty( $sheetsync_import_incomplete ) ) {
		$sheetsync_default_intent = 'add_new_sheet';
	} else {
		$sheetsync_default_intent = 'apply_sheet';
	}
} elseif ( 'wc_to_sheets' === $sheetsync_sync_direction ) {
	$sheetsync_default_intent = 'publish_wc';
} elseif ( 'two_way' === $sheetsync_sync_direction ) {
	$sheetsync_default_intent = $sheetsync_is_first_export ? 'publish_wc' : 'both_ways';
}
if ( ! isset( $sheetsync_intents[ $sheetsync_default_intent ] ) ) {
	$sheetsync_default_intent = (string) array_key_first( $sheetsync_intents );
}

$sheetsync_last_sync_html = function_exists( 'sheetsync_connection_sync_status_html' )
	? sheetsync_connection_sync_status_html( $sheetsync_conn_id, $connection->last_sync_at ?? null )
	: '';
$sheetsync_conn_name = (string) ( $connection->name ?? '' );
$sheetsync_sheet_blocked = ! empty( $sheetsync_sheet_ready['message'] ) && empty( $sheetsync_sheet_ready['ok'] );
$sheetsync_sheet_fix_url = (string) ( $sheetsync_sheet_ready['fix_url'] ?? '' );
$sheetsync_row_total     = (int) ( $sheetsync_sheet_row_total ?? 0 );
$sheetsync_mapped        = (int) ( $sheetsync_mapped_count ?? 0 );
?>
<div class="ss-intent-sync-panel" id="ss-intent-sync-panel"
	data-connection-id="<?php echo esc_attr( (string) $sheetsync_conn_id ); ?>"
	data-sheet-ready="<?php echo $sheetsync_sheet_blocked ? '0' : '1'; ?>"
	<?php if ( $sheetsync_sheet_fix_url !== '' ) : ?>
	data-sheet-fix-url="<?php echo esc_attr( $sheetsync_sheet_fix_url ); ?>"
	<?php endif; ?>>
	<?php if ( $sheetsync_conn_name !== '' ) : ?>
	<p class="ss-intent-connection-name">
		<strong><?php echo esc_html( $sheetsync_conn_name ); ?></strong>
	</p>
	<?php endif; ?>

	<div class="ss-direction-pills" role="group" aria-label="<?php esc_attr_e( 'Sync direction', 'sheetsync-for-woocommerce' ); ?>">
		<?php foreach ( $sheetsync_direction_labels as $sheetsync_dir_key => $sheetsync_dir_label ) :
			$sheetsync_dir_active = ( $sheetsync_sync_direction === $sheetsync_dir_key );
			$sheetsync_dir_locked = ! $sheetsync_is_pro && in_array( $sheetsync_dir_key, array( 'wc_to_sheets', 'two_way' ), true ) && ! $sheetsync_dir_active;
			?>
		<button type="button"
			class="ss-direction-pill<?php echo $sheetsync_dir_active ? ' is-active' : ''; ?><?php echo $sheetsync_dir_locked ? ' is-locked' : ''; ?>"
			data-direction="<?php echo esc_attr( $sheetsync_dir_key ); ?>"
			<?php echo $sheetsync_dir_active ? ' aria-current="true"' : ''; ?>>
			<?php echo esc_html( $sheetsync_dir_label ); ?>
			<?php if ( $sheetsync_dir_locked ) : ?>
				<span class="sheetsync-pro-badge">PRO</span>
			<?php endif; ?>
		</button>
		<?php endforeach; ?>
	</div>
	<p class="description ss-direction-change-hint ss-direction-save-hint" aria-live="polite">
		<?php esc_html_e( 'Click a direction to switch — saves immediately.', 'sheetsync-for-woocommerce' ); ?>
	</p>

	<?php if ( 'sheets_to_wc' === $sheetsync_sync_direction ) : ?>
	<div class="ss-intent-guide notice notice-info inline">
		<p class="ss-intent-guide-title"><strong><?php esc_html_e( 'Which option should I use?', 'sheetsync-for-woocommerce' ); ?></strong></p>
		<ul class="ss-intent-guide-list">
			<?php if ( $sheetsync_mapped < 1 ) : ?>
			<li><?php esc_html_e( 'Empty WooCommerce store → choose Import all products from sheet, then Sync now.', 'sheetsync-for-woocommerce' ); ?></li>
			<?php elseif ( $sheetsync_row_total > 0 && $sheetsync_mapped < $sheetsync_row_total ) : ?>
			<li>
				<?php
				printf(
					/* translators: 1: linked count, 2: sheet row count */
					esc_html__( 'Partial import (%1$d of ~%2$d linked) → Add new products only, or Import all to start clean.', 'sheetsync-for-woocommerce' ),
					$sheetsync_mapped,
					$sheetsync_row_total
				);
				?>
			</li>
			<?php else : ?>
			<li><?php esc_html_e( 'You edited the Google Sheet → Apply sheet changes (update + add new).', 'sheetsync-for-woocommerce' ); ?></li>
			<?php endif; ?>
			<?php if ( (int) ( $sheetsync_orphaned_maps ?? 0 ) > 0 ) : ?>
			<li>
				<?php
				printf(
					/* translators: %d: orphan map count */
					esc_html__( '%d linked rows point at deleted products — use Import all from sheet after emptying Trash.', 'sheetsync-for-woocommerce' ),
					(int) $sheetsync_orphaned_maps
				);
				?>
			</li>
			<?php endif; ?>
		</ul>
	</div>
	<?php endif; ?>

	<fieldset class="ss-intent-cards" aria-label="<?php esc_attr_e( 'What should sync do?', 'sheetsync-for-woocommerce' ); ?>">
		<legend class="screen-reader-text"><?php esc_html_e( 'Sync intent', 'sheetsync-for-woocommerce' ); ?></legend>
		<?php foreach ( $sheetsync_intents as $sheetsync_intent_key => $sheetsync_intent ) : ?>
		<label class="ss-intent-card<?php echo $sheetsync_default_intent === $sheetsync_intent_key ? ' is-selected' : ''; ?>">
			<input type="radio" name="sync_intent" value="<?php echo esc_attr( $sheetsync_intent_key ); ?>"
				<?php checked( $sheetsync_default_intent, $sheetsync_intent_key ); ?>
				data-strategy="<?php echo esc_attr( $sheetsync_intent['strategy'] ); ?>"
				data-job-direction="<?php echo esc_attr( $sheetsync_intent['job_direction'] ); ?>"
				data-pull-mode="<?php echo esc_attr( $sheetsync_intent['pull_mode'] ); ?>">
			<span class="ss-intent-card-body">
				<span class="ss-intent-card-title">
					<?php echo esc_html( $sheetsync_intent['label'] ); ?>
					<?php if ( ! empty( $sheetsync_intent['recommended'] ) ) : ?>
						<span class="ss-intent-badge"><?php esc_html_e( 'Recommended', 'sheetsync-for-woocommerce' ); ?></span>
					<?php endif; ?>
				</span>
				<span class="ss-intent-card-desc description"><?php echo esc_html( $sheetsync_intent['description'] ); ?></span>
			</span>
		</label>
		<?php endforeach; ?>
	</fieldset>

	<div class="ss-intent-status">
		<span class="ss-intent-status-icon" aria-hidden="true">✓</span>
		<span class="ss-intent-status-text">
			<?php
			if ( $sheetsync_row_total > 0 && 'sheets_to_wc' === $sheetsync_sync_direction ) {
				printf(
					/* translators: 1: last sync time, 2: linked count, 3: sheet row estimate */
					esc_html__( 'Last synced %1$s · %2$s of ~%3$s sheet rows linked', 'sheetsync-for-woocommerce' ),
					wp_kses_post( $sheetsync_last_sync_html ),
					number_format_i18n( $sheetsync_mapped ),
					number_format_i18n( $sheetsync_row_total )
				);
			} else {
				printf(
					/* translators: 1: last sync time, 2: linked product count */
					esc_html__( 'Last synced %1$s · %2$s products linked', 'sheetsync-for-woocommerce' ),
					wp_kses_post( $sheetsync_last_sync_html ),
					number_format_i18n( $sheetsync_mapped )
				);
			}
			?>
		</span>
	</div>

	<div class="ss-intent-actions">
		<button type="button" id="ss-sync-now-btn" class="button button-primary button-hero ss-sync-btn"
			data-connection-id="<?php echo esc_attr( (string) $sheetsync_conn_id ); ?>"
			<?php disabled( ! $sheetsync_has_maps || $sheetsync_sheet_blocked ); ?>>
			<span class="dashicons dashicons-update"></span>
			<?php esc_html_e( 'Sync now', 'sheetsync-for-woocommerce' ); ?>
		</button>
		<p class="description ss-intent-sync-hint">
			<?php esc_html_e( 'Keep this tab open until sync finishes. If you see a timeout, click Run Sync Again — progress continues in the background.', 'sheetsync-for-woocommerce' ); ?>
		</p>
		<?php if ( ! $sheetsync_has_maps ) : ?>
		<p class="description"><?php esc_html_e( 'Complete field mapping on the Field Mapping tab first.', 'sheetsync-for-woocommerce' ); ?></p>
		<?php elseif ( $sheetsync_sheet_blocked ) : ?>
		<p class="description ss-sheet-sync-blocked">
			<?php echo esc_html( (string) $sheetsync_sheet_ready['message'] ); ?>
			<?php if ( $sheetsync_sheet_fix_url !== '' ) : ?>
				<a href="<?php echo esc_url( $sheetsync_sheet_fix_url ); ?>"><?php esc_html_e( 'Fix connection', 'sheetsync-for-woocommerce' ); ?></a>
			<?php endif; ?>
		</p>
		<?php endif; ?>
	</div>
</div>
