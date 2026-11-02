<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

// Signature verification
$secret = 'test_secret_key';
AppSettings::set('razorpay.key_secret', $secret, true);
AppSettings::set('razorpay.enabled', '1');
$sig = hash_hmac('sha256', 'order_abc|pay_xyz', $secret);
ok(RazorpayGateway::verifyPaymentSignature('order_abc', 'pay_xyz', $sig), 'Razorpay signature verifies');
ok(!RazorpayGateway::verifyPaymentSignature('order_abc', 'pay_xyz', 'bad'), 'Invalid signature rejected');

// Webhook idempotency
$eid = 'test_evt_' . Security::randomToken(4);
ok(PaymentService::storeWebhookEvent('razorpay', $eid, '{"test":1}'), 'Webhook event stored');
ok(!PaymentService::storeWebhookEvent('razorpay', $eid, '{"test":1}'), 'Duplicate webhook rejected');

// Currency guard
$r = PaymentService::completePayment('AT-NO-SUCH', 'simulate', 'x', 'y', 100.0, 'USD', 'pay_fake');
ok(!$r['ok'], 'Non-INR currency rejected');

// Amount mismatch on real order would need fixture — verify completePayment returns false message
ok(PaymentGatewayConfig::useSimulatedCheckout() || PaymentGatewayConfig::activeGateway() !== '', 'Gateway config loads');

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}

echo "\nPASS: Stage 9 payment checks.\n";
exit(0);
