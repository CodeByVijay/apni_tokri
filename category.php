<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

$slug = isset($_GET['slug']) && is_string($_GET['slug']) ? trim($_GET['slug']) : '';
if ($slug === '') {
    $primary = catalogue_primary_category_url();
    if (strpos($primary, '/category.php?') !== false) {
        header('Location: ' . $primary, true, 302);
        exit;
    }
}
$sort = isset($_GET['sort']) && is_string($_GET['sort']) ? $_GET['sort'] : 'relevance';
$availability = isset($_GET['availability']) && is_string($_GET['availability']) ? $_GET['availability'] : 'all';
$demoLoading = StorefrontDesignPreview::allowsLoadingPreview()
    && isset($_GET['demo'])
    && $_GET['demo'] === 'loading';

$catalogue = demo_catalogue();
$category = $catalogue->category($slug);
$loadCatalogueJs = true;

if ($category === null) {
    SeoNotFoundLog::recordCurrentRequest();
    http_response_code(404);
    layout_public_start(['seo' => SeoResolver::notFound(), 'activeNav' => 'categories']);
    require APNI_TOKRI_ROOT . '/includes/partials/demo-banner.php';
    $emptyTitle = 'Category not found';
    $emptyMessage = 'Try browsing from the homepage categories.';
    $emptyActionHref = url_base() . '/index.php';
    $emptyActionLabel = 'Home';
    require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
    layout_public_end();
    exit;
}

$products = $catalogue->productsInCategory($slug);

if ($availability === 'in_stock') {
    $products = array_values(array_filter($products, 'catalogue_is_in_stock'));
}

usort($products, static function (array $a, array $b) use ($sort): int {
    $va = catalogue_default_variant($a);
    $vb = catalogue_default_variant($b);
    $pa = $va ? catalogue_variant_prices($va)['display'] : 0;
    $pb = $vb ? catalogue_variant_prices($vb)['display'] : 0;
    if ($sort === 'price_asc') {
        return $pa <=> $pb;
    }
    if ($sort === 'price_desc') {
        return $pb <=> $pa;
    }
    if ($sort === 'newest') {
        $na = in_array('new', $a['flags'] ?? [], true) ? 1 : 0;
        $nb = in_array('new', $b['flags'] ?? [], true) ? 1 : 0;

        return $nb <=> $na;
    }

    return strcmp($a['name'] ?? '', $b['name'] ?? '');
});

$catName = Lang::locale() === 'hi' ? ($category['name_hi'] ?? $category['name']) : $category['name'];

$categoryFiltered = $sort !== 'relevance' || $availability !== 'all';
$seoCategory = SeoResolver::forStorefrontCategory($category, $categoryFiltered);
layout_public_start([
    'seo' => $seoCategory,
    'jsonLd' => $categoryFiltered ? null : SeoStructuredData::forCategoryPage($category, $seoCategory),
    'activeNav' => 'categories',
    'loadCategoryMobileCss' => true,
]);
require APNI_TOKRI_ROOT . '/includes/partials/demo-banner.php';

$baseCatUrl = url_base() . '/category.php?slug=' . rawurlencode($slug);
?>
<div class="at-pagehead">
    <div class="at-container pb-6">
        <nav class="at-breadcrumb" aria-label="Breadcrumb">
            <a href="<?= Security::escape(url_base()) ?>/index.php">Home</a><span class="sep">/</span>
            <?php foreach (catalogue_category_trail($category) as $anc): ?>
                <a href="<?= Security::escape(catalogue_category_url($anc)) ?>"><?= Security::escape((string) $anc['name']) ?></a><span class="sep">/</span>
            <?php endforeach; ?>
            <span aria-current="page"><?= Security::escape($catName) ?></span>
        </nav>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="at-h1"><?= Security::escape($catName) ?></h1>
                <?php
                $productCount = count($products);
                $countLine = $productCount . ' product' . ($productCount === 1 ? '' : 's');
                if (storefront_uses_demo_catalogue()) {
                    $countLine .= ' · sample catalogue';
                }
                ?>
                <p class="mt-1 at-muted text-sm"><?= Security::escape($countLine) ?></p>
            </div>
            <nav class="at-catnav -mx-1 max-w-full md:hidden" aria-label="Switch category">
                <?php foreach (catalogue_nav_tree() as $c): ?>
                    <a href="<?= Security::escape(catalogue_category_url($c)) ?>" class="at-focusable <?= $c['slug'] === $slug ? 'is-active' : '' ?>"><?= Security::escape(Lang::locale() === 'hi' ? ($c['name_hi'] ?? $c['name']) : $c['name']) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
    </div>
</div>

<div class="at-container py-6 md:py-8">
    <?php
    if (CommerceGateway::useDatabase() && Install::isSchemaReady()) {
        $catRow = CategoryRepository::findBySlug($slug, true);
        if ($catRow !== null) {
            $applicableOffers = OfferStorefront::forCategory((int) $catRow['id'], CustomerAuth::id());
            if ($applicableOffers !== []) {
                $offerDealContext = 'category';
                require APNI_TOKRI_ROOT . '/includes/partials/store-offer-badges.php';
            }
        }
    }
    ?>
    <?php $childCats = catalogue_child_categories($category); ?>
    <?php if ($childCats !== []): ?>
        <nav class="at-catnav mb-4" aria-label="Sub-categories">
            <?php foreach ($childCats as $cc): ?>
                <a href="<?= Security::escape(catalogue_category_url($cc)) ?>" class="at-focusable"><?= Security::escape((string) $cc['name']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <form method="get" class="at-toolbar" aria-label="Filter products">
        <input type="hidden" name="slug" value="<?= Security::escape($slug) ?>">
        <div class="at-filter-bar">
            <label class="text-sm font-semibold" for="availability">Show</label>
            <select id="availability" name="availability" class="at-select at-focusable" onchange="this.form.submit()">
                <option value="all" <?= $availability === 'all' ? 'selected' : '' ?>>All items</option>
                <option value="in_stock" <?= $availability === 'in_stock' ? 'selected' : '' ?>>In stock only</option>
            </select>
            <?php if ($availability !== 'all' || $sort !== 'relevance'): ?>
                <a href="<?= Security::escape($baseCatUrl) ?>" class="at-link-arrow !text-sm at-focusable">Clear</a>
            <?php endif; ?>
        </div>
        <div class="at-filter-bar">
            <label class="text-sm font-semibold" for="sort">Sort by</label>
            <select id="sort" name="sort" class="at-select at-focusable" onchange="this.form.submit()">
                <option value="relevance" <?= $sort === 'relevance' ? 'selected' : '' ?>>Relevance</option>
                <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: low to high</option>
                <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: high to low</option>
                <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
            </select>
        </div>
    </form>

    <div class="mt-6">
        <?php if ($demoLoading): ?>
            <?php $skeletonCount = 8;
            require APNI_TOKRI_ROOT . '/includes/partials/loading-skeleton.php'; ?>
        <?php elseif ($products === [] && $childCats !== [] && $availability === 'all'): ?>
            <p class="at-muted text-sm">Browse the sub-categories above.</p>
        <?php elseif ($products === [] && storefront_catalogue_coming_soon() && $availability === 'all'): ?>
            <?php
            $emptyTitle = 'Products coming soon';
            $emptyMessage = 'We\'re preparing our catalogue. Please check back shortly.';
            require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
            ?>
        <?php elseif ($products === []): ?>
            <?php
            $emptyTitle = 'No products match these filters';
            $emptyMessage = 'Try another category or clear the availability filter.';
            $emptyActionHref = $baseCatUrl;
            $emptyActionLabel = 'Clear filters';
            require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
            ?>
        <?php else: ?>
            <?php $variantPickerCards = true; require APNI_TOKRI_ROOT . '/includes/partials/product-grid.php'; ?>
        <?php endif; ?>
    </div>
    <?php if (StorefrontDesignPreview::allowsLoadingPreview()): ?>
        <p class="mt-8 text-xs at-muted"><a href="<?= Security::escape($baseCatUrl) ?>&amp;demo=loading" class="underline">Preview loading state</a> (design review only)</p>
    <?php endif; ?>
</div>
<?php layout_public_end(); ?>
