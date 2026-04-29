<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Response')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-response.php';
}

if (!class_exists('CWSB_Seller_Repository')) {
    require_once __DIR__ . '/../../repositories/seller/class-cwsb-seller-repository.php';
}

/**
 * Handles seller endpoint requests originating from the admin dashboard.
 */
class CWSB_Dashboard_Seller_Service
{
    private static function prevent_response_caching()
    {
        nocache_headers();
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!defined('DONOTCACHEDB')) {
            define('DONOTCACHEDB', true);
        }
    }

    public static function get_all_sellers_for_dashboard(WP_REST_Request $request)
    {
        self::prevent_response_caching();

        $page     = max(1, (int) $request->get_param('page'));
        $per_page = (int) $request->get_param('per_page');
        if ($per_page <= 0) {
            $per_page = 50;
        }
        $per_page = min($per_page, 200);

        $sellers  = CWSB_Seller_Repository::get_all_sellers_for_dashboard($page, $per_page);
        $total    = CWSB_Seller_Repository::count_all_sellers();
        $has_more = ($page * $per_page) < (int) $total;

        return CWSB_Response::ok([
            'page'      => $page,
            'per_page'  => $per_page,
            'total'     => (int) $total,
            'count'     => is_array($sellers) ? count($sellers) : 0,
            'has_more'  => $has_more,
            'next_page' => $has_more ? $page + 1 : null,
            'sellers'   => is_array($sellers) ? $sellers : [],
        ]);
    }

    public static function get_active_sellers_for_dashboard(WP_REST_Request $request)
    {
        self::prevent_response_caching();

        $page     = max(1, (int) $request->get_param('page'));
        $per_page = (int) $request->get_param('per_page');
        if ($per_page <= 0) {
            $per_page = 50;
        }
        $per_page = min($per_page, 200);

        $sellers  = CWSB_Seller_Repository::get_active_sellers_for_dashboard($page, $per_page);
        $total    = CWSB_Seller_Repository::count_active_sellers();
        $has_more = ($page * $per_page) < (int) $total;

        return CWSB_Response::ok([
            'page'      => $page,
            'per_page'  => $per_page,
            'total'     => (int) $total,
            'count'     => is_array($sellers) ? count($sellers) : 0,
            'has_more'  => $has_more,
            'next_page' => $has_more ? $page + 1 : null,
            'sellers'   => is_array($sellers) ? $sellers : [],
        ]);
    }

    public static function get_dashboard_seller_by_phone(WP_REST_Request $request)
    {
        self::prevent_response_caching();
        $phone = (string) $request->get_param('phone');

        if (trim($phone) === '') {
            return CWSB_Response::error('invalid_request', 'phone is required.', 422);
        }

        $seller = CWSB_Seller_Repository::find_dashboard_seller_by_phone($phone);
        return CWSB_Response::ok(['seller' => $seller ?: null]);
    }

    public static function get_dashboard_seller_by_email(WP_REST_Request $request)
    {
        self::prevent_response_caching();
        $email = (string) $request->get_param('email');

        if (trim($email) === '') {
            return CWSB_Response::error('invalid_request', 'email is required.', 422);
        }

        $seller = CWSB_Seller_Repository::find_dashboard_seller_by_email($email);
        return CWSB_Response::ok(['seller' => $seller ?: null]);
    }
}
