<?php
/**
 * Delivery Issues Report Page
 * File: includes/class-delivery-issues-page.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCHD_Delivery_Issues_Page {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_issues_page'));
    }
    
    /**
     * Add issues page to admin menu
     */
    public function add_issues_page() {
        add_submenu_page(
            'woocommerce',
            __('Delivery Issues Report', 'wc-home-delivery'),
            __('Delivery Issues', 'wc-home-delivery'),
            'manage_woocommerce',
            'wchd-delivery-issues',
            array($this, 'render_issues_page')
        );
    }
    
    /**
     * Render issues page
     */
    public function render_issues_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'home_delivery_issues';
        
        // Get date range
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-m-d', strtotime('-30 days'));
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-d');
        $issue_type_filter = isset($_GET['issue_type']) ? sanitize_text_field($_GET['issue_type']) : '';
        
        // Get overall stats
        $total_issues = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name 
            WHERE occurred_at BETWEEN %s AND %s",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));
        
        $unique_customers = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT customer_email) FROM $table_name 
            WHERE occurred_at BETWEEN %s AND %s 
            AND customer_email != ''",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));
        
        // Get issues by type
        $issues_by_type = $wpdb->get_results($wpdb->prepare(
            "SELECT issue_type, COUNT(*) as count, COUNT(DISTINCT customer_email) as unique_customers
            FROM $table_name
            WHERE occurred_at BETWEEN %s AND %s
            GROUP BY issue_type
            ORDER BY count DESC",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));
        
        // Get issues by postcode
        $issues_by_postcode = $wpdb->get_results($wpdb->prepare(
            "SELECT postcode, suburb, COUNT(*) as count
            FROM $table_name
            WHERE occurred_at BETWEEN %s AND %s
            AND postcode != ''
            GROUP BY postcode, suburb
            ORDER BY count DESC
            LIMIT 20",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));
        
        // Get recent issues list
        $where_clause = "WHERE occurred_at BETWEEN %s AND %s";
        $params = array($start_date . ' 00:00:00', $end_date . ' 23:59:59');
        
        if ($issue_type_filter) {
            $where_clause .= " AND issue_type = %s";
            $params[] = $issue_type_filter;
        }
        
        $recent_issues = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name 
            $where_clause
            ORDER BY occurred_at DESC
            LIMIT 100",
            ...$params
        ));
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <!-- Date Filter Form -->
            <form method="get" action="">
                <input type="hidden" name="page" value="wchd-delivery-issues" />
                <table class="form-table">
                    <tr>
                        <th><label for="start_date"><?php _e('Date Range', 'wc-home-delivery'); ?></label></th>
                        <td>
                            <input type="date" id="start_date" name="start_date" value="<?php echo esc_attr($start_date); ?>" />
                            <?php _e('to', 'wc-home-delivery'); ?>
                            <input type="date" id="end_date" name="end_date" value="<?php echo esc_attr($end_date); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="issue_type"><?php _e('Issue Type', 'wc-home-delivery'); ?></label></th>
                        <td>
                            <select id="issue_type" name="issue_type">
                                <option value=""><?php _e('All Types', 'wc-home-delivery'); ?></option>
                                <option value="not_serviceable" <?php selected($issue_type_filter, 'not_serviceable'); ?>>Not Serviceable</option>
                                <option value="no_dates_available" <?php selected($issue_type_filter, 'no_dates_available'); ?>>No Dates Available</option>
                                <option value="date_selection_failed" <?php selected($issue_type_filter, 'date_selection_failed'); ?>>Date Selection Failed</option>
                                <option value="address_change_issue" <?php selected($issue_type_filter, 'address_change_issue'); ?>>Address Change Issue</option>
                                <option value="api_error" <?php selected($issue_type_filter, 'api_error'); ?>>API Error</option>
                                <option value="postcode_check_failed" <?php selected($issue_type_filter, 'postcode_check_failed'); ?>>Postcode Check Failed</option>
                                <option value="calendar_load_failed" <?php selected($issue_type_filter, 'calendar_load_failed'); ?>>Calendar Load Failed</option>
                                <option value="checkout_validation_failed" <?php selected($issue_type_filter, 'checkout_validation_failed'); ?>>Checkout Validation Failed</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" class="button-primary" value="<?php _e('Filter', 'wc-home-delivery'); ?>" />
                    <a href="<?php echo admin_url('admin.php?page=wchd-delivery-issues'); ?>" class="button">
                        <?php _e('Reset', 'wc-home-delivery'); ?>
                    </a>
                </p>
            </form>
            
            <!-- Summary Stats -->
            <div class="wchd-stats-boxes" style="display: flex; gap: 20px; margin: 20px 0;">
                <div style="background: #fff; padding: 20px; border: 1px solid #ccc; flex: 1;">
                    <h3 style="margin-top: 0;"><?php _e('Total Issues', 'wc-home-delivery'); ?></h3>
                    <p style="font-size: 32px; margin: 0; color: #d63638;"><?php echo number_format($total_issues); ?></p>
                </div>
                <div style="background: #fff; padding: 20px; border: 1px solid #ccc; flex: 1;">
                    <h3 style="margin-top: 0;"><?php _e('Affected Customers', 'wc-home-delivery'); ?></h3>
                    <p style="font-size: 32px; margin: 0; color: #f0b849;"><?php echo number_format($unique_customers); ?></p>
                </div>
                <div style="background: #fff; padding: 20px; border: 1px solid #ccc; flex: 1;">
                    <h3 style="margin-top: 0;"><?php _e('Average Per Day', 'wc-home-delivery'); ?></h3>
                    <p style="font-size: 32px; margin: 0; color: #00a0d2;">
                        <?php 
                        $days = max(1, (strtotime($end_date) - strtotime($start_date)) / 86400);
                        echo number_format($total_issues / $days, 1); 
                        ?>
                    </p>
                </div>
            </div>
            
            <!-- Issues by Type -->
            <h2><?php _e('Issues by Type', 'wc-home-delivery'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Issue Type', 'wc-home-delivery'); ?></th>
                        <th><?php _e('Count', 'wc-home-delivery'); ?></th>
                        <th><?php _e('Unique Customers', 'wc-home-delivery'); ?></th>
                        <th><?php _e('Percentage', 'wc-home-delivery'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($issues_by_type as $issue) : ?>
                        <tr>
                            <td>
                                <a href="<?php echo add_query_arg('issue_type', $issue->issue_type); ?>">
                                    <?php echo $this->get_issue_type_label($issue->issue_type); ?>
                                </a>
                            </td>
                            <td><?php echo $issue->count; ?></td>
                            <td><?php echo $issue->unique_customers; ?></td>
                            <td>
                                <?php 
                                $percentage = $total_issues > 0 ? ($issue->count / $total_issues * 100) : 0;
                                echo number_format($percentage, 1) . '%';
                                ?>
                                <div style="background: #f0f0f0; height: 20px; margin-top: 5px;">
                                    <div style="background: #0073aa; height: 100%; width: <?php echo $percentage; ?>%;"></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Issues by Location -->
            <h2><?php _e('Top Problem Areas', 'wc-home-delivery'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Postcode', 'wc-home-delivery'); ?></th>
                        <th><?php _e('Suburb', 'wc-home-delivery'); ?></th>
                        <th><?php _e('Issue Count', 'wc-home-delivery'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($issues_by_postcode as $location) : ?>
                        <tr>
                            <td><?php echo esc_html($location->postcode); ?></td>
                            <td><?php echo esc_html($location->suburb); ?></td>
                            <td><?php echo $location->count; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Recent Issues -->
            <h2><?php _e('Recent Issues', 'wc-home-delivery'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Time', 'wc-home-delivery'); ?></th>
                        <th><?php _e('Issue Type', 'wc-home-delivery'); ?></th>
                        <th><?php _e('Customer', 'wc-home-delivery'); ?></th>
                        <th><?php _e('Location', 'wc-home-delivery'); ?></th>
                        <th><?php _e('Error Message', 'wc-home-delivery'); ?></th>
                        <th><?php _e('Order', 'wc-home-delivery'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_issues as $issue) : ?>
                        <tr>
                            <td><?php echo date('Y-m-d H:i', strtotime($issue->occurred_at)); ?></td>
                            <td><?php echo $this->get_issue_type_label($issue->issue_type); ?></td>
                            <td><?php echo esc_html($issue->customer_email); ?></td>
                            <td><?php echo esc_html($issue->suburb . ' ' . $issue->postcode); ?></td>
                            <td><?php echo esc_html($issue->error_message); ?></td>
                            <td>
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
            
            <p class="description">
                <?php _e('This report shows all delivery-related issues encountered by customers during checkout.', 'wc-home-delivery'); ?>
            </p>
        </div>
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
}

// Initialize the page
new WCHD_Delivery_Issues_Page();