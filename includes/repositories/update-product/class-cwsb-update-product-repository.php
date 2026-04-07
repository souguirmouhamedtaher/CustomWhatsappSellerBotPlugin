<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Update_Product_Queries')) {
    require_once __DIR__ . '/class-cwsb-update-product-queries.php';
}

if (!class_exists('CWSB_Update_Product_Writer')) {
    require_once __DIR__ . '/class-cwsb-update-product-writer.php';
}

/**
 * Update-product repository facade.
 *
 * Public API is preserved while implementation is split into:
 * - CWSB_Update_Product_Queries
 * - CWSB_Update_Product_Writer
 */
class CWSB_Update_Product_Repository
{
    public static function find_products_paged($seller_user_id, $page, $limit)
    {
        return CWSB_Update_Product_Queries::find_products_paged($seller_user_id, $page, $limit);
    }

    public static function find_product_photos($product_id, $seller_user_id)
    {
        return CWSB_Update_Product_Queries::find_product_photos($product_id, $seller_user_id);
    }

    public static function find_product_edit_info($product_id, $seller_user_id)
    {
        return CWSB_Update_Product_Queries::find_product_edit_info($product_id, $seller_user_id);
    }

    public static function find_product_category_info($product_id, $seller_user_id)
    {
        return CWSB_Update_Product_Queries::find_product_category_info($product_id, $seller_user_id);
    }

    public static function update_product($product_id, $seller_user_id, $data)
    {
        return CWSB_Update_Product_Writer::update_product($product_id, $seller_user_id, $data);
    }
}
