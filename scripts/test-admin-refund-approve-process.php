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

/**
 * Fresh captured order for gateway fail / pending scenarios (avoids draining the main fixture payment).
 *
 * @return array{order_id: int, order_item_id: int, payment_id: int, order_number: string}
 */
function refund_approve_process_isolated_order(PDO $pdo, int $customerId, string $token, string $suffix, int $variantId): array
{
    $amount = 150.00;
    $orderNumber = 'AT-APPR-' . $suffix . '-' . strtoupper($token);
    $pdo->prepare(
        'INSERT INTO orders
         (order_number, order_source, customer_id, status, subtotal, discount_total, delivery_fee, grand_total,
          currency, payment_status, placed_at)
         VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, ?, NOW())'
    )->execute([
        $orderNumber,
        'storefront',
        $customerId,
        'delivered',
        $amount,
        $amount,
        'INR',
        'paid',
    ]);
    $orderId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO order_items
         (order_id, variant_id, product_name, variant_name, quantity, unit_price, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $orderId,
        $variantId > 0 ? $variantId : null,
        'Refund approve-process probe',
        'unit',
        1,
        $amount,
        $amount,
    ]);
    $orderItemId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO payments
         (order_id, gateway, gateway_payment_id, amount, amount_refunded_inr, currency, status, idempotency_key)
         VALUES (?, ?, ?, ?, 0, ?, ?, ?)'
    )->execute([
        $orderId,
        'simulate',
        'gw_appr_' . $suffix . '_' . $token,
        $amount,
        'INR',
        'captured',
        'pay_appr_' . $suffix . '_' . $token,
    ]);
    $paymentId = (int) $pdo->lastInsertId();

    return [
        'order_id' => $orderId,
        'order_item_id' => $orderItemId,
        'payment_id' => $paymentId,
        'order_number' => $orderNumber,
    ];
}

$fail = 0;
$debugRefundTests = getenv('APNI_TOKRI_REFUND_TEST_DEBUG') === '1';
$ok = static function (bool $cond, string $msg, array $debug = []) use (&$fail, $debugRefundTests): void {
    echo ($cond ? 'OK' : 'FAIL') . ': ' . $msg . "\n";
    if (!$cond) {
        $fail++;
        if ($debugRefundTests && $debug !== []) {
            fwrite(STDERR, 'DEBUG: ' . $msg . ' → ' . json_encode($debug, JSON_UNESCAPED_UNICODE) . "\n");
        }
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

$variantId = (int) ($pdo->query(
    'SELECT variant_id FROM order_items WHERE id = ' . (int) $fixture['order_item_id'] . ' LIMIT 1'
)->fetchColumn() ?: 0);
$isoFail = refund_approve_process_isolated_order($pdo, $customerA, $tok, 'FAIL', $variantId);
$orderIdFailIso = (int) $isoFail['order_id'];
$reqNumFail = 'RF-APPR-FAIL-' . $tok;
$pdo->prepare(
    'INSERT INTO refund_requests (request_number, customer_id, order_id, reason_code, status, preferred_resolution)
     VALUES (?, ?, ?, ?, ?, ?)'
)->execute([$reqNumFail, $customerA, $orderIdFailIso, 'quality', RefundStatus::REQUESTED, 'refund']);
$requestIdFail = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO refund_request_items (refund_request_id, order_item_id, quantity, amount_inr) VALUES (?, ?, 1, ?)'
)->execute([$requestIdFail, (int) $isoFail['order_item_id'], 25.00]);
$pdo->prepare('UPDATE payments SET gateway = ? WHERE id = ?')->execute(['unsupported_gw', (int) $isoFail['payment_id']]);
$failRes = RefundRequestService::approveAndProcessGatewayRefund($requestIdFail, 25.0, 'gw fail');
$st->execute([$requestIdFail]);
$rowFailPre = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$histFail = $pdo->prepare('SELECT action, payload_json FROM refund_request_history WHERE refund_request_id = ? ORDER BY id');
$histFail->execute([$requestIdFail]);
$failHistory = $histFail->fetchAll(PDO::FETCH_ASSOC) ?: [];
$failPr = $pdo->prepare('SELECT * FROM payment_refunds WHERE refund_request_id = ?');
$failPr->execute([$requestIdFail]);
$ok(
    empty($failRes['ok']) && !empty($failRes['approved_saved']),
    'Gateway fail keeps approval',
    [
        'result' => $failRes,
        'db' => $rowFailPre,
        'remaining_captured' => RefundRequestService::capturedAmountForOrder($orderIdFailIso),
        'payment_gateway' => $pdo->query('SELECT gateway FROM payments WHERE id = ' . (int) $isoFail['payment_id'])->fetchColumn(),
        'payment_refunds' => $failPr->fetchAll(PDO::FETCH_ASSOC),
        'history' => $failHistory,
    ]
);
$st->execute([$requestIdFail]);
$rowFail = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$ok((string) ($rowFail['status'] ?? '') === RefundStatus::REFUND_FAILED, 'Status refund_failed after gateway error', ['row' => $rowFail]);
$ok(abs((float) ($rowFail['approved_amount_inr'] ?? 0) - 25.0) < 0.01, 'Approved amount preserved on gateway failure', ['row' => $rowFail]);
$pdo->prepare('UPDATE payments SET gateway = ? WHERE id = ?')->execute(['simulate', (int) $isoFail['payment_id']]);

$retry = RefundRequestService::retryGatewayRefund($requestIdFail);
$ok(!empty($retry['ok']), 'Manual retry after failure succeeds', ['retry' => $retry]);
$st->execute([$requestIdFail]);
$rowRetry = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$retryPr = RefundRequestService::latestPaymentRefundForRequest($requestIdFail);
$ok((string) ($rowRetry['status'] ?? '') === RefundStatus::REFUNDED, 'Retry completes refund', [
    'row' => $rowRetry,
    'payment_refund' => $retryPr,
]);

$isoPend = refund_approve_process_isolated_order($pdo, $customerA, $tok, 'PEND', $variantId);
$orderIdPendIso = (int) $isoPend['order_id'];
$reqNumPending = 'RF-APPR-PEND-' . $tok;
$pdo->prepare(
    'INSERT INTO refund_requests (request_number, customer_id, order_id, reason_code, status, preferred_resolution)
     VALUES (?, ?, ?, ?, ?, ?)'
)->execute([$reqNumPending, $customerA, $orderIdPendIso, 'quality', RefundStatus::REQUESTED, 'refund']);
$requestIdPend = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO refund_request_items (refund_request_id, order_item_id, quantity, amount_inr) VALUES (?, ?, 1, ?)'
)->execute([$requestIdPend, (int) $isoPend['order_item_id'], 30.00]);
$pdo->prepare('UPDATE payments SET gateway = ?, gateway_payment_id = ? WHERE id = ?')->execute([
    'cashfree',
    'cf_pay_test',
    (int) $isoPend['payment_id'],
]);
$cashfreeHttpCalls = 0;
$prevHandler = PaymentHttp::$testHandler;
PaymentHttp::$testHandler = static function (
    string $method,
    string $url,
    ?array $jsonBody,
    array $headers = [],
    ?string $basicUser = null,
    ?string $basicPass = null
) use (&$cashfreeHttpCalls): array {
    $cashfreeHttpCalls++;
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
$st->execute([$requestIdPend]);
$rowPend = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$gwPend = RefundRequestService::latestPaymentRefundForRequest($requestIdPend);
$ok(!empty($pend['ok']), 'Cashfree pending response treated as success initiation', [
    'result' => $pend,
    'row' => $rowPend,
    'cashfree_configured' => CashfreeGateway::isConfigured(),
    'payment_http_calls' => $cashfreeHttpCalls,
    'payment_refund' => $gwPend,
]);
$ok((string) ($rowPend['status'] ?? '') === RefundStatus::PROCESSING, 'Pending gateway leaves request processing', [
    'row' => $rowPend,
    'payment_refund' => $gwPend,
]);
$block = RefundRequestService::initiateGatewayRefund($requestIdPend);
$ok(empty($block['ok']), 'In-progress gateway refund cannot be duplicated');

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

$over = RefundRequestService::approveAndProcessGatewayRefund($requestId, 99999.0, null);
$ok(empty($over['ok']) && empty($over['approved_saved'] ?? false), 'Excessive amount rejected without approval');

foreach ([$orderIdFailIso, $orderIdPendIso] as $isoOid) {
    $pdo->prepare('DELETE FROM payment_refunds WHERE payment_id IN (SELECT id FROM payments WHERE order_id = ?)')->execute([$isoOid]);
    $pdo->prepare('DELETE FROM refund_request_items WHERE order_item_id IN (SELECT id FROM order_items WHERE order_id = ?)')->execute([$isoOid]);
    $pdo->prepare('DELETE FROM refund_requests WHERE order_id = ?')->execute([$isoOid]);
    $pdo->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$isoOid]);
    $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$isoOid]);
    $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$isoOid]);
}

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
