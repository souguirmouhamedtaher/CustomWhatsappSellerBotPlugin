<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Seller_State_Writer')) {
    require_once __DIR__ . '/class-cwsb-seller-state-writer.php';
}

/**
 * Seller state repository facade.
 *
 * Public API remains stable while implementation lives in
 * CWSB_Seller_State_Writer.
 */
class CWSB_Seller_State_Repository
{
    public static function save_seller_state($user_id, $state = [])
    {
        return CWSB_Seller_State_Writer::save_seller_state($user_id, $state);
    }

    public static function insert_seller_state_by_phone($phone, $state = [])
    {
        return CWSB_Seller_State_Writer::insert_seller_state_by_phone($phone, $state);
    }

    public static function update_seller_code_by_flow_token($flow_token, $code)
    {
        return CWSB_Seller_State_Writer::update_seller_code_by_flow_token($flow_token, $code);
    }

    public static function set_session_active_until_by_flow_token($flow_token, $session_active_until)
    {
        return CWSB_Seller_State_Writer::set_session_active_until_by_flow_token($flow_token, $session_active_until);
    }

    public static function set_reset_token_by_email($email, $reset_token, $reset_token_expiry)
    {
        return CWSB_Seller_State_Writer::set_reset_token_by_email($email, $reset_token, $reset_token_expiry);
    }

    public static function mark_auth_portal_sent_by_phone($phone, $sent_at)
    {
        return CWSB_Seller_State_Writer::mark_auth_portal_sent_by_phone($phone, $sent_at);
    }
}