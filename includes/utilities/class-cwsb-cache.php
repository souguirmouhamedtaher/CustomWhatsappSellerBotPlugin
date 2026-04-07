<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Cache_Backend')) {
    require_once __DIR__ . '/class-cwsb-cache-backend.php';
}

if (!class_exists('CWSB_Cache_Metrics')) {
    require_once __DIR__ . '/class-cwsb-cache-metrics.php';
}

/**
 * Shared object-cache helper for CWSB repositories.
 */
class CWSB_Cache
{
    private static function disabled()
    {
        if (defined('CWSB_DISABLE_PLUGIN_CACHE') && CWSB_DISABLE_PLUGIN_CACHE) {
            return true;
        }

        $raw = getenv('CWSB_DISABLE_PLUGIN_CACHE');
        if (!is_string($raw)) {
            return false;
        }

        $value = strtolower(trim($raw));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private static function normalize_key($key)
    {
        $normalized = trim((string) $key);
        return $normalized === '' ? null : $normalized;
    }

    private static function stale_key($key)
    {
        return $key . '__stale';
    }

    public static function group()
    {
        return 'cwsb';
    }

    public static function ttl()
    {
        return 60;
    }

    public static function stale_ttl()
    {
        return 120;
    }

    public static function get($key, &$found = false)
    {
        if (self::disabled()) {
            $found = false;
            return null;
        }

        $cache_key = self::normalize_key($key);
        if ($cache_key === null) {
            $found = false;
            CWSB_Cache_Metrics::record_miss();
            return null;
        }

        $found = false;
        $cached = wp_cache_get($cache_key, self::group());
        if (!is_array($cached) || !isset($cached['__cwsb_cached'])) {
            CWSB_Cache_Metrics::record_miss();
            return null;
        }

        $found = true;
        CWSB_Cache_Metrics::record_hit();
        return array_key_exists('value', $cached) ? $cached['value'] : null;
    }

    public static function get_many($keys)
    {
        if (self::disabled()) {
            return [];
        }

        $result = [];
        $group = self::group();
        $normalized_keys = [];

        foreach ((array) $keys as $key) {
            $cache_key = self::normalize_key($key);
            if ($cache_key === null) {
                CWSB_Cache_Metrics::record_miss();
                continue;
            }
            $normalized_keys[] = $cache_key;
        }

        if (empty($normalized_keys)) {
            return $result;
        }

        if (function_exists('wp_cache_get_multiple')) {
            $cached_values = wp_cache_get_multiple($normalized_keys, $group);
            foreach ($cached_values as $key => $cached) {
                if (is_array($cached) && isset($cached['__cwsb_cached'])) {
                    $result[$key] = array_key_exists('value', $cached) ? $cached['value'] : null;
                    CWSB_Cache_Metrics::record_hit();
                } else {
                    CWSB_Cache_Metrics::record_miss();
                }
            }
            return $result;
        }

        foreach ($normalized_keys as $key) {
            $value = self::get($key, $found);
            if ($found) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public static function set($key, $value, $ttl = null)
    {
        if (self::disabled()) {
            return true;
        }

        $cache_key = self::normalize_key($key);
        if ($cache_key === null) {
            return false;
        }

        $result = wp_cache_set(
            $cache_key,
            [
                '__cwsb_cached' => 1,
                'value' => $value,
            ],
            self::group(),
            $ttl !== null ? (int) $ttl : self::ttl()
        );

        wp_cache_delete(self::stale_key($cache_key), self::group());

        CWSB_Cache_Metrics::record_set();
        return $result;
    }

    public static function set_many($values, $ttl = null)
    {
        if (self::disabled()) {
            return is_array($values) ? count($values) : 0;
        }

        $group = self::group();
        $ttl_seconds = $ttl !== null ? (int) $ttl : self::ttl();
        $count = 0;

        foreach ($values as $key => $value) {
            $cache_key = self::normalize_key($key);
            if ($cache_key === null) {
                continue;
            }

            $success = wp_cache_set(
                $cache_key,
                [
                    '__cwsb_cached' => 1,
                    'value' => $value,
                ],
                $group,
                $ttl_seconds
            );
            if ($success) {
                wp_cache_delete(self::stale_key($cache_key), $group);
                $count++;
                CWSB_Cache_Metrics::record_set();
            }
        }

        return $count;
    }

    public static function delete($key)
    {
        if (self::disabled()) {
            return true;
        }

        $cache_key = self::normalize_key($key);
        if ($cache_key === null) {
            return false;
        }

        $group = self::group();
        wp_cache_delete(self::stale_key($cache_key), $group);
        $result = wp_cache_delete($cache_key, $group);
        CWSB_Cache_Metrics::record_delete();
        return $result;
    }

    public static function delete_many($keys)
    {
        if (self::disabled()) {
            return is_array($keys) ? count($keys) : 0;
        }

        $count = 0;
        foreach ($keys as $key) {
            if (self::delete($key)) {
                $count++;
            }
        }
        return $count;
    }

    public static function delete_pattern($pattern)
    {
        if (self::disabled()) {
            return 0;
        }

        return CWSB_Cache_Backend::delete_pattern(self::group(), $pattern);
    }

    public static function get_maybe_stale($key, &$found = false)
    {
        return self::get($key, $found);
    }

    public static function remember($key, $callback, $ttl = null, $stale_ttl = null)
    {
        if (self::disabled()) {
            return call_user_func($callback);
        }

        $cache_key = self::normalize_key($key);
        if ($cache_key === null) {
            return call_user_func($callback);
        }

        $value = self::get($cache_key, $found);
        if ($found) {
            return $value;
        }

        try {
            $value = call_user_func($callback);
            self::set($cache_key, $value, $ttl);

            return $value;
        } catch (Exception $e) {
            error_log('CWSB_Cache: Computation failed for key=' . $cache_key . ', error=' . $e->getMessage());
            throw $e;
        }
    }

    public static function flush_group()
    {
        if (self::disabled()) {
            return;
        }

        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group(self::group());
            return;
        }

        wp_cache_flush();
    }

    public static function get_metrics()
    {
        return CWSB_Cache_Metrics::get_metrics();
    }

    public static function reset_metrics()
    {
        CWSB_Cache_Metrics::reset_metrics();
    }

    public static function log_metrics()
    {
        CWSB_Cache_Metrics::log_metrics();
    }

    public static function with_cache($namespace, $key, $callback, $ttl = null)
    {
        return self::remember($namespace . '_' . $key, $callback, $ttl);
    }
}
