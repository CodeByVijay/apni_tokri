<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/stage12_phase_c1_fixture.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function skip(string $m): void
{
    echo 'SKIP: ' . $m . "\n";
}

$fixture = stage12_phase_c1_create_fixture($pdo);
$customerA = (int) $fixture['customer_a'];
$customerB = (int) $fixture['customer_b'];
$orderId = (int) $fixture['order_id'];
$paymentId = (int) $fixture['payment_id'];
$refundRequestIds = [];
$emptyCustomer = 0;

try {
    $cleanupRf = $pdo->prepare(
        "DELETE FROM payment_refunds WHERE gateway = 'simulate' AND gateway_refund_id = ?"
    );
    $cleanupRf->execute(['rf_c1_' . $fixture['token']]);
    $cleanupRf->execute(['rf_test_id']);

    $capturedCheck = $pdo->prepare('SELECT status FROM payments WHERE id = ?');
    $capturedCheck->execute([$paymentId]);
    ok((string) $capturedCheck->fetchColumn() === 'captured', 'Fixture payment is captured (production representation)');

$reqNum = 'RF-C1T-' . $fixture['token'];
$pdo->prepare(
    'INSERT INTO refund_requests
     (request_number, customer_id, order_id, reason_code, description, preferred_resolution, status, internal_notes, rejection_reason)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    $reqNum,
    $customerA,
    $orderId,
    'quality',
    'Phase C1 test request',
    'refund',
    RefundStatus::REJECTED,
    'INTERNAL_ADMIN_ONLY_NOTE',
    'Visible rejection to customer',
]);
$requestId = (int) $pdo->lastInsertId();
$refundRequestIds[] = $requestId;

$pdo->prepare(
    'INSERT INTO refund_request_items (refund_request_id, order_item_id, quantity, amount_inr)
     VALUES (?, ?, 1, ?)'
)->execute([$requestId, (int) $fixture['order_item_id'], 150.00]);

RefundRequestService::appendHistoryPublic($pdo, $requestId, 'customer', $customerA, 'requested', ['amount' => 50]);
RefundRequestService::appendHistoryPublic($pdo, $requestId, 'admin', 1, 'rejected', ['reason' => 'hidden in payload']);
RefundRequestService::appendHistoryPublic($pdo, $requestId, 'system', null, 'gateway_failed', [
    'message' => 'secret_gateway_response',
    'api_key' => 'must_not_leak',
]);

$pdo->prepare(
    'INSERT INTO payment_refunds
     (refund_request_id, payment_id, gateway, amount_inr, idempotency_key, gateway_refund_id, gateway_status, response_json)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    $requestId,
    $paymentId,
    'simulate',
    10.00,
    'c1_test_' . $requestId,
    'rf_c1_' . $fixture['token'],
    'pending',
    json_encode(['secret' => 'response_json_blob'], JSON_UNESCAPED_UNICODE),
]);

$listA = RefundRequestService::listForCustomer($customerA, 100);
ok(count(array_filter($listA, static function (array $r) use ($reqNum): bool {
    return (string) ($r['request_number'] ?? '') === $reqNum;
})) === 1, 'Customer A sees own refund in list');

$listB = RefundRequestService::listForCustomer($customerB, 100);
ok(count(array_filter($listB, static function (array $r) use ($requestId): bool {
    return (int) ($r['id'] ?? 0) === $requestId;
})) === 0, 'Customer B does not see Customer A refund');

$byNumA = RefundRequestService::byNumberForCustomer($reqNum, $customerA);
ok($byNumA !== null && (int) $byNumA['id'] === $requestId, 'Customer A loads refund by number');

ok(RefundRequestService::byNumberForCustomer($reqNum, $customerB) === null, 'Direct detail URL owner check by number');

ok(RefundRequestService::getForCustomer($requestId, $customerB) === null, 'Direct detail ID cannot bypass ownership');

$rawRow = $pdo->prepare('SELECT * FROM refund_requests WHERE id = ?');
$rawRow->execute([$requestId]);
$raw = $rawRow->fetch(PDO::FETCH_ASSOC) ?: [];
ok(isset($raw['internal_notes']) && $raw['internal_notes'] !== '', 'Fixture has internal notes');
$safe = RefundRequestService::customerSafeRequest($raw);
ok(!array_key_exists('internal_notes', $safe), 'Internal admin notes stripped from customer view');

$timeline = RefundRequestService::customerTimeline($requestId, $customerA);
$timelineJson = json_encode($timeline, JSON_UNESCAPED_UNICODE);
ok(!str_contains((string) $timelineJson, 'secret_gateway_response'), 'Timeline hides gateway failure payload');
ok(!str_contains((string) $timelineJson, 'must_not_leak'), 'Timeline hides gateway credentials from payload');
ok(!str_contains((string) $timelineJson, 'INTERNAL_ADMIN'), 'Timeline hides internal notes');

$prRows = RefundRequestService::paymentRefundsForRequest($requestId, $customerA);
ok($prRows !== [] && !array_key_exists('response_json', $prRows[0]), 'Payment refunds omit response_json');

$rejectedEvent = null;
foreach ($timeline as $ev) {
    if (($ev['label'] ?? '') === 'Refund rejected') {
        $rejectedEvent = $ev;
    }
}
ok($rejectedEvent !== null && str_contains((string) ($rejectedEvent['detail'] ?? ''), 'Visible rejection'), 'Rejection reason visible on timeline');

ok(RefundRequestService::publicStatusLabel(RefundStatus::PROCESSING) === 'Refund processing', 'Status label mapping');
ok(RefundRequestService::publicGatewayStatusLabel('processed') === 'Refund completed', 'Gateway label terminal success');

$pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute([
    '987C1E' . substr($fixture['token'], 0, 4),
    'Stage12 C1 empty customer',
]);
$emptyCustomer = (int) $pdo->lastInsertId();
ok(RefundRequestService::listForCustomer($emptyCustomer) === [], 'Empty state customer has no refunds');

if (!empty($fixture['subscription_payment_id'])) {
    $subReqNum = 'RF-C1SUB-' . $fixture['token'];
    $pdo->prepare(
        'INSERT INTO refund_requests (request_number, customer_id, order_id, reason_code, description, preferred_resolution, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $subReqNum,
        $customerA,
        $orderId,
        'other',
        'sub link test',
        'refund',
        RefundStatus::APPROVED,
    ]);
    $subReqId = (int) $pdo->lastInsertId();
    $refundRequestIds[] = $subReqId;
    $pdo->prepare(
        'INSERT INTO payment_refunds
         (refund_request_id, payment_id, subscription_payment_id, gateway, amount_inr, idempotency_key, gateway_status)
         VALUES (?, NULL, ?, ?, 5.00, ?, ?)'
    )->execute([
        $subReqId,
        (int) $fixture['subscription_payment_id'],
        'simulate',
        'c1_sub_' . $subReqId,
        'pending',
    ]);
    $ctx = RefundRequestService::subscriptionContextForRequest($subReqId, $customerA);
    ok(
        $ctx !== null && $ctx['subscription_number'] === (string) $fixture['subscription_number'],
        'Subscription refund context when payment_refunds linked'
    );
} else {
    skip('veg_7 plan unavailable — subscription context');
}

$createProbe = RefundRequestService::create(
    $customerB,
    (string) $fixture['order_number'],
    'other',
    'probe',
    'refund',
    [],
    []
);
ok($createProbe['ok'] === false, 'Existing refund create validation unchanged (foreign order items)');

} catch (Throwable $e) {
    echo 'FAIL: Uncaught exception — ' . $e->getMessage() . "\n";
    $fail++;
} finally {
    stage12_phase_c1_teardown($pdo, $fixture, $refundRequestIds);
    if (isset($emptyCustomer) && $emptyCustomer > 0) {
        $pdo->prepare('DELETE FROM customers WHERE id = ? AND mobile LIKE ?')->execute([$emptyCustomer, '987C1E%']);
    }
}

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Stage 12 Phase C1 customer refund visibility.\n";
exit(0);
