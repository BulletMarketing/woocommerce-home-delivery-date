<?php
/**
 * Dashboard Widget for Delivery Issues Tracking
 * File: includes/class-dashboard-widget.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCHD_Dashboard_Widget {
    
    public function __construct() {
        // Add dashboard widget
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
        
        // AJAX handlers for tracking issues
        add_action('wp_ajax_wchd_track_delivery_issue', array($this, 'ajax_track_delivery_issue'));
        add_action('wp_ajax_nopriv_wchd_track_delivery_issue', array($this, 'ajax_track_delivery_issue'));
        
        // Create tracking table on activation
        register_activation_hook(WCHD_PLUGIN_FILE, array($this, 'create_tracking_table'));
    }
    
    /**
     * Create tracking table
     */
    public function create_tracking_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'home_delivery_issues';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
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
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Add dashboard widget
     */
    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'wchd_delivery_issues',
            __('Home Delivery Issues Monitor', 'wc-home-delivery'),
            array($this, 'dashboard_widget_content')
        );
    }
    
    /**
     * Dashboard widget content
     */
    public function dashboard_widget_content() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'home_delivery_issues';
        
        // Get stats for last 7 days
        $seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
        
        // Get issue counts
        $issue_stats = $wpdb->get_results("
            SELECT 
                issue_type,
                COUNT(*) as count,
                COUNT(DISTINCT customer_email) as unique_customers
            FROM $table_name
            WHERE occurred_at >= '$seven_days_ago'
            GROUP BY issue_type
            ORDER BY count DESC
        ");
        
        // Get recent issues
        $recent_issues = $wpdb->get_results("
            SELECT *
            FROM $table_name
            ORDER BY occurred_at DESC
            LIMIT 10
        ");
        
        // Get hourly distribution
        $hourly_stats = $wpdb->get_results("
            SELECT 
                HOUR(occurred_at) as hour,
                COUNT(*) as count
            FROM $table_name
            WHERE occurred_at >= '$seven_days_ago'
            GROUP BY HOUR(occurred_at)
            ORDER BY hour
        ");
        
        ?>
        <div class="wchd-dashboard-widget">
            <h3><?php _e('Last 7 Days Summary', 'wc-home-delivery'); ?></h3>
            
            <?php if ($issue_stats) : ?>
                <table class="widefat" style="margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th><?php _e('Issue Type', 'wc-home-delivery'); ?></th>
                            <th><?php _e('Total', 'wc-home-delivery'); ?></th>
                            <th><?php _e('Unique Customers', 'wc-home-delivery'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($issue_stats as $stat) : ?>
                            <tr>
                                <td><?php echo $this->get_issue_type_label($stat->issue_type); ?></td>
                                <td><strong><?php echo $stat->count; ?></strong></td>
                                <td><?php echo $stat->unique_customers; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php _e('No issues recorded in the last 7 days.', 'wc-home-delivery'); ?></p>
            <?php endif; ?>
            
            <h3><?php _e('Recent Issues', 'wc-home-delivery'); ?></h3>
            
            <?php if ($recent_issues) : ?>
                <div style="max-height: 300px; overflow-y: auto;">
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php _e('Time', 'wc-home-delivery'); ?></th>
                                <th><?php _e('Issue', 'wc-home-delivery'); ?></th>
                                <th><?php _e('Location', 'wc-home-delivery'); ?></th>
                                <th><?php _e('Details', 'wc-home-delivery'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_issues as $issue) : ?>
                                <tr>
                                    <td><?php echo human_time_diff(strtotime($issue->occurred_at), current_time('timestamp')) . ' ago'; ?></td>
                                    <td><?php echo $this->get_issue_type_label($issue->issue_type); ?></td>
                                    <td><?php echo esc_html($issue->suburb . ' ' . $issue->postcode); ?></td>
                                    <td>
                                        <?php if ($issue->error_message) : ?>
                                            <span title="<?php echo esc_attr($issue->error_message); ?>">
                                                <?php echo wp_trim_words($issue->error_message, 10); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($issue->order_id) : ?>
                                            <a href="<?php echo admin_url('post.php?post=' . $issue->order_id . '&action=edit'); ?>">
                                                #<?php echo $issue->order_id; ?>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <p><?php _e('No recent issues.', 'wc-home-delivery'); ?></p>
            <?php endif; ?>
            
            <p class="submit">
                <a href="<?php echo admin_url('admin.php?page=wchd-delivery-issues'); ?>" class="button button-primary">
                    <?php _e('View Detailed Report', 'wc-home-delivery'); ?>
                </a>
            </p>
        </div>
        
        <style>
            .wchd-dashboard-widget h3 {
                margin-top: 20px;
                margin-bottom: 10px;
            }
            .wchd-dashboard-widget table {
                margin-top: 0;
            }
            .wchd-dashboard-widget td {
                padding: 8px;
            }
        </style>
        <?php
    }
    
    /**
     * Get issue type label
     */
    private function get_issue_type_label($type) {
        $labels = array(
            'not_serviceable' => __('Not Serviceable', 'wc-home-delivery'),
            'no_dates_available' => __('No Dates Available', 'wc-home-delivery'),
            'date_selection_failed' => __('Date Selection Failed', 'wc-home-delivery'),
            'address_change_issue' => __('Address Change Issue', 'wc-home-delivery'),
            'api_error' => __('API Error', 'wc-home-delivery'),
            'postcode_check_failed' => __('Postcode Check Failed', 'wc-home-delivery'),
            'calendar_load_failed' => __('Calendar Load Failed', 'wc-home-delivery'),
            'checkout_validation_failed' => __('Checkout Validation Failed', 'wc-home-delivery')
        );
        
        return isset($labels[$type]) ? $labels[$type] : $type;
    }
    
    /**
     * AJAX handler for tracking issues
     */
    public function ajax_track_delivery_issue() {
        // Don't require nonce for tracking to ensure we capture all issues
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'home_delivery_issues';
        
        $issue_type = isset($_POST['issue_type']) ? sanitize_text_field($_POST['issue_type']) : '';
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : null;
        $customer_email = isset($_POST['customer_email']) ? sanitize_email($_POST['customer_email']) : '';
        $postcode = isset($_POST['postcode']) ? sanitize_text_field($_POST['postcode']) : '';
        $suburb = isset($_POST['suburb']) ? sanitize_text_field($_POST['suburb']) : '';
        $error_message = isset($_POST['error_message']) ? sanitize_text_field($_POST['error_message']) : '';
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
        
        // Get or create session ID
        if (!session_id()) {
            session_start();
        }
        $session_id = session_id();
        
        // Insert issue record
        $wpdb->insert(
            $table_name,
            array(
                'issue_type' => $issue_type,
                'order_id' => $order_id,
                'customer_email' => $customer_email,
                'postcode' => $postcode,
                'suburb' => $suburb,
                'error_message' => $error_message,
                'user_agent' => $user_agent,
                'session_id' => $session_id,
                'occurred_at' => current_time('mysql')
            ),
            array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        wp_send_json_success(array('tracked' => true));
    }
}

// Initialize the widget
new WCHD_Dashboard_Widget();