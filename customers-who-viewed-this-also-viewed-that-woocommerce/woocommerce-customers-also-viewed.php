<?php

/**
 * Plugin Name: Customers Also Viewed for WooCommerce
 * Description: Show a group of products that were viewed by other customers that also viewed the current product, ie. Customers who viewed this also viewed that.
 * Version: 1.3.0
 * Requires PHP: 7.0
 * Author: Benjamin Hall
 * Author URI: https://conobe.co.uk
 * Text Domain: customers-who-viewed-this-also-viewed-that-woocommerce
 *
 * Copyright: © 2026 Benjamin Hall (Conobe).
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Require the library file
 */
require_once __DIR__ . '/lib/ConobeWoocommerceAlsoViewed.php';

/**
 * Set up the plugin
 */
$conobe_woocommerce_also_viewed = new benhall14\ConobeWoocommerceAlsoViewed(__FILE__);

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('conobe_migrate_legacy_tracking');
    wp_clear_scheduled_hook('conobe_cleanup_tracking');
});
