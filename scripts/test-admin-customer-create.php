<?php

declare(strict_types=1);

/**
 * Admin manual customer creation (disposable DB).
 *
 * php scripts/run-in-disposable-db.php scripts/test-admin-customer-create.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/stage14_phase1_fixture.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();

$fail = 0;

function ccOk(bool $cond, string $label): void
{
    global $fail;
    echo ($cond ? 'OK' : 'FAIL') . ': ' . $label . PHP_EOL;
    if (!$cond) {
        ++$fail;
    }
}

$pdo = Database::pdo();
$mobileNew = '9876123456';
$mobileDup = '9876123457';
$emailDup = 'dup-create-test@example.invalid';

$pdo->prepare('DELETE FROM customers WHERE mobile IN (?, ?) OR email = ?')->execute([$mobileNew, $mobileDup, $emailDup]);
$pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?, ?, ?, 1)')
    ->execute([$mobileDup, 'Existing', $emailDup]);

$superId = stage14_p1_super_admin_id($pdo);
stage14_p1_session($superId, 'admin@apnitokri.local', 'Super Admin');
Permission::clearCache();

// Successful creation
$res = AdminCustomerService::createFromAdmin(
    'Play Review Provisioning',
    '98 7612 3456',
    'reviewer-create@example.invalid',
    true,
    $superId
);
ccOk(!empty($res['ok']) && (int) ($res['customer_id'] ?? 0) > 0, 'successful customer creation');
$row = AdminCustomerService::findById((int) ($res['customer_id'] ?? 0));
ccOk(
    is_array($row)
    && ($row['mobile'] ?? '') === $mobileNew
    && (int) ($row['is_active'] ?? 0) === 1,
    'mobile stored normalized and active'
);

// Play Review Access eligibility
$check = PlayReviewAccess::validateReviewerCustomer($mobileNew);
ccOk(!empty($check['ok']), 'PlayReviewAccess validates new admin customer');

// Missing name
$badName = AdminCustomerService::createFromAdmin('', $mobileNew, '', true, $superId);
ccOk(empty($badName['ok']) && isset($badName['errors']['name']), 'missing required name rejected');

// Invalid mobile
$badMob = AdminCustomerService::createFromAdmin('Test User', '12345', '', true, $superId);
ccOk(empty($badMob['ok']) && isset($badMob['errors']['mobile']), 'invalid mobile rejected');

// Duplicate mobile
$dupMob = AdminCustomerService::createFromAdmin('Another', $mobileDup, 'other@example.invalid', true, $superId);
ccOk(empty($dupMob['ok']) && isset($dupMob['errors']['mobile']), 'duplicate mobile rejected');

// Duplicate email
$dupEmail = AdminCustomerService::createFromAdmin('Another', '9876123999', $emailDup, true, $superId);
ccOk(empty($dupEmail['ok']) && isset($dupEmail['errors']['email']), 'duplicate email rejected');

// Inactive status
$inactiveMob = '9876123400';
$pdo->prepare('DELETE FROM customers WHERE mobile = ?')->execute([$inactiveMob]);
$inactive = AdminCustomerService::createFromAdmin('Inactive User', $inactiveMob, '', false, $superId);
ccOk(!empty($inactive['ok']), 'inactive customer can be created');
$inactiveRow = AdminCustomerService::findById((int) ($inactive['customer_id'] ?? 0));
ccOk(is_array($inactiveRow) && (int) ($inactiveRow['is_active'] ?? 1) === 0, 'inactive flag stored');

// Unauthorized admin
$financeId = stage14_p1_create_user_with_role($pdo, 'at-cc-finance@apnitokri.local', 'finance');
stage14_p1_session($financeId, 'at-cc-finance@apnitokri.local', 'Finance');
Permission::clearCache();
$forbidden = AdminCustomerService::createFromAdmin('X', '9876123401', '', true, $financeId);
ccOk(empty($forbidden['ok']) && ($forbidden['message'] ?? '') === 'Forbidden.', 'unauthorized admin forbidden');
stage14_p1_session($superId);

// CSRF validation
$_SESSION[Csrf::fieldName()] = 'valid-token';
$_POST = [Csrf::fieldName() => 'wrong-token'];
$_SERVER['REQUEST_METHOD'] = 'POST';
ccOk(!Csrf::validateRequest(), 'CSRF rejects mismatched token');
unset($_POST[Csrf::fieldName()]);
$_SERVER['REQUEST_METHOD'] = 'GET';

// Audit row
$audit = (int) $pdo->query(
    "SELECT COUNT(*) FROM admin_audit_logs WHERE action = 'customer.create' AND entity_id = '" . (int) ($res['customer_id'] ?? 0) . "'"
)->fetchColumn();
ccOk($audit >= 1, 'audit log customer.create without sensitive payload');

$payload = $pdo->query(
    "SELECT payload_json FROM admin_audit_logs WHERE action = 'customer.create' AND entity_id = '" . (int) ($res['customer_id'] ?? 0) . "' ORDER BY id DESC LIMIT 1"
)->fetchColumn();
$payloadStr = is_string($payload) ? $payload : '';
ccOk(
    $payloadStr !== ''
    && !str_contains($payloadStr, 'reviewer-create@')
    && str_contains($payloadStr, 'mobile_suffix'),
    'audit payload has mobile_suffix only not full PII'
);

stage14_p1_cleanup($pdo);
$pdo->prepare('DELETE FROM customers WHERE mobile IN (?, ?, ?, ?) OR email = ?')->execute([
    $mobileNew,
    $mobileDup,
    $inactiveMob,
    '9876123999',
    $emailDup,
]);

echo PHP_EOL . ($fail === 0 ? 'All admin customer create tests passed.' : "{$fail} test(s) failed.") . PHP_EOL;
exit($fail > 0 ? 1 : 0);
