<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

/**
 * Wallet database query layer.
 *
 * All wallet-specific SQL lives here:
 * - Subtotal aggregation by currency (for balance computation)
 * - Last transaction date lookup
 * - Paginated transaction rows (per completed order)
 */
class CWSB_Wallet_Queries
{
    /**
     * Compute wallet subtotals per currency for a seller.
     *
     * Single aggregated query — no N+1.
     * Inner: per completed order → articles_subtotal = total − shipping − tax + discount
     * Outer: SUM by currency
     *
     * Returns: [['currency' => 'TND', 'total_subtotal' => 12345.67, 'order_count' => 80], ...]
     */
    public static function compute_wallet_subtotals_by_seller($seller_user_id)
    {
        global $wpdb;

        $uid = (int) $seller_user_id;
        if ($uid <= 0) {
            return [];
        }

        $marketplace_orders_table = $wpdb->prefix . 'wcfm_marketplace_orders';
        if (!self::table_exists($marketplace_orders_table)) {
            return [];
        }

        $sql = "
            SELECT
                currency,
                SUM(articles_subtotal) AS total_subtotal,
                COUNT(*)               AS order_count
            FROM (
                SELECT
                    MAX(CASE WHEN pm.meta_key = '_order_currency'  THEN pm.meta_value END) AS currency,
                    (
                        COALESCE(CAST(MAX(CASE WHEN pm.meta_key = '_order_total'    THEN pm.meta_value END) AS DECIMAL(10,4)), 0)
                        - COALESCE(CAST(MAX(CASE WHEN pm.meta_key = '_order_shipping' THEN pm.meta_value END) AS DECIMAL(10,4)), 0)
                        - COALESCE(CAST(MAX(CASE WHEN pm.meta_key = '_order_tax'      THEN pm.meta_value END) AS DECIMAL(10,4)), 0)
                        + COALESCE(CAST(MAX(CASE WHEN pm.meta_key = '_cart_discount'  THEN pm.meta_value END) AS DECIMAL(10,4)), 0)
                    ) AS articles_subtotal
                FROM {$marketplace_orders_table} mo
                INNER JOIN {$wpdb->posts} p
                    ON  p.ID          = mo.order_id
                    AND p.post_type   = 'shop_order'
                    AND p.post_status = 'wc-completed'
                LEFT JOIN {$wpdb->postmeta} pm
                    ON  pm.post_id  = p.ID
                    AND pm.meta_key IN ('_order_total', '_order_shipping', '_order_tax', '_cart_discount', '_order_currency')
                WHERE mo.vendor_id = %d
                GROUP BY p.ID
            ) t
            GROUP BY currency
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $uid), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Get the post_date of the most recent completed order for a seller.
     *
     * Returns raw MySQL datetime string or null if no completed orders exist.
     */
    public static function find_wallet_last_tx_at_by_seller($seller_user_id)
    {
        global $wpdb;

        $uid = (int) $seller_user_id;
        if ($uid <= 0) {
            return null;
        }

        $marketplace_orders_table = $wpdb->prefix . 'wcfm_marketplace_orders';
        if (!self::table_exists($marketplace_orders_table)) {
            return null;
        }

        $sql = "
            SELECT MAX(p.post_date)
            FROM {$marketplace_orders_table} mo
            INNER JOIN {$wpdb->posts} p
                ON  p.ID          = mo.order_id
                AND p.post_type   = 'shop_order'
                AND p.post_status = 'wc-completed'
            WHERE mo.vendor_id = %d
        ";

        $result = $wpdb->get_var($wpdb->prepare($sql, $uid));
        return ($result !== null && $result !== '') ? (string) $result : null;
    }

    /**
     * Fetch per-order wallet transaction rows for a seller.
     *
     * Each row represents one completed order (NOT grouped by currency).
     * articles_subtotal = total − shipping − tax + discount (same formula as subtotals query).
     *
     * Returns: [['order_id', 'post_date', 'order_number', 'currency', 'articles_subtotal'], ...]
     */
    public static function find_wallet_transaction_rows_by_seller($seller_user_id, $limit, $offset = 0)
    {
        global $wpdb;

        $uid         = (int) $seller_user_id;
        $safe_limit  = max(1, (int) $limit);
        $safe_offset = max(0, (int) $offset);

        if ($uid <= 0) {
            return [];
        }

        $marketplace_orders_table = $wpdb->prefix . 'wcfm_marketplace_orders';
        if (!self::table_exists($marketplace_orders_table)) {
            return [];
        }

        $sql = "
            SELECT
                p.ID   AS order_id,
                p.post_date,
                MAX(CASE WHEN pm.meta_key = '_order_number'   THEN pm.meta_value END) AS order_number,
                MAX(CASE WHEN pm.meta_key = '_order_currency' THEN pm.meta_value END) AS currency,
                (
                    COALESCE(CAST(MAX(CASE WHEN pm.meta_key = '_order_total'    THEN pm.meta_value END) AS DECIMAL(10,4)), 0)
                    - COALESCE(CAST(MAX(CASE WHEN pm.meta_key = '_order_shipping' THEN pm.meta_value END) AS DECIMAL(10,4)), 0)
                    - COALESCE(CAST(MAX(CASE WHEN pm.meta_key = '_order_tax'      THEN pm.meta_value END) AS DECIMAL(10,4)), 0)
                    + COALESCE(CAST(MAX(CASE WHEN pm.meta_key = '_cart_discount'  THEN pm.meta_value END) AS DECIMAL(10,4)), 0)
                ) AS articles_subtotal
            FROM {$marketplace_orders_table} mo
            INNER JOIN {$wpdb->posts} p
                ON  p.ID          = mo.order_id
                AND p.post_type   = 'shop_order'
                AND p.post_status = 'wc-completed'
            LEFT JOIN {$wpdb->postmeta} pm
                ON  pm.post_id  = p.ID
                AND pm.meta_key IN ('_order_total', '_order_shipping', '_order_tax', '_cart_discount', '_order_currency', '_order_number')
            WHERE mo.vendor_id = %d
            GROUP BY p.ID, p.post_date
            ORDER BY p.post_date DESC, p.ID DESC
            LIMIT %d OFFSET %d
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $uid, $safe_limit, $safe_offset), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Helper: Check if a table exists in the database.
     */
    private static function table_exists($table_name)
    {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        return $exists === $table_name;
    }
}
