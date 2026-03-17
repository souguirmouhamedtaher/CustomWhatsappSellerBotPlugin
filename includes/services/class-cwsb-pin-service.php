<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PIN utility service backed by user meta storage.
 */
class CWSB_Pin_Service
{
    // User meta key used to store hashed seller PIN values.
    private static $meta_key = 'cwsb_pin_code';

    /**
     * Checks whether PIN is exactly 4 digits.
     */
    public static function is_valid_pin($pin)
    {
        return preg_match('/^\d{4}$/', (string) $pin) === 1;
    }

    /**
     * Returns true when seller already has a stored PIN hash.
     */
    public static function has_pin_for_seller($seller_id)
    {
        $seller_id = (int) $seller_id;
        if ($seller_id <= 0) {
            return false;
        }

        $value = get_user_meta($seller_id, self::$meta_key, true);
        return is_string($value) && $value !== '';
    }

    /**
     * Hashes and stores seller PIN.
     */
    public static function setup_pin($seller_id, $pin)
    {
        $seller_id = (int) $seller_id;
        if ($seller_id <= 0 || !self::is_valid_pin($pin)) {
            return false;
        }

        $hash = wp_hash_password((string) $pin);
        return update_user_meta($seller_id, self::$meta_key, $hash) !== false;
    }

    /**
     * Verifies a plain PIN against stored hash.
     */
    public static function verify_pin_for_seller($seller_id, $pin)
    {
        $seller_id = (int) $seller_id;
        if ($seller_id <= 0 || !self::is_valid_pin($pin)) {
            return false;
        }

        $hash = get_user_meta($seller_id, self::$meta_key, true);
        if (!is_string($hash) || $hash === '') {
            return false;
        }

        return wp_check_password((string) $pin, $hash);
    }
}
