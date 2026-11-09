<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('cms');

$useDb = Install::isSchemaReady() && AdminAuth::check() && CmsPageService::schemaReady();
if ($useDb) {
    admin_process_list_delete('cms', url_base() . '/admin/cms/index.php');
}
$filterQ = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$filterStatus = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
$filterVisibility = isset($_GET['visibility']) ? trim((string) $_GET['visibility']) : '';
$pageNum = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 20;

admin_layout_start(['pageTitle' => 'CMS pages', 'activeAdminNav' => 'cms']);
$pageHeading = 'CMS & legal pages';
$pageDescription = 'Manage storefront legal and content pages. Draft pages are never visible on the public site.';
$pageActionsHtml = '<a href="' . Security::escape(url_base()) . '/admin/cms/edit.php" class="at-btn at-btn--primary at-btn--sm at-focusable">New page</a>';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if ($useDb): ?>
<form method="get" class="at-admin-card mb-4">
    <div class="at-admin-card__body flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[10rem]">
            <label class="at-label" for="q">Search</label>
            <input id="q" name="q" class="at-input at-focusable" value="<?= Security::escape($filterQ) ?>" placeholder="Title or slug">
        </div>
        <div>
            <label class="at-label" for="status">Status</label>
            <select id="status" name="status" class="at-select at-focusable">
                <option value="">All</option>
                <option value="<?= PublishStatus::DRAFT ?>"<?= $filterStatus === PublishStatus::DRAFT ? ' selected' : '' ?>>Draft</option>
                <option value="<?= PublishStatus::PUBLISHED ?>"<?= $filterStatus === PublishStatus::PUBLISHED ? ' selected' : '' ?>>Published</option>
            </select>
        </div>
        <div>
            <label class="at-label" for="visibility">Visibility</label>
            <select id="visibility" name="visibility" class="at-select at-focusable">
                <option value="">All</option>
                <option value="public"<?= $filterVisibility === 'public' ? ' selected' : '' ?>>Public</option>
                <option value="private"<?= $filterVisibility === 'private' ? ' selected' : '' ?>>Private</option>
            </select>
        </div>
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Filter</button>
    </div>
</form>
<?php endif;

$columns = [
    ['label' => 'Title', 'key' => 'title'],
    ['label' => 'Slug', 'key' => 'slug'],
    ['label' => 'Status', 'key' => 'status'],
    ['label' => 'Visibility', 'key' => 'visibility'],
    ['label' => 'Footer', 'key' => 'footer'],
    ['label' => 'Published', 'key' => 'published_at'],
    ['label' => 'Updated', 'key' => 'updated_at'],
    ['label' => 'Actions', 'key' => 'actions', 'align' => 'actions', 'raw' => true],
];
$rows = [];
$total = 0;
$totalPages = 1;

if ($useDb) {
    $statusFilter = $filterStatus !== '' ? $filterStatus : null;
    $visFilter = $filterVisibility !== '' ? $filterVisibility : null;
    $result = CmsPageService::searchForAdmin($filterQ, $statusFilter, $visFilter, $pageNum, $perPage);
    $total = (int) ($result['total'] ?? 0);
    $totalPages = max(1, (int) ceil($total / $perPage));
    foreach ($result['rows'] as $p) {
        $id = (int) ($p['id'] ?? 0);
        $rows[] = [
            'title' => (string) ($p['title'] ?? ''),
            'slug' => (string) ($p['slug'] ?? ''),
            'status' => (string) ($p['status'] ?? ''),
            'visibility' => (string) ($p['visibility'] ?? 'public'),
            'footer' => !empty($p['show_in_footer']) ? 'Yes' : 'No',
            'published_at' => $p['published_at'] ? substr((string) $p['published_at'], 0, 16) : '—',
            'updated_at' => $p['updated_at'] ? substr((string) $p['updated_at'], 0, 16) : '—',
            'actions' => admin_table_actions(
                url_base() . '/admin/cms/edit.php?id=' . $id,
                'Edit',
                'cms_page',
                $id,
                (string) ($p['title'] ?? 'page')
            ),
        ];
    }
}

require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';

if ($useDb && $totalPages > 1):
    $baseQs = http_build_query(array_filter([
        'q' => $filterQ !== '' ? $filterQ : null,
        'status' => $filterStatus !== '' ? $filterStatus : null,
        'visibility' => $filterVisibility !== '' ? $filterVisibility : null,
    ]));
    ?>
<p class="mt-4 text-sm at-muted flex flex-wrap gap-2 items-center">
    <span>Page <?= (int) $pageNum ?> of <?= (int) $totalPages ?> (<?= (int) $total ?> pages)</span>
    <?php if ($pageNum > 1): ?>
        <a class="underline font-semibold" href="?<?= Security::escape($baseQs . ($baseQs !== '' ? '&' : '') . 'page=' . ($pageNum - 1)) ?>">Previous</a>
    <?php endif; ?>
    <?php if ($pageNum < $totalPages): ?>
        <a class="underline font-semibold" href="?<?= Security::escape($baseQs . ($baseQs !== '' ? '&' : '') . 'page=' . ($pageNum + 1)) ?>">Next</a>
    <?php endif; ?>
</p>
<?php endif;

if (!$useDb): ?>
<div class="at-alert at-alert--info mt-4">Connect the database and apply migration <code>1.9.0_stage13_cms_foundation.sql</code> to manage pages.</div>
<?php endif;

admin_layout_end();
