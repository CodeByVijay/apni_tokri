<?php

declare(strict_types=1);

/**
 * Product savings scale with quantity; cart summary exposes AJAX hook.
 * CLI: php scripts/test-cart-product-savings.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

function t(bool $ok, string $label): void
{
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) {
        exit(1);
    }
}

$saleLine = static function (int $qty): array {
    return [
        'qty' => $qty,
        'unit_price' => 20.0,
        'regular_price' => 25.0,
        'on_sale' => true,
    ];
};

$noneLine = [
    'qty' => 2,
    'unit_price' => 35.0,
    'regular_price' => 35.0,
    'on_sale' => false,
];

$t1 = PricingService::totalsFromLines([$saleLine(1)], null, '000000');
t(abs((float) $t1['product_savings'] - 5.0) < 0.01, 'qty 1 → ₹5 savings');

$t2 = PricingService::totalsFromLines([$saleLine(2)], null, '000000');
t(abs((float) $t2['product_savings'] - 10.0) < 0.01, 'qty 2 → ₹10 savings');

$t5 = PricingService::totalsFromLines([$saleLine(5)], null, '000000');
t(abs((float) $t5['product_savings'] - 25.0) < 0.01, 'qty 5 → ₹25 savings');

$t3 = PricingService::totalsFromLines([$saleLine(3)], null, '000000');
t(abs((float) $t3['product_savings'] - 15.0) < 0.01, 'qty 3 → ₹15 savings');

$mixed = PricingService::totalsFromLines([$saleLine(2), $noneLine], null, '000000');
t(abs((float) $mixed['product_savings'] - 10.0) < 0.01, 'mixed cart: only sale line counts');

$js = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/cart.js');
t(strpos($js, 'data-cart-product-savings') !== false, 'cart.js updates product savings from totals');

$partial = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/cart-summary.php');
t(strpos($partial, 'data-cart-product-savings-row') !== false, 'cart summary row for AJAX');

echo "Cart product savings tests passed.\n";
