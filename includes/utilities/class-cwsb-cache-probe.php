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

    /**
     * Returns all captured snapshots.
     *
     * @return array<string, array>
     */
    public static function snapshots()
    {
        return self::$snapshots;
    }

    /**
     * Parse PHP shorthand memory values (e.g. 256M, 1G) into bytes.
     *
     * @param string $value
     * @return int|null
     */
    private static function parse_memory_bytes($value)
    {
        $raw = trim((string) $value);
        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $unit = strtolower(substr($raw, -1));
        $num  = (float) $raw;

        switch ($unit) {
            case 'g':
                return (int) ($num * 1024 * 1024 * 1024);
            case 'm':
                return (int) ($num * 1024 * 1024);
            case 'k':
                return (int) ($num * 1024);
            default:
                return (int) $num;
        }
    }

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

        $memory_limit_raw   = (string) ini_get('memory_limit');
        $memory_limit_bytes = self::parse_memory_bytes($memory_limit_raw);
        $memory_usage_bytes = memory_get_usage(true);
        $memory_peak_bytes  = memory_get_peak_usage(true);

        $snapshot = [
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
            // Intentionally kept as "unknown" to avoid introspecting backend
            // internals on managed hosts where that can be expensive.
            'cwsb_group_non_persistent'  => 'unknown',

            // --- Relevant constants ---
            'CWSB_DISABLE_PLUGIN_CACHE'  => defined('CWSB_DISABLE_PLUGIN_CACHE')
                                            ? CWSB_DISABLE_PLUGIN_CACHE
                                            : '(not defined)',
            'REST_REQUEST'               => defined('REST_REQUEST')  ? REST_REQUEST  : '(not defined)',
            'DONOTCACHEPAGE'             => defined('DONOTCACHEPAGE') ? DONOTCACHEPAGE : '(not defined)',
            'DONOTCACHEDB'               => defined('DONOTCACHEDB')   ? DONOTCACHEDB   : '(not defined)',

            // --- Memory footprint ---
            'memory_limit_raw'           => $memory_limit_raw,
            'memory_limit_bytes'         => $memory_limit_bytes,
            'memory_usage_bytes'         => $memory_usage_bytes,
            'memory_peak_bytes'          => $memory_peak_bytes,

            // --- Safety marker ---
            // This probe is intentionally read-only: no wp_cache_set/get/delete.
            'cache_rw_probe'             => 'skipped_read_only_mode',
        ];

        self::$snapshots[$phase] = $snapshot;

        error_log('CWSB cache probe snapshot: ' . wp_json_encode([
            'phase' => $phase,
            'wp_object_cache_class' => $snapshot['wp_object_cache_class'],
            'wp_using_ext_object_cache' => $snapshot['wp_using_ext_object_cache'],
            'object_cache_dropin_exists' => $snapshot['object_cache_dropin_exists'],
            'CWSB_DISABLE_PLUGIN_CACHE' => $snapshot['CWSB_DISABLE_PLUGIN_CACHE'],
            'REST_REQUEST' => $snapshot['REST_REQUEST'],
            'DONOTCACHEPAGE' => $snapshot['DONOTCACHEPAGE'],
            'DONOTCACHEDB' => $snapshot['DONOTCACHEDB'],
            'memory_limit_raw' => $snapshot['memory_limit_raw'],
            'memory_usage_bytes' => $snapshot['memory_usage_bytes'],
            'memory_peak_bytes' => $snapshot['memory_peak_bytes'],
        ]));
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
            'permission_callback' => [static::class, 'allow_debug_endpoint'],
        ]);
    }

    /**
     * Lightweight permission check that avoids option/object-cache access.
     *
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    public static function allow_debug_endpoint(WP_REST_Request $request)
    {
        $provided = trim((string) $request->get_header('x-api-key'));

        $expected = '';
        if (defined('CWSB_API_KEY')) {
            $expected = trim((string) CWSB_API_KEY);
        }
        if ($expected === '') {
            $expected = trim((string) getenv('WP_PLUGIN_API_KEY'));
        }

        if ($expected === '' || $provided === '') {
            return new WP_Error('cwsb_unauthorized', 'Unauthorized', ['status' => 401]);
        }

        if (!hash_equals($expected, $provided)) {
            return new WP_Error('cwsb_forbidden', 'Forbidden', ['status' => 403]);
        }

        return true;
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

        return new WP_REST_Response([
            'server_time'          => gmdate('Y-m-d H:i:s') . ' UTC',
            'php_version'          => PHP_VERSION,
            'wp_version'           => null,
            'snapshots'            => array_values(self::$snapshots),
            'notes'                => [
                'debug_endpoint_mode' => 'lightweight',
                'db_reads' => 'skipped',
                'wp_cache_rw' => 'skipped',
            ],
        ], 200);
    }
}
