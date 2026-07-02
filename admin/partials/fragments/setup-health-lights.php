<?php
defined( 'ABSPATH' ) || exit;

$sheetsync_lights_compact = ! empty( $sheetsync_lights_compact );
$sheetsync_lights_title   = isset( $sheetsync_lights_title ) ? (string) $sheetsync_lights_title : '';
$sheetsync_lights         = function_exists( 'sheetsync_get_setup_health_traffic_lights' )
	? sheetsync_get_setup_health_traffic_lights()
	: array();

if ( empty( $sheetsync_lights ) ) {
	return;
}

$sheetsync_lights_ok = function_exists( 'sheetsync_setup_health_all_ok' ) && sheetsync_setup_health_all_ok();
?>
<div class="ss-health-lights<?php echo $sheetsync_lights_compact ? ' ss-health-lights--compact' : ''; ?><?php echo $sheetsync_lights_ok ? ' ss-health-lights--all-ok' : ''; ?>">
	<?php if ( $sheetsync_lights_title !== '' ) : ?>
		<p class="ss-health-lights-title"><strong><?php echo esc_html( $sheetsync_lights_title ); ?></strong></p>
	<?php endif; ?>
	<ul class="ss-health-lights-list" role="list">
		<?php foreach ( $sheetsync_lights as $sheetsync_light ) :
			$sheetsync_light_ok = ! empty( $sheetsync_light['ok'] );
			?>
		<li class="ss-health-light ss-health-light-<?php echo esc_attr( (string) ( $sheetsync_light['key'] ?? '' ) ); ?><?php echo $sheetsync_light_ok ? ' is-ok' : ' is-warn'; ?>">
			<span class="ss-health-light-icon" aria-hidden="true"><?php echo $sheetsync_light_ok ? '✓' : '!'; ?></span>
			<span class="ss-health-light-label"><?php echo esc_html( (string) ( $sheetsync_light['label'] ?? '' ) ); ?></span>
			<?php if ( ! $sheetsync_light_ok && ! empty( $sheetsync_light['fix_url'] ) ) : ?>
				<a class="ss-health-light-fix" href="<?php echo esc_url( (string) $sheetsync_light['fix_url'] ); ?>">
					<?php echo esc_html( (string) ( $sheetsync_light['fix_label'] ?? __( 'Fix', 'sheetsync-for-woocommerce' ) ) ); ?>
				</a>
			<?php endif; ?>
		</li>
		<?php endforeach; ?>
	</ul>
	<?php if ( ! $sheetsync_lights_compact ) : ?>
	<p class="description ss-health-lights-foot">
		<?php esc_html_e( 'Fix any items above for reliable background sync on large catalogs.', 'sheetsync-for-woocommerce' ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync-setup-health' ) ); ?>"><?php esc_html_e( 'Full setup health', 'sheetsync-for-woocommerce' ); ?></a>
	</p>
	<?php endif; ?>
</div>
