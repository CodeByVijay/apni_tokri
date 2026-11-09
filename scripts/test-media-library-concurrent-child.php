<?php

declare(strict_types=1);

/**
 * Subprocess worker for scripts/test-media-library-concurrent.php — not run directly.
 */
if (getenv('AT_ML_CONCURRENT_CHILD') !== '1') {
    fwrite(STDERR, "This script is only for concurrent Media Library subprocesses.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/lib/test_script_safety.php';

if (getenv('APNI_TOKRI_DISPOSABLE_DB') === '1') {
    $cfg = App::config();
    $db = $cfg['database'];
    $db['name'] = (string) (getenv('APNI_TOKRI_TEST_DB_NAME') ?: ($db['name'] ?? ''));
    foreach ([
        'host' => 'APNI_TOKRI_TEST_DB_HOST',
        'port' => 'APNI_TOKRI_TEST_DB_PORT',
        'user' => 'APNI_TOKRI_TEST_DB_USER',
    ] as $key => $envKey) {
        $v = getenv($envKey);
        if (is_string($v) && $v !== '') {
            $db[$key] = $key === 'port' ? (int) $v : $v;
        }
    }
    $db['password'] = '';
    $db['pass'] = '';
    $pass = getenv('APNI_TOKRI_TEST_DB_PASS');
    if ($pass === false) {
        $pass = getenv('APNI_TOKRI_DISPOSABLE_MYSQL_PASS');
    }
    if ($pass !== false) {
        $db['password'] = (string) $pass;
        $db['pass'] = (string) $pass;
    }
    $cfg['database'] = $db;
    App::setConfig($cfg);
    Database::disconnect();
}

$out = getenv('AT_ML_CONCURRENT_OUT');
if (!is_string($out) || $out === '') {
    exit(2);
}

$mode = (string) (getenv('AT_ML_CONCURRENT_MODE') ?: 'ingest');
$barrier = getenv('AT_ML_BARRIER');
$imagePath = getenv('AT_ML_IMAGE_PATH');
$productId = (int) (getenv('AT_ML_PRODUCT_ID') ?: '0');
$mediaId = (int) (getenv('AT_ML_MEDIA_ID') ?: '0');

$payload = ['mode' => $mode, 'ok' => false];

try {
    TestScriptSafety::assertDisposableDatabaseOrAbort();
    TestScriptSafety::assertSafeToMutateSettings();
    Install::ensureConnection();

    if (is_string($barrier) && $barrier !== '' && is_file($barrier)) {
        $bf = fopen($barrier, 'c+b');
        if ($bf !== false) {
            flock($bf, LOCK_EX);
            flock($bf, LOCK_UN);
            fclose($bf);
        }
    }

    if ($mode === 'ingest') {
        if (!is_string($imagePath) || !is_file($imagePath)) {
            throw new RuntimeException('Missing upload image path.');
        }
        $file = [
            'name' => basename($imagePath),
            'type' => 'image/png',
            'tmp_name' => $imagePath,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($imagePath),
        ];
        $result = MediaLibrary::ingestUpload($file, 1);
        $payload = [
            'mode' => 'ingest',
            'ok' => !empty($result['ok']),
            'media_id' => (int) ($result['media_id'] ?? 0),
            'duplicate' => !empty($result['duplicate']),
            'message' => (string) ($result['message'] ?? ''),
            'sha256' => is_file($imagePath) ? hash_file('sha256', $imagePath) : '',
        ];
    } elseif ($mode === 'purge') {
        $payload = [
            'mode' => 'purge',
            'ok' => false,
            'result' => MediaLibrary::purgeDeleted($mediaId),
        ];
        $payload['ok'] = !empty($payload['result']['ok']);
    } elseif ($mode === 'attach') {
        $attach = MediaLibrary::attachToProduct($productId, [$mediaId], true);
        $payload = [
            'mode' => 'attach',
            'ok' => $attach['attached'] !== [],
            'attached' => $attach['attached'],
            'errors' => $attach['errors'],
        ];
    } else {
        throw new RuntimeException('Unknown concurrent mode.');
    }
} catch (Throwable $e) {
    $payload['error'] = $e->getMessage();
}

file_put_contents($out, json_encode($payload, JSON_THROW_ON_ERROR));
exit(0);
