<?php

declare(strict_types=1);

/**
 * Payment-session ↔ delivery hold lifecycle (Cashfree + Razorpay policies).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

$fail = 0;
$ok = static function (bool $cond, string $msg) use (&$fail): void {
    echo ($cond ? 'OK' : 'FAIL') . ': ' . $msg . "\n";
    if (!$cond) {
        $fail++;
    }
};

Install::ensureConnection();
$pdo = Database::pdo();

$ok(class_exists('PaymentSlotHoldService'), 'PaymentSlotHoldService loaded');
$ok(
    PaymentSlotHoldService::CASHFREE_SESSION_MINUTES > DeliverySlotReservationService::DEFAULT_HOLD_MINUTES,
    'Cashfree session window exceeds checkout hold'
);
$ok(
    PaymentSlotHoldService::ABSOLUTE_MAX_HOLD_MINUTES >= PaymentSlotHoldService::CASHFREE_SESSION_MINUTES,
    'Absolute hold cap covers cashfree session window'
);

if (!DeliverySlotReservationService::tableReady()) {
    fwrite(STDERR, "delivery_slot_reservations missing.\n");
    exit(1);
}

$slug = 'psh_' . bin2hex(random_bytes(3));
$pdo->prepare(
    'INSERT INTO delivery_slots (slug, label, window_label, delivery_day, start_time, end_time, cutoff_time, capacity, sort_order, is_active, weekday_mask)
     VALUES (?,?,?,?,?,?,?,?,?,?,127)'
)->execute([$slug, 'PSH', 'PSH', 'same_day', '23:00:00', '23:30:00', '23:00:00', 5, 9990, 1]);
$slotId = (int) $pdo->lastInsertId();
$date = DeliveryConfig::now()->format('Y-m-d');
$orderNumber = 'PSH-ORD-' . strtoupper(bin2hex(random_bytes(3)));

$pdo->prepare(
    "INSERT INTO orders (order_number, status, subtotal, discount_total, delivery_fee, grand_total, payment_status,
                         delivery_slot, delivery_slot_id, delivery_date, customer_address_id, placed_at)
     VALUES (?, 'pending_payment', 100, 0, 0, 100, 'pending', ?, ?, ?, 1, NOW())"
)->execute([$orderNumber, $slug, $slotId, $date]);
$orderId = (int) $pdo->lastInsertId();

Database::transaction(static function () use ($pdo, $slotId, $date, $orderId): void {
    DeliverySlotReservationService::createHold($pdo, $slotId, $date, $orderId, 30);
});
$expBefore = $pdo->query('SELECT expires_at FROM delivery_slot_reservations WHERE order_id = ' . $orderId)->fetchColumn();
$ok($expBefore !== false, 'Checkout hold created');

$pdo->prepare(
    'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key) VALUES (?, ?, ?, ?, ?, ?)'
)->execute([$orderId, 'cashfree', 100, 'INR', 'pending', 'pay_' . $orderNumber . '_1']);
$paymentId = (int) $pdo->lastInsertId();

$target = DeliveryConfig::now()->modify('+' . PaymentSlotHoldService::CASHFREE_SESSION_MINUTES . ' minutes');
PaymentSlotHoldService::afterPaymentSessionStarted($pdo, $orderId, 'cashfree', $target);
$expAfter = new DateTimeImmutable((string) $pdo->query('SELECT expires_at FROM delivery_slot_reservations WHERE order_id = ' . $orderId)->fetchColumn());
$ok($expAfter > new DateTimeImmutable((string) $expBefore), 'Cashfree session extends hold beyond checkout TTL');

$pdo->exec(
    'UPDATE delivery_slot_reservations SET expires_at = NOW() - INTERVAL 5 MINUTE WHERE order_id = ' . $orderId
);
$ok(
    PaymentSlotHoldService::synchronizeOpenPaymentHold($pdo, $orderId),
    'Open payment re-sync extends expired hold'
);
$hold = DeliverySlotReservationService::activeHoldForOrder($pdo, $orderId);
$ok($hold !== null, 'Hold active after open-payment sync');

$pdo->exec("UPDATE payments SET status = 'failed' WHERE id = " . $paymentId);
$pdo->exec(
    'UPDATE delivery_slot_reservations SET expires_at = NOW() - INTERVAL 1 MINUTE, status = \'held\' WHERE order_id = ' . $orderId
);
$n = DeliverySlotReservationService::releaseExpiredHolds($pdo);
$ok($n >= 1, 'Failed payment allows expiry cleanup');
$holdAfterFail = DeliverySlotReservationService::activeHoldForOrder($pdo, $orderId);
$ok($holdAfterFail === null, 'Hold released after payment failure + expiry');

// Reentrancy: released hold + open Cashfree attempt must not recurse refresh → lock → refresh (HTTP 500 OOM).
$pdo->prepare(
    'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key) VALUES (?, ?, ?, ?, ?, ?)'
)->execute([$orderId, 'cashfree', 100, 'INR', 'pending', 'pay_' . $orderNumber . '_2']);
$pdo->exec(
    "INSERT INTO delivery_slot_reservations (delivery_slot_id, delivery_date, order_id, status, expires_at, created_at, updated_at)
     VALUES ($slotId, '$date', $orderId, 'released', NOW() - INTERVAL 10 MINUTE, NOW(), NOW())
     ON DUPLICATE KEY UPDATE status = 'released', expires_at = NOW() - INTERVAL 10 MINUTE"
);
$reentryOk = true;
try {
    PaymentSlotHoldService::synchronizeOpenPaymentHold($pdo, $orderId);
    DeliverySlotReservationService::lockSlotAndAssertCapacity($pdo, $slotId, $date, $orderId);
} catch (Throwable $e) {
    $reentryOk = false;
}
$ok($reentryOk, 'released hold + open payment refresh does not recurse fatally');

$holdSrc = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/delivery/payment_slot_hold.php');
$ok(str_contains($holdSrc, 'refreshHoldsInProgress'), 'refreshHolds reentrancy guard present');

$pdo->prepare('DELETE FROM delivery_slot_reservations WHERE order_id = ?')->execute([$orderId]);
$pdo->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$orderId]);
$pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
$pdo->prepare('DELETE FROM delivery_slots WHERE id = ?')->execute([$slotId]);

$service = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/payment/service.php');
$cashfree = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/payment/cashfree.php');
$razorpay = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/payment/razorpay.php');
$ok(str_contains($service, 'PaymentSlotHoldService::afterPaymentSessionStarted'), 'initiate extends hold (cashfree)');
$ok(str_contains($service, 'afterPaymentSessionResumed'), 'Cashfree resume extends hold');
$ok(str_contains($cashfree, 'order_expiry_time'), 'Cashfree PG order expiry sent');
$ok(str_contains($razorpay, 'expire_at'), 'Razorpay order expiry sent');

echo $fail === 0 ? "PASS: payment slot hold lifecycle.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
