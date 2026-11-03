<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('coupons');
$useDb = Install::isSchemaReady() && AdminAuth::check();
$base = url_base() . '/admin/coupons';

$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$sort = AdminServerList::resolveSort((string) ($_GET['sort'] ?? 'created_at'), [
    'code' => 'code',
    'created_at' => 'created_at',
    'used_count' => 'used_count',
    'id' => 'id',
], 'created_at');
$dir = (string) ($_GET['dir'] ?? 'desc');
$filterActive = isset($_GET['active']) && $_GET['active'] !== '' ? (int) $_GET['active'] : null;
if ($filterActive !== null && $filterActive !== 0 && $filterActive !== 1) {
    $filterActive = null;
}

if ($useDb) {
    admin_process_list_delete('coupons', $base . '/index.php');
}

admin_layout_start(['pageTitle' => 'Coupons', 'activeAdminNav' => 'coupons']);
$pageHeading = 'Coupons';
$pageDescription = 'Codes, limits, eligibility and stacking rules — validated on the server at checkout.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<p class="mb-4"><a href="<?= Security::escape($base) ?>/edit.php" class="at-btn at-btn--primary at-btn--sm at-focusable">New coupon</a>
<a href="<?= Security::escape(url_base()) ?>/admin/reports/coupons.php" class="at-btn at-btn--secondary at-btn--sm at-focusable">Usage report</a></p>
<?php
$queryBase = array_filter([
    'q' => $listQ !== '' ? $listQ : null,
    'active' => $filterActive !== null ? (string) $filterActive : null,
    'sort' => $sort !== 'created_at' ? $sort : null,
    'dir' => strtolower($dir) !== 'desc' ? $dir : null,
]);

if ($useDb): ?>
<form method="get" class="at-admin-card mb-4">
    <div class="at-admin-card__body flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[10rem]">
            <label class="at-label" for="q">Search</label>
            <input id="q" name="q" class="at-input at-focusable" value="<?= Security::escape($listQ) ?>" placeholder="Code or internal name">
        </div>
        <div>
            <label class="at-label" for="active">Status</label>
            <select id="active" name="active" class="at-select at-focusable">
                <option value="">All</option>
                <option value="1"<?= $filterActive === 1 ? ' selected' : '' ?>>Active</option>
                <option value="0"<?= $filterActive === 0 ? ' selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <input type="hidden" name="sort" value="<?= Security::escape($sort) ?>">
        <input type="hidden" name="dir" value="<?= Security::escape($dir) ?>">
        <input type="hidden" name="per_page" value="<?= (int) $listPerPage ?>">
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Filter</button>
    </div>
</form>
<?php endif;

$rows = [];
$total = 0;
$totalPages = 1;
if ($useDb) {
    $list = CouponRepository::searchForAdmin($listQ, $filterActive, $listPage, $listPerPage, $sort, $dir);
    $total = (int) $list['total'];
    $totalPages = (int) $list['total_pages'];
    $listPage = (int) $list['page'];
    foreach ($list['rows'] as $c) {
        $disc = ($c['discount_type'] ?? '') === 'percent'
            ? (float) $c['discount_value'] . '%'
            : '₹' . number_format((float) $c['discount_value'], 0);
        $limit = $c['usage_limit'] !== null
            ? (int) $c['used_count'] . '/' . (int) $c['usage_limit']
            : (int) $c['used_count'] . '/∞';
        $cid = (int) $c['id'];
        $code = (string) $c['code'];
        $rows[] = [
            'code' => $code,
            'disc' => $disc,
            'uses' => $limit,
            'active' => !empty($c['is_active']) ? 'Yes' : 'No',
            'actions' => admin_table_actions(
                $base . '/edit.php?id=' . $cid,
                'Edit',
                'coupon',
                $cid,
                $code,
                $listPage,
                $listPerPage,
                $listQ
            ),
        ];
    }
} elseif ($rows === [] && AdminPersistence::allowsDemoPreview()) {
    $rows = [['code' => 'WELCOME10 (seed)', 'disc' => '10%', 'uses' => 'demo', 'active' => 'Yes', 'actions' => '']];
}

$columns = [
    [
        'label' => 'Code',
        'label_html' => AdminServerList::sortHeaderLink('code', 'Code', $sort, $dir, $base . '/index.php', $queryBase),
        'key' => 'code',
    ],
    ['label' => 'Discount', 'key' => 'disc'],
    ['label' => 'Uses', 'key' => 'uses', 'align' => 'num'],
    ['label' => 'Active', 'key' => 'active'],
    ['label' => 'Actions', 'key' => 'actions', 'align' => 'actions', 'raw' => true],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';

if ($useDb && $totalPages > 1) {
    admin_list_pagination($listPage, $total, $listPerPage, $queryBase, $base . '/index.php');
}
admin_layout_end();
