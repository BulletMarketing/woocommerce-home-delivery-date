<?php
/**
 * Plugin Name: WooCommerce Home Delivery Date
 * Plugin URI: https://yourwebsite.com
 * Description: Adds home delivery date selection to WooCommerce checkout with API integration for postcode validation and delivery scheduling.
 * Version: 1.0.2
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * Text Domain: woocommerce-home-delivery-date
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.3
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WCHD_VERSION', '1.0.2');
define('WCHD_PLUGIN_FILE', __FILE__);
define('WCHD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCHD_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main plugin class
 */
class WooCommerce_Home_Delivery_Date {
    
    /**
     * Single instance of the plugin
     */
    private static $instance = null;
    
    /**
     * Get single instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        // Load plugin files
        $this->load_files();
        
        // Initialize components
        $this->init_components();
        
        // Load textdomain
        load_plugin_textdomain('woocommerce-home-delivery-date', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Load required files
     */
    private function load_files() {
        require_once WCHD_PLUGIN_DIR . 'includes/class-home-delivery-api.php';
        require_once WCHD_PLUGIN_DIR . 'includes/class-checkout-fields.php';
        require_once WCHD_PLUGIN_DIR . 'includes/class-admin-settings.php';
        require_once WCHD_PLUGIN_DIR . 'includes/class-dashboard-widget.php';
        require_once WCHD_PLUGIN_DIR . 'includes/class-delivery-issues-page.php';
        require_once WCHD_PLUGIN_DIR . 'includes/pdf-filters.php';
    }
    
    /**
     * Initialize components
     */
    private function init_components() {
        // Initialize API
        WCHD_Home_Delivery_API::get_instance();
        
        // Initialize checkout fields
        WCHD_Checkout_Fields::get_instance();
        
        // Initialize admin settings
        if (is_admin()) {
            WCHD_Admin_Settings::get_instance();
            WCHD_Dashboard_Widget::get_instance();
            WCHD_Delivery_Issues_Page::get_instance();
        }
        
        // Add admin column for delivery date
        add_filter('manage_edit-shop_order_columns', array($this, 'add_delivery_date_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'display_delivery_date_column'), 10, 2);
        
        // Add admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        
        // AJAX handlers for admin
        add_action('wp_ajax_update_delivery_date', array($this, 'ajax_update_delivery_date'));
    }
    
    /**
     * Add delivery date column to orders list
     */
    public function add_delivery_date_column($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            
            // Add delivery date column after order status
            if ($key === 'order_status') {
                $new_columns['hds_delivery_date'] = __('Delivery Date', 'woocommerce-home-delivery-date');
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Display delivery date in orders list
     */
    public function display_delivery_date_column($column, $post_id) {
        if ($column === 'hds_delivery_date') {
            $order = wc_get_order($post_id);
            if (!$order) return;
            
            $delivery_date = $order->get_meta('_delivery_date');
            $delivery_zone = $order->get_meta('_delivery_zone');
            
            echo '<div class="wchd-delivery-date-cell" data-order-id="' . esc_attr($post_id) . '">';
            
            if ($delivery_date) {
                $formatted_date = date('D, M j', strtotime($delivery_date));
                echo '<div class="wchd-date-display">';
                echo '<strong>' . esc_html($formatted_date) . '</strong>';
                if ($delivery_zone) {
                    echo '<br><small>' . esc_html($delivery_zone) . '</small>';
                }
                echo '</div>';
                echo '<a href="#" class="wchd-edit-date">✎</a>';
            } else {
                echo '<div class="wchd-date-display">—</div>';
                echo '<a href="#" class="wchd-edit-date">✎</a>';
            }
            
            // Edit form (hidden by default)
            echo '<div class="wchd-date-edit-form" style="display:none;">';
            echo '<input type="date" class="wchd-date-input" value="' . esc_attr($delivery_date) . '">';
            echo '<br>';
            echo '<button type="button" class="button wchd-save-date">Save</button>';
            echo '<button type="button" class="button wchd-cancel-edit">Cancel</button>';
            echo '</div>';
            
            echo '</div>';
        }
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function admin_enqueue_scripts($hook) {
        if ($hook === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'shop_order') {
            wp_enqueue_style('wchd-admin', WCHD_PLUGIN_URL . 'assets/css/admin.css', array(), WCHD_VERSION);
            wp_enqueue_script('wchd-admin', WCHD_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), WCHD_VERSION, true);
            
            wp_localize_script('wchd-admin', 'wchd_admin', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wchd_admin_nonce')
            ));
        }
    }
    
    /**
     * AJAX handler for updating delivery date
     */
    public function ajax_update_delivery_date() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'wchd_admin_nonce')) {
            wp_die('Security check failed');
        }
        
        // Check permissions
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $order_id = intval($_POST['order_id']);
        $delivery_date = sanitize_text_field($_POST['delivery_date']);
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('Order not found');
        }
        
        // Update delivery date
        if ($delivery_date) {
            $order->update_meta_data('_delivery_date', $delivery_date);
            $formatted_date = date('D, M j', strtotime($delivery_date));
        } else {
            $order->delete_meta_data('_delivery_date');
            $formatted_date = '';
        }
        
        $order->save();
        
        wp_send_json_success(array(
            'formatted_date' => $formatted_date
        ));
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create default options
        $default_options = array(
            'api_endpoint' => '',
            'api_key' => '',
            'cutoff_time' => '15:00',
            'enable_logging' => false
        );
        
        add_option('wchd_settings', $default_options);
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p><strong>' . __('WooCommerce Home Delivery Date', 'woocommerce-home-delivery-date') . '</strong> ' . __('requires WooCommerce to be installed and active.', 'woocommerce-home-delivery-date') . '</p></div>';
    }
}

// Initialize plugin
WooCommerce_Home_Delivery_Date::get_instance();