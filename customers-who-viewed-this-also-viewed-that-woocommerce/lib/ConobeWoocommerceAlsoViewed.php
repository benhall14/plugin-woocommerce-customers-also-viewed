<?php

namespace benhall14;

/**
 * ConobeWoocommerceAlso Viewed
 */
class ConobeWoocommerceAlsoViewed
{

    /**
     * The plugin directory.
     *
     * @var string
     */
    private $base;

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

    private $tracking_table;

    private $retention_days = 365;

    private $minimum_views = 1;

    private $excluded_category_ids = [];

    private $excluded_product_ids = [];

    private $require_consent = false;

    private $stock_filter = 'woocommerce';

    private $request_tracking_recorded = false;

    /**
     * Set up the plugin.
     *
     * @param string $base
     */
    public function __construct($base)
    {
        # set the base directory
        $this->base = dirname($base);

        global $wpdb;
        $this->tracking_table = $wpdb->prefix . 'conobe_also_viewed';

        # pull the plugin options
        $options = get_option($this->option_name, []);
        $options = is_array($options) ? $options : [];

        # merge the plugin options with the plugin defaults
        if (isset($options['products_per_page']) && is_scalar($options['products_per_page'])) {
            $this->products_per_page = min(50, max(1, absint($options['products_per_page'])));
        }

        if (isset($options['h2_title']) && is_scalar($options['h2_title'])) {
            $this->h2_title = sanitize_text_field($options['h2_title']);
        }

        if (isset($options['tab_title']) && is_scalar($options['tab_title'])) {
            $this->tab_title = sanitize_text_field($options['tab_title']);
        }

        if (isset($options['hide_when_no_products']) && is_scalar($options['hide_when_no_products'])) {
            $this->hide_when_no_products = filter_var($options['hide_when_no_products'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($options['no_product_text']) && is_scalar($options['no_product_text'])) {
            $this->no_product_text = sanitize_text_field($options['no_product_text']);
        }

        if (isset($options['use_tab']) && is_scalar($options['use_tab'])) {
            $this->use_tab = filter_var($options['use_tab'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($options['location']) && is_scalar($options['location'])) {
            $location = sanitize_key($options['location']);
            if (array_key_exists($location, $this->locationHookOptions())) {
                $this->location = $location;
            }
        }

        if (isset($options['retention_days']) && is_scalar($options['retention_days'])) {
            $this->retention_days = min(3650, max(0, absint($options['retention_days'])));
        }

        if (isset($options['minimum_views']) && is_scalar($options['minimum_views'])) {
            $this->minimum_views = min(100000, max(1, absint($options['minimum_views'])));
        }

        if (isset($options['excluded_category_ids']) && is_array($options['excluded_category_ids'])) {
            $category_ids = array_filter($options['excluded_category_ids'], 'is_scalar');
            $this->excluded_category_ids = array_values(array_filter(array_map('absint', $category_ids)));
        }

        if (isset($options['excluded_product_ids']) && is_array($options['excluded_product_ids'])) {
            $product_ids = array_filter($options['excluded_product_ids'], 'is_scalar');
            $this->excluded_product_ids = array_values(array_filter(array_map('absint', $product_ids)));
        }

        if (isset($options['require_consent']) && is_scalar($options['require_consent'])) {
            $this->require_consent = filter_var($options['require_consent'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($options['stock_filter']) && is_scalar($options['stock_filter'])) {
            $stock_filter = sanitize_key($options['stock_filter']);
            if (in_array($stock_filter, ['woocommerce', 'hide', 'show'], true)) {
                $this->stock_filter = $stock_filter;
            }
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
        add_action('init', [$this, 'initializeTrackingStorage'], 1);
        add_action('init', [$this, 'registerRecommendationsBlock']);
        add_action('conobe_migrate_legacy_tracking', [$this, 'migrateLegacyTrackingBatch']);
        add_action('conobe_cleanup_tracking', [$this, 'cleanupTracking']);
        add_action('admin_init', [$this, 'addPrivacyPolicyContent']);

        // track session
        add_action('template_redirect', [$this, 'trackSession'], 20);

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
            add_submenu_page(
                'woocommerce',
                'Customers Also Viewed',
                'Customers Also Viewed',
                'manage_options',
                'conobe-customers-also-viewed',
                [$this, 'controlPanel']
            );
        }, 99);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);

        // set up shortcode
        add_shortcode($this->shortcode_id, [$this, 'shortcode']);

        // set up the clear tracking URL
        add_action('wp_ajax_clear_product_tracking', [$this, 'clearTrackingResponse']);
    }

    /**
     * Create tracking storage and schedule background maintenance.
     *
     * @return void
     */
    public function initializeTrackingStorage()
    {
        global $wpdb;

        if (get_option('conobe_also_viewed_db_version') !== '1') {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE {$this->tracking_table} (
                product_id bigint(20) unsigned NOT NULL,
                viewed_product_id bigint(20) unsigned NOT NULL,
                view_count bigint(20) unsigned NOT NULL DEFAULT 0,
                last_viewed datetime NOT NULL,
                PRIMARY KEY  (product_id,viewed_product_id),
                KEY viewed_product_id (viewed_product_id),
                KEY last_viewed (last_viewed)
            ) $charset_collate;";
            dbDelta($sql);
            if (empty($wpdb->last_error)) {
                update_option('conobe_also_viewed_db_version', '1');
            }
        }

        if (!get_option('conobe_also_viewed_migration_complete') && !wp_next_scheduled('conobe_migrate_legacy_tracking')) {
            wp_schedule_single_event(time() + 30, 'conobe_migrate_legacy_tracking');
        }

        if (!wp_next_scheduled('conobe_cleanup_tracking')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'conobe_cleanup_tracking');
        }
    }

    /**
     * Move one batch of legacy post-meta relationships into indexed storage.
     *
     * @return void
     */
    public function migrateLegacyTrackingBatch()
    {
        global $wpdb;

        $batch_size = 200;
        $legacy_product_ids = get_posts([
            'post_type' => 'product',
            'post_status' => 'any',
            'posts_per_page' => $batch_size,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_key' => '_also_viewed',
            'meta_compare' => 'EXISTS',
            'no_found_rows' => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ]);

        if (!$legacy_product_ids) {
            update_option('conobe_also_viewed_migration_complete', 1);
            delete_option('conobe_also_viewed_migration_cursor');
            return;
        }

        $legacy_products = [];
        foreach ($legacy_product_ids as $legacy_product_id) {
            $post_id = absint($legacy_product_id);
            $relations = get_post_meta($post_id, '_also_viewed', true);
            if (!is_array($relations)) {
                continue;
            }
            foreach ($relations as $viewed_id => $view_count) {
                $viewed_id = absint($viewed_id);
                if ($viewed_id && $viewed_id !== $post_id && is_numeric($view_count)) {
                    $legacy_products[$post_id][$viewed_id] = max(
                        absint($legacy_products[$post_id][$viewed_id] ?? 0),
                        absint($view_count)
                    );
                }
            }
        }

        foreach ($legacy_products as $product_id => $relations) {
            foreach ($relations as $viewed_id => $view_count) {
                foreach ([[$product_id, $viewed_id], [$viewed_id, $product_id]] as $pair) {
                    // Atomic upserts keep count migration idempotent if a batch must be retried.
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A custom indexed relation table requires atomic writes and is not cacheable.
                    $result = $wpdb->query($wpdb->prepare(
                        "INSERT INTO %i (product_id, viewed_product_id, view_count, last_viewed)
                        VALUES (%d, %d, %d, UTC_TIMESTAMP())
                        ON DUPLICATE KEY UPDATE view_count = GREATEST(view_count, %d)",
                        $this->tracking_table,
                        $pair[0],
                        $pair[1],
                        $view_count,
                        $view_count
                    ));
                    if (false === $result) {
                        wp_schedule_single_event(time() + 60, 'conobe_migrate_legacy_tracking');
                        return;
                    }
                }
            }
        }

        foreach ($legacy_product_ids as $legacy_product_id) {
            delete_post_meta(absint($legacy_product_id), '_also_viewed');
        }

        delete_option('conobe_also_viewed_migration_cursor');
        $this->invalidateRecommendationCaches();
        wp_schedule_single_event(time() + 10, 'conobe_migrate_legacy_tracking');
    }

    /**
     * Remove tracking relationships older than the configured retention period.
     *
     * @return void
     */
    public function cleanupTracking()
    {
        if ($this->retention_days > 0) {
            global $wpdb;
            // The custom table is not compatible with WordPress's metadata deletion APIs.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled retention deletion directly targets the indexed custom table.
            $wpdb->query($wpdb->prepare(
                'DELETE FROM %i WHERE last_viewed < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)',
                $this->tracking_table,
                $this->retention_days
            ));
        }

        update_option('conobe_also_viewed_last_cleanup', gmdate('Y-m-d H:i:s'));
        $this->invalidateRecommendationCaches();
    }

    /**
     * Add privacy guidance for the anonymous product-history cookie.
     *
     * @return void
     */
    public function addPrivacyPolicyContent()
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = '<p>' . esc_html__('This store uses the Customers Also Viewed plugin to remember recently viewed product IDs in a browser cookie and maintain aggregate product-to-product view counts. It does not store customer names or account IDs. Tracking can be configured to require statistics consent, and aggregate records are automatically removed according to the retention setting.', 'customers-who-viewed-this-also-viewed-that-woocommerce') . '</p>';
        wp_add_privacy_policy_content(esc_html__('Customers Also Viewed', 'customers-who-viewed-this-also-viewed-that-woocommerce'), $content);
    }

    /**
     * Register the dynamic block for use in block-based product templates.
     *
     * @return void
     */
    public function registerRecommendationsBlock()
    {
        if (!function_exists('register_block_type')) {
            return;
        }

        wp_register_script(
            'conobe-also-viewed-block',
            plugins_url('../js/recommendations-block.js', __FILE__),
            ['wp-blocks', 'wp-element'],
            '1.3.0',
            true
        );
        register_block_type('conobe/customers-also-viewed', [
            'editor_script' => 'conobe-also-viewed-block',
            'render_callback' => [$this, 'renderRecommendationsBlock'],
        ]);
    }

    /**
     * Render the dynamic recommendations block.
     *
     * @return string
     */
    public function renderRecommendationsBlock()
    {
        return (string) $this->productLoop();
    }

    /**
     * Determine whether this visitor has allowed statistics tracking.
     *
     * @return bool
     */
    private function trackingAllowed()
    {
        return !$this->require_consent || (function_exists('wp_has_consent') && wp_has_consent('statistics'));
    }

    /**
     * Remove all tracing of the tracking information from the database
     *
     * @return void
     */
    public function clearTracking()
    {
        global $wpdb;

        $deleted = delete_post_meta_by_key('_also_viewed');
        // The custom table is not compatible with WordPress's metadata deletion APIs.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Clear all rows in the indexed custom table.
        $wpdb->query($wpdb->prepare('DELETE FROM %i', $this->tracking_table));
        update_option('conobe_also_viewed_migration_complete', 1);
        delete_option('conobe_also_viewed_migration_cursor');
        wp_clear_scheduled_hook('conobe_migrate_legacy_tracking');
        $this->invalidateRecommendationCaches();

        return $deleted;
    }

    /**
     * Undocumented function
     *
     * @return void
     */
    public function clearTrackingResponse()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to clear product tracking.', 'customers-who-viewed-this-also-viewed-that-woocommerce'));
        }
        check_ajax_referer('conobe_clear_tracking');
        $this->clearTracking();

        $redirect = isset($_GET['redirect']) ? esc_url_raw(wp_unslash($_GET['redirect'])) : '';
        if ($redirect) {
            wp_safe_redirect(add_query_arg('clear_tracking', 'true', $redirect));
            exit;
        } else {
            wp_die(esc_html__('The product tracking information has been removed.', 'customers-who-viewed-this-also-viewed-that-woocommerce'));
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
        // return if we are not dealing with a product
        if (!is_singular('product')) {
            return;
        }

        if (!$this->trackingAllowed()) {
            return;
        }

        $product_id = absint(get_queried_object_id());
        if (!$product_id) {
            return;
        }

        $tracked_products = [];
        if (isset($_COOKIE['woocommerce_also_viewed']) && is_scalar($_COOKIE['woocommerce_also_viewed']) && $_COOKIE['woocommerce_also_viewed'] !== '') {
            $cookie = sanitize_text_field(wp_unslash($_COOKIE['woocommerce_also_viewed']));
            $tracked_products = array_filter(array_map('absint', explode('|', $cookie)));
        }

        $this->recordProductRelations($product_id, $tracked_products);
        $this->request_tracking_recorded = true;

        // add the current product id
        if (!in_array($product_id, $tracked_products, true)) {
            $tracked_products[] = $product_id;
        }

        // limit at 15
        $tracked_products = array_slice(array_values(array_unique($tracked_products)), -15);

        if (!function_exists('wc_setcookie')) {
            return;
        }

        // set the cookie
        wc_setcookie('woocommerce_also_viewed', implode('|', $tracked_products));

        // return
        return $this;
    }

    /**
     * Increment aggregate counts for products viewed in the same browsing session.
     *
     * @param int   $product_id Current product ID.
     * @param array $viewed_ids Previously viewed product IDs.
     * @return void
     */
    private function recordProductRelations($product_id, array $viewed_ids)
    {
        global $wpdb;

        foreach (array_unique(array_filter(array_map('absint', $viewed_ids))) as $viewed_id) {
            if ($viewed_id === $product_id || 'product' !== get_post_type($viewed_id)) {
                continue;
            }

            foreach ([[$product_id, $viewed_id], [$viewed_id, $product_id]] as $pair) {
                // Atomic upserts are required for concurrent product views.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This is an indexed custom-table write and cannot use WordPress metadata APIs.
                $wpdb->query($wpdb->prepare(
                    'INSERT INTO %i (product_id, viewed_product_id, view_count, last_viewed)
                    VALUES (%d, %d, 1, UTC_TIMESTAMP())
                    ON DUPLICATE KEY UPDATE view_count = view_count + 1, last_viewed = UTC_TIMESTAMP()',
                    $this->tracking_table,
                    $pair[0],
                    $pair[1]
                ));
                delete_transient($this->recommendationCacheKey($pair[0]));
                delete_transient($this->recommendationCacheKey($pair[1]));
            }
        }

        delete_transient('conobe_also_viewed_pair_count');
    }

    /**
     * Build the cache key for one product's ordered recommendation IDs.
     *
     * @param int $product_id Product ID.
     * @return string
     */
    private function recommendationCacheKey($product_id)
    {
        return 'conobe_recommendation_ids_' . md5(implode(':', [
            absint($product_id),
            $this->products_per_page,
            $this->minimum_views,
            $this->retention_days,
            absint(get_option('conobe_recommendation_cache_generation', 0)),
        ]));
    }

    /**
     * Invalidate cached recommendation lists after broad data changes.
     *
     * @return void
     */
    private function invalidateRecommendationCaches()
    {
        $generation = absint(get_option('conobe_recommendation_cache_generation', 0));
        update_option('conobe_recommendation_cache_generation', $generation + 1, false);
        delete_transient('conobe_also_viewed_pair_count');
    }

    /**
     * Compatibility wrapper for integrations that called the legacy public method.
     *
     * @return self
     */
    public function updateProduct()
    {
        if ($this->request_tracking_recorded || !is_singular('product') || !$this->trackingAllowed()) {
            return $this;
        }

        $product_id = absint(get_queried_object_id());
        $viewed_ids = [];
        if (isset($_COOKIE['woocommerce_also_viewed']) && is_scalar($_COOKIE['woocommerce_also_viewed'])) {
            $viewed_ids = array_map('absint', explode('|', sanitize_text_field(wp_unslash($_COOKIE['woocommerce_also_viewed']))));
        }
        $this->recordProductRelations($product_id, $viewed_ids);

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
        echo wp_kses_post(apply_filters(
            'conobe_woocommerce_customers_also_viewed_before_tab_wrapper',
            '<div class="woocommerce-customers-also-viewed-tab">'
        ));
        echo wp_kses_post((string) $this->productLoop());
        echo wp_kses_post(apply_filters(
            'conobe_woocommerce_customers_also_viewed_after_tab_wrapper',
            '</div>'
        ));
    }

    /**
     * Return the HTML when no products are found/matched/instock.
     *
     * @return string|null
     */
    public function noProducts()
    {
        if ($this->hide_when_no_products) {
            return;
        } else {
            $return = '<h2 class="woocommerce-customers-also-viewed-title">' . esc_html($this->h2_title) . '</h2>';
            $return .= '<div>';
            $return .= '<div class="woocommerce-customers-also-viewed-no-products">';
            $return .= esc_html($this->no_product_text);
            $return .= '</div>';
            $return .= '</div>';

            return $return;
        }
    }

    /**
     * The product loop that generates the main <ul> and <li> items using the product meta data.
     *
     * @return string|null
     */
    public function productLoop()
    {
        // we can't run on mulitple products, so return if it's not a single product
        $current_product_id = get_queried_object_id();
        if (!is_singular('product') || !$current_product_id) {
            return;
        }

        global $product;
        $previous_product = $product ?? null;

        global $wpdb;

        $cache_key = $this->recommendationCacheKey($current_product_id);
        $clean_ids = get_transient($cache_key);
        if (false === $clean_ids) {
            $sql = 'SELECT viewed_product_id FROM %i WHERE product_id = %d AND view_count >= %d';
            $query_values = [$this->tracking_table, $current_product_id, $this->minimum_views];
            if ($this->retention_days > 0) {
                $sql .= ' AND last_viewed >= %s';
                $query_values[] = gmdate('Y-m-d H:i:s', time() - ($this->retention_days * DAY_IN_SECONDS));
            }
            $sql .= ' ORDER BY view_count DESC, last_viewed DESC LIMIT %d';
            $query_values[] = max(1, $this->products_per_page);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The custom-table result is transient-cached and metadata APIs cannot query aggregate pair counts.
            $clean_ids = array_map('absint', (array) $wpdb->get_col($wpdb->prepare($sql, $query_values)));
            set_transient($cache_key, $clean_ids, 5 * MINUTE_IN_SECONDS);
        }
        $clean_ids = array_values(array_diff($clean_ids, $this->excluded_product_ids));

        // if no clean ids are set, bail out
        if (!count($clean_ids)) {
            return $this->noProducts();
        }

        // Use WooCommerce's query helpers for catalog visibility and stock settings.
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => $this->products_per_page,
            'no_found_rows' => 1,
            'post_status' => 'publish',
            'post__in' => $clean_ids,
            'orderby' => 'post__in',
        );

        if (function_exists('WC') && WC() && WC()->query) {
            // Preserve WooCommerce's catalog and stock visibility rules.
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            $args['meta_query'] = WC()->query->get_meta_query();
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
            $args['tax_query'] = WC()->query->get_tax_query();
        }

        if (!empty($this->excluded_category_ids)) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Category exclusions are an explicit store setting.
            $args['tax_query'][] = [
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $this->excluded_category_ids,
                'operator' => 'NOT IN',
            ];
        }

        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Preserve the configured stock visibility behavior.
        $args['tax_query'] = $this->applyStockFilter($args['tax_query'] ?? []);

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

        echo '<h2>' . esc_html($this->h2_title) . '</h2>';

        echo '<div class="woocommerce customers_also_viewed">';

        // apply filters to the opening ul wrapper
        echo wp_kses_post(apply_filters(
            'conobe_woocommerce_also_viewed_before_products',
            '<ul class="' . esc_attr(implode(' ', array_map('sanitize_html_class', $classes))) . '">',
            $classes
        ));

        // loop through matched products
        while ($loop->have_posts()) {
            $loop->the_post();
            $product = wc_get_product(get_the_ID());
            $this->singleProduct($product);
        }

        // apply filters to the wrapping ul
        echo wp_kses_post(apply_filters('conobe_woocommerce_also_viewed_after_products', '</ul>'));

        // reset the post query
        wp_reset_postdata();
        $product = $previous_product;

        echo '</div>';

        echo '</div>';

        // get the content from the output buffer
        $product_html = ob_get_clean();

        // return the html
        return $product_html;
    }

    /**
     * Apply the configured out-of-stock behavior to the visibility query.
     *
     * @param array $tax_query WooCommerce tax-query clauses.
     * @return array
     */
    private function applyStockFilter(array $tax_query)
    {
        if ('woocommerce' === $this->stock_filter || !function_exists('wc_get_product_visibility_term_ids')) {
            return $tax_query;
        }

        $visibility_terms = wc_get_product_visibility_term_ids();
        $out_of_stock_term = absint($visibility_terms['outofstock'] ?? 0);
        if (!$out_of_stock_term) {
            return $tax_query;
        }

        $found_clause = false;
        foreach ($tax_query as $index => $clause) {
            if (!is_array($clause) || ($clause['taxonomy'] ?? '') !== 'product_visibility') {
                continue;
            }

            $terms = array_map('absint', (array) ($clause['terms'] ?? []));
            if ('hide' === $this->stock_filter && 'NOT IN' === strtoupper($clause['operator'] ?? '')) {
                $terms[] = $out_of_stock_term;
                $tax_query[$index]['terms'] = array_values(array_unique($terms));
                $found_clause = true;
            } elseif ('show' === $this->stock_filter && 'NOT IN' === strtoupper($clause['operator'] ?? '') && in_array($out_of_stock_term, $terms, true)) {
                $tax_query[$index]['terms'] = array_values(array_diff($terms, [$out_of_stock_term]));
                if (empty($tax_query[$index]['terms'])) {
                    unset($tax_query[$index]);
                }
                $found_clause = true;
            }
        }

        if ('hide' === $this->stock_filter && !$found_clause) {
            $tax_query[] = [
                'taxonomy' => 'product_visibility',
                'field' => 'term_taxonomy_id',
                'terms' => [$out_of_stock_term],
                'operator' => 'NOT IN',
            ];
        }

        return $tax_query;
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
        echo wp_kses_post(apply_filters(
            'conobe_woocommerce_customers_also_viewed_before_main_wrapper',
            '<div class="woocommerce-customers-also-viewed">'
        ));
        echo wp_kses_post((string) $this->productLoop());
        echo wp_kses_post(apply_filters(
            'conobe_woocommerce_customers_also_viewed_before_main_wrapper',
            '</div>'
        ));
    }

    /**
     * Creates the admin control panel
     *
     * @return void
     */
    public function controlPanel()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'customers-who-viewed-this-also-viewed-that-woocommerce'));
        }

        $this->saveOptions();

        $diagnostic_product_id = 0;
        // The read-only value is nonce-verified before it is unslashed or used.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['diagnostic_product_id'])) {
            check_admin_referer('conobe_product_diagnostic');
            $diagnostic_request = wp_unslash($_GET['diagnostic_product_id']);
            if (is_scalar($diagnostic_request)) {
                $diagnostic_product_id = absint($diagnostic_request);
            }
        }

        echo '<div id="conobe" class="wrap">';
        echo '<header class="cav-header">';
        echo '<h1>Customers Also Viewed</h1>';
        echo '<p>Choose where recommendations appear and how they are presented.</p>';
        echo '</header>';

        // This value only controls a status notice; it does not trigger an action.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['settings-updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'customers-who-viewed-this-also-viewed-that-woocommerce') . '</p></div>';
        }
        // This value only controls a status notice; the destructive request has its own verified nonce.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['clear_tracking'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Product tracking data cleared.', 'customers-who-viewed-this-also-viewed-that-woocommerce') . '</p></div>';
        }

        echo '<div class="cav-admin-layout">';
        echo '<form class="cav-settings" method="post">';
        wp_nonce_field('conobe_save_options');

        echo '<section class="cav-section">';
        echo '<h2>Recommendations</h2>';
        echo '<div class="cav-field">';
        echo '<label for="conobe_h2_title">Section title</label>';
        echo '<p class="description">Shown above the recommended products.</p>';
        echo '<input class="regular-text" type="text" id="conobe_h2_title" name="conobe_h2_title" value="' . esc_attr($this->h2_title) . '">';
        echo '</div>';
        echo '<div class="cav-field">';
        echo '<label for="conobe_products_per_page">Products to show</label>';
        echo '<p class="description">Choose between 1 and 50 products.</p>';
        echo '<input type="number" id="conobe_products_per_page" name="conobe_products_per_page" min="1" max="50" value="' . absint($this->products_per_page) . '">';
        echo '</div>';
        echo '<div class="cav-field">';
        echo '<label for="conobe_minimum_views">Minimum co-views</label>';
        echo '<p class="description">Only recommend products seen together at least this many times.</p>';
        echo '<input type="number" id="conobe_minimum_views" name="conobe_minimum_views" min="1" max="100000" value="' . absint($this->minimum_views) . '">';
        echo '</div>';
        echo '<div class="cav-field">';
        echo '<label for="conobe_hide_when_no_products">When no recommendations are available</label>';
        echo '<select id="conobe_hide_when_no_products" name="conobe_hide_when_no_products">';
        echo '<option value="false" ' . selected($this->hide_when_no_products, false, false) . '>Show a message</option>';
        echo '<option value="true" ' . selected($this->hide_when_no_products, true, false) . '>Hide the section</option>';
        echo '</select>';
        echo '</div>';
        echo '<div class="cav-field">';
        echo '<label for="conobe_no_product_text">No recommendations message</label>';
        echo '<p class="description">Displayed when the section is visible but has no matching products.</p>';
        echo '<input class="regular-text" type="text" id="conobe_no_product_text" name="conobe_no_product_text" value="' . esc_attr($this->no_product_text) . '">';
        echo '</div>';
        echo '</section>';

        echo '<section class="cav-section">';
        echo '<h2>Placement</h2>';
        echo '<div class="cav-field">';
        echo '<label for="conobe_location">Automatic placement</label>';
        echo '<p class="description">Available locations depend on your theme’s WooCommerce templates.</p>';
        echo '<select name="conobe_location" id="conobe_location">';

        foreach ($this->locationHookOptions() as $hook => $text) {
            echo '<option value="' . esc_attr($hook) . '" ' . selected($this->location, $hook, false) . '>' . esc_html($text) . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '<div class="cav-field">';
        echo '<label for="conobe_use_tab">Product information tab</label>';
        echo '<select name="conobe_use_tab" id="conobe_use_tab">';
        echo '<option value="true" ' . selected($this->use_tab, true, false) . '>Show tab</option>';
        echo '<option value="false" ' . selected($this->use_tab, false, false) . '>Hide tab</option>';
        echo '</select>';
        echo '</div>';
        echo '<div class="cav-field">';
        echo '<label for="conobe_tab_title">Tab name</label>';
        echo '<input class="regular-text" type="text" id="conobe_tab_title" name="conobe_tab_title" value="' . esc_attr($this->tab_title) . '">';
        echo '</div>';
        echo '<div class="cav-field">';
        echo '<label for="conobe_stock_filter">Out-of-stock products</label>';
        echo '<select name="conobe_stock_filter" id="conobe_stock_filter">';
        echo '<option value="woocommerce" ' . selected($this->stock_filter, 'woocommerce', false) . '>Use WooCommerce catalog settings</option>';
        echo '<option value="hide" ' . selected($this->stock_filter, 'hide', false) . '>Always hide</option>';
        echo '<option value="show" ' . selected($this->stock_filter, 'show', false) . '>Allow in recommendations</option>';
        echo '</select>';
        echo '</div>';
        echo '<div class="cav-field">';
        echo '<label for="conobe_excluded_categories">Exclude product categories</label>';
        echo '<p class="description">Hold Command (Mac) or Control (Windows) to select multiple categories.</p>';
        echo '<select multiple size="6" id="conobe_excluded_categories" name="conobe_excluded_categories[]">';
        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        if (!is_wp_error($categories)) {
            foreach ($categories as $category) {
                $category_id = absint($category->term_id);
                echo '<option value="' . esc_attr($category_id) . '" ' . selected(in_array($category_id, $this->excluded_category_ids, true), true, false) . '>' . esc_html($category->name) . '</option>';
            }
        }
        echo '</select>';
        echo '</div>';
        echo '<div class="cav-field">';
        echo '<label for="conobe_excluded_products">Exclude product IDs</label>';
        echo '<p class="description">Enter product IDs separated by commas.</p>';
        echo '<input class="regular-text" type="text" id="conobe_excluded_products" name="conobe_excluded_products" value="' . esc_attr(implode(', ', $this->excluded_product_ids)) . '">';
        echo '</div>';
        echo '</section>';

        echo '<section class="cav-section cav-privacy">';
        echo '<h2>Privacy and retention</h2>';
        echo '<div class="cav-field">';
        echo '<label for="conobe_retention_days">Keep view data for</label>';
        echo '<p class="description">Set to 0 to retain aggregate view counts indefinitely.</p>';
        echo '<input type="number" id="conobe_retention_days" name="conobe_retention_days" min="0" max="3650" value="' . absint($this->retention_days) . '">';
        echo '<span>days</span>';
        echo '</div>';
        echo '<div class="cav-field">';
        echo '<label for="conobe_require_consent">Require statistics consent</label>';
        echo '<select id="conobe_require_consent" name="conobe_require_consent">';
        echo '<option value="false" ' . selected($this->require_consent, false, false) . '>No, track as configured</option>';
        echo '<option value="true" ' . selected($this->require_consent, true, false) . '>Yes, require consent</option>';
        echo '</select>';
        echo '<p class="description">When enabled, tracking requires a consent manager that provides <code>wp_has_consent(\'statistics\')</code>.</p>';
        echo '</div>';
        echo '</section>';

        echo '<div class="cav-save-row">';
        submit_button(__('Save changes', 'customers-who-viewed-this-also-viewed-that-woocommerce'), 'primary', 'submit', false);
        echo '</div>';
        echo '</form>';

        echo '<aside class="cav-tools">';
        echo '<section class="cav-section cav-shortcode">';
        echo '<h2>Shortcode</h2>';
        echo '<p class="description">Place recommendations in your product content or a shortcode block.</p>';
        echo '<div class="cav-copy-row">';
        echo '<input type="text" id="conobe_shortcode" value="[' . esc_attr($this->shortcode_id) . ']" readonly aria-label="Shortcode" data-copy-shortcode-field>';
        echo '<button type="button" class="button" data-copy-shortcode>Copy</button>';
        echo '</div>';
        echo '<p class="cav-copy-status" aria-live="polite" data-copy-shortcode-status></p>';
        echo '</section>';

        $diagnostics = $this->getTrackingDiagnostics();
        echo '<section class="cav-section cav-status">';
        echo '<h2>Tracking status</h2>';
        echo '<p><strong>Storage:</strong> ' . esc_html($diagnostics['storage']) . '</p>';
        echo '<p><strong>Recommendation pairs:</strong> ' . esc_html(number_format_i18n($diagnostics['pairs'])) . '</p>';
        echo '<p><strong>Legacy migration:</strong> ' . esc_html($diagnostics['migration']) . '</p>';
        echo '<p><strong>Last cleanup:</strong> ' . esc_html($diagnostics['last_cleanup']) . '</p>';
        if ($this->require_consent && !function_exists('wp_has_consent')) {
            echo '<p class="cav-status-warning">Tracking is paused until a compatible consent manager is active.</p>';
        } elseif (0 === $diagnostics['pairs']) {
            echo '<p class="description">No product pairs have been recorded yet. Visit product pages after tracking is enabled.</p>';
        }
        echo '</section>';

        echo '<section class="cav-section cav-product-diagnostic">';
        echo '<h2>Product diagnostic</h2>';
        echo '<p class="description">Check tracked co-view data for a specific product before catalog and exclusion filters are applied.</p>';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
        wp_nonce_field('conobe_product_diagnostic', '_wpnonce', false);
        echo '<input type="hidden" name="page" value="conobe-customers-also-viewed">';
        echo '<label for="conobe_diagnostic_product_id">Product ID</label>';
        echo '<div class="cav-copy-row">';
        echo '<input type="number" min="1" id="conobe_diagnostic_product_id" name="diagnostic_product_id" value="' . esc_attr($diagnostic_product_id ?: '') . '">';
        submit_button(__('Check product', 'customers-who-viewed-this-also-viewed-that-woocommerce'), 'secondary', 'check_product', false);
        echo '</div>';
        echo '</form>';

        if ($diagnostic_product_id) {
            if ('product' === get_post_type($diagnostic_product_id)) {
                global $wpdb;
                $diagnostic_cache_key = 'conobe_product_pair_count_' . md5(implode(':', [
                    $diagnostic_product_id,
                    $this->minimum_views,
                    $this->retention_days,
                ]));
                $product_pair_count = get_transient($diagnostic_cache_key);
                if (false === $product_pair_count) {
                    $diagnostic_sql = 'SELECT COUNT(*) FROM %i WHERE product_id = %d AND view_count >= %d';
                    $diagnostic_values = [$this->tracking_table, $diagnostic_product_id, $this->minimum_views];
                    if ($this->retention_days > 0) {
                        $diagnostic_sql .= ' AND last_viewed >= %s';
                        $diagnostic_values[] = gmdate('Y-m-d H:i:s', time() - ($this->retention_days * DAY_IN_SECONDS));
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This custom-table count is cached for five minutes.
                    $product_pair_count = absint($wpdb->get_var($wpdb->prepare($diagnostic_sql, $diagnostic_values)));
                    set_transient($diagnostic_cache_key, $product_pair_count, 5 * MINUTE_IN_SECONDS);
                }
                if ($product_pair_count) {
                    $count_message = sprintf(
                        // translators: %s is the number of tracked product pairs.
                        _n('%s tracked product pair meets the threshold.', '%s tracked product pairs meet the threshold.', $product_pair_count, 'customers-who-viewed-this-also-viewed-that-woocommerce'),
                        number_format_i18n($product_pair_count)
                    );
                    echo '<p>' . esc_html($count_message) . ' <a href="' . esc_url(get_permalink($diagnostic_product_id)) . '">' . esc_html__('View product', 'customers-who-viewed-this-also-viewed-that-woocommerce') . '</a></p>';
                } else {
                    echo '<p class="cav-status-warning">' . esc_html__('No recent co-view pairs meet the minimum threshold for this product.', 'customers-who-viewed-this-also-viewed-that-woocommerce') . '</p>';
                }
            } else {
                echo '<p class="cav-status-warning">' . esc_html__('That ID is not a product.', 'customers-who-viewed-this-also-viewed-that-woocommerce') . '</p>';
            }
        }
        echo '</section>';

        echo '<section class="cav-section cav-clear-tracking">';
        echo '<h2>Tracking data</h2>';
        echo '<p class="description">Clear the collected view history and restart recommendation data.</p>';
        $clear_url = add_query_arg('action', 'clear_product_tracking', admin_url('admin-ajax.php'));
        $clear_url = add_query_arg('redirect', admin_url('admin.php?page=conobe-customers-also-viewed'), $clear_url);
        echo '<a class="button" href="' . esc_url(wp_nonce_url($clear_url, 'conobe_clear_tracking')) . '" onclick="return confirm(\'Clear all product tracking data? This cannot be undone.\')">Clear tracking data</a>';
        echo '</section>';
        echo '</aside>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Load assets only on this plugin's settings page.
     *
     * @param string $hook_suffix Current admin page hook.
     * @return void
     */
    public function enqueueAdminAssets($hook_suffix)
    {
        if ('woocommerce_page_conobe-customers-also-viewed' !== $hook_suffix) {
            return;
        }

        wp_enqueue_style('conobe-also-viewed-admin', plugins_url('../css/admin.css', __FILE__), [], '1.3.0');
        wp_enqueue_script('conobe-also-viewed-admin', plugins_url('../js/admin.js', __FILE__), [], '1.3.0', true);
    }

    /**
     * Return inexpensive, cached tracking diagnostics for the settings page.
     *
     * @return array
     */
    private function getTrackingDiagnostics()
    {
        global $wpdb;

        $storage_ready = get_option('conobe_also_viewed_db_version') === '1';
        $pair_count = get_transient('conobe_also_viewed_pair_count');
        if (false === $pair_count) {
            if ($storage_ready) {
                // This indexed custom-table count is cached below; WordPress metadata APIs cannot provide it.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $pair_count = absint($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $this->tracking_table)));
            } else {
                $pair_count = 0;
            }
            set_transient('conobe_also_viewed_pair_count', $pair_count, 5 * MINUTE_IN_SECONDS);
        }

        if (get_option('conobe_also_viewed_migration_complete')) {
            $migration_status = 'Complete';
        } elseif (get_option('conobe_also_viewed_migration_cursor')) {
            $migration_status = 'In progress';
        } else {
            $migration_status = 'Pending';
        }

        $last_cleanup = get_option('conobe_also_viewed_last_cleanup');

        return [
            'storage' => $storage_ready ? 'Ready' : 'Not initialized',
            'pairs' => $pair_count,
            'migration' => $migration_status,
            'last_cleanup' => $last_cleanup ? get_date_from_gmt($last_cleanup, 'Y-m-d H:i') : 'Not run yet',
        ];
    }

    /**
     * Save the POSTed plugin options
     *
     * @return void
     */
    public function saveOptions()
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || 'POST' !== $_SERVER['REQUEST_METHOD']) {
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to update these settings.', 'customers-who-viewed-this-also-viewed-that-woocommerce'));
        }
        check_admin_referer('conobe_save_options');
        $posted = wp_unslash($_POST);
        if (!isset($posted['conobe_location'])) {
            return;
        }
        $text_value = static function ($key, $default = '') use ($posted) {
            return isset($posted[$key]) && is_scalar($posted[$key])
                ? sanitize_text_field((string) $posted[$key])
                : $default;
        };
        $integer_value = static function ($key, $default) use ($posted) {
            return isset($posted[$key]) && is_scalar($posted[$key])
                ? absint($posted[$key])
                : absint($default);
        };

        $new_options = [
            'h2_title' => $text_value('conobe_h2_title', $this->h2_title),
            'products_per_page' => min(50, max(1, $integer_value('conobe_products_per_page', $this->products_per_page))),
            'minimum_views' => min(100000, max(1, $integer_value('conobe_minimum_views', $this->minimum_views))),
            'use_tab' => $text_value('conobe_use_tab') === 'true',
            'tab_title' => $text_value('conobe_tab_title', $this->tab_title),
            'hide_when_no_products' => $text_value('conobe_hide_when_no_products') === 'true',
            'no_product_text' => $text_value('conobe_no_product_text', $this->no_product_text),
            'retention_days' => min(3650, $integer_value('conobe_retention_days', $this->retention_days)),
            'require_consent' => $text_value('conobe_require_consent') === 'true',
        ];

        $location = sanitize_key($text_value('conobe_location', $this->location));
        $new_options['location'] = $location;

        $stock_filter = sanitize_key($text_value('conobe_stock_filter', $this->stock_filter));
        $new_options['stock_filter'] = in_array($stock_filter, ['woocommerce', 'hide', 'show'], true)
            ? $stock_filter
            : 'woocommerce';

        $category_ids = isset($posted['conobe_excluded_categories']) && is_array($posted['conobe_excluded_categories'])
            ? array_map('absint', array_filter($posted['conobe_excluded_categories'], 'is_scalar'))
            : [];
        $new_options['excluded_category_ids'] = array_values(array_unique(array_filter($category_ids)));

        $excluded_products = preg_split('/[,\s]+/', $text_value('conobe_excluded_products'));
        $new_options['excluded_product_ids'] = array_values(array_unique(array_filter(array_map('absint', $excluded_products))));

        $allowed_locations = array_keys($this->locationHookOptions());
        if (!in_array($new_options['location'] ?? '', $allowed_locations, true)) {
            $new_options['location'] = 'woocommerce_after_single_product';
        }

        update_option($this->option_name, $new_options);

        wp_safe_redirect(add_query_arg('settings-updated', 'true', admin_url('admin.php?page=conobe-customers-also-viewed')));
        exit;
    }

    /**
     * Return a list of possible hooks => location mapping.
     *
     * @return array
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
