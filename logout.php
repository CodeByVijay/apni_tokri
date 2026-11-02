<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Security::sendSecurityHeaders(Security::isHttps());

// Logout mutates state: POST + CSRF only. A GET (link, prefetch, <img>) must never sign anyone out.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    header('Location: ' . url_base() . '/my-account.php', true, 303);
    exit;
}
Csrf::requireValid();

CustomerService::logout();
header('Location: ' . url_base() . '/index.php', true, 303);
exit;
