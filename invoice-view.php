<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

if (!CustomerService::isLoggedIn() || CustomerAuth::id() === null) {
    header('Location: ' . url_base() . '/login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? ''), true, 302);
    exit;
}
$ref = isset($_GET['ref']) && is_string($_GET['ref']) ? trim($_GET['ref']) : '';
$inv = $ref !== '' ? InvoiceService::forCustomer($ref, (int) CustomerAuth::id()) : null;

layout_public_start(['pageTitle' => 'Invoice — Apni Tokri', 'activeNav' => 'account']);
$accountActive = 'invoices';
$accountTitle = $inv !== null ? ($inv['document_type'] === 'credit_note' ? 'Credit note' : 'Invoice') . ' ' . $inv['invoice_number'] : 'Invoice';
$accountSub = null;
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-start.php';

if ($inv === null):
    http_response_code(404);
    $emptyTitle = 'Invoice not found';
    $emptyMessage = 'Check the link or view all your invoices.';
    $emptyActionHref = url_base() . '/my-invoices.php';
    $emptyActionLabel = 'My invoices';
    require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
else:
    $dl = url_base() . '/invoice-download.php?ref=' . rawurlencode((string) $inv['public_ref']);
    $os = Database::pdo()->prepare('SELECT order_number FROM orders WHERE id = ?');
    $os->execute([(int) $inv['order_id']]);
    $orderNo = (string) $os->fetchColumn();
    ?>
    <div class="flex flex-wrap gap-2 mb-4">
        <a class="at-btn at-btn--primary at-btn--sm at-focusable" href="<?= Security::escape($dl) ?>">Download PDF</a>
        <a class="at-btn at-btn--secondary at-btn--sm at-focusable" target="_blank" rel="noopener" href="<?= Security::escape($dl) ?>&amp;inline=1">Open in new tab</a>
        <a class="at-btn at-btn--secondary at-btn--sm at-focusable" href="<?= Security::escape(url_base()) ?>/order-details.php?id=<?= rawurlencode($orderNo) ?>">View order</a>
    </div>
    <iframe title="Invoice" src="<?= Security::escape($dl) ?>&amp;inline=1" class="w-full rounded-lg border border-[var(--at-line)] bg-white" style="height:80vh;min-height:520px"></iframe>
<?php endif;
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-end.php';
layout_public_end();
