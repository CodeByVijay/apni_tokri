<?php

declare(strict_types=1);

/**
 * StartMessaging SMS integration tests (mocked HTTP only — no real SMS).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

$fail = 0;

/** @var array<string, string|null> */
$settingsBackup = [
    'sms.operation_mode' => AppSettings::get('sms.operation_mode', '') ?? '',
    'sms.enabled' => AppSettings::get('sms.enabled', '') ?? '',
    'sms.provider' => AppSettings::get('sms.provider', '') ?? '',
    'sms.api_url' => AppSettings::get('sms.api_url', '') ?? '',
    'sms.sender_id' => AppSettings::get('sms.sender_id', '') ?? '',
    'sms.dlt.otp' => AppSettings::get('sms.dlt.otp', '') ?? '',
    'sms.startmessaging.variable_app_name' => AppSettings::get('sms.startmessaging.variable_app_name', '') ?? '',
    'sms.startmessaging.variable_expiry' => AppSettings::get('sms.startmessaging.variable_expiry', '') ?? '',
];
$hadApiKey = SmsSettingsService::hasStoredApiKey();

function ok(bool $cond, string $label): void
{
    global $fail;
    echo ($cond ? 'OK: ' : 'FAIL: ') . $label . "\n";
    if (!$cond) {
        $fail++;
    }
}

function sm_reset_settings(): void
{
    AppSettings::setMany([
        'sms.operation_mode' => ['value' => OtpSmsService::MODE_LIVE],
        'sms.enabled' => ['value' => '1'],
        'sms.provider' => ['value' => SmsSettingsService::PROVIDER_STARTMESSAGING],
        'sms.api_url' => ['value' => StartMessagingSmsService::DEFAULT_BASE_URL],
        'sms.sender_id' => ['value' => ''],
        'sms.dlt.otp' => ['value' => 'tpl-otp-uuid-test'],
        'sms.startmessaging.variable_app_name' => ['value' => '1'],
        'sms.startmessaging.variable_expiry' => ['value' => '0'],
    ]);
    AppSettings::set(SmsSettingsService::KEY_API_KEY, 'test-api-key-secret', true);
    AppSettings::resetCache();
}

sm_reset_settings();
StartMessagingSmsService::$testRequestHandler = null;

// Empty sender ID allowed for save validation
$saveErr = SmsSettingsService::validateSaveFields(
    true,
    SmsSettingsService::PROVIDER_STARTMESSAGING,
    StartMessagingSmsService::DEFAULT_BASE_URL,
    '',
    ''
);
ok($saveErr === [], 'Empty Sender ID passes validation for StartMessaging');
ok(
    SmsSettingsService::readinessErrorForSend() === null,
    'Readiness OK with empty Sender ID when template + key set'
);

$httpErr = SmsSettingsService::validateSaveFields(
    true,
    SmsSettingsService::PROVIDER_HTTP,
    'https://sms.example.com/send',
    '',
    ''
);
ok(isset($httpErr['sender_id']), 'Empty Sender ID still blocked for HTTP provider');

$captured = [];
StartMessagingSmsService::$testRequestHandler = static function (
    string $method,
    string $url,
    ?array $json,
    array $headers,
    ?string $a,
    ?string $b
) use (&$captured): array {
    $captured = compact('method', 'url', 'json', 'headers');

    return [
        'status' => 201,
        'body' => json_encode(['success' => true, 'data' => ['messageId' => 'msg-abc-123']], JSON_THROW_ON_ERROR),
        'json' => ['success' => true, 'data' => ['messageId' => 'msg-abc-123']],
    ];
};

$result = StartMessagingSmsService::sendOtp('9876543210', '654321', 300);
ok(!empty($result['ok']), 'Successful send parses HTTP 201');
ok(($result['message_id'] ?? '') === 'msg-abc-123', 'Message ID from data.messageId');

ok($captured['method'] === 'POST', 'Uses POST');
ok(str_ends_with($captured['url'], '/otp/send'), 'Targets /otp/send');
ok(($captured['json']['phoneNumber'] ?? '') === '+919876543210', 'phoneNumber E.164');
ok(($captured['json']['templateId'] ?? '') === 'tpl-otp-uuid-test', 'templateId from sms.dlt.otp');
ok(($captured['json']['variables']['otp'] ?? '') === '654321', 'variables.otp set');
ok(($captured['json']['variables']['appName'] ?? '') !== '', 'variables.appName when enabled');
ok(!isset($captured['json']['variables']['expiry']), 'variables.expiry omitted when disabled');

$hasApiKeyHeader = false;
foreach ($captured['headers'] as $h) {
    if (stripos($h, 'X-API-Key:') === 0 && strpos($h, 'test-api-key-secret') !== false) {
        $hasApiKeyHeader = true;
    }
}
ok($hasApiKeyHeader, 'Sends X-API-Key authentication header');

AppSettings::set('sms.startmessaging.variable_expiry', '1');
AppSettings::set('sms.startmessaging.variable_app_name', '0');
AppSettings::resetCache();
StartMessagingSmsService::$testRequestHandler = static function (
    string $method,
    string $url,
    ?array $json,
    array $headers,
    ?string $a,
    ?string $b
) use (&$captured): array {
    $captured['json'] = $json;

    return ['status' => 201, 'body' => '{}', 'json' => ['success' => true, 'data' => ['messageId' => 'x']]];
};
StartMessagingSmsService::sendOtp('9876543210', '111222', 120);
ok(($captured['json']['variables']['expiry'] ?? '') === '2', 'expiry minutes from TTL when enabled');
ok(!isset($captured['json']['variables']['appName']), 'appName omitted when disabled');

StartMessagingSmsService::$testRequestHandler = static function (): array {
    return [
        'status' => 401,
        'body' => '{"success":false,"message":"Invalid API key"}',
        'json' => ['success' => false, 'message' => 'Invalid API key'],
    ];
};
$bad = StartMessagingSmsService::sendOtp('9876543210', '123456', 300);
ok(empty($bad['ok']), 'HTTP 401 treated as failure');
ok(strpos((string) ($bad['error'] ?? ''), 'Invalid API key') === false, 'Failure message does not echo provider secret text');
ok(strpos((string) ($bad['error'] ?? ''), 'test-api-key') === false, 'Failure message does not contain configured API key');

StartMessagingSmsService::$testRequestHandler = static function (): array {
    return ['status' => 0, 'body' => '', 'error' => 'timeout'];
};
$timeout = StartMessagingSmsService::sendOtp('9876543210', '123456', 300);
ok(empty($timeout['ok']), 'Transport timeout treated as failure');

AppSettings::set(SmsSettingsService::KEY_API_KEY, '', true);
AppSettings::resetCache();
StartMessagingSmsService::$testRequestHandler = static function (): array {
    return ['status' => 201, 'body' => '', 'json' => ['success' => true, 'data' => ['messageId' => 'x']]];
};
$noKey = StartMessagingSmsService::sendOtp('9876543210', '123456', 300);
ok(empty($noKey['ok']), 'Missing API key blocks send before HTTP');

$parsed = StartMessagingSmsService::parseSendResponse(201, ['success' => true, 'data' => ['messageId' => 'mid-1']], '');
ok(!empty($parsed['ok']) && $parsed['message_id'] === 'mid-1', 'parseSendResponse success path');
$parsedBad = StartMessagingSmsService::parseSendResponse(201, ['success' => true, 'data' => []], '');
ok(empty($parsedBad['ok']), 'parseSendResponse rejects missing messageId');

StartMessagingSmsService::$testRequestHandler = null;
foreach ($settingsBackup as $key => $val) {
    AppSettings::set($key, (string) $val);
}
if (!$hadApiKey) {
    AppSettings::set(SmsSettingsService::KEY_API_KEY, '', true);
}
AppSettings::resetCache();

echo $fail === 0 ? "All StartMessaging SMS tests passed.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
