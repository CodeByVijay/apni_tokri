<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/list_actions.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('customers');

$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$sort = AdminServerList::resolveSort((string) ($_GET['sort'] ?? 'created_at'), [
    'created_at' => 'created_at', 'name' => 'name', 'mobile' => 'mobile', 'email' => 'email',
], 'created_at');
$dir = (string) ($_GET['dir'] ?? 'desc');
$queryBase = array_filter(['q' => $listQ !== '' ? $listQ : null]);

admin_layout_start(['pageTitle' => 'Customers', 'activeAdminNav' => 'customers']);
$pageHeading = 'Customers';
$pageDescription = 'Search and manage customer accounts. OTP codes and password hashes are never shown here.';
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
        <input type="hidden" name="sort" value="<?= Security::escape($sort) ?>">
        <input type="hidden" name="dir" value="<?= Security::escape($dir) ?>">
        <input type="hidden" name="per_page" value="<?= (int) $listPerPage ?>">
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Search</button>
    </div>
</form>
<?php
$list = AdminCustomerService::searchForAdmin($listQ, $listPage, $listPerPage, $sort, $dir);
$base = url_base();
$rows = [];
foreach ($list['rows'] as $row) {
    $cid = (int) ($row['id'] ?? 0);
    $active = !empty($row['is_active']);
    $viewHref = $base . '/admin/customers/view.php?id=' . $cid;
    $editHref = $base . '/admin/customers/edit.php?id=' . $cid;
    $actions = '<span class="at-admin-actions">'
        . '<a class="at-btn at-btn--sm at-btn--ghost at-focusable" href="' . Security::escape($viewHref) . '">View</a> '
        . admin_action_edit_link($editHref, 'Edit customer')
        . '</span>';
    $rows[] = [
        'name' => (string) (($row['name'] ?? '') !== '' ? $row['name'] : 'Customer'),
        'mobile' => (string) ($row['mobile'] ?? ''),
        'email' => (string) ($row['email'] ?? '') !== '' ? (string) $row['email'] : '—',
        'orders' => (string) (int) ($row['order_count'] ?? 0),
        'joined' => customer_format_datetime((string) ($row['created_at'] ?? '')),
        'status' => $active ? 'active' : 'inactive',
        'actions' => $actions,
    ];
}
$columns = [
    ['label' => 'Name', 'label_html' => AdminServerList::sortHeaderLink('name', 'Name', $sort, $dir, $base . '/admin/customers/index.php', $queryBase), 'key' => 'name'],
    ['label' => 'Mobile', 'label_html' => AdminServerList::sortHeaderLink('mobile', 'Mobile', $sort, $dir, $base . '/admin/customers/index.php', $queryBase), 'key' => 'mobile'],
    ['label' => 'Email', 'label_html' => AdminServerList::sortHeaderLink('email', 'Email', $sort, $dir, $base . '/admin/customers/index.php', $queryBase), 'key' => 'email'],
    ['label' => 'Orders', 'key' => 'orders', 'align' => 'num'],
    ['label' => 'Registered', 'label_html' => AdminServerList::sortHeaderLink('created_at', 'Registered', $sort, $dir, $base . '/admin/customers/index.php', $queryBase), 'key' => 'joined'],
    ['label' => 'Status', 'key' => 'status'],
    ['label' => 'Actions', 'key' => 'actions', 'align' => 'actions', 'raw' => true],
];
$emptyMessage = $listQ !== '' ? 'No customers match your search.' : 'No customers yet.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
if ((int) $list['total_pages'] > 1) {
    admin_list_pagination((int) $list['page'], (int) $list['total'], $listPerPage, $queryBase, $base . '/admin/customers/index.php');
}
endif;
admin_layout_end();
