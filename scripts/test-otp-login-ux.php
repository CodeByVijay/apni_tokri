<?php

declare(strict_types=1);

/**
 * OTP login & registration UX refinements — disposable-DB regression test.
 *
 * php scripts/run-in-disposable-db.php scripts/test-otp-login-ux.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();

$fail = 0;
function okUx(bool $cond, string $label): void
{
    global $fail;
    echo ($cond ? 'OK' : 'FAIL') . ': ' . $label . PHP_EOL;
    if (!$cond) {
        ++$fail;
    }
}

$pdo = Database::pdo();

// Neutralise review-login + SMS gating so seeded OTP rows are authoritative.
AppSettings::set(PlayReviewAccess::KEY_ENABLED, '0');
AppSettings::set(PlayReviewAccess::KEY_MOBILE, '');
AppSettings::set(PlayReviewAccess::KEY_OTP_HASH, '');
AppSettings::set('sms.operation_mode', OtpSmsService::MODE_TEST);
AppSettings::resetCache();

$mobiles = ['9811100001', '9811100002', '9811100003', '9811100004', '9811100005', '9811100006', '9811100007'];
$in = implode(',', array_fill(0, count($mobiles), '?'));
$pdo->prepare("DELETE FROM customer_otp_attempts WHERE mobile IN ($in)")->execute($mobiles);
$pdo->prepare("DELETE FROM customers WHERE mobile IN ($in)")->execute($mobiles);

function uxSeedOtp(PDO $pdo, string $mobile, string $otp): void
{
    $pdo->prepare(
        'INSERT INTO customer_otp_attempts (mobile, otp_hash, expires_at, ip_address)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), ?)'
    )->execute([$mobile, password_hash($otp, PASSWORD_DEFAULT), '127.0.0.1']);
}

function uxCustomer(PDO $pdo, string $mobile): ?array
{
    $stmt = $pdo->prepare('SELECT id, mobile, name, email, is_active FROM customers WHERE mobile = ? LIMIT 1');
    $stmt->execute([$mobile]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

// ---- 1. Mobile validation rules (6-9 start, 66/69 allowed, 10 digits) ----
okUx(Validation::mobileIndia('6612345678'), 'mobile: 66-prefix accepted');
okUx(Validation::mobileIndia('6912345678'), 'mobile: 69-prefix accepted');
okUx(Validation::mobileIndia('9812345678'), 'mobile: 98-prefix accepted');
okUx(!Validation::mobileIndia('5812345678'), 'mobile: 5-prefix rejected');
okUx(!Validation::mobileIndia('981234567'), 'mobile: 9 digits rejected');
okUx(!Validation::mobileIndia('98123456789'), 'mobile: 11 digits rejected');
okUx(Validation::normalizeMobileIndia('+91 98123 45678') === '9812345678', 'mobile: +91 spaced input normalizes');
okUx(Validation::normalizeMobileIndia('') === null, 'mobile: empty rejected');

// ---- 2. uiContextForMobile modes ----
okUx(CustomerOtpRegistration::uiContextForMobile('9811100001')['mode'] === CustomerOtpRegistration::MODE_NEW, 'ctx: unknown mobile -> new');

$pdo->prepare("INSERT INTO customers (mobile, name, email, is_active) VALUES ('9811100002', 'Named Full', 'full@example.test', 1)")->execute();
okUx(CustomerOtpRegistration::uiContextForMobile('9811100002')['mode'] === CustomerOtpRegistration::MODE_RETURNING, 'ctx: name+email -> returning');

$pdo->prepare("INSERT INTO customers (mobile, name, email, is_active) VALUES ('9811100003', 'Named No Email', NULL, 1)")->execute();
$ctxNoEmail = CustomerOtpRegistration::uiContextForMobile('9811100003');
okUx($ctxNoEmail['mode'] === CustomerOtpRegistration::MODE_RETURNING, 'ctx: name with blank email -> returning (no re-ask)');
okUx($ctxNoEmail['email'] === '', 'ctx: returning blank email prefilled as empty');

$pdo->prepare("INSERT INTO customers (mobile, name, email, is_active) VALUES ('9811100004', NULL, 'legacy@example.test', 1)")->execute();
okUx(CustomerOtpRegistration::uiContextForMobile('9811100004')['mode'] === CustomerOtpRegistration::MODE_LEGACY_PROFILE, 'ctx: email but no name -> legacy profile');

// ---- 3. validateRegistrationInput — email optional ----
$v = CustomerOtpRegistration::validateRegistrationInput(
    ['name' => 'Test User', 'email' => '', 'consent' => '1'],
    CustomerOtpRegistration::MODE_NEW
);
okUx(!empty($v['ok']) && array_key_exists('email', $v) && $v['email'] === null, 'reg input: empty email allowed, normalizes to null');

$v = CustomerOtpRegistration::validateRegistrationInput(
    ['name' => 'Test User', 'email' => 'not-an-email', 'consent' => '1'],
    CustomerOtpRegistration::MODE_NEW
);
okUx(empty($v['ok']) && isset($v['field_errors']['email']), 'reg input: invalid supplied email rejected');

$v = CustomerOtpRegistration::validateRegistrationInput(
    ['name' => 'Test User', 'email' => 'User@Example.COM', 'consent' => '1'],
    CustomerOtpRegistration::MODE_NEW
);
okUx(!empty($v['ok']) && $v['email'] === 'user@example.com', 'reg input: valid email normalized lowercase');

$v = CustomerOtpRegistration::validateRegistrationInput(
    ['name' => '', 'email' => '', 'consent' => '1'],
    CustomerOtpRegistration::MODE_NEW
);
okUx(empty($v['ok']) && isset($v['field_errors']['name']) && !isset($v['field_errors']['email']), 'reg input: name still required, email not flagged');

$v = CustomerOtpRegistration::validateRegistrationInput(
    ['name' => 'Test User', 'email' => ''],
    CustomerOtpRegistration::MODE_NEW
);
okUx(empty($v['ok']) && isset($v['field_errors']['consent']), 'reg input: consent still required for new customers');

// ---- 4. Full OTP verify: register without email ----
uxSeedOtp($pdo, '9811100001', '123456');
$res = CustomerAuth::verifyOtpWithRegistration('9811100001', '123456', [
    'name' => 'No Email User',
    'email' => '',
    'consent' => '1',
]);
okUx(!empty($res['ok']), 'verify: new customer registers without email');
$row = uxCustomer($pdo, '9811100001');
okUx($row !== null && $row['name'] === 'No Email User' && $row['email'] === null, 'verify: row stores NULL email (no placeholder)');
$cid1 = (int) ($res['customer_id'] ?? 0);
okUx($cid1 > 0 && (int) ($_SESSION['customer_id'] ?? 0) === $cid1, 'verify: session logged in after registration');

// Second login: same mobile, blank-email customer -> returning, no fields needed.
uxSeedOtp($pdo, '9811100001', '654321');
$res = CustomerAuth::verifyOtpWithRegistration('9811100001', '654321', []);
okUx(!empty($res['ok']), 'verify: returning customer with blank email logs in');

// ---- 5. Register with valid email; duplicate email still blocked ----
uxSeedOtp($pdo, '9811100005', '123456');
$res = CustomerAuth::verifyOtpWithRegistration('9811100005', '123456', [
    'name' => 'Email User',
    'email' => 'shopper@example.test',
    'consent' => '1',
]);
okUx(!empty($res['ok']), 'verify: new customer registers with valid email');
$row = uxCustomer($pdo, '9811100005');
okUx($row !== null && $row['email'] === 'shopper@example.test', 'verify: supplied email persisted');

uxSeedOtp($pdo, '9811100006', '123456');
$res = CustomerAuth::verifyOtpWithRegistration('9811100006', '123456', [
    'name' => 'Dup Email',
    'email' => 'shopper@example.test',
    'consent' => '1',
]);
okUx(empty($res['ok']) && isset($res['field_errors']['email']), 'verify: duplicate supplied email still rejected');
okUx(uxCustomer($pdo, '9811100006') === null, 'verify: no account created on duplicate email');

// Second blank-email account is allowed (dup check skipped for empty email).
uxSeedOtp($pdo, '9811100006', '234567');
$res = CustomerAuth::verifyOtpWithRegistration('9811100006', '234567', [
    'name' => 'Second No Email',
    'email' => '',
    'consent' => '1',
]);
okUx(!empty($res['ok']), 'verify: second account without email allowed (no false duplicate)');

// ---- 6. Invalid supplied email rejected before OTP consume ----
uxSeedOtp($pdo, '9811100007', '123456');
$res = CustomerAuth::verifyOtpWithRegistration('9811100007', '123456', [
    'name' => 'Bad Email',
    'email' => 'bad@@mail',
    'consent' => '1',
]);
okUx(empty($res['ok']) && isset($res['field_errors']['email']), 'verify: invalid supplied email rejected');

// ---- 7. Legacy profile completion without email ----
uxSeedOtp($pdo, '9811100004', '123456');
$res = CustomerAuth::verifyOtpWithRegistration('9811100004', '123456', [
    'name' => 'Legacy Now Named',
    'email' => '',
]);
okUx(!empty($res['ok']), 'verify: legacy profile completes with name only');
$row = uxCustomer($pdo, '9811100004');
okUx($row !== null && $row['name'] === 'Legacy Now Named', 'verify: legacy name saved');

// Wrong OTP still fails for blank-email returning customer.
uxSeedOtp($pdo, '9811100001', '999999');
$res = CustomerAuth::verifyOtpWithRegistration('9811100001', '000000', []);
okUx(empty($res['ok']), 'verify: wrong OTP rejected (security intact)');

// ---- 8. Static markup checks ----
$login = (string) file_get_contents(APNI_TOKRI_ROOT . '/login.php');
okUx(str_contains($login, 'id="mobile-hint" class="at-field-error hidden"'), 'login: mobile hint starts hidden');
okUx(str_contains($login, 'novalidate'), 'login: form uses JS-driven validation');
$js = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/apni-tokri.js');
okUx(str_contains($js, 'Please enter your mobile number.'), 'login JS: empty-input message');
okUx(str_contains($js, 'Enter a valid 10-digit Indian mobile number.'), 'login JS: invalid-input message');
okUx(str_contains($js, 'var attempted = false'), 'login JS: errors gated behind submit attempt');
okUx(!preg_match('/\}\);\s*\n\s*sync\(\);\s*\n\s*\}\s*\n\}\)\(jQuery\)/', $js), 'login JS: no init-time sync() call');

$otp = (string) file_get_contents(APNI_TOKRI_ROOT . '/verify-otp.php');
okUx(str_contains($otp, "substr(\$mobile, 0, 5) . ' ' . substr(\$mobile, 5, 1)"), 'verify-otp: masked display shows first 6 digits');
okUx(str_contains($otp, '••••'), 'verify-otp: last 4 digits masked with bullets');
okUx(str_contains($otp, '(optional)') && str_contains($otp, 'Get order updates and invoices by email.'), 'verify-otp: email optional label + helper');
okUx(!preg_match('/name="email"[^>]*required/', $otp), 'verify-otp: email input no longer required');
okUx(str_contains($otp, "emailVal === '' || emailRe.test(emailVal)"), 'verify-otp JS: empty email passes submit gate');
okUx(str_contains($otp, 'You can update your profile from My account after signing in.'), 'verify-otp: returning helper text preserved');

// Mask format sanity: replicate the display expression.
$m = '9999951234';
okUx('+91 ' . substr($m, 0, 5) . ' ' . substr($m, 5, 1) . '••••' === '+91 99999 5••••', 'mask format matches spec example');

echo ($fail === 0 ? 'ALL OTP LOGIN UX CHECKS OK' : "FAILURES: $fail") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
