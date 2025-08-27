<?php
/**
 * Home Delivery API Handler
 * File: includes/class-home-delivery-api.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCHD_Home_Delivery_API {
    
    private static $instance = null;
    private $api_endpoint;
    private $api_key;
    private $enable_logging;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $settings = get_option('wchd_settings', array());
        $this->api_endpoint = isset($settings['api_endpoint']) ? rtrim($settings['api_endpoint'], '/') : 'https://api.homedelivery.com.au';
        $this->api_key = isset($settings['api_key']) ? $settings['api_key'] : '';
        $this->enable_logging = isset($settings['enable_logging']) ? $settings['enable_logging'] : false;
    }
    
    /**
     * Check if postcode is serviceable
     */
    public function check_postcode_serviceability($postcode, $suburb = '') {
        if (empty($this->api_endpoint) || empty($this->api_key)) {
            return new WP_Error('api_not_configured', 'API endpoint or key not configured');
        }
        
        // Clean postcode
        $postcode = preg_replace('/[^0-9]/', '', $postcode);
        
        if (empty($postcode)) {
            return new WP_Error('invalid_postcode', 'Invalid postcode format');
        }
        
        // First, get available suburbs for this postcode
        $suburbs_response = $this->get_postcode_suburbs($postcode);
        
        if (is_wp_error($suburbs_response)) {
            return $suburbs_response;
        }
        
        // If no suburb provided, return the list of available suburbs
        if (empty($suburb)) {
            return array(
                'require_suburb' => true,
                'postcode' => $postcode,
                'suburbs' => isset($suburbs_response['suburbs']) ? $suburbs_response['suburbs'] : array()
            );
        }
        
        // Clean suburb name
        $suburb = strtoupper(trim($suburb));
        
        // Check if the provided suburb is in the list of available suburbs
        $available_suburbs = array();
        if (isset($suburbs_response['suburbs']) && is_array($suburbs_response['suburbs'])) {
            $available_suburbs = array_map('strtoupper', $suburbs_response['suburbs']);
        }
        
        $matched_suburb = null;
        
        // Try exact match first
        if (in_array($suburb, $available_suburbs)) {
            $matched_suburb = $suburb;
        } else {
            // Try partial match
            foreach ($available_suburbs as $available_suburb) {
                if (strpos($available_suburb, $suburb) !== false || strpos($suburb, $available_suburb) !== false) {
                    $matched_suburb = $available_suburb;
                    break;
                }
            }
        }
        
        if (!$matched_suburb) {
            return new WP_Error('suburb_not_found', 'Suburb not found for this postcode');
        }
        
        // Now check if this specific suburb/postcode combination is serviceable
        $serviceable_response = $this->check_suburb_serviceable($matched_suburb, $postcode);
        
        if (is_wp_error($serviceable_response)) {
            return $serviceable_response;
        }
        
        if (!isset($serviceable_response['is_serviceable']) || !$serviceable_response['is_serviceable']) {
            return new WP_Error('not_serviceable', 'This area is not currently serviceable');
        }
        
        return array(
            'serviceable' => true,
            'postcode' => $postcode,
            'suburb' => $suburb,
            'matched_suburb' => $matched_suburb,
            'zone' => isset($serviceable_response['zone']) ? $serviceable_response['zone'] : '',
            'depot' => isset($serviceable_response['depot']) ? $serviceable_response['depot'] : ''
        );
    }
    
    /**
     * Get available suburbs for a postcode
     */
    private function get_postcode_suburbs($postcode) {
        $url = $this->api_endpoint . '/api/serviceable/postcode/' . $postcode;
        
        $response = $this->make_api_request($url);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return $response;
    }
    
    /**
     * Check if specific suburb/postcode is serviceable
     */
    private function check_suburb_serviceable($suburb, $postcode) {
        $url = $this->api_endpoint . '/api/serviceable?' . http_build_query(array(
            'suburb' => $suburb,
            'postcode' => $postcode
        ));
        
        $response = $this->make_api_request($url);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return $response;
    }
    
    /**
     * Get delivery dates
     */
    public function get_delivery_dates($suburb = '', $postcode = '') {
        if (empty($this->api_endpoint) || empty($this->api_key)) {
            return new WP_Error('api_not_configured', 'API endpoint or key not configured');
        }
        
        if (empty($suburb) || empty($postcode)) {
            return new WP_Error('missing_location', 'Suburb and postcode required for delivery dates');
        }
        
        $url = $this->api_endpoint . '/api/serviceable/service-days?' . http_build_query(array(
            'suburb' => strtoupper($suburb),
            'postcode' => $postcode,
            'weeksToShow' => 2
        ));
        
        $response = $this->make_api_request($url);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return $this->process_delivery_dates($response);
    }
    
    /**
     * Process delivery dates
     */
    private function process_delivery_dates($api_response) {
        if (!isset($api_response['days']) || !is_array($api_response['days'])) {
            return new WP_Error('invalid_response', 'No delivery days found in API response');
        }
        
        $delivery_dates = array();
        
        foreach ($api_response['days'] as $day) {
            if (!isset($day['nextDeliveryDate']) || empty($day['nextDeliveryDate'])) {
                continue;
            }
            
            $delivery_dates[] = array(
                'date' => date('Y-m-d', strtotime($day['nextDeliveryDate'])),
                'display' => date('l, F j, Y', strtotime($day['nextDeliveryDate'])),
                'day' => isset($day['day']) ? $day['day'] : ''
            );
        }
        
        return array(
            'zone' => isset($api_response['zone']) ? $api_response['zone'] : '',
            'depot' => isset($api_response['depot']) ? $api_response['depot'] : '',
            'cutoff_time' => isset($api_response['cutoffTime']) ? $api_response['cutoffTime'] : '12:00',
            'dates' => $delivery_dates
        );
    }
    
    /**
     * Make API request
     */
    private function make_api_request($url, $method = 'GET') {
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        );
        
        if ($this->enable_logging) {
            error_log('[WCHD API] Request: ' . $method . ' ' . $url);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            if ($this->enable_logging) {
                error_log('[WCHD API] Error: ' . $response->get_error_message());
            }
            return new WP_Error('api_request_failed', 'API request failed: ' . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($this->enable_logging) {
            error_log('[WCHD API] Response: ' . $status_code . ' - ' . $body);
        }
        
        if ($status_code === 401) {
            return new WP_Error('unauthorized', 'API authentication failed. Please check your API key.');
        }
        
        if ($status_code >= 400) {
            return new WP_Error('api_error', 'API request failed with status: ' . $status_code);
        }
        
        $decoded = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_json', 'Invalid JSON response from API');
        }
        
        return $decoded;
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        if (empty($this->api_endpoint) || empty($this->api_key)) {
            return new WP_Error('api_not_configured', 'API endpoint or key not configured');
        }
        
        // Test with a known postcode
        $response = $this->get_postcode_suburbs('3000');
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return array(
            'success' => true,
            'message' => 'API connection successful',
            'test_data' => $response
        );
    }
}