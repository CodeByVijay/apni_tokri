<?php

declare(strict_types=1);

/**
 * My orders list markup regressions (no cancel duplicate, per-card detail URLs, card affordances).
 * CLI: php scripts/test-my-orders-list-ui.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
function okOrd(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$src = (string) file_get_contents(APNI_TOKRI_ROOT . '/my-orders.php');
okOrd(!preg_match('/cancel=1/i', $src), 'Listing does not expose cancel=1 links');
okOrd(!preg_match('/OrderCancellationService/', $src), 'Listing does not call cancellation service');
okOrd(str_contains($src, 'data-at-order-card'), 'Order cards use data-at-order-card');
okOrd(str_contains($src, 'at-order-card__cta'), 'View details CTA class present');
okOrd(str_contains($src, 'chevron-right'), 'View details includes chevron icon');
okOrd(str_contains($src, 'data-at-order-card-stop'), 'Invoice action stops card navigation');
okOrd(preg_match('/data-order-href="<\?= Security::escape\(\$detailUrl\) \?>"/', $src) === 1, 'Card data-order-href matches detail URL variable');
okOrd(preg_match('/href="<\?= Security::escape\(\$detailUrl\) \?>"/', $src) === 1, 'View details href uses same detail URL');
okOrd(str_contains($src, 'customer_status_badge'), 'Status badges preserved');

$js = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/apni-tokri.js');
okOrd(str_contains($js, '[data-at-order-card]') && str_contains($js, 'data-at-order-card-stop'), 'JS handles card click without nested action duplication');

$css = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/css/apni-tokri.css');
okOrd(str_contains($css, 'at-account-nav--responsive'), 'Account nav responsive grid styles');
okOrd(str_contains($css, 'at-order-card__head'), 'Order card hierarchy styles');

$shell = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/account-shell-start.php');
okOrd(str_contains($shell, 'at-account-nav--responsive'), 'Account shell uses responsive nav class');

exit($fail > 0 ? 1 : 0);
