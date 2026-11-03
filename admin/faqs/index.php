<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('faqs');

$useDb = Install::isSchemaReady() && AdminAuth::check();
if ($useDb) {
    admin_process_list_delete('faqs', url_base() . '/admin/faqs/index.php');
}
$filterQ = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$filterCat = isset($_GET['category']) ? (int) $_GET['category'] : 0;
$filterActive = isset($_GET['active']) && $_GET['active'] !== '' ? (int) $_GET['active'] : null;
if ($filterActive !== null && $filterActive !== 0 && $filterActive !== 1) {
    $filterActive = null;
}
$pageNum = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 20;
[$sortCol, $sortDir] = FaqService::normalizeAdminSort(
    (string) ($_GET['sort'] ?? 'sort_order'),
    (string) ($_GET['dir'] ?? 'asc')
);

admin_layout_start(['pageTitle' => 'FAQs', 'activeAdminNav' => 'faqs']);
$pageHeading = 'FAQ categories & questions';
$pageDescription = 'Manage public help content. Inactive FAQs and categories are hidden on the storefront.';
$pageActionsHtml = '<a href="' . Security::escape(url_base()) . '/admin/faqs/categories.php" class="at-btn at-btn--secondary at-btn--sm at-focusable">Categories</a>'
    . ' <a href="' . Security::escape(url_base()) . '/admin/faqs/edit.php" class="at-btn at-btn--primary at-btn--sm at-focusable">Add FAQ</a>';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$categories = $useDb ? FaqRepository::listCategoriesForAdmin(true) : [];

if ($useDb): ?>
<form method="get" class="at-admin-card mb-4">
    <div class="at-admin-card__body flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[10rem]">
            <label class="at-label" for="q">Search</label>
            <input id="q" name="q" class="at-input at-focusable" value="<?= Security::escape($filterQ) ?>" placeholder="Question or answer">
        </div>
        <div>
            <label class="at-label" for="category">Category</label>
            <select id="category" name="category" class="at-select at-focusable">
                <option value="">All</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"<?= $filterCat === (int) $c['id'] ? ' selected' : '' ?>><?= Security::escape((string) $c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="at-label" for="active">Status</label>
            <select id="active" name="active" class="at-select at-focusable">
                <option value="">All</option>
                <option value="1"<?= $filterActive === 1 ? ' selected' : '' ?>>Active</option>
                <option value="0"<?= $filterActive === 0 ? ' selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <input type="hidden" name="sort" value="<?= Security::escape($sortCol) ?>">
        <input type="hidden" name="dir" value="<?= Security::escape($sortDir) ?>">
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Filter</button>
    </div>
</form>
<?php endif;

$columns = [
    ['label' => 'Question', 'key' => 'question'],
    ['label' => 'Category', 'key' => 'category_name'],
    ['label' => 'Order', 'key' => 'sort_order', 'align' => 'num'],
    ['label' => 'Status', 'key' => 'status'],
    ['label' => 'Actions', 'key' => 'actions', 'align' => 'actions', 'raw' => true],
];
$rows = [];
$total = 0;
$totalPages = 1;

if ($useDb) {
    $result = FaqRepository::searchForAdmin(
        $filterQ,
        $filterCat > 0 ? $filterCat : null,
        $filterActive,
        $pageNum,
        $perPage,
        $sortCol,
        $sortDir
    );
    $total = (int) ($result['total'] ?? 0);
    $totalPages = max(1, (int) ceil($total / $perPage));
    foreach ($result['rows'] as $f) {
        $fid = (int) ($f['id'] ?? 0);
        $rows[] = [
            'question' => (string) ($f['question'] ?? ''),
            'category_name' => (string) ($f['category_name'] ?? '—'),
            'sort_order' => (string) ($f['sort_order'] ?? '0'),
            'status' => !empty($f['is_active']) ? 'active' : 'inactive',
            'actions' => admin_table_actions(
                url_base() . '/admin/faqs/edit.php?id=' . $fid,
                'Edit',
                'faq',
                $fid,
                (string) ($f['question'] ?? 'FAQ')
            ),
        ];
    }
}

require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';

if ($useDb && $totalPages > 1):
    $baseQs = http_build_query(array_filter([
        'q' => $filterQ !== '' ? $filterQ : null,
        'category' => $filterCat > 0 ? $filterCat : null,
        'active' => $filterActive !== null ? $filterActive : null,
        'sort' => $sortCol,
        'dir' => $sortDir,
    ]));
    ?>
<p class="mt-4 text-sm at-muted flex flex-wrap gap-2 items-center">
    <span>Page <?= (int) $pageNum ?> of <?= (int) $totalPages ?> (<?= (int) $total ?> FAQs)</span>
    <?php if ($pageNum > 1): ?>
        <a class="underline font-semibold" href="?<?= Security::escape($baseQs . ($baseQs !== '' ? '&' : '') . 'page=' . ($pageNum - 1)) ?>">Previous</a>
    <?php endif; ?>
    <?php if ($pageNum < $totalPages): ?>
        <a class="underline font-semibold" href="?<?= Security::escape($baseQs . ($baseQs !== '' ? '&' : '') . 'page=' . ($pageNum + 1)) ?>">Next</a>
    <?php endif; ?>
</p>
<?php endif;

if (!$useDb): ?>
<div class="at-alert at-alert--info mt-4">Connect the database to manage FAQs.</div>
<?php endif;

admin_layout_end();
