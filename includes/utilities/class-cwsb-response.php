<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Response factory to keep payload shape consistent across endpoints.
 */
class CWSB_Response
{
    private static function corruptionScore($value)
    {
        if (!is_string($value) || $value === '') {
            return 0;
        }

        $markers = preg_match_all('/Ã|Â|â|Ø|Ù|�/u', $value, $m);
        $controls = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value, $c);
        return ((int) $markers * 4) + ((int) $controls * 8);
    }

    private static function maybeRepairMojibake($value)
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (!preg_match('/Ã.|Â.|â.|�/u', $value)) {
            return $value;
        }

        $best = $value;
        $bestScore = self::corruptionScore($value);

        if (function_exists('iconv')) {
            $latin1 = @iconv('UTF-8', 'ISO-8859-1//IGNORE', $value);
            if (is_string($latin1) && $latin1 !== '') {
                $candidate = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $latin1);
                if (is_string($candidate) && $candidate !== '') {
                    $score = self::corruptionScore($candidate);
                    if ($score < $bestScore) {
                        $best = $candidate;
                        $bestScore = $score;
                    }
                }
            }

            $cp1252 = @iconv('UTF-8', 'Windows-1252//IGNORE', $value);
            if (is_string($cp1252) && $cp1252 !== '') {
                $candidate = @iconv('Windows-1252', 'UTF-8//IGNORE', $cp1252);
                if (is_string($candidate) && $candidate !== '') {
                    $score = self::corruptionScore($candidate);
                    if ($score < $bestScore) {
                        $best = $candidate;
                        $bestScore = $score;
                    }
                }
            }
        }

        return $best;
    }

    private static function withUtf8Headers($payload, $status)
    {
        $response = new WP_REST_Response(self::normalizePayloadEncoding($payload), (int) $status);
        $response->header('Content-Type', 'application/json; charset=UTF-8');
        return $response;
    }

    private static function normalizePayloadEncoding($value)
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalizePayloadEncoding($item);
            }
            return $normalized;
        }

        if (!is_string($value) || $value === '') {
            return $value;
        }

        return self::maybeRepairMojibake($value);
    }

    /**
     * Builds a success response.
     */
    public static function ok($data = [], $status = 200)
    {
        return self::withUtf8Headers([
            'success' => true,
            'data' => $data,
        ], (int) $status);
    }

    /**
     * Builds a standardized error response.
     */
    public static function error($code, $message, $status = 400, $details = [])
    {
        return self::withUtf8Headers([
            'success' => false,
            'error' => [
                'code' => (string) $code,
                'message' => (string) $message,
                'details' => is_array($details) ? $details : [],
            ],
        ], (int) $status);
    }
}
