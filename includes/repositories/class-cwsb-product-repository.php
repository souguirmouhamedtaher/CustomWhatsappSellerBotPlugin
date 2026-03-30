<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Cache')) {
    require_once __DIR__ . '/../utilities/class-cwsb-cache.php';
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../utilities/class-cwsb-utils.php';
}

/**
 * Product data-access layer for seller-specific WhatsApp product flow.
 */
class CWSB_Product_Repository
{
    // Keep this comfortably above flow page size so UI pagination can traverse full seller catalog.
    const DEFAULT_PRODUCTS_LIMIT = 200;
    const MAX_CAROUSEL_IMAGES = 3;

    private static function product_list_user_cache_key($seller_user_id)
    {
        return 'product:list:user:' . (int) $seller_user_id . ':limit:' . (int) self::DEFAULT_PRODUCTS_LIMIT;
    }

    private static function product_list_phone_cache_key($phone)
    {
        $normalized = CWSB_Utils::normalize_phone($phone);
        return 'product:list:phone:' . $normalized . ':limit:' . (int) self::DEFAULT_PRODUCTS_LIMIT;
    }

    private static function product_list_flow_cache_key($flow_token)
    {
        return 'product:list:flow:' . CWSB_Utils::normalize_text($flow_token) . ':limit:' . (int) self::DEFAULT_PRODUCTS_LIMIT;
    }

    private static function product_detail_cache_key($product_id)
    {
        return 'product:detail:' . (int) $product_id;
    }

    /**
     * Clears cached product-list reads for known seller references.
     */
    public static function invalidate_cached_lists_for_seller_refs($seller_user_id = 0, $phones = [], $flow_tokens = [])
    {
        $uid = (int) $seller_user_id;
        if ($uid > 0) {
            CWSB_Cache::delete(self::product_list_user_cache_key($uid));
        }

        foreach ((array) $phones as $phone) {
            $normalized = CWSB_Utils::normalize_phone($phone);
            if ($normalized === '') {
                continue;
            }
            CWSB_Cache::delete(self::product_list_phone_cache_key($normalized));
        }

        foreach ((array) $flow_tokens as $flow_token) {
            $token = CWSB_Utils::normalize_text($flow_token);
            if ($token === '') {
                continue;
            }
            CWSB_Cache::delete(self::product_list_flow_cache_key($token));
        }
    }

    /**
     * Returns all products owned by seller resolved by phone from state table.
     */
    public static function find_products_by_seller_phone($phone)
    {
        $cache_key = self::product_list_phone_cache_key($phone);
        $cache_hit = false;
        $cached = CWSB_Cache::get($cache_key, $cache_hit);
        if ($cache_hit) {
            return $cached;
        }

        $seller_user_id = self::find_state_user_id_by_phone($phone);
        if ($seller_user_id <= 0) {
            CWSB_Cache::set($cache_key, []);
            return [];
        }

        $products = self::find_products_by_seller_user_id($seller_user_id);
        CWSB_Cache::set($cache_key, $products);
        return $products;
    }

    /**
     * Returns all products owned by the seller resolved by flow token.
     */
    public static function find_products_by_seller_flow_token($flow_token)
    {
        $cache_key = self::product_list_flow_cache_key($flow_token);
        $cache_hit = false;
        $cached = CWSB_Cache::get($cache_key, $cache_hit);
        if ($cache_hit) {
            return $cached;
        }

        // Resolve seller strictly from custom state table first.
        $seller_user_id = self::find_state_user_id_by_flow_token($flow_token);

        // Fallback: derive phone from flow token and resolve user_id from state table by phone.
        if ($seller_user_id <= 0) {
            $phone = CWSB_Utils::extract_phone_from_flow_token($flow_token);
            if ($phone !== '') {
                $seller_user_id = self::find_state_user_id_by_phone($phone);
            }
        }

        if ($seller_user_id <= 0) {
            CWSB_Cache::set($cache_key, []);
            return [];
        }

        $products = self::find_products_by_seller_user_id($seller_user_id);
        CWSB_Cache::set($cache_key, $products);
        return $products;
    }

    private static function find_products_by_seller_user_id($seller_user_id)
    {
        global $wpdb;

        $cache_key = self::product_list_user_cache_key($seller_user_id);
        $cache_hit = false;
        $cached = CWSB_Cache::get($cache_key, $cache_hit);
        if ($cache_hit) {
            return $cached;
        }

        $sql = "
            SELECT
                p.ID,
                p.post_title,
                                p.post_date,
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
            GROUP BY p.ID, p.post_title, p.post_date
            ORDER BY p.post_date DESC
            LIMIT %d
        ";

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                $sql,
                (int) $seller_user_id,
                (int) self::DEFAULT_PRODUCTS_LIMIT
            ),
            ARRAY_A
        );

        $products = [];
        foreach ((array) $rows as $row) {
            $is_variable = ((int) ($row['has_variations'] ?? 0)) === 1;
            $final_price = CWSB_Utils::to_money_string(
                (isset($row['sale_price']) && trim((string) $row['sale_price']) !== '')
                    ? $row['sale_price']
                    : ((isset($row['price']) && trim((string) $row['price']) !== '') ? $row['price'] : ($row['regular_price'] ?? ''))
            );
            $stock = CWSB_Utils::to_int_or_zero($row['stock'] ?? '');
            $created_at = !empty($row['post_date']) ? mysql2date('d/m/Y', $row['post_date']) : '';

            $image_src = '';
            $thumbnail_id = isset($row['thumbnail_id']) ? (int) $row['thumbnail_id'] : 0;
            if ($thumbnail_id > 0) {
                $image_src = (string) wp_get_attachment_image_url($thumbnail_id, 'full');
            }

            $products[] = [
                'id' => isset($row['ID']) ? (string) $row['ID'] : '',
                'name' => isset($row['post_title']) ? (string) $row['post_title'] : '',
                'type' => $is_variable ? 'variable' : 'simple',
                'sku' => isset($row['sku']) ? (string) $row['sku'] : '',
                'price' => $final_price,
                'stock' => $stock,
                'image_src' => $image_src,
                'image_gallery' => $image_src !== '' ? [$image_src] : [],
                'created_at' => $created_at,
                'short_description' => '',
                'full_description' => '',
                'categories' => [],
                'tags' => [],
                'general_price_euro' => $final_price,
                'general_price_tnd' => '',
                'promo_price_euro' => '',
                'promo_price_tnd' => '',
                'stock_quantity' => $stock,
                'manage_stock' => ((string) ($row['manage_stock'] ?? '')) === 'yes',
                'is_variable' => $is_variable,
            ];
        }

        CWSB_Cache::set($cache_key, $products);
        return $products;
    }

    /**
     * Returns one product by id (global lookup, no seller scope check).
     */
    public static function find_product_by_id($product_id)
    {
        $pid = (int) $product_id;
        if ($pid <= 0) {
            return null;
        }

        $cache_key = self::product_detail_cache_key($pid);
        $cache_hit = false;
        $cached = CWSB_Cache::get($cache_key, $cache_hit);
        if ($cache_hit) {
            return $cached;
        }

        $product = self::map_product_detail_by_sql($pid);
        CWSB_Cache::set($cache_key, $product);
        return $product;
    }

    /**
     * Returns one variation by product_id + variation_id (global lookup).
     */
    public static function find_variation_by_id($product_id, $variation_id)
    {
        $pid = (int) $product_id;
        $vid = (int) $variation_id;
        if ($pid <= 0 || $vid <= 0) {
            return null;
        }

        $variation_post = get_post($vid);
        if (!$variation_post || (int) $variation_post->post_parent !== $pid || $variation_post->post_type !== 'product_variation') {
            return null;
        }

        $parent_image_src = '';
        $parent_image_id = (int) get_post_meta($pid, '_thumbnail_id', true);
        if ($parent_image_id > 0) {
            $parent_image_src = (string) wp_get_attachment_image_url($parent_image_id, 'full');
        }

        return self::map_variation_by_post_id($vid, $parent_image_src);
    }

    private static function map_product_detail_by_sql($product_id)
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
            GROUP BY p.ID, p.post_title, p.post_excerpt, p.post_content, p.post_date
            LIMIT 1
        ";

        $row = $wpdb->get_row($wpdb->prepare($sql, $pid));
        if (!$row) {
            return null;
        }

        $image_src = '';
        $thumbnail_id = isset($row->thumbnail_id) ? (int) $row->thumbnail_id : 0;
        if ($thumbnail_id > 0) {
            $image_src = (string) wp_get_attachment_image_url($thumbnail_id, 'full');
        }
        $image_gallery = self::build_image_gallery_urls($image_src, (string) ($row->gallery_ids ?? ''));

        // Pass parent image to variation mapping so thumbnail-less variations still render.
        $variations = self::find_variations_for_product_sql($pid, $image_src);
        $is_variable = !empty($variations);

        $created_at = '';
        if (!empty($row->post_date)) {
            $created_at = mysql2date('d/m/Y', $row->post_date);
        }

        $categories = self::find_term_names_by_taxonomy_sql($pid, 'product_cat');
        $tags = self::find_term_names_by_taxonomy_sql($pid, 'product_tag');

        $result = [
            'id' => (string) $pid,
            'name' => (string) $row->post_title,
            'type' => $is_variable ? 'variable' : 'simple',
            'sku' => (string) ($row->sku ?? ''),
            'image_src' => $image_src,
            'image_gallery' => $image_gallery,
            'created_at' => $created_at,
            'short_description' => (string) ($row->post_excerpt ?? ''),
            'full_description' => (string) ($row->post_content ?? ''),
            'categories' => is_array($categories) ? array_values($categories) : [],
            'tags' => is_array($tags) ? array_values($tags) : [],
            'general_price_euro' => CWSB_Utils::to_money_string($row->regular_price ?? ''),
            'general_price_tnd' => CWSB_Utils::to_money_string($row->regular_price_tnd ?? ''),
            'promo_price_euro' => CWSB_Utils::to_money_string($row->sale_price ?? ''),
            'promo_price_tnd' => CWSB_Utils::to_money_string($row->sale_price_tnd ?? ''),
            'stock_quantity' => CWSB_Utils::to_int_or_zero($row->stock ?? ''),
            'manage_stock' => ((string) ($row->manage_stock ?? '')) === 'yes',
            'is_variable' => $is_variable,
        ];

        if ($is_variable) {
            $result['variations'] = $variations;
        }

        return $result;
    }

    private static function find_variations_for_product_sql($product_id, $parent_image_src = '')
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
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $variations = [];
        foreach ($rows as $row) {
            $variation_id = (int) ($row->ID ?? 0);
            if ($variation_id <= 0) {
                continue;
            }

            $image_src = '';
            $thumbnail_id = isset($row->thumbnail_id) ? (int) $row->thumbnail_id : 0;
            if ($thumbnail_id > 0) {
                $image_src = (string) wp_get_attachment_image_url($thumbnail_id, 'full');
            } elseif (is_string($parent_image_src) && $parent_image_src !== '') {
                // Fallback to parent product image when variation has no own thumbnail.
                $image_src = $parent_image_src;
            }

            $attributes = self::find_variation_attributes_sql($variation_id);
            if (!isset($attributes['weight']) && trim((string) ($row->weight ?? '')) !== '') {
                $attributes['weight'] = (string) $row->weight;
            }

            $variations[] = [
                'id' => (string) $variation_id,
                'sku' => (string) ($row->sku ?? ''),
                'title' => (string) ($row->post_title ?? ''),
                'stock' => CWSB_Utils::to_int_or_zero($row->stock ?? ''),
                'stock_status' => (string) ($row->stock_status ?? ''),
                'manage_stock' => ((string) ($row->manage_stock ?? '')) === 'yes',
                'attributes' => $attributes,
                'price_euro' => CWSB_Utils::to_money_string($row->price ?? ''),
                'price_tnd' => CWSB_Utils::to_money_string($row->price_tnd ?? ''),
                'image_src' => $image_src,
            ];
        }

        return $variations;
    }

    private static function find_variation_attributes_sql($variation_id)
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

    private static function find_term_names_by_taxonomy_sql($product_id, $taxonomy)
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

        $rows = $wpdb->get_col(
            $wpdb->prepare($sql, $pid, $tax)
        );

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

    private static function map_product($product_id)
    {
        return self::map_product_detail_by_sql((int) $product_id);
    }

    private static function map_post_product_fallback($product_id)
    {
        $post = get_post((int) $product_id);
        if (!$post || $post->post_type !== 'product') {
            return null;
        }

        $created_at = '';
        if (!empty($post->post_date)) {
            $created_at = mysql2date('d/m/Y', $post->post_date);
        }

        $regular_price = CWSB_Utils::to_money_string(get_post_meta($post->ID, '_regular_price', true));
        $sale_price = CWSB_Utils::to_money_string(get_post_meta($post->ID, '_sale_price', true));

        return [
            'id' => (string) $post->ID,
            'name' => (string) $post->post_title,
            'type' => 'simple',
            'sku' => (string) get_post_meta($post->ID, '_sku', true),
            'image_src' => '',
            'image_gallery' => [],
            'created_at' => $created_at,
            'short_description' => (string) $post->post_excerpt,
            'full_description' => (string) $post->post_content,
            'categories' => [],
            'tags' => [],
            'general_price_euro' => $regular_price,
            'general_price_tnd' => CWSB_Utils::to_money_string(get_post_meta($post->ID, '_regular_price_tnd', true)),
            'promo_price_euro' => $sale_price,
            'promo_price_tnd' => CWSB_Utils::to_money_string(get_post_meta($post->ID, '_sale_price_tnd', true)),
            'stock_quantity' => CWSB_Utils::to_int_or_zero(get_post_meta($post->ID, '_stock', true)),
            'manage_stock' => ((string) get_post_meta($post->ID, '_manage_stock', true)) === 'yes',
            'is_variable' => false,
        ];
    }

    private static function map_variation($variation, $parent_image_src = '')
    {
        $variation_id = 0;
        if (is_numeric($variation)) {
            $variation_id = (int) $variation;
        } elseif (is_object($variation) && method_exists($variation, 'get_id')) {
            $variation_id = (int) $variation->get_id();
        }

        return self::map_variation_by_post_id($variation_id, $parent_image_src);
    }

    private static function map_variation_by_post_id($variation_id, $parent_image_src = '')
    {
        $vid = (int) $variation_id;
        if ($vid <= 0) {
            return null;
        }

        $variation_post = get_post($vid);
        if (!$variation_post || $variation_post->post_type !== 'product_variation') {
            return null;
        }

        $image_src = '';
        $image_id = (int) get_post_meta($vid, '_thumbnail_id', true);
        if ($image_id > 0) {
            $image_src = (string) wp_get_attachment_image_url($image_id, 'full');
        } elseif (is_string($parent_image_src) && $parent_image_src !== '') {
            $image_src = $parent_image_src;
        }

        $attributes = self::find_variation_attributes_sql($vid);
        $weight = trim((string) get_post_meta($vid, '_weight', true));
        if (!isset($attributes['weight']) && $weight !== '') {
            $attributes['weight'] = $weight;
        }

        return [
            'id' => (string) $vid,
            'sku' => (string) get_post_meta($vid, '_sku', true),
            'title' => (string) $variation_post->post_title,
            'stock' => CWSB_Utils::to_int_or_zero(get_post_meta($vid, '_stock', true)),
            'stock_status' => (string) get_post_meta($vid, '_stock_status', true),
            'manage_stock' => ((string) get_post_meta($vid, '_manage_stock', true)) === 'yes',
            'attributes' => $attributes,
            'price_euro' => CWSB_Utils::to_money_string(get_post_meta($vid, '_price', true)),
            'price_tnd' => CWSB_Utils::to_money_string(get_post_meta($vid, '_price_tnd', true)),
            'image_src' => $image_src,
        ];
    }

    private static function build_image_gallery_urls($primary_image_src, $gallery_ids_csv)
    {
        $urls = [];

        $primary = trim((string) $primary_image_src);
        if ($primary !== '') {
            $urls[] = $primary;
        }

        $csv = trim((string) $gallery_ids_csv);
        if ($csv === '') {
            return array_values(array_unique($urls));
        }

        $ids = array_filter(array_map('intval', explode(',', $csv)));
        foreach ($ids as $id) {
            if ((int) $id <= 0) {
                continue;
            }
            $url = (string) wp_get_attachment_image_url((int) $id, 'full');
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return array_slice(array_values(array_unique($urls)), 0, (int) self::MAX_CAROUSEL_IMAGES);
    }

    private static function build_product_gallery_urls_from_meta($product_ref, $primary_image_src)
    {
        $product_id = 0;
        if (is_numeric($product_ref)) {
            $product_id = (int) $product_ref;
        } elseif (is_object($product_ref) && method_exists($product_ref, 'get_id')) {
            $product_id = (int) $product_ref->get_id();
        }

        $gallery_ids_csv = $product_id > 0 ? (string) get_post_meta($product_id, '_product_image_gallery', true) : '';
        return self::build_image_gallery_urls($primary_image_src, $gallery_ids_csv);
    }

    private static function find_state_user_id_by_flow_token($flow_token)
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

    private static function find_state_user_id_by_phone($phone)
    {
        global $wpdb;

        $normalized = CWSB_Utils::normalize_phone($phone);
        if ($normalized === '') {
            return 0;
        }

        $table = CWSB_Seller_Repository::state_table_name();
        // EXACT phone match only - no fallback/partial matching.
        $user_id = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT user_id FROM {$table} WHERE phone = %s LIMIT 1", $normalized)
        );

        return $user_id;
    }
}
