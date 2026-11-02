<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

ok(Install::installedSchemaVersion() === Install::SCHEMA_VERSION, 'Schema matches Install::SCHEMA_VERSION');

$secret = 'whsec_test_' . bin2hex(random_bytes(4));
AppSettings::set('razorpay.webhook_secret', $secret, true);
$raw = '{"event":"refund.processed","payload":{"refund":{"entity":{"id":"rf_test_1","amount":10000,"status":"processed","notes":{"idempotency_key":"refund_req_999_attempt_1"}}}}}';
$sig = hash_hmac('sha256', $raw, $secret);
ok(RazorpayGateway::verifyWebhookSignature($raw, $sig), 'Valid Razorpay webhook signature');
ok(!RazorpayGateway::verifyWebhookSignature($raw, 'bad'), 'Invalid Razorpay webhook signature rejected');

$eid = 'refund_wh_' . Security::randomToken(4);
ok(PaymentService::storeWebhookEvent('razorpay', $eid, $raw), 'Refund webhook idempotency store');
ok(!PaymentService::storeWebhookEvent('razorpay', $eid, $raw), 'Duplicate refund webhook rejected');

$ignored = RefundWebhookService::handleRazorpay(json_decode($raw, true) ?: [], $eid);
ok($ignored['ok'] === false, 'Unknown refund idempotency ignored safely');

$ts = (string) time();
$cfRaw = '{"type":"REFUND_SUCCESS_WEBHOOK","data":{"refund":{"cf_refund_id":"cf_ref_1","refund_status":"SUCCESS","refund_amount":10}}}';
$cfWhBefore = AppSettings::getSecret('cashfree.webhook_secret', '') ?? '';
$usedEphemeralCfWh = false;
$cfSigningSecret = CashfreeGateway::webhookSigningSecret();
if ($cfSigningSecret === '') {
    $usedEphemeralCfWh = true;
    AppSettings::set('cashfree.webhook_secret', 'cf_test_wh_' . bin2hex(random_bytes(4)), true);
    $cfSigningSecret = CashfreeGateway::webhookSigningSecret();
}
$cfSig = base64_encode(hash_hmac('sha256', $ts . $cfRaw, $cfSigningSecret, true));
ok(CashfreeGateway::verifyWebhookSignature($cfRaw, $cfSig, $ts), 'Cashfree valid webhook signature');
ok(!CashfreeGateway::verifyWebhookSignature($cfRaw, 'bad', $ts), 'Cashfree invalid webhook signature rejected');
if ($usedEphemeralCfWh) {
    AppSettings::set('cashfree.webhook_secret', $cfWhBefore, true);
}

ok(RefundCompletionService::isTerminalSuccess('processed'), 'Terminal success statuses');
ok(!RefundCompletionService::isTerminalSuccess('pending'), 'Pending not terminal');

ok(GatewayRefundService::refundPayment(999999, 1.0, 'cap_test_' . time())['ok'] === false, 'Refund cap rejects missing payment');

$badItem = ReviewService::submit(999999, 999999, 5, 'test review body here');
ok($badItem['ok'] === false, 'Review rejects foreign order item');

$att = Upload::storeSupportAttachment(['error' => UPLOAD_ERR_NO_FILE]);
ok($att['ok'] === false, 'Empty attachment rejected');

$ticket = SupportTicketService::create(null, 'other', 'Test', 'Hello');
ok($ticket['ok'] === true, 'Guest ticket create still allowed');

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Stage 12 Phase B checks.\n";
exit(0);
