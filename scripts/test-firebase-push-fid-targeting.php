<?php

declare(strict_types=1);

/**
 * Verifies FCM v1 payload uses fid for web and token for Android (reflection — no network).
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$ref = new ReflectionClass(PushNotificationService::class);
$m = $ref->getMethod('buildMessage');
$m->setAccessible(true);

$webMsg = $m->invoke(null, 'fid-test-abc', 'web', 'fid', 'T', 'B', null, []);
$andMsg = $m->invoke(null, 'android-token-xyz', 'android', 'fcm_token', 'T', 'B', null, []);

$ok = isset($webMsg['fid']) && $webMsg['fid'] === 'fid-test-abc' && !isset($webMsg['token'])
    && isset($andMsg['token']) && $andMsg['token'] === 'android-token-xyz' && !isset($andMsg['fid']);

if (!$ok) {
    fwrite(STDERR, "FAIL fid/token message shape\n");
    exit(1);
}

fwrite(STDOUT, "OK push FID vs token message targeting\n");
exit(0);
