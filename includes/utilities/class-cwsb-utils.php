<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared normalization/conversion helpers used across plugin classes.
 */
class CWSB_Utils
{
    private static function has_encoding($encoding)
    {
        if (!function_exists('mb_list_encodings')) {
            return false;
        }

        $needle = strtolower((string) $encoding);
        foreach ((array) mb_list_encodings() as $enc) {
            if (strtolower((string) $enc) === $needle) {
                return true;
            }
        }

        return false;
    }

    private static function safe_convert($text, $fromEncoding)
    {
        if ($text === '' || !function_exists('mb_convert_encoding')) {
            return '';
        }

        if (!self::has_encoding($fromEncoding)) {
            return '';
        }

        try {
            $converted = @mb_convert_encoding($text, 'UTF-8', $fromEncoding);
            return is_string($converted) ? $converted : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    private static function reinterpret_utf8_via($text, $singleByteEncoding)
    {
        if ($text === '' || !function_exists('iconv')) {
            return '';
        }

        if (!self::has_encoding($singleByteEncoding)) {
            return '';
        }

        try {
            $bytes = @iconv('UTF-8', $singleByteEncoding . '//IGNORE', $text);
            if (!is_string($bytes) || $bytes === '') {
                return '';
            }

            return self::safe_convert($bytes, $singleByteEncoding);
        } catch (Throwable $e) {
            return '';
        }
    }

    private static function arabic_score($text)
    {
        if ($text === '') {
            return 0;
        }

        if (function_exists('mb_check_encoding') && !@mb_check_encoding($text, 'UTF-8')) {
            return 0;
        }

        $matches = @preg_match_all('/[\x{0600}-\x{06FF}]/u', $text);
        return $matches === false ? 0 : (int) $matches;
    }

    private static function noise_score($text)
    {
        if ($text === '') {
            return 0;
        }

        $count = 0;
        $count += preg_match_all('/Ã/', $text);
        $count += preg_match_all('/Â/', $text);
        $count += preg_match_all('/â/', $text);
        $count += preg_match_all('/�/', $text);
        $count += preg_match_all('/Ø/', $text);
        $count += preg_match_all('/Ù/', $text);
        $count += preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text);
        return (int) $count;
    }

    private static function choose_best_text($original, $candidates)
    {
        $best = $original;
        $bestArabic = self::arabic_score($original);
        $bestNoise = self::noise_score($original);

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            $arabic = self::arabic_score($candidate);
            $noise = self::noise_score($candidate);

            if ($arabic > $bestArabic || ($arabic === $bestArabic && $noise < $bestNoise)) {
                $best = $candidate;
                $bestArabic = $arabic;
                $bestNoise = $noise;
            }
        }

        return $best;
    }

    private static function repair_arabic_text($text)
    {
        if ($text === '' || !function_exists('mb_convert_encoding')) {
            return $text;
        }

        $candidates = [];

        $latin1 = self::safe_convert($text, 'ISO-8859-1');
        if ($latin1 !== '') {
            $candidates[] = $latin1;
        }

        $latin1Reinterpreted = self::reinterpret_utf8_via($text, 'ISO-8859-1');
        if ($latin1Reinterpreted !== '') {
            $candidates[] = $latin1Reinterpreted;
        }

        $win1252 = self::safe_convert($text, 'Windows-1252');
        if ($win1252 !== '') {
            $candidates[] = $win1252;
        }

        $win1252Reinterpreted = self::reinterpret_utf8_via($text, 'Windows-1252');
        if ($win1252Reinterpreted !== '') {
            $candidates[] = $win1252Reinterpreted;
        }

        $win1256 = self::safe_convert($text, 'Windows-1256');
        if ($win1256 !== '') {
            $candidates[] = $win1256;
        }

        return self::choose_best_text($text, $candidates);
    }

    private static function looks_like_mojibake($text)
    {
        if ($text === '') {
            return false;
        }

        return preg_match('/Ã.|Â.|â./', $text) === 1
            || strpos($text, '�') !== false
            || preg_match('/Ø.|Ù./', $text) === 1;
    }

    private static function marker_score($text)
    {
        if ($text === '') {
            return 0;
        }

        $count = 0;
        $count += preg_match_all('/Ã/', $text);
        $count += preg_match_all('/Â/', $text);
        $count += preg_match_all('/â/', $text);
        $count += preg_match_all('/�/', $text);
        $count += preg_match_all('/Ø/', $text);
        $count += preg_match_all('/Ù/', $text);
        $count += preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text);
        return (int) $count;
    }

    private static function repair_mojibake($text)
    {
        if (!self::looks_like_mojibake($text)) {
            return $text;
        }

        if (!function_exists('mb_convert_encoding')) {
            return $text;
        }

        $latin1 = self::safe_convert($text, 'ISO-8859-1');
        $win1252 = self::safe_convert($text, 'Windows-1252');

        $repaired = self::choose_best_text($text, [$latin1, $win1252]);
        if ($repaired === '' || $repaired === $text) {
            return $text;
        }

        return self::marker_score($repaired) < self::marker_score($text) ? $repaired : $text;
    }

    public static function normalize_text($value)
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        $repaired = self::repair_mojibake($text);
        $repaired = self::repair_arabic_text($repaired);

        return trim($repaired);
    }

    public static function normalize_phone($phone)
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (!is_string($digits) || $digits === '') {
            return '';
        }

        // Handle Tunisia numbers in a canonical international format without plus (216 + 8 digits).
        // Examples:
        // - +21650354773  -> 21650354773
        // - 21650354773   -> 21650354773
        // - 0021650354773 -> 21650354773
        // - 50354773      -> 21650354773
        if (strpos($digits, '00216') === 0 && strlen($digits) === 13) {
            return substr($digits, 2);
        }

        if (strpos($digits, '216') === 0 && strlen($digits) === 11) {
            return $digits;
        }

        if (strlen($digits) === 8) {
            return '216' . $digits;
        }

        // France examples:
        // - +33782655322  -> 33782655322
        // - 33782655322   -> 33782655322
        // - 0033782655322 -> 33782655322
        // - 0782655322    -> 33782655322
        if (strpos($digits, '0033') === 0 && strlen($digits) === 13) {
            return substr($digits, 2);
        }

        if (strpos($digits, '33') === 0 && strlen($digits) === 11) {
            return $digits;
        }

        if (strlen($digits) === 10 && strpos($digits, '0') === 0) {
            return '33' . substr($digits, 1);
        }

        // Senegal examples:
        // - +221771234567  -> 221771234567
        // - 221771234567   -> 221771234567
        // - 00221771234567 -> 221771234567
        // - 771234567      -> 221771234567
        if (strpos($digits, '00221') === 0 && strlen($digits) === 14) {
            return substr($digits, 2);
        }

        if (strpos($digits, '221') === 0 && strlen($digits) === 12) {
            return $digits;
        }

        if (strlen($digits) === 9) {
            return '221' . $digits;
        }

        // Reject unsupported countries instead of guessing.
        return '';
    }

    public static function extract_phone_from_flow_token($flow_token)
    {
        $token = self::normalize_text($flow_token);
        if ($token === '') {
            return '';
        }

        if (!preg_match('/^flowtoken-(.+)-\d+$/', $token, $matches)) {
            return '';
        }

        $raw = isset($matches[1]) ? (string) $matches[1] : '';
        return self::normalize_phone($raw);
    }

    // Builds stable phone references for tolerant comparisons against mixed historical data.
    public static function phone_comparison_refs($phone)
    {
        $canonical = self::normalize_phone($phone);
        if ($canonical === '') {
            return [
                'canonical' => '',
                'local' => '',
                'legacy' => '',
                'intl00' => '',
                'intl_plus' => '',
                'suffix' => '',
                'suffix_length' => 0,
            ];
        }

        if (strlen($canonical) === 11 && strpos($canonical, '216') === 0) {
            $local = substr($canonical, -8);
            $suffix = $local;
        } elseif (strlen($canonical) === 11 && strpos($canonical, '33') === 0) {
            $local = '0' . substr($canonical, 2);
            $suffix = substr($canonical, 2);
        } elseif (strlen($canonical) === 12 && strpos($canonical, '221') === 0) {
            $local = substr($canonical, -9);
            $suffix = $local;
        } else {
            $local = $canonical;
            $suffix = $canonical;
        }

        return [
            'canonical' => $canonical,
            'local' => $local,
            'legacy' => $canonical,
            'intl00' => '00' . $canonical,
            'intl_plus' => '+' . $canonical,
            'suffix' => $suffix,
            'suffix_length' => strlen($suffix),
        ];
    }

    public static function to_money_string($value)
    {
        if ($value === null) {
            return '';
        }

        return self::normalize_text($value);
    }

    public static function to_int_or_zero($value)
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return (int) $value;
    }

    public static function to_bool($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(self::normalize_text($value));
        return $normalized === '1' || $normalized === 'true' || $normalized === 'yes';
    }

    public static function now_ms()
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * Decode a WMCP TND meta value (JSON string like {"TND":"100"}) and return
     * the raw TND amount string. Falls back to $legacy_fallback when the JSON
     * is absent, empty, or malformed.
     *
     * @param string $meta_json       Raw meta_value from _regular_price_wmcp / _sale_price_wmcp.
     * @param string $legacy_fallback Value of the old _regular_price_tnd / _sale_price_tnd key.
     * @return string
     */
    public static function decode_wmcp_tnd($meta_json, $legacy_fallback = '')
    {
        $raw = trim((string) $meta_json);
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['TND']) && trim((string) $decoded['TND']) !== '') {
                return trim((string) $decoded['TND']);
            }
        }

        return trim((string) $legacy_fallback);
    }
}
