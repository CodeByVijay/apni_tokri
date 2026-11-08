<?php

declare(strict_types=1);

/** Static: cart minimum-order UI updates on AJAX (no browser). */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
$ok = static function (bool $c, string $m) use (&$fail): void {
    echo ($c ? 'PASS: ' : 'FAIL: ') . $m . "\n";
    if (!$c) {
        $fail++;
    }
};

$js = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/cart.js');
$ok(str_contains($js, 'refreshMinimumOrder(totals)') && str_contains($js, 'function refreshSummary(totals)'), 'refreshSummary invokes refreshMinimumOrder');
$ok(str_contains($js, 'applyCartMutationResponse') && str_contains($js, 'refreshSummary(totals)'), 'cart mutation refreshes summary');
$ok(str_contains($js, 'data-cart-min-order-alert'), 'red cart alert selector');
$ok(str_contains($js, 'fillMinOrderCopy'), 'cart alert and summary share min-order copy');
$marketing = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/settings/marketing.php');
$ok(str_contains($marketing, 'Minimum order value after discounts is {amount}.'), 'default min-order message explains after discounts');
$ok(str_contains($js, 'data-cart-checkout-cta'), 'checkout CTA toggled with min order');
$update = (string) file_get_contents(APNI_TOKRI_ROOT . '/ajax/cart/update.php');
$ok(str_contains($update, 'cartAjaxPayload'), 'update endpoint returns full totals payload');
$pricing = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/commerce/pricing.php');
$ok(str_contains($pricing, 'attachMinimumOrderTotals'), 'server totals include min order fields');
$cartPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/cart.php');
$checkoutPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/checkout.php');
$ok(str_contains($cartPhp, 'data-cart-min-order-remaining'), 'cart.php two-line min order warning');
$ok(str_contains($checkoutPhp, 'data-checkout-min-order-remaining'), 'checkout.php two-line min order warning');
$merch = MarketingSettings::merchandiseSubtotalAfterDiscounts([
    'subtotal' => 80.0,
    'offer_discount' => 10.0,
    'coupon_discount' => 0.0,
]);
$ok(abs($merch - 70.0) < 0.01, 'static: ₹70 merchandise after ₹10 offer on ₹80 subtotal');

exit($fail > 0 ? 1 : 0);
