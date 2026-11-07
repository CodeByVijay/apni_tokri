<?php

declare(strict_types=1);

/**
 * Admin recovery: captured payment + expired slot → reassignment → single delivery.
 * Run: php scripts/run-in-disposable-db.php scripts/test-payment-slot-reconciliation-recovery.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/test_cli_runner.php';

$fail = 0;
$ok = static function (bool $c, string $m) use (&$fail): void {
    if ($c) {
        echo "PASS: $m\n";
    } else {
        echo "FAIL: $m\n";
        $fail++;
    }
};

if (!Install::isSchemaReady() || !PaymentCompletionExceptionService::tableReady() || !DeliverySlotReservationService::tableReady()) {
    fwrite(STDERR, "Schema / D2 tables not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();

$tag = substr(bin2hex(random_bytes(3)), 0, 6);
$slug = 'recv_' . $tag;
$pdo->prepare(
    'INSERT INTO delivery_slots (slug, label, window_label, delivery_day, start_time, end_time, cutoff_time, capacity, sort_order, is_active, weekday_mask)
     VALUES (?,?,?,?,?,?,?,?,?,?,127)'
)->execute([$slug, 'Recovery AM', '8–10 AM', 'same_day', '08:00:00', '10:00:00', '23:59:00', 2, 9990, 1]);
$slotId = (int) $pdo->lastInsertId();
$date = DeliveryConfig::now()->format('Y-m-d');

$addrId = (int) ($pdo->query('SELECT id FROM customer_addresses ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
if ($addrId <= 0) {
    $pdo->exec("INSERT INTO customers (mobile, name, is_active) VALUES ('9{$tag}00001', 'Recv Test', 1)");
    $cid = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO customer_addresses (customer_id, pincode, line1, city, is_default) VALUES (?,?,?,?,1)')
        ->execute([$cid, '452001', 'Test line', 'Indore']);
    $addrId = (int) $pdo->lastInsertId();
}

$orderNumber = 'RECV-' . strtoupper($tag);
$pdo->prepare(
    "INSERT INTO orders (order_number, status, subtotal, discount_total, delivery_fee, grand_total, payment_status,
     delivery_slot, delivery_slot_id, delivery_date, customer_address_id, placed_at)
     VALUES (?, 'pending_payment', 245, 0, 0, 245, 'pending', ?, ?, ?, ?, NOW())"
)->execute([$orderNumber, $slug, $slotId, $date, $addrId]);
$orderId = (int) $pdo->lastInsertId();

$pdo->prepare(
    'INSERT INTO order_items (order_id, product_name, quantity, unit_price, line_total) VALUES (?, ?, 1, 245, 245)'
)->execute([$orderId, 'Test veg box']);

$pdo->prepare(
    'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key) VALUES (?, ?, ?, ?, ?, ?)'
)->execute([$orderId, 'simulate', 245, 'INR', 'created', 'pay_' . $orderNumber . '_1']);

DeliverySlotReservationService::createHold($pdo, $slotId, $date, $orderId, 30);
$pdo->exec("UPDATE delivery_slot_reservations SET expires_at = NOW() - INTERVAL 5 MINUTE WHERE order_id = {$orderId}");

$failPay = PaymentService::completePayment(
    $orderNumber,
    'simulate',
    'gw_recv_' . $tag,
    'ord_recv_' . $tag,
    245.0,
    'INR',
    'pay_' . $orderNumber . '_1',
    'evt_recv_' . $tag
);
$ok(!empty($failPay['reconciliation']) && empty($failPay['ok']), 'captured payment with expired slot → reconciliation');

$paySt = (string) $pdo->query("SELECT status FROM payments WHERE order_id = {$orderId}")->fetchColumn();
$ok($paySt === 'reconciliation_required', 'payment stays reconciliation_required (not pending)');

$ex = PaymentCompletionExceptionService::openForOrderId($orderId);
$ok($ex !== null, 'open completion exception');
$exceptionId = $ex !== null ? (int) $ex['id'] : 0;

$opts = PaymentFulfilmentReconciliationService::listReplacementSlotOptions($orderId);
$ok($opts !== [], 'replacement slots listed');

$adminId = (int) ($pdo->query('SELECT id FROM admin_users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 1);

$recover = PaymentFulfilmentReconciliationService::adminReassignSlotAndCompleteFulfilment(
    $orderId,
    $exceptionId,
    $slotId,
    $date,
    $adminId,
    'Automated test: slot reassigned after expired hold.'
);
$ok(!empty($recover['ok']), 'admin recovery completes fulfilment: ' . ($recover['message'] ?? ''));

$paid = (string) $pdo->query("SELECT payment_status FROM orders WHERE id = {$orderId}")->fetchColumn();
$ok($paid === 'paid', 'order payment_status paid after recovery');

$paySt2 = (string) $pdo->query("SELECT status FROM payments WHERE order_id = {$orderId}")->fetchColumn();
$ok($paySt2 === 'captured', 'payment row captured');

$delCount = (int) $pdo->query("SELECT COUNT(*) FROM deliveries WHERE order_id = {$orderId}")->fetchColumn();
$ok($delCount === 1, 'exactly one delivery record');

$dup = PaymentFulfilmentReconciliationService::adminReassignSlotAndCompleteFulfilment(
    $orderId,
    $exceptionId,
    $slotId,
    $date,
    $adminId,
    'Duplicate recovery attempt should be idempotent.'
);
$ok(empty($dup['ok']) || !empty($dup['idempotent']) || str_contains((string) ($dup['message'] ?? ''), 'already'), 'duplicate admin recovery blocked or idempotent');

$dupPay = PaymentService::completePayment(
    $orderNumber,
    'simulate',
    'gw_recv_' . $tag,
    'ord_recv_' . $tag,
    245.0,
    'INR',
    'pay_' . $orderNumber . '_1',
    'evt_dup_' . $tag
);
$ok(!empty($dupPay['ok']) && !empty($dupPay['idempotent']), 'duplicate completePayment idempotent');

// Full slot branch
$slug2 = 'recv2_' . $tag;
$pdo->prepare(
    'INSERT INTO delivery_slots (slug, label, window_label, delivery_day, start_time, end_time, cutoff_time, capacity, sort_order, is_active, weekday_mask)
     VALUES (?,?,?,?,?,?,?,?,?,?,127)'
)->execute([$slug2, 'Full', 'Full', 'same_day', '11:00:00', '12:00:00', '23:59:00', 1, 9991, 1]);
$slotFull = (int) $pdo->lastInsertId();
$pdo->prepare(
    "INSERT INTO orders (order_number, status, subtotal, discount_total, delivery_fee, grand_total, payment_status,
     delivery_slot_id, delivery_date, placed_at)
     VALUES ('BLOCK-{$tag}', 'confirmed', 10, 0, 0, 10, 'paid', ?, ?, NOW())"
)->execute([$slotFull, $date]);

$orderNumber2 = 'RECV2-' . strtoupper($tag);
$pdo->prepare(
    "INSERT INTO orders (order_number, status, subtotal, discount_total, delivery_fee, grand_total, payment_status,
     delivery_slot, delivery_slot_id, delivery_date, customer_address_id, placed_at)
     VALUES (?, 'pending_payment', 50, 0, 0, 50, 'pending', ?, ?, ?, ?, NOW())"
)->execute([$orderNumber2, $slug2, $slotFull, $date, $addrId]);
$orderId2 = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key) VALUES (?,?,?,?,?,?)')
    ->execute([$orderId2, 'simulate', 50, 'INR', 'created', 'pay_' . $orderNumber2 . '_1']);
PaymentCompletionExceptionService::recordSlotFailure(
    $orderNumber2,
    'pay_' . $orderNumber2 . '_1',
    'simulate',
    'gw_full_' . $tag,
    'ord_full_' . $tag,
    'evt_f',
    50.0,
    'Delivery slot reservation expired. Choose another slot.'
);
$ex2 = PaymentCompletionExceptionService::openForOrderId($orderId2);
$bad = PaymentFulfilmentReconciliationService::adminReassignSlotAndCompleteFulfilment(
    $orderId2,
    $ex2 ? (int) $ex2['id'] : 0,
    $slotFull,
    $date,
    $adminId,
    'Should fail because slot capacity is full.'
);
$ok(empty($bad['ok']), 'recovery rejects full replacement slot');

$pdo->exec("DELETE FROM deliveries WHERE order_id IN ({$orderId}, {$orderId2})");
$pdo->exec("DELETE FROM payment_completion_exceptions WHERE order_id IN ({$orderId}, {$orderId2})");
$pdo->exec("DELETE FROM payments WHERE order_id IN ({$orderId}, {$orderId2})");
$pdo->exec("DELETE FROM order_items WHERE order_id IN ({$orderId}, {$orderId2})");
$pdo->exec("DELETE FROM delivery_slot_reservations WHERE order_id IN ({$orderId}, {$orderId2})");
$pdo->exec("DELETE FROM orders WHERE id IN ({$orderId}, {$orderId2}) OR order_number = 'BLOCK-{$tag}'");
$pdo->exec("DELETE FROM delivery_slots WHERE id IN ({$slotId}, {$slotFull})");

exit($fail > 0 ? 1 : 0);
