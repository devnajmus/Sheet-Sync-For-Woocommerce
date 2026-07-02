=== SheetSync for WooCommerce ===
Contributors: devnajmus, freemius
Tags: woocommerce, google sheets, sync, products, spreadsheet
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
WC requires at least: 7.0
WC tested up to: 9.9
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync WooCommerce products and orders with Google Sheets. Two-way sync, orders, auto sync, webhooks, and dashboards.

== Description ==

SheetSync for WooCommerce connects your WooCommerce store to Google Sheets. Sync products and orders, map fields, and keep inventory up to date.

= Free Features =
* One Google Sheet connection
* Google Sheets → WooCommerce product import
* Field mapping for core product fields
* Sync logs

= Pro Features =
* Orders sync with status filters
* WooCommerce → Google Sheets export
* Two-way sync
* Real-time webhook sync
* Multiple connections
* Sale price, categories, tags, images, weight, dimensions
* Variable product / variation sync
* Scheduled sync (15min, 30min, hourly, daily)
* Email notifications
* Import/Export connections
* Sales and inventory dashboards

= Requirements =
* WordPress 6.0+
* WooCommerce 7.0+
* PHP 8.0+
* PHP OpenSSL extension
* Google Cloud Service Account with Sheets API enabled

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`
2. Activate through the Plugins menu
3. Complete the Freemius opt-in when prompted
4. Go to **SheetSync → Account** to activate your license key (if purchased)
5. Go to **SheetSync → Settings** and paste your Google Service Account JSON
6. Go to **SheetSync → Connections** and create your first connection

User documentation is in the plugin folder: `docs/DOCUMENTATION-INDEX.md` (setup, variable products, background sync & cron).

== Changelog ==

= 1.0.0 =
* Initial public release
* Freemius licensing (free + Pro)
* Google Sheets ↔ WooCommerce sync for products and orders
* Free build: connection edit page, field mapping, and manual sync
* Uninstall cleanup via Freemius after_uninstall hook

== Upgrade Notice ==

= 1.0.0 =
Initial release.
