<?php
/**
 * Checkout Fields Handler
 * File: includes/class-checkout-fields.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCHD_Checkout_Fields {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Add delivery fields to checkout
        add_action('woocommerce_checkout_fields', array($this, 'add_delivery_fields'));
        
        // Validate delivery fields
        add_action('woocommerce_checkout_process', array($this, 'validate_delivery_fields'));
        
        // Save delivery data to order
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_delivery_data'));
        
        // Display delivery data in admin order
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_delivery_data_admin'));
        
        // Display delivery data in customer order
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_delivery_data_customer'));
        
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_check_postcode_serviceability', array($this, 'ajax_check_postcode_serviceability'));
        add_action('wp_ajax_nopriv_check_postcode_serviceability', array($this, 'ajax_check_postcode_serviceability'));
        add_action('wp_ajax_get_delivery_dates', array($this, 'ajax_get_delivery_dates'));
        add_action('wp_ajax_nopriv_get_delivery_dates', array($this, 'ajax_get_delivery_dates'));
        add_action('wp_ajax_wchd_track_delivery_issue', array($this, 'ajax_track_delivery_issue'));
        add_action('wp_ajax_nopriv_wchd_track_delivery_issue', array($this, 'ajax_track_delivery_issue'));
    }
    
    /**
     * Add delivery fields to checkout
     */
    public function add_delivery_fields($fields) {
        // Only add fields if delivery is needed
        if (!$this->needs_delivery()) {
            return $fields;
        }
        
        // Add delivery fields section
        $fields['delivery'] = array(
            'delivery_postcode' => array(
                'type' => 'hidden',
                'default' => '',
            ),
            'delivery_suburb' => array(
                'type' => 'hidden',
                'default' => '',
            ),
            'delivery_zone' => array(
                'type' => 'hidden',
                'default' => '',
            ),
            'delivery_depot' => array(
                'type' => 'hidden',
                'default' => '',
            ),
            'is_serviceable' => array(
                'type' => 'hidden',
                'default' => '0',
            ),
            'delivery_date' => array(
                'type' => 'hidden',
                'default' => '',
                'required' => false,
            ),
        );
        
        return $fields;
    }
    
    /**
     * Check if delivery is needed for current cart
     */
    private function needs_delivery() {
        if (!WC()->cart) {
            return false;
        }
        
        // Check if any items in cart need delivery
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            
            // Skip virtual/downloadable products
            if ($product->is_virtual() || $product->is_downloadable()) {
                continue;
            }
            
            // This product needs delivery
            return true;
        }
        
        return false;
    }
    
    /**
     * Validate delivery fields
     */
    public function validate_delivery_fields() {
        // Only validate if delivery is needed
        if (!$this->needs_delivery()) {
            return;
        }
        
        // Get address data to check if we have a valid postcode
        $ship_to_different = isset($_POST['ship_to_different_address']) ? true : false;
        
        if ($ship_to_different) {
            $postcode = isset($_POST['shipping_postcode']) ? sanitize_text_field($_POST['shipping_postcode']) : '';
            $suburb = isset($_POST['shipping_city']) ? sanitize_text_field($_POST['shipping_city']) : '';
            $state = isset($_POST['shipping_state']) ? sanitize_text_field($_POST['shipping_state']) : '';
        } else {
            $postcode = isset($_POST['billing_postcode']) ? sanitize_text_field($_POST['billing_postcode']) : '';
            $suburb = isset($_POST['billing_city']) ? sanitize_text_field($_POST['billing_city']) : '';
            $state = isset($_POST['billing_state']) ? sanitize_text_field($_POST['billing_state']) : '';
        }
        
        // If no postcode, skip validation
        if (empty($postcode)) {
            return;
        }
        
        // If not Victoria, skip validation
        if (!empty($state) && $state !== 'VIC') {
            return;
        }
        
        // Validate Victoria postcode format
        if (strlen($postcode) === 4 && is_numeric($postcode)) {
            $postcodeInt = intval($postcode);
            if (($postcodeInt >= 3000 && $postcodeInt <= 3999) || ($postcodeInt >= 8000 && $postcodeInt <= 8999)) {
                // Valid Victoria postcode - allow checkout to proceed
                // Don't require serviceability check to be completed
                return;
            }
        }
        
        // Only show error if they have an invalid postcode format
        if (!empty($postcode) && strlen($postcode) === 4 && is_numeric($postcode)) {
            $postcodeInt = intval($postcode);
            if (!(($postcodeInt >= 3000 && $postcodeInt <= 3999) || ($postcodeInt >= 8000 && $postcodeInt <= 8999))) {
                wc_add_notice(__('Sorry, we only deliver to Victoria postcodes (3000-3999, 8000-8999).', 'woocommerce-home-delivery-date'), 'error');
            }
        }
    }
    
    /**
     * Save delivery data to order
     */
    public function save_delivery_data($order_id) {
        if (!$this->needs_delivery()) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Save delivery data
        $delivery_fields = array(
            '_delivery_postcode' => 'delivery_postcode',
            '_delivery_suburb' => 'delivery_suburb',
            '_delivery_zone' => 'delivery_zone',
            '_delivery_depot' => 'delivery_depot',
            '_delivery_date' => 'delivery_date',
        );
        
        foreach ($delivery_fields as $meta_key => $field_key) {
            if (isset($_POST[$field_key])) {
                $value = sanitize_text_field($_POST[$field_key]);
                if (!empty($value)) {
                    $order->update_meta_data($meta_key, $value);
                }
            }
        }
        
        // Also save the actual address data used
        $ship_to_different = isset($_POST['ship_to_different_address']) ? true : false;
        
        if ($ship_to_different) {
            $postcode = isset($_POST['shipping_postcode']) ? sanitize_text_field($_POST['shipping_postcode']) : '';
            $suburb = isset($_POST['shipping_city']) ? sanitize_text_field($_POST['shipping_city']) : '';
        } else {
            $postcode = isset($_POST['billing_postcode']) ? sanitize_text_field($_POST['billing_postcode']) : '';
            $suburb = isset($_POST['billing_city']) ? sanitize_text_field($_POST['billing_city']) : '';
        }
        
        if (!empty($postcode)) {
            $order->update_meta_data('_delivery_postcode', $postcode);
        }
        if (!empty($suburb)) {
            $order->update_meta_data('_delivery_suburb', $suburb);
        }
        
        $order->save();
    }
    
    /**
     * Display delivery data in admin order
     */
    public function display_delivery_data_admin($order) {
        $delivery_date = $order->get_meta('_delivery_date');
        $delivery_zone = $order->get_meta('_delivery_zone');
        $delivery_suburb = $order->get_meta('_delivery_suburb');
        $delivery_postcode = $order->get_meta('_delivery_postcode');
        
        if ($delivery_date || $delivery_zone || $delivery_suburb) {
            echo '<div class="address">';
            echo '<p><strong>' . __('Delivery Information:', 'woocommerce-home-delivery-date') . '</strong></p>';
            
            if ($delivery_date) {
                $formatted_date = date('l, F j, Y', strtotime($delivery_date));
                echo '<p><strong>' . __('Delivery Date:', 'woocommerce-home-delivery-date') . '</strong> ' . esc_html($formatted_date) . '</p>';
            }
            
            if ($delivery_zone) {
                echo '<p><strong>' . __('Delivery Zone:', 'woocommerce-home-delivery-date') . '</strong> ' . esc_html($delivery_zone) . '</p>';
            }
            
            if ($delivery_suburb && $delivery_postcode) {
                echo '<p><strong>' . __('Delivery Area:', 'woocommerce-home-delivery-date') . '</strong> ' . esc_html($delivery_suburb) . ' ' . esc_html($delivery_postcode) . '</p>';
            }
            
            echo '</div>';
        }
    }
    
    /**
     * Display delivery data in customer order
     */
    public function display_delivery_data_customer($order) {
        $delivery_date = $order->get_meta('_delivery_date');
        $delivery_zone = $order->get_meta('_delivery_zone');
        
        if ($delivery_date || $delivery_zone) {
            echo '<section class="woocommerce-delivery-details">';
            echo '<h2 class="woocommerce-column__title">' . __('Delivery Information', 'woocommerce-home-delivery-date') . '</h2>';
            
            if ($delivery_date) {
                $formatted_date = date('l, F j, Y', strtotime($delivery_date));
                echo '<p><strong>' . __('Delivery Date:', 'woocommerce-home-delivery-date') . '</strong> ' . esc_html($formatted_date) . '</p>';
            }
            
            if ($delivery_zone) {
                echo '<p><strong>' . __('Delivery Zone:', 'woocommerce-home-delivery-date') . '</strong> ' . esc_html($delivery_zone) . '</p>';
            }
            
            echo '</section>';
        }
    }
    
    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        if (is_checkout() && $this->needs_delivery()) {
            wp_enqueue_style('wchd-checkout', WCHD_PLUGIN_URL . 'assets/css/checkout.css', array(), WCHD_VERSION);
            wp_enqueue_script('wchd-checkout', WCHD_PLUGIN_URL . 'assets/js/checkout.js', array('jquery'), WCHD_VERSION, true);
            
            wp_localize_script('wchd-checkout', 'wchd_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wchd_checkout_nonce'),
                'needs_delivery' => 'yes'
            ));
        }
    }
    
    /**
     * AJAX handler for checking postcode serviceability
     */
    public function ajax_check_postcode_serviceability() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'wchd_checkout_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $postcode = sanitize_text_field($_POST['postcode']);
        $suburb = sanitize_text_field($_POST['suburb']);
        
        if (empty($postcode)) {
            wp_send_json_error(array('message' => 'Postcode is required'));
        }
        
        // Get API instance
        $api = WCHD_Home_Delivery_API::get_instance();
        
        // Check serviceability
        $result = $api->check_postcode_serviceability($postcode, $suburb);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX handler for getting delivery dates
     */
    public function ajax_get_delivery_dates() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'wchd_checkout_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        // Get API instance
        $api = WCHD_Home_Delivery_API::get_instance();
        
        // Get delivery dates
        $result = $api->get_delivery_dates();
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX handler for tracking delivery issues
     */
    public function ajax_track_delivery_issue() {
        $issue_type = sanitize_text_field($_POST['issue_type']);
        $postcode = sanitize_text_field($_POST['postcode']);
        $suburb = sanitize_text_field($_POST['suburb']);
        $customer_email = sanitize_email($_POST['customer_email']);
        
        // Log the issue
        $log_data = array(
            'timestamp' => current_time('mysql'),
            'issue_type' => $issue_type,
            'postcode' => $postcode,
            'suburb' => $suburb,
            'customer_email' => $customer_email,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
        );
        
        // Add any additional data
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'error_') === 0 || $key === 'error_message') {
                $log_data[$key] = sanitize_text_field($value);
            }
        }
        
        // Store in WordPress options (you might want to use a custom table for better performance)
        $existing_issues = get_option('wchd_delivery_issues', array());
        $existing_issues[] = $log_data;
        
        // Keep only last 1000 issues to prevent database bloat
        if (count($existing_issues) > 1000) {
            $existing_issues = array_slice($existing_issues, -1000);
        }
        
        update_option('wchd_delivery_issues', $existing_issues);
        
        wp_send_json_success();
    }
}