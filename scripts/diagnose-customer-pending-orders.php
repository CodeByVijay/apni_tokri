<?php

declare(strict_types=1);

/**
 * Inspect duplicate / pending checkout orders for a customer (no cancellations).
 * CLI: php scripts/diagnose-customer-pending-orders.php 9407005023
 * Optional: php scripts/diagnose-customer-pending-orders.php 9407005023 VT-261108-0C3C86
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

$mobile = isset($argv[1]) ? preg_replace('/\D+/', '', (string) $argv[1]) : '';
$focusOrder = isset($argv[2]) ? trim((string) $argv[2]) : '';
if ($mobile === '' || strlen($mobile) < 10) {
    fwrite(STDERR, "Usage: php scripts/diagnose-customer-pending-orders.php <mobile> [order_number]\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$stmt = $pdo->prepare('SELECT id, name FROM customers WHERE mobile = ? LIMIT 1');
$stmt->execute([$mobile]);
$cust = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cust) {
    fwrite(STDERR, "Customer not found.\n");
    exit(1);
}
$cid = (int) $cust['id'];
echo 'Customer #' . $cid . ' ' . (string) $cust['name'] . ' (' . $mobile . ")\n";
echo 'Cashfree API: ' . PaymentGatewayConfig::cashfreeApiHostLabel() . "\n\n";

$sql = "SELECT o.id, o.order_number, o.status, o.payment_status, o.grand_total, o.checkout_nonce,
               o.placed_at, o.delivery_slot, o.delivery_date
        FROM orders o
        WHERE o.customer_id = ? AND o.status = 'pending_payment'
        ORDER BY o.id DESC";
$params = [$cid];
if ($focusOrder !== '') {
    $sql = "SELECT o.id, o.order_number, o.status, o.payment_status, o.grand_total, o.checkout_nonce,
                   o.placed_at, o.delivery_slot, o.delivery_date
            FROM orders o
            WHERE o.customer_id = ? AND o.order_number = ?
            LIMIT 1";
    $params = [$cid, $focusOrder];
}
$orders = $pdo->prepare($sql);
$orders->execute($params);

foreach ($orders->fetchAll(PDO::FETCH_ASSOC) as $order) {
    $oid = (int) $order['id'];
    $ono = (string) $order['order_number'];
    echo "=== {$ono} ===\n";
    echo '  status=' . (string) $order['status'] . ' payment_status=' . (string) $order['payment_status'];
    echo ' total=' . (string) $order['grand_total'] . ' sig=' . substr((string) ($order['checkout_nonce'] ?? ''), 0, 20) . "…\n";
    $payStmt = $pdo->prepare('SELECT id, gateway, status, amount, gateway_order_id, idempotency_key, created_at FROM payments WHERE order_id = ? ORDER BY id ASC');
    $payStmt->execute([$oid]);
    foreach ($payStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        echo '  payment #' . (int) $p['id'] . ' ' . (string) $p['gateway'] . ' ' . (string) $p['status'];
        echo ' gw_order=' . (string) ($p['gateway_order_id'] ?? '—');
        echo ' at ' . (string) $p['created_at'] . "\n";
    }
    if (CashfreeGateway::isConfigured()) {
        $fetch = CashfreeGateway::fetchOrder($ono);
        if (!empty($fetch['ok'])) {
            echo '  Cashfree PG: order_status=' . (string) ($fetch['order_status'] ?? '');
            echo ' cf_order_id=' . (string) ($fetch['cf_order_id'] ?? '');
            echo ' session=' . (trim((string) ($fetch['payment_session_id'] ?? '')) !== '' ? 'present' : 'missing') . "\n";
        } else {
            echo '  Cashfree PG fetch: ' . (string) ($fetch['error'] ?? 'failed') . "\n";
        }
    }
    echo "\n";
}

$diag = PaymentGatewayDiagnosticState::adminView('cashfree');
echo "Cashfree admin diagnostic: " . (string) ($diag['detail'] ?? '') . "\n";
if (!empty($diag['http_status'])) {
    echo 'Last init HTTP status: ' . (int) $diag['http_status'] . "\n";
}
