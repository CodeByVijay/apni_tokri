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
$css = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/css/apni-tokri.css');

$ok(str_contains($od, 'order-retry-payment-cta.php'), 'order-details includes retry CTA partial');
$ok(str_contains($od, 'customerCanRetryStorefrontPayment'), 'uses existing retry eligibility');
$ok(str_contains($od, 'payment.php?order='), 'links to existing payment.php');
$ok(!preg_match('/<a[^>]+href=[^>]*payment\.php\?order=/', $od), 'no duplicate retry anchor in order-details (CTA lives in partial)');
$ok(str_contains($od, 'Retry Payment'), 'top CTA references Retry Payment pointer in payment section');

$ok(str_contains($partial, 'data-at-retry-payment'), 'busy-state hook on CTA');
$ok(str_contains($partial, 'Complete your payment to confirm'), 'helper copy present');
$ok(str_contains($partial, 'Retry Payment'), 'button label');

$ok(str_contains($css, '.at-order-retry-pay__btn'), 'mobile full-width retry styles');

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
