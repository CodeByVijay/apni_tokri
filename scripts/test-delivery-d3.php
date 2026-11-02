<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

Install::ensureConnection();
$pdo = Database::pdo();

ok(Install::installedSchemaVersion() === Install::SCHEMA_VERSION, 'schema version');
ok(DeliverySmsService::tableReady(), 'delivery_sms_logs table');
ok(in_array(DeliveryStatus::PENDING_DISPATCH, DeliveryStatus::all(), true), 'DeliveryStatus constants');

ok(
    DeliveryStatusTransitions::canTransition(DeliveryStatus::PENDING_DISPATCH, DeliveryStatus::READY, false),
    'pending_dispatch to ready'
);
ok(
    !DeliveryStatusTransitions::canTransition(DeliveryStatus::PENDING_DISPATCH, DeliveryStatus::DELIVERED, false),
    'pending_dispatch cannot skip to delivered via transition map'
);
ok(
    DeliveryStatusTransitions::canTransition(DeliveryStatus::FAILED, DeliveryStatus::PENDING_DISPATCH, true),
    'failed retry to pending_dispatch'
);

$customerId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
ok($customerId > 0, 'fixture customer');
$pdo->prepare('UPDATE customers SET mobile = ? WHERE id = ?')->execute(['9876543210', $customerId]);

$ordNum = 'D3T-' . strtoupper(substr(Security::randomToken(4), 0, 6));
$pdo->prepare(
    'INSERT INTO orders (order_number, customer_id, status, payment_status, subtotal, grand_total, delivery_slot, delivery_date, placed_at)
     VALUES (?, ?, ?, ?, 100, 100, ?, CURDATE(), NOW())'
)->execute([$ordNum, $customerId, 'confirmed', 'paid', 'Test slot']);
$orderId = (int) $pdo->lastInsertId();
$delId = DeliveryAssignmentService::createForOrder($orderId);
ok($delId !== null && $delId > 0, 'createForOrder returns id');
$row = DeliveryAssignmentService::findById((int) $delId);
ok($row !== null && DeliveryStatus::normalize((string) $row['status']) === DeliveryStatus::PENDING_DISPATCH, 'initial pending_dispatch');

$sms1 = DeliverySmsService::logsForDelivery((int) $delId);
ok($sms1 !== [] && ($sms1[0]['event_key'] ?? '') === 'order_confirmed', 'order_confirmed SMS logged');
DeliverySmsService::sendDeliveryEvent((int) $delId, 'order_confirmed');
$smsDup = DeliverySmsService::logsForDelivery((int) $delId);
$sentCount = 0;
foreach ($smsDup as $l) {
    if (($l['event_key'] ?? '') === 'order_confirmed' && ($l['status'] ?? '') === 'sent') {
        $sentCount++;
    }
}
ok($sentCount === 1, 'duplicate order_confirmed SMS prevented');

$resReady = DeliveryAssignmentService::updateFromAdmin((int) $delId, [
    'status' => DeliveryStatus::READY,
    'internal_remarks' => 'Packed for D3 test run',
]);
ok(!empty($resReady['ok']), 'transition to ready');

$dateBefore = $pdo->prepare('SELECT delivery_date, delivery_slot_id FROM orders WHERE id = ?');
$dateBefore->execute([$orderId]);
$before = $dateBefore->fetch(PDO::FETCH_ASSOC);

$resDisp = DeliveryAssignmentService::updateFromAdmin((int) $delId, [
    'status' => DeliveryStatus::DISPATCHED,
    'courier_service_name' => 'Porter',
    'booking_reference' => 'BK-D3-TEST',
    'internal_remarks' => 'Booked manually in Porter app',
]);
ok(!empty($resDisp['ok']), 'transition to dispatched');

$dateAfter = $pdo->prepare('SELECT delivery_date, delivery_slot_id FROM orders WHERE id = ?');
$dateAfter->execute([$orderId]);
$after = $dateAfter->fetch(PDO::FETCH_ASSOC);
ok(($before['delivery_date'] ?? null) === ($after['delivery_date'] ?? null), 'delivery_date unchanged');
ok(($before['delivery_slot_id'] ?? null) === ($after['delivery_slot_id'] ?? null), 'delivery_slot_id unchanged');

$timeline = DeliveryCustomerTimeline::forOrderId($orderId);
$hasDispatched = false;
foreach ($timeline as $step) {
    if (stripos((string) ($step['status'] ?? ''), 'dispatch') !== false) {
        $hasDispatched = true;
    }
    ok(stripos((string) ($step['status'] ?? ''), 'Porter') === false, 'customer timeline hides courier name');
}
$visible = DeliveryCustomerTimeline::visibleHistoryRows((int) $delId);
foreach ($visible as $vr) {
    ok(stripos((string) ($vr['note'] ?? ''), 'Porter') === false, 'customer-visible history note excludes internal remark');
}
ok($hasDispatched, 'customer timeline includes dispatched step');

$resDel = DeliveryAssignmentService::updateFromAdmin((int) $delId, [
    'status' => DeliveryStatus::DELIVERED,
    'internal_remarks' => 'Customer confirmed receipt by phone',
]);
ok(!empty($resDel['ok']), 'transition to delivered');

$pdo->prepare('DELETE FROM delivery_sms_send_locks WHERE delivery_id = ?')->execute([$delId]);
$pdo->prepare('DELETE FROM delivery_sms_logs WHERE delivery_id = ?')->execute([$delId]);
$pdo->prepare('DELETE FROM delivery_status_history WHERE delivery_id = ?')->execute([$delId]);
$pdo->prepare('DELETE FROM deliveries WHERE id = ?')->execute([$delId]);
$pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);

echo $fail === 0 ? "\nPASS: D3 manual delivery checks.\n" : "\n{$fail} failure(s).\n";
exit($fail > 0 ? 1 : 0);
