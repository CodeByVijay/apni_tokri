<?php

declare(strict_types=1);

/**
 * Admin fetch gateway refund status (mocked HTTP; no live API).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

require_once __DIR__ . '/includes/stage12_phase_c1_fixture.php';

$fail = 0;
$debug = getenv('APNI_TOKRI_REFUND_TEST_DEBUG') === '1';
$ok = static function (bool $cond, string $msg, array $extra = []) use (&$fail, $debug): void {
    echo ($cond ? 'OK' : 'FAIL') . ': ' . $msg . "\n";
    if (!$cond) {
        $fail++;
        if ($debug && $extra !== []) {
            fwrite(STDERR, 'DEBUG ' . $msg . ': ' . json_encode($extra, JSON_UNESCAPED_UNICODE) . "\n");
        }
    }
};

Install::ensureConnection();
$pdo = Database::pdo();
$_SESSION['admin_user_id'] = 1;
$_SESSION['admin_demo_role'] = 'super_admin';
Permission::clearCache();

$fixture = stage12_phase_c1_create_fixture($pdo);
$customerA = (int) $fixture['customer_a'];
$tok = $fixture['token'];
$variantId = (int) ($pdo->query(
    'SELECT variant_id FROM order_items WHERE id = ' . (int) $fixture['order_item_id'] . ' LIMIT 1'
)->fetchColumn() ?: 0);

AppSettings::set('cashfree.enabled', '1');
AppSettings::set('cashfree.app_id', 'cf_test_id_12345678');
AppSettings::set('cashfree.secret_key', 'cf_test_secret_key_value', true);
AppSettings::set('payment.test_mode', '1');
AppSettings::resetCache();

$isoOrder = static function (PDO $pdo, int $customerId, string $token, string $suffix, int $variantId): array {
    $amount = 120.00;
    $orderNumber = 'AT-FETCH-' . $suffix . '-' . strtoupper($token);
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
        'Fetch status probe',
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
        'cashfree',
        'cf_pay_fetch_' . $suffix,
        $amount,
        'INR',
        'captured',
        'pay_fetch_' . $suffix . '_' . $token,
    ]);
    $paymentId = (int) $pdo->lastInsertId();

    return [
        'order_id' => $orderId,
        'order_item_id' => $orderItemId,
        'payment_id' => $paymentId,
        'order_number' => $orderNumber,
    ];
};

$insertPaymentRefund = static function (
    PDO $pdo,
    int $requestId,
    int $paymentId,
    string $gateway,
    float $amountInr,
    string $idempotencyKey,
    ?string $gatewayRefundId,
    string $gatewayStatus = 'pending'
): int {
    $pdo->prepare(
        'INSERT INTO payment_refunds
         (refund_request_id, payment_id, gateway, amount_inr, idempotency_key, gateway_refund_id, gateway_status, response_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $requestId,
        $paymentId,
        $gateway,
        $amountInr,
        $idempotencyKey,
        $gatewayRefundId,
        $gatewayStatus,
        '{}',
    ]);

    return (int) $pdo->lastInsertId();
};

$createReq = static function (PDO $pdo, array $iso, int $customerId, string $reqNum, float $amount): int {
    $pdo->prepare(
        'INSERT INTO refund_requests (request_number, customer_id, order_id, reason_code, status, preferred_resolution, approved_amount_inr)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $reqNum,
        $customerId,
        (int) $iso['order_id'],
        'quality',
        RefundStatus::PROCESSING,
        'refund',
        $amount,
    ]);
    $requestId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO refund_request_items (refund_request_id, order_item_id, quantity, amount_inr) VALUES (?, ?, 1, ?)'
    )->execute([$requestId, (int) $iso['order_item_id'], $amount]);

    return $requestId;
};

$cashfreeMerchantRefundId = static function (string $idempotencyKey): string {
    return substr(preg_replace('/[^a-zA-Z0-9_]/', '_', $idempotencyKey), 0, 40);
};

$httpLog = [];
$mockInvocations = 0;
$prevHandler = PaymentHttp::$testHandler;
$setMock = static function (callable $resolver) use (&$httpLog, &$mockInvocations): void {
    $mockInvocations = 0;
    PaymentHttp::$testHandler = static function (
        string $method,
        string $url,
        ?array $jsonBody,
        array $headers = [],
        ?string $basicUser = null,
        ?string $basicPass = null
    ) use (&$httpLog, &$mockInvocations, $resolver): array {
        $mockInvocations++;
        $httpLog[] = [
            'method' => $method,
            'url' => $url,
            'body' => $jsonBody,
        ];
        $resp = $resolver($method, $url, $jsonBody);
        $httpLog[count($httpLog) - 1]['response_status'] = $resp['status'] ?? null;
        $httpLog[count($httpLog) - 1]['response_ok'] = $resp['ok'] ?? null;

        return $resp;
    };
};

$assertNoPostRefundCreate = static function (array $httpLog) use ($ok): void {
    foreach ($httpLog as $call) {
        $method = strtoupper((string) ($call['method'] ?? ''));
        $url = (string) ($call['url'] ?? '');
        $isRefundCreate = $method === 'POST' && str_contains($url, '/refunds') && !str_ends_with($url, '/refunds');
        $ok(!$isRefundCreate, 'No POST refund-create call: ' . $method . ' ' . $url);
    }
};

// --- Pending: lookup via Cashfree cf_refund_id ---
$isoPend = $isoOrder($pdo, $customerA, $tok, 'PENDONLY', $variantId);
$reqPend = $createReq($pdo, $isoPend, $customerA, 'RF-FETCH-PEND-' . $tok, 35.0);
$idempotencyPend = 'refund_req_fetch_pend_' . $reqPend;
$cfRefundIdPend = 'cf_ref_pend_' . $tok;
$insertPaymentRefund(
    $pdo,
    $reqPend,
    (int) $isoPend['payment_id'],
    'cashfree',
    35.0,
    $idempotencyPend,
    $cfRefundIdPend,
    'pending'
);
$httpLog = [];
$setMock(static function (string $method, string $url) use ($cfRefundIdPend): array {
    if ($method !== 'GET' || !preg_match('#/refunds/' . preg_quote(rawurlencode($cfRefundIdPend), '#') . '$#', $url)) {
        return ['ok' => false, 'status' => 502, 'body' => '', 'json' => null];
    }

    return [
        'ok' => true,
        'status' => 200,
        'body' => '{"cf_refund_id":"' . $cfRefundIdPend . '","refund_status":"PENDING"}',
        'json' => ['cf_refund_id' => $cfRefundIdPend, 'refund_status' => 'PENDING'],
    ];
});
$pendFetch = RefundRequestService::fetchGatewayRefundStatus($reqPend);
$getCalls = array_values(array_filter($httpLog, static fn (array $c): bool => strtoupper($c['method']) === 'GET'));
$postCalls = array_values(array_filter($httpLog, static fn (array $c): bool => strtoupper($c['method']) === 'POST'));
$prPend = $pdo->prepare('SELECT gateway_status, gateway_refund_id FROM payment_refunds WHERE refund_request_id = ? ORDER BY id DESC LIMIT 1');
$prPend->execute([$reqPend]);
$prPendRow = $prPend->fetch(PDO::FETCH_ASSOC) ?: [];
$st = $pdo->prepare('SELECT status FROM refund_requests WHERE id = ?');
$st->execute([$reqPend]);
$reqPendStatus = (string) $st->fetchColumn();
$ok($mockInvocations === 1, 'Pending: mock handler invoked once', ['http' => $httpLog, 'result' => $pendFetch]);
$ok(count($getCalls) === 1, 'Pending: exactly one GET', ['http' => $httpLog]);
$ok($postCalls === [], 'Pending: no POST calls', ['http' => $httpLog]);
$ok(!empty($pendFetch['ok']), 'Pending: service returned success', ['result' => $pendFetch]);
$ok(strtolower((string) ($prPendRow['gateway_status'] ?? '')) === 'pending', 'Pending: payment_refunds gateway_status pending', ['row' => $prPendRow]);
$ok($reqPendStatus === RefundStatus::PROCESSING, 'Pending: refund request stays processing', ['status' => $reqPendStatus]);
$assertNoPostRefundCreate($httpLog);

// --- Success: merchant refund_id lookup when gateway_refund_id empty ---
$isoSucc = $isoOrder($pdo, $customerA, $tok, 'SUCCESS', $variantId);
$reqSucc = $createReq($pdo, $isoSucc, $customerA, 'RF-FETCH-SUCC-' . $tok, 40.0);
$idempotencySucc = 'refund_req_fetch_succ_' . $reqSucc;
$merchantLookupSucc = $cashfreeMerchantRefundId($idempotencySucc);
$payIdSucc = (int) $isoSucc['payment_id'];
$insertPaymentRefund($pdo, $reqSucc, $payIdSucc, 'cashfree', 40.0, $idempotencySucc, null, 'pending');
$httpLog = [];
$setMock(static function (string $method, string $url) use ($merchantLookupSucc, $isoSucc): array {
    $orderEnc = rawurlencode((string) $isoSucc['order_number']);
    $refEnc = rawurlencode($merchantLookupSucc);
    $expectedSuffix = '/orders/' . $orderEnc . '/refunds/' . $refEnc;
    if ($method === 'GET' && str_ends_with($url, $expectedSuffix)) {
        return [
            'ok' => true,
            'status' => 200,
            'body' => '{"cf_refund_id":"cf_ref_success_' . $merchantLookupSucc . '","refund_status":"SUCCESS","refund_amount":40}',
            'json' => [
                'cf_refund_id' => 'cf_ref_success_' . $merchantLookupSucc,
                'refund_status' => 'SUCCESS',
                'refund_amount' => 40,
            ],
        ];
    }

    return ['ok' => false, 'status' => 502, 'body' => '', 'json' => null];
});
$succFetch = RefundRequestService::fetchGatewayRefundStatus($reqSucc);
$st->execute([$reqSucc]);
$reqSuccStatus = (string) $st->fetchColumn();
$payStmt = $pdo->prepare('SELECT amount_refunded_inr FROM payments WHERE id = ?');
$payStmt->execute([$payIdSucc]);
$refundedOnce = (float) $payStmt->fetchColumn();
$prSucc = $pdo->prepare('SELECT gateway_status, gateway_refund_id, completed_at FROM payment_refunds WHERE refund_request_id = ? LIMIT 1');
$prSucc->execute([$reqSucc]);
$prSuccRow = $prSucc->fetch(PDO::FETCH_ASSOC) ?: [];
$ok($mockInvocations >= 1, 'Success: mock handler invoked', ['http' => $httpLog, 'result' => $succFetch]);
$ok(!empty($succFetch['ok']), 'Success: service returned success', ['result' => $succFetch]);
$ok($reqSuccStatus === RefundStatus::REFUNDED, 'Success: refund request refunded', ['status' => $reqSuccStatus]);
$ok(
    CashfreeRefundWebhook::classifyStatus('SUCCESS', '') === 'success',
    'Success: SUCCESS normalized to success class'
);
$ok(abs($refundedOnce - 40.0) < 0.02, 'Success: payment amount_refunded_inr updated once', ['refunded' => $refundedOnce]);
$ok((string) ($prSuccRow['gateway_refund_id'] ?? '') !== '', 'Success: gateway_refund_id back-filled from gateway');
$ok($prSuccRow['completed_at'] !== null && $prSuccRow['completed_at'] !== '', 'Success: payment_refunds completed_at set');

$httpLogBeforeRepeat = $httpLog;
$httpLog = [];
$setMock(static function (string $method, string $url) use ($merchantLookupSucc, $isoSucc): array {
    $orderEnc = rawurlencode((string) $isoSucc['order_number']);
    $refEnc = rawurlencode('cf_ref_success_' . $merchantLookupSucc);
    if ($method === 'GET' && str_contains($url, '/orders/' . $orderEnc . '/refunds/')) {
        return [
            'ok' => true,
            'status' => 200,
            'json' => [
                'cf_refund_id' => 'cf_ref_success_' . $merchantLookupSucc,
                'refund_status' => 'SUCCESS',
                'refund_amount' => 40,
            ],
            'body' => '{}',
        ];
    }

    return ['ok' => false, 'status' => 502, 'body' => '', 'json' => null];
});
$repeatFetch = RefundRequestService::fetchGatewayRefundStatus($reqSucc);
$payStmt->execute([$payIdSucc]);
$refundedTwice = (float) $payStmt->fetchColumn();
$st->execute([$reqSucc]);
$ok(!empty($repeatFetch['ok']), 'Success: repeat fetch succeeds (idempotent)');
$ok((string) $st->fetchColumn() === RefundStatus::REFUNDED, 'Success: repeat fetch keeps refunded');
$ok(abs($refundedTwice - $refundedOnce) < 0.001, 'Success: repeat fetch does not double payment refund', [
    'first' => $refundedOnce,
    'second' => $refundedTwice,
]);

// --- Razorpay: missing gateway_refund_id (idempotency present but not used for GET) ---
$isoMiss = $isoOrder($pdo, $customerA, $tok, 'MISSING', $variantId);
$pdo->prepare('UPDATE payments SET gateway = ? WHERE id = ?')->execute(['razorpay', (int) $isoMiss['payment_id']]);
$reqMiss = $createReq($pdo, $isoMiss, $customerA, 'RF-FETCH-MISS-' . $tok, 20.0);
$idempotencyMiss = 'refund_req_fetch_miss_' . $reqMiss;
$insertPaymentRefund($pdo, $reqMiss, (int) $isoMiss['payment_id'], 'razorpay', 20.0, $idempotencyMiss, null, 'pending');
$httpLog = [];
$missFetch = RefundRequestService::fetchGatewayRefundStatus($reqMiss);
$ok(empty($missFetch['ok']), 'Missing Razorpay gateway_refund_id rejected', ['result' => $missFetch]);
$ok(str_contains(strtolower((string) ($missFetch['message'] ?? '')), 'missing'), 'Missing ref message', ['result' => $missFetch]);
$ok($mockInvocations === 0, 'Missing ref: no HTTP calls', ['http' => $httpLog]);

// --- API error preserves status ---
$isoErr = $isoOrder($pdo, $customerA, $tok, 'APIERR', $variantId);
$reqErr = $createReq($pdo, $isoErr, $customerA, 'RF-FETCH-ERR-' . $tok, 22.0);
$cfErr = 'cf_ref_err_' . $tok;
$insertPaymentRefund(
    $pdo,
    $reqErr,
    (int) $isoErr['payment_id'],
    'cashfree',
    22.0,
    'refund_req_fetch_err_' . $reqErr,
    $cfErr,
    'pending'
);
$httpLog = [];
$setMock(static function (string $method, string $url): array {
    if ($method === 'GET' && str_contains($url, '/refunds/')) {
        return ['ok' => false, 'status' => 502, 'body' => '', 'json' => null];
    }

    return ['ok' => false, 'status' => 500, 'body' => '', 'json' => null];
});
$errFetch = RefundRequestService::fetchGatewayRefundStatus($reqErr);
$st->execute([$reqErr]);
$ok(empty($errFetch['ok']), 'API error returns failure to admin', ['result' => $errFetch]);
$ok((string) $st->fetchColumn() === RefundStatus::PROCESSING, 'API error preserves request status');

$hist = $pdo->prepare(
    'SELECT action FROM refund_request_history WHERE refund_request_id = ? AND action = ?'
);
$hist->execute([$reqPend, 'gateway_status_fetch']);
$ok($hist->fetch(PDO::FETCH_ASSOC) !== false, 'Audit history records gateway_status_fetch');

$uiProcessing = RefundRequestService::adminGatewayStatusFetchUi($reqPend, RefundStatus::PROCESSING);
$ok(!empty($uiProcessing['show_button']), 'UI: processing + persisted reference → fetch visible');

$pdo->prepare('UPDATE refund_requests SET status = ? WHERE id = ?')->execute([RefundStatus::REFUND_FAILED, $reqPend]);
$uiFailedWithRef = RefundRequestService::adminGatewayStatusFetchUi($reqPend, RefundStatus::REFUND_FAILED);
$ok(!empty($uiFailedWithRef['show_button']), 'UI: refund_failed + gateway reference → fetch visible');

$isoNoRow = $isoOrder($pdo, $customerA, $tok, 'NOROW', $variantId);
$reqNoRow = $createReq($pdo, $isoNoRow, $customerA, 'RF-FETCH-NOROW-' . $tok, 15.0);
$pdo->prepare('UPDATE refund_requests SET status = ? WHERE id = ?')->execute([RefundStatus::REFUND_FAILED, $reqNoRow]);
$uiNoRow = RefundRequestService::adminGatewayStatusFetchUi($reqNoRow, RefundStatus::REFUND_FAILED);
$ok(
    empty($uiNoRow['show_button']) && str_contains((string) ($uiNoRow['unavailable_message'] ?? ''), 'no gateway refund attempt'),
    'UI: refund_failed + no payment_refunds → fetch hidden with explanation'
);

$uiMissBtn = RefundRequestService::adminGatewayStatusFetchUi($reqMiss, RefundStatus::PROCESSING);
$ok(
    empty($uiMissBtn['show_button']) && str_contains((string) ($uiMissBtn['unavailable_message'] ?? ''), 'reference'),
    'UI: missing gateway refund reference → fetch hidden with explanation'
);

$index = (string) file_get_contents(dirname(__DIR__) . '/admin/refunds/index.php');
$ok(str_contains($index, 'fetch_gateway_status'), 'Admin detail exposes fetch_gateway_status action');
$ok(str_contains($index, 'Fetch Gateway Status'), 'Fetch Gateway Status button');
$ok(str_contains($index, 'adminGatewayStatusFetchUi'), 'Admin detail uses gateway fetch UI helper');
$ok(str_contains($index, 'Gateway actions'), 'Retry and fetch grouped in gateway actions');

PaymentHttp::$testHandler = $prevHandler;

foreach ([$isoPend, $isoSucc, $isoMiss, $isoErr, $isoNoRow] as $iso) {
    $oid = (int) $iso['order_id'];
    $pdo->prepare('DELETE FROM payment_refunds WHERE payment_id IN (SELECT id FROM payments WHERE order_id = ?)')->execute([$oid]);
    $pdo->prepare('DELETE FROM refund_request_history WHERE refund_request_id IN (SELECT id FROM refund_requests WHERE order_id = ?)')->execute([$oid]);
    $pdo->prepare('DELETE FROM refund_request_items WHERE order_item_id IN (SELECT id FROM order_items WHERE order_id = ?)')->execute([$oid]);
    $pdo->prepare('DELETE FROM refund_requests WHERE order_id = ?')->execute([$oid]);
    $pdo->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$oid]);
    $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$oid]);
    $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$oid]);
}

stage12_phase_c1_teardown($pdo, $fixture, []);

echo $fail === 0 ? "PASS: admin refund fetch gateway status.\n" : "Failures: {$fail}\n";
exit($fail > 0 ? 1 : 0);
