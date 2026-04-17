<?php
/**
 * Standalone unit test runner for CWSB pure calculation helpers.
 *
 * No PHPUnit or WordPress bootstrap needed.
 * Run from the plugin root:
 *   php tests/unit/run.php
 *
 * Covers: CWSB_Add_Product_Support_Service (all pure math / string methods)
 *         CWSB_Utils (normalize_phone, extract_phone_from_flow_token)
 *         CWSB_Logger (measure, end_timer without matching start, log levels)
 */

// ---------------------------------------------------------------------------
// 1. WordPress / plugin stubs
// ---------------------------------------------------------------------------

define('ABSPATH', __DIR__ . '/../../');
define('WP_DEBUG', true);
define('SUITE_START', microtime(true));

if (!function_exists('number_format')) {
    // number_format is a native PHP function â€” always available.
}

// Minimal CWSB_Logger stub so CWSB_Utils loads without triggering its guard,
// then we load the real one immediately after.
// (Order: logger -> utils -> support-service)
require_once __DIR__ . '/../../includes/utilities/class-cwsb-logger.php';
require_once __DIR__ . '/../../includes/utilities/class-cwsb-utils.php';
require_once __DIR__ . '/../../includes/services/add-product/class-cwsb-add-product-support-service.php';

// ---------------------------------------------------------------------------
// 2. Micro test framework
// ---------------------------------------------------------------------------

$results = [];        // ['name', 'passed', 'assertions', 'elapsed_us', 'failures']
$total_assertions = 0;

function assert_equals($expected, $actual, $label = '')
{
    global $current_failures, $current_assertions;
    $current_assertions++;
    if ($expected === $actual) {
        return true;
    }
    $current_failures[] = sprintf(
        '  FAIL [%s]: expected %s, got %s',
        $label,
        var_export($expected, true),
        var_export($actual, true)
    );
    return false;
}

function assert_nearly_equals($expected, $actual, $label = '', $epsilon = 0.0001)
{
    global $current_failures, $current_assertions;
    $current_assertions++;
    if (abs($expected - $actual) <= $epsilon) {
        return true;
    }
    $current_failures[] = sprintf(
        '  FAIL [%s]: expected ~%s, got %s (epsilon %s)',
        $label,
        var_export($expected, true),
        var_export($actual, true),
        $epsilon
    );
    return false;
}

function assert_empty_array($actual, $label = '')
{
    global $current_failures, $current_assertions;
    $current_assertions++;
    if (is_array($actual) && count($actual) === 0) {
        return true;
    }
    $current_failures[] = sprintf(
        '  FAIL [%s]: expected empty array, got %s',
        $label,
        json_encode($actual)
    );
    return false;
}

function assert_count($expected_count, $actual, $label = '')
{
    global $current_failures, $current_assertions;
    $current_assertions++;
    $actual_count = is_array($actual) ? count($actual) : -1;
    if ($actual_count === $expected_count) {
        return true;
    }
    $current_failures[] = sprintf(
        '  FAIL [%s]: expected count %d, got %d',
        $label,
        $expected_count,
        $actual_count
    );
    return false;
}

function round_threshold_to_int_expected($value, $threshold = 0.2)
{
    $num = (float) $value;
    if ($num <= 0) {
        return 0;
    }

    $safe_threshold = (float) $threshold;
    if ($safe_threshold < 0) {
        $safe_threshold = 0;
    }
    if ($safe_threshold > 1) {
        $safe_threshold = 1;
    }

    $base = (int) floor($num);
    $fraction = $num - $base;
    $epsilon = 0.000000001;

    if (($fraction + $epsilon) >= $safe_threshold) {
        return $base + 1;
    }

    return $base;
}

function run_test($name, callable $fn)
{
    global $results, $total_assertions, $current_failures, $current_assertions;

    $current_failures   = [];
    $current_assertions = 0;

    $start = microtime(true);
    try {
        $fn();
    } catch (Throwable $e) {
        $current_failures[] = '  EXCEPTION: ' . $e->getMessage();
    }
    $elapsed_us = (microtime(true) - $start) * 1_000_000;

    $passed = empty($current_failures);
    $total_assertions += $current_assertions;

    $results[] = [
        'name'       => $name,
        'passed'     => $passed,
        'assertions' => $current_assertions,
        'elapsed_us' => $elapsed_us,
        'failures'   => $current_failures,
    ];
}

// ---------------------------------------------------------------------------
// 3. Tests: CWSB_Add_Product_Support_Service â€“ pure math / string methods
// ---------------------------------------------------------------------------

// --- normalize_status -------------------------------------------------------

run_test('normalize_status: valid statuses pass through', function () {
    assert_equals('draft',   CWSB_Add_Product_Support_Service::normalize_status('draft'),   'draft');
    assert_equals('publish', CWSB_Add_Product_Support_Service::normalize_status('PUBLISH'),  'PUBLISH');
    assert_equals('pending', CWSB_Add_Product_Support_Service::normalize_status('pending'),  'pending');
    assert_equals('private', CWSB_Add_Product_Support_Service::normalize_status('private'),  'private');
});

run_test('normalize_status: unknown value defaults to draft', function () {
    assert_equals('draft', CWSB_Add_Product_Support_Service::normalize_status('live'),    'live');
    assert_equals('draft', CWSB_Add_Product_Support_Service::normalize_status(''),        'empty');
    assert_equals('draft', CWSB_Add_Product_Support_Service::normalize_status('UNKNOWN'), 'UNKNOWN');
    assert_equals('draft', CWSB_Add_Product_Support_Service::normalize_status(null),      'null');
});

// --- to_positive_float ------------------------------------------------------

run_test('to_positive_float: numeric strings and numbers', function () {
    assert_nearly_equals(12.5,  CWSB_Add_Product_Support_Service::to_positive_float('12.5'),  'string 12.5');
    assert_nearly_equals(12.5,  CWSB_Add_Product_Support_Service::to_positive_float('12,5'),  'comma sep');
    assert_nearly_equals(100.0, CWSB_Add_Product_Support_Service::to_positive_float(100),     'int');
    assert_nearly_equals(0.01,  CWSB_Add_Product_Support_Service::to_positive_float('0.01'),  '0.01');
});

run_test('to_positive_float: zero/negative/invalid return 0', function () {
    assert_nearly_equals(0.0, CWSB_Add_Product_Support_Service::to_positive_float(0),       'zero int');
    assert_nearly_equals(0.0, CWSB_Add_Product_Support_Service::to_positive_float(-5),      'negative');
    assert_nearly_equals(0.0, CWSB_Add_Product_Support_Service::to_positive_float('abc'),   'non-numeric');
    assert_nearly_equals(0.0, CWSB_Add_Product_Support_Service::to_positive_float(''),      'empty');
    assert_nearly_equals(0.0, CWSB_Add_Product_Support_Service::to_positive_float(null),    'null');
});

// --- format_decimal_string ---------------------------------------------------

run_test('format_decimal_string: rounding and formatting', function () {
    assert_equals('10.00',    CWSB_Add_Product_Support_Service::format_decimal_string(10,       2), '10 2dp');
    assert_equals('3.14',     CWSB_Add_Product_Support_Service::format_decimal_string(3.14159,  2), 'pi 2dp');
    assert_equals('3.142',    CWSB_Add_Product_Support_Service::format_decimal_string(3.14159,  3), 'pi 3dp');
    assert_equals('0.100',    CWSB_Add_Product_Support_Service::format_decimal_string(0.1,      3), '0.1 3dp');
    assert_equals('1000.00',  CWSB_Add_Product_Support_Service::format_decimal_string(1000,     2), '1000 2dp');
});

run_test('format_decimal_string: zero/negative returns empty', function () {
    assert_equals('', CWSB_Add_Product_Support_Service::format_decimal_string(0,    2), 'zero');
    assert_equals('', CWSB_Add_Product_Support_Service::format_decimal_string(-1,   2), 'negative');
    assert_equals('', CWSB_Add_Product_Support_Service::format_decimal_string('abc',2), 'non-numeric');
});

run_test('format_decimal_string: decimals clamped to [0,6]', function () {
    $v = 1.23456789;
    // decimals=-1 clamps to 0 -> '1'
    assert_equals('1', CWSB_Add_Product_Support_Service::format_decimal_string($v, -1), 'clamped 0dp');
    // decimals=10 clamps to 6 -> '1.234568'
    assert_equals('1.234568', CWSB_Add_Product_Support_Service::format_decimal_string($v, 10), 'clamped 6dp');
});

// --- to_price_string --------------------------------------------------------

run_test('to_price_string: valid prices formatted to 2dp', function () {
    assert_equals('10.00', CWSB_Add_Product_Support_Service::to_price_string(10),       'int');
    assert_equals('10.00', CWSB_Add_Product_Support_Service::to_price_string('10'),     'string');
    assert_equals('10.00', CWSB_Add_Product_Support_Service::to_price_string('10,00'),  'comma');
    assert_equals('1.50',  CWSB_Add_Product_Support_Service::to_price_string(1.5),      '1.5');
    assert_equals('99.99', CWSB_Add_Product_Support_Service::to_price_string('99.99'),  '99.99');
});

run_test('to_price_string: zero/null/negative/empty returns empty string', function () {
    assert_equals('', CWSB_Add_Product_Support_Service::to_price_string(null),   'null');
    assert_equals('', CWSB_Add_Product_Support_Service::to_price_string(''),     'empty');
    assert_equals('', CWSB_Add_Product_Support_Service::to_price_string(0),      'zero');
    assert_equals('', CWSB_Add_Product_Support_Service::to_price_string(-5),     'negative');
    assert_equals('', CWSB_Add_Product_Support_Service::to_price_string('abc'),  'alpha');
});

// --- to_dimension_string -----------------------------------------------------

run_test('to_dimension_string: returns 3dp string for positives', function () {
    assert_equals('10.000', CWSB_Add_Product_Support_Service::to_dimension_string(10),      'int');
    assert_equals('1.500',  CWSB_Add_Product_Support_Service::to_dimension_string(1.5),     'float');
    assert_equals('1.500',  CWSB_Add_Product_Support_Service::to_dimension_string('1,5'),   'comma');
    assert_equals('0.010',  CWSB_Add_Product_Support_Service::to_dimension_string('0.01'),  '0.01');
});

run_test('to_dimension_string: zero/negative returns empty', function () {
    assert_equals('', CWSB_Add_Product_Support_Service::to_dimension_string(0),   'zero');
    assert_equals('', CWSB_Add_Product_Support_Service::to_dimension_string(-3),  'negative');
    assert_equals('', CWSB_Add_Product_Support_Service::to_dimension_string('x'), 'alpha');
});

// --- convert_tnd_to_eur (math core) -----------------------------------------

run_test('convert_tnd_to_eur: formula = (tnd / rate) + markup, threshold integer', function () {
    $config = ['exchange_rate' => 3.358, 'fixed_markup_eur' => 9.0, 'rounding_decimals' => 2];

    $expected = round_threshold_to_int_expected((100 / 3.358) + 9, 0.2);
    assert_nearly_equals($expected, CWSB_Add_Product_Support_Service::convert_tnd_to_eur(100, $config), '100 TND');

    $expected50 = round_threshold_to_int_expected((50 / 3.358) + 9, 0.2);
    assert_nearly_equals($expected50, CWSB_Add_Product_Support_Service::convert_tnd_to_eur(50, $config), '50 TND');

    $config_no_markup = ['exchange_rate' => 3.358, 'fixed_markup_eur' => 0, 'rounding_decimals' => 4];
    $expected_no_markup = round_threshold_to_int_expected(100 / 3.358, 0.2);
    assert_nearly_equals(
        $expected_no_markup,
        CWSB_Add_Product_Support_Service::convert_tnd_to_eur(100, $config_no_markup),
        'no markup',
        0.0001
    );
});

run_test('convert_tnd_to_eur: defaults when config omitted', function () {
    $expected = round_threshold_to_int_expected((100 / 3.358) + 9, 0.2);
    assert_nearly_equals($expected, CWSB_Add_Product_Support_Service::convert_tnd_to_eur(100), 'default config');
});

run_test('convert_tnd_to_eur: zero/negative input returns 0', function () {
    assert_nearly_equals(0, CWSB_Add_Product_Support_Service::convert_tnd_to_eur(0),    'zero');
    assert_nearly_equals(0, CWSB_Add_Product_Support_Service::convert_tnd_to_eur(-10),  'negative');
    assert_nearly_equals(0, CWSB_Add_Product_Support_Service::convert_tnd_to_eur('abc'),'alpha');
    assert_nearly_equals(0, CWSB_Add_Product_Support_Service::convert_tnd_to_eur(null), 'null');
});

run_test('convert_tnd_to_eur: invalid rate falls back to 3.358', function () {
    $config_zero_rate = ['exchange_rate' => 0, 'fixed_markup_eur' => 9, 'rounding_decimals' => 2];
    $expected = round_threshold_to_int_expected((100 / 3.358) + 9, 0.2);
    assert_nearly_equals($expected, CWSB_Add_Product_Support_Service::convert_tnd_to_eur(100, $config_zero_rate), 'rate=0 falls back');
});

run_test('convert_tnd_to_eur: threshold boundary behavior at 0.2', function () {
    $config = ['exchange_rate' => 1, 'fixed_markup_eur' => 0, 'rounding_decimals' => 2];

    assert_equals(14, CWSB_Add_Product_Support_Service::convert_tnd_to_eur(14.0646, $config), '< 14.2');
    assert_equals(15, CWSB_Add_Product_Support_Service::convert_tnd_to_eur(14.21321, $config), '>= 14.2');
    assert_equals(15, CWSB_Add_Product_Support_Service::convert_tnd_to_eur(14.564, $config), '>= 14.2 high frac');
});

run_test('convert_tnd_to_eur: rounding_decimals does not change integer result', function () {
    $base = ['exchange_rate' => 3.358, 'fixed_markup_eur' => 9];
    $a = CWSB_Add_Product_Support_Service::convert_tnd_to_eur(100, array_merge($base, ['rounding_decimals' => 0]));
    $b = CWSB_Add_Product_Support_Service::convert_tnd_to_eur(100, array_merge($base, ['rounding_decimals' => 4]));

    assert_equals($a, $b, 'decimals ignored for threshold integer rounding');
});

// --- validate_create_payload ------------------------------------------------

run_test('validate_create_payload: valid minimal payload returns no errors', function () {
    $payload = [
        'name'        => 'Test Product',
        'category_id' => 'electronics',
        'quantity'    => 5,
        'pricing'     => ['regular_tnd' => 120],
    ];
    assert_empty_array(CWSB_Add_Product_Support_Service::validate_create_payload($payload), 'valid payload');
});

run_test('validate_create_payload: missing name returns error', function () {
    $payload = ['category_id' => 'elec', 'quantity' => 1, 'pricing' => ['regular_tnd' => 50]];
    $errors = CWSB_Add_Product_Support_Service::validate_create_payload($payload);
    assert_count(1, $errors, 'one error');
    assert_equals('product.name', $errors[0]['field'] ?? '', 'field is name');
    assert_equals('required', $errors[0]['code'] ?? '', 'code is required');
});

run_test('validate_create_payload: missing category returns error', function () {
    $payload = ['name' => 'P', 'quantity' => 1, 'pricing' => ['regular_tnd' => 50]];
    $errors = CWSB_Add_Product_Support_Service::validate_create_payload($payload);
    assert_count(1, $errors, 'one error');
    assert_equals('product.category_id', $errors[0]['field'] ?? '', 'field is category_id');
});

run_test('validate_create_payload: zero quantity returns error', function () {
    $payload = ['name' => 'P', 'category_id' => 'cats', 'quantity' => 0, 'pricing' => ['regular_tnd' => 50]];
    $errors = CWSB_Add_Product_Support_Service::validate_create_payload($payload);
    assert_count(1, $errors, 'one error');
    assert_equals('product.quantity', $errors[0]['field'] ?? '', 'field is quantity');
    assert_equals('invalid_range', $errors[0]['code'] ?? '', 'code is invalid_range');
});

run_test('validate_create_payload: no price at all returns error', function () {
    $payload = ['name' => 'P', 'category_id' => 'cats', 'quantity' => 1];
    $errors = CWSB_Add_Product_Support_Service::validate_create_payload($payload);
    $fields = array_column($errors, 'field');
    assert_equals(true, in_array('product.pricing.regular', $fields, true), 'pricing.regular error present');
});

run_test('validate_create_payload: promo_tnd >= regular_tnd returns error', function () {
    $payload = [
        'name'        => 'P',
        'category_id' => 'cats',
        'quantity'    => 1,
        'pricing'     => ['regular_tnd' => 100, 'promo_tnd' => 100],
    ];
    $errors = CWSB_Add_Product_Support_Service::validate_create_payload($payload);
    $fields = array_column($errors, 'field');
    assert_equals(true, in_array('product.pricing.promo_tnd', $fields, true), 'promo >= regular error');
});

run_test('validate_create_payload: promo_tnd without regular_tnd returns error', function () {
    $payload = [
        'name'        => 'P',
        'category_id' => 'cats',
        'quantity'    => 1,
        'pricing'     => ['promo_tnd' => 80],
    ];
    $errors = CWSB_Add_Product_Support_Service::validate_create_payload($payload);
    $fields = array_column($errors, 'field');
    assert_equals(true, in_array('product.pricing.regular_tnd', $fields, true), 'missing regular_tnd error');
    // Also triggers the "no price at all" error since regular_tnd and regular_eur are both 0
    assert_equals(true, in_array('product.pricing.regular', $fields, true), 'no price error present');
});

run_test('validate_create_payload: legacy field names (prix_regulier_tnd, quantite)', function () {
    $payload = [
        'name'              => 'Produit',
        'category_id'       => 'cats',
        'quantite'          => 3,
        'prix_regulier_tnd' => 200,
    ];
    assert_empty_array(CWSB_Add_Product_Support_Service::validate_create_payload($payload), 'legacy fields valid');
});

run_test('validate_create_payload: non-array payload returns errors gracefully', function () {
    $errors = CWSB_Add_Product_Support_Service::validate_create_payload(null);
    assert_equals(true, count($errors) > 0, 'non-array has errors');
    $errors2 = CWSB_Add_Product_Support_Service::validate_create_payload('bad-string');
    assert_equals(true, count($errors2) > 0, 'string input has errors');
});

// ---------------------------------------------------------------------------
// 4. Tests: CWSB_Utils pure helpers
// ---------------------------------------------------------------------------

run_test('normalize_text: trims and returns plain text unchanged', function () {
    assert_equals('hello', CWSB_Utils::normalize_text('  hello  '), 'trim');
    assert_equals('hello world', CWSB_Utils::normalize_text('hello world'), 'space');
    assert_equals('', CWSB_Utils::normalize_text(''), 'empty');
    assert_equals('', CWSB_Utils::normalize_text('   '), 'whitespace only');
});

run_test('normalize_phone: Tunisia 8-digit number', function () {
    assert_equals('21650354773', CWSB_Utils::normalize_phone('50354773'),         '8-digit');
    assert_equals('21650354773', CWSB_Utils::normalize_phone('+21650354773'),      '+216');
    assert_equals('21650354773', CWSB_Utils::normalize_phone('0021650354773'),     '00216');
    assert_equals('21650354773', CWSB_Utils::normalize_phone('21650354773'),       '216 prefix');
});

run_test('normalize_phone: France number formats', function () {
    assert_equals('33782655322', CWSB_Utils::normalize_phone('+33782655322'),      '+33');
    assert_equals('33782655322', CWSB_Utils::normalize_phone('33782655322'),       '33 prefix');
    assert_equals('33782655322', CWSB_Utils::normalize_phone('0033782655322'),     '0033');
    assert_equals('33782655322', CWSB_Utils::normalize_phone('0782655322'),        '0xxx France');
});

run_test('normalize_phone: unsupported formats return empty', function () {
    assert_equals('', CWSB_Utils::normalize_phone('123'), 'too short');
    assert_equals('', CWSB_Utils::normalize_phone(''),    'empty');
    assert_equals('', CWSB_Utils::normalize_phone('abc'), 'letters');
});

run_test('extract_phone_from_flow_token: valid token', function () {
    assert_equals('21650354773', CWSB_Utils::extract_phone_from_flow_token('flowtoken-50354773-1234567890'), '8-digit in token');
    assert_equals('21650354773', CWSB_Utils::extract_phone_from_flow_token('flowtoken-21650354773-9999'),   '11-digit in token');
});

run_test('extract_phone_from_flow_token: invalid token returns empty', function () {
    assert_equals('', CWSB_Utils::extract_phone_from_flow_token(''),                   'empty');
    assert_equals('', CWSB_Utils::extract_phone_from_flow_token('invalidtoken'),       'no pattern');
    assert_equals('', CWSB_Utils::extract_phone_from_flow_token('flowtoken-abc-xyz'),  'non-numeric seq');
});

run_test('to_bool: truthy and falsy values', function () {
    assert_equals(true,  CWSB_Utils::to_bool(true),    'true bool');
    assert_equals(true,  CWSB_Utils::to_bool('1'),     '1 string');
    assert_equals(true,  CWSB_Utils::to_bool('true'),  'true string');
    assert_equals(true,  CWSB_Utils::to_bool('yes'),   'yes string');
    assert_equals(false, CWSB_Utils::to_bool(false),   'false bool');
    assert_equals(false, CWSB_Utils::to_bool('0'),     '0 string');
    assert_equals(false, CWSB_Utils::to_bool(''),      'empty string');
    assert_equals(false, CWSB_Utils::to_bool('no'),    'no string');
    assert_equals(false, CWSB_Utils::to_bool('false'), 'false string');
});

// ---------------------------------------------------------------------------
// 5. Tests: CWSB_Logger (no WP dependency â€” only uses microtime / error_log)
// ---------------------------------------------------------------------------

run_test('CWSB_Logger::measure: returns result and elapsed_ms', function () {
    $measured = CWSB_Logger::measure('test_sum', function () {
        $sum = 0;
        for ($i = 0; $i < 1000; $i++) {
            $sum += $i;
        }
        return $sum;
    });

    assert_equals(true, isset($measured['result']),    'result key present');
    assert_equals(true, isset($measured['elapsed_ms']),'elapsed_ms key present');
    assert_nearly_equals(499500, $measured['result'],  'sum 0..999', 0.5);
    assert_equals(true, $measured['elapsed_ms'] >= 0,  'elapsed non-negative');
});

run_test('CWSB_Logger::end_timer without start_timer logs warning and returns 0', function () {
    $elapsed = CWSB_Logger::end_timer('nonexistent_timer_xyz');
    assert_nearly_equals(0.0, $elapsed, 'returns 0 when no start');
});

run_test('CWSB_Logger::start_timer + end_timer measures real elapsed time', function () {
    CWSB_Logger::start_timer('sleep_test');
    // Perform a small but measurable computation
    $x = 0;
    for ($i = 0; $i < 50000; $i++) {
        $x += sqrt($i);
    }
    $elapsed = CWSB_Logger::end_timer('sleep_test', 'Computation completed');
    assert_equals(true, $elapsed >= 0,    'elapsed >= 0');
    assert_equals(true, is_float($elapsed), 'elapsed is float');
    // Sanity: should be well under 5000ms on any machine
    assert_equals(true, $elapsed < 5000, 'elapsed < 5000ms');
});

// ---------------------------------------------------------------------------
// 6. Results output
// ---------------------------------------------------------------------------

$passed_count = 0;
$failed_count = 0;

echo PHP_EOL;
echo '=======================================================================' . PHP_EOL;
echo '  CWSB Plugin â€” Unit Test Report' . PHP_EOL;
echo '  ' . date('Y-m-d H:i:s') . PHP_EOL;
echo '=======================================================================' . PHP_EOL . PHP_EOL;

$max_name_len = max(array_map(fn($r) => strlen($r['name']), $results)) + 2;

printf("  %-{$max_name_len}s  %6s  %8s  %5s\n", 'Test', 'Status', 'Time(Âµs)', 'Asrts');
echo '  ' . str_repeat('-', $max_name_len + 26) . PHP_EOL;

foreach ($results as $r) {
    $status = $r['passed'] ? 'PASS' : 'FAIL';
    printf(
        "  %-{$max_name_len}s  %-6s  %8.1f  %5d\n",
        $r['name'],
        $status,
        $r['elapsed_us'],
        $r['assertions']
    );
    if (!$r['passed']) {
        foreach ($r['failures'] as $f) {
            echo $f . PHP_EOL;
        }
        $failed_count++;
    } else {
        $passed_count++;
    }
}

$total_elapsed_ms = round((microtime(true) - SUITE_START) * 1000, 2);

echo PHP_EOL;
echo '-----------------------------------------------------------------------' . PHP_EOL;
printf(
    "  Tests: %d passed, %d failed  |  Assertions: %d  |  Total time: %.2f ms\n",
    $passed_count,
    $failed_count,
    $total_assertions,
    $total_elapsed_ms
);
echo '-----------------------------------------------------------------------' . PHP_EOL;
echo PHP_EOL;

exit($failed_count > 0 ? 1 : 0);
