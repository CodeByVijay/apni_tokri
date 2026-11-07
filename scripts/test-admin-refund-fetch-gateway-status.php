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
$customerA = (int) $fixture['customer_a'];
$tok = $fixture['token'];
$variantId = (int) ($pdo->query(
    'SELECT variant_id FROM order_items WHERE id = ' . (int) $fixture['order_item_id'] . ' LIMIT 1'
)->fetchColumn() ?: 0);

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
        'pay_fetch_' . $suffix,
    ]);
    $paymentId = (int) $pdo->lastInsertId();

    return [
        'order_id' => $orderId,
        'order_item_id' => $orderItemId,
        'payment_id' => $paymentId,
        'order_number' => $orderNumber,
    ];
};

AppSettings::set('cashfree.enabled', '1');
AppSettings::set('cashfree.app_id', 'cf_test_id_12345678');
AppSettings::set('cashfree.secret_key', 'cf_test_secret_key_value', true);

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

$httpLog = [];
$prevHandler = PaymentHttp::$testHandler;
$setMock = static function (callable $resolver) use (&$httpLog): void {
    PaymentHttp::$testHandler = static function (
        string $method,
        string $url,
        ?array $jsonBody,
        array $headers = [],
        ?string $basicUser = null,
        ?string $basicPass = null
    ) use (&$httpLog, $resolver): array {
        $httpLog[] = ['method' => $method, 'url' => $url];

        return $resolver($method, $url);
    };
};

$isoPend = $isoOrder($pdo, $customerA, $tok, 'PENDONLY', $variantId);
$reqPend = $createReq($pdo, $isoPend, $customerA, 'RF-FETCH-PEND-' . $tok, 35.0);
$idempotencyPend = 'refund_req_' . $reqPend;
$pdo->prepare(
    'INSERT INTO payment_refunds
     (refund_request_id, payment_id, gateway, amount_inr, idempotency_key, gateway_refund_id, gateway_status, response_json)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    $reqPend,
    (int) $isoPend['payment_id'],
    'cashfree',
    35.0,
    $idempotencyPend,
    'cf_ref_pend',
    'pending',
    '{}',
]);
$httpLog = [];
$setMock(static function (string $method, string $url): array {
    if ($method === 'GET' && str_contains($url, 'cf_ref_pend')) {
        return [
            'ok' => true,
            'status' => 200,
            'body' => '{"cf_refund_id":"cf_ref_pend","refund_status":"PENDING"}',
            'json' => ['cf_refund_id' => 'cf_ref_pend', 'refund_status' => 'PENDING'],
        ];
    }

    return ['ok' => false, 'status' => 502, 'body' => '', 'json' => null];
});
$pendFetch = RefundRequestService::fetchGatewayRefundStatus($reqPend);
$getOnly = array_filter($httpLog, static fn (array $c): bool => $c['method'] === 'GET');
$ok(!empty($pendFetch['ok']), 'Pending fetch succeeds');
$ok(count($getOnly) === 1, 'Fetch uses GET only (no refund create)');
$st = $pdo->prepare('SELECT status FROM refund_requests WHERE id = ?');
$st->execute([$reqPend]);
$ok((string) $st->fetchColumn() === RefundStatus::PROCESSING, 'Pending gateway keeps request processing');

$isoSucc = $isoOrder($pdo, $customerA, $tok, 'SUCCESS', $variantId);
$reqSucc = $createReq($pdo, $isoSucc, $customerA, 'RF-FETCH-SUCC-' . $tok, 40.0);
$idempotencySucc = 'refund_req_' . $reqSucc;
$pdo->prepare(
    'INSERT INTO payment_refunds
     (refund_request_id, payment_id, gateway, amount_inr, idempotency_key, gateway_refund_id, gateway_status, response_json)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    $reqSucc,
    (int) $isoSucc['payment_id'],
    'cashfree',
    40.0,
    $idempotencySucc,
    null,
    'pending',
    '{}',
]);
$httpLog = [];
$merchantRefundKey = 'refund_req_' . $reqSucc;
$setMock(static function (string $method, string $url) use ($merchantRefundKey): array {
    if ($method === 'GET' && str_contains($url, '/refunds/' . rawurlencode($merchantRefundKey))) {
        return [
            'ok' => true,
            'status' => 200,
            'body' => '{"cf_refund_id":"cf_ref_success","refund_status":"SUCCESS","refund_amount":40}',
            'json' => [
                'cf_refund_id' => 'cf_ref_success',
                'refund_status' => 'SUCCESS',
                'refund_amount' => 40,
            ],
        ];
    }

    return ['ok' => false, 'status' => 502, 'body' => '', 'json' => null];
});
$succFetch = RefundRequestService::fetchGatewayRefundStatus($reqSucc);
$st->execute([$reqSucc]);
$ok(!empty($succFetch['ok']), 'Success fetch completes refund');
$ok((string) $st->fetchColumn() === RefundStatus::REFUNDED, 'SUCCESS gateway status → refunded');

$isoMiss = $isoOrder($pdo, $customerA, $tok, 'MISSING', $variantId);
$reqMiss = $createReq($pdo, $isoMiss, $customerA, 'RF-FETCH-MISS-' . $tok, 20.0);
$pdo->prepare(
    'INSERT INTO payment_refunds
     (refund_request_id, payment_id, gateway, amount_inr, idempotency_key, gateway_refund_id, gateway_status, response_json)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    $reqMiss,
    (int) $isoMiss['payment_id'],
    'razorpay',
    20.0,
    null,
    null,
    'pending',
    '{}',
]);
$missFetch = RefundRequestService::fetchGatewayRefundStatus($reqMiss);
$ok(empty($missFetch['ok']) && str_contains((string) ($missFetch['message'] ?? ''), 'missing'), 'Missing gateway ref shows reconciliation unavailable');

$isoErr = $isoOrder($pdo, $customerA, $tok, 'APIERR', $variantId);
$reqErr = $createReq($pdo, $isoErr, $customerA, 'RF-FETCH-ERR-' . $tok, 22.0);
$pdo->prepare(
    'INSERT INTO payment_refunds
     (refund_request_id, payment_id, gateway, amount_inr, idempotency_key, gateway_refund_id, gateway_status, response_json)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    $reqErr,
    (int) $isoErr['payment_id'],
    'cashfree',
    22.0,
    'refund_req_' . $reqErr,
    'cf_ref_err',
    'pending',
    '{}',
]);
$before = RefundStatus::PROCESSING;
$errFetch = RefundRequestService::fetchGatewayRefundStatus($reqErr);
$st->execute([$reqErr]);
$ok(empty($errFetch['ok']), 'API error returns failure to admin');
$ok((string) $st->fetchColumn() === $before, 'API error preserves request status');

$hist = $pdo->prepare(
    'SELECT action FROM refund_request_history WHERE refund_request_id = ? AND action = ?'
);
$hist->execute([$reqPend, 'gateway_status_fetch']);
$ok($hist->fetch(PDO::FETCH_ASSOC) !== false, 'Audit history records gateway_status_fetch');

$index = (string) file_get_contents(dirname(__DIR__) . '/admin/refunds/index.php');
$ok(str_contains($index, 'fetch_gateway_status'), 'Admin detail exposes fetch_gateway_status action');
$ok(str_contains($index, 'Fetch Gateway Status'), 'Fetch Gateway Status button');

PaymentHttp::$testHandler = $prevHandler;

foreach ([$isoPend, $isoSucc, $isoMiss, $isoErr] as $iso) {
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
