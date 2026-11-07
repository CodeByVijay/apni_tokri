<?php

declare(strict_types=1);

/**
 * Admin approve + gateway process workflow (mocked / simulate gateway; no live API).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

require_once __DIR__ . '/includes/stage12_phase_c1_fixture.php';

$fail = 0;
$ok = static function (bool $cond, string $msg) use (&$fail): void {
    echo ($cond ? 'OK' : 'FAIL') . ': ' . $msg . "\n";
    if (!$cond) {
        $fail++;
    }
};

Install::ensureConnection();
$pdo = Database::pdo();
$_SESSION['admin_user_id'] = 1;
$_SESSION['admin_demo_role'] = 'super_admin';
Permission::clearCache();

$fixture = stage12_phase_c1_create_fixture($pdo);
$orderId = (int) $fixture['order_id'];
$customerA = (int) $fixture['customer_a'];
$tok = $fixture['token'];

$reqNum = 'RF-APPR-' . $tok;
$pdo->prepare(
    'INSERT INTO refund_requests (request_number, customer_id, order_id, reason_code, status, preferred_resolution)
     VALUES (?, ?, ?, ?, ?, ?)'
)->execute([$reqNum, $customerA, $orderId, 'quality', RefundStatus::REQUESTED, 'refund']);
$requestId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO refund_request_items (refund_request_id, order_item_id, quantity, amount_inr) VALUES (?, ?, 1, ?)'
)->execute([$requestId, (int) $fixture['order_item_id'], 80.00]);

$available = RefundRequestService::availableApprovalAmountForRequest($requestId);
$ok($available >= 80.0, 'Available approval balance includes request lines');

$partial = RefundRequestService::approveAndProcessGatewayRefund($requestId, 50.0, 'partial test');
$ok(!empty($partial['ok']), 'Partial approve+process succeeds (simulate)');
$st = $pdo->prepare('SELECT status, approved_amount_inr FROM refund_requests WHERE id = ?');
$st->execute([$requestId]);
$row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$ok((string) ($row['status'] ?? '') === RefundStatus::REFUNDED, 'Partial ends refunded via simulate completion');
$ok(abs((float) ($row['approved_amount_inr'] ?? 0) - 50.0) < 0.01, 'Partial approved amount saved');
$gw = RefundRequestService::latestPaymentRefundForRequest($requestId);
$ok($gw !== null && (float) ($gw['amount_inr'] ?? 0) === 50.0, 'Gateway row amount matches partial');

$dup = RefundRequestService::approveAndProcessGatewayRefund($requestId, 10.0, null);
$ok(empty($dup['ok']), 'Duplicate approve+process rejected');

$again = RefundRequestService::initiateGatewayRefund($requestId);
$ok(empty($again['ok']), 'Cannot re-initiate completed refund');

$reqNumFull = 'RF-APPR-FULL-' . $tok;
$pdo->prepare(
    'INSERT INTO refund_requests (request_number, customer_id, order_id, reason_code, status, preferred_resolution)
     VALUES (?, ?, ?, ?, ?, ?)'
)->execute([$reqNumFull, $customerA, $orderId, 'quality', RefundStatus::REQUESTED, 'refund']);
$requestIdFull = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO refund_request_items (refund_request_id, order_item_id, quantity, amount_inr) VALUES (?, ?, 1, ?)'
)->execute([$requestIdFull, (int) $fixture['order_item_id'], 150.00]);
$fullAmt = RefundRequestService::availableApprovalAmountForRequest($requestIdFull);
$full = RefundRequestService::approveAndProcessGatewayRefund($requestIdFull, $fullAmt, 'full');
$ok(!empty($full['ok']), 'Full approve+process succeeds');

$reqNumFail = 'RF-APPR-FAIL-' . $tok;
$pdo->prepare(
    'INSERT INTO refund_requests (request_number, customer_id, order_id, reason_code, status, preferred_resolution)
     VALUES (?, ?, ?, ?, ?, ?)'
)->execute([$reqNumFail, $customerA, $orderId, 'quality', RefundStatus::REQUESTED, 'refund']);
$requestIdFail = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO refund_request_items (refund_request_id, order_item_id, quantity, amount_inr) VALUES (?, ?, 1, ?)'
)->execute([$requestIdFail, (int) $fixture['order_item_id'], 25.00]);
$pdo->prepare("UPDATE payments SET gateway = 'unsupported_gw' WHERE order_id = ?")->execute([$orderId]);
$failRes = RefundRequestService::approveAndProcessGatewayRefund($requestIdFail, 25.0, 'gw fail');
$ok(empty($failRes['ok']) && !empty($failRes['approved_saved']), 'Gateway fail keeps approval');
$st->execute([$requestIdFail]);
$rowFail = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$ok((string) ($rowFail['status'] ?? '') === RefundStatus::REFUND_FAILED, 'Status refund_failed after gateway error');
$ok(abs((float) ($rowFail['approved_amount_inr'] ?? 0) - 25.0) < 0.01, 'Approved amount preserved on gateway failure');
$pdo->prepare("UPDATE payments SET gateway = 'simulate' WHERE order_id = ?")->execute([$orderId]);

$retry = RefundRequestService::retryGatewayRefund($requestIdFail);
$ok(!empty($retry['ok']), 'Manual retry after failure succeeds');
$st->execute([$requestIdFail]);
$rowRetry = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$ok((string) ($rowRetry['status'] ?? '') === RefundStatus::REFUNDED, 'Retry completes refund');

$over = RefundRequestService::approveAndProcessGatewayRefund($requestId, 99999.0, null);
$ok(empty($over['ok']) && empty($over['approved_saved'] ?? false), 'Excessive amount rejected without approval');

$reqNumPending = 'RF-APPR-PEND-' . $tok;
$pdo->prepare(
    'INSERT INTO refund_requests (request_number, customer_id, order_id, reason_code, status, preferred_resolution)
     VALUES (?, ?, ?, ?, ?, ?)'
)->execute([$reqNumPending, $customerA, $orderId, 'quality', RefundStatus::REQUESTED, 'refund']);
$requestIdPend = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO refund_request_items (refund_request_id, order_item_id, quantity, amount_inr) VALUES (?, ?, 1, ?)'
)->execute([$requestIdPend, (int) $fixture['order_item_id'], 30.00]);
$pdo->prepare("UPDATE payments SET gateway = 'cashfree', gateway_payment_id = 'cf_pay_test' WHERE order_id = ?")->execute([$orderId]);
$prevHandler = PaymentHttp::$testHandler;
PaymentHttp::$testHandler = static function (
    string $method,
    string $url,
    ?array $jsonBody,
    array $headers = [],
    ?string $basicUser = null,
    ?string $basicPass = null
): array {
    return [
        'ok' => true,
        'status' => 200,
        'body' => '{"cf_refund_id":"cf_ref_mock","refund_status":"PENDING"}',
        'json' => ['cf_refund_id' => 'cf_ref_mock', 'refund_status' => 'PENDING'],
    ];
};
AppSettings::set('cashfree.enabled', '1');
AppSettings::set('cashfree.app_id', 'cf_test_id_12345678');
AppSettings::set('cashfree.secret_key', 'cf_test_secret_key_value', true);
$pend = RefundRequestService::approveAndProcessGatewayRefund($requestIdPend, 30.0, 'pending gw');
PaymentHttp::$testHandler = $prevHandler;
$pdo->prepare("UPDATE payments SET gateway = 'simulate' WHERE order_id = ?")->execute([$orderId]);
$st->execute([$requestIdPend]);
$ok(!empty($pend['ok']), 'Cashfree pending response treated as success initiation');
$ok((string) ($st->fetchColumn() ?: '') === RefundStatus::PROCESSING, 'Pending gateway leaves request processing');
$block = RefundRequestService::initiateGatewayRefund($requestIdPend);
$ok(empty($block['ok']), 'In-progress gateway refund cannot be duplicated');

foreach ([$requestId, $requestIdFull, $requestIdFail, $requestIdPend] as $rid) {
    $pdo->prepare('DELETE FROM payment_refunds WHERE refund_request_id = ?')->execute([$rid]);
    $pdo->prepare('DELETE FROM refund_request_history WHERE refund_request_id = ?')->execute([$rid]);
    $pdo->prepare('DELETE FROM refund_request_items WHERE refund_request_id = ?')->execute([$rid]);
    $pdo->prepare('DELETE FROM refund_requests WHERE id = ?')->execute([$rid]);
}
stage12_phase_c1_teardown($pdo, $fixture, []);

$index = (string) file_get_contents(dirname(__DIR__) . '/admin/refunds/index.php');
$ok(str_contains($index, 'approve_process'), 'Admin detail uses approve_process action');
$ok(str_contains($index, 'Approve &amp; Process Refund'), 'Primary approve+process button');
$ok(str_contains($index, 'retry_gateway'), 'Retry action for failed gateway');

echo $fail === 0 ? "PASS: admin refund approve-process.\n" : "Failures: {$fail}\n";
exit($fail > 0 ? 1 : 0);
