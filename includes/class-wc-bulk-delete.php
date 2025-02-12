<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Bulk_Delete {

    public function __construct() {
        add_action('wp_ajax_poc_get_counts', array($this, 'poc_get_counts'));
        add_action('wp_ajax_poc_delete_orders_batch', array($this, 'poc_delete_orders_batch'));
        add_action('wp_ajax_poc_delete_products_batch', array($this, 'poc_delete_products_batch'));
    }


    public function poc_get_counts() {
        check_ajax_referer('poc_ajax_nonce', 'poc_nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
    
        $product_count = count(wc_get_products(array(
            'limit' => -1,
            'status' => 'any',
            'return' => 'ids',
        )));
    
        $order_count = count(wc_get_orders(array(
            'limit' => -1,
            'type' => 'shop_order',
            'return' => 'ids',
        )));
        
        wp_send_json_success(array(
            'product_count' => (int)$product_count,
            'order_count' => (int)$order_count
        ));
    }

    public function poc_delete_orders_batch() {
        check_ajax_referer('poc_ajax_nonce', 'poc_nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
    
        $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
        $batch_size = 40;
        
        // Get batch of orders
        $orders = wc_get_orders(array(
            'limit' => $batch_size,
            'offset' => $offset,
            'type' => 'shop_order',
        ));
        
        $deleted = 0;
        $skipped = 0;
        $skipped_ids = array();
        
        foreach ($orders as $order) {
            try {
                // Delete the order
                if ($order->delete(true)) {
                    $deleted++;
                } else {
                    $skipped++;
                    $skipped_ids[] = array(
                        'id' => $order->get_id(),
                        'number' => $order->get_order_number()
                    );
                }
            } catch (Exception $e) {
                $skipped++;
                $skipped_ids[] = array(
                    'id' => $order->get_id(),
                    'number' => $order->get_order_number(),
                    'error' => $e->getMessage()
                );
                continue;
            }
        }
    
        // Log skipped orders
        if (!empty($skipped_ids)) {
            error_log('Skipped orders during bulk deletion: ' . print_r($skipped_ids, true));
        }
        
        wp_send_json_success(array(
            'deleted' => $deleted,
            'skipped' => $skipped,
            'skipped_ids' => $skipped_ids,
            'done' => count($orders) < $batch_size
        ));
    }

    public function poc_delete_products_batch() {
        check_ajax_referer('poc_ajax_nonce', 'poc_nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
    
        $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
        $batch_size = 40;
        
        // Get batch of products
        $products = wc_get_products(array(
            'status' => 'any',
            'limit' => $batch_size,
            'offset' => $offset,
        ));
        
        $deleted = 0;
        $skipped = 0;
        $skipped_ids = array();
        
        foreach ($products as $product) {
            try {
                $deletion_successful = true;
                
                // Delete variations first if it's a variable product
                if ($product->is_type('variable')) {
                    $variations = $product->get_children();
                    foreach ($variations as $variation_id) {
                        $variation = wc_get_product($variation_id);
                        if ($variation && !$variation->delete(true)) {
                            $deletion_successful = false;
                        }
                    }
                }
                
                // Delete the product
                if ($deletion_successful && $product->delete(true)) {
                    $deleted++;
                } else {
                    $skipped++;
                    $skipped_ids[] = array(
                        'id' => $product->get_id(),
                        'name' => $product->get_name()
                    );
                }
            } catch (Exception $e) {
                $skipped++;
                $skipped_ids[] = array(
                    'id' => $product->get_id(),
                    'name' => $product->get_name(),
                    'error' => $e->getMessage()
                );
                continue;
            }
        }
    
        // Log skipped products
        if (!empty($skipped_ids)) {
            error_log('Skipped products during bulk deletion: ' . print_r($skipped_ids, true));
        }
        
        wp_send_json_success(array(
            'deleted' => $deleted,
            'skipped' => $skipped,
            'skipped_ids' => $skipped_ids,
            'done' => count($products) < $batch_size
        ));
    }
    

}

// Initialize the product generator
new WC_Bulk_Delete();
