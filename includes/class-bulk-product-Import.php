<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Bulk_Product_Import {
   
    public function __construct() {
        add_action('wp_ajax_import_products', [$this, 'handle_product_import']);
    }

    private function parse_csv($file) {
        $products = [];
        if (($handle = fopen($file, "r")) !== FALSE) {
            // Get headers
            $headers = fgetcsv($handle);
            
            // Create lowercase header mapping for case insensitivity
            $header_map = array_map('strtolower', $headers);
            
            while (($data = fgetcsv($handle)) !== FALSE) {
                $product_data = [];
                
                // Map CSV columns to product data
                foreach ($data as $index => $value) {
                    if (isset($header_map[$index])) {
                        $product_data[$header_map[$index]] = $value;
                    }
                }
                
                $products[] = $product_data;
            }
            fclose($handle);
        }
        return $products;
    }

    private function process_products($products, $batch_size, $current_batch) {
        $successful = 0;
        $failed = 0;
        $skipped = 0;
        
        // Get slice of products for this batch
        $batch_products = array_slice($products, $current_batch * $batch_size, $batch_size);
        
        foreach ($batch_products as $product_data) {
            try {
                // Check if product with this ID or SKU already exists
                $product_id = isset($product_data['id']) ? intval($product_data['id']) : 0;
                $sku = isset($product_data['sku']) ? sanitize_text_field($product_data['sku']) : '';
                
                $existing_product = false;
                
                if ($product_id > 0) {
                    $existing_product = wc_get_product($product_id);
                }
                
                if (!$existing_product && !empty($sku)) {
                    $existing_id = wc_get_product_id_by_sku($sku);
                    if ($existing_id) {
                        $existing_product = wc_get_product($existing_id);
                    }
                }
                
                // Skip if product already exists and we're not updating
                if ($existing_product) {
                    $skipped++;
                    continue;
                }
                
                // Determine product type
                $product_type = isset($product_data['type']) ? strtolower(sanitize_text_field($product_data['type'])) : 'simple';
                
                // Create new product based on type
                switch ($product_type) {
                    case 'variable':
                        $product = new WC_Product_Variable();
                        break;
                    case 'grouped':
                        $product = new WC_Product_Grouped();
                        break;
                    case 'external':
                        $product = new WC_Product_External();
                        break;
                    default:
                        $product = new WC_Product_Simple();
                }
                
                // Set basic product data
                if (isset($product_data['name'])) {
                    $product->set_name(sanitize_text_field($product_data['name']));
                }
                
                if (isset($product_data['sku'])) {
                    $product->set_sku(sanitize_text_field($product_data['sku']));
                }
                
                if (isset($product_data['regular price'])) {
                    $product->set_regular_price(wc_format_decimal($product_data['regular price']));
                }
                
                if (isset($product_data['sale price']) && !empty($product_data['sale price'])) {
                    $product->set_sale_price(wc_format_decimal($product_data['sale price']));
                }
                
                if (isset($product_data['description'])) {
                    $product->set_description(wp_kses_post($product_data['description']));
                }
                
                if (isset($product_data['short description'])) {
                    $product->set_short_description(wp_kses_post($product_data['short description']));
                }
                
                // Stock management
                if (isset($product_data['in stock'])) {
                    $stock_status = wc_string_to_bool($product_data['in stock']) ? 'instock' : 'outofstock';
                    $product->set_stock_status($stock_status);
                }
                
                if (isset($product_data['stock'])) {
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity(wc_stock_amount($product_data['stock']));
                }
                
                if (isset($product_data['backorders allowed'])) {
                    $backorders = wc_string_to_bool($product_data['backorders allowed']) ? 'yes' : 'no';
                    $product->set_backorders($backorders);
                }
                
                // Dimensions
                if (isset($product_data['weight'])) {
                    $product->set_weight($product_data['weight']);
                }
                
                if (isset($product_data['length'])) {
                    $product->set_length($product_data['length']);
                }
                
                if (isset($product_data['width'])) {
                    $product->set_width($product_data['width']);
                }
                
                if (isset($product_data['height'])) {
                    $product->set_height($product_data['height']);
                }
                
                // Product status
                if (isset($product_data['published'])) {
                    $status = wc_string_to_bool($product_data['published']) ? 'publish' : 'draft';
                    $product->set_status($status);
                }
                
                if (isset($product_data['featured'])) {
                    $product->set_featured(wc_string_to_bool($product_data['featured']));
                }
                
                if (isset($product_data['catalog visibility'])) {
                    $product->set_catalog_visibility($product_data['catalog visibility']);
                }
                
                // Set categories
                if (isset($product_data['categories']) && !empty($product_data['categories'])) {
                    $categories = explode(',', $product_data['categories']);
                    $category_ids = [];
                    
                    foreach ($categories as $category_name) {
                        $category_name = trim($category_name);
                        $term = get_term_by('name', $category_name, 'product_cat');
                        
                        if ($term) {
                            $category_ids[] = $term->term_id;
                        } else {
                            // Create category if it doesn't exist
                            $new_term = wp_insert_term($category_name, 'product_cat');
                            if (!is_wp_error($new_term)) {
                                $category_ids[] = $new_term['term_id'];
                            }
                        }
                    }
                    
                    if (!empty($category_ids)) {
                        $product->set_category_ids($category_ids);
                    }
                }
                
                // Set tags
                if (isset($product_data['tags']) && !empty($product_data['tags'])) {
                    $tags = explode(',', $product_data['tags']);
                    $tag_ids = [];
                    
                    foreach ($tags as $tag_name) {
                        $tag_name = trim($tag_name);
                        $term = get_term_by('name', $tag_name, 'product_tag');
                        
                        if ($term) {
                            $tag_ids[] = $term->term_id;
                        } else {
                            // Create tag if it doesn't exist
                            $new_term = wp_insert_term($tag_name, 'product_tag');
                            if (!is_wp_error($new_term)) {
                                $tag_ids[] = $new_term['term_id'];
                            }
                        }
                    }
                    
                    if (!empty($tag_ids)) {
                        $product->set_tag_ids($tag_ids);
                    }
                }
                
                // Process image URLs
                if (isset($product_data['images']) && !empty($product_data['images'])) {
                    $image_urls = explode(',', $product_data['images']);
                    
                    if (!empty($image_urls[0])) {
                        // Find an existing attachment or use a placeholder
                        $image_id = $this->get_image_id_from_url($image_urls[0]);
                        if ($image_id) {
                            $product->set_image_id($image_id);
                        }
                    }
                    
                    // Add gallery images
                    if (count($image_urls) > 1) {
                        $gallery_ids = [];
                        for ($i = 1; $i < count($image_urls); $i++) {
                            $gallery_image_id = $this->get_image_id_from_url($image_urls[$i]);
                            if ($gallery_image_id) {
                                $gallery_ids[] = $gallery_image_id;
                            }
                        }
                        
                        if (!empty($gallery_ids)) {
                            $product->set_gallery_image_ids($gallery_ids);
                        }
                    }
                }
                
                // Process meta fields if present
                if (isset($product_data['meta fields']) && !empty($product_data['meta fields'])) {
                    $meta_fields = explode('|', $product_data['meta fields']);
                    
                    foreach ($meta_fields as $meta_field) {
                        $meta_parts = explode('::', $meta_field, 2);
                        if (count($meta_parts) === 2) {
                            $meta_key = trim($meta_parts[0]);
                            $meta_value = trim($meta_parts[1]);
                            
                            update_post_meta($product->get_id(), $meta_key, $meta_value);
                        }
                    }
                }
                
                // Save the product
                $product->save();
                $successful++;
                
            } catch (Exception $e) {
                error_log('Product import error: ' . $e->getMessage());
                $failed++;
            }
        }
        
        return [
            'successful' => $successful,
            'failed' => $failed,
            'skipped' => $skipped,
            'processed' => count($batch_products)
        ];
    }
    
    private function get_image_id_from_url($url) {
        // Try to find existing attachment by URL
        $attachment_id = attachment_url_to_postid($url);
        
        if ($attachment_id) {
            return $attachment_id;
        }
        
        // If URL is placeholder, find default WooCommerce placeholder
        if (strpos($url, 'woocommerce-placeholder') !== false) {
            return get_option('woocommerce_placeholder_image', 0);
        }
        
        return 0;
    }

    public function handle_product_import() {
        check_ajax_referer('import_products_nonce', 'nonce');
    
        // Process uploaded CSV
        if (!isset($_FILES['csv_file'])) {
            wp_send_json_error('No file uploaded');
        }
    
        $file = $_FILES['csv_file']['tmp_name'];
        $batch_size = intval($_POST['batch_size'] ?? 50);
        $current_batch = intval($_POST['current_batch'] ?? 0);
    
        $products = $this->parse_csv($file);
        $total_products = count($products);
    
        $results = $this->process_products($products, $batch_size, $current_batch);
    
        // Determine if more batches are needed
        $is_complete = ($current_batch * $batch_size + $results['processed']) >= $total_products;
    
        wp_send_json_success([
            'processed' => $results['processed'],
            'successful' => $results['successful'],
            'failed' => $results['failed'],
            'skipped' => $results['skipped'],
            'total_products' => $total_products,
            'current_batch' => $current_batch,
            'is_complete' => $is_complete
        ]);
    }
}

// Initialize the product importer
new WC_Bulk_Product_Import();