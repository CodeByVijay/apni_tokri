<?php

declare(strict_types=1);

/**
 * Admin assisted order UI rules — discount exclusivity, delivery/payment mode field resolution.
 *
 * php scripts/run-in-disposable-db.php scripts/test-admin-assisted-order-ui.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/stage14_phase1_fixture.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();

$fail = 0;
function uiOk(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . PHP_EOL;
    if (!$c) {
        ++$fail;
    }
}

$pdo = Database::pdo();
$superId = stage14_p1_super_admin_id($pdo);
stage14_p1_session($superId);
Permission::clearCache();

$pdo->prepare('DELETE FROM customer_addresses WHERE customer_id IN (SELECT id FROM customers WHERE mobile = ?)')->execute(['9800888801']);
$pdo->prepare('DELETE FROM customers WHERE mobile = ?')->execute(['9800888801']);
$pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?, ?, ?, 1)')
    ->execute(['9800888801', 'UI Cust', 'ui@example.invalid']);
$custId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO customer_addresses (customer_id, label, line1, city, state, pincode, is_default, receiver_name, receiver_phone)
     VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
)->execute([$custId, 'Home', 'L1', 'Indore', 'MP', '452001', 'R', '9800888801']);
$addrId = (int) $pdo->lastInsertId();
$varId = (int) ($pdo->query(
    "SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id WHERE p.slug = 'tmp-tomato' LIMIT 1"
)->fetchColumn() ?: 0);

$base = [
    'customer_id' => $custId,
    'address_id' => $addrId,
    'lines' => json_encode([['variant_id' => $varId, 'quantity' => 1]], JSON_THROW_ON_ERROR),
    'apply_delivery_charges' => '1',
    'free_delivery' => '0',
    'delivery_slot' => 'auto',
    'delivery_date' => '',
    'discount_mode' => 'none',
];

$none = AdminAssistedOrderService::quote(AdminAssistedOrderService::collectPostInput($base), true);
uiOk(!empty($none['ok']), 'quote none discount mode');

$search = AdminAssistedOrderService::searchVariants('tomato', 1, 5);
$first = $search['items'][0] ?? [];
uiOk(isset($first['variant_id']) && (int) $first['variant_id'] > 0, 'variant search exposes variant_id');
uiOk(isset($first['product_name']) && isset($first['unit_price']), 'variant search row shape for picker UI');

$both = $base;
$both['discount_mode'] = 'coupon';
$both['coupon_code'] = 'INVALID_COUPON_XX';
$both['custom_discount_enabled'] = '1';
$both['custom_discount_type'] = 'flat';
$both['custom_discount_value'] = '10';
$both['custom_discount_reason'] = 'no';
$rejectBoth = AdminAssistedOrderService::quote(AdminAssistedOrderService::collectPostInput($both), true);
uiOk(empty($rejectBoth['ok']), 'reject coupon + custom together');

$custom = $base;
$custom['discount_mode'] = 'custom';
$custom['custom_discount_enabled'] = '1';
$custom['custom_discount_type'] = 'flat';
$custom['custom_discount_value'] = '5';
$custom['custom_discount_reason'] = 'Goodwill';
$customQ = AdminAssistedOrderService::quote(AdminAssistedOrderService::collectPostInput($custom), true);
uiOk(!empty($customQ['ok']), 'custom-only quote');
$q = $customQ['quote'] ?? [];
uiOk(($q['discount_mode'] ?? '') === 'custom', 'quote marks custom mode');

$slotOnly = $base;
$slotOnly['delivery_schedule_mode'] = 'slot';
$slotOnly['custom_delivery_date'] = date('Y-m-d', strtotime('+3 days'));
$slotOnly['custom_delivery_start'] = '09:00';
$slotOnly['custom_delivery_end'] = '11:00';
$slotOnly['custom_delivery_reason'] = 'should be ignored';
$slotQ = AdminAssistedOrderService::quote(AdminAssistedOrderService::collectPostInput($slotOnly), true);
uiOk(!empty($slotQ['ok']), 'slot mode ignores posted custom delivery fields');

$pending = $base;
$pending['payment_mode'] = 'pending';
$pending['offline_payment_method'] = 'upi';
$pending['offline_payment_reference'] = 'X';
$pending['offline_payment_confirm'] = '1';
$pending['offline_payment_verification_note'] = 'n';
$pendingQ = AdminAssistedOrderService::quote(AdminAssistedOrderService::collectPostInput($pending), true);
uiOk(!empty($pendingQ['ok']), 'pending mode quote ignores offline fields');

exit($fail > 0 ? 1 : 0);
