<?php

declare(strict_types=1);

/**
 * OTP rate limits (3 / 15 min per mobile, 10 / hour per IP) + rolling window retry time.
 * Run: php7.3 scripts/run-in-disposable-db.php scripts/test-otp-rate-limit.php
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

function okR(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

/** @implements SmsGatewayAdapter */
final class OtpRateFailAdapter implements SmsGatewayAdapter
{
    public function send(string $mobile, string $message, string $senderId, ?string $dltTemplateId): array
    {
        return ['ok' => false, 'error' => 'rejected', 'http_status' => 500];
    }
}

$mobile = '9876500001';
$pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile = ?')->execute([$mobile]);
AppSettings::set('sms.operation_mode', OtpSmsService::MODE_TEST);
AppSettings::set('sms.test.fixed_otp', '112233');
AppSettings::resetCache();
OtpResendCooldown::$bypassForTests = true;

$blockedOnFourth = false;
$fourthRemaining = 0;
$fourthMessage = '';
for ($i = 0; $i < 4; $i++) {
    try {
        CustomerAuth::issueOtp($mobile);
        echo "OTP request " . ($i + 1) . " ok\n";
    } catch (OtpRequestException $e) {
        echo "OTP request " . ($i + 1) . " blocked: " . $e->getMessage() . "\n";
        if ($i === 3) {
            $blockedOnFourth = $e->errorCode() === OtpSmsService::ERR_RATE_LIMITED;
            $fourthRemaining = $e->remainingSeconds();
            $fourthMessage = $e->getMessage();
        }
    } catch (Throwable $e) {
        echo "OTP request " . ($i + 1) . " error: " . $e->getMessage() . "\n";
        if ($i !== 3) {
            $fail++;
        }
    }
}

okR($blockedOnFourth, 'Fourth request within 15 minutes blocked (3-per-window limit)');
okR($fourthRemaining > 0, 'Rate limit returns remaining_seconds > 0');
okR(strpos($fourthMessage, 'Try again in') !== false && preg_match('/\d{2}:\d{2}/', $fourthMessage) === 1, 'Rate limit message includes MM:SS retry time');

$cnt = (int) $pdo->query("SELECT COUNT(*) FROM customer_otp_attempts WHERE mobile = " . $pdo->quote($mobile))->fetchColumn();
okR($cnt === 3, 'Exactly three OTP rows recorded for three successful sends');

// Window expiry: age out rows, fourth request allowed
$pdo->exec(
    'UPDATE customer_otp_attempts SET created_at = DATE_SUB(NOW(), INTERVAL 16 MINUTE) WHERE mobile = '
    . $pdo->quote($mobile)
);
try {
    CustomerAuth::issueOtp($mobile);
    okR(true, 'Request allowed after rate-limit window expires');
} catch (Throwable $e) {
    okR(false, 'Request allowed after rate-limit window expires: ' . $e->getMessage());
}

// Failed SMS must not consume mobile rate-limit slots
$pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile = ?')->execute([$mobile]);
AppSettings::set('sms.operation_mode', OtpSmsService::MODE_LIVE);
AppSettings::set('sms.enabled', '1');
AppSettings::set('sms.provider', SmsSettingsService::PROVIDER_HTTP);
AppSettings::set('sms.http.url', 'https://example.test/sms');
AppSettings::set('sms.http.method', 'POST');
AppSettings::resetCache();
SmsGatewayFactory::setTestAdapter(new OtpRateFailAdapter());
for ($i = 0; $i < 5; $i++) {
    try {
        CustomerAuth::issueOtp($mobile);
        okR(false, 'Failed SMS should not succeed');
    } catch (OtpRequestException $e) {
        // expected
    }
}
$failCnt = (int) $pdo->query("SELECT COUNT(*) FROM customer_otp_attempts WHERE mobile = " . $pdo->quote($mobile))->fetchColumn();
okR($failCnt === 0, 'Failed SMS attempts leave no OTP rows (do not consume rate limit)');
okR(OtpRateLimit::activeBlock($mobile) === null, 'Rate limit not active after repeated failed SMS sends');

$pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile = ?')->execute([$mobile]);
AppSettings::set('sms.operation_mode', OtpSmsService::MODE_DISABLED);
AppSettings::set('sms.test.fixed_otp', '');
AppSettings::resetCache();
OtpResendCooldown::$bypassForTests = false;
SmsGatewayFactory::setTestAdapter(null);

exit($fail > 0 ? 1 : 0);
