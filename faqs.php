<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Security::sendSecurityHeaders(Security::isHttps());

$useDb = Install::isSchemaReady();
$filterSlug = isset($_GET['cat']) && is_string($_GET['cat']) ? trim($_GET['cat']) : '';
if ($filterSlug !== '' && !Validation::slug($filterSlug)) {
    $filterSlug = '';
}
$searchQ = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$pageNum = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 20;

$categories = [];
$faqRows = [];
$totalFaqs = 0;
$totalPages = 1;

$productionSchemaMissing = AppEnvironment::isProduction() && !$useDb;

if ($useDb) {
    $categories = FaqRepository::activeCategories();
    $result = FaqRepository::listActivePublic(
        $filterSlug !== '' ? $filterSlug : null,
        $searchQ !== '' ? $searchQ : null,
        $pageNum,
        $perPage
    );
    $faqRows = $result['rows'];
    $totalFaqs = (int) ($result['total'] ?? 0);
    $totalPages = max(1, (int) ceil($totalFaqs / $perPage));
} elseif (!$productionSchemaMissing) {
    require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';
    $demoFaqs = demo_catalogue()->faqs();
    if ($filterSlug !== '') {
        $demoFaqs = array_values(array_filter($demoFaqs, static function (array $f): bool {
        return ($f['cat'] ?? '') === $filterSlug;
    }));
    }
    if ($searchQ !== '') {
        $qLower = mb_strtolower($searchQ);
        $demoFaqs = array_values(array_filter($demoFaqs, static function (array $f) use ($qLower): bool {
            $hay = mb_strtolower(($f['q'] ?? '') . ' ' . ($f['a'] ?? ''));

            return str_contains($hay, $qLower);
        }));
    }
    foreach ($demoFaqs as $f) {
        $faqRows[] = [
            'question' => (string) ($f['q'] ?? ''),
            'answer' => (string) ($f['a'] ?? ''),
            'category_slug' => (string) ($f['cat'] ?? 'general'),
            'category_name' => ucfirst((string) ($f['cat'] ?? 'general')),
        ];
    }
    $totalFaqs = count($faqRows);
    $categories = [
        ['slug' => 'general', 'name' => 'General'],
        ['slug' => 'delivery', 'name' => 'Delivery'],
        ['slug' => 'products', 'name' => 'Products'],
        ['slug' => 'payments', 'name' => 'Payments'],
        ['slug' => 'reviews', 'name' => 'Reviews'],
    ];
}

/** @var array<string, list<array<string, mixed>>> $groupedFaqs */
$groupedFaqs = [];
foreach ($faqRows as $faq) {
    $key = (string) ($faq['category_name'] ?? 'FAQs');
    $groupedFaqs[$key][] = $faq;
}

$faqFiltered = $searchQ !== '' || $filterSlug !== '' || $pageNum > 1;
$seoFaq = SeoResolver::forFaqPage($faqFiltered);
layout_public_start([
    'seo' => $seoFaq,
    'jsonLd' => SeoStructuredData::forFaqPage($faqRows, $seoFaq, $faqFiltered),
    'activeNav' => '',
]);
if (!$useDb && !$productionSchemaMissing) {
    require APNI_TOKRI_ROOT . '/includes/partials/demo-banner.php';
}
?>
<?php if ($productionSchemaMissing): ?>
<div class="at-container py-8">
    <div class="at-alert at-alert--error" role="alert">FAQs are unavailable until the database is installed and configured.</div>
</div>
<?php layout_public_end(); exit; endif; ?>
<div class="at-pagehead">
    <div class="at-container pb-6">
        <nav class="at-breadcrumb" aria-label="Breadcrumb"><a href="<?= Security::escape(url_base()) ?>/index.php">Home</a><span class="sep">/</span><span aria-current="page"><?= Security::escape(__('nav_faqs')) ?></span></nav>
        <h1 class="at-h1">Frequently asked questions</h1>
        <p class="mt-1 at-muted">Quick answers about delivery, payments, products and reviews.</p>
        <form method="get" class="at-search mt-4 max-w-xl" action="<?= Security::escape(url_base()) ?>/faqs.php">
            <?php if ($filterSlug !== ''): ?>
                <input type="hidden" name="cat" value="<?= Security::escape($filterSlug) ?>">
            <?php endif; ?>
            <div class="at-search__field">
                <?= at_icon('search') ?>
                <input type="search" name="q" value="<?= Security::escape($searchQ) ?>" placeholder="Search FAQs…" aria-label="Search FAQ questions and answers" autocomplete="off">
            </div>
        </form>
    </div>
</div>
<div class="at-container py-8 max-w-3xl">
    <div class="flex flex-wrap gap-2">
        <a href="<?= Security::escape(url_base()) ?>/faqs.php<?= $searchQ !== '' ? '?q=' . rawurlencode($searchQ) : '' ?>" class="at-chip at-focusable <?= $filterSlug === '' ? 'is-active' : '' ?>">All</a>
        <?php foreach ($categories as $cat): ?>
            <?php
            $slug = (string) ($cat['slug'] ?? '');
            $qs = $searchQ !== '' ? '?q=' . rawurlencode($searchQ) . '&cat=' . rawurlencode($slug) : '?cat=' . rawurlencode($slug);
            ?>
            <a href="<?= Security::escape(url_base()) ?>/faqs.php<?= Security::escape($qs) ?>" class="at-chip at-focusable <?= $filterSlug === $slug ? 'is-active' : '' ?>"><?= Security::escape((string) ($cat['name'] ?? $slug)) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="mt-6" id="faq-list">
        <?php if ($faqRows === []): ?>
            <?php
            $emptyTitle = $searchQ !== '' ? 'No matching FAQs' : 'No FAQs in this category';
            $emptyMessage = $searchQ !== ''
                ? 'Try different keywords or browse all categories.'
                : 'Select another category or view all.';
            $emptyIcon = 'help';
            require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
            ?>
        <?php else: ?>
            <?php foreach ($groupedFaqs as $catLabel => $items): ?>
                <?php if (count($groupedFaqs) > 1 || $filterSlug === ''): ?>
                    <h2 class="at-h3 mt-6 mb-2 first:mt-0"><?= Security::escape($catLabel) ?></h2>
                <?php endif; ?>
                <?php foreach ($items as $faq): ?>
                    <details class="at-faq faq-item mb-2">
                        <summary><?= Security::escape((string) ($faq['question'] ?? '')) ?></summary>
                        <div class="at-faq__a"><?= Security::escape((string) ($faq['answer'] ?? '')) ?></div>
                    </details>
                <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($useDb && $totalPages > 1):
        $baseQs = http_build_query(array_filter([
            'cat' => $filterSlug !== '' ? $filterSlug : null,
            'q' => $searchQ !== '' ? $searchQ : null,
        ]));
        ?>
    <p class="mt-6 text-sm at-muted flex flex-wrap gap-2 items-center">
        <span>Page <?= (int) $pageNum ?> of <?= (int) $totalPages ?></span>
        <?php if ($pageNum > 1): ?>
            <a class="underline font-semibold" href="?<?= Security::escape($baseQs . ($baseQs !== '' ? '&' : '') . 'page=' . ($pageNum - 1)) ?>">Previous</a>
        <?php endif; ?>
        <?php if ($pageNum < $totalPages): ?>
            <a class="underline font-semibold" href="?<?= Security::escape($baseQs . ($baseQs !== '' ? '&' : '') . 'page=' . ($pageNum + 1)) ?>">Next</a>
        <?php endif; ?>
    </p>
    <?php endif; ?>

    <div class="at-card at-card--tint mt-8"><div class="at-card__body flex flex-wrap items-center justify-between gap-3">
        <div><h2 class="at-h3">Didn’t find your answer?</h2><p class="text-sm at-muted mt-1">Raise a ticket and we’ll help.</p></div>
        <a href="<?= Security::escape(url_base()) ?>/support.php" class="at-btn at-btn--primary at-focusable">Contact support</a>
    </div></div>
</div>
<?php layout_public_end(); ?>
