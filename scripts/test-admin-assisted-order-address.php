<?php

declare(strict_types=1);

/**
 * Admin assisted order — create address on behalf of customer.
 *
 * php scripts/run-in-disposable-db.php scripts/test-admin-assisted-order-address.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/stage14_phase1_fixture.php';
require_once __DIR__ . '/includes/admin_assisted_test_helpers.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();

$fail = 0;
function aaOk(bool $c, string $l): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $l . PHP_EOL;
    if (!$c) {
        ++$fail;
    }
}

$pdo = Database::pdo();
$superId = stage14_p1_super_admin_id($pdo);
stage14_p1_session($superId);
Permission::clearCache();

$pdo->prepare('DELETE FROM customer_addresses WHERE customer_id IN (SELECT id FROM customers WHERE mobile IN (?, ?, ?))')
    ->execute(['9800666601', '9800666602', '9800666603']);
$pdo->prepare('DELETE FROM customers WHERE mobile IN (?, ?, ?)')->execute(['9800666601', '9800666602', '9800666603']);

$pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?, ?, ?, 1)')
    ->execute(['9800666601', 'Addr With', 'addrwith@example.invalid']);
$withId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO customer_addresses (customer_id, label, line1, city, state, pincode, is_default, receiver_name, receiver_phone)
     VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
)->execute([$withId, 'Home', 'Existing Line', 'Indore', 'MP', '452001', 'Existing', '9800666601']);

$pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?, ?, ?, 1)')
    ->execute(['9800666602', 'Addr Without', 'addrwithout@example.invalid']);
$withoutId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?, ?, ?, 0)')
    ->execute(['9800666603', 'Addr Inactive', 'addrinact@example.invalid']);
$inactiveId = (int) $pdo->lastInsertId();

$listWith = AdminAssistedOrderService::addressesForCustomer($withId);
aaOk(!empty($listWith['ok']) && count($listWith['items'] ?? []) === 1, 'customer with existing address');

$listWithout = AdminAssistedOrderService::addressesForCustomer($withoutId);
aaOk(!empty($listWithout['ok']) && ($listWithout['items'] ?? []) === [], 'customer without addresses');

$validPost = [
    'house_number' => '12',
    'building_name' => 'Green Apartments',
    'street' => 'MG Road',
    'area' => 'South Tukoganj',
    'addr_city' => 'Indore',
    'addr_state' => 'Madhya Pradesh',
    'pincode' => '452001',
    'receiver_name' => 'New Receiver',
    'receiver_phone' => '9800666602',
    'label_type' => 'Home',
    'address_submission_key' => bin2hex(random_bytes(16)),
];

$created = AdminAssistedOrderService::createAddressForCustomer($withoutId, $validPost);
aaOk(!empty($created['ok']) && !empty($created['address']['id']), 'create address for customer without saved addresses');
$newAddrId = (int) ($created['address']['id'] ?? 0);

$dup = AdminAssistedOrderService::createAddressForCustomer($withoutId, $validPost);
aaOk(!empty($dup['ok']) && !empty($dup['reused']) && (int) ($dup['address']['id'] ?? 0) === $newAddrId, 'duplicate submission reuses address');

$defaultFlag = (int) $pdo->query('SELECT is_default FROM customer_addresses WHERE id = ' . $newAddrId)->fetchColumn();
aaOk($defaultFlag === 1, 'first address becomes default');

$badPin = $validPost;
$badPin['pincode'] = '12345';
$badPin['address_submission_key'] = bin2hex(random_bytes(16));
$bad = AdminAssistedOrderService::createAddressForCustomer($withoutId, $badPin);
aaOk(empty($bad['ok']), 'invalid pincode rejected');

$secondPost = $validPost;
$secondPost['house_number'] = '99';
$secondPost['make_default'] = '0';
$secondPost['address_submission_key'] = bin2hex(random_bytes(16));
$second = AdminAssistedOrderService::createAddressForCustomer($withoutId, $secondPost);
aaOk(!empty($second['ok']), 'second address without make default');
$firstStillDefault = (int) $pdo->query(
    'SELECT is_default FROM customer_addresses WHERE customer_id = ' . $withoutId . ' AND id = ' . $newAddrId
)->fetchColumn();
aaOk($firstStillDefault === 1, 'default preserved when not requested');

$explicitDefault = $secondPost;
$explicitDefault['make_default'] = '1';
// Intentionally re-saving a similar address: bypass the advisory duplicate warning.
$explicitDefault['allow_duplicate'] = '1';
$explicitDefault['address_submission_key'] = bin2hex(random_bytes(16));
$third = AdminAssistedOrderService::createAddressForCustomer($withoutId, $explicitDefault);
$thirdId = (int) ($third['address']['id'] ?? 0);
$newDefault = (int) $pdo->query('SELECT is_default FROM customer_addresses WHERE id = ' . $thirdId)->fetchColumn();
aaOk($newDefault === 1, 'explicit make_default updates default');

$inactive = AdminAssistedOrderService::createAddressForCustomer($inactiveId, $validPost);
aaOk(empty($inactive['ok']), 'inactive customer blocked');

$quotePost = [
    'customer_id' => $withoutId,
    'address_id' => $newAddrId,
    'lines' => json_encode([['variant_id' => (int) ($pdo->query(
        "SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id WHERE p.slug = 'tmp-tomato' LIMIT 1"
    )->fetchColumn() ?: 0), 'quantity' => 1]], JSON_THROW_ON_ERROR),
    'apply_delivery_charges' => '1',
    'delivery_slot' => 'auto',
];
$quoted = AdminAssistedOrderService::quote(AdminAssistedOrderService::collectPostInput($quotePost));
aaOk(!empty($quoted['ok']), 'delivery quote after new address');

$auditTable = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_audit_logs'"
)->fetchColumn();
if ($auditTable > 0) {
    $audit = (int) $pdo->query(
        "SELECT COUNT(*) FROM admin_audit_logs WHERE action = 'customer.address_assisted_create' AND entity_id = "
        . $pdo->quote((string) $newAddrId)
    )->fetchColumn();
    aaOk($audit >= 1, 'audit logged for address create');
} else {
    aaOk(true, 'audit logged for address create (admin_audit_logs N/A)');
}

$financeId = stage14_p1_create_user_with_role($pdo, 'at-addr-fin@example.invalid', 'finance');
stage14_p1_session($financeId);
Permission::clearCache();
aaOk(empty(AdminAssistedOrderService::createAddressForCustomer($withoutId, $validPost)['ok']), 'finance cannot create address');
stage14_p1_session($superId);

$stillListed = CustomerAccountService::listAddresses($withoutId);
aaOk(count($stillListed) >= 2, 'addresses visible via CustomerAccountService');

stage14_p1_clear_session();
echo $fail === 0 ? "All admin-assisted address tests passed.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
