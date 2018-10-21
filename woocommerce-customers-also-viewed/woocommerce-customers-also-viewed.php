<?php

/**
 * Plugin Name: Customers who viewed this also viewed that - WooCommerce.
 * Description: Show a group of products that were viewed by other customers that also viewed the current product, ie. Customers who viewed this also viewed that.
 * Version: 1.0
 * Author: Benjamin Hall
 * Author URI: https://conobe.co.uk
 * Text Domain: woocommerce-extension
 *
 * Copyright: © 2018 Benjamin Hall (Conobe).
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

/**
 * Require the library file
 */
require 'lib/ConobeWoocommerceAlsoViewed.php';

/**
 * Set up the plugin
 */
$conobe_woocommerce_also_viewed = new benhall14\ConobeWoocommerceAlsoViewed(__FILE__);
