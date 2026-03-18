<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Response')) {
    require_once __DIR__ . '/../utilities/class-cwsb-response.php';
}

if (!class_exists('CWSB_Auth_Middleware')) {
    require_once __DIR__ . '/../middleware/class-cwsb-auth-middleware.php';
}

if (!class_exists('CWSB_Seller_Repository')) {
    require_once __DIR__ . '/../repositories/class-cwsb-seller-repository.php';
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../utilities/class-cwsb-utils.php';
}

/**
 * Dedicated controller for add-product flow endpoints.
 * Uses WooCommerce CRUD to ensure writes are identical to normal WP/WC product creation.
 */
class CWSB_Add_Product_Controller
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
        $include_empty = $include_empty_param === null ? false : CWSB_Utils::to_bool($include_empty_param);
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
        $include_empty = $include_empty_param === null ? false : CWSB_Utils::to_bool($include_empty_param);
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
        $regular_tnd = self::to_positive_float($request->get_param('regular_tnd'));
        $promo_tnd = self::to_positive_float($request->get_param('promo_tnd'));
        $config = self::get_pricing_config();

        $live = self::convert_tnd_to_eur_live($regular_tnd, $promo_tnd, $config);

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

        $regular_eur = self::convert_tnd_to_eur($regular_tnd, $config);
        $promo_eur = self::convert_tnd_to_eur($promo_tnd, $config);

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
        if (!function_exists('wc_get_product')) {
            return CWSB_Response::error('woocommerce_missing', 'WooCommerce is not available.', 500);
        }

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

        $validation = self::validate_create_payload($product);
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
        $regular_tnd_num = self::to_positive_float(isset($pricing['regular_tnd']) ? $pricing['regular_tnd'] : null);
        $promo_tnd_num = self::to_positive_float(isset($pricing['promo_tnd']) ? $pricing['promo_tnd'] : null);
        $regular_eur_num = self::to_positive_float(isset($pricing['regular_eur']) ? $pricing['regular_eur'] : null);
        $promo_eur_num = self::to_positive_float(isset($pricing['promo_eur']) ? $pricing['promo_eur'] : null);

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
            $existing_product_id = self::find_product_id_by_idempotency_key($idempotency_key);
            if ($existing_product_id > 0) {
                return CWSB_Response::ok([
                    'product_id' => (string) $existing_product_id,
                    'created' => false,
                    'status' => 'existing',
                ], 200);
            }
        }

        try {
            $status = self::normalize_status(isset($product['status']) ? $product['status'] : 'draft');
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

            // Manual Woo data mapping instead of WC_Product_Simple CRUD object.
            wp_set_object_terms($product_id, 'simple', 'product_type');

            // Auto-generate SKU if requested
            if ($auto_generate_sku && $sku === '') {
                $sku = self::generate_auto_sku($sku_prefix, $seller_user_id);
            }

            if ($sku !== '') {
                update_post_meta($product_id, '_sku', $sku);
            }

            $regular_eur = self::to_price_string(isset($pricing['regular_eur']) ? $pricing['regular_eur'] : null);
            $promo_eur = self::to_price_string(isset($pricing['promo_eur']) ? $pricing['promo_eur'] : null);
            if ($regular_eur !== '') {
                update_post_meta($product_id, '_regular_price', $regular_eur);
            }
            if ($promo_eur !== '' && (float) $promo_eur > 0) {
                update_post_meta($product_id, '_sale_price', $promo_eur);
                update_post_meta($product_id, '_price', $promo_eur);
            } elseif ($regular_eur !== '') {
                update_post_meta($product_id, '_price', $regular_eur);
            }

            $quantity = isset($product['quantity']) ? (int) $product['quantity'] : 0;
            if ($quantity > 0) {
                update_post_meta($product_id, '_manage_stock', 'yes');
                update_post_meta($product_id, '_stock', (string) $quantity);
                update_post_meta($product_id, '_stock_status', 'instock');
            } else {
                update_post_meta($product_id, '_manage_stock', 'no');
                update_post_meta($product_id, '_stock_status', 'instock');
            }

            $dimensions = isset($product['dimensions']) && is_array($product['dimensions']) ? $product['dimensions'] : [];
            $length = isset($dimensions['longueur']) ? self::to_dimension_string($dimensions['longueur']) : '';
            $width = isset($dimensions['largeur']) ? self::to_dimension_string($dimensions['largeur']) : '';
            $height = isset($dimensions['profondeur']) ? self::to_dimension_string($dimensions['profondeur']) : '';
            update_post_meta($product_id, '_length', $length);
            update_post_meta($product_id, '_width', $width);
            update_post_meta($product_id, '_height', $height);

            $weight = isset($product['weight']) && is_array($product['weight']) ? $product['weight'] : [];
            $weight_value = isset($weight['value']) ? self::to_dimension_string($weight['value']) : '';
            update_post_meta($product_id, '_weight', $weight_value);

            $category_id = CWSB_Utils::normalize_text(isset($product['category_id']) ? $product['category_id'] : '');
            $subcategory_id = CWSB_Utils::normalize_text(isset($product['subcategory_id']) ? $product['subcategory_id'] : '');
            $category_term_ids = self::resolve_product_category_term_ids($category_id, $subcategory_id);
            if (empty($category_term_ids)) {
                wp_delete_post($product_id, true);
                return CWSB_Response::error('invalid_category', 'Unable to resolve category/subcategory mapping.', 422);
            }
            wp_set_object_terms($product_id, array_map('intval', $category_term_ids), 'product_cat');

            if ($idempotency_key !== '') {
                update_post_meta($product_id, '_cwsb_idempotency_key', $idempotency_key);
            }

            $regular_tnd = self::to_price_string(isset($pricing['regular_tnd']) ? $pricing['regular_tnd'] : null);
            $promo_tnd = self::to_price_string(isset($pricing['promo_tnd']) ? $pricing['promo_tnd'] : null);
            if ($regular_tnd !== '') {
                update_post_meta($product_id, '_regular_price_tnd', $regular_tnd);
                update_post_meta($product_id, '_price_tnd', $promo_tnd !== '' ? $promo_tnd : $regular_tnd);
            }
            if ($promo_tnd !== '') {
                update_post_meta($product_id, '_sale_price_tnd', $promo_tnd);
            }

            $attributes = isset($product['attributes']) && is_array($product['attributes']) ? $product['attributes'] : [];
            $color = CWSB_Utils::normalize_text(isset($attributes['couleur']) ? $attributes['couleur'] : '');
            $size = CWSB_Utils::normalize_text(isset($attributes['taille']) ? $attributes['taille'] : '');
            if ($color !== '') {
                update_post_meta($product_id, '_cwsb_color', $color);
            }
            if ($size !== '') {
                update_post_meta($product_id, '_cwsb_size', $size);
            }

            $category_label = CWSB_Utils::normalize_text(isset($product['category_label']) ? $product['category_label'] : '');
            $subcategory_label = CWSB_Utils::normalize_text(isset($product['subcategory_label']) ? $product['subcategory_label'] : '');
            if ($category_label !== '') {
                update_post_meta($product_id, '_cwsb_category_label', $category_label);
            }
            if ($subcategory_label !== '') {
                update_post_meta($product_id, '_cwsb_subcategory_label', $subcategory_label);
            }

            $image_ids = self::save_images_for_product($product_id, isset($product['images_base64']) ? $product['images_base64'] : []);
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

    private static function normalize_status($value)
    {
        $status = strtolower(CWSB_Utils::normalize_text($value));
        $allowed = ['draft', 'publish', 'pending', 'private'];
        return in_array($status, $allowed, true) ? $status : 'draft';
    }

    private static function get_pricing_config()
    {
        $exchange_rate = (float) get_option('cwsb_eur_exchange_rate', 3.358);
        if ($exchange_rate <= 0) {
            $exchange_rate = 3.358;
        }

        $fixed_markup = (float) get_option('cwsb_eur_fixed_markup', 9);
        $rounding_decimals = (int) get_option('cwsb_eur_rounding_decimals', 2);
        $rounding_decimals = max(0, min($rounding_decimals, 4));

        return [
            'exchange_rate' => $exchange_rate,
            'fixed_markup_eur' => $fixed_markup,
            'rounding_decimals' => $rounding_decimals,
        ];
    }

    private static function convert_tnd_to_eur($tnd, $config = [])
    {
        $safe_tnd = self::to_positive_float($tnd);
        if ($safe_tnd <= 0) {
            return 0;
        }

        $exchange_rate = isset($config['exchange_rate']) ? (float) $config['exchange_rate'] : 3.358;
        $fixed_markup = isset($config['fixed_markup_eur']) ? (float) $config['fixed_markup_eur'] : 9;
        $rounding_decimals = isset($config['rounding_decimals']) ? (int) $config['rounding_decimals'] : 2;

        if ($exchange_rate <= 0) {
            $exchange_rate = 3.358;
        }

        $rounding_decimals = max(0, min($rounding_decimals, 4));
        $eur = ($safe_tnd / $exchange_rate) + $fixed_markup;

        return round($eur, $rounding_decimals);
    }

    private static function convert_tnd_to_eur_live($regular_tnd, $promo_tnd, $config = [])
    {
        if (!function_exists('wmc_get_price')) {
            return null;
        }

        if (!class_exists('WOOMULTI_CURRENCY_Data')) {
            return null;
        }

        try {
            $settings = WOOMULTI_CURRENCY_Data::get_ins();
            if (!$settings || !method_exists($settings, 'get_list_currencies')) {
                return null;
            }

            $currencies = $settings->get_list_currencies();
            if (!is_array($currencies) || !isset($currencies['EUR'])) {
                return null;
            }

            if (!isset($currencies['TND'], $currencies['EUR'])) {
                return null;
            }

            $rate_eur = isset($currencies['EUR']['rate']) ? (float) $currencies['EUR']['rate'] : 0;
            $rate_tnd = isset($currencies['TND']['rate']) ? (float) $currencies['TND']['rate'] : 0;

            if ($rate_eur <= 0) {
                $rate_eur = function_exists('wmc_get_exchange_rate') ? (float) wmc_get_exchange_rate('EUR') : 0;
            }

            if ($rate_tnd <= 0) {
                $rate_tnd = function_exists('wmc_get_exchange_rate') ? (float) wmc_get_exchange_rate('TND') : 0;
            }

            if ($rate_eur <= 0 || $rate_tnd <= 0) {
                return null;
            }

            $eur_per_tnd = $rate_eur / $rate_tnd;
            if ($eur_per_tnd <= 0) {
                return null;
            }

            $eur_decimals = isset($currencies['EUR']['decimals']) ? absint($currencies['EUR']['decimals']) : 2;
            $eur_decimals = max(0, min($eur_decimals, 4));
            $fixed_markup = isset($config['fixed_markup_eur']) ? (float) $config['fixed_markup_eur'] : 9;

            // CURCY rates are indexed against store base currency.
            // For TND -> EUR we use: amount * (rate_EUR / rate_TND).
            $regular_eur = $regular_tnd > 0
                ? round(($regular_tnd * $eur_per_tnd) + $fixed_markup, $eur_decimals)
                : 0;
            $promo_eur = $promo_tnd > 0
                ? round(($promo_tnd * $eur_per_tnd) + $fixed_markup, $eur_decimals)
                : 0;

            return [
                'regular_eur' => $regular_eur,
                'promo_eur' => $promo_eur,
                'rate' => $eur_per_tnd,
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function to_price_string($value)
    {
        if ($value === null || $value === '') {
            return '';
        }

        $raw = str_replace(',', '.', (string) $value);
        $num = is_numeric($raw) ? (float) $raw : 0;
        if ($num <= 0) {
            return '';
        }
        return wc_format_decimal($num, wc_get_price_decimals());
    }

    private static function to_positive_float($value)
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $raw = str_replace(',', '.', (string) $value);
        if (!is_numeric($raw)) {
            return 0.0;
        }

        $num = (float) $raw;
        return $num > 0 ? $num : 0.0;
    }

    private static function to_dimension_string($value)
    {
        $raw = str_replace(',', '.', (string) $value);
        $num = is_numeric($raw) ? (float) $raw : 0;
        if ($num <= 0) {
            return '';
        }
        return wc_format_decimal($num, 3);
    }

    private static function resolve_product_category_term($value, $create_if_missing = false, $parent_id = 0)
    {
        $value = CWSB_Utils::normalize_text($value);
        if ($value === '') {
            return null;
        }

        $taxonomy = 'product_cat';

        if (ctype_digit($value)) {
            $term = get_term((int) $value, $taxonomy);
            if ($term instanceof WP_Term && !is_wp_error($term)) {
                return $term;
            }
        }

        $slug = sanitize_title($value);
        $term = get_term_by('slug', $slug, $taxonomy);
        if (!$term) {
            $term = get_term_by('name', $value, $taxonomy);
        }

        if (!$term || is_wp_error($term)) {
            if (!$create_if_missing) {
                return null;
            }

            $insert_args = ['slug' => $slug];
            if ((int) $parent_id > 0) {
                $insert_args['parent'] = (int) $parent_id;
            }

            $inserted = wp_insert_term(ucwords(str_replace(['-', '_'], ' ', $value)), $taxonomy, $insert_args);
            if (is_wp_error($inserted) || !isset($inserted['term_id'])) {
                return null;
            }
            $created = get_term((int) $inserted['term_id'], $taxonomy);
            return $created instanceof WP_Term ? $created : null;
        }

        return $term instanceof WP_Term ? $term : null;
    }

    private static function resolve_product_category_term_ids($category_id, $subcategory_id = '')
    {
        $category_term = self::resolve_product_category_term($category_id, true);
        if (!($category_term instanceof WP_Term)) {
            return [];
        }

        $term_ids = [(int) $category_term->term_id];
        $subcategory_value = CWSB_Utils::normalize_text($subcategory_id);
        if ($subcategory_value === '') {
            return $term_ids;
        }

        $subcategory_term = self::resolve_product_category_term(
            $subcategory_value,
            true,
            (int) $category_term->term_id
        );
        if (!($subcategory_term instanceof WP_Term)) {
            return $term_ids;
        }

        if ((int) $subcategory_term->parent > 0 && (int) $subcategory_term->parent !== (int) $category_term->term_id) {
            return [];
        }

        $term_ids[] = (int) $subcategory_term->term_id;
        return array_values(array_unique(array_map('intval', $term_ids)));
    }

    private static function save_images_for_product($product_id, $images_base64)
    {
        $pid = (int) $product_id;
        if ($pid <= 0 || !is_array($images_base64) || empty($images_base64)) {
            return [];
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $saved_ids = [];
        $max_images = min(6, count($images_base64));

        for ($i = 0; $i < $max_images; $i++) {
            $raw = isset($images_base64[$i]) ? (string) $images_base64[$i] : '';
            if (trim($raw) === '') {
                continue;
            }

            $raw = preg_replace('/^data:image\/[a-zA-Z0-9.+-]+;base64,/', '', $raw);
            $bin = base64_decode($raw, true);
            if ($bin === false || $bin === '') {
                continue;
            }

            $filename = 'cwsb-product-' . $pid . '-' . ($i + 1) . '-' . time() . '.jpg';
            $upload = wp_upload_bits($filename, null, $bin);
            if (!empty($upload['error'])) {
                continue;
            }

            $attachment = [
                'post_mime_type' => 'image/jpeg',
                'post_title' => sanitize_text_field(pathinfo($filename, PATHINFO_FILENAME)),
                'post_content' => '',
                'post_status' => 'inherit',
            ];

            $attach_id = wp_insert_attachment($attachment, $upload['file'], $pid);
            if (!$attach_id || is_wp_error($attach_id)) {
                continue;
            }

            $metadata = wp_generate_attachment_metadata((int) $attach_id, $upload['file']);
            wp_update_attachment_metadata((int) $attach_id, $metadata);

            $saved_ids[] = (int) $attach_id;
        }

        return $saved_ids;
    }

    private static function find_product_id_by_idempotency_key($idempotency_key)
    {
        global $wpdb;

        $key = CWSB_Utils::normalize_text($idempotency_key);
        if ($key === '') {
            return 0;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY post_id DESC LIMIT 1",
                '_cwsb_idempotency_key',
                $key
            )
        );
    }

    private static function validate_create_payload($product)
    {
        $p = is_array($product) ? $product : [];
        $errors = [];

        $name = CWSB_Utils::normalize_text(isset($p['name']) ? $p['name'] : '');
        if ($name === '') {
            $errors[] = [
                'field' => 'product.name',
                'code' => 'required',
                'message' => 'Product name is required.',
            ];
        }

        $category_id = CWSB_Utils::normalize_text(isset($p['category_id']) ? $p['category_id'] : '');
        if ($category_id === '') {
            $errors[] = [
                'field' => 'product.category_id',
                'code' => 'required',
                'message' => 'Product category is required.',
            ];
        }

        $quantity = isset($p['quantity']) ? (int) $p['quantity'] : 0;
        if ($quantity <= 0) {
            $errors[] = [
                'field' => 'product.quantity',
                'code' => 'invalid_range',
                'message' => 'Quantity must be greater than zero.',
            ];
        }

        $pricing = isset($p['pricing']) && is_array($p['pricing']) ? $p['pricing'] : [];
        $regular_tnd = self::to_positive_float(isset($pricing['regular_tnd']) ? $pricing['regular_tnd'] : null);
        $promo_tnd = self::to_positive_float(isset($pricing['promo_tnd']) ? $pricing['promo_tnd'] : null);
        $regular_eur = self::to_positive_float(isset($pricing['regular_eur']) ? $pricing['regular_eur'] : null);
        $promo_eur = self::to_positive_float(isset($pricing['promo_eur']) ? $pricing['promo_eur'] : null);

        if ($regular_tnd <= 0 && $regular_eur <= 0) {
            $errors[] = [
                'field' => 'product.pricing.regular',
                'code' => 'required',
                'message' => 'A regular price (TND or EUR) is required.',
            ];
        }

        if ($regular_tnd > 0 && $promo_tnd > 0 && $promo_tnd >= $regular_tnd) {
            $errors[] = [
                'field' => 'product.pricing.promo_tnd',
                'code' => 'invalid_range',
                'message' => 'Promo TND must be strictly lower than regular TND.',
            ];
        }

        if ($regular_eur > 0 && $promo_eur > 0 && $promo_eur >= $regular_eur) {
            $errors[] = [
                'field' => 'product.pricing.promo_eur',
                'code' => 'invalid_range',
                'message' => 'Promo EUR must be strictly lower than regular EUR.',
            ];
        }

        return $errors;
    }

    private static function generate_auto_sku($prefix, $seller_user_id)
    {
        global $wpdb;

        $prefix = strtoupper(CWSB_Utils::normalize_text($prefix));
        if ($prefix === '' || $prefix === 'GEN') {
            $prefix = 'CWSB';
        }

        // Find the next sequence number for this seller+prefix combination
        $pattern = $prefix . '-%';
        $latest_sku = $wpdb->get_var($wpdb->prepare(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = %s
             AND meta_value LIKE %s
             AND p.post_author = %d
             AND p.post_type = 'product'
             ORDER BY meta_value DESC
             LIMIT 1",
            '_sku',
            $pattern,
            (int)$seller_user_id
        ));

        $next_seq = 1;
        if ($latest_sku) {
            // Extract sequence number from last SKU (e.g., "CWSB-TAHER-009" -> 9)
            $parts = explode('-', (string)$latest_sku);
            if (count($parts) >= 2) {
                $last_num = (int)end($parts);
                $next_seq = $last_num + 1;
            }
        }

        return sprintf('%s-%03d', $prefix, $next_seq);
    }
}

