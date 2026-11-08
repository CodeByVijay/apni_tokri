<?php

declare(strict_types=1);

/**
 * Admin cancellation must restore paid-order inventory via restockStorefrontOrderOnce (idempotent).
 * CLI: php scripts/test-admin-cancel-inventory-restore.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;
$tag = substr(Security::randomToken(8), 0, 10);

function oki(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$mig = dirname(__DIR__) . '/database/migrations/1.23.0_order_customer_cancellation.sql';
if (!OrderCancellationService::restockTableReady() && is_readable($mig)) {
    $pdo->exec((string) file_get_contents($mig));
}

$v1 = (int) ($pdo->query(
    'SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id WHERE v.is_active = 1 ORDER BY v.id ASC LIMIT 1'
)->fetchColumn() ?: 0);
$v2 = (int) ($pdo->query(
    'SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id WHERE v.is_active = 1 AND v.id <> ' . (int) $v1 . ' ORDER BY v.id ASC LIMIT 1'
)->fetchColumn() ?: 0);
oki($v1 > 0, 'Variant 1');
$pdo->prepare('UPDATE product_variants SET stock_qty = 20 WHERE id = ?')->execute([$v1]);
if ($v2 > 0) {
    $pdo->prepare('UPDATE product_variants SET stock_qty = 15 WHERE id = ?')->execute([$v2]);
}

$pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute([
    '97' . substr($tag, 0, 8),
    'Inv Restore',
]);
$custId = (int) $pdo->lastInsertId();

function stockOf(PDO $pdo, int $vid): int
{
    return (int) $pdo->query('SELECT stock_qty FROM product_variants WHERE id = ' . $vid)->fetchColumn();
}

function movCountForVariant(PDO $pdo, int $vid, string $like): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM inventory_movements WHERE variant_id = ? AND reason LIKE ?');
    $st->execute([$vid, $like]);

    return (int) $st->fetchColumn();
}

// --- Admin cancel restores stock ---
$orderNum = 'AT-INV-A-' . strtoupper($tag);
$stockBefore = stockOf($pdo, $v1);
InventoryService::decrement($v1, 1, 'Test setup order placement ' . $orderNum);
oki(stockOf($pdo, $v1) === $stockBefore - 1, 'Simulated placement decrement');

$pdo->prepare(
    'INSERT INTO orders (order_number, customer_id, status, subtotal, grand_total, payment_status, delivery_slot, placed_at)
     VALUES (?, ?, ?, 10, 10, ?, ?, NOW())'
)->execute([$orderNum, $custId, OrderStatus::CONFIRMED, 'paid', 'morning']);
$orderId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, quantity, unit_price, line_total)
     VALUES (?, ?, ?, ?, 1, 10, 10)'
)->execute([$orderId, $v1, 'A', '1kg']);

$upd = AdminOrderService::updateStatus($orderId, OrderStatus::CANCELLED, 'Unavailable stock');
oki(!empty($upd['ok']), 'Admin cancellation succeeds');
oki(stockOf($pdo, $v1) === $stockBefore, 'Stock restored after admin cancel');
oki(movCountForVariant($pdo, $v1, '%order ' . $orderNum . '%') >= 1, 'Positive movement references order number');
$mov = $pdo->prepare(
    'SELECT change_qty, qty_before, qty_after FROM inventory_movements WHERE variant_id = ? AND reason LIKE ? ORDER BY id DESC LIMIT 1'
);
$mov->execute([$v1, '%order ' . $orderNum . '%']);
$row = $mov->fetch(PDO::FETCH_ASSOC) ?: [];
oki((int) ($row['change_qty'] ?? 0) === 1, 'Movement qty +1');
oki((int) ($row['qty_after'] ?? 0) === (int) ($row['qty_before'] ?? 0) + 1, 'Before/after consistent');

$restockEvents = (int) $pdo->query('SELECT COUNT(*) FROM order_stock_restock_events WHERE order_id = ' . $orderId)->fetchColumn();
oki($restockEvents === 1, 'Single restock idempotency marker');

// Duplicate status note / repeat cancel does not double-restore
$before2 = stockOf($pdo, $v1);
AdminOrderService::updateStatus($orderId, OrderStatus::CANCELLED, 'Still cancelled');
oki(stockOf($pdo, $v1) === $before2, 'Duplicate admin cancel status does not restore twice');
oki(
    (int) $pdo->query('SELECT COUNT(*) FROM order_stock_restock_events WHERE order_id = ' . $orderId)->fetchColumn() === 1,
    'Still one restock event'
);

// Direct second restock call
oki(OrderCancellationService::restockStorefrontOrderOnce($orderId, 'Order cancelled'), 'Second restock call ok');
oki(stockOf($pdo, $v1) === $before2, 'Explicit restock idempotent');

// --- Customer cancel path preserved ---
$orderNumB = 'AT-INV-C-' . strtoupper($tag);
$sb = stockOf($pdo, $v1);
InventoryService::decrement($v1, 1, 'Setup ' . $orderNumB);
$pdo->prepare(
    'INSERT INTO orders (order_number, customer_id, status, subtotal, grand_total, payment_status, delivery_slot)
     VALUES (?, ?, ?, 10, 10, ?, ?)'
)->execute([$orderNumB, $custId, OrderStatus::CONFIRMED, 'paid', 'morning']);
$orderIdB = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, quantity, unit_price, line_total)
     VALUES (?, ?, ?, ?, 1, 10, 10)'
)->execute([$orderIdB, $v1, 'B', '1kg']);
$pdo->prepare(
    "INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key, captured_at)
     VALUES (?, 'simulate', 10, 'INR', 'captured', ?, NOW())"
)->execute([$orderIdB, 'pay_' . $orderNumB]);
$r = OrderCancellationService::cancelByCustomer($custId, $orderNumB, 'ordered_by_mistake', '', 'idem-inv-' . $tag);
oki(!empty($r['ok']), 'Customer cancel still restores');
oki(stockOf($pdo, $v1) === $sb, 'Customer path stock restored');

// --- Multi variant / qty ---
if ($v2 > 0) {
    $orderNumM = 'AT-INV-M-' . strtoupper($tag);
    $s1 = stockOf($pdo, $v1);
    $s2 = stockOf($pdo, $v2);
    InventoryService::decrement($v1, 2, 'Setup m1');
    InventoryService::decrement($v2, 3, 'Setup m2');
    $pdo->prepare(
        'INSERT INTO orders (order_number, customer_id, status, subtotal, grand_total, payment_status, delivery_slot)
         VALUES (?, ?, ?, 50, 50, ?, ?)'
    )->execute([$orderNumM, $custId, OrderStatus::CONFIRMED, 'paid', 'morning']);
    $orderIdM = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, quantity, unit_price, line_total)
         VALUES (?, ?, ?, ?, 2, 10, 20), (?, ?, ?, ?, 3, 10, 30)'
    )->execute([$orderIdM, $v1, 'A', '1', $orderIdM, $v2, 'B', '1']);
    AdminOrderService::updateStatus($orderIdM, OrderStatus::CANCELLED, 'Stock');
    oki(stockOf($pdo, $v1) === $s1, 'Variant 1 qty 2 restored');
    oki(stockOf($pdo, $v2) === $s2, 'Variant 2 qty 3 restored');
    $pdo->prepare('DELETE FROM order_stock_restock_events WHERE order_id = ?')->execute([$orderIdM]);
    $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderIdM]);
    $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderIdM]);
}

// --- Unpaid: no artificial stock increase ---
$orderNumU = 'AT-INV-U-' . strtoupper($tag);
$su = stockOf($pdo, $v1);
$pdo->prepare(
    'INSERT INTO orders (order_number, customer_id, status, subtotal, grand_total, payment_status, delivery_slot)
     VALUES (?, ?, ?, 5, 5, ?, ?)'
)->execute([$orderNumU, $custId, OrderStatus::PENDING_PAYMENT, 'pending', 'morning']);
$orderIdU = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, quantity, unit_price, line_total)
     VALUES (?, ?, ?, ?, 1, 5, 5)'
)->execute([$orderIdU, $v1, 'U', '1']);
AdminOrderService::updateStatus($orderIdU, OrderStatus::CANCELLED, 'No pay');
oki(stockOf($pdo, $v1) === $su, 'Unpaid cancel does not increment stock');
oki(
    movCountForVariant($pdo, $v1, '%' . $orderNumU . '%') === 0,
    'No movement for unpaid cancel'
);

// Cleanup
$pdo->exec('DELETE FROM order_stock_restock_events WHERE order_id IN (' . $orderId . ', ' . $orderIdB . ', ' . $orderIdU . ')');
$pdo->exec('DELETE FROM order_cancellation_events WHERE order_id IN (' . $orderIdB . ')');
$pdo->exec('DELETE FROM refund_requests WHERE order_id IN (' . $orderId . ', ' . $orderIdB . ', ' . $orderIdU . ')');
$pdo->exec('DELETE FROM order_items WHERE order_id IN (' . $orderId . ', ' . $orderIdB . ', ' . $orderIdU . ')');
$pdo->exec('DELETE FROM orders WHERE id IN (' . $orderId . ', ' . $orderIdB . ', ' . $orderIdU . ')');
$pdo->exec('DELETE FROM customers WHERE id = ' . $custId);

echo $fail === 0 ? "PASS: admin cancel inventory restore.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
