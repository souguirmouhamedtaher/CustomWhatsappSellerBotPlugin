<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Captures object-cache diagnostic snapshots at plugin bootstrap time and
 * exposes them via a REST endpoint so they are visible on staging.
 *
 * Endpoint: GET /wp-json/whatsapp-bot/v1/debug/cache-state
 * Header  : x-api-key: <WP_PLUGIN_API_KEY>
 */
class CWSB_Cache_Probe
{
    /** @var array<string, array> */
    private static $snapshots = [];

    // -------------------------------------------------------------------------
    // Snapshot capture
    // -------------------------------------------------------------------------

    /**
     * Capture a snapshot of the object-cache environment.
     *
     * @param string $phase Human-readable label ('before_class_load', 'after_class_load', 'during_request').
     */
    public static function capture($phase)
    {
        global $wp_object_cache;

        self::$snapshots[$phase] = [
            'phase'     => $phase,
            'timestamp' => microtime(true),

            // --- Object cache backend ---
            'wp_object_cache_class'      => isset($wp_object_cache) && is_object($wp_object_cache)
                                            ? get_class($wp_object_cache)
                                            : null,
            'wp_using_ext_object_cache'  => function_exists('wp_using_ext_object_cache')
                                            ? wp_using_ext_object_cache()
                                            : null,
            'object_cache_dropin_exists' => file_exists(WP_CONTENT_DIR . '/object-cache.php'),

            // --- cwsb group persistence ---
            'cwsb_group_non_persistent'  => self::probe_non_persistent_group(),

            // --- Relevant constants ---
            'CWSB_DISABLE_PLUGIN_CACHE'  => defined('CWSB_DISABLE_PLUGIN_CACHE')
                                            ? CWSB_DISABLE_PLUGIN_CACHE
                                            : '(not defined)',
            'REST_REQUEST'               => defined('REST_REQUEST')  ? REST_REQUEST  : '(not defined)',
            'DONOTCACHEPAGE'             => defined('DONOTCACHEPAGE') ? DONOTCACHEPAGE : '(not defined)',
            'DONOTCACHEDB'               => defined('DONOTCACHEDB')   ? DONOTCACHEDB   : '(not defined)',

            // --- Live write/read probe for the cwsb group ---
            'cache_write_read_test'      => self::probe_cache_rw(),
        ];
    }

    // -------------------------------------------------------------------------
    // Probe helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether the 'cwsb' group is registered as non-persistent in the
     * active object-cache backend.
     *
     * @return bool|null  true = confirmed non-persistent, false = persistent, null = unknown
     */
    private static function probe_non_persistent_group()
    {
        global $wp_object_cache;

        if (!isset($wp_object_cache) || !is_object($wp_object_cache)) {
            return null;
        }

        // Most drop-ins (WP Redis, WP Object Cache, Memcached) expose this property.
        foreach (['non_persistent_groups', 'non_persistent_cache_groups', 'ignored_groups'] as $prop) {
            if (property_exists($wp_object_cache, $prop)) {
                $groups = (array) $wp_object_cache->$prop;
                return in_array('cwsb', $groups, true);
            }
        }

        return null; // property not found on this backend
    }

    /**
     * Write a short-lived test value to the 'cwsb' group and read it back.
     * Tells us whether reads/writes to this group actually persist  within the
     * same request.
     *
     * @return array{wrote: string, read: mixed, found: bool, match: bool}
     */
    private static function probe_cache_rw()
    {
        $key   = '_cwsb_probe_' . microtime(true);
        $group = 'cwsb';
        $value = 'probe_' . wp_rand(1000, 9999);

        wp_cache_set($key, $value, $group, 5);
        $found = false;
        $read  = wp_cache_get($key, $group, false, $found);
        wp_cache_delete($key, $group);

        return [
            'wrote' => $value,
            'read'  => $read,
            'found' => (bool) $found,
            'match' => ($read === $value),
        ];
    }

    // -------------------------------------------------------------------------
    // REST endpoint
    // -------------------------------------------------------------------------

    /**
     * Register the diagnostic REST endpoint.
     * Called via add_action('rest_api_init', ...) after all classes are loaded.
     */
    public static function register_endpoint()
    {
        register_rest_route(CWSB_NS, '/debug/cache-state', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [static::class, 'endpoint_response'],
            'permission_callback' => ['CWSB_Auth_Middleware', 'require_api_key'],
        ]);
    }

    /**
     * REST endpoint callback.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function endpoint_response(WP_REST_Request $request)
    {
        // Capture live state at the moment the request is served.
        self::capture('during_request');

        global $wpdb;

        // Read the API key directly from DB (bypass object cache, same as the middleware fix).
        $db_api_key = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            'cwsb_api_key'
        ));

        return new WP_REST_Response([
            'server_time'          => gmdate('Y-m-d H:i:s') . ' UTC',
            'php_version'          => PHP_VERSION,
            'wp_version'           => get_bloginfo('version'),
            'cwsb_api_key_in_db'   => $db_api_key !== null
                                      ? '(present, length=' . strlen((string) $db_api_key) . ')'
                                      : '(missing/null)',
            'snapshots'            => array_values(self::$snapshots),
        ], 200);
    }
}
