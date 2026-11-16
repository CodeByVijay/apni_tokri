<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed', 405);
}

Csrf::requireValid();
$raw = file_get_contents('php://input');
$body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $_POST;
if (!is_array($body)) {
    $body = [];
}

$platform = strtolower(trim((string) ($body['platform'] ?? 'web')));
$permission = (string) ($body['permission'] ?? 'unknown');
$customerId = CustomerAuth::id();
$appVersion = isset($body['app_version']) ? (string) $body['app_version'] : null;

if ($platform === 'web') {
    $fid = trim((string) ($body['fid'] ?? $body['installation_id'] ?? ''));
    if ($fid === '') {
        Ajax::error('Firebase Installation ID (fid) is required for web.', 422, 'missing_fid');
    }
    $result = PushTokenService::register($platform, $fid, $permission, $customerId, $appVersion, 'fid');
} elseif ($platform === 'android') {
    $token = trim((string) ($body['token'] ?? ''));
    if ($token === '') {
        Ajax::error('FCM token is required for Android.', 422, 'missing_token');
    }
    $result = PushTokenService::register($platform, $token, $permission, $customerId, $appVersion, 'fcm_token');
} else {
    Ajax::error('Invalid platform.', 422);
}

if (empty($result['ok'])) {
    Ajax::error((string) ($result['message'] ?? 'Registration failed'), 422);
}

Ajax::success(['registration_id' => $result['registration_id'] ?? null], 'Registered');
