<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Auth_Middleware')) {
    require_once __DIR__ . '/../../middleware/class-cwsb-auth-middleware.php';
}

if (!class_exists('CWSB_Admin_Service')) {
    require_once __DIR__ . '/../../services/admin/class-cwsb-admin-service.php';
}

/**
 * Route registration for admin power tools endpoints.
 */
class CWSB_Admin_Controller
{
    public static function register_routes()
    {
        register_rest_route(CWSB_NS, '/admin/sellers/search', [
            'methods'             => 'POST',
            'callback'            => ['CWSB_Admin_Service', 'search_sellers'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key_and_admin_actor'],
        ]);

        register_rest_route(CWSB_NS, '/admin/seller/profile', [
            'methods'             => 'POST',
            'callback'            => ['CWSB_Admin_Service', 'seller_profile'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key_and_admin_actor'],
            'args'                => [
                'phone' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/admin/seller/reset-pin', [
            'methods'             => 'POST',
            'callback'            => ['CWSB_Admin_Service', 'reset_pin'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key_and_admin_actor'],
            'args'                => [
                'phone' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/admin/seller/force-logout', [
            'methods'             => 'POST',
            'callback'            => ['CWSB_Admin_Service', 'force_logout'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key_and_admin_actor'],
            'args'                => [
                'phone' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/admin/seller/block', [
            'methods'             => 'POST',
            'callback'            => ['CWSB_Admin_Service', 'block_seller'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key_and_admin_actor'],
            'args'                => [
                'phone'  => ['required' => true],
                'reason' => ['required' => false],
            ],
        ]);

        register_rest_route(CWSB_NS, '/admin/seller/unblock', [
            'methods'             => 'POST',
            'callback'            => ['CWSB_Admin_Service', 'unblock_seller'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key_and_admin_actor'],
            'args'                => [
                'phone' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/admin/sellers/bulk', [
            'methods'             => 'POST',
            'callback'            => ['CWSB_Admin_Service', 'bulk_action'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key_and_admin_actor'],
            'args'                => [
                'action' => ['required' => true],
                'phones' => ['required' => true],
            ],
        ]);
    }
}
