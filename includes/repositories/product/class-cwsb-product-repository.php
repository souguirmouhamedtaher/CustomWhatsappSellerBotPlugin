<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Cache')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-cache.php';
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

if (!class_exists('CWSB_Product_Queries')) {
    require_once __DIR__ . '/class-cwsb-product-queries.php';
}

if (!class_exists('CWSB_Product_Mapper')) {
    require_once __DIR__ . '/class-cwsb-product-mapper.php';
}

if (!class_exists('CWSB_Product_Resolver')) {
    require_once __DIR__ . '/class-cwsb-product-resolver.php';
}

/**
 * Product repository facade.
 *
 * Public API remains stable while heavy logic is split into:
 * - CWSB_Product_Queries
 * - CWSB_Product_Mapper
 * - CWSB_Product_Resolver
 */
class CWSB_Product_Repository
{
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

    public static function find_products_by_seller_phone($phone)
    {
        $cache_key = self::product_list_phone_cache_key($phone);
        $cache_hit = false;
        $cached = CWSB_Cache::get($cache_key, $cache_hit);
        if ($cache_hit) {
            return $cached;
        }

        $seller_user_id = CWSB_Product_Resolver::find_state_user_id_by_phone($phone);
        if ($seller_user_id <= 0) {
            CWSB_Cache::set($cache_key, []);
            return [];
        }

        $products = self::find_products_by_seller_user_id($seller_user_id);
        CWSB_Cache::set($cache_key, $products);
        return $products;
    }

    public static function find_products_by_seller_flow_token($flow_token)
    {
        $cache_key = self::product_list_flow_cache_key($flow_token);
        $cache_hit = false;
        $cached = CWSB_Cache::get($cache_key, $cache_hit);
        if ($cache_hit) {
            return $cached;
        }

        $seller_user_id = CWSB_Product_Resolver::find_state_user_id_by_flow_token($flow_token);

        if ($seller_user_id <= 0) {
            $phone = CWSB_Utils::extract_phone_from_flow_token($flow_token);
            if ($phone !== '') {
                $seller_user_id = CWSB_Product_Resolver::find_state_user_id_by_phone($phone);
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

        $product = CWSB_Product_Mapper::map_product_detail_by_sql($pid, self::MAX_CAROUSEL_IMAGES);
        CWSB_Cache::set($cache_key, $product);
        return $product;
    }

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

        return CWSB_Product_Mapper::map_variation_by_post_id($vid, $parent_image_src);
    }

    private static function find_products_by_seller_user_id($seller_user_id)
    {
        $cache_key = self::product_list_user_cache_key($seller_user_id);
        $cache_hit = false;
        $cached = CWSB_Cache::get($cache_key, $cache_hit);
        if ($cache_hit) {
            return $cached;
        }

        // Suspend WordPress object cache during large result set to prevent auto-cache bloat.
        wp_suspend_cache_addition(true);
        $rows = CWSB_Product_Queries::find_products_rows_by_seller_user_id((int) $seller_user_id, (int) self::DEFAULT_PRODUCTS_LIMIT);
        wp_suspend_cache_addition(false);

        $products = [];
        foreach ((array) $rows as $row) {
            $products[] = CWSB_Product_Mapper::map_list_row($row);
        }

        CWSB_Cache::set($cache_key, $products);
        return $products;
    }
}
