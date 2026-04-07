<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

/**
 * Order database query layer.
 *
 * All WooCommerce data fetching happens here.
 * Handles:
 * - Order row fetching
 * - Order item/article fetching
 * - Cache suspension for large queries
 */
class CWSB_Order_Queries
{
    const MAX_PRODUCT_IDS = 3000;

    /**
     * Find all order IDs for seller with optional limit.
     *
     * Prefers wc_order_product_lookup table (faster) with fallback to order_items.
     * Suspends WordPress auto-cache during query to prevent memory bloat.
     */
    public static function find_order_ids_for_seller($seller_user_id, $limit = null)
    {
        global $wpdb;

        $seller_user_id = (int) $seller_user_id;
        $safe_limit = null;
        if ($limit !== null) {
            $safe_limit = max(1, (int) $limit);
        }
        if ($seller_user_id <= 0) {
            return [];
        }

        // Need to resolve products first
        if (!class_exists('CWSB_Order_Resolver')) {
            require_once __DIR__ . '/class-cwsb-order-resolver.php';
        }
        $product_ids = CWSB_Order_Resolver::find_seller_product_ids($seller_user_id, self::MAX_PRODUCT_IDS);
        if (empty($product_ids)) {
            return [];
        }

        // Suspend WordPress object cache during large order ID lookups.
        wp_suspend_cache_addition(true);

        $lookup_table = $wpdb->prefix . 'wc_order_product_lookup';
        if (self::table_exists($lookup_table)) {
            $product_placeholders = self::build_placeholders(count($product_ids), '%d');
            $sql = "
                SELECT DISTINCT o.ID
                FROM {$lookup_table} opl
                INNER JOIN {$wpdb->posts} o
                    ON o.ID = opl.order_id
                WHERE opl.product_id IN ({$product_placeholders})
                  AND o.post_type = 'shop_order'
                  AND o.post_status NOT IN ('trash', 'auto-draft')
                ORDER BY o.post_date_gmt DESC, o.ID DESC
            ";

            if ($safe_limit !== null) {
                $sql .= "\n                LIMIT %d\n";
            }

            $params = array_map('intval', $product_ids);
            if ($safe_limit !== null) {
                $params[] = (int) $safe_limit;
            }
            $rows = $wpdb->get_col($wpdb->prepare($sql, ...$params));
            $ids = self::sanitize_int_ids($rows);
            if (!empty($ids)) {
                wp_suspend_cache_addition(false);
                return $ids;
            }
        }

        // Fallback for stores without wc_order_product_lookup.
        $order_items_table = $wpdb->prefix . 'woocommerce_order_items';
        $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

        $product_placeholders = self::build_placeholders(count($product_ids), '%d');
        $sql = "
            SELECT DISTINCT o.ID
            FROM {$wpdb->posts} o
            INNER JOIN {$order_items_table} oi
                ON oi.order_id = o.ID
               AND oi.order_item_type = 'line_item'
            INNER JOIN {$order_itemmeta_table} oim
                ON oim.order_item_id = oi.order_item_id
               AND oim.meta_key = '_product_id'
            WHERE o.post_type = 'shop_order'
              AND o.post_status NOT IN ('trash', 'auto-draft')
              AND CAST(oim.meta_value AS UNSIGNED) IN ({$product_placeholders})
            ORDER BY o.post_date_gmt DESC, o.ID DESC
        ";

        if ($safe_limit !== null) {
            $sql .= "\n            LIMIT %d\n";
        }

        $params = array_map('intval', $product_ids);
        if ($safe_limit !== null) {
            $params[] = (int) $safe_limit;
        }
        $rows = $wpdb->get_col($wpdb->prepare($sql, ...$params));
        $ids = self::sanitize_int_ids($rows);

        // Re-enable WordPress object cache after large result processing.
        wp_suspend_cache_addition(false);

        return $ids;
    }

    /**
     * Get order status aggregation for list of order IDs.
     *
     * Returns: [{ post_status, order_count }, ...]
     * Suspends cache during aggregation query.
     */
    public static function find_order_status_rows_by_order_ids($order_ids)
    {
        global $wpdb;

        $ids = self::sanitize_int_ids($order_ids);
        if (empty($ids)) {
            return [];
        }

        // Suspend WordPress object cache during status aggregation query.
        wp_suspend_cache_addition(true);

        $placeholders = self::build_placeholders(count($ids), '%d');
        $sql = "
            SELECT post_status, COUNT(*) AS order_count
            FROM {$wpdb->posts}
            WHERE ID IN ({$placeholders})
            GROUP BY post_status
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$ids), ARRAY_A);
        
        // Re-enable WordPress object cache.
        wp_suspend_cache_addition(false);
        
        return is_array($rows) ? $rows : [];
    }

    /**
     * Fetch complete order row by ID.
     *
     * Includes all order metadata (billing, shipping, payment, etc).
     */
    public static function find_order_row_by_id($order_id)
    {
        global $wpdb;

        $oid = (int) $order_id;
        if ($oid <= 0) {
            return null;
        }

        $sql = "
            SELECT
                p.ID,
                p.post_status,
                p.post_date,
                p.post_excerpt,
                MAX(CASE WHEN pm.meta_key = '_order_number' THEN pm.meta_value END) AS order_number,
                MAX(CASE WHEN pm.meta_key = '_customer_user' THEN pm.meta_value END) AS customer_user,
                MAX(CASE WHEN pm.meta_key = '_order_total' THEN pm.meta_value END) AS order_total,
                MAX(CASE WHEN pm.meta_key = '_order_currency' THEN pm.meta_value END) AS order_currency,
                MAX(CASE WHEN pm.meta_key = '_payment_method_title' THEN pm.meta_value END) AS payment_method_title,
                MAX(CASE WHEN pm.meta_key = '_transaction_id' THEN pm.meta_value END) AS transaction_id,
                MAX(CASE WHEN pm.meta_key = '_customer_note' THEN pm.meta_value END) AS customer_note,
                MAX(CASE WHEN pm.meta_key = '_order_shipping' THEN pm.meta_value END) AS order_shipping,
                MAX(CASE WHEN pm.meta_key = '_shipping_total' THEN pm.meta_value END) AS shipping_total,
                MAX(CASE WHEN pm.meta_key = '_billing_first_name' THEN pm.meta_value END) AS billing_first_name,
                MAX(CASE WHEN pm.meta_key = '_billing_last_name' THEN pm.meta_value END) AS billing_last_name,
                MAX(CASE WHEN pm.meta_key = '_billing_address_1' THEN pm.meta_value END) AS billing_address_1,
                MAX(CASE WHEN pm.meta_key = '_billing_address_2' THEN pm.meta_value END) AS billing_address_2,
                MAX(CASE WHEN pm.meta_key = '_billing_city' THEN pm.meta_value END) AS billing_city,
                MAX(CASE WHEN pm.meta_key = '_billing_state' THEN pm.meta_value END) AS billing_state,
                MAX(CASE WHEN pm.meta_key = '_billing_postcode' THEN pm.meta_value END) AS billing_postcode,
                MAX(CASE WHEN pm.meta_key = '_billing_country' THEN pm.meta_value END) AS billing_country,
                MAX(CASE WHEN pm.meta_key = '_shipping_first_name' THEN pm.meta_value END) AS shipping_first_name,
                MAX(CASE WHEN pm.meta_key = '_shipping_last_name' THEN pm.meta_value END) AS shipping_last_name,
                MAX(CASE WHEN pm.meta_key = '_shipping_address_1' THEN pm.meta_value END) AS shipping_address_1,
                MAX(CASE WHEN pm.meta_key = '_shipping_address_2' THEN pm.meta_value END) AS shipping_address_2,
                MAX(CASE WHEN pm.meta_key = '_shipping_city' THEN pm.meta_value END) AS shipping_city,
                MAX(CASE WHEN pm.meta_key = '_shipping_state' THEN pm.meta_value END) AS shipping_state,
                MAX(CASE WHEN pm.meta_key = '_shipping_postcode' THEN pm.meta_value END) AS shipping_postcode,
                MAX(CASE WHEN pm.meta_key = '_shipping_country' THEN pm.meta_value END) AS shipping_country
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE p.ID = %d
              AND p.post_type = 'shop_order'
              AND p.post_status NOT IN ('trash', 'auto-draft')
            GROUP BY p.ID, p.post_status, p.post_date, p.post_excerpt
            LIMIT 1
        ";

        $row = $wpdb->get_row($wpdb->prepare($sql, $oid), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Helper: Check if table exists.
     */
    public static function table_exists($table_name)
    {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        return $exists === $table_name;
    }

    /**
     * Helper: Build SQL placeholders.
     */
    public static function build_placeholders($count, $placeholder)
    {
        $count = max(1, (int) $count);
        return implode(',', array_fill(0, $count, $placeholder));
    }

    /**
     * Helper: Sanitize and unique-ify integer IDs.
     */
    public static function sanitize_int_ids($values)
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
