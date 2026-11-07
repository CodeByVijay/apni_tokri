<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
$ok = static function (bool $cond, string $msg) use (&$fail): void {
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        $fail++;
    } else {
        echo "PASS: $msg\n";
    }
};

$od = (string) file_get_contents(APNI_TOKRI_ROOT . '/order-details.php');
$partial = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/order-retry-payment-cta.php');
$launch = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/order-retry-payment-launch.php');
$svc = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/payment/service.php');
$css = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/css/apni-tokri.css');

$ok(str_contains($od, 'order-retry-payment-cta.php'), 'order-details includes retry CTA partial');
$ok(str_contains($od, 'order-retry-payment-launch.php'), 'order-details includes gateway launch partial');
$ok(str_contains($od, 'storefront_retry_payment'), 'POST retry handler');
$ok(str_contains($od, 'prepareStorefrontOrderPayment'), 'uses shared payment preparation');
$ok(!preg_match('/<a[^>]+href=[^>]*payment\.php\?order=/', $od . $partial), 'no payment.php link from retry CTA');
$ok(str_contains($od, 'checkout_payment_autolaunch'), 'autolaunch session on order details');
$ok(str_contains($od, 'Retry Payment'), 'top CTA references Retry Payment pointer in payment section');

$ok(str_contains($partial, 'storefront_retry_payment'), 'CTA posts to order-details');
$ok(str_contains($partial, 'data-at-retry-payment'), 'busy-state hook on CTA');
$ok(str_contains($partial, 'at-order-retry-pay at-card'), 'CTA aligned with status card shell');

$ok(str_contains($launch, 'autoLaunchGateway'), 'launch exposes autolaunch to client');
$ok(str_contains($launch, 'at-cashfree-pay'), 'Cashfree launch button id preserved');

$ok(str_contains($svc, 'function prepareStorefrontOrderPayment'), 'PaymentService preparation helper');
$ok(str_contains($svc, 'grand_total'), 'amount from server order row');

$ok(str_contains($css, '.at-order-retry-pay__btn'), 'retry button styles');
$ok(!preg_match('/\.at-order-retry-pay\s*\{[^}]*max-width:\s*28rem/s', $css), 'desktop retry card not narrow');

$ok(PaymentService::customerCanRetryStorefrontPayment([
    'payment_status' => 'pending',
    'status' => 'pending_payment',
]), 'eligible: pending payment order');
$ok(!PaymentService::customerCanRetryStorefrontPayment([
    'payment_status' => 'paid',
    'status' => 'confirmed',
]), 'ineligible: paid');
$ok(!PaymentService::customerCanRetryStorefrontPayment([
    'payment_status' => 'pending',
    'status' => 'cancelled',
]), 'ineligible: cancelled');

exit($fail > 0 ? 1 : 0);
