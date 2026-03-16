<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Response')) {
    require_once __DIR__ . '/class-cwsb-response.php';
}

if (!class_exists('CWSB_Seller_Repository')) {
    require_once __DIR__ . '/class-cwsb-seller-repository.php';
}

if (!class_exists('CWSB_Auth_Middleware')) {
    require_once __DIR__ . '/class-cwsb-auth-middleware.php';
}

if (!class_exists('CWSB_Product_Repository')) {
    require_once __DIR__ . '/class-cwsb-product-repository.php';
}

if (!class_exists('CWSB_Order_Repository')) {
    require_once __DIR__ . '/class-cwsb-order-repository.php';
}

if (!class_exists('CWSB_Cache')) {
    require_once __DIR__ . '/class-cwsb-cache.php';
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/class-cwsb-utils.php';
}

/**
 * REST controller for seller auth-flow related endpoints.
 */
class CWSB_Auth_Controller
{
    /**
     * Registers all plugin routes under CWSB_NS.
     *
     * All routes are protected with API key middleware.
     */
    public static function register_routes()
    {
        register_rest_route(CWSB_NS, '/seller/all', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_all_sellers'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'page' => [
                    'required' => false,
                    'default' => 1,
                ],
                'per_page' => [
                    'required' => false,
                    'default' => 50,
                ],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/by-phone', [
            'methods' => 'POST',
            'callback' => [self::class, 'get_seller_by_phone'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'phone' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/state/by-phone', [
            'methods' => 'POST',
            'callback' => [self::class, 'get_seller_state_by_phone'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'phone' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/by-flow-token', [
            'methods' => 'POST',
            'callback' => [self::class, 'get_seller_by_flow_token'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/update-code', [
            'methods' => 'POST',
            'callback' => [self::class, 'update_seller_code'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
                'code' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/state/insert', [
            'methods' => 'POST',
            'callback' => [self::class, 'insert_seller_state'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'phone' => ['required' => true],
                'flow_token' => ['required' => false],
                'code' => ['required' => false],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/session/activate', [
            'methods' => 'POST',
            'callback' => [self::class, 'activate_seller_session'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
                'session_active_until' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/session/deactivate', [
            'methods' => 'POST',
            'callback' => [self::class, 'deactivate_seller_session'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/reset-token/set', [
            'methods' => 'POST',
            'callback' => [self::class, 'set_seller_reset_token'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'email' => ['required' => true],
                'reset_token' => ['required' => true],
                'reset_token_expiry' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/products/by-flow-token', [
            'methods' => 'POST',
            'callback' => [self::class, 'get_seller_products_by_flow_token'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => false],
                'phone' => ['required' => false],
                'page' => ['required' => false],
                'per_page' => ['required' => false],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/product/by-id', [
            'methods' => 'POST',
            'callback' => [self::class, 'get_seller_product_by_id'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'product_id' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/product/variation/by-id', [
            'methods' => 'POST',
            'callback' => [self::class, 'get_seller_product_variation_by_id'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'product_id' => ['required' => true],
                'variation_id' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/orders/by-flow-token', [
            'methods' => 'POST',
            'callback' => [self::class, 'get_seller_orders_by_flow_token'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/orders/counters/by-flow-token', [
            'methods' => 'POST',
            'callback' => [self::class, 'get_seller_order_counters_by_flow_token'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/orders/list/by-flow-token', [
            'methods' => 'POST',
            'callback' => [self::class, 'get_seller_order_list_by_flow_token'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/order/by-id', [
            'methods' => 'POST',
            'callback' => [self::class, 'get_seller_order_by_id'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'order_id' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/order/articles/by-id', [
            'methods' => 'POST',
            'callback' => [self::class, 'get_seller_order_articles_by_id'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'order_id' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/cache/auth/warmup/get', [
            'methods' => 'POST',
            'callback' => [self::class, 'get_auth_warmup_cache'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/cache/auth/warmup/set', [
            'methods' => 'POST',
            'callback' => [self::class, 'set_auth_warmup_cache'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
                'has_code' => ['required' => true],
                'prepared_at' => ['required' => false],
            ],
        ]);

        register_rest_route(CWSB_NS, '/cache/auth/pending/set', [
            'methods' => 'POST',
            'callback' => [self::class, 'set_auth_pending_cache'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
                'code' => ['required' => true],
                'expires_at' => ['required' => false],
            ],
        ]);

        register_rest_route(CWSB_NS, '/cache/auth/pending/consume', [
            'methods' => 'POST',
            'callback' => [self::class, 'consume_auth_pending_cache'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/cache/products/list/get', [
            'methods' => 'POST',
            'callback' => [self::class, 'get_products_list_cache'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/cache/products/list/set', [
            'methods' => 'POST',
            'callback' => [self::class, 'set_products_list_cache'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
                'products' => ['required' => true],
                'prepared_at' => ['required' => false],
            ],
        ]);
    }

    private static function auth_warmup_cache_key($flow_token)
    {
        return 'auth:warmup:' . CWSB_Utils::normalize_text($flow_token);
    }

    private static function auth_pending_cache_key($flow_token)
    {
        return 'auth:pending:' . CWSB_Utils::normalize_text($flow_token);
    }

    private static function product_list_cache_key($flow_token)
    {
        return 'products:list:' . CWSB_Utils::normalize_text($flow_token);
    }

    /**
     * GET /wp-json/whatsapp-bot/v1/seller/all
     */
    public static function get_all_sellers(WP_REST_Request $request)
    {
        $page = max(1, (int) $request->get_param('page'));
        $per_page = (int) $request->get_param('per_page');
        if ($per_page <= 0) {
            $per_page = 50;
        }
        $per_page = min($per_page, 200);

        $rows = CWSB_Seller_Repository::get_all_sellers($page, $per_page);
        $total = CWSB_Seller_Repository::count_all_sellers();

        return CWSB_Response::ok([
            'page' => $page,
            'per_page' => $per_page,
            'total' => (int) $total,
            'count' => is_array($rows) ? count($rows) : 0,
            'sellers' => is_array($rows) ? $rows : [],
        ]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/seller/by-phone
     * Body: { "phone": "216xxxxxxxx" }
     */
    public static function get_seller_by_phone(WP_REST_Request $request)
    {
        $phone = (string) $request->get_param('phone');
        // Resolve seller from user tables and merge with state row.
        $seller = CWSB_Seller_Repository::find_vendor_by_phone($phone);

        if (!$seller) {
            return CWSB_Response::ok([
                'seller' => null,
            ]);
        }

        return CWSB_Response::ok([
            'seller' => $seller,
        ]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/seller/state/by-phone
     * Body: { "phone": "216xxxxxxxx" }
     */
    public static function get_seller_state_by_phone(WP_REST_Request $request)
    {
        $phone = (string) $request->get_param('phone');
        // Resolve seller from state table only (fast path for WELCOME routing).
        $seller = CWSB_Seller_Repository::find_state_seller_by_phone($phone);

        if (!$seller) {
            return CWSB_Response::ok([
                'seller' => null,
            ]);
        }

        return CWSB_Response::ok([
            'seller' => $seller,
        ]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/seller/by-flow-token
     * Body: { "flow_token": "..." }
     */
    public static function get_seller_by_flow_token(WP_REST_Request $request)
    {
        $flow_token = (string) $request->get_param('flow_token');
        // Resolve seller by current flow token mapping in state table.
        $seller = CWSB_Seller_Repository::find_vendor_by_flow_token($flow_token);

        if (!$seller) {
            return CWSB_Response::ok([
                'seller' => null,
            ]);
        }

        return CWSB_Response::ok([
            'seller' => $seller,
        ]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/seller/update-code
     * Body: { "flow_token": "...", "code": "1234" }
     */
    public static function update_seller_code(WP_REST_Request $request)
    {
        $flow_token = (string) $request->get_param('flow_token');
        $code = (string) $request->get_param('code');

        if (trim($flow_token) === '' || trim($code) === '') {
            return CWSB_Response::error(
                'invalid_request',
                'flow_token and code are required.',
                422
            );
        }

    // Update seller code for the seller currently bound to this flow token.
        $seller = CWSB_Seller_Repository::update_seller_code_by_flow_token($flow_token, $code);

        if (!$seller) {
            return CWSB_Response::ok([
                'seller' => null,
            ]);
        }

        return CWSB_Response::ok([
            'seller' => $seller,
        ]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/seller/state/insert
     * Body: { "phone": "...", "flow_token": "..." }
     */
    public static function insert_seller_state(WP_REST_Request $request)
    {
        // Read phone from request body (required field for resolving seller).
        $phone = (string) $request->get_param('phone');
        // Read flow token from request body (used to map flow session to seller state).
        $flow_token = (string) $request->get_param('flow_token');
        // Read code as raw param so we can distinguish omitted vs provided null/empty.
        $code_param = $request->get_param('code');

        if (trim($phone) === '') {
            return CWSB_Response::error(
                'invalid_request',
                'phone is required.',
                422
            );
        }

        // Build partial state payload without forcing code by default.
        $state = [
            // Persist latest flow token for this seller.
            'flow_token' => $flow_token,
            // Optional reset-token value.
            'reset_token' => $request->get_param('reset_token'),
            // Optional reset-token expiry value.
            'reset_token_expiry' => $request->get_param('reset_token_expiry'),
            // Optional session expiration value.
            'session_active_until' => $request->get_param('session_active_until'),
        ];

        // Only include code when client explicitly sends it (prevents unintended code overwrite).
        if ($code_param !== null) {
            $state['code'] = (string) $code_param;
        }

        $seller = CWSB_Seller_Repository::insert_seller_state_by_phone($phone, $state);

        if (!$seller) {
            return CWSB_Response::ok([
                'seller' => null,
            ]);
        }

        return CWSB_Response::ok([
            'seller' => $seller,
        ]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/seller/session/activate
     */
    public static function activate_seller_session(WP_REST_Request $request)
    {
        $flow_token = (string) $request->get_param('flow_token');
        $session_active_until = (int) $request->get_param('session_active_until');

        if (trim($flow_token) === '' || $session_active_until <= 0) {
            return CWSB_Response::error('invalid_request', 'flow_token and session_active_until are required.', 422);
        }

        // Persist session expiry timestamp for this flow token owner.
        $seller = CWSB_Seller_Repository::set_session_active_until_by_flow_token($flow_token, $session_active_until);

        if (!$seller) {
            return CWSB_Response::ok(['seller' => null]);
        }

        return CWSB_Response::ok(['seller' => $seller]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/seller/session/deactivate
     */
    public static function deactivate_seller_session(WP_REST_Request $request)
    {
        $flow_token = (string) $request->get_param('flow_token');

        if (trim($flow_token) === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        // Clear session by writing NULL into session_active_until.
        $seller = CWSB_Seller_Repository::set_session_active_until_by_flow_token($flow_token, null);

        if (!$seller) {
            return CWSB_Response::ok(['seller' => null]);
        }

        return CWSB_Response::ok(['seller' => $seller]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/seller/reset-token/set
     */
    public static function set_seller_reset_token(WP_REST_Request $request)
    {
        $email = (string) $request->get_param('email');
        $reset_token = (string) $request->get_param('reset_token');
        $reset_token_expiry = (int) $request->get_param('reset_token_expiry');

        if (trim($email) === '' || trim($reset_token) === '' || $reset_token_expiry <= 0) {
            return CWSB_Response::error('invalid_request', 'email, reset_token and reset_token_expiry are required.', 422);
        }

        // Store password-reset token values for seller identified by email.
        $seller = CWSB_Seller_Repository::set_reset_token_by_email($email, $reset_token, $reset_token_expiry);

        if (!$seller) {
            return CWSB_Response::ok(['seller' => null]);
        }

        return CWSB_Response::ok(['seller' => $seller]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/seller/products/by-flow-token
     */
    public static function get_seller_products_by_flow_token(WP_REST_Request $request)
    {
        $flow_token = (string) $request->get_param('flow_token');
        $phone = (string) $request->get_param('phone');
        $page = max(1, (int) $request->get_param('page'));
        $per_page = (int) $request->get_param('per_page');
        if ($per_page <= 0) {
            $per_page = 5;
        }
        $per_page = min($per_page, 50);

        if (trim($flow_token) === '' && trim($phone) === '') {
            return CWSB_Response::error('invalid_request', 'phone or flow_token is required.', 422);
        }

        if (trim($phone) !== '') {
            $products = CWSB_Product_Repository::find_products_by_seller_phone($phone);
            $total = is_array($products) ? count($products) : 0;
            $offset = ($page - 1) * $per_page;
            $paged_products = is_array($products) ? array_slice($products, $offset, $per_page) : [];
            $has_more = ($offset + count($paged_products)) < $total;
            return CWSB_Response::ok([
                'count' => is_array($paged_products) ? count($paged_products) : 0,
                'total' => (int) $total,
                'page' => (int) $page,
                'per_page' => (int) $per_page,
                'has_more' => (bool) $has_more,
                'next_page' => $has_more ? (int) ($page + 1) : null,
                'products' => is_array($paged_products) ? $paged_products : [],
            ]);
        }

        $products = CWSB_Product_Repository::find_products_by_seller_flow_token($flow_token);
        $total = is_array($products) ? count($products) : 0;
        $offset = ($page - 1) * $per_page;
        $paged_products = is_array($products) ? array_slice($products, $offset, $per_page) : [];
        $has_more = ($offset + count($paged_products)) < $total;
        return CWSB_Response::ok([
            'count' => is_array($paged_products) ? count($paged_products) : 0,
            'total' => (int) $total,
            'page' => (int) $page,
            'per_page' => (int) $per_page,
            'has_more' => (bool) $has_more,
            'next_page' => $has_more ? (int) ($page + 1) : null,
            'products' => is_array($paged_products) ? $paged_products : [],
        ]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/seller/product/by-id
     */
    public static function get_seller_product_by_id(WP_REST_Request $request)
    {
        $product_id = (string) $request->get_param('product_id');

        if (trim($product_id) === '') {
            return CWSB_Response::error('invalid_request', 'product_id is required.', 422);
        }

        $product = CWSB_Product_Repository::find_product_by_id($product_id);

        return CWSB_Response::ok([
            'product' => $product ?: null,
        ]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/seller/product/variation/by-id
     */
    public static function get_seller_product_variation_by_id(WP_REST_Request $request)
    {
        $product_id = (string) $request->get_param('product_id');
        $variation_id = (string) $request->get_param('variation_id');

        if (trim($product_id) === '' || trim($variation_id) === '') {
            return CWSB_Response::error('invalid_request', 'product_id and variation_id are required.', 422);
        }

        $variation = CWSB_Product_Repository::find_variation_by_id($product_id, $variation_id);

        return CWSB_Response::ok([
            'variation' => $variation ?: null,
        ]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/seller/orders/by-flow-token
     */
    public static function get_seller_orders_by_flow_token(WP_REST_Request $request)
    {
        $flow_token = (string) $request->get_param('flow_token');

        if (trim($flow_token) === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        $orders = CWSB_Order_Repository::find_orders_by_seller_flow_token($flow_token);
        return CWSB_Response::ok([
            'count' => is_array($orders) ? count($orders) : 0,
            'orders' => is_array($orders) ? $orders : [],
        ]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/seller/orders/counters/by-flow-token
     */
    public static function get_seller_order_counters_by_flow_token(WP_REST_Request $request)
    {
        $flow_token = (string) $request->get_param('flow_token');

        if (trim($flow_token) === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        $counters = CWSB_Order_Repository::find_order_status_counters_by_flow_token($flow_token);
        return CWSB_Response::ok([
            'counters' => is_array($counters) ? $counters : [
                'total' => 0,
                'completed' => 0,
                'in_delivery' => 0,
                'to_deliver' => 0,
            ],
        ]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/seller/orders/list/by-flow-token
     */
    public static function get_seller_order_list_by_flow_token(WP_REST_Request $request)
    {
        $flow_token = (string) $request->get_param('flow_token');

        if (trim($flow_token) === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        $status_filter = CWSB_Utils::normalize_text($request->get_param('status_filter'));
        $page = (int) $request->get_param('page');
        $limit = (int) $request->get_param('limit');

        if ($page > 0 || $limit > 0 || $status_filter !== '') {
            $paged = CWSB_Order_Repository::find_order_summaries_page_by_seller_flow_token(
                $flow_token,
                $status_filter,
                $page > 0 ? $page : 1,
                $limit > 0 ? $limit : 10
            );

            return CWSB_Response::ok([
                'count' => isset($paged['count']) ? (int) $paged['count'] : 0,
                'page' => isset($paged['page']) ? (int) $paged['page'] : 1,
                'limit' => isset($paged['limit']) ? (int) $paged['limit'] : 10,
                'has_more' => !empty($paged['has_more']),
                'next_page' => isset($paged['next_page']) ? $paged['next_page'] : null,
                'status_filter' => isset($paged['status_filter']) ? (string) $paged['status_filter'] : 'all',
                'orders' => (isset($paged['orders']) && is_array($paged['orders'])) ? $paged['orders'] : [],
            ]);
        }

        $orders = CWSB_Order_Repository::find_order_summaries_by_seller_flow_token($flow_token);
        return CWSB_Response::ok([
            'count' => is_array($orders) ? count($orders) : 0,
            'orders' => is_array($orders) ? $orders : [],
        ]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/seller/order/by-id
     */
    public static function get_seller_order_by_id(WP_REST_Request $request)
    {
        $order_id = (string) $request->get_param('order_id');

        if (trim($order_id) === '') {
            return CWSB_Response::error('invalid_request', 'order_id is required.', 422);
        }

        $order = CWSB_Order_Repository::find_order_by_id($order_id);
        return CWSB_Response::ok([
            'order' => $order ?: null,
        ]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/seller/order/articles/by-id
     */
    public static function get_seller_order_articles_by_id(WP_REST_Request $request)
    {
        $order_id = (string) $request->get_param('order_id');

        if (trim($order_id) === '') {
            return CWSB_Response::error('invalid_request', 'order_id is required.', 422);
        }

        $articles = CWSB_Order_Repository::find_order_articles_by_order_id($order_id);
        return CWSB_Response::ok([
            'count' => is_array($articles) ? count($articles) : 0,
            'articles' => is_array($articles) ? $articles : [],
        ]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/cache/auth/warmup/get
     */
    public static function get_auth_warmup_cache(WP_REST_Request $request)
    {
        $flow_token = CWSB_Utils::normalize_text($request->get_param('flow_token'));
        if ($flow_token === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        $cache_key = self::auth_warmup_cache_key($flow_token);
        $found = false;
        $entry = CWSB_Cache::get($cache_key, $found);

        return CWSB_Response::ok([
            'entry' => $found ? $entry : null,
        ]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/cache/auth/warmup/set
     */
    public static function set_auth_warmup_cache(WP_REST_Request $request)
    {
        $flow_token = CWSB_Utils::normalize_text($request->get_param('flow_token'));
        if ($flow_token === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        $has_code = CWSB_Utils::to_bool($request->get_param('has_code'));
        $prepared_at = (int) $request->get_param('prepared_at');
        if ($prepared_at <= 0) {
            $prepared_at = CWSB_Utils::now_ms();
        }

        $entry = [
            'has_code' => $has_code,
            'prepared_at' => $prepared_at,
        ];

        CWSB_Cache::set(self::auth_warmup_cache_key($flow_token), $entry, 5 * 60);

        return CWSB_Response::ok([
            'entry' => $entry,
        ]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/cache/auth/pending/set
     */
    public static function set_auth_pending_cache(WP_REST_Request $request)
    {
        $flow_token = CWSB_Utils::normalize_text($request->get_param('flow_token'));
        $code = CWSB_Utils::normalize_text($request->get_param('code'));

        if ($flow_token === '' || $code === '') {
            return CWSB_Response::error('invalid_request', 'flow_token and code are required.', 422);
        }

        $expires_at = (int) $request->get_param('expires_at');
        if ($expires_at <= 0) {
            $expires_at = CWSB_Utils::now_ms() + (10 * 60 * 1000);
        }

        $entry = [
            'code' => $code,
            'expires_at' => $expires_at,
        ];

        CWSB_Cache::set(self::auth_pending_cache_key($flow_token), $entry, 10 * 60);

        return CWSB_Response::ok([
            'entry' => $entry,
        ]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/cache/auth/pending/consume
     */
    public static function consume_auth_pending_cache(WP_REST_Request $request)
    {
        $flow_token = CWSB_Utils::normalize_text($request->get_param('flow_token'));
        if ($flow_token === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        $cache_key = self::auth_pending_cache_key($flow_token);
        $found = false;
        $entry = CWSB_Cache::get($cache_key, $found);

        if ($found) {
            CWSB_Cache::delete($cache_key);
        }

        return CWSB_Response::ok([
            'entry' => $found ? $entry : null,
        ]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/cache/products/list/get
     */
    public static function get_products_list_cache(WP_REST_Request $request)
    {
        $flow_token = CWSB_Utils::normalize_text($request->get_param('flow_token'));
        if ($flow_token === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        $cache_key = self::product_list_cache_key($flow_token);
        $found = false;
        $entry = CWSB_Cache::get($cache_key, $found);

        return CWSB_Response::ok([
            'entry' => $found ? $entry : null,
        ]);
    }

    /**
     * POST /wp-json/whatsapp-bot/v1/cache/products/list/set
     */
    public static function set_products_list_cache(WP_REST_Request $request)
    {
        $flow_token = CWSB_Utils::normalize_text($request->get_param('flow_token'));
        if ($flow_token === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        $products = $request->get_param('products');
        if (!is_array($products)) {
            return CWSB_Response::error('invalid_request', 'products must be an array.', 422);
        }

        $prepared_at = (int) $request->get_param('prepared_at');
        if ($prepared_at <= 0) {
            $prepared_at = (int) round(microtime(true) * 1000);
        }

        $entry = [
            'products' => $products,
            'prepared_at' => $prepared_at,
        ];

        CWSB_Cache::set(self::product_list_cache_key($flow_token), $entry, 5 * 60);

        return CWSB_Response::ok([
            'entry' => $entry,
        ]);
    }
}