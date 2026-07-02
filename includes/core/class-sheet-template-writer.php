<?php
/**
 * Write product sheet headers + examples + Help tab text to a Google Sheet.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Sheet_Template_Writer', false ) ) :

class SheetSync_Sheet_Template_Writer {

	/** Reliable demo image URLs (direct JPG). */
	private const IMG_TSHIRT_FEATURED = 'https://picsum.photos/id/237/800/800';
	private const IMG_TSHIRT_GALLERY  = 'https://picsum.photos/id/238/800/800,https://picsum.photos/id/239/800/800';
	private const IMG_HOODIE_FEATURED = 'https://picsum.photos/id/431/800/800';
	private const IMG_HOODIE_GALLERY  = 'https://picsum.photos/id/432/800/800,https://picsum.photos/id/433/800/800';
	private const IMG_VAR_FEATURED    = 'https://picsum.photos/id/429/800/800';

	/**
	 * @return array{success: bool, message: string}
	 */
	public function write_to_connection( int $connection_id ): array {
		$conn = SheetSync_Sync_Engine::get_connection( $connection_id );
		if ( ! $conn || empty( $conn->spreadsheet_id ) || empty( $conn->sheet_name ) ) {
			return array(
				'success' => false,
				'message' => __( 'Connection or spreadsheet not found.', 'sheetsync-for-woocommerce' ),
			);
		}

		if ( SheetSync_Sync_Engine::is_orders_type( $conn->connection_type ?? 'products' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Template writer is for product connections only.', 'sheetsync-for-woocommerce' ),
			);
		}

		$client = new SheetSync_Sheets_Client();
		$tab    = $conn->sheet_name;
		$sid    = $conn->spreadsheet_id;

		try {
			$this->apply_recommended_field_maps( $connection_id );

			$export_maps = class_exists( 'SheetSync_Bulk_Processor', false )
				? SheetSync_Bulk_Processor::maps_for_sheet_export( $connection_id )
				: SheetSync_Field_Mapper::get_maps_for_export( $connection_id );

			$headers  = SheetSync_Field_Mapper::build_header_row_from_maps( $export_maps );
			$col_end  = SheetSync_Field_Mapper::index_to_col( count( $headers ) - 1 );
			$examples = $this->example_rows( $export_maps );
			$all_rows = array_merge( array( $headers ), $examples );
			$last_row = count( $all_rows );
			$range    = SheetSync_Sheets_Client::tab_range( $tab, 'A1:' . $col_end . $last_row );
			$client->set_rows( $sid, $range, $all_rows );

			$client->format_product_template_sheet(
				$sid,
				$tab,
				count( $headers ),
				count( $examples ),
				0
			);

			if ( ! empty( $export_maps ) ) {
				try {
					$client->apply_export_sheet_filters(
						$sid,
						$tab,
						max( 1, (int) $conn->header_row ),
						count( $examples ),
						$export_maps,
						$connection_id
					);
				} catch ( Exception $e ) {
					// Non-fatal — template rows and colors still written.
				}
			}

			$help_tab = 'SheetSync Help';
			$meta     = $client->get_metadata( $sid );
			if ( ! in_array( $help_tab, $meta['sheets'] ?? array(), true ) ) {
				$this->ensure_help_tab( $client, $sid, $help_tab );
			}
			$client->set_rows( $sid, "{$help_tab}!A1:A14", $this->help_rows() );
			$client->format_help_tab( $sid, $help_tab );

			return array(
				'success' => true,
				'message' => __( 'Professional template written (rows 2–4 are examples). Field mapping updated. Use Check sheet, then Full sync to import products and images.', 'sheetsync-for-woocommerce' ),
			);
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	private function apply_recommended_field_maps( int $connection_id ): void {
		if ( class_exists( 'SheetSync_Pro_Admin', false ) ) {
			SheetSync_Pro_Admin::apply_preset_field_maps( $connection_id, SheetSync_Field_Mapper::PROFILE_FULL );
			return;
		}
		$field_map = SheetSync_Field_Mapper::preset_to_field_map( SheetSync_Field_Mapper::PROFILE_FULL );
		if ( ! empty( $field_map ) ) {
			SheetSync_Sync_Engine::save_field_maps( $connection_id, $field_map );
			SheetSync_Field_Mapper::invalidate_cache( $connection_id );
		}
	}

	/**
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
	 * @return list<list<string>>
	 */
	private function example_rows( array $maps ): array {
		return array(
			SheetSync_Field_Mapper::build_row_from_field_values(
				$maps,
				array(
					'_sku'            => 'DEMO-TSH-001',
					'post_title'      => 'Demo Cotton T-Shirt',
					'_regular_price'  => '29.99',
					'_stock'          => '50',
					'post_status'     => 'publish',
					'menu_order'      => '1',
					'_sale_price'     => '24.99',
					'post_excerpt'    => 'Soft cotton tee for everyday wear.',
					'_stock_status'   => 'instock',
					'_weight'         => '0.2',
					'_length'         => '28',
					'_width'          => '22',
					'_height'         => '2',
					'post_content'    => 'Full product description goes here.',
					'_product_image'  => self::IMG_TSHIRT_FEATURED,
					'_gallery_images' => self::IMG_TSHIRT_GALLERY,
					'_product_type'   => 'simple',
					'_product_cats'   => 'T-Shirts',
					'_product_tags'   => 'cotton,demo',
					'sheet_row_role'  => 'Simple product',
				)
			),
			SheetSync_Field_Mapper::build_row_from_field_values(
				$maps,
				array(
					'_sku'            => 'DEMO-HOODIE-01',
					'post_title'      => 'Demo Variable Hoodie',
					'post_status'     => 'publish',
					'menu_order'      => '2',
					'post_excerpt'    => 'Variable parent — list all colors and sizes here.',
					'_stock_status'   => 'instock',
					'_weight'         => '0.5',
					'_length'         => '35',
					'_width'          => '30',
					'_height'         => '8',
					'post_content'    => 'Parent row: set Product Type = variable. Price/stock go on variation rows.',
					'_product_image'  => self::IMG_HOODIE_FEATURED,
					'_gallery_images' => self::IMG_HOODIE_GALLERY,
					'_product_type'   => 'variable',
					'_product_cats'   => 'Hoodies',
					'_product_tags'   => 'demo',
					'sheet_color'     => 'red,black',
					'sheet_size'      => 's,m',
					'sheet_row_role'  => 'Variable (main)',
				)
			),
			SheetSync_Field_Mapper::build_row_from_field_values(
				$maps,
				array(
					'_sku'           => 'DEMO-HOODIE-01-RED-S',
					'post_title'     => 'Demo Hoodie — Red / S',
					'_regular_price' => '59.99',
					'_stock'         => '10',
					'post_status'    => 'publish',
					'menu_order'     => '3',
					'post_excerpt'   => 'Variation row — one Color and one Size.',
					'_stock_status'  => 'instock',
					'_weight'        => '0.5',
					'_length'        => '35',
					'_width'         => '30',
					'_height'        => '8',
					'_product_image' => self::IMG_VAR_FEATURED,
					'parent_sku'     => 'DEMO-HOODIE-01',
					'sheet_color'    => 'red',
					'sheet_size'     => 's',
					'sheet_row_role' => 'Variation (option)',
				)
			),
		);
	}

	/**
	 * @return list<list<string>>
	 */
	private function help_rows(): array {
		return array(
			array( 'SheetSync — Product import guide' ),
			array( 'Row 2: Simple product (one SKU, Product Type = simple).' ),
			array( 'Row 3: Variable parent (Product Type = variable, Color = red,black, Size = s,m).' ),
			array( 'Row 4: Variation (Parent SKU = parent SKU, single Color + Size, price + stock).' ),
			array( 'Images: WordPress Media Library URL, attachment ID (1234), or any https image link.' ),
			array( 'Featured Image URL = main photo. Gallery Image URLs = comma-separated.' ),
			array( 'Color / Size: Use normal words (red, s). No need to type pa_color or pa_size.' ),
			array( 'WooCommerce setup (one time): Products → Attributes → create Color and Size, add terms (red, black, s, m).' ),
			array( 'Workflow: Edit sheet → WordPress → Check sheet → Full sync (first import) or Update existing SKUs.' ),
			array( 'Delete or replace rows 2–4 with your own products when ready.' ),
			array( 'Troubleshooting: If images fail, open Settings → Test image URL. Hosting must allow outbound HTTPS.' ),
			array( '' ),
			array( 'Header colors: Blue = core fields | Orange = images | Purple = categories | Gold = variations' ),
		);
	}

	/**
	 * @throws Exception If tab cannot be created.
	 */
	private function ensure_help_tab( SheetSync_Sheets_Client $client, string $spreadsheet_id, string $title ): void {
		unset( $client );
		$url  = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $spreadsheet_id ) . ':batchUpdate';
		$body = array(
			'requests' => array(
				array(
					'addSheet' => array(
						'properties' => array( 'title' => $title ),
					),
				),
			),
		);
		SheetSync_Google_Auth::api_post( $url, $body );
	}
}

endif;
