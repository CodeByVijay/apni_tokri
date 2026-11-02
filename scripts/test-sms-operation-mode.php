<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;

function ok(bool $c, string $l): void
{
    global $fail;
    echo ($c ? 'OK: ' : 'FAIL: ') . $l . "\n";
    if (!$c) {
        $fail++;
    }
}

/** @implements SmsGatewayAdapter */
final class SmsOpModeCountAdapter implements SmsGatewayAdapter
{
    public static $calls = 0;

    public function send(string $mobile, string $message, string $senderId, ?string $dltTemplateId): array
    {
        self::$calls++;

        return ['ok' => true, 'message_id' => 'live_test_1', 'http_status' => 200];
    }
}

function sms_mode_reset(): void
{
    AppSettings::setMany([
        'sms.operation_mode' => ['value' => OtpSmsService::MODE_DISABLED],
        'sms.test.fixed_otp' => ['value' => ''],
        'sms.enabled' => ['value' => '0'],
        'sms.provider' => ['value' => SmsSettingsService::PROVIDER_SIMULATE],
    ]);
    AppSettings::resetCache();
    SmsGatewayFactory::setTestAdapter(null);
    SmsEnvironmentService::setTestProductionOverride(null);
}

sms_mode_reset();
$mobile = '9876500088';
$pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile = ?')->execute([$mobile]);

// DISABLED
ok(OtpSmsService::operationMode() === OtpSmsService::MODE_DISABLED, 'Default effective mode is disabled');
try {
    CustomerAuth::issueOtp($mobile);
    ok(false, 'Disabled mode should not issue OTP');
} catch (OtpRequestException $e) {
    ok($e->errorCode() === OtpSmsService::ERR_SMS_DISABLED, 'Disabled mode customer error code');
    ok(strpos($e->getMessage(), 'API key') === false, 'Disabled message has no secrets');
}
$stmt = $pdo->prepare('SELECT COUNT(*) FROM customer_otp_attempts WHERE mobile = ?');
$stmt->execute([$mobile]);
ok((int) $stmt->fetchColumn() === 0, 'Disabled mode does not persist OTP row');

// TEST MODE
AppSettings::set('sms.operation_mode', OtpSmsService::MODE_TEST);
AppSettings::set('sms.test.fixed_otp', '112233');
AppSettings::resetCache();
SmsOpModeCountAdapter::$calls = 0;
SmsGatewayFactory::setTestAdapter(new SmsOpModeCountAdapter());
try {
    CustomerAuth::issueOtp($mobile);
    ok(SmsOpModeCountAdapter::$calls === 0, 'Test mode does not call external SMS adapter');
    $hashStmt = $pdo->prepare('SELECT otp_hash FROM customer_otp_attempts WHERE mobile = ? ORDER BY id DESC LIMIT 1');
    $hashStmt->execute([$mobile]);
    $hash = (string) $hashStmt->fetchColumn();
    ok(password_verify('112233', $hash), 'Test mode fixed OTP is hashed and verifiable');
    ok(!password_verify('999999', $hash), 'Test mode OTP not guessable without config');
} catch (Throwable $e) {
    ok(false, 'Test mode issue OTP: ' . $e->getMessage());
}
$pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile = ?')->execute([$mobile]);

// Production rejects test mode save
SmsEnvironmentService::setTestProductionOverride(true);
AppSettings::set('sms.operation_mode', OtpSmsService::MODE_TEST);
AppSettings::resetCache();
ok(OtpSmsService::operationMode() === OtpSmsService::MODE_DISABLED, 'Production fails closed: test config not effective');
$saveProd = SmsSettingsService::saveFromRequest([
    'operation_mode' => OtpSmsService::MODE_TEST,
    'enabled' => '0',
    'provider' => SmsSettingsService::PROVIDER_SIMULATE,
    'api_url' => '',
    'sender_id' => '',
    'api_key' => '',
    'http_allowed_status_codes' => '200',
    'http_require_json' => '1',
    'http_success_json_path' => 'status',
    'http_success_json_value' => 'success',
    'http_message_id_json_path' => 'message_id',
    'http_timeout_seconds' => '15',
]);
ok(empty($saveProd['ok']), 'Production rejects saving Test Mode');
SmsEnvironmentService::setTestProductionOverride(null);

// LIVE MODE
AppSettings::set('sms.operation_mode', OtpSmsService::MODE_LIVE);
AppSettings::set('sms.enabled', '1');
AppSettings::set('sms.provider', SmsSettingsService::PROVIDER_HTTP);
AppSettings::set('sms.api_url', 'https://sms-provider.example.test/send');
AppSettings::set('sms.sender_id', 'APNITOKRI');
AppSettings::set('sms.api_key', 'SecretKeyLive', true);
AppSettings::resetCache();
SmsOpModeCountAdapter::$calls = 0;
SmsGatewayFactory::setTestAdapter(new SmsOpModeCountAdapter());
$pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile = ?')->execute([$mobile]);
try {
    CustomerAuth::issueOtp($mobile);
    ok(SmsOpModeCountAdapter::$calls === 1, 'Live mode calls provider adapter once');
} catch (Throwable $e) {
    ok(false, 'Live mode issue with valid config: ' . $e->getMessage());
}

SmsGatewayFactory::setTestAdapter(new class implements SmsGatewayAdapter {
    public function send(string $mobile, string $message, string $senderId, ?string $dltTemplateId): array
    {
        return ['ok' => false, 'error' => 'Provider rejected send.', 'http_status' => 400];
    }
});
$pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile = ?')->execute([$mobile]);
try {
    CustomerAuth::issueOtp($mobile);
    ok(false, 'Live rejection should throw');
} catch (OtpRequestException $e) {
    ok($e->errorCode() === OtpSmsService::ERR_SMS_PROVIDER_REJECTED, 'Provider rejection mapped');
}
$stmt->execute([$mobile]);
ok((int) $stmt->fetchColumn() === 0, 'Failed live send rolls back OTP row');

// Error message mapping
ok(strpos(OtpSmsService::publicErrorMessage(OtpSmsService::ERR_RATE_LIMITED), 'Too many') !== false, 'RATE_LIMITED message');
ok(strpos(OtpSmsService::publicErrorMessage('auth_failed SecretKey 123456'), '123456') === false, 'Public errors never contain OTP/secrets');

// CSRF still enforced (reuse check)
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST[Csrf::fieldName()] = 'bad';
ok(Csrf::validateRequest() === false, 'CSRF enforcement unchanged');
unset($_POST[Csrf::fieldName()], $_SERVER['REQUEST_METHOD']);

sms_mode_reset();
$pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile = ?')->execute([$mobile]);

echo $fail === 0 ? "\nPASS: SMS operation mode tests\n" : "\n{$fail} check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
