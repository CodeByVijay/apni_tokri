<?php

declare(strict_types=1);

/**
 * Static + fixture checks: assisted-order conditional panels survive Tailwind [hidden] override.
 *
 * php scripts/test-admin-assisted-order-hidden-panels.php
 */
$root = dirname(__DIR__);
$fail = 0;
function hpOk(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . PHP_EOL;
    if (!$c) {
        ++$fail;
    }
}

$create = (string) file_get_contents($root . '/admin/orders/create.php');
$css = (string) file_get_contents($root . '/assets/css/apni-tokri.css');
$js = (string) file_get_contents($root . '/assets/js/admin-assisted-order.js');

hpOk(str_contains($css, '.at-assisted-order [hidden]'), 'CSS forces [hidden] display:none under assisted order');
hpOk(str_contains($css, '.at-aorder-is-hidden'), 'CSS defines .at-aorder-is-hidden');
hpOk(str_contains($js, 'setAorderPanelVisible'), 'JS panel visibility helper present');
hpOk(str_contains($js, "classList.toggle('at-aorder-is-hidden'"), 'JS toggles at-aorder-is-hidden class');
hpOk(str_contains($js, 'toggleDeliveryModeFields();'), 'delivery toggle called on init');
hpOk(str_contains($js, 'togglePaymentModeFields();'), 'payment toggle called on init');
hpOk(str_contains($js, 'toggleDiscountModeFields();'), 'discount toggle called on init');

foreach ([
    'data-at-aorder-custom-delivery-wrap',
    'data-at-aorder-offline-wrap',
    'data-at-aorder-discount-coupon-panel',
    'data-at-aorder-discount-custom-panel',
] as $attr) {
    hpOk(
        (bool) preg_match('/' . preg_quote($attr, '/') . '[^>]*\bhidden\b/', $create),
        $attr . ' has hidden in markup'
    );
    hpOk(
        (bool) preg_match('/' . preg_quote($attr, '/') . '[^>]*\bat-aorder-is-hidden\b/', $create),
        $attr . ' has at-aorder-is-hidden class in markup'
    );
}

// Same-element anti-pattern: class="…grid…" and hidden on one tag for these panels.
hpOk(
    !preg_match('/class="[^"]*\bgrid\b[^"]*"[^>]*data-at-aorder-(custom-delivery-wrap|offline-wrap|discount-custom-panel)[^>]*\bhidden\b/', $create)
    && !preg_match('/data-at-aorder-(custom-delivery-wrap|offline-wrap|discount-custom-panel)[^>]*class="[^"]*\bgrid\b[^"]*"[^>]*\bhidden\b/', $create),
    'grid utility not on same element as hidden panel wrappers'
);

$fixture = $root . '/scripts/fixtures/assisted-order-hidden-panels.html';
hpOk(is_file($fixture), 'browser fixture HTML exists');

echo $fail === 0 ? "All assisted-order hidden-panel static checks passed.\n" : "{$fail} check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
