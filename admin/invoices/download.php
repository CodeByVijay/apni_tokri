<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('invoices');

$id = (int) ($_GET['id'] ?? 0);
$inv = $id > 0 && Install::isSchemaReady() ? InvoiceService::byId($id) : null;
if ($inv === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invoice not found.';
    exit;
}
$inline = !empty($_GET['inline']);
if (($_GET['layout'] ?? '') === 'thermal') {
    $bytes = InvoiceService::thermalPdf($inv);
    if ($bytes === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Thermal layout is disabled.';
        exit;
    }
    InvoiceStorage::stream($inv, $inline, $bytes, preg_replace('/[^A-Za-z0-9_\-]+/', '-', (string) $inv['invoice_number']) . '-thermal');
}
InvoiceStorage::stream($inv, $inline);
