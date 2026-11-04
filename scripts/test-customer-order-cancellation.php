<?php

declare(strict_types=1);

/**
 * Customer order cancellation — eligibility, security, stock/refund idempotency.
 * Applies migration 1.23.0 on configured DB if tables missing (local dev only).
 * CLI: php7.3 scripts/test-customer-order-cancellation.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;
$runSuffix = substr(Security::randomToken(8), 0, 12);

function okc(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$mig = dirname(__DIR__) . '/database/migrations/1.23.0_order_customer_cancellation.sql';
if (!OrderCancellationService::eventsTableReady() && is_readable($mig)) {
    $pdo->exec((string) file_get_contents($mig));
}
okc(OrderCancellationService::eventsTableReady(), 'order_cancellation_events table ready');
okc(OrderCancellationService::restockTableReady(), 'order_stock_restock_events table ready');

$customerA = (int) ($pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
$customerB = (int) ($pdo->query('SELECT id FROM customers ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 0);
if ($customerA === $customerB) {
    $pdo->prepare(
        'INSERT INTO customers (email, password_hash, name, phone) VALUES (?, ?, ?, ?)'
    )->execute(['at-cancel-b-' . time() . '@example.test', password_hash('x', PASSWORD_DEFAULT), 'B', '9000000001']);
    $customerB = (int) $pdo->lastInsertId();
}
okc($customerA > 0 && $customerB > 0, 'Two customers for ownership test');

$variantId = (int) ($pdo->query(
    'SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id WHERE v.is_active = 1 LIMIT 1'
)->fetchColumn() ?: 0);
okc($variantId > 0, 'Active variant');
$pdo->prepare('UPDATE product_variants SET stock_qty = 200 WHERE id = ?')->execute([$variantId]);
$stockBefore = (int) $pdo->query('SELECT stock_qty FROM product_variants WHERE id = ' . $variantId)->fetchColumn();
function mkOrder(PDO $pdo, int $cid, int $variantId, string $status, string $payStatus, string $suffix): int
{
    $num = 'AT-CANCEL-' . $suffix . '-' . strtoupper(substr(Security::randomToken(3), 0, 5));
    $pdo->prepare(
        'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, placed_at)
         VALUES (?, ?, ?, 100, 0, 0, 100, ?, NOW())'
    )->execute([$num, $cid, $status, $payStatus]);
    $oid = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, quantity, unit_price, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$oid, $variantId, 'Test', 'Var', 2, 50, 100]);

    return $oid;
}

$pendingId = mkOrder($pdo, $customerA, $variantId, OrderStatus::PENDING_PAYMENT, 'pending', 'PEND');
$pendingNum = (string) $pdo->query('SELECT order_number FROM orders WHERE id = ' . $pendingId)->fetchColumn();
$rowP = $pdo->query('SELECT * FROM orders WHERE id = ' . $pendingId)->fetch(PDO::FETCH_ASSOC);
okc(OrderCancellationService::eligibility($rowP)['eligible'], 'Pending payment eligible');
$r = OrderCancellationService::cancelByCustomer($customerA, $pendingNum, 'ordered_by_mistake', '', 'idem-pend-' . $runSuffix);
okc(!empty($r['ok']), 'Cancel pending order');
okc(
    (string) $pdo->query('SELECT status FROM orders WHERE id = ' . $pendingId)->fetchColumn() === OrderStatus::CANCELLED,
    'Pending order status cancelled'
);

$confirmedId = mkOrder($pdo, $customerA, $variantId, OrderStatus::CONFIRMED, 'paid', 'CONF');
$confirmedNum = (string) $pdo->query('SELECT order_number FROM orders WHERE id = ' . $confirmedId)->fetchColumn();
$pdo->prepare(
    "INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key, captured_at) VALUES (?, 'simulate', 100, 'INR', 'captured', ?, NOW())"
)->execute([$confirmedId, 'pay-' . $confirmedId]);
InventoryService::decrement($variantId, 2, 'Test setup order ' . $confirmedId);
$stockAfterDec = (int) $pdo->query('SELECT stock_qty FROM product_variants WHERE id = ' . $variantId)->fetchColumn();
$idemConf = 'idem-conf-' . $runSuffix;
$r2 = OrderCancellationService::cancelByCustomer($customerA, $confirmedNum, 'no_longer_needed', '', $idemConf);
okc(!empty($r2['ok']), 'Cancel confirmed paid order');
$stockAfterCancel = (int) $pdo->query('SELECT stock_qty FROM product_variants WHERE id = ' . $variantId)->fetchColumn();
okc($stockAfterCancel === $stockAfterDec + 2, 'Stock restored once after paid cancel');
$refCnt = (int) $pdo->query('SELECT COUNT(*) FROM refund_requests WHERE order_id = ' . $confirmedId)->fetchColumn();
okc($refCnt === 1, 'Online paid cancel creates refund request');
$refSt = (string) $pdo->query('SELECT status FROM refund_requests WHERE order_id = ' . $confirmedId . ' LIMIT 1')->fetchColumn();
okc($refSt === RefundStatus::REQUESTED, 'Refund stays requested (not auto-completed)');

$dup = OrderCancellationService::cancelByCustomer($customerA, $confirmedNum, 'no_longer_needed', '', $idemConf);
okc(!empty($dup['ok']) && !empty($dup['idempotent']), 'Duplicate idempotency returns ok');
$already = OrderCancellationService::cancelByCustomer($customerA, $confirmedNum, 'no_longer_needed', '', 'idem-already-' . $runSuffix);
okc(!empty($already['ok']) && !empty($already['idempotent']), 'Already cancelled order cannot be cancelled again');
okc((int) $pdo->query('SELECT COUNT(*) FROM order_stock_restock_events WHERE order_id = ' . $confirmedId)->fetchColumn() <= 1, 'Single restock event');

$packedId = mkOrder($pdo, $customerA, $variantId, OrderStatus::READY, 'paid', 'PACK');
$packedNum = (string) $pdo->query('SELECT order_number FROM orders WHERE id = ' . $packedId)->fetchColumn();
$rowPack = $pdo->query('SELECT * FROM orders WHERE id = ' . $packedId)->fetch(PDO::FETCH_ASSOC);
okc(!OrderCancellationService::eligibility($rowPack)['eligible'], 'Packed (ready) not eligible');
$badPack = OrderCancellationService::cancelByCustomer($customerA, $packedNum, 'ordered_by_mistake', '', 'idem-pack-' . $runSuffix);
okc(empty($badPack['ok']), 'Reject cancel packed order');

$dispId = mkOrder($pdo, $customerA, $variantId, OrderStatus::OUT_FOR_DELIVERY, 'paid', 'DISP');
$dispNum = (string) $pdo->query('SELECT order_number FROM orders WHERE id = ' . $dispId)->fetchColumn();
okc(empty(OrderCancellationService::cancelByCustomer($customerA, $dispNum, 'ordered_by_mistake', '', 'idem-disp-' . $runSuffix)['ok']), 'Reject dispatched');

$delId = mkOrder($pdo, $customerA, $variantId, OrderStatus::DELIVERED, 'paid', 'DEL');
$delNum = (string) $pdo->query('SELECT order_number FROM orders WHERE id = ' . $delId)->fetchColumn();
okc(empty(OrderCancellationService::cancelByCustomer($customerA, $delNum, 'ordered_by_mistake', '', 'idem-del-' . $runSuffix)['ok']), 'Reject delivered');

$procId = mkOrder($pdo, $customerA, $variantId, OrderStatus::PREPARING, 'paid', 'PROC');
$procNum = (string) $pdo->query('SELECT order_number FROM orders WHERE id = ' . $procId)->fetchColumn();
$pdo->prepare('INSERT INTO deliveries (order_id, status) VALUES (?, ?)')->execute([$procId, DeliveryStatus::PENDING_DISPATCH]);
okc(!empty(OrderCancellationService::cancelByCustomer($customerA, $procNum, 'delivery_too_slow', '', 'idem-proc-' . $runSuffix)['ok']), 'Cancel preparing before dispatch');

$proc2 = mkOrder($pdo, $customerA, $variantId, OrderStatus::PREPARING, 'paid', 'PROC2');
$proc2Num = (string) $pdo->query('SELECT order_number FROM orders WHERE id = ' . $proc2)->fetchColumn();
$pdo->prepare('INSERT INTO deliveries (order_id, status) VALUES (?, ?)')->execute([$proc2, DeliveryStatus::DISPATCHED]);
okc(empty(OrderCancellationService::cancelByCustomer($customerA, $proc2Num, 'ordered_by_mistake', '', 'idem-proc2-' . $runSuffix)['ok']), 'Reject preparing when dispatched');

$ownId = mkOrder($pdo, $customerA, $variantId, OrderStatus::CONFIRMED, 'pending', 'OWN');
$ownNum = (string) $pdo->query('SELECT order_number FROM orders WHERE id = ' . $ownId)->fetchColumn();
okc(empty(OrderCancellationService::cancelByCustomer($customerB, $ownNum, 'ordered_by_mistake', '', 'idem-other-' . $runSuffix)['ok']), 'Other customer cannot cancel');

$codId = mkOrder($pdo, $customerA, $variantId, OrderStatus::CONFIRMED, 'pending', 'COD');
$codNum = (string) $pdo->query('SELECT order_number FROM orders WHERE id = ' . $codId)->fetchColumn();
OrderCancellationService::cancelByCustomer($customerA, $codNum, 'ordered_by_mistake', '', 'idem-cod-' . $runSuffix);
$codRef = (int) $pdo->query('SELECT COUNT(*) FROM refund_requests WHERE order_id = ' . $codId)->fetchColumn();
okc($codRef === 0, 'Unpaid/COD-like cancel does not create refund request');

$stmtEvt = $pdo->prepare('SELECT reason_code FROM order_cancellation_events WHERE order_id = ?');
$stmtEvt->execute([$pendingId]);
okc((string) $stmtEvt->fetchColumn() === 'ordered_by_mistake', 'Cancellation reason recorded');

$subStatusBefore = null;
$subRow = $pdo->query('SELECT id, status FROM customer_subscriptions ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (is_array($subRow)) {
    $subStatusBefore = (string) $subRow['status'];
    $subId = (int) $subRow['id'];
    $subOrdId = mkOrder($pdo, $customerA, $variantId, OrderStatus::CONFIRMED, 'paid', 'SUB');
    $pdo->prepare('UPDATE orders SET order_source = ?, subscription_delivery_day_id = NULL WHERE id = ?')
        ->execute(['subscription', $subOrdId]);
    $subOrdNum = (string) $pdo->query('SELECT order_number FROM orders WHERE id = ' . $subOrdId)->fetchColumn();
    OrderCancellationService::cancelByCustomer($customerA, $subOrdNum, 'other', 'test sub order only', 'idem-sub-' . $subOrdId . '-' . $runSuffix);
    $subStatusAfter = (string) $pdo->query('SELECT status FROM customer_subscriptions WHERE id = ' . $subId)->fetchColumn();
    okc($subStatusBefore === $subStatusAfter, 'Subscription root status unchanged when cancelling delivery order');
}

echo $fail === 0 ? "PASS: customer order cancellation.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
