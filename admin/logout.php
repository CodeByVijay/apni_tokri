<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

// Logout mutates state: POST + CSRF only. A GET (link, prefetch, <img>)
// must never sign an admin out (VT-2026-05).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
Csrf::requireValid();

AdminAuth::logout();
header('Location: ' . url_to('/admin/login.php'), true, 303);
exit;
