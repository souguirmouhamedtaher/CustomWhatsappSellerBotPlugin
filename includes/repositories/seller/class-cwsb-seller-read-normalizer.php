<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

/**
 * Seller row normalization helpers.
 */
class CWSB_Seller_Read_Normalizer
{
    public static function normalize_seller_row($row)
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
            'auth_portal_sent_at' => isset($row['auth_portal_sent_at']) && $row['auth_portal_sent_at'] !== null ? (int) $row['auth_portal_sent_at'] : null,
        ];
    }
}
