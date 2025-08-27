/**
 * Checkout JavaScript with Issue Tracking
 * File: assets/js/checkout.js
 */

jQuery(document).ready(function($) {
    'use strict';
    
    var checkTimeout;
    var lastCheckedPostcode = '';
    var lastCheckedSuburb = '';
    var isChecking = false;
    var pendingCheck = false;
    var validationInProgress = false;
    
    // Initialize on page load
    init();
    
    // Track delivery issues
    function trackIssue(issueType, additionalData) {
        var data = {
            action: 'wchd_track_delivery_issue',
            issue_type: issueType,
            postcode: getCurrentPostcodeAndSuburb().postcode,
            suburb: getCurrentPostcodeAndSuburb().suburb,
            customer_email: $('#billing_email').val() || ''
        };
        
        // Add additional data if provided
        if (additionalData) {
            $.extend(data, additionalData);
        }
        
        // Send tracking data
        $.post(wchd_ajax.ajax_url, data);
    }
    
    function init() {
        // Check if we need delivery
        if (wchd_ajax.needs_delivery !== 'yes') {
            $('#home_delivery_fields').hide();
            return;
        }
        
        // Bind to postcode changes
        bindPostcodeListeners();
        
        // Check if ship to different address is checked
        $('#ship-to-different-address-checkbox').on('change', function() {
            setTimeout(function() {
                checkPostcodeAndSuburb();
            }, 100);
        });
        
        // Track when address fields change after initial load
        var addressChangeCount = 0;
        $('#billing_postcode, #billing_city, #shipping_postcode, #shipping_city').on('change', function() {
            addressChangeCount++;
            if (addressChangeCount > 2) { // Track if changed multiple times
                trackIssue('address_change_issue', {
                    error_message: 'Address changed ' + addressChangeCount + ' times'
                });
            }
        });
        
        // Prevent form submission while checking - but be less strict
        $('form.checkout').on('submit', function(e) {
            if (isChecking || validationInProgress) {
                e.preventDefault();
                showMessage('info', 'Please wait while we verify your delivery area...');
                
                // Try again after a short delay
                setTimeout(function() {
                    if (!isChecking && !validationInProgress) {
                        $('form.checkout').submit();
                    }
                }, 1000);
                
                return false;
            }
            
            // Less strict validation - only block if we have an invalid postcode format
            var currentData = getCurrentPostcodeAndSuburb();
            if (currentData.postcode && currentData.postcode.length === 4) {
                var postcodeInt = parseInt(currentData.postcode);
                if (!((postcodeInt >= 3000 && postcodeInt <= 3999) || (postcodeInt >= 8000 && postcodeInt <= 8999))) {
                    e.preventDefault();
                    showMessage('error', 'Sorry, we only deliver to Victoria postcodes (3000-3999, 8000-8999).');
                    return false;
                }
            }
            
            // Allow checkout to proceed even if serviceability check hasn't completed
            // The backend validation will handle this more gracefully
        });
        
        // Initial check if postcode already exists
        setTimeout(function() {
            checkPostcodeAndSuburb();
        }, 500);
    }
    
    // Bind listeners to postcode and suburb fields
    function bindPostcodeListeners() {
        // Billing fields - increased delay for fast typers
        $('#billing_postcode, #billing_city').on('input keyup change', function() {
            clearTimeout(checkTimeout);
            pendingCheck = true;
            checkTimeout = setTimeout(function() {
                if (pendingCheck) {
                    checkPostcodeAndSuburb();
                    pendingCheck = false;
                }
            }, 1200); // Increased to 1200ms to reduce API calls
        });
        
        // Shipping fields
        $('#shipping_postcode, #shipping_city').on('input keyup change', function() {
            clearTimeout(checkTimeout);
            pendingCheck = true;
            checkTimeout = setTimeout(function() {
                if (pendingCheck) {
                    checkPostcodeAndSuburb();
                    pendingCheck = false;
                }
            }, 1200);
        });
        
        // State changes - immediate check
        $('#billing_state, #shipping_state').on('change', function() {
            clearTimeout(checkTimeout);
            setTimeout(function() {
                checkPostcodeAndSuburb();
            }, 100);
        });
    }
    
    // Get current postcode and suburb
    function getCurrentPostcodeAndSuburb() {
        var shipToDifferent = $('#ship-to-different-address-checkbox').is(':checked');
        var postcode, suburb, state;
        
        if (shipToDifferent) {
            postcode = $('#shipping_postcode').val();
            suburb = $('#shipping_city').val();
            state = $('#shipping_state').val();
        } else {
            postcode = $('#billing_postcode').val();
            suburb = $('#billing_city').val();
            state = $('#billing_state').val();
        }
        
        return {
            postcode: postcode ? postcode.trim() : '',
            suburb: suburb ? suburb.trim() : '',
            state: state
        };
    }
    
    // Check postcode and suburb with callback support
    function checkPostcodeAndSuburb(callback) {
        var data = getCurrentPostcodeAndSuburb();
        
        // Don't check if already checking
        if (isChecking) {
            if (callback) {
                // Wait for current check to complete
                var waitForCheck = setInterval(function() {
                    if (!isChecking) {
                        clearInterval(waitForCheck);
                        callback();
                    }
                }, 100);
            }
            return;
        }
        
        // Don't check if no postcode
        if (!data.postcode || data.postcode.length !== 4) {
            hideDeliveryOptions();
            if (callback) callback();
            return;
        }
        
        // Don't check if not Victoria
        if (data.state && data.state !== 'VIC') {
            showNotServiceableMessage('We only deliver to Victoria.');
            trackIssue('not_serviceable', {
                error_message: 'Non-Victoria state selected: ' + data.state
            });
            if (callback) callback();
            return;
        }
        
        // Don't re-check the same postcode/suburb combination unless forced
        if (data.postcode === lastCheckedPostcode && data.suburb === lastCheckedSuburb && !callback) {
            return;
        }
        
        // Validate Victoria postcode
        var postcodeInt = parseInt(data.postcode);
        if (!((postcodeInt >= 3000 && postcodeInt <= 3999) || (postcodeInt >= 8000 && postcodeInt <= 8999))) {
            showNotServiceableMessage('Sorry, we only deliver to Victoria postcodes (3000-3999, 8000-8999).');
            trackIssue('not_serviceable', {
                error_message: 'Invalid postcode range: ' + data.postcode
            });
            if (callback) callback();
            return;
        }
        
        // Store for comparison
        lastCheckedPostcode = data.postcode;
        lastCheckedSuburb = data.suburb;
        
        // Show checking message
        showMessage('info', 'Checking delivery availability...');
        isChecking = true;
        validationInProgress = true;
        
        // Check serviceability
        $.ajax({
            url: wchd_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'check_postcode_serviceability',
                postcode: data.postcode,
                suburb: data.suburb,
                nonce: wchd_ajax.nonce
            },
            timeout: 15000, // Increased timeout to 15 seconds
            success: function(response) {
                isChecking = false;
                validationInProgress = false;
                
                if (response.success) {
                    if (response.data.require_suburb) {
                        // Need suburb selection - wait for user to enter suburb
                        showMessage('info', 'Please enter your suburb to check delivery availability.');
                        hideDeliveryOptions();
                        // Set as serviceable so checkout can proceed
                        $('#is_serviceable').val('1');
                    } else {
                        // Serviceable - get delivery dates
                        handleServiceableResponse(response.data, callback);
                        return; // Don't call callback here, it's called in handleServiceableResponse
                    }
                } else {
                    // API says not serviceable, but allow checkout to proceed
                    showMessage('info', 'Delivery availability could not be confirmed. You can still place your order and we will contact you about delivery.');
                    hideDeliveryOptions();
                    $('#is_serviceable').val('1'); // Allow checkout to proceed
                    trackIssue('not_serviceable_but_allowed', {
                        error_message: response.data.message
                    });
                }
                
                if (callback) callback();
            },
            error: function(xhr, status, error) {
                isChecking = false;
                validationInProgress = false;
                
                // On API error, allow checkout to proceed
                showMessage('info', 'Delivery availability could not be checked. You can still place your order and we will contact you about delivery.');
                $('#is_serviceable').val('1'); // Allow checkout to proceed
                
                trackIssue('postcode_check_failed_but_allowed', {
                    error_message: 'AJAX error: ' + error + ' Status: ' + status
                });
                
                if (callback) callback();
            }
        });
    }
    
    // Handle serviceable response
    function handleServiceableResponse(data, callback) {
        // Store zone info
        $('#delivery_zone').val(data.zone);
        $('#delivery_depot').val(data.depot);
        $('#is_serviceable').val('1');
        
        var currentData = getCurrentPostcodeAndSuburb();
        $('#delivery_postcode').val(currentData.postcode);
        
        // Use matched suburb if provided (in case of case mismatch)
        if (data.matched_suburb) {
            $('#delivery_suburb').val(data.matched_suburb);
            // Update the WooCommerce field if different
            var shipToDifferent = $('#ship-to-different-address-checkbox').is(':checked');
            if (shipToDifferent) {
                $('#shipping_city').val(data.matched_suburb);
            } else {
                $('#billing_city').val(data.matched_suburb);
            }
        } else if (data.auto_suburb) {
            $('#delivery_suburb').val(data.auto_suburb);
        } else {
            $('#delivery_suburb').val(currentData.suburb);
        }
        
        // Get delivery dates
        getDeliveryDates(callback);
    }
    
    // Get delivery dates with callback support
    function getDeliveryDates(callback) {
        $.ajax({
            url: wchd_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'get_delivery_dates',
                nonce: wchd_ajax.nonce
            },
            timeout: 15000,
            success: function(response) {
                if (response.success) {
                    populateDeliveryDates(response.data);
                    $('#delivery_date_selector').slideDown();
                    showMessage('success', 'Great! We deliver to your area. Please select your preferred delivery date below.');
                } else {
                    showMessage('info', 'Delivery dates could not be loaded, but you can still place your order. We will contact you about delivery scheduling.');
                    trackIssue('no_dates_available_but_allowed', {
                        error_message: response.data.message
                    });
                }
                
                if (callback) callback();
            },
            error: function(xhr, status, error) {
                showMessage('info', 'Delivery dates could not be loaded, but you can still place your order. We will contact you about delivery scheduling.');
                trackIssue('api_error_but_allowed', {
                    error_message: 'Failed to get delivery dates: ' + error
                });
                
                if (callback) callback();
            }
        });
    }
    
    // Populate delivery dates
    function populateDeliveryDates(data) {
        var $info = $('#delivery_info');
        
        if (data.dates && data.dates.length > 0) {
            // Initialize calendar
            initDeliveryCalendar(data);
            
            // Show delivery info
            var infoHtml = '<div class="wchd-delivery-info">';
            infoHtml += '<p><strong>Delivery Zone:</strong> ' + data.zone + '</p>';
            infoHtml += '<p><strong>Order Cutoff:</strong> Orders must be placed by ' + data.cutoff_time + ' the day before delivery.</p>';
            infoHtml += '</div>';
            
            $info.html(infoHtml);
        } else {
            showMessage('info', 'No delivery dates available, but you can still place your order. We will contact you about delivery scheduling.');
            hideDeliveryOptions();
            trackIssue('no_dates_available_but_allowed', {
                error_message: 'Empty dates array returned'
            });
        }
    }
    
    // Initialize delivery calendar
    function initDeliveryCalendar(data) {
        var availableDates = {};
        var minDate = null;
        var maxDate = null;
        var firstAvailableDate = null;
        
        // Build available dates object
        $.each(data.dates, function(index, dateInfo) {
            availableDates[dateInfo.date] = dateInfo.display;
            
            // Store first available date
            if (!firstAvailableDate) {
                firstAvailableDate = dateInfo.date;
            }
            
            var date = new Date(dateInfo.date);
            if (!minDate || date < minDate) minDate = date;
            if (!maxDate || date > maxDate) maxDate = date;
        });
        
        // Create calendar HTML
        var calendarHtml = '<div class="wchd-calendar-wrapper">';
        calendarHtml += '<div class="wchd-calendar-header">';
        calendarHtml += '<button type="button" class="wchd-cal-prev">&lt;</button>';
        calendarHtml += '<span class="wchd-cal-month"></span>';
        calendarHtml += '<button type="button" class="wchd-cal-next">&gt;</button>';
        calendarHtml += '</div>';
        calendarHtml += '<div class="wchd-calendar-grid">';
        calendarHtml += '<div class="wchd-cal-day-header">Sun</div>';
        calendarHtml += '<div class="wchd-cal-day-header">Mon</div>';
        calendarHtml += '<div class="wchd-cal-day-header">Tue</div>';
        calendarHtml += '<div class="wchd-cal-day-header">Wed</div>';
        calendarHtml += '<div class="wchd-cal-day-header">Thu</div>';
        calendarHtml += '<div class="wchd-cal-day-header">Fri</div>';
        calendarHtml += '<div class="wchd-cal-day-header">Sat</div>';
        calendarHtml += '<div class="wchd-cal-days"></div>';
        calendarHtml += '</div>';
        calendarHtml += '</div>';
        
        $('#delivery_calendar').html(calendarHtml);
        
        var currentMonth = new Date();
        if (minDate && minDate > currentMonth) {
            currentMonth = new Date(minDate);
        }
        
        // Track if calendar loaded successfully
        var calendarLoaded = false;
        
        // Render calendar
        function renderMonth(date) {
            var year = date.getFullYear();
            var month = date.getMonth();
            var firstDay = new Date(year, month, 1).getDay();
            var daysInMonth = new Date(year, month + 1, 0).getDate();
            
            $('.wchd-cal-month').text(date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' }));
            
            var daysHtml = '';
            
            // Empty cells for days before month starts
            for (var i = 0; i < firstDay; i++) {
                daysHtml += '<div class="wchd-cal-day wchd-cal-empty"></div>';
            }
            
            // Days of the month
            for (var day = 1; day <= daysInMonth; day++) {
                var dateStr = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
                var isAvailable = availableDates.hasOwnProperty(dateStr);
                var currentDate = new Date(dateStr);
                var today = new Date();
                today.setHours(0,0,0,0);
                var tomorrow = new Date();
                tomorrow.setDate(tomorrow.getDate() + 1);
                tomorrow.setHours(0,0,0,0);
                var isPast = currentDate < today;
                var isTomorrow = currentDate.getTime() === tomorrow.getTime();
                
                var classes = 'wchd-cal-day';
                if (isAvailable && !isPast) {
                    classes += ' wchd-cal-available';
                } else if (isPast) {
                    classes += ' wchd-cal-past';
                } else if (isTomorrow && !isAvailable) {
                    // Tomorrow but past cutoff
                    classes += ' wchd-cal-unavailable wchd-cal-tomorrow';
                } else {
                    classes += ' wchd-cal-unavailable';
                }
                
                if ($('#delivery_date').val() === dateStr) {
                    classes += ' wchd-cal-selected';
                }
                
                daysHtml += '<div class="' + classes + '" data-date="' + dateStr + '">' + day + '</div>';
            }
            
            $('.wchd-cal-days').html(daysHtml);
            calendarLoaded = true;
        }
        
        // Calendar navigation
        $('.wchd-cal-prev').on('click', function() {
            currentMonth.setMonth(currentMonth.getMonth() - 1);
            renderMonth(currentMonth);
        });
        
        $('.wchd-cal-next').on('click', function() {
            currentMonth.setMonth(currentMonth.getMonth() + 1);
            renderMonth(currentMonth);
        });
        
        // Date selection
        $(document).on('click', '.wchd-cal-available', function() {
            var dateStr = $(this).data('date');
            var displayDate = availableDates[dateStr];
            
            $('.wchd-cal-day').removeClass('wchd-cal-selected');
            $(this).addClass('wchd-cal-selected');
            
            $('#delivery_date').val(dateStr);
            $('#selected_date_display').html('<strong>Selected delivery date:</strong> ' + displayDate);
            
            // Clear any error messages
            $('.woocommerce-error').remove();
        });
        
        // Initial render
        try {
            renderMonth(currentMonth);
        } catch (error) {
            trackIssue('calendar_load_failed', {
                error_message: 'Calendar render error: ' + error.message
            });
        }
        
        // Auto-select first available date if none selected
        setTimeout(function() {
            if (!$('#delivery_date').val() && firstAvailableDate) {
                // Find and click the first available date
                $('.wchd-cal-available[data-date="' + firstAvailableDate + '"]').click();
                
                // If not visible in current month, navigate to it
                if ($('.wchd-cal-available[data-date="' + firstAvailableDate + '"]').length === 0) {
                    currentMonth = new Date(firstAvailableDate);
                    renderMonth(currentMonth);
                    $('.wchd-cal-available[data-date="' + firstAvailableDate + '"]').click();
                }
            }
        }, 100);
    }
    
    // Show not serviceable message
    function showNotServiceableMessage(message) {
        showMessage('error', message);
        hideDeliveryOptions();
        $('#is_serviceable').val('0');
        $('#delivery_zone').val('');
        $('#delivery_depot').val('');
        $('#delivery_date').val('');
    }
    
    // Hide delivery options
    function hideDeliveryOptions() {
        $('#delivery_date_selector').slideUp();
        $('#delivery_date').val('');
        $('#selected_date_display').empty();
        $('#delivery_calendar').empty();
    }
    
    // Show message
    function showMessage(type, message) {
        var $messageDiv = $('#delivery_availability_message');
        var cssClass = type === 'error' ? 'wchd-error' : (type === 'success' ? 'wchd-success' : 'wchd-info');
        
        $messageDiv.html('<div class="' + cssClass + '">' + message + '</div>').show();
    }
    
    // Update checkout when delivery date changes
    $('#delivery_date').on('change', function() {
        if ($(this).val()) {
            // Clear any error messages
            $('.woocommerce-error').remove();
        }
    });
    
    // Listen for checkout updates - preserve our validation state
    $(document.body).on('updated_checkout', function() {
        // Don't re-check immediately after checkout update to avoid race conditions
        setTimeout(function() {
            var currentData = getCurrentPostcodeAndSuburb();
            if (currentData.postcode && $('#is_serviceable').val() !== '1') {
                checkPostcodeAndSuburb();
            }
        }, 1500); // Increased delay
    });
    
    // Track checkout validation failures
    $(document.body).on('checkout_error', function(event, error_message) {
        if (error_message && error_message.indexOf('delivery date') !== -1) {
            trackIssue('checkout_validation_failed', {
                error_message: error_message
            });
        }
    });
});