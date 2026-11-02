<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

Security::sendSecurityHeaders(Security::isHttps());

if (!CustomerService::isLoggedIn()) {
    http_response_code(403);
    exit;
}
$customerId = CustomerAuth::id();
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($customerId === null || $id <= 0) {
    http_response_code(404);
    exit;
}

$row = SupportTicketService::attachmentForCustomer($id, $customerId);
if ($row === null) {
    http_response_code(404);
    exit;
}

$path = APNI_TOKRI_ROOT . '/' . ltrim((string) $row['file_path'], '/');
if (!is_readable($path)) {
    http_response_code(404);
    exit;
}

$mime = (string) ($row['mime_type'] ?? 'application/octet-stream');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="support-' . $id . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
