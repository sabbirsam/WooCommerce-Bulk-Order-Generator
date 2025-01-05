<?php

class WC_Bulk_Product_Generator {
    private $batch_size = 20;
    private $product_titles = array();
    private $product_descriptions = array();
    
    public function __construct() {
        add_action('wp_ajax_process_product_batch', array($this, 'process_product_batch'));
        add_action('wp_ajax_stop_product_generation', array($this, 'stop_product_generation'));
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
        check_ajax_referer('generate_products_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 20;
        $price_min = isset($_POST['price_min']) ? floatval($_POST['price_min']) : 10;
        $price_max = isset($_POST['price_max']) ? floatval($_POST['price_max']) : 100;
        
        if ($batch_size < 1 || $batch_size > 50) {
            $batch_size = 20;
        }

        $success_count = 0;
        $failed_count = 0;

        try {
            global $wpdb;
            $wpdb->query('START TRANSACTION');

            for ($i = 0; $i < $batch_size; $i++) {
                try {
                    $product_data = $this->generate_product_data($price_min, $price_max);
                    $product = $this->create_product($product_data);
                    
                    if ($product && !is_wp_error($product)) {
                        $success_count++;
                    } else {
                        $failed_count++;
                    }
                } catch (Exception $e) {
                    error_log('Product generation error: ' . $e->getMessage());
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
        $regular_price = round(rand($price_min * 100, $price_max * 100) / 100, 2);
        
        // 30% chance of sale price
        $sale_price = null;
        if (rand(1, 100) <= 30) {
            $discount = rand(10, 30) / 100; // 10-30% discount
            $sale_price = round($regular_price * (1 - $discount), 2);
        }

        // Generate SKU
        $sku = sprintf('TEST-%s-%d', strtoupper(substr(str_replace(' ', '', $noun), 0, 3)), rand(1000, 9999));

        return array(
            'title' => $title,
            'description' => $description,
            'regular_price' => $regular_price,
            'sale_price' => $sale_price,
            'sku' => $sku,
            'stock_quantity' => rand(0, 100),
            'weight' => rand(1, 50) / 10,
            'length' => rand(10, 100),
            'width' => rand(10, 100),
            'height' => rand(10, 100)
        );
    }

    private function create_product($data) {
        $product = new WC_Product_Simple();
        
        $product->set_name($data['title']);
        $product->set_description($data['description']);
        $product->set_short_description(substr($data['description'], 0, 100) . '...');
        $product->set_regular_price($data['regular_price']);
        
        if (!is_null($data['sale_price'])) {
            $product->set_sale_price($data['sale_price']);
        }
        
        $product->set_sku($data['sku']);
        
        // Set other product data
        $product->set_manage_stock(true);
        $product->set_stock_quantity($data['stock_quantity']);
        $product->set_weight($data['weight']);
        $product->set_length($data['length']);
        $product->set_width($data['width']);
        $product->set_height($data['height']);
        
        // Randomly set featured status (10% chance)
        if (rand(1, 100) <= 10) {
            $product->set_featured(true);
        }
        
        // Set product categories
        $category_ids = $this->get_random_categories();
        if (!empty($category_ids)) {
            $product->set_category_ids($category_ids);
        }

        // Save the product
        $product_id = $product->save();
        
        if ($product_id) {
            // Add placeholder image
            $this->maybe_add_placeholder_image($product_id);
        }

        return $product;
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
        $num_cats = rand(1, min(3, count($categories)));
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

    public function stop_product_generation() {
        check_ajax_referer('generate_products_nonce', 'nonce');
        wp_send_json_success();
    }
}

// Initialize the product generator
new WC_Bulk_Product_Generator();
