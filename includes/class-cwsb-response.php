<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Response factory to keep payload shape consistent across endpoints.
 */
class CWSB_Response
{
    /**
     * Builds a success response.
     */
    public static function ok($data = [], $status = 200)
    {
        return new WP_REST_Response([
            'success' => true,
            'data' => $data,
        ], (int) $status);
    }

    /**
     * Builds a standardized error response.
     */
    public static function error($code, $message, $status = 400, $details = [])
    {
        return new WP_REST_Response([
            'success' => false,
            'error' => [
                'code' => (string) $code,
                'message' => (string) $message,
                'details' => is_array($details) ? $details : [],
            ],
        ], (int) $status);
    }
}
