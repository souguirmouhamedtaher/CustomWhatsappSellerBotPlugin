<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Auth_Middleware')) {
    require_once __DIR__ . '/../middleware/class-cwsb-auth-middleware.php';
}

if (!class_exists('CWSB_Auth_Seller_Endpoints_Service')) {
    require_once __DIR__ . '/../services/class-cwsb-auth-seller-endpoints-service.php';
}

if (!class_exists('CWSB_Auth_Cache_Endpoints_Service')) {
    require_once __DIR__ . '/../services/class-cwsb-auth-cache-endpoints-service.php';
}

/**
 * Route registration controller for auth-flow endpoints.
 */
class CWSB_Auth_Controller
{
    public static function register_routes()
    {
        register_rest_route(CWSB_NS, '/seller/all', [
            'methods' => 'GET',
            'callback' => ['CWSB_Auth_Seller_Endpoints_Service', 'get_all_sellers'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'page' => ['required' => false, 'default' => 1],
                'per_page' => ['required' => false, 'default' => 50],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/by-phone', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Seller_Endpoints_Service', 'get_seller_by_phone'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => ['phone' => ['required' => true]],
        ]);

        register_rest_route(CWSB_NS, '/seller/state/by-phone', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Seller_Endpoints_Service', 'get_seller_state_by_phone'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => ['phone' => ['required' => true]],
        ]);

        register_rest_route(CWSB_NS, '/seller/by-flow-token', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Seller_Endpoints_Service', 'get_seller_by_flow_token'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => ['flow_token' => ['required' => true]],
        ]);

        register_rest_route(CWSB_NS, '/seller/update-code', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Seller_Endpoints_Service', 'update_seller_code'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
                'code' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/state/insert', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Seller_Endpoints_Service', 'insert_seller_state'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'phone' => ['required' => true],
                'flow_token' => ['required' => false],
                'code' => ['required' => false],
                'auth_portal_sent_at' => ['required' => false],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/session/activate', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Seller_Endpoints_Service', 'activate_seller_session'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
                'session_active_until' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/session/deactivate', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Seller_Endpoints_Service', 'deactivate_seller_session'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => ['flow_token' => ['required' => true]],
        ]);

        register_rest_route(CWSB_NS, '/seller/session/pre-expiry-auth-pending', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Seller_Endpoints_Service', 'get_pre_expiry_auth_pending_sellers'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'page' => ['required' => false, 'default' => 1],
                'limit' => ['required' => false, 'default' => 100],
                'lead_minutes' => ['required' => false, 'default' => 15],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/session/mark-auth-portal-sent', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Seller_Endpoints_Service', 'mark_auth_portal_sent'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'phone' => ['required' => true],
                'sent_at' => ['required' => false],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/reset-token/set', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Seller_Endpoints_Service', 'set_seller_reset_token'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'email' => ['required' => true],
                'reset_token' => ['required' => true],
                'reset_token_expiry' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/products/by-flow-token', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Seller_Endpoints_Service', 'get_seller_products_by_flow_token'],
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
            'callback' => ['CWSB_Auth_Seller_Endpoints_Service', 'get_seller_product_by_id'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => ['product_id' => ['required' => true]],
        ]);

        register_rest_route(CWSB_NS, '/seller/product/variation/by-id', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Seller_Endpoints_Service', 'get_seller_product_variation_by_id'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'product_id' => ['required' => true],
                'variation_id' => ['required' => true],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/orders/by-flow-token', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Seller_Endpoints_Service', 'get_seller_orders_by_flow_token'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => ['flow_token' => ['required' => true]],
        ]);

        register_rest_route(CWSB_NS, '/seller/orders/counters/by-flow-token', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Seller_Endpoints_Service', 'get_seller_order_counters_by_flow_token'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => ['flow_token' => ['required' => true]],
        ]);

        register_rest_route(CWSB_NS, '/seller/orders/list/by-flow-token', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Seller_Endpoints_Service', 'get_seller_order_list_by_flow_token'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => ['flow_token' => ['required' => true]],
        ]);

        register_rest_route(CWSB_NS, '/seller/order/by-id', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Seller_Endpoints_Service', 'get_seller_order_by_id'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => ['order_id' => ['required' => true]],
        ]);

        register_rest_route(CWSB_NS, '/seller/order/articles/by-id', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Seller_Endpoints_Service', 'get_seller_order_articles_by_id'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'order_id' => ['required' => true],
                'page' => ['required' => false],
                'limit' => ['required' => false],
            ],
        ]);

        register_rest_route(CWSB_NS, '/cache/auth/warmup/get', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Cache_Endpoints_Service', 'get_auth_warmup_cache'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => ['flow_token' => ['required' => true]],
        ]);

        register_rest_route(CWSB_NS, '/cache/auth/warmup/set', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Cache_Endpoints_Service', 'set_auth_warmup_cache'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
                'has_code' => ['required' => true],
                'prepared_at' => ['required' => false],
            ],
        ]);

        register_rest_route(CWSB_NS, '/cache/auth/pending/set', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Cache_Endpoints_Service', 'set_auth_pending_cache'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
                'code' => ['required' => true],
                'expires_at' => ['required' => false],
            ],
        ]);

        register_rest_route(CWSB_NS, '/cache/auth/pending/consume', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Cache_Endpoints_Service', 'consume_auth_pending_cache'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => ['flow_token' => ['required' => true]],
        ]);

        register_rest_route(CWSB_NS, '/cache/products/list/get', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Cache_Endpoints_Service', 'get_products_list_cache'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => ['flow_token' => ['required' => true]],
        ]);

        register_rest_route(CWSB_NS, '/cache/products/list/set', [
            'methods' => 'POST',
            'callback' => ['CWSB_Auth_Cache_Endpoints_Service', 'set_products_list_cache'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
                'products' => ['required' => true],
                'prepared_at' => ['required' => false],
            ],
        ]);
    }
}
