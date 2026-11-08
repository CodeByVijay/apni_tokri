<?php

declare(strict_types=1);

/**
 * Unpaid order payment expiry + auto-cancel (migration 1.33.0).
 * CLI: php scripts/test-unpaid-order-expiry.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/lib/test_script_safety.php';

TestScriptSafety::assertSafeToMutateSettings();

$fail = 0;
function okx(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

// --- Static wiring ---
$root = APNI_TOKRI_ROOT;
okx(is_readable($root . '/cron/expire_unpaid_orders.php'), 'cron script exists');
okx(is_readable($root . '/includes/commerce/unpaid_order_expiry.php'), 'service exists');
$cron = (string) file_get_contents($root . '/cron/expire_unpaid_orders.php');
okx(str_contains($cron, 'UnpaidOrderExpiryService::runCronBatch'), 'cron invokes batch');
okx(str_contains($cron, 'acquireCronLock'), 'cron uses lock');
$boot = (string) file_get_contents($root . '/includes/bootstrap.php');
okx(str_contains($boot, 'unpaid_order_expiry.php'), 'bootstrap loads service');
$chk = (string) file_get_contents($root . '/includes/commerce/checkout.php');
okx(str_contains($chk, 'payment_expires_at'), 'checkout stamps expiry');
$ps = (string) file_get_contents($root . '/includes/payment/service.php');
okx(str_contains($ps, 'UnpaidOrderExpiryService::isPaymentWindowOpen'), 'retry respects payment window');
$od = (string) file_get_contents($root . '/order-details.php');
okx(str_contains($od, 'order-payment-window.php'), 'order details shows payment window');
okx(method_exists(OrderCancellationService::class, 'cancelForPaymentTimeout'), 'cancelForPaymentTimeout exists');
okx(method_exists(NotificationDispatcher::class, 'orderPaymentTimeoutCancelled'), 'timeout notification exists');
$expirySrc = (string) file_get_contents($root . '/includes/commerce/unpaid_order_expiry.php');
okx(
    !preg_match('/\$thisPrecheckEligible\s*\(/', $expirySrc),
    'processOrder must call self::thisPrecheckEligible (not undefined $thisPrecheckEligible variable)'
);
okx(str_contains($expirySrc, 'self::thisPrecheckEligible($preOrder)'), 'pre-reconcile gate uses self::thisPrecheckEligible');

if (!Install::isSchemaReady()) {
    echo "SKIP: Schema not ready (DB tests).\n";
    exit($fail > 0 ? 1 : 0);
}

Install::ensureConnection();
$pdo = Database::pdo();
$mig = dirname(__DIR__) . '/database/migrations/1.33.0_unpaid_order_payment_expiry.sql';
if (!UnpaidOrderExpiryService::columnReady() && is_readable($mig)) {
  try {
      $pdo->exec((string) file_get_contents($mig));
  } catch (Throwable $e) {
      echo 'WARN: migration apply: ' . $e->getMessage() . "\n";
  }
}
if (!UnpaidOrderExpiryService::columnReady()) {
    echo "SKIP: payment_expires_at column missing.\n";
    exit($fail > 0 ? 1 : 0);
}

okx(UnpaidOrderExpiryService::windowMinutes() >= 5, 'window minutes sane');

$customerId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
$variantId = (int) ($pdo->query(
    'SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id WHERE v.is_active = 1 LIMIT 1'
)->fetchColumn() ?: 0);
okx($customerId > 0 && $variantId > 0, 'fixtures customer + variant');

$mk = static function (PDO $pdo, int $cid, int $vid, string $suffix, string $expAt, string $pay = 'pending', string $status = 'pending_payment'): int {
    $num = 'AT-EXP-' . $suffix . '-' . strtoupper(substr(Security::randomToken(3), 0, 5));
    $pdo->prepare(
        'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, placed_at, payment_expires_at, order_source)
         VALUES (?, ?, ?, 100, 0, 0, 100, ?, DATE_SUB(NOW(), INTERVAL 20 MINUTE), ?, ?)'
    )->execute([$num, $cid, $status, $pay, $expAt, 'storefront']);
    $oid = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, quantity, unit_price, line_total)
         VALUES (?, ?, ?, ?, 1, 100, 100)'
    )->execute([$oid, $vid, 'Test', 'Var']);

    return $oid;
};

$pastExp = date('Y-m-d H:i:s', time() - 3600);
$futureExp = date('Y-m-d H:i:s', time() + 900);

$expiredId = $mk($pdo, $customerId, $variantId, 'OLD', $pastExp);
$youngId = $mk($pdo, $customerId, $variantId, 'NEW', $futureExp);

$outOld = UnpaidOrderExpiryService::processOrder($expiredId);
okx(($outOld['action'] ?? '') === 'cancelled', 'expired pending order cancelled');
okx(
    (string) $pdo->query('SELECT status FROM orders WHERE id = ' . $expiredId)->fetchColumn() === OrderStatus::CANCELLED,
    'expired order status cancelled'
);
$evt = OrderCancellationService::cancellationEventForOrder($expiredId);
okx($evt !== null && ($evt['reason_code'] ?? '') === OrderCancellationService::REASON_PAYMENT_TIMEOUT, 'payment_timeout reason');
okx(($evt['requested_by'] ?? '') === 'system', 'system cancellation source');

$outYoung = UnpaidOrderExpiryService::processOrder($youngId);
okx(($outYoung['action'] ?? '') === 'skip', 'young order skipped');
okx(
    (string) $pdo->query('SELECT status FROM orders WHERE id = ' . $youngId)->fetchColumn() === OrderStatus::PENDING_PAYMENT,
    'young order still pending'
);

$failedId = $mk($pdo, $customerId, $variantId, 'FAIL', $futureExp, 'failed');
$rowF = $pdo->query('SELECT * FROM orders WHERE id = ' . $failedId)->fetch(PDO::FETCH_ASSOC);
okx(PaymentService::customerCanRetryStorefrontPayment($rowF), 'failed payment within window can retry');

$staleRetry = $mk($pdo, $customerId, $variantId, 'RST', $pastExp, 'failed');
$rowStale = $pdo->query('SELECT * FROM orders WHERE id = ' . $staleRetry)->fetch(PDO::FETCH_ASSOC);
$exp1 = (string) $rowStale['payment_expires_at'];
okx(!PaymentService::customerCanRetryStorefrontPayment($rowStale), 'retry blocked after expiry');
$pdo->prepare('UPDATE orders SET payment_expires_at = ? WHERE id = ?')->execute([$exp1, $staleRetry]);
okx(
    (string) $pdo->query('SELECT payment_expires_at FROM orders WHERE id = ' . $staleRetry)->fetchColumn() === $exp1,
    'expiry not extended by failed state alone'
);

$paidSkip = $mk($pdo, $customerId, $variantId, 'PAID', $pastExp, 'paid', OrderStatus::CONFIRMED);
$outPaid = UnpaidOrderExpiryService::processOrder($paidSkip);
okx(in_array((string) ($outPaid['action'] ?? ''), ['skip', 'defer'], true), 'paid order skipped');

$cancelledId = $mk($pdo, $customerId, $variantId, 'CAN', $pastExp);
$pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([OrderStatus::CANCELLED, $cancelledId]);
$outCan = UnpaidOrderExpiryService::processOrder($cancelledId);
okx(($outCan['action'] ?? '') === 'skip', 'already cancelled skipped');

$codId = $mk($pdo, $customerId, $variantId, 'COD', $pastExp);
$pdo->prepare(
    "INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key) VALUES (?, 'cod', 100, 'INR', 'pending', ?)"
)->execute([$codId, 'cod-' . $codId]);
$batchIds = UnpaidOrderExpiryService::cronEligibleOrder($pdo, 200);
okx(!in_array($codId, $batchIds, true), 'COD order excluded from cron selection');

$capturedId = $mk($pdo, $customerId, $variantId, 'CAP', $pastExp);
$pdo->prepare(
    "INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key, captured_at) VALUES (?, 'razorpay', 100, 'INR', 'captured', ?, NOW())"
)->execute([$capturedId, 'cap-' . $capturedId]);
$outCap = UnpaidOrderExpiryService::processOrder($capturedId);
okx(($outCap['action'] ?? '') === 'skip' || ($outCap['action'] ?? '') === 'paid', 'captured payment prevents cancel');

$deferId = $mk($pdo, $customerId, $variantId, 'DEF', $pastExp);
$pdo->prepare(
    "INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key, updated_at) VALUES (?, 'cashfree', 100, 'INR', 'pending', ?, NOW())"
)->execute([$deferId, 'def-' . $deferId]);
$outDef = UnpaidOrderExpiryService::processOrder($deferId);
okx(($outDef['action'] ?? '') === 'defer', 'in-flight payment defers cancel');

$cfMissId = $mk($pdo, $customerId, $variantId, 'CFNF', $pastExp, 'failed');
$pdo->prepare(
    "INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key) VALUES (?, 'cashfree', 100, 'INR', 'failed', ?)"
)->execute([$cfMissId, 'cfnf-' . $cfMissId]);
$prevHandler = PaymentHttp::$testHandler;
PaymentHttp::$testHandler = static function (string $method, string $url): array {
    if ($method === 'GET' && str_contains($url, '/orders/') && !str_contains($url, '/payments')) {
        return ['ok' => false, 'status' => 404, 'body' => '{"message":"not found"}', 'json' => ['message' => 'not found']];
    }

    return ['ok' => false, 'status' => 404, 'body' => '', 'json' => null];
};
try {
    $outCfMiss = UnpaidOrderExpiryService::processOrder($cfMissId);
    okx(($outCfMiss['action'] ?? '') === 'defer', 'expired cashfree 404 reaches reconcile defer without ErrorException');
} finally {
    PaymentHttp::$testHandler = $prevHandler;
}

$idempotentId = $mk($pdo, $customerId, $variantId, 'IDM', $pastExp);
UnpaidOrderExpiryService::processOrder($idempotentId);
$nQ = NotificationQueueService::tableReady()
    ? (int) $pdo->query(
        "SELECT COUNT(*) FROM notification_queue WHERE idempotency_key = 'order.payment_timeout_cancelled:order:" . $idempotentId . ":email'"
    )->fetchColumn()
    : 0;
UnpaidOrderExpiryService::processOrder($idempotentId);
$nQ2 = NotificationQueueService::tableReady()
    ? (int) $pdo->query(
        "SELECT COUNT(*) FROM notification_queue WHERE idempotency_key = 'order.payment_timeout_cancelled:order:" . $idempotentId . ":email'"
    )->fetchColumn()
    : 0;
okx(!$nQ2 || $nQ2 === $nQ, 'repeat cron does not duplicate timeout notification');

$slotId = (int) ($pdo->query('SELECT id FROM delivery_slots WHERE is_active = 1 LIMIT 1')->fetchColumn() ?: 0);
if ($slotId > 0 && DeliverySlotReservationService::tableReady()) {
    $holdId = $mk($pdo, $customerId, $variantId, 'SL', $pastExp);
    $date = date('Y-m-d', strtotime('+2 days'));
    DeliverySlotReservationService::createHold($pdo, $slotId, $date, $holdId, 30);
    $before = (int) $pdo->query(
        'SELECT COUNT(*) FROM delivery_slot_reservations WHERE order_id = ' . $holdId . " AND status = 'held'"
    )->fetchColumn();
    UnpaidOrderExpiryService::processOrder($holdId);
    $after = (int) $pdo->query(
        'SELECT COUNT(*) FROM delivery_slot_reservations WHERE order_id = ' . $holdId . " AND status = 'held'"
    )->fetchColumn();
    okx($before >= 1, 'slot hold created');
    okx($after === 0, 'slot hold released on timeout cancel');
}

$manualId = $mk($pdo, $customerId, $variantId, 'MAN', $futureExp);
$manualNum = (string) $pdo->query('SELECT order_number FROM orders WHERE id = ' . $manualId)->fetchColumn();
$rMan = OrderCancellationService::cancelByCustomer($customerId, $manualNum, 'ordered_by_mistake', '', 'idem-man-' . $manualId);
okx(!empty($rMan['ok']), 'manual customer cancel still works');

echo $fail === 0 ? "All unpaid expiry checks passed.\n" : "Failures: $fail\n";
exit($fail > 0 ? 1 : 0);
