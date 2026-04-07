<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Cache')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-cache.php';
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

if (!class_exists('CWSB_Seller_Read_Repository')) {
    require_once __DIR__ . '/class-cwsb-seller-read-repository.php';
}

/**
 * Cache invalidation helpers for seller state mutations.
 */
class CWSB_Seller_State_Cache_Invalidator
{
    public static function invalidate_seller_read_caches($user_id, $phones = [], $flow_tokens = [])
    {
        $uid = (int) $user_id;
        if ($uid > 0) {
            CWSB_Cache::delete(CWSB_Seller_Read_Repository::seller_user_cache_key($uid));
        }

        if ($uid > 0) {
            $state = CWSB_Seller_Read_Repository::get_state_by_user_id($uid);
            if (isset($state['phone'])) {
                $phones[] = $state['phone'];
            }
            if (isset($state['flow_token'])) {
                $flow_tokens[] = $state['flow_token'];
            }
        }

        $normalized_phones = [];
        foreach ((array) $phones as $phone) {
            $normalized = CWSB_Utils::normalize_phone($phone);
            if ($normalized !== '') {
                $normalized_phones[] = $normalized;
            }
        }
        $normalized_phones = array_values(array_unique($normalized_phones));

        $normalized_flows = [];
        foreach ((array) $flow_tokens as $flow_token) {
            $token = CWSB_Utils::normalize_text($flow_token);
            if ($token !== '') {
                $normalized_flows[] = $token;
            }
        }
        $normalized_flows = array_values(array_unique($normalized_flows));

        foreach ($normalized_phones as $phone) {
            CWSB_Cache::delete(CWSB_Seller_Read_Repository::seller_phone_cache_key($phone));
        }

        foreach ($normalized_flows as $flow_token) {
            CWSB_Cache::delete(CWSB_Seller_Read_Repository::seller_flow_cache_key($flow_token));
        }

        if (class_exists('CWSB_Product_Repository') && method_exists('CWSB_Product_Repository', 'invalidate_cached_lists_for_seller_refs')) {
            CWSB_Product_Repository::invalidate_cached_lists_for_seller_refs($uid, $normalized_phones, $normalized_flows);
        }
    }
}
