<?php

declare(strict_types=1);

/**
 * App Review Access — OTP login regression (disposable DB).
 *
 * php scripts/run-in-disposable-db.php scripts/test-play-review-access.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();

$fail = 0;

function praOk(bool $cond, string $label): void
{
    global $fail;
    echo ($cond ? 'OK' : 'FAIL') . ': ' . $label . PHP_EOL;
    if (!$cond) {
        ++$fail;
    }
}

function praResetPlayReview(): void
{
    AppSettings::set(PlayReviewAccess::KEY_ENABLED, '0');
    AppSettings::set(PlayReviewAccess::KEY_MOBILE, '');
    AppSettings::set(PlayReviewAccess::KEY_OTP_HASH, '');
    AppSettings::resetCache();
}

function praSeedCustomer(PDO $pdo, string $mobile, string $name, string $email, int $active = 1): int
{
    $pdo->prepare(
        'INSERT INTO customers (mobile, name, email, is_active) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email), is_active = VALUES(is_active)'
    )->execute([$mobile, $name, $email, $active]);
    $stmt = $pdo->prepare('SELECT id FROM customers WHERE mobile = ? LIMIT 1');
    $stmt->execute([$mobile]);

    return (int) $stmt->fetchColumn();
}

function praEnableReview(string $mobile, string $otpPlain): void
{
    AppSettings::set(PlayReviewAccess::KEY_ENABLED, '1');
    AppSettings::set(PlayReviewAccess::KEY_MOBILE, $mobile);
    AppSettings::set(PlayReviewAccess::KEY_OTP_HASH, password_hash($otpPlain, PASSWORD_DEFAULT));
    AppSettings::resetCache();
}

function praOtpAttemptCount(PDO $pdo, string $mobile, bool $excludeThrottle = true): int
{
    if ($excludeThrottle) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM customer_otp_attempts WHERE mobile = ? AND otp_hash <> ?'
        );
        $stmt->execute([$mobile, PlayReviewAccess::THROTTLE_OTP_HASH]);
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM customer_otp_attempts WHERE mobile = ?');
        $stmt->execute([$mobile]);
    }

    return (int) $stmt->fetchColumn();
}

$pdo = Database::pdo();
$reviewerMobile = '9876012345';
$normalMobile = '9876098765';
$reviewOtp = '847291';
$otherOtp = '111222';

praResetPlayReview();
$pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile IN (?, ?)')->execute([$reviewerMobile, $normalMobile]);
$pdo->prepare('DELETE FROM customers WHERE mobile IN (?, ?)')->execute([$reviewerMobile, $normalMobile]);

$reviewerId = praSeedCustomer($pdo, $reviewerMobile, 'Play Reviewer', 'reviewer-play-test@example.invalid', 1);
$normalId = praSeedCustomer($pdo, $normalMobile, 'Normal Shopper', 'normal-play-test@example.invalid', 1);
praOk($reviewerId > 0 && $normalId > 0, 'seed reviewer and normal customers');

AppSettings::set('sms.operation_mode', OtpSmsService::MODE_DISABLED);
AppSettings::resetCache();

// 7 — disabled before enable
praEnableReview($reviewerMobile, $reviewOtp);
AppSettings::set(PlayReviewAccess::KEY_ENABLED, '0');
AppSettings::resetCache();
$disabledVerify = CustomerAuth::verifyOtpWithRegistration($reviewerMobile, $reviewOtp, []);
praOk(empty($disabledVerify['ok']), 'reviewer fixed OTP rejected when feature disabled');

// Enable for main scenarios
AppSettings::set(PlayReviewAccess::KEY_ENABLED, '1');
AppSettings::resetCache();

$beforeIssue = praOtpAttemptCount($pdo, $reviewerMobile);
try {
    $issued = CustomerAuth::issueOtp($reviewerMobile);
    praOk(($issued['mobile'] ?? '') === $reviewerMobile, 'reviewer issueOtp succeeds with SMS disabled');
} catch (Throwable $e) {
    praOk(false, 'reviewer issueOtp with SMS disabled: ' . $e->getMessage());
}
$afterIssue = praOtpAttemptCount($pdo, $reviewerMobile);
praOk($afterIssue === $beforeIssue, 'reviewer issue does not insert normal OTP row');

// 1 — correct OTP
CustomerAuth::logout();
$good = CustomerAuth::verifyOtpWithRegistration($reviewerMobile, $reviewOtp, []);
praOk(!empty($good['ok']) && CustomerAuth::id() === $reviewerId, 'reviewer mobile + correct fixed OTP logs in');

// 2 — wrong OTP
CustomerAuth::logout();
$bad = CustomerAuth::verifyOtpWithRegistration($reviewerMobile, '000000', []);
praOk(empty($bad['ok']), 'reviewer mobile + wrong OTP rejected');

// 3 — normal mobile + reviewer OTP
CustomerAuth::logout();
AppSettings::set('sms.operation_mode', OtpSmsService::MODE_TEST);
AppSettings::resetCache();
$plain = '592814';
$hash = password_hash($plain, PASSWORD_DEFAULT);
$pdo->prepare(
    'INSERT INTO customer_otp_attempts (mobile, otp_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 300 SECOND))'
)->execute([$normalMobile, $hash]);
$cross = CustomerAuth::verifyOtpWithRegistration($normalMobile, $reviewOtp, []);
praOk(empty($cross['ok']), 'normal mobile + reviewer OTP rejected');
$cross2 = CustomerAuth::verifyOtpWithRegistration($normalMobile, $plain, []);
praOk(!empty($cross2['ok']), 'normal mobile + valid random OTP succeeds');

// 5 — expired OTP for normal
CustomerAuth::logout();
$pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile = ? AND otp_hash <> ?')->execute([$normalMobile, PlayReviewAccess::THROTTLE_OTP_HASH]);
$pdo->prepare(
    'INSERT INTO customer_otp_attempts (mobile, otp_hash, expires_at) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 60 SECOND))'
)->execute([$normalMobile, password_hash('592814', PASSWORD_DEFAULT)]);
$expired = CustomerAuth::verifyOtpWithRegistration($normalMobile, '592814', []);
praOk(empty($expired['ok']), 'normal mobile + expired OTP rejected');

// 8 — inactive reviewer
CustomerAuth::logout();
$pdo->prepare('UPDATE customers SET is_active = 0 WHERE mobile = ?')->execute([$reviewerMobile]);
$inactive = CustomerAuth::verifyOtpWithRegistration($reviewerMobile, $reviewOtp, []);
praOk(empty($inactive['ok']), 'inactive reviewer customer rejected at verify');
$pdo->prepare('UPDATE customers SET is_active = 1 WHERE mobile = ?')->execute([$reviewerMobile]);

// 9 — missing customer (configured mobile with no row)
CustomerAuth::logout();
$ghostMobile = '9876011111';
AppSettings::set(PlayReviewAccess::KEY_MOBILE, $ghostMobile);
AppSettings::resetCache();
praOk(!PlayReviewAccess::isReviewLoginEnabledForMobile($ghostMobile), 'missing customer disables review login flag');
try {
    CustomerAuth::issueOtp($ghostMobile);
    praOk(false, 'issue for missing reviewer customer should fail');
} catch (OtpRequestException $e) {
    praOk(true, 'missing reviewer customer rejected at issue');
}
AppSettings::set(PlayReviewAccess::KEY_MOBILE, $reviewerMobile);
AppSettings::resetCache();

// 6 — SMS disabled again on issue
AppSettings::set('sms.operation_mode', OtpSmsService::MODE_DISABLED);
AppSettings::resetCache();
OtpResendCooldown::$bypassForTests = true;
try {
    CustomerAuth::issueOtp($reviewerMobile);
    praOk(true, 'reviewer issue with SMS operation disabled');
} catch (Throwable $e) {
    praOk(false, 'reviewer issue SMS disabled: ' . $e->getMessage());
}
OtpResendCooldown::$bypassForTests = false;

// 13 — resend no SMS / no OTP row
$countBeforeResend = praOtpAttemptCount($pdo, $reviewerMobile);
OtpResendCooldown::$bypassForTests = true;
CustomerAuth::issueOtp($reviewerMobile);
OtpResendCooldown::$bypassForTests = false;
praOk(praOtpAttemptCount($pdo, $reviewerMobile) === $countBeforeResend, 'reviewer resend does not add normal OTP rows');

// 14 — failed attempt throttling
CustomerAuth::logout();
$pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile = ? AND otp_hash = ?')->execute([$reviewerMobile, PlayReviewAccess::THROTTLE_OTP_HASH]);
for ($i = 0; $i < PlayReviewAccess::THROTTLE_MAX_ATTEMPTS; $i++) {
    CustomerAuth::verifyOtpWithRegistration($reviewerMobile, '000001', []);
}
$throttled = CustomerAuth::verifyOtpWithRegistration($reviewerMobile, $reviewOtp, []);
praOk(empty($throttled['ok']) && PlayReviewAccess::isVerifyThrottled($reviewerMobile), 'failed reviewer attempts throttle verify');
$pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile = ? AND otp_hash = ?')->execute([$reviewerMobile, PlayReviewAccess::THROTTLE_OTP_HASH]);
$afterClear = CustomerAuth::verifyOtpWithRegistration($reviewerMobile, $reviewOtp, []);
praOk(!empty($afterClear['ok']), 'throttle clears after window reset (manual row delete)');

// 12 — hash not in admin form state
$form = PlayReviewAccess::adminFormState();
$json = json_encode($form);
praOk(
    is_string($json)
    && !str_contains($json, $reviewOtp)
    && !str_contains($json, '$2y$'),
    'admin form state exposes no OTP or hash'
);

// 11 — save forbidden for non-super-admin
require_once __DIR__ . '/includes/stage14_phase1_fixture.php';
stage14_p1_cleanup($pdo);
$supportId = stage14_p1_create_user_with_role($pdo, 'at-pra-support@apnitokri.local', 'support');
stage14_p1_session($supportId, 'at-pra-support@apnitokri.local', 'Support');
$deny = PlayReviewAccess::saveFromRequest([
    'play_review_enabled' => '1',
    'play_review_mobile' => $reviewerMobile,
    'play_review_otp' => '123456',
]);
praOk(empty($deny['ok']) && ($deny['message'] ?? '') === 'Forbidden.', 'non-super-admin cannot save play review settings');
stage14_p1_clear_session();

// 10 — admin account linkage: no mobile on admin_users (architecture N/A)
praOk(true, 'admin mobile linkage N/A (admin_users has no mobile column)');

// 15 — normal customer regression with SMS test mode
CustomerAuth::logout();
praResetPlayReview();
AppSettings::set('sms.operation_mode', OtpSmsService::MODE_TEST);
AppSettings::resetCache();
$pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile = ?')->execute([$normalMobile]);
AppSettings::set('sms.test.fixed_otp', '592814');
AppSettings::resetCache();
OtpResendCooldown::$bypassForTests = true;
CustomerAuth::issueOtp($normalMobile);
OtpResendCooldown::$bypassForTests = false;
praOk(praOtpAttemptCount($pdo, $normalMobile) >= 1, 'normal issue creates hashed OTP row');
$normLogin = CustomerAuth::verifyOtpWithRegistration($normalMobile, '592814', []);
praOk(!empty($normLogin['ok']) && CustomerAuth::id() === $normalId, 'normal customer login regression');

praResetPlayReview();
stage14_p1_cleanup($pdo);

echo PHP_EOL . ($fail === 0 ? 'All play review tests passed.' : "{$fail} test(s) failed.") . PHP_EOL;
exit($fail > 0 ? 1 : 0);
