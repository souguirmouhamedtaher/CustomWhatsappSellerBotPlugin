<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Response')) {
    require_once __DIR__ . '/../utilities/class-cwsb-response.php';
}

if (!class_exists('CWSB_Seller_Repository')) {
    require_once __DIR__ . '/../repositories/class-cwsb-seller-repository.php';
}

if (!class_exists('CWSB_Product_Repository')) {
    require_once __DIR__ . '/../repositories/class-cwsb-product-repository.php';
}

if (!class_exists('CWSB_Order_Repository')) {
    require_once __DIR__ . '/../repositories/class-cwsb-order-repository.php';
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../utilities/class-cwsb-utils.php';
}

/**
 * Seller/product/order endpoint handlers extracted from auth controller.
 */
class CWSB_Auth_Seller_Endpoints_Service
{
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

    public static function get_seller_by_phone(WP_REST_Request $request)
    {
        $phone = (string) $request->get_param('phone');
        $seller = CWSB_Seller_Repository::find_vendor_by_phone($phone);

        return CWSB_Response::ok([
            'seller' => $seller ?: null,
        ]);
    }

    public static function get_seller_state_by_phone(WP_REST_Request $request)
    {
        $phone = (string) $request->get_param('phone');
        $seller = CWSB_Seller_Repository::find_state_seller_by_phone($phone);

        return CWSB_Response::ok([
            'seller' => $seller ?: null,
        ]);
    }

    public static function get_seller_by_flow_token(WP_REST_Request $request)
    {
        $flow_token = (string) $request->get_param('flow_token');
        $seller = CWSB_Seller_Repository::find_vendor_by_flow_token($flow_token);

        return CWSB_Response::ok([
            'seller' => $seller ?: null,
        ]);
    }

    public static function update_seller_code(WP_REST_Request $request)
    {
        $flow_token = (string) $request->get_param('flow_token');
        $code = (string) $request->get_param('code');

        if (trim($flow_token) === '' || trim($code) === '') {
            return CWSB_Response::error('invalid_request', 'flow_token and code are required.', 422);
        }

        $seller = CWSB_Seller_Repository::update_seller_code_by_flow_token($flow_token, $code);

        return CWSB_Response::ok([
            'seller' => $seller ?: null,
        ]);
    }

    public static function insert_seller_state(WP_REST_Request $request)
    {
        $phone = (string) $request->get_param('phone');
        $flow_token = (string) $request->get_param('flow_token');
        $code_param = $request->get_param('code');

        if (trim($phone) === '') {
            return CWSB_Response::error('invalid_request', 'phone is required.', 422);
        }

        $state = [
            'flow_token' => $flow_token,
            'reset_token' => $request->get_param('reset_token'),
            'reset_token_expiry' => $request->get_param('reset_token_expiry'),
            'session_active_until' => $request->get_param('session_active_until'),
        ];

        if ($code_param !== null) {
            $state['code'] = (string) $code_param;
        }

        $seller = CWSB_Seller_Repository::insert_seller_state_by_phone($phone, $state);

        return CWSB_Response::ok([
            'seller' => $seller ?: null,
        ]);
    }

    public static function activate_seller_session(WP_REST_Request $request)
    {
        $flow_token = (string) $request->get_param('flow_token');
        $session_active_until = (int) $request->get_param('session_active_until');

        if (trim($flow_token) === '' || $session_active_until <= 0) {
            return CWSB_Response::error('invalid_request', 'flow_token and session_active_until are required.', 422);
        }

        $seller = CWSB_Seller_Repository::set_session_active_until_by_flow_token($flow_token, $session_active_until);
        return CWSB_Response::ok(['seller' => $seller ?: null]);
    }

    public static function deactivate_seller_session(WP_REST_Request $request)
    {
        $flow_token = (string) $request->get_param('flow_token');

        if (trim($flow_token) === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        $seller = CWSB_Seller_Repository::set_session_active_until_by_flow_token($flow_token, null);
        return CWSB_Response::ok(['seller' => $seller ?: null]);
    }

    public static function set_seller_reset_token(WP_REST_Request $request)
    {
        $email = (string) $request->get_param('email');
        $reset_token = (string) $request->get_param('reset_token');
        $reset_token_expiry = (int) $request->get_param('reset_token_expiry');

        if (trim($email) === '' || trim($reset_token) === '' || $reset_token_expiry <= 0) {
            return CWSB_Response::error('invalid_request', 'email, reset_token and reset_token_expiry are required.', 422);
        }

        $seller = CWSB_Seller_Repository::set_reset_token_by_email($email, $reset_token, $reset_token_expiry);
        return CWSB_Response::ok(['seller' => $seller ?: null]);
    }

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

        $products = trim($phone) !== ''
            ? CWSB_Product_Repository::find_products_by_seller_phone($phone)
            : CWSB_Product_Repository::find_products_by_seller_flow_token($flow_token);

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

    public static function get_seller_product_by_id(WP_REST_Request $request)
    {
        $product_id = (string) $request->get_param('product_id');

        if (trim($product_id) === '') {
            return CWSB_Response::error('invalid_request', 'product_id is required.', 422);
        }

        $product = CWSB_Product_Repository::find_product_by_id($product_id);
        return CWSB_Response::ok(['product' => $product ?: null]);
    }

    public static function get_seller_product_variation_by_id(WP_REST_Request $request)
    {
        $product_id = (string) $request->get_param('product_id');
        $variation_id = (string) $request->get_param('variation_id');

        if (trim($product_id) === '' || trim($variation_id) === '') {
            return CWSB_Response::error('invalid_request', 'product_id and variation_id are required.', 422);
        }

        $variation = CWSB_Product_Repository::find_variation_by_id($product_id, $variation_id);
        return CWSB_Response::ok(['variation' => $variation ?: null]);
    }

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

    public static function get_seller_order_by_id(WP_REST_Request $request)
    {
        $order_id = (string) $request->get_param('order_id');

        if (trim($order_id) === '') {
            return CWSB_Response::error('invalid_request', 'order_id is required.', 422);
        }

        $order = CWSB_Order_Repository::find_order_by_id($order_id);
        return CWSB_Response::ok(['order' => $order ?: null]);
    }

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
}
