<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('orders');

$page = max(1, (int) ($_GET['page'] ?? 1));

admin_layout_start(['pageTitle' => 'Orders', 'activeAdminNav' => 'orders']);
admin_flash_render();
$pageHeading = 'Orders';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if (!Install::isSchemaReady()) {
    echo '<div class="at-alert at-alert--error">Database schema is not ready.</div>';
    admin_layout_end();
    exit;
}

$list = AdminOrderService::listForAdmin($page);
$columns = [
    ['label' => 'Order ID', 'key' => 'id', 'class' => 'is-id'],
    ['label' => 'Customer', 'key' => 'customer'],
    ['label' => 'Status', 'key' => 'status'],
    ['label' => 'Total', 'key' => 'total', 'align' => 'num'],
    ['label' => '', 'key' => 'link', 'align' => 'actions'],
];
$rows = $list['rows'];
$emptyMessage = 'No orders yet.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';

$total = (int) $list['total'];
$totalPages = max(1, (int) ceil($total / AdminOrderService::PER_PAGE));
if ($totalPages > 1) {
    $nextQs = http_build_query(['page' => (string) min($totalPages, $page + 1)]);
    echo '<div class="at-admin-pager">';
    echo '<span>Page ' . (int) $page . ' of ' . (int) $totalPages . ' · ' . (int) $total . ' orders</span>';
    echo '<span class="at-admin-pager__nav">';
    if ($page > 1) {
        echo '<a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="?page=' . (int) ($page - 1) . '">Previous</a> ';
    }
    if ($page < $totalPages) {
        echo '<a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="?' . Security::escape($nextQs) . '">Next</a>';
    }
    echo '</span></div>';
}

admin_layout_end();
