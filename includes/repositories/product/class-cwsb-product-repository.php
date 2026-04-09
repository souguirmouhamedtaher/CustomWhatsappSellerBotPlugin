<?php

if (!defined('ABSPATH')) {
    exit;
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

    public static function invalidate_cached_lists_for_seller_refs($seller_user_id = 0, $phones = [], $flow_tokens = [])
    {
    }

    public static function find_products_by_seller_phone($phone)
    {
        $seller_user_id = CWSB_Product_Resolver::find_state_user_id_by_phone($phone);
        if ($seller_user_id <= 0) {
            return [];
        }

        return self::find_products_by_seller_user_id($seller_user_id);
    }

    public static function find_products_by_seller_flow_token($flow_token)
    {
        $seller_user_id = CWSB_Product_Resolver::find_state_user_id_by_flow_token($flow_token);

        if ($seller_user_id <= 0) {
            $phone = CWSB_Utils::extract_phone_from_flow_token($flow_token);
            if ($phone !== '') {
                $seller_user_id = CWSB_Product_Resolver::find_state_user_id_by_phone($phone);
            }
        }

        if ($seller_user_id <= 0) {
            return [];
        }

        return self::find_products_by_seller_user_id($seller_user_id);
    }

    public static function find_product_by_id($product_id)
    {
        $pid = (int) $product_id;
        if ($pid <= 0) {
            return null;
        }

        return CWSB_Product_Mapper::map_product_detail_by_sql($pid, self::MAX_CAROUSEL_IMAGES);
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
        $rows = CWSB_Product_Queries::find_products_rows_by_seller_user_id((int) $seller_user_id, (int) self::DEFAULT_PRODUCTS_LIMIT);

        $products = [];
        foreach ((array) $rows as $row) {
            $products[] = CWSB_Product_Mapper::map_list_row($row);
        }

        return $products;
    }
}
