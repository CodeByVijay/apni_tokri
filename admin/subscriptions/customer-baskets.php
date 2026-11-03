<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('subscriptions');

$base = url_base() . '/admin/subscriptions/customer-baskets.php';
$from = isset($_GET['from']) && is_string($_GET['from']) ? $_GET['from'] : date('Y-m-d');
$to = isset($_GET['to']) && is_string($_GET['to']) ? $_GET['to'] : date('Y-m-d', strtotime('+14 days'));
$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$sort = AdminServerList::resolveSort((string) ($_GET['sort'] ?? 'delivery_date'), [
    'delivery_date' => 'delivery_date', 'subscription_number' => 'subscription_number', 'total_inr' => 'total_inr',
], 'delivery_date');
$dir = (string) ($_GET['dir'] ?? 'asc');
$queryBase = array_filter([
    'from' => $from,
    'to' => $to,
    'q' => $listQ !== '' ? $listQ : null,
]);

admin_layout_start(['pageTitle' => 'Customer baskets', 'activeAdminNav' => 'subscriptions']);
$pageHeading = 'Customer daily baskets';
$pageDescription = 'Suggested vs customized vs confirmed baskets for prep planning.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<form method="get" class="flex flex-wrap gap-3 items-end mb-4">
    <div><label class="at-label">From</label><input type="date" class="at-input at-focusable" name="from" value="<?= Security::escape($from) ?>"></div>
    <div><label class="at-label">To</label><input type="date" class="at-input at-focusable" name="to" value="<?= Security::escape($to) ?>"></div>
    <div class="flex-1 min-w-[10rem]"><label class="at-label" for="q">Search</label>
        <input id="q" name="q" class="at-input at-focusable" value="<?= Security::escape($listQ) ?>" placeholder="Subscription #, mobile"></div>
    <input type="hidden" name="per_page" value="<?= (int) $listPerPage ?>">
    <button type="submit" class="at-btn at-btn--secondary at-focusable">Filter</button>
</form>
<?php
$rows = [];
$list = ['total' => 0, 'page' => 1, 'total_pages' => 1];
if (Install::isSchemaReady()) {
    $list = SubscriptionAdminListService::searchCustomerBaskets($from, $to, $listQ, $listPage, $listPerPage, $sort, $dir);
    foreach ($list['rows'] as $r) {
        $rows[] = [
            'date' => (string) $r['delivery_date'],
            'customer' => (string) $r['mobile'],
            'sub' => (string) $r['subscription_number'],
            'mode' => (string) ($r['source_type'] ?? '—'),
            'status' => (string) ($r['basket_row_status'] ?? $r['basket_status'] ?? 'none'),
            'total' => catalogue_format_price((float) ($r['total_inr'] ?? 0)),
            'allowance' => catalogue_format_price((float) ($r['allowance_inr'] ?? 0)),
        ];
    }
}
$columns = [
    ['label' => 'Date', 'label_html' => AdminServerList::sortHeaderLink('delivery_date', 'Date', $sort, $dir, $base, $queryBase), 'key' => 'date'],
    ['label' => 'Customer', 'key' => 'customer'],
    ['label' => 'Subscription', 'label_html' => AdminServerList::sortHeaderLink('subscription_number', 'Subscription', $sort, $dir, $base, $queryBase), 'key' => 'sub'],
    ['label' => 'Source', 'key' => 'mode'],
    ['label' => 'Status', 'key' => 'status'],
    ['label' => 'Total', 'label_html' => AdminServerList::sortHeaderLink('total_inr', 'Total', $sort, $dir, $base, $queryBase), 'key' => 'total', 'align' => 'num'],
    ['label' => 'Allowance', 'key' => 'allowance'],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
if (Install::isSchemaReady() && (int) ($list['total_pages'] ?? 1) > 1) {
    admin_list_pagination((int) $list['page'], (int) $list['total'], $listPerPage, $queryBase, $base);
}
admin_layout_end();
