<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

if (!class_exists('CWSB_Seller_Repository')) {
    require_once __DIR__ . '/../seller/class-cwsb-seller-repository.php';
}

/**
 * Product seller resolution helpers.
 */
class CWSB_Product_Resolver
{
    public static function find_state_user_id_by_flow_token($flow_token)
    {
        global $wpdb;

        $token = CWSB_Utils::normalize_text($flow_token);
        if ($token === '') {
            return 0;
        }

        $table = CWSB_Seller_Repository::state_table_name();
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT user_id FROM {$table} WHERE flow_token = %s LIMIT 1", $token)
        );
    }

    public static function find_state_user_id_by_phone($phone)
    {
        global $wpdb;

        $refs = CWSB_Utils::phone_comparison_refs($phone);
        $normalized = $refs['canonical'];
        if ($normalized === '') {
            return 0;
        }

        $local8 = $refs['local8'];
        $legacy216 = $refs['legacy216'];

        $table = CWSB_Seller_Repository::state_table_name();
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT user_id FROM {$table}
                 WHERE phone IN (%s, %s)
                    OR RIGHT(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), 8) = %s
                 LIMIT 1",
                $local8,
                $legacy216,
                $local8
            )
        );
    }
}
