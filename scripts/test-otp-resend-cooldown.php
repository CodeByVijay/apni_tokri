<?php

declare(strict_types=1);

/**
 * OTP resend cooldown (120s default, session + server lock).
 * Run: php7.3 scripts/run-in-disposable-db.php scripts/test-otp-resend-cooldown.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

AppSession::start(App::config()['session'] ?? []);

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;

function okC(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

/** @implements SmsGatewayAdapter */
final class OtpResendFailAdapter implements SmsGatewayAdapter
{
    public static $calls = 0;

    public function send(string $mobile, string $message, string $senderId, ?string $dltTemplateId): array
    {
        self::$calls++;

        return ['ok' => false, 'error' => 'rejected', 'http_status' => 500];
    }
}

function smsReset(string $mobile): void
{
    global $pdo;
    $pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile = ?')->execute([$mobile]);
    OtpResendCooldown::clear();
    OtpResendCooldown::$secondsForTest = null;
    AppSettings::set('sms.operation_mode', OtpSmsService::MODE_TEST);
    AppSettings::set('sms.test.fixed_otp', '445566');
    AppSettings::resetCache();
    SmsGatewayFactory::setTestAdapter(null);
}

$mobile = '9876500099';
smsReset($mobile);
OtpResendCooldown::$secondsForTest = 120;

$_SESSION['otp_pending_mobile'] = $mobile;

try {
    CustomerAuth::issueOtp($mobile);
    okC(true, 'Initial OTP issued');
} catch (Throwable $e) {
    okC(false, 'Initial OTP issued: ' . $e->getMessage());
}

okC(OtpResendCooldown::remainingSeconds($mobile) > 110, 'Cooldown active immediately after send');

try {
    CustomerAuth::issueOtp($mobile);
    okC(false, 'Second immediate issue should be blocked');
} catch (OtpRequestException $e) {
    okC($e->errorCode() === OtpSmsService::ERR_RESEND_COOLDOWN, 'Resend blocked with cooldown code');
    okC($e->remainingSeconds() > 0, 'Remaining seconds returned');
}

$cntStmt = $pdo->prepare('SELECT COUNT(*) FROM customer_otp_attempts WHERE mobile = ?');
$cntStmt->execute([$mobile]);
$cnt = (int) $cntStmt->fetchColumn();
okC($cnt === 1, 'Cooldown block did not insert second OTP row');

// After cooldown elapses (test: 2 seconds)
smsReset($mobile);
OtpResendCooldown::$secondsForTest = 2;
CustomerAuth::issueOtp($mobile);
sleep(3);
try {
    CustomerAuth::issueOtp($mobile);
    okC(true, 'Resend allowed after cooldown elapsed');
} catch (OtpRequestException $e) {
    okC(false, 'Resend allowed after cooldown: ' . $e->getMessage());
}

// Failed SMS does not start cooldown
smsReset($mobile);
OtpResendCooldown::$secondsForTest = 120;
AppSettings::set('sms.operation_mode', OtpSmsService::MODE_LIVE);
AppSettings::set('sms.enabled', '1');
AppSettings::set('sms.provider', SmsSettingsService::PROVIDER_HTTP);
AppSettings::set('sms.http.url', 'https://example.test/sms');
AppSettings::set('sms.http.method', 'POST');
AppSettings::resetCache();
OtpResendFailAdapter::$calls = 0;
SmsGatewayFactory::setTestAdapter(new OtpResendFailAdapter());
try {
    CustomerAuth::issueOtp($mobile);
    okC(false, 'Failed send should throw');
} catch (OtpRequestException $e) {
    okC(true, 'Failed send throws');
}
okC(OtpResendCooldown::remainingSeconds($mobile) === 0, 'Failed SMS did not start cooldown');
$cntFailStmt = $pdo->prepare('SELECT COUNT(*) FROM customer_otp_attempts WHERE mobile = ?');
$cntFailStmt->execute([$mobile]);
$cntFail = (int) $cntFailStmt->fetchColumn();
okC($cntFail === 0, 'Failed SMS rolled back OTP row');

// Rapid duplicate: only one SMS path succeeds
smsReset($mobile);
AppSettings::set('sms.operation_mode', OtpSmsService::MODE_TEST);
AppSettings::resetCache();
CustomerAuth::issueOtp($mobile);
$countOtp = static function () use ($pdo, $mobile): int {
    $s = $pdo->prepare('SELECT COUNT(*) FROM customer_otp_attempts WHERE mobile = ?');
    $s->execute([$mobile]);

    return (int) $s->fetchColumn();
};
$before = $countOtp();
for ($i = 0; $i < 3; $i++) {
    try {
        CustomerAuth::issueOtp($mobile);
    } catch (OtpRequestException $e) {
        // expected
    }
}
$after = $countOtp();
okC($before === 1 && $after === 1, 'Repeated rapid resend attempts did not create multiple OTP rows');

// Reload preserves cooldown (session)
smsReset($mobile);
CustomerAuth::issueOtp($mobile);
$rem1 = OtpResendCooldown::remainingSeconds($mobile);
$rem2 = OtpResendCooldown::remainingSeconds($mobile);
okC($rem1 > 0 && abs($rem1 - $rem2) <= 1, 'Reload/session reread preserves remaining cooldown');

// Concurrent lock: second call waits then hits cooldown
smsReset($mobile);
CustomerAuth::issueOtp($mobile);
try {
    CustomerAuth::issueOtp($mobile);
    okC(false, 'Sequential concurrent bypass');
} catch (OtpRequestException $e) {
    okC($e->errorCode() === OtpSmsService::ERR_RESEND_COOLDOWN, 'Lock + cooldown prevents bypass');
}

smsReset($mobile);
OtpResendCooldown::$secondsForTest = null;
AppSettings::set('sms.operation_mode', OtpSmsService::MODE_DISABLED);
AppSettings::resetCache();

exit($fail > 0 ? 1 : 0);
