<?php

namespace benhall14;

/**
 * ConobeWoocommerceAlso Viewed
 */
class ConobeWoocommerceAlsoViewed
{

    /**
     * The internal plugin options name.
     *
     * @var string
     */
    private $option_name = 'conobe_woocommerce_also_viewed';

    /**
     * The internal plugin shortcode id
     *
     * @var string
     */
    private $shortcode_id = 'customers-also-viewed';

    /**
     * The default number of products to show.
     *
     * @var integer
     */
    private $products_per_page = 10;

    /**
     * The default h2 title for the main block.
     *
     * @var string
     */
    private $h2_title = 'Customers who viewed this item also viewed';

    /**
     * The default tab title.
     *
     * @var string
     */
    private $tab_title = 'Customers Also Viewed';

    /**
     * The default text when no products are found.
     *
     * @var string
     */
    private $no_product_text = 'No products found.';

    /**
     * The default visiblity status - hide when no products are matched.
     *
     * @var boolean
     */
    private $hide_when_no_products = false;

    /**
     * The default location hook
     *
     * @var string
     */
    private $location = 'woocommerce_after_single_product';

    /**
     * The default use_tab flag set to true
     *
     * @var boolean
     */
    private $use_tab = true;

    /**
     * The internal debugging flag
     *
     * @var boolean
     */
    private $debug = false;

    /**
     * Set up the plugin.
     *
     * @param string $base
     */
    public function __construct($base)
    {
        # set the base directory
        $this->base = dirname($base);

        # pull the plugin options
        $options = get_option($this->option_name);

        # set the raw options
        $this->raw_options = $options;

        # merge the plugin options with the plugin defaults
        if (isset($options['products_per_page'])) {
            $this->products_per_page = (int) $options['products_per_page'];
        }
        if (isset($options['h2_title'])) {
            $this->h2_title = $options['h2_title'];
        }

        if (isset($options['tab_title'])) {
            $this->tab_title = $options['tab_title'];
        }

        if (isset($options['hide_when_no_products'])) {
            $this->hide_when_no_products = $options['hide_when_no_products'];
        }

        if (isset($options['no_product_text'])) {
            $this->no_product_text = $options['no_product_text'];
        }

        if (isset($options['use_tab'])) {
            $this->use_tab = ($options['use_tab'] == true ? true : false);
        }

        if (isset($options['location'])) {
            $this->location = $options['location'];
        }

        # set the debug flag - internal
        if ($this->debug) {
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
        }

        # run the hooks
        $this->hooks();
    }

    /**
     * Sets up all of the plugin hooks into the WP hook system.
     *
     * @return void
     */
    public function hooks()
    {
        // track session
        add_action('template_redirect', [$this, 'trackSession'], 20);
        
        // update product meta
        add_action('woocommerce_after_single_product', [$this, 'updateProduct']);

        // set up tabs
        if ($this->use_tab) {
            add_filter('woocommerce_product_tabs', [$this, 'initWoocommerceTabs']);
        }
        
        // set up hook for the main block
        if ($this->location && $this->location != 'hide_block') {
            add_action($this->location, [$this, 'printProducts'], 15);
        }
        
        // set up menu and control panel page
        add_action('admin_menu', function () {
            add_submenu_page('woocommerce',
                'Customers Also Viewed',
                'Customers Also Viewed',
                'manage_options',
                'conobe-customers-also-viewed',
                [$this, 'controlPanel']
            );
        }, 99);

        // set up shortcode
        add_shortcode($this->shortcode_id, [$this, 'shortcode']);

        // set up the clear tracking URL
        add_action('wp_ajax_clear_product_tracking', [$this, 'clearTrackingResponse']);
    }

    /**
     * Remove all tracing of the tracking information from the database
     *
     * @return void
     */
    public function clearTracking()
    {
        global $wpdb;

        return $wpdb->delete($wpdb->postmeta, array('meta_key' => '_also_viewed'));
    }

    /**
     * Undocumented function
     *
     * @return void
     */
    public function clearTrackingResponse()
    {

        $this->clearTracking();

        $redirect = isset($_GET['redirect']) ? $_GET['redirect'] : '';
        
        if ($redirect) {
            header('Location: ' . $redirect . '&clear_tracking=true');
            exit;
        } else {
            die('The product tracking information has been removed.');
        }
    }

    /**
     * Get the status of whether to show the content in a tab.
     *
     * @return boolean
     */
    public function showInTab()
    {
        return $this->use_tab;
    }

    /**
     * Tracks the session and updates product cookie.
     *
     * @return void
     */
    public function trackSession()
    {
        global $post;

        // return if we are not dealing with a product
        if (!is_singular('product')) {
            return;
        }
    
        // tracked products
        $tracked_products = [];
    
        // get the current tracking cookie
        if (isset($_COOKIE['woocommerce_also_viewed']) && !empty($_COOKIE['woocommerce_also_viewed'])) {
            $tracked_products = (array) explode('|', $_COOKIE['woocommerce_also_viewed']);
        }
    
        // add the current product id
        if (!in_array($post->ID, $tracked_products)) {
            $tracked_products[] = $post->ID;
        }
    
        // limit at 15
        if (sizeof($tracked_products) > 15) {
            array_shift($tracked_products);
        }
    
        // set the cookie
        wc_setcookie('woocommerce_also_viewed', implode('|', $tracked_products));

        // return
        return $this;
    }

    /**
     * Uses the tracking cookie and updates the product meta.
     *
     * @return void
     */
    public function updateProduct()
    {
        global $post;

        // check if the current page is a product.
        if (!is_singular('product')) {
            return;
        }
    
        // get the tracking cookie
        $customer_cookie = isset($_COOKIE['woocommerce_also_viewed']) && !empty($_COOKIE['woocommerce_also_viewed'])
            ? array_flip(explode('|', $_COOKIE['woocommerce_also_viewed']))
            : [];
    
        // remove the current product cookie
        unset($customer_cookie[$post->ID]);
    
        // get the saved also viewed items for this product
        $customers_also_viewed = get_post_meta($post->ID, '_also_viewed', true);
        if (!is_array($customers_also_viewed)) {
            $customers_also_viewed = [];
        }
    
        // loop through
        if (!empty($customer_cookie) && is_array($customer_cookie)) {
            foreach ($customer_cookie as $_product_id => $value) {
                if (isset($customers_also_viewed[$_product_id])) {
                    $customers_also_viewed[$_product_id]++;
                } else {
                    $customers_also_viewed[$_product_id] = 1;
                }
            }
        }
    
        #if (sizeof($customers_also_viewed) > 15) {
            #array_shift($customers_also_viewed);
        #}
    
        // don't waste time saving an empty array
        if (!empty($customers_also_viewed)) {
            update_post_meta($post->ID, '_also_viewed', $customers_also_viewed);
        }

        return $this;
    }

    /**
     * The main tab hook.
     *
     * Inserts the plugin output into the woocommerce tab section.
     *
     * @param array $tabs
     * @return void
     */
    public function initWoocommerceTabs($tabs)
    {
        // if the use_tab isn't turned on, return the default tabs
        if (!$this->showInTab()) {
            return $tabs;
        }

        // update the tabs
        $tabs['also_viewed'] = [
            'title' => $this->tab_title,
            'priority' => 50,
            'callback' => [$this, 'woocommerceTab']
        ];
    
        // return the new tabs
        return $tabs;
    }
    
    /**
     * The tab output
     *
     * @return void
     */
    public function woocommerceTab()
    {
        global $post;
        echo apply_filters('conobe_woocommerce_customers_also_viewed_before_tab_wrapper',
            '<div class="woocommerce-customers-also-viewed-tab">');
            echo $this->productLoop();
        echo apply_filters('conobe_woocommerce_customers_also_viewed_after_tab_wrapper',
            '</div>');
    }

    /**
     * Return the HTML when no products are found/matched/instock.
     *
     * @return void
     */
    public function noProducts()
    {
        if ($this->hide_when_no_products) {
            return;
        } else {
            $return = '<h2 class="woocommerce-customers-also-viewed-title">' . $this->h2_title . '</h2>';
            $return .= '<div>';
            $return .= '<div class="woocommerce-customers-also-viewed-no-products">';
            $return .= $this->no_product_text;
            $return .= '</div>';
            $return .= '</div>';

            return $return;
        }
    }

    /**
     * The product loop that generates the main <ul> and <li> items using the product meta data.
     *
     * @return void
     */
    public function productLoop()
    {
        global $post, $woocommerce;

        // we can't run on mulitple products, so return if it's not a single product
        if (!is_singular('product')) {
            return;
        }

        // get the products meta
        $product_ids = get_post_meta($post->ID, '_also_viewed', true);
        if (!is_array($product_ids)) {
            $product_ids = [];
        }

        // only show the $product_per_page set number of products
        if (count($product_ids) > $this->products_per_page) {
            $product_ids = array_slice(array_flip($product_ids), 0, $this->products_per_page);
            $product_ids = array_flip($product_ids);
        }

        // set up the clean ids.
        $clean_ids = [];
        foreach ($product_ids as $id => $count) {
            $id = (int) $id;
            if ($id && $id != $post->ID) {
                $clean_ids[] = $id;
            }
        }

        // if no clean ids are set, bail out
        if (!count($clean_ids)) {
            return $this->noProducts();
        }
        
        // The WP_Query arguments.
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => $this->products_per_page,
            'no_found_rows' => 1,
            'post_status' => 'publish',
            'post__in' => $clean_ids,
            'meta_query' => [
                $woocommerce->query->stock_status_meta_query()
            ]
        );

        $args = apply_filters('conobe_woocommerce_also_viewed_query_args', $args);

        // get the products
        $loop = new \WP_Query($args);
        
        // apply the filter to the product wrap classes
        $classes = apply_filters('conobe_woocommerce_also_viewed_product_wrap_classes', ['products']);

        // again, bail out if no products are returned.
        if (!$loop->have_posts()) {
            return $this->noProducts();
        }

        // start the output buffering
        ob_start();

        echo '<div class="customers_also_viewed_wrapper">';

        echo '<h2>' . $this->h2_title  . '</h2>';

        echo '<div class="woocommerce customers_also_viewed">';
            
        woocommerce_product_loop_start();
        
        // apply filters to the opening ul wrapper
        echo apply_filters('conobe_woocommerce_also_viewed_before_products',
            '<ul class="' . implode(' ', $classes) . '">',
            $classes
        );

        // loop through matched products
        while ($loop->have_posts()) {
            $loop->the_post();
            global $product;
            $this->singleProduct($product);
        }

        // apply filters to the wrapping ul
        echo apply_filters('conobe_woocommerce_also_viewed_after_products', '</ul>');

        // reset the post query
        wp_reset_query();

        echo '</div>';
            
        echo '</div>';
        
        // get the content from the output buffer
        $product_html = ob_get_contents();

        // clean the buffer
        ob_clean();

        // return the html
        return $product_html;
    }

    /**
     * Get the single product template.
     *
     * @param object $product
     * @return void
     */
    public function singleProduct($product)
    {
        // locate the 'customers-also-viewed' template;
        $template_path = $this->locateTemplate('customers-also-viewed.php');
        
        // load the template
        if ($template_path) {
            require $template_path;
        }
    }

    /**
     * Locate the template in either:
     * 1) The stylesheet directory,
     * 2) The theme directory or,
     * 3) The plugin directory.
     *
     * @param string $file
     * @return void
     */
    public function locateTemplate($file)
    {

        // the stylesheet template directory
        $stylesheet_path = get_stylesheet_directory() . '/customers-also-viewed/' . $file;
        
        // the parent theme template directory
        $theme_path = get_template_directory() . '/customers-also-viewed/' . $file;

        // the plugin template directory
        $plugin_path = $this->base . '/templates/' . $file;

        // return the correct paths
        if (file_exists($stylesheet_path)) {
            return $stylesheet_path;
        } elseif (file_exists($theme_path)) {
            return $theme_path;
        } elseif (file_exists($plugin_path)) {
            return $plugin_path;
        }

        return false;
    }

    /**
     * Set up the product content for use with the shortcode [customers-also-viewed]
     *
     * @return void
     */
    public function shortcode()
    {
        global $post;

        // don't allow the shortcode on non-single product pages.
        if (!is_singular('product')) {
            return;
        }

        // return the product content
        return $this->productLoop();
    }

    /**
     * Print the main product content.
     *
     * @return void
     */
    public function printProducts()
    {
        echo apply_filters('conobe_woocommerce_customers_also_viewed_before_main_wrapper',
            '<div class="woocommerce-customers-also-viewed">');
        echo $this->productLoop();
        echo apply_filters('conobe_woocommerce_customers_also_viewed_before_main_wrapper',
            '</div>');
    }

    /**
     * Creates the admin control panel
     *
     * @return void
     */
    public function controlPanel()
    {

        // check permissions
        if (!current_user_can( 'manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // save any POSTed options
        $this->saveOptions();

        // styles
        echo '<style>';
        require $this->base . '/css/admin.css';
        echo '</style>';

        echo '<div id="conobe" class="wrap">';
        echo '<h1 class="wp-heading-inline">Customers who viewed this item also viewed - Conobe</h1>';
        echo '<hr class="wp-header-end">';
        echo '<form method="post">';

        echo '<div class="credits">';
        echo '<p><a href="https://conobe.co.uk">Created by Benjamin Hall - Conobe.</a></p>';
        echo '<p>If you find this project helpful or useful in any way, please consider 
            getting me a cup of coffee - It\'s really appreciated :)</p>';
        echo '<a href="https://paypal.me/benhall14" target="_blank">Buy me a coffee?</a>';
        echo '</div>';

        if ($this->debug) {
            echo '<pre>';
            print_R($this->raw_options);
            echo '</pre>';
        }

        if (isset($_GET['clear_tracking'])) {
            echo '<div class="alert">Product tracking information cleared.</div>';
        }

        // h2 title
        echo '<label for="conobe_h2_title">';
        echo '<span>Title</span>';
        echo '<p>Please choose the title text that appears above the block of products.</p>';
        echo '<input type="text" id="conobe_h2_title" 
            name="conobe_h2_title" value="' . $this->h2_title . '"/>';
        echo '</label>';

        // products per page
        echo '<label for="conobe_products_per_page">';
        echo '<span>Products per page</span>';
        echo '<p>Please choose the maximum number of products to show.<br/>The default is <b>10</b>.</p>';
        echo '<input type="number" id="conobe_products_per_page" 
            name="conobe_products_per_page" value="' . $this->products_per_page . '"/>';
        echo '</label>';

        // use_tab
        echo '<label for="conobe_use_tab">';
        echo '<span>Show in product tabs</span>';
        echo '<p>This option allows you to toggle <b>On/Off</b>, the "Customers Also Viewed"
             section in the product information tabs</p>';
        echo '<select name="conobe_use_tab" id="conobe_use_tab">';
            $yes_selected = ($this->use_tab ? 'selected="selected"' : '');
            $no_selected = ($this->use_tab ? '' : 'selected="selected"');
            echo '<option value="true" ' . $yes_selected . '>On - Yes, show the tab</option>';
            echo '<option value="false" ' . $no_selected . '>Off - No, hide the tab.</option>';
        echo '</select>';
        echo '</label>';

        // tab_title
        echo '<label for="conobe_tab_title">';
        echo '<span>Tab Name</span>';
        echo '<p>You can override the default tab title by updating this field. <br/>';
        echo '<b>Please note</b> - The product tabs option above must be toggled <b>ON</b> for this to show.</p>';
        echo '<input type="text" id="conobe_tab_title" 
            name="conobe_tab_title" value="' . $this->tab_title . '"/>';
        echo '</label>';

        // hide_when_no_products
        echo '<label for="conobe_hide_when_no_products">';
        echo '<span>Hide products when none are found</span>';
        echo '<p>You can choose to either show a "No product found." box or simply hide the section from view.</p>';
        echo '<select name="conobe_hide_when_no_products" id="conobe_hide_when_no_products">';
            $yes_selected = ($this->hide_when_no_products ? 'selected="selected"' : '');
            $no_selected = (!$this->hide_when_no_products ? 'selected="selected"' : '');
            echo '<option value="true" ' . $yes_selected . '>Yes - Hide Products</option>';
            echo '<option value="false" ' . $no_selected . '>No - Show "No products found." text</option>';
        echo '</select>';
        echo '</label>';

        // no_product_text
        echo '<label for="conobe_no_product_text">';
        echo '<span>No Product Text</span>';
        echo '<p>You can choose to override the default text when no products are found.';
        echo 'The default value is: <b>"No products found."</b>.<br/>';
        echo '<b>Please note</b> - The previous option must be set to "No" in order for this to show.</p>';
        echo '<input type="text" id="conobe_no_product_text" 
            name="conobe_no_product_text" value="' . $this->no_product_text . '"/>';
        echo '</label>';

        // location hook
        echo '<label for="conobe_location">';
        echo '<span>Location</span>';
        echo '<p>Please choose from one of the placements from the box below.<br/>';
        echo '<b>Please Note</b> - This is dependant on <b>"' . wp_get_theme() . '"</b> 
            implementing the correct woocommerce hooks.</p>';
        echo '<select name="conobe_location" id="conobe_location">';

        foreach ($this->locationHookOptions() as $hook => $text) {
            $selected = '';

            if ($this->location == $hook) {
                $selected = 'selected="selected"';
            }
            echo '<option value="' . $hook . '" ' . $selected . '>' . $text . '</option>';
        }
        echo '</select>';
        echo '</label>';

        // shortcode
        echo '<label for="conobe_shortcode">';
        echo '<span>Shortcode - Advanced</span>';
        echo '<p>You can add the shortcode to single product pages in product body content, 
            widgets or via the do_shortcode code.<br/>';
        echo '<b>Please note</b> - This will probably require developer help - 99% percent of users 
            won\'t ever need to use this. It must be used "within the product loop" of a single product.</p>';
        echo '<input readonly type="text" id="conobe_shortcode" 
            name="conobe_shortcode" value="[' . $this->shortcode_id . ']"/>';
        echo '</label>';

        // Clear Tracking
        echo '<label for="conobe_clear">';
        echo '<span>!! Attention - Clear Tracking !!</span>';
        echo '<p>You can clear the current product suggestions by clicking the link below.</p>';
        echo '<p><b>Please Note - This cannot be undone!</b></p>';
        echo '<a href="' . admin_url('admin-ajax.php?action=clear_product_tracking&redirect=' . urlencode((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]")) . '" onclick="return confirm(\'Are you sure? This cannot be undone\')">';
        echo 'I understand, please clear the product tracking data</a>';
        echo '</label>';

        echo '<div class="submit-button">';
            echo '<button type="submit">Save Changes</button>';
        echo '</div>';
        
        echo '</form>';
    }

    /**
     * Save the POSTed plugin options
     *
     * @return void
     */
    public function saveOptions()
    {

        // the list of clean allowed options
        $allowed_options = [
            ['conobe_h2_title', 'text'],
            ['conobe_products_per_page', 'int'],
            ['conobe_use_tab', 'bool'],
            ['conobe_tab_title', 'text'],
            ['conobe_hide_when_no_products', 'bool'],
            ['conobe_no_product_text', 'text'],
            ['conobe_location', 'text']
        ];
        
        // if there have been options posted
        if (isset($_POST['conobe_location'])) {
            $new_options = [];

            // loop through and clean the options
            foreach ($allowed_options as $option) {
                $key = $option[0];
                $op_key = str_replace('conobe_', '', $key);
                $type = $option[1];
                if (isset($_POST[$key])) {
                    if ($type == 'bool') {
                        $new_options[$op_key] = ($_POST[$key] == 'true') ? true : false;
                    } elseif ($type == 'int') {
                        $new_options[$op_key] = (int) $_POST[$key];
                    } else {
                        $new_options[$op_key] = $_POST[$key];
                    }
                }
            }

            // merge the old and new options
            #if (is_array($this->raw_options)) {
                #$clean_options = array_merge($this->raw_options, $new_options);
            #} else {
                $clean_options = $new_options;
            #}

            // save the new options
            update_option($this->option_name, $clean_options);

            // redirect to update the page
            header("Location: http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]");

            die();
        }
    }

    /**
     * Return a list of possible hooks => location mapping.
     *
     * @return void
     */
    public function locationHookOptions()
    {
        return [
            'hide_block' => 'Don\'t Show',
            'woocommerce_before_single_product' => 'Before the single product',
            'woocommerce_before_single_product_summary' => 'Before the single product summary',
            'woocommerce_single_product_summary' => 'On the single product summary',
            'woocommerce_before_add_to_cart_form' => 'Before the add to cart form',
            #'woocommerce_before_variations_form' => 'Before variations form',
            #'woocommerce_before_add_to_cart_button' => 'Before add to cart button',
            #'woocommerce_before_single_variation' => 'Before single variation',
            #'woocommerce_single_variation' => 'Single variation',
            #'woocommerce_after_single_variation' => 'After single variation',
            #'woocommerce_after_add_to_cart_button' => 'After add to cart button',
            #'woocommerce_after_variations_form' => 'After the variations form',
            'woocommerce_after_add_to_cart_form' => 'After the add to cart form',
            'woocommerce_product_meta_start' => 'Before the product meta',
            'woocommerce_product_meta_end' => 'After the product meta',
            #'woocommerce_share' => 'Before the share',
            'woocommerce_after_single_product_summary' => 'After the single product summary',
            'woocommerce_after_single_product' => 'After the single product (Default)'
        ];
    }
}
