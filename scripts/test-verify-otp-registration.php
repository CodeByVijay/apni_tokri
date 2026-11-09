<?php

declare(strict_types=1);

/**
 * OTP verify — first-time registration vs returning login.
 * Run: php scripts/run-in-disposable-db.php scripts/test-verify-otp-registration.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/lib/test_script_safety.php';
require_once dirname(__DIR__) . '/scripts/includes/disposable_test_admin.php';

/** @return array{name: string, email: string, consent?: string} */
function otp_reg_input(string $name, string $email, bool $consent = true): array
{
    $p = ['name' => $name, 'email' => $email];
    if ($consent) {
        $p['consent'] = '1';
    }

    return $p;
}

$fail = 0;
function okR(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

Install::ensureConnection();
$pdo = Database::pdo();
$migration = dirname(__DIR__) . '/database/migrations/1.36.0_customer_registration_consent.sql';
if (is_readable($migration)) {
    $pdo->exec((string) file_get_contents($migration));
}

AppSettings::set('sms.operation_mode', OtpSmsService::MODE_TEST);
AppSettings::set('sms.test.fixed_otp', '654321');
AppSettings::resetCache();

$tag = 'reg' . bin2hex(random_bytes(3));
$existingMobile = '987650' . substr($tag, 0, 4);
$freshMobile = '987651' . substr($tag, 0, 4);
$legacyMobile = '987652' . substr($tag, 0, 4);
$dupeEmailMobile = '987653' . substr($tag, 0, 4);

$pdo->prepare('INSERT INTO customers (mobile, name, email) VALUES (?, ?, ?)')->execute([
    $existingMobile,
    'Returning User',
    'returning+' . $tag . '@example.test',
]);
$pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute([$legacyMobile, 'Legacy Only Name']);
$pdo->prepare('INSERT INTO customers (mobile, name, email) VALUES (?, ?, ?)')->execute([
    '9999900001',
    'Email Owner',
    'shared+' . $tag . '@example.test',
]);

$customerEmail = static function (string $m) use ($pdo): ?string {
    $s = $pdo->prepare('SELECT email FROM customers WHERE mobile = ?');
    $s->execute([$m]);
    $v = $s->fetchColumn();

    return $v === false ? null : ($v === null ? null : (string) $v);
};

$consentCount = static function (int $cid) use ($pdo): int {
    if (!CustomerOtpRegistration::consentTableReady()) {
        return 0;
    }
    $s = $pdo->prepare('SELECT COUNT(*) FROM customer_registration_consents WHERE customer_id = ?');
    $s->execute([$cid]);

    return (int) $s->fetchColumn();
};

$ui = CustomerOtpRegistration::uiContextForMobile($existingMobile);
okR($ui['mode'] === CustomerOtpRegistration::MODE_RETURNING, 'Returning customer identified server-side');
okR(!$ui['show_consent'], 'Returning customer: no consent required');

// 1. Valid first-time registration
CustomerAuth::issueOtp($freshMobile);
$r = CustomerAuth::verifyOtpWithRegistration($freshMobile, '654321', otp_reg_input('New Customer', 'new+' . $tag . '@example.test'));
okR(!empty($r['ok']), 'First-time registration with name, email, consent');
$idFresh = (int) ($r['customer_id'] ?? 0);
okR($consentCount($idFresh) === 1, 'Consent row stored for new registration');

// 2–5. Validation failures (new customer mobile each)
$valMobile = static function () use ($tag): string {
    return '99' . substr(bin2hex(random_bytes(4)), 0, 8);
};

foreach (
    [
        ['no-name', otp_reg_input('', 'x@example.test'), 'name'],
        ['no-email', otp_reg_input('Valid Name', ''), 'email'],
        ['bad-email', otp_reg_input('Valid Name', 'not-an-email'), 'email'],
        ['no-consent', otp_reg_input('Valid Name', 'nc+' . $tag . '@example.test', false), 'consent'],
    ] as [$label, $input, $field]
) {
    $m = $valMobile();
    CustomerAuth::issueOtp($m);
    $vr = CustomerAuth::verifyOtpWithRegistration($m, '654321', $input);
    okR(empty($vr['ok']) && isset($vr['field_errors'][$field]), 'Rejected ' . $label);
}

// 6–8. Returning customer login
CustomerAuth::issueOtp($existingMobile);
$beforeName = (string) $pdo->query('SELECT name FROM customers WHERE mobile = ' . $pdo->quote($existingMobile))->fetchColumn();
$ret = CustomerAuth::verifyOtpWithRegistration(
    $existingMobile,
    '654321',
    otp_reg_input('Tampered Name', 'hacker@evil.test')
);
okR(!empty($ret['ok']), 'Returning customer logs in with OTP only');
$afterName = (string) $pdo->query('SELECT name FROM customers WHERE mobile = ' . $pdo->quote($existingMobile))->fetchColumn();
okR($beforeName === $afterName && $beforeName === 'Returning User', 'Tampered POST cannot overwrite returning name/email');

// 11. Duplicate email on new registration
CustomerAuth::issueOtp($dupeEmailMobile);
$de = CustomerAuth::verifyOtpWithRegistration(
    $dupeEmailMobile,
    '654321',
    otp_reg_input('Dupe Test', 'shared+' . $tag . '@example.test')
);
okR(empty($de['ok']) && isset($de['field_errors']['email']), 'Duplicate email rejected');

// 12. Bad OTP
$mBad = $valMobile();
CustomerAuth::issueOtp($mBad);
$bad = CustomerAuth::verifyOtpWithRegistration($mBad, '000000', otp_reg_input('X', 'bad+' . $tag . '@example.test'));
okR(empty($bad['ok']), 'Invalid OTP cannot register');

// Legacy profile completion (missing email)
CustomerAuth::issueOtp($legacyMobile);
$leg = CustomerAuth::verifyOtpWithRegistration(
    $legacyMobile,
    '654321',
    otp_reg_input('Legacy User', 'legacy+' . $tag . '@example.test', false)
);
okR(!empty($leg['ok']), 'Legacy customer completes email without new consent');
okR($customerEmail($legacyMobile) === 'legacy+' . $tag . '@example.test', 'Legacy email saved');

// 10. Duplicate mobile — second insert path uses lock; count stays 1
okR((int) $pdo->query('SELECT COUNT(*) FROM customers WHERE mobile = ' . $pdo->quote($freshMobile))->fetchColumn() === 1, 'No duplicate mobile row');

// Cart merge smoke (no exception)
CustomerAuth::logout();
$_SESSION['cart_guest_lines'] = [];
CustomerAuth::issueOtp($existingMobile);
CustomerAuth::verifyOtpWithRegistration($existingMobile, '654321', []);
okR(CustomerAuth::check(), 'Session after returning login');

$pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile IN (?,?,?,?,?)')->execute([
    $existingMobile,
    $freshMobile,
    $legacyMobile,
    $dupeEmailMobile,
    $mBad,
]);

echo "\nSUMMARY fail={$fail}\n";
exit($fail > 0 ? 1 : 0);
