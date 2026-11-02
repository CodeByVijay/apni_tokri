<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/guard.php';
require_once __DIR__ . '/includes/stage14_phase1_fixture.php';
require_once __DIR__ . '/includes/smtp_test_peer.php';

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

function smtp_p2_cleanup(): void
{
    Database::disconnect();
    Install::ensureConnection();
    $pdo = Database::pdo();
    $keys = [
        SmtpSettingsService::KEY_ENABLED,
        SmtpSettingsService::KEY_HOST,
        SmtpSettingsService::KEY_PORT,
        SmtpSettingsService::KEY_ENCRYPTION,
        SmtpSettingsService::KEY_AUTH_ENABLED,
        SmtpSettingsService::KEY_USERNAME,
        SmtpSettingsService::KEY_PASSWORD,
        SmtpSettingsService::KEY_FROM_EMAIL,
        SmtpSettingsService::KEY_FROM_NAME,
        SmtpSettingsService::KEY_TIMEOUT,
    ];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $pdo->prepare("DELETE FROM settings WHERE setting_key IN ($placeholders)")->execute($keys);
    AppSettings::resetCache();
}

smtp_p2_cleanup();

// 1. SMTP settings validation
$bad = SmtpSettingsService::validateFields(
    true,
    '',
    'abc',
    'invalid_mode',
    true,
    '',
    'not-an-email',
    '0',
    '',
    false
);
ok(isset($bad['host']) && isset($bad['port']) && isset($bad['encryption']) && isset($bad['from_email']), 'SMTP settings validation');

// 2. Missing SMTP configuration
AppSettings::set(SmtpSettingsService::KEY_ENABLED, '1');
AppSettings::resetCache();
ok(SmtpSettingsService::readinessError() !== null, 'Missing SMTP configuration');

// 3. Disabled SMTP behavior
AppSettings::set(SmtpSettingsService::KEY_ENABLED, '0');
AppSettings::resetCache();
$mailer = new SmtpMailer();
$disabledSend = $mailer->send('a@example.com', 'Test', 'Body', null);
ok(empty($disabledSend['ok']) && strpos((string) ($disabledSend['error'] ?? ''), 'disabled') !== false, 'Disabled SMTP behavior');

// 4–6. Password preservation, encryption, not returned in admin output
AppSettings::set(SmtpSettingsService::KEY_ENABLED, '1');
AppSettings::set(SmtpSettingsService::KEY_HOST, 'smtp.example.test');
AppSettings::set(SmtpSettingsService::KEY_PORT, '587');
AppSettings::set(SmtpSettingsService::KEY_ENCRYPTION, SmtpSettingsService::ENCRYPTION_STARTTLS);
AppSettings::set(SmtpSettingsService::KEY_AUTH_ENABLED, '1');
AppSettings::set(SmtpSettingsService::KEY_USERNAME, 'mailer');
AppSettings::set(SmtpSettingsService::KEY_FROM_EMAIL, 'noreply@example.test');
AppSettings::set(SmtpSettingsService::KEY_PASSWORD, 'SecretPass123!', true);
AppSettings::resetCache();

$saveBlank = SmtpSettingsService::saveFromRequest([
    'enabled' => '1',
    'host' => 'smtp.example.test',
    'port' => '587',
    'encryption' => SmtpSettingsService::ENCRYPTION_STARTTLS,
    'auth_enabled' => '1',
    'username' => 'mailer',
    'from_email' => 'noreply@example.test',
    'from_name' => 'Apni Tokri',
    'timeout_seconds' => '15',
    'password' => '',
]);
ok(!empty($saveBlank['ok']), 'Password preservation when update password is blank');
ok((AppSettings::getSecret(SmtpSettingsService::KEY_PASSWORD, '') ?? '') === 'SecretPass123!', 'Stored password unchanged after blank update');

$stmt = $pdo->prepare('SELECT setting_value, is_encrypted FROM settings WHERE setting_key = ? LIMIT 1');
$stmt->execute([SmtpSettingsService::KEY_PASSWORD]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
ok((int) ($row['is_encrypted'] ?? 0) === 1, 'Encrypted password storage');
ok((string) ($row['setting_value'] ?? '') !== 'SecretPass123!', 'Password not stored plaintext in DB');

$adminState = SmtpSettingsService::adminFormState();
$encoded = json_encode($adminState, JSON_UNESCAPED_UNICODE);
ok(!isset($adminState['password']) && strpos((string) $encoded, 'SecretPass123!') === false, 'Password not returned in settings output');

// 7–9. Invalid sender, port, encryption
$invalidEmail = SmtpSettingsService::saveFromRequest([
    'enabled' => '1',
    'host' => 'smtp.example.test',
    'port' => '587',
    'encryption' => SmtpSettingsService::ENCRYPTION_NONE,
    'auth_enabled' => '1',
    'username' => 'mailer',
    'from_email' => 'bad',
    'timeout_seconds' => '15',
    'password' => '',
]);
ok(empty($invalidEmail['ok']), 'Invalid sender email rejection');

$invalidPort = SmtpSettingsService::validateFields(true, 'host.test', '70000', SmtpSettingsService::ENCRYPTION_NONE, false, '', 'a@b.co', '15', '', false);
ok(isset($invalidPort['port']), 'Invalid port rejection');

$invalidEnc = SmtpSettingsService::validateFields(true, 'host.test', '587', 'tls-wrong', false, '', 'a@b.co', '15', '', false);
ok(isset($invalidEnc['encryption']), 'Invalid encryption mode rejection');

// 10. Missing settings permission denial
$invId = stage14_p1_create_user_with_role($pdo, 'at-smtp14-inv@apnitokri.local', 'inventory_manager');
stage14_p1_session($invId, 'at-smtp14-inv@apnitokri.local');
ok(!Permission::canModule('settings'), 'Missing settings permission denial');
stage14_p1_clear_session();
$pdo->prepare('DELETE FROM admin_user_roles WHERE user_id = ?')->execute([$invId]);
$pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$invId]);

// 11. CSRF validation
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST[Csrf::fieldName()] = 'invalid-token';
ok(Csrf::validateRequest() === false, 'CSRF validation');
unset($_POST[Csrf::fieldName()]);
unset($_SERVER['REQUEST_METHOD']);

// 12–15. SMTP protocol handling via test peer
$baseConfig = [
    'enabled' => true,
    'host' => 'localhost',
    'port' => 2525,
    'encryption' => SmtpSettingsService::ENCRYPTION_NONE,
    'auth_enabled' => true,
    'username' => 'user@test',
    'password' => 'good-pass',
    'from_email' => 'from@example.test',
    'from_name' => 'Test',
    'timeout_seconds' => 5,
];

$authFail = smtp_test_peer_run_server(
    function ($server) {
        smtp_test_peer_auth_fail($server);
    },
    function () use ($baseConfig) {
        $mailer = new SmtpMailer($baseConfig);

        return $mailer->send('to@example.test', 'Subject', 'Hello', null);
    }
);
if (($authFail['error'] ?? '') === 'pcntl_unavailable') {
    ok(true, 'SMTP authentication failure handling (skipped — pcntl unavailable)');
} else {
    ok(empty($authFail['ok']), 'SMTP authentication failure handling');
}

SmtpMailer::setStreamConnector(function () {
    return false;
});
$connFail = (new SmtpMailer($baseConfig))->send('to@example.test', 'Subject', 'Hello', null);
SmtpMailer::setStreamConnector(null);
ok(empty($connFail['ok']), 'SMTP connection failure handling');

$reject = smtp_test_peer_run_server(
    function ($server) {
        smtp_test_peer_reject_rcpt($server);
    },
    function () use ($baseConfig) {
        $cfg = $baseConfig;
        $cfg['auth_enabled'] = false;
        $mailer = new SmtpMailer($cfg);

        return $mailer->send('bad@example.test', 'Subject', 'Hello', null);
    }
);
if (($reject['error'] ?? '') === 'pcntl_unavailable') {
    ok(true, 'SMTP server rejection handling (skipped — pcntl unavailable)');
} else {
    ok(empty($reject['ok']), 'SMTP server rejection handling');
}

$success = smtp_test_peer_run_server(
    function ($server) {
        smtp_test_peer_success_dialog($server, false, '', '');
    },
    function () use ($baseConfig) {
        $cfg = $baseConfig;
        $cfg['auth_enabled'] = false;
        $mailer = new SmtpMailer($cfg);

        return $mailer->send('to@example.test', 'Subject', 'Hello', null);
    }
);
if (($success['error'] ?? '') === 'pcntl_unavailable') {
    ok(true, 'Successful SMTP response handling (skipped — pcntl unavailable)');
} else {
    ok(!empty($success['ok']), 'Successful SMTP response handling');
}

Database::disconnect();
Install::ensureConnection();
AppSettings::resetCache();

// 16. No credentials in logs or error output
$publicMsg = SmtpMailer::publicErrorMessage('auth_failed');
ok(strpos($publicMsg, 'good-pass') === false && strpos($publicMsg, 'user@test') === false, 'No credentials in error output');

// 17. Test email endpoint does not modify settings
Install::ensureConnection();
AppSettings::resetCache();
AppSettings::set(SmtpSettingsService::KEY_HOST, 'keep-host.example.test');
AppSettings::set(SmtpSettingsService::KEY_PORT, '2525');
AppSettings::resetCache();
$beforeHost = AppSettings::get(SmtpSettingsService::KEY_HOST, '');
AppSettings::set(SmtpSettingsService::KEY_ENABLED, '0');
AppSettings::resetCache();
SmtpSettingsService::sendTestEmail('admin@example.test');
ok((AppSettings::get(SmtpSettingsService::KEY_HOST, '') ?? '') === $beforeHost, 'Test email endpoint does not modify settings');

// 18. Existing gateway settings regression
AppSettings::set('payment.active_gateway', 'razorpay');
AppSettings::set('payment.test_mode', '1');
AppSettings::resetCache();
SmtpSettingsService::saveFromRequest([
    'enabled' => '0',
    'host' => 'smtp.example.test',
    'port' => '587',
    'encryption' => SmtpSettingsService::ENCRYPTION_NONE,
    'auth_enabled' => '0',
    'username' => '',
    'from_email' => 'noreply@example.test',
    'from_name' => '',
    'timeout_seconds' => '15',
    'password' => '',
]);
ok((AppSettings::get('payment.active_gateway', '') ?? '') === 'razorpay', 'Existing gateway settings regression');
ok((AppSettings::get('payment.test_mode', '') ?? '') === '1', 'Gateway test mode unchanged');

try {
    smtp_p2_cleanup();
} catch (Throwable $e) {
    // Best-effort cleanup; do not fail the suite after assertions passed.
}

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}

echo "\nPASS: Stage 14.2 SMTP checks.\n";
exit(0);
