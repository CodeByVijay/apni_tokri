<?php

declare(strict_types=1);

/**
 * Storefront payment — auto-open Cashfree / Razorpay after Proceed to pay (fallback page retained).
 * Run: php scripts/run-in-disposable-db.php scripts/test-payment-cashfree-autolaunch.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$root = dirname(__DIR__);
$checkout = (string) file_get_contents($root . '/includes/commerce/checkout.php');
$payment = (string) file_get_contents($root . '/payment.php');
$js = (string) file_get_contents($root . '/assets/js/apni-tokri.js');

ok(str_contains($checkout, 'checkout_payment_autolaunch'), 'Checkout sets gateway auto-launch session flag');
ok(str_contains($checkout, "'razorpay'"), 'Checkout auto-launch includes Razorpay');
ok(str_contains($checkout, "'cashfree'"), 'Checkout auto-launch includes Cashfree');
ok(str_contains($payment, 'autoLaunchGatewayCheckout'), 'Payment page computes gateway auto-launch');
ok(str_contains($payment, 'autoLaunchGateway'), 'Payment page exposes auto-launch to client');
ok(str_contains($payment, 'gatewayMode'), 'Payment page exposes active gateway mode to client');
ok(str_contains($payment, 'Refresh payment status'), 'Cashfree recovery refresh preserved');
ok(str_contains($payment, 'retry=1'), 'Payment retry path preserved');
ok(str_contains($js, 'openCashfreeCheckout'), 'Cashfree opener helper');
ok(str_contains($js, 'openRazorpayCheckout'), 'Razorpay opener helper');
ok(str_contains($js, 'autoLaunchGateway'), 'Client auto-launches configured gateway');
ok(str_contains($js, 'verifyUrl'), 'Server-side verify POST preserved for Razorpay');
ok(str_contains($js, 'cashfreeSyncStatus'), 'Cashfree customer sync preserved');
ok(str_contains($payment, 'syncCashfreeOrderPayment'), 'Cashfree server sync on payment page load');

echo $fail === 0 ? "PASS: payment gateway autolaunch.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
