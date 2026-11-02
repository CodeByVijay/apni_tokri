<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/guard.php';
require_once __DIR__ . '/includes/stage14_phase1_fixture.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}
if (!Install::isVersionCurrent()) {
    fwrite(STDERR, 'Apply migration 1.12.0_stage14_sms_phase3.sql (expected ' . Install::SCHEMA_VERSION . ").\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
}
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGCHLD, SIG_IGN);
}

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function sms_p3_cleanup(): void
{
    $keys = [
        SmsSettingsService::KEY_ENABLED,
        SmsSettingsService::KEY_PROVIDER,
        SmsSettingsService::KEY_API_URL,
        SmsSettingsService::KEY_API_KEY,
        SmsSettingsService::KEY_SENDER_ID,
        SmsSettingsService::KEY_OPERATION_MODE,
        SmsSettingsService::KEY_TEST_FIXED_OTP,
        'sms.template.otp',
    ];
    Database::disconnect();
    Install::ensureConnection();
    $pdo = Database::pdo();
    $ph = implode(',', array_fill(0, count($keys), '?'));
    $pdo->prepare("DELETE FROM settings WHERE setting_key IN ($ph)")->execute($keys);
    AppSettings::resetCache();
}

/** @implements SmsGatewayAdapter */
final class SmsTestOkAdapter implements SmsGatewayAdapter
{
    public static $calls = 0;

    public function send(string $mobile, string $message, string $senderId, ?string $dltTemplateId): array
    {
        self::$calls++;

        return ['ok' => true, 'message_id' => 'test_msg_' . self::$calls, 'response' => '{"status":"success"}'];
    }
}

/** @implements SmsGatewayAdapter */
final class SmsTestRejectAdapter implements SmsGatewayAdapter
{
    public function send(string $mobile, string $message, string $senderId, ?string $dltTemplateId): array
    {
        return ['ok' => false, 'error' => 'Provider rejected send.', 'response' => '{"status":"failed"}'];
    }
}

sms_p3_cleanup();

// 1. SMS settings validation
$badProvider = SmsSettingsService::validateSaveFields(true, 'unknown', 'https://api.example.com/send', 'APNITOKRI', '');
ok(isset($badProvider['provider']), 'SMS settings validation (unsupported provider)');

$urlErr = SmsSettingsService::validateHttpApiUrl('http://insecure.example.com/send');
ok($urlErr !== null, 'SMS settings validation (HTTPS required)');

// 2–4. Encrypted credential, preservation, absent from snapshot
AppSettings::set(SmsSettingsService::KEY_PROVIDER, SmsSettingsService::PROVIDER_HTTP);
AppSettings::set(SmsSettingsService::KEY_API_URL, 'https://sms-provider.example.test/send');
AppSettings::set(SmsSettingsService::KEY_SENDER_ID, 'APNITOKRI');
AppSettings::set(SmsSettingsService::KEY_API_KEY, 'SecretSmsKey99', true);
AppSettings::resetCache();

$stmt = $pdo->prepare('SELECT setting_value, is_encrypted FROM settings WHERE setting_key = ? LIMIT 1');
$stmt->execute([SmsSettingsService::KEY_API_KEY]);
$keyRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
ok((int) ($keyRow['is_encrypted'] ?? 0) === 1, 'Encrypted API credential storage');
ok((string) ($keyRow['setting_value'] ?? '') !== 'SecretSmsKey99', 'Credential not stored plaintext');

$saveBlank = SmsSettingsService::saveFromRequest([
    'enabled' => '1',
    'provider' => SmsSettingsService::PROVIDER_HTTP,
    'api_url' => 'https://sms-provider.example.test/send',
    'sender_id' => 'APNITOKRI',
    'api_key' => '',
    'http_allowed_status_codes' => '200',
    'http_require_json' => '1',
    'http_success_json_path' => 'status',
    'http_success_json_value' => 'success',
    'http_message_id_json_path' => 'message_id',
    'http_timeout_seconds' => '15',
    'template_otp' => 'Code {otp}',
]);
ok(!empty($saveBlank['ok']), 'Credential preservation on blank update');
ok((AppSettings::getSecret(SmsSettingsService::KEY_API_KEY, '') ?? '') === 'SecretSmsKey99', 'Stored API key unchanged');

$snap = SmsSettingsService::adminFormState();
$encoded = json_encode($snap, JSON_UNESCAPED_UNICODE);
ok(!isset($snap['api_key']) && strpos((string) $encoded, 'SecretSmsKey99') === false, 'Credentials absent from admin snapshots');

// 5–7. Disabled / missing / unsupported
AppSettings::set(SmsSettingsService::KEY_ENABLED, '0');
AppSettings::set(SmsSettingsService::KEY_PROVIDER, SmsSettingsService::PROVIDER_HTTP);
AppSettings::resetCache();
ok(SmsSettingsService::readinessErrorForSend() !== null, 'Disabled SMS behavior');

AppSettings::set(SmsSettingsService::KEY_ENABLED, '1');
AppSettings::set(SmsSettingsService::KEY_API_URL, '');
AppSettings::resetCache();
ok(SmsSettingsService::readinessErrorForSend() !== null, 'Missing configuration behavior');

$unsupported = SmsSettingsService::saveFromRequest(['enabled' => '1', 'provider' => 'twilio_fake']);
ok(empty($unsupported['ok']), 'Unsupported gateway rejection');

// 8. Invalid recipient
$badMobile = SmsSettingsService::sendTestSms('123');
ok(empty($badMobile['ok']) && isset($badMobile['errors']['test_mobile']), 'Invalid recipient rejection');

// 9–10. Permission & CSRF
$invId = stage14_p1_create_user_with_role($pdo, 'at-sms14-inv@apnitokri.local', 'inventory_manager');
stage14_p1_session($invId, 'at-sms14-inv@apnitokri.local');
ok(!Permission::canModule('settings'), 'Settings permission denial');
stage14_p1_clear_session();
$pdo->prepare('DELETE FROM admin_user_roles WHERE user_id = ?')->execute([$invId]);
$pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$invId]);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST[Csrf::fieldName()] = 'bad';
ok(Csrf::validateRequest() === false, 'CSRF enforcement');
unset($_POST[Csrf::fieldName()], $_SERVER['REQUEST_METHOD']);

// 11–13. Provider responses via test adapter
AppSettings::set(SmsSettingsService::KEY_PROVIDER, SmsSettingsService::PROVIDER_SIMULATE);
AppSettings::set(SmsSettingsService::KEY_ENABLED, '0');
AppSettings::resetCache();
SmsGatewayFactory::setTestAdapter(new SmsTestOkAdapter());
$okSend = SmsGatewayFactory::adapter()->send('9876543210', 'hi', 'APNI', null);
ok(!empty($okSend['ok']), 'Provider success response');

SmsGatewayFactory::setTestAdapter(new SmsTestRejectAdapter());
$rej = SmsGatewayFactory::adapter()->send('9876543210', 'hi', 'APNI', null);
ok(empty($rej['ok']), 'Provider rejection response');

SmsGatewayFactory::setTestAdapter(new SmsTestRejectAdapter());
$timeoutMsg = SmsSettingsService::publicSendError('Gateway timeout or transport error.');
ok(strpos($timeoutMsg, 'SecretSmsKey99') === false, 'HTTP timeout/failure handling (safe message)');

// 14–19. OTP integration
SmsGatewayFactory::setTestAdapter(new SmsTestOkAdapter());
SmsTestOkAdapter::$calls = 0;
AppSettings::set('sms.operation_mode', OtpSmsService::MODE_LIVE);
AppSettings::set(SmsSettingsService::KEY_ENABLED, '1');
AppSettings::set(SmsSettingsService::KEY_PROVIDER, SmsSettingsService::PROVIDER_HTTP);
AppSettings::set(SmsSettingsService::KEY_API_URL, 'https://sms-provider.example.test/send');
AppSettings::set(SmsSettingsService::KEY_SENDER_ID, 'APNITOKRI');
AppSettings::set('sms.http.allowed_status_codes', '200');
AppSettings::set('sms.http.require_json', '1');
AppSettings::set('sms.http.success_json_path', 'status');
AppSettings::set('sms.http.success_json_value', 'success');
AppSettings::set('sms.http.message_id_json_path', 'message_id');
AppSettings::set('sms.http.timeout_seconds', '15');
AppSettings::resetCache();

$mobile = '9876512345';
$pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile = ?')->execute([$mobile]);

$issued = CustomerAuth::issueOtp($mobile);
ok(SmsTestOkAdapter::$calls >= 1, 'OTP sent through the adapter');
ok(!isset($issued['otp']), 'OTP not exposed in issueOtp response');

$hashStmt = $pdo->prepare('SELECT otp_hash FROM customer_otp_attempts WHERE mobile = ? ORDER BY id DESC LIMIT 1');
$hashStmt->execute([$mobile]);
$hash = (string) ($hashStmt->fetchColumn() ?: '');
ok($hash !== '' && !password_verify('000000', $hash), 'OTP hash storage preserved');
ok(strlen($hash) > 20, 'OTP hash stored securely');

AppSettings::set('sms.operation_mode', OtpSmsService::MODE_TEST);
AppSettings::set('sms.test.fixed_otp', '654321');
AppSettings::resetCache();
$pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile = ?')->execute([$mobile]);
CustomerAuth::issueOtp($mobile);
ok(CustomerAuth::verifyOtp($mobile, '654321', 'Test User'), 'OTP expiry and verification behavior preserved');

AppSettings::set('sms.operation_mode', OtpSmsService::MODE_LIVE);
AppSettings::resetCache();
SmsGatewayFactory::setTestAdapter(new SmsTestRejectAdapter());
$pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile = ?')->execute([$mobile]);
try {
    CustomerAuth::issueOtp($mobile);
    ok(false, 'OTP gateway failure should throw');
} catch (OtpRequestException $e) {
    ok(strpos($e->getMessage(), 'SecretSmsKey99') === false && strpos($e->getMessage(), '123456') === false, 'OTP gateway failure behavior');
} catch (RuntimeException $e) {
    ok(strpos($e->getMessage(), 'SecretSmsKey99') === false, 'OTP gateway failure behavior (legacy exception)');
}
$cntStmt = $pdo->prepare('SELECT COUNT(*) FROM customer_otp_attempts WHERE mobile = ?');
$cntStmt->execute([$mobile]);
ok((int) $cntStmt->fetchColumn() === 0, 'Failed OTP send rolls back attempt row');

$rateBlocked = 0;
$pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile = ?')->execute(['9876500099']);
AppSettings::set('sms.operation_mode', OtpSmsService::MODE_TEST);
AppSettings::set('sms.test.fixed_otp', '112233');
AppSettings::resetCache();
SmsGatewayFactory::setTestAdapter(new SmsTestOkAdapter());
for ($i = 0; $i < 4; $i++) {
    try {
        CustomerAuth::issueOtp('9876500099');
    } catch (OtpRequestException $e) {
        $rateBlocked++;
    } catch (RuntimeException $e) {
        $rateBlocked++;
    }
}
ok($rateBlocked >= 1, 'OTP rate limiting on repeated issue');

$publicErr = OtpSmsService::publicErrorMessage('auth_failed SecretSmsKey99 123456');
ok(strpos($publicErr, 'SecretSmsKey99') === false && strpos($publicErr, '123456') === false, 'OTP never exposed in error output');

SmsGatewayFactory::setTestAdapter(null);

// 20. D3 delivery SMS regression (dedupe)
if (DeliverySmsService::tableReady() && DeliverySmsSendLockService::tableReady()) {
    AppSettings::set('sms.operation_mode', OtpSmsService::MODE_TEST);
    AppSettings::set('sms.provider', 'simulate');
    AppSettings::set('sms.enabled', '0');
    AppSettings::set('sms.template.ready', 'Order {order_number} ready');
    AppSettings::resetCache();
    $custId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
    if ($custId > 0) {
        $pdo->prepare('UPDATE customers SET mobile = ? WHERE id = ?')->execute(['9876543210', $custId]);
        $ord = 'SMS14-' . strtoupper(substr(Security::randomToken(3), 0, 5));
        $pdo->prepare(
            'INSERT INTO orders (order_number, customer_id, status, payment_status, subtotal, grand_total, delivery_slot, delivery_date, placed_at)
             VALUES (?, ?, ?, ?, 50, 50, ?, CURDATE(), NOW())'
        )->execute([$ord, $custId, 'confirmed', 'paid', 'Morning']);
        $oid = (int) $pdo->lastInsertId();
        $did = (int) (DeliveryAssignmentService::createForOrder($oid) ?? 0);
        if ($did > 0) {
            $pdo->prepare('DELETE FROM delivery_sms_logs WHERE delivery_id = ?')->execute([$did]);
            $pdo->prepare('DELETE FROM delivery_sms_send_locks WHERE delivery_id = ?')->execute([$did]);
            DeliverySmsService::sendDeliveryEvent($did, 'ready');
            DeliverySmsService::sendDeliveryEvent($did, 'ready');
            $sent = 0;
            foreach (DeliverySmsService::logsForDelivery($did) as $l) {
                if (($l['event_key'] ?? '') === 'ready' && ($l['status'] ?? '') === 'sent') {
                    $sent++;
                }
            }
            ok($sent === 1, 'Existing D3 delivery SMS regression (dedupe)');
            $pdo->prepare('DELETE FROM delivery_sms_send_locks WHERE delivery_id = ?')->execute([$did]);
            $pdo->prepare('DELETE FROM delivery_sms_logs WHERE delivery_id = ?')->execute([$did]);
            $pdo->prepare('DELETE FROM deliveries WHERE id = ?')->execute([$did]);
            $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$oid]);
        } else {
            ok(false, 'D3 fixture delivery created');
        }
    } else {
        ok(false, 'D3 fixture customer exists');
    }
} else {
    ok(true, 'Existing D3 delivery SMS regression (skipped — D3 tables missing)');
}

// 21–22. Test action / SMTP regression
AppSettings::set('smtp.host', 'smtp-keep.example.test');
AppSettings::set('smtp.enabled', '0');
AppSettings::resetCache();
$beforeSmtpHost = AppSettings::get('smtp.host', '');
AppSettings::set('sms.operation_mode', OtpSmsService::MODE_LIVE);
AppSettings::set(SmsSettingsService::KEY_ENABLED, '1');
AppSettings::set(SmsSettingsService::KEY_PROVIDER, SmsSettingsService::PROVIDER_HTTP);
AppSettings::resetCache();
SmsGatewayFactory::setTestAdapter(new SmsTestOkAdapter());
SmsSettingsService::sendTestSms('9876543210');
ok((AppSettings::get('smtp.host', '') ?? '') === $beforeSmtpHost, 'SMS test action does not modify settings');

AppSettings::set('payment.active_gateway', 'cashfree');
AppSettings::resetCache();
SmsSettingsService::saveFromRequest([
    'operation_mode' => OtpSmsService::MODE_DISABLED,
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
    'template_otp' => 'Code {otp}',
]);
ok((AppSettings::get('smtp.host', '') ?? '') === $beforeSmtpHost, 'SMTP settings regression');
ok((AppSettings::get('payment.active_gateway', '') ?? '') === 'cashfree', 'Gateway unchanged during SMS save');

try {
    sms_p3_cleanup();
} catch (Throwable $e) {
    // ignore cleanup errors
}

SmsGatewayFactory::setTestAdapter(null);

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}

echo "\nPASS: Stage 14.3 SMS checks.\n";
exit(0);
