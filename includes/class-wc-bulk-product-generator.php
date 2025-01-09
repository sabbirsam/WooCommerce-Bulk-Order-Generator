<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Bulk_Product_Generator {
    private $batch_size = 20;
    private $product_titles = array();
    private $product_descriptions = array();
    
    public function __construct() {
        add_action('wp_ajax_process_product_batch', array($this, 'process_product_batch'));
        $this->init_sample_data();
    }

    private function init_sample_data() {
        // Sample product titles and descriptions for random generation
        $this->product_titles = array(
            'adjectives' => array('Premium', 'Deluxe', 'Professional', 'Essential', 'Advanced', 'Classic', 'Modern', 'Ultra', 'Smart', 'Eco-friendly'),
            'nouns' => array('Widget', 'Gadget', 'Tool', 'Device', 'System', 'Solution', 'Package', 'Kit', 'Set', 'Bundle'),
            'categories' => array('Pro', 'Plus', 'Elite', 'Max', 'Lite', 'Basic', 'Premium', 'Ultimate', 'Standard', 'Deluxe')
        );

        $this->product_descriptions = array(
            'intros' => array(
                'Experience the difference with our',
                'Discover the power of',
                'Enhance your lifestyle with',
                'Upgrade your experience with',
                'Transform your workflow using'
            ),
            'features' => array(
                'Built with premium materials',
                'Designed for optimal performance',
                'Features advanced technology',
                'Includes comprehensive documentation',
                'Backed by our quality guarantee'
            ),
            'benefits' => array(
                'Increases productivity',
                'Saves time and effort',
                'Improves efficiency',
                'Enhances user experience',
                'Reduces operational costs'
            )
        );
    }

    public function process_product_batch() {
        if (!check_ajax_referer('generate_products_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Validate and sanitize input parameters
        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 20;
        $price_min = isset($_POST['price_min']) ? floatval($_POST['price_min']) : 10;
        $price_max = isset($_POST['price_max']) ? floatval($_POST['price_max']) : 100;
        
        if ($batch_size < 1 || $batch_size > 50) {
            $batch_size = 20;
        }

        $success_count = 0;
        $failed_count = 0;
        $errors = array();

        try {
            // Disable WordPress auto-save and revision features temporarily
            wp_defer_term_counting(true);
            wp_defer_comment_counting(true);
            
            // Process products in smaller chunks for better memory management
            for ($i = 0; $i < $batch_size; $i++) {
                try {
                    $product_data = $this->generate_product_data($price_min, $price_max);
                    
                    // Verify product data
                    if (empty($product_data['title']) || empty($product_data['description'])) {
                        throw new Exception('Invalid product data generated');
                    }
                    
                    $product = $this->create_product($product_data);
                    
                    if ($product && !is_wp_error($product) && $product->get_id() > 0) {
                        $success_count++;
                        // Clear object cache for each product
                        clean_post_cache($product->get_id());
                    } else {
                        $failed_count++;
                        $errors[] = 'Failed to create product: ' . ($product instanceof WP_Error ? $product->get_error_message() : 'Unknown error');
                    }
                    
                    // Free up memory
                    unset($product);
                    wp_cache_flush();
                } catch (Exception $e) {
                    $failed_count++;
                    $errors[] = $e->getMessage();
                    error_log('Product generation error: ' . $e->getMessage());
                }
            }

            // Re-enable WordPress features
            wp_defer_term_counting(false);
            wp_defer_comment_counting(false);

            wp_send_json_success(array(
                'success' => $success_count,
                'failed' => $failed_count,
                'errors' => $errors
            ));

        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'errors' => $errors
            ));
        }
    }

    private function generate_product_data($price_min, $price_max) {
        // Generate random product title
        $adjective = $this->product_titles['adjectives'][array_rand($this->product_titles['adjectives'])];
        $noun = $this->product_titles['nouns'][array_rand($this->product_titles['nouns'])];
        $category = $this->product_titles['categories'][array_rand($this->product_titles['categories'])];
        
        $title = $adjective . ' ' . $noun . ' ' . $category;

        // Generate random description
        $intro = $this->product_descriptions['intros'][array_rand($this->product_descriptions['intros'])];
        $feature = $this->product_descriptions['features'][array_rand($this->product_descriptions['features'])];
        $benefit = $this->product_descriptions['benefits'][array_rand($this->product_descriptions['benefits'])];
        
        $description = sprintf(
            "%s %s. %s. %s.",
            $intro,
            strtolower($title),
            $feature,
            $benefit
        );

        // Generate random price within range
        $regular_price = round(wp_rand($price_min * 100, $price_max * 100) / 100, 2);
        
        // 30% chance of sale price
        $sale_price = null;
        if (wp_rand(1, 100) <= 30) {
            $discount = wp_rand(10, 30) / 100; // 10-30% discount
            $sale_price = round($regular_price * (1 - $discount), 2);
        }

        // Generate SKU
        $sku = sprintf('TEST-%s-%d', strtoupper(substr(str_replace(' ', '', $noun), 0, 3)), wp_rand(1000000, 9999999));

        return array(
            'title' => $title,
            'description' => $description,
            'regular_price' => $regular_price,
            'sale_price' => $sale_price,
            'sku' => $sku,
            'stock_quantity' => wp_rand(0, 100),
            'weight' => wp_rand(1, 50) / 10,
            'length' => wp_rand(10, 100),
            'width' => wp_rand(10, 100),
            'height' => wp_rand(10, 100)
        );
    }

    private function create_product($data) {
        try {
            // Create new product object
            $product = new WC_Product_Simple();
            
            // Basic product data
            $product->set_name(wp_strip_all_tags($data['title']));
            $product->set_description(wp_kses_post($data['description']));
            $product->set_short_description(wp_kses_post(substr($data['description'], 0, 100) . '...'));
            $product->set_regular_price(strval($data['regular_price'])); // Convert to string
            
            if (!is_null($data['sale_price'])) {
                $product->set_sale_price(strval($data['sale_price']));
            }
            
            // Generate unique SKU
            $sku = $data['sku'];
            $counter = 1;
            while (wc_get_product_id_by_sku($sku)) {
                $sku = $data['sku'] . '-' . $counter;
                $counter++;
            }
            $product->set_sku($sku);
            
            // Stock management
            $product->set_manage_stock(true);
            $product->set_stock_quantity($data['stock_quantity']);
            $product->set_stock_status('instock');
            
            // Dimensions
            $product->set_weight(strval($data['weight']));
            $product->set_length(strval($data['length']));
            $product->set_width(strval($data['width']));
            $product->set_height(strval($data['height']));
            
            // Status and visibility
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            
            // Save product
            $product_id = $product->save();
            
            if (!$product_id) {
                throw new Exception('Failed to save product');
            }
            
            // Add categories
            $category_ids = $this->get_random_categories();
            if (!empty($category_ids)) {
                wp_set_object_terms($product_id, $category_ids, 'product_cat');
            }
            
            // Add placeholder image
            $this->maybe_add_placeholder_image($product_id);
            
            return $product;
            
        } catch (Exception $e) {
            error_log('Error creating product: ' . $e->getMessage());
            return new WP_Error('product_creation_failed', $e->getMessage());
        }
    }

    private function get_random_categories() {
        $categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'fields' => 'ids'
        ));

        if (is_wp_error($categories) || empty($categories)) {
            return array();
        }

        // Randomly select 1-3 categories
        $num_cats = wp_rand(1, min(3, count($categories)));
        shuffle($categories);
        return array_slice($categories, 0, $num_cats);
    }

    private function maybe_add_placeholder_image($product_id) {
        // Check if WooCommerce placeholder image exists
        $placeholder_id = get_option('woocommerce_placeholder_image', 0);
        
        if ($placeholder_id) {
            set_post_thumbnail($product_id, $placeholder_id);
        }
    }
}

// Initialize the product generator
new WC_Bulk_Product_Generator();
