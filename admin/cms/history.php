<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('cms');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$versionId = isset($_GET['version']) ? (int) $_GET['version'] : 0;
$page = CmsPageService::getByIdForAdmin($id);

if ($page === null) {
    http_response_code(404);
    admin_layout_start(['pageTitle' => 'History', 'activeAdminNav' => 'cms']);
    echo '<div class="at-alert at-alert--error">Page not found.</div>';
    admin_layout_end();
    exit;
}

$versions = CmsPageService::versionHistoryForPage($id);
$versionDetail = $versionId > 0 ? CmsPageService::versionForAdmin($versionId) : null;
if ($versionId > 0 && $versionDetail === null) {
    admin_flash_set('Version not found or not accessible.', 'error');
}

admin_layout_start(['pageTitle' => 'Version history', 'activeAdminNav' => 'cms']);
$pageHeading = 'Version history';
$pageDescription = (string) ($page['title'] ?? '') . ' — append-only audit trail.';
$pageActionsHtml = '<a href="' . Security::escape(url_base()) . '/admin/cms/edit.php?id=' . (int) $id . '" class="at-btn at-btn--secondary at-btn--sm at-focusable">Edit page</a>';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if ($versionDetail !== null && (int) ($versionDetail['cms_page_id'] ?? 0) === $id): ?>
<div class="at-card max-w-3xl mb-6">
    <div class="at-card__body">
        <h2 class="font-bold text-lg mb-2">Version #<?= (int) ($versionDetail['version_number'] ?? 0) ?></h2>
        <p class="text-sm at-muted mb-4">
            <?= Security::escape(substr((string) ($versionDetail['created_at'] ?? ''), 0, 19)) ?>
            · Status <?= Security::escape((string) ($versionDetail['status'] ?? '')) ?>
            <?php if (!empty($versionDetail['change_summary'])): ?>
                · <?= Security::escape((string) $versionDetail['change_summary']) ?>
            <?php endif; ?>
        </p>
        <h3 class="font-semibold text-lg"><?= Security::escape((string) ($versionDetail['title'] ?? '')) ?></h3>
        <div class="cms-body border-t pt-4 mt-2">
            <?= (string) ($versionDetail['body_html'] ?? '') ?>
        </div>
    </div>
</div>
<?php endif;

$columns = [
    ['label' => '#', 'key' => 'ver', 'align' => 'num'],
    ['label' => 'When', 'key' => 'when'],
    ['label' => 'Status', 'key' => 'status'],
    ['label' => 'Summary', 'key' => 'summary'],
    ['label' => 'Editor', 'key' => 'editor'],
    ['label' => '', 'key' => 'link', 'align' => 'actions'],
];
$rows = [];
foreach ($versions as $v) {
    $vid = (int) ($v['id'] ?? 0);
    $rows[] = [
        'ver' => (string) ($v['version_number'] ?? ''),
        'when' => substr((string) ($v['created_at'] ?? ''), 0, 19),
        'status' => (string) ($v['status'] ?? ''),
        'summary' => (string) ($v['change_summary'] ?? '—'),
        'editor' => !empty($v['created_by']) ? '#' . (int) $v['created_by'] : '—',
        'link' => 'View',
        'action_href' => url_base() . '/admin/cms/history.php?id=' . $id . '&version=' . $vid,
    ];
}
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
admin_layout_end();
