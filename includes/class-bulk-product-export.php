<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Bulk_Product_Export {
    
    public function __construct() {
        add_action('wp_ajax_start_product_export', [$this, 'start_product_export']);
        add_action('wp_ajax_export_product_batch', [$this, 'export_product_batch']);
    }

    // Start Product Export
    public function start_product_export() {
        check_ajax_referer('export_products_nonce', 'nonce');
    
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
    
        $product_types = !empty($_POST['product_types']) ? array_map('sanitize_text_field', $_POST['product_types']) : [];
        $categories = !empty($_POST['categories']) ? array_map('sanitize_text_field', $_POST['categories']) : [];
        $tags = !empty($_POST['tags']) ? array_map('sanitize_text_field', $_POST['tags']) : [];

        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1 // Get total count
        ];
    
        // Build tax query only if filters are selected
        $tax_query = [];
        
        // Apply product type filter
        if (!empty($product_types)) {
            $product_type_query = [];
            
            foreach ($product_types as $product_type) {
                $product_type_query[] = [
                    'taxonomy' => 'product_type',
                    'field' => 'slug',
                    'terms' => $product_type
                ];
            }
            
            if (!empty($product_types)) {
                $tax_query[] = [
                    'taxonomy' => 'product_type',
                    'field' => 'slug',
                    'terms' => $product_types,
                    'operator' => 'IN'
                ];
            }
        }
        
        // Apply category filter
        if (!empty($categories)) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $categories
            ];
        }
        
        // Apply tag filter
        if (!empty($tags)) {
            $tax_query[] = [
                'taxonomy' => 'product_tag',
                'field' => 'term_id',
                'terms' => $tags
            ];
        }
        
        // Only add tax_query if we have filters
        if (!empty($tax_query)) {
            // Set the relation to AND if we have multiple tax queries
            if (count($tax_query) > 1) {
                $args['tax_query'] = [
                    'relation' => 'AND',
                    $tax_query
                ];
            } else {
                $args['tax_query'] = $tax_query;
            }
        }
        
        $query = new WP_Query($args);
        $total_products = $query->found_posts;
    
        // Generate a unique session ID
        $export_session = uniqid('wc_product_export_');
    
        wp_send_json_success([
            'export_session' => $export_session,
            'total_products' => $total_products
        ]);
    }
    
    public function export_product_batch() {
        check_ajax_referer('export_products_nonce', 'nonce');
    
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        ini_set('memory_limit', '512M');
    
        $batch_size = intval($_POST['batch_size']);
        $batch_number = intval($_POST['batch_number']);
        $total_batches = intval($_POST['total_batches']);
        $export_session = sanitize_text_field($_POST['export_session']);
        $product_types = !empty($_POST['product_types']) ? array_map('sanitize_text_field', $_POST['product_types']) : [];
        $categories = !empty($_POST['categories']) ? array_map('sanitize_text_field', $_POST['categories']) : [];
        $tags = !empty($_POST['tags']) ? array_map('sanitize_text_field', $_POST['tags']) : [];
    
        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $batch_size,
            'offset' => $batch_number * $batch_size
        ];
    
        // Build tax query only if filters are selected
        $tax_query = [];
        
        // Apply product type filter
        if (!empty($product_types)) {
            $product_type_query = [];
            
            foreach ($product_types as $product_type) {
                $product_type_query[] = [
                    'taxonomy' => 'product_type',
                    'field' => 'slug',
                    'terms' => $product_type
                ];
            }
            
            if (!empty($product_types)) {
                $tax_query[] = [
                    'taxonomy' => 'product_type',
                    'field' => 'slug',
                    'terms' => $product_types,
                    'operator' => 'IN'
                ];
            }
        }
        
        // Apply category filter
        if (!empty($categories)) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $categories
            ];
        }
        
        // Apply tag filter
        if (!empty($tags)) {
            $tax_query[] = [
                'taxonomy' => 'product_tag',
                'field' => 'term_id',
                'terms' => $tags
            ];
        }
        
        // Only add tax_query if we have filters
        if (!empty($tax_query)) {
            // Set the relation to AND if we have multiple tax queries
            if (count($tax_query) > 1) {
                $args['tax_query'] = [
                    'relation' => 'AND',
                    $tax_query
                ];
            } else {
                $args['tax_query'] = $tax_query;
            }
        }
    
        $query = new WP_Query($args);
        $products = $query->posts;
    
        $upload_dir = wp_upload_dir();
        $export_file = $upload_dir['basedir'] . '/wc-bulk-product-export-' . sanitize_file_name($export_session) . '.csv';
    
        // Open/append to CSV file
        $file_mode = ($batch_number == 0) ? 'w' : 'a';
        $fp = fopen($export_file, $file_mode);
    
        // Write headers on first batch
        if ($batch_number == 0) {
            fputcsv($fp, [
                'ID',
                'Type',
                'SKU',
                'Name',
                'Published',
                'Featured',
                'Catalog visibility',
                'Short description',
                'Description',
                'Date sale price starts',
                'Date sale price ends',
                'Tax status',
                'Tax class',
                'In stock',
                'Stock',
                'Backorders allowed',
                'Sold individually',
                'Weight',
                'Length',
                'Width',
                'Height',
                'Allow customer reviews',
                'Purchase note',
                'Sale price',
                'Regular price',
                'Categories',
                'Tags',
                'Shipping class',
                'Images',
                'Download limit',
                'Download expiry days',
                'Parent',
                'Grouped products',
                'Upsells',
                'Cross-sells',
                'External URL',
                'Button text',
                'Position',
                'Attributes',
                'Attribute data',
                'Attribute default',
                'Attribute visible',
                'Attribute global',
                'Variations',
                'Custom fields',
                'Brand',
                'Meta fields'
            ]);
        }
    
        $success_count = 0;
        $failed_count = 0;
    
        foreach ($products as $post) {
            try {
                $product = wc_get_product($post->ID);
                
                if (!$product) {
                    $failed_count++;
                    continue;
                }
                
                // Get product categories
                $categories = [];
                $product_categories = get_the_terms($product->get_id(), 'product_cat');
                if ($product_categories) {
                    foreach ($product_categories as $category) {
                        $categories[] = $category->name;
                    }
                }
                
                // Get product tags
                $tags = [];
                $product_tags = get_the_terms($product->get_id(), 'product_tag');
                if ($product_tags) {
                    foreach ($product_tags as $tag) {
                        $tags[] = $tag->name;
                    }
                }
                
                // Get product images
                $images = [];
                $attachment_ids = $product->get_gallery_image_ids();
                if ($product->get_image_id()) {
                    array_unshift($attachment_ids, $product->get_image_id());
                }
                foreach ($attachment_ids as $attachment_id) {
                    $images[] = wp_get_attachment_url($attachment_id);
                }
                
                // Get product attributes
                $attributes = [];
                $attribute_data = [];
                $attribute_default = [];
                $attribute_visible = [];
                $attribute_global = [];
                
                foreach ($product->get_attributes() as $attribute) {
                    if ($attribute->is_taxonomy()) {
                        $attributes[] = $attribute->get_name();
                        
                        $values = [];
                        $terms = wp_get_post_terms($product->get_id(), $attribute->get_name());
                        foreach ($terms as $term) {
                            $values[] = $term->name;
                        }
                        
                        $attribute_data[] = implode('|', $values);
                    } else {
                        $attributes[] = $attribute->get_name();
                        $attribute_data[] = implode('|', $attribute->get_options());
                    }
                    
                    $attribute_default[] = ''; // Default value only applies to variations
                    $attribute_visible[] = $attribute->get_visible() ? 1 : 0;
                    $attribute_global[] = $attribute->is_taxonomy() ? 1 : 0;
                }
                
                // Get variations if it's a variable product
                $variations = [];
                if ($product->is_type('variable')) {
                    $product_variations = $product->get_children();
                    foreach ($product_variations as $variation_id) {
                        $variation = wc_get_product($variation_id);
                        $variations[] = $variation_id;
                    }
                }
                
                // Get custom fields (excluding WooCommerce internal meta)
                $custom_fields = [];
                $meta_data = $product->get_meta_data();
                foreach ($meta_data as $meta) {
                    if (!in_array($meta->key, ['_price', '_regular_price', '_sale_price', '_sku', '_weight', '_length', '_width', '_height']) && strpos($meta->key, '_') !== 0) {
                        $custom_fields[] = $meta->key . '::' . maybe_serialize($meta->value);
                    }
                }
                
                // Get brand (assuming it's a custom taxonomy)
                $brand = '';
                $product_brand = get_the_terms($product->get_id(), 'product_brand');
                if ($product_brand && !is_wp_error($product_brand)) {
                    $brands = [];
                    foreach ($product_brand as $term) {
                        $brands[] = $term->name;
                    }
                    $brand = implode('|', $brands);
                }
                
                // Get all meta fields including WooCommerce internal ones
                $meta_fields = [];
                $all_meta = get_post_meta($product->get_id());
                foreach ($all_meta as $key => $values) {
                    if (is_array($values) && count($values) === 1) {
                        $meta_fields[] = $key . '::' . maybe_serialize($values[0]);
                    } else {
                        $meta_fields[] = $key . '::' . maybe_serialize($values);
                    }
                }
                
                // Add CSV row
                fputcsv($fp, [
                    $product->get_id(),
                    $product->get_type(),
                    $product->get_sku(),
                    $product->get_name(),
                    $product->get_status() === 'publish' ? 1 : 0,
                    $product->get_featured() ? 1 : 0,
                    $product->get_catalog_visibility(),
                    $product->get_short_description(),
                    $product->get_description(),
                    $product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->format('Y-m-d H:i:s') : '',
                    $product->get_date_on_sale_to() ? $product->get_date_on_sale_to()->format('Y-m-d H:i:s') : '',
                    $product->get_tax_status(),
                    $product->get_tax_class(),
                    $product->get_stock_status() === 'instock' ? 1 : 0,
                    $product->get_stock_quantity(),
                    $product->get_backorders() !== 'no' ? 1 : 0,
                    $product->get_sold_individually() ? 1 : 0,
                    $product->get_weight(),
                    $product->get_length(),
                    $product->get_width(),
                    $product->get_height(),
                    $product->get_reviews_allowed() ? 1 : 0,
                    $product->get_purchase_note(),
                    $product->get_sale_price(),
                    $product->get_regular_price(),
                    implode('|', $categories),
                    implode('|', $tags),
                    $product->get_shipping_class(),
                    implode('|', $images),
                    $product->get_download_limit(),
                    $product->get_download_expiry(),
                    $product->get_parent_id(),
                    $product->is_type('grouped') ? implode('|', $product->get_children()) : '',
                    implode('|', $product->get_upsell_ids()),
                    implode('|', $product->get_cross_sell_ids()),
                    $product->is_type('external') ? $product->get_product_url() : '',
                    $product->is_type('external') ? $product->get_button_text() : '',
                    $product->get_menu_order(),
                    implode('|', $attributes),
                    implode('|', $attribute_data),
                    implode('|', $attribute_default),
                    implode('|', $attribute_visible),
                    implode('|', $attribute_global),
                    implode('|', $variations),
                    implode('|', $custom_fields),
                    $brand,
                    implode('|', $meta_fields)
                ]);
                
                $success_count++;
            } catch (Exception $e) {
                $failed_count++;
            }
        }
    
        fclose($fp);
    
        $is_last_batch = ($batch_number + 1) >= $total_batches;
    
        if ($is_last_batch) {
            $download_url = str_replace(
                $upload_dir['basedir'], 
                $upload_dir['baseurl'], 
                $export_file
            );
        } else {
            $download_url = '';
        }
    
        wp_send_json_success([
            'success' => $success_count,
            'failed' => $failed_count,
            'is_last_batch' => $is_last_batch,
            'download_url' => $download_url
        ]);
    }
}

// Initialize the product exporter
new WC_Bulk_Product_Export();