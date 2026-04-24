<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Seller_Vendor_Queries')) {
    require_once __DIR__ . '/class-cwsb-seller-vendor-queries.php';
}

if (!class_exists('CWSB_Seller_State_Queries')) {
    require_once __DIR__ . '/class-cwsb-seller-state-queries.php';
}

/**
 * Compatibility facade for seller read query helpers.
 */
class CWSB_Seller_Read_Queries
{
    public static function state_table_name()
    {
        return CWSB_Seller_State_Queries::state_table_name();
    }

    public static function vendor_capability_like()
    {
        return CWSB_Seller_Vendor_Queries::vendor_capability_like();
    }

    public static function find_state_user_id_by_phone_refs($refs)
    {
        return CWSB_Seller_State_Queries::find_state_user_id_by_phone_refs($refs);
    }

    public static function find_vendor_row_by_phone_exact($refs)
    {
        return CWSB_Seller_Vendor_Queries::find_vendor_row_by_phone_exact($refs);
    }

    public static function find_vendor_row_by_phone_normalized($refs)
    {
        return CWSB_Seller_Vendor_Queries::find_vendor_row_by_phone_normalized($refs);
    }

    public static function find_vendor_row_by_email($email)
    {
        return CWSB_Seller_Vendor_Queries::find_vendor_row_by_email($email);
    }

    public static function find_vendor_row_by_user_id($user_id)
    {
        return CWSB_Seller_Vendor_Queries::find_vendor_row_by_user_id($user_id);
    }

    public static function find_state_seller_row_by_phone_refs($refs)
    {
        return CWSB_Seller_State_Queries::find_state_seller_row_by_phone_refs($refs);
    }

    public static function get_all_seller_rows($page, $per_page)
    {
        return CWSB_Seller_Vendor_Queries::get_all_seller_rows($page, $per_page);
    }

    public static function count_all_sellers()
    {
        return CWSB_Seller_Vendor_Queries::count_all_sellers();
    }

    public static function get_state_by_user_id($user_id)
    {
        return CWSB_Seller_State_Queries::get_state_by_user_id($user_id);
    }

    public static function find_user_id_by_flow_token($flow_token)
    {
        return CWSB_Seller_State_Queries::find_user_id_by_flow_token($flow_token);
    }

    public static function get_state_map_by_user_ids($user_ids)
    {
        return CWSB_Seller_State_Queries::get_state_map_by_user_ids($user_ids);
    }

    public static function get_pre_expiry_auth_pending_sellers($page, $limit, $lead_minutes)
    {
        return CWSB_Seller_State_Queries::get_pre_expiry_auth_pending_sellers(
            $page,
            $limit,
            $lead_minutes,
            self::vendor_capability_like()
        );
    }

    public static function get_dashboard_seller_rows($page, $per_page)
    {
        return CWSB_Seller_Vendor_Queries::get_dashboard_seller_rows($page, $per_page);
    }
}
