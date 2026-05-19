<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

if (!class_exists('CWSB_Seller_Repository')) {
    require_once __DIR__ . '/../seller/class-cwsb-seller-repository.php';
}

/**
 * Data access helpers for admin endpoints.
 */
class CWSB_Admin_Ops_Repository
{
    const BULK_MAX = 50;
    const BULK_HARD_MAX = 200;

    public static function state_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'cwsb_seller_state';
    }

    public static function search_sellers($params)
    {
        global $wpdb;

        $state = self::state_table_name();
        $cap_key = $wpdb->prefix . 'capabilities';
        $q = CWSB_Utils::normalize_text(isset($params['q']) ? $params['q'] : '');
        $status = CWSB_Utils::normalize_text(isset($params['status']) ? $params['status'] : 'all');
        $city = CWSB_Utils::normalize_text(isset($params['city']) ? $params['city'] : '');
        $category = CWSB_Utils::normalize_text(isset($params['category']) ? $params['category'] : '');
        $page = max(1, (int) (isset($params['page']) ? $params['page'] : 1));
        $per_page = max(1, min((int) (isset($params['per_page']) ? $params['per_page'] : 50), 200));
        $offset = ($page - 1) * $per_page;
        $sort = CWSB_Utils::normalize_text(isset($params['sort']) ? $params['sort'] : 'created_desc');

        $where = [];
        $args = [$cap_key, '%"wcfm_vendor"%'];

        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $where[] = "(
                u.display_name LIKE %s OR
                u.user_email LIKE %s OR
                COALESCE(bp.meta_value, ph.meta_value, wfp.meta_value, '') LIKE %s OR
                COALESCE(store.meta_value, '') LIKE %s OR
                COALESCE(citym.meta_value, '') LIKE %s
            )";
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
        }

        if ($city !== '') {
            $where[] = 'COALESCE(citym.meta_value, \'\') LIKE %s';
            $args[] = '%' . $wpdb->esc_like($city) . '%';
        }

        if ($category !== '') {
            $where[] = 'COALESCE(cat.meta_value, \'\') LIKE %s';
            $args[] = '%' . $wpdb->esc_like($category) . '%';
        }

        if ($status === 'blocked') {
            $where[] = "COALESCE(s.seller_status, 'active') = 'blocked'";
        } elseif ($status === 'active') {
            $where[] = "COALESCE(s.seller_status, 'active') = 'active'";
        } elseif ($status === 'dormant') {
            $now_ms = (int) round(microtime(true) * 1000);
            $threshold = $now_ms - (30 * 24 * 60 * 60 * 1000);
            $where[] = '(s.session_active_until IS NULL OR s.session_active_until < %d)';
            $args[] = $threshold;
        }

        $where_sql = '';
        if (!empty($where)) {
            $where_sql = ' AND ' . implode(' AND ', $where);
        }

        $order_sql = 'u.ID DESC';
        if ($sort === 'created_asc') {
            $order_sql = 'u.ID ASC';
        }

        $base_from = "
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->usermeta} caps
                ON caps.user_id = u.ID
               AND caps.meta_key = %s
            LEFT JOIN {$wpdb->usermeta} bp
                ON bp.user_id = u.ID AND bp.meta_key = 'billing_phone'
            LEFT JOIN {$wpdb->usermeta} ph
                ON ph.user_id = u.ID AND ph.meta_key = 'phone'
            LEFT JOIN {$wpdb->usermeta} wfp
                ON wfp.user_id = u.ID AND wfp.meta_key = 'wcfm_phone'
            LEFT JOIN {$wpdb->usermeta} store
                ON store.user_id = u.ID AND store.meta_key IN ('store_name', '_wcfm_store_name')
            LEFT JOIN {$wpdb->usermeta} citym
                ON citym.user_id = u.ID AND citym.meta_key IN ('billing_city', 'city')
            LEFT JOIN {$wpdb->usermeta} cat
                ON cat.user_id = u.ID AND cat.meta_key IN ('store_category', 'category')
            LEFT JOIN {$state} s
                ON s.user_id = u.ID
            WHERE caps.meta_value LIKE %s
            {$where_sql}
        ";

        $count_sql = "SELECT COUNT(DISTINCT u.ID) {$base_from}";
        $count_prepared = $wpdb->prepare($count_sql, $args);
        $total = (int) $wpdb->get_var($count_prepared);

        $rows_sql = "
            SELECT
                u.ID AS user_id,
                u.display_name AS name,
                u.user_email AS email,
                COALESCE(bp.meta_value, ph.meta_value, wfp.meta_value, '') AS phone,
                COALESCE(store.meta_value, '') AS store_name,
                COALESCE(citym.meta_value, '') AS city,
                COALESCE(cat.meta_value, '') AS category,
                COALESCE(s.seller_status, 'active') AS status,
                s.session_active_until,
                s.flow_token,
                u.user_registered AS created_at
            {$base_from}
            GROUP BY u.ID
            ORDER BY {$order_sql}
            LIMIT %d OFFSET %d
        ";

        $rows_args = $args;
        $rows_args[] = $per_page;
        $rows_args[] = $offset;
        $rows_prepared = $wpdb->prepare($rows_sql, $rows_args);
        $rows = $wpdb->get_results($rows_prepared, ARRAY_A);

        return [
            'page'     => $page,
            'per_page' => $per_page,
            'total'    => $total,
            'has_more' => ($page * $per_page) < $total,
            'sellers'  => is_array($rows) ? $rows : [],
        ];
    }

    public static function find_profile_by_phone($phone)
    {
        global $wpdb;

        $seller = CWSB_Seller_Repository::find_dashboard_seller_by_phone($phone);
        if (!$seller || empty($seller['user_id'])) {
            return null;
        }

        $user_id = (int) $seller['user_id'];
        $state_table = self::state_table_name();
        $orders_table = $wpdb->prefix . 'wcfm_marketplace_orders';

        $state = $wpdb->get_row(
            $wpdb->prepare("SELECT flow_token, session_active_until, seller_status, blocked_reason, blocked_at, blocked_by FROM {$state_table} WHERE user_id = %d LIMIT 1", $user_id),
            ARRAY_A
        );

        $products = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_author = %d AND post_type = 'product' AND post_status IN ('publish','private','draft','pending')",
                $user_id
            )
        );

        $orders_30d = 0;
        $gmv_30d = 0.0;
        $recent_orders = [];

        $orders_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $orders_table));
        if ($orders_exists === $orders_table) {
            $orders_30d = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT order_id) FROM {$orders_table} WHERE vendor_id = %d AND created >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                    $user_id
                )
            );

            $recent = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT order_id, created, order_status FROM {$orders_table} WHERE vendor_id = %d ORDER BY created DESC LIMIT 5",
                    $user_id
                ),
                ARRAY_A
            );

            if (is_array($recent)) {
                foreach ($recent as $row) {
                    $order_id = isset($row['order_id']) ? (int) $row['order_id'] : 0;
                    if ($order_id <= 0) {
                        continue;
                    }

                    $total = (float) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_order_total' LIMIT 1",
                            $order_id
                        )
                    );
                    $gmv_30d += $total;
                    $recent_orders[] = [
                        'id'         => $order_id,
                        'total'      => $total,
                        'status'     => isset($row['order_status']) ? (string) $row['order_status'] : '',
                        'created_at' => isset($row['created']) ? (string) $row['created'] : '',
                    ];
                }
            }
        }

        $normalized_phone = CWSB_Utils::normalize_phone($phone);
        return [
            'profile' => [
                'user_id'     => $user_id,
                'name'        => isset($seller['name']) ? $seller['name'] : '',
                'store_name'  => isset($seller['store_name']) ? $seller['store_name'] : '',
                'city'        => isset($seller['city']) ? $seller['city'] : '',
                'email'       => isset($seller['email']) ? $seller['email'] : '',
                'category'    => isset($seller['category']) ? $seller['category'] : '',
                'status'      => isset($state['seller_status']) ? (string) $state['seller_status'] : 'active',
                'created_at'  => isset($seller['created_at']) ? $seller['created_at'] : '',
                'phone'       => $normalized_phone,
            ],
            'session' => [
                'active'                  => !empty($state['session_active_until']) && ((int) $state['session_active_until'] > (int) round(microtime(true) * 1000)),
                'last_seen'               => isset($state['session_active_until']) ? (int) $state['session_active_until'] : null,
                'flow_token_expires_at'   => isset($state['session_active_until']) ? (int) $state['session_active_until'] : null,
            ],
            'counters' => [
                'products'       => $products,
                'orders_30d'     => $orders_30d,
                'gmv_30d'        => round($gmv_30d, 2),
                'wallet_balance' => 0.0,
            ],
            'blocklist' => [
                'blocked'    => isset($state['seller_status']) && $state['seller_status'] === 'blocked',
                'reason'     => isset($state['blocked_reason']) ? (string) $state['blocked_reason'] : '',
                'blocked_at' => isset($state['blocked_at']) ? (int) $state['blocked_at'] : null,
                'blocked_by' => isset($state['blocked_by']) ? (string) $state['blocked_by'] : '',
            ],
            'recent_orders' => $recent_orders,
        ];
    }

    public static function reset_pin($phone)
    {
        $seller = CWSB_Seller_Repository::insert_seller_state_by_phone($phone, [
            'code'                 => '',
            'reset_token'          => '',
            'reset_token_expiry'   => null,
            'session_active_until' => null,
        ]);

        return is_array($seller) ? $seller : null;
    }

    public static function force_logout($phone)
    {
        $seller = CWSB_Seller_Repository::insert_seller_state_by_phone($phone, [
            'session_active_until' => null,
            'flow_token'           => '',
        ]);

        return is_array($seller) ? $seller : null;
    }

    public static function set_block_status($phone, $blocked, $reason, $actor_email)
    {
        $status = $blocked ? 'blocked' : 'active';
        $now_ms = (int) round(microtime(true) * 1000);

        $state = [
            'seller_status' => $status,
        ];

        if ($blocked) {
            $state['blocked_reason'] = (string) $reason;
            $state['blocked_at'] = $now_ms;
            $state['blocked_by'] = (string) $actor_email;
        } else {
            $state['blocked_reason'] = '';
            $state['blocked_at'] = null;
            $state['blocked_by'] = '';
        }

        $seller = CWSB_Seller_Repository::insert_seller_state_by_phone($phone, $state);
        if (!is_array($seller) || empty($seller['user_id'])) {
            return null;
        }

        update_user_meta((int) $seller['user_id'], 'cwsb_vendor_status', $status);

        return $seller;
    }
}
