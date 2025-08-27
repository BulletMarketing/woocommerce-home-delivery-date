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
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Home Delivery Settings',
            'Home Delivery',
            'manage_woocommerce',
            'wchd-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Initialize settings
     */
    public function init_settings() {
        register_setting('wchd_settings_group', 'wchd_settings');
        
        // API Settings Section
        add_settings_section(
            'wchd_api_section',
            'API Configuration',
            array($this, 'api_section_callback'),
            'wchd-settings'
        );
        
        add_settings_field(
            'api_endpoint',
            'API Endpoint',
            array($this, 'api_endpoint_callback'),
            'wchd-settings',
            'wchd_api_section'
        );
        
        add_settings_field(
            'api_key',
            'API Key (Bearer Token)',
            array($this, 'api_key_callback'),
            'wchd-settings',
            'wchd_api_section'
        );
        
        add_settings_field(
            'enable_logging',
            'Enable API Logging',
            array($this, 'enable_logging_callback'),
            'wchd-settings',
            'wchd_api_section'
        );
    }
    
    /**
     * API section callback
     */
    public function api_section_callback() {
        echo '<p>Configure your Home Delivery Service API connection.</p>';
    }
    
    /**
     * API endpoint field callback
     */
    public function api_endpoint_callback() {
        $settings = get_option('wchd_settings', array());
        $value = isset($settings['api_endpoint']) ? $settings['api_endpoint'] : 'https://api.homedelivery.com.au';
        
        echo '<input type="url" name="wchd_settings[api_endpoint]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">The Home Delivery Service API endpoint URL.</p>';
    }
    
    /**
     * API key field callback
     */
    public function api_key_callback() {
        $settings = get_option('wchd_settings', array());
        $value = isset($settings['api_key']) ? $settings['api_key'] : '';
        
        echo '<input type="password" name="wchd_settings[api_key]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Your Home Delivery Service API Bearer token.</p>';
    }
    
    /**
     * Enable logging field callback
     */
    public function enable_logging_callback() {
        $settings = get_option('wchd_settings', array());
        $value = isset($settings['enable_logging']) ? $settings['enable_logging'] : false;
        
        echo '<input type="checkbox" name="wchd_settings[enable_logging]" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label>Enable detailed API logging for debugging</label>';
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Home Delivery Settings</h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('wchd_settings_group');
                do_settings_sections('wchd-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}