<?php
/**
 * Home Delivery API Integration Class
 * File: includes/class-home-delivery-api.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCHD_Home_Delivery_API {
    
    private $api_url;
    private $api_token;
    private $sandbox_mode;
    
    public function __construct() {
        $this->sandbox_mode = get_option('wchd_sandbox_mode', 'yes') === 'yes';
        $this->api_url = $this->sandbox_mode ? 'https://api.sandbox.homedelivery.com.au' : 'https://api.homedelivery.com.au';
        $this->api_token = get_option('wchd_api_token', '');
        
        // Add AJAX handlers
        add_action('wp_ajax_check_postcode_serviceability', array($this, 'ajax_check_postcode'));
        add_action('wp_ajax_nopriv_check_postcode_serviceability', array($this, 'ajax_check_postcode'));
        
        add_action('wp_ajax_get_delivery_dates', array($this, 'ajax_get_delivery_dates'));
        add_action('wp_ajax_nopriv_get_delivery_dates', array($this, 'ajax_get_delivery_dates'));
    }
    
    /**
     * Check if postcode is serviceable
     */
    public function check_serviceability($suburb, $postcode) {
        $endpoint = '/api/serviceable';
        
        // Normalize suburb - capitalize first letter of each word
        $suburb = ucwords(strtolower(trim($suburb)));
        
        $params = array(
            'suburb' => $suburb,
            'postcode' => $postcode
        );
        
        $response = $this->make_api_request($endpoint, 'GET', $params);
        
        if ($response && isset($response['is_serviceable'])) {
            // Check if it's in Victoria
            if ($this->is_victoria_postcode($postcode)) {
                return $response;
            }
        }
        
        // If first attempt fails, try to find matching suburb
        if (!$response || !$response['is_serviceable']) {
            $possible_suburbs = $this->get_suburbs_for_postcode($postcode);
            if ($possible_suburbs) {
                // Try to find a matching suburb (case-insensitive)
                foreach ($possible_suburbs as $possible_suburb) {
                    if (strcasecmp($suburb, $possible_suburb) === 0) {
                        // Try again with properly cased suburb
                        $params['suburb'] = $possible_suburb;
                        $response = $this->make_api_request($endpoint, 'GET', $params);
                        if ($response && $response['is_serviceable']) {
                            return $response;
                        }
                    }
                }
                
                // If no exact match, try partial match
                foreach ($possible_suburbs as $possible_suburb) {
                    if (stripos($possible_suburb, $suburb) !== false || stripos($suburb, $possible_suburb) !== false) {
                        $params['suburb'] = $possible_suburb;
                        $response = $this->make_api_request($endpoint, 'GET', $params);
                        if ($response && $response['is_serviceable']) {
                            return $response;
                        }
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * Get service days for a suburb and postcode
     */
    public function get_service_days($suburb, $postcode) {
        $endpoint = '/api/serviceable/service-days';
        $params = array(
            'suburb' => strtoupper($suburb),
            'postcode' => $postcode,
            'weeksToShow' => 4 // Show 4 weeks of delivery dates
        );
        
        $response = $this->make_api_request($endpoint, 'GET', $params);
        
        if ($response && isset($response['days'])) {
            return $this->format_delivery_dates($response);
        }
        
        return false;
    }
    
    /**
     * Get suburbs for a postcode
     */
    public function get_suburbs_for_postcode($postcode) {
        $endpoint = '/api/serviceable/postcode/' . $postcode;
        
        $response = $this->make_api_request($endpoint, 'GET');
        
        if ($response && isset($response['suburbs'])) {
            return $response['suburbs'];
        }
        
        return false;
    }
    
    /**
     * Check if postcode is in Victoria
     */
    private function is_victoria_postcode($postcode) {
        // Victoria postcodes range from 3000-3999 and 8000-8999
        $postcode = intval($postcode);
        return ($postcode >= 3000 && $postcode <= 3999) || ($postcode >= 8000 && $postcode <= 8999);
    }
    
    /**
     * Format delivery dates for frontend display
     */
    private function format_delivery_dates($response) {
        $formatted_dates = array();
        
        // Set timezone to Melbourne/Sydney for Australian Eastern Time
        $timezone = new DateTimeZone('Australia/Melbourne');
        $now = new DateTime('now', $timezone);
        
        // Get cutoff time from settings
        $cutoff_time = get_option('wchd_cutoff_time', '06:00');
        $cutoff_parts = explode(':', $cutoff_time);
        $cutoff_hour = isset($cutoff_parts[0]) ? intval($cutoff_parts[0]) : 6;
        $cutoff_minute = isset($cutoff_parts[1]) ? intval($cutoff_parts[1]) : 0;
        
        // Get current time
        $current_hour = (int)$now->format('H');
        $current_minute = (int)$now->format('i');
        
        // Check if we've passed cutoff time
        $past_cutoff = false;
        if ($current_hour > $cutoff_hour || ($current_hour === $cutoff_hour && $current_minute >= $cutoff_minute)) {
            $past_cutoff = true;
        }
        
        // Calculate minimum delivery date
        $min_date = new DateTime('now', $timezone);
        if ($past_cutoff) {
            // After cutoff, need to add 2 days (can't deliver tomorrow)
            $min_date->add(new DateInterval('P2D'));
        } else {
            // Before cutoff, can still deliver tomorrow
            $min_date->add(new DateInterval('P1D'));
        }
        
        // Reset time to start of day for comparison
        $min_date->setTime(0, 0, 0);
        
        if (isset($response['days']) && is_array($response['days'])) {
            foreach ($response['days'] as $day) {
                if (isset($day['nextDeliveryDate'])) {
                    $date = new DateTime($day['nextDeliveryDate'], $timezone);
                    
                    // Only include dates that are after our minimum date
                    if ($date >= $min_date) {
                        $formatted_dates[] = array(
                            'date' => $date->format('Y-m-d'),
                            'display' => $date->format('l, F j, Y'),
                            'day' => $day['day'],
                            'windows' => isset($day['deliveryWindow']) ? $day['deliveryWindow'] : array()
                        );
                    }
                }
            }
        }
        
        // Format cutoff time for display
        $cutoff_display = DateTime::createFromFormat('H:i', $cutoff_time)->format('g:i A');
        
        // Add current time info for display
        $cutoff_status = $past_cutoff ? 
            'Orders placed now will be delivered from ' . $min_date->format('l, F j') : 
            'Orders placed before ' . $cutoff_display . ' can be delivered tomorrow';
        
        return array(
            'dates' => $formatted_dates,
            'zone' => $response['zone'],
            'depot' => $response['depot'],
            'cutoff_days' => 1,
            'cutoff_time' => $cutoff_display,
            'cutoff_status' => $cutoff_status,
            'current_time' => $now->format('g:i A'),
            'available_days' => $response['days'] // Include all days for calendar
        );
    }
    
    /**
     * Make API request
     */
    private function make_api_request($endpoint, $method = 'GET', $params = array()) {
        $url = $this->api_url . $endpoint;
        
        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        );
        
        if ($method === 'POST' && !empty($params)) {
            $args['body'] = json_encode($params);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            error_log('Home Delivery API Error: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (wp_remote_retrieve_response_code($response) !== 200) {
            error_log('Home Delivery API Error Response: ' . print_r($data, true));
            return false;
        }
        
        return $data;
    }
    
    /**
     * AJAX handler for checking postcode
     */
    public function ajax_check_postcode() {
        check_ajax_referer('wchd_nonce', 'nonce');
        
        $postcode = isset($_POST['postcode']) ? sanitize_text_field($_POST['postcode']) : '';
        $suburb = isset($_POST['suburb']) ? sanitize_text_field($_POST['suburb']) : '';
        
        if (empty($postcode)) {
            wp_send_json_error(array('message' => 'Please enter a postcode'));
        }
        
        // Check if Victoria postcode
        if (!$this->is_victoria_postcode($postcode)) {
            wp_send_json_error(array('message' => 'Sorry, we only deliver to Victoria postcodes (3000-3999, 8000-8999)'));
        }
        
        // Get suburbs for postcode
        $suburbs = $this->get_suburbs_for_postcode($postcode);
        
        if (!$suburbs || count($suburbs) == 0) {
            wp_send_json_error(array('message' => 'This postcode is not in our delivery area'));
        }
        
        // If we have a suburb, try to match it
        if (!empty($suburb)) {
            // Normalize the suburb
            $suburb = ucwords(strtolower(trim($suburb)));
            
            // First try exact match
            $result = $this->check_serviceability($suburb, $postcode);
            if ($result && $result['is_serviceable']) {
                $this->store_in_session($postcode, $suburb, $result);
                wp_send_json_success(array(
                    'serviceable' => true,
                    'zone' => $result['zone'],
                    'depot' => $result['depot']
                ));
            }
            
            // If that didn't work, check all suburbs for this postcode
            foreach ($suburbs as $possible_suburb) {
                if (strcasecmp($suburb, $possible_suburb) === 0 || 
                    stripos($possible_suburb, $suburb) !== false || 
                    stripos($suburb, $possible_suburb) !== false) {
                    
                    $result = $this->check_serviceability($possible_suburb, $postcode);
                    if ($result && $result['is_serviceable']) {
                        $this->store_in_session($postcode, $possible_suburb, $result);
                        wp_send_json_success(array(
                            'serviceable' => true,
                            'zone' => $result['zone'],
                            'depot' => $result['depot'],
                            'matched_suburb' => $possible_suburb
                        ));
                    }
                }
            }
        }
        
        // If only one suburb, use it automatically
        if (count($suburbs) == 1) {
            $suburb = $suburbs[0];
            $result = $this->check_serviceability($suburb, $postcode);
            
            if ($result && $result['is_serviceable']) {
                $this->store_in_session($postcode, $suburb, $result);
                wp_send_json_success(array(
                    'serviceable' => true,
                    'zone' => $result['zone'],
                    'depot' => $result['depot'],
                    'auto_suburb' => $suburb
                ));
            }
        }
        
        // Multiple suburbs - need user to be more specific
        if (count($suburbs) > 1) {
            wp_send_json_success(array(
                'suburbs' => $suburbs,
                'require_suburb' => true,
                'message' => 'Multiple suburbs found for this postcode. Please enter your suburb to continue.'
            ));
        }
        
        wp_send_json_error(array('message' => 'Sorry, we don\'t deliver to this area'));
    }
    
    /**
     * Store delivery info in session
     */
    private function store_in_session($postcode, $suburb, $result) {
        if (class_exists('WC_Session')) {
            if (!WC()->session) {
                WC()->session = new WC_Session_Handler();
                WC()->session->init();
            }
            WC()->session->set('delivery_postcode', $postcode);
            WC()->session->set('delivery_suburb', $suburb);
            WC()->session->set('delivery_zone', $result['zone']);
        }
    }
    
    /**
     * AJAX handler for getting delivery dates
     */
    public function ajax_get_delivery_dates() {
        check_ajax_referer('wchd_nonce', 'nonce');
        
        $postcode = '';
        $suburb = '';
        
        // Get from session if available
        if (class_exists('WC_Session') && WC()->session) {
            $postcode = WC()->session->get('delivery_postcode');
            $suburb = WC()->session->get('delivery_suburb');
        }
        
        if (empty($postcode) || empty($suburb)) {
            wp_send_json_error(array('message' => 'Please check your postcode first'));
        }
        
        $dates = $this->get_service_days($suburb, $postcode);
        
        if ($dates) {
            wp_send_json_success($dates);
        } else {
            wp_send_json_error(array('message' => 'Unable to retrieve delivery dates'));
        }
    }
}