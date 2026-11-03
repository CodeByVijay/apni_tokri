<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('products');

$useDb = Install::isSchemaReady() && AdminAuth::check();
if ($useDb) {
    admin_process_list_delete('products', url_base() . '/admin/products/index.php');
}
$filterQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$filterCat = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
$filterStatus = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$sort = AdminServerList::resolveSort((string) ($_GET['sort'] ?? 'updated_at'), [
    'name' => 'name', 'sku' => 'sku', 'updated_at' => 'updated_at', 'status' => 'status',
], 'updated_at');
$dir = (string) ($_GET['dir'] ?? 'desc');
$queryBase = array_filter([
    'q' => $filterQ !== '' ? $filterQ : null,
    'category' => $filterCat !== '' ? $filterCat : null,
    'status' => $filterStatus !== '' ? $filterStatus : null,
    'sort' => $sort !== 'updated_at' ? $sort : null,
    'dir' => strtolower($dir) !== 'desc' ? $dir : null,
]);

admin_layout_start(['pageTitle' => 'Products', 'activeAdminNav' => 'products']);
$pageHeading = 'Products';
$pageDescription = 'Manage catalogue, variants, images and visibility.';
$pageActionsHtml = '<a href="' . Security::escape(url_base()) . '/admin/products/edit.php" class="at-btn at-btn--primary at-btn--sm at-focusable">Add product</a>';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if ($useDb): ?>
<form method="get" class="at-admin-card mb-4">
    <div class="at-admin-card__body flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[10rem]">
            <label class="at-label" for="q">Search</label>
            <input id="q" name="q" class="at-input at-focusable" value="<?= Security::escape($filterQ) ?>" placeholder="Name, SKU, slug">
        </div>
        <div>
            <label class="at-label" for="category">Category</label>
            <select id="category" name="category" class="at-select at-focusable">
                <option value="">All</option>
                <?php foreach (CategoryRepository::allForAdmin() as $c): ?>
                    <option value="<?= Security::escape((string) $c['slug']) ?>"<?= $filterCat === $c['slug'] ? ' selected' : '' ?>><?= Security::escape((string) $c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="at-label" for="status">Status</label>
            <select id="status" name="status" class="at-select at-focusable">
                <option value="">All</option>
                <?php foreach (['published', 'draft', 'archived'] as $st): ?>
                    <option value="<?= $st ?>"<?= $filterStatus === $st ? ' selected' : '' ?>><?= ucfirst($st) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="hidden" name="per_page" value="<?= (int) $listPerPage ?>">
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Filter</button>
    </div>
</form>
<?php endif;

$columns = [
    ['label' => 'Name', 'label_html' => AdminServerList::sortHeaderLink('name', 'Name', $sort, $dir, url_base() . '/admin/products/index.php', $queryBase), 'key' => 'name'],
    ['label' => 'SKU', 'key' => 'sku'],
    ['label' => 'Category', 'key' => 'cat'],
    ['label' => 'Status', 'key' => 'status'],
    ['label' => 'Actions', 'key' => 'actions', 'align' => 'actions', 'raw' => true],
];
$rows = [];
$total = 0;
$totalPages = 1;
if ($useDb) {
    $list = ProductRepository::searchForAdmin($filterQ, $filterCat, $filterStatus, $listPage, $listPerPage, $sort, $dir);
    $total = (int) $list['total'];
    $totalPages = (int) $list['total_pages'];
    $listPage = (int) $list['page'];
    foreach ($list['rows'] as $p) {
        $pid = (int) $p['id'];
        $rows[] = [
            'name' => (string) $p['name'],
            'sku' => (string) ($p['sku'] ?? '—'),
            'cat' => (string) ($p['category_name'] ?? $p['category_slug'] ?? '—'),
            'status' => (string) $p['status'],
            'actions' => admin_table_actions(
                url_base() . '/admin/products/edit.php?id=' . $pid,
                'Edit',
                'product',
                $pid,
                (string) $p['name'],
                $listPage,
                $listPerPage,
                $filterQ
            ),
        ];
    }
} elseif (AdminPersistence::allowsDemoPreview()) {
    foreach (demo_catalogue()->products() as $p) {
        $rows[] = [
            'name' => (string) $p['name'],
            'sku' => (string) ($p['sku'] ?? ''),
            'cat' => (string) ($p['category_slug'] ?? ''),
            'status' => 'Published (demo)',
            'actions' => '',
        ];
    }
}
$tableClass = 'at-admin-table';
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
if ($useDb && $totalPages > 1) {
    admin_list_pagination($listPage, $total, $listPerPage, $queryBase, url_base() . '/admin/products/index.php');
}
admin_layout_end();
