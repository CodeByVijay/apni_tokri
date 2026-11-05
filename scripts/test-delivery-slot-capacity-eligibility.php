<?php

declare(strict_types=1);

/** Slot capacity: paid/hold/cancelled/failed/refunded eligibility (disposable DB). */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$pass = 0;
$fail = 0;

function t(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

$tok = strtolower(bin2hex(random_bytes(4)));
$date = DeliveryConfig::now()->modify('+2 days')->format('Y-m-d');

$pdo->exec("INSERT INTO delivery_slots (slug, label, window_label, delivery_day, start_time, end_time, cutoff_time, capacity, weekday_mask, is_active, sort_order)
    VALUES ('zt-cap-$tok', 'Cap test', 'Test', 'same_day', '09:00:00', '12:00:00', '08:00:00', 2, 127, 1, 990)");
$slotId = (int) $pdo->lastInsertId();

$customerId = (int) $pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();
if ($customerId <= 0) {
    $pdo->exec("INSERT INTO customers (mobile, name, is_active) VALUES ('9" . random_int(100000000, 999999999) . "', 'CapTest', 1)");
    $customerId = (int) $pdo->lastInsertId();
}

function mkOrder(PDO $pdo, int $slotId, string $date, int $customerId, string $num, string $status, string $pay): int
{
    $pdo->prepare(
        'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total,
         payment_status, delivery_slot, delivery_slot_id, delivery_date, placed_at)
         VALUES (?, ?, ?, 100, 0, 0, 100, ?, ?, ?, ?, NOW())'
    )->execute([$num, $customerId, $status, $pay, 'zt-cap', $slotId, $date]);

    return (int) $pdo->lastInsertId();
}

$used = static function (int $slotId, string $date) use ($pdo): int {
    return DeliverySlotReservationService::usedUnits($pdo, $slotId, $date, null);
};

$adminOrders = static function (int $slotId): int {
    return DeliverySlotRepository::capacityConsumingOrderCount($slotId);
};

// Confirmed paid consumes capacity
$paidId = mkOrder($pdo, $slotId, $date, $customerId, 'ZT-P-' . $tok, 'confirmed', 'paid');
t($used($slotId, $date) === 1, 'Paid confirmed order uses 1 unit');
t($adminOrders($slotId) === 1, 'Admin Orders count includes paid order');

// Payment failed does not consume (no hold)
$failId = mkOrder($pdo, $slotId, $date, $customerId, 'ZT-F-' . $tok, 'payment_failed', 'failed');
t($used($slotId, $date) === 1, 'Payment failed order does not add capacity');
t($adminOrders($slotId) === 1, 'Admin count unchanged after payment failed row');

// Active hold consumes until released
$pendId = mkOrder($pdo, $slotId, $date, $customerId, 'ZT-H-' . $tok, 'pending_payment', 'pending');
if (DeliverySlotReservationService::tableReady()) {
    DeliverySlotReservationService::createHold($pdo, $slotId, $date, $pendId, 30);
    t($used($slotId, $date) === 2, 'Active hold adds capacity');
    t($adminOrders($slotId) === 2, 'Admin count includes active hold');
    DeliverySlotReservationService::releaseForOrder($pendId, $pdo);
    t($used($slotId, $date) === 1, 'Released hold frees capacity');
    t($adminOrders($slotId) === 1, 'Admin count drops after hold release');
}

// Cancelled paid order releases capacity
$pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([OrderStatus::CANCELLED, $paidId]);
DeliverySlotReservationService::releaseForOrder($paidId, $pdo);
t($used($slotId, $date) === 0, 'Cancelled paid order no longer consumes capacity');
t($adminOrders($slotId) === 0, 'Admin Orders zero after cancellation');

// Reference count still includes historical rows
t(DeliverySlotRepository::orderReferenceCount($slotId) >= 3, 'Linked order history preserved for slug lock');

// Multiple paid on same slot/date
$id1 = mkOrder($pdo, $slotId, $date, $customerId, 'ZT-A-' . $tok, 'processing', 'paid');
$id2 = mkOrder($pdo, $slotId, $date, $customerId, 'ZT-B-' . $tok, 'delivered', 'paid');
t($used($slotId, $date) === 2, 'Two paid orders fill 2 units');
$pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([OrderStatus::CANCELLED, $id1]);
DeliverySlotReservationService::releaseForOrder($id1, $pdo);
t($used($slotId, $date) === 1, 'Cancelling one of two releases one unit');

$pdo->exec('DELETE FROM delivery_slot_reservations WHERE delivery_slot_id = ' . $slotId);
$pdo->exec('DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE delivery_slot_id = ' . $slotId . ')');
$pdo->exec('DELETE FROM order_status_history WHERE order_id IN (SELECT id FROM orders WHERE delivery_slot_id = ' . $slotId . ')');
$pdo->exec('DELETE FROM orders WHERE delivery_slot_id = ' . $slotId);
$pdo->exec('DELETE FROM delivery_slots WHERE id = ' . $slotId);

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
