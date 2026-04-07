<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cache metrics collector.
 */
class CWSB_Cache_Metrics
{
    private static $metrics = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'deletes' => 0,
    ];

    public static function record_hit()
    {
        self::$metrics['hits']++;
    }

    public static function record_miss()
    {
        self::$metrics['misses']++;
    }

    public static function record_set()
    {
        self::$metrics['sets']++;
    }

    public static function record_delete()
    {
        self::$metrics['deletes']++;
    }

    public static function get_metrics()
    {
        $total = self::$metrics['hits'] + self::$metrics['misses'];
        return [
            'hits' => self::$metrics['hits'],
            'misses' => self::$metrics['misses'],
            'sets' => self::$metrics['sets'],
            'deletes' => self::$metrics['deletes'],
            'hitRate' => $total > 0 ? round(self::$metrics['hits'] / $total * 100, 1) : 0,
        ];
    }

    public static function reset_metrics()
    {
        self::$metrics = [
            'hits' => 0,
            'misses' => 0,
            'sets' => 0,
            'deletes' => 0,
        ];
    }

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
}
