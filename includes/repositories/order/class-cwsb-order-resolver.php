<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Seller_Repository')) {
    require_once __DIR__ . '/../seller/class-cwsb-seller-repository.php';
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

/**
 * Order resolver and ID lookup layer.
 *
 * Resolves seller user IDs from flow tokens and phone numbers.
 * Handles multiple fallback strategies for resilience.
 */
class CWSB_Order_Resolver
{
    /**
     * Resolve seller user ID from flow token.
     *
     * Strategy:
     * 1. Check cached flow token mapping
     * 2. Query seller state table directly via flow_token
     * 3. Extract phone from token, check state table by phone
     * 4. Fallback to seller repository (if available)
     */
    public static function resolve_seller_user_id_by_flow_token($flow_token)
    {
        $token = CWSB_Utils::normalize_text($flow_token);
        if ($token === '') {
            return 0;
        }

        $phone = CWSB_Utils::extract_phone_from_flow_token($token);
        global $wpdb;
        $state_table = CWSB_Seller_Repository::state_table_name();

        // Strategy 1: Direct flow token lookup
        $direct_user_id = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT user_id FROM {$state_table} WHERE flow_token = %s LIMIT 1", $token)
        );

        if ($direct_user_id > 0) {
            return $direct_user_id;
        }

        // Strategy 2: Phone-based lookup from state table
        if ($phone !== '') {
            $refs = CWSB_Utils::phone_comparison_refs($phone);
            $local = $refs['local'];
            $legacy = $refs['legacy'];
            $suffix = $refs['suffix'];
            $suffix_length = $refs['suffix_length'];

            $by_phone_user_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT user_id FROM {$state_table}
                     WHERE phone IN (%s, %s)
                        OR RIGHT(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), %d) = %s
                     ORDER BY id DESC
                     LIMIT 1",
                    $local,
                    $legacy,
                    $suffix_length,
                    $suffix
                )
            );

            if ($by_phone_user_id > 0) {
                return $by_phone_user_id;
            }
        }

        // Strategy 3: Fallback to seller repository (flow token)
        if (method_exists('CWSB_Seller_Repository', 'find_vendor_by_flow_token')) {
            $seller = CWSB_Seller_Repository::find_vendor_by_flow_token($token);
            if (is_array($seller) && isset($seller['user_id'])) {
                $uid = (int) $seller['user_id'];
                if ($uid > 0) {
                    return $uid;
                }
            }
        }

        // Strategy 4: Fallback to seller repository (phone)
        if ($phone !== '' && method_exists('CWSB_Seller_Repository', 'find_vendor_by_phone')) {
            $seller = CWSB_Seller_Repository::find_vendor_by_phone($phone);
            if (is_array($seller) && isset($seller['user_id'])) {
                $uid = (int) $seller['user_id'];
                if ($uid > 0) {
                    return $uid;
                }
            }
        }

        return 0;
    }

    /**
     * Find all product IDs owned by seller.
     */
    public static function find_seller_product_ids($seller_user_id, $limit)
    {
        global $wpdb;

        $seller_user_id = (int) $seller_user_id;
        $limit = max(1, (int) $limit);

        if ($seller_user_id <= 0) {
            return [];
        }

        $sql = "
            SELECT ID
            FROM {$wpdb->posts}
            WHERE post_type = 'product'
              AND post_status IN ('publish', 'private')
              AND post_author = %d
            ORDER BY ID DESC
            LIMIT %d
        ";

        $rows = $wpdb->get_col($wpdb->prepare($sql, $seller_user_id, $limit));
        return self::sanitize_int_ids($rows);
    }

    /**
     * Get empty order counters structure.
     */
    public static function empty_order_counters()
    {
        return [
            'total'       => 0,
            'completed'   => 0,
            'in_delivery' => 0,
            'pending'     => 0,
            'cancelled'   => 0,
            'refunded'    => 0,
            'anomaly'     => 0,
        ];
    }

    /**
     * Helper: Sanitize and unique-ify integer IDs.
     */
    private static function sanitize_int_ids($values)
    {
        if (!is_array($values)) {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $values)));
        return array_values(array_filter($ids, function ($v) {
            return $v > 0;
        }));
    }
}
