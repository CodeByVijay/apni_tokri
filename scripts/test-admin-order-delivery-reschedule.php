<?php

declare(strict_types=1);

/** Admin order delivery reschedule — capacity, states, rollback. */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

if (!Install::isSchemaReady() || !DeliverySlotReservationService::tableReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function ok(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

$pdo = Database::pdo();
$tok = strtolower(bin2hex(random_bytes(4)));
$dateA = DeliveryConfig::now()->modify('+3 days')->format('Y-m-d');
$dateB = DeliveryConfig::now()->modify('+4 days')->format('Y-m-d');

$pdo->exec(
    "INSERT INTO delivery_slots (slug, label, window_label, delivery_day, start_time, end_time, cutoff_time, capacity, weekday_mask, is_active, sort_order)
     VALUES ('zt-rs-a-$tok', 'RS A', 'Morning', 'same_day', '09:00:00', '12:00:00', '08:00:00', 2, 127, 1, 991)"
);
$slotA = (int) $pdo->lastInsertId();
$pdo->exec(
    "INSERT INTO delivery_slots (slug, label, window_label, delivery_day, start_time, end_time, cutoff_time, capacity, weekday_mask, is_active, sort_order)
     VALUES ('zt-rs-b-$tok', 'RS B', 'Evening', 'same_day', '18:00:00', '21:00:00', '17:00:00', 1, 127, 1, 992)"
);
$slotB = (int) $pdo->lastInsertId();

$customerId = (int) $pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();
if ($customerId <= 0) {
    $pdo->exec("INSERT INTO customers (mobile, name, is_active) VALUES ('9" . random_int(100000000, 999999999) . "', 'RsTest', 1)");
    $customerId = (int) $pdo->lastInsertId();
}

$mkOrder = static function (
    PDO $pdo,
    int $slotId,
    string $date,
    int $customerId,
    string $num,
    string $status,
    string $pay
): int {
    $snap = json_encode([
        'schema_version' => DeliveryQuoteService::SNAPSHOT_SCHEMA,
        'slot_id' => $slotId,
        'slot_slug' => 'zt',
        'delivery_date' => $date,
    ], JSON_UNESCAPED_UNICODE);
    $pdo->prepare(
        'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total,
         payment_status, delivery_slot, delivery_slot_id, delivery_date, delivery_snapshot_json, placed_at)
         VALUES (?, ?, ?, 100, 0, 0, 100, ?, ?, ?, ?, ?, NOW())'
    )->execute([$num, $customerId, $status, $pay, 'zt-rs', $slotId, $date, $snap]);

    return (int) $pdo->lastInsertId();
};

$orderId = $mkOrder($pdo, $slotA, $dateA, $customerId, 'ZT-RS-' . $tok, OrderStatus::CONFIRMED, 'paid');
DeliverySlotReservationService::createHold($pdo, $slotA, $dateA, $orderId, 30);
DeliverySlotReservationService::consumeForOrder($orderId, $pdo);

$beforeUsedA = DeliverySlotReservationService::usedUnits($pdo, $slotA, $dateA, null);
$beforeUsedB = DeliverySlotReservationService::usedUnits($pdo, $slotB, $dateB, null);

$res = AdminOrderDeliveryRescheduleService::reschedule(
    $orderId,
    $slotB,
    $dateB,
    1,
    'Customer agreed on phone to evening slot next day.'
);
ok(!empty($res['ok']), 'Successful reschedule returns ok');

$row = $pdo->prepare('SELECT delivery_slot_id, delivery_date, payment_status FROM orders WHERE id = ?');
$row->execute([$orderId]);
$after = $row->fetch(PDO::FETCH_ASSOC);
ok((int) ($after['delivery_slot_id'] ?? 0) === $slotB, 'Order moved to new slot');
ok((string) ($after['delivery_date'] ?? '') === $dateB, 'Order moved to new date');
ok((string) ($after['payment_status'] ?? '') === 'paid', 'Payment status unchanged');

$afterUsedA = DeliverySlotReservationService::usedUnits($pdo, $slotA, $dateA, null);
$afterUsedB = DeliverySlotReservationService::usedUnits($pdo, $slotB, $dateB, null);
ok($afterUsedA === $beforeUsedA - 1, 'Old slot/date capacity freed');
ok($afterUsedB === $beforeUsedB + 1, 'New slot/date capacity consumed');

$hist = $pdo->prepare('SELECT note FROM order_status_history WHERE order_id = ? ORDER BY id DESC LIMIT 1');
$hist->execute([$orderId]);
$note = (string) ($hist->fetchColumn() ?: '');
ok(str_contains($note, 'Delivery rescheduled'), 'History entry records reschedule');

// Full destination slot
$pdo->exec("UPDATE delivery_slots SET capacity = 1 WHERE id = {$slotB}");
$blockerId = $mkOrder($pdo, $slotB, $dateB, $customerId, 'ZT-BLK-' . $tok, OrderStatus::CONFIRMED, 'paid');
$victimId = $mkOrder($pdo, $slotA, $dateA, $customerId, 'ZT-VIC-' . $tok, OrderStatus::CONFIRMED, 'paid');
$resFull = AdminOrderDeliveryRescheduleService::reschedule(
    $victimId,
    $slotB,
    $dateB,
    1,
    'Should fail because destination slot is full.'
);
ok(empty($resFull['ok']), 'Full slot rejects reschedule');
$unchanged = $pdo->prepare('SELECT delivery_slot_id FROM orders WHERE id = ?');
$unchanged->execute([$victimId]);
ok((int) $unchanged->fetchColumn() === $slotA, 'Rollback leaves order on original slot when destination full');

// Invalid state — delivered
$delId = $mkOrder($pdo, $slotA, $dateA, $customerId, 'ZT-DEL-' . $tok, OrderStatus::DELIVERED, 'paid');
$resDel = AdminOrderDeliveryRescheduleService::reschedule($delId, $slotB, $dateB, 1, 'Should not allow delivered order reschedule.');
ok(empty($resDel['ok']), 'Delivered order cannot be rescheduled');

// Concurrent capacity on cap=1 slot B
$config = App::config()['database'];
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $config['host'] ?? '127.0.0.1',
    (int) ($config['port'] ?? 3306),
    $config['name'],
    $config['charset'] ?? 'utf8mb4'
);
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$pdoA = new PDO($dsn, (string) $config['user'], (string) ($config['password'] ?? ''), $opts);
$pdoB = new PDO($dsn, (string) $config['user'], (string) ($config['password'] ?? ''), $opts);

$dateC = DeliveryConfig::now()->modify('+5 days')->format('Y-m-d');
$pdo->exec("UPDATE delivery_slots SET capacity = 1 WHERE id = {$slotB}");
$conA = $mkOrder($pdo, $slotA, $dateA, $customerId, 'ZT-CA-' . $tok, OrderStatus::CONFIRMED, 'paid');
$conB = $mkOrder($pdo, $slotA, $dateA, $customerId, 'ZT-CB-' . $tok, OrderStatus::CONFIRMED, 'paid');

$pdoA->beginTransaction();
$pdoA->prepare('SELECT id FROM delivery_slots WHERE id = ? FOR UPDATE')->execute([$slotB]);
$pdoB->exec('SET SESSION innodb_lock_wait_timeout = 1');
$blocked = false;
try {
    $pdoB->beginTransaction();
    $pdoB->prepare('SELECT id FROM delivery_slots WHERE id = ? FOR UPDATE')->execute([$slotB]);
    $pdoB->commit();
} catch (PDOException $e) {
    $blocked = (int) ($e->errorInfo[1] ?? 0) === 1205;
    if ($pdoB->inTransaction()) {
        $pdoB->rollBack();
    }
}
if ($pdoA->inTransaction()) {
    $pdoA->commit();
}
ok($blocked, 'Concurrent lock blocks second connection on full slot row');

$rA = AdminOrderDeliveryRescheduleService::reschedule($conA, $slotB, $dateC, 1, 'Concurrent test order A move.');
$rB = AdminOrderDeliveryRescheduleService::reschedule($conB, $slotB, $dateC, 1, 'Concurrent test order B move.');
$wins = (!empty($rA['ok']) ? 1 : 0) + (!empty($rB['ok']) ? 1 : 0);
ok($wins === 1, 'Only one concurrent reschedule wins on cap=1 destination');

// Cleanup
$ids = [$orderId, $blockerId, $victimId, $delId, $conA, $conB];
foreach ($ids as $oid) {
    DeliverySlotReservationService::releaseForOrder($oid, $pdo);
}
$idList = implode(',', array_map('intval', $ids));
$pdo->exec("DELETE FROM order_status_history WHERE order_id IN ({$idList})");
$pdo->exec("DELETE FROM delivery_slot_reservations WHERE order_id IN ({$idList})");
$pdo->exec("DELETE FROM orders WHERE id IN ({$idList})");
$pdo->exec("DELETE FROM delivery_slots WHERE id IN ({$slotA}, {$slotB})");

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
