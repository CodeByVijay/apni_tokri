<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('payments');

$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$sort = AdminServerList::resolveSort((string) ($_GET['sort'] ?? 'created_at'), [
    'created_at' => 'created_at', 'amount' => 'amount', 'status' => 'status',
], 'created_at');
$dir = (string) ($_GET['dir'] ?? 'desc');
$queryBase = array_filter(['q' => $listQ !== '' ? $listQ : null]);

admin_layout_start(['pageTitle' => 'Payments', 'activeAdminNav' => 'payments']);
$pageHeading = 'Payment history & reconciliation';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<p class="mb-4 text-sm"><a class="underline at-focusable" href="<?= Security::escape(url_base()) ?>/admin/payments/completion_exceptions.php">Payment completion exceptions (captured / slot conflict) →</a></p>
<?php if (!Install::isSchemaReady() || !AdminAuth::check()): ?>
<div class="at-alert at-alert--info">Database admin session required.</div>
<?php else: ?>
<form method="get" class="at-admin-card mb-4">
    <div class="at-admin-card__body flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[10rem]">
            <label class="at-label" for="q">Search</label>
            <input id="q" name="q" class="at-input at-focusable" value="<?= Security::escape($listQ) ?>" placeholder="Order #, gateway ref, status">
        </div>
        <input type="hidden" name="per_page" value="<?= (int) $listPerPage ?>">
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Search</button>
    </div>
</form>
<?php
$list = PaymentRepository::searchForAdmin($listQ, $listPage, $listPerPage, $sort, $dir);
$rows = [];
foreach ($list['rows'] as $p) {
    $rows[] = [
        'id' => (string) ($p['gateway_payment_id'] ?? $p['idempotency_key'] ?? $p['id']),
        'gw' => ucfirst((string) ($p['gateway'] ?? '')),
        'amount' => catalogue_format_price((float) ($p['amount'] ?? 0)),
        'status' => ucfirst((string) ($p['status'] ?? '')),
        'order' => (string) ($p['order_number'] ?? ''),
    ];
}
$columns = [
    ['label' => 'Reference', 'key' => 'id'],
    ['label' => 'Order', 'key' => 'order'],
    ['label' => 'Gateway', 'key' => 'gw'],
    ['label' => 'Amount', 'key' => 'amount', 'align' => 'num'],
    ['label' => 'Status', 'key' => 'status'],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
if ((int) $list['total_pages'] > 1) {
    admin_list_pagination((int) $list['page'], (int) $list['total'], $listPerPage, $queryBase, url_base() . '/admin/payments/index.php');
}
endif;
admin_layout_end();
