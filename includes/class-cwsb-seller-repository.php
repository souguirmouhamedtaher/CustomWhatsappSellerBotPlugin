<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Cache')) {
    require_once __DIR__ . '/class-cwsb-cache.php';
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/class-cwsb-utils.php';
}

/**
 * Data-access layer for seller identity and auth-flow state.
 *
 * Identity fields are sourced from wp_users/wp_usermeta.
 * Flow fields are sourced from wp_cwsb_seller_state.
 */
class CWSB_Seller_Repository
{
    private static function seller_user_cache_key($user_id)
    {
        return 'seller:user:' . (int) $user_id;
    }

    private static function seller_phone_cache_key($phone)
    {
        return 'seller:phone:' . CWSB_Utils::normalize_phone($phone);
    }

    private static function seller_flow_cache_key($flow_token)
    {
        return 'seller:flow:' . CWSB_Utils::normalize_text($flow_token);
    }

    /**
     * Clears seller read caches and related product list caches.
     */
    private static function invalidate_seller_read_caches($user_id, $phones = [], $flow_tokens = [])
    {
        $uid = (int) $user_id;
        if ($uid > 0) {
            CWSB_Cache::delete(self::seller_user_cache_key($uid));
        }

        if ($uid > 0) {
            $state = self::get_state_by_user_id($uid);
            if (isset($state['phone'])) {
                $phones[] = $state['phone'];
            }
            if (isset($state['flow_token'])) {
                $flow_tokens[] = $state['flow_token'];
            }
        }

        $normalized_phones = [];
        foreach ((array) $phones as $phone) {
            $normalized = CWSB_Utils::normalize_phone($phone);
            if ($normalized !== '') {
                $normalized_phones[] = $normalized;
            }
        }
        $normalized_phones = array_values(array_unique($normalized_phones));

        $normalized_flows = [];
        foreach ((array) $flow_tokens as $flow_token) {
            $token = CWSB_Utils::normalize_text($flow_token);
            if ($token !== '') {
                $normalized_flows[] = $token;
            }
        }
        $normalized_flows = array_values(array_unique($normalized_flows));

        foreach ($normalized_phones as $phone) {
            CWSB_Cache::delete(self::seller_phone_cache_key($phone));
        }

        foreach ($normalized_flows as $flow_token) {
            CWSB_Cache::delete(self::seller_flow_cache_key($flow_token));
        }

        if (class_exists('CWSB_Product_Repository') && method_exists('CWSB_Product_Repository', 'invalidate_cached_lists_for_seller_refs')) {
            CWSB_Product_Repository::invalidate_cached_lists_for_seller_refs($uid, $normalized_phones, $normalized_flows);
        }
    }

    /**
     * LIKE pattern used to match WCFM vendor capability in serialized meta.
     */
    private static function vendor_capability_like()
    {
        return '%\"wcfm_vendor\"%';
    }

    /**
     * Returns fully-qualified custom seller-state table name.
     */
    public static function state_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'cwsb_seller_state';
    }

    /**
     * Finds a vendor user by phone and vendor capability.
     * Adapt meta keys if your installation stores phone elsewhere.
     */
    public static function find_vendor_by_phone($phone)
    {
        global $wpdb;

        // Normalize once so query comparisons are consistent.
        $normalized = CWSB_Utils::normalize_phone($phone);
        if ($normalized === '') {
            return null;
        }

        $cache_key = self::seller_phone_cache_key($normalized);
        $cache_hit = false;
        $cached = CWSB_Cache::get($cache_key, $cache_hit);
        if ($cache_hit) {
            return $cached;
        }

        // Fast path: resolve seller from plugin state table first.
        // EXACT phone match only - no partial/fallback matching.
        $table = self::state_table_name();
        $user_id = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT user_id FROM {$table} WHERE phone = %s LIMIT 1", $normalized)
        );

        if ($user_id > 0) {
            $seller = self::find_vendor_by_user_id($user_id);
            CWSB_Cache::set($cache_key, $seller);
            return $seller;
        }

        $cap_key = $wpdb->prefix . 'capabilities';

        // Fast SQL path: exact phone match keeps query index-friendly.
        $sql_exact = "
            SELECT
                u.ID AS user_id,
                u.display_name AS name,
                u.user_email AS email,
                COALESCE(um_phone.meta_value, '') AS phone
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->usermeta} um_phone ON um_phone.user_id = u.ID
            INNER JOIN {$wpdb->usermeta} um_caps ON um_caps.user_id = u.ID
            WHERE um_phone.meta_key IN ('billing_phone', 'phone', 'wcfm_phone')
              AND um_phone.meta_value = %s
              AND um_caps.meta_key = %s
              AND um_caps.meta_value LIKE %s
            LIMIT 1
        ";

        $row = $wpdb->get_row(
            $wpdb->prepare($sql_exact, $normalized, $cap_key, self::vendor_capability_like()),
            ARRAY_A
        );

        // Slow fallback: normalized comparison for legacy formatted phone values.
        if (!$row) {
            $sql_normalized = "
                SELECT
                    u.ID AS user_id,
                    u.display_name AS name,
                    u.user_email AS email,
                    COALESCE(um_phone.meta_value, '') AS phone
                FROM {$wpdb->users} u
                INNER JOIN {$wpdb->usermeta} um_phone ON um_phone.user_id = u.ID
                INNER JOIN {$wpdb->usermeta} um_caps ON um_caps.user_id = u.ID
                WHERE um_phone.meta_key IN ('billing_phone', 'phone', 'wcfm_phone')
                  AND REPLACE(REPLACE(REPLACE(um_phone.meta_value, '+', ''), ' ', ''), '-', '') = %s
                  AND um_caps.meta_key = %s
                  AND um_caps.meta_value LIKE %s
                LIMIT 1
            ";

            $row = $wpdb->get_row(
                $wpdb->prepare($sql_normalized, $normalized, $cap_key, self::vendor_capability_like()),
                ARRAY_A
            );
        }

        if (!$row) {
            CWSB_Cache::set($cache_key, null);
            return null;
        }

        $uid = (int) $row['user_id'];
        $row['phone'] = CWSB_Utils::normalize_phone(isset($row['phone']) ? $row['phone'] : '');
        $state = self::get_state_by_user_id($uid);
        $seller = self::normalize_seller_row(array_merge($row, $state));
        CWSB_Cache::set($cache_key, $seller);
        return $seller;
    }

    /**
     * Finds a seller by email and merges user identity with saved state.
     */
    public static function find_vendor_by_email($email)
    {
        global $wpdb;

        $mail = CWSB_Utils::normalize_text($email);
        if ($mail === '') {
            return null;
        }

        $cap_key = $wpdb->prefix . 'capabilities';
        $sql = "
            SELECT
                u.ID AS user_id,
                u.display_name AS name,
                u.user_email AS email,
                COALESCE(billing_phone.meta_value, phone.meta_value, wcfm_phone.meta_value, '') AS phone
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->usermeta} caps
                ON caps.user_id = u.ID
               AND caps.meta_key = %s
            LEFT JOIN {$wpdb->usermeta} billing_phone
                ON billing_phone.user_id = u.ID
               AND billing_phone.meta_key = 'billing_phone'
            LEFT JOIN {$wpdb->usermeta} phone
                ON phone.user_id = u.ID
               AND phone.meta_key = 'phone'
            LEFT JOIN {$wpdb->usermeta} wcfm_phone
                ON wcfm_phone.user_id = u.ID
               AND wcfm_phone.meta_key = 'wcfm_phone'
            WHERE LOWER(u.user_email) = LOWER(%s)
              AND caps.meta_value LIKE %s
            LIMIT 1
        ";

        $row = $wpdb->get_row(
            $wpdb->prepare($sql, $cap_key, $mail, self::vendor_capability_like()),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        $row['phone'] = CWSB_Utils::normalize_phone(isset($row['phone']) ? $row['phone'] : '');
        // Merge persisted state values (code/token/session...) into seller payload.
        $state = self::get_state_by_user_id((int) $row['user_id']);
        return self::normalize_seller_row(array_merge($row, $state));
    }

    /**
     * Upserts seller state row for a known user id.
     */
    public static function save_seller_state($user_id, $state = [])
    {
        global $wpdb;

        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return false;
        }

        $table = self::state_table_name();
        $allowed = [
            'name',
            'email',
            'phone',
            'code',
            'flow_token',
            'reset_token',
            'reset_token_expiry',
            'session_active_until',
        ];

        $data = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, (array) $state)) {
                $data[$key] = $state[$key];
            }
        }

        if (array_key_exists('phone', $data)) {
            $data['phone'] = CWSB_Utils::normalize_phone($data['phone']);
        }

        if (empty($data)) {
            // Nothing to write, treat as success.
            return true;
        }

        $previous_state = self::get_state_by_user_id($user_id);

        $exists = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT user_id FROM {$table} WHERE user_id = %d LIMIT 1", $user_id)
        );

        if ($exists > 0) {
            // Update existing state row.
            $updated = $wpdb->update($table, $data, ['user_id' => $user_id]);
            if ($updated === false) {
                return false;
            }

            $phones_to_invalidate = [];
            $flows_to_invalidate = [];
            if (isset($previous_state['phone'])) {
                $phones_to_invalidate[] = $previous_state['phone'];
            }
            if (isset($data['phone'])) {
                $phones_to_invalidate[] = $data['phone'];
            }
            if (isset($previous_state['flow_token'])) {
                $flows_to_invalidate[] = $previous_state['flow_token'];
            }
            if (isset($data['flow_token'])) {
                $flows_to_invalidate[] = $data['flow_token'];
            }

            self::invalidate_seller_read_caches($user_id, $phones_to_invalidate, $flows_to_invalidate);
            return true;
        }

        $missing_identity = [];
        foreach (['name', 'email', 'phone'] as $identity_key) {
                if (!array_key_exists($identity_key, $data) || $data[$identity_key] === null || CWSB_Utils::normalize_text($data[$identity_key]) === '') {
                $missing_identity[] = $identity_key;
            }
        }

        if (!empty($missing_identity)) {
            // Backfill required identity columns from user tables when inserting first row.
            $seller_identity = self::find_vendor_by_user_id($user_id);
            if (!$seller_identity) {
                return false;
            }

            foreach ($missing_identity as $identity_key) {
                if (isset($seller_identity[$identity_key])) {
                    $data[$identity_key] = $seller_identity[$identity_key];
                }
            }
        }

        if (!array_key_exists('name', $data) || !array_key_exists('email', $data) || !array_key_exists('phone', $data)) {
            return false;
        }

        $data['name'] = CWSB_Utils::normalize_text($data['name']);
        $data['email'] = CWSB_Utils::normalize_text($data['email']);
        $data['phone'] = CWSB_Utils::normalize_phone($data['phone']);

        if ($data['name'] === '' || $data['email'] === '' || $data['phone'] === '') {
            return false;
        }

        $insert_data = array_merge(['user_id' => $user_id], $data);
        $inserted = $wpdb->insert($table, $insert_data);
        if ($inserted === false) {
            return false;
        }

        $phones_to_invalidate = [];
        $flows_to_invalidate = [];
        if (isset($data['phone'])) {
            $phones_to_invalidate[] = $data['phone'];
        }
        if (isset($data['flow_token'])) {
            $flows_to_invalidate[] = $data['flow_token'];
        }

        self::invalidate_seller_read_caches($user_id, $phones_to_invalidate, $flows_to_invalidate);
        return true;
    }

    /**
     * Ensures a state row exists for seller identified by phone.
     *
     * Existing rows are partially updated (flow_token/code only when provided).
     */
    public static function insert_seller_state_by_phone($phone, $state = [])
    {
        global $wpdb;

        $normalized_phone = CWSB_Utils::normalize_phone($phone);
        if ($normalized_phone === '') {
            return null;
        }

        // Fast path: if a state row already exists for this phone, update it directly
        // without resolving through heavy user/usermeta joins.
        $existing_state = self::find_state_seller_by_phone($normalized_phone);
        if ($existing_state && isset($existing_state['user_id'])) {
            $user_id = (int) $existing_state['user_id'];
            if ($user_id > 0) {
                $table = self::state_table_name();
                $updates = [];
                foreach (['flow_token', 'code', 'reset_token', 'reset_token_expiry', 'session_active_until'] as $key) {
                    if (array_key_exists($key, (array) $state)) {
                        $updates[$key] = $state[$key];
                    }
                }

                if (array_key_exists('flow_token', $updates)) {
                    $updates['flow_token'] = CWSB_Utils::normalize_text($updates['flow_token']);
                }

                if (!empty($updates)) {
                    $wpdb->update($table, $updates, ['user_id' => $user_id]);
                }

                self::invalidate_seller_read_caches(
                    $user_id,
                    [$normalized_phone],
                    [isset($updates['flow_token']) ? $updates['flow_token'] : '']
                );

                return self::find_state_seller_by_phone($normalized_phone);
            }
        }

        $seller = self::find_vendor_by_phone($phone);
        if (!$seller || !isset($seller['user_id'])) {
            return null;
        }

        $user_id = (int) $seller['user_id'];
        if ($user_id <= 0) {
            return null;
        }

        $table = self::state_table_name();
        $previous_state = self::get_state_by_user_id($user_id);
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT user_id FROM {$table} WHERE user_id = %d LIMIT 1", $user_id)
        );

        if ($exists > 0) {
            // Prepare a partial update payload for existing seller state row.
            $updates = [];
            // If flow_token is provided, refresh it on the existing state row.
            if (array_key_exists('flow_token', (array) $state)) {
                $updates['flow_token'] = CWSB_Utils::normalize_text($state['flow_token']);
            }
            // If code is provided, update it on the existing state row.
            if (array_key_exists('code', (array) $state)) {
                $updates['code'] = $state['code'];
            }

            // Execute DB update only when at least one field is present.
            if (!empty($updates)) {
                $wpdb->update($table, $updates, ['user_id' => $user_id]);
            }

            $phones_to_invalidate = [
                isset($seller['phone']) ? $seller['phone'] : '',
                isset($previous_state['phone']) ? $previous_state['phone'] : '',
            ];
            $flows_to_invalidate = [
                isset($previous_state['flow_token']) ? $previous_state['flow_token'] : '',
                isset($updates['flow_token']) ? $updates['flow_token'] : '',
            ];

            self::invalidate_seller_read_caches($user_id, $phones_to_invalidate, $flows_to_invalidate);

            // Return latest state-based snapshot after update.
            return self::find_state_seller_by_phone($normalized_phone);
        }

        $base_state = [
            'name' => isset($seller['name']) ? (string) $seller['name'] : '',
            'email' => isset($seller['email']) ? (string) $seller['email'] : '',
            'phone' => isset($seller['phone']) ? (string) $seller['phone'] : '',
        ];

        $ok = self::save_seller_state($user_id, array_merge($base_state, (array) $state));
        if (!$ok) {
            return null;
        }

        return self::find_state_seller_by_phone($normalized_phone);
    }

    /**
     * Finds seller by user id and merges identity with state values.
     */
    public static function find_vendor_by_user_id($user_id)
    {
        global $wpdb;

        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return null;
        }

        $cache_key = self::seller_user_cache_key($user_id);
        $cache_hit = false;
        $cached = CWSB_Cache::get($cache_key, $cache_hit);
        if ($cache_hit) {
            return $cached;
        }

        $cap_key = $wpdb->prefix . 'capabilities';
        $sql = "
            SELECT
                u.ID AS user_id,
                u.display_name AS name,
                u.user_email AS email,
                COALESCE(billing_phone.meta_value, phone.meta_value, wcfm_phone.meta_value, '') AS phone
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->usermeta} caps
                ON caps.user_id = u.ID
               AND caps.meta_key = %s
            LEFT JOIN {$wpdb->usermeta} billing_phone
                ON billing_phone.user_id = u.ID
               AND billing_phone.meta_key = 'billing_phone'
            LEFT JOIN {$wpdb->usermeta} phone
                ON phone.user_id = u.ID
               AND phone.meta_key = 'phone'
            LEFT JOIN {$wpdb->usermeta} wcfm_phone
                ON wcfm_phone.user_id = u.ID
               AND wcfm_phone.meta_key = 'wcfm_phone'
            WHERE u.ID = %d
              AND caps.meta_value LIKE %s
            LIMIT 1
        ";

        $row = $wpdb->get_row(
            $wpdb->prepare($sql, $cap_key, $user_id, self::vendor_capability_like()),
            ARRAY_A
        );

        if (!$row) {
            CWSB_Cache::set($cache_key, null);
            return null;
        }

        $row['phone'] = CWSB_Utils::normalize_phone(isset($row['phone']) ? $row['phone'] : '');
        // Attach auth-flow state fields from custom table.
        $state = self::get_state_by_user_id($user_id);
        $seller = self::normalize_seller_row(array_merge($row, $state));
        CWSB_Cache::set($cache_key, $seller);
        return $seller;
    }

    /**
     * Finds seller by current flow token value.
     */
    public static function find_vendor_by_flow_token($flow_token)
    {
        $token = CWSB_Utils::normalize_text($flow_token);
        if ($token === '') {
            return null;
        }

        $cache_key = self::seller_flow_cache_key($token);
        $cache_hit = false;
        $cached = CWSB_Cache::get($cache_key, $cache_hit);
        if ($cache_hit) {
            return $cached;
        }

        $user_id = self::find_user_id_by_flow_token($token);

        if ($user_id <= 0) {
            CWSB_Cache::set($cache_key, null);
            return null;
        }

        $seller = self::find_vendor_by_user_id($user_id);
        CWSB_Cache::set($cache_key, $seller);
        return $seller;
    }

    /**
     * Finds seller directly from state table by exact normalized phone.
     * Used by WELCOME screen routing to avoid heavy user/usermeta joins.
     * CACHED: 30 seconds (hottest code path, runs on every flow start)
     */
    public static function find_state_seller_by_phone($phone)
    {
        $normalized = CWSB_Utils::normalize_phone($phone);
        if ($normalized === '') {
            return null;
        }

        // OPTIMIZATION: Cache state-by-phone lookups aggressively (30s TTL).
        // This is the hottest code path (WELCOME screen routing).
        // Invalidated immediately on seller state mutations.
        return CWSB_Cache::with_cache(
            'seller-state-by-phone',
            $normalized,
            function () use ($normalized) {
                global $wpdb;
                $table = self::state_table_name();
                $row = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT user_id, name, email, phone, code, flow_token, reset_token, reset_token_expiry, session_active_until FROM {$table} WHERE phone = %s LIMIT 1",
                        $normalized
                    ),
                    ARRAY_A
                );

                if (!is_array($row)) {
                    return null;
                }

                return self::normalize_seller_row($row);
            },
            30  // TTL: 30 seconds
        );
    }

    /**
     * Updates seller code using flow token lookup.
     */
    public static function update_seller_code_by_flow_token($flow_token, $code)
    {
        $token = CWSB_Utils::normalize_text($flow_token);
        $new_code = CWSB_Utils::normalize_text($code);

        if ($token === '' || $new_code === '') {
            return null;
        }

        $uid = self::find_user_id_by_flow_token($token);
        if ($uid > 0) {
            $ok = self::save_seller_state($uid, [
                'code' => $new_code,
            ]);

            if (!$ok) {
                return null;
            }

            return self::find_vendor_by_user_id($uid);
        }

        return null;
    }

    /**
     * Sets (or clears when null) session expiration for flow token owner.
     */
    public static function set_session_active_until_by_flow_token($flow_token, $session_active_until)
    {
        $token = CWSB_Utils::normalize_text($flow_token);
        if ($token === '') {
            return null;
        }

        $uid = self::find_user_id_by_flow_token($token);
        if ($uid <= 0) {
            return null;
        }

        $ok = self::save_seller_state($uid, [
            'session_active_until' => $session_active_until,
        ]);

        if (!$ok) {
            return null;
        }

        return self::find_vendor_by_user_id($uid);
    }

    /**
     * Stores reset token and expiry for seller resolved by email.
     */
    public static function set_reset_token_by_email($email, $reset_token, $reset_token_expiry)
    {
        $seller = self::find_vendor_by_email($email);
        if (!$seller || !isset($seller['user_id'])) {
            return null;
        }

        $uid = (int) $seller['user_id'];
        $ok = self::save_seller_state($uid, [
            'reset_token' => (string) $reset_token,
            'reset_token_expiry' => (int) $reset_token_expiry,
        ]);

        if (!$ok) {
            return null;
        }

        return self::find_vendor_by_user_id($uid);
    }

    /**
     * Returns all seller users from DB by checking wp_capabilities contains wcfm_vendor.
     * Raw DB output (no Seller-model mapping).
     */
    public static function get_all_sellers($page = 1, $per_page = 50)
    {
        global $wpdb;

        $page = max(1, (int) $page);
        $per_page = (int) $per_page;
        if ($per_page <= 0) {
            $per_page = 50;
        }
        $per_page = min($per_page, 200);
        $offset = ($page - 1) * $per_page;

        $cap_key = $wpdb->prefix . 'capabilities';

        $sql = "
            SELECT
                u.ID AS user_id,
                u.display_name AS name,
                u.user_email AS email,
                COALESCE(billing_phone.meta_value, phone.meta_value, wcfm_phone.meta_value, '') AS phone
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->usermeta} caps
                ON caps.user_id = u.ID
               AND caps.meta_key = %s
            LEFT JOIN {$wpdb->usermeta} billing_phone
                ON billing_phone.user_id = u.ID
               AND billing_phone.meta_key = 'billing_phone'
            LEFT JOIN {$wpdb->usermeta} phone
                ON phone.user_id = u.ID
               AND phone.meta_key = 'phone'
            LEFT JOIN {$wpdb->usermeta} wcfm_phone
                ON wcfm_phone.user_id = u.ID
               AND wcfm_phone.meta_key = 'wcfm_phone'
            WHERE caps.meta_value LIKE %s
            ORDER BY u.ID DESC
            LIMIT %d OFFSET %d
        ";

        $prepared_sql = $wpdb->prepare(
            $sql,
            $cap_key,
            self::vendor_capability_like(),
            $per_page,
            $offset
        );

        $rows = $wpdb->get_results($prepared_sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $user_ids = [];
        foreach ($rows as $row) {
            if (isset($row['user_id'])) {
                $user_ids[] = (int) $row['user_id'];
            }
        }
        // Fetch states in one query to avoid N+1 lookups.
        $states = self::get_state_map_by_user_ids($user_ids);

        $sellers = [];
        foreach ($rows as $row) {
            $row['phone'] = CWSB_Utils::normalize_phone(isset($row['phone']) ? $row['phone'] : '');
            $uid = isset($row['user_id']) ? (int) $row['user_id'] : 0;
            $state = isset($states[$uid]) ? $states[$uid] : [];
            $sellers[] = self::normalize_seller_row(array_merge($row, $state));
        }

        return $sellers;
    }

    /**
     * Returns total number of sellers matching vendor capability.
     */
    public static function count_all_sellers()
    {
        global $wpdb;

        $cap_key = $wpdb->prefix . 'capabilities';
        $sql = "
            SELECT COUNT(1)
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->usermeta} caps
                ON caps.user_id = u.ID
               AND caps.meta_key = %s
            WHERE caps.meta_value LIKE %s
        ";

        return (int) $wpdb->get_var(
            $wpdb->prepare($sql, $cap_key, self::vendor_capability_like())
        );
    }

    /**
     * Returns one seller-state row by user id.
     */
    private static function get_state_by_user_id($user_id)
    {
        global $wpdb;

        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return [];
        }

        $table = self::state_table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT name, email, phone, code, flow_token, reset_token, reset_token_expiry, session_active_until FROM {$table} WHERE user_id = %d LIMIT 1",
                $user_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : [];
    }

    /**
     * Resolves user id currently bound to the given flow token.
     */
    private static function find_user_id_by_flow_token($flow_token)
    {
        global $wpdb;

        $token = CWSB_Utils::normalize_text($flow_token);
        if ($token === '') {
            return 0;
        }

        $table = self::state_table_name();
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT user_id FROM {$table} WHERE flow_token = %s LIMIT 1", $token)
        );
    }

    /**
     * Returns map: user_id => state row for a list of user ids.
     */
    private static function get_state_map_by_user_ids($user_ids)
    {
        global $wpdb;

        if (!is_array($user_ids) || empty($user_ids)) {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $user_ids)));
        $ids = array_filter($ids, function ($v) {
            return $v > 0;
        });

        if (empty($ids)) {
            return [];
        }

        $table = self::state_table_name();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT user_id, name, email, phone, code, flow_token, reset_token, reset_token_expiry, session_active_until FROM {$table} WHERE user_id IN ({$placeholders})";
        $prepared = $wpdb->prepare($sql, ...$ids);
        $rows = $wpdb->get_results($prepared, ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $uid = isset($row['user_id']) ? (int) $row['user_id'] : 0;
            if ($uid > 0) {
                $map[$uid] = $row;
            }
        }

        return $map;
    }

    /**
     * Casts DB values to stable API response types.
     */
    private static function normalize_seller_row($row)
    {
        return [
            'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : 0,
            'name' => isset($row['name']) ? (string) $row['name'] : '',
            'email' => isset($row['email']) ? (string) $row['email'] : '',
            'code' => isset($row['code']) ? ($row['code'] === null ? null : (string) $row['code']) : null,
            'phone' => isset($row['phone']) ? (string) $row['phone'] : '',
            'flow_token' => isset($row['flow_token']) ? ($row['flow_token'] === null ? null : (string) $row['flow_token']) : null,
            'reset_token' => isset($row['reset_token']) ? ($row['reset_token'] === null ? null : (string) $row['reset_token']) : null,
            'reset_token_expiry' => isset($row['reset_token_expiry']) && $row['reset_token_expiry'] !== null ? (int) $row['reset_token_expiry'] : null,
            'session_active_until' => isset($row['session_active_until']) && $row['session_active_until'] !== null ? (int) $row['session_active_until'] : null,
        ];
    }
}