<?php

declare(strict_types=1);

/**
 * Admin status → cancelled must auto-create cancellation refund request (no gateway call).
 * CLI: php scripts/test-admin-cancel-refund-request.php
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

function oka(bool $c, string $m): void
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

$variantId = (int) ($pdo->query(
    'SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id WHERE v.is_active = 1 LIMIT 1'
)->fetchColumn() ?: 0);
oka($variantId > 0, 'Catalogue variant');

$pdo->prepare('INSERT INTO customers (mobile, name, email) VALUES (?, ?, ?)')->execute([
    '98' . substr($tag, 0, 8),
    'Admin Cancel Test',
    'admincancel+' . $tag . '@example.test',
]);
$custId = (int) $pdo->lastInsertId();

$orderNum = 'AT-ADM-CN-' . strtoupper($tag);
$pdo->prepare(
    'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, delivery_slot, placed_at)
     VALUES (?, ?, ?, 5, 0, 0, 5, ?, ?, NOW())'
)->execute([$orderNum, $custId, OrderStatus::CONFIRMED, 'paid', 'morning']);
$orderId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, quantity, unit_price, line_total)
     VALUES (?, ?, ?, ?, 1, 5, 5)'
)->execute([$orderId, $variantId, 'Test', '500g']);
$pdo->prepare(
    "INSERT INTO payments (order_id, gateway, gateway_payment_id, amount, currency, status, idempotency_key, captured_at)
     VALUES (?, 'cashfree', ?, 5, 'INR', 'captured', ?, NOW())"
)->execute([$orderId, 'cf_' . $tag, 'pay_adm_' . $tag]);

$upd = AdminOrderService::updateStatus($orderId, OrderStatus::CANCELLED, 'Unavailable stock');
oka(!empty($upd['ok']), 'Admin marks order cancelled');

$reqCnt = (int) $pdo->query(
    "SELECT COUNT(*) FROM refund_requests WHERE order_id = {$orderId} AND reason_code = 'cancellation'"
)->fetchColumn();
oka($reqCnt === 1, 'Single cancellation refund request created');

$reqId = (int) $pdo->query(
    "SELECT id FROM refund_requests WHERE order_id = {$orderId} ORDER BY id DESC LIMIT 1"
)->fetchColumn();
$reqSt = (string) $pdo->query("SELECT status FROM refund_requests WHERE id = {$reqId}")->fetchColumn();
oka($reqSt === RefundStatus::REQUESTED, 'Refund request status requested (not gateway-refunded)');

$itemSum = (float) $pdo->query(
    'SELECT COALESCE(SUM(amount_inr), 0) FROM refund_request_items WHERE refund_request_id = ' . $reqId
)->fetchColumn();
oka(abs($itemSum - 5.0) < 0.02, 'Refund line items total remaining refundable ₹5');

$gwCnt = (int) $pdo->query(
    'SELECT COUNT(*) FROM payment_refunds WHERE payment_id IN (SELECT id FROM payments WHERE order_id = ' . $orderId . ')'
)->fetchColumn();
oka($gwCnt === 0, 'No gateway refund row on auto-create');

$ev = OrderCancellationService::cancellationEventForOrder($orderId);
oka($ev !== null && ($ev['requested_by'] ?? '') === 'admin', 'Admin cancellation event recorded');

$block = RefundRequestService::adminOrderCancellationRefundBlock($orderId);
oka($block !== null && !empty($block['needs_approval']), 'Order detail block shows approval required');

$dupUpd = AdminOrderService::updateStatus($orderId, OrderStatus::CANCELLED, 'Unavailable stock again');
oka(
    (int) $pdo->query("SELECT COUNT(*) FROM refund_requests WHERE order_id = {$orderId}")->fetchColumn() === 1,
    'Duplicate admin cancel does not duplicate refund request'
);

// Unpaid order — no refund request
$unpaidNum = 'AT-ADM-U-' . strtoupper($tag);
$pdo->prepare(
    'INSERT INTO orders (order_number, customer_id, status, subtotal, grand_total, payment_status, delivery_slot)
     VALUES (?, ?, ?, 5, 5, ?, ?)'
)->execute([$unpaidNum, $custId, OrderStatus::PENDING_PAYMENT, 'pending', 'morning']);
$unpaidId = (int) $pdo->lastInsertId();
AdminOrderService::updateStatus($unpaidId, OrderStatus::CANCELLED, 'Stock');
oka(
    (int) $pdo->query("SELECT COUNT(*) FROM refund_requests WHERE order_id = {$unpaidId}")->fetchColumn() === 0,
    'Unpaid admin cancel does not create refund request'
);

// Partial prior refund reduces available
$partNum = 'AT-ADM-P-' . strtoupper($tag);
$pdo->prepare(
    'INSERT INTO orders (order_number, customer_id, status, subtotal, grand_total, payment_status, delivery_slot, placed_at)
     VALUES (?, ?, ?, 10, 10, ?, ?, NOW())'
)->execute([$partNum, $custId, OrderStatus::CONFIRMED, 'paid', 'morning']);
$partId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, quantity, unit_price, line_total)
     VALUES (?, ?, ?, ?, 1, 10, 10)'
)->execute([$partId, $variantId, 'Test', '1kg']);
$pdo->prepare(
    "INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key, captured_at, amount_refunded_inr)
     VALUES (?, 'cashfree', 10, 'INR', 'captured', ?, NOW(), 3)"
)->execute([$partId, 'pay_part_' . $tag]);
$available = RefundRequestService::availableRefundAmountForOrder($partId);
oka(abs($available - 7.0) < 0.02, 'Remaining refundable after partial payment refund is ₹7');
AdminOrderService::updateStatus($partId, OrderStatus::CANCELLED, 'Stock');
$partReqSum = (float) $pdo->query(
    'SELECT COALESCE(SUM(amount_inr), 0) FROM refund_request_items ri
     INNER JOIN refund_requests rr ON rr.id = ri.refund_request_id WHERE rr.order_id = ' . $partId
)->fetchColumn();
oka(abs($partReqSum - 7.0) < 0.02, 'Auto refund request respects remaining refundable amount');

$pdo->exec("DELETE FROM refund_request_items WHERE refund_request_id IN (SELECT id FROM refund_requests WHERE order_id IN ({$orderId}, {$unpaidId}, {$partId}))");
$pdo->exec("DELETE FROM refund_requests WHERE order_id IN ({$orderId}, {$unpaidId}, {$partId})");
$pdo->exec("DELETE FROM order_cancellation_events WHERE order_id IN ({$orderId}, {$unpaidId}, {$partId})");
$pdo->exec('DELETE FROM payments WHERE order_id IN (' . $orderId . ', ' . $unpaidId . ', ' . $partId . ')');
$pdo->exec('DELETE FROM order_items WHERE order_id IN (' . $orderId . ', ' . $unpaidId . ', ' . $partId . ')');
$pdo->exec('DELETE FROM orders WHERE id IN (' . $orderId . ', ' . $unpaidId . ', ' . $partId . ')');
$pdo->exec('DELETE FROM customers WHERE id = ' . $custId);

echo $fail === 0 ? "PASS: admin cancel refund request.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
