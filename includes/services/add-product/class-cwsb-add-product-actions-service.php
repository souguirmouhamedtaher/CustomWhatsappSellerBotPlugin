<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Response')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-response.php';
}

if (!class_exists('CWSB_Auth_Middleware')) {
    require_once __DIR__ . '/../../middleware/class-cwsb-auth-middleware.php';
}

if (!class_exists('CWSB_Seller_Repository')) {
    require_once __DIR__ . '/../../repositories/seller/class-cwsb-seller-repository.php';
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

if (!class_exists('CWSB_Add_Product_Support_Service')) {
    require_once __DIR__ . '/class-cwsb-add-product-support-service.php';
}

/**
 * Dedicated controller for add-product flow endpoints.
 * Uses WooCommerce CRUD to ensure writes are identical to normal WP/WC product creation.
 */
class CWSB_Add_Product_Actions_Service
{
    public static function register_routes()
    {
        register_rest_route(CWSB_NS, '/seller/product/categories/list', [
            'methods' => 'POST',
            'callback' => [self::class, 'list_product_categories'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'include_empty' => ['required' => false],
                'parent_only' => ['required' => false],
                'limit' => ['required' => false],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/product/subcategories/list', [
            'methods' => 'POST',
            'callback' => [self::class, 'list_product_subcategories'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'category_id' => ['required' => true],
                'include_empty' => ['required' => false],
                'limit' => ['required' => false],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/pricing/convert', [
            'methods' => 'POST',
            'callback' => [self::class, 'convert_tnd_prices'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'regular_tnd' => ['required' => false],
                'promo_tnd' => ['required' => false],
            ],
        ]);

        register_rest_route(CWSB_NS, '/seller/product/create/by-flow-token', [
            'methods' => 'POST',
            'callback' => [self::class, 'create_product_by_flow_token'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
                'product' => ['required' => true],
                'idempotency_key' => ['required' => false],
            ],
        ]);
    }

    public static function list_product_categories(WP_REST_Request $request)
    {
        $include_empty_param = $request->get_param('include_empty');
            $include_empty = $include_empty_param === null ? true : CWSB_Utils::to_bool($include_empty_param);
        $parent_only = $request->get_param('parent_only');
        $parent_only = $parent_only === null ? true : CWSB_Utils::to_bool($parent_only);
        $limit = (int) $request->get_param('limit');
        if ($limit <= 0) {
            $limit = 60;
        }
        $limit = min($limit, 120);

        $query_args = [
            'taxonomy' => 'product_cat',
            'hide_empty' => !$include_empty,
            'orderby' => 'name',
            'order' => 'ASC',
            'number' => $limit,
        ];

        if ($parent_only) {
            $query_args['parent'] = 0;
        }

        $terms = get_terms($query_args);

        if (is_wp_error($terms)) {
            return CWSB_Response::error('category_fetch_failed', 'Unable to list product categories.', 500, [
                'wp_error' => $terms->get_error_message(),
            ]);
        }

        $categories = [];
        foreach ((array) $terms as $term) {
            if (!($term instanceof WP_Term)) {
                continue;
            }
            $categories[] = [
                'id' => (string) $term->slug,
                'title' => (string) $term->name,
                'term_id' => (int) $term->term_id,
                'count' => (int) $term->count,
            ];
        }

        return CWSB_Response::ok([
            'count' => count($categories),
            'categories' => $categories,
        ]);
    }

    public static function list_product_subcategories(WP_REST_Request $request)
    {
        $category_id = CWSB_Utils::normalize_text($request->get_param('category_id'));
        if ($category_id === '') {
            return CWSB_Response::error('invalid_request', 'category_id is required.', 422);
        }

        $parent_term = self::resolve_product_category_term($category_id, false);
        if (!($parent_term instanceof WP_Term)) {
            return CWSB_Response::ok([
                'count' => 0,
                'subcategories' => [],
            ]);
        }

        $include_empty_param = $request->get_param('include_empty');
            $include_empty = $include_empty_param === null ? true : CWSB_Utils::to_bool($include_empty_param);
        $limit = (int) $request->get_param('limit');
        if ($limit <= 0) {
            $limit = 60;
        }
        $limit = min($limit, 120);

        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => !$include_empty,
            'orderby' => 'name',
            'order' => 'ASC',
            'number' => $limit,
            'parent' => (int) $parent_term->term_id,
        ]);

        if (is_wp_error($terms)) {
            return CWSB_Response::error('subcategory_fetch_failed', 'Unable to list product subcategories.', 500, [
                'wp_error' => $terms->get_error_message(),
            ]);
        }

        $subcategories = [];
        foreach ((array) $terms as $term) {
            if (!($term instanceof WP_Term)) {
                continue;
            }

            $subcategories[] = [
                'id' => (string) $term->slug,
                'title' => (string) $term->name,
                'description' => (string) $parent_term->name . ' > ' . (string) $term->name,
                'parentId' => (string) $parent_term->slug,
                'term_id' => (int) $term->term_id,
                'count' => (int) $term->count,
            ];
        }

        return CWSB_Response::ok([
            'count' => count($subcategories),
            'subcategories' => $subcategories,
        ]);
    }

    public static function convert_tnd_prices(WP_REST_Request $request)
    {
        $regular_tnd = CWSB_Add_Product_Support_Service::to_positive_float($request->get_param('regular_tnd'));
        $promo_tnd = CWSB_Add_Product_Support_Service::to_positive_float($request->get_param('promo_tnd'));
        $config = CWSB_Add_Product_Support_Service::get_pricing_config();

        $live = CWSB_Add_Product_Support_Service::convert_tnd_to_eur_live($regular_tnd, $promo_tnd, $config);

        if ($live !== null) {
            return CWSB_Response::ok([
                'regular_eur' => $live['regular_eur'],
                'promo_eur' => $live['promo_eur'],
                'config' => array_merge($config, [
                    'provider' => 'woocommerce-multi-currency',
                    'live_rate' => isset($live['rate']) ? (float) $live['rate'] : 0,
                    'target_currency' => 'EUR',
                ]),
            ]);
        }

        $regular_eur = CWSB_Add_Product_Support_Service::convert_tnd_to_eur($regular_tnd, $config);
        $promo_eur = CWSB_Add_Product_Support_Service::convert_tnd_to_eur($promo_tnd, $config);

        return CWSB_Response::ok([
            'regular_eur' => $regular_eur,
            'promo_eur' => $promo_eur,
            'config' => array_merge($config, [
                'provider' => 'local-fallback',
            ]),
        ]);
    }

    public static function create_product_by_flow_token(WP_REST_Request $request)
    {
        $flow_token = CWSB_Utils::normalize_text($request->get_param('flow_token'));
        $raw_product = $request->get_param('product');
        $product = is_array($raw_product) ? $raw_product : [];
        $idempotency_key = CWSB_Utils::normalize_text($request->get_param('idempotency_key'));

        if ($flow_token === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        $name = CWSB_Utils::normalize_text(isset($product['name']) ? $product['name'] : '');
        if ($name === '') {
            return CWSB_Response::error('invalid_request', 'product.name is required.', 422);
        }

        $validation = CWSB_Add_Product_Support_Service::validate_create_payload($product);
        if (!empty($validation)) {
            return CWSB_Response::error('validation_error', 'Invalid product payload.', 422, [
                'fields' => $validation,
            ]);
        }

        $seller = CWSB_Seller_Repository::find_vendor_by_flow_token($flow_token);
        if (!$seller) {
            $phone = CWSB_Utils::extract_phone_from_flow_token($flow_token);
            if ($phone !== '') {
                $seller = CWSB_Seller_Repository::find_vendor_by_phone($phone);
            }
        }
        $seller_user_id = isset($seller['user_id']) ? (int) $seller['user_id'] : 0;
        if ($seller_user_id <= 0) {
            return CWSB_Response::error('seller_not_found', 'No seller found for this flow_token.', 404);
        }

        $pricing = isset($product['pricing']) && is_array($product['pricing']) ? $product['pricing'] : [];
        $regular_tnd_num = CWSB_Add_Product_Support_Service::to_positive_float(
            isset($pricing['regular_tnd']) ? $pricing['regular_tnd'] :
            (isset($pricing['prix_regulier_tnd']) ? $pricing['prix_regulier_tnd'] :
            (isset($product['prix_regulier_tnd']) ? $product['prix_regulier_tnd'] : null))
        );
        $promo_tnd_num = CWSB_Add_Product_Support_Service::to_positive_float(
            isset($pricing['promo_tnd']) ? $pricing['promo_tnd'] :
            (isset($pricing['prix_promo_tnd']) ? $pricing['prix_promo_tnd'] :
            (isset($product['prix_promo_tnd']) ? $product['prix_promo_tnd'] : null))
        );
        $regular_eur_num = CWSB_Add_Product_Support_Service::to_positive_float(
            isset($pricing['regular_eur']) ? $pricing['regular_eur'] :
            (isset($pricing['prix_regulier_eur']) ? $pricing['prix_regulier_eur'] :
            (isset($product['prix_regulier_eur']) ? $product['prix_regulier_eur'] : null))
        );
        $promo_eur_num = CWSB_Add_Product_Support_Service::to_positive_float(
            isset($pricing['promo_eur']) ? $pricing['promo_eur'] :
            (isset($pricing['prix_promo_eur']) ? $pricing['prix_promo_eur'] :
            (isset($product['prix_promo_eur']) ? $product['prix_promo_eur'] : null))
        );

        if (($regular_tnd_num > 0 && $promo_tnd_num > 0 && $promo_tnd_num >= $regular_tnd_num)
            || ($regular_eur_num > 0 && $promo_eur_num > 0 && $promo_eur_num >= $regular_eur_num)) {
            return CWSB_Response::error('invalid_pricing', 'Promo price must be strictly lower than regular price.', 422, [
                'regular_tnd' => $regular_tnd_num,
                'promo_tnd' => $promo_tnd_num,
                'regular_eur' => $regular_eur_num,
                'promo_eur' => $promo_eur_num,
            ]);
        }

        if ($idempotency_key !== '') {
            $existing_product_id = CWSB_Add_Product_Support_Service::find_product_id_by_idempotency_key($idempotency_key);
            if ($existing_product_id > 0) {
                return CWSB_Response::ok([
                    'product_id' => (string) $existing_product_id,
                    'created' => false,
                    'status' => 'existing',
                ], 200);
            }
        }

        try {
            $status = CWSB_Add_Product_Support_Service::normalize_status(isset($product['status']) ? $product['status'] : 'draft');
            $sku = CWSB_Utils::normalize_text(isset($product['sku']) ? $product['sku'] : '');
            $auto_generate_sku = isset($product['auto_generate_sku']) && $product['auto_generate_sku'] === true;
            $sku_prefix = CWSB_Utils::normalize_text(isset($product['sku_prefix']) ? $product['sku_prefix'] : 'GEN');
            $short_description = CWSB_Utils::normalize_text(isset($product['short_description']) ? $product['short_description'] : '');
            $description = CWSB_Utils::normalize_text(isset($product['description']) ? $product['description'] : '');

            $postarr = [
                'post_type' => 'product',
                'post_status' => $status,
                'post_title' => $name,
                'post_content' => $description,
                'post_excerpt' => $short_description,
                'post_author' => $seller_user_id,
            ];

            $product_id = wp_insert_post($postarr, true);
            if (is_wp_error($product_id) || (int) $product_id <= 0) {
                return CWSB_Response::error('create_failed', 'Unable to create product post.', 500, [
                    'wp_error' => is_wp_error($product_id) ? $product_id->get_error_message() : '',
                ]);
            }

            $product_id = (int) $product_id;

            // Manual product post/meta mapping using WordPress core writes only.
            wp_set_object_terms($product_id, 'simple', 'product_type');

            // Auto-generate SKU if requested
            if ($auto_generate_sku && $sku === '') {
                $sku = CWSB_Add_Product_Support_Service::generate_auto_sku($sku_prefix, $seller_user_id);
            }

            if ($sku !== '') {
                update_post_meta($product_id, '_sku', $sku);
            }

            $regular_eur = CWSB_Add_Product_Support_Service::to_price_string(
                isset($pricing['regular_eur']) ? $pricing['regular_eur'] :
                (isset($pricing['prix_regulier_eur']) ? $pricing['prix_regulier_eur'] :
                (isset($product['prix_regulier_eur']) ? $product['prix_regulier_eur'] : null))
            );
            $promo_eur = CWSB_Add_Product_Support_Service::to_price_string(
                isset($pricing['promo_eur']) ? $pricing['promo_eur'] :
                (isset($pricing['prix_promo_eur']) ? $pricing['prix_promo_eur'] :
                (isset($product['prix_promo_eur']) ? $product['prix_promo_eur'] : null))
            );
            if ($regular_eur !== '') {
                update_post_meta($product_id, '_regular_price', $regular_eur);
            }
            if ($promo_eur !== '' && (float) $promo_eur > 0) {
                update_post_meta($product_id, '_sale_price', $promo_eur);
                update_post_meta($product_id, '_price', $promo_eur);
            } elseif ($regular_eur !== '') {
                update_post_meta($product_id, '_price', $regular_eur);
            }

            $quantity = isset($product['quantity']) ? (int) $product['quantity'] : (isset($product['quantite']) ? (int) $product['quantite'] : 0);
            if ($quantity > 0) {
                update_post_meta($product_id, '_manage_stock', 'yes');
                update_post_meta($product_id, '_stock', (string) $quantity);
                update_post_meta($product_id, '_stock_status', 'instock');
            } else {
                update_post_meta($product_id, '_manage_stock', 'yes');
                update_post_meta($product_id, '_stock', '0');
                update_post_meta($product_id, '_stock_status', 'outofstock');
            }

            $dimensions = isset($product['dimensions']) && is_array($product['dimensions']) ? $product['dimensions'] : [];
            $length = isset($dimensions['longueur']) ? CWSB_Add_Product_Support_Service::to_dimension_string($dimensions['longueur']) : (isset($dimensions['length']) ? CWSB_Add_Product_Support_Service::to_dimension_string($dimensions['length']) : '');
            $width = isset($dimensions['largeur']) ? CWSB_Add_Product_Support_Service::to_dimension_string($dimensions['largeur']) : (isset($dimensions['width']) ? CWSB_Add_Product_Support_Service::to_dimension_string($dimensions['width']) : '');
            $height = isset($dimensions['profondeur']) ? CWSB_Add_Product_Support_Service::to_dimension_string($dimensions['profondeur']) : (isset($dimensions['height']) ? CWSB_Add_Product_Support_Service::to_dimension_string($dimensions['height']) : '');
            $dimension_unit = CWSB_Utils::normalize_text(isset($dimensions['unit']) ? $dimensions['unit'] : (isset($dimensions['unite']) ? $dimensions['unite'] : 'cm'));
            update_post_meta($product_id, '_length', $length);
            update_post_meta($product_id, '_width', $width);
            update_post_meta($product_id, '_height', $height);
            update_post_meta($product_id, '_cwsb_dim_unit', $dimension_unit !== '' ? $dimension_unit : 'cm');

            $weight = isset($product['weight']) && is_array($product['weight']) ? $product['weight'] : [];
            $weight_value = isset($weight['value']) ? CWSB_Add_Product_Support_Service::to_dimension_string($weight['value']) : '';
            $weight_unit = CWSB_Utils::normalize_text(isset($weight['unit']) ? $weight['unit'] : (isset($weight['unite']) ? $weight['unite'] : 'kg'));
            update_post_meta($product_id, '_weight', $weight_value);
            update_post_meta($product_id, '_cwsb_weight_unit', $weight_unit !== '' ? $weight_unit : 'kg');

            $category_id = CWSB_Utils::normalize_text(isset($product['category_id']) ? $product['category_id'] : '');
            $subcategory_id = CWSB_Utils::normalize_text(isset($product['subcategory_id']) ? $product['subcategory_id'] : '');
            $category_term_ids = CWSB_Add_Product_Support_Service::resolve_product_category_term_ids($category_id, $subcategory_id);
            if (empty($category_term_ids)) {
                wp_delete_post($product_id, true);
                return CWSB_Response::error('invalid_category', 'Unable to resolve category/subcategory mapping.', 422);
            }
            wp_set_object_terms($product_id, array_map('intval', $category_term_ids), 'product_cat');
            $term_taxonomy_ids = wp_get_object_terms($product_id, 'product_cat', ['fields' => 'tt_ids']);
            if (!is_wp_error($term_taxonomy_ids) && !empty($term_taxonomy_ids)) {
                wp_update_term_count_now(array_map('intval', $term_taxonomy_ids), 'product_cat');
            }
            clean_term_cache(array_map('intval', $category_term_ids), 'product_cat');

            if ($idempotency_key !== '') {
                update_post_meta($product_id, '_cwsb_idempotency_key', $idempotency_key);
            }

            $regular_tnd = CWSB_Add_Product_Support_Service::to_price_string(
                isset($pricing['regular_tnd']) ? $pricing['regular_tnd'] :
                (isset($pricing['prix_regulier_tnd']) ? $pricing['prix_regulier_tnd'] :
                (isset($product['prix_regulier_tnd']) ? $product['prix_regulier_tnd'] : null))
            );
            $promo_tnd = CWSB_Add_Product_Support_Service::to_price_string(
                isset($pricing['promo_tnd']) ? $pricing['promo_tnd'] :
                (isset($pricing['prix_promo_tnd']) ? $pricing['prix_promo_tnd'] :
                (isset($product['prix_promo_tnd']) ? $product['prix_promo_tnd'] : null))
            );
            if ($regular_tnd !== '') {
                update_post_meta($product_id, '_regular_price_tnd', $regular_tnd);
                update_post_meta($product_id, '_price_tnd', $promo_tnd !== '' ? $promo_tnd : $regular_tnd);
            }
            if ($promo_tnd !== '') {
                update_post_meta($product_id, '_sale_price_tnd', $promo_tnd);
            }

            $attributes = isset($product['attributes']) && is_array($product['attributes']) ? $product['attributes'] : [];
            $color = CWSB_Utils::normalize_text(isset($attributes['couleur']) ? $attributes['couleur'] : (isset($attributes['color']) ? $attributes['color'] : ''));
            $size = CWSB_Utils::normalize_text(isset($attributes['taille']) ? $attributes['taille'] : (isset($attributes['size']) ? $attributes['size'] : ''));
            update_post_meta($product_id, '_cwsb_color', $color);
            update_post_meta($product_id, '_cwsb_size', $size);

            $product_attributes = [];
            if ($color !== '') {
                $product_attributes['cwsb_color'] = [
                    'name' => 'Couleur',
                    'value' => $color,
                    'position' => 0,
                    'is_visible' => 1,
                    'is_variation' => 0,
                    'is_taxonomy' => 0,
                ];
            }
            if ($size !== '') {
                $product_attributes['cwsb_size'] = [
                    'name' => 'Taille',
                    'value' => $size,
                    'position' => 1,
                    'is_visible' => 1,
                    'is_variation' => 0,
                    'is_taxonomy' => 0,
                ];
            }
            if (!empty($product_attributes)) {
                update_post_meta($product_id, '_product_attributes', $product_attributes);
            }

            $category_label = CWSB_Utils::normalize_text(isset($product['category_label']) ? $product['category_label'] : '');
            $subcategory_label = CWSB_Utils::normalize_text(isset($product['subcategory_label']) ? $product['subcategory_label'] : '');
            if ($category_label !== '') {
                update_post_meta($product_id, '_cwsb_category_label', $category_label);
            }
            if ($subcategory_label !== '') {
                update_post_meta($product_id, '_cwsb_subcategory_label', $subcategory_label);
            }

            $image_ids = CWSB_Add_Product_Support_Service::save_images_for_product($product_id, isset($product['images_base64']) ? $product['images_base64'] : []);
            if (!empty($image_ids)) {
                set_post_thumbnail($product_id, (int) $image_ids[0]);
                if (count($image_ids) > 1) {
                    $gallery_ids = array_slice($image_ids, 1);
                    update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
                }
            }

            clean_post_cache($product_id);
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($product_id);
            }
            if (function_exists('wc_get_product')) {
                $wc_product = wc_get_product($product_id);
                if ($wc_product && method_exists($wc_product, 'read_meta_data')) {
                    $wc_product->read_meta_data(true);
                }
            }

            return CWSB_Response::ok([
                'product_id' => (string) $product_id,
                'created' => true,
                'status' => $status,
            ], 201);
        } catch (Throwable $e) {
            return CWSB_Response::error('create_exception', 'Unexpected error while creating product.', 500, [
                'message' => $e->getMessage(),
            ]);
        }
    }

}

