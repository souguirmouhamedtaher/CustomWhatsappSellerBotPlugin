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
    /**
     * Registers add-product REST endpoints for categories, pricing conversion, and product creation.
     *
     * This method centralizes route definitions and delegates business logic to the actions service.
     * It relies on the WordPress native `register_rest_route()` API to declare method, callback,
     * permission callback, and argument requirements for each endpoint in the plugin namespace.
     */
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

        register_rest_route(CWSB_NS, '/seller/pricing/convert-xof', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'convert_xof_prices'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args'                => [
                'regular_xof' => ['required' => false],
                'promo_xof'   => ['required' => false],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/pricing/convert-eur-xos', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'convert_xof_prices'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args'                => [
                'regular_xof' => ['required' => false],
                'promo_xof'   => ['required' => false],
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

        register_rest_route(CWSB_NS, '/seller/product/create-xof/by-flow-token', [
            'methods' => 'POST',
            'callback' => [self::class, 'create_product_xof_by_flow_token'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
                'product' => ['required' => true],
                'idempotency_key' => ['required' => false],
            ],
        ]);
    }

    /**
     * Handles category-list requests and forwards the REST request to the add-product service layer.
     *
     * The method receives the WordPress native `WP_REST_Request` object and performs no direct data
     * access, ensuring controller responsibilities remain limited to routing and delegation.
     */
    public static function list_product_categories(WP_REST_Request $request)
    {
        return CWSB_Add_Product_Actions_Service::list_product_categories($request);
    }

    /**
     * Handles subcategory-list requests and forwards the REST request to the add-product service.
     *
     * The implementation keeps endpoint behavior consistent by passing the original `WP_REST_Request`
     * object unchanged to service logic where validation and response shaping are performed.
     */
    public static function list_product_subcategories(WP_REST_Request $request)
    {
        return CWSB_Add_Product_Actions_Service::list_product_subcategories($request);
    }

    /**
     * Handles TND-to-EUR pricing conversion requests through the add-product service.
     *
     * The controller does not compute prices directly; it delegates to service code that applies
     * configured conversion and rounding rules while returning a normalized REST response payload.
     */
    public static function convert_tnd_prices(WP_REST_Request $request)
    {
        return CWSB_Add_Product_Actions_Service::convert_tnd_prices($request);
    }

    /**
     * Handles XOF-to-EUR pricing conversion requests through the add-product service.
     *
     * The method receives a WordPress REST request and forwards it to currency logic that resolves
     * exchange settings and fallback behavior for XOF pricing flows.
     */
    public static function convert_xof_prices(WP_REST_Request $request)
    {
        return CWSB_Add_Product_Actions_Service::convert_xof_prices($request);
    }

    /**
     * Handles TND-based product creation requests identified by flow token.
     *
     * This method delegates to the service layer where payload validation, seller resolution,
     * WordPress post creation, and WooCommerce meta writes are executed.
     */
    public static function create_product_by_flow_token(WP_REST_Request $request)
    {
        return CWSB_Add_Product_Actions_Service::create_product_by_flow_token($request);
    }

    /**
     * Handles XOF-based product creation requests identified by flow token.
     *
     * The controller forwards the WordPress REST request as-is, allowing the service layer to
     * enforce XOF validation rules and persist XOF-specific pricing/meta mappings.
     */
    public static function create_product_xof_by_flow_token(WP_REST_Request $request)
    {
        return CWSB_Add_Product_Actions_Service::create_product_xof_by_flow_token($request);
    }
}

