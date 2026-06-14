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
    /**
     * Returns paginated seller products for TND-oriented listing endpoints.
     *
     * The method reads identity and paging parameters from the WordPress REST request, resolves
     * products through repository methods (phone or flow-token path), then performs in-memory page
     * slicing and returns pagination metadata in a normalized response payload.
     */
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

    /**
     * Returns paginated seller products for XOF-oriented listing endpoints.
     *
     * This method mirrors the TND list flow but delegates to XOF repository read paths so price
     * mapping reflects XOF fields while preserving pagination and response shape parity.
     */
    public static function get_seller_products_by_flow_token_xof(WP_REST_Request $request)
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
            ? CWSB_Product_Repository::find_products_by_seller_phone_xof($phone)
            : CWSB_Product_Repository::find_products_by_seller_flow_token_xof($flow_token);

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

    /**
     * Returns a single product detail payload by product ID (TND-oriented read path).
     *
     * The endpoint validates required product identity and delegates full product assembly to
     * repository/mapping layers before returning standardized response envelopes.
     */
    public static function get_seller_product_by_id(WP_REST_Request $request)
    {
        $product_id = (string) $request->get_param('product_id');

        if (trim($product_id) === '') {
            return CWSB_Response::error('invalid_request', 'product_id is required.', 422);
        }

        $product = CWSB_Product_Repository::find_product_by_id($product_id);
        return CWSB_Response::ok(['product' => $product ?: null]);
    }

    /**
     * Returns a single product detail payload by product ID (XOF-oriented read path).
     *
     * The method validates request input and delegates to XOF-aware repository mapping so
     * consumers receive XOF pricing fields in the detail payload.
     */
    public static function get_seller_product_by_id_xof(WP_REST_Request $request)
    {
        $product_id = (string) $request->get_param('product_id');

        if (trim($product_id) === '') {
            return CWSB_Response::error('invalid_request', 'product_id is required.', 422);
        }

        $product = CWSB_Product_Repository::find_product_by_id_xof($product_id);
        return CWSB_Response::ok(['product' => $product ?: null]);
    }

    /**
     * Returns variation detail payload for a product variation (TND-oriented read path).
     *
     * It validates both product and variation identifiers from `WP_REST_Request` and relies on
     * repository checks to ensure variation-parent consistency before responding.
     */
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

    /**
     * Returns variation detail payload for a product variation (XOF-oriented read path).
     *
     * The method mirrors the TND variation flow but delegates to XOF-aware repository mapping so
     * variation pricing values are read from XOF meta keys.
     */
    public static function get_seller_product_variation_by_id_xof(WP_REST_Request $request)
    {
        $product_id = (string) $request->get_param('product_id');
        $variation_id = (string) $request->get_param('variation_id');

        if (trim($product_id) === '' || trim($variation_id) === '') {
            return CWSB_Response::error('invalid_request', 'product_id and variation_id are required.', 422);
        }

        $variation = CWSB_Product_Repository::find_variation_by_id_xof($product_id, $variation_id);
        return CWSB_Response::ok(['variation' => $variation ?: null]);
    }
}
