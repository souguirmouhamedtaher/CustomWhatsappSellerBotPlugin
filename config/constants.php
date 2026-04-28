<?php
/**
 * Plugin Configuration Constants
 * 
 * Central location for all plugin-wide constants to enable easy adjustments
 * for different environments (dev, staging, production).
 */

if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// REST API Configuration
// ============================================================================

/** REST API namespace for all endpoints */
if (!defined('CWSB_REST_NS')) {
    define('CWSB_REST_NS', 'whatsapp-bot/v1');
}

/** Plugin directory path for requiring class files */
if (!defined('CWSB_PLUGIN_DIR')) {
    define('CWSB_PLUGIN_DIR', plugin_dir_path(__FILE__) . '..');
}

// ============================================================================
// Database Configuration
// ============================================================================

/** Custom seller state table name (auto-prefixed with wp_ or custom prefix) */
if (!defined('CWSB_STATE_TABLE')) {
    // Will be resolved as {wpdb->prefix}cwsb_seller_state
    define('CWSB_STATE_TABLE', 'cwsb_seller_state');
}

// ============================================================================
// Product Configuration
// ============================================================================

/** Maximum products to return per seller in single request */
if (!defined('CWSB_PRODUCTS_LIMIT')) {
    define('CWSB_PRODUCTS_LIMIT', 200);
}

/** Maximum carousel images per product */
if (!defined('CWSB_MAX_CAROUSEL_IMAGES')) {
    define('CWSB_MAX_CAROUSEL_IMAGES', 3);
}

/** Maximum uploaded images accepted for add-product payloads */
if (!defined('CWSB_MAX_PRODUCT_UPLOAD_IMAGES')) {
    define('CWSB_MAX_PRODUCT_UPLOAD_IMAGES', 10);
}

/** Maximum gallery images returned by full product detail endpoints */
if (!defined('CWSB_MAX_PRODUCT_GALLERY_IMAGES')) {
    define('CWSB_MAX_PRODUCT_GALLERY_IMAGES', 10);
}

// ============================================================================
// Order Configuration
// ============================================================================

/** Default number of orders to return */
if (!defined('CWSB_ORDERS_DEFAULT_LIMIT')) {
    define('CWSB_ORDERS_DEFAULT_LIMIT', 5);
}

/** Maximum orders per page in pagination */
if (!defined('CWSB_ORDERS_MAX_PAGE_LIMIT')) {
    define('CWSB_ORDERS_MAX_PAGE_LIMIT', 5);
}

/** Maximum product IDs to scan when finding seller orders */
if (!defined('CWSB_MAX_PRODUCT_IDS')) {
    define('CWSB_MAX_PRODUCT_IDS', 3000);
}

// ============================================================================
// Wallet / Finance Configuration
// ============================================================================

/** Commission rate applied to seller net revenue (22.61%) */
if (!defined('CWSB_WALLET_COMMISSION_RATE')) {
    define('CWSB_WALLET_COMMISSION_RATE', 0.2261);
}

/** EUR to TND conversion rate */
if (!defined('CWSB_EUR_TO_TND_RATE')) {
    define('CWSB_EUR_TO_TND_RATE', 3.358);
}

/** Maximum transactions per page in wallet pagination */
if (!defined('CWSB_WALLET_TX_PAGE_LIMIT')) {
    define('CWSB_WALLET_TX_PAGE_LIMIT', 5);
}

// ============================================================================
// Performance & Debugging
// ============================================================================

/** Enable performance logging */
if (!defined('CWSB_DEBUG_PERFORMANCE')) {
    define('CWSB_DEBUG_PERFORMANCE', defined('WP_DEBUG') && WP_DEBUG);
}

/** Enable query logging */
if (!defined('CWSB_DEBUG_QUERIES')) {
    define('CWSB_DEBUG_QUERIES', defined('WP_DEBUG') && WP_DEBUG);
}
