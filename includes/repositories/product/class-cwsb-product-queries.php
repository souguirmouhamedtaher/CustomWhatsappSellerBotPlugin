<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

/**
 * Product SQL query layer.
 */
class CWSB_Product_Queries
{
    public static function find_products_rows_by_seller_user_id($seller_user_id, $limit)
    {
        global $wpdb;

        $sql = "
            SELECT
                p.ID,
                p.post_title,
                p.post_date,
                p.post_status,
                MAX(CASE WHEN pm.meta_key = '_sku' THEN pm.meta_value END) AS sku,
                MAX(CASE WHEN pm.meta_key = '_price' THEN pm.meta_value END) AS price,
                MAX(CASE WHEN pm.meta_key = '_sale_price' THEN pm.meta_value END) AS sale_price,
                MAX(CASE WHEN pm.meta_key = '_regular_price' THEN pm.meta_value END) AS regular_price,
                MAX(CASE WHEN pm.meta_key = '_stock' THEN pm.meta_value END) AS stock,
                MAX(CASE WHEN pm.meta_key = '_manage_stock' THEN pm.meta_value END) AS manage_stock,
                MAX(CASE WHEN pm.meta_key = '_thumbnail_id' THEN pm.meta_value END) AS thumbnail_id,
                CASE WHEN EXISTS (
                    SELECT 1
                    FROM {$wpdb->posts} v
                    WHERE v.post_parent = p.ID
                      AND v.post_type = 'product_variation'
                      AND v.post_status IN ('publish', 'private')
                ) THEN 1 ELSE 0 END AS has_variations
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE p.post_type = 'product'
              AND p.post_status IN ('publish', 'private', 'draft', 'pending')
              AND p.post_author = %d
            GROUP BY p.ID, p.post_title, p.post_date, p.post_status
            ORDER BY p.post_date DESC
            LIMIT %d
        ";

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, (int) $seller_user_id, (int) $limit),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public static function find_product_detail_row_by_id($product_id)
    {
        global $wpdb;

        $pid = (int) $product_id;
        if ($pid <= 0) {
            return null;
        }

        $sql = "
            SELECT
                p.ID,
                p.post_title,
                p.post_excerpt,
                p.post_content,
                p.post_date,
                p.post_status,
                MAX(CASE WHEN pm.meta_key = '_sku' THEN pm.meta_value END) AS sku,
                MAX(CASE WHEN pm.meta_key = '_regular_price' THEN pm.meta_value END) AS regular_price,
                MAX(CASE WHEN pm.meta_key = '_sale_price' THEN pm.meta_value END) AS sale_price,
                MAX(CASE WHEN pm.meta_key = '_regular_price_tnd' THEN pm.meta_value END) AS regular_price_tnd,
                MAX(CASE WHEN pm.meta_key = '_sale_price_tnd' THEN pm.meta_value END) AS sale_price_tnd,
                MAX(CASE WHEN pm.meta_key = '_stock' THEN pm.meta_value END) AS stock,
                MAX(CASE WHEN pm.meta_key = '_manage_stock' THEN pm.meta_value END) AS manage_stock,
                MAX(CASE WHEN pm.meta_key = '_thumbnail_id' THEN pm.meta_value END) AS thumbnail_id,
                MAX(CASE WHEN pm.meta_key = '_product_image_gallery' THEN pm.meta_value END) AS gallery_ids
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE p.ID = %d
              AND p.post_type = 'product'
              AND p.post_status IN ('publish', 'private', 'draft', 'pending')
            GROUP BY p.ID, p.post_title, p.post_excerpt, p.post_content, p.post_date, p.post_status
            LIMIT 1
        ";

        return $wpdb->get_row($wpdb->prepare($sql, $pid));
    }

    public static function find_variations_rows_for_product($product_id)
    {
        global $wpdb;

        $pid = (int) $product_id;
        if ($pid <= 0) {
            return [];
        }

        $sql = "
            SELECT
                p.ID,
                p.post_title,
                MAX(CASE WHEN pm.meta_key = '_sku' THEN pm.meta_value END) AS sku,
                MAX(CASE WHEN pm.meta_key = '_price' THEN pm.meta_value END) AS price,
                MAX(CASE WHEN pm.meta_key = '_price_tnd' THEN pm.meta_value END) AS price_tnd,
                MAX(CASE WHEN pm.meta_key = '_stock' THEN pm.meta_value END) AS stock,
                MAX(CASE WHEN pm.meta_key = '_stock_status' THEN pm.meta_value END) AS stock_status,
                MAX(CASE WHEN pm.meta_key = '_manage_stock' THEN pm.meta_value END) AS manage_stock,
                MAX(CASE WHEN pm.meta_key = '_thumbnail_id' THEN pm.meta_value END) AS thumbnail_id,
                MAX(CASE WHEN pm.meta_key = '_weight' THEN pm.meta_value END) AS weight
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE p.post_type = 'product_variation'
              AND p.post_parent = %d
              AND p.post_status IN ('publish', 'private')
            GROUP BY p.ID, p.post_title
            ORDER BY p.menu_order ASC, p.ID ASC
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $pid));
        return is_array($rows) ? $rows : [];
    }

    public static function find_variation_attributes($variation_id)
    {
        global $wpdb;

        $vid = (int) $variation_id;
        if ($vid <= 0) {
            return [];
        }

        $sql = "
            SELECT meta_key, meta_value
            FROM {$wpdb->postmeta}
            WHERE post_id = %d
              AND meta_key LIKE 'attribute_%%'
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $vid));
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $attributes = [];
        foreach ($rows as $row) {
            $key = isset($row->meta_key) ? strtolower((string) $row->meta_key) : '';
            $key = preg_replace('/^attribute_/', '', $key);
            $key = preg_replace('/^pa_/', '', $key);
            if ($key === '') {
                continue;
            }
            $attributes[$key] = isset($row->meta_value) ? (string) $row->meta_value : '';
        }

        return $attributes;
    }

    public static function find_term_names_by_taxonomy($product_id, $taxonomy)
    {
        global $wpdb;

        $pid = (int) $product_id;
        $tax = trim((string) $taxonomy);
        if ($pid <= 0 || $tax === '') {
            return [];
        }

        $sql = "
            SELECT t.name
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
            WHERE tr.object_id = %d
              AND tt.taxonomy = %s
            ORDER BY t.name ASC
        ";

        $rows = $wpdb->get_col($wpdb->prepare($sql, $pid, $tax));
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $names = [];
        foreach ($rows as $row) {
            $name = trim((string) $row);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }
}
