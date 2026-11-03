<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('orders');

$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$sort = AdminServerList::resolveSort((string) ($_GET['sort'] ?? 'placed_at'), [
    'placed_at' => 'placed_at', 'order_number' => 'order_number', 'grand_total' => 'grand_total', 'status' => 'status',
], 'placed_at');
$dir = (string) ($_GET['dir'] ?? 'desc');
$queryBase = array_filter(['q' => $listQ !== '' ? $listQ : null]);

admin_layout_start(['pageTitle' => 'Orders', 'activeAdminNav' => 'orders']);
$pageHeading = 'Orders';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if (!Install::isSchemaReady()) {
    echo '<div class="at-alert at-alert--error">Database schema is not ready.</div>';
    admin_layout_end();
    exit;
}
?>
<form method="get" class="at-admin-card mb-4">
    <div class="at-admin-card__body flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[10rem]">
            <label class="at-label" for="q">Search</label>
            <input id="q" name="q" class="at-input at-focusable" value="<?= Security::escape($listQ) ?>" placeholder="Order #, customer, mobile, status">
        </div>
        <input type="hidden" name="sort" value="<?= Security::escape($sort) ?>">
        <input type="hidden" name="dir" value="<?= Security::escape($dir) ?>">
        <input type="hidden" name="per_page" value="<?= (int) $listPerPage ?>">
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Search</button>
    </div>
</form>
<?php
$list = AdminOrderService::searchForAdmin($listQ, $listPage, $listPerPage, $sort, $dir);
$columns = [
    ['label' => 'Order ID', 'label_html' => AdminServerList::sortHeaderLink('order_number', 'Order ID', $sort, $dir, url_base() . '/admin/orders/index.php', $queryBase), 'key' => 'id', 'class' => 'is-id'],
    ['label' => 'Customer', 'key' => 'customer'],
    ['label' => 'Status', 'key' => 'status'],
    ['label' => 'Total', 'key' => 'total', 'align' => 'num'],
    ['label' => 'Actions', 'key' => 'actions', 'align' => 'actions', 'raw' => true],
];
$rows = $list['rows'];
$emptyMessage = 'No orders yet.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';

$total = (int) $list['total'];
$listPage = (int) $list['page'];
if ((int) $list['total_pages'] > 1) {
    admin_list_pagination($listPage, $total, $listPerPage, $queryBase, url_base() . '/admin/orders/index.php');
}
admin_layout_end();
