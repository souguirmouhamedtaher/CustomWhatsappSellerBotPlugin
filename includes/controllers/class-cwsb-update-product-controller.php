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
 * Dedicated controller for update-product flow endpoints.
 */
class CWSB_Update_Product_Controller
{
    public static function register_routes()
    {
        register_rest_route(CWSB_NS, '/seller/product/update/by-flow-token', [
            'methods' => 'POST',
            'callback' => [self::class, 'update_product_by_flow_token'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
            'args' => [
                'flow_token' => ['required' => true],
                'product_id' => ['required' => true],
                'product' => ['required' => true],
            ],
        ]);
    }

    public static function update_product_by_flow_token(WP_REST_Request $request)
    {
        if (!function_exists('wc_get_product')) {
            return CWSB_Response::error('woocommerce_missing', 'WooCommerce is not available.', 500);
        }

        $flow_token = CWSB_Utils::normalize_text($request->get_param('flow_token'));
        $product_id = (int) $request->get_param('product_id');
        $raw_product = $request->get_param('product');
        $product = is_array($raw_product) ? $raw_product : [];

        if ($flow_token === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        if ($product_id <= 0) {
            return CWSB_Response::error('invalid_request', 'product_id is required.', 422);
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

        $post = get_post($product_id);
        if (!($post instanceof WP_Post) || $post->post_type !== 'product') {
            return CWSB_Response::error('not_found', 'Product not found.', 404);
        }

        if ((int) $post->post_author !== $seller_user_id) {
            return CWSB_Response::error('forbidden', 'This seller cannot update this product.', 403);
        }

        try {
            $name = self::normalize_optional_text(isset($product['product_name']) ? $product['product_name'] : null);
            if ($name === '') {
                $name = self::normalize_optional_text(isset($product['name']) ? $product['name'] : null);
            }

            if ($name !== '') {
                wp_update_post([
                    'ID' => $product_id,
                    'post_title' => $name,
                ]);
            }

            $pricing = self::extract_update_pricing($product);
            self::apply_update_pricing($product_id, $pricing);

            self::apply_update_stock($product_id, isset($product['quantite']) ? $product['quantite'] : null);
            self::apply_update_dimensions($product_id, $product);
            self::apply_update_attributes($product_id, $product);
            self::apply_update_categories($product_id, $product);
            self::apply_update_images($product_id, $product);

            clean_post_cache($product_id);
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($product_id);
            }

            return CWSB_Response::ok([
                'product_id' => (string) $product_id,
                'updated' => true,
            ]);
        } catch (Throwable $e) {
            return CWSB_Response::error('update_exception', 'Unexpected error while updating product.', 500, [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private static function normalize_optional_text($value)
    {
        $text = CWSB_Utils::normalize_text($value);
        if ($text === '' || strtolower($text) === 'n/a') {
            return '';
        }
        return $text;
    }

    private static function extract_update_pricing($product)
    {
        $pricing = is_array($product) ? $product : [];

        $regular_tnd = self::to_positive_float(isset($pricing['prix_regulier_tnd']) ? $pricing['prix_regulier_tnd'] : null);
        $promo_tnd = self::to_positive_float(isset($pricing['prix_promo_tnd']) ? $pricing['prix_promo_tnd'] : null);
        $regular_eur = self::to_positive_float(isset($pricing['prix_regulier_eur']) ? $pricing['prix_regulier_eur'] : null);
        $promo_eur = self::to_positive_float(isset($pricing['prix_promo_eur']) ? $pricing['prix_promo_eur'] : null);

        return [
            'regular_tnd' => $regular_tnd,
            'promo_tnd' => $promo_tnd,
            'regular_eur' => $regular_eur,
            'promo_eur' => $promo_eur,
        ];
    }

    private static function apply_update_pricing($product_id, $pricing)
    {
        $regular_eur = isset($pricing['regular_eur']) ? (float) $pricing['regular_eur'] : 0;
        $promo_eur = isset($pricing['promo_eur']) ? (float) $pricing['promo_eur'] : 0;
        $regular_tnd = isset($pricing['regular_tnd']) ? (float) $pricing['regular_tnd'] : 0;
        $promo_tnd = isset($pricing['promo_tnd']) ? (float) $pricing['promo_tnd'] : 0;

        if ($regular_eur > 0) {
            $regular = self::to_price_string($regular_eur);
            update_post_meta($product_id, '_regular_price', $regular);
            update_post_meta($product_id, '_price', $promo_eur > 0 ? self::to_price_string($promo_eur) : $regular);
        }

        if ($promo_eur > 0) {
            update_post_meta($product_id, '_sale_price', self::to_price_string($promo_eur));
        }

        if ($regular_tnd > 0) {
            $regular_tnd_str = self::to_price_string($regular_tnd);
            update_post_meta($product_id, '_regular_price_tnd', $regular_tnd_str);
            update_post_meta($product_id, '_price_tnd', $promo_tnd > 0 ? self::to_price_string($promo_tnd) : $regular_tnd_str);
        }

        if ($promo_tnd > 0) {
            update_post_meta($product_id, '_sale_price_tnd', self::to_price_string($promo_tnd));
        }
    }

    private static function apply_update_stock($product_id, $quantity_value)
    {
        $quantity = (int) $quantity_value;
        if ($quantity <= 0) {
            return;
        }

        update_post_meta($product_id, '_manage_stock', 'yes');
        update_post_meta($product_id, '_stock', (string) $quantity);
        update_post_meta($product_id, '_stock_status', $quantity > 0 ? 'instock' : 'outofstock');
    }

    private static function apply_update_dimensions($product_id, $product)
    {
        $length = isset($product['longueur']) ? self::to_dimension_string($product['longueur']) : '';
        $width = isset($product['largeur']) ? self::to_dimension_string($product['largeur']) : '';
        $height = isset($product['profondeur']) ? self::to_dimension_string($product['profondeur']) : '';
        $weight = isset($product['valeur_poids']) ? self::to_dimension_string($product['valeur_poids']) : '';

        if ($length !== '') {
            update_post_meta($product_id, '_length', $length);
        }
        if ($width !== '') {
            update_post_meta($product_id, '_width', $width);
        }
        if ($height !== '') {
            update_post_meta($product_id, '_height', $height);
        }
        if ($weight !== '') {
            update_post_meta($product_id, '_weight', $weight);
        }
    }

    private static function apply_update_attributes($product_id, $product)
    {
        $color = self::normalize_optional_text(isset($product['couleur']) ? $product['couleur'] : null);
        $size = self::normalize_optional_text(isset($product['taille']) ? $product['taille'] : null);

        if ($color !== '') {
            update_post_meta($product_id, '_cwsb_color', $color);
        }
        if ($size !== '') {
            update_post_meta($product_id, '_cwsb_size', $size);
        }
    }

    private static function apply_update_categories($product_id, $product)
    {
        $category_id = self::normalize_optional_text(isset($product['product_category']) ? $product['product_category'] : null);
        $category_label = self::normalize_optional_text(isset($product['product_category_label']) ? $product['product_category_label'] : null);
        $subcategory_id = self::normalize_optional_text(isset($product['product_subcategory']) ? $product['product_subcategory'] : null);
        $subcategory_label = self::normalize_optional_text(isset($product['product_subcategory_label']) ? $product['product_subcategory_label'] : null);

        $category_ref = $category_id !== '' ? $category_id : $category_label;
        $subcategory_ref = $subcategory_id !== '' ? $subcategory_id : $subcategory_label;

        if ($category_ref === '') {
            return;
        }

        $term_ids = self::resolve_product_category_term_ids($category_ref, $subcategory_ref);
        if (!empty($term_ids)) {
            wp_set_object_terms($product_id, array_map('intval', $term_ids), 'product_cat');
        }

        if ($category_label !== '') {
            update_post_meta($product_id, '_cwsb_category_label', $category_label);
        }
        if ($subcategory_label !== '') {
            update_post_meta($product_id, '_cwsb_subcategory_label', $subcategory_label);
        }
    }

    private static function apply_update_images($product_id, $product)
    {
        $photos_modifiees = CWSB_Utils::to_bool(isset($product['photos_modifiees']) ? $product['photos_modifiees'] : false);
        if (!$photos_modifiees) {
            return;
        }

        $images = isset($product['images_base64']) && is_array($product['images_base64']) ? $product['images_base64'] : [];
        if (empty($images)) {
            return;
        }

        $image_ids = self::save_images_for_product($product_id, $images);
        if (empty($image_ids)) {
            return;
        }

        set_post_thumbnail($product_id, (int) $image_ids[0]);
        $gallery_ids = count($image_ids) > 1 ? array_slice($image_ids, 1) : [];
        update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
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
}
