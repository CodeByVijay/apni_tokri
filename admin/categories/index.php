<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('categories');

$useDb = Install::isSchemaReady() && AdminAuth::check();
if ($useDb) {
    admin_process_list_delete('categories', url_base() . '/admin/categories/index.php');
}
$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$sort = AdminServerList::resolveSort((string) ($_GET['sort'] ?? 'sort_order'), [
    'name' => 'name', 'slug' => 'slug', 'sort_order' => 'sort_order',
], 'sort_order');
$dir = (string) ($_GET['dir'] ?? 'asc');
$queryBase = array_filter(['q' => $listQ !== '' ? $listQ : null]);

admin_layout_start(['pageTitle' => 'Categories', 'activeAdminNav' => 'categories']);
$pageHeading = 'Categories';
$pageActionsHtml = '<a href="' . Security::escape(url_base()) . '/admin/categories/edit.php" class="at-btn at-btn--primary at-btn--sm at-focusable">Add category</a>';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if ($useDb): ?>
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
<?php endif;

$columns = [
    ['label' => 'Name', 'label_html' => AdminServerList::sortHeaderLink('name', 'Name', $sort, $dir, url_base() . '/admin/categories/index.php', $queryBase), 'key' => 'name'],
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
    admin_list_pagination($listPage, $total, $listPerPage, $queryBase, url_base() . '/admin/categories/index.php');
}
admin_layout_end();
