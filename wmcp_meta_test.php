<?php
// One-off local smoke test for WMCP TND meta writes.
// Usage (PowerShell):
// C:\xampp\php\php.exe wmcp_meta_test.php --product=271172 --regular=60 --sale=8

define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 3) . '/wp-load.php';

if (!function_exists('get_post_meta')) {
    fwrite(STDERR, "WordPress bootstrap failed.\n");
    exit(1);
}

$args = [];
foreach ($argv as $arg) {
    if (strpos($arg, '--') === 0) {
        $eq = strpos($arg, '=');
        if ($eq !== false) {
            $k = substr($arg, 2, $eq - 2);
            $v = substr($arg, $eq + 1);
            $args[$k] = $v;
        }
    }
}

$product_id = isset($args['product']) ? (int) $args['product'] : 0;
$regular = isset($args['regular']) ? (string) $args['regular'] : '';
$sale = isset($args['sale']) ? (string) $args['sale'] : '';

if ($product_id <= 0) {
    fwrite(STDERR, "Missing --product=<id>\n");
    exit(1);
}

if ($regular === '') {
    fwrite(STDERR, "Missing --regular=<value>\n");
    exit(1);
}

$wmcp_regular = wp_json_encode(['TND' => $regular]);
$wmcp_sale = $sale !== '' ? wp_json_encode(['TND' => $sale]) : '';

delete_post_meta($product_id, '_regular_price_wmcp');
update_post_meta($product_id, '_regular_price_wmcp', $wmcp_regular);

if ($sale !== '') {
    delete_post_meta($product_id, '_sale_price_wmcp');
    update_post_meta($product_id, '_sale_price_wmcp', $wmcp_sale);
} else {
    delete_post_meta($product_id, '_sale_price_wmcp');
}

$result = [
    'product_id' => $product_id,
    '_regular_price_wmcp' => get_post_meta($product_id, '_regular_price_wmcp', true),
    '_sale_price_wmcp' => get_post_meta($product_id, '_sale_price_wmcp', true),
    '_regular_price_tnd' => get_post_meta($product_id, '_regular_price_tnd', true),
    '_sale_price_tnd' => get_post_meta($product_id, '_sale_price_tnd', true),
    '_price_tnd' => get_post_meta($product_id, '_price_tnd', true),
];

echo wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
