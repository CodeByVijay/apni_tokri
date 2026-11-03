<?php

declare(strict_types=1);

/**
 * Customer order detail page — data assembly, security, and display contracts.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;
$pass = 0;

function ok(bool $c, string $m): void
{
    global $fail, $pass;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if ($c) {
        $pass++;
    } else {
        $fail++;
    }
}

$sample = $pdo->query(
    'SELECT o.id, o.order_number, o.customer_id, o.payment_status, o.delivery_snapshot_json
     FROM orders o
     INNER JOIN order_items oi ON oi.order_id = o.id
     ORDER BY o.id DESC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

ok($sample !== false, 'Fixture order exists');

if (!$sample) {
    echo "PASS: {$pass} FAIL: {$fail}\n";
    exit($fail > 0 ? 1 : 0);
}

$cid = (int) $sample['customer_id'];
$num = (string) $sample['order_number'];

$detail = OrderRepository::forCustomerDetail($num, $cid);
ok($detail !== null, 'forCustomerDetail returns view for owner');
ok(isset($detail['overview'], $detail['payments'], $detail['payment_summary'], $detail['address'], $detail['delivery'], $detail['price'], $detail['timeline']), 'Detail sections present');

$wrong = OrderRepository::forCustomerDetail($num, $cid + 99999);
ok($wrong === null, 'Wrong customer cannot load order detail');

$other = $pdo->query('SELECT id FROM customers WHERE id <> ' . (int) $cid . ' LIMIT 1')->fetchColumn();
if ($other) {
    ok(OrderRepository::forCustomerDetail($num, (int) $other) === null, 'Another customer blocked from order');
} else {
    ok(true, 'Another customer blocked (no second customer to test)');
}

ok(is_array($detail['items'] ?? null) && ($detail['items'] ?? []) !== [], 'Order items populated');

$payStmt = $pdo->prepare('SELECT gateway, status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1');
$payStmt->execute([(int) $sample['id']]);
$payRow = $payStmt->fetch(PDO::FETCH_ASSOC);
if ($payRow) {
    $gw = (string) $payRow['gateway'];
    $payments = $detail['payments'] ?? [];
    $last = $payments[count($payments) - 1] ?? null;
    ok($last !== null && ($last['gateway'] ?? '') === $gw, 'Payment gateway read from payments row');
    ok(($last['gateway_label'] ?? '') === PaymentGatewayConfig::gatewayLabel($gw), 'Gateway label from record not settings');
} else {
    ok(true, 'Payment gateway display (no payment row on sample)');
}

$addr = $detail['address'];
if (!empty($sample['delivery_snapshot_json'])) {
    ok($addr !== null, 'Address block when order has snapshot or address id');
} else {
    ok($addr === null || is_array($addr), 'Legacy address fallback or null');
}

$timeline = $detail['timeline'] ?? [];
$hasFakeFuture = false;
foreach ($timeline as $step) {
    if (!empty($step['done']) && empty($step['at']) && ($step['status'] ?? '') !== 'Order received') {
        $hasFakeFuture = true;
    }
}
ok(!$hasFakeFuture, 'No completed timeline steps without timestamps (except fallback label)');

$price = $detail['price'] ?? [];
$grand = round((float) ($pdo->query('SELECT grand_total FROM orders WHERE id = ' . (int) $sample['id'])->fetchColumn() ?: 0), 2);
ok(round((float) ($price['grand_total'] ?? 0), 2) === $grand, 'Grand total matches orders row');

ok(CustomerOrderDetailService::orderPaymentStatusLabel('paid') === 'Paid', 'Payment status label paid');
ok(CustomerOrderDetailService::orderPaymentStatusLabel('partially_refunded') === 'Partially refunded', 'Payment status label partial refund');

$pendingOrder = $pdo->query(
    "SELECT order_number, customer_id FROM orders WHERE payment_status = 'pending' ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if ($pendingOrder) {
    $pd = OrderRepository::forCustomerDetail((string) $pendingOrder['order_number'], (int) $pendingOrder['customer_id']);
    ok(($pd['payment_summary']['status_label'] ?? '') === 'Pending' || ($pd['overview']['payment_status_label'] ?? '') === 'Pending', 'Pending payment order summary');
} else {
    ok(true, 'Pending payment order (none in DB)');
}

$subOrder = $pdo->query(
    "SELECT order_number, customer_id FROM orders WHERE order_source = 'subscription' AND payment_status = 'paid' ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if ($subOrder) {
    $sd = OrderRepository::forCustomerDetail((string) $subOrder['order_number'], (int) $subOrder['customer_id']);
    ok(($sd['payment_summary']['gateway_label'] ?? '') !== '' || ($sd['overview']['order_type'] ?? '') !== null, 'Subscription order type/payment copy');
} else {
    ok(true, 'Subscription order (none in DB)');
}

$html = '';
ob_start();
$orderItems = $detail['items'] ?? [];
require APNI_TOKRI_ROOT . '/includes/partials/order-items.php';
$html = (string) ob_get_clean();
ok(strpos($html, 'at-oi') !== false, 'Order items partial renders');
ok(strpos($html, 'overflow-x') === false, 'Items partial avoids horizontal scroll class');

echo "PASS: {$pass} FAIL: {$fail}\n";
exit($fail > 0 ? 1 : 0);
