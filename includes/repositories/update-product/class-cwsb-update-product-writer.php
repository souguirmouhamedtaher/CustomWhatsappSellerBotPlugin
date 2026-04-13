<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

/**
 * Write/mutation layer for update-product flow.
 */
class CWSB_Update_Product_Writer
{
    /**
     * Applies all field updates, optional new images, and optional category reassignment.
     *
     * @param int   $product_id     WooCommerce product ID.
     * @param int   $seller_user_id WordPress user ID (ownership check).
     * @param array $data           Flat map of fields to update.
     * @return bool True on success, false if not found / not owned.
     */
    public static function update_product($product_id, $seller_user_id, $data)
    {
        global $wpdb;

        $product_id     = (int) $product_id;
        $seller_user_id = (int) $seller_user_id;

        $owner_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT post_author, post_status FROM {$wpdb->posts}
                  WHERE ID = %d AND post_type = 'product' LIMIT 1",
                $product_id
            ),
            ARRAY_A
        );

        if (!$owner_row) {
            return false;
        }

        $owner = (int) $owner_row['post_author'];
        if ($owner !== $seller_user_id) {
            return false;
        }

        $should_force_draft = self::contains_non_price_or_stock_changes($data);

        if (!empty($data['name'])) {
            $clean_name = CWSB_Utils::normalize_text($data['name']);
            if ($clean_name !== '') {
                wp_update_post([
                    'ID'         => $product_id,
                    'post_title' => $clean_name,
                    'post_name'  => sanitize_title($clean_name),
                ]);
            }
        }

        $price_map = [
            '_regular_price'     => 'regular_eur',
            '_sale_price'        => 'sale_eur',
            '_regular_price_tnd' => 'regular_tnd',
            '_sale_price_tnd'    => 'sale_tnd',
        ];
        foreach ($price_map as $meta_key => $data_key) {
            if (isset($data[$data_key])) {
                self::replace_product_meta($product_id, $meta_key, CWSB_Utils::to_money_string($data[$data_key]));
            }
        }

        $regular_eur_norm = isset($data['regular_eur']) ? CWSB_Utils::to_money_string($data['regular_eur']) : null;
        $sale_eur_norm = isset($data['sale_eur']) ? CWSB_Utils::to_money_string($data['sale_eur']) : null;
        $regular_tnd_norm = isset($data['regular_tnd']) ? CWSB_Utils::to_money_string($data['regular_tnd']) : null;
        $sale_tnd_norm = isset($data['sale_tnd']) ? CWSB_Utils::to_money_string($data['sale_tnd']) : null;

        // Keep compatibility keys in sync for custom admin views.
        if (isset($data['regular_tnd'])) {
            self::replace_product_meta($product_id, 'regular_price_tnd', $regular_tnd_norm);
            self::replace_or_delete_product_meta(
                $product_id,
                '_regular_price_wmcp',
                self::build_wmcp_tnd_json($regular_tnd_norm)
            );
        }
        if (isset($data['sale_tnd'])) {
            self::replace_product_meta($product_id, 'sale_price_tnd', $sale_tnd_norm);
            self::replace_or_delete_product_meta(
                $product_id,
                '_sale_price_wmcp',
                self::build_wmcp_tnd_json($sale_tnd_norm)
            );
        }
        if (isset($data['regular_tnd']) || isset($data['sale_tnd'])) {
            $effective_tnd = ($sale_tnd_norm !== null && $sale_tnd_norm !== '')
                ? $sale_tnd_norm
                : (($regular_tnd_norm !== null) ? $regular_tnd_norm : CWSB_Utils::to_money_string(get_post_meta($product_id, '_regular_price_tnd', true)));
            self::replace_product_meta($product_id, '_price_tnd', $effective_tnd);
            self::replace_product_meta($product_id, 'price_tnd', $effective_tnd);
        }

        if (isset($data['regular_eur']) || isset($data['sale_eur'])) {
            $effective_eur = ($sale_eur_norm !== null && $sale_eur_norm !== '')
                ? $sale_eur_norm
                : (($regular_eur_norm !== null) ? $regular_eur_norm : CWSB_Utils::to_money_string(get_post_meta($product_id, '_regular_price', true)));
            self::replace_product_meta($product_id, '_price', $effective_eur);
        }

        if (isset($data['stock'])) {
            $stock = CWSB_Utils::to_int_or_zero($data['stock']);
            update_post_meta($product_id, '_stock', $stock);
            update_post_meta($product_id, '_manage_stock', 'yes');
            update_post_meta($product_id, '_stock_status', $stock > 0 ? 'instock' : 'outofstock');
        }

        $dim_map = ['_length' => 'length', '_width' => 'width', '_height' => 'height'];
        foreach ($dim_map as $meta_key => $data_key) {
            if (isset($data[$data_key])) {
                update_post_meta($product_id, $meta_key, sanitize_text_field((string) $data[$data_key]));
            }
        }
        if (isset($data['dim_unit'])) {
            update_post_meta($product_id, '_cwsb_dim_unit', sanitize_text_field($data['dim_unit']));
        }

        if (isset($data['weight'])) {
            update_post_meta($product_id, '_weight', sanitize_text_field((string) $data['weight']));
        }
        if (isset($data['weight_unit'])) {
            update_post_meta($product_id, '_cwsb_weight_unit', sanitize_text_field($data['weight_unit']));
        }

        $color_updated = array_key_exists('color', $data);
        $size_updated = array_key_exists('size', $data);
        $color_value = '';
        $size_value = '';

        if ($color_updated) {
            $color_value = CWSB_Utils::normalize_text($data['color']);
            update_post_meta($product_id, '_cwsb_color', $color_value);
        }
        if ($size_updated) {
            $size_value = CWSB_Utils::normalize_text($data['size']);
            update_post_meta($product_id, '_cwsb_size', $size_value);
        }
        if ($color_updated || $size_updated) {
            $product_attributes = get_post_meta($product_id, '_product_attributes', true);
            if (!is_array($product_attributes)) {
                $product_attributes = [];
            }

            if ($color_updated) {
                if ($color_value !== '') {
                    $product_attributes['cwsb_color'] = [
                        'name' => 'Couleur',
                        'value' => $color_value,
                        'position' => 0,
                        'is_visible' => 1,
                        'is_variation' => 0,
                        'is_taxonomy' => 0,
                    ];
                } else {
                    unset($product_attributes['cwsb_color']);
                }
            }

            if ($size_updated) {
                if ($size_value !== '') {
                    $product_attributes['cwsb_size'] = [
                        'name' => 'Taille',
                        'value' => $size_value,
                        'position' => 1,
                        'is_visible' => 1,
                        'is_variation' => 0,
                        'is_taxonomy' => 0,
                    ];
                } else {
                    unset($product_attributes['cwsb_size']);
                }
            }

            if (!empty($product_attributes)) {
                update_post_meta($product_id, '_product_attributes', $product_attributes);
            } else {
                delete_post_meta($product_id, '_product_attributes');
            }
        }

        if (!empty($data['category_id'])) {
            $category_term = get_term_by('slug', sanitize_text_field($data['category_id']), 'product_cat');
            if ($category_term && !is_wp_error($category_term)) {
                $term_ids = [(int) $category_term->term_id];

                if (!empty($data['subcategory_id'])) {
                    $sub_term = get_term_by('slug', sanitize_text_field($data['subcategory_id']), 'product_cat');
                    if ($sub_term && !is_wp_error($sub_term)) {
                        $term_ids[] = (int) $sub_term->term_id;
                    }
                }

                wp_set_object_terms($product_id, $term_ids, 'product_cat');
                $term_taxonomy_ids = wp_get_object_terms($product_id, 'product_cat', ['fields' => 'tt_ids']);
                if (!is_wp_error($term_taxonomy_ids) && !empty($term_taxonomy_ids)) {
                    wp_update_term_count_now(array_map('intval', $term_taxonomy_ids), 'product_cat');
                }
                clean_term_cache(array_map('intval', $term_ids), 'product_cat');

                if (!empty($data['category_label'])) {
                    update_post_meta($product_id, '_cwsb_category_label', CWSB_Utils::normalize_text($data['category_label']));
                }
                if (!empty($data['subcategory_label'])) {
                    update_post_meta($product_id, '_cwsb_subcategory_label', CWSB_Utils::normalize_text($data['subcategory_label']));
                }
            }
        }

        if (!empty($data['images']) && is_array($data['images'])) {
            $saved_ids = self::save_images_for_product($product_id, $data['images']);
            if (!empty($saved_ids)) {
                set_post_thumbnail($product_id, $saved_ids[0]);
                $gallery_ids = array_slice($saved_ids, 1);
                if (!empty($gallery_ids)) {
                    update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
                } else {
                    delete_post_meta($product_id, '_product_image_gallery');
                }
            }
        }

        if ($should_force_draft) {
            wp_update_post([
                'ID' => $product_id,
                'post_status' => 'draft',
            ]);
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

        if (class_exists('CWSB_Product_Repository') && class_exists('CWSB_Seller_Read_Repository')) {
            $vendor = CWSB_Seller_Read_Repository::find_vendor_by_user_id($seller_user_id);
            if ($vendor) {
                CWSB_Product_Repository::invalidate_cached_lists_for_seller_refs(
                    $seller_user_id,
                    [isset($vendor['phone']) ? $vendor['phone'] : ''],
                    [isset($vendor['flow_token']) ? $vendor['flow_token'] : '']
                );
            }
        }

        return true;
    }

    /**
     * Replace a postmeta key with exactly one row to avoid duplicate values.
     */
    private static function replace_product_meta($product_id, $meta_key, $meta_value)
    {
        delete_post_meta($product_id, $meta_key);
        update_post_meta($product_id, $meta_key, $meta_value);
    }

    /**
     * Replaces a meta key when value is non-empty, otherwise deletes it.
     */
    private static function replace_or_delete_product_meta($product_id, $meta_key, $meta_value)
    {
        delete_post_meta($product_id, $meta_key);
        if ($meta_value !== null && $meta_value !== '') {
            update_post_meta($product_id, $meta_key, $meta_value);
        }
    }

    /**
     * Builds WMCP-compatible TND JSON payload (e.g. {"TND":"100"}).
     */
    private static function build_wmcp_tnd_json($amount)
    {
        $normalized = CWSB_Utils::to_money_string($amount);
        if ($normalized === '') {
            return '';
        }

        $payload = wp_json_encode([
            'TND' => (string) $normalized,
        ]);

        return is_string($payload) ? $payload : '{"TND":"' . $normalized . '"}';
    }

    /**
     * Returns true when payload contains at least one change outside price/stock fields.
     * Business rule: non price/quantity updates must move product back to draft.
     */
    private static function contains_non_price_or_stock_changes($data)
    {
        if (!is_array($data) || empty($data)) {
            return false;
        }

        $price_or_stock_keys = [
            'regular_eur',
            'sale_eur',
            'regular_tnd',
            'sale_tnd',
            'stock',
        ];

        foreach ($data as $key => $value) {
            if (in_array((string) $key, $price_or_stock_keys, true)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Saves base64-encoded images as WordPress attachments and returns their IDs.
     */
    private static function save_images_for_product($product_id, $images)
    {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $saved_ids = [];
        $count     = 0;
        $max       = 6;

        foreach ($images as $raw) {
            if ($count >= $max) {
                break;
            }

            if (!is_string($raw) || $raw === '') {
                continue;
            }

            $raw = preg_replace('/^data:image\/[a-zA-Z0-9.+-]+;base64,/', '', $raw);
            $bin = base64_decode($raw, true);
            if ($bin === false || $bin === '') {
                continue;
            }

            $filename = 'product-' . $product_id . '-img' . ($count + 1) . '-' . time() . '.jpg';
            $upload   = wp_upload_bits($filename, null, $bin);
            if (!empty($upload['error'])) {
                continue;
            }

            $attachment = [
                'post_mime_type' => 'image/jpeg',
                'post_title'     => sanitize_text_field(pathinfo($filename, PATHINFO_FILENAME)),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ];

            $attach_id = wp_insert_attachment($attachment, $upload['file'], $product_id);
            if (!$attach_id || is_wp_error($attach_id)) {
                continue;
            }

            $metadata = wp_generate_attachment_metadata((int) $attach_id, $upload['file']);
            wp_update_attachment_metadata((int) $attach_id, $metadata);
            wp_update_post([
                'ID' => (int) $attach_id,
                'post_parent' => $product_id,
            ]);

            $saved_ids[] = (int) $attach_id;
            $count++;
        }

        return $saved_ids;
    }
}