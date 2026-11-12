<?php

declare(strict_types=1);

/**
 * Assisted-order create page: conditional panels must not combine Tailwind layout classes with [hidden].
 *
 * php scripts/test-admin-assisted-order-panel-markup.php
 */

$fail = 0;
function panelOk(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . PHP_EOL;
    if (!$c) {
        ++$fail;
    }
}

$path = dirname(__DIR__) . '/admin/orders/create.php';
$html = (string) file_get_contents($path);

$panelAttrs = [
    'data-at-aorder-custom-delivery-wrap',
    'data-at-aorder-discount-coupon-panel',
    'data-at-aorder-discount-custom-panel',
    'data-at-aorder-offline-wrap',
];

foreach ($panelAttrs as $attr) {
    $re = '/<div([^>]*' . preg_quote($attr, '/') . '[^>]*)>/';
    if (!preg_match($re, $html, $m)) {
        panelOk(false, "panel wrapper found: {$attr}");
        continue;
    }
    $attrs = $m[1];
    panelOk(str_contains($attrs, 'data-at-aorder-panel'), "{$attr} has data-at-aorder-panel");
    panelOk(str_contains($attrs, 'hidden'), "{$attr} is hidden on initial load");
    panelOk(!preg_match('/\bclass="[^"]*\bgrid\b/', $attrs), "{$attr} wrapper has no grid class (Tailwind vs hidden)");
}

$cssPath = dirname(__DIR__) . '/assets/css/apni-tokri.css';
$css = (string) file_get_contents($cssPath);
panelOk(
    str_contains($css, '.at-assisted-order [data-at-aorder-panel][hidden]'),
    'CSS enforces hidden assisted-order panels'
);

exit($fail > 0 ? 1 : 0);
