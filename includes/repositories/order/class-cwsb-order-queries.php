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
    /**
     * Find all order IDs for seller with optional limit.
     *
    * Uses WCFM marketplace order mapping by vendor_id.
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

        $marketplace_orders_table = $wpdb->prefix . 'wcfm_marketplace_orders';
        if (!self::table_exists($marketplace_orders_table)) {
            return [];
        }

        $sql = "
            SELECT DISTINCT o.ID
            FROM {$marketplace_orders_table} mo
            INNER JOIN {$wpdb->posts} o
                ON o.ID = mo.order_id
            WHERE mo.vendor_id = %d
              AND o.post_type = 'shop_order'
              AND o.post_status NOT IN ('trash', 'auto-draft')
            ORDER BY o.post_date_gmt DESC, o.ID DESC
        ";

        if ($safe_limit !== null) {
            $sql .= "\n            LIMIT %d\n";
        }

        $params = [(int) $seller_user_id];
        if ($safe_limit !== null) {
            $params[] = (int) $safe_limit;
        }
        $rows = $wpdb->get_col($wpdb->prepare($sql, ...$params));
        $ids = self::sanitize_int_ids($rows);

        return $ids;
    }

    /**
     * Find order IDs for seller filtered by status with pagination.
     *
     * Returns up to (limit + 1) rows to enable has_more detection.
     * Status filtering happens in SQL WHERE clause, not in PHP.
     * Uses WCFM marketplace order mapping by vendor_id.
     */
    public static function find_order_ids_for_seller_by_status($seller_user_id, $status_filter = 'all', $limit = null, $offset = 0)
    {
        global $wpdb;

        $seller_user_id = (int) $seller_user_id;
        $safe_limit = null;
        if ($limit !== null) {
            $safe_limit = max(1, (int) $limit);
        }
        $safe_offset = max(0, (int) $offset);

        if ($seller_user_id <= 0) {
            return [];
        }

        $marketplace_orders_table = $wpdb->prefix . 'wcfm_marketplace_orders';
        if (!self::table_exists($marketplace_orders_table)) {
            return [];
        }

        $status_clauses = self::build_status_where_clause($status_filter);
        if ($status_clauses === '') {
            $status_clauses = 'AND ( 1=1 )'; // Match all statuses
        }

        $sql = "
            SELECT DISTINCT o.ID
            FROM {$marketplace_orders_table} mo
            INNER JOIN {$wpdb->posts} o
                ON o.ID = mo.order_id
            WHERE mo.vendor_id = %d
              AND o.post_type = 'shop_order'
              AND o.post_status NOT IN ('trash', 'auto-draft')
              {$status_clauses}
            ORDER BY o.post_date_gmt DESC, o.ID DESC
        ";

        $params = [(int) $seller_user_id];

        if ($safe_limit !== null) {
            $sql .= "\n            LIMIT %d OFFSET %d\n";
            $params[] = $safe_limit;
            $params[] = $safe_offset;
        }

        $rows = $wpdb->get_col($wpdb->prepare($sql, ...$params));
        $ids = self::sanitize_int_ids($rows);

        return $ids;
    }

    /**
     * Build SQL WHERE clause for status filter.
     *
     * Converts semantic status filter to WordPress post_status values.
     * Returns empty string for 'all' (no additional WHERE clause needed).
     */
    public static function build_status_where_clause($status_filter)
    {
        $filter = strtolower(trim((string) $status_filter));

        if ($filter === 'completed') {
            return "AND o.post_status IN ('wc-completed','wc-commande-livree')";
        }

        if ($filter === 'in_delivery' || $filter === 'in-delivery') {
            return "AND o.post_status IN ('wc-en-cours-de-livra')";
        }

        if ($filter === 'pending') {
            return "AND o.post_status IN ('wc-processing','wc-on-hold','wc-a-livrer','wc-vendeur-va-fabriq','wc-a-creer-le-bordea','wc-paye-par-mobile-m')";
        }

        if ($filter === 'cancelled') {
            return "AND o.post_status IN ('wc-cancelled','wc-colis-refuse','wc-colis-refuse-clot')";
        }

        if ($filter === 'refunded') {
            return "AND o.post_status IN ('wc-refunded')";
        }

        if ($filter === 'anomaly') {
            return "AND o.post_status IN ('wc-anomalie-de-pick','wc-anomalie-de-livra','wc-vendeur-injoignab','wc-acheteur-injoigna','wc-vendeur-nas-pas-l')";
        }

        return ''; // 'all' or unknown filter
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

        $placeholders = self::build_placeholders(count($ids), '%d');
        $sql = "
            SELECT post_status, COUNT(*) AS order_count
            FROM {$wpdb->posts}
            WHERE ID IN ({$placeholders})
            GROUP BY post_status
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$ids), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Get raw post statuses keyed by order ID.
     *
     * Returns: [order_id => post_status]
     */
    public static function find_order_status_map_by_order_ids($order_ids)
    {
        global $wpdb;

        $ids = self::sanitize_int_ids($order_ids);
        if (empty($ids)) {
            return [];
        }

        $placeholders = self::build_placeholders(count($ids), '%d');
        $sql = "
            SELECT ID, post_status
            FROM {$wpdb->posts}
            WHERE ID IN ({$placeholders})
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$ids), ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $oid = isset($row['ID']) ? (int) $row['ID'] : 0;
            if ($oid <= 0) {
                continue;
            }

            $map[$oid] = isset($row['post_status']) ? (string) $row['post_status'] : '';
        }

        return $map;
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
     * Check whether an order contains at least one product owned by seller.
     */
    public static function seller_owns_order($seller_user_id, $order_id)
    {
        global $wpdb;

        $seller_user_id = (int) $seller_user_id;
        $order_id = (int) $order_id;

        if ($seller_user_id <= 0 || $order_id <= 0) {
            return false;
        }

        $marketplace_orders_table = $wpdb->prefix . 'wcfm_marketplace_orders';
        if (!self::table_exists($marketplace_orders_table)) {
            return false;
        }

        $sql = "
            SELECT 1
            FROM {$marketplace_orders_table} mo
            INNER JOIN {$wpdb->posts} orders
                ON orders.ID = mo.order_id
            WHERE mo.order_id = %d
              AND mo.vendor_id = %d
              AND orders.post_type = 'shop_order'
              AND orders.post_status NOT IN ('trash', 'auto-draft')
            LIMIT 1
        ";

        $found = (int) $wpdb->get_var($wpdb->prepare($sql, $order_id, $seller_user_id));

        return $found === 1;
    }

    /**
     * Find paginated order line-item rows for an order.
     */
    public static function find_order_article_rows_by_order_id($order_id, $limit = null, $offset = 0)
    {
        global $wpdb;

        $oid = (int) $order_id;
        if ($oid <= 0) {
            return [];
        }

        $safe_limit = null;
        if ($limit !== null) {
            $safe_limit = max(1, (int) $limit);
        }
        $safe_offset = max(0, (int) $offset);

        $order_items_table = $wpdb->prefix . 'woocommerce_order_items';
        $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

        $sql = "
            SELECT
                oi.order_item_id,
                oi.order_item_name,
                MAX(CASE WHEN oim.meta_key = '_product_id' THEN oim.meta_value END) AS product_id,
                MAX(CASE WHEN oim.meta_key = '_variation_id' THEN oim.meta_value END) AS variation_id,
                MAX(CASE WHEN oim.meta_key = '_qty' THEN oim.meta_value END) AS quantity,
                MAX(CASE WHEN oim.meta_key = '_line_total' THEN oim.meta_value END) AS line_total,
                MAX(CASE WHEN oim.meta_key = '_line_subtotal' THEN oim.meta_value END) AS line_subtotal
            FROM {$order_items_table} oi
            LEFT JOIN {$order_itemmeta_table} oim ON oim.order_item_id = oi.order_item_id
            WHERE oi.order_id = %d
              AND oi.order_item_type = 'line_item'
            GROUP BY oi.order_item_id, oi.order_item_name
            ORDER BY oi.order_item_id ASC
        ";

        $params = [$oid];
        if ($safe_limit !== null) {
            $sql .= "\n            LIMIT %d OFFSET %d\n";
            $params[] = $safe_limit;
            $params[] = $safe_offset;
        }

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Count line items for an order.
     */
    public static function count_order_articles_by_order_id($order_id)
    {
        global $wpdb;

        $oid = (int) $order_id;
        if ($oid <= 0) {
            return 0;
        }

        $order_items_table = $wpdb->prefix . 'woocommerce_order_items';
        $sql = "
            SELECT COUNT(*)
            FROM {$order_items_table}
            WHERE order_id = %d
              AND order_item_type = 'line_item'
        ";

        return (int) $wpdb->get_var($wpdb->prepare($sql, $oid));
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
