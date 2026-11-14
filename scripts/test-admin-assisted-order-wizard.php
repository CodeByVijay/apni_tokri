<?php

declare(strict_types=1);

/**
 * Assisted-order create page: 4-step wizard markup and JS controller checks.
 *
 * php scripts/test-admin-assisted-order-wizard.php
 */
$root = dirname(__DIR__);
$fail = 0;
function wizOk(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . PHP_EOL;
    if (!$c) {
        ++$fail;
    }
}

$create = (string) file_get_contents($root . '/admin/orders/create.php');
$js = (string) file_get_contents($root . '/assets/js/admin-assisted-order.js');
$css = (string) file_get_contents($root . '/assets/css/apni-tokri.css');

// --- Step panels and progress indicator ---
foreach ([1, 2, 3, 4] as $n) {
    wizOk(str_contains($create, 'data-at-aorder-step-panel="' . $n . '"'), "step {$n} panel present");
}
wizOk(str_contains($create, 'data-at-aorder-stepnav=') && str_contains($create, 'data-at-aorder-stepnav-item'), 'progress stepnav item + button attrs rendered');
foreach (['Customer', 'Products', 'Delivery', 'Payment'] as $label) {
    wizOk(str_contains($create, "'" . $label . "'"), "wizard step label present: {$label}");
}
foreach ([2, 3, 4] as $n) {
    wizOk(
        (bool) preg_match('/data-at-aorder-step-panel="' . $n . '"[^>]*\bhidden\b/', $create),
        "step {$n} panel hidden on initial load"
    );
}
wizOk(
    !preg_match('/data-at-aorder-step-panel="1"[^>]*\bhidden\b/', $create),
    'step 1 panel visible on initial load'
);

// --- Step navigation controls ---
wizOk(substr_count($create, 'data-at-aorder-step-error') === 4, 'per-step error region in each footer');
wizOk(substr_count($create, 'data-at-aorder-next=') === 3, 'three Continue buttons (steps 1-3)');
wizOk(substr_count($create, 'data-at-aorder-prev=') === 3, 'three Back buttons (steps 2-4)');
wizOk(substr_count($create, '>Cancel</a>') === 4, 'Cancel/back-to-orders action on every step');
wizOk(str_contains($create, 'admin/orders/index.php'), 'Cancel links point to orders index');

// --- Step-group content: hooks preserved inside the right step ---
$stepOf = static function (string $html, int $step): string {
    $pos = strpos($html, 'data-at-aorder-step-panel="' . $step . '"');
    $next = strpos($html, 'data-at-aorder-step-panel="' . ($step + 1) . '"');
    return $pos === false ? '' : substr($html, $pos, $next === false ? null : $next - $pos);
};
$s1 = $stepOf($create, 1);
$s2 = $stepOf($create, 2);
$s3 = $stepOf($create, 3);
$s4 = $stepOf($create, 4);
wizOk(str_contains($s1, 'data-at-aorder-customer-results') && str_contains($s1, 'data-at-aorder-address-list'), 'step 1 holds customer search + address list');
wizOk(str_contains($s2, 'data-at-aorder-variant-results') && str_contains($s2, 'data-at-aorder-items-body'), 'step 2 holds product search + items table');
wizOk(str_contains($s2, 'data-at-aorder-items-meta') && str_contains($s2, 'data-at-aorder-items-emptystate'), 'step 2 has item count/subtotal + empty state');
wizOk(str_contains($s3, 'data-at-aorder-delivery-advanced') && str_contains($s3, 'data-at-aorder-free-delivery'), 'step 3 has advanced delivery options with free delivery');
wizOk(str_contains($s4, 'data-at-aorder-discount-none') && str_contains($s4, 'data-at-aorder-pay-pending') && str_contains($s4, 'data-at-aorder-place'), 'step 4 holds discounts + payment + place action');
wizOk(str_contains($s4, 'data-at-aorder-review'), 'step 4 has review/confirmation recap');

// --- JS controller hooks ---
foreach (['stepIsComplete', 'canEnterStep', 'goToStep', 'updateStepNav', 'beforeunload', 'orderPlaced', 'renderReview', 'updateDeliveryWhen', 'updateItemsMeta', 'stepQty'] as $fn) {
    wizOk(str_contains($js, $fn), "JS wizard hook present: {$fn}");
}
wizOk(substr_count($js, 'data-at-aorder-step-panel') >= 2, 'JS toggles step panels');
wizOk(str_contains($js, 'data-at-aorder-stepnav'), 'JS wires progress buttons');

// --- CSS for wizard ---
wizOk(str_contains($css, '.at-aorder-steps__item.is-active'), 'CSS styles active step');
wizOk(str_contains($css, '.at-aorder-steps__item.is-done'), 'CSS styles completed step');
wizOk(str_contains($css, '.at-aorder-steps__item.is-locked'), 'CSS styles locked step');
wizOk(str_contains($css, '.at-qty-stepper'), 'CSS styles quantity steppers');

echo $fail === 0 ? "All assisted-order wizard checks passed.\n" : "{$fail} check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
