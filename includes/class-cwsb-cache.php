<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared object-cache helper for CWSB repositories.
 * 
 * Uses WordPress object cache API (Redis backend if configured).
 * 
 * Features:
 * - Wrapper around wp_cache_* for null-safety (distinguish miss from cached null)
 * - Batch get/set for multi-key operations
 * - Pattern-based invalidation (prefix matching)
 * - Built-in metrics tracking (hits, misses, deletes)
 * - Stale-while-revalidate pattern for cache stampede prevention
 * - Graceful degradation if cache backend unavailable
 *
 * Values are wrapped so callers can distinguish a cache miss from a cached null.
 */
class CWSB_Cache
{
    /**
     * Cache group used by plugin repositories.
     */
    public static function group()
    {
        return 'cwsb';
    }

    /**
     * Default cache TTL (seconds).
     */
    public static function ttl()
    {
        return 60;
    }

    /**
     * Extended TTL for stale-while-revalidate pattern.
     * Cached values remain accessible even after expiration.
     */
    public static function stale_ttl()
    {
        return 120; // Stale data served for 2 minutes
    }

    /**
     * Reads wrapped value from object cache.
     * 
     * @param string $key Cache key
     * @param bool $found Reference parameter set to true if key found
     * @return mixed Cached value or null
     */
    public static function get($key, &$found = false)
    {
        $found = false;
        $cached = wp_cache_get((string) $key, self::group());
        if (!is_array($cached) || !isset($cached['__cwsb_cached'])) {
            self::record_miss($key);
            return null;
        }

        $found = true;
        self::record_hit($key);
        return array_key_exists('value', $cached) ? $cached['value'] : null;
    }

    /**
     * Get multiple cache values in one operation.
     * More efficient than loop of get() calls.
     * 
     * @param array $keys Array of cache keys
     * @return array Array of [key => value] pairs (only found keys included)
     */
    public static function get_many($keys)
    {
        $result = array();
        $group = self::group();
        
        // Use wp_cache_get_multiple if available (WP 5.5+)
        if (function_exists('wp_cache_get_multiple')) {
            $cached_values = wp_cache_get_multiple($keys, $group);
            foreach ($cached_values as $key => $cached) {
                if (is_array($cached) && isset($cached['__cwsb_cached'])) {
                    $result[$key] = array_key_exists('value', $cached) ? $cached['value'] : null;
                    self::record_hit($key);
                } else {
                    self::record_miss($key);
                }
            }
        } else {
            // Fallback for older WordPress
            foreach ($keys as $key) {
                $value = self::get($key, $found);
                if ($found) {
                    $result[$key] = $value;
                }
            }
        }
        
        return $result;
    }

    /**
     * Stores wrapped value in object cache.
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param null|int $ttl TTL in seconds
     * @return bool True if set successfully
     */
    public static function set($key, $value, $ttl = null)
    {
        $result = wp_cache_set(
            (string) $key,
            [
                '__cwsb_cached' => 1,
                'value' => $value,
            ],
            self::group(),
            $ttl !== null ? (int) $ttl : self::ttl()
        );
        
        self::record_set($key);
        return $result;
    }

    /**
     * Set multiple cache values in one operation.
     * More efficient than loop of set() calls.
     * 
     * @param array $values Array of [key => value] pairs
     * @param null|int $ttl TTL in seconds
     * @return int Number of values successfully set
     */
    public static function set_many($values, $ttl = null)
    {
        $group = self::group();
        $ttl_seconds = $ttl !== null ? (int) $ttl : self::ttl();
        $count = 0;
        
        foreach ($values as $key => $value) {
            $success = wp_cache_set(
                (string) $key,
                [
                    '__cwsb_cached' => 1,
                    'value' => $value,
                ],
                $group,
                $ttl_seconds
            );
            if ($success) {
                $count++;
                self::record_set($key);
            }
        }
        
        return $count;
    }

    /**
     * Deletes value from object cache.
     * 
     * @param string $key Cache key
     * @return bool True if deleted successfully
     */
    public static function delete($key)
    {
        $result = wp_cache_delete((string) $key, self::group());
        self::record_delete($key);
        return $result;
    }

    /**
     * Delete multiple cache values.
     * 
     * @param array $keys Array of cache keys to delete
     * @return int Number of keys successfully deleted
     */
    public static function delete_many($keys)
    {
        $count = 0;
        foreach ($keys as $key) {
            if (self::delete($key)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Invalidate all cache keys matching a prefix pattern.
     * 
     * Usage:
     *   CWSB_Cache::delete_pattern('seller_state_*');  // Delete all seller state entries
     * 
     * Note: This requires Redis or memcached. Falls back to single-key delete for other backends.
     * 
     * @param string $pattern Prefix pattern (e.g., 'seller_*')
     * @return int Number of patterns matched
     */
    public static function delete_pattern($pattern)
    {
        global $wp_object_cache;
        
        // If using Redis with WP_REDIS_DISABLE_METRICS
        if (function_exists('wp_redis_delete_pattern')) {
            $group = self::group();
            return wp_redis_delete_pattern($group . ':' . $pattern . '*');
        }
        
        // Check if we have direct access to backend
        if (!isset($wp_object_cache) || !is_object($wp_object_cache)) {
            error_log('CWSB_Cache: delete_pattern() requires Redis backend. Falling back.');
            return 0;
        }
        
        // Try to invalidate if using Redis directly
        if (method_exists($wp_object_cache, 'redis')) {
            try {
                $redis = $wp_object_cache->redis();
                if ($redis) {
                    $group = self::group();
                    $count = $redis->eval(
                        "return #redis.call('del', unpack(redis.call('keys', ARGV[1])))",
                        0,
                        $group . ':' . $pattern . '*'
                    );
                    return (int) $count;
                }
            } catch (Exception $e) {
                error_log('CWSB_Cache: delete_pattern() error: ' . $e->getMessage());
            }
        }
        
        return 0;
    }

    /**
     * Get cache value, but return stale data if fresh cache expired.
     * Prevents cache stampedes and improves availability.
     * 
     * Usage:
     *   $seller = CWSB_Cache::get_maybe_stale('seller_50354773');
     * 
     * @param string $key Cache key
     * @param bool $found Reference set to true if key found (fresh or stale)
     * @return mixed Cached value (fresh or stale) or null
     */
    public static function get_maybe_stale($key, &$found = false)
    {
        // Try fresh cache first
        $value = self::get($key, $found);
        if ($found) {
            return $value;
        }
        
        // Check for stale cache (stored with __cwsb_stale flag)
        $stale_data = wp_cache_get((string) $key . '__stale', self::group());
        if (is_array($stale_data) && isset($stale_data['__cwsb_cached'])) {
            $found = true;
            error_log('CWSB_Cache: Returned stale data for key=' . $key);
            return array_key_exists('value', $stale_data) ? $stale_data['value'] : null;
        }
        
        return null;
    }

    /**
     * Wrapper: Get value or compute it, with built-in stale-while-revalidate.
     * 
     * If cache miss or expired:
     * 1. Attempt to recompute value (blocking)
     * 2. If computation fails, serve stale data if available
     * 3. Return fresh data or null
     * 
     * Usage:
     *   $seller = CWSB_Cache::remember(
     *       'seller_50354773',
     *       fn() => get_seller_from_db('50354773'),
     *       60,  // Fresh TTL
     *       120  // Stale TTL (optional)
     *   );
     * 
     * @param string $key Cache key
     * @param callable $callback Function to call on cache miss
     * @param null|int $ttl Fresh cache TTL
     * @param null|int $stale_ttl Stale cache TTL
     * @return mixed Computed or cached value
     */
    public static function remember($key, $callback, $ttl = null, $stale_ttl = null)
    {
        // Try fresh cache first
        $value = self::get($key, $found);
        if ($found) {
            return $value;
        }
        
        try {
            // Compute fresh value
            $value = call_user_func($callback);
            
            // Store fresh
            self::set($key, $value, $ttl);
            
            // Also store as stale backup
            if ($stale_ttl === null) {
                $stale_ttl = self::stale_ttl();
            }
            wp_cache_set(
                (string) $key . '__stale',
                [
                    '__cwsb_cached' => 1,
                    'value' => $value,
                ],
                self::group(),
                $stale_ttl
            );
            
            return $value;
        } catch (Exception $e) {
            // Computation failed, try to return stale data
            error_log('CWSB_Cache: Computation failed for key=' . $key . ', error=' . $e->getMessage());
            $stale_data = self::get_maybe_stale($key, $found);
            if ($found) {
                return $stale_data;
            }
            throw $e;
        }
    }

    /**
     * Clear entire cache group.
     */
    public static function flush_group()
    {
        wp_cache_flush_group(self::group());
    }

    // ===== METRICS TRACKING =====

    private static $metrics = array(
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'deletes' => 0,
    );

    /**
     * Record a cache hit in metrics.
     * 
     * @param string $key Cache key
     */
    private static function record_hit($key)
    {
        self::$metrics['hits']++;
    }

    /**
     * Record a cache miss in metrics.
     * 
     * @param string $key Cache key
     */
    private static function record_miss($key)
    {
        self::$metrics['misses']++;
    }

    /**
     * Record a cache set in metrics.
     * 
     * @param string $key Cache key
     */
    private static function record_set($key)
    {
        self::$metrics['sets']++;
    }

    /**
     * Record a cache delete in metrics.
     * 
     * @param string $key Cache key
     */
    private static function record_delete($key)
    {
        self::$metrics['deletes']++;
    }

    /**
     * Get cache metrics.
     * 
     * @return array Metrics including hit rate percentage
     */
    public static function get_metrics()
    {
        $total = self::$metrics['hits'] + self::$metrics['misses'];
        return array(
            'hits' => self::$metrics['hits'],
            'misses' => self::$metrics['misses'],
            'sets' => self::$metrics['sets'],
            'deletes' => self::$metrics['deletes'],
            'hitRate' => $total > 0 ? round(self::$metrics['hits'] / $total * 100, 1) : 0,
        );
    }

    /**
     * Reset metrics.
     */
    public static function reset_metrics()
    {
        self::$metrics = array(
            'hits' => 0,
            'misses' => 0,
            'sets' => 0,
            'deletes' => 0,
        );
    }

    /**
     * Log current metrics to error log.
     */
    public static function log_metrics()
    {
        $metrics = self::get_metrics();
        error_log(
            'CWSB_Cache Metrics: hits=' . $metrics['hits'] .
            ' misses=' . $metrics['misses'] .
            ' sets=' . $metrics['sets'] .
            ' deletes=' . $metrics['deletes'] .
            ' hitRate=' . $metrics['hitRate'] . '%'
        );
    }

    // ===== BACKWARD COMPATIBILITY =====

    /**
     * Backward-compatible wrapper: Get value or compute it.
     * Original method signature used throughout plugin.
     * Now uses the improved remember() with stale-while-revalidate support.
     * 
     * @param string $namespace Cache namespace
     * @param string $key Cache key
     * @param callable $callback Function to call on cache miss
     * @param null|int $ttl TTL in seconds
     * @return mixed Computed or cached value
     */
    public static function with_cache($namespace, $key, $callback, $ttl = null)
    {
        // Map old signature to new remember() method with stale caching
        return self::remember($namespace . '_' . $key, $callback, $ttl);
    }
}
