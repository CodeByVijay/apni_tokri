<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Security::sendSecurityHeaders(Security::isHttps());

$deny = static function (int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $msg;
    exit;
};

if (!CustomerService::isLoggedIn() || CustomerAuth::id() === null) {
    $deny(401, 'Please sign in to download your invoice.');
}
$ref = isset($_GET['ref']) && is_string($_GET['ref']) ? trim($_GET['ref']) : '';
// Same response for "not found" and "not yours" so references cannot be probed.
$inv = $ref !== '' ? InvoiceService::forCustomer($ref, (int) CustomerAuth::id()) : null;
if ($inv === null) {
    $deny(404, 'Invoice not found.');
}
InvoiceStorage::stream($inv, !empty($_GET['inline']));
