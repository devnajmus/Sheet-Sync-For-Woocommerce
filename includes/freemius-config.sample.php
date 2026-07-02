<?php
/**
 * Freemius SDK credentials — copy to freemius-config.php and fill from Developer Dashboard.
 *
 * Dashboard: https://dashboard.freemius.com → Your Plugin → SDK Integration
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'                => 0,
	'slug'              => 'sheetsync-for-woocommerce',
	'type'              => 'plugin',
	'public_key'        => 'pk_',
	'is_premium'        => true,
	'is_premium_only'   => true,
	'has_addons'        => false,
	'has_paid_plans'    => true,
	'is_org_compliant'  => true,
	'wp_org_gatekeeper' => '',
	'trial'             => array(
		'days'               => 3,
		'is_require_payment' => false,
	),
	'menu'              => array(
		'slug'       => 'sheetsync',
		'first-path' => 'admin.php?page=sheetsync',
		'support'    => false,
	),
);
