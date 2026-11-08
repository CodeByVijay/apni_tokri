<?php

declare(strict_types=1);

/**
 * Admin custom delivery window during payment fulfilment reconciliation (mocked PG via simulate).
 * CLI: php scripts/run-in-disposable-db.php scripts/test-admin-custom-delivery-override.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/test_cli_runner.php';

$fail = 0;
$ok = static function (bool $c, string $m) use (&$fail): void {
    echo ($c ? 'PASS: ' : 'FAIL: ') . $m . "\n";
    if (!$c) {
        $fail++;
    }
};

$recon = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/payment/fulfilment_reconciliation.php');
$view = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/orders/view.php');
$quote = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/delivery/quote.php');
$ok(str_contains($recon, 'adminCustomDeliveryAndCompleteFulfilment'), 'static: custom fulfilment service method');
$ok(str_contains($view, 'recover_payment_fulfilment_custom'), 'static: admin custom POST action');
$ok(str_contains($view, 'custom_customer_confirmed'), 'static: customer confirmation checkbox');
$ok(str_contains($view, 'Custom delivery time (Admin override)'), 'static: admin UI section');
$ok(!str_contains($recon, 'createOrder'), 'static: no Cashfree/Razorpay create in reconciliation service');
$ok(str_contains($quote, 'admin_delivery_override'), 'static: payment complete respects admin override snapshot');
$ok(str_contains($recon, "order.custom_delivery_fulfilment"), 'static: audit event for custom delivery');
$ok(str_contains($recon, 'capacity_override') && str_contains($recon, 'deliveries.manage'), 'static: capacity override gated on deliveries.manage');
$ok(str_contains($recon, 'capacityOverrideUsed && !$allowCapacityOverride'), 'static: rejects override without permission');
$ok(str_contains($recon, 'Payment is still captured but fulfilment failed again'), 'static: failed fulfilment keeps reconciliation messaging');
$checkout = (string) file_get_contents(APNI_TOKRI_ROOT . '/checkout.php');
$ok(!str_contains($checkout, 'recover_payment_fulfilment_custom'), 'static: checkout UI unchanged');
$ok(!str_contains($checkout, 'Custom delivery time (Admin override)'), 'static: no admin override on storefront checkout');
$refund = (string) @file_get_contents(APNI_TOKRI_ROOT . '/includes/trust/refund.php');
if ($refund !== '') {
    $ok(!str_contains($refund, 'adminCustomDeliveryAndCompleteFulfilment'), 'static: refund module not coupled to custom recovery');
}

if (!Install::isSchemaReady() || !PaymentCompletionExceptionService::tableReady() || !DeliverySlotReservationService::tableReady()) {
    fwrite(STDERR, "SKIP DB: schema / reconciliation tables not ready.\n");
    echo $fail > 0 ? "Static failures: {$fail}\n" : "Static checks passed; DB tests skipped.\n";
    exit($fail > 0 ? 1 : 0);
}

Install::ensureConnection();
$pdo = Database::pdo();
$tag = substr(bin2hex(random_bytes(3)), 0, 6);
$slug = 'cust_' . $tag;
$pdo->prepare(
    'INSERT INTO delivery_slots (slug, label, window_label, delivery_day, start_time, end_time, cutoff_time, capacity, sort_order, is_active, weekday_mask)
     VALUES (?,?,?,?,?,?,?,?,?,?,127)'
)->execute([$slug, 'Custom test', 'All day', 'same_day', '06:00:00', '22:00:00', '23:59:00', 3, 9988, 1]);
$slotId = (int) $pdo->lastInsertId();
$date = DeliveryConfig::now()->format('Y-m-d');
$start = DeliveryConfig::now()->modify('+2 hours')->format('H:i');
$end = DeliveryConfig::now()->modify('+3 hours')->format('H:i');

$orderNumber = 'CUST-' . strtoupper($tag);
$pdo->prepare(
    "INSERT INTO orders (order_number, status, subtotal, grand_total, delivery_fee, payment_status,
     delivery_slot, delivery_slot_id, delivery_date, placed_at)
     VALUES (?, 'pending_payment', 80, 80, 0, 'pending', ?, ?, ?, NOW())"
)->execute([$orderNumber, $slug, $slotId, $date]);
$orderId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key) VALUES (?, ?, ?, ?, ?, ?)'
)->execute([$orderId, 'simulate', 80, 'INR', 'created', 'pay_' . $orderNumber . '_1']);
DeliverySlotReservationService::createHold($pdo, $slotId, $date, $orderId, 30);
$pdo->exec("UPDATE delivery_slot_reservations SET expires_at = NOW() - INTERVAL 5 MINUTE WHERE order_id = {$orderId}");

$failPay = PaymentService::completePayment(
    $orderNumber,
    'simulate',
    'gw_cust_' . $tag,
    'ord_cust_' . $tag,
    80.0,
    'INR',
    'pay_' . $orderNumber . '_1',
    'evt_cust_' . $tag
);
$ok(!empty($failPay['reconciliation']), 'DB: expired hold → reconciliation');

$ex = PaymentCompletionExceptionService::openForOrderId($orderId);
$exceptionId = $ex !== null ? (int) $ex['id'] : 0;
$adminId = (int) ($pdo->query('SELECT id FROM admin_users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 1);

$yesterday = DeliveryConfig::now()->modify('-1 day')->format('Y-m-d');
$badPast = PaymentFulfilmentReconciliationService::adminCustomDeliveryAndCompleteFulfilment(
    $orderId,
    $exceptionId,
    $yesterday,
    '10:00',
    '11:00',
    $adminId,
    'Test past delivery date rejection.',
    true
);
$ok(empty($badPast['ok']), 'DB: past delivery date rejected');

$badEnd = PaymentFulfilmentReconciliationService::adminCustomDeliveryAndCompleteFulfilment(
    $orderId,
    $exceptionId,
    $date,
    '18:00',
    '10:00',
    $adminId,
    'Test end before start rejection.',
    true
);
$ok(empty($badEnd['ok']), 'DB: end before start rejected');

$badNote = PaymentFulfilmentReconciliationService::adminCustomDeliveryAndCompleteFulfilment(
    $orderId,
    $exceptionId,
    $date,
    $start,
    $end,
    $adminId,
    'short',
    true
);
$ok(empty($badNote['ok']), 'DB: short confirmation note rejected');

$badConfirm = PaymentFulfilmentReconciliationService::adminCustomDeliveryAndCompleteFulfilment(
    $orderId,
    $exceptionId,
    $date,
    $start,
    $end,
    $adminId,
    'Customer agreed on phone to this window.',
    false
);
$ok(empty($badConfirm['ok']), 'DB: missing customer confirmation rejected');

$custom = PaymentFulfilmentReconciliationService::adminCustomDeliveryAndCompleteFulfilment(
    $orderId,
    $exceptionId,
    $date,
    $start,
    $end,
    $adminId,
    'Customer agreed on phone to custom delivery window.',
    true
);
$ok(!empty($custom['ok']), 'DB: custom window fulfilment succeeds: ' . ($custom['message'] ?? ''));

$payCount = (int) $pdo->query('SELECT COUNT(*) FROM payments WHERE order_id = ' . $orderId)->fetchColumn();
$ok($payCount === 1, 'DB: no extra payment row created');

$resCount = (int) $pdo->query('SELECT COUNT(*) FROM delivery_slot_reservations WHERE order_id = ' . $orderId)->fetchColumn();
$ok($resCount <= 1, 'DB: no duplicate reservation rows');

$dup = PaymentFulfilmentReconciliationService::adminCustomDeliveryAndCompleteFulfilment(
    $orderId,
    $exceptionId,
    $date,
    $start,
    $end,
    $adminId,
    'Duplicate custom submit should be idempotent.',
    true
);
$ok(empty($dup['ok']) || !empty($dup['idempotent']), 'DB: duplicate custom submit idempotent or blocked');

// Predefined path still available (regression)
$orderNumber2 = 'CUST2-' . strtoupper($tag);
$pdo->prepare(
    "INSERT INTO orders (order_number, status, subtotal, grand_total, delivery_fee, payment_status,
     delivery_slot, delivery_slot_id, delivery_date, placed_at)
     VALUES (?, 'pending_payment', 50, 50, 0, 'pending', ?, ?, ?, NOW())"
)->execute([$orderNumber2, $slug, $slotId, $date]);
$orderId2 = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key) VALUES (?, ?, ?, ?, ?, ?)'
)->execute([$orderId2, 'simulate', 50, 'INR', 'created', 'pay_' . $orderNumber2 . '_1']);
DeliverySlotReservationService::createHold($pdo, $slotId, $date, $orderId2, 30);
$pdo->exec("UPDATE delivery_slot_reservations SET expires_at = NOW() - INTERVAL 5 MINUTE WHERE order_id = {$orderId2}");
PaymentService::completePayment(
    $orderNumber2,
    'simulate',
    'gw2_' . $tag,
    'ord2_' . $tag,
    50.0,
    'INR',
    'pay_' . $orderNumber2 . '_1',
    'evt2_' . $tag
);
$ex2 = PaymentCompletionExceptionService::openForOrderId($orderId2);
$pre = PaymentFulfilmentReconciliationService::adminReassignSlotAndCompleteFulfilment(
    $orderId2,
    $ex2 ? (int) $ex2['id'] : 0,
    $slotId,
    $date,
    $adminId,
    'Predefined slot recovery regression test.'
);
$ok(!empty($pre['ok']), 'DB: predefined slot recovery still works');

$pdo->exec("DELETE FROM deliveries WHERE order_id IN ({$orderId}, {$orderId2})");
$pdo->exec("DELETE FROM payment_completion_exceptions WHERE order_id IN ({$orderId}, {$orderId2})");
$pdo->exec("DELETE FROM payments WHERE order_id IN ({$orderId}, {$orderId2})");
$pdo->exec("DELETE FROM delivery_slot_reservations WHERE order_id IN ({$orderId}, {$orderId2})");
$pdo->exec("DELETE FROM orders WHERE id IN ({$orderId}, {$orderId2})");
$pdo->exec("DELETE FROM delivery_slots WHERE id = {$slotId}");

echo $fail > 0 ? "Failures: {$fail}\n" : "All custom delivery override checks passed.\n";
exit($fail > 0 ? 1 : 0);
