<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Cache')) {
    require_once __DIR__ . '/../utilities/class-cwsb-cache.php';
}

if (!class_exists('CWSB_Seller_Repository')) {
    require_once __DIR__ . '/class-cwsb-seller-repository.php';
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../utilities/class-cwsb-utils.php';
}

/**
 * Rebuilt order repository for seller flows.
 *
 * Design goals:
 * - Keep each call bounded and predictable.
 * - Prefer WooCommerce lookup tables when available.
 * - Preserve the existing public API expected by controllers.
 */
class CWSB_Order_Repository
{
    const DEFAULT_ORDERS_LIMIT = 5;
    const MAX_ORDERS_PAGE_LIMIT = 5;
    const MAX_PRODUCT_IDS = 3000;

    private static function order_counters_flow_cache_key($flow_token)
    {
        return 'order:counters:flow:' . CWSB_Utils::normalize_text($flow_token);
    }

    private static function order_summary_list_flow_cache_key($flow_token)
    {
        return 'order:summary:list:flow:' . CWSB_Utils::normalize_text($flow_token);
    }

    private static function order_list_flow_cache_key($flow_token)
    {
        return 'order:list:flow:' . CWSB_Utils::normalize_text($flow_token);
    }

    private static function order_detail_cache_key($order_id)
    {
        return 'order:detail:' . (int) $order_id;
    }

    private static function order_seller_flow_cache_key($flow_token)
    {
        return 'order:seller:flow:' . CWSB_Utils::normalize_text($flow_token);
    }

    private static function order_seller_phone_cache_key($phone)
    {
        $normalized = CWSB_Utils::normalize_phone($phone);
        if ($normalized === '') {
            return '';
        }

        return 'order:seller:phone:' . $normalized;
    }

    public static function find_orders_by_seller_flow_token($flow_token)
    {
        $token = CWSB_Utils::normalize_text($flow_token);
        if ($token === '') {
            return [];
        }

        $cache_key = self::order_list_flow_cache_key($token);
        $cache_hit = false;
        $cached = CWSB_Cache::get($cache_key, $cache_hit);
        if ($cache_hit) {
            return is_array($cached) ? $cached : [];
        }

        $seller_user_id = self::resolve_seller_user_id_by_flow_token($token);
        if ($seller_user_id <= 0) {
            CWSB_Cache::set($cache_key, []);
            return [];
        }

        $order_ids = self::find_order_ids_for_seller($seller_user_id);
        if (empty($order_ids)) {
            CWSB_Cache::set($cache_key, []);
            return [];
        }

        $orders = [];
        foreach ($order_ids as $order_id) {
            $mapped = self::find_order_by_id($order_id);
            if (is_array($mapped)) {
                $orders[] = $mapped;
            }
        }

        CWSB_Cache::set($cache_key, $orders);
        return $orders;
    }

    public static function find_order_status_counters_by_flow_token($flow_token)
    {
        $started_at = microtime(true);
        $token = CWSB_Utils::normalize_text($flow_token);
        if ($token === '') {
            return self::empty_order_counters();
        }

        $cache_key = self::order_counters_flow_cache_key($token);
        $cache_hit = false;
        $cached = CWSB_Cache::get($cache_key, $cache_hit);
        if ($cache_hit && is_array($cached)) {
            return $cached;
        }

        $seller_user_id = self::resolve_seller_user_id_by_flow_token($token);
        if ($seller_user_id <= 0) {
            $empty = self::empty_order_counters();
            CWSB_Cache::set($cache_key, $empty);
            return $empty;
        }

        $order_ids = self::find_order_ids_for_seller($seller_user_id);
        if (empty($order_ids)) {
            $empty = self::empty_order_counters();
            CWSB_Cache::set($cache_key, $empty);
            return $empty;
        }

        $rows = self::find_order_status_rows_by_order_ids($order_ids);
        $counters = self::empty_order_counters();

        foreach ($rows as $row) {
            $raw_status = isset($row['post_status']) ? (string) $row['post_status'] : '';
            $count = isset($row['order_count']) ? (int) $row['order_count'] : 0;
            if ($count <= 0) {
                continue;
            }

            $status = self::map_order_status($raw_status);
            $counters['total'] += $count;
            if (!isset($counters[$status])) {
                $status = 'to_deliver';
            }
            $counters[$status] += $count;
        }

        CWSB_Cache::set($cache_key, $counters);
        error_log('CWSB orders counters built in ' . round((microtime(true) - $started_at) * 1000) . 'ms for token ' . substr($token, -6));

        return $counters;
    }

    public static function find_order_summaries_by_seller_flow_token($flow_token)
    {
        $started_at = microtime(true);
        $token = CWSB_Utils::normalize_text($flow_token);
        if ($token === '') {
            return [];
        }

        $cache_key = self::order_summary_list_flow_cache_key($token);
        $cache_hit = false;
        $cached = CWSB_Cache::get($cache_key, $cache_hit);
        if ($cache_hit) {
            return is_array($cached) ? $cached : [];
        }

        $seller_user_id = self::resolve_seller_user_id_by_flow_token($token);
        if ($seller_user_id <= 0) {
            CWSB_Cache::set($cache_key, []);
            return [];
        }

        $order_ids = self::find_order_ids_for_seller($seller_user_id);
        if (empty($order_ids)) {
            CWSB_Cache::set($cache_key, []);
            return [];
        }

        $orders = [];
        foreach ($order_ids as $order_id) {
            $order = self::find_order_summary_by_id($order_id);
            if (is_array($order)) {
                $orders[] = $order;
            }
        }

        CWSB_Cache::set($cache_key, $orders);
        error_log('CWSB orders list summaries built in ' . round((microtime(true) - $started_at) * 1000) . 'ms for token ' . substr($token, -6) . ' count=' . count($orders));

        return $orders;
    }

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

        $normalized_filter = self::normalize_status_filter($status_filter);
        $safe_limit = max(1, min((int) $limit, self::MAX_ORDERS_PAGE_LIMIT));
        $safe_page = max(1, (int) $page);
        $offset = ($safe_page - 1) * $safe_limit;

        $seller_user_id = self::resolve_seller_user_id_by_flow_token($token);
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
        $candidate_ids = self::find_order_ids_for_seller($seller_user_id, $scan_limit);

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

        $matched_ids = [];
        foreach ($candidate_ids as $order_id) {
            $raw_status = get_post_status((int) $order_id);
            $mapped_status = self::map_order_status($raw_status);
            if (!self::status_matches_filter($mapped_status, $normalized_filter)) {
                continue;
            }

            $matched_ids[] = (int) $order_id;
            if (count($matched_ids) >= ($offset + $safe_limit + 1)) {
                break;
            }
        }

        $slice_ids = array_slice($matched_ids, $offset, $safe_limit);
        $orders = [];
        foreach ($slice_ids as $order_id) {
            $order = self::find_order_summary_by_id($order_id);
            if (is_array($order)) {
                $orders[] = $order;
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

    public static function find_order_by_id($order_id)
    {
        $oid = (int) $order_id;
        if ($oid <= 0) {
            return null;
        }

        $cache_key = self::order_detail_cache_key($oid);
        $cache_hit = false;
        $cached = CWSB_Cache::get($cache_key, $cache_hit);
        if ($cache_hit) {
            return $cached;
        }

        $row = self::find_order_row_by_id($oid);
        if (!is_array($row)) {
            CWSB_Cache::set($cache_key, null);
            return null;
        }

        $mapped = self::map_order($row, false);
        CWSB_Cache::set($cache_key, $mapped);
        return $mapped;
    }

    public static function find_order_articles_by_order_id($order_id)
    {
        $row = self::find_order_row_by_id($order_id);
        if (!is_array($row)) {
            return [];
        }

        return self::map_order_articles($row);
    }

    private static function find_order_summary_by_id($order_id)
    {
        $row = self::find_order_row_by_id($order_id);
        if (!is_array($row)) {
            return null;
        }

        return self::map_order_summary($row);
    }

    private static function find_order_row_by_id($order_id)
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

    private static function find_order_article_rows($order_id)
    {
        global $wpdb;

        $oid = (int) $order_id;
        if ($oid <= 0) {
            return [];
        }

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

        $rows = $wpdb->get_results($wpdb->prepare($sql, $oid), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    private static function summarize_order_article_rows($rows)
    {
        $articles_count = 0;
        $subtotal = 0.0;

        foreach ((array) $rows as $row) {
            $quantity = max(0, (int) ($row['quantity'] ?? 0));
            $articles_count += $quantity;

            $line_subtotal = (float) ($row['line_subtotal'] ?? 0);
            if ($line_subtotal <= 0) {
                $line_subtotal = (float) ($row['line_total'] ?? 0);
            }
            $subtotal += $line_subtotal;
        }

        return [
            'articles_count' => $articles_count,
            'subtotal' => $subtotal,
        ];
    }

    private static function compose_person_name($first_name, $last_name)
    {
        return CWSB_Utils::normalize_text(trim((string) $first_name . ' ' . (string) $last_name));
    }

    private static function format_order_created_at($post_date)
    {
        $date = trim((string) $post_date);
        if ($date === '') {
            return '';
        }

        return mysql2date('d/m/Y H:i', $date);
    }

    private static function build_order_reference($row)
    {
        $order_number = CWSB_Utils::normalize_text(isset($row['order_number']) ? $row['order_number'] : '');
        if ($order_number === '') {
            $order_number = (string) (isset($row['ID']) ? (int) $row['ID'] : 0);
        }

        return CWSB_Utils::normalize_text('Commande #' . $order_number);
    }

    private static function build_order_address_info($row, $prefix)
    {
        $name = self::compose_person_name(
            isset($row[$prefix . '_first_name']) ? $row[$prefix . '_first_name'] : '',
            isset($row[$prefix . '_last_name']) ? $row[$prefix . '_last_name'] : ''
        );

        $parts = [
            $name,
            CWSB_Utils::normalize_text(isset($row[$prefix . '_address_1']) ? $row[$prefix . '_address_1'] : ''),
            CWSB_Utils::normalize_text(isset($row[$prefix . '_address_2']) ? $row[$prefix . '_address_2'] : ''),
            CWSB_Utils::normalize_text(isset($row[$prefix . '_city']) ? $row[$prefix . '_city'] : ''),
            CWSB_Utils::normalize_text(isset($row[$prefix . '_state']) ? $row[$prefix . '_state'] : ''),
            CWSB_Utils::normalize_text(isset($row[$prefix . '_postcode']) ? $row[$prefix . '_postcode'] : ''),
            CWSB_Utils::normalize_text(isset($row[$prefix . '_country']) ? $row[$prefix . '_country'] : ''),
        ];

        return CWSB_Utils::normalize_text(implode("\n", array_filter($parts)));
    }

    private static function map_order_summary($row)
    {
        $article_rows = self::find_order_article_rows(isset($row['ID']) ? (int) $row['ID'] : 0);
        $metrics = self::summarize_order_article_rows($article_rows);
        $status = self::map_order_status(isset($row['post_status']) ? $row['post_status'] : '');
        $customer_name = self::compose_person_name(
            isset($row['billing_first_name']) ? $row['billing_first_name'] : '',
            isset($row['billing_last_name']) ? $row['billing_last_name'] : ''
        );
        $customer_id = (int) (isset($row['customer_user']) ? $row['customer_user'] : 0);

        return [
            'id' => (string) (isset($row['ID']) ? (int) $row['ID'] : 0),
            'reference' => self::build_order_reference($row),
            'customer_name' => $customer_name !== '' ? $customer_name : ('Client #' . $customer_id),
            'created_at' => self::format_order_created_at(isset($row['post_date']) ? $row['post_date'] : ''),
            'total' => (float) (isset($row['order_total']) ? $row['order_total'] : 0),
            'currency' => CWSB_Utils::normalize_text(isset($row['order_currency']) ? $row['order_currency'] : ''),
            'status' => $status,
            'tags' => [self::map_order_status_label($status)],
            'articles_count' => (int) $metrics['articles_count'],
        ];
    }

    private static function map_order($row, $include_articles = false)
    {
        $articles = [];
        $article_rows = self::find_order_article_rows(isset($row['ID']) ? (int) $row['ID'] : 0);
        $metrics = self::summarize_order_article_rows($article_rows);
        $articles_count = (int) $metrics['articles_count'];
        $subtotal = (float) $metrics['subtotal'];

        if ($include_articles) {
            $articles = self::map_order_articles($row);
            $articles_count = count($articles);
            $subtotal = 0.0;
            foreach ($articles as $article) {
                $subtotal += ((float) $article['price']) * ((int) $article['quantity']);
            }
        }

        $status = self::map_order_status(isset($row['post_status']) ? $row['post_status'] : '');
        $customer_name = self::compose_person_name(
            isset($row['billing_first_name']) ? $row['billing_first_name'] : '',
            isset($row['billing_last_name']) ? $row['billing_last_name'] : ''
        );
        $customer_id = (int) (isset($row['customer_user']) ? $row['customer_user'] : 0);

        return [
            'id' => (string) (isset($row['ID']) ? (int) $row['ID'] : 0),
            'reference' => self::build_order_reference($row),
            'customer_name' => $customer_name !== '' ? $customer_name : ('Client #' . $customer_id),
            'created_at' => self::format_order_created_at(isset($row['post_date']) ? $row['post_date'] : ''),
            'total' => (float) (isset($row['order_total']) ? $row['order_total'] : 0),
            'currency' => CWSB_Utils::normalize_text(isset($row['order_currency']) ? $row['order_currency'] : ''),
            'status' => $status,
            'tags' => [self::map_order_status_label($status)],
            'articles_count' => $articles_count,
            'payment_method' => CWSB_Utils::normalize_text(isset($row['payment_method_title']) ? $row['payment_method_title'] : ''),
            'transaction_id' => CWSB_Utils::normalize_text(isset($row['transaction_id']) ? $row['transaction_id'] : ''),
            'customer_note' => CWSB_Utils::normalize_text(isset($row['customer_note']) && $row['customer_note'] !== '' ? $row['customer_note'] : (isset($row['post_excerpt']) ? $row['post_excerpt'] : '')),
            'articles' => $articles,
            'billing_info' => self::build_order_address_info($row, 'billing'),
            'shipping_info' => self::build_order_address_info($row, 'shipping'),
            'subtotal' => (float) $subtotal,
            'shipping_cost' => (float) (isset($row['order_shipping']) && $row['order_shipping'] !== '' ? $row['order_shipping'] : (isset($row['shipping_total']) ? $row['shipping_total'] : 0)),
        ];
    }

    private static function map_order_articles($row)
    {
        $articles = [];
        $currency = CWSB_Utils::normalize_text(isset($row['order_currency']) ? $row['order_currency'] : '');
        $item_rows = self::find_order_article_rows(isset($row['ID']) ? (int) $row['ID'] : 0);

        foreach ((array) $item_rows as $item) {
            $product_id = (int) ($item['product_id'] ?? 0);
            $variation_id = (int) ($item['variation_id'] ?? 0);
            $effective_product_id = $variation_id > 0 ? $variation_id : $product_id;

            $image_url = '';
            if ($effective_product_id > 0) {
                $image_id = (int) get_post_meta($effective_product_id, '_thumbnail_id', true);
                if ($image_id <= 0 && $product_id > 0) {
                    $image_id = (int) get_post_meta($product_id, '_thumbnail_id', true);
                }
                if ($image_id > 0) {
                    $image_url = (string) wp_get_attachment_image_url($image_id, 'medium');
                }
            }

            $item_id = (int) ($item['order_item_id'] ?? 0);
            $qty = max(0, (int) ($item['quantity'] ?? 0));
            $line_total = (float) ($item['line_total'] ?? 0);
            $unit_price = $qty > 0 ? ($line_total / $qty) : 0.0;

            $articles[] = [
                'id' => (string) ($effective_product_id > 0 ? $effective_product_id : $item_id),
                'name' => CWSB_Utils::normalize_text(isset($item['order_item_name']) ? $item['order_item_name'] : ''),
                'sku' => CWSB_Utils::normalize_text($effective_product_id > 0 ? get_post_meta($effective_product_id, '_sku', true) : ''),
                'quantity' => $qty,
                'price' => (float) $unit_price,
                'currency' => $currency,
                'image' => CWSB_Utils::normalize_text($image_url),
            ];
        }

        return $articles;
    }

    private static function normalize_status_filter($status_filter)
    {
        $filter = strtolower(CWSB_Utils::normalize_text($status_filter));

        if ($filter === 'completed') {
            return 'completed';
        }

        if ($filter === 'in_delivery') {
            return 'in_delivery';
        }

        if ($filter === 'to_deliver') {
            return 'to_deliver';
        }

        return 'all';
    }

    private static function status_matches_filter($status, $filter)
    {
        if ($filter === 'all') {
            return true;
        }

        return strtolower((string) $status) === strtolower((string) $filter);
    }

    private static function map_order_status($status)
    {
        $value = strtolower(CWSB_Utils::normalize_text($status));
        $value = preg_replace('/^wc-/', '', $value);

        if ($value === 'completed') {
            return 'completed';
        }

        if ($value === 'in_delivery' || $value === 'in-delivery' || $value === 'shipped') {
            return 'in_delivery';
        }

        return 'to_deliver';
    }

    private static function map_order_status_label($status)
    {
        if ($status === 'completed') {
            return 'Livree';
        }

        if ($status === 'in_delivery') {
            return 'En livraison';
        }

        return 'A livrer';
    }

    private static function find_order_ids_for_seller($seller_user_id, $limit = null)
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

        $product_ids = self::find_seller_product_ids($seller_user_id, self::MAX_PRODUCT_IDS);
        if (empty($product_ids)) {
            return [];
        }

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

        return self::sanitize_int_ids($rows);
    }

    private static function find_order_status_rows_by_order_ids($order_ids)
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

    private static function resolve_seller_user_id_by_flow_token($flow_token)
    {
        $token = CWSB_Utils::normalize_text($flow_token);
        if ($token === '') {
            return 0;
        }

        $flow_cache_key = self::order_seller_flow_cache_key($token);
        $cache_hit = false;
        $cached = CWSB_Cache::get($flow_cache_key, $cache_hit);
        if ($cache_hit) {
            return (int) $cached;
        }

        $phone = CWSB_Utils::extract_phone_from_flow_token($token);
        $phone_cache_key = self::order_seller_phone_cache_key($phone);
        if ($phone_cache_key !== '') {
            $cached_phone = CWSB_Cache::get($phone_cache_key, $cache_hit);
            if ($cache_hit) {
                $uid = (int) $cached_phone;
                if ($uid > 0) {
                    CWSB_Cache::set($flow_cache_key, $uid);
                    return $uid;
                }
            }
        }

        global $wpdb;
        $state_table = CWSB_Seller_Repository::state_table_name();

        $direct_user_id = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT user_id FROM {$state_table} WHERE flow_token = %s LIMIT 1", $token)
        );

        if ($direct_user_id > 0) {
            CWSB_Cache::set($flow_cache_key, $direct_user_id);
            if ($phone_cache_key !== '') {
                CWSB_Cache::set($phone_cache_key, $direct_user_id);
            }
            return $direct_user_id;
        }

        if ($phone !== '') {
            $by_phone_user_id = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT user_id FROM {$state_table} WHERE phone = %s ORDER BY id DESC LIMIT 1", $phone)
            );

            if ($by_phone_user_id > 0) {
                CWSB_Cache::set($flow_cache_key, $by_phone_user_id);
                if ($phone_cache_key !== '') {
                    CWSB_Cache::set($phone_cache_key, $by_phone_user_id);
                }
                return $by_phone_user_id;
            }
        }

        // Soft fallback through seller repository if available.
        if (method_exists('CWSB_Seller_Repository', 'find_vendor_by_flow_token')) {
            $seller = CWSB_Seller_Repository::find_vendor_by_flow_token($token);
            if (is_array($seller) && isset($seller['user_id'])) {
                $uid = (int) $seller['user_id'];
                if ($uid > 0) {
                    CWSB_Cache::set($flow_cache_key, $uid);
                    if ($phone_cache_key !== '') {
                        CWSB_Cache::set($phone_cache_key, $uid);
                    }
                    return $uid;
                }
            }
        }

        if ($phone !== '' && method_exists('CWSB_Seller_Repository', 'find_vendor_by_phone')) {
            $seller = CWSB_Seller_Repository::find_vendor_by_phone($phone);
            if (is_array($seller) && isset($seller['user_id'])) {
                $uid = (int) $seller['user_id'];
                if ($uid > 0) {
                    CWSB_Cache::set($flow_cache_key, $uid);
                    if ($phone_cache_key !== '') {
                        CWSB_Cache::set($phone_cache_key, $uid);
                    }
                    return $uid;
                }
            }
        }

        return 0;
    }

    private static function find_seller_product_ids($seller_user_id, $limit)
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

    private static function empty_order_counters()
    {
        return [
            'total' => 0,
            'completed' => 0,
            'in_delivery' => 0,
            'to_deliver' => 0,
        ];
    }

    private static function table_exists($table_name)
    {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        return $exists === $table_name;
    }

    private static function build_placeholders($count, $placeholder)
    {
        $count = max(1, (int) $count);
        return implode(',', array_fill(0, $count, $placeholder));
    }

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
