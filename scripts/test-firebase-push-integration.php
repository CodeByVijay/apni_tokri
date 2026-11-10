<?php

declare(strict_types=1);

/**
 * Firebase push backend integration (disposable DB only).
 *
 * php scripts/run-in-disposable-db.php --migrate-firebase-push scripts/test-firebase-push-integration.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/disposable_db_runner.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();

$ok = 0;
$fail = 0;

function assertPush(bool $cond, string $label): void
{
    global $ok, $fail;
    if ($cond) {
        ++$ok;
        fwrite(STDOUT, "OK  {$label}\n");
    } else {
        ++$fail;
        fwrite(STDERR, "FAIL {$label}\n");
    }
}

$pdo = Database::pdo();

$tables = ['firebase_projects', 'device_push_registrations', 'push_notification_templates', 'push_notification_logs'];
foreach ($tables as $t) {
    assertPush(PushSchema::tableReady($t), "table {$t} exists");
}

$stmt = $pdo->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_push_registrations' AND COLUMN_NAME = 'target_type'"
);
assertPush($stmt->fetchColumn() !== false, 'column device_push_registrations.target_type');

$schemaVer = (string) $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version' LIMIT 1")->fetchColumn();
assertPush(
    str_contains($schemaVer, '1.39.3')
    || str_contains($schemaVer, '1.39.2')
    || str_contains($schemaVer, '1.39.1')
    || str_contains($schemaVer, '1.39.0'),
    'schema_version reflects firebase migrations'
);

$perm = $pdo->query("SELECT COUNT(*) FROM admin_permissions WHERE slug = 'firebase.manage'")->fetchColumn();
assertPush((int) $perm === 1, 'permission firebase.manage');

// --- Fresh project + encrypted credential round-trip ---
$rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($rsa, $priv);
$sa = [
    'type' => 'service_account',
    'project_id' => 'disposable-fcm-test',
    'client_email' => 'disp@test.iam.gserviceaccount.com',
    'private_key' => $priv,
];
$parsed = FirebaseCredentialManager::parseServiceAccountUpload(json_encode($sa, JSON_THROW_ON_ERROR));
assertPush(!empty($parsed['ok']), 'service account parse');
$enc = FirebaseCredentialManager::encryptForStorage($sa);
$dec = FirebaseCredentialManager::decryptStored($enc);
assertPush(is_array($dec) && ($dec['client_email'] ?? '') === $sa['client_email'], 'SecretStore round-trip');

$save = FirebaseAdminService::saveProject([
    'name' => 'Disposable Test',
    'firebase_project_id' => 'disposable-fcm-test',
    'firebase_project_number' => '123456',
    'web_api_key' => 'AIza_test',
    'web_app_id' => '1:123:web:abc',
    'vapid_public_key' => 'BTestVapid',
    'android_package_name' => 'com.test.app',
], json_encode($sa, JSON_THROW_ON_ERROR));
assertPush(!empty($save['ok']), 'create firebase project');
$projectId = (int) ($save['id'] ?? 0);

FirebaseOAuth::$testHttpHandler = static fn () => [
    'ok' => true, 'status' => 200, 'body' => '{"access_token":"mock","expires_in":3600}',
    'json' => ['access_token' => 'mock', 'expires_in' => 3600],
];
$testCred = FirebaseAdminService::testExisting($projectId);
assertPush(!empty($testCred['ok']), 'credential test (mocked OAuth)');

$badSa = $sa;
$badSa['private_key'] = str_replace("\n", '', $badSa['private_key']);
FirebaseOAuth::$testHttpHandler = static fn () => [
    'ok' => false, 'status' => 401, 'body' => '', 'json' => null,
];
// activate requires real oauth success - mock success first for project A
FirebaseOAuth::$testHttpHandler = static fn () => [
    'ok' => true, 'status' => 200, 'body' => '{"access_token":"mock","expires_in":3600}',
    'json' => ['access_token' => 'mock', 'expires_in' => 3600],
];
$act = FirebaseAdminService::activate($projectId);
assertPush(!empty($act['ok']), 'activate project');

$saveB = FirebaseAdminService::saveProject([
    'name' => 'Disposable B',
    'firebase_project_id' => 'disposable-fcm-b',
    'web_api_key' => 'key',
    'web_app_id' => 'app',
    'vapid_public_key' => 'vapid',
], json_encode($sa, JSON_THROW_ON_ERROR));
$idB = (int) ($saveB['id'] ?? 0);
FirebaseOAuth::$testHttpHandler = static fn () => ['ok' => false, 'status' => 401, 'body' => '', 'json' => null];
$badAct = FirebaseAdminService::activate($idB);
assertPush(empty($badAct['ok']), 'reject activate when credential test fails');

$active = FirebaseProjectManager::activeProject(false);
assertPush($active !== null && (int) $active['id'] === $projectId, 'previous active unchanged after failed activation');

FirebaseOAuth::$testHttpHandler = static fn () => [
    'ok' => true, 'status' => 200, 'body' => '{"access_token":"mock2","expires_in":3600}',
    'json' => ['access_token' => 'mock2', 'expires_in' => 3600],
];
$actB = FirebaseAdminService::activate($idB);
assertPush(!empty($actB['ok']), 'activate project B');
$rollbackId = (int) (AppSettings::get(PushSettings::KEY_ROLLBACK_PROJECT_ID, '0') ?? '0');
assertPush($rollbackId === $projectId, 'rollback pointer stores previous active');

FirebaseOAuth::$testHttpHandler = static fn () => [
    'ok' => true, 'status' => 200, 'body' => '{"access_token":"mock","expires_in":3600}',
    'json' => ['access_token' => 'mock', 'expires_in' => 3600],
];
$rb = FirebaseAdminService::rollbackToPrevious();
assertPush(!empty($rb['ok']), 'rollback to previous project');

AppSettings::set(PushSettings::KEY_ENABLED, '1');
AppSettings::set(PushSettings::KEY_DRY_RUN, '1');
assertPush(PushSettings::isDryRun(), 'push dry-run setting');

// --- Registrations ---
$custId = (int) $pdo->query('SELECT id FROM customers ORDER BY id ASC LIMIT 1')->fetchColumn();
$_SERVER['HTTP_USER_AGENT'] = 'IntegrationTest/1.0';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$regWeb = PushTokenService::register('web', 'c8mTestFid0123456789ab', 'granted', $custId, null, 'fid');
assertPush(!empty($regWeb['ok']), 'register web FID');
$regAnd = PushTokenService::register('android', str_repeat('a', 140), 'granted', $custId, '1.0', 'fcm_token');
assertPush(!empty($regAnd['ok']), 'register android token');

$row = $pdo->prepare('SELECT target_type FROM device_push_registrations WHERE id = ?');
$row->execute([(int) ($regWeb['registration_id'] ?? 0)]);
assertPush((string) $row->fetchColumn() === 'fid', 'web target_type fid');

$rev = PushTokenService::adminRevokeById((int) ($regWeb['registration_id'] ?? 0));
assertPush(!empty($rev['ok']), 'admin revoke registration');

// --- Templates ---
$tplCount = (int) $pdo->query('SELECT COUNT(*) FROM push_notification_templates')->fetchColumn();
assertPush($tplCount >= 10, 'seeded push templates including subscription pause/resume');
$pauseTpl = PushTemplateRepository::findActive('subscription.paused.push');
$resTpl = PushTemplateRepository::findActive('subscription.resumed.push');
assertPush($pauseTpl !== null && $resTpl !== null, 'subscription pause/resume templates');

// --- Idempotency / dry-run send ---
$idempotency = 'push:integration:test:' . bin2hex(random_bytes(4));
$r1 = PushNotificationService::sendToCustomer(
    $custId,
    'order.confirmed.push',
    ['customer_name' => 'T', 'order_number' => 'ORD1', 'order_total' => '10', 'order_id' => '1'],
    $idempotency,
    'integration.test'
);
assertPush(!empty($r1['dry_run']), 'customer send dry-run');
$r2 = PushNotificationService::sendToCustomer(
    $custId,
    'order.confirmed.push',
    ['customer_name' => 'T', 'order_number' => 'ORD1', 'order_total' => '10', 'order_id' => '1'],
    $idempotency,
    'integration.test'
);
assertPush(!empty($r2['duplicate']), 'idempotency duplicate');

$logs = (int) $pdo->query('SELECT COUNT(*) FROM push_notification_logs')->fetchColumn();
assertPush($logs >= 1, 'notification log rows written');

// --- URL validation ---
$badUrl = PushUrlValidator::resolveClickUrl('https://evil.example/phish', []);
$goodUrl = PushUrlValidator::resolveClickUrl('/order-details.php?id=1', ['order_id' => '5']);
assertPush($badUrl === null && $goodUrl !== null, 'click URL host validation');

// --- Delete guard ---
$pdo->prepare(
    'INSERT INTO device_push_registrations (customer_id, firebase_project_id, platform, target_type, fcm_token, device_key, status)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
)->execute([$custId, $projectId, 'android', 'fcm_token', str_repeat('z', 50), hash('sha256', 'x'), 'active']);
$del = FirebaseAdminService::delete($projectId);
assertPush(empty($del['ok']), 'delete blocked with active device registrations');

// --- Rate limit ---
AppSettings::set(PushSettings::KEY_DRY_RUN, '1');
$adminId = (int) $pdo->query('SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$_SESSION['admin_user_id'] = $adminId > 0 ? $adminId : 1;
for ($i = 0; $i < PushSettings::MANUAL_SEND_DAILY_MAX + 2; $i++) {
    PushSettings::recordManualSendAttempt();
}
assertPush(PushSettings::manualSendRemainingToday() === 0, 'manual send rate limit cap');

// --- OAuth cache invalidation on activate ---
$cacheKey = 'firebase.oauth.cache.' . $idB;
AppSettings::set($cacheKey, json_encode(['token' => 'old', 'exp' => time() + 3600], JSON_THROW_ON_ERROR), true);
FirebaseAdminService::activate($idB);
$cached = AppSettings::getSecret($cacheKey, '');
assertPush($cached === null || $cached === '', 'oauth cache invalidated on activate (key removed or empty)');

fwrite(STDOUT, "\n{$ok} passed, {$fail} failed\n");
exit($fail > 0 ? 1 : 0);
