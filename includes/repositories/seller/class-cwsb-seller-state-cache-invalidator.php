<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

if (!class_exists('CWSB_Seller_Read_Repository')) {
    require_once __DIR__ . '/class-cwsb-seller-read-repository.php';
}

/**
 * Compatibility shim kept after cache layer removal.
 */
class CWSB_Seller_State_Cache_Invalidator
{
    public static function invalidate_seller_read_caches($user_id, $phones = [], $flow_tokens = [])
    {
        return;
    }
}
