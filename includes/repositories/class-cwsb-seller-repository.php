<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Seller_Read_Repository')) {
    require_once __DIR__ . '/class-cwsb-seller-read-repository.php';
}

if (!class_exists('CWSB_Seller_State_Repository')) {
    require_once __DIR__ . '/class-cwsb-seller-state-repository.php';
}

/**
 * Backward-compatible facade for seller repository operations.
 */
class CWSB_Seller_Repository
{
    public static function state_table_name()
    {
        return CWSB_Seller_Read_Repository::state_table_name();
    }

    public static function find_vendor_by_phone($phone)
    {
        return CWSB_Seller_Read_Repository::find_vendor_by_phone($phone);
    }

    public static function find_vendor_by_email($email)
    {
        return CWSB_Seller_Read_Repository::find_vendor_by_email($email);
    }

    public static function save_seller_state($user_id, $state = [])
    {
        return CWSB_Seller_State_Repository::save_seller_state($user_id, $state);
    }

    public static function insert_seller_state_by_phone($phone, $state = [])
    {
        return CWSB_Seller_State_Repository::insert_seller_state_by_phone($phone, $state);
    }

    public static function find_vendor_by_user_id($user_id)
    {
        return CWSB_Seller_Read_Repository::find_vendor_by_user_id($user_id);
    }

    public static function find_vendor_by_flow_token($flow_token)
    {
        return CWSB_Seller_Read_Repository::find_vendor_by_flow_token($flow_token);
    }

    public static function find_state_seller_by_phone($phone)
    {
        return CWSB_Seller_Read_Repository::find_state_seller_by_phone($phone);
    }

    public static function update_seller_code_by_flow_token($flow_token, $code)
    {
        return CWSB_Seller_State_Repository::update_seller_code_by_flow_token($flow_token, $code);
    }

    public static function set_session_active_until_by_flow_token($flow_token, $session_active_until)
    {
        return CWSB_Seller_State_Repository::set_session_active_until_by_flow_token($flow_token, $session_active_until);
    }

    public static function set_reset_token_by_email($email, $reset_token, $reset_token_expiry)
    {
        return CWSB_Seller_State_Repository::set_reset_token_by_email($email, $reset_token, $reset_token_expiry);
    }

    public static function get_all_sellers($page = 1, $per_page = 50)
    {
        return CWSB_Seller_Read_Repository::get_all_sellers($page, $per_page);
    }

    public static function count_all_sellers()
    {
        return CWSB_Seller_Read_Repository::count_all_sellers();
    }
}
