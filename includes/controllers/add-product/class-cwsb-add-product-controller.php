<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Auth_Middleware')) {
    require_once __DIR__ . '/../../middleware/class-cwsb-auth-middleware.php';
}

if (!class_exists('CWSB_Add_Product_Actions_Service')) {
    require_once __DIR__ . '/../../services/add-product/class-cwsb-add-product-actions-service.php';
}

/**
 * Dedicated controller for add-product flow endpoints.
 * Keeps routing concerns separate from business logic.
 */
class CWSB_Add_Product_Controller
{
    public static function register_routes()
    {
        register_rest_route(CWSB_NS, '/seller/product/categories/list', [
            'methods' => 'POST',
            'callback' => [self::class, 'list_product_categories'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'include_empty' => ['required' => false],
                'parent_only' => ['required' => false],
                'limit' => ['required' => false],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/product/subcategories/list', [
            'methods' => 'POST',
            'callback' => [self::class, 'list_product_subcategories'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'category_id' => ['required' => true],
                'include_empty' => ['required' => false],
                'limit' => ['required' => false],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/pricing/convert', [
            'methods' => 'POST',
            'callback' => [self::class, 'convert_tnd_prices'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'regular_tnd' => ['required' => false],
                'promo_tnd' => ['required' => false],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/product/create/by-flow-token', [
            'methods' => 'POST',
            'callback' => [self::class, 'create_product_by_flow_token'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
                'product' => ['required' => true],
                'idempotency_key' => ['required' => false],
            ],
        ]);
    }

    public static function list_product_categories(WP_REST_Request $request)
    {
        return CWSB_Add_Product_Actions_Service::list_product_categories($request);
    }

    public static function list_product_subcategories(WP_REST_Request $request)
    {
        return CWSB_Add_Product_Actions_Service::list_product_subcategories($request);
    }

    public static function convert_tnd_prices(WP_REST_Request $request)
    {
        return CWSB_Add_Product_Actions_Service::convert_tnd_prices($request);
    }

    public static function create_product_by_flow_token(WP_REST_Request $request)
    {
        return CWSB_Add_Product_Actions_Service::create_product_by_flow_token($request);
    }
}

