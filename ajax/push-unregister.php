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
if ($platform === 'web') {
    $fid = trim((string) ($body['fid'] ?? $body['installation_id'] ?? ''));
    PushTokenService::unregisterCurrentDevice($platform, $fid, 'fid');
} else {
    $token = trim((string) ($body['token'] ?? ''));
    PushTokenService::unregisterCurrentDevice($platform, $token, 'fcm_token');
}

Ajax::success([], 'Unregistered');
