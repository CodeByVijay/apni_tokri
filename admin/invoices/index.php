<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('invoices');

$filters = [
    'q' => (string) ($_GET['q'] ?? ''),
    'customer' => (string) ($_GET['customer'] ?? ''),
    'from' => (string) ($_GET['from'] ?? ''),
    'to' => (string) ($_GET['to'] ?? ''),
    'type' => (string) ($_GET['type'] ?? ''),
    'payment' => (string) ($_GET['payment'] ?? ''),
    'email' => (string) ($_GET['email'] ?? ''),
    'sort' => (string) ($_GET['sort'] ?? 'date'),
    'dir' => (string) ($_GET['dir'] ?? 'desc'),
    'page' => (int) ($_GET['page'] ?? 1),
];

admin_layout_start(['pageTitle' => 'Invoices', 'activeAdminNav' => 'invoices']);
$pageHeading = 'Invoices';
$pageDescription = 'Retail invoices and credit notes. Documents are immutable once issued.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if (!Install::isSchemaReady()) {
    echo '<div class="at-alert at-alert--error">Database schema is not ready.</div>';
    admin_layout_end();
    exit;
}

$res = InvoiceQuery::adminSearch($filters);
$base = url_base() . '/admin/invoices';
$qsBase = array_filter($filters, static function ($v, $k) {
    return $k !== 'page' && $v !== '' && $v !== null;
}, ARRAY_FILTER_USE_BOTH);
$sortLink = static function (string $key, string $label) use ($filters, $qsBase, $base): string {
    $dir = ($filters['sort'] === $key && strtolower($filters['dir']) === 'desc') ? 'asc' : 'desc';
    $qs = http_build_query(array_merge($qsBase, ['sort' => $key, 'dir' => $dir]));
    $arrow = $filters['sort'] === $key ? (strtolower($filters['dir']) === 'asc' ? ' ▲' : ' ▼') : '';

    return '<a class="underline-offset-2 hover:underline at-focusable" href="' . Security::escape($base . '/index.php?' . $qs) . '">' . Security::escape($label . $arrow) . '</a>';
};
$sel = static function (string $cur, string $val): string {
    return $cur === $val ? ' selected' : '';
};
?>
<form method="get" class="at-card mb-4"><div class="at-card__body grid gap-3 md:grid-cols-3 lg:grid-cols-4 items-end">
    <div><label class="at-label" for="f-q">Invoice / order no.</label>
        <input id="f-q" name="q" class="at-input at-focusable" value="<?= Security::escape($filters['q']) ?>" placeholder="AT/2026-27/… or order no."></div>
    <div><label class="at-label" for="f-c">Customer</label>
        <input id="f-c" name="customer" class="at-input at-focusable" value="<?= Security::escape($filters['customer']) ?>" placeholder="Name, mobile or email"></div>
    <div><label class="at-label" for="f-from">From</label>
        <input id="f-from" type="date" name="from" class="at-input at-focusable" value="<?= Security::escape($filters['from']) ?>"></div>
    <div><label class="at-label" for="f-to">To</label>
        <input id="f-to" type="date" name="to" class="at-input at-focusable" value="<?= Security::escape($filters['to']) ?>"></div>
    <div><label class="at-label" for="f-type">Document</label>
        <select id="f-type" name="type" class="at-select at-focusable">
            <option value="">All</option>
            <option value="retail_invoice"<?= $sel($filters['type'], 'retail_invoice') ?>>Retail invoice</option>
            <option value="credit_note"<?= $sel($filters['type'], 'credit_note') ?>>Credit note</option>
        </select></div>
    <div><label class="at-label" for="f-pay">Order payment</label>
        <select id="f-pay" name="payment" class="at-select at-focusable">
            <option value="">All</option>
            <option value="paid"<?= $sel($filters['payment'], 'paid') ?>>Paid</option>
            <option value="partially_refunded"<?= $sel($filters['payment'], 'partially_refunded') ?>>Partly refunded</option>
            <option value="refunded"<?= $sel($filters['payment'], 'refunded') ?>>Refunded</option>
        </select></div>
    <div><label class="at-label" for="f-em">Email</label>
        <select id="f-em" name="email" class="at-select at-focusable">
            <option value="">All</option>
            <option value="none"<?= $sel($filters['email'], 'none') ?>>Not sent</option>
            <option value="sent"<?= $sel($filters['email'], 'sent') ?>>Sent</option>
            <option value="failed"<?= $sel($filters['email'], 'failed') ?>>Failed</option>
        </select></div>
    <div class="flex gap-2">
        <button class="at-btn at-btn--primary at-focusable" type="submit">Filter</button>
        <a class="at-btn at-btn--secondary at-focusable" href="<?= Security::escape($base) ?>/index.php">Reset</a>
    </div>
</div></form>

<?php if ($res['rows'] === []): ?>
    <div class="at-card"><div class="at-card__body text-sm at-muted">No invoices match. Invoices are created when a paid order is first viewed by the customer or by the invoice sweep.</div></div>
<?php else: ?>
<div class="at-admin-table-wrap">
    <table class="at-admin-table">
        <thead><tr>
            <th><?= $sortLink('number', 'Invoice no.') ?></th>
            <th>Type</th>
            <th><?= $sortLink('date', 'Date') ?></th>
            <th>Order</th>
            <th><?= $sortLink('customer', 'Customer') ?></th>
            <th class="is-num"><?= $sortLink('amount', 'Amount') ?></th>
            <th>Order payment</th>
            <th>Email</th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($res['rows'] as $r): ?>
            <?php $isCn = $r['document_type'] === 'credit_note'; ?>
            <tr>
                <td class="is-id"><a class="underline at-focusable" href="<?= Security::escape($base) ?>/view.php?id=<?= (int) $r['id'] ?>"><?= Security::escape((string) $r['invoice_number']) ?></a></td>
                <td><?= $isCn ? 'Credit note' : 'Invoice' ?></td>
                <td class="is-nowrap"><?= Security::escape(InvoiceFormat::date((string) $r['invoice_date'])) ?></td>
                <td><a class="underline at-focusable" href="<?= Security::escape(url_base()) ?>/admin/orders/view.php?id=<?= (int) $r['order_id'] ?>"><?= Security::escape((string) $r['order_number']) ?></a></td>
                <td><?= Security::escape((string) ($r['customer_name'] ?: 'Customer')) ?><br><span class="text-xs at-muted"><?= Security::escape((string) $r['customer_mobile']) ?></span></td>
                <td class="is-num"><?= Security::escape(InvoiceFormat::money((float) $r['grand_total'])) ?></td>
                <td><?= admin_status_badge((string) $r['payment_status']) ?></td>
                <td><?= $r['email_status'] === null ? '<span class="at-muted text-xs">Not sent</span>' : admin_status_badge((string) $r['email_status']) ?></td>
                <td class="is-nowrap">
                    <a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="<?= Security::escape($base) ?>/download.php?id=<?= (int) $r['id'] ?>&amp;inline=1" target="_blank" rel="noopener">View</a>
                    <a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="<?= Security::escape($base) ?>/download.php?id=<?= (int) $r['id'] ?>">Download</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php if ($res['pages'] > 1): ?>
    <div class="at-admin-pager">
        <span>Page <?= (int) $res['page'] ?> of <?= (int) $res['pages'] ?> · <?= (int) $res['total'] ?> documents</span>
        <span class="at-admin-pager__nav">
            <?php if ($res['page'] > 1): ?><a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="?<?= Security::escape(http_build_query(array_merge($qsBase, ['page' => $res['page'] - 1]))) ?>">Previous</a><?php endif; ?>
            <?php if ($res['page'] < $res['pages']): ?><a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="?<?= Security::escape(http_build_query(array_merge($qsBase, ['page' => $res['page'] + 1]))) ?>">Next</a><?php endif; ?>
        </span>
    </div>
<?php endif; ?>
<?php endif; ?>
<?php admin_layout_end(); ?>
