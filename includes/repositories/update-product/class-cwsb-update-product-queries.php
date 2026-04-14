<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

/**
 * SQL read layer for update-product flow.
 */
class CWSB_Update_Product_Queries
{
    /**
     * Returns a paginated list of the seller's products.
     *
     * @param int $seller_user_id WordPress user ID of the seller.
     * @param int $page           1-based page number.
     * @param int $limit          Items per page (1-5).
     * @return array { total: int, products: array[] }
     */
    public static function find_products_paged($seller_user_id, $page, $limit)
    {
        global $wpdb;

        $seller_user_id = (int) $seller_user_id;
        $page           = max(1, (int) $page);
        $limit          = max(1, min(5, (int) $limit));
        $offset         = ($page - 1) * $limit;

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                   FROM {$wpdb->posts} p
                  WHERE p.post_type   = 'product'
                    AND p.post_status IN ('publish','private','draft','pending')
                    AND p.post_author  = %d",
                $seller_user_id
            )
        );

        if ($total === 0) {
            return ['total' => 0, 'products' => []];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    p.ID,
                    p.post_title,
                    p.post_status,
                    MAX(CASE WHEN pm.meta_key = '_sku'               THEN pm.meta_value END) AS sku,
                    MAX(CASE WHEN pm.meta_key = '_regular_price'     THEN pm.meta_value END) AS price_eur,
                    MAX(CASE WHEN pm.meta_key = '_regular_price_tnd' THEN pm.meta_value END) AS price_tnd,
                    MAX(CASE WHEN pm.meta_key = '_regular_price_wmcp' THEN pm.meta_value END) AS price_wmcp,
                    MAX(CASE WHEN pm.meta_key = '_stock'             THEN pm.meta_value END) AS stock,
                    MAX(CASE WHEN pm.meta_key = '_thumbnail_id'      THEN pm.meta_value END) AS thumbnail_id
                   FROM {$wpdb->posts} p
              LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                  WHERE p.post_type   = 'product'
                    AND p.post_status IN ('publish','private','draft','pending')
                    AND p.post_author  = %d
               GROUP BY p.ID, p.post_title, p.post_status
               ORDER BY p.post_date DESC
                  LIMIT %d OFFSET %d",
                $seller_user_id,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        $products = [];
        foreach ((array) $rows as $row) {
            $image_url = '';
            if (!empty($row['thumbnail_id'])) {
                $url = wp_get_attachment_url((int) $row['thumbnail_id']);
                $image_url = $url ? $url : '';
            }

            $products[] = [
                'id'          => (int) $row['ID'],
                'name'        => $row['post_title'],
                'sku'         => isset($row['sku']) ? $row['sku'] : '',
                'price_eur'   => isset($row['price_eur']) ? $row['price_eur'] : '',
                'price_tnd'   => CWSB_Utils::decode_wmcp_tnd(isset($row['price_wmcp']) ? $row['price_wmcp'] : '', isset($row['price_tnd']) ? $row['price_tnd'] : ''),
                'stock'       => CWSB_Utils::to_int_or_zero($row['stock']),
                'post_status' => $row['post_status'],
                'image_url'   => $image_url,
            ];
        }

        return ['total' => $total, 'products' => $products];
    }

    /**
     * Returns the product's cover + gallery image URLs for the photos edit screen.
     *
     * @param int $product_id     WooCommerce product ID.
     * @param int $seller_user_id WordPress user ID of the owning seller.
     * @return array|null
     */
    public static function find_product_photos($product_id, $seller_user_id)
    {
        global $wpdb;

        $product_id     = (int) $product_id;
        $seller_user_id = (int) $seller_user_id;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    p.post_title,
                    MAX(CASE WHEN pm.meta_key = '_thumbnail_id'         THEN pm.meta_value END) AS thumbnail_id,
                    MAX(CASE WHEN pm.meta_key = '_product_image_gallery' THEN pm.meta_value END) AS gallery_ids
                   FROM {$wpdb->posts} p
              LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                  WHERE p.ID          = %d
                    AND p.post_type   = 'product'
                    AND p.post_author = %d
               GROUP BY p.ID, p.post_title",
                $product_id,
                $seller_user_id
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        return [
            'product_id'   => $product_id,
            'product_name' => $row['post_title'],
            'image_urls'   => self::build_gallery_urls($row['thumbnail_id'], $row['gallery_ids']),
        ];
    }

    /**
     * Returns all editable fields for the info screens (price, stock, dimensions, attributes).
     *
     * @param int $product_id     WooCommerce product ID.
     * @param int $seller_user_id WordPress user ID of the owning seller.
     * @return array|null
     */
    public static function find_product_edit_info($product_id, $seller_user_id)
    {
        global $wpdb;

        $product_id     = (int) $product_id;
        $seller_user_id = (int) $seller_user_id;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    p.post_title,
                    MAX(CASE WHEN pm.meta_key = '_regular_price'      THEN pm.meta_value END) AS regular_eur,
                    MAX(CASE WHEN pm.meta_key = '_sale_price'          THEN pm.meta_value END) AS sale_eur,
                    MAX(CASE WHEN pm.meta_key = '_regular_price_tnd'   THEN pm.meta_value END) AS regular_tnd,
                    MAX(CASE WHEN pm.meta_key = '_sale_price_tnd'      THEN pm.meta_value END) AS sale_tnd,
                    MAX(CASE WHEN pm.meta_key = '_regular_price_wmcp'  THEN pm.meta_value END) AS regular_wmcp,
                    MAX(CASE WHEN pm.meta_key = '_sale_price_wmcp'     THEN pm.meta_value END) AS sale_wmcp,
                    MAX(CASE WHEN pm.meta_key = '_stock'               THEN pm.meta_value END) AS stock,
                    MAX(CASE WHEN pm.meta_key = '_manage_stock'        THEN pm.meta_value END) AS manage_stock,
                    MAX(CASE WHEN pm.meta_key = '_length'              THEN pm.meta_value END) AS length,
                    MAX(CASE WHEN pm.meta_key = '_width'               THEN pm.meta_value END) AS width,
                    MAX(CASE WHEN pm.meta_key = '_height'              THEN pm.meta_value END) AS height,
                    MAX(CASE WHEN pm.meta_key = '_cwsb_dim_unit'       THEN pm.meta_value END) AS dim_unit,
                    MAX(CASE WHEN pm.meta_key = '_weight'              THEN pm.meta_value END) AS weight,
                    MAX(CASE WHEN pm.meta_key = '_cwsb_weight_unit'    THEN pm.meta_value END) AS weight_unit,
                    MAX(CASE WHEN pm.meta_key = '_cwsb_color'          THEN pm.meta_value END) AS color,
                    MAX(CASE WHEN pm.meta_key = '_cwsb_size'           THEN pm.meta_value END) AS size
                   FROM {$wpdb->posts} p
              LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                  WHERE p.ID          = %d
                    AND p.post_type   = 'product'
                    AND p.post_author = %d
               GROUP BY p.ID, p.post_title",
                $product_id,
                $seller_user_id
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        return [
            'product_id'   => $product_id,
            'product_name' => $row['post_title'],
            'regular_eur'  => isset($row['regular_eur']) ? $row['regular_eur'] : '',
            'sale_eur'     => isset($row['sale_eur']) ? $row['sale_eur'] : '',
            'regular_tnd'  => CWSB_Utils::decode_wmcp_tnd(isset($row['regular_wmcp']) ? $row['regular_wmcp'] : '', isset($row['regular_tnd']) ? $row['regular_tnd'] : ''),
            'sale_tnd'     => CWSB_Utils::decode_wmcp_tnd(isset($row['sale_wmcp']) ? $row['sale_wmcp'] : '', isset($row['sale_tnd']) ? $row['sale_tnd'] : ''),
            'stock'        => CWSB_Utils::to_int_or_zero($row['stock']),
            'manage_stock' => ($row['manage_stock'] === 'yes'),
            'length'       => isset($row['length']) ? $row['length'] : '',
            'width'        => isset($row['width']) ? $row['width'] : '',
            'height'       => isset($row['height']) ? $row['height'] : '',
            'dim_unit'     => !empty($row['dim_unit']) ? $row['dim_unit'] : 'cm',
            'weight'       => isset($row['weight']) ? $row['weight'] : '',
            'weight_unit'  => !empty($row['weight_unit']) ? $row['weight_unit'] : 'kg',
            'color'        => isset($row['color']) ? $row['color'] : '',
            'size'         => isset($row['size']) ? $row['size'] : '',
        ];
    }

    /**
     * Returns the product's assigned category and subcategory slugs/labels.
     *
     * @param int $product_id     WooCommerce product ID.
     * @param int $seller_user_id WordPress user ID of the owning seller.
     * @return array|null
     */
    public static function find_product_category_info($product_id, $seller_user_id)
    {
        global $wpdb;

        $product_id     = (int) $product_id;
        $seller_user_id = (int) $seller_user_id;

        $owner = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_author FROM {$wpdb->posts}
                  WHERE ID = %d AND post_type = 'product' LIMIT 1",
                $product_id
            )
        );

        if ($owner !== $seller_user_id) {
            return null;
        }

        $meta_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
                  WHERE post_id  = %d
                    AND meta_key IN ('_cwsb_category_label','_cwsb_subcategory_label')",
                $product_id
            ),
            ARRAY_A
        );

        $label_map = [];
        foreach ((array) $meta_rows as $m) {
            $label_map[$m['meta_key']] = $m['meta_value'];
        }

        $terms = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.term_id, t.slug, t.name, tt.parent
                   FROM {$wpdb->term_relationships} tr
              INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
              INNER JOIN {$wpdb->terms} t           ON t.term_id = tt.term_id
                  WHERE tr.object_id = %d AND tt.taxonomy = 'product_cat'",
                $product_id
            ),
            ARRAY_A
        );

        $category    = null;
        $subcategory = null;

        foreach ((array) $terms as $term) {
            if ((int) $term['parent'] === 0) {
                $category = $term;
            } else {
                $subcategory = $term;
            }
        }

        return [
            'product_id'        => $product_id,
            'category_slug'     => $category ? $category['slug'] : '',
            'category_name'     => $category ? $category['name'] : '',
            'category_label'    => isset($label_map['_cwsb_category_label']) ? $label_map['_cwsb_category_label'] : ($category ? $category['name'] : ''),
            'subcategory_slug'  => $subcategory ? $subcategory['slug'] : '',
            'subcategory_name'  => $subcategory ? $subcategory['name'] : '',
            'subcategory_label' => isset($label_map['_cwsb_subcategory_label']) ? $label_map['_cwsb_subcategory_label'] : ($subcategory ? $subcategory['name'] : ''),
        ];
    }

    /**
     * Resolves thumbnail + gallery attachment IDs to full URLs.
     */
    private static function build_gallery_urls($thumbnail_id, $gallery_ids_string)
    {
        $urls = [];

        if (!empty($thumbnail_id)) {
            $url = wp_get_attachment_url((int) $thumbnail_id);
            if ($url) {
                $urls[] = $url;
            }
        }

        if (!empty($gallery_ids_string)) {
            foreach (explode(',', $gallery_ids_string) as $id_str) {
                $id = (int) trim($id_str);
                if ($id > 0) {
                    $url = wp_get_attachment_url($id);
                    if ($url) {
                        $urls[] = $url;
                    }
                }
            }
        }

        return $urls;
    }
}