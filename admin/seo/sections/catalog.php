<?php

declare(strict_types=1);

$simple = SeoAdminCenter::isSimple();
$filter = isset($_GET['seo_filter']) && is_string($_GET['seo_filter']) ? $_GET['seo_filter'] : 'missing_meta';
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPerPage = 20;
$productRows = [];
$total = 0;
if ($useDb && $filter !== 'all') {
    $pdo = Database::pdo();
    $where = ["p.status = 'published'"];
    $params = [];
    if ($listQ !== '') {
        $like = '%' . AdminServerList::escapeLike($listQ) . '%';
        $where[] = '(p.name LIKE ? OR p.slug LIKE ?)';
        $params[] = $like;
        $params[] = $like;
    }
    if ($filter === 'missing_title') {
        $where[] = "TRIM(COALESCE(p.meta_title,'')) = ''";
    } elseif ($filter === 'missing_desc') {
        $where[] = "TRIM(COALESCE(p.meta_description,'')) = ''";
    } elseif ($filter === 'missing_meta') {
        $where[] = "(TRIM(COALESCE(p.meta_title,'')) = '' OR TRIM(COALESCE(p.meta_description,'')) = '')";
    }
    $whereSql = implode(' AND ', $where);
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM products p WHERE ' . $whereSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $offset = ($listPage - 1) * $listPerPage;
    $stmt = $pdo->prepare(
        'SELECT p.id, p.name, p.slug, p.meta_title, p.meta_description FROM products p WHERE ' . $whereSql
        . ' ORDER BY p.updated_at DESC LIMIT ' . (int) $listPerPage . ' OFFSET ' . (int) $offset
    );
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $editUrl = url_base() . '/admin/products/edit.php?id=' . (int) $row['id'] . '#seo';
        $suggestTitle = SeoAdminCenter::applyTemplateTokens(SeoAdminCenter::template('product_title'), ['{product_name}' => (string) $row['name']]);
        $productRows[] = [
            'name' => (string) $row['name'],
            'seo' => trim((string) ($row['meta_title'] ?? '')) !== '' ? 'Title set' : 'Missing title',
            'desc' => trim((string) ($row['meta_description'] ?? '')) !== '' ? 'Desc set' : 'Missing description',
            'suggest' => $simple ? 'Suggested: ' . $suggestTitle : $suggestTitle,
            'actions' => '<a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="' . Security::escape($editUrl) . '">Edit SEO</a>',
        ];
    }
}
$categories = $useDb ? CategoryRepository::searchForAdmin('', 'published', 1, 100, 'sort_order', 'asc')['rows'] ?? [] : [];
?>
<div class="grid gap-6">
    <div class="at-card">
        <div class="at-card__body space-y-4">
            <h3 class="font-bold">SEO templates</h3>
            <p class="text-sm at-muted">Use placeholders: <code>{product_name}</code>, <code>{category_name}</code>, <code>{city}</code>, <code>{brand_name}</code></p>
            <form method="post" class="grid gap-3 md:grid-cols-2 max-w-4xl">
                <?= Csrf::field() ?>
                <input type="hidden" name="seo_action" value="save_templates">
                <div><label class="at-label">Product title template</label>
                    <input class="at-input at-focusable" name="product_title" value="<?= Security::escape(SeoAdminCenter::template('product_title')) ?>"></div>
                <div><label class="at-label">Product description template</label>
                    <textarea class="at-input at-focusable" name="product_desc" rows="2"><?= Security::escape(SeoAdminCenter::template('product_desc')) ?></textarea></div>
                <div><label class="at-label">Category title template</label>
                    <input class="at-input at-focusable" name="category_title" value="<?= Security::escape(SeoAdminCenter::template('category_title')) ?>"></div>
                <div><label class="at-label">Category description template</label>
                    <textarea class="at-input at-focusable" name="category_desc" rows="2"><?= Security::escape(SeoAdminCenter::template('category_desc')) ?></textarea></div>
                <div class="md:col-span-2"><button type="submit" class="at-btn at-btn--secondary at-focusable"<?= $useDb ? '' : ' disabled' ?>>Save templates</button></div>
            </form>
        </div>
    </div>

    <div class="at-card">
        <div class="at-card__body space-y-3">
            <h3 class="font-bold">Products</h3>
            <form method="get" class="flex flex-wrap gap-2 items-end">
                <input type="hidden" name="section" value="catalog">
                <input type="hidden" name="seo_filter" value="<?= Security::escape($filter) ?>">
                <input class="at-input at-focusable" name="q" value="<?= Security::escape($listQ) ?>" placeholder="Search products…">
                <button type="submit" class="at-btn at-btn--secondary at-focusable">Search</button>
            </form>
            <p class="text-sm">
                <a href="?section=catalog&seo_filter=missing_meta" class="at-focusable">Need SEO</a> ·
                <a href="?section=catalog&seo_filter=missing_title" class="at-focusable">Missing titles</a> ·
                <a href="?section=catalog&seo_filter=all" class="at-focusable">All published</a>
            </p>
            <?php
            if ($filter === 'all') {
                $result = ProductRepository::searchForAdmin($listQ, '', 'published', $listPage, $listPerPage);
                $productRows = [];
                foreach ($result['rows'] as $row) {
                    $editUrl = url_base() . '/admin/products/edit.php?id=' . (int) $row['id'] . '#seo';
                    $productRows[] = [
                        'name' => (string) ($row['name'] ?? ''),
                        'seo' => trim((string) ($row['meta_title'] ?? '')) !== '' ? 'Title set' : 'Missing',
                        'desc' => trim((string) ($row['meta_description'] ?? '')) !== '' ? 'OK' : 'Missing',
                        'suggest' => '—',
                        'actions' => '<a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="' . Security::escape($editUrl) . '">Edit</a>',
                    ];
                }
                $total = (int) ($result['total'] ?? 0);
            }
            $columns = [
                ['label' => 'Product', 'key' => 'name'],
                ['label' => 'Title', 'key' => 'seo'],
                ['label' => 'Description', 'key' => 'desc'],
                ['label' => 'Suggestion', 'key' => 'suggest'],
                ['label' => '', 'key' => 'actions', 'raw' => true, 'align' => 'actions'],
            ];
            require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
            ?>
            <p class="text-xs at-muted">For bulk apply, select products on the products list and use templates from Advanced mode below.</p>
            <?php if ($useDb && !SeoAdminCenter::isSimple()): ?>
            <form method="post" class="space-y-2 border-t border-[var(--at-line)] pt-3">
                <?= Csrf::field() ?>
                <input type="hidden" name="seo_action" value="bulk_apply_products">
                <p class="text-sm font-medium">Bulk apply template (by product ID, comma-separated)</p>
                <input class="at-input at-focusable" name="product_ids_csv" placeholder="Product IDs, comma-separated (e.g. 12, 45, 88)">
                <label class="flex gap-2 text-sm"><input type="checkbox" name="only_fill_blanks" value="1" checked> Only fill blank SEO fields</label>
                <button type="submit" class="at-btn at-btn--primary at-btn--sm at-focusable">Apply template</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <div id="categories" class="at-card">
        <div class="at-card__body space-y-3">
            <h3 class="font-bold">Categories</h3>
            <ul class="divide-y divide-[var(--at-line)] text-sm">
                <?php foreach ($categories as $cat): ?>
                    <?php
                    $cid = (int) ($cat['id'] ?? 0);
                    $mt = trim((string) ($cat['meta_title'] ?? ''));
                    $md = trim((string) ($cat['meta_description'] ?? ''));
                    ?>
                    <li class="py-2 flex flex-wrap justify-between gap-2">
                        <span><?= Security::escape((string) ($cat['name'] ?? '')) ?>
                            <span class="at-muted text-xs"><?= $mt === '' ? '· missing title' : '' ?><?= $md === '' ? ' · missing description' : '' ?></span>
                        </span>
                        <a class="at-btn at-btn--sm at-btn--ghost at-focusable" href="<?= Security::escape(url_base() . '/admin/categories/edit.php?id=' . $cid) ?>">Edit SEO</a>
                    </li>
                <?php endforeach; ?>
                <?php if ($categories === []): ?>
                    <li class="at-muted">No published categories.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>
