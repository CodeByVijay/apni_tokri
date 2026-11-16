<?php

declare(strict_types=1);

/**
 * Platform flags, storefront gating, preserve-on-edit, activation, rollback.
 *
 * php scripts/run-in-disposable-db.php --migrate-firebase-push scripts/test-firebase-platform-integration.php
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

function platOk(bool $c, string $l): void
{
    global $ok, $fail;
    if ($c) {
        ++$ok;
        fwrite(STDOUT, "OK  {$l}\n");
    } else {
        ++$fail;
        fwrite(STDERR, "FAIL {$l}\n");
    }
}

$pdo = Database::pdo();

$stmt = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'firebase_projects'
       AND COLUMN_NAME IN ('platform_web','platform_android')"
);
platOk((int) $stmt->fetchColumn() === 2, 'migration 1.39.4 platform columns');

$rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($rsa, $priv);

function saJson(string $projectId, string $priv): string
{
    return json_encode([
        'type' => 'service_account',
        'project_id' => $projectId,
        'client_email' => 'disp@test.iam.gserviceaccount.com',
        'private_key' => $priv,
    ], JSON_THROW_ON_ERROR);
}

FirebaseOAuth::$testHttpHandler = static fn () => [
    'ok' => true, 'status' => 200,
    'body' => '{"access_token":"mock","expires_in":3600}',
    'json' => ['access_token' => 'mock', 'expires_in' => 3600],
];

$webBase = [
    'firebase_project_number' => '999111222333',
    'web_api_key' => 'AIza_web',
    'web_app_id' => '1:999111222333:web:abc',
    'web_auth_domain' => 'w.firebaseapp.com',
    'web_storage_bucket' => 'w.appspot.com',
    'web_measurement_id' => 'G-WEB',
    'vapid_public_key' => 'BVapidWeb',
];
$andBase = [
    'android_app_id' => '1:999111222333:android:xyz',
    'android_package_name' => 'com.test.platform',
];

// Website-only
$saveWeb = FirebaseAdminService::saveProject(array_merge([
    'name' => 'Web Only',
    'firebase_project_id' => 'plat-web-only',
    'platform_web' => '1',
], $webBase), saJson('plat-web-only', $priv), null);
platOk(!empty($saveWeb['ok']), 'save website-only project');
$webId = (int) ($saveWeb['id'] ?? 0);

// Android-only
$saveAnd = FirebaseAdminService::saveProject(array_merge([
    'name' => 'Android Only',
    'firebase_project_id' => 'plat-android-only',
    'platform_android' => '1',
], $andBase), saJson('plat-android-only', $priv), null);
platOk(!empty($saveAnd['ok']), 'save android-only project');
$andId = (int) ($saveAnd['id'] ?? 0);

// Combined
$saveBoth = FirebaseAdminService::saveProject(array_merge([
    'name' => 'Both',
    'firebase_project_id' => 'plat-both',
    'platform_web' => '1',
    'platform_android' => '1',
], $webBase, $andBase), saJson('plat-both', $priv), null);
platOk(!empty($saveBoth['ok']), 'save combined project');
$bothId = (int) ($saveBoth['id'] ?? 0);

$rowWeb = FirebaseProjectManager::findById($webId);
platOk(!empty($rowWeb['platform_web']) && empty($rowWeb['platform_android']), 'web-only flags in DB');

FirebaseAdminService::activate($webId);
FirebaseProjectManager::resetCache();
platOk(FirebaseProjectManager::publicWebConfig() !== null, 'website-only active returns publicWebConfig');

FirebaseAdminService::activate($bothId);
FirebaseProjectManager::resetCache();
platOk(FirebaseProjectManager::publicWebConfig() !== null, 'combined active returns publicWebConfig');

FirebaseAdminService::activate($andId);
FirebaseProjectManager::resetCache();
platOk(FirebaseProjectManager::publicWebConfig() === null, 'android-only active does not expose web config');

$regWeb = PushTokenService::register('web', 'c8mPlatTestFid0123456789', 'granted', null, null, 'fid');
platOk(empty($regWeb['ok']), 'web registration blocked when website platform disabled');

$regAnd = PushTokenService::register('android', str_repeat('b', 140), 'granted', null, '1.0', 'fcm_token');
platOk(!empty($regAnd['ok']), 'android FCM registration on android-only project');
platOk(($regAnd['registration_id'] ?? 0) > 0, 'android registration id returned');

// Preserve web fields when unchecking web on edit
$bothRow = FirebaseProjectManager::findById($bothId);
$preserve = FirebaseProjectForm::validateAndBuildFields([
    'name' => 'Both',
    'firebase_project_id' => 'plat-both',
    'platform_android' => '1',
    'android_app_id' => '1:999111222333:android:xyz',
    'android_package_name' => 'com.test.platform',
    'web_api_key' => '',
    'web_app_id' => '',
], null, $bothRow);
platOk(!empty($preserve['ok']) && ($preserve['fields']['web_api_key'] ?? '') === 'AIza_web', 'uncheck web preserves saved web_api_key');

$pdo->prepare('UPDATE firebase_projects SET platform_web = 0, platform_android = 0 WHERE id = ?')->execute([$andId]);
FirebaseProjectManager::resetCache();
$failAnd = FirebaseAdminService::activate($andId);
platOk(empty($failAnd['ok']), 'cannot activate with no platform enabled');
$pdo->prepare('UPDATE firebase_projects SET platform_android = 1 WHERE id = ?')->execute([$andId]);

FirebaseAdminService::activate($bothId);
$prevId = (int) (AppSettings::get(PushSettings::KEY_ROLLBACK_PROJECT_ID, '0') ?? '0');
platOk($prevId === $andId, 'rollback pointer stores previous active');
$rb = FirebaseAdminService::rollbackToPrevious();
platOk(!empty($rb['ok']), 'rollback to previous project');
$active = FirebaseProjectManager::activeProject(false);
platOk($active !== null && (int) $active['id'] === $andId, 'rollback restored previous active project');

fwrite(STDOUT, "\n{$ok} passed, {$fail} failed\n");
exit($fail > 0 ? 1 : 0);
