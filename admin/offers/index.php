<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('offers');
$useDb = Install::isSchemaReady() && AdminAuth::check();
$base = url_base() . '/admin/offers';

$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$sort = AdminServerList::resolveSort((string) ($_GET['sort'] ?? 'priority'), [
    'name' => 'name',
    'priority' => 'priority',
    'id' => 'id',
], 'priority');
$dir = (string) ($_GET['dir'] ?? 'desc');

if ($useDb) {
    admin_process_list_delete('offers', $base . '/index.php');
}

admin_layout_start(['pageTitle' => 'Offers', 'activeAdminNav' => 'offers']);
$pageHeading = 'Automatic offers';
$pageDescription = 'One best offer applies per cart (no offer stacking). Coupon stacking controlled per coupon/offer.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<p class="mb-4"><a href="<?= Security::escape($base) ?>/edit.php" class="at-btn at-btn--primary at-btn--sm at-focusable">New offer</a></p>
<?php
$queryBase = array_filter([
    'q' => $listQ !== '' ? $listQ : null,
    'sort' => $sort !== 'priority' ? $sort : null,
    'dir' => strtolower($dir) !== 'desc' ? $dir : null,
]);

if ($useDb): ?>
<form method="get" class="at-admin-card mb-4">
    <div class="at-admin-card__body flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[10rem]">
            <label class="at-label" for="q">Search</label>
            <input id="q" name="q" class="at-input at-focusable" value="<?= Security::escape($listQ) ?>" placeholder="Offer name">
        </div>
        <input type="hidden" name="sort" value="<?= Security::escape($sort) ?>">
        <input type="hidden" name="dir" value="<?= Security::escape($dir) ?>">
        <input type="hidden" name="per_page" value="<?= (int) $listPerPage ?>">
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Search</button>
    </div>
</form>
<?php endif;

$rows = [];
$total = 0;
$totalPages = 1;
if ($useDb) {
    $list = OfferRepository::searchForAdmin($listQ, null, $listPage, $listPerPage, $sort, $dir);
    $total = (int) $list['total'];
    $totalPages = (int) $list['total_pages'];
    $listPage = (int) $list['page'];
    foreach ($list['rows'] as $o) {
        $oid = (int) $o['id'];
        $name = (string) $o['name'];
        $rows[] = [
            'name' => $name,
            'scope' => (string) $o['scope'],
            'disc' => ($o['discount_type'] ?? '') === 'percent' ? (float) $o['discount_value'] . '%' : '₹' . (float) $o['discount_value'],
            'active' => !empty($o['is_active']) ? 'Yes' : 'No',
            'actions' => admin_table_actions($base . '/edit.php?id=' . $oid, 'Edit', 'offer', $oid, $name, $listPage, $listPerPage, $listQ),
        ];
    }
}
$columns = [
    [
        'label' => 'Name',
        'label_html' => AdminServerList::sortHeaderLink('name', 'Name', $sort, $dir, $base . '/index.php', $queryBase),
        'key' => 'name',
    ],
    ['label' => 'Scope', 'key' => 'scope'],
    ['label' => 'Discount', 'key' => 'disc'],
    ['label' => 'Active', 'key' => 'active'],
    ['label' => 'Actions', 'key' => 'actions', 'align' => 'actions', 'raw' => true],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
if ($useDb && $totalPages > 1) {
    admin_list_pagination($listPage, $total, $listPerPage, $queryBase, $base . '/index.php');
}
admin_layout_end();
