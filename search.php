<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

$q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$loadCatalogueJs = true;

layout_public_start([
    'seo' => SeoResolver::forSearchPage($q, $q !== ''),
    'activeNav' => '',
]);
require APNI_TOKRI_ROOT . '/includes/partials/demo-banner.php';
?>
<div class="at-pagehead">
    <div class="at-container pb-6">
        <nav class="at-breadcrumb" aria-label="Breadcrumb">
            <a href="<?= Security::escape(url_base()) ?>/index.php">Home</a><span class="sep">/</span><span aria-current="page">Search</span>
        </nav>
        <h1 class="at-h1"><?= $q !== '' ? 'Results for “' . Security::escape($q) . '”' : 'Search the store' ?></h1>
        <form method="get" action="<?= Security::escape(url_base()) ?>/search.php" class="at-search mt-4 max-w-xl" role="search">
            <label class="sr-only" for="search-q">Search products</label>
            <div class="at-search__field">
                <?= at_icon('search') ?>
                <input id="search-q" type="search" name="q" value="<?= Security::escape($q) ?>" placeholder="<?= Security::escape(__('search_placeholder')) ?>" autocomplete="off">
                <button type="submit">Search</button>
            </div>
        </form>
    </div>
</div>
<div class="at-container py-6 md:py-8">
    <?php if ($q === ''): ?>
        <div class="py-6">
            <?php
            $emptyTitle = 'What are you looking for?';
            $emptyMessage = 'Search by product name or SKU, or jump into a category below.';
            $emptyIcon = 'search';
            unset($emptyActionHref, $emptyActionLabel);
            require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
            ?>
            <div class="flex flex-wrap justify-center gap-2 mt-2">
                <?php foreach (demo_catalogue()->categories() as $cat): ?>
                    <a href="<?= Security::escape(catalogue_category_url($cat)) ?>" class="at-chip at-focusable"><?= Security::escape($cat['name']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php else:
        $results = demo_catalogue()->search($q);
        ?>
        <p class="text-sm at-muted"><?= count($results) ?> result<?= count($results) === 1 ? '' : 's' ?></p>
        <?php if ($results === []): ?>
            <div class="py-6">
                <?php
                $emptyTitle = 'No products found';
                $emptyMessage = 'Check the spelling or try a more general word like “tomato” or “pickle”.';
                $emptyIcon = 'search';
                $emptyActionHref = url_base() . '/index.php';
                $emptyActionLabel = 'Back to home';
                require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
                ?>
                <div class="text-center mt-6">
                    <p class="text-sm font-bold">Browse a category instead</p>
                    <div class="flex flex-wrap justify-center gap-2 mt-3">
                        <?php foreach (demo_catalogue()->categories() as $cat): ?>
                            <a href="<?= Security::escape(catalogue_category_url($cat)) ?>" class="at-chip at-focusable"><?= Security::escape($cat['name']) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="mt-4">
                <?php $products = $results;
                require APNI_TOKRI_ROOT . '/includes/partials/product-grid.php'; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php layout_public_end(); ?>
