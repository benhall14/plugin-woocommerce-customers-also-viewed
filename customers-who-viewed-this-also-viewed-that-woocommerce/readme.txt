=== Customers Also Viewed for WooCommerce ===
Contributors: @benhall14
Tags: woocommerce, product recommendation, related products
Donate link: https://paypal.me/benhall14
Requires at least: 6.2
Requires PHP: 7.0
Tested up to: 7.1.3
Stable tag: 1.3.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Simple, lightweight recommendations using aggregate co-view counts stored in your store. No external service or customer profiles.

== Description ==
Customers Also Viewed for WooCommerce is a simple, no-bloat way to show products people view together. It records product relationships during browsing sessions and uses aggregate counts to rank recommendations, all within your store.

Add recommendations through classic WooCommerce product-page hooks, the Customers Also Viewed block in block-based product templates, or the `[customers-also-viewed]` shortcode on a single-product page. Settings let you choose placement and titles, set a minimum co-view count, exclude products or categories, and control how out-of-stock products are handled.

Tracking uses a browser cookie containing recently viewed product IDs and an indexed table of aggregate product-pair counts. You can set a retention period, optionally require a compatible consent manager that provides `wp_has_consent('statistics')`, review tracking diagnostics, or clear stored data. Existing `_also_viewed` metadata is migrated to indexed storage in background batches.

== Installation ==
1. Install and activate WooCommerce.
2. Install and activate this plugin.
3. Go to **WooCommerce > Customers Also Viewed** to configure recommendations and tracking.

== Privacy and data ==
The plugin stores recently viewed product IDs in a browser cookie and aggregate product-to-product view counts in your WordPress database. It does not store customer names or account IDs. Tracking can optionally require statistics consent through a compatible consent manager. The settings page includes a retention period and a control to clear stored tracking data. A privacy-policy notice is added when WordPress provides the privacy policy API.

== Changelog ==
= 1.3.0 =
* Rebuilt recommendation tracking around an indexed product-relationship table, with background migration of existing tracking data.
* Added a dynamic block for block-based product templates while retaining classic hooks, product tabs, and shortcode placement.
* Added minimum co-view thresholds, product and category exclusions, stock visibility options, retention controls, and tracking diagnostics.
* Added optional statistics-consent gating and privacy-policy guidance.
* Improved settings security, output escaping, and compatibility with current WordPress and WooCommerce APIs.
