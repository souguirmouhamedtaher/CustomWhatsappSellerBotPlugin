<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Cache')) {
    require_once __DIR__ . '/../utilities/class-cwsb-cache.php';
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../utilities/class-cwsb-utils.php';
}

/**
 * Read/query side of seller repository.
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
        return '%\"wcfm_vendor\"%';
    }

    public static function state_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'cwsb_seller_state';
    }

    public static function find_vendor_by_phone($phone)
    {
        global $wpdb;

        $normalized = CWSB_Utils::normalize_phone($phone);
        if ($normalized === '') {
            return null;
        }

        $cache_key = self::seller_phone_cache_key($normalized);
        $cache_hit = false;
        $cached = CWSB_Cache::get($cache_key, $cache_hit);
        if ($cache_hit) {
            return $cached;
        }

        $table = self::state_table_name();
        $user_id = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT user_id FROM {$table} WHERE phone = %s LIMIT 1", $normalized)
        );

        if ($user_id > 0) {
            $seller = self::find_vendor_by_user_id($user_id);
            CWSB_Cache::set($cache_key, $seller);
            return $seller;
        }

        $cap_key = $wpdb->prefix . 'capabilities';

        $sql_exact = "
            SELECT
                u.ID AS user_id,
                u.display_name AS name,
                u.user_email AS email,
                COALESCE(um_phone.meta_value, '') AS phone
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->usermeta} um_phone ON um_phone.user_id = u.ID
            INNER JOIN {$wpdb->usermeta} um_caps ON um_caps.user_id = u.ID
            WHERE um_phone.meta_key IN ('billing_phone', 'phone', 'wcfm_phone')
              AND um_phone.meta_value = %s
              AND um_caps.meta_key = %s
              AND um_caps.meta_value LIKE %s
            LIMIT 1
        ";

        $row = $wpdb->get_row(
            $wpdb->prepare($sql_exact, $normalized, $cap_key, self::vendor_capability_like()),
            ARRAY_A
        );

        if (!$row) {
            $sql_normalized = "
                SELECT
                    u.ID AS user_id,
                    u.display_name AS name,
                    u.user_email AS email,
                    COALESCE(um_phone.meta_value, '') AS phone
                FROM {$wpdb->users} u
                INNER JOIN {$wpdb->usermeta} um_phone ON um_phone.user_id = u.ID
                INNER JOIN {$wpdb->usermeta} um_caps ON um_caps.user_id = u.ID
                WHERE um_phone.meta_key IN ('billing_phone', 'phone', 'wcfm_phone')
                  AND REPLACE(REPLACE(REPLACE(um_phone.meta_value, '+', ''), ' ', ''), '-', '') = %s
                  AND um_caps.meta_key = %s
                  AND um_caps.meta_value LIKE %s
                LIMIT 1
            ";

            $row = $wpdb->get_row(
                $wpdb->prepare($sql_normalized, $normalized, $cap_key, self::vendor_capability_like()),
                ARRAY_A
            );
        }

        if (!$row) {
            CWSB_Cache::set($cache_key, null);
            return null;
        }

        $uid = (int) $row['user_id'];
        $row['phone'] = CWSB_Utils::normalize_phone(isset($row['phone']) ? $row['phone'] : '');
        $state = self::get_state_by_user_id($uid);
        $seller = self::normalize_seller_row(array_merge($row, $state));
        CWSB_Cache::set($cache_key, $seller);
        return $seller;
    }

    public static function find_vendor_by_email($email)
    {
        global $wpdb;

        $mail = CWSB_Utils::normalize_text($email);
        if ($mail === '') {
            return null;
        }

        $cap_key = $wpdb->prefix . 'capabilities';
        $sql = "
            SELECT
                u.ID AS user_id,
                u.display_name AS name,
                u.user_email AS email,
                COALESCE(billing_phone.meta_value, phone.meta_value, wcfm_phone.meta_value, '') AS phone
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->usermeta} caps
                ON caps.user_id = u.ID
               AND caps.meta_key = %s
            LEFT JOIN {$wpdb->usermeta} billing_phone
                ON billing_phone.user_id = u.ID
               AND billing_phone.meta_key = 'billing_phone'
            LEFT JOIN {$wpdb->usermeta} phone
                ON phone.user_id = u.ID
               AND phone.meta_key = 'phone'
            LEFT JOIN {$wpdb->usermeta} wcfm_phone
                ON wcfm_phone.user_id = u.ID
               AND wcfm_phone.meta_key = 'wcfm_phone'
            WHERE LOWER(u.user_email) = LOWER(%s)
              AND caps.meta_value LIKE %s
            LIMIT 1
        ";

        $row = $wpdb->get_row(
            $wpdb->prepare($sql, $cap_key, $mail, self::vendor_capability_like()),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        $row['phone'] = CWSB_Utils::normalize_phone(isset($row['phone']) ? $row['phone'] : '');
        $state = self::get_state_by_user_id((int) $row['user_id']);
        return self::normalize_seller_row(array_merge($row, $state));
    }

    public static function find_vendor_by_user_id($user_id)
    {
        global $wpdb;

        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return null;
        }

        $cache_key = self::seller_user_cache_key($user_id);
        $cache_hit = false;
        $cached = CWSB_Cache::get($cache_key, $cache_hit);
        if ($cache_hit) {
            return $cached;
        }

        $cap_key = $wpdb->prefix . 'capabilities';
        $sql = "
            SELECT
                u.ID AS user_id,
                u.display_name AS name,
                u.user_email AS email,
                COALESCE(billing_phone.meta_value, phone.meta_value, wcfm_phone.meta_value, '') AS phone
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->usermeta} caps
                ON caps.user_id = u.ID
               AND caps.meta_key = %s
            LEFT JOIN {$wpdb->usermeta} billing_phone
                ON billing_phone.user_id = u.ID
               AND billing_phone.meta_key = 'billing_phone'
            LEFT JOIN {$wpdb->usermeta} phone
                ON phone.user_id = u.ID
               AND phone.meta_key = 'phone'
            LEFT JOIN {$wpdb->usermeta} wcfm_phone
                ON wcfm_phone.user_id = u.ID
               AND wcfm_phone.meta_key = 'wcfm_phone'
            WHERE u.ID = %d
              AND caps.meta_value LIKE %s
            LIMIT 1
        ";

        $row = $wpdb->get_row(
            $wpdb->prepare($sql, $cap_key, $user_id, self::vendor_capability_like()),
            ARRAY_A
        );

        if (!$row) {
            CWSB_Cache::set($cache_key, null);
            return null;
        }

        $row['phone'] = CWSB_Utils::normalize_phone(isset($row['phone']) ? $row['phone'] : '');
        $state = self::get_state_by_user_id($user_id);
        $seller = self::normalize_seller_row(array_merge($row, $state));
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
        $normalized = CWSB_Utils::normalize_phone($phone);
        if ($normalized === '') {
            return null;
        }

        return CWSB_Cache::with_cache(
            'seller-state-by-phone',
            $normalized,
            function () use ($normalized) {
                global $wpdb;
                $table = self::state_table_name();
                $row = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT user_id, name, email, phone, code, flow_token, reset_token, reset_token_expiry, session_active_until FROM {$table} WHERE phone = %s LIMIT 1",
                        $normalized
                    ),
                    ARRAY_A
                );

                if (!is_array($row)) {
                    return null;
                }

                return self::normalize_seller_row($row);
            },
            30
        );
    }

    public static function get_all_sellers($page = 1, $per_page = 50)
    {
        global $wpdb;

        $page = max(1, (int) $page);
        $per_page = (int) $per_page;
        if ($per_page <= 0) {
            $per_page = 50;
        }
        $per_page = min($per_page, 200);
        $offset = ($page - 1) * $per_page;

        $cap_key = $wpdb->prefix . 'capabilities';

        $sql = "
            SELECT
                u.ID AS user_id,
                u.display_name AS name,
                u.user_email AS email,
                COALESCE(billing_phone.meta_value, phone.meta_value, wcfm_phone.meta_value, '') AS phone
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->usermeta} caps
                ON caps.user_id = u.ID
               AND caps.meta_key = %s
            LEFT JOIN {$wpdb->usermeta} billing_phone
                ON billing_phone.user_id = u.ID
               AND billing_phone.meta_key = 'billing_phone'
            LEFT JOIN {$wpdb->usermeta} phone
                ON phone.user_id = u.ID
               AND phone.meta_key = 'phone'
            LEFT JOIN {$wpdb->usermeta} wcfm_phone
                ON wcfm_phone.user_id = u.ID
               AND wcfm_phone.meta_key = 'wcfm_phone'
            WHERE caps.meta_value LIKE %s
            ORDER BY u.ID DESC
            LIMIT %d OFFSET %d
        ";

        $prepared_sql = $wpdb->prepare(
            $sql,
            $cap_key,
            self::vendor_capability_like(),
            $per_page,
            $offset
        );

        $rows = $wpdb->get_results($prepared_sql, ARRAY_A);
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
            $sellers[] = self::normalize_seller_row(array_merge($row, $state));
        }

        return $sellers;
    }

    public static function count_all_sellers()
    {
        global $wpdb;

        $cap_key = $wpdb->prefix . 'capabilities';
        $sql = "
            SELECT COUNT(1)
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->usermeta} caps
                ON caps.user_id = u.ID
               AND caps.meta_key = %s
            WHERE caps.meta_value LIKE %s
        ";

        return (int) $wpdb->get_var(
            $wpdb->prepare($sql, $cap_key, self::vendor_capability_like())
        );
    }

    public static function get_state_by_user_id($user_id)
    {
        global $wpdb;

        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return [];
        }

        $table = self::state_table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT name, email, phone, code, flow_token, reset_token, reset_token_expiry, session_active_until FROM {$table} WHERE user_id = %d LIMIT 1",
                $user_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : [];
    }

    public static function find_user_id_by_flow_token($flow_token)
    {
        global $wpdb;

        $token = CWSB_Utils::normalize_text($flow_token);
        if ($token === '') {
            return 0;
        }

        $table = self::state_table_name();
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT user_id FROM {$table} WHERE flow_token = %s LIMIT 1", $token)
        );
    }

    public static function get_state_map_by_user_ids($user_ids)
    {
        global $wpdb;

        if (!is_array($user_ids) || empty($user_ids)) {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $user_ids)));
        $ids = array_filter($ids, function ($v) {
            return $v > 0;
        });

        if (empty($ids)) {
            return [];
        }

        $table = self::state_table_name();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT user_id, name, email, phone, code, flow_token, reset_token, reset_token_expiry, session_active_until FROM {$table} WHERE user_id IN ({$placeholders})";
        $prepared = $wpdb->prepare($sql, ...$ids);
        $rows = $wpdb->get_results($prepared, ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $uid = isset($row['user_id']) ? (int) $row['user_id'] : 0;
            if ($uid > 0) {
                $map[$uid] = $row;
            }
        }

        return $map;
    }

    public static function normalize_seller_row($row)
    {
        return [
            'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : 0,
            'name' => isset($row['name']) ? (string) $row['name'] : '',
            'email' => isset($row['email']) ? (string) $row['email'] : '',
            'code' => isset($row['code']) ? ($row['code'] === null ? null : (string) $row['code']) : null,
            'phone' => isset($row['phone']) ? (string) $row['phone'] : '',
            'flow_token' => isset($row['flow_token']) ? ($row['flow_token'] === null ? null : (string) $row['flow_token']) : null,
            'reset_token' => isset($row['reset_token']) ? ($row['reset_token'] === null ? null : (string) $row['reset_token']) : null,
            'reset_token_expiry' => isset($row['reset_token_expiry']) && $row['reset_token_expiry'] !== null ? (int) $row['reset_token_expiry'] : null,
            'session_active_until' => isset($row['session_active_until']) && $row['session_active_until'] !== null ? (int) $row['session_active_until'] : null,
        ];
    }
}
