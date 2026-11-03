<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('seo');
$useDb = AdminPersistence::isAvailable();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!$useDb) {
        admin_flash_persistence_blocked('Redirect saved (demo).');
        header('Location: ' . (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''), true, 302);
        exit;
    }
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : 'save';
    if ($action === 'delete') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $result = SeoRedirectService::deleteById($id);
        if ($result['ok']) {
            AuditLog::write('seo.redirect.delete', 'seo_redirect', $id, []);
            admin_flash_set('Redirect removed.');
        } else {
            admin_flash_set((string) ($result['message'] ?? 'Could not delete redirect.'), 'error');
        }
        header('Location: ' . url_base() . '/admin/seo/index.php', true, 302);
        exit;
    }
    $from = isset($_POST['from']) && is_string($_POST['from']) ? trim($_POST['from']) : '';
    $to = isset($_POST['to']) && is_string($_POST['to']) ? trim($_POST['to']) : '';
    $result = SeoRedirectService::registerPermanent($from, $to);
    if ($result['ok']) {
        AuditLog::write('seo.redirect.save', 'seo_redirect', null, ['from' => $from, 'to' => $to]);
        admin_flash_set('301 redirect saved.');
    } else {
        admin_flash_set((string) ($result['message'] ?? 'Could not save redirect.'), 'error');
    }
    header('Location: ' . url_base() . '/admin/seo/index.php', true, 302);
    exit;
}

$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$rows = [];
$total = 0;
$totalPages = 1;
if ($useDb) {
    $result = SeoRedirectRepository::searchForAdmin($listPage, $listPerPage, $listQ);
    $total = (int) ($result['total'] ?? 0);
    $totalPages = max(1, (int) ceil($total / $listPerPage));
    foreach ($result['rows'] as $row) {
        $id = (int) ($row['id'] ?? 0);
        $deleteForm = '<form method="post" class="inline" onsubmit="return confirm(\'Remove this redirect?\');">'
            . Csrf::field()
            . '<input type="hidden" name="action" value="delete">'
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<button type="submit" class="at-btn at-btn--ghost at-btn--sm at-focusable">Delete</button></form>';
        $rows[] = [
            'from' => (string) ($row['from_path'] ?? ''),
            'to' => (string) ($row['to_path'] ?? ''),
            'code' => (string) ($row['http_code'] ?? '301'),
            'actions' => $deleteForm,
        ];
    }
}

admin_layout_start(['pageTitle' => 'SEO & redirects', 'activeAdminNav' => 'seo']);
$pageHeading = 'SEO settings & 301 redirects';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
AdminPersistence::renderUnavailableAlert();
?>
<form method="post" class="at-card mb-4">
    <div class="at-card__body space-y-3">
        <h3 class="font-bold">Add redirect</h3>
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save">
        <div><label class="at-label">Old path</label><input class="at-input at-focusable" name="from" placeholder="/old-product-slug" required<?= $useDb ? '' : ' disabled' ?>></div>
        <div><label class="at-label">New path</label><input class="at-input at-focusable" name="to" placeholder="/fresh-red-tomatoes" required<?= $useDb ? '' : ' disabled' ?>></div>
        <button type="submit" class="at-btn at-btn--primary at-focusable"<?= $useDb ? '' : ' disabled' ?>>Save 301</button>
    </div>
</form>
<?php if ($useDb): ?>
<form method="get" class="mb-4 flex gap-2 max-w-md">
    <input class="at-input at-focusable flex-1" name="q" value="<?= Security::escape($listQ) ?>" placeholder="Search paths…">
    <button type="submit" class="at-btn at-btn--secondary at-focusable">Search</button>
</form>
<?php endif; ?>
<?php
$columns = [
    ['label' => 'From', 'key' => 'from'],
    ['label' => 'To', 'key' => 'to'],
    ['label' => 'Code', 'key' => 'code', 'align' => 'num'],
    ['label' => 'Actions', 'key' => 'actions', 'align' => 'actions', 'raw' => true],
];
if ($rows === [] && !$useDb && !AdminPersistence::allowsDemoPreview()) {
    $rows = [];
}
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
if ($useDb && $totalPages > 1) {
    admin_list_pagination($listPage, $total, $listPerPage, array_filter(['q' => $listQ !== '' ? $listQ : null]), url_base() . '/admin/seo/index.php');
}
?>
<p class="text-sm text-neutral-600 mt-4">Sitemap generation and structured data controls connect in Stage 13.</p>
<?php admin_layout_end(); ?>
