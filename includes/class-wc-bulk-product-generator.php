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
        $product_types = isset($_POST['product_types']) && is_array($_POST['product_types']) ? array_map('sanitize_text_field', $_POST['product_types']) : array('simple');
        
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
                    $product_data = $this->generate_product_data($price_min, $price_max, $product_types);
                    
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

    private function generate_product_data($price_min, $price_max, $product_types = array('simple')) {
        // Pick a random product type from the selected types
        $type = $product_types[array_rand($product_types)];
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
            'height' => wp_rand(10, 100),
            'type' => $type
        );
    }

    private function create_product($data) {
        try {
            $type = isset($data['type']) ? $data['type'] : 'simple';
            switch ($type) {
                case 'variable':
                    $product = new WC_Product_Variable();
                    break;
                case 'grouped':
                    $product = new WC_Product_Grouped();
                    break;
                case 'external':
                case 'affiliate':
                    $product = new WC_Product_External();
                    break;
                case 'simple':
                default:
                    $product = new WC_Product_Simple();
                    break;
            }
            // Basic product data
            $product->set_name(wp_strip_all_tags($data['title']));
            $product->set_description(wp_kses_post($data['description']));
            $product->set_short_description(wp_kses_post(substr($data['description'], 0, 100) . '...'));
            // External/affiliate product special handling
            if ($type === 'external' || $type === 'affiliate') {
                $product->set_product_url('https://example.com/?ref=bulkgen' . wp_rand(1000,9999));
                $product->set_button_text('Buy Now');
                $product->set_regular_price('0');
            } else {
                $product->set_regular_price(strval($data['regular_price'])); // Convert to string
                if (!is_null($data['sale_price'])) {
                    $product->set_sale_price(strval($data['sale_price']));
                }
            }
            // Generate unique SKU
            $sku = $data['sku'];
            $counter = 1;
            while (wc_get_product_id_by_sku($sku)) {
                $sku = $data['sku'] . '-' . $counter;
                $counter++;
            }
            $product->set_sku($sku);
            // Stock management (skip for grouped/external)
            if ($type !== 'grouped' && $type !== 'external' && $type !== 'affiliate') {
                $product->set_manage_stock(true);
                $product->set_stock_quantity($data['stock_quantity']);
                $product->set_stock_status('instock');
            }
            // Dimensions
            $product->set_weight(strval($data['weight']));
            $product->set_length(strval($data['length']));
            $product->set_width(strval($data['width']));
            $product->set_height(strval($data['height']));
            // Status and visibility
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            // Save product to get ID
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
            // Add attributes/variations for variable products
            if ($type === 'variable') {
                $this->add_random_attributes_and_variations($product, $product_id);
            }
            // Assign children for grouped products
            if ($type === 'grouped') {
                $this->assign_random_grouped_children($product, $product_id);
            }
            return wc_get_product($product_id); // Return the updated product object
        } catch (Exception $e) {
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

    // Helper: Add random attributes and variations to a variable product
    private function add_random_attributes_and_variations($product, $product_id) {
        $attribute_options = array(
            'color' => array('Red', 'Blue', 'Green', 'Yellow', 'Black', 'White'),
            'size' => array('S', 'M', 'L', 'XL', 'XXL'),
            'material' => array('Cotton', 'Polyester', 'Wool', 'Silk'),
            'style' => array('Casual', 'Formal', 'Sport', 'Vintage'),
        );
        $attribute_labels = array(
            'color' => 'Color',
            'size' => 'Size',
            'material' => 'Material',
            'style' => 'Style',
        );
        // Pick 1-2 random attributes
        $attribute_keys = array_keys($attribute_options);
        shuffle($attribute_keys);
        $num_attrs = wp_rand(1, 2);
        $selected_attrs = array_slice($attribute_keys, 0, $num_attrs);
        $attributes = array();
        foreach ($selected_attrs as $attr_key) {
            $values = $attribute_options[$attr_key];
            shuffle($values);
            $num_values = wp_rand(2, min(4, count($values)));
            $selected_values = array_slice($values, 0, $num_values);
            $taxonomy = 'pa_' . $attr_key;
            // Register taxonomy if not exists
            if (!taxonomy_exists($taxonomy)) {
                register_taxonomy(
                    $taxonomy,
                    'product',
                    array('label' => $attribute_labels[$attr_key], 'public' => false, 'hierarchical' => false)
                );
            }
            // Ensure terms exist
            foreach ($selected_values as $val) {
                if (!term_exists($val, $taxonomy)) {
                    wp_insert_term($val, $taxonomy);
                }
            }
            $attributes[$taxonomy] = array(
                'name' => $taxonomy,
                'value' => implode(' | ', $selected_values),
                'is_visible' => 1,
                'is_variation' => 1,
            );
        }
        // Set attributes
        $product->set_attributes(array_map(function($attr) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_name($attr['name']);
            $attribute->set_options(explode(' | ', $attr['value']));
            $attribute->set_visible($attr['is_visible']);
            $attribute->set_variation($attr['is_variation']);
            return $attribute;
        }, $attributes));
        $product->save();
        // Create variations for all combinations
        $attr_values = array();
        foreach ($attributes as $tax => $attr) {
            $attr_values[] = explode(' | ', $attr['value']);
        }
        $combinations = $this->cartesian_product($attr_values);
        foreach ($combinations as $combo) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);
            $variation_attributes = array();
            $i = 0;
            foreach (array_keys($attributes) as $tax) {
                $variation_attributes[$tax] = $combo[$i];
                $i++;
            }
            $variation->set_attributes($variation_attributes);
            $variation->set_regular_price(strval(wp_rand(10, 100)));
            $variation->set_stock_quantity(wp_rand(1, 50));
            $variation->set_manage_stock(true);
            $variation->set_stock_status('instock');
            $variation->save();
        }
    }

    // Helper: Cartesian product for attribute combinations
    private function cartesian_product($arrays) {
        $result = array(array());
        foreach ($arrays as $property => $property_values) {
            $tmp = array();
            foreach ($result as $result_item) {
                foreach ($property_values as $property_value) {
                    $tmp[] = array_merge($result_item, array($property_value));
                }
            }
            $result = $tmp;
        }
        return $result;
    }

    // Helper: Assign random children to grouped product
    private function assign_random_grouped_children($product, $product_id) {
        // Get 2-4 random simple products
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => 10,
            'post_status' => 'publish',
            'fields' => 'ids',
            'orderby' => 'rand',
            'meta_query' => array(
                array(
                    'key' => '_product_type',
                    'value' => 'simple',
                    'compare' => '=',
                ),
            ),
            'exclude' => array($product_id),
        );
        $simple_products = get_posts($args);
        if (count($simple_products) < 2) {
            return; // Not enough products to group
        }
        shuffle($simple_products);
        $num_children = wp_rand(2, min(4, count($simple_products)));
        $children = array_slice($simple_products, 0, $num_children);
        foreach ($children as $child_id) {
            wp_set_object_terms($child_id, 'grouped', 'product_type', true);
        }
        $product->set_children($children);
        $product->save();
    }
}

// Initialize the product generator
new WC_Bulk_Product_Generator();
