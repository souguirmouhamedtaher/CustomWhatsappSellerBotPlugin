<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Response')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-response.php';
}

if (!class_exists('CWSB_Auth_Middleware')) {
    require_once __DIR__ . '/../../middleware/class-cwsb-auth-middleware.php';
}

if (!class_exists('CWSB_Update_Product_Service')) {
    require_once __DIR__ . '/../../services/update-product/class-cwsb-update-product-service.php';
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

/**
 * REST controller for update-product flow endpoints.
 *
 * Routes:
 *   POST /seller/product/list-paged/by-flow-token    { flow_token, page?, limit? }
 *   POST /seller/product/photos/by-flow-token         { flow_token, product_id }
 *   POST /seller/product/edit-info/by-flow-token      { flow_token, product_id }
 *   POST /seller/product/category-info/by-flow-token  { flow_token, product_id }
 *   POST /seller/product/update/by-flow-token         { flow_token, product_id, data }
 */
class CWSB_Update_Product_Controller
{
    public static function register_routes()
    {
        // EP1 — Paginated product list
        register_rest_route(CWSB_NS, '/seller/product/list-paged/by-flow-token', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'list_products_paged'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args'                => [
                'flow_token' => ['required' => true],
                'page'       => ['required' => false, 'default' => 1],
                'limit'      => ['required' => false, 'default' => 5],
            ],
        ]);

        // EP2 — Photos screen
        register_rest_route(CWSB_NS, '/seller/product/photos/by-flow-token', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'get_product_photos'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args'                => [
                'flow_token' => ['required' => true],
                'product_id' => ['required' => true],
            ],
        ]);

        // EP3 — Edit-info screen
        register_rest_route(CWSB_NS, '/seller/product/edit-info/by-flow-token', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'get_product_edit_info'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args'                => [
                'flow_token' => ['required' => true],
                'product_id' => ['required' => true],
            ],
        ]);

        // EP3b - Edit-info screen (XOF)
        register_rest_route(CWSB_NS, '/seller/product/edit-info-xof/by-flow-token', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'get_product_edit_info_xof'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args'                => [
                'flow_token' => ['required' => true],
                'product_id' => ['required' => true],
            ],
        ]);

        // EP4 — Category-info screen
        register_rest_route(CWSB_NS, '/seller/product/category-info/by-flow-token', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'get_product_category_info'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args'                => [
                'flow_token' => ['required' => true],
                'product_id' => ['required' => true],
            ],
        ]);

        // EP5 — Apply update
        register_rest_route(CWSB_NS, '/seller/product/update/by-flow-token', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'update_product'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args'                => [
                'flow_token' => ['required' => true],
                'product_id' => ['required' => true],
                'data'       => ['required' => true],
            ],
        ]);

        // EP5b - Apply update (XOF, flat payload)
        register_rest_route(CWSB_NS, '/seller/product/update-xof/by-flow-token', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'update_product_xof'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args'                => [
                'flow_token' => ['required' => true],
                'product_id' => ['required' => true],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    public static function list_products_paged(WP_REST_Request $request)
    {
        $flow_token = CWSB_Utils::normalize_text($request->get_param('flow_token'));
        $page       = max(1, (int) $request->get_param('page'));
        $limit      = max(1, min(5, (int) $request->get_param('limit')));

        if ($flow_token === '') {
            return CWSB_Response::error('invalid_params', 'flow_token is required.', 400);
        }

        $result = CWSB_Update_Product_Service::get_products_paged($flow_token, $page, $limit);
        return self::map_result($result);
    }

    public static function get_product_photos(WP_REST_Request $request)
    {
        $flow_token = CWSB_Utils::normalize_text($request->get_param('flow_token'));
        $product_id = (int) $request->get_param('product_id');

        if ($flow_token === '' || $product_id <= 0) {
            return CWSB_Response::error('invalid_params', 'flow_token and product_id are required.', 400);
        }

        $result = CWSB_Update_Product_Service::get_product_photos($flow_token, $product_id);
        return self::map_result($result);
    }

    public static function get_product_edit_info(WP_REST_Request $request)
    {
        $flow_token = CWSB_Utils::normalize_text($request->get_param('flow_token'));
        $product_id = (int) $request->get_param('product_id');

        if ($flow_token === '' || $product_id <= 0) {
            return CWSB_Response::error('invalid_params', 'flow_token and product_id are required.', 400);
        }

        $result = CWSB_Update_Product_Service::get_product_edit_info($flow_token, $product_id);
        return self::map_result($result);
    }

    public static function get_product_edit_info_xof(WP_REST_Request $request)
    {
        $flow_token = CWSB_Utils::normalize_text($request->get_param('flow_token'));
        $product_id = (int) $request->get_param('product_id');

        if ($flow_token === '' || $product_id <= 0) {
            return CWSB_Response::error('invalid_params', 'flow_token and product_id are required.', 400);
        }

        $result = CWSB_Update_Product_Service::get_product_edit_info_xof($flow_token, $product_id);
        return self::map_result($result);
    }

    public static function get_product_category_info(WP_REST_Request $request)
    {
        $flow_token = CWSB_Utils::normalize_text($request->get_param('flow_token'));
        $product_id = (int) $request->get_param('product_id');

        if ($flow_token === '' || $product_id <= 0) {
            return CWSB_Response::error('invalid_params', 'flow_token and product_id are required.', 400);
        }

        $result = CWSB_Update_Product_Service::get_product_category_info($flow_token, $product_id);
        return self::map_result($result);
    }

    public static function update_product(WP_REST_Request $request)
    {
        $flow_token = CWSB_Utils::normalize_text($request->get_param('flow_token'));
        $product_id = (int) $request->get_param('product_id');
        $data       = $request->get_param('data');

        if ($flow_token === '' || $product_id <= 0) {
            return CWSB_Response::error('invalid_params', 'flow_token and product_id are required.', 400);
        }

        if (!is_array($data) || empty($data)) {
            return CWSB_Response::error('invalid_params', 'data must be a non-empty object.', 400);
        }

        $result = CWSB_Update_Product_Service::update_product($flow_token, $product_id, $data);
        return self::map_result($result);
    }

    public static function update_product_xof(WP_REST_Request $request)
    {
        $flow_token = CWSB_Utils::normalize_text($request->get_param('flow_token'));
        $product_id = (int) $request->get_param('product_id');

        if ($flow_token === '' || $product_id <= 0) {
            return CWSB_Response::error('invalid_params', 'flow_token and product_id are required.', 400);
        }

        $allowed_fields = [
            'name',
            'regular_xof',
            'sale_xof',
            'regular_eur',
            'sale_eur',
            'stock',
            'length',
            'width',
            'height',
            'dim_unit',
            'weight',
            'weight_unit',
            'color',
            'size',
            'category_id',
            'category_label',
            'subcategory_id',
            'subcategory_label',
            'short_description',
            'full_description',
            'description',
            'post_status',
            'images',
        ];

        $data = [];
        foreach ($allowed_fields as $field) {
            if ($request->has_param($field)) {
                $data[$field] = $request->get_param($field);
            }
        }

        if (empty($data)) {
            return CWSB_Response::error('invalid_params', 'No update fields provided.', 400);
        }

        $result = CWSB_Update_Product_Service::update_product_xof($flow_token, $product_id, $data);
        return self::map_result($result);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function map_result($result)
    {
        if ($result['ok']) {
            return CWSB_Response::ok($result['data']);
        }

        $code   = isset($result['code'])    ? $result['code']    : 'error';
        $msg    = isset($result['message']) ? $result['message'] : 'An error occurred.';
        $status = ($code === 'seller_not_found' || $code === 'product_not_found') ? 404 : 500;

        return CWSB_Response::error($code, $msg, $status);
    }
}
