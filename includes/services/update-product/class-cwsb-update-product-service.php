<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Update_Product_Repository')) {
    require_once __DIR__ . '/../../repositories/update-product/class-cwsb-update-product-repository.php';
}

if (!class_exists('CWSB_Seller_Read_Repository')) {
    require_once __DIR__ . '/../../repositories/seller/class-cwsb-seller-read-repository.php';
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

/**
 * Business logic layer for the update-product WhatsApp flow.
 */
class CWSB_Update_Product_Service
{
    // -------------------------------------------------------------------------
    // EP1 — Product list (paginated)
    // -------------------------------------------------------------------------

    /**
     * @param string $flow_token
     * @param int    $page   1-based page number.
     * @param int    $limit  Items per page.
     * @return array { ok, data|code, message }
     */
    public static function get_products_paged($flow_token, $page, $limit)
    {
        $seller_user_id = self::resolve_seller_user_id($flow_token);
        if (!$seller_user_id) {
            return self::seller_not_found();
        }

        $data = CWSB_Update_Product_Repository::find_products_paged($seller_user_id, $page, $limit);
        return ['ok' => true, 'data' => $data];
    }

    // -------------------------------------------------------------------------
    // EP2 — Photos screen
    // -------------------------------------------------------------------------

    /**
     * @param string $flow_token
     * @param int    $product_id
     * @return array { ok, data|code, message }
     */
    public static function get_product_photos($flow_token, $product_id)
    {
        $seller_user_id = self::resolve_seller_user_id($flow_token);
        if (!$seller_user_id) {
            return self::seller_not_found();
        }

        $data = CWSB_Update_Product_Repository::find_product_photos((int) $product_id, $seller_user_id);
        if (!$data) {
            return self::product_not_found();
        }
        return ['ok' => true, 'data' => $data];
    }

    // -------------------------------------------------------------------------
    // EP3 — Edit-info screen
    // -------------------------------------------------------------------------

    /**
     * @param string $flow_token
     * @param int    $product_id
     * @return array { ok, data|code, message }
     */
    public static function get_product_edit_info($flow_token, $product_id)
    {
        $seller_user_id = self::resolve_seller_user_id($flow_token);
        if (!$seller_user_id) {
            return self::seller_not_found();
        }

        $data = CWSB_Update_Product_Repository::find_product_edit_info((int) $product_id, $seller_user_id);
        if (!$data) {
            return self::product_not_found();
        }
        return ['ok' => true, 'data' => $data];
    }

    // -------------------------------------------------------------------------
    // EP4 — Category-info screen
    // -------------------------------------------------------------------------

    /**
     * @param string $flow_token
     * @param int    $product_id
     * @return array { ok, data|code, message }
     */
    public static function get_product_category_info($flow_token, $product_id)
    {
        $seller_user_id = self::resolve_seller_user_id($flow_token);
        if (!$seller_user_id) {
            return self::seller_not_found();
        }

        $data = CWSB_Update_Product_Repository::find_product_category_info((int) $product_id, $seller_user_id);
        if (!$data) {
            return self::product_not_found();
        }
        return ['ok' => true, 'data' => $data];
    }

    // -------------------------------------------------------------------------
    // EP5 — Apply update
    // -------------------------------------------------------------------------

    /**
     * @param string $flow_token
     * @param int    $product_id
     * @param array  $data        Fields to update.
     * @return array { ok, data|code, message }
     */
    public static function update_product($flow_token, $product_id, $data)
    {
        $seller_user_id = self::resolve_seller_user_id($flow_token);
        if (!$seller_user_id) {
            return self::seller_not_found();
        }

        $ok = CWSB_Update_Product_Repository::update_product((int) $product_id, $seller_user_id, $data);
        if (!$ok) {
            return ['ok' => false, 'code' => 'update_failed', 'message' => 'Product not found, not owned, or update failed.'];
        }

        return ['ok' => true, 'data' => ['product_id' => (int) $product_id, 'updated' => true]];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function resolve_seller_user_id($flow_token)
    {
        if (!class_exists('CWSB_Seller_Read_Repository')) {
            return 0;
        }
        return (int) CWSB_Seller_Read_Repository::find_user_id_by_flow_token($flow_token);
    }

    private static function seller_not_found()
    {
        return ['ok' => false, 'code' => 'seller_not_found', 'message' => 'Seller not found for the given flow token.'];
    }

    private static function product_not_found()
    {
        return ['ok' => false, 'code' => 'product_not_found', 'message' => 'Product not found or access denied.'];
    }
}
