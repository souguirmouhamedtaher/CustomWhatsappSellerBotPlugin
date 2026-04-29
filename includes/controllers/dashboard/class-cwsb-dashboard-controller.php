<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Auth_Middleware')) {
    require_once __DIR__ . '/../../middleware/class-cwsb-auth-middleware.php';
}

if (!class_exists('CWSB_Dashboard_Seller_Service')) {
    require_once __DIR__ . '/../../services/dashboard/class-cwsb-dashboard-seller-service.php';
}

/**
 * Route registration for seller dashboard endpoints.
 */
class CWSB_Dashboard_Controller
{
    public static function register_routes()
    {
        register_rest_route(CWSB_NS, '/seller/dashboard/all', [
            'methods'             => 'GET',
            'callback'            => ['CWSB_Dashboard_Seller_Service', 'get_all_sellers_for_dashboard'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args'                => [
                'page'     => ['required' => false, 'default' => 1],
                'per_page' => ['required' => false, 'default' => 50],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/dashboard/active', [
            'methods'             => 'GET',
            'callback'            => ['CWSB_Dashboard_Seller_Service', 'get_active_sellers_for_dashboard'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args'                => [
                'page'     => ['required' => false, 'default' => 1],
                'per_page' => ['required' => false, 'default' => 50],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/dashboard/by-phone', [
            'methods'             => 'POST',
            'callback'            => ['CWSB_Dashboard_Seller_Service', 'get_dashboard_seller_by_phone'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args'                => [
                'phone' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/dashboard/by-email', [
            'methods'             => 'POST',
            'callback'            => ['CWSB_Dashboard_Seller_Service', 'get_dashboard_seller_by_email'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args'                => [
                'email' => ['required' => true],
            ],
        ]);
    }
}
