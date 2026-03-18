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
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY user_id (user_id),
        KEY email (email),
        KEY phone (phone),
        KEY flow_token (flow_token),
        KEY reset_token (reset_token)
    ) {$charset_collate};";

    dbDelta($sql);
}

// Load plugin classes grouped by role for easier maintenance.
$cwsb_class_files = [
    'includes/utilities/class-cwsb-response.php',
    'includes/utilities/class-cwsb-cache.php',
    'includes/utilities/class-cwsb-utils.php',
    'includes/middleware/class-cwsb-auth-middleware.php',
    'includes/repositories/class-cwsb-seller-repository.php',
    'includes/repositories/class-cwsb-order-repository.php',
    'includes/repositories/class-cwsb-product-repository.php',
    'includes/services/class-cwsb-pin-service.php',
    'includes/controllers/class-cwsb-auth-controller.php',
    'includes/controllers/class-cwsb-add-product-controller.php',
    'includes/controllers/class-cwsb-update-product-controller.php',
];

foreach ($cwsb_class_files as $cwsb_relative_path) {
    require_once CWSB_PLUGIN_DIR . $cwsb_relative_path;
}

// Ensure state table exists before first API call.
register_activation_hook(__FILE__, 'cwsb_create_tables');
// Register REST endpoints under whatsapp-bot/v1.
add_action('rest_api_init', ['CWSB_Auth_Controller', 'register_routes']);
add_action('rest_api_init', ['CWSB_Add_Product_Controller', 'register_routes']);
add_action('rest_api_init', ['CWSB_Update_Product_Controller', 'register_routes']);