<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PIN utility service backed by wp_cwsb_seller_state.code.
 */
class CWSB_Pin_Service
{
    private static function state_table_name()
    {
        if (class_exists('CWSB_Seller_Read_Repository') && method_exists('CWSB_Seller_Read_Repository', 'state_table_name')) {
            return CWSB_Seller_Read_Repository::state_table_name();
        }

        global $wpdb;
        return $wpdb->prefix . 'cwsb_seller_state';
    }

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
        global $wpdb;

        $seller_id = (int) $seller_id;
        if ($seller_id <= 0) {
            return false;
        }

        $table = self::state_table_name();
        $value = $wpdb->get_var(
            $wpdb->prepare("SELECT code FROM {$table} WHERE user_id = %d LIMIT 1", $seller_id)
        );

        return is_string($value) && trim($value) !== '';
    }

    /**
     * Hashes and stores seller PIN.
     */
    public static function setup_pin($seller_id, $pin)
    {
        global $wpdb;

        $seller_id = (int) $seller_id;
        if ($seller_id <= 0 || !self::is_valid_pin($pin)) {
            return false;
        }

        $hash = wp_hash_password((string) $pin);
        $table = self::state_table_name();

        $updated = $wpdb->update(
            $table,
            ['code' => $hash],
            ['user_id' => $seller_id],
            ['%s'],
            ['%d']
        );

        return $updated !== false && $updated > 0;
    }

    /**
     * Verifies a plain PIN against stored hash.
     */
    public static function verify_pin_for_seller($seller_id, $pin)
    {
        global $wpdb;

        $seller_id = (int) $seller_id;
        if ($seller_id <= 0 || !self::is_valid_pin($pin)) {
            return false;
        }

        $table = self::state_table_name();
        $hash = $wpdb->get_var(
            $wpdb->prepare("SELECT code FROM {$table} WHERE user_id = %d LIMIT 1", $seller_id)
        );

        if (!is_string($hash) || $hash === '') {
            return false;
        }

        return wp_check_password((string) $pin, $hash);
    }
}
