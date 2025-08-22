# AI Rules for WooCommerce Home Delivery Date Plugin

## Tech Stack Overview

- **WordPress Plugin Architecture**: Standard WordPress plugin structure with main plugin file, includes directory, and assets
- **WooCommerce Integration**: Extends WooCommerce checkout, order management, and admin functionality
- **PHP Backend**: Object-oriented PHP classes for API integration, admin settings, checkout fields, and delivery logic
- **jQuery Frontend**: Client-side JavaScript using jQuery for checkout interactions, calendar functionality, and AJAX requests
- **CSS Styling**: Custom CSS with Blocksy theme compatibility, responsive design, and CSS custom properties
- **WordPress Admin**: Custom admin pages, dashboard widgets, order column customizations, and settings panels
- **AJAX Communication**: WordPress AJAX handlers for postcode checking, delivery date retrieval, and order updates
- **Database Integration**: WordPress options API for settings, custom post meta for delivery data

## Library and Framework Rules

### PHP Development
- **MUST USE**: WordPress core functions (`wp_enqueue_script`, `add_action`, `wp_ajax_*`, etc.)
- **MUST USE**: WooCommerce hooks and functions (`woocommerce_checkout_fields`, `WC_Order`, etc.)
- **AVOID**: External PHP frameworks - stick to WordPress/WooCommerce APIs
- **REQUIRED**: Proper WordPress coding standards and security practices (nonces, sanitization)

### JavaScript Development
- **MUST USE**: jQuery (already loaded by WordPress/WooCommerce)
- **AVOID**: Modern frameworks like React, Vue, or Angular - this is a traditional WordPress plugin
- **REQUIRED**: WordPress AJAX pattern with `wp_localize_script` for passing data
- **PATTERN**: Use `jQuery(document).ready()` and namespace all functions

### CSS Styling
- **MUST USE**: CSS custom properties (CSS variables) for theme compatibility
- **REQUIRED**: Blocksy theme compatibility classes and variables
- **AVOID**: CSS frameworks like Bootstrap or Tailwind - use custom CSS
- **PATTERN**: Mobile-first responsive design with `@media` queries

### WordPress Integration
- **MUST USE**: WordPress hooks system (`add_action`, `add_filter`)
- **MUST USE**: WordPress options API for settings storage
- **MUST USE**: WordPress nonce system for security
- **REQUIRED**: Proper plugin activation/deactivation hooks

## File Structure Rules

### PHP Files
- Main plugin file: `woocommerce-home-delivery-date.php`
- Classes in `includes/` directory with `class-` prefix
- One class per file, following WordPress naming conventions
- Use proper WordPress plugin headers and documentation

### Asset Files
- CSS files in `assets/css/` directory
- JavaScript files in `assets/js/` directory
- Separate admin and frontend assets
- Use WordPress `wp_enqueue_*` functions for loading

### Naming Conventions
- Plugin prefix: `wchd_` for functions and `WCHD_` for classes
- CSS classes: `wchd-` prefix for custom styles
- JavaScript objects: `wchd_ajax` for localized data
- Database options: `wchd_` prefix

## Development Patterns

### AJAX Implementation
```php
// PHP: Register AJAX handlers
add_action('wp_ajax_action_name', 'callback_function');
add_action('wp_ajax_nopriv_action_name', 'callback_function');

// JavaScript: Use WordPress AJAX pattern
$.ajax({
    url: wchd_ajax.ajax_url,
    data: { action: 'action_name', nonce: wchd_ajax.nonce }
});
```

### Settings Management
- Use WordPress Options API (`get_option`, `update_option`)
- Group related settings in arrays
- Provide default values for all options

### Security Requirements
- Always use nonces for AJAX requests
- Sanitize all input data
- Escape all output data
- Check user capabilities before admin actions

## Integration Rules

### WooCommerce Compatibility
- Hook into WooCommerce checkout process properly
- Use WooCommerce order meta functions
- Follow WooCommerce coding standards
- Test with WooCommerce updates

### Theme Compatibility
- Use CSS custom properties for easy theme integration
- Avoid hardcoded colors or fonts
- Provide fallback styles for unsupported themes
- Test with popular WordPress themes

### Plugin Compatibility
- Don't conflict with other delivery plugins
- Use unique function and class names
- Handle graceful degradation if dependencies missing

## Code Quality Standards

### Error Handling
- Use WordPress error handling patterns
- Log errors appropriately
- Provide user-friendly error messages
- Track delivery issues for debugging

### Performance
- Minimize database queries
- Cache API responses when appropriate
- Use WordPress transients for temporary data
- Optimize JavaScript for mobile devices

### Documentation
- Comment complex logic thoroughly
- Use WordPress documentation standards
- Provide inline code examples
- Document all hooks and filters

## Forbidden Practices

- **NO** external CDNs for libraries (use WordPress bundled versions)
- **NO** inline JavaScript or CSS (use proper enqueue system)
- **NO** direct database queries (use WordPress APIs)
- **NO** hardcoded paths or URLs (use WordPress functions)
- **NO** PHP short tags or deprecated functions
- **NO** global variables without proper prefixing

## Testing Requirements

- Test with latest WordPress and WooCommerce versions
- Verify mobile responsiveness
- Test AJAX functionality thoroughly
- Validate HTML and CSS
- Check accessibility compliance
- Test with different themes and plugins