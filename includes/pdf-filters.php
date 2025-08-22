<?php
/**
 * PDF Filters for Home Delivery
 * File: includes/pdf-filters.php
 * 
 * Add this file to your plugin and include it in your main plugin file:
 * require_once WCHD_PLUGIN_PATH . 'includes/pdf-filters.php';
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Control which PDF document types show delivery info
 * By default, show on all document types
 */
add_filter('wchd_show_delivery_in_pdf', function($show, $document_type, $order) {
    // Show on invoices and packing slips, but not on credit notes
    if ($document_type === 'credit-note') {
        return false;
    }
    
    return true;
}, 10, 3);

/**
 * Customize delivery date position based on document type
 */
add_filter('wchd_show_delivery_after_shop_address', function($show, $document_type, $order) {
    // Show after shop address on invoices
    if ($document_type === 'invoice') {
        return true;
    }
    return false;
}, 10, 3);

add_filter('wchd_show_delivery_before_footer', function($show, $document_type, $order) {
    // Show before footer on packing slips for emphasis
    if ($document_type === 'packing-slip') {
        return true;
    }
    return false;
}, 10, 3);

/**
 * Add delivery date to the order data section (near order number and date)
 * This is useful for the Professional version's summary section
 */
add_filter('wpo_wcpdf_order_data', function($data, $order, $document_type) {
    if (!is_object($order)) {
        return $data;
    }
    
    $delivery_date = $order->get_meta('_delivery_date', true);
    if ($delivery_date) {
        try {
            $date_obj = new DateTime($delivery_date);
            $formatted_date = $date_obj->format('l, F j, Y');
        } catch (Exception $e) {
            $formatted_date = $delivery_date;
        }
        
        // Add to order data array
        $data['delivery_date'] = array(
            'label' => __('Delivery Date:', 'wc-home-delivery'),
            'value' => $formatted_date
        );
        
        $delivery_zone = $order->get_meta('_delivery_zone', true);
        if ($delivery_zone) {
            $data['delivery_zone'] = array(
                'label' => __('Delivery Zone:', 'wc-home-delivery'),
                'value' => $delivery_zone
            );
        }
    }
    
    return $data;
}, 10, 3);

/**
 * Add delivery info to the template's order info array
 * This works with Professional templates that use get_order_info()
 */
add_filter('wpo_wcpdf_order_info', function($info, $document) {
    if (!method_exists($document, 'get_order')) {
        return $info;
    }
    
    $order = $document->get_order();
    if (!$order) {
        return $info;
    }
    
    $delivery_date = $order->get_meta('_delivery_date', true);
    if ($delivery_date) {
        try {
            $date_obj = new DateTime($delivery_date);
            $formatted_date = $date_obj->format('l, F j, Y');
        } catch (Exception $e) {
            $formatted_date = $delivery_date;
        }
        
        // Add delivery date to info array
        $info['delivery_date'] = $formatted_date;
        
        // Add other delivery details
        $info['delivery_zone'] = $order->get_meta('_delivery_zone', true);
        $info['delivery_suburb'] = $order->get_meta('_delivery_suburb', true);
        $info['delivery_postcode'] = $order->get_meta('_delivery_postcode', true);
    }
    
    return $info;
}, 10, 2);

/**
 * Add custom merge tags for Professional templates
 */
add_filter('wpo_wcpdf_custom_text_merge_tags', function($merge_tags, $document) {
    if (!method_exists($document, 'get_order')) {
        return $merge_tags;
    }
    
    $order = $document->get_order();
    if (!$order) {
        return $merge_tags;
    }
    
    $delivery_date = $order->get_meta('_delivery_date', true);
    if ($delivery_date) {
        try {
            $date_obj = new DateTime($delivery_date);
            $formatted_date = $date_obj->format('l, F j, Y');
        } catch (Exception $e) {
            $formatted_date = $delivery_date;
        }
        
        $merge_tags['{{delivery_date}}'] = $formatted_date;
        $merge_tags['{{delivery_zone}}'] = $order->get_meta('_delivery_zone', true);
        $merge_tags['{{delivery_area}}'] = $order->get_meta('_delivery_suburb', true) . ' ' . $order->get_meta('_delivery_postcode', true);
    }
    
    return $merge_tags;
}, 10, 2);

/**
 * For templates that use blocks, add delivery block
 */
add_action('wpo_wcpdf_custom_blocks', function($document) {
    if (!method_exists($document, 'get_order')) {
        return;
    }
    
    $order = $document->get_order();
    if (!$order) {
        return;
    }
    
    $delivery_date = $order->get_meta('_delivery_date', true);
    if (!$delivery_date) {
        return;
    }
    
    try {
        $date_obj = new DateTime($delivery_date);
        $formatted_date = $date_obj->format('l, F j, Y');
    } catch (Exception $e) {
        $formatted_date = $delivery_date;
    }
    
    ?>
    <div class="delivery-block" style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0;">
        <h3 style="margin: 0 0 5px 0; color: #856404;"><?php _e('Delivery Information', 'wc-home-delivery'); ?></h3>
        <p style="margin: 0; font-weight: bold; color: #856404;">
            <?php _e('Scheduled Delivery:', 'wc-home-delivery'); ?> <?php echo esc_html($formatted_date); ?>
        </p>
        <?php
        $zone = $order->get_meta('_delivery_zone', true);
        if ($zone) {
            echo '<p style="margin: 0; font-size: 12px; color: #856404;">' . __('Zone:', 'wc-home-delivery') . ' ' . esc_html($zone) . '</p>';
        }
        ?>
    </div>
    <?php
});