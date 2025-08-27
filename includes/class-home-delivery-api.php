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
        $this->api_endpoint = isset($settings['api_endpoint']) ? rtrim($settings['api_endpoint'], '/') : '';
        $this->api_key = isset($settings['api_key']) ? $settings['api_key'] : '';
        $this->enable_logging = isset($settings['enable_logging']) ? $settings['enable_logging'] : false;
    }
    
    public function check_postcode_serviceability($postcode, $suburb = '') {
        if (empty($this->api_endpoint) || empty($this->api_key)) {
            return new WP_Error('api_not_configured', 'API endpoint or key not configured');
        }
        
        $postcode = preg_replace('/[^0-9]/', '', $postcode);
        
        if (empty($postcode)) {
            return new WP_Error('invalid_postcode', 'Invalid postcode format');
        }
        
        $suburbs_response = $this->get_postcode_suburbs($postcode);
        
        if (is_wp_error($suburbs_response)) {
            return $suburbs_response;
        }
        
        if (empty($suburb)) {
            return array(
                'require_suburb' => true,
                'postcode' => $postcode,
                'suburbs' => $suburbs_response['suburbs']
            );
        }
        
        $suburb = strtoupper(trim($suburb));
        $available_suburbs = array_map('strtoupper', $suburbs_response['suburbs']);
        $matched_suburb = null;
        
        if (in_array($suburb, $available_suburbs)) {
            $matched_suburb = $suburb;
        } else {
            foreach ($available_suburbs as $available_suburb) {
                if (strpos($available_suburb, $suburb) !== false || strpos($suburb, $available_suburb) !== false) {
                    $matched_suburb = $available_suburb;
                    break;
                }
            }
        }
        
        if (!$matched_suburb) {
            return new WP_Error('suburb_not_found', 'Suburb not found for this postcode. Available suburbs: ' . implode(', ', $suburbs_response['suburbs']));
        }
        
        $serviceable_response = $this->check_suburb_serviceable($matched_suburb, $postcode);
        
        if (is_wp_error($serviceable_response)) {
            return $serviceable_response;
        }
        
        if (!$serviceable_response['is_serviceable']) {
            return new WP_Error('not_serviceable', 'This area is not currently serviceable');
        }
        
        return array(
            'serviceable' => true,
            'postcode' => $postcode,
            'suburb' => $suburb,
            'matched_suburb' => $matched_suburb,
            'zone' => $serviceable_response['zone'],
            'zone_code' => $serviceable_response['zoneCode'],
            'depot' => $serviceable_response['depot'],
            'depot_state' => $serviceable_response['depotState']
        );
    }
    
    private function get_postcode_suburbs($postcode) {
        $url = $this->api_endpoint . '/api/serviceable/postcode/' . $postcode;
        
        $response = $this->make_api_request($url);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        if (!isset($response['suburbs']) || !is_array($response['suburbs'])) {
            return new WP_Error('invalid_response', 'Invalid API response format');
        }
        
        return $response;
    }
    
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
    
    public function get_delivery_dates($suburb = '', $postcode = '', $weeks = 2) {
        if (empty($this->api_endpoint) || empty($this->api_key)) {
            return new WP_Error('api_not_configured', 'API endpoint or key not configured');
        }
        
        if (empty($suburb) || empty($postcode)) {
            return new WP_Error('missing_location', 'Suburb and postcode required for delivery dates');
        }
        
        $url = $this->api_endpoint . '/api/serviceable/service-days?' . http_build_query(array(
            'suburb' => strtoupper($suburb),
            'postcode' => $postcode,
            'weeksToShow' => $weeks
        ));
        
        $response = $this->make_api_request($url);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return $this->process_delivery_dates($response);
    }
    
    private function process_delivery_dates($api_response) {
        if (!isset($api_response['days']) || !is_array($api_response['days'])) {
            return new WP_Error('invalid_response', 'No delivery days found in API response');
        }
        
        $delivery_dates = array();
        $cutoff_time = isset($api_response['cutoffTime']) ? $api_response['cutoffTime'] : '12:00:00';
        $cutoff_days = isset($api_response['cutoffDays']) ? intval($api_response['cutoffDays']) : 1;
        
        try {
            $timezone = new DateTimeZone('Australia/Melbourne');
            $now = new DateTime('now', $timezone);
        } catch (Exception $e) {
            $timezone = null;
            $now = new DateTime();
        }
        
        foreach ($api_response['days'] as $day) {
            if (!isset($day['nextDeliveryDate']) || empty($day['nextDeliveryDate'])) {
                continue;
            }
            
            try {
                if ($timezone) {
                    $delivery_date = new DateTime($day['nextDeliveryDate'], $timezone);
                } else {
                    $delivery_date = new DateTime($day['nextDeliveryDate']);
                }
                
                $cutoff_date = clone $delivery_date;
                $cutoff_date->modify('-' . $cutoff_days . ' days');
                
                $time_parts = explode(':', $cutoff_time);
                $cutoff_date->setTime(
                    intval($time_parts[0]),
                    intval($time_parts[1]),
                    isset($time_parts[2]) ? intval($time_parts[2]) : 0
                );
                
                if ($now > $cutoff_date) {
                    continue;
                }
                
                $delivery_dates[] = array(
                    'date' => $delivery_date->format('Y-m-d'),
                    'display' => $delivery_date->format('l, F j, Y'),
                    'day' => $day['day'],
                    'deliveries_count' => isset($day['deliveriesCount']) ? $day['deliveriesCount'] : 0
                );
            } catch (Exception $e) {
                continue;
            }
        }
        
        usort($delivery_dates, function($a, $b) {
            return strcmp($a['date'], $b['date']);
        });
        
        return array(
            'zone' => isset($api_response['zone']) ? $api_response['zone'] : '',
            'zone_code' => isset($api_response['zoneCode']) ? $api_response['zoneCode'] : '',
            'depot' => isset($api_response['depot']) ? $api_response['depot'] : '',
            'cutoff_time' => $cutoff_time,
            'cutoff_days' => $cutoff_days,
            'dates' => $delivery_dates
        );
    }
    
    private function make_api_request($url, $method = 'GET', $body = null) {
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        );
        
        if ($body && $method !== 'GET') {
            $args['body'] = is_array($body) ? json_encode($body) : $body;
        }
        
        $this->log('API Request: ' . $method . ' ' . $url);
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            $this->log('API Error: ' . $response->get_error_message());
            return new WP_Error('api_request_failed', 'API request failed: ' . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        $this->log('API Response: ' . $status_code . ' - ' . $body);
        
        if ($status_code === 401) {
            return new WP_Error('unauthorized', 'API authentication failed. Please check your API key.');
        }
        
        if ($status_code >= 400) {
            $decoded = json_decode($body, true);
            if (isset($decoded['errors']) && is_array($decoded['errors'])) {
                $error_message = isset($decoded['errors'][0]['displayMessage']) 
                    ? $decoded['errors'][0]['displayMessage'] 
                    : 'API request failed';
                return new WP_Error('api_error', $error_message);
            }
            return new WP_Error('api_error', 'API request failed with status: ' . $status_code);
        }
        
        $decoded = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_json', 'Invalid JSON response from API');
        }
        
        return $decoded;
    }
    
    private function log($message) {
        if (!$this->enable_logging) {
            return;
        }
        
        error_log('[WCHD API] ' . $message);
    }
    
    public function test_connection() {
        if (empty($this->api_endpoint) || empty($this->api_key)) {
            return new WP_Error('api_not_configured', 'API endpoint or key not configured');
        }
        
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