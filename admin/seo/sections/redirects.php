<?php

declare(strict_types=1);

/** Redirects & 404 — preserved 301 management + 404 log. */
$useDb = AdminPersistence::isAvailable();
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
            . '<input type="hidden" name="seo_action" value="redirect_delete">'
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
$nfRows = $useDb && SeoNotFoundLog::tableReady() ? SeoNotFoundLog::listForAdmin(30) : [];
$prefillFrom = isset($_GET['from']) && is_string($_GET['from']) ? trim($_GET['from']) : '';
?>
<div class="at-seo-saas__stack">
    <div id="redirect-form" class="at-seo-saas__panel space-y-3">
        <h3 class="at-seo-saas__panel-title">Add redirect</h3>
        <p class="text-sm at-muted">When a page address changes, add a permanent redirect so visitors and Google reach the new URL.</p>
        <form method="post" class="space-y-3 max-w-xl">
            <?= Csrf::field() ?>
            <input type="hidden" name="section" value="redirects">
            <input type="hidden" name="seo_action" value="redirect_save">
            <div><label class="at-label">Old path</label><input class="at-input at-focusable" name="from" placeholder="/old-product-slug" value="<?= Security::escape($prefillFrom) ?>" required<?= $useDb ? '' : ' disabled' ?>></div>
            <div><label class="at-label">New path</label><input class="at-input at-focusable" name="to" placeholder="/fresh-red-tomatoes" required<?= $useDb ? '' : ' disabled' ?>></div>
            <button type="submit" class="at-btn at-btn--primary at-focusable"<?= $useDb ? '' : ' disabled' ?>>Save 301 redirect</button>
        </form>
    </div>

    <?php if ($useDb): ?>
    <form method="get" class="flex gap-2 max-w-md">
        <input type="hidden" name="section" value="redirects">
        <input class="at-input at-focusable flex-1" name="q" value="<?= Security::escape($listQ) ?>" placeholder="Search paths…">
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Search</button>
    </form>
    <?php
    $columns = [
        ['label' => 'From', 'key' => 'from'],
        ['label' => 'To', 'key' => 'to'],
        ['label' => 'Code', 'key' => 'code', 'align' => 'num'],
        ['label' => 'Actions', 'key' => 'actions', 'align' => 'actions', 'raw' => true],
    ];
    require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
    if ($totalPages > 1) {
        admin_list_pagination($listPage, $total, $listPerPage, array_filter(['q' => $listQ !== '' ? $listQ : null, 'section' => 'redirects']), url_base() . '/admin/seo/index.php');
    }
    ?>

    <div class="at-seo-saas__panel space-y-3">
        <h3 class="at-seo-saas__panel-title">404 errors</h3>
        <p class="text-sm at-muted">URLs visitors requested that were not found. Create a redirect when you have a better destination — do not send every 404 to the homepage.</p>
        <?php if ($nfRows === []): ?>
            <p class="text-sm at-muted">No recent 404 requests recorded<?= SeoNotFoundLog::tableReady() ? '' : ' (run migration 1.35.0 to enable logging)' ?>.</p>
        <?php else: ?>
            <ul class="divide-y divide-[var(--at-line)] text-sm">
                <?php foreach ($nfRows as $nf): ?>
                    <li class="py-2 flex flex-wrap gap-2 items-center justify-between">
                        <span class="font-mono break-all"><?= Security::escape((string) ($nf['request_path'] ?? '')) ?></span>
                        <span class="at-muted"><?= (int) ($nf['hit_count'] ?? 0) ?> hits</span>
                        <a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="?section=redirects&amp;from=<?= rawurlencode((string) ($nf['request_path'] ?? '')) ?>#redirect-form">Create redirect</a>
                        <form method="post" class="inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="seo_action" value="nf_ignore">
                            <input type="hidden" name="nf_id" value="<?= (int) ($nf['id'] ?? 0) ?>">
                            <button type="submit" class="at-btn at-btn--ghost at-btn--sm at-focusable">Ignore</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
