/**
 * Admin JavaScript
 * File: assets/js/admin.js
 */

jQuery(document).ready(function($) {
    'use strict';
    
    // Edit delivery date click
    $(document).on('click', '.wchd-edit-date', function(e) {
        e.preventDefault();
        
        var $cell = $(this).closest('.wchd-delivery-date-cell');
        $cell.find('.wchd-date-display, .wchd-edit-date').hide();
        $cell.find('.wchd-date-edit-form').show();
        $cell.find('.wchd-date-input').focus();
    });
    
    // Cancel edit
    $(document).on('click', '.wchd-cancel-edit', function(e) {
        e.preventDefault();
        
        var $cell = $(this).closest('.wchd-delivery-date-cell');
        $cell.find('.wchd-date-display, .wchd-edit-date').show();
        $cell.find('.wchd-date-edit-form').hide();
    });
    
    // Save delivery date
    $(document).on('click', '.wchd-save-date', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $cell = $button.closest('.wchd-delivery-date-cell');
        var orderId = $cell.data('order-id');
        var deliveryDate = $cell.find('.wchd-date-input').val();
        
        // Show loading
        $button.prop('disabled', true).text('Saving...');
        
        // AJAX request
        $.ajax({
            url: wchd_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'update_delivery_date',
                order_id: orderId,
                delivery_date: deliveryDate,
                nonce: wchd_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Update display
                    var $display = $cell.find('.wchd-date-display');
                    
                    if (response.data.formatted_date) {
                        var zone = $display.find('small').length ? '<br>' + $display.find('small').prop('outerHTML') : '';
                        $display.html('<strong>' + response.data.formatted_date + '</strong>' + zone);
                    } else {
                        $display.html('—');
                    }
                    
                    // Hide edit form
                    $cell.find('.wchd-date-display, .wchd-edit-date').show();
                    $cell.find('.wchd-date-edit-form').hide();
                    
                    // Flash success
                    $cell.css('background-color', '#c8e6c9');
                    setTimeout(function() {
                        $cell.css('background-color', '');
                    }, 1000);
                } else {
                    alert('Error: ' + (response.data || 'Failed to update delivery date'));
                }
            },
            error: function() {
                alert('Error updating delivery date. Please try again.');
            },
            complete: function() {
                $button.prop('disabled', false).text('Save');
            }
        });
    });
    
    // Allow Enter key to save
    $(document).on('keypress', '.wchd-date-input', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $(this).siblings('.wchd-save-date').click();
        }
    });
    
    // Allow Escape key to cancel
    $(document).on('keydown', '.wchd-date-input', function(e) {
        if (e.which === 27) {
            e.preventDefault();
            $(this).siblings('.wchd-cancel-edit').click();
        }
    });
});