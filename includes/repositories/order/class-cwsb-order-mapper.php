<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

/**
 * Order data transformation and mapping layer.
 *
 * Converts raw database rows into normalized data structures for API responses.
 * Handles all formatting, normalization, and data enrichment.
 */
class CWSB_Order_Mapper
{
    /**
     * Transform raw order row into summary format (minimal fields for lists).
     */
    public static function map_order_summary($row)
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

    /**
     * Transform raw order row into full detail format (all fields).
     */
    public static function map_order($row, $include_articles = false)
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

    /**
     * Transform order articles from database rows into API format.
     */
    public static function map_order_articles($row)
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

    /**
     * Normalize status filter from user input.
     */
    public static function normalize_status_filter($status_filter)
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

    /**
     * Check if order status matches filter criteria.
     */
    public static function status_matches_filter($status, $filter)
    {
        if ($filter === 'all') {
            return true;
        }

        return strtolower((string) $status) === strtolower((string) $filter);
    }

    /**
     * Map WordPress order status to WhatsApp bot status.
     */
    public static function map_order_status($status)
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

    /**
     * Get localized label for order status.
     */
    public static function map_order_status_label($status)
    {
        if ($status === 'completed') {
            return 'Livree';
        }

        if ($status === 'in_delivery') {
            return 'En livraison';
        }

        return 'A livrer';
    }

    /**
     * Helper: Compose full name from first and last name.
     */
    private static function compose_person_name($first_name, $last_name)
    {
        return CWSB_Utils::normalize_text(trim((string) $first_name . ' ' . (string) $last_name));
    }

    /**
     * Helper: Format MySQL datetime to display format.
     */
    private static function format_order_created_at($post_date)
    {
        $date = trim((string) $post_date);
        if ($date === '') {
            return '';
        }

        return mysql2date('d/m/Y H:i', $date);
    }

    /**
     * Helper: Build order reference number string.
     */
    private static function build_order_reference($row)
    {
        $order_number = CWSB_Utils::normalize_text(isset($row['order_number']) ? $row['order_number'] : '');
        if ($order_number === '') {
            $order_number = (string) (isset($row['ID']) ? (int) $row['ID'] : 0);
        }

        return CWSB_Utils::normalize_text('Commande #' . $order_number);
    }

    /**
     * Helper: Build address info block (billing or shipping).
     */
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

    /**
     * Helper: Fetch and summarize order articles.
     */
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

    /**
     * Helper: Summarize article metrics (count and subtotal).
     */
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
}
