<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

AdminDemoPerms::denyIfCannot('products');

$useDb = Install::isSchemaReady() && AdminAuth::check();
$filterQ = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$filterCat = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
$filterStatus = isset($_GET['status']) ? trim((string) $_GET['status']) : '';

admin_layout_start(['pageTitle' => 'Products', 'activeAdminNav' => 'products']);
admin_flash_render();
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
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Filter</button>
    </div>
</form>
<?php endif;

$columns = [
    ['label' => 'Name', 'key' => 'name'],
    ['label' => 'SKU', 'key' => 'sku'],
    ['label' => 'Category', 'key' => 'cat'],
    ['label' => 'Status', 'key' => 'status'],
    ['label' => '', 'key' => 'link', 'align' => 'actions'],
];
$rows = [];

if ($useDb) {
    foreach (ProductRepository::adminList(
        $filterQ !== '' ? $filterQ : null,
        $filterCat !== '' ? $filterCat : null,
        $filterStatus !== '' ? $filterStatus : null
    ) as $p) {
        $rows[] = [
            'name' => (string) $p['name'],
            'sku' => (string) ($p['sku'] ?? '—'),
            'cat' => (string) ($p['category_name'] ?? $p['category_slug'] ?? '—'),
            'status' => (string) $p['status'],
            'link' => 'Edit',
            'action_href' => url_base() . '/admin/products/edit.php?id=' . (int) $p['id'],
        ];
    }
} else {
    foreach (demo_catalogue()->products() as $p) {
        $rows[] = [
            'name' => (string) $p['name'],
            'sku' => (string) ($p['sku'] ?? ''),
            'cat' => (string) ($p['category_slug'] ?? ''),
            'status' => 'Published (demo)',
            'link' => 'Edit',
            'action_href' => url_base() . '/admin/products/edit.php',
        ];
    }
}

$tableClass = 'at-admin-table';
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
admin_layout_end();
