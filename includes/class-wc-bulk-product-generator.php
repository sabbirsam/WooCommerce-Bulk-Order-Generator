<?php
/**
 * WC Bulk Product Generator
 *
 * @package WcBulkOrderGenerator
 */

namespace WcBulkOrderGenerator;

if (!defined('ABSPATH')) {
    exit;
}

class WC_Bulk_Product_Generator {
    private $batch_size = 20;
    private $product_names = array();
    private $product_descriptions = array();
    
    public function __construct() {
        add_action('wp_ajax_process_product_batch', array($this, 'process_product_batch'));
        $this->init_sample_data();
    }

    private function init_sample_data() {
        // Real-sounding product names pool
        $this->product_names = array(
            // Apparel & Accessories
            'Classic Leather Wallet', 'Slim Fit Chino Pants', 'Merino Wool Sweater', 'Canvas Tote Bag',
            'Polarized Sunglasses', 'Waterproof Hiking Boots', 'Cashmere Scarf', 'Denim Jacket',
            'Running Shorts', 'Yoga Leggings', 'Oxford Button-Down Shirt', 'Leather Belt',
            // Electronics & Tech
            'Wireless Noise-Cancelling Headphones', 'Portable Bluetooth Speaker', 'USB-C Hub',
            'Mechanical Keyboard', 'Ergonomic Mouse', 'Phone Stand Desk Mount', 'LED Desk Lamp',
            'Webcam 1080p', 'Smart Plug', 'Portable Charger 20000mAh', 'Cable Management Kit',
            // Home & Kitchen
            'Stainless Steel Water Bottle', 'French Press Coffee Maker', 'Bamboo Cutting Board',
            'Cast Iron Skillet', 'Non-Stick Frying Pan', 'Electric Kettle', 'Airtight Food Containers Set',
            'Silicone Baking Mat', 'Dish Drying Rack', 'Kitchen Scale', 'Reusable Produce Bags',
            // Health & Beauty
            'Vitamin C Serum', 'Moisturizing Face Cream', 'Natural Lip Balm', 'Bamboo Toothbrush',
            'Essential Oil Diffuser', 'Foam Roller', 'Resistance Bands Set', 'Yoga Block',
            'Shea Butter Body Lotion', 'Charcoal Face Mask',
            // Sports & Outdoors
            'Stainless Steel Thermos', 'Trekking Poles', 'Camping Lantern', 'Dry Bag 20L',
            'Compression Socks', 'Jump Rope', 'Pull-Up Bar', 'Gym Gloves',
            // Office & Stationery
            'Hardcover Notebook', 'Fountain Pen', 'Desk Organizer', 'Monitor Riser',
            'Wrist Rest Pad', 'Sticky Notes Set', 'Wireless Charging Pad', 'Laptop Sleeve',
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
        $product_types = isset($_POST['product_types']) && is_array($_POST['product_types']) ? array_map('sanitize_text_field', wp_unslash($_POST['product_types'])) : array('simple');
        $use_random_images = isset($_POST['use_random_images']) ? absint($_POST['use_random_images']) : 0;
        $prefix = isset($_POST['product_name_prefix']) ? sanitize_text_field(wp_unslash($_POST['product_name_prefix'])) : '';
        $suffix = isset($_POST['product_name_suffix']) ? sanitize_text_field(wp_unslash($_POST['product_name_suffix'])) : '';
        
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
                    $product_data = $this->generate_product_data($price_min, $price_max, $product_types, $prefix, $suffix);
                    
                    // Verify product data
                    if (empty($product_data['title']) || empty($product_data['description'])) {
                        throw new \Exception('Invalid product data generated');
                    }
                    
                    $product = $this->create_product($product_data, $use_random_images);
                    
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
                } catch (\Exception $e) {
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

        } catch (\Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'errors' => $errors
            ));
        }
    }

    private function generate_product_data($price_min, $price_max, $product_types = array('simple'), $prefix = '', $suffix = '') {
        // Pick a random product type from the selected types
        $type = $product_types[array_rand($product_types)];
        
        // Pick a random real product name
        $base_name = $this->product_names[array_rand($this->product_names)];
        
        // Apply prefix and/or suffix if provided
        $title = trim($prefix . ' ' . $base_name . ' ' . $suffix);

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

        // Generate SKU based on the base product name
        $sku = sprintf('PROD-%s-%d', strtoupper(substr(str_replace(' ', '', $base_name), 0, 4)), wp_rand(1000000, 9999999));

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

    private function create_product($data, $use_random_images = 0) {
        try {
            $type = isset($data['type']) ? $data['type'] : 'simple';
            switch ($type) {
                case 'variable':
                    $product = new \WC_Product_Variable();
                    break;
                case 'grouped':
                    $product = new \WC_Product_Grouped();
                    break;
                case 'external':
                case 'affiliate':
                    $product = new \WC_Product_External();
                    break;
                case 'simple':
                default:
                    $product = new \WC_Product_Simple();
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
                throw new \Exception('Failed to save product');
            }
            // Add categories
            $category_ids = $this->get_random_categories();
            if (!empty($category_ids)) {
                wp_set_object_terms($product_id, $category_ids, 'product_cat');
            }
            // Add images based on checkbox
            if ($use_random_images) {
                $this->add_random_images($product_id);
            } else {
                // Add placeholder image
                $this->maybe_add_placeholder_image($product_id);
            }
            // Add attributes/variations for variable products
            if ($type === 'variable') {
                $this->add_random_attributes_and_variations($product, $product_id);
            }
            // Assign children for grouped products
            if ($type === 'grouped') {
                $this->assign_random_grouped_children($product, $product_id);
            }
            return wc_get_product($product_id); // Return the updated product object
        } catch (\Exception $e) {
            return new \WP_Error('product_creation_failed', $e->getMessage());
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

    /**
     * Add random images from the images folder to product
     * 
     * @param int $product_id The product ID
     */
    private function add_random_images($product_id) {
        // Define the images folder path
        $images_folder = WC_BULK_GENERATOR_PLUGIN_DIR . 'images/';
        
        // Check if images folder exists
        if (!is_dir($images_folder)) {
            // Fallback to placeholder if folder doesn't exist
            $this->maybe_add_placeholder_image($product_id);
            return;
        }
        
        // Get all image files from the folder
        $allowed_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp');
        $image_files = array();
        
        if ($handle = opendir($images_folder)) {
            while (false !== ($file = readdir($handle))) {
                $file_extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($file_extension, $allowed_extensions)) {
                    $image_files[] = $images_folder . $file;
                }
            }
            closedir($handle);
        }
        
        // If no images found, use placeholder
        if (empty($image_files)) {
            $this->maybe_add_placeholder_image($product_id);
            return;
        }
        
        // Randomly select 1-4 images
        shuffle($image_files);
        $num_images = wp_rand(1, min(4, count($image_files)));
        $selected_images = array_slice($image_files, 0, $num_images);
        
        $attachment_ids = array();
        
        foreach ($selected_images as $index => $image_path) {
            // Upload image to WordPress media library
            $attachment_id = $this->upload_image_to_media_library($image_path, $product_id);
            
            if ($attachment_id) {
                $attachment_ids[] = $attachment_id;
                
                // Set the first image as featured image
                if ($index === 0) {
                    set_post_thumbnail($product_id, $attachment_id);
                }
            }
        }
        
        // Set gallery images (excluding the featured image)
        if (count($attachment_ids) > 1) {
            $product = wc_get_product($product_id);
            if ($product) {
                $gallery_ids = array_slice($attachment_ids, 1);
                $product->set_gallery_image_ids($gallery_ids);
                $product->save();
            }
        }
    }

    /**
     * Upload an image to WordPress media library
     * 
     * @param string $image_path Full path to the image file
     * @param int $product_id The product ID to attach the image to
     * @return int|false Attachment ID on success, false on failure
     */
    private function upload_image_to_media_library($image_path, $product_id) {
        // Check if file exists
        if (!file_exists($image_path)) {
            return false;
        }
        
        // Get the file name
        $filename = basename($image_path);
        
        // Check if this image already exists in media library
        $existing_attachment = get_posts(array(
            'post_type' => 'attachment',
            'meta_query' => array(
                array(
                    'key' => '_wp_attached_file',
                    'value' => $filename,
                    'compare' => 'LIKE'
                )
            ),
            'posts_per_page' => 1,
            'fields' => 'ids'
        ));
        
        // If image already exists, reuse it
        if (!empty($existing_attachment)) {
            return $existing_attachment[0];
        }
        
        // Include required WordPress files
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        // Upload the file
        $upload = wp_upload_bits($filename, null, file_get_contents($image_path));
        
        if ($upload['error']) {
            return false;
        }
        
        // Prepare attachment data
        $file_path = $upload['file'];
        $file_type = wp_check_filetype($filename, null);
        
        $attachment = array(
            'post_mime_type' => $file_type['type'],
            'post_title' => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit'
        );
        
        // Insert the attachment
        $attachment_id = wp_insert_attachment($attachment, $file_path, $product_id);
        
        if (is_wp_error($attachment_id)) {
            return false;
        }
        
        // Generate attachment metadata
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $attachment_data);
        
        return $attachment_id;
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
            $attribute = new \WC_Product_Attribute();
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
            $variation = new \WC_Product_Variation();
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

            // Generate a unique SKU for the variation
            $parent_sku = $product->get_sku();
            $attr_slug = implode('-', array_map('sanitize_title', $combo));
            $base_sku = ($parent_sku ? $parent_sku : 'prod-' . $product_id) . '-' . $attr_slug;
            // Ensure uniqueness
            $sku = $base_sku;
            $suffix = 1;
            while ( wc_get_product_id_by_sku( $sku ) ) {
                $sku = $base_sku . '-' . $suffix;
                $suffix++;
            }
            $variation->set_sku($sku);

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
new \WcBulkOrderGenerator\WC_Bulk_Product_Generator();
