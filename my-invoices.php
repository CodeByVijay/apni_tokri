<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

if (!CustomerService::isLoggedIn() || CustomerAuth::id() === null) {
    header('Location: ' . url_base() . '/login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? ''), true, 302);
    exit;
}
$cid = (int) CustomerAuth::id();
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 15;
$rows = [];
$total = 0;
if (CommerceGateway::useDatabase()) {
    $rows = InvoiceQuery::forCustomer($cid, $page, $per);
    $total = InvoiceQuery::countForCustomer($cid);
}

layout_public_start(['pageTitle' => 'My invoices — Apni Tokri', 'activeNav' => 'account']);
$accountActive = 'invoices';
$accountTitle = 'My invoices';
$accountSub = 'Download invoices and credit notes for delivered orders.';
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-start.php';

if ($rows === []):
    $emptyTitle = 'No invoices yet';
    $emptyMessage = 'Invoices appear here once an order is paid.';
    $emptyIcon = 'receipt';
    $emptyActionHref = url_base() . '/my-orders.php';
    $emptyActionLabel = 'My orders';
    ?><div class="at-card"><div class="at-card__body"><?php require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php'; ?></div></div>
<?php else: ?>
    <ul class="grid gap-3">
        <?php foreach ($rows as $r):
            $isCn = $r['document_type'] === 'credit_note';
            $dl = url_base() . '/invoice-download.php?ref=' . rawurlencode((string) $r['public_ref']); ?>
            <li class="at-card"><div class="at-card__body flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-mono font-bold"><?= Security::escape((string) $r['invoice_number']) ?></p>
                    <p class="text-sm at-muted"><?= $isCn ? 'Credit note' : 'Invoice' ?> · <?= Security::escape(InvoiceFormat::date((string) $r['invoice_date'])) ?> · Order <?= Security::escape((string) $r['order_number']) ?></p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-extrabold"><?= Security::escape(catalogue_format_price((float) $r['grand_total'])) ?></span>
                    <a class="at-btn at-btn--secondary at-btn--sm at-focusable" href="<?= Security::escape(url_base()) ?>/invoice-view.php?ref=<?= rawurlencode((string) $r['public_ref']) ?>">View</a>
                    <a class="at-btn at-btn--primary at-btn--sm at-focusable" href="<?= Security::escape($dl) ?>">Download</a>
                </div>
            </div></li>
        <?php endforeach; ?>
    </ul>
    <?php require APNI_TOKRI_ROOT . '/includes/partials/account-pagination.php';
    customer_account_pagination($page, $total, $per); ?>
<?php endif;
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-end.php';
layout_public_end();
