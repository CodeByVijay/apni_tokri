<?php

declare(strict_types=1);

/**
 * Admin assisted order placement + payment (disposable DB, simulate gateway).
 *
 * php scripts/run-in-disposable-db.php scripts/test-admin-assisted-order-placement.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/stage14_phase1_fixture.php';
require_once __DIR__ . '/lib/test_script_safety.php';
require_once __DIR__ . '/includes/admin_assisted_test_helpers.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();

$fail = 0;
function apOk(bool $c, string $l): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $l . PHP_EOL;
    if (!$c) {
        ++$fail;
    }
}

$pdo = Database::pdo();
TestScriptSafety::assertSafeToMutateSettings();
AppSettings::set('payment.test_mode', '1');
AppSettings::set('payment.active_gateway', 'simulate');
AppSettings::resetCache();

$superId = stage14_p1_super_admin_id($pdo);
stage14_p1_session($superId);
Permission::clearCache();

$pdo->prepare('DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE customer_id IN (SELECT id FROM customers WHERE mobile = ?))')->execute(['9800333301']);
$pdo->prepare('DELETE FROM orders WHERE customer_id IN (SELECT id FROM customers WHERE mobile = ?)')->execute(['9800333301']);
$pdo->prepare('DELETE FROM customer_addresses WHERE customer_id IN (SELECT id FROM customers WHERE mobile = ?)')->execute(['9800333301']);
$pdo->prepare('DELETE FROM customers WHERE mobile = ?')->execute(['9800333301']);

$pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?, ?, ?, 1)')
    ->execute(['9800333301', 'Place Customer', 'place@example.invalid']);
$custId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO customer_addresses (customer_id, label, line1, city, state, pincode, is_default, receiver_name, receiver_phone)
     VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
)->execute([$custId, 'Home', 'Line 1', 'Indore', 'MP', '452001', 'Place', '9800333301']);
$addrId = (int) $pdo->lastInsertId();
$varId = (int) ($pdo->query(
    "SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id WHERE p.slug = 'tmp-tomato' LIMIT 1"
)->fetchColumn() ?: 0);
$stockBefore = (int) ($pdo->query('SELECT stock_qty FROM product_variants WHERE id = ' . $varId)->fetchColumn() ?: 0);

$bundle = admin_assisted_quote_and_place([
    'customer_id' => $custId,
    'address_id' => $addrId,
    'lines' => json_encode([['variant_id' => $varId, 'quantity' => 1]], JSON_THROW_ON_ERROR),
    'apply_delivery_charges' => '1',
    'free_delivery' => '0',
    'delivery_slot' => 'auto',
    'delivery_date' => '',
]);
$placed = $bundle['place'] ?? [];
apOk(!empty($placed['ok']) && !empty($placed['order_number']), 'place order');

$orderId = (int) ($placed['order_id'] ?? 0);
$row = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$row->execute([$orderId]);
$order = $row->fetch(PDO::FETCH_ASSOC);
if (!is_array($order)) {
    $order = [];
}
apOk(is_array($order) && !empty($order) && (string) $order['order_source'] === 'admin_assisted', 'order_source admin_assisted');
apOk((int) ($order['created_by_admin_id'] ?? 0) === $superId, 'created_by_admin_id');
apOk((string) ($order['payment_status'] ?? '') === 'pending', 'pending payment');
apOk((string) ($order['status'] ?? '') === 'pending_payment', 'pending_payment status');

$stockAfterPlace = (int) ($pdo->query('SELECT stock_qty FROM product_variants WHERE id = ' . $varId)->fetchColumn() ?: 0);
apOk($stockAfterPlace === $stockBefore, 'no inventory decrement before payment');

$placeInput = AdminAssistedOrderService::collectPostInput([
    'customer_id' => $custId,
    'address_id' => $addrId,
    'lines' => json_encode([['variant_id' => $varId, 'quantity' => 1]], JSON_THROW_ON_ERROR),
    'apply_delivery_charges' => '1',
    'free_delivery' => '0',
    'delivery_slot' => 'auto',
    'delivery_date' => '',
    'place_submission_token' => (string) ($bundle['quote']['place_submission_token'] ?? ''),
    'client_grand_total' => (string) ($bundle['quote']['grand_total'] ?? '0'),
]);
$dup = AdminAssistedOrderService::placeOrder($placeInput);
apOk(!empty($dup['ok']) && !empty($dup['reused']), 'idempotent duplicate place');

$payUrl = !empty($order['order_number'])
    ? OrderEmailContext::customerPaymentRetryUrl((string) $order['order_number'])
    : '';
apOk($payUrl !== '' && str_contains($payUrl, 'payment.php'), 'customer payment url');

$resend = AdminAssistedOrderService::resendPendingPaymentEmail($orderId);
apOk(!empty($resend['ok']), 'resend payment email queues');

$tamper = $placeInput;
$tamper['client_grand_total'] = '0.01';
$badTotal = AdminAssistedOrderService::placeOrder(AdminAssistedOrderService::collectPostInput($tamper));
apOk(empty($badTotal['ok']), 'grand total tampering rejected');

$pdo->prepare('UPDATE customers SET is_active = 0 WHERE id = ?')->execute([$custId]);
$inactive = AdminAssistedOrderService::placeOrder($placeInput);
apOk(empty($inactive['ok']), 'inactive customer rejected');
$pdo->prepare('UPDATE customers SET is_active = 1 WHERE id = ?')->execute([$custId]);

$omId = stage14_p1_create_user_with_role($pdo, 'at-apl-om@example.invalid', 'order_manager');
stage14_p1_session($omId);
Permission::clearCache();
$pdo->prepare('DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE customer_id = ?)')->execute([$custId]);
$pdo->prepare('DELETE FROM orders WHERE customer_id = ?')->execute([$custId]);
$omPlace = admin_assisted_quote_and_place([
    'customer_id' => $custId,
    'address_id' => $addrId,
    'lines' => json_encode([['variant_id' => $varId, 'quantity' => 1]], JSON_THROW_ON_ERROR),
    'apply_delivery_charges' => '1',
    'free_delivery' => '0',
    'delivery_slot' => 'auto',
    'delivery_date' => '',
])['place'] ?? [];
apOk(!empty($omPlace['ok']) && !empty($omPlace['order_number']), 'order_manager can place');
stage14_p1_session($superId);
Permission::clearCache();

$financeId = stage14_p1_create_user_with_role($pdo, 'at-apl-finance@example.invalid', 'finance');
stage14_p1_session($financeId);
Permission::clearCache();
apOk(empty(AdminAssistedOrderService::resendPendingPaymentEmail($orderId)['ok']), 'finance cannot resend');

stage14_p1_clear_session();
AppSettings::set('payment.test_mode', '0');
AppSettings::resetCache();

echo $fail === 0 ? "All assisted-order placement tests passed.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
