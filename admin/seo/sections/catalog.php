<?php

declare(strict_types=1);

$simple = SeoAdminCenter::isSimple();
$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) && is_string($_GET['q']) ? $_GET['q'] : '');
$seoFilter = SeoAdminCenter::normalizeProductSeoFilter(isset($_GET['seo_filter']) ? (string) $_GET['seo_filter'] : 'all');
$statusFilter = SeoAdminCenter::normalizeProductStatusFilter(isset($_GET['status']) ? (string) $_GET['status'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$listPerPage = max(10, min(50, $listPerPage > 0 ? $listPerPage : 20));
$catalogParams = array_filter([
    'q' => $listQ !== '' ? $listQ : null,
    'seo_filter' => $seoFilter !== 'all' ? $seoFilter : null,
    'status' => $statusFilter !== '' ? $statusFilter : null,
    'page' => $listPage > 1 ? $listPage : null,
    'per_page' => $listPerPage !== 20 ? $listPerPage : null,
], static fn ($v) => $v !== null && $v !== '');
$stats = $useDb ? SeoAdminCenter::productSeoDashboardCounts() : [
    'published' => 0, 'complete' => 0, 'missing_title' => 0, 'missing_description' => 0, 'needs_improvement' => 0,
];
$list = $useDb
    ? SeoAdminCenter::searchProductSeoCatalog($listQ, $seoFilter, $statusFilter, $listPage, $listPerPage)
    : ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $listPerPage, 'total_pages' => 1];
$editProductId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
$editProduct = null;
if ($useDb && $editProductId > 0) {
    $editProduct = ProductRepository::find($editProductId);
    if ($editProduct === null) {
        $editProductId = 0;
    }
}
$listAction = SeoAdminCenter::catalogSectionUrl([]);
$filterOptions = [
    'all' => 'All products',
    'missing' => 'Missing SEO',
    'complete' => 'Complete',
    'needs_improvement' => 'Needs improvement',
];
$statusOptions = ['' => 'Any status', 'published' => 'Published', 'draft' => 'Draft', 'archived' => 'Archived'];
$clearUrl = SeoAdminCenter::catalogSectionUrl([]);
?>
<div class="at-seo-saas__stack at-seo-saas__catalog">
    <div class="at-seo-saas__stat-row at-seo-saas__catalog-stats">
        <?php
        $statCards = [
            ['label' => 'Published products', 'value' => (int) $stats['published'], 'hint' => 'Live in catalogue'],
            ['label' => 'SEO complete', 'value' => (int) $stats['complete'], 'hint' => 'Title + description ready'],
            ['label' => 'Missing title', 'value' => (int) $stats['missing_title'], 'hint' => 'No search title yet'],
            ['label' => 'Missing description', 'value' => (int) $stats['missing_description'], 'hint' => 'No summary for Google'],
            ['label' => 'Needs improvement', 'value' => (int) $stats['needs_improvement'], 'hint' => 'Partial or short text'],
        ];
        foreach ($statCards as $card): ?>
            <div class="at-seo-saas__stat">
                    <p class="at-seo-saas__stat-label"><?= Security::escape($card['label']) ?></p>
                    <p class="at-seo-saas__stat-value"><?= (int) $card['value'] ?></p>
                    <p class="at-seo-saas__stat-hint"><?= Security::escape($card['hint']) ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="at-seo-saas__panel">
            <div class="flex flex-wrap items-center justify-between gap-2 at-seo-saas__panel-head">
                <h3 class="at-seo-saas__panel-title">Product catalogue SEO</h3>
                <p class="text-sm at-muted"><?= (int) $list['total'] ?> result<?= (int) $list['total'] === 1 ? '' : 's' ?></p>
            </div>

            <form method="get" action="<?= Security::escape(url_base() . '/admin/seo/index.php') ?>" class="at-seo-saas__catalog-toolbar">
                <input type="hidden" name="section" value="catalog">
                <div class="at-seo-saas__catalog-toolbar-grid">
                    <div class="at-seo-saas__catalog-toolbar-field at-seo-saas__catalog-toolbar-field--search">
                        <label class="at-label" for="catalog-q">Search</label>
                        <input id="catalog-q" name="q" class="at-input at-focusable w-full" value="<?= Security::escape($listQ) ?>"
                               placeholder="Product name, SKU or slug">
                    </div>
                    <div class="at-seo-saas__catalog-toolbar-field">
                        <label class="at-label" for="catalog-seo-filter">SEO filter</label>
                        <select id="catalog-seo-filter" name="seo_filter" class="at-select at-focusable w-full">
                            <?php foreach ($filterOptions as $key => $label): ?>
                                <option value="<?= Security::escape($key) ?>"<?= $seoFilter === $key ? ' selected' : '' ?>><?= Security::escape($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="at-seo-saas__catalog-toolbar-field">
                        <label class="at-label" for="catalog-status">Status</label>
                        <select id="catalog-status" name="status" class="at-select at-focusable w-full">
                            <?php foreach ($statusOptions as $key => $label): ?>
                                <option value="<?= Security::escape($key) ?>"<?= $statusFilter === $key ? ' selected' : '' ?>><?= Security::escape($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="at-seo-saas__catalog-toolbar-actions">
                        <button type="submit" class="at-btn at-btn--primary at-focusable">Apply</button>
                        <a href="<?= Security::escape($clearUrl) ?>" class="at-btn at-btn--ghost at-focusable">Clear filters</a>
                    </div>
                </div>
            </form>

            <?php if (!$useDb): ?>
                <p class="text-sm at-muted">Connect the database to manage product SEO.</p>
            <?php elseif ($list['rows'] === []): ?>
                <div class="at-seo-catalog-empty text-center py-10 px-4 border border-dashed border-[var(--at-line)] rounded-lg">
                    <p class="font-bold">No products found</p>
                    <p class="text-sm at-muted mt-2 max-w-md mx-auto">
                        <?php if ($listQ !== ''): ?>
                            Nothing matches “<?= Security::escape($listQ) ?>” with the current filters.
                            If you searched for a product that already has SEO filled in, try filter “All products”.
                        <?php else: ?>
                            No products match the selected filters.
                        <?php endif; ?>
                    </p>
                    <a href="<?= Security::escape($clearUrl) ?>" class="at-btn at-btn--secondary at-focusable mt-4 inline-flex">Reset filters</a>
                </div>
            <?php else: ?>
                <div class="at-seo-catalog-table-wrap at-seo-catalog-table-wrap--desktop">
                    <table class="at-admin-table at-seo-catalog-table w-full">
                        <thead>
                            <tr>
                                <th class="at-seo-catalog-table__col-product">Product</th>
                                <th class="at-seo-catalog-table__col-meta">Title</th>
                                <th class="at-seo-catalog-table__col-meta">Description</th>
                                <th class="at-seo-catalog-table__col-health">SEO health</th>
                                <th class="is-actions at-seo-catalog-table__col-actions"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($list['rows'] as $row):
                                $health = $row['health'];
                                $manageUrl = SeoAdminCenter::catalogSectionUrl(array_merge($catalogParams, ['product_id' => (int) $row['id']]));
                                ?>
                                <tr>
                                    <td class="at-seo-catalog-table__product">
                                        <div class="at-seo-catalog-product">
                                            <span class="at-seo-catalog-thumb">
                                                <?php if (($row['thumb_url'] ?? '') !== ''): ?>
                                                    <img src="<?= Security::escape((string) $row['thumb_url']) ?>" alt="" loading="lazy">
                                                <?php else: ?>
                                                    <span class="at-seo-catalog-thumb--empty" aria-hidden="true"><?= at_icon('image', 'at-seo-catalog-thumb__icon') ?></span>
                                                <?php endif; ?>
                                            </span>
                                            <span class="at-seo-catalog-product__text">
                                                <span class="at-seo-catalog-product__name"><?= Security::escape((string) $row['name']) ?></span>
                                                <span class="at-seo-catalog-product__sku"><?= Security::escape((string) $row['sku']) ?></span>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="at-seo-catalog-table__meta"><?= Security::escape((string) ($health['title_label'] ?? '')) ?></td>
                                    <td class="at-seo-catalog-table__meta"><?= Security::escape((string) ($health['desc_label'] ?? '')) ?></td>
                                    <td class="at-seo-catalog-table__health">
                                        <span class="at-status at-status--<?= Security::escape((string) ($health['variant'] ?? 'neutral')) ?>"><?= Security::escape((string) ($health['label'] ?? '')) ?></span>
                                    </td>
                                    <td class="is-actions at-seo-catalog-table__actions">
                                        <a href="<?= Security::escape($manageUrl) ?>" class="at-btn at-btn--sm at-btn--primary at-focusable">Manage SEO</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <ul class="at-seo-catalog-cards">
                    <?php foreach ($list['rows'] as $row):
                        $health = $row['health'];
                        $manageUrl = SeoAdminCenter::catalogSectionUrl(array_merge($catalogParams, ['product_id' => (int) $row['id']]));
                        ?>
                        <li class="at-seo-catalog-card">
                            <div class="at-seo-catalog-card__head">
                                <span class="at-seo-catalog-thumb">
                                    <?php if (($row['thumb_url'] ?? '') !== ''): ?>
                                        <img src="<?= Security::escape((string) $row['thumb_url']) ?>" alt="" loading="lazy">
                                    <?php else: ?>
                                        <span class="at-seo-catalog-thumb--empty" aria-hidden="true"><?= at_icon('image', 'at-seo-catalog-thumb__icon') ?></span>
                                    <?php endif; ?>
                                </span>
                                <div class="at-seo-catalog-card__identity">
                                    <p class="at-seo-catalog-product__name"><?= Security::escape((string) $row['name']) ?></p>
                                    <p class="at-seo-catalog-product__sku"><?= Security::escape((string) $row['sku']) ?></p>
                                </div>
                            </div>
                            <dl class="at-seo-catalog-card__details">
                                <div>
                                    <dt>Title</dt>
                                    <dd><?= Security::escape((string) ($health['title_label'] ?? '')) ?></dd>
                                </div>
                                <div>
                                    <dt>Description</dt>
                                    <dd><?= Security::escape((string) ($health['desc_label'] ?? '')) ?></dd>
                                </div>
                                <div>
                                    <dt>SEO health</dt>
                                    <dd>
                                        <span class="at-status at-status--<?= Security::escape((string) ($health['variant'] ?? 'neutral')) ?>"><?= Security::escape((string) ($health['label'] ?? '')) ?></span>
                                    </dd>
                                </div>
                            </dl>
                            <div class="at-seo-catalog-card__actions">
                                <a href="<?= Security::escape($manageUrl) ?>" class="at-btn at-btn--sm at-btn--primary at-focusable">Manage SEO</a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php
                $pagerBase = array_filter([
                    'section' => 'catalog',
                    'q' => $listQ !== '' ? $listQ : null,
                    'seo_filter' => $seoFilter !== 'all' ? $seoFilter : null,
                    'status' => $statusFilter !== '' ? $statusFilter : null,
                ]);
                if ($list['total_pages'] > 1) {
                    admin_list_pagination(
                        (int) $list['page'],
                        (int) $list['total'],
                        (int) $list['per_page'],
                        $pagerBase,
                        url_base() . '/admin/seo/index.php',
                        [10, 20, 50]
                    );
                }
                ?>
            <?php endif; ?>
    </div>

    <details class="at-seo-saas__accordion">
        <summary class="at-focusable">SEO templates (products &amp; categories)</summary>
        <div class="p-4 pt-0 space-y-3 border-t border-[var(--at-line)]">
            <p class="text-sm at-muted">Placeholders: <code>{product_name}</code>, <code>{category_name}</code>, <code>{city}</code>, <code>{brand_name}</code></p>
            <form method="post" class="grid gap-3 md:grid-cols-2 max-w-4xl">
                <?= Csrf::field() ?>
                <input type="hidden" name="section" value="catalog">
                <input type="hidden" name="seo_action" value="save_templates">
                <?php foreach ($catalogParams as $hk => $hv): ?>
                    <input type="hidden" name="<?= Security::escape((string) $hk) ?>" value="<?= Security::escape((string) $hv) ?>">
                <?php endforeach; ?>
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
    </details>

    <?php
    $categories = $useDb ? CategoryRepository::searchForAdmin('', 'published', 1, 100, 'sort_order', 'asc')['rows'] ?? [] : [];
    ?>
    <div id="categories" class="at-seo-saas__panel">
            <h3 class="at-seo-saas__panel-title">Categories</h3>
            <p class="at-seo-saas__panel-sub">Open a category to edit its search title and description.</p>
            <ul class="divide-y divide-[var(--at-line)] text-sm">
                <?php foreach ($categories as $cat):
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

<?php if ($editProduct !== null): ?>
    <?php require __DIR__ . '/catalog-product-editor.php'; ?>
<?php endif; ?>
