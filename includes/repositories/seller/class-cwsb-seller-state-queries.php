<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

/**
 * Seller-state-centric read queries.
 */
class CWSB_Seller_State_Queries
{
    public static function state_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'cwsb_seller_state';
    }

    public static function find_state_user_id_by_phone_refs($refs)
    {
        global $wpdb;

        $table = self::state_table_name();
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT user_id FROM {$table}
                 WHERE phone IN (%s, %s, %s, %s)
                    OR REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', '') IN (%s, %s, %s, %s)
                    OR RIGHT(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), %d) = %s
                 LIMIT 1",
                $refs['local'],
                $refs['legacy'],
                $refs['intl00'],
                $refs['intl_plus'],
                $refs['local'],
                $refs['legacy'],
                $refs['intl00'],
                $refs['canonical'],
                $refs['suffix_length'],
                $refs['suffix']
            )
        );
    }

    public static function find_state_seller_row_by_phone_refs($refs)
    {
        global $wpdb;

        $table = self::state_table_name();
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT user_id, name, email, phone, code, flow_token, reset_token, reset_token_expiry, session_active_until, auth_portal_sent_at
                 FROM {$table}
                 WHERE phone IN (%s, %s, %s, %s)
                    OR REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', '') IN (%s, %s, %s, %s)
                    OR RIGHT(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), %d) = %s
                 LIMIT 1",
                $refs['local'],
                $refs['legacy'],
                $refs['intl00'],
                $refs['intl_plus'],
                $refs['local'],
                $refs['legacy'],
                $refs['intl00'],
                $refs['canonical'],
                $refs['suffix_length'],
                $refs['suffix']
            ),
            ARRAY_A
        );
    }

    public static function get_state_by_user_id($user_id)
    {
        global $wpdb;

        $uid = (int) $user_id;
        if ($uid <= 0) {
            return [];
        }

        $table = self::state_table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT name, email, phone, code, flow_token, reset_token, reset_token_expiry, session_active_until, auth_portal_sent_at FROM {$table} WHERE user_id = %d LIMIT 1",
                $uid
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
        $sql = "SELECT user_id, name, email, phone, code, flow_token, reset_token, reset_token_expiry, session_active_until, auth_portal_sent_at FROM {$table} WHERE user_id IN ({$placeholders})";
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

    public static function get_pre_expiry_auth_pending_sellers($page, $limit, $lead_minutes, $vendor_capability_like)
    {
        global $wpdb;

        $page = max(1, (int) $page);
        $limit = (int) $limit;
        if ($limit <= 0) {
            $limit = 100;
        }
        $limit = min($limit, 300);
        $offset = ($page - 1) * $limit;

        $lead_minutes = (int) $lead_minutes;
        if ($lead_minutes <= 0) {
            $lead_minutes = 15;
        }

        $now_ms = (int) round(microtime(true) * 1000);
        $lead_window_end_ms = $now_ms + ($lead_minutes * 60 * 1000);
        $lead_window_ms = $lead_minutes * 60 * 1000;

        $table = self::state_table_name();
        $cap_key = $wpdb->prefix . 'capabilities';

        $sql = "
            SELECT
                s.user_id,
                COALESCE(s.name, u.display_name, '') AS name,
                COALESCE(s.email, u.user_email, '') AS email,
                s.phone,
                s.code,
                s.flow_token,
                s.reset_token,
                s.reset_token_expiry,
                s.session_active_until,
                s.auth_portal_sent_at
            FROM {$table} s
            INNER JOIN {$wpdb->users} u
                ON u.ID = s.user_id
            INNER JOIN {$wpdb->usermeta} caps
                ON caps.user_id = u.ID
               AND caps.meta_key = %s
            WHERE caps.meta_value LIKE %s
              AND s.phone IS NOT NULL
              AND s.phone <> ''
              AND s.session_active_until IS NOT NULL
              AND s.session_active_until > 0
              AND s.session_active_until <= %d
              AND (
                    s.auth_portal_sent_at IS NULL
                  OR s.auth_portal_sent_at < (s.session_active_until - %d)
              )
            ORDER BY s.session_active_until ASC, s.user_id ASC
            LIMIT %d OFFSET %d
        ";

        $prepared = $wpdb->prepare(
            $sql,
            $cap_key,
            $vendor_capability_like,
            $lead_window_end_ms,
            $lead_window_ms,
            $limit,
            $offset
        );

        $rows = $wpdb->get_results($prepared, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }
}
