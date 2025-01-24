<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Bulk_Order_Export {
    
    public function __construct() {
        add_action('wp_ajax_start_order_export', [$this, 'start_order_export']);
        add_action('wp_ajax_export_order_batch', [$this, 'export_order_batch']);
    }

    // Export 
    public function start_order_export() {
        check_ajax_referer('export_orders_nonce', 'nonce');
    
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
    
        $export_all = isset($_POST['export_all']) ? filter_var($_POST['export_all'], FILTER_VALIDATE_BOOLEAN) : false;
        $date_from = $export_all ? null : sanitize_text_field($_POST['date_from']);
        $date_to = $export_all ? null : sanitize_text_field($_POST['date_to']);
        $statuses = isset($_POST['statuses']) ? array_map('sanitize_text_field', $_POST['statuses']) : [];
    
        $args = [
            'type' => 'shop_order',
            'limit' => -1 // Get total count
        ];
    
        // Apply date range filter only if not exporting all
        if (!$export_all && $date_from && $date_to) {
            $args['date_created'] = [
                'start' => $date_from . ' 00:00:00',
                'end' => $date_to . ' 23:59:59'
            ];
        }
    
        // Apply status filter
        if (!empty($statuses)) {
            $args['status'] = $statuses;
        }
    
        $orders = wc_get_orders($args);
        $total_orders = count($orders);
    
        // Generate a unique session ID
        $export_session = uniqid('wc_bulk_export_');
    
        wp_send_json_success([
            'export_session' => $export_session,
            'total_orders' => $total_orders
        ]);
    }

    
    
    public function export_order_batch() {
        check_ajax_referer('export_orders_nonce', 'nonce');
    
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
    
        $batch_size = intval($_POST['batch_size']);
        $batch_number = intval($_POST['batch_number']);
        $total_batches = intval($_POST['total_batches']);
        $export_session = sanitize_text_field($_POST['export_session']);
        $export_all = isset($_POST['export_all']) ? filter_var($_POST['export_all'], FILTER_VALIDATE_BOOLEAN) : false;
        $date_from = $export_all ? null : sanitize_text_field($_POST['date_from']);
        $date_to = $export_all ? null : sanitize_text_field($_POST['date_to']);
        $statuses = isset($_POST['statuses']) ? array_map('sanitize_text_field', $_POST['statuses']) : [];
    
        $args = [
            'type' => 'shop_order',
            'limit' => $batch_size,
            'offset' => $batch_number * $batch_size
        ];
    
        // Apply date range filter only if not exporting all
        if (!$export_all && $date_from && $date_to) {
            $args['date_created'] = [
                'start' => $date_from . ' 00:00:00',
                'end' => $date_to . ' 23:59:59'
            ];
        }
    
        // Apply status filter
        if (!empty($statuses)) {
            $args['status'] = $statuses;
        }
    
        $orders = wc_get_orders($args);
    
        $upload_dir = wp_upload_dir();
        $export_file = $upload_dir['basedir'] . '/wc-bulk-export-' . sanitize_file_name($export_session) . '.csv';
    
        // Open/append to CSV file
        $file_mode = ($batch_number == 0) ? 'w' : 'a';
        $fp = fopen($export_file, $file_mode);
    
        // Write headers on first batch
        if ($batch_number == 0) {
            fputcsv($fp, [
                'Order ID', 
                'Date', 
                'Status', 
                'Total', 
                'Customer Name', 
                'Customer Email',
                'Shipping Method',
                'Payment Method'
            ]);
        }
    
        $success_count = 0;
        $failed_count = 0;
    
        foreach ($orders as $order) {
            try {
                // Get shipping method
                $shipping_method = '';
                $shipping_methods = $order->get_shipping_methods();
                if (!empty($shipping_methods)) {
                    $shipping_method = reset($shipping_methods)->get_method_title();
                }
    
                // Get payment method
                $payment_method = $order->get_payment_method_title();
    
                fputcsv($fp, [
                    $order->get_id(),
                    $order->get_date_created()->format('Y-m-d H:i:s'),
                    $order->get_status(),
                    $order->get_total(),
                    $order->get_formatted_billing_full_name(),
                    $order->get_billing_email(),
                    $shipping_method,
                    $payment_method
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

// Initialize the product generator
new WC_Bulk_Order_Export();
