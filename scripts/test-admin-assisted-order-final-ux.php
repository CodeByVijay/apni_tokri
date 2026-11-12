<?php

declare(strict_types=1);

/**
 * Admin assisted order — final UX: slot labels, custom delivery, coupon quote, offline payment.
 *
 * php scripts/run-in-disposable-db.php scripts/test-admin-assisted-order-final-ux.php
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
function uxOk(bool $c, string $l): void
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
AppSettings::resetCache();

$superId = stage14_p1_super_admin_id($pdo);
stage14_p1_session($superId);
Permission::clearCache();

$opts = AdminAssistedOrderService::checkoutSlotOptionsForAdmin();
if ($opts !== []) {
    $label = DeliverySlotDisplay::formatAdminAssistedOption($opts[0]);
    uxOk(str_contains($label, '·'), 'slot option label includes time/date separators');
    uxOk(($opts[0]['time_range'] ?? '') !== '' || str_contains($label, '–'), 'slot option includes time range from DB');
}

$pdo->prepare('DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE customer_id IN (SELECT id FROM customers WHERE mobile = ?))')->execute(['9800777701']);
$pdo->prepare('DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE customer_id IN (SELECT id FROM customers WHERE mobile = ?))')->execute(['9800777701']);
$pdo->prepare('DELETE FROM orders WHERE customer_id IN (SELECT id FROM customers WHERE mobile = ?)')->execute(['9800777701']);
$pdo->prepare('DELETE FROM customer_addresses WHERE customer_id IN (SELECT id FROM customers WHERE mobile = ?)')->execute(['9800777701']);
$pdo->prepare('DELETE FROM customers WHERE mobile = ?')->execute(['9800777701']);

$pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?, ?, ?, 1)')
    ->execute(['9800777701', 'UX Customer', 'ux@example.invalid']);
$custId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO customer_addresses (customer_id, label, line1, city, state, pincode, is_default, receiver_name, receiver_phone)
     VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
)->execute([$custId, 'Home', 'Line 1', 'Indore', 'MP', '452001', 'Recv', '9800777701']);
$addrId = (int) $pdo->lastInsertId();
$varId = (int) ($pdo->query(
    "SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id WHERE p.slug = 'tmp-tomato' LIMIT 1"
)->fetchColumn() ?: 0);
$stockStart = (int) ($pdo->query('SELECT stock_qty FROM product_variants WHERE id = ' . $varId)->fetchColumn() ?: 0);

$tomorrow = DeliveryConfig::now()->modify('+1 day')->format('Y-m-d');
$customQuote = AdminAssistedOrderService::quote(AdminAssistedOrderService::collectPostInput([
    'customer_id' => $custId,
    'address_id' => $addrId,
    'lines' => json_encode([['variant_id' => $varId, 'quantity' => 1]], JSON_THROW_ON_ERROR),
    'delivery_schedule_mode' => 'custom',
    'custom_delivery_date' => $tomorrow,
    'custom_delivery_start' => '10:00',
    'custom_delivery_end' => '12:00',
    'custom_delivery_reason' => 'Customer agreed window',
]), true);
uxOk(!empty($customQuote['ok']), 'custom delivery quote ok');
$qCustom = $customQuote['quote'] ?? [];
uxOk(($qCustom['delivery_schedule_mode'] ?? '') === 'custom', 'quote marks custom schedule');

$badCustom = AdminAssistedOrderService::quote(AdminAssistedOrderService::collectPostInput([
    'customer_id' => $custId,
    'address_id' => $addrId,
    'lines' => json_encode([['variant_id' => $varId, 'quantity' => 1]], JSON_THROW_ON_ERROR),
    'delivery_schedule_mode' => 'custom',
    'custom_delivery_date' => $tomorrow,
    'custom_delivery_start' => '14:00',
    'custom_delivery_end' => '12:00',
    'custom_delivery_reason' => 'bad range',
]), true);
uxOk(empty($badCustom['ok']), 'reject custom end before start');

$postBase = [
    'customer_id' => $custId,
    'address_id' => $addrId,
    'lines' => json_encode([['variant_id' => $varId, 'quantity' => 2]], JSON_THROW_ON_ERROR),
    'apply_delivery_charges' => '1',
    'free_delivery' => '0',
    'delivery_slot' => 'auto',
    'delivery_date' => '',
    'payment_mode' => 'offline_paid',
    'offline_payment_method' => 'upi',
    'offline_payment_reference' => 'UTRUX123456',
    'offline_payment_date' => date('Y-m-d'),
    'offline_payment_time' => '10:30',
    'offline_payment_confirm' => '1',
    'offline_payment_verification_note' => 'Verified against bank statement',
];
$quoted = AdminAssistedOrderService::quote(AdminAssistedOrderService::collectPostInput($postBase), true);
uxOk(!empty($quoted['ok']), 'offline mode quote');
$grand = (float) (($quoted['quote']['grand_total'] ?? 0));
$postBase['offline_payment_amount'] = (string) $grand;
$postBase['place_submission_token'] = (string) ($quoted['quote']['place_submission_token'] ?? '');
$postBase['client_grand_total'] = (string) $grand;
$placed = AdminAssistedOrderService::placeOrder(AdminAssistedOrderService::collectPostInput($postBase));
uxOk(!empty($placed['ok']) && ($placed['payment_mode'] ?? '') === 'offline_paid', 'offline place ok');

$orderId = (int) ($placed['order_id'] ?? 0);
$ord = $pdo->query('SELECT * FROM orders WHERE id = ' . $orderId)->fetch(PDO::FETCH_ASSOC);
uxOk(is_array($ord) && (string) ($ord['payment_status'] ?? '') === 'paid', 'order payment_status paid');
uxOk((string) ($ord['status'] ?? '') === 'confirmed', 'order status confirmed');
$payGw = (string) ($pdo->query('SELECT gateway FROM payments WHERE order_id = ' . $orderId . ' LIMIT 1')->fetchColumn() ?: '');
uxOk($payGw === 'offline', 'offline payment gateway row');
$stockAfter = (int) ($pdo->query('SELECT stock_qty FROM product_variants WHERE id = ' . $varId)->fetchColumn() ?: 0);
uxOk($stockAfter === $stockStart - 2, 'inventory decremented once for offline paid');

$dup = AdminAssistedOrderService::placeOrder(AdminAssistedOrderService::collectPostInput($postBase));
uxOk(!empty($dup['ok']) && !empty($dup['reused']), 'offline idempotent place');
$stockDup = (int) ($pdo->query('SELECT stock_qty FROM product_variants WHERE id = ' . $varId)->fetchColumn() ?: 0);
uxOk($stockDup === $stockAfter, 'duplicate offline place does not decrement stock again');

$badAmt = $postBase;
$badAmt['offline_payment_amount'] = (string) round($grand - 10, 2);
$badAmt['place_submission_token'] = (string) (AdminAssistedOrderService::quote(AdminAssistedOrderService::collectPostInput($badAmt), true)['quote']['place_submission_token'] ?? '');
$badPlace = AdminAssistedOrderService::placeOrder(AdminAssistedOrderService::collectPostInput($badAmt));
uxOk(empty($badPlace['ok']), 'reject offline amount mismatch');

$pendingPost = [
    'customer_id' => $custId,
    'address_id' => $addrId,
    'lines' => json_encode([['variant_id' => $varId, 'quantity' => 1]], JSON_THROW_ON_ERROR),
    'apply_delivery_charges' => '1',
    'free_delivery' => '0',
    'delivery_slot' => 'auto',
    'delivery_date' => '',
    'payment_mode' => 'pending',
];
$pendingBundle = admin_assisted_quote_and_place($pendingPost);
uxOk(!empty($pendingBundle['place']['ok']), 'pending payment regression place');
$pendId = (int) ($pendingBundle['place']['order_id'] ?? 0);
$pendPay = (string) ($pdo->query('SELECT payment_status FROM orders WHERE id = ' . $pendId)->fetchColumn() ?: '');
uxOk($pendPay === 'pending', 'pending payment order still pending');

uxOk($fail === 0, 'all final UX tests counted');

exit($fail > 0 ? 1 : 0);
