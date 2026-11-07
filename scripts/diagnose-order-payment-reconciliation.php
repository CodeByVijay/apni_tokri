<?php

declare(strict_types=1);

/**
 * Read-only: payment + fulfilment reconciliation state for an order (CLI).
 * Usage: php scripts/diagnose-order-payment-reconciliation.php AT-261107-8B815D
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$orderNumber = $argv[1] ?? '';
if ($orderNumber === '') {
    fwrite(STDERR, "Usage: php scripts/diagnose-order-payment-reconciliation.php <order_number>\n");
    exit(1);
}
if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}
Install::ensureConnection();
$pdo = Database::pdo();

$order = $pdo->prepare('SELECT * FROM orders WHERE order_number = ? LIMIT 1');
$order->execute([$orderNumber]);
$orderRow = $order->fetch(PDO::FETCH_ASSOC);
if (!$orderRow) {
    fwrite(STDERR, "Order not found: $orderNumber\n");
    exit(1);
}
$orderId = (int) $orderRow['id'];
echo "Order: $orderNumber (id=$orderId)\n";
echo "status=" . ($orderRow['status'] ?? '') . " payment_status=" . ($orderRow['payment_status'] ?? '') . "\n";
echo "grand_total=" . ($orderRow['grand_total'] ?? '') . "\n\n";

$payments = $pdo->prepare('SELECT id, gateway, status, amount, idempotency_key, gateway_payment_id, gateway_order_id, captured_at, updated_at FROM payments WHERE order_id = ? ORDER BY id ASC');
$payments->execute([$orderId]);
foreach ($payments->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
    echo "Payment #{$p['id']} {$p['gateway']} status={$p['status']} amt={$p['amount']} idem={$p['idempotency_key']}\n";
    echo "  gw_payment_id={$p['gateway_payment_id']} gw_order_id={$p['gateway_order_id']}\n";
}

$ex = PaymentCompletionExceptionService::openForOrderId($orderId);
if ($ex) {
    echo "\nOPEN payment_completion_exception #{$ex['id']}\n";
    echo "  category=" . ($ex['failure_category'] ?? 'n/a') . "\n";
    echo "  reason=" . ($ex['failure_reason'] ?? '') . "\n";
    echo "  gateway_payment_id=" . ($ex['gateway_payment_id'] ?? '') . "\n";
    echo "  captured_amount_inr=" . ($ex['captured_amount_inr'] ?? '') . "\n";
    echo "  created_at=" . ($ex['created_at'] ?? '') . "\n";
} else {
    echo "\nNo open payment_completion_exception.\n";
}

$ctx = PaymentService::storefrontFulfilmentReconciliationContext($orderId);
echo "\nstorefrontFulfilmentReconciliationContext: " . ($ctx === null ? 'null' : json_encode($ctx, JSON_UNESCAPED_UNICODE)) . "\n";

$hist = $pdo->prepare('SELECT status, note, created_at FROM order_status_history WHERE order_id = ? ORDER BY id DESC LIMIT 5');
$hist->execute([$orderId]);
echo "\nRecent order_status_history:\n";
foreach ($hist->fetchAll(PDO::FETCH_ASSOC) ?: [] as $h) {
    echo '  [' . ($h['created_at'] ?? '') . '] ' . ($h['status'] ?? '') . ' — ' . ($h['note'] ?? '') . "\n";
}

exit(0);
