<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Order_Queries')) {
    require_once __DIR__ . '/class-cwsb-order-queries.php';
}

if (!class_exists('CWSB_Order_Mapper')) {
    require_once __DIR__ . '/class-cwsb-order-mapper.php';
}

if (!class_exists('CWSB_Order_Resolver')) {
    require_once __DIR__ . '/class-cwsb-order-resolver.php';
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

/**
 * Order repository facade.
 *
 * Delegates to specialized classes:
 * - CWSB_Order_Queries: Database operations
 * - CWSB_Order_Mapper: Data transformation
 * - CWSB_Order_Resolver: ID resolution
 *
 * Preserves the public API expected by controllers.
 */
class CWSB_Order_Repository
{
    const DEFAULT_ORDERS_LIMIT = 5;
    const MAX_ORDERS_PAGE_LIMIT = 5;

    /**
     * Find all orders for seller by flow token.
     */
    public static function find_orders_by_seller_flow_token($flow_token)
    {
        $token = CWSB_Utils::normalize_text($flow_token);
        if ($token === '') {
            return [];
        }

        $seller_user_id = CWSB_Order_Resolver::resolve_seller_user_id_by_flow_token($token);
        if ($seller_user_id <= 0) {
            return [];
        }

        $order_ids = CWSB_Order_Queries::find_order_ids_for_seller($seller_user_id);
        if (empty($order_ids)) {
            return [];
        }

        $orders = [];
        foreach ($order_ids as $order_id) {
            $mapped = self::find_order_by_id($order_id);
            if (is_array($mapped)) {
                $orders[] = $mapped;
            }
        }

        return $orders;
    }

    /**
     * Find order status counters by flow token.
     */
    public static function find_order_status_counters_by_flow_token($flow_token)
    {
        $started_at = microtime(true);
        $token = CWSB_Utils::normalize_text($flow_token);
        if ($token === '') {
            return CWSB_Order_Resolver::empty_order_counters();
        }

        $seller_user_id = CWSB_Order_Resolver::resolve_seller_user_id_by_flow_token($token);
        if ($seller_user_id <= 0) {
            return CWSB_Order_Resolver::empty_order_counters();
        }

        $order_ids = CWSB_Order_Queries::find_order_ids_for_seller($seller_user_id);
        if (empty($order_ids)) {
            return CWSB_Order_Resolver::empty_order_counters();
        }

        $rows = CWSB_Order_Queries::find_order_status_rows_by_order_ids($order_ids);
        $counters = CWSB_Order_Resolver::empty_order_counters();

        foreach ($rows as $row) {
            $raw_status = isset($row['post_status']) ? (string) $row['post_status'] : '';
            $count = isset($row['order_count']) ? (int) $row['order_count'] : 0;
            if ($count <= 0) {
                continue;
            }

            $status = CWSB_Order_Mapper::map_order_status($raw_status);
            $counters['total'] += $count;
            if (!isset($counters[$status])) {
                $status = 'to_deliver';
            }
            $counters[$status] += $count;
        }

        error_log('CWSB orders counters built in ' . round((microtime(true) - $started_at) * 1000) . 'ms for token ' . substr($token, -6));

        return $counters;
    }

    /**
     * Find all order summaries for seller.
     */
    public static function find_order_summaries_by_seller_flow_token($flow_token)
    {
        $started_at = microtime(true);
        $token = CWSB_Utils::normalize_text($flow_token);
        if ($token === '') {
            return [];
        }

        $seller_user_id = CWSB_Order_Resolver::resolve_seller_user_id_by_flow_token($token);
        if ($seller_user_id <= 0) {
            return [];
        }

        $order_ids = CWSB_Order_Queries::find_order_ids_for_seller($seller_user_id);
        if (empty($order_ids)) {
            return [];
        }

        $orders = [];
        foreach ($order_ids as $order_id) {
            $row = CWSB_Order_Queries::find_order_row_by_id($order_id);
            if (is_array($row)) {
                $orders[] = CWSB_Order_Mapper::map_order_summary($row);
            }
        }

        error_log('CWSB orders list summaries built in ' . round((microtime(true) - $started_at) * 1000) . 'ms for token ' . substr($token, -6) . ' count=' . count($orders));

        return $orders;
    }

    /**
     * Find paginated order summaries with filtering.
     */
    public static function find_order_summaries_page_by_seller_flow_token($flow_token, $status_filter, $page, $limit)
    {
        $started_at = microtime(true);
        $token = CWSB_Utils::normalize_text($flow_token);
        if ($token === '') {
            return [
                'count' => 0,
                'page' => 1,
                'limit' => 5,
                'has_more' => false,
                'next_page' => null,
                'status_filter' => 'all',
                'orders' => [],
            ];
        }

        $normalized_filter = CWSB_Order_Mapper::normalize_status_filter($status_filter);
        $safe_limit = max(1, min((int) $limit, self::MAX_ORDERS_PAGE_LIMIT));
        $safe_page = max(1, (int) $page);
        $offset = ($safe_page - 1) * $safe_limit;

        $seller_user_id = CWSB_Order_Resolver::resolve_seller_user_id_by_flow_token($token);
        if ($seller_user_id <= 0) {
            return [
                'count' => 0,
                'page' => $safe_page,
                'limit' => $safe_limit,
                'has_more' => false,
                'next_page' => null,
                'status_filter' => $normalized_filter,
                'orders' => [],
            ];
        }

        $needed = $offset + $safe_limit + 1;
        $scan_limit = max(self::DEFAULT_ORDERS_LIMIT, min(400, $needed * 3));
        $candidate_ids = CWSB_Order_Queries::find_order_ids_for_seller($seller_user_id, $scan_limit);

        if (empty($candidate_ids)) {
            return [
                'count' => 0,
                'page' => $safe_page,
                'limit' => $safe_limit,
                'has_more' => false,
                'next_page' => null,
                'status_filter' => $normalized_filter,
                'orders' => [],
            ];
        }

        $status_map = CWSB_Order_Queries::find_order_status_map_by_order_ids($candidate_ids);
        $matched_ids = [];
        foreach ($candidate_ids as $order_id) {
            $oid = (int) $order_id;
            $raw_status = isset($status_map[$oid]) ? (string) $status_map[$oid] : '';
            $mapped_status = CWSB_Order_Mapper::map_order_status($raw_status);
            if (!CWSB_Order_Mapper::status_matches_filter($mapped_status, $normalized_filter)) {
                continue;
            }

            $matched_ids[] = $oid;
            if (count($matched_ids) >= ($offset + $safe_limit + 1)) {
                break;
            }
        }

        $slice_ids = array_slice($matched_ids, $offset, $safe_limit);
        $orders = [];
        foreach ($slice_ids as $order_id) {
            $row = CWSB_Order_Queries::find_order_row_by_id($order_id);
            if (is_array($row)) {
                $orders[] = CWSB_Order_Mapper::map_order_summary($row);
            }
        }

        $has_more = count($matched_ids) > ($offset + count($orders));
        $next_page = $has_more ? ($safe_page + 1) : null;

        error_log(
            'CWSB orders page built in ' . round((microtime(true) - $started_at) * 1000) .
            'ms for token ' . substr($token, -6) .
            ' filter=' . $normalized_filter .
            ' page=' . $safe_page .
            ' count=' . count($orders) .
            ' has_more=' . ($has_more ? '1' : '0')
        );

        return [
            'count' => count($orders),
            'page' => $safe_page,
            'limit' => $safe_limit,
            'has_more' => $has_more,
            'next_page' => $next_page,
            'status_filter' => $normalized_filter,
            'orders' => $orders,
        ];
    }

    /**
     * Find single order by ID.
     */
    public static function find_order_by_id($order_id)
    {
        $oid = (int) $order_id;
        if ($oid <= 0) {
            return null;
        }

        $row = CWSB_Order_Queries::find_order_row_by_id($oid);
        if (!is_array($row)) {
            return null;
        }

        $mapped = CWSB_Order_Mapper::map_order($row, false);
        return $mapped;
    }

    /**
     * Find single order by ID, scoped to seller flow token ownership.
     */
    public static function find_order_by_id_for_seller_flow_token($flow_token, $order_id)
    {
        $token = CWSB_Utils::normalize_text($flow_token);
        $oid = (int) $order_id;

        if ($token === '' || $oid <= 0) {
            return null;
        }

        $seller_user_id = CWSB_Order_Resolver::resolve_seller_user_id_by_flow_token($token);
        if ($seller_user_id <= 0) {
            return null;
        }

        if (!CWSB_Order_Queries::seller_owns_order($seller_user_id, $oid)) {
            return null;
        }

        return self::find_order_by_id($oid);
    }

    /**
     * Find order articles by order ID.
     */
    public static function find_order_articles_by_order_id($order_id)
    {
        $row = CWSB_Order_Queries::find_order_row_by_id($order_id);
        if (!is_array($row)) {
            return [];
        }

        return CWSB_Order_Mapper::map_order_articles($row);
    }

    /**
     * Find order articles by order ID, scoped to seller flow token ownership.
     */
    public static function find_order_articles_by_order_id_for_seller_flow_token($flow_token, $order_id)
    {
        $token = CWSB_Utils::normalize_text($flow_token);
        $oid = (int) $order_id;

        if ($token === '' || $oid <= 0) {
            return [];
        }

        $seller_user_id = CWSB_Order_Resolver::resolve_seller_user_id_by_flow_token($token);
        if ($seller_user_id <= 0) {
            return [];
        }

        if (!CWSB_Order_Queries::seller_owns_order($seller_user_id, $oid)) {
            return [];
        }

        return self::find_order_articles_by_order_id($oid);
    }
}
