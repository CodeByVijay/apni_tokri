<?php

declare(strict_types=1);

/**
 * Admin assisted order — Phase 1 (disposable DB + migration 1.40.0).
 *
 * php scripts/run-in-disposable-db.php --migrate-admin-assisted scripts/test-admin-assisted-order.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/stage14_phase1_fixture.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();

$fail = 0;

function aoOk(bool $cond, string $label): void
{
    global $fail;
    echo ($cond ? 'OK' : 'FAIL') . ': ' . $label . PHP_EOL;
    if (!$cond) {
        ++$fail;
    }
}

$pdo = Database::pdo();

aoOk(AdminAssistedOrderService::columnsReady(), 'migration columns present');

$permStmt = $pdo->prepare('SELECT id FROM admin_permissions WHERE slug = ? LIMIT 1');
$permStmt->execute([AdminAssistedOrderService::PERMISSION]);
$permId = (int) ($permStmt->fetchColumn() ?: 0);
aoOk($permId > 0, 'orders.create_on_behalf permission seeded');

$omRole = (int) ($pdo->query("SELECT id FROM admin_roles WHERE slug = 'order_manager' LIMIT 1")->fetchColumn() ?: 0);
$omHas = false;
if ($omRole > 0 && $permId > 0) {
    $chk = $pdo->prepare('SELECT 1 FROM admin_role_permissions WHERE role_id = ? AND permission_id = ? LIMIT 1');
    $chk->execute([$omRole, $permId]);
    $omHas = (bool) $chk->fetchColumn();
}
aoOk($omHas, 'order_manager has create_on_behalf');

$financeId = stage14_p1_create_user_with_role($pdo, 'at-aorder-finance@apnitokri.local', 'finance');
$superId = stage14_p1_super_admin_id($pdo);

// Customers: active, inactive, addresses
$pdo->prepare('DELETE FROM customer_addresses WHERE customer_id IN (SELECT id FROM customers WHERE mobile IN (?, ?, ?))')
    ->execute(['9800111101', '9800111102', '9800111103']);
$pdo->prepare('DELETE FROM customers WHERE mobile IN (?, ?, ?)')->execute(['9800111101', '9800111102', '9800111103']);
$pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?, ?, ?, 1)')
    ->execute(['9800111101', 'Assisted Active', 'active-assisted@example.invalid']);
$activeId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?, ?, ?, 0)')
    ->execute(['9800111102', 'Assisted Inactive', 'inactive-assisted@example.invalid']);
$inactiveId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?, ?, ?, 1)')
    ->execute(['9800111103', 'Other Customer', 'other-assisted@example.invalid']);
$otherId = (int) $pdo->lastInsertId();

$pdo->prepare(
    'INSERT INTO customer_addresses (customer_id, label, line1, city, state, pincode, is_default, receiver_name, receiver_phone)
     VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
)->execute([$activeId, 'Home', 'Line 1', 'Indore', 'MP', '452001', 'Active User', '9800111101']);
$addr1 = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO customer_addresses (customer_id, label, line1, city, state, pincode, is_default, receiver_name, receiver_phone)
     VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)'
)->execute([$activeId, 'Office', 'Line 2', 'Indore', 'MP', '452002', 'Office', '9800111101']);
$addr2 = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO customer_addresses (customer_id, label, line1, city, state, pincode, is_default)
     VALUES (?, ?, ?, ?, ?, ?, 1)'
)->execute([$otherId, 'Other', 'X', 'Indore', 'MP', '452001']);
$otherAddr = (int) $pdo->lastInsertId();

$short = AdminAssistedOrderService::searchCustomers('a', 1, 20);
aoOk($short['total'] === 0 && $short['items'] === [], 'customer search rejects < 2 characters');

$byName = AdminAssistedOrderService::searchCustomers('Assisted', 1, 20);
aoOk($byName['total'] >= 2, 'customer search by name');
$foundInactive = false;
$inactiveSelectable = true;
foreach ($byName['items'] as $row) {
    if ((int) $row['id'] === $inactiveId) {
        $foundInactive = true;
        $inactiveSelectable = !empty($row['selectable']);
    }
}
aoOk($foundInactive, 'inactive customer appears in search results');
aoOk(!$inactiveSelectable, 'inactive customer not selectable');

$byMobile = AdminAssistedOrderService::searchCustomers('9800111101', 1, 20);
aoOk(count($byMobile['items']) >= 1 && (int) $byMobile['items'][0]['id'] === $activeId, 'customer search by mobile');

$addrs = AdminAssistedOrderService::addressesForCustomer($activeId);
aoOk(!empty($addrs['ok']) && count($addrs['items'] ?? []) === 2, 'multiple saved addresses');

$badAddr = AdminAssistedOrderService::validateAddressOwnership($activeId, $otherAddr);
aoOk(empty($badAddr['ok']), 'address ownership rejected for wrong customer');

$goodAddr = AdminAssistedOrderService::validateAddressOwnership($activeId, $addr1);
aoOk(!empty($goodAddr['ok']), 'address ownership accepted');

$inactiveAddr = AdminAssistedOrderService::addressesForCustomer($inactiveId);
aoOk(empty($inactiveAddr['ok']), 'addresses blocked for inactive customer');

// Variant from disposable seed (Tomato etc.)
$varId = (int) ($pdo->query(
    "SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id
     WHERE p.slug = 'tmp-tomato' LIMIT 1"
)->fetchColumn() ?: 0);
aoOk($varId > 0, 'seed variant exists');

$varSearch = AdminAssistedOrderService::searchVariants('Tomato', 1, 20);
aoOk($varSearch['total'] >= 1, 'variant search by product name');
$hasStock = false;
foreach ($varSearch['items'] as $it) {
    if ((int) ($it['variant_id'] ?? 0) === $varId) {
        $hasStock = ($it['available_qty'] ?? 0) > 0;
    }
}
aoOk($hasStock, 'variant search shows available stock');

$line = AdminAssistedOrderService::validateLineItem($varId, 2, $activeId);
aoOk(!empty($line['ok']) && (float) ($line['line']['line_total'] ?? 0) > 0, 'valid line item');

$lineInactive = AdminAssistedOrderService::validateLineItem($varId, 1, $inactiveId);
aoOk(empty($lineInactive['ok']), 'line item rejected for inactive customer');

$lineBad = AdminAssistedOrderService::validateLineItem(999999999, 1, $activeId);
aoOk(empty($lineBad['ok']), 'invalid variant rejected');

$pdo->prepare('UPDATE product_variants SET stock_qty = 0 WHERE id = ?')->execute([$varId]);
$lineOos = AdminAssistedOrderService::validateLineItem($varId, 1, $activeId);
aoOk(empty($lineOos['ok']), 'insufficient stock rejected');
$pdo->prepare('UPDATE product_variants SET stock_qty = 1000 WHERE id = ?')->execute([$varId]);

// RBAC
stage14_p1_session($financeId, 'at-aorder-finance@apnitokri.local', 'Finance');
Permission::clearCache();
aoOk(!AdminAssistedOrderService::canCreateOnBehalf(), 'finance role cannot create on behalf');

stage14_p1_session($superId, 'admin@apnitokri.local', 'Super Admin');
Permission::clearCache();
aoOk(AdminAssistedOrderService::canCreateOnBehalf(), 'super_admin can create on behalf');

$_SESSION[Csrf::fieldName()] = 'good-token';
$_POST = [Csrf::fieldName() => 'bad-token'];
$_SERVER['REQUEST_METHOD'] = 'POST';
aoOk(!Csrf::validateRequest(), 'CSRF rejects mismatched token');
unset($_POST[Csrf::fieldName()]);
$_SERVER['REQUEST_METHOD'] = 'GET';

// Regression: existing orders unchanged storefront source
$sfCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM orders WHERE order_source = 'storefront' OR order_source = '' OR order_source IS NULL"
)->fetchColumn();
aoOk($sfCount >= 0, 'orders table readable after migration');

$colNull = $pdo->query(
    'SELECT COUNT(*) FROM orders WHERE created_by_admin_id IS NOT NULL'
)->fetchColumn();
aoOk((int) $colNull === 0, 'existing orders have null created_by_admin_id');

stage14_p1_clear_session();

echo $fail === 0 ? "All admin-assisted-order Phase 1 checks passed.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
