<?php
defined( 'ABSPATH' ) || exit;

$sheetsync_conn_id   = isset( $conn_id ) ? (int) $conn_id : 0;
$sheetsync_log_total = isset( $log_total ) ? (int) $log_total : count( $logs );
$sheetsync_cleared   = isset( $_GET['cleared'] ) ? absint( wp_unslash( $_GET['cleared'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$sheetsync_tz        = isset( $tz_string ) ? $tz_string : wp_timezone_string();
?>

<div class="sheetsync-wrap sheetsync-logs-wrap">
	<?php require __DIR__ . '/header.php'; ?>

	<?php if ( $sheetsync_cleared > 0 ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php
			printf(
				/* translators: %d: number of log rows deleted */
				esc_html__( 'Cleared %d log entries.', 'sheetsync-for-woocommerce' ),
				$sheetsync_cleared
			);
			?>
		</p></div>
	<?php endif; ?>

	<div class="sheetsync-card ss-logs-card">
		<div class="ss-logs-toolbar">
			<div class="ss-logs-toolbar-title">
				<h2><?php esc_html_e( 'Sync Logs', 'sheetsync-for-woocommerce' ); ?></h2>
				<p class="ss-logs-subtitle">
					<?php
					printf(
						/* translators: 1: entry count, 2: timezone */
						esc_html__( '%1$d entries · Times shown in %2$s (WordPress timezone)', 'sheetsync-for-woocommerce' ),
						$sheetsync_log_total,
						esc_html( $sheetsync_tz ?: 'UTC' )
					);
					?>
				</p>
				<p class="ss-logs-help description">
					<?php esc_html_e( 'Plain-language categories help you find what went wrong. Use the suggested actions to fix issues without reading technical job details.', 'sheetsync-for-woocommerce' ); ?>
				</p>
			</div>
			<div class="ss-logs-toolbar-actions">
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ss-logs-filter-form">
					<input type="hidden" name="page" value="sheetsync-logs" />
					<label class="screen-reader-text" for="ss-logs-conn-filter"><?php esc_html_e( 'Filter by connection', 'sheetsync-for-woocommerce' ); ?></label>
					<select id="ss-logs-conn-filter" name="connection_id" onchange="this.form.submit()">
						<option value="0"><?php esc_html_e( 'All connections', 'sheetsync-for-woocommerce' ); ?></option>
						<?php foreach ( $connections as $sheetsync_c ) : ?>
							<option value="<?php echo esc_attr( (string) $sheetsync_c->id ); ?>" <?php selected( $sheetsync_conn_id, (int) $sheetsync_c->id ); ?>>
								<?php echo esc_html( $sheetsync_c->name ?: __( '(Unnamed)', 'sheetsync-for-woocommerce' ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</form>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync-logs' . ( $sheetsync_conn_id ? '&connection_id=' . $sheetsync_conn_id : '' ) ) ); ?>" class="button ss-btn-icon">
                    <span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Refresh', 'sheetsync-for-woocommerce' ); ?>
				</a>
				<?php if ( ! empty( $logs ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ss-logs-clear-form"
						onsubmit="return confirm('<?php echo esc_js( $sheetsync_conn_id ? __( 'Delete all logs for this connection?', 'sheetsync-for-woocommerce' ) : __( 'Delete ALL sync logs?', 'sheetsync-for-woocommerce' ) ); ?>');">
						<?php wp_nonce_field( 'sheetsync_clear_logs' ); ?>
						<input type="hidden" name="action" value="sheetsync_clear_logs" />
						<input type="hidden" name="connection_id" value="<?php echo esc_attr( (string) $sheetsync_conn_id ); ?>" />
						<button type="submit" class="button button-secondary ss-logs-clear-btn">
							<span class="dashicons dashicons-trash"></span>
							<?php
							echo $sheetsync_conn_id
								? esc_html__( 'Clear connection logs', 'sheetsync-for-woocommerce' )
								: esc_html__( 'Clear all logs', 'sheetsync-for-woocommerce' );
							?>
						</button>
					</form>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( empty( $logs ) ) : ?>
			<div class="ss-empty-state ss-logs-empty">
				<span class="dashicons dashicons-list-view"></span>
				<h3><?php esc_html_e( 'No logs yet', 'sheetsync-for-woocommerce' ); ?></h3>
				<p><?php esc_html_e( 'Sync activity will appear here after your first sync.', 'sheetsync-for-woocommerce' ); ?></p>
			</div>
		<?php else : ?>
			<div class="ss-logs-table-wrap">
				<table class="sheetsync-logs-table">
					<thead>
						<tr>
							<th class="col-time"><?php esc_html_e( 'Time', 'sheetsync-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Connection', 'sheetsync-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Category', 'sheetsync-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Activity', 'sheetsync-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Result', 'sheetsync-for-woocommerce' ); ?></th>
							<th class="col-num"><?php esc_html_e( 'OK', 'sheetsync-for-woocommerce' ); ?></th>
							<th class="col-num"><?php esc_html_e( 'Skip', 'sheetsync-for-woocommerce' ); ?></th>
							<th class="col-num"><?php esc_html_e( 'Failed', 'sheetsync-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Details', 'sheetsync-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'What to do', 'sheetsync-for-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $sheetsync_log ) : ?>
							<?php
							$sheetsync_ts       = SheetSync_Logger::log_timestamp( (string) ( $sheetsync_log['created_at'] ?? '' ) );
							$sheetsync_now      = (int) current_time( 'timestamp' );
							$sheetsync_recent   = $sheetsync_ts > 0 && ( $sheetsync_now - $sheetsync_ts ) < 120;
							$sheetsync_status   = (string) ( $sheetsync_log['status'] ?? '' );
							$sheetsync_category = SheetSync_Logger::human_category( $sheetsync_log );
							$sheetsync_actions  = SheetSync_Logger::recovery_actions( $sheetsync_log );
							$sheetsync_display  = SheetSync_Logger::display_message( (string) ( $sheetsync_log['message'] ?? '' ) );
							?>
							<tr class="ss-log-row ss-log-row-<?php echo esc_attr( $sheetsync_status ); ?> ss-log-cat-<?php echo esc_attr( $sheetsync_category ); ?><?php echo $sheetsync_recent ? ' ss-log-row-recent' : ''; ?>">
								<td class="col-time">
									<span class="ss-log-time-ago">
										<?php
										if ( $sheetsync_ts > 0 ) {
											echo esc_html( human_time_diff( $sheetsync_ts, $sheetsync_now ) . ' ' . __( 'ago', 'sheetsync-for-woocommerce' ) );
										} else {
											esc_html_e( '—', 'sheetsync-for-woocommerce' );
										}
										?>
									</span>
									<?php if ( $sheetsync_recent ) : ?>
										<span class="ss-log-just-now"><?php esc_html_e( 'Just now', 'sheetsync-for-woocommerce' ); ?></span>
									<?php endif; ?>
									<span class="ss-log-time-full">
										<?php
										if ( $sheetsync_ts > 0 ) {
											echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $sheetsync_ts ) );
										}
										?>
									</span>
								</td>
								<td>
									<span class="ss-log-conn"><?php echo esc_html( $sheetsync_log['connection_name'] ?? '—' ); ?></span>
								</td>
								<td>
									<span class="ss-log-category ss-log-category-<?php echo esc_attr( $sheetsync_category ); ?>">
										<?php echo esc_html( SheetSync_Logger::human_category_label( $sheetsync_log ) ); ?>
									</span>
								</td>
								<td>
									<span class="ss-log-type"><?php echo esc_html( SheetSync_Logger::human_sync_type_label( (string) ( $sheetsync_log['sync_type'] ?? '' ) ) ); ?></span>
								</td>
								<td>
									<span class="ss-log-status ss-log-<?php echo esc_attr( $sheetsync_status ); ?>">
										<?php echo esc_html( SheetSync_Logger::human_status_label( $sheetsync_status ) ); ?>
									</span>
								</td>
								<td class="col-num"><span class="ss-log-stat ss-log-stat-ok"><?php echo esc_html( (string) (int) ( $sheetsync_log['rows_processed'] ?? 0 ) ); ?></span></td>
								<td class="col-num"><span class="ss-log-stat"><?php echo esc_html( (string) (int) ( $sheetsync_log['rows_skipped'] ?? 0 ) ); ?></span></td>
								<td class="col-num">
									<?php $sheetsync_err = (int) ( $sheetsync_log['rows_errored'] ?? 0 ); ?>
									<span class="ss-log-stat<?php echo $sheetsync_err > 0 ? ' ss-log-stat-err' : ''; ?>"><?php echo esc_html( (string) $sheetsync_err ); ?></span>
								</td>
								<td class="col-message">
									<span class="ss-log-message"><?php echo esc_html( $sheetsync_display ); ?></span>
								</td>
								<td class="col-actions">
									<?php if ( ! empty( $sheetsync_actions ) ) : ?>
										<ul class="ss-log-actions">
											<?php foreach ( array_slice( $sheetsync_actions, 0, 3 ) as $sheetsync_action ) : ?>
												<li>
													<a href="<?php echo esc_url( (string) ( $sheetsync_action['url'] ?? '' ) ); ?>"
														<?php echo ! empty( $sheetsync_action['external'] ) ? ' target="_blank" rel="noopener"' : ''; ?>>
														<?php echo esc_html( (string) ( $sheetsync_action['label'] ?? '' ) ); ?>
													</a>
												</li>
											<?php endforeach; ?>
										</ul>
									<?php else : ?>
										<span class="ss-log-actions-empty">—</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="ss-logs-footnote">
				<?php esc_html_e( 'Showing the latest 100 entries. Older logs are removed automatically based on retention in Settings.', 'sheetsync-for-woocommerce' ); ?>
			</p>
		<?php endif; ?>
	</div>
</div>
