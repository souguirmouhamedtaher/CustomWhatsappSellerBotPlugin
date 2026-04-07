<?php
/*
Plugin Name: Custom WhatsApp Seller Bot
Description: Seller lookup endpoints for WhatsApp bot.
Version: 1.0.0
Author: ILEYCOM-INTERNSHIPS
*/

if (!defined('ABSPATH')) {
    exit;
}

// Prevent caching of REST API responses to ensure fresh seller state.
if (defined('REST_REQUEST') && REST_REQUEST) {
    nocache_headers();
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    if (!defined('DONOTCACHEDB')) {
        define('DONOTCACHEDB', true);
    }
}

// REST namespace used by all plugin endpoints.
define('CWSB_NS', 'whatsapp-bot/v1');
// Absolute plugin directory path used for requiring class files.
define('CWSB_PLUGIN_DIR', plugin_dir_path(__FILE__));

/**
 * Creates/updates plugin tables on activation.
 *
 * Stores seller flow-related state that is used by WhatsApp auth flow screens.
 * Uses dbDelta so schema changes are safely applied over time.
 */
function cwsb_create_tables()
{
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table_name = $wpdb->prefix . 'cwsb_seller_state';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        phone VARCHAR(50) NOT NULL,
        code VARCHAR(255) NULL,
        flow_token VARCHAR(255) NULL,
        reset_token VARCHAR(255) NULL,
        reset_token_expiry BIGINT(20) NULL,
        session_active_until BIGINT(20) NULL,
        auth_portal_sent_at BIGINT(20) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY user_id (user_id),
        KEY email (email),
        KEY phone (phone),
        KEY flow_token (flow_token),
        KEY reset_token (reset_token),
        KEY session_active_until (session_active_until),
        KEY auth_portal_sent_at (auth_portal_sent_at)
    ) {$charset_collate};";

    dbDelta($sql);
}

/**
 * Ensures seller state table exists during runtime (first-run/self-healing).
 * Keeps API usable even when plugin activation hook did not run for this DB.
 */
function cwsb_ensure_tables()
{
    static $checked = false;

    if ($checked) {
        return;
    }
    $checked = true;

    global $wpdb;
    $table_name = $wpdb->prefix . 'cwsb_seller_state';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
    if ($exists === $table_name) {
        return;
    }

    cwsb_create_tables();
}

/**
 * Applies lightweight schema upgrades for already-installed tables.
 */
function cwsb_upgrade_schema_if_needed()
{
    static $upgraded = false;

    if ($upgraded) {
        return;
    }
    $upgraded = true;

    global $wpdb;
    $table_name = $wpdb->prefix . 'cwsb_seller_state';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
    if ($exists !== $table_name) {
        return;
    }

    $column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$table_name} LIKE 'auth_portal_sent_at'");
    if (!$column_exists) {
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN auth_portal_sent_at BIGINT(20) NULL AFTER session_active_until");
    }

    $session_idx = $wpdb->get_var("SHOW INDEX FROM {$table_name} WHERE Key_name = 'session_active_until'");
    if (!$session_idx) {
        $wpdb->query("ALTER TABLE {$table_name} ADD KEY session_active_until (session_active_until)");
    }

    $portal_idx = $wpdb->get_var("SHOW INDEX FROM {$table_name} WHERE Key_name = 'auth_portal_sent_at'");
    if (!$portal_idx) {
        $wpdb->query("ALTER TABLE {$table_name} ADD KEY auth_portal_sent_at (auth_portal_sent_at)");
    }
}

// Load plugin classes grouped by role for easier maintenance.
$cwsb_class_files = [
    'includes/utilities/class-cwsb-response.php',
    'includes/utilities/class-cwsb-logger.php',
    'includes/utilities/class-cwsb-cache-backend.php',
    'includes/utilities/class-cwsb-cache-metrics.php',
    'includes/utilities/class-cwsb-cache.php',
    'includes/utilities/class-cwsb-utils.php',
    'includes/middleware/class-cwsb-auth-middleware.php',
    // Seller repositories
    'includes/repositories/seller/class-cwsb-seller-vendor-queries.php',
    'includes/repositories/seller/class-cwsb-seller-state-queries.php',
    'includes/repositories/seller/class-cwsb-seller-read-queries.php',
    'includes/repositories/seller/class-cwsb-seller-read-normalizer.php',
    'includes/repositories/seller/class-cwsb-seller-read-repository.php',
    'includes/repositories/seller/class-cwsb-seller-state-cache-invalidator.php',
    'includes/repositories/seller/class-cwsb-seller-state-writer.php',
    'includes/repositories/seller/class-cwsb-seller-state-repository.php',
    'includes/repositories/seller/class-cwsb-seller-repository.php',
    // Order repositories
    'includes/repositories/order/class-cwsb-order-queries.php',
    'includes/repositories/order/class-cwsb-order-mapper.php',
    'includes/repositories/order/class-cwsb-order-resolver.php',
    'includes/repositories/order/class-cwsb-order-repository.php',
    // Product repositories
    'includes/repositories/product/class-cwsb-product-queries.php',
    'includes/repositories/product/class-cwsb-product-mapper.php',
    'includes/repositories/product/class-cwsb-product-resolver.php',
    'includes/repositories/product/class-cwsb-product-repository.php',
    // Update-product repositories
    'includes/repositories/update-product/class-cwsb-update-product-queries.php',
    'includes/repositories/update-product/class-cwsb-update-product-writer.php',
    'includes/repositories/update-product/class-cwsb-update-product-repository.php',
    // Add-product services
    'includes/services/add-product/class-cwsb-add-product-support-service.php',
    'includes/services/add-product/class-cwsb-add-product-actions-service.php',
    // Auth services
    'includes/services/auth/class-cwsb-pin-service.php',
    'includes/services/auth/class-cwsb-auth-seller-core-service.php',
    'includes/services/auth/class-cwsb-auth-product-endpoints-service.php',
    'includes/services/auth/class-cwsb-auth-order-endpoints-service.php',
    'includes/services/auth/class-cwsb-auth-cache-endpoints-service.php',
    'includes/services/auth/class-cwsb-auth-seller-endpoints-service.php',
    // Update-product services
    'includes/services/update-product/class-cwsb-update-product-service.php',
    // Controllers
    'includes/controllers/auth/class-cwsb-auth-controller.php',
    'includes/controllers/add-product/class-cwsb-add-product-controller.php',
    'includes/controllers/update-product/class-cwsb-update-product-controller.php',
];

foreach ($cwsb_class_files as $cwsb_relative_path) {
    require_once CWSB_PLUGIN_DIR . $cwsb_relative_path;
}

// Ensure state table exists before first API call.
register_activation_hook(__FILE__, 'cwsb_create_tables');
add_action('plugins_loaded', 'cwsb_ensure_tables', 5);
add_action('plugins_loaded', 'cwsb_upgrade_schema_if_needed', 6);
add_action('rest_api_init', 'cwsb_ensure_tables', 1);
add_action('rest_api_init', 'cwsb_upgrade_schema_if_needed', 2);
// Register REST endpoints under whatsapp-bot/v1.
add_action('rest_api_init', ['CWSB_Auth_Controller', 'register_routes']);
add_action('rest_api_init', ['CWSB_Add_Product_Controller', 'register_routes']);
add_action('rest_api_init', ['CWSB_Update_Product_Controller', 'register_routes']);
