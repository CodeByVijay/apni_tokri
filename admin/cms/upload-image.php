<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

header('Content-Type: application/json; charset=utf-8');

if (!AdminAuth::check() || !Permission::canModule('cms')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!Csrf::validateRequest()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid CSRF token'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = $_FILES['image'] ?? null;
if (!is_array($file)) {
    echo json_encode(['ok' => false, 'message' => 'No image uploaded'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stored = Upload::storeImage($file, 'cms');
if (!$stored['ok']) {
    echo json_encode(['ok' => false, 'message' => $stored['message'] ?? 'Upload failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$publicUrl = url_base() . '/' . ltrim((string) $stored['path'], '/');
echo json_encode(['ok' => true, 'url' => $publicUrl], JSON_UNESCAPED_UNICODE);
