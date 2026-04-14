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

/**
 * Product mapping and transformation layer.
 */
class CWSB_Product_Mapper
{
    public static function map_list_row($row)
    {
        $is_variable = ((int) ($row['has_variations'] ?? 0)) === 1;
        $final_price = CWSB_Utils::to_money_string(
            (isset($row['sale_price']) && trim((string) $row['sale_price']) !== '')
                ? $row['sale_price']
                : ((isset($row['price']) && trim((string) $row['price']) !== '') ? $row['price'] : ($row['regular_price'] ?? ''))
        );
        $stock = CWSB_Utils::to_int_or_zero($row['stock'] ?? '');
        $created_at = !empty($row['post_date']) ? mysql2date('d/m/Y', $row['post_date']) : '';

        $image_src = '';
        $thumbnail_id = isset($row['thumbnail_id']) ? (int) $row['thumbnail_id'] : 0;
        if ($thumbnail_id > 0) {
            $image_src = (string) wp_get_attachment_image_url($thumbnail_id, 'full');
        }

        return [
            'id' => isset($row['ID']) ? (string) $row['ID'] : '',
            'name' => isset($row['post_title']) ? (string) $row['post_title'] : '',
            'type' => $is_variable ? 'variable' : 'simple',
            'post_status' => isset($row['post_status']) ? (string) $row['post_status'] : '',
            'status' => isset($row['post_status']) ? (string) $row['post_status'] : '',
            'state' => isset($row['post_status']) ? (string) $row['post_status'] : '',
            'sku' => isset($row['sku']) ? (string) $row['sku'] : '',
            'price' => $final_price,
            'stock' => $stock,
            'image_src' => $image_src,
            'image_gallery' => $image_src !== '' ? [$image_src] : [],
            'created_at' => $created_at,
            'short_description' => '',
            'full_description' => '',
            'categories' => [],
            'tags' => [],
            'general_price_euro' => $final_price,
            'general_price_tnd' => '',
            'promo_price_euro' => '',
            'promo_price_tnd' => '',
            'stock_quantity' => $stock,
            'manage_stock' => ((string) ($row['manage_stock'] ?? '')) === 'yes',
            'is_variable' => $is_variable,
        ];
    }

    public static function map_product_detail_by_sql($product_id, $maxCarouselImages)
    {
        $row = CWSB_Product_Queries::find_product_detail_row_by_id((int) $product_id);
        if (!$row) {
            return null;
        }

        $image_src = '';
        $thumbnail_id = isset($row->thumbnail_id) ? (int) $row->thumbnail_id : 0;
        if ($thumbnail_id > 0) {
            $image_src = (string) wp_get_attachment_image_url($thumbnail_id, 'full');
        }
        $image_gallery = self::build_image_gallery_urls($image_src, (string) ($row->gallery_ids ?? ''), (int) $maxCarouselImages);

        $pid = (int) $product_id;
        $variations = self::find_variations_for_product_sql($pid, $image_src);
        $is_variable = !empty($variations);

        $created_at = '';
        if (!empty($row->post_date)) {
            $created_at = mysql2date('d/m/Y', $row->post_date);
        }

        $categories = CWSB_Product_Queries::find_term_names_by_taxonomy($pid, 'product_cat');
        $tags = CWSB_Product_Queries::find_term_names_by_taxonomy($pid, 'product_tag');

        $result = [
            'id' => (string) $pid,
            'name' => (string) $row->post_title,
            'type' => $is_variable ? 'variable' : 'simple',
            'post_status' => (string) ($row->post_status ?? ''),
            'status' => (string) ($row->post_status ?? ''),
            'state' => (string) ($row->post_status ?? ''),
            'sku' => (string) ($row->sku ?? ''),
            'image_src' => $image_src,
            'image_gallery' => $image_gallery,
            'created_at' => $created_at,
            'short_description' => (string) ($row->post_excerpt ?? ''),
            'full_description' => (string) ($row->post_content ?? ''),
            'categories' => is_array($categories) ? array_values($categories) : [],
            'tags' => is_array($tags) ? array_values($tags) : [],
            'general_price_euro' => CWSB_Utils::to_money_string($row->regular_price ?? ''),
            'general_price_tnd' => CWSB_Utils::to_money_string(CWSB_Utils::decode_wmcp_tnd($row->regular_price_wmcp ?? '', $row->regular_price_tnd ?? '')),
            'promo_price_tnd' => CWSB_Utils::to_money_string(CWSB_Utils::decode_wmcp_tnd($row->sale_price_wmcp ?? '', $row->sale_price_tnd ?? '')),
            'promo_price_tnd' => CWSB_Utils::to_money_string($row->sale_price_tnd ?? ''),
            'stock_quantity' => CWSB_Utils::to_int_or_zero($row->stock ?? ''),
            'manage_stock' => ((string) ($row->manage_stock ?? '')) === 'yes',
            'is_variable' => $is_variable,
        ];

        if ($is_variable) {
            $result['variations'] = $variations;
        }

        return $result;
    }

    public static function map_variation_by_post_id($variation_id, $parent_image_src = '')
    {
        $vid = (int) $variation_id;
        if ($vid <= 0) {
            return null;
        }

        $variation_post = get_post($vid);
        if (!$variation_post || $variation_post->post_type !== 'product_variation') {
            return null;
        }

        $image_src = '';
        $image_id = (int) get_post_meta($vid, '_thumbnail_id', true);
        if ($image_id > 0) {
            $image_src = (string) wp_get_attachment_image_url($image_id, 'full');
        } elseif (is_string($parent_image_src) && $parent_image_src !== '') {
            $image_src = $parent_image_src;
        }

        $attributes = CWSB_Product_Queries::find_variation_attributes($vid);
        $weight = trim((string) get_post_meta($vid, '_weight', true));
        if (!isset($attributes['weight']) && $weight !== '') {
            $attributes['weight'] = $weight;
        }

        return [
            'id' => (string) $vid,
            'sku' => (string) get_post_meta($vid, '_sku', true),
            'title' => (string) $variation_post->post_title,
            'stock' => CWSB_Utils::to_int_or_zero(get_post_meta($vid, '_stock', true)),
            'stock_status' => (string) get_post_meta($vid, '_stock_status', true),
            'manage_stock' => ((string) get_post_meta($vid, '_manage_stock', true)) === 'yes',
            'attributes' => $attributes,
            'price_euro' => CWSB_Utils::to_money_string(get_post_meta($vid, '_price', true)),
            'price_tnd' => CWSB_Utils::to_money_string(get_post_meta($vid, '_price_tnd', true)),
            'image_src' => $image_src,
        ];
    }

    private static function find_variations_for_product_sql($product_id, $parent_image_src = '')
    {
        $rows = CWSB_Product_Queries::find_variations_rows_for_product((int) $product_id);
        if (empty($rows)) {
            return [];
        }

        $variations = [];
        foreach ($rows as $row) {
            $variation_id = (int) ($row->ID ?? 0);
            if ($variation_id <= 0) {
                continue;
            }

            $image_src = '';
            $thumbnail_id = isset($row->thumbnail_id) ? (int) $row->thumbnail_id : 0;
            if ($thumbnail_id > 0) {
                $image_src = (string) wp_get_attachment_image_url($thumbnail_id, 'full');
            } elseif (is_string($parent_image_src) && $parent_image_src !== '') {
                $image_src = $parent_image_src;
            }

            $attributes = CWSB_Product_Queries::find_variation_attributes($variation_id);
            if (!isset($attributes['weight']) && trim((string) ($row->weight ?? '')) !== '') {
                $attributes['weight'] = (string) $row->weight;
            }

            $variations[] = [
                'id' => (string) $variation_id,
                'sku' => (string) ($row->sku ?? ''),
                'title' => (string) ($row->post_title ?? ''),
                'stock' => CWSB_Utils::to_int_or_zero($row->stock ?? ''),
                'stock_status' => (string) ($row->stock_status ?? ''),
                'manage_stock' => ((string) ($row->manage_stock ?? '')) === 'yes',
                'attributes' => $attributes,
                'price_euro' => CWSB_Utils::to_money_string($row->price ?? ''),
                'price_tnd' => CWSB_Utils::to_money_string($row->price_tnd ?? ''),
                'image_src' => $image_src,
            ];
        }

        return $variations;
    }

    private static function build_image_gallery_urls($primary_image_src, $gallery_ids_csv, $maxImages)
    {
        $urls = [];

        $primary = trim((string) $primary_image_src);
        if ($primary !== '') {
            $urls[] = $primary;
        }

        $csv = trim((string) $gallery_ids_csv);
        if ($csv === '') {
            return array_values(array_unique($urls));
        }

        $ids = array_filter(array_map('intval', explode(',', $csv)));
        foreach ($ids as $id) {
            if ((int) $id <= 0) {
                continue;
            }
            $url = (string) wp_get_attachment_image_url((int) $id, 'full');
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return array_slice(array_values(array_unique($urls)), 0, (int) $maxImages);
    }
}
