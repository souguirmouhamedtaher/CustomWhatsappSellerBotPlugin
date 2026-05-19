<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

if (!class_exists('CWSB_Seller_Read_Repository')) {
    require_once __DIR__ . '/class-cwsb-seller-read-repository.php';
}

/**
 * Write/update side of seller repository.
 */
class CWSB_Seller_State_Writer
{
    public static function save_seller_state($user_id, $state = [])
    {
        global $wpdb;

        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return false;
        }

        $table = CWSB_Seller_Read_Repository::state_table_name();
        $allowed = [
            'name',
            'email',
            'phone',
            'code',
            'flow_token',
            'reset_token',
            'reset_token_expiry',
            'session_active_until',
            'auth_portal_sent_at',
            'seller_status',
            'blocked_reason',
            'blocked_at',
            'blocked_by',
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
            return true;
        }

        $previous_state = CWSB_Seller_Read_Repository::get_state_by_user_id($user_id);

        $exists = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT user_id FROM {$table} WHERE user_id = %d LIMIT 1", $user_id)
        );

        $bigint_fields = ['reset_token_expiry', 'session_active_until', 'auth_portal_sent_at', 'blocked_at'];
        $data_formats = [];
        foreach (array_keys($data) as $k) {
            $data_formats[] = in_array($k, $bigint_fields, true) ? '%d' : '%s';
        }

        if ($exists > 0) {
            $updated = $wpdb->update($table, $data, ['user_id' => $user_id], $data_formats, ['%d']);
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

            return true;
        }

        $missing_identity = [];
        foreach (['name', 'email', 'phone'] as $identity_key) {
            if (!array_key_exists($identity_key, $data) || $data[$identity_key] === null || CWSB_Utils::normalize_text($data[$identity_key]) === '') {
                $missing_identity[] = $identity_key;
            }
        }

        if (!empty($missing_identity)) {
            $seller_identity = CWSB_Seller_Read_Repository::find_vendor_by_user_id($user_id);
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
        $insert_formats = ['%d']; // user_id
        foreach (array_keys($data) as $k) {
            $insert_formats[] = in_array($k, $bigint_fields, true) ? '%d' : '%s';
        }
        $inserted = $wpdb->insert($table, $insert_data, $insert_formats);
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

        return true;
    }

    public static function insert_seller_state_by_phone($phone, $state = [])
    {
        global $wpdb;

        $normalized_phone = CWSB_Utils::normalize_phone($phone);
        if ($normalized_phone === '') {
            return null;
        }

        $existing_state = CWSB_Seller_Read_Repository::find_state_seller_by_phone($normalized_phone);
        if ($existing_state && isset($existing_state['user_id'])) {
            $user_id = (int) $existing_state['user_id'];
            if ($user_id > 0) {
                $table = CWSB_Seller_Read_Repository::state_table_name();
                $updates = [];
                foreach (['flow_token', 'code', 'reset_token', 'reset_token_expiry', 'session_active_until', 'auth_portal_sent_at', 'seller_status', 'blocked_reason', 'blocked_at', 'blocked_by'] as $key) {
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

                return CWSB_Seller_Read_Repository::find_state_seller_by_phone($normalized_phone);
            }
        }

        $seller = CWSB_Seller_Read_Repository::find_vendor_by_phone($phone);
        if (!$seller || !isset($seller['user_id'])) {
            return null;
        }

        $user_id = (int) $seller['user_id'];
        if ($user_id <= 0) {
            return null;
        }

        $table = CWSB_Seller_Read_Repository::state_table_name();
        $previous_state = CWSB_Seller_Read_Repository::get_state_by_user_id($user_id);
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT user_id FROM {$table} WHERE user_id = %d LIMIT 1", $user_id)
        );

        if ($exists > 0) {
            $updates = [];
            if (array_key_exists('flow_token', (array) $state)) {
                $updates['flow_token'] = CWSB_Utils::normalize_text($state['flow_token']);
            }
            if (array_key_exists('code', (array) $state)) {
                $updates['code'] = $state['code'];
            }
            if (array_key_exists('reset_token', (array) $state)) {
                $updates['reset_token'] = $state['reset_token'];
            }
            if (array_key_exists('reset_token_expiry', (array) $state)) {
                $updates['reset_token_expiry'] = $state['reset_token_expiry'];
            }
            if (array_key_exists('session_active_until', (array) $state)) {
                $updates['session_active_until'] = $state['session_active_until'];
            }
            if (array_key_exists('auth_portal_sent_at', (array) $state)) {
                $updates['auth_portal_sent_at'] = $state['auth_portal_sent_at'];
            }

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

            return CWSB_Seller_Read_Repository::find_state_seller_by_phone($normalized_phone);
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

        return CWSB_Seller_Read_Repository::find_state_seller_by_phone($normalized_phone);
    }

    public static function update_seller_code_by_flow_token($flow_token, $code)
    {
        $token = CWSB_Utils::normalize_text($flow_token);
        $new_code = CWSB_Utils::normalize_text($code);

        if ($token === '' || $new_code === '') {
            return null;
        }

        $uid = CWSB_Seller_Read_Repository::find_user_id_by_flow_token($token);
        if ($uid > 0) {
            $ok = self::save_seller_state($uid, ['code' => $new_code]);
            if (!$ok) {
                return null;
            }
            return CWSB_Seller_Read_Repository::find_vendor_by_user_id($uid);
        }

        return null;
    }

    public static function set_session_active_until_by_flow_token($flow_token, $session_active_until)
    {
        $token = CWSB_Utils::normalize_text($flow_token);
        if ($token === '') {
            return null;
        }

        $uid = CWSB_Seller_Read_Repository::find_user_id_by_flow_token($token);
        if ($uid <= 0) {
            return null;
        }

        $state_update = ['session_active_until' => $session_active_until];
        if ($session_active_until !== null && (int) $session_active_until > 0) {
            $state_update['auth_portal_sent_at'] = null;
        }

        $ok = self::save_seller_state($uid, $state_update);
        if (!$ok) {
            return null;
        }

        return CWSB_Seller_Read_Repository::find_vendor_by_user_id($uid);
    }

    public static function set_reset_token_by_email($email, $reset_token, $reset_token_expiry)
    {
        global $wpdb;

        $mail = CWSB_Utils::normalize_text($email);
        if ($mail === '') {
            return null;
        }

        $table = CWSB_Seller_Read_Repository::state_table_name();

        $uid = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT user_id FROM {$table} WHERE LOWER(email) = LOWER(%s) LIMIT 1",
                $mail
            )
        );

        if ($uid <= 0) {
            error_log('[CWSB] set_reset_token_by_email: state-table miss for email=' . $mail . ' wpdb_err=' . $wpdb->last_error);
            return null;
        }

        $expiry  = PHP_INT_SIZE >= 8 ? (int) $reset_token_expiry : (float) $reset_token_expiry;
        $updated = $wpdb->update(
            $table,
            [
                'reset_token'        => (string) $reset_token,
                'reset_token_expiry' => $expiry,
            ],
            ['user_id' => $uid],
            ['%s', '%d'],
            ['%d']
        );

        if ($updated === false) {
            error_log('[CWSB] set_reset_token_by_email: update failed for uid=' . $uid . ' wpdb_err=' . $wpdb->last_error);
            return null;
        }

        return CWSB_Seller_Read_Repository::find_state_seller_by_email($mail);
    }

    public static function mark_auth_portal_sent_by_phone($phone, $sent_at)
    {
        $normalized_phone = CWSB_Utils::normalize_phone($phone);
        if ($normalized_phone === '') {
            return null;
        }

        $seller = CWSB_Seller_Read_Repository::find_state_seller_by_phone($normalized_phone);
        if (!$seller || !isset($seller['user_id'])) {
            return null;
        }

        $uid = (int) $seller['user_id'];
        if ($uid <= 0) {
            return null;
        }

        $timestamp = (int) $sent_at;
        if ($timestamp <= 0) {
            return null;
        }

        $ok = self::save_seller_state($uid, ['auth_portal_sent_at' => $timestamp]);
        if (!$ok) {
            return null;
        }

        return CWSB_Seller_Read_Repository::find_vendor_by_user_id($uid);
    }
}
