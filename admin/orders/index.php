<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('orders');

$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequestWithOptions(
    isset($_GET['per_page']) ? (int) $_GET['per_page'] : null,
    AdminOrderService::PER_PAGE_OPTIONS,
    AdminOrderService::PER_PAGE
);
$sort = AdminServerList::resolveSort((string) ($_GET['sort'] ?? 'created_at'), [
    'created_at' => 'created_at',
    'placed_at' => 'placed_at',
    'order_number' => 'order_number',
    'grand_total' => 'grand_total',
    'status' => 'status',
], 'created_at');
$dir = (string) ($_GET['dir'] ?? 'desc');
$refundFacet = isset($_GET['refund_facet']) ? trim((string) $_GET['refund_facet']) : '';
$facetLabels = AdminOrderService::refundFacetLabels();
if (!array_key_exists($refundFacet, $facetLabels)) {
    $refundFacet = '';
}
$queryBase = array_filter([
    'q' => $listQ !== '' ? $listQ : null,
    'refund_facet' => $refundFacet !== '' ? $refundFacet : null,
    'sort' => $sort !== 'created_at' ? $sort : null,
    'dir' => strtolower($dir) !== 'desc' ? $dir : null,
    'per_page' => $listPerPage !== AdminOrderService::PER_PAGE ? $listPerPage : null,
]);

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
        <input type="hidden" name="page" value="1">
        <input type="hidden" name="sort" value="<?= Security::escape($sort) ?>">
        <input type="hidden" name="dir" value="<?= Security::escape($dir) ?>">
        <input type="hidden" name="per_page" value="<?= (int) $listPerPage ?>">
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Search</button>
    </div>
</form>
<nav class="flex flex-wrap gap-2 mb-4" aria-label="Order refund filters">
    <?php foreach ($facetLabels as $key => $label):
        $qs = http_build_query(array_filter([
            'q' => $listQ !== '' ? $listQ : null,
            'refund_facet' => $key !== '' ? $key : null,
            'sort' => $sort !== 'created_at' ? $sort : null,
            'dir' => strtolower($dir) !== 'desc' ? $dir : null,
            'per_page' => $listPerPage !== AdminOrderService::PER_PAGE ? $listPerPage : null,
        ]));
        $href = url_base() . '/admin/orders/index.php' . ($qs !== '' ? '?' . $qs : '');
        $active = $refundFacet === $key ? ' is-active' : '';
        ?>
        <a class="at-chip at-focusable<?= $active ?>" href="<?= Security::escape($href) ?>"><?= Security::escape($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php
$list = AdminOrderService::searchForAdmin($listQ, $listPage, $listPerPage, $sort, $dir, $refundFacet);
$columns = [
    ['label' => 'Order ID', 'label_html' => AdminServerList::sortHeaderLink('order_number', 'Order ID', $sort, $dir, url_base() . '/admin/orders/index.php', $queryBase), 'key' => 'id', 'class' => 'is-id'],
    ['label' => 'Customer', 'key' => 'customer'],
    ['label' => 'Status', 'key' => 'status'],
    ['label' => 'Refund', 'key' => 'refund'],
    ['label' => 'Total', 'key' => 'total', 'align' => 'num'],
    ['label' => 'Actions', 'key' => 'actions', 'align' => 'actions', 'raw' => true],
];
$rows = $list['rows'];
$emptyMessage = $listQ !== '' ? 'No orders match your search.' : 'No orders yet.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';

$total = (int) $list['total'];
$listPage = (int) $list['page'];
admin_list_pagination(
    $listPage,
    $total,
    $listPerPage,
    $queryBase,
    url_base() . '/admin/orders/index.php',
    AdminOrderService::PER_PAGE_OPTIONS,
    $total === 1 ? 'order' : 'orders',
    true
);
admin_layout_end();
