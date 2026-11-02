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

ok(Install::installedSchemaVersion() === Install::SCHEMA_VERSION, 'Schema version matches Install::SCHEMA_VERSION');

$labels = RefundRequestService::reasonLabels();
ok(isset($labels['quality']) && isset($labels['duplicate_payment']), 'Refund reason labels');

$res = TestimonialService::saveAdmin(null, 'A', 'Story', false, null, false, 0);
ok(!$res['ok'], 'Testimonial blocked without consent');

$res2 = TestimonialService::saveAdmin(null, 'A', 'Story', true, 'phone log', false, 0);
ok($res2['ok'] && !empty($res2['id']), 'Testimonial saved with consent');

$gw = GatewayRefundService::refundPayment(999999999, 10.0, 'test_invalid_' . time());
ok(!$gw['ok'], 'Refund rejects missing payment');

// Cap check via service logic
ok(RefundRequestService::capturedAmountForOrder(999999999) === 0.0, 'Unknown order captured amount zero');

$histTable = Database::pdo()->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'refund_request_history'"
)->fetchColumn();
ok((int) $histTable === 1, 'Refund audit history table exists');

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Stage 12 trust/refund checks.\n";
exit(0);
