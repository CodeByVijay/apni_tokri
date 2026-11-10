<?php

declare(strict_types=1);

/**
 * Firebase OAuth JWT + mocked token exchange (no network).
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($key === false) {
    fwrite(STDERR, "FAIL could not generate test key\n");
    exit(1);
}
openssl_pkey_export($key, $privatePem);
$details = openssl_pkey_get_details($key);
$publicPem = $details['key'] ?? '';

$sa = [
    'type' => 'service_account',
    'project_id' => 'unit-test',
    'client_email' => 'unit@test.iam.gserviceaccount.com',
    'private_key' => $privatePem,
];

FirebaseOAuth::$testHttpHandler = static function (string $method, string $url, string $body, array $headers): array {
    return [
        'ok' => true,
        'status' => 200,
        'body' => '{"access_token":"mock-token","expires_in":3600}',
        'json' => ['access_token' => 'mock-token', 'expires_in' => 3600],
    ];
};

$tok = FirebaseOAuth::obtainAccessToken($sa, 99901);
if (empty($tok['ok']) || ($tok['access_token'] ?? '') !== 'mock-token') {
    fwrite(STDERR, "FAIL OAuth mock\n");
    exit(1);
}

FirebaseFcmClient::$testHandler = static function () {
    return [
        'ok' => true,
        'status' => 200,
        'json' => ['name' => 'projects/x/messages/abc'],
    ];
};
$send = FirebaseFcmClient::send('unit-test', 'mock-token', ['token' => 'device', 'notification' => ['title' => 't', 'body' => 'b']]);
if (empty($send['ok'])) {
    fwrite(STDERR, "FAIL FCM client mock\n");
    exit(1);
}

fwrite(STDOUT, "OK firebase push oauth/fcm unit\n");
exit(0);
