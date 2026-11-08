<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('categories');

$useDb = Install::isSchemaReady() && AdminAuth::check();
$self = url_base() . '/admin/categories/index.php';
$reorderMode = isset($_GET['reorder']) && (string) $_GET['reorder'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $useDb) {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'reorder') {
        $parseIds = static function (string $raw): array {
            $raw = trim($raw);
            if ($raw === '') {
                return [];
            }
            $parts = preg_split('/\s*,\s*/', $raw) ?: [];
            $out = [];
            foreach ($parts as $p) {
                $id = (int) $p;
                if ($id > 0) {
                    $out[] = $id;
                }
            }

            return $out;
        };
        $ordered = $parseIds((string) ($_POST['category_order'] ?? ''));
        $baseline = $parseIds((string) ($_POST['baseline_order'] ?? ''));
        $result = CategoryRepository::reorderAll($ordered, $baseline);
        admin_flash_set(
            (string) ($result['message'] ?? ($result['ok'] ? 'Order saved.' : 'Could not save order.')),
            !empty($result['ok']) ? 'success' : 'error'
        );
        header('Location: ' . $self, true, 302);
        exit;
    }
}

if ($useDb) {
    admin_process_list_delete('categories', $self);
}
$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$sort = AdminServerList::resolveSort((string) ($_GET['sort'] ?? 'sort_order'), [
    'name' => 'name', 'slug' => 'slug', 'sort_order' => 'sort_order',
], 'sort_order');
$dir = (string) ($_GET['dir'] ?? 'asc');
$queryBase = array_filter(['q' => $listQ !== '' ? $listQ : null]);

$reorderCategories = ($useDb && $reorderMode) ? CategoryRepository::allOrdered() : [];
$reorderBaselineIds = $reorderCategories !== [] ? array_map(static fn (array $r): int => (int) $r['id'], $reorderCategories) : [];
$categoryCount = $useDb ? count(CategoryRepository::orderedIdList()) : 0;

admin_layout_start(['pageTitle' => 'Categories', 'activeAdminNav' => 'categories']);
$pageHeading = $reorderMode ? 'Reorder categories' : 'Categories';
$pageActionsHtml = '';
if (!$reorderMode) {
    $pageActionsHtml = '<a href="' . Security::escape(url_base()) . '/admin/categories/edit.php" class="at-btn at-btn--primary at-btn--sm at-focusable">Add category</a>';
    if ($useDb && $categoryCount > 0) {
        $pageActionsHtml .= ' <a href="' . Security::escape($self . '?reorder=1') . '" class="at-btn at-btn--secondary at-btn--sm at-focusable">Reorder categories</a>';
    }
} else {
    $pageActionsHtml = '<a href="' . Security::escape($self) . '" class="at-btn at-btn--ghost at-btn--sm at-focusable">Back to list</a>';
}
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if ($reorderMode): ?>
    <div class="at-admin-card" data-at-category-reorder>
        <div class="at-admin-card__body space-y-3">
            <p class="text-sm at-muted">Drag rows or use the arrow buttons. All categories appear here (including hidden). Customers only see published categories with published ancestors; homepage tiles also require “Show on homepage”.</p>
            <p class="text-sm at-dslots-reorder-status hidden" data-at-dslots-reorder-status role="status" aria-live="polite"></p>
            <?php if ($reorderCategories === []): ?>
                <p class="text-sm at-muted">No categories yet.</p>
            <?php else: ?>
                <form method="post" class="space-y-3" data-at-dslots-reorder-form data-at-dslots-initial="<?= Security::escape(implode(',', $reorderBaselineIds)) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="reorder">
                    <input type="hidden" name="category_order" value="<?= Security::escape(implode(',', $reorderBaselineIds)) ?>" data-at-dslots-order-input>
                    <input type="hidden" name="baseline_order" value="<?= Security::escape(implode(',', $reorderBaselineIds)) ?>" data-at-dslots-baseline-input>
                    <div class="at-admin-table-wrap">
                        <table class="at-admin-table at-admin-table--categories-reorder">
                            <thead><tr>
                                <th class="at-dslots-reorder-col-handle" scope="col"><span class="sr-only">Drag</span></th>
                                <th class="is-num at-dslots-reorder-col-seq" scope="col">#</th>
                                <th scope="col">Name</th>
                                <th scope="col">Slug</th>
                                <th scope="col">Homepage</th>
                                <th scope="col">Status</th>
                                <th class="at-dslots-reorder-col-move" scope="col"><span class="sr-only">Move</span></th>
                            </tr></thead>
                            <tbody data-at-dslots-reorder-body>
                            <?php foreach ($reorderCategories as $index => $c):
                                $cid = (int) $c['id'];
                                $name = (string) ($c['name'] ?? '');
                                ?>
                                <tr data-category-id="<?= $cid ?>" data-at-dslots-row>
                                    <td class="at-dslots-reorder-col-handle">
                                        <button type="button" class="at-dslots-reorder-handle at-focusable" draggable="true" aria-label="Drag to reorder <?= Security::escape($name) ?>" data-at-dslots-drag-handle>⋮⋮</button>
                                    </td>
                                    <td class="is-num at-dslots-reorder-col-seq" data-at-dslots-seq><?= $index + 1 ?></td>
                                    <td><?= Security::escape($name) ?></td>
                                    <td><?= Security::escape((string) ($c['slug'] ?? '')) ?></td>
                                    <td><?= !empty($c['show_on_homepage']) ? 'Yes' : 'No' ?></td>
                                    <td><?= Security::escape((string) ($c['status'] ?? '')) ?></td>
                                    <td class="at-dslots-reorder-col-move">
                                        <span class="at-dslots-reorder-arrows">
                                            <button type="button" class="at-btn at-btn--sm at-btn--ghost at-focusable" data-at-dslots-up aria-label="Move <?= Security::escape($name) ?> up">↑</button>
                                            <button type="button" class="at-btn at-btn--sm at-btn--ghost at-focusable" data-at-dslots-down aria-label="Move <?= Security::escape($name) ?> down">↓</button>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex flex-wrap gap-2 items-center">
                        <button type="submit" class="at-btn at-btn--primary at-focusable" data-at-dslots-save disabled>Save order</button>
                        <button type="button" class="at-btn at-btn--ghost at-focusable" data-at-dslots-cancel>Cancel</button>
                        <span class="text-sm at-muted" data-at-dslots-unsaved-hint hidden>You have unsaved changes.</span>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>

<?php if ($useDb): ?>
<form method="get" class="at-admin-card mb-4">
    <div class="at-admin-card__body flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[10rem]">
            <label class="at-label" for="q">Search</label>
            <input id="q" name="q" class="at-input at-focusable" value="<?= Security::escape($listQ) ?>" placeholder="Name or slug">
        </div>
        <input type="hidden" name="per_page" value="<?= (int) $listPerPage ?>">
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Search</button>
    </div>
</form>
<?php endif; ?>

<?php
$columns = [
    ['label' => 'Name', 'label_html' => AdminServerList::sortHeaderLink('name', 'Name', $sort, $dir, $self, $queryBase), 'key' => 'name'],
    ['label' => 'Slug', 'key' => 'slug'],
    ['label' => 'Homepage', 'key' => 'home'],
    ['label' => 'Status', 'key' => 'status'],
    ['label' => 'Actions', 'key' => 'actions', 'align' => 'actions', 'raw' => true],
];
$rows = [];
$total = 0;
$totalPages = 1;
if ($useDb) {
    $list = CategoryRepository::searchForAdmin($listQ, null, $listPage, $listPerPage, $sort, $dir);
    $total = (int) $list['total'];
    $totalPages = (int) $list['total_pages'];
    $listPage = (int) $list['page'];
    foreach ($list['rows'] as $c) {
        $cid = (int) $c['id'];
        $rows[] = [
            'name' => (string) $c['name'],
            'slug' => (string) $c['slug'],
            'home' => !empty($c['show_on_homepage']) ? 'Yes' : 'No',
            'status' => (string) ($c['status'] ?? ''),
            'actions' => admin_table_actions(
                url_base() . '/admin/categories/edit.php?id=' . $cid,
                'Edit',
                'category',
                $cid,
                (string) $c['name'],
                $listPage,
                $listPerPage,
                $listQ
            ),
        ];
    }
} elseif (AdminPersistence::allowsDemoPreview()) {
    foreach (demo_catalogue()->categories() as $c) {
        $rows[] = ['name' => $c['name'], 'slug' => $c['slug'], 'home' => 'Yes', 'status' => 'published (demo)', 'actions' => ''];
    }
}
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
if ($useDb && $totalPages > 1) {
    admin_list_pagination($listPage, $total, $listPerPage, $queryBase, $self);
}
?>
<?php endif; ?>

<?php admin_layout_end(); ?>
