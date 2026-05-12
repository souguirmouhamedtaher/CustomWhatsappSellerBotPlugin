<?php
/*
Plugin Name: Custom WhatsApp Seller Bot
Description: Seller lookup endpoints for WhatsApp bot.
Version: 1.0.16
Author: ILEYCOM-INTERNSHIPS
*/

if (!defined('ABSPATH')) {
    exit;
}

// REST namespace used by all plugin endpoints.
define('CWSB_NS', 'whatsapp-bot/v1');
// Absolute plugin directory path used for requiring class files.
define('CWSB_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Current plugin basename used by WordPress updates API.
define('CWSB_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Public GitHub repository used as update source.
if (!defined('CWSB_UPDATER_REPO_OWNER')) {
    define('CWSB_UPDATER_REPO_OWNER', 'souguirmouhamedtaher');
}
if (!defined('CWSB_UPDATER_REPO_NAME')) {
    define('CWSB_UPDATER_REPO_NAME', 'CustomWhatsappSellerBotPlugin');
}

// Load shared constants so cache flags and TTLs are consistently applied.
require_once CWSB_PLUGIN_DIR . 'config/constants.php';
require_once CWSB_PLUGIN_DIR . 'includes/utilities/class-cwsb-plugin-updater.php';

/**
 * Send no-cache headers for this plugin namespace during REST responses.
 */
function cwsb_send_rest_no_cache_headers($served, $result, $request, $server)
{
    $route = $request instanceof WP_REST_Request ? (string) $request->get_route() : '';
    if (strpos($route, '/' . CWSB_NS . '/') !== 0) {
        return $served;
    }

    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    if (!defined('DONOTCACHEDB')) {
        define('DONOTCACHEDB', true);
    }
    if (function_exists('nocache_headers')) {
        nocache_headers();
    }

    return $served;
}

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

    $reset_token_col = $wpdb->get_var("SHOW COLUMNS FROM {$table_name} LIKE 'reset_token'");
    if (!$reset_token_col) {
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN reset_token VARCHAR(255) NULL AFTER code");
    }

    $reset_token_expiry_col = $wpdb->get_var("SHOW COLUMNS FROM {$table_name} LIKE 'reset_token_expiry'");
    if (!$reset_token_expiry_col) {
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN reset_token_expiry BIGINT(20) NULL AFTER reset_token");
    }

    $reset_token_idx = $wpdb->get_var("SHOW INDEX FROM {$table_name} WHERE Key_name = 'reset_token'");
    if (!$reset_token_idx) {
        $wpdb->query("ALTER TABLE {$table_name} ADD KEY reset_token (reset_token)");
    }
}

/**
 * Lazy-load REST controllers and register plugin routes.
 *
 * Keeping this work inside rest_api_init avoids loading the full class graph
 * for non-REST requests (for example wp-admin pages), lowering memory usage.
 */
function cwsb_register_rest_routes()
{
    require_once CWSB_PLUGIN_DIR . 'includes/controllers/auth/class-cwsb-auth-controller.php';
    require_once CWSB_PLUGIN_DIR . 'includes/controllers/add-product/class-cwsb-add-product-controller.php';
    require_once CWSB_PLUGIN_DIR . 'includes/controllers/update-product/class-cwsb-update-product-controller.php';
    require_once CWSB_PLUGIN_DIR . 'includes/controllers/dashboard/class-cwsb-dashboard-controller.php';

    CWSB_Auth_Controller::register_routes();
    CWSB_Add_Product_Controller::register_routes();
    CWSB_Update_Product_Controller::register_routes();
    CWSB_Dashboard_Controller::register_routes();
}

/**
 * Boots GitHub-based plugin updates for in-place WordPress upgrades.
 */
function cwsb_bootstrap_plugin_updater()
{
    if (defined('WP_INSTALLING') && WP_INSTALLING) {
        return;
    }

    CWSB_Plugin_Updater::bootstrap(
        __FILE__,
        CWSB_UPDATER_REPO_OWNER,
        CWSB_UPDATER_REPO_NAME
    );
}

// Ensure state table exists before first API call.
register_activation_hook(__FILE__, 'cwsb_create_tables');
add_action('plugins_loaded', 'cwsb_ensure_tables', 5);
add_action('plugins_loaded', 'cwsb_upgrade_schema_if_needed', 6);
add_action('plugins_loaded', 'cwsb_bootstrap_plugin_updater', 7);
add_action('rest_api_init', 'cwsb_ensure_tables', 1);
add_action('rest_api_init', 'cwsb_upgrade_schema_if_needed', 2);
add_action('rest_api_init', 'cwsb_register_rest_routes', 10);
add_filter('rest_pre_serve_request', 'cwsb_send_rest_no_cache_headers', 10, 4);
