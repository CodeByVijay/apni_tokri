<?php

declare(strict_types=1);

/**
 * Order summary partial: coupon row visibility (cart + checkout share this partial).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$pass = 0;
$fail = 0;

function t(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

/** @param array<string, mixed> $totals */
function renderCartSummary(array $totals): string
{
    ob_start();
    $compact = false;
    require APNI_TOKRI_ROOT . '/includes/partials/cart-summary.php';

    return (string) ob_get_clean();
}

$baseTotals = [
    'subtotal' => 200.0,
    'product_savings' => 0.0,
    'offer_discount' => 0.0,
    'offer_label' => 'Automatic offer',
    'coupon_discount' => 0.0,
    'coupon_code' => null,
    'delivery_fee' => 0.0,
    'total' => 200.0,
    'delivery_quote_accurate' => true,
    'free_delivery_eligible' => false,
];

// No coupon: row must stay hidden (class + attribute) and must not expose empty "Coupon ()" as visible content.
$htmlNone = renderCartSummary($baseTotals);
t(!catalogue_cart_coupon_applied($baseTotals), 'Helper: no coupon when code/discount absent');
t(strpos($htmlNone, 'data-cart-coupon-row') !== false, 'Coupon row placeholder exists for AJAX');
t(
    preg_match('/data-cart-coupon-row[^>]*\bhidden\b/', $htmlNone) === 1,
    'No coupon: summary row marked hidden'
);
t(strpos($htmlNone, 'data-cart-coupon-code') !== false, 'No coupon: coupon code span present for AJAX updates');
t(strpos($htmlNone, 'Coupon (') === false || preg_match('/data-cart-coupon-row[^>]*hidden[^>]*>[\s\S]*Coupon \(/', $htmlNone) === 1, 'No coupon: no visible Coupon () label');

// Stale code with zero discount (invalid state): treat as not applied.
$stale = $baseTotals;
$stale['coupon_code'] = 'GHOST';
$stale['coupon_discount'] = 0.0;
$htmlStale = renderCartSummary($stale);
t(!catalogue_cart_coupon_applied($stale), 'Helper: code without discount is not applied');
t(preg_match('/data-cart-coupon-row[^>]*\bhidden\b/', $htmlStale) === 1, 'Stale code: row hidden');

// Valid coupon.
$applied = $baseTotals;
$applied['coupon_code'] = 'WELCOME10';
$applied['coupon_discount'] = 20.0;
$applied['total'] = 180.0;
$htmlApplied = renderCartSummary($applied);
t(catalogue_cart_coupon_applied($applied), 'Helper: valid coupon applied');
t(
    preg_match('/data-cart-coupon-row[^>]*\bhidden\b/', $htmlApplied) !== 1,
    'Applied coupon: row not hidden'
);
t(strpos($htmlApplied, 'WELCOME10') !== false, 'Applied coupon: code in summary');
t(strpos($htmlApplied, '−₹20') !== false || strpos($htmlApplied, '₹20') !== false, 'Applied coupon: discount shown');

// Automatic offer without coupon.
$offerOnly = $baseTotals;
$offerOnly['offer_discount'] = 15.0;
$htmlOffer = renderCartSummary($offerOnly);
t(strpos($htmlOffer, 'Automatic offer') !== false, 'Offer-only: automatic offer line present');
t(preg_match('/data-cart-coupon-row[^>]*\bhidden\b/', $htmlOffer) === 1, 'Offer-only: coupon row hidden');

// CSS guard for .hidden vs display:flex.
$css = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/css/apni-tokri.css');
t(strpos($css, '.at-summary-list > div.hidden') !== false, 'CSS: hidden rows override summary flex display');

// Cart and checkout use the same partial.
$cartSrc = (string) file_get_contents(APNI_TOKRI_ROOT . '/cart.php');
$checkoutSrc = (string) file_get_contents(APNI_TOKRI_ROOT . '/checkout.php');
t(
    strpos($cartSrc, 'includes/partials/cart-summary.php') !== false
    && strpos($checkoutSrc, 'includes/partials/cart-summary.php') !== false,
    'Cart and checkout include the same order summary partial'
);

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
