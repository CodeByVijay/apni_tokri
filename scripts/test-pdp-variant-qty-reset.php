<?php

declare(strict_types=1);

/**
 * PDP resets quantity to variant min on variant change (catalogue.js contract).
 * CLI: php scripts/test-pdp-variant-qty-reset.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

function t(bool $ok, string $label): void
{
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) {
        exit(1);
    }
}

$js = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/catalogue.js');
t(strpos($js, 'function updateFromVariant($opt, resetQuantity)') !== false, 'updateFromVariant accepts resetQuantity');
t(strpos($js, 'updateFromVariant($(this), true)') !== false, 'variant change passes resetQuantity true');
t(strpos($js, 'if (resetQuantity)') !== false && strpos($js, '$qty.val(orderMin)') !== false, 'resets qty to orderMin when flag set');

$productPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/product.php');
t(strpos($productPhp, 'data-order-min') !== false, 'PDP variant radios expose data-order-min');

echo "PDP variant quantity reset regression checks passed.\n";
