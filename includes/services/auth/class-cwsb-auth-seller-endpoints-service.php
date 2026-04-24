<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Auth_Seller_Core_Service')) {
    require_once __DIR__ . '/class-cwsb-auth-seller-core-service.php';
}

if (!class_exists('CWSB_Auth_Product_Endpoints_Service')) {
    require_once __DIR__ . '/class-cwsb-auth-product-endpoints-service.php';
}

if (!class_exists('CWSB_Auth_Order_Endpoints_Service')) {
    require_once __DIR__ . '/class-cwsb-auth-order-endpoints-service.php';
}

/**
 * Backward-compatible facade for auth endpoint handlers.
 */
class CWSB_Auth_Seller_Endpoints_Service
{
    public static function get_all_sellers(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::get_all_sellers($request);
    }

    public static function get_seller_by_phone(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::get_seller_by_phone($request);
    }

    public static function get_seller_state_by_phone(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::get_seller_state_by_phone($request);
    }

    public static function get_seller_by_flow_token(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::get_seller_by_flow_token($request);
    }

    public static function update_seller_code(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::update_seller_code($request);
    }

    public static function insert_seller_state(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::insert_seller_state($request);
    }

    public static function activate_seller_session(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::activate_seller_session($request);
    }

    public static function deactivate_seller_session(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::deactivate_seller_session($request);
    }

    public static function get_pre_expiry_auth_pending_sellers(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::get_pre_expiry_auth_pending_sellers($request);
    }

    public static function mark_auth_portal_sent(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::mark_auth_portal_sent($request);
    }

    public static function set_seller_reset_token(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::set_seller_reset_token($request);
    }

    public static function get_seller_products_by_flow_token(WP_REST_Request $request)
    {
        return CWSB_Auth_Product_Endpoints_Service::get_seller_products_by_flow_token($request);
    }

    public static function get_seller_product_by_id(WP_REST_Request $request)
    {
        return CWSB_Auth_Product_Endpoints_Service::get_seller_product_by_id($request);
    }

    public static function get_seller_product_variation_by_id(WP_REST_Request $request)
    {
        return CWSB_Auth_Product_Endpoints_Service::get_seller_product_variation_by_id($request);
    }

    public static function get_seller_orders_by_flow_token(WP_REST_Request $request)
    {
        return CWSB_Auth_Order_Endpoints_Service::get_seller_orders_by_flow_token($request);
    }

    public static function get_seller_order_counters_by_flow_token(WP_REST_Request $request)
    {
        return CWSB_Auth_Order_Endpoints_Service::get_seller_order_counters_by_flow_token($request);
    }

    public static function get_seller_order_list_by_flow_token(WP_REST_Request $request)
    {
        return CWSB_Auth_Order_Endpoints_Service::get_seller_order_list_by_flow_token($request);
    }

    public static function get_seller_order_by_id(WP_REST_Request $request)
    {
        return CWSB_Auth_Order_Endpoints_Service::get_seller_order_by_id($request);
    }

    public static function get_seller_order_articles_by_id(WP_REST_Request $request)
    {
        return CWSB_Auth_Order_Endpoints_Service::get_seller_order_articles_by_id($request);
    }

    public static function get_all_sellers_for_dashboard(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::get_all_sellers_for_dashboard($request);
    }
}
