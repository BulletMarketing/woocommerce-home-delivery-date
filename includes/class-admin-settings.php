<?php
/**
 * Admin Settings Handler
 * File: includes/class-admin-settings.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCHD_Admin_Settings {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'init_settings'));
        add_action('wp_ajax_wchd_test_api', array($this, 'ajax_test_api'));
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Home Delivery Settings', 'woocommerce-home-delivery-date'),
            __('Home Delivery', 'woocommerce-home-delivery-date'),
            'manage_woocommerce',
            'wchd-settings',
            array($this, 'settings_page')
        );
    }
    
    public function init_settings() {
        register_setting('wchd_settings_group', 'wchd_settings', array($this, 'sanitize_settings'));
        
        add_settings_section(
            'wchd_api_section',
            __('API Configuration', 'woocommerce-home-delivery-date'),
            array($this, 'api_section_callback'),
            'wchd-settings'
        );
        
        add_settings_field(
            'api_endpoint',
            __('API Endpoint', 'woocommerce-home-delivery-date'),
            array($this, 'api_endpoint_callback'),
            'wchd-settings',
            'wchd_api_section'
        );
        
        add_settings_field(
            'api_key',
            __('API Key (Bearer Token)', 'woocommerce-home-delivery-date'),
            array($this, 'api_key_callback'),
            'wchd-settings',
            'wchd_api_section'
        );
        
        add_settings_section(
            'wchd_general_section',
            __('General Settings', 'woocommerce-home-delivery-date'),
            array($this, 'general_section_callback'),
            'wchd-settings'
        );
        
        add_settings_field(
            'cutoff_time',
            __('Default Cutoff Time', 'woocommerce-home-delivery-date'),
            array($this, 'cutoff_time_callback'),
            'wchd-settings',
            'wchd_general_section'
        );
        
        add_settings_field(
            'enable_logging',
            __('Enable API Logging', 'woocommerce-home-delivery-date'),
            array($this, 'enable_logging_callback'),
            'wchd-settings',
            'wchd_general_section'
        );
    }
    
    public function api_section_callback() {
        echo '<p>' . __('Configure your Home Delivery Service API connection.', 'woocommerce-home-delivery-date') . '</p>';
    }
    
    public function general_section_callback() {
        echo '<p>' . __('General plugin settings.', 'woocommerce-home-delivery-date') . '</p>';
    }
    
    public function api_endpoint_callback() {
        $settings = get_option('wchd_settings', array());
        $value = isset($settings['api_endpoint']) ? $settings['api_endpoint'] : 'https://api.homedelivery.com.au';
        
        echo '<input type="url" name="wchd_settings[api_endpoint]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('The Home Delivery Service API endpoint URL. Default: https://api.homedelivery.com.au', 'woocommerce-home-delivery-date') . '</p>';
    }
    
    public function api_key_callback() {
        $settings = get_option('wchd_settings', array());
        $value = isset($settings['api_key']) ? $settings['api_key'] : '';
        
        echo '<input type="password" name="wchd_settings[api_key]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Your Home Delivery Service API Bearer token. Contact Home Delivery Service for your API key.', 'woocommerce-home-delivery-date') . '</p>';
    }
    
    public function cutoff_time_callback() {
        $settings = get_option('wchd_settings', array());
        $value = isset($settings['cutoff_time']) ? $settings['cutoff_time'] : '15:00';
        
        echo '<input type="time" name="wchd_settings[cutoff_time]" value="' . esc_attr($value) . '" />';
        echo '<p class="description">' . __('Default cutoff time for orders (24-hour format). This will be overridden by API data when available.', 'woocommerce-home-delivery-date') . '</p>';
    }
    
    public function enable_logging_callback() {
        $settings = get_option('wchd_settings', array());
        $value = isset($settings['enable_logging']) ? $settings['enable_logging'] : false;
        
        echo '<input type="checkbox" name="wchd_settings[enable_logging]" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label for="wchd_settings[enable_logging]">' . __('Enable detailed API logging for debugging', 'woocommerce-home-delivery-date') . '</label>';
        echo '<p class="description">' . __('When enabled, API requests and responses will be logged to the WordPress error log.', 'woocommerce-home-delivery-date') . '</p>';
    }
    
    public function sanitize_settings($input) {
        $sanitized = array();
        
        if (isset($input['api_endpoint'])) {
            $sanitized['api_endpoint'] = esc_url_raw(rtrim($input['api_endpoint'], '/'));
        }
        
        if (isset($input['api_key'])) {
            $sanitized['api_key'] = sanitize_text_field($input['api_key']);
        }
        
        if (isset($input['cutoff_time'])) {
            $sanitized['cutoff_time'] = sanitize_text_field($input['cutoff_time']);
        }
        
        if (isset($input['enable_logging'])) {
            $sanitized['enable_logging'] = (bool) $input['enable_logging'];
        }
        
        return $sanitized;
    }
    
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="notice notice-info">
                <p><strong><?php _e('Setup Instructions:', 'woocommerce-home-delivery-date'); ?></strong></p>
                <ol>
                    <li><?php _e('Contact Home Delivery Service to obtain your API Bearer token', 'woocommerce-home-delivery-date'); ?></li>
                    <li><?php _e('Enter your API key in the field below', 'woocommerce-home-delivery-date'); ?></li>
                    <li><?php _e('Test the API connection using the button below', 'woocommerce-home-delivery-date'); ?></li>
                    <li><?php _e('Configure your cutoff time and other settings', 'woocommerce-home-delivery-date'); ?></li>
                </ol>
            </div>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('wchd_settings_group');
                do_settings_sections('wchd-settings');
                submit_button();
                ?>
            </form>
            
            <div class="wchd-api-test">
                <h2><?php _e('API Connection Test', 'woocommerce-home-delivery-date'); ?></h2>
                <p><?php _e('Test your API connection to ensure everything is working correctly.', 'woocommerce-home-delivery-date'); ?></p>
                
                <button type="button" id="wchd-test-api" class="button button-secondary">
                    <?php _e('Test API Connection', 'woocommerce-home-delivery-date'); ?>
                </button>
                
                <div id="wchd-test-results" style="margin-top: 15px;"></div>
            </div>
            
            <div class="wchd-delivery-issues" style="margin-top: 30px;">
                <h2><?php _e('Recent Delivery Issues', 'woocommerce-home-delivery-date'); ?></h2>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=wchd-delivery-issues'); ?>" class="button">
                        <?php _e('View Delivery Issues Log', 'woocommerce-home-delivery-date'); ?>
                    </a>
                </p>
            </div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#wchd-test-api').on('click', function() {
                var $button = $(this);
                var $results = $('#wchd-test-results');
                
                $button.prop('disabled', true).text('Testing...');
                $results.html('<p>Testing API connection...</p>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wchd_test_api',
                        nonce: '<?php echo wp_create_nonce('wchd_test_api'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $results.html('<div class="notice notice-success inline"><p><strong>Success!</strong> ' + response.data.message + '</p></div>');
                            if (response.data.test_data) {
                                $results.append('<details><summary>API Response Details</summary><pre>' + JSON.stringify(response.data.test_data, null, 2) + '</pre></details>');
                            }
                        } else {
                            $results.html('<div class="notice notice-error inline"><p><strong>Error:</strong> ' + response.data.message + '</p></div>');
                        }
                    },
                    error: function() {
                        $results.html('<div class="notice notice-error inline"><p><strong>Error:</strong> Failed to test API connection.</p></div>');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('Test API Connection');
                    }
                });
            });
        });
        </script>
        
        <style>
        .wchd-api-test {
            background: #fff;
            border: 1px solid #ccd0d4;
            padding: 20px;
            margin-top: 20px;
        }
        
        .wchd-delivery-issues {
            background: #fff;
            border: 1px solid #ccd0d4;
            padding: 20px;
        }
        
        details {
            margin-top: 10px;
        }
        
        details pre {
            background: #f1f1f1;
            padding: 10px;
            overflow-x: auto;
            max-height: 300px;
        }
        </style>
        <?php
    }
    
    public function ajax_test_api() {
        if (!wp_verify_nonce($_POST['nonce'], 'wchd_test_api')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $api = WCHD_Home_Delivery_API::get_instance();
        $result = $api->test_connection();
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success($result);
    }
}