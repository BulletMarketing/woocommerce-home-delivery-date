<?php
/**
 * Plugin Name: WooCommerce Home Delivery Date
 * Plugin URI: https://bulletmarketing.com.au/
 * Description: Integrates Home Delivery API to check postcodes and display delivery dates for Victoria, Australia
 * Version: 1.1.4
 * Author: Bullet Marketing
 * Author URI: https://bulletmarketing.com.au/
 * License: GPL v2 or later
 * Text Domain: wc-home-delivery
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WCHD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WCHD_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WCHD_PLUGIN_FILE', __FILE__);

// Include required files
require_once WCHD_PLUGIN_PATH . 'includes/class-home-delivery-api.php';
require_once WCHD_PLUGIN_PATH . 'includes/class-checkout-fields.php';
require_once WCHD_PLUGIN_PATH . 'includes/class-admin-settings.php';
require_once WCHD_PLUGIN_PATH . 'includes/pdf-filters.php';
require_once WCHD_PLUGIN_PATH . 'includes/class-dashboard-widget.php';
require_once WCHD_PLUGIN_PATH . 'includes/class-delivery-issues-page.php';

// Initialize the plugin
add_action('plugins_loaded', 'wchd_init_plugin');

function wchd_init_plugin() {
    if (class_exists('WooCommerce')) {
        new WCHD_Home_Delivery_API();
        new WCHD_Checkout_Fields();
        new WCHD_Admin_Settings();
        new WCHD_Dashboard_Widget();
        new WCHD_Delivery_Issues_Page();
    }
}

// Activation hook
register_activation_hook(__FILE__, 'wchd_activate');
function wchd_activate() {
    // Create database table for storing delivery dates if needed
    global $wpdb;
    $table_name = $wpdb->prefix . 'home_delivery_dates';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        order_id int(11) NOT NULL,
        delivery_date date NOT NULL,
        delivery_window varchar(50),
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Create issues tracking table
    $issues_table = $wpdb->prefix . 'home_delivery_issues';
    
    $sql_issues = "CREATE TABLE IF NOT EXISTS $issues_table (
        id int(11) NOT NULL AUTO_INCREMENT,
        issue_type varchar(50) NOT NULL,
        order_id int(11) DEFAULT NULL,
        customer_email varchar(100),
        postcode varchar(10),
        suburb varchar(100),
        error_message text,
        occurred_at datetime DEFAULT CURRENT_TIMESTAMP,
        user_agent text,
        session_id varchar(100),
        PRIMARY KEY (id),
        KEY issue_type (issue_type),
        KEY occurred_at (occurred_at)
    ) $charset_collate;";
    
    dbDelta($sql_issues);
}

// Add settings link on plugin page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wchd_add_plugin_page_settings_link');
function wchd_add_plugin_page_settings_link($links) {
    $links[] = '<a href="' . admin_url('admin.php?page=wc-settings&tab=home_delivery') . '">' . __('Settings', 'wc-home-delivery') . '</a>';
    $links[] = '<a href="' . admin_url('admin.php?page=wchd-delivery-issues') . '">' . __('Issues Report', 'wc-home-delivery') . '</a>';
    return $links;
}