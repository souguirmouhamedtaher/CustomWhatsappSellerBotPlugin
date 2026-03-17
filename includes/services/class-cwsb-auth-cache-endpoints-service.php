<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Response')) {
    require_once __DIR__ . '/../utilities/class-cwsb-response.php';
}

if (!class_exists('CWSB_Cache')) {
    require_once __DIR__ . '/../utilities/class-cwsb-cache.php';
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../utilities/class-cwsb-utils.php';
}

/**
 * Cache endpoint handlers extracted from auth controller.
 */
class CWSB_Auth_Cache_Endpoints_Service
{
    private static function auth_warmup_cache_key($flow_token)
    {
        return 'auth:warmup:' . CWSB_Utils::normalize_text($flow_token);
    }

    private static function auth_pending_cache_key($flow_token)
    {
        return 'auth:pending:' . CWSB_Utils::normalize_text($flow_token);
    }

    private static function product_list_cache_key($flow_token)
    {
        return 'products:list:' . CWSB_Utils::normalize_text($flow_token);
    }

    public static function get_auth_warmup_cache(WP_REST_Request $request)
    {
        $flow_token = CWSB_Utils::normalize_text($request->get_param('flow_token'));
        if ($flow_token === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        $cache_key = self::auth_warmup_cache_key($flow_token);
        $found = false;
        $entry = CWSB_Cache::get($cache_key, $found);

        return CWSB_Response::ok([
            'entry' => $found ? $entry : null,
        ]);
    }

    public static function set_auth_warmup_cache(WP_REST_Request $request)
    {
        $flow_token = CWSB_Utils::normalize_text($request->get_param('flow_token'));
        if ($flow_token === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        $has_code = CWSB_Utils::to_bool($request->get_param('has_code'));
        $prepared_at = (int) $request->get_param('prepared_at');
        if ($prepared_at <= 0) {
            $prepared_at = CWSB_Utils::now_ms();
        }

        $entry = [
            'has_code' => $has_code,
            'prepared_at' => $prepared_at,
        ];

        CWSB_Cache::set(self::auth_warmup_cache_key($flow_token), $entry, 5 * 60);

        return CWSB_Response::ok([
            'entry' => $entry,
        ]);
    }

    public static function set_auth_pending_cache(WP_REST_Request $request)
    {
        $flow_token = CWSB_Utils::normalize_text($request->get_param('flow_token'));
        $code = CWSB_Utils::normalize_text($request->get_param('code'));

        if ($flow_token === '' || $code === '') {
            return CWSB_Response::error('invalid_request', 'flow_token and code are required.', 422);
        }

        $expires_at = (int) $request->get_param('expires_at');
        if ($expires_at <= 0) {
            $expires_at = CWSB_Utils::now_ms() + (10 * 60 * 1000);
        }

        $entry = [
            'code' => $code,
            'expires_at' => $expires_at,
        ];

        CWSB_Cache::set(self::auth_pending_cache_key($flow_token), $entry, 10 * 60);

        return CWSB_Response::ok([
            'entry' => $entry,
        ]);
    }

    public static function consume_auth_pending_cache(WP_REST_Request $request)
    {
        $flow_token = CWSB_Utils::normalize_text($request->get_param('flow_token'));
        if ($flow_token === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        $cache_key = self::auth_pending_cache_key($flow_token);
        $found = false;
        $entry = CWSB_Cache::get($cache_key, $found);

        if ($found) {
            CWSB_Cache::delete($cache_key);
        }

        return CWSB_Response::ok([
            'entry' => $found ? $entry : null,
        ]);
    }

    public static function get_products_list_cache(WP_REST_Request $request)
    {
        $flow_token = CWSB_Utils::normalize_text($request->get_param('flow_token'));
        if ($flow_token === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        $cache_key = self::product_list_cache_key($flow_token);
        $found = false;
        $entry = CWSB_Cache::get($cache_key, $found);

        return CWSB_Response::ok([
            'entry' => $found ? $entry : null,
        ]);
    }

    public static function set_products_list_cache(WP_REST_Request $request)
    {
        $flow_token = CWSB_Utils::normalize_text($request->get_param('flow_token'));
        if ($flow_token === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        $products = $request->get_param('products');
        if (!is_array($products)) {
            return CWSB_Response::error('invalid_request', 'products must be an array.', 422);
        }

        $prepared_at = (int) $request->get_param('prepared_at');
        if ($prepared_at <= 0) {
            $prepared_at = (int) round(microtime(true) * 1000);
        }

        $entry = [
            'products' => $products,
            'prepared_at' => $prepared_at,
        ];

        CWSB_Cache::set(self::product_list_cache_key($flow_token), $entry, 5 * 60);

        return CWSB_Response::ok([
            'entry' => $entry,
        ]);
    }
}
