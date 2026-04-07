<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Backend-specific cache helpers.
 */
class CWSB_Cache_Backend
{
    /**
     * Delete by pattern using Redis-safe SCAN when available.
     */
    public static function delete_pattern($group, $pattern)
    {
        global $wp_object_cache;

        // Preferred drop-in helper.
        if (function_exists('wp_redis_delete_pattern')) {
            return (int) wp_redis_delete_pattern($group . ':' . $pattern . '*');
        }

        if (!isset($wp_object_cache) || !is_object($wp_object_cache)) {
            error_log('CWSB_Cache: delete_pattern() requires Redis backend. Falling back.');
            return 0;
        }

        if (!method_exists($wp_object_cache, 'redis')) {
            return 0;
        }

        try {
            $redis = $wp_object_cache->redis();
            if (!$redis) {
                return 0;
            }

            $pattern_key = $group . ':' . $pattern . '*';
            $count = 0;
            $cursor = 0;

            do {
                $result = $redis->scan($cursor, 'MATCH', $pattern_key, 'COUNT', 100);
                if (!is_array($result) || count($result) < 2) {
                    break;
                }

                $cursor = (int) $result[0];
                $keys = $result[1] ?? [];
                if (!empty($keys)) {
                    $count += (int) $redis->del(...$keys);
                }
            } while ($cursor !== 0);

            return $count;
        } catch (Exception $e) {
            error_log('CWSB_Cache: delete_pattern() error: ' . $e->getMessage());
            return 0;
        }
    }
}
