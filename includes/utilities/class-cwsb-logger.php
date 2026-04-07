<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Structured logger for the CWSB plugin.
 *
 * Writes to a rotating daily .txt log file AND echoes to PHP error_log.
 * Every entry includes the entry weight in Ko (kilobytes).
 *
 * Default log path: plugin_root/logs/cwsb-YYYY-MM-DD.log
 * Override path:    define('CWSB_LOG_FILE', '/absolute/path/to/file.txt');
 * Disable logging:  define('CWSB_LOG_DISABLED', true);
 * Min level:        define('CWSB_LOG_MIN_LEVEL', 'debug'|'info'|'warning'|'error');
 *                   Defaults to 'debug' when WP_DEBUG=true, otherwise 'info'.
 *
 * Usage:
 *   CWSB_Logger::info('Product created', ['product_id' => 42]);
 *   CWSB_Logger::start_timer('convert_prices');
 *   $elapsed_ms = CWSB_Logger::end_timer('convert_prices');
 *   $r = CWSB_Logger::measure('my_op', fn() => do_work());
 *   // $r['result'], $r['elapsed_ms']
 */
class CWSB_Logger
{
    const LEVEL_DEBUG   = 0;
    const LEVEL_INFO    = 1;
    const LEVEL_WARNING = 2;
    const LEVEL_ERROR   = 3;

    private static $timers   = [];
    private static $log_file = null;   // resolved once, then cached

    // -------------------------------------------------------------------------
    // Public log methods
    // -------------------------------------------------------------------------

    public static function debug($message, array $context = [])
    {
        self::write(self::LEVEL_DEBUG, $message, $context);
    }

    public static function info($message, array $context = [])
    {
        self::write(self::LEVEL_INFO, $message, $context);
    }

    public static function warning($message, array $context = [])
    {
        self::write(self::LEVEL_WARNING, $message, $context);
    }

    public static function error($message, array $context = [])
    {
        self::write(self::LEVEL_ERROR, $message, $context);
    }

    // -------------------------------------------------------------------------
    // Timing helpers
    // -------------------------------------------------------------------------

    public static function start_timer($name)
    {
        self::$timers[$name] = microtime(true);
    }

    /**
     * Stop timer, log elapsed time, return elapsed ms.
     *
     * @param  string $name
     * @param  string $message optional label; defaults to "Timer: {name}"
     * @param  array  $context extra context
     * @return float  elapsed milliseconds, or 0.0 if never started
     */
    public static function end_timer($name, $message = '', array $context = [])
    {
        if (!isset(self::$timers[$name])) {
            self::warning('end_timer called without matching start_timer', ['timer' => $name]);
            return 0.0;
        }

        $elapsed_ms = round((microtime(true) - self::$timers[$name]) * 1000, 3);
        unset(self::$timers[$name]);

        $label = $message !== '' ? $message : 'Timer: ' . $name;
        self::info($label, array_merge(['elapsed_ms' => $elapsed_ms, 'timer' => $name], $context));

        return $elapsed_ms;
    }

    /**
     * Wrap a callable, log how long it took, return result + elapsed_ms.
     *
     * @param  string   $name
     * @param  callable $callback
     * @param  array    $context
     * @return array{result: mixed, elapsed_ms: float}
     */
    public static function measure($name, callable $callback, array $context = [])
    {
        self::start_timer($name);
        $result     = call_user_func($callback);
        $elapsed_ms = self::end_timer($name, 'Measured: ' . $name, $context);

        return ['result' => $result, 'elapsed_ms' => $elapsed_ms];
    }

    // -------------------------------------------------------------------------
    // File path
    // -------------------------------------------------------------------------

    /**
     * Return the absolute path to today's log file, creating the directory
     * if it does not yet exist. Returns null if the directory cannot be created.
     *
     * @return string|null
     */
    public static function log_file()
    {
        if (self::$log_file !== null) {
            return self::$log_file;
        }

        if (defined('CWSB_LOG_FILE') && is_string(CWSB_LOG_FILE) && CWSB_LOG_FILE !== '') {
            self::$log_file = CWSB_LOG_FILE;
            return self::$log_file;
        }

        // Default: plugin_root/logs/cwsb-YYYY-MM-DD.log
        // __DIR__ = includes/utilities  â†’  ../.. = plugin root
        $log_dir = realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR . 'logs';

        if (!is_dir($log_dir)) {
            if (!mkdir($log_dir, 0755, true)) {
                return null;
            }

            // Prevent direct HTTP access to the logs directory.
            $htaccess = $log_dir . DIRECTORY_SEPARATOR . '.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Deny from all\n");
            }
        }

        self::$log_file = $log_dir . DIRECTORY_SEPARATOR . 'cwsb-' . date('Y-m-d') . '.log';
        return self::$log_file;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private static function disabled()
    {
        return defined('CWSB_LOG_DISABLED') && CWSB_LOG_DISABLED;
    }

    private static function min_level()
    {
        $override = defined('CWSB_LOG_MIN_LEVEL') ? strtolower((string) CWSB_LOG_MIN_LEVEL) : '';

        $map = [
            'debug'   => self::LEVEL_DEBUG,
            'info'    => self::LEVEL_INFO,
            'warning' => self::LEVEL_WARNING,
            'error'   => self::LEVEL_ERROR,
        ];

        if (isset($map[$override])) {
            return $map[$override];
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            return self::LEVEL_DEBUG;
        }

        return self::LEVEL_INFO;
    }

    private static function level_label($level)
    {
        $labels = [
            self::LEVEL_DEBUG   => 'DEBUG',
            self::LEVEL_INFO    => 'INFO',
            self::LEVEL_WARNING => 'WARNING',
            self::LEVEL_ERROR   => 'ERROR',
        ];
        return isset($labels[$level]) ? $labels[$level] : 'UNKNOWN';
    }

    private static function write($level, $message, array $context)
    {
        if (self::disabled() || $level < self::min_level()) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $label     = self::level_label($level);

        // Build the core line (without the weight field â€” weight is computed after)
        $line = '[' . $timestamp . '] [' . $label . '] ' . $message;

        if (!empty($context)) {
            $line .= ' | ' . self::serialize_context($context);
        }

        // Weight of this log entry in Ko (kilobytes, French notation)
        $weight_ko = round(strlen($line) / 1024, 3);
        $line      = '[' . $timestamp . '] [' . $label . '] [' . number_format($weight_ko, 3, '.', '') . ' Ko] ' . $message;

        if (!empty($context)) {
            $line .= ' | ' . self::serialize_context($context);
        }

        $line .= PHP_EOL;

        // Write to log file
        $path = self::log_file();
        if ($path !== null) {
            file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        }

        // Mirror to PHP error_log (strip trailing newline for cleaner output)
        error_log(rtrim($line));
    }

    private static function serialize_context(array $context)
    {
        $parts = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $parts[] = $key . '=' . var_export($value, true);
            } else {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                $parts[] = $key . '=' . ($encoded !== false ? $encoded : '[unserializable]');
            }
        }
        return implode(', ', $parts);
    }

}
