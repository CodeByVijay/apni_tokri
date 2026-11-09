<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('products');

$productsListUrl = url_base() . '/admin/products/index.php';
$useDb = Install::isSchemaReady() && AdminAuth::check();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $useDb) {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'bulk_status') {
        $rawIds = $_POST['product_ids'] ?? [];
        $ids = is_array($rawIds) ? $rawIds : [];
        $targetStatus = strtolower(trim((string) ($_POST['bulk_status'] ?? '')));
        $result = ProductRepository::bulkUpdateStatusForAdmin($ids, $targetStatus);
        $changed = 0;
        foreach ($result['results'] as $row) {
            if (!empty($row['ok']) && empty($row['unchanged'])) {
                $changed++;
            }
        }
        $targetLabel = $targetStatus === 'published' ? 'Published' : 'Draft';
        if (!$result['ok'] && $result['failed'] > 0) {
            $failedList = $result['failed_names'] !== [] ? implode(', ', $result['failed_names']) : 'Unknown products';
            if ($changed > 0) {
                admin_flash_set(
                    'Updated ' . $changed . ' product(s) to ' . $targetLabel . '. Failed: ' . $failedList . '.',
                    'warning'
                );
            } else {
                admin_flash_set('Could not update selected products. Failed: ' . $failedList . '.', 'error');
            }
        } elseif ($changed > 0) {
            admin_flash_set('Updated ' . $changed . ' product(s) to ' . $targetLabel . '.', 'success');
        } else {
            admin_flash_set('No product status changes were needed.', 'info');
        }
        $redirectQuery = array_filter([
            'q' => trim((string) ($_POST['list_q'] ?? '')) !== '' ? trim((string) $_POST['list_q']) : null,
            'category' => trim((string) ($_POST['list_category'] ?? '')) !== '' ? trim((string) $_POST['list_category']) : null,
            'status' => trim((string) ($_POST['list_status'] ?? '')) !== '' ? trim((string) $_POST['list_status']) : null,
            'sort' => trim((string) ($_POST['list_sort'] ?? '')) !== '' ? trim((string) $_POST['list_sort']) : null,
            'dir' => trim((string) ($_POST['list_dir'] ?? '')) !== '' ? trim((string) ($_POST['list_dir'] ?? '')) : null,
            'page' => max(1, (int) ($_POST['list_page'] ?? 1)),
            'per_page' => (int) ($_POST['list_per_page'] ?? ProductRepository::ADMIN_LIST_DEFAULT_PER_PAGE),
        ]);
        $qs = http_build_query($redirectQuery);
        header('Location: ' . $productsListUrl . ($qs !== '' ? '?' . $qs : ''), true, 302);
        exit;
    }
}

if ($useDb) {
    admin_process_list_delete('products', $productsListUrl);
}

$filterQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$filterCat = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
$filterStatus = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequestWithOptions(
    isset($_GET['per_page']) ? (int) $_GET['per_page'] : null,
    ProductRepository::ADMIN_LIST_PER_PAGE_OPTIONS,
    ProductRepository::ADMIN_LIST_DEFAULT_PER_PAGE
);
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
    'per_page' => $listPerPage !== ProductRepository::ADMIN_LIST_DEFAULT_PER_PAGE ? $listPerPage : null,
]);

admin_layout_start(['pageTitle' => 'Products', 'activeAdminNav' => 'products']);
$pageHeading = 'Products';
$pageDescription = 'Manage catalogue, variants, images and visibility.';
$pageActionsHtml = '<a href="' . Security::escape(url_base()) . '/admin/products/product-types.php" class="at-btn at-btn--secondary at-btn--sm at-focusable">Product types</a>'
    . ' <a href="' . Security::escape(url_base()) . '/admin/products/bulk-import.php" class="at-btn at-btn--secondary at-btn--sm at-focusable">Bulk import</a>'
    . ' <a href="' . Security::escape(url_base()) . '/admin/products/edit.php" class="at-btn at-btn--primary at-btn--sm at-focusable">Add product</a>';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if ($useDb): ?>
<form method="get" class="at-admin-card mb-4" id="at-products-filter-form">
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
        <input type="hidden" name="page" value="1">
        <input type="hidden" name="per_page" value="<?= (int) $listPerPage ?>">
        <input type="hidden" name="sort" value="<?= Security::escape($sort) ?>">
        <input type="hidden" name="dir" value="<?= Security::escape($dir) ?>">
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Filter</button>
    </div>
</form>
<?php endif;

$columns = [
    ['label' => 'Name', 'label_html' => AdminServerList::sortHeaderLink('name', 'Name', $sort, $dir, $productsListUrl, $queryBase), 'key' => 'name'],
    ['label' => 'SKU', 'key' => 'sku'],
    ['label' => 'Category', 'key' => 'cat'],
    ['label' => 'Status', 'key' => 'status'],
    ['label' => 'Actions', 'key' => 'actions', 'align' => 'actions', 'raw' => true],
];
$rows = [];
$total = 0;
$totalPages = 1;
$enableBulkSelect = $useDb;
$bulkFormId = 'at-products-bulk-form';

if ($useDb) {
    if ($enableBulkSelect): ?>
    <form method="post" class="at-admin-card mb-4" id="at-products-bulk-form" data-at-products-bulk>
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="bulk_status">
        <input type="hidden" name="list_q" value="<?= Security::escape($filterQ) ?>">
        <input type="hidden" name="list_category" value="<?= Security::escape($filterCat) ?>">
        <input type="hidden" name="list_status" value="<?= Security::escape($filterStatus) ?>">
        <input type="hidden" name="list_sort" value="<?= Security::escape($sort) ?>">
        <input type="hidden" name="list_dir" value="<?= Security::escape($dir) ?>">
        <input type="hidden" name="list_page" value="<?= (int) $listPage ?>">
        <input type="hidden" name="list_per_page" value="<?= (int) $listPerPage ?>">
        <div class="at-admin-card__body flex flex-wrap items-end gap-3">
            <div>
                <label class="at-label" for="bulk_status">Bulk status</label>
                <select id="bulk_status" name="bulk_status" class="at-select at-focusable">
                    <option value="draft">Draft</option>
                    <option value="published">Published</option>
                </select>
            </div>
            <button type="button" class="at-btn at-btn--primary at-focusable" id="at-products-bulk-apply" disabled>Apply</button>
            <p class="text-sm at-muted mb-0" id="at-products-bulk-count" role="status" aria-live="polite">0 products selected</p>
        </div>
    </form>
    <div id="at-products-bulk-confirm" class="at-modal-backdrop" aria-hidden="true" role="presentation">
        <div class="at-modal" role="dialog" aria-modal="true" aria-labelledby="at-products-bulk-confirm-title">
            <div class="at-modal__header">
                <h2 id="at-products-bulk-confirm-title" class="at-modal__title">Confirm bulk status change</h2>
                <button type="button" class="at-close at-focusable" data-at-bulk-confirm-close aria-label="Close"><span aria-hidden="true">×</span></button>
            </div>
            <div class="at-modal__body space-y-2">
                <p id="at-products-bulk-confirm-summary" class="text-sm"></p>
                <ul id="at-products-bulk-confirm-list" class="text-sm at-muted max-h-48 overflow-y-auto list-disc pl-5 space-y-1"></ul>
            </div>
            <div class="at-modal__footer flex gap-2">
                <button type="button" class="at-btn at-btn--ghost at-focusable" data-at-bulk-confirm-close>Cancel</button>
                <button type="button" class="at-btn at-btn--primary at-focusable" id="at-products-bulk-confirm-submit">Confirm and apply</button>
            </div>
        </div>
    </div>
    <?php endif;

    $list = ProductRepository::searchForAdmin($filterQ, $filterCat, $filterStatus, $listPage, $listPerPage, $sort, $dir);
    $total = (int) $list['total'];
    $totalPages = (int) $list['total_pages'];
    $listPage = (int) $list['page'];
    foreach ($list['rows'] as $p) {
        $pid = (int) $p['id'];
        $pname = (string) $p['name'];
        $pstatus = (string) $p['status'];
        $rows[] = [
            'bulk_id' => $pid,
            'bulk_name' => $pname,
            'bulk_status' => $pstatus,
            'name' => $pname,
            'sku' => (string) ($p['sku'] ?? '—'),
            'cat' => (string) ($p['category_name'] ?? $p['category_slug'] ?? '—'),
            'status' => $pstatus,
            'actions' => admin_table_actions(
                url_base() . '/admin/products/edit.php?id=' . $pid,
                'Edit',
                'product',
                $pid,
                $pname,
                $listPage,
                $listPerPage,
                $filterQ
            ),
        ];
    }
} elseif (AdminPersistence::allowsDemoPreview()) {
    $enableBulkSelect = false;
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

if ($useDb) {
    admin_list_pagination(
        $listPage,
        $total,
        $listPerPage,
        $queryBase,
        $productsListUrl,
        ProductRepository::ADMIN_LIST_PER_PAGE_OPTIONS,
        $total === 1 ? 'product' : 'products',
        true,
        true
    );
    echo '<script src="' . Security::escape(asset_url('assets/js/admin-products-list.js')) . '"></script>';
}
admin_layout_end();
