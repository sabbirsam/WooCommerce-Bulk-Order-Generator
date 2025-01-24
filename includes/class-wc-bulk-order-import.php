<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Bulk_Order_Import {
   
    public function __construct() {
        add_action('wp_ajax_import_orders', [$this, 'handle_order_import']);
    }

    // Import 
    public function handle_order_import() {
        check_ajax_referer('import_orders_nonce', 'nonce');
    
        // Process uploaded CSV
        if (!isset($_FILES['csv_file'])) {
            wp_send_json_error('No file uploaded');
        }
    
        $file = $_FILES['csv_file']['tmp_name'];
        $batch_size = intval($_POST['batch_size'] ?? 50);
        $current_batch = intval($_POST['current_batch'] ?? 0);
    
        $orders = $this->parse_csv($file);
        $total_orders = count($orders);
    
        // Calculate the slice of orders for this batch
        $batch_orders = array_slice($orders, $current_batch * $batch_size, $batch_size);
    
        $results = $this->process_orders($batch_orders);
    
        // Determine if more batches are needed
        $is_complete = ($current_batch * $batch_size + count($batch_orders)) >= $total_orders;
    
        wp_send_json_success([
            'processed' => count($batch_orders),
            'successful' => $results['successful'],
            'failed' => $results['failed'],
            'skipped' => $results['skipped'],
            'total_orders' => $total_orders,
            'current_batch' => $current_batch,
            'is_complete' => $is_complete
        ]);
    }

    private function parse_csv($file) {
        $orders = [];
        if (($handle = fopen($file, "r")) !== FALSE) {
            // Skip header
            fgetcsv($handle);
            
            while (($data = fgetcsv($handle)) !== FALSE) {
                $orders[] = [
                    'order_id' => $data[0],
                    'date' => $data[1],
                    'status' => $data[2],
                    'total' => $data[3],
                    'customer_name' => $data[4],
                    'customer_email' => $data[5],
                    'shipping_method' => $data[6],
                    'payment_method' => $data[7]
                ];
            }
            fclose($handle);
        }
        return $orders;
    }

    private function process_orders($orders) {
        $successful = 0;
        $failed = 0;
        $skipped = 0;
    
        foreach ($orders as $order_data) {
            try {
                // Check if order with this custom order number already exists
                $existing_orders = wc_get_orders([
                    'meta_key' => '_order_number',
                    'meta_value' => $order_data['order_id'],
                    'numberposts' => 1
                ]);
    
                // Skip if order already exists
                if (!empty($existing_orders)) {
                    $skipped++;
                    continue;
                }
    
                // Create a new WooCommerce order
                $order = wc_create_order([
                    'status' => $order_data['status']
                ]);
    
                // Set custom order number
                update_post_meta($order->get_id(), '_order_number', $order_data['order_id']);
    
                // Set order data
                $order->set_date_created(wc_string_to_timestamp($order_data['date']));
                $order->set_total($order_data['total']);
    
                // Create or find customer
                $customer_id = email_exists($order_data['customer_email']);
                if (!$customer_id) {
                    $customer_id = wc_create_new_customer(
                        $order_data['customer_email'], 
                        sanitize_user(explode('@', $order_data['customer_email'])[0]),
                        wp_generate_password()
                    );
                }
    
                // Set customer
                $order->set_customer_id($customer_id);
    
                // Set billing and shipping details
                $name_parts = explode(' ', $order_data['customer_name'], 2);
                $order->set_billing_first_name($name_parts[0]);
                $order->set_billing_last_name($name_parts[1] ?? '');
                $order->set_billing_email($order_data['customer_email']);
    
                // Set shipping method if provided
                if (!empty($order_data['shipping_method'])) {
                    $shipping_item = new WC_Order_Item_Shipping();
                    $shipping_item->set_method_title($order_data['shipping_method']);
                    $shipping_item->set_total($order_data['total']); 
                    $order->add_item($shipping_item);
                }
    
                // Set payment method if provided
                if (!empty($order_data['payment_method'])) {
                    $order->set_payment_method($order_data['payment_method']);
                }
    
                // Save the order
                $order->save();
    
                $successful++;
            } catch (Exception $e) {
                error_log('Order import error: ' . $e->getMessage());
                $failed++;
            }
        }
    
        return [
            'successful' => $successful,
            'failed' => $failed,
            'skipped' => $skipped
        ];
    }

}

// Initialize the product generator
new WC_Bulk_Order_Import();
