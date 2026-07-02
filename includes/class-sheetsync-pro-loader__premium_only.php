<?php
/**
 * Pro plugin loader — bootstraps Pro-only classes from this plugin’s directory.
 *
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Pro_Loader' ) ) :

class SheetSync_Pro_Loader {

	/** @var bool */
	private static bool $field_filter_registered = false;

	/**
	 * Initialise Pro features after the core plugin fires `sheetsync_loaded`.
	 */
	public function run(): void {
		if ( ! class_exists( 'SheetSync_Loader', false ) ) {
			return;
		}

		$this->register_field_map_filter();
		$this->load_pro();
	}

	/**
	 * Merge premium field labels into the free Field Mapper API when licensed.
	 */
	private function register_field_map_filter(): void {
		if ( self::$field_filter_registered ) {
			return;
		}
		self::$field_filter_registered = true;
		add_filter( 'sheetsync_field_map_all_fields', array( __CLASS__, 'filter_field_map_all_fields' ), 10, 1 );
	}

	/**
	 * @param array<string, string> $base Free-tier fields from core.
	 * @return array<string, string>
	 */
	public static function filter_field_map_all_fields( array $base ): array {
		return array_merge( $base, self::pro_only_product_field_labels(), class_exists( 'SheetSync_Field_Mapper', false ) ? SheetSync_Field_Mapper::ORDER_FIELDS : array() );
	}

	/**
	 * Product fields beyond FREE_FIELDS (labels only; logic stays in Pro / core).
	 *
	 * @return array<string, string>
	 */
	private static function pro_only_product_field_labels(): array {
		return array(
			'_sale_price'       => __( 'Sale Price', 'sheetsync-for-woocommerce' ),
			'post_excerpt'      => __( 'Short Description', 'sheetsync-for-woocommerce' ),
			'_stock_status'     => __( 'Stock Status', 'sheetsync-for-woocommerce' ),
			'_weight'           => __( 'Weight', 'sheetsync-for-woocommerce' ),
			'_length'           => __( 'Length', 'sheetsync-for-woocommerce' ),
			'_width'            => __( 'Width', 'sheetsync-for-woocommerce' ),
			'_height'           => __( 'Height', 'sheetsync-for-woocommerce' ),
			'post_content'      => __( 'Long Description', 'sheetsync-for-woocommerce' ),
			'_product_image'    => __( 'Main Image (URL)', 'sheetsync-for-woocommerce' ),
			'_gallery_images'   => __( 'Gallery Images (comma-separated URLs)', 'sheetsync-for-woocommerce' ),
			'_product_type'     => __( 'Product Type', 'sheetsync-for-woocommerce' ),
			'_product_cats'     => __( 'Categories', 'sheetsync-for-woocommerce' ),
			'primary_category'  => __( 'Primary Category (filter)', 'sheetsync-for-woocommerce' ),
			'_product_tags'     => __( 'Tags', 'sheetsync-for-woocommerce' ),
			'parent_sku'        => __( 'Parent SKU (variable products)', 'sheetsync-for-woocommerce' ),
			'variation_attrs'   => __( 'Variation Attributes', 'sheetsync-for-woocommerce' ),
			'grouped_child_skus' => __( 'Grouped Child SKUs (comma-separated)', 'sheetsync-for-woocommerce' ),
			'sheet_color'       => __( 'Color (easy — optional)', 'sheetsync-for-woocommerce' ),
			'sheet_size'        => __( 'Size (easy — optional)', 'sheetsync-for-woocommerce' ),
			'sheet_row_role'       => __( 'Row Type', 'sheetsync-for-woocommerce' ),
			'sheet_product_group'  => __( 'Product Group (SKU)', 'sheetsync-for-woocommerce' ),
			'sheet_option_summary' => __( 'Options / Notes', 'sheetsync-for-woocommerce' ),
			'sheet_belongs_to'     => __( 'Belongs To (parent)', 'sheetsync-for-woocommerce' ),
		);
	}

	private function load_pro(): void {
		if ( ! defined( 'SHEETSYNC_PRO_DIR' ) ) {
			return;
		}
		$pro_files = array(
			'includes/pro/class-pro-remote-stock-rest.php'     => 'SheetSync_Pro_Remote_Stock_REST',
			'includes/pro/class-pro-sheet-column-visibility.php' => 'SheetSync_Pro_Sheet_Column_Visibility',
			'includes/pro/class-pro-product-fields.php'     => 'SheetSync_Pro_Product_Fields',
			'includes/pro/class-pro-custom-meta-sync.php'   => 'SheetSync_Pro_Custom_Meta_Sync',
			'includes/pro/class-pro-connection-rules.php'   => 'SheetSync_Pro_Connection_Rules',
			'includes/pro/class-pro-multisite-stock.php'      => 'SheetSync_Pro_Multisite_Stock',
			'includes/pro/class-two-way-sync.php'           => 'SheetSync_Two_Way_Sync',
			'includes/pro/class-webhook-handler.php'     => 'SheetSync_Webhook_Handler',
			'includes/pro/class-order-sync.php'          => 'SheetSync_Order_Sync',
			'includes/pro/class-variation-sync.php'      => 'SheetSync_Variation_Sync',
			'includes/pro/class-export-order.php'        => 'SheetSync_Export_Order',
			'includes/pro/class-cron-manager.php'        => 'SheetSync_Cron_Manager',
			'includes/pro/class-off-peak-publish.php'    => 'SheetSync_Off_Peak_Publish',
			'includes/pro/class-order-sheet-poller.php'   => 'SheetSync_Order_Sheet_Poller',
			'includes/pro/class-product-sheet-poller.php' => 'SheetSync_Product_Sheet_Poller',
			'includes/pro/class-bulk-processor.php'      => 'SheetSync_Bulk_Processor',
			'includes/pro/class-email-notifier.php'      => 'SheetSync_Email_Notifier',
			'includes/pro/class-email-reports.php'       => 'SheetSync_Email_Reports',
			'includes/pro/class-geo-resolver.php'          => 'SheetSync_Geo_Resolver',
			'includes/pro/class-sales-dashboard.php'     => 'SheetSync_Sales_Dashboard',
			'includes/pro/class-inventory-dashboard.php' => 'SheetSync_Inventory_Dashboard',
			'includes/pro/class-bulk-order-export.php'   => 'SheetSync_Bulk_Order_Export',
			'includes/pro/class-dashboard-phase2.php'       => 'SheetSync_Dashboard_Phase2',
			'includes/pro/class-dashboard-phase3.php'       => 'SheetSync_Dashboard_Phase3',
			'includes/pro/class-dashboard-enhancements.php' => 'SheetSync_Dashboard_Enhancements',
		);
		foreach ( $pro_files as $file => $class ) {
			$path = SHEETSYNC_PRO_DIR . $file;
			if ( file_exists( $path ) && ! class_exists( $class, false ) ) {
				require_once $path;
				if ( class_exists( $class, false ) ) {
					if ( method_exists( $class, 'init' ) ) {
						call_user_func( array( $class, 'init' ) );
					} else {
						try {
							new $class();
						} catch ( Throwable $e ) {
							// Defensive: never let a Pro class fatal the whole request.
						}
					}
				}
			}
		}
	}
}

endif;
