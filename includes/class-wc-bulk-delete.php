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
        if (!check_ajax_referer('poc_ajax_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
    
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
    
        wp_cache_flush();
        
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
    
        error_log('Product count: ' . $product_count . ', Order count: ' . $order_count);
    
        wp_send_json_success(array(
            'product_count' => (int)$product_count,
            'order_count' => (int)$order_count
        ));
    }
    
    public function poc_delete_orders_batch() {
        if (!check_ajax_referer('poc_ajax_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
    
        $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
        $batch_size = 20; // Increased batch size
    
        $orders = wc_get_orders(array(
            'limit' => $batch_size,
            'offset' => $offset,
            'type' => 'shop_order',
        ));
    
        $deleted = 0;
        $skipped = 0;
        $errors = array();
    
        foreach ($orders as $order) {
            try {
                wp_cache_delete('order-' . $order->get_id(), 'orders');
                if ($order->delete(true)) {
                    $deleted++;
                    error_log('Successfully deleted order ID: ' . $order->get_id());
                } else {
                    $skipped++;
                    $errors[] = array(
                        'id' => $order->get_id(),
                        'error' => 'Failed to delete order'
                    );
                    error_log('Failed to delete order ID: ' . $order->get_id());
                }
            } catch (Exception $e) {
                $skipped++;
                $errors[] = array(
                    'id' => $order->get_id(),
                    'error' => $e->getMessage()
                );
                error_log('Exception while deleting order ID: ' . $order->get_id() . ', error: ' . $e->getMessage());
            }
        }
    
        if (!empty($errors)) {
            error_log('Errors during batch deletion: ' . print_r($errors, true));
        }
    
        wp_send_json_success(array(
            'deleted' => $deleted,
            'skipped' => $skipped,
            'errors' => $errors,
            'done' => count($orders) < $batch_size
        ));
    }
    
    public function poc_delete_products_batch() {
        if (!check_ajax_referer('poc_ajax_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
    
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
    
        $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
        $batch_size = 20; // Increased batch size
    
        $products = wc_get_products(array(
            'status' => 'any',
            'limit' => $batch_size,
            'offset' => $offset,
        ));
    
        $deleted = 0;
        $skipped = 0;
        $errors = array();
    
        foreach ($products as $product) {
            try {
                $deletion_successful = true;
    
                if ($product->is_type('variable')) {
                    $variations = $product->get_children();
                    foreach ($variations as $variation_id) {
                        wp_cache_delete('product-' . $variation_id, 'products');
                        $variation = wc_get_product($variation_id);
                        if ($variation && !$variation->delete(true)) {
                            $deletion_successful = false;
                            $errors[] = array(
                                'id' => $variation_id,
                                'error' => 'Failed to delete variation'
                            );
                        }
                    }
                }
    
                wp_cache_delete('product-' . $product->get_id(), 'products');
                if ($deletion_successful && $product->delete(true)) {
                    $deleted++;
                } else {
                    $skipped++;
                    $errors[] = array(
                        'id' => $product->get_id(),
                        'error' => 'Failed to delete product'
                    );
                }
            } catch (Exception $e) {
                $skipped++;
                $errors[] = array(
                    'id' => $product->get_id(),
                    'error' => $e->getMessage()
                );
                error_log('Exception while deleting product ID: ' . $product->get_id() . ', error: ' . $e->getMessage());
            }
        }
    
        if (!empty($errors)) {
            error_log('Errors during batch deletion: ' . print_r($errors, true));
        }
    
        wp_send_json_success(array(
            'deleted' => $deleted,
            'skipped' => $skipped,
            'errors' => $errors,
            'done' => count($products) < $batch_size
        ));
    }
    

}

// Initialize the product generator
new WC_Bulk_Delete();
