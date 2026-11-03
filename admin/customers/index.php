<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('customers');

$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$sort = AdminServerList::resolveSort((string) ($_GET['sort'] ?? 'created_at'), [
    'created_at' => 'created_at', 'name' => 'name', 'mobile' => 'mobile',
], 'created_at');
$dir = (string) ($_GET['dir'] ?? 'desc');
$queryBase = array_filter(['q' => $listQ !== '' ? $listQ : null]);

admin_layout_start(['pageTitle' => 'Customers', 'activeAdminNav' => 'customers']);
$pageHeading = 'Customers';
$pageDescription = 'Admin cannot view customer OTPs or passwords.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if (!Install::isSchemaReady()): ?>
<p class="text-sm at-muted">Database not ready.</p>
<?php else: ?>
<form method="get" class="at-admin-card mb-4">
    <div class="at-admin-card__body flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[10rem]">
            <label class="at-label" for="q">Search</label>
            <input id="q" name="q" class="at-input at-focusable" value="<?= Security::escape($listQ) ?>" placeholder="Name, mobile, email">
        </div>
        <input type="hidden" name="per_page" value="<?= (int) $listPerPage ?>">
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Search</button>
    </div>
</form>
<?php
$list = AdminDashboardService::searchCustomersForAdmin($listQ, $listPage, $listPerPage, $sort, $dir);
$rows = [];
foreach ($list['rows'] as $row) {
    $rows[] = [
        'name' => (string) (($row['name'] ?? '') !== '' ? $row['name'] : 'Customer'),
        'mobile' => (string) ($row['mobile'] ?? ''),
        'orders' => (string) (int) ($row['order_count'] ?? 0),
        'joined' => date('M Y', strtotime((string) ($row['created_at'] ?? 'now'))),
    ];
}
$columns = [
    ['label' => 'Name', 'label_html' => AdminServerList::sortHeaderLink('name', 'Name', $sort, $dir, url_base() . '/admin/customers/index.php', $queryBase), 'key' => 'name'],
    ['label' => 'Mobile', 'key' => 'mobile'],
    ['label' => 'Orders', 'key' => 'orders', 'align' => 'num'],
    ['label' => 'Joined', 'key' => 'joined'],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
if ((int) $list['total_pages'] > 1) {
    admin_list_pagination((int) $list['page'], (int) $list['total'], $listPerPage, $queryBase, url_base() . '/admin/customers/index.php');
}
endif;
admin_layout_end();
