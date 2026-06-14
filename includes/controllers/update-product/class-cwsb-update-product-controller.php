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
    /**
     * Registers update-product REST endpoints for list, photo, edit-info, category-info, and updates.
     *
     * This method defines all route contracts for the update flow and relies on the WordPress native
     * `register_rest_route()` API to bind endpoint callbacks and request argument constraints.
     */
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

    /**
     * Returns a paginated product list for a seller resolved from flow token.
     *
     * The method reads pagination params from the incoming `WP_REST_Request`, performs basic guard
     * validation, then delegates to the service and converts the service envelope to a REST response.
     */
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

    /**
     * Returns current product images for the update photos screen.
     *
     * This endpoint validates flow token and product identity from the WordPress REST request before
     * delegating to service logic that loads thumbnail and gallery metadata.
     */
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

    /**
     * Returns editable product information for the TND-oriented edit-info flow.
     *
     * The method performs request-level validation only and delegates all seller ownership checks,
     * data retrieval, and mapping to the update-product service layer.
     */
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

    /**
     * Returns editable product information for the XOF-oriented edit-info flow.
     *
     * Like the TND variant, this endpoint accepts a `WP_REST_Request`, validates route parameters,
     * and delegates XOF-specific read behavior to service/repository code.
     */
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

    /**
     * Returns category and subcategory context for product category reassignment screens.
     *
     * The controller validates required identifiers and forwards handling to service code that reads
     * taxonomy/meta state for the seller-owned product.
     */
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

    /**
     * Applies a TND-oriented product update using nested data payload semantics.
     *
     * This endpoint expects a non-empty `data` object and forwards update intent to the service layer,
     * which enforces ownership, field persistence, and response mapping.
     */
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

    /**
     * Applies an XOF-oriented product update with parity support for nested and flat payload forms.
     *
     * The method first attempts TND-parity extraction from `data` in the WordPress REST request,
     * then falls back to flat field extraction for backward compatibility before delegating update
     * execution to XOF service logic.
     */
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

        // Parity with TND endpoint: accept nested data object first.
        $payload_data = $request->get_param('data');
        if (is_array($payload_data) && !empty($payload_data)) {
            $data = [];
            foreach ($allowed_fields as $field) {
                if (array_key_exists($field, $payload_data)) {
                    $data[$field] = $payload_data[$field];
                }
            }
        } else {
            // Backward compatibility: also accept flat XOF payload fields.
            $data = [];
            foreach ($allowed_fields as $field) {
                if ($request->has_param($field)) {
                    $data[$field] = $request->get_param($field);
                }
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

    /**
     * Converts internal service envelopes into plugin-standard REST responses.
     *
     * This helper centralizes success/error mapping and returns responses through the shared
     * response utility so all controller endpoints expose a consistent API contract.
     */
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
