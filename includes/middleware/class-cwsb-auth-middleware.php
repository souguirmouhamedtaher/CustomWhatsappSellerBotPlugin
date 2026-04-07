<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Logger')) {
    require_once __DIR__ . '/../utilities/class-cwsb-logger.php';
}

/**
 * Middleware helpers for authenticating incoming REST requests.
 */
class CWSB_Auth_Middleware
{
    /**
     * Validates x-api-key header against plugin configuration.
     *
     * Resolution order for expected key:
     * 1) WordPress option cwsb_api_key
     * 2) CWSB_API_KEY constant
     * 3) WP_PLUGIN_API_KEY environment variable
     *
     * @return true|WP_Error
     */
    public static function require_api_key(WP_REST_Request $request)
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';

        $expected = trim((string) get_option('cwsb_api_key', ''));
        if ($expected === '' && defined('CWSB_API_KEY')) {
            $expected = trim((string) CWSB_API_KEY);
        }
        if ($expected === '') {
            $expected = trim((string) getenv('WP_PLUGIN_API_KEY'));
        }

        $provided = trim((string) $request->get_header('x-api-key'));

        CWSB_Logger::info('Incoming API request', [
            'method' => $method,
            'uri' => $uri,
            'has_api_key_header' => $provided !== '',
        ]);

        if ($expected === '' || $provided === '') {
            CWSB_Logger::warning('API key missing', [
                'method' => $method,
                'uri' => $uri,
                'expected_key_configured' => $expected !== '',
                'provided_key_present' => $provided !== '',
            ]);
            return new WP_Error('cwsb_unauthorized', 'Unauthorized', ['status' => 401]);
        }

        // Constant-time comparison to prevent timing attacks.
        if (!hash_equals($expected, $provided)) {
            CWSB_Logger::warning('API key mismatch', [
                'method' => $method,
                'uri' => $uri,
            ]);
            return new WP_Error('cwsb_forbidden', 'Forbidden', ['status' => 403]);
        }

        CWSB_Logger::debug('API key validated', [
            'method' => $method,
            'uri' => $uri,
        ]);

        return true;
    }
}
