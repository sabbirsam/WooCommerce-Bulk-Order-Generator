<?php
/**
 * Plugin Name: WooCommerce Bulk Order Generator
 * Plugin URI: 
 * Description: Generates bulk random orders for WooCommerce testing with optimized batch processing
 * Version: 1.0
 * Author: sabbirsam
 * Text Domain: wc-bulk-order-generator
 * Requires WooCommerce: 1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}


// Define plugin constants
define('WC_BULK_GENERATOR_VERSION', '1.0.0');
define('WC_BULK_GENERATOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_BULK_GENERATOR_PLUGIN_URL', plugin_dir_url(__FILE__));

// Require dependencies
require_once WC_BULK_GENERATOR_PLUGIN_DIR . 'includes/class-wc-bulk-product-generator.php';


class WC_Bulk_Order_Generator {
    private $batch_size = 50;
    private $products_cache = array();
    private $product_generator;
    
    public function __construct() {

         // Initialize product generator
         $this->init_dependencies();
         
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_process_order_batch', array($this, 'process_order_batch'));
        add_action('wp_ajax_stop_order_generation', array($this, 'stop_order_generation'));
        add_action('admin_init', array($this, 'register_settings'));
        // Disable emails during order generation
        add_action('woocommerce_email', array($this, 'disable_emails'));
    }


    private function init_dependencies() {
        if (!class_exists('WC_Bulk_Product_Generator')) {
            if (!file_exists(WC_BULK_GENERATOR_PLUGIN_DIR . 'includes/class-wc-bulk-product-generator.php')) {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error"><p>' . 
                         esc_html__('WooCommerce Bulk Generator: Required file "class-wc-bulk-product-generator.php" is missing.', 'wc-bulk-order-generator') . 
                         '</p></div>';
                });
                return;
            }
            require_once WC_BULK_GENERATOR_PLUGIN_DIR . 'includes/class-wc-bulk-product-generator.php';
        }

        $this->product_generator = new WC_Bulk_Product_Generator();
    }


    public function register_settings() {
        register_setting('wc_bulk_generator', 'wc_bulk_generator_settings');
        
        // Set default values for all settings
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

    public function disable_emails($email_class) {
        remove_all_actions('woocommerce_order_status_pending_to_processing_notification');
        remove_all_actions('woocommerce_order_status_pending_to_completed_notification');
        remove_all_actions('woocommerce_order_status_processing_to_completed_notification');
        remove_all_actions('woocommerce_new_order_notification');
    }

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

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Generate Test Orders',
            'Generate Test Orders',
            'manage_woocommerce',
            'wc-order-generator',
            array($this, 'admin_page')
        );
    }

    /* public function admin_page() {
        $settings = get_option('wc_bulk_generator_settings');
        ?>
        <div class="wrap wc-bulk-generator-wrap">
            <div class="wc-bulk-generator-header">
                <h1>Generate Test Orders</h1>
                <p class="description">Generate test orders in batches with monitoring data.</p>
            </div>

            <form id="order-generator-form" method="post">
                <div class="settings-grid">
                    <div class="setting-card">
                        <label for="num_orders">Number of Orders</label>
                        <input type="number" id="num_orders" name="num_orders" 
                               value="100" min="1" max="<?php echo esc_attr($settings['max_orders']); ?>">
                        <p class="description">Generate between 1 and <?php echo number_format($settings['max_orders']); ?> orders</p>
                    </div>
                    
                    <div class="setting-card">
                        <label for="batch_size">Batch Size</label>
                        <input type="number" id="batch_size" name="batch_size" 
                               value="<?php echo esc_attr($settings['batch_size']); ?>" min="10" max="100">
                        <p class="description">Orders to process per batch (10-100)</p>
                    </div>
                </div>

                <div class="progress-wrapper">
                    <div class="progress-bar" style="width: 0%"></div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value" id="total-processed">0</div>
                        <div class="stat-label">Total Processed</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value" id="success-count">0</div>
                        <div class="stat-label">Successful</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value" id="failed-count">0</div>
                        <div class="stat-label">Failed</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value" id="processing-rate">0</div>
                        <div class="stat-label">Orders/Second</div>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value" id="elapsed-time">0s</div>
                        <div class="stat-label">Elapsed Time</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value" id="time-remaining">--</div>
                        <div class="stat-label">Estimated Time Remaining</div>
                    </div>
                </div>

                <div id="generation-status" class="notice notice-info" style="display: none;"></div>

                <div class="control-buttons">
                    <input type="submit" id="start-generation" class="button button-primary" value="Generate Orders">
                    <button type="button" id="stop-generation" class="button" disabled>Stop Generation</button>
                    <button type="button" id="reset-generation" class="button button-secondary">Reset</button>
                    <!-- <button data-tooltip="This is a custom tooltip!">Hover over me!</button> -->
                </div>
            </form>
        </div>
        <?php
    } */

    public function admin_page() {
        $settings = get_option('wc_bulk_generator_settings');
        ?>
        <div class="wrap wc-bulk-generator-wrap">
            <div class="wc-bulk-generator-header">
                <h1>WooCommerce Bulk Generator</h1>
                <p class="description">Generate test orders and products in batches with monitoring data.</p>
            </div>
    
            <div class="wc-tabs-wrapper">
                <nav class="nav-tab-wrapper">
                    <a href="#orders" class="nav-tab nav-tab-active">Orders</a>
                    <a href="#products" class="nav-tab">Products</a>
                    <a href="#debug" class="nav-tab">Debug</a>
                </nav>
    
                <!-- Orders Tab -->
                <div id="orders" class="tab-content active">
                    <form id="order-generator-form" method="post">
                        <div class="settings-grid">
                            <div class="setting-card">
                                <label for="num_orders">Number of Orders</label>
                                <input type="number" id="num_orders" name="num_orders" 
                                       value="100" min="1" max="<?php echo esc_attr($settings['max_orders']); ?>">
                                <p class="description">Generate between 1 and <?php echo number_format($settings['max_orders']); ?> orders</p>
                            </div>
                            
                            <div class="setting-card">
                                <label for="batch_size">Batch Size</label>
                                <input type="number" id="batch_size" name="batch_size" 
                                       value="<?php echo esc_attr($settings['batch_size']); ?>" min="10" max="100">
                                <p class="description">Orders to process per batch (10-100)</p>
                            </div>
                        </div>
    
                        <div class="progress-wrapper">
                            <div class="progress-bar" style="width: 0%"></div>
                        </div>
    
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-value" id="total-processed">0</div>
                                <div class="stat-label">Total Processed</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value" id="success-count">0</div>
                                <div class="stat-label">Successful</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value" id="failed-count">0</div>
                                <div class="stat-label">Failed</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value" id="processing-rate">0</div>
                                <div class="stat-label">Orders/Second</div>
                            </div>
                        </div>
    
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-value" id="elapsed-time">0s</div>
                                <div class="stat-label">Elapsed Time</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value" id="time-remaining">--</div>
                                <div class="stat-label">Estimated Time Remaining</div>
                            </div>
                        </div>
    
                        <div id="generation-status" class="notice notice-info" style="display: none;"></div>
    
                        <div class="control-buttons">
                            <input type="submit" id="start-generation" class="button button-primary" value="Generate Orders">
                            <button type="button" id="stop-generation" class="button" disabled>Stop Generation</button>
                            <button type="button" id="reset-generation" class="button button-secondary">Reset</button>
                        </div>
                    </form>
                </div>
    
                <!-- Products Tab -->
                <div id="products" class="tab-content">
                    <form id="product-generator-form" method="post">
                        <div class="settings-grid">
                            <div class="setting-card">
                                <label for="num_products">Number of Products</label>
                                <input type="number" id="num_products" name="num_products" 
                                       value="50" min="1" max="1000">
                                <p class="description">Generate between 1 and 1,000 products</p>
                            </div>
                            
                            <div class="setting-card">
                                <label for="product_batch_size">Batch Size</label>
                                <input type="number" id="product_batch_size" name="product_batch_size" 
                                       value="20" min="5" max="50">
                                <p class="description">Products to process per batch (5-50)</p>
                            </div>
                        </div>
    
                        <div class="settings-grid">
                            <div class="setting-card">
                                <label for="price_min">Minimum Price</label>
                                <input type="number" id="price_min" name="price_min" 
                                       value="10" min="0" step="0.01">
                            </div>
                            
                            <div class="setting-card">
                                <label for="price_max">Maximum Price</label>
                                <input type="number" id="price_max" name="price_max" 
                                       value="100" min="0" step="0.01">
                            </div>
                        </div>
    
                        <div class="progress-wrapper">
                            <div class="product-progress-bar" style="width: 0%"></div>
                        </div>
    
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-value" id="products-processed">0</div>
                                <div class="stat-label">Products Created</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value" id="products-failed">0</div>
                                <div class="stat-label">Failed</div>
                            </div>
                        </div>
    
                        <div id="product-generation-status" class="notice notice-info" style="display: none;"></div>
    
                        <div class="control-buttons">
                            <input type="submit" id="start-product-generation" class="button button-primary" value="Generate Products">
                            <button type="button" id="stop-product-generation" class="button" disabled>Stop Generation</button>
                            <button type="button" id="reset-product-generation" class="button button-secondary">Reset</button>
                        </div>
                    </form>
                </div>
    
                <!-- Debug Tab -->
                <div id="debug" class="tab-content">
                    <div class="debug-info">
                        <h2>Debug Information</h2>
                        <div class="debug-grid">
                            <div class="debug-card">
                                <h3>System Status</h3>
                                <pre id="system-status">Loading...</pre>
                            </div>
                            <div class="debug-card">
                                <h3>Generation Logs</h3>
                                <div id="generation-logs" class="debug-logs"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }


    private function cache_products() {
        if (empty($this->products_cache)) {
            $this->products_cache = wc_get_products(array(
                'status' => 'publish',
                'limit' => -1
            ));
        }
        return $this->products_cache;
    }

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

    public function stop_order_generation() {
        check_ajax_referer('generate_orders_nonce', 'nonce');
        wp_send_json_success();
    }
}

// Initialize the plugin
function wc_bulk_generator_init() {
    new WC_Bulk_Order_Generator();
}
add_action('plugins_loaded', 'wc_bulk_generator_init');


