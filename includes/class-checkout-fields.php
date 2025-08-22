<?php
/**
 * Checkout Fields Integration Class - Enhanced for PDF
 * File: includes/class-checkout-fields.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCHD_Checkout_Fields {
    
    public function __construct() {
        // Add custom checkout fields
        add_action('woocommerce_after_checkout_billing_form', array($this, 'add_delivery_date_fields'));
        
        // Validate checkout fields
        add_action('woocommerce_checkout_process', array($this, 'validate_delivery_fields'));
        
        // Save delivery date to order
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_delivery_date'));
        
        // Display in order details
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_delivery_date_admin'));
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_delivery_date_customer'));
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Add to email
        add_action('woocommerce_email_order_meta', array($this, 'add_delivery_date_to_email'), 10, 3);
        
        // PDF Invoices & Packing Slips for WooCommerce integration - Multiple hooks for better compatibility
        add_action('wpo_wcpdf_after_order_data', array($this, 'add_delivery_date_to_pdf'), 10, 2);
        add_action('wpo_wcpdf_after_order_details', array($this, 'add_delivery_date_to_pdf_after_details'), 10, 2);
        add_action('wpo_wcpdf_before_customer_notes', array($this, 'add_delivery_date_to_pdf_before_notes'), 10, 2);
        
        // For templates that use custom positions
        add_action('wpo_wcpdf_after_document_label', array($this, 'add_delivery_date_to_pdf_header'), 10, 2);
        
        // Add custom styles
        add_filter('wpo_wcpdf_custom_styles', array($this, 'add_pdf_styles'));
        
        // Add to template settings for Professional version
        add_filter('wpo_wcpdf_templates_settings', array($this, 'add_delivery_to_template_settings'), 10, 3);
        
        // Add custom display option
        add_filter('wpo_wcpdf_document_settings', array($this, 'add_delivery_display_option'), 10, 2);
        
        // Hook into the order info section specifically
        add_action('wpo_wcpdf_after_shop_address', array($this, 'add_delivery_date_after_shop_address'), 10, 2);
        add_action('wpo_wcpdf_before_shop_address', array($this, 'add_delivery_date_before_shop_address'), 10, 2);
        
        // For summary/totals area
        add_action('wpo_wcpdf_after_order_notes', array($this, 'add_delivery_date_after_notes'), 10, 2);
        add_action('wpo_wcpdf_before_footer', array($this, 'add_delivery_date_before_footer'), 10, 2);
    }
    
    /**
     * Add delivery date fields to checkout
     */
    public function add_delivery_date_fields() {
        $shipping_country = WC()->customer->get_shipping_country();
        $billing_country = WC()->customer->get_billing_country();
        
        // Only show for Australian addresses
        if (($shipping_country && $shipping_country !== 'AU') && ($billing_country && $billing_country !== 'AU')) {
            return;
        }
        
        echo '<div id="home_delivery_fields" class="home-delivery-wrapper">';
        echo '<h3>' . __('Delivery Information', 'wc-home-delivery') . '</h3>';
        
        // Service availability message
        echo '<div id="delivery_availability_message" class="delivery-message"></div>';
        
        // Delivery date selector (hidden by default)
        echo '<div id="delivery_date_selector" class="form-row form-row-wide" style="display:none;">';
        
        echo '<label for="delivery_date">' . __('Select Delivery Date', 'wc-home-delivery') . ' <abbr class="required" title="required">*</abbr></label>';
        echo '<div id="delivery_calendar"></div>';
        echo '<input type="hidden" name="delivery_date" id="delivery_date" />';
        echo '<div id="selected_date_display" class="selected-date-display"></div>';
        
        echo '<div id="delivery_info" class="delivery-info"></div>';
        echo '</div>';
        
        // Hidden fields for storing data
        echo '<input type="hidden" name="delivery_suburb" id="delivery_suburb" />';
        echo '<input type="hidden" name="delivery_postcode" id="delivery_postcode" />';
        echo '<input type="hidden" name="delivery_zone" id="delivery_zone" />';
        echo '<input type="hidden" name="delivery_depot" id="delivery_depot" />';
        echo '<input type="hidden" name="is_serviceable" id="is_serviceable" value="0" />';
        
        echo '</div>';
    }
    
    /**
     * Validate delivery fields
     */
    public function validate_delivery_fields() {
        // Check if delivery is required (physical products)
        if (!$this->cart_needs_delivery()) {
            return;
        }
        
        // Check if address is serviceable
        if (isset($_POST['is_serviceable']) && $_POST['is_serviceable'] == '0') {
            $postcode = isset($_POST['billing_postcode']) ? $_POST['billing_postcode'] : 
                       (isset($_POST['shipping_postcode']) ? $_POST['shipping_postcode'] : '');
            
            if ($postcode) {
                $postcode_int = intval($postcode);
                if (($postcode_int >= 3000 && $postcode_int <= 3999) || ($postcode_int >= 8000 && $postcode_int <= 8999)) {
                    wc_add_notice(__('Sorry, we do not deliver to your area. Please contact us for alternative arrangements.', 'wc-home-delivery'), 'error');
                } else {
                    wc_add_notice(__('Sorry, we only deliver to Victoria postcodes (3000-3999, 8000-8999).', 'wc-home-delivery'), 'error');
                }
            }
        }
        
        // Check if delivery date is selected for serviceable areas
        if (isset($_POST['is_serviceable']) && $_POST['is_serviceable'] == '1' && empty($_POST['delivery_date'])) {
            wc_add_notice(__('Please select a delivery date.', 'wc-home-delivery'), 'error');
        }
    }
    
    /**
     * Check if cart needs delivery
     */
    private function cart_needs_delivery() {
        $needs_delivery = false;
        
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            if (!$product->is_virtual() && !$product->is_downloadable()) {
                $needs_delivery = true;
                break;
            }
        }
        
        return $needs_delivery;
    }
    
    /**
     * Save delivery date to order
     */
    public function save_delivery_date($order_id) {
        // Get the order object
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Save delivery date
        if (!empty($_POST['delivery_date'])) {
            $delivery_date = sanitize_text_field($_POST['delivery_date']);
            $order->update_meta_data('_delivery_date', $delivery_date);
            
            // Also save using legacy method for compatibility
            update_post_meta($order_id, '_delivery_date', $delivery_date);
        }
        
        // Save delivery postcode
        if (!empty($_POST['delivery_postcode'])) {
            $delivery_postcode = sanitize_text_field($_POST['delivery_postcode']);
            $order->update_meta_data('_delivery_postcode', $delivery_postcode);
            update_post_meta($order_id, '_delivery_postcode', $delivery_postcode);
        }
        
        // Save delivery suburb
        if (!empty($_POST['delivery_suburb'])) {
            $delivery_suburb = sanitize_text_field($_POST['delivery_suburb']);
            $order->update_meta_data('_delivery_suburb', $delivery_suburb);
            update_post_meta($order_id, '_delivery_suburb', $delivery_suburb);
        }
        
        // Save delivery zone
        if (!empty($_POST['delivery_zone'])) {
            $delivery_zone = sanitize_text_field($_POST['delivery_zone']);
            $order->update_meta_data('_delivery_zone', $delivery_zone);
            update_post_meta($order_id, '_delivery_zone', $delivery_zone);
        }
        
        // Save delivery depot
        if (!empty($_POST['delivery_depot'])) {
            $delivery_depot = sanitize_text_field($_POST['delivery_depot']);
            $order->update_meta_data('_delivery_depot', $delivery_depot);
            update_post_meta($order_id, '_delivery_depot', $delivery_depot);
        }
        
        // Save the order
        $order->save();
        
        // Save to custom table for reporting
        if (!empty($_POST['delivery_date'])) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'home_delivery_dates';
            
            $wpdb->insert(
                $table_name,
                array(
                    'order_id' => $order_id,
                    'delivery_date' => sanitize_text_field($_POST['delivery_date']),
                    'delivery_window' => ''
                )
            );
        }
    }
    
    /**
     * Display delivery date in admin
     */
    public function display_delivery_date_admin($order) {
        $delivery_date = get_post_meta($order->get_id(), '_delivery_date', true);
        $delivery_postcode = get_post_meta($order->get_id(), '_delivery_postcode', true);
        $delivery_suburb = get_post_meta($order->get_id(), '_delivery_suburb', true);
        $delivery_zone = get_post_meta($order->get_id(), '_delivery_zone', true);
        
        if ($delivery_date) {
            echo '<h3>' . __('Delivery Information', 'wc-home-delivery') . '</h3>';
            
            try {
                $date = new DateTime($delivery_date);
                echo '<p><strong>' . __('Delivery Date:', 'wc-home-delivery') . '</strong> ' . $date->format('l, F j, Y') . '</p>';
            } catch (Exception $e) {
                echo '<p><strong>' . __('Delivery Date:', 'wc-home-delivery') . '</strong> ' . esc_html($delivery_date) . '</p>';
            }
            
            if ($delivery_suburb && $delivery_postcode) {
                echo '<p><strong>' . __('Delivery Area:', 'wc-home-delivery') . '</strong> ' . esc_html($delivery_suburb) . ' ' . esc_html($delivery_postcode) . '</p>';
            }
            
            if ($delivery_zone) {
                echo '<p><strong>' . __('Zone:', 'wc-home-delivery') . '</strong> ' . esc_html($delivery_zone) . '</p>';
            }
        }
    }
    
    /**
     * Display delivery date to customer
     */
    public function display_delivery_date_customer($order) {
        $delivery_date = get_post_meta($order->get_id(), '_delivery_date', true);
        
        if ($delivery_date) {
            echo '<h2>' . __('Delivery Information', 'wc-home-delivery') . '</h2>';
            echo '<table class="woocommerce-table woocommerce-table--delivery-details shop_table">';
            echo '<tbody>';
            echo '<tr>';
            echo '<th>' . __('Scheduled Delivery Date:', 'wc-home-delivery') . '</th>';
            echo '<td>' . esc_html($delivery_date) . '</td>';
            echo '</tr>';
            echo '</tbody>';
            echo '</table>';
        }
    }
    
    /**
     * Add delivery date to email
     */
    public function add_delivery_date_to_email($order, $sent_to_admin, $plain_text) {
        $delivery_date = get_post_meta($order->get_id(), '_delivery_date', true);
        
        if ($delivery_date) {
            if ($plain_text) {
                echo "\n" . __('Delivery Date:', 'wc-home-delivery') . ' ' . $delivery_date . "\n";
            } else {
                echo '<h2>' . __('Delivery Information', 'wc-home-delivery') . '</h2>';
                echo '<p><strong>' . __('Scheduled Delivery Date:', 'wc-home-delivery') . '</strong> ' . esc_html($delivery_date) . '</p>';
            }
        }
    }
    
    /**
     * Enqueue scripts
     */
    public function enqueue_scripts() {
        if (is_checkout()) {
            wp_enqueue_script(
                'wchd-checkout',
                WCHD_PLUGIN_URL . 'assets/js/checkout.js',
                array('jquery'),
                '1.0.4',
                true
            );
            
            wp_localize_script('wchd-checkout', 'wchd_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wchd_nonce'),
                'needs_delivery' => $this->cart_needs_delivery() ? 'yes' : 'no'
            ));
            
            wp_enqueue_style(
                'wchd-checkout',
                WCHD_PLUGIN_URL . 'assets/css/checkout.css',
                array(),
                '1.0.4'
            );
        }
    }
    
    /**
     * Get formatted delivery info for PDF
     */
    private function get_pdf_delivery_info($order) {
        if (!is_object($order)) {
            return false;
        }
        
        $delivery_date = $order->get_meta('_delivery_date', true);
        if (!$delivery_date) {
            return false;
        }
        
        $info = array(
            'date' => $delivery_date,
            'formatted_date' => $delivery_date,
            'zone' => $order->get_meta('_delivery_zone', true),
            'suburb' => $order->get_meta('_delivery_suburb', true),
            'postcode' => $order->get_meta('_delivery_postcode', true)
        );
        
        try {
            $date_obj = new DateTime($delivery_date);
            $info['formatted_date'] = $date_obj->format('l, F j, Y');
        } catch (Exception $e) {
            // Keep original format
        }
        
        return $info;
    }
    
    /**
     * Main PDF delivery date display
     */
    public function add_delivery_date_to_pdf($document_type, $order) {
        // Check if we should display
        if (!apply_filters('wchd_show_delivery_in_pdf', true, $document_type, $order)) {
            return;
        }
        
        $info = $this->get_pdf_delivery_info($order);
        if (!$info) {
            return;
        }
        
        $this->output_pdf_delivery_section($info, 'after_order_data');
    }
    
    /**
     * Add delivery date after order details
     */
    public function add_delivery_date_to_pdf_after_details($document_type, $order) {
        if (!apply_filters('wchd_show_delivery_after_details', false, $document_type, $order)) {
            return;
        }
        
        $info = $this->get_pdf_delivery_info($order);
        if (!$info) {
            return;
        }
        
        $this->output_pdf_delivery_section($info, 'after_details');
    }
    
    /**
     * Add delivery date before customer notes
     */
    public function add_delivery_date_to_pdf_before_notes($document_type, $order) {
        if (!apply_filters('wchd_show_delivery_before_notes', false, $document_type, $order)) {
            return;
        }
        
        $info = $this->get_pdf_delivery_info($order);
        if (!$info) {
            return;
        }
        
        $this->output_pdf_delivery_section($info, 'before_notes');
    }
    
    /**
     * Add delivery date to PDF header area
     */
    public function add_delivery_date_to_pdf_header($document_type, $order) {
        if (!apply_filters('wchd_show_delivery_in_header', false, $document_type, $order)) {
            return;
        }
        
        $info = $this->get_pdf_delivery_info($order);
        if (!$info) {
            return;
        }
        
        echo '<div class="hds-delivery-header" style="margin: 5px 0; font-weight: bold; color: #e40000;">';
        echo __('Delivery Date:', 'wc-home-delivery') . ' ' . esc_html($info['formatted_date']);
        echo '</div>';
    }
    
    /**
     * Add delivery date after shop address
     */
    public function add_delivery_date_after_shop_address($document_type, $order) {
        if (!apply_filters('wchd_show_delivery_after_shop_address', true, $document_type, $order)) {
            return;
        }
        
        $info = $this->get_pdf_delivery_info($order);
        if (!$info) {
            return;
        }
        
        $this->output_pdf_delivery_section($info, 'after_shop_address');
    }
    
    /**
     * Add delivery date before shop address
     */
    public function add_delivery_date_before_shop_address($document_type, $order) {
        if (!apply_filters('wchd_show_delivery_before_shop_address', false, $document_type, $order)) {
            return;
        }
        
        $info = $this->get_pdf_delivery_info($order);
        if (!$info) {
            return;
        }
        
        $this->output_pdf_delivery_section($info, 'before_shop_address');
    }
    
    /**
     * Add delivery date after notes
     */
    public function add_delivery_date_after_notes($document_type, $order) {
        if (!apply_filters('wchd_show_delivery_after_notes', false, $document_type, $order)) {
            return;
        }
        
        $info = $this->get_pdf_delivery_info($order);
        if (!$info) {
            return;
        }
        
        $this->output_pdf_delivery_section($info, 'after_notes');
    }
    
    /**
     * Add delivery date before footer
     */
    public function add_delivery_date_before_footer($document_type, $order) {
        if (!apply_filters('wchd_show_delivery_before_footer', false, $document_type, $order)) {
            return;
        }
        
        $info = $this->get_pdf_delivery_info($order);
        if (!$info) {
            return;
        }
        
        $this->output_pdf_delivery_section($info, 'before_footer');
    }
    
    /**
     * Output the PDF delivery section
     */
    private function output_pdf_delivery_section($info, $position = '') {
        static $displayed_positions = array();
        
        // Prevent duplicate displays in same position
        if (isset($displayed_positions[$position])) {
            return;
        }
        $displayed_positions[$position] = true;
        
        $class = 'hds-delivery-info';
        if ($position) {
            $class .= ' hds-position-' . str_replace('_', '-', $position);
        }
        
        ?>
        <div class="<?php echo esc_attr($class); ?>">
            <h3><?php _e('Delivery Information', 'wc-home-delivery'); ?></h3>
            <table class="hds-delivery-table">
                <tr>
                    <td class="hds-label"><?php _e('Delivery Date:', 'wc-home-delivery'); ?></td>
                    <td class="hds-value"><?php echo esc_html($info['formatted_date']); ?></td>
                </tr>
                <?php if ($info['suburb'] && $info['postcode']) : ?>
                <tr>
                    <td class="hds-label"><?php _e('Delivery Area:', 'wc-home-delivery'); ?></td>
                    <td class="hds-value"><?php echo esc_html($info['suburb'] . ' ' . $info['postcode']); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($info['zone']) : ?>
                <tr>
                    <td class="hds-label"><?php _e('Zone:', 'wc-home-delivery'); ?></td>
                    <td class="hds-value"><?php echo esc_html($info['zone']); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
        <?php
    }
    
    /**
     * Add custom styles for PDF
     */
    public function add_pdf_styles($styles) {
        $styles .= '
            /* Home Delivery Styles */
            .hds-delivery-info {
                margin: 15px 0;
                padding: 12px;
                border: 1px solid #ddd;
                background: #f9f9f9;
                page-break-inside: avoid;
                clear: both;
            }
            
            .hds-delivery-info h3 {
                margin: 0 0 10px 0;
                font-size: 14px;
                font-weight: bold;
                color: #333;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .hds-delivery-table {
                width: 100%;
                font-size: 12px;
                border-collapse: collapse;
            }
            
            .hds-delivery-table td {
                padding: 5px 0;
                vertical-align: top;
            }
            
            .hds-delivery-table .hds-label {
                width: 35%;
                font-weight: bold;
                color: #666;
            }
            
            .hds-delivery-table .hds-value {
                width: 65%;
                color: #333;
            }
            
            /* Position-specific styles */
            .hds-position-after-shop-address {
                margin-top: 20px;
                margin-bottom: 0;
            }
            
            .hds-position-before-shop-address {
                margin-bottom: 20px;
                margin-top: 0;
            }
            
            .hds-position-after-order-data {
                background: #fff3cd;
                border-color: #ffeaa7;
            }
            
            .hds-position-before-footer {
                margin-top: 30px;
                border: 2px solid #e40000;
                background: #fff;
            }
            
            .hds-position-before-footer h3 {
                color: #e40000;
            }
            
            /* Header style */
            .hds-delivery-header {
                margin: 5px 0;
                font-weight: bold;
                color: #e40000;
                font-size: 14px;
            }
            
            /* Responsive for different templates */
            @media print {
                .hds-delivery-info {
                    background: #f5f5f5 !important;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
            }
        ';
        
        return $styles;
    }
    
    /**
     * Add delivery to template settings (Professional version)
     */
    public function add_delivery_to_template_settings($settings, $document_type, $tab) {
        if ($tab === 'display') {
            $settings[] = array(
                'type' => 'setting',
                'id' => 'show_delivery_date',
                'title' => __('Display delivery date', 'wc-home-delivery'),
                'callback' => 'checkbox',
                'section' => 'display_options',
                'default' => 1,
                'description' => __('Show Home Delivery Service delivery date on the document', 'wc-home-delivery')
            );
            
            $settings[] = array(
                'type' => 'setting',
                'id' => 'delivery_date_position',
                'title' => __('Delivery date position', 'wc-home-delivery'),
                'callback' => 'select',
                'section' => 'display_options',
                'options' => array(
                    'after_order_data' => __('After order data', 'wc-home-delivery'),
                    'after_shop_address' => __('After shop address', 'wc-home-delivery'),
                    'before_footer' => __('Before footer', 'wc-home-delivery'),
                    'after_notes' => __('After customer notes', 'wc-home-delivery')
                ),
                'default' => 'after_shop_address',
                'description' => __('Choose where to display the delivery information', 'wc-home-delivery')
            );
        }
        
        return $settings;
    }
    
    /**
     * Add delivery display option to document settings
     */
    public function add_delivery_display_option($settings, $document) {
        $settings['show_delivery_date'] = array(
            'label' => __('Show delivery date', 'wc-home-delivery'),
            'value' => 'yes',
            'description' => __('Display Home Delivery Service information', 'wc-home-delivery')
        );
        
        return $settings;
    }
}