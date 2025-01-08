<?php
/**
 * Plugin Name: WC Bulk Order Generator
 * 
 * @author            devsabbirahmed
 * @copyright         2024- SABBIRSAM
 * @license           GPL-2.0-or-laters
 * @package WC Bulk Order Generator
 * 
 * Plugin URI: https://github.com/sabbirsam/WooCommerce-Bulk-Order-Generator
 * Description: Generates bulk random orders for WooCommerce testing with optimized batch processing
 * Version: 1.0
 * Requires at least: 5.9
 * Requires PHP:      5.6
 * Author: sabbirsam
 * Author URI:        https://profiles.wordpress.org/devsabbirahmed/
 * Text Domain: wc-bulk-order-generator
 * Domain Path: /languages/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

 defined('ABSPATH') || die('Hey, what are you doing here? You silly human!');


// Define plugin constants.
define('WC_BULK_GENERATOR_VERSION', '1.0.0');
define( 'WC_BULK_GENERATOR_PLUGIN_FILE', __FILE__ );
define('WC_BULK_GENERATOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_BULK_GENERATOR_PLUGIN_URL', plugin_dir_url(__FILE__));

// Require dependencies.
if ( file_exists(WC_BULK_GENERATOR_PLUGIN_DIR . 'includes/class-wc-bulk-product-generator.php') ) {
    require_once WC_BULK_GENERATOR_PLUGIN_DIR . 'includes/class-wc-bulk-product-generator.php';
}

/**
 * Class WC_Bulk_Order_Generator
 * 
 * This class handles the bulk generation of WooCommerce orders and products.
 * It provides an admin interface for configuring generation settings and processes orders/products in batches.
 */
class WC_Bulk_Order_Generator {
    /**
     * Default batch size for order generation.
     *
     * @var int
     */
    private $batch_size = 50;
    /**
     * Cache for products during generation.
     *
     * @var array
     */
    private $products_cache = array();
    /**
     * Instance of the product generator class.
     *
     * @var WC_Bulk_Product_Generator
     */
    private $product_generator;

    /**
     * WC_Bulk_Order_Generator constructor.
     * Initializes the plugin by setting up dependencies, hooks, and admin functionality.
     */
    public function __construct() {

        // Initialize product generator.
        $this->init_dependencies();
        // Admin menu and scripts.
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        // AJAX actions.
        add_action('wp_ajax_process_order_batch', array($this, 'process_order_batch'));
        add_action('wp_ajax_stop_order_generation', array($this, 'stop_order_generation'));
         // Register settings.
        add_action('admin_init', array($this, 'register_settings'));
        // Disable emails during order generation.
        add_action('woocommerce_email', array($this, 'disable_emails'));

        add_action('admin_notices', array($this, 'check_woocommerce_status'));

         // Add custom plugin action links (Dashboard link).
         add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_dashboard_link'));

    }


    /**
     * Initializes dependencies for the class, specifically the product generator.
     * Ensures the required file is included and the product generator instance is created.
     */
    private function init_dependencies() {
        if (!class_exists('WC_Bulk_Product_Generator')) {
            if (!file_exists(WC_BULK_GENERATOR_PLUGIN_DIR . 'includes/class-wc-bulk-product-generator.php')) {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error"><p>' . 
                         esc_html__('Bulk Order Generator: Required file "class-wc-bulk-product-generator.php" is missing.', 'wc-bulk-order-generator') . 
                         '</p></div>';
                });
                return;
            }
            require_once WC_BULK_GENERATOR_PLUGIN_DIR . 'includes/class-wc-bulk-product-generator.php';
        }

        $this->product_generator = new WC_Bulk_Product_Generator();
    }

    /**
     * Add the "Dashboard" link next to the "Deactivate" button on the plugin page.
     *
     * @param array $links The existing action links.
     * @return array Modified action links.
     */
    public function add_dashboard_link($links) {
        $dashboard_link = array(
            'dashboard' => '<a href="' . admin_url('admin.php?page=wc-order-generator') . '">' . esc_html__('Generator', 'wc-bulk-order-generator') . '</a>',
        );
        return array_merge($dashboard_link, $links);
    }

    /**
     * Registers plugin settings for the WC Bulk Order Generator.
     * 
     * This function registers the plugin's settings using the WordPress Settings API.
     * It defines default values for all settings and ensures they are saved in the WordPress options table.
     * The settings are sanitized before being saved using the provided callback function.
     * 
     * @return void
     */
    public function register_settings() {
        // Register the settings for the WC Bulk Generator plugin.
        register_setting('wc_bulk_generator', 'wc_bulk_generator_settings', array(
            'sanitize_callback' => array($this, 'sanitize_settings')
        ));

         // Set the default values for all settings.
        $defaults = array(
            'batch_size' => 50,
            'max_orders' => 1000000,
            'date_range' => 90,
            'products_per_order' => 5,
            'product_batch_size' => 20,
            'max_products' => 1000      
        );

        $existing_settings = get_option('wc_bulk_generator_settings', array());
        $merged_settings = wp_parse_args($existing_settings, $defaults);

        update_option('wc_bulk_generator_settings', $merged_settings);
    }

    /**
     * Sanitizes the settings values before saving them to the database.
     * 
     * This function ensures that all settings are sanitized properly, converting them to integer values 
     * if they are set, or using default values if not. This is to prevent any invalid or malicious data 
     * from being stored.
     * 
     * @param array $settings The settings to be sanitized.
     * 
     * @return array The sanitized settings with default values applied where necessary.
     */
    public function sanitize_settings($settings) {
        return array(
            'batch_size' => isset($settings['batch_size']) ? absint($settings['batch_size']) : 50,
            'max_orders' => isset($settings['max_orders']) ? absint($settings['max_orders']) : 1000000,
            'date_range' => isset($settings['date_range']) ? absint($settings['date_range']) : 90,
            'products_per_order' => isset($settings['products_per_order']) ? absint($settings['products_per_order']) : 5,
            'product_batch_size' => isset($settings['product_batch_size']) ? absint($settings['product_batch_size']) : 20,
            'max_products' => isset($settings['max_products']) ? absint($settings['max_products']) : 1000,
        );
    }

    /**
     * Disables email notifications for specific WooCommerce order statuses.
     * 
     * This function removes the default WooCommerce email notifications 
     * that are triggered when an order status changes. This is useful when 
     * using the bulk order generator to avoid sending emails for bulk-created orders.
     * 
     * @param object $email_class The WooCommerce email class instance.
     * 
     * @return void
     */
    public function disable_emails($email_class) {
        remove_all_actions('woocommerce_order_status_pending_to_processing_notification');
        remove_all_actions('woocommerce_order_status_pending_to_completed_notification');
        remove_all_actions('woocommerce_order_status_processing_to_completed_notification');
        remove_all_actions('woocommerce_new_order_notification');
    }

    /**
     * Check if WooCommerce is active or installed and show an admin notice if it's not.
     */
    public function check_woocommerce_status() {
        // Check if WooCommerce is active.
        if (!is_plugin_active('woocommerce/woocommerce.php')) {
            ?>
            <div class="notice notice-error is-dismissible">
                <p>
                    <?php 
                    printf(
                        __('WooCommerce is not installed or active. <strong>%s</strong> may not work correctly.', 'wc-bulk-order-generator'),
                        'WC Bulk Order Generator'
                    );
                    ?>
                    <a href="<?php echo esc_url(admin_url('plugin-install.php?s=woocommerce&tab=search&type=term')); ?>" class="button button-primary" style="margin-left: 10px;">
                        <?php _e('Install WooCommerce', 'wc-bulk-order-generator'); ?>
                    </a>
                </p>
            </div>
            <?php
        }
    }
    
    /**
     * Enqueues styles and scripts for the bulk order generator page in the WooCommerce admin.
     * 
     * This function loads the necessary CSS and JavaScript files for the bulk order generator page. It 
     * also localizes settings and nonce values to be used in the JavaScript for AJAX requests.
     * 
     * @param string $hook The current admin page hook.
     * 
     * @return void
     */
    public function enqueue_scripts($hook) {
        if ('woocommerce_page_wc-order-generator' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'wc-order-generator', 
            WC_BULK_GENERATOR_PLUGIN_URL . 'css/generator.css',
            array(),
            WC_BULK_GENERATOR_VERSION
        );
        
        wp_enqueue_script(
            'wc-order-generator',
            WC_BULK_GENERATOR_PLUGIN_URL . 'js/generator.js',
            array('jquery'),
            WC_BULK_GENERATOR_VERSION,
            true
        );
        
        $settings = get_option('wc_bulk_generator_settings', array());
        
        // Ensure all required keys exist with defaults
        $settings = wp_parse_args($settings, array(
            'batch_size' => 50,
            'max_orders' => 1000000,
            'product_batch_size' => 20,
            'max_products' => 1000
        ));

        wp_localize_script('wc-order-generator', 'wcOrderGenerator', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('generate_orders_nonce'),
            'products_nonce' => wp_create_nonce('generate_products_nonce'),
            'batch_size' => $settings['batch_size'],
            'max_orders' => $settings['max_orders'],
            'product_batch_size' => $settings['product_batch_size'],
            'max_products' => $settings['max_products']
        ));
    }

    /**
     * Adds a submenu page for the Bulk Order Generator under the WooCommerce menu.
     * 
     * This function adds the "Bulk Generator" page to the WooCommerce menu, allowing administrators 
     * to access the bulk order generation interface from the WordPress admin dashboard.
     * 
     * @return void
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            esc_html__('Bulk Generator', 'wc-bulk-order-generator'),
            esc_html__('Bulk Generator', 'wc-bulk-order-generator'),
            'manage_woocommerce',
            'wc-order-generator',
            array($this, 'admin_page')
        );
    }

    /**
     * Renders the Bulk Order Generator admin page.
     * 
     * This method generates the HTML for the "Bulk Order Generator" page within the WordPress admin dashboard.
     * It fetches the plugin settings, sanitizes them, and then outputs a form or user interface for administrators 
     * to configure the bulk order generation settings such as maximum orders and batch size.
     * 
     * This page allows administrators to access the interface for generating orders and products in bulk for testing 
     * or other use cases. The page includes a header, description, and forms to configure settings.
     * 
     * @return void
     * 
     * @since 1.0.0
     */
    public function admin_page() {
        // Get settings and sanitize them
        $settings = get_option('wc_bulk_generator_settings');
        $max_orders = isset($settings['max_orders']) ? intval($settings['max_orders']) : 100;
        $batch_size = isset($settings['batch_size']) ? intval($settings['batch_size']) : 50;
    
        ?>
        <div class="wrap wc-bulk-order-generator-wrap">
            <div class="wc-bulk-order-generator-header">
                <h1><?php esc_html_e('WC Bulk Generator', 'wc-bulk-order-generator'); ?></h1>
                <p class="description"><?php esc_html_e('Generate test orders and products in batches with monitoring data.', 'wc-bulk-order-generator'); ?></p>
            </div>
    
            <div class="wc-tabs-wrapper">
                <nav class="nav-tab-wrapper">
                    <a href="#products" class="nav-tab nav-tab-active"><?php esc_html_e('Products', 'wc-bulk-order-generator'); ?></a>
                    <a href="#orders" class="nav-tab"><?php esc_html_e('Orders', 'wc-bulk-order-generator'); ?></a>
                    <a href="#debug" class="nav-tab"><?php esc_html_e('About Me', 'wc-bulk-order-generator'); ?></a>
                </nav>
    
                <!-- Orders Tab -->
                <div id="orders" class="tab-content">
                    <form id="order-generator-form" method="post">
                        <div class="settings-grid">
                            <div class="setting-card">
                                <label for="num_orders"><?php esc_html_e('Number of Orders', 'wc-bulk-order-generator'); ?></label>
                                <input type="number" id="num_orders" name="num_orders" 
                                       value="100" 
                                       min="1" 
                                       max="<?php echo esc_attr($max_orders); ?>">
                                <p class="description">
                                    <?php 
                                    printf(
                                        esc_html__('Generate between 1 and %s orders', 'wc-bulk-order-generator'), 
                                        number_format_i18n($max_orders)
                                    ); 
                                    ?>
                                </p>
                            </div>
    
                            <div class="setting-card">
                                <label for="batch_size"><?php esc_html_e('Batch Size', 'wc-bulk-order-generator'); ?></label>
                                <input type="number" id="batch_size" name="batch_size" 
                                       value="<?php echo esc_attr($batch_size); ?>" 
                                       min="10" 
                                       max="100">
                                <p class="description">
                                    <?php esc_html_e('Orders to process per batch (10-100)', 'wc-bulk-order-generator'); ?>
                                </p>
                            </div>
                        </div>
    
                        <div class="progress-wrapper">
                            <div class="progress-bar" style="width: 0%"></div>
                        </div>
    
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-value" id="total-processed"><?php esc_html_e('0', 'wc-bulk-order-generator'); ?></div>
                                <div class="stat-label"><?php esc_html_e('Total Processed', 'wc-bulk-order-generator'); ?></div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value" id="success-count"><?php esc_html_e('0', 'wc-bulk-order-generator'); ?></div>
                                <div class="stat-label"><?php esc_html_e('Successful', 'wc-bulk-order-generator'); ?></div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value" id="failed-count"><?php esc_html_e('0', 'wc-bulk-order-generator'); ?></div>
                                <div class="stat-label"><?php esc_html_e('Failed', 'wc-bulk-order-generator'); ?></div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value" id="processing-rate"><?php esc_html_e('0', 'wc-bulk-order-generator'); ?></div>
                                <div class="stat-label"><?php esc_html_e('Orders/Second', 'wc-bulk-order-generator'); ?></div>
                            </div>
                        </div>
    
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-value" id="elapsed-time"><?php esc_html_e('0s', 'wc-bulk-order-generator'); ?></div>
                                <div class="stat-label"><?php esc_html_e('Elapsed Time', 'wc-bulk-order-generator'); ?></div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value" id="time-remaining"><?php esc_html_e('--', 'wc-bulk-order-generator'); ?></div>
                                <div class="stat-label"><?php esc_html_e('Estimated Time Remaining', 'wc-bulk-order-generator'); ?></div>
                            </div>
                        </div>
    
                        <div id="generation-status" class="notice notice-info" style="display: none;"></div>
    
                        <div class="control-buttons">
                            <input type="submit" id="start-generation" class="button button-primary" value="<?php esc_attr_e('Generate Orders', 'wc-bulk-order-generator'); ?>">
                            <button type="button" id="stop-generation" class="button" disabled><?php esc_html_e('Stop Generation', 'wc-bulk-order-generator'); ?></button>
                            <button type="button" id="reset-generation" class="button button-secondary"><?php esc_html_e('Reset', 'wc-bulk-order-generator'); ?></button>
                        </div>
                    </form>
                </div>
    
                <!-- Products Tab -->
                <div id="products" class="tab-content active">
                    <form id="product-generator-form" method="post">
                        <div class="settings-grid">
                            <div class="setting-card">
                                <label for="num_products"><?php esc_html_e('Number of Products', 'wc-bulk-order-generator'); ?></label>
                                <input type="number" id="num_products" name="num_products" 
                                       value="50" 
                                       min="1" 
                                       max="1000">
                                <p class="description">
                                    <?php esc_html_e('Generate between 1 and 1,000 products', 'wc-bulk-order-generator'); ?>
                                </p>
                            </div>
    
                            <div class="setting-card">
                                <label for="product_batch_size"><?php esc_html_e('Batch Size', 'wc-bulk-order-generator'); ?></label>
                                <input type="number" id="product_batch_size" name="product_batch_size" 
                                       value="20" 
                                       min="5" 
                                       max="50">
                                <p class="description">
                                    <?php esc_html_e('Products to process per batch (5-50)', 'wc-bulk-order-generator'); ?>
                                </p>
                            </div>
                        </div>
    
                        <div class="progress-wrapper">
                            <div class="product-progress-bar" style="width: 0%"></div>
                        </div>
    
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-value" id="products-processed"><?php esc_html_e('0', 'wc-bulk-order-generator'); ?></div>
                                <div class="stat-label"><?php esc_html_e('Products Created', 'wc-bulk-order-generator'); ?></div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value" id="products-failed"><?php esc_html_e('0', 'wc-bulk-order-generator'); ?></div>
                                <div class="stat-label"><?php esc_html_e('Failed', 'wc-bulk-order-generator'); ?></div>
                            </div>
                        </div>
    
                        <div id="product-generation-status" class="notice notice-info" style="display: none;"></div>
    
                        <div class="control-buttons">
                            <input type="submit" id="start-product-generation" class="button button-primary" value="<?php esc_attr_e('Generate Products', 'wc-bulk-order-generator'); ?>">
                            <button type="button" id="stop-product-generation" class="button" disabled><?php esc_html_e('Stop Generation', 'wc-bulk-order-generator'); ?></button>
                            <button type="button" id="reset-product-generation" class="button button-secondary"><?php esc_html_e('Reset', 'wc-bulk-order-generator'); ?></button>
                        </div>
                    </form>
                </div>
    
                <!-- Debug Tab -->
                <div id="debug" class="tab-content">
                    <div class="debug-info">
                        <h2><?php esc_html_e('WooCommerce Bulk Order Generator', 'wc-bulk-order-generator'); ?></h2>
                        <p><?php esc_html_e('Generates bulk random orders/products for WooCommerce testing with optimized batch processing', 'wc-bulk-order-generator'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    

    /**
     * Caches the products from the WooCommerce store.
     * 
     * This method checks if the products are already cached. If not, it retrieves all published products 
     * from the store using `wc_get_products()` and stores them in a class variable `$products_cache`. 
     * This helps in optimizing performance when accessing products multiple times.
     * 
     * @return array The list of cached or retrieved WooCommerce products.
     * 
     * @since 1.0.0
     */
    private function cache_products() {
        if (empty($this->products_cache)) {
            $this->products_cache = wc_get_products(array(
                'status' => 'publish',
                'limit' => -1
            ));
        }
        return $this->products_cache;
    }

    /**
     * Processes a batch of orders for generation.
     * 
     * This method is responsible for generating a batch of orders, creating orders with random products,
     * random shipping, and customer details. It processes multiple orders at once in a batch to improve performance 
     * when creating test orders. The method handles order creation, error logging, and transaction management.
     * 
     * It uses AJAX to handle requests from the admin interface, ensures the current user has the necessary permissions, 
     * and sends the generated orders to the database.
     * 
     * @return void JSON response with success or error data.
     * 
     * @since 1.0.0
     */
    public function process_order_batch() {
        check_ajax_referer('generate_orders_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $this->disable_emails(null);
        
        set_time_limit(300);
        ini_set('memory_limit', '512M');
    
        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 50;
        if ($batch_size < 1 || $batch_size > 100) {
            $batch_size = 50;
        }
        
        $success_count = 0;
        $failed_count = 0;
    
        try {
            $available_products = $this->cache_products();
    
            if (empty($available_products)) {
                wp_send_json_error('No products found');
                return;
            }

            $settings = get_option('wc_bulk_generator_settings');
            $max_products_per_order = absint($settings['products_per_order']);
            $date_range = absint($settings['date_range']);
    
            $country_codes = array('US', 'GB', 'CA', 'AU');
            $states = array(
                'US' => array('NY', 'CA', 'TX', 'FL', 'IL'),
                'GB' => array('LND', 'BKM', 'ESX', 'KNT'),
                'CA' => array('ON', 'BC', 'QC', 'AB'),
                'AU' => array('NSW', 'VIC', 'QLD', 'WA')
            );
            
            global $wpdb;
            $wpdb->query('START TRANSACTION');
    
            for ($i = 0; $i < $batch_size; $i++) {
                try {
                    $order = wc_create_order();
                    
                    $num_products = rand(1, max(1, $max_products_per_order));
                    $product_keys = array_rand($available_products, $num_products);
                    if (!is_array($product_keys)) {
                        $product_keys = array($product_keys);
                    }
    
                    $order_total = 0.0;
                    foreach ($product_keys as $key) {
                        $product = $available_products[$key];
                        $quantity = rand(1, 5);
                        $price = (float)$product->get_price();
                        
                        if ($price > 0) {
                            $order->add_product($product, $quantity);
                            $order_total += ($price * $quantity);
                        }
                    }
    
                    $country = $country_codes[array_rand($country_codes)];
                    $state = $states[$country][array_rand($states[$country])];
    
                    $this->set_customer_details($order, $country, $state);
    
                    $date = date('Y-m-d H:i:s', strtotime('-' . rand(0, $date_range) . ' days'));
                    $order->set_date_created($date);
    
                    $this->set_order_status($order, $date);
    
                    if ($order_total > 0) {
                        $this->add_shipping_and_tax($order, $order_total, $country);
                    }
    
                    $payment_methods = array('bacs', 'cheque', 'cod', 'paypal');
                    $order->set_payment_method($payment_methods[array_rand($payment_methods)]);
    
                    $order->calculate_totals();
                    $order->save();
    
                    $success_count++;
    
                } catch (Exception $e) {
                    error_log('Order generation error: ' . $e->getMessage());
                    $failed_count++;
                }
            }
    
            $wpdb->query('COMMIT');
    
            wp_send_json_success(array(
                'success' => $success_count,
                'failed' => $failed_count
            ));
    
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Sets the customer details for an order.
     * 
     * This method generates random customer details (first name, last name, address, email, etc.) 
     * and sets them on the order for both billing and shipping. It ensures that every generated order 
     * has unique and realistic-looking customer data.
     * 
     * @param WC_Order $order The WooCommerce order object to populate with customer data.
     * @param string $country The country code (e.g., 'US', 'GB') for setting the customer's location.
     * @param string $state The state code (e.g., 'CA', 'TX') for setting the customer's location.
     * 
     * @return void
     * 
     * @since 1.0.0
     */
    private function set_customer_details($order, $country, $state) {
        $first_names = array('John', 'Jane', 'Michael', 'Sarah', 'David', 'Emma');
        $last_names = array('Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia');
        
        $first_name = $first_names[array_rand($first_names)];
        $last_name = $last_names[array_rand($last_names)];
        $address = rand(100, 9999) . ' ' . array_rand(array('Main St' => 1, 'Oak Ave' => 1, 'Market St' => 1));
        $city = array_rand(array('New York' => 1, 'Los Angeles' => 1, 'Chicago' => 1, 'Houston' => 1));
        $postcode = sprintf('%05d', rand(10000, 99999));
        
        $address_data = array(
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'address_1'  => $address,
            'address_2'  => rand(0, 1) ? 'Apt ' . rand(100, 999) : '',
            'city'       => $city,
            'state'      => $state,
            'postcode'   => $postcode,
            'country'    => $country
        );

        $billing_data = array_merge($address_data, array(
            'email' => strtolower($first_name . '.' . $last_name . rand(100, 999) . '@example.com'),
            'phone' => sprintf('(%d) %d-%d', rand(200, 999), rand(200, 999), rand(1000, 9999))
        ));

        foreach ($billing_data as $key => $value) {
            $method = "set_billing_{$key}";
            if (method_exists($order, $method)) {
                $order->$method($value);
            }
        }

        foreach ($address_data as $key => $value) {
            $method = "set_shipping_{$key}";
            if (method_exists($order, $method)) {
                $order->$method($value);
            }
        }
    }


    /**
     * Sets the order status based on the age of the order.
     * 
     * This method assigns a status to the given order based on how old it is. Orders that are older than 
     * 7 days have a higher likelihood of being marked as 'completed', while recent orders have a wider 
     * range of statuses (such as 'pending', 'processing', etc.). The status is chosen using weighted probabilities.
     * 
     * @param WC_Order $order The WooCommerce order object to set the status for.
     * @param string $date The date when the order was created, used to calculate the order age.
     * 
     * @return void
     * 
     * @since 1.0.0
     */
    private function set_order_status($order, $date) {
        $days_ago = (time() - strtotime($date)) / (60 * 60 * 24);
        
        if ($days_ago > 7) {
            // Older orders are more likely to be completed
            $statuses = array(
                'completed' => 70,
                'processing' => 20,
                'refunded' => 5,
                'cancelled' => 5
            );
        } else {
            // Recent orders have more varied statuses
            $statuses = array(
                'completed' => 30,
                'processing' => 40,
                'on-hold' => 15,
                'pending' => 15
            );
        }

        // Choose status based on weighted probability
        $rand = rand(1, 100);
        $cumulative = 0;
        foreach ($statuses as $status => $probability) {
            $cumulative += $probability;
            if ($rand <= $cumulative) {
                $order->set_status($status);
                break;
            }
        }
    }

    /**
     * Adds shipping costs and tax to the order.
     * 
     * This method randomly selects a shipping method and calculates a shipping cost based on predefined 
     * ranges. It also calculates and adds tax to the order if the shipping country is one of the supported 
     * countries (US, CA, GB, AU). The tax is calculated as a percentage of the order total.
     * 
     * @param WC_Order $order The WooCommerce order object to which shipping and tax details are added.
     * @param float $order_total The total amount of the order, used to calculate the tax.
     * @param string $country The country code (e.g., 'US', 'CA', 'GB', 'AU') used to determine tax rates.
     * 
     * @return void
     * 
     * @since 1.0.0
     */
    private function add_shipping_and_tax($order, $order_total, $country) {
        $shipping_methods = array(
            'flat_rate' => array(5, 15),
            'free_shipping' => array(0, 0),
            'express' => array(15, 30)
        );
        
        $method = array_rand($shipping_methods);
        $range = $shipping_methods[$method];
        $shipping_cost = (float)rand($range[0], $range[1]);
        
        if ($shipping_cost > 0) {
            $item = new WC_Order_Item_Shipping();
            $item->set_method_title($method);
            $item->set_total($shipping_cost);
            $order->add_item($item);
        }

        if (in_array($country, array('US', 'CA', 'GB', 'AU'))) {
            $tax_rate = (float)rand(5, 20) / 100;
            $tax_total = (float)$order_total * $tax_rate;
            $order->set_cart_tax($tax_total);
        }
    }

    /**
     * Stops the order generation process.
     * 
     * This method handles an AJAX request to stop the generation of orders. It verifies the nonce for security 
     * and sends a success response back to the client. This function is useful for controlling the generation 
     * of orders through AJAX.
     * 
     * @return void
     * 
     * @since 1.0.0
     */
    public function stop_order_generation() {
        check_ajax_referer('generate_orders_nonce', 'nonce');
        wp_send_json_success();
    }
}

/**
 * Initializes the WC Bulk Order Generator plugin.
 * 
 * This function is called when the plugins are loaded in WordPress. It initializes the `WC_Bulk_Order_Generator` 
 * class, which is responsible for generating bulk orders in WooCommerce. The function is hooked to the `plugins_loaded` 
 * action, ensuring that the class is instantiated only after all plugins are loaded.
 * 
 * @return void
 * 
 * @since 1.0.0
 */
function wc_bulk_generator_init() {
    new WC_Bulk_Order_Generator();
}
add_action('plugins_loaded', 'wc_bulk_generator_init');


