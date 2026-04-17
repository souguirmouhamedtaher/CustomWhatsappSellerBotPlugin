<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

if (!class_exists('CWSB_Logger')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-logger.php';
}

/**
 * Shared helper logic for add-product flow.
 */
class CWSB_Add_Product_Support_Service
{
    private static function round_with_threshold_to_int($value, $threshold = 0.2)
    {
        $num = (float) $value;
        if ($num <= 0) {
            return 0;
        }

        $safe_threshold = (float) $threshold;
        if ($safe_threshold < 0) {
            $safe_threshold = 0;
        }
        if ($safe_threshold > 1) {
            $safe_threshold = 1;
        }

        $base = (int) floor($num);
        $fraction = $num - $base;
        $epsilon = 0.000000001;

        if (($fraction + $epsilon) >= $safe_threshold) {
            return $base + 1;
        }

        return $base;
    }

    private static function normalize_image_payload($image)
    {
        $raw = trim((string) $image);
        if ($raw === '') {
            return '';
        }

        return (string) preg_replace('/^data:image\/[a-zA-Z0-9.+-]+;base64,/', '', $raw);
    }

    private static function decode_image_payload($image)
    {
        $raw = self::normalize_image_payload($image);
        if ($raw === '') {
            return null;
        }

        $binary = base64_decode($raw, true);
        if ($binary === false || $binary === '') {
            return null;
        }

        return [
            'raw' => $raw,
            'binary' => $binary,
        ];
    }

    private static function collect_uploadable_images($images, $limit = null)
    {
        if (!is_array($images)) {
            return [];
        }

        $collected = [];
        foreach ($images as $image) {
            $decoded = self::decode_image_payload($image);
            if ($decoded === null) {
                continue;
            }

            $collected[] = $decoded;
            if ($limit !== null && count($collected) >= (int) $limit) {
                break;
            }
        }

        return $collected;
    }

    private static function count_non_empty_images($images)
    {
        return count(self::collect_uploadable_images($images));
    }

    public static function normalize_status($value)
    {
        $status = strtolower(CWSB_Utils::normalize_text($value));
        $allowed = ['draft', 'publish', 'pending', 'private'];
        return in_array($status, $allowed, true) ? $status : 'draft';
    }

    public static function get_pricing_config()
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

    public static function convert_tnd_to_eur($tnd, $config = [])
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
        $result = self::round_with_threshold_to_int($eur, 0.2);

        CWSB_Logger::debug('convert_tnd_to_eur', [
            'tnd'        => $safe_tnd,
            'rate'       => $exchange_rate,
            'markup'     => $fixed_markup,
            'decimals'   => $rounding_decimals,
            'rounding_mode' => 'threshold_integer',
            'rounding_threshold' => 0.2,
            'eur'        => $result,
        ]);

        return $result;
    }

    public static function convert_tnd_to_eur_live($regular_tnd, $promo_tnd, $config = [])
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

            $fixed_markup = isset($config['fixed_markup_eur']) ? (float) $config['fixed_markup_eur'] : 9;

            $regular_eur = $regular_tnd > 0
                ? self::round_with_threshold_to_int(($regular_tnd * $eur_per_tnd) + $fixed_markup, 0.2)
                : 0;
            $promo_eur = $promo_tnd > 0
                ? self::round_with_threshold_to_int(($promo_tnd * $eur_per_tnd) + $fixed_markup, 0.2)
                : 0;

            return [
                'regular_eur' => $regular_eur,
                'promo_eur' => $promo_eur,
                'rate' => $eur_per_tnd,
            ];
        } catch (Throwable $e) {
            CWSB_Logger::error('convert_tnd_to_eur_live threw exception', ['message' => $e->getMessage()]);
            return null;
        }
    }

    public static function to_price_string($value)
    {
        if ($value === null || $value === '') {
            return '';
        }

        $raw = str_replace(',', '.', (string) $value);
        $num = is_numeric($raw) ? (float) $raw : 0;
        if ($num <= 0) {
            return '';
        }
        return self::format_decimal_string($num, 2);
    }

    public static function to_positive_float($value)
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

    public static function to_dimension_string($value)
    {
        $raw = str_replace(',', '.', (string) $value);
        $num = is_numeric($raw) ? (float) $raw : 0;
        if ($num <= 0) {
            return '';
        }
        return self::format_decimal_string($num, 3);
    }

    public static function format_decimal_string($value, $decimals)
    {
        $num = is_numeric($value) ? (float) $value : 0.0;
        if ($num <= 0) {
            return '';
        }

        $precision = max(0, min(6, (int) $decimals));
        return number_format($num, $precision, '.', '');
    }

    public static function resolve_product_category_term($value, $create_if_missing = false, $parent_id = 0)
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
                CWSB_Logger::warning('resolve_product_category_term: wp_insert_term failed', [
                    'value'     => $value,
                    'parent_id' => $parent_id,
                    'wp_error'  => is_wp_error($inserted) ? $inserted->get_error_message() : 'missing term_id',
                ]);
                return null;
            }
            $created = get_term((int) $inserted['term_id'], $taxonomy);
            return $created instanceof WP_Term ? $created : null;
        }

        return $term instanceof WP_Term ? $term : null;
    }

    public static function resolve_product_category_term_ids($category_id, $subcategory_id = '')
    {
        $category_term = self::resolve_product_category_term($category_id, true);
        if (!($category_term instanceof WP_Term)) {
            CWSB_Logger::warning('resolve_product_category_term_ids: category not resolved', ['category_id' => $category_id]);
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

    public static function save_images_for_product($product_id, $images_base64, $seller_user_id = 0)
    {
        $pid = (int) $product_id;
        $author_id = (int) $seller_user_id;
        if ($pid <= 0 || !is_array($images_base64) || empty($images_base64)) {
            return [];
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $saved_ids = [];
        $uploadable_images = self::collect_uploadable_images($images_base64, (int) CWSB_MAX_PRODUCT_UPLOAD_IMAGES);

        foreach ($uploadable_images as $index => $image) {
            $filename = 'cwsb-product-' . $pid . '-' . ($index + 1) . '-' . time() . '.jpg';
            $upload = wp_upload_bits($filename, null, $image['binary']);
            if (!empty($upload['error'])) {
                continue;
            }

            $attachment = [
                'post_mime_type' => 'image/jpeg',
                'post_title' => sanitize_text_field(pathinfo($filename, PATHINFO_FILENAME)),
                'post_content' => '',
                'post_status' => 'inherit',
                // Keep media ownership aligned with the seller account for vendor dashboards.
                'post_author' => $author_id > 0 ? $author_id : 0,
            ];

            $attach_id = wp_insert_attachment($attachment, $upload['file'], $pid);
            if (!$attach_id || is_wp_error($attach_id)) {
                continue;
            }

            $metadata = wp_generate_attachment_metadata((int) $attach_id, $upload['file']);
            wp_update_attachment_metadata((int) $attach_id, $metadata);
            wp_update_post([
                'ID' => (int) $attach_id,
                'post_parent' => $pid,
            ]);

            $saved_ids[] = (int) $attach_id;
        }

        return $saved_ids;
    }

    public static function find_product_id_by_idempotency_key($idempotency_key)
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

    public static function validate_create_payload($product)
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

        if (isset($p['images_base64']) && !is_array($p['images_base64'])) {
            $errors[] = [
                'field' => 'product.images_base64',
                'code' => 'invalid_type',
                'message' => 'Product images must be provided as an array.',
            ];
        }

        $image_count = self::count_non_empty_images(isset($p['images_base64']) ? $p['images_base64'] : []);
        if ($image_count > (int) CWSB_MAX_PRODUCT_UPLOAD_IMAGES) {
            $errors[] = [
                'field' => 'product.images_base64',
                'code' => 'max_items_exceeded',
                'message' => sprintf('A maximum of %d product images is allowed.', (int) CWSB_MAX_PRODUCT_UPLOAD_IMAGES),
            ];
        }

        $quantity = isset($p['quantity']) ? (int) $p['quantity'] : (isset($p['quantite']) ? (int) $p['quantite'] : 0);
        if ($quantity <= 0) {
            $errors[] = [
                'field' => 'product.quantity',
                'code' => 'invalid_range',
                'message' => 'Quantity must be greater than zero.',
            ];
        }

        $pricing = isset($p['pricing']) && is_array($p['pricing']) ? $p['pricing'] : [];
        $regular_tnd = self::to_positive_float(
            isset($pricing['regular_tnd']) ? $pricing['regular_tnd'] :
            (isset($pricing['prix_regulier_tnd']) ? $pricing['prix_regulier_tnd'] :
            (isset($p['prix_regulier_tnd']) ? $p['prix_regulier_tnd'] : null))
        );
        $promo_tnd = self::to_positive_float(
            isset($pricing['promo_tnd']) ? $pricing['promo_tnd'] :
            (isset($pricing['prix_promo_tnd']) ? $pricing['prix_promo_tnd'] :
            (isset($p['prix_promo_tnd']) ? $p['prix_promo_tnd'] : null))
        );
        $regular_eur = self::to_positive_float(
            isset($pricing['regular_eur']) ? $pricing['regular_eur'] :
            (isset($pricing['prix_regulier_eur']) ? $pricing['prix_regulier_eur'] :
            (isset($p['prix_regulier_eur']) ? $p['prix_regulier_eur'] : null))
        );
        $promo_eur = self::to_positive_float(
            isset($pricing['promo_eur']) ? $pricing['promo_eur'] :
            (isset($pricing['prix_promo_eur']) ? $pricing['prix_promo_eur'] :
            (isset($p['prix_promo_eur']) ? $p['prix_promo_eur'] : null))
        );

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

        if ($promo_tnd > 0 && $regular_tnd <= 0) {
            $errors[] = [
                'field' => 'product.pricing.regular_tnd',
                'code' => 'required',
                'message' => 'Regular TND is required when promo TND is provided.',
            ];
        }

        if ($regular_eur > 0 && $promo_eur > 0 && $promo_eur >= $regular_eur) {
            $errors[] = [
                'field' => 'product.pricing.promo_eur',
                'code' => 'invalid_range',
                'message' => 'Promo EUR must be strictly lower than regular EUR.',
            ];
        }

        if ($promo_eur > 0 && $regular_eur <= 0) {
            $errors[] = [
                'field' => 'product.pricing.regular_eur',
                'code' => 'required',
                'message' => 'Regular EUR is required when promo EUR is provided.',
            ];
        }

        return $errors;
    }

    public static function generate_auto_sku($prefix, $seller_user_id)
    {
        global $wpdb;

        $vendor_raw = strtoupper(CWSB_Utils::normalize_text($prefix));
        $vendor = preg_replace('/[^A-Z0-9]/', '', $vendor_raw);
        if ($vendor === '' || $vendor === 'GEN') {
            $vendor = 'VENDOR';
        }

        $sku_prefix = 'CWSB_' . $vendor;

        // Random, counter-free SKU format: CWSB_<VENDOR><RANDOM>
        // Keep retrying on collisions to guarantee uniqueness.
        $max_attempts = 12;
        for ($i = 0; $i < $max_attempts; $i++) {
            $candidate = $sku_prefix . self::random_sku_suffix(8);
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(1) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                '_sku',
                $candidate
            ));
            if ($exists === 0) {
                return $candidate;
            }
        }

        // Extremely unlikely fallback: include timestamp to force uniqueness.
        return $sku_prefix . strtoupper(dechex(time()));
    }

    /**
     * Generates an uppercase alpha-numeric random suffix.
     */
    private static function random_sku_suffix($length)
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max_idx = strlen($alphabet) - 1;
        $size = max(4, (int) $length);
        $out = '';

        for ($i = 0; $i < $size; $i++) {
            $idx = random_int(0, $max_idx);
            $out .= $alphabet[$idx];
        }

        return $out;
    }
}