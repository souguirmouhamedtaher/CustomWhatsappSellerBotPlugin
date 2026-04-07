<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

/**
 * Vendor-centric read queries.
 */
class CWSB_Seller_Vendor_Queries
{
    public static function vendor_capability_like()
    {
        return '%"wcfm_vendor"%';
    }

    public static function find_vendor_row_by_phone_exact($refs)
    {
        global $wpdb;

        $cap_key = $wpdb->prefix . 'capabilities';
        $sql = "
            SELECT
                u.ID AS user_id,
                u.display_name AS name,
                u.user_email AS email,
                COALESCE(um_phone.meta_value, '') AS phone
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->usermeta} um_phone ON um_phone.user_id = u.ID
            INNER JOIN {$wpdb->usermeta} um_caps ON um_caps.user_id = u.ID
            WHERE um_phone.meta_key IN ('billing_phone', 'phone', 'wcfm_phone')
              AND um_phone.meta_value IN (%s, %s, %s, %s)
              AND um_caps.meta_key = %s
              AND um_caps.meta_value LIKE %s
            LIMIT 1
        ";

        return $wpdb->get_row(
            $wpdb->prepare(
                $sql,
                $refs['local'],
                $refs['legacy'],
                $refs['intl00'],
                $refs['intl_plus'],
                $cap_key,
                self::vendor_capability_like()
            ),
            ARRAY_A
        );
    }

    public static function find_vendor_row_by_phone_normalized($refs)
    {
        global $wpdb;

        $cap_key = $wpdb->prefix . 'capabilities';
        $sql = "
            SELECT
                u.ID AS user_id,
                u.display_name AS name,
                u.user_email AS email,
                COALESCE(um_phone.meta_value, '') AS phone
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->usermeta} um_phone ON um_phone.user_id = u.ID
            INNER JOIN {$wpdb->usermeta} um_caps ON um_caps.user_id = u.ID
            WHERE um_phone.meta_key IN ('billing_phone', 'phone', 'wcfm_phone')
              AND (
                    REPLACE(REPLACE(REPLACE(um_phone.meta_value, '+', ''), ' ', ''), '-', '') IN (%s, %s, %s, %s)
                 OR RIGHT(REPLACE(REPLACE(REPLACE(um_phone.meta_value, '+', ''), ' ', ''), '-', ''), %d) = %s
              )
              AND um_caps.meta_key = %s
              AND um_caps.meta_value LIKE %s
            LIMIT 1
        ";

        return $wpdb->get_row(
            $wpdb->prepare(
                $sql,
                $refs['local'],
                $refs['legacy'],
                $refs['intl00'],
                $refs['canonical'],
                $refs['suffix_length'],
                $refs['suffix'],
                $cap_key,
                self::vendor_capability_like()
            ),
            ARRAY_A
        );
    }

    public static function find_vendor_row_by_email($email)
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

        return $wpdb->get_row(
            $wpdb->prepare($sql, $cap_key, $mail, self::vendor_capability_like()),
            ARRAY_A
        );
    }

    public static function find_vendor_row_by_user_id($user_id)
    {
        global $wpdb;

        $uid = (int) $user_id;
        if ($uid <= 0) {
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
            WHERE u.ID = %d
              AND caps.meta_value LIKE %s
            LIMIT 1
        ";

        return $wpdb->get_row(
            $wpdb->prepare($sql, $cap_key, $uid, self::vendor_capability_like()),
            ARRAY_A
        );
    }

    public static function get_all_seller_rows($page, $per_page)
    {
        global $wpdb;

        $page = max(1, (int) $page);
        $per_page = max(1, min((int) $per_page, 200));
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

        $prepared = $wpdb->prepare($sql, $cap_key, self::vendor_capability_like(), $per_page, $offset);
        $rows = $wpdb->get_results($prepared, ARRAY_A);
        return is_array($rows) ? $rows : [];
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

        return (int) $wpdb->get_var($wpdb->prepare($sql, $cap_key, self::vendor_capability_like()));
    }
}
