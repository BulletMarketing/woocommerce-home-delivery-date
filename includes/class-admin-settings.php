<?php
/**
 * Admin Settings Class - Fixed Sorting for HPOS
 * File: includes/class-admin-settings.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCHD_Admin_Settings {
    
    public function __construct() {
        // Add settings tab
        add_filter('woocommerce_settings_tabs_array', array($this, 'add_settings_tab'), 50);
        add_action('woocommerce_settings_tabs_home_delivery', array($this, 'settings_tab'));
        add_action('woocommerce_update_options_home_delivery', array($this, 'update_settings'));
        
        // Add delivery date column to orders
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_delivery_date_column'), 20);
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'delivery_date_column_content_hpos'), 10, 2);
        
        // Make column sortable for HPOS
        add_filter('woocommerce_order_list_table_sortable_columns', array($this, 'make_delivery_date_sortable'), 20);
        add_filter('woocommerce_order_query_args', array($this, 'handle_delivery_date_sorting'), 20);
        
        // Fix the sorting link
        add_filter('manage_woocommerce_page_wc-orders_sortable_columns', array($this, 'make_delivery_date_sortable'), 20);
        
        // For legacy orders screen
        add_filter('manage_edit-shop_order_columns', array($this, 'add_delivery_date_column'), 20);
        add_action('manage_shop_order_posts_custom_column', array($this, 'delivery_date_column_content'), 10, 2);
        add_filter('manage_edit-shop_order_sortable_columns', array($this, 'make_delivery_date_sortable_legacy'), 20);
        add_filter('request', array($this, 'handle_legacy_sorting'));
        
        // Add filter by delivery date
        add_action('woocommerce_order_list_table_restrict_manage_orders', array($this, 'add_delivery_date_filter'));
        add_filter('woocommerce_order_query_args', array($this, 'filter_orders_by_delivery_date'), 30);
        
        // Add meta box to order edit page
        add_action('add_meta_boxes', array($this, 'add_delivery_meta_box'), 10);
        add_action('add_meta_boxes_woocommerce_page_wc-orders', array($this, 'add_delivery_meta_box'), 10);
        
        // Save meta box data
        add_action('save_post_shop_order', array($this, 'save_delivery_meta_box'));
        add_action('woocommerce_process_shop_order_meta', array($this, 'save_delivery_meta_box_hpos'));
        
        // Admin menu for delivery reports
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // AJAX handlers for inline editing
        add_action('wp_ajax_update_delivery_date', array($this, 'ajax_update_delivery_date'));
        
        // Admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    /**
     * Add settings tab
     */
    public function add_settings_tab($settings_tabs) {
        $settings_tabs['home_delivery'] = __('Home Delivery', 'wc-home-delivery');
        return $settings_tabs;
    }
    
    /**
     * Settings tab content
     */
    public function settings_tab() {
        woocommerce_admin_fields($this->get_settings());
    }
    
    /**
     * Update settings
     */
    public function update_settings() {
        woocommerce_update_options($this->get_settings());
    }
    
    /**
     * Get settings fields
     */
    public function get_settings() {
        $settings = array(
            'section_title' => array(
                'name' => __('Home Delivery API Settings', 'wc-home-delivery'),
                'type' => 'title',
                'desc' => __('Configure your Home Delivery API settings.', 'wc-home-delivery'),
                'id' => 'wchd_section_title'
            ),
            'api_token' => array(
                'name' => __('API Token', 'wc-home-delivery'),
                'type' => 'text',
                'desc' => __('Enter your Home Delivery API token. You can generate this from your Home Delivery dashboard.', 'wc-home-delivery'),
                'id' => 'wchd_api_token',
                'default' => '461166a1-792e-4ca9-8d14-995626ddff8e',
                'css' => 'width:400px;'
            ),
            'sandbox_mode' => array(
                'name' => __('Sandbox Mode', 'wc-home-delivery'),
                'type' => 'checkbox',
                'desc' => __('Enable sandbox mode for testing', 'wc-home-delivery'),
                'id' => 'wchd_sandbox_mode',
                'default' => 'yes'
            ),
            'delivery_options_title' => array(
                'name' => __('Delivery Options', 'wc-home-delivery'),
                'type' => 'title',
                'id' => 'wchd_delivery_options_title'
            ),
            'cutoff_time' => array(
                'name' => __('Order Cutoff Time', 'wc-home-delivery'),
                'type' => 'time',
                'desc' => __('Orders must be placed before this time for next day delivery (24-hour format)', 'wc-home-delivery'),
                'id' => 'wchd_cutoff_time',
                'default' => '06:00',
                'css' => 'width:150px;',
                'custom_attributes' => array(
                    'pattern' => '[0-9]{2}:[0-9]{2}'
                )
            ),
            'weeks_to_show' => array(
                'name' => __('Weeks to Show', 'wc-home-delivery'),
                'type' => 'number',
                'desc' => __('Number of weeks of delivery dates to show to customers', 'wc-home-delivery'),
                'id' => 'wchd_weeks_to_show',
                'default' => '4',
                'css' => 'width:100px;',
                'custom_attributes' => array(
                    'min' => '1',
                    'max' => '8'
                )
            ),
            'delivery_instructions' => array(
                'name' => __('Delivery Instructions', 'wc-home-delivery'),
                'type' => 'textarea',
                'desc' => __('Instructions shown to customers when selecting delivery date', 'wc-home-delivery'),
                'id' => 'wchd_delivery_instructions',
                'default' => 'Please select your preferred delivery date. Orders must be placed before the cutoff time.',
                'css' => 'width:400px; height:100px;'
            ),
            'section_end' => array(
                'type' => 'sectionend',
                'id' => 'wchd_section_end'
            )
        );
        
        return apply_filters('wchd_settings', $settings);
    }
    
    /**
     * Add delivery date column to orders list
     */
    public function add_delivery_date_column($columns) {
        // Insert after order_date or order_status
        $new_columns = array();
        $inserted = false;
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            if (!$inserted && in_array($key, array('order_date', 'order_status', 'order_number'))) {
                $new_columns['hds_delivery_date'] = __('HDS- Date', 'wc-home-delivery');
                $inserted = true;
            }
        }
        
        // If not inserted yet, add at the end
        if (!$inserted) {
            $new_columns['hds_delivery_date'] = __('HDS- Date', 'wc-home-delivery');
        }
        
        return $new_columns;
    }
    
    /**
     * Delivery date column content for legacy orders
     */
    public function delivery_date_column_content($column, $post_id) {
        if ('hds_delivery_date' === $column) {
            $order = wc_get_order($post_id);
            if ($order) {
                $this->display_delivery_date_cell($order);
            }
        }
    }
    
    /**
     * Delivery date column content for HPOS
     */
    public function delivery_date_column_content_hpos($column, $order) {
        if ('hds_delivery_date' === $column) {
            if (!is_object($order)) {
                $order = wc_get_order($order);
            }
            
            if ($order) {
                $this->display_delivery_date_cell($order);
            }
        }
    }
    
    /**
     * Display delivery date cell content
     */
    private function display_delivery_date_cell($order) {
        $order_id = $order->get_id();
        
        // Try to get delivery date from order meta first
        $delivery_date = $order->get_meta('_delivery_date', true);
        
        // If not found, try legacy post meta
        if (!$delivery_date) {
            $delivery_date = get_post_meta($order_id, '_delivery_date', true);
        }
        
        echo '<div class="wchd-delivery-date-cell" data-order-id="' . $order_id . '">';
        
        if ($delivery_date) {
            try {
                $date = new DateTime($delivery_date);
                echo '<span class="wchd-date-display">';
                echo '<strong>' . $date->format('l, M j') . '</strong>';
                
                // Try to get delivery zone
                $delivery_zone = $order->get_meta('_delivery_zone', true);
                if (!$delivery_zone) {
                    $delivery_zone = get_post_meta($order_id, '_delivery_zone', true);
                }
                
                if ($delivery_zone) {
                    echo '<br><small>' . esc_html($delivery_zone) . '</small>';
                }
                echo '</span>';
            } catch (Exception $e) {
                echo '<span class="wchd-date-display">' . esc_html($delivery_date) . '</span>';
            }
        } else {
            echo '<span class="wchd-date-display">—</span>';
        }
        
        // Edit button
        echo ' <a href="#" class="wchd-edit-date" title="' . __('Edit delivery date', 'wc-home-delivery') . '">✏️</a>';
        
        // Hidden edit form
        echo '<div class="wchd-date-edit-form" style="display:none;">';
        echo '<input type="date" class="wchd-date-input" value="' . esc_attr($delivery_date) . '" />';
        echo '<button class="button button-small wchd-save-date">' . __('Save', 'wc-home-delivery') . '</button>';
        echo '<button class="button button-small wchd-cancel-edit">' . __('Cancel', 'wc-home-delivery') . '</button>';
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * Make delivery date column sortable
     */
    public function make_delivery_date_sortable($columns) {
        $columns['hds_delivery_date'] = 'hds_delivery_date';
        return $columns;
    }
    
    /**
     * Make delivery date column sortable for legacy orders
     */
    public function make_delivery_date_sortable_legacy($columns) {
        $columns['hds_delivery_date'] = 'hds_delivery_date';
        return $columns;
    }
    
    /**
     * Handle delivery date sorting for HPOS
     */
    public function handle_delivery_date_sorting($args) {
        // Check if we're sorting by delivery date
        if (isset($_GET['orderby']) && $_GET['orderby'] === 'hds_delivery_date') {
            // Set meta key for sorting
            $args['meta_key'] = '_delivery_date';
            $args['orderby'] = 'meta_value';
            $args['order'] = isset($_GET['order']) ? strtoupper($_GET['order']) : 'ASC';
            
            // Important: We need to handle orders that don't have delivery dates
            if (!isset($args['meta_query'])) {
                $args['meta_query'] = array();
            }
            
            // Add meta query to handle NULL values
            $args['meta_query'][] = array(
                'relation' => 'OR',
                array(
                    'key' => '_delivery_date',
                    'compare' => 'EXISTS'
                ),
                array(
                    'key' => '_delivery_date',
                    'compare' => 'NOT EXISTS'
                )
            );
        }
        
        return $args;
    }
    
    /**
     * Handle legacy sorting
     */
    public function handle_legacy_sorting($vars) {
        global $typenow;
        
        if ($typenow != 'shop_order') {
            return $vars;
        }
        
        if (isset($vars['orderby']) && $vars['orderby'] == 'hds_delivery_date') {
            $vars = array_merge($vars, array(
                'meta_key' => '_delivery_date',
                'orderby' => 'meta_value'
            ));
        }
        
        return $vars;
    }
    
    /**
     * Add delivery date filter dropdown (HPOS)
     */
    public function add_delivery_date_filter() {
        global $wpdb;
        
        // Get the correct table name based on HPOS
        $table = $wpdb->prefix . 'wc_orders_meta';
        
        // Check if table exists (HPOS enabled)
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            // Fall back to postmeta
            $table = $wpdb->postmeta;
            $order_table = $wpdb->posts;
            $id_column = 'post_id';
            $status_column = 'post_status';
            $type_check = " AND p.post_type = 'shop_order'";
        } else {
            $order_table = $wpdb->prefix . 'wc_orders';
            $id_column = 'order_id';
            $status_column = 'status';
            $type_check = "";
        }
        
        // Get all unique delivery dates
        $query = "
            SELECT DISTINCT m.meta_value 
            FROM {$table} m
            JOIN {$order_table} p ON m.{$id_column} = p.id
            WHERE m.meta_key = '_delivery_date' 
            AND m.meta_value != '' 
            {$type_check}
            ORDER BY m.meta_value DESC
        ";
        
        $dates = $wpdb->get_col($query);
        
        if (empty($dates)) {
            return;
        }
        
        $selected = isset($_GET['delivery_date_filter']) ? $_GET['delivery_date_filter'] : '';
        ?>
        <select name="delivery_date_filter" id="delivery_date_filter">
            <option value=""><?php _e('All delivery dates', 'wc-home-delivery'); ?></option>
            <?php foreach ($dates as $date) : ?>
                <?php
                try {
                    $date_obj = new DateTime($date);
                    $display = $date_obj->format('F j, Y');
                } catch (Exception $e) {
                    $display = $date;
                }
                ?>
                <option value="<?php echo esc_attr($date); ?>" <?php selected($selected, $date); ?>>
                    <?php echo esc_html($display); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }
    
    /**
     * Filter orders by delivery date (HPOS)
     */
    public function filter_orders_by_delivery_date($args) {
        if (!empty($_GET['delivery_date_filter'])) {
            if (!isset($args['meta_query'])) {
                $args['meta_query'] = array();
            }
            
            $args['meta_query'][] = array(
                'key' => '_delivery_date',
                'value' => sanitize_text_field($_GET['delivery_date_filter']),
                'compare' => '='
            );
        }
        
        return $args;
    }
    
    /**
     * Add delivery meta box
     */
    public function add_delivery_meta_box() {
        add_meta_box(
            'wchd_delivery_details',
            __('Delivery Details', 'wc-home-delivery'),
            array($this, 'delivery_meta_box_content'),
            'shop_order',
            'side',
            'high'
        );
        
        // For HPOS
        $screen = wc_get_page_screen_id('shop-order');
        add_meta_box(
            'wchd_delivery_details',
            __('Delivery Details', 'wc-home-delivery'),
            array($this, 'delivery_meta_box_content'),
            $screen,
            'side',
            'high'
        );
    }
    
    /**
     * Delivery meta box content
     */
    public function delivery_meta_box_content($post_or_order) {
        // Handle both post and order object
        if (is_a($post_or_order, 'WC_Order')) {
            $order = $post_or_order;
            $order_id = $order->get_id();
        } else {
            $order_id = $post_or_order->ID;
            $order = wc_get_order($order_id);
        }
        
        if (!$order) {
            echo '<p>' . __('Order not found.', 'wc-home-delivery') . '</p>';
            return;
        }
        
        $delivery_date = $order->get_meta('_delivery_date', true);
        $delivery_postcode = $order->get_meta('_delivery_postcode', true);
        $delivery_suburb = $order->get_meta('_delivery_suburb', true);
        $delivery_zone = $order->get_meta('_delivery_zone', true);
        
        wp_nonce_field('wchd_save_delivery_meta', 'wchd_delivery_nonce');
        
        echo '<div class="wchd-delivery-details">';
        
        // Delivery date input
        echo '<p><strong>' . __('Delivery Date:', 'wc-home-delivery') . '</strong></p>';
        echo '<input type="date" name="wchd_delivery_date" id="wchd_delivery_date" value="' . esc_attr($delivery_date) . '" style="width:100%;" />';
        
        if ($delivery_date) {
            try {
                $date = new DateTime($delivery_date);
                echo '<p style="margin-top:5px;"><small>Current: ' . $date->format('l, F j, Y') . '</small></p>';
            } catch (Exception $e) {
                echo '<p style="margin-top:5px;"><small>Current: ' . esc_html($delivery_date) . '</small></p>';
            }
        }
        
        if ($delivery_suburb && $delivery_postcode) {
            echo '<p><strong>' . __('Area:', 'wc-home-delivery') . '</strong><br>' . esc_html($delivery_suburb) . ' ' . esc_html($delivery_postcode) . '</p>';
        }
        
        if ($delivery_zone) {
            echo '<p><strong>' . __('Zone:', 'wc-home-delivery') . '</strong><br>' . esc_html($delivery_zone) . '</p>';
        }
        
        echo '<p class="description">' . __('You can manually set or change the delivery date here.', 'wc-home-delivery') . '</p>';
        
        echo '</div>';
    }
    
    /**
     * Add admin menu for reports
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Delivery Reports', 'wc-home-delivery'),
            __('Delivery Reports', 'wc-home-delivery'),
            'manage_woocommerce',
            'wchd-delivery-reports',
            array($this, 'delivery_reports_page')
        );
    }
    
    /**
     * Delivery reports page
     */
    public function delivery_reports_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php
            // Get delivery data
            global $wpdb;
            $table_name = $wpdb->prefix . 'home_delivery_dates';
            
            // Get upcoming deliveries
            $upcoming_deliveries = $wpdb->get_results(
                "SELECT d.*, p.post_status, pm.meta_value as order_total
                FROM $table_name d
                LEFT JOIN {$wpdb->posts} p ON d.order_id = p.ID
                LEFT JOIN {$wpdb->postmeta} pm ON d.order_id = pm.post_id AND pm.meta_key = '_order_total'
                WHERE d.delivery_date >= CURDATE()
                AND p.post_status IN ('wc-processing', 'wc-on-hold', 'wc-completed')
                ORDER BY d.delivery_date ASC"
            );
            
            // Group by date
            $deliveries_by_date = array();
            foreach ($upcoming_deliveries as $delivery) {
                $date = $delivery->delivery_date;
                if (!isset($deliveries_by_date[$date])) {
                    $deliveries_by_date[$date] = array(
                        'count' => 0,
                        'total' => 0,
                        'orders' => array()
                    );
                }
                $deliveries_by_date[$date]['count']++;
                $deliveries_by_date[$date]['total'] += floatval($delivery->order_total);
                $deliveries_by_date[$date]['orders'][] = $delivery->order_id;
            }
            ?>
            
            <div class="wchd-reports">
                <h2><?php _e('Upcoming Deliveries', 'wc-home-delivery'); ?></h2>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Date', 'wc-home-delivery'); ?></th>
                            <th><?php _e('Day', 'wc-home-delivery'); ?></th>
                            <th><?php _e('Orders', 'wc-home-delivery'); ?></th>
                            <th><?php _e('Total Value', 'wc-home-delivery'); ?></th>
                            <th><?php _e('Actions', 'wc-home-delivery'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($deliveries_by_date)) : ?>
                            <tr>
                                <td colspan="5"><?php _e('No upcoming deliveries found.', 'wc-home-delivery'); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($deliveries_by_date as $date => $data) : ?>
                                <?php $date_obj = new DateTime($date); ?>
                                <tr>
                                    <td><strong><?php echo $date_obj->format('F j, Y'); ?></strong></td>
                                    <td><?php echo $date_obj->format('l'); ?></td>
                                    <td><?php echo $data['count']; ?></td>
                                    <td><?php echo wc_price($data['total']); ?></td>
                                    <td>
                                        <a href="<?php echo admin_url('edit.php?post_type=shop_order&delivery_date=' . $date); ?>" class="button button-small">
                                            <?php _e('View Orders', 'wc-home-delivery'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX handler for updating delivery date
     */
    public function ajax_update_delivery_date() {
        check_ajax_referer('wchd_admin_nonce', 'nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            wp_die(-1);
        }
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $delivery_date = isset($_POST['delivery_date']) ? sanitize_text_field($_POST['delivery_date']) : '';
        
        if (!$order_id) {
            wp_send_json_error('Invalid order ID');
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('Order not found');
        }
        
        // Update the delivery date
        $order->update_meta_data('_delivery_date', $delivery_date);
        $order->save();
        
        // Update in custom table
        $this->update_delivery_table($order_id, $delivery_date);
        
        // Return formatted date
        if ($delivery_date) {
            $date = new DateTime($delivery_date);
            $formatted_date = $date->format('l, M j');
            wp_send_json_success(array('formatted_date' => $formatted_date));
        } else {
            wp_send_json_success(array('formatted_date' => ''));
        }
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on orders page
        if (!in_array($hook, array('edit.php', 'woocommerce_page_wc-orders'))) {
            return;
        }
        
        // Check if we're on the shop_order post type
        if ($hook === 'edit.php') {
            $screen = get_current_screen();
            if (!$screen || $screen->post_type !== 'shop_order') {
                return;
            }
        }
        
        wp_enqueue_script(
            'wchd-admin',
            WCHD_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            '1.0.5',
            true
        );
        
        wp_localize_script('wchd-admin', 'wchd_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wchd_admin_nonce')
        ));
        
        wp_enqueue_style(
            'wchd-admin',
            WCHD_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            '1.0.5'
        );
        
        // Add inline CSS to ensure sortable styling and fix HPOS sorting
        $inline_css = '
            /* Make sortable headers clickable */
            .wp-list-table .sortable a,
            .wp-list-table .sorted a {
                display: block;
                width: 100%;
                padding: 4px 21px 4px 0;
            }
            
            /* Ensure HDS column is sortable */
            th.sortable.column-hds_delivery_date a,
            th.sorted.column-hds_delivery_date a {
                cursor: pointer;
            }
            
            /* Fix sorting arrows */
            .wp-list-table .sorting-indicators {
                margin-top: 8px;
            }
            
            /* Ensure column header is properly styled */
            .column-hds_delivery_date {
                width: 140px;
            }
        ';
        wp_add_inline_style('wchd-admin', $inline_css);
        
        // Add JavaScript to fix HPOS sorting URLs
        if ($hook === 'woocommerce_page_wc-orders') {
            $inline_js = '
            jQuery(document).ready(function($) {
                // Fix sorting links for HPOS
                $("th.sortable.column-hds_delivery_date a, th.sorted.column-hds_delivery_date a").each(function() {
                    var href = $(this).attr("href");
                    if (href && href.indexOf("orderby=hds_delivery_date") === -1) {
                        // Update the link to include proper orderby parameter
                        var url = new URL(href, window.location.href);
                        url.searchParams.set("orderby", "hds_delivery_date");
                        
                        // Toggle order
                        var currentOrder = url.searchParams.get("order") || "asc";
                        if ($(this).parent().hasClass("sorted") && $(this).parent().hasClass("asc")) {
                            url.searchParams.set("order", "desc");
                        } else {
                            url.searchParams.set("order", "asc");
                        }
                        
                        $(this).attr("href", url.toString());
                    }
                });
            });
            ';
            wp_add_inline_script('wchd-admin', $inline_js);
        }
    }
    
    /**
     * Save delivery meta box data
     */
    public function save_delivery_meta_box($post_id) {
        // Check nonce
        if (!isset($_POST['wchd_delivery_nonce']) || !wp_verify_nonce($_POST['wchd_delivery_nonce'], 'wchd_save_delivery_meta')) {
            return;
        }
        
        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Check permissions
        if (!current_user_can('edit_shop_order', $post_id)) {
            return;
        }
        
        // Save delivery date
        if (isset($_POST['wchd_delivery_date'])) {
            $delivery_date = sanitize_text_field($_POST['wchd_delivery_date']);
            update_post_meta($post_id, '_delivery_date', $delivery_date);
            
            // Update custom table
            $this->update_delivery_table($post_id, $delivery_date);
        }
    }
    
    /**
     * Save delivery meta box data for HPOS
     */
    public function save_delivery_meta_box_hpos($order_id) {
        // Check nonce
        if (!isset($_POST['wchd_delivery_nonce']) || !wp_verify_nonce($_POST['wchd_delivery_nonce'], 'wchd_save_delivery_meta')) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Save delivery date
        if (isset($_POST['wchd_delivery_date'])) {
            $delivery_date = sanitize_text_field($_POST['wchd_delivery_date']);
            $order->update_meta_data('_delivery_date', $delivery_date);
            $order->save();
            
            // Update custom table
            $this->update_delivery_table($order_id, $delivery_date);
        }
    }
    
    /**
     * Update delivery table
     */
    private function update_delivery_table($order_id, $delivery_date) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'home_delivery_dates';
        
        if ($delivery_date) {
            // Check if entry exists
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_name WHERE order_id = %d",
                $order_id
            ));
            
            if ($existing) {
                // Update existing
                $wpdb->update(
                    $table_name,
                    array('delivery_date' => $delivery_date),
                    array('order_id' => $order_id)
                );
            } else {
                // Insert new
                $wpdb->insert(
                    $table_name,
                    array(
                        'order_id' => $order_id,
                        'delivery_date' => $delivery_date,
                        'delivery_window' => ''
                    )
                );
            }
        } else {
            // Delete if date is empty
            $wpdb->delete($table_name, array('order_id' => $order_id));
        }
    }
}