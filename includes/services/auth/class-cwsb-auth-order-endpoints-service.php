<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Response')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-response.php';
}

if (!class_exists('CWSB_Order_Repository')) {
    require_once __DIR__ . '/../../repositories/order/class-cwsb-order-repository.php';
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

/**
 * Order-related endpoint handlers.
 */
class CWSB_Auth_Order_Endpoints_Service
{
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
                $limit > 0 ? $limit : 5
            );

            return CWSB_Response::ok([
                'count' => isset($paged['count']) ? (int) $paged['count'] : 0,
                'page' => isset($paged['page']) ? (int) $paged['page'] : 1,
                'limit' => isset($paged['limit']) ? (int) $paged['limit'] : 5,
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
        $flow_token = (string) $request->get_param('flow_token');
        $order_id = (string) $request->get_param('order_id');

        if (trim($flow_token) === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        if (trim($order_id) === '') {
            return CWSB_Response::error('invalid_request', 'order_id is required.', 422);
        }

        $order = CWSB_Order_Repository::find_order_by_id_for_seller_flow_token($flow_token, $order_id);
        return CWSB_Response::ok(['order' => $order ?: null]);
    }

    public static function get_seller_order_articles_by_id(WP_REST_Request $request)
    {
        $flow_token = (string) $request->get_param('flow_token');
        $order_id = (string) $request->get_param('order_id');
        $page = max(1, (int) $request->get_param('page'));
        $limit = (int) $request->get_param('limit');
        if ($limit <= 0) {
            $limit = 3;
        }
        $limit = min($limit, 3);

        if (trim($flow_token) === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        if (trim($order_id) === '') {
            return CWSB_Response::error('invalid_request', 'order_id is required.', 422);
        }

        $paged = CWSB_Order_Repository::find_order_articles_page_by_order_id_for_seller_flow_token(
            $flow_token,
            $order_id,
            $page,
            $limit
        );

        return CWSB_Response::ok([
            'count' => isset($paged['count']) ? (int) $paged['count'] : 0,
            'total' => isset($paged['total']) ? (int) $paged['total'] : 0,
            'page' => isset($paged['page']) ? (int) $paged['page'] : $page,
            'limit' => isset($paged['limit']) ? (int) $paged['limit'] : $limit,
            'has_more' => !empty($paged['has_more']),
            'next_page' => isset($paged['next_page']) ? $paged['next_page'] : null,
            'articles' => (isset($paged['articles']) && is_array($paged['articles'])) ? $paged['articles'] : [],
        ]);
    }
}
