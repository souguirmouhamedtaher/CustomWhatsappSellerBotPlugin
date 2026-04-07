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

if (!class_exists('CWSB_Seller_Read_Queries')) {
    require_once __DIR__ . '/class-cwsb-seller-read-queries.php';
}

if (!class_exists('CWSB_Seller_Read_Normalizer')) {
    require_once __DIR__ . '/class-cwsb-seller-read-normalizer.php';
}

/**
 * Read/query side of seller repository.
 *
 * Facade over split query/normalization helpers.
 */
class CWSB_Seller_Read_Repository
{
    public static function seller_user_cache_key($user_id)
    {
        return 'seller:user:' . (int) $user_id;
    }

    public static function seller_phone_cache_key($phone)
    {
        return 'seller:phone:' . CWSB_Utils::normalize_phone($phone);
    }

    public static function seller_flow_cache_key($flow_token)
    {
        return 'seller:flow:' . CWSB_Utils::normalize_text($flow_token);
    }

    public static function vendor_capability_like()
    {
        return CWSB_Seller_Read_Queries::vendor_capability_like();
    }

    public static function state_table_name()
    {
        return CWSB_Seller_Read_Queries::state_table_name();
    }

    public static function find_vendor_by_phone($phone)
    {
        $refs = CWSB_Utils::phone_comparison_refs($phone);
        $normalized = $refs['canonical'];
        if ($normalized === '') {
            return null;
        }

        $cache_key = self::seller_phone_cache_key($normalized);
        $cache_hit = false;
        $cached = CWSB_Cache::get($cache_key, $cache_hit);
        if ($cache_hit) {
            return $cached;
        }

        $user_id = CWSB_Seller_Read_Queries::find_state_user_id_by_phone_refs($refs);
        if ($user_id > 0) {
            $seller = self::find_vendor_by_user_id($user_id);
            CWSB_Cache::set($cache_key, $seller);
            return $seller;
        }

        // Suspend WordPress object cache during large user/meta JOINs.
        wp_suspend_cache_addition(true);
        $row = CWSB_Seller_Read_Queries::find_vendor_row_by_phone_exact($refs);
        if (!$row) {
            $row = CWSB_Seller_Read_Queries::find_vendor_row_by_phone_normalized($refs);
        }

        if (!$row) {
            CWSB_Cache::set($cache_key, null);
            wp_suspend_cache_addition(false);
            return null;
        }

        $uid = (int) $row['user_id'];
        $row['phone'] = CWSB_Utils::normalize_phone(isset($row['phone']) ? $row['phone'] : '');
        $state = self::get_state_by_user_id($uid);
        $seller = CWSB_Seller_Read_Normalizer::normalize_seller_row(array_merge($row, $state));

        wp_suspend_cache_addition(false);
        CWSB_Cache::set($cache_key, $seller);

        return $seller;
    }

    public static function find_vendor_by_email($email)
    {
        $row = CWSB_Seller_Read_Queries::find_vendor_row_by_email($email);
        if (!$row) {
            return null;
        }

        $row['phone'] = CWSB_Utils::normalize_phone(isset($row['phone']) ? $row['phone'] : '');
        $state = self::get_state_by_user_id((int) $row['user_id']);
        return CWSB_Seller_Read_Normalizer::normalize_seller_row(array_merge($row, $state));
    }

    public static function find_vendor_by_user_id($user_id)
    {
        $uid = (int) $user_id;
        if ($uid <= 0) {
            return null;
        }

        $cache_key = self::seller_user_cache_key($uid);
        $cache_hit = false;
        $cached = CWSB_Cache::get($cache_key, $cache_hit);
        if ($cache_hit) {
            return $cached;
        }

        $row = CWSB_Seller_Read_Queries::find_vendor_row_by_user_id($uid);
        if (!$row) {
            CWSB_Cache::set($cache_key, null);
            return null;
        }

        $row['phone'] = CWSB_Utils::normalize_phone(isset($row['phone']) ? $row['phone'] : '');
        $state = self::get_state_by_user_id($uid);
        $seller = CWSB_Seller_Read_Normalizer::normalize_seller_row(array_merge($row, $state));
        CWSB_Cache::set($cache_key, $seller);
        return $seller;
    }

    public static function find_vendor_by_flow_token($flow_token)
    {
        $token = CWSB_Utils::normalize_text($flow_token);
        if ($token === '') {
            return null;
        }

        $cache_key = self::seller_flow_cache_key($token);
        $cache_hit = false;
        $cached = CWSB_Cache::get($cache_key, $cache_hit);
        if ($cache_hit) {
            return $cached;
        }

        $user_id = self::find_user_id_by_flow_token($token);
        if ($user_id <= 0) {
            CWSB_Cache::set($cache_key, null);
            return null;
        }

        $seller = self::find_vendor_by_user_id($user_id);
        CWSB_Cache::set($cache_key, $seller);
        return $seller;
    }

    public static function find_state_seller_by_phone($phone)
    {
        $refs = CWSB_Utils::phone_comparison_refs($phone);
        $normalized = $refs['canonical'];
        if ($normalized === '') {
            return null;
        }

        return CWSB_Cache::with_cache(
            'seller-state-by-phone',
            $normalized,
            function () use ($refs) {
                $row = CWSB_Seller_Read_Queries::find_state_seller_row_by_phone_refs($refs);
                if (!is_array($row)) {
                    return null;
                }
                return CWSB_Seller_Read_Normalizer::normalize_seller_row($row);
            },
            30
        );
    }

    public static function get_all_sellers($page = 1, $per_page = 50)
    {
        $per_page = (int) $per_page;
        if ($per_page <= 0) {
            $per_page = 50;
        }

        $rows = CWSB_Seller_Read_Queries::get_all_seller_rows((int) $page, $per_page);
        if (!is_array($rows)) {
            return [];
        }

        $user_ids = [];
        foreach ($rows as $row) {
            if (isset($row['user_id'])) {
                $user_ids[] = (int) $row['user_id'];
            }
        }

        $states = self::get_state_map_by_user_ids($user_ids);

        $sellers = [];
        foreach ($rows as $row) {
            $row['phone'] = CWSB_Utils::normalize_phone(isset($row['phone']) ? $row['phone'] : '');
            $uid = isset($row['user_id']) ? (int) $row['user_id'] : 0;
            $state = isset($states[$uid]) ? $states[$uid] : [];
            $sellers[] = CWSB_Seller_Read_Normalizer::normalize_seller_row(array_merge($row, $state));
        }

        return $sellers;
    }

    public static function count_all_sellers()
    {
        return CWSB_Seller_Read_Queries::count_all_sellers();
    }

    public static function get_state_by_user_id($user_id)
    {
        return CWSB_Seller_Read_Queries::get_state_by_user_id($user_id);
    }

    public static function find_user_id_by_flow_token($flow_token)
    {
        return CWSB_Seller_Read_Queries::find_user_id_by_flow_token($flow_token);
    }

    public static function get_state_map_by_user_ids($user_ids)
    {
        return CWSB_Seller_Read_Queries::get_state_map_by_user_ids($user_ids);
    }

    public static function normalize_seller_row($row)
    {
        return CWSB_Seller_Read_Normalizer::normalize_seller_row($row);
    }

    public static function get_pre_expiry_auth_pending_sellers($page = 1, $limit = 100, $lead_minutes = 15)
    {
        $rows = CWSB_Seller_Read_Queries::get_pre_expiry_auth_pending_sellers((int) $page, (int) $limit, (int) $lead_minutes);

        $sellers = [];
        foreach ($rows as $row) {
            $normalized = CWSB_Seller_Read_Normalizer::normalize_seller_row($row);
            $normalized['phone'] = CWSB_Utils::normalize_phone(isset($normalized['phone']) ? $normalized['phone'] : '');
            if ($normalized['phone'] === '') {
                continue;
            }
            $sellers[] = $normalized;
        }

        return $sellers;
    }
}
