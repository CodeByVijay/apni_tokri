<?php

declare(strict_types=1);

/**
 * Phase 3 — guest delivery-address migration after OTP login + selected-address
 * precedence in cart/checkout resolution, against a disposable database.
 *
 * CLI: php scripts/run-in-disposable-db.php scripts/test-guest-address-migration.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/disposable_db_runner.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();
TestScriptSafety::assertSafeToMutateSettings();

$fail = 0;
function migOk(bool $c, string $l): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $l . PHP_EOL;
    if (!$c) {
        ++$fail;
    }
}

$pdo = Database::pdo();

// ---- Fixtures: two customers, one with a default address --------------------
$mobiles = ['9700111001', '9700111002'];
$in = implode(',', array_fill(0, count($mobiles), '?'));
$pdo->prepare("DELETE FROM customer_addresses WHERE customer_id IN (SELECT id FROM customers WHERE mobile IN ($in))")->execute($mobiles);
$pdo->prepare("DELETE FROM customers WHERE mobile IN ($in)")->execute($mobiles);
$pdo->prepare("INSERT INTO customers (mobile, name, email, is_active) VALUES (?, ?, NULL, 1)")->execute(['9700111001', 'Guest Migrate']);
$pdo->prepare("INSERT INTO customers (mobile, name, email, is_active) VALUES (?, ?, NULL, 1)")->execute(['9700111002', 'Has Default']);
$c1 = (int) $pdo->query("SELECT id FROM customers WHERE mobile='9700111001'")->fetchColumn();
$c2 = (int) $pdo->query("SELECT id FROM customers WHERE mobile='9700111002'")->fetchColumn();

// Serviceable pincode in seeded zones ("UI Pin Zone", prefix 4520).
$zone = '452010';
$probe = DeliveryService::evaluatePincode($zone);
migOk(!empty($probe['serviceable']), "fixture: pincode $zone is serviceable");

$guestAddr = [
    'address_type' => 'Home',
    'house_number' => '42-B',
    'building_name' => 'Shree Heights',
    'street' => 'Scheme 54',
    'area' => 'Vijay Nagar',
    'landmark' => 'Near C21 Mall',
    'city' => 'Indore',
    'state' => 'Madhya Pradesh',
    'pincode' => $zone,
    'latitude' => '22.7197',
    'longitude' => '75.8577',
    'location_confirmed' => '1',
];

function selectGuest(array $input): array
{
    $v = DeliverySessionAddress::validate($input);
    if ($v['errors'] !== []) {
        return ['__invalid' => $v['errors']];
    }
    $snap = DeliverySessionAddress::refreshServiceability($v['clean']);
    DeliverySessionAddress::store($snap);

    return $snap;
}

// ---- 1. Guest selection persists a valid snapshot ---------------------------
CustomerAuth::logout();
$snap = selectGuest($guestAddr);
migOk(!isset($snap['__invalid']), 'guest selection validates');
migOk(($snap['kind'] ?? '') === 'guest', 'snapshot kind=guest');
migOk(!empty($snap['serviceable']), "snapshot serviceable for zone pin ($zone)");
migOk(DeliverySessionAddress::selectedAddressId() === null, 'no saved id while guest');

// ---- 2. OTP login → migrate ------------------------------------------------
CustomerAuth::loginById($c1);
DeliverySessionAddress::migrateToCustomerAccount($c1);
$after = DeliverySessionAddress::current();
migOk(($after['kind'] ?? '') === 'saved', 'migrated: kind=saved');
migOk(DeliverySessionAddress::selectedAddressId() !== null, 'migrated: selectedAddressId set');
$migId = DeliverySessionAddress::selectedAddressId();
$row = $migId ? CustomerService::address($migId, $c1) : null;
migOk($row !== null && ($row['area'] ?? '') === 'Vijay Nagar', 'migrated row exists in customer_addresses');
migOk($row !== null && ($row['receiver_phone'] ?? '') === '9700111001', 'receiver phone fell back to customer mobile');
migOk($row !== null && (float) ($row['latitude'] ?? 0) > 0, 'migrated coordinates persisted');

// ---- 3. Repeated migration → no duplicate ----------------------------------
DeliverySessionAddress::migrateToCustomerAccount($c1);
$count = (int) $pdo->query("SELECT COUNT(*) FROM customer_addresses WHERE customer_id=$c1 AND archived_at IS NULL")->fetchColumn();
migOk($count === 1, 'repeated migration inserts no duplicate');

// ---- 4. Equivalent address reused, not duplicated --------------------------
CustomerAuth::logout();
DeliverySessionAddress::clear();
selectGuest($guestAddr); // same address again as guest
DeliverySessionAddress::migrateToCustomerAccount($c1);
$count = (int) $pdo->query("SELECT COUNT(*) FROM customer_addresses WHERE customer_id=$c1 AND archived_at IS NULL")->fetchColumn();
migOk($count === 1, 'equivalent guest address reuses existing row');
migOk(DeliverySessionAddress::selectedAddressId() === $migId, 'selection points at reused id');

// ---- 5. Unrelated address not treated as duplicate -------------------------
$other = $guestAddr;
$other['house_number'] = '7';
$other['street'] = 'MG Road';
$other['area'] = 'Palasia';
$other['latitude'] = '22.7244';
$other['longitude'] = '75.8839';
CustomerAuth::logout();
DeliverySessionAddress::clear();
selectGuest($other);
DeliverySessionAddress::migrateToCustomerAccount($c1);
$count = (int) $pdo->query("SELECT COUNT(*) FROM customer_addresses WHERE customer_id=$c1 AND archived_at IS NULL")->fetchColumn();
migOk($count === 2, 'unrelated address inserts as new row');
migOk(DeliverySessionAddress::selectedAddressId() !== $migId, 'selection points at the new row');

// ---- 6. Default address preserved + selection wins --------------------------
$pdo->prepare("INSERT INTO customer_addresses (customer_id, label, receiver_name, receiver_phone, line1, city, state, pincode, is_default) VALUES (?, 'Office', 'Has Default', '9700111002', 'Old Default Bldg', 'Indore', 'Madhya Pradesh', ?, 1)")
    ->execute([$c2, $zone]);
$defId = (int) $pdo->lastInsertId();
CustomerAuth::logout();
DeliverySessionAddress::clear();
$snap = selectGuest($guestAddr);
DeliverySessionAddress::migrateToCustomerAccount($c2);
$cur = DeliverySessionAddress::current();
$defNow = $pdo->query("SELECT is_default FROM customer_addresses WHERE id=$defId")->fetchColumn();
$newRow = $pdo->query("SELECT id, is_default FROM customer_addresses WHERE customer_id=$c2 AND area='Vijay Nagar'")->fetch(PDO::FETCH_ASSOC);
migOk((string) $defNow === '1', 'existing default not overwritten by migration');
migOk($newRow && (string) $newRow['is_default'] === '0', 'migrated row is not default');
migOk(DeliverySessionAddress::selectedAddressId() === (int) $newRow['id'], 'migrated address remains selected');
CustomerAuth::loginById($c2);
$ref = new ReflectionMethod(CartService::class, 'resolveCheckoutAddress');
$ref->setAccessible(true);
$resolved = $ref->invoke(null, null);
migOk($resolved !== null && (int) $resolved['id'] === (int) $newRow['id'], 'cart preview resolves selected over default');
$refPin = new ReflectionMethod(CartService::class, 'resolveDeliveryPin');
$refPin->setAccessible(true);
migOk($refPin->invoke(null) === $zone, 'cart pin resolves to selected address pin');

// ---- 7. Failed migration preserves guest snapshot ---------------------------
CustomerAuth::logout();
DeliverySessionAddress::clear();
$bad = $guestAddr;
$bad['pincode'] = '700001'; // unserviceable pincode → addAddress rejects
$badSnap = selectGuest($bad);
migOk(!isset($badSnap['__invalid']), 'unserviceable guest snapshot still stores');
DeliverySessionAddress::migrateToCustomerAccount($c1);
$still = DeliverySessionAddress::current();
migOk(($still['kind'] ?? '') === 'guest', 'failed migration keeps guest kind');
migOk(($still['area'] ?? '') === 'Vijay Nagar', 'failed migration keeps guest snapshot data');
$count = (int) $pdo->query("SELECT COUNT(*) FROM customer_addresses WHERE customer_id=$c1 AND archived_at IS NULL")->fetchColumn();
migOk($count === 2, 'failed migration inserted nothing');

// ---- 8. placeOrderDb sync: session selection follows ordered address --------
// Simulate the checkout-order line: ordered address becomes session selection.
CustomerAuth::loginById($c1);
$list = CustomerAccountService::listAddresses($c1);
$ordered = $list[1]; // the Palasia row is not currently selected
DeliverySessionAddress::store(
    DeliverySessionAddress::refreshServiceability(DeliverySessionAddress::snapshotFromSavedRow($ordered))
);
$cur = DeliverySessionAddress::current();
migOk(DeliverySessionAddress::selectedAddressId() === (int) $ordered['id'], 'order sync: selected id follows ordered address');
migOk(DeliveryContext::sessionPin() === $zone, 'order sync: session pin matches ordered pincode');
migOk(($cur['kind'] ?? '') === 'saved', 'order sync: kind=saved');

// ---- 9. Logout clears selection ---------------------------------------------
CustomerService::logout();
migOk(DeliverySessionAddress::current() === null, 'logout clears session address');
migOk(DeliveryContext::sessionPin() === null, 'logout clears session pin');

echo ($fail === 0 ? 'ALL OK' : "FAILURES: $fail") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
