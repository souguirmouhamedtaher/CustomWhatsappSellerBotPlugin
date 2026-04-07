<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Response')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-response.php';
}

if (!class_exists('CWSB_Product_Repository')) {
    require_once __DIR__ . '/../../repositories/product/class-cwsb-product-repository.php';
}

/**
 * Product-related endpoint handlers.
 */
class CWSB_Auth_Product_Endpoints_Service
{
    public static function get_seller_products_by_flow_token(WP_REST_Request $request)
    {
        $flow_token = (string) $request->get_param('flow_token');
        $phone = (string) $request->get_param('phone');
        $page = max(1, (int) $request->get_param('page'));
        $per_page = (int) $request->get_param('per_page');
        if ($per_page <= 0) {
            $per_page = 5;
        }
        $per_page = min($per_page, 50);

        if (trim($flow_token) === '' && trim($phone) === '') {
            return CWSB_Response::error('invalid_request', 'phone or flow_token is required.', 422);
        }

        $products = trim($phone) !== ''
            ? CWSB_Product_Repository::find_products_by_seller_phone($phone)
            : CWSB_Product_Repository::find_products_by_seller_flow_token($flow_token);

        $total = is_array($products) ? count($products) : 0;
        $offset = ($page - 1) * $per_page;
        $paged_products = is_array($products) ? array_slice($products, $offset, $per_page) : [];
        $has_more = ($offset + count($paged_products)) < $total;

        return CWSB_Response::ok([
            'count' => is_array($paged_products) ? count($paged_products) : 0,
            'total' => (int) $total,
            'page' => (int) $page,
            'per_page' => (int) $per_page,
            'has_more' => (bool) $has_more,
            'next_page' => $has_more ? (int) ($page + 1) : null,
            'products' => is_array($paged_products) ? $paged_products : [],
        ]);
    }

    public static function get_seller_product_by_id(WP_REST_Request $request)
    {
        $product_id = (string) $request->get_param('product_id');

        if (trim($product_id) === '') {
            return CWSB_Response::error('invalid_request', 'product_id is required.', 422);
        }

        $product = CWSB_Product_Repository::find_product_by_id($product_id);
        return CWSB_Response::ok(['product' => $product ?: null]);
    }

    public static function get_seller_product_variation_by_id(WP_REST_Request $request)
    {
        $product_id = (string) $request->get_param('product_id');
        $variation_id = (string) $request->get_param('variation_id');

        if (trim($product_id) === '' || trim($variation_id) === '') {
            return CWSB_Response::error('invalid_request', 'product_id and variation_id are required.', 422);
        }

        $variation = CWSB_Product_Repository::find_variation_by_id($product_id, $variation_id);
        return CWSB_Response::ok(['variation' => $variation ?: null]);
    }
}
