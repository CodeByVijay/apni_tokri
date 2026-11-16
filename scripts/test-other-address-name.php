<?php

declare(strict_types=1);

/** Temporary check: "Other" custom address name through validate → migrate. */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/disposable_db_runner.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();
TestScriptSafety::assertSafeToMutateSettings();

$fail = 0;
function nmOk(bool $c, string $l): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $l . PHP_EOL;
    if (!$c) {
        ++$fail;
    }
}

$pdo = Database::pdo();
$pdo->exec("DELETE FROM customer_addresses WHERE customer_id IN (SELECT id FROM customers WHERE mobile='9700333001')");
$pdo->exec("DELETE FROM customers WHERE mobile='9700333001'");
$pdo->exec("INSERT INTO customers (mobile, name, is_active) VALUES ('9700333001','Other Name',1)");
$custId = (int) $pdo->query("SELECT id FROM customers WHERE mobile='9700333001'")->fetchColumn();

$input = [
    'address_type' => 'Other',
    'address_name' => "Parents' Home",
    'house_number' => '7',
    'street' => 'Scheme 54',
    'area' => 'Vijay Nagar',
    'city' => 'Indore',
    'state' => 'Madhya Pradesh',
    'pincode' => '452010',
    'latitude' => '',
    'longitude' => '',
    'location_confirmed' => '0',
];

$v = DeliverySessionAddress::validate($input);
nmOk($v['errors'] === [], 'Other+name validates: ' . json_encode($v['errors']));
nmOk(($v['clean']['label'] ?? '') === "Parents' Home", 'clean label is custom name');
nmOk(($v['clean']['address_type'] ?? '') === 'Other', 'clean address_type=Other');
nmOk(($v['clean']['address_name'] ?? '') === "Parents' Home", 'clean address_name kept');

// missing name must fail
$v2 = DeliverySessionAddress::validate(array_merge($input, ['address_name' => '']));
nmOk(isset($v2['errors']['address_name']), 'Other without name rejected');

// Home ignores stray address_name
$v3 = DeliverySessionAddress::validate(array_merge($input, ['address_type' => 'Home']));
nmOk($v3['errors'] === [] && ($v3['clean']['label'] ?? '') === 'Home', 'Home unaffected by address_name');

// store snapshot + migrate
DeliverySessionAddress::clear();
$snap = DeliverySessionAddress::refreshServiceability($v['clean']);
DeliverySessionAddress::store($snap);
DeliverySessionAddress::migrateToCustomerAccount($custId);
$cur = DeliverySessionAddress::current();
nmOk(($cur['kind'] ?? '') === 'saved', 'guest Other migrated to saved');
$row = CustomerAccountService::findAddress($custId, (int) ($cur['source_address_id'] ?? 0));
nmOk($row !== null && ($row['label'] ?? '') === "Parents' Home", 'saved row label = custom name, got: ' . ($row['label'] ?? 'null'));

// saved selection snapshot keeps custom name
$snap2 = DeliverySessionAddress::snapshotFromSavedRow($row);
nmOk(($snap2['label'] ?? '') === "Parents' Home", 'saved snapshot label = custom name');
nmOk(DeliverySessionAddress::subtitle($snap2) === "Parents' Home · 452010", 'subtitle shows custom name');

exit($fail > 0 ? 1 : 0);
