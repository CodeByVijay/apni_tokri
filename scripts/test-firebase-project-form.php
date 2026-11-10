<?php

declare(strict_types=1);

/**
 * Firebase project form validation (unit + disposable DB save scenarios).
 *
 * php scripts/test-firebase-project-form.php
 * php scripts/run-in-disposable-db.php --migrate-firebase-push scripts/test-firebase-project-form.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$ok = 0;
$fail = 0;

function fformOk(bool $cond, string $label): void
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

$existing = [
    'web_api_key' => 'keep-key',
    'web_app_id' => '1:111:web:old',
    'web_auth_domain' => 'old.firebaseapp.com',
    'web_storage_bucket' => 'old',
    'web_measurement_id' => 'G-OLD',
    'vapid_public_key' => 'Bold',
    'android_app_id' => '1:111:android:old',
    'android_package_name' => 'com.keep.app',
];

$webOnly = [
    'name' => 'Web Only',
    'firebase_project_id' => 'test-web-only',
    'platform_web' => '1',
    'firebase_project_number' => '111222333444',
    'web_api_key' => 'AIza_test',
    'web_app_id' => '1:111222333444:web:abc',
    'web_auth_domain' => 'x.firebaseapp.com',
    'web_storage_bucket' => 'x.appspot.com',
    'web_measurement_id' => 'G-TEST',
    'vapid_public_key' => 'BTestKey',
];
$v = FirebaseProjectForm::validateAndBuildFields($webOnly, null, $existing);
fformOk(!empty($v['ok']), 'web-only validates without android fields on edit');

$andOnly = [
    'name' => 'Android Only',
    'firebase_project_id' => 'test-android-only',
    'platform_android' => '1',
    'android_app_id' => '1:555666777888:android:xyz',
    'android_package_name' => 'com.example.app',
];
$v2 = FirebaseProjectForm::validateAndBuildFields($andOnly, null, null);
fformOk(empty($v2['ok']), 'android-only new project still requires service account');
$v2b = FirebaseProjectForm::validateAndBuildFields($andOnly, null, $existing);
fformOk(!empty($v2b['ok']), 'android-only validates without web fields on edit');
fformOk(($v2b['fields']['web_api_key'] ?? '') === 'keep-key', 'android-only edit preserves prior web fields');

$badSender = $webOnly;
$badSender['web_app_id'] = '1:999888777666:web:abc';
$v3 = FirebaseProjectForm::validateAndBuildFields($badSender, null, null);
fformOk(empty($v3['ok']), 'web app id sender mismatch rejected');

$switchAndroid = [
    'name' => 'Switch',
    'firebase_project_id' => 'switch-test',
    'platform_android' => '1',
    'android_app_id' => '1:555666777888:android:xyz',
    'android_package_name' => 'com.example.app',
    'web_api_key' => '',
    'web_app_id' => '',
];
$v4 = FirebaseProjectForm::validateAndBuildFields($switchAndroid, null, $existing);
fformOk(!empty($v4['ok']) && ($v4['fields']['web_api_key'] ?? '') === 'keep-key', 'uncheck web preserves stored web fields on edit');

$rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($rsa, $priv);
$saJson = json_encode([
    'type' => 'service_account',
    'project_id' => 'mismatch-proj',
    'client_email' => 'sa@test.iam.gserviceaccount.com',
    'private_key' => $priv,
], JSON_THROW_ON_ERROR);
$v5 = FirebaseProjectForm::validateAndBuildFields($webOnly, $saJson, null);
fformOk(empty($v5['ok']), 'service account project_id mismatch rejected');

$dbName = (string) (App::config()['database']['name'] ?? '');
$disposable = getenv('APNI_TOKRI_DISPOSABLE_DB') === '1' || preg_match('/^apni_tokri_(tmp|phase4|phase41|rl|sec_p1)_/i', $dbName);
if ($disposable) {
    TestScriptSafety::assertDisposableDatabaseOrAbort();
    FirebaseOAuth::$testHttpHandler = static fn () => [
        'ok' => true,
        'status' => 200,
        'body' => '{"access_token":"mock","expires_in":3600}',
        'json' => ['access_token' => 'mock', 'expires_in' => 3600],
    ];
    $sa = json_decode($saJson, true, 512, JSON_THROW_ON_ERROR);
    $sa['project_id'] = 'test-web-only';
    $saJsonOk = json_encode($sa, JSON_THROW_ON_ERROR);

    $save = FirebaseAdminService::saveProject($webOnly, $saJsonOk, null);
    fformOk(!empty($save['ok']), 'disposable save web-only project');
    $wid = (int) ($save['id'] ?? 0);

    $combined = array_merge($webOnly, [
        'firebase_project_id' => 'test-combined',
        'platform_web' => '1',
        'platform_android' => '1',
        'android_app_id' => '1:111222333444:android:def',
        'android_package_name' => 'com.example.combined',
    ]);
    $sa['project_id'] = 'test-combined';
    $saveC = FirebaseAdminService::saveProject($combined, json_encode($sa, JSON_THROW_ON_ERROR), null);
    fformOk(!empty($saveC['ok']), 'disposable save combined project');
    $cid = (int) ($saveC['id'] ?? 0);

    $row = FirebaseProjectManager::findById($wid);
    fformOk(is_array($row) && !empty($row['platform_web']) && empty($row['platform_android']), 'web-only platform flags stored');

    $act = FirebaseAdminService::activate($wid);
    fformOk(!empty($act['ok']), 'activate web-only with mocked oauth');
    $rb = FirebaseAdminService::rollbackToPrevious();
    fformOk(empty($rb['ok']), 'rollback with no previous id on first activate (expected)');

    FirebaseAdminService::activate($cid);
    $prev = FirebaseAdminService::rollbackToPrevious();
    fformOk(!empty($prev['ok']), 'rollback to previous active project');

    $expiredAct = FirebaseAdminService::activate($cid);
    fformOk(!empty($expiredAct['ok']), 're-activate combined project');
}

fwrite(STDOUT, "\n{$ok} passed, {$fail} failed\n");
exit($fail > 0 ? 1 : 0);
