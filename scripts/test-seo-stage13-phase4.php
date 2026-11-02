<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/stage13_phase4_fixture.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function assertNoDuplicateTags(SeoMetadata $seo): bool
{
    $types = [];
    foreach ($seo->headTagLines() as $line) {
        if (preg_match('/<meta name="([^"]+)"/', $line, $m)) {
            if (isset($types['name:' . $m[1]])) {
                return false;
            }
            $types['name:' . $m[1]] = true;
        }
        if (preg_match('/<meta property="([^"]+)"/', $line, $m)) {
            if (isset($types['prop:' . $m[1]])) {
                return false;
            }
            $types['prop:' . $m[1]] = true;
        }
        if (str_contains($line, 'rel="canonical"') && isset($types['canonical'])) {
            return false;
        }
        if (str_contains($line, 'rel="canonical"')) {
            $types['canonical'] = true;
        }
    }

    return true;
}

stage13_p4_cleanup($pdo);
$token = strtolower(Security::randomToken(4));

AppSettings::set('seo.home.meta_title', 'AT-SEO13 Home Title ' . $token);
AppSettings::set('seo.home.meta_description', 'AT-SEO13 home description for tests.');
$home = SeoResolver::forHomepage();
ok(str_contains($home->documentTitle, 'AT-SEO13 Home Title'), 'Homepage metadata title');
AppSettings::set('seo.home.meta_title', '');
AppSettings::set('seo.home.meta_description', '');

$pdo->prepare(
    "INSERT INTO categories (parent_id, name, slug, description, sort_order, show_on_homepage, status, meta_title, meta_description)
     VALUES (NULL, ?, ?, ?, 99, 0, 'published', ?, ?)"
)->execute([
    'AT-SEO13 Cat ' . $token,
    'at-seo13-cat-' . $token,
    'Category description for AT-SEO13.',
    'AT-SEO13 Custom Cat Title',
    'AT-SEO13 custom cat meta description.',
]);
$catId = (int) $pdo->lastInsertId();

$catSeo = SeoResolver::forCategoryRow(
    (array) CategoryRepository::find($catId),
    false
);
ok(str_contains($catSeo->documentTitle, 'AT-SEO13 Custom Cat Title'), 'Category SEO title');
ok($catSeo->canonicalUrl !== null && str_contains($catSeo->canonicalUrl, 'at-seo13-cat-' . $token), 'Category canonical URL');

$catFallback = SeoResolver::forCategoryRow([
    'status' => 'published',
    'name' => 'AT-SEO13 Fallback Cat',
    'slug' => 'at-seo13-fallback-' . $token,
    'meta_title' => '',
    'meta_description' => '',
    'description' => 'Fallback category description text.',
], false);
ok(str_contains($catFallback->documentTitle, 'AT-SEO13 Fallback Cat'), 'Category SEO title fallback');

$catFiltered = SeoResolver::forCategoryRow((array) CategoryRepository::find($catId), true);
ok(str_contains((string) $catFiltered->robots, 'noindex'), 'Search/filter page robots (category filtered)');

$pdo->prepare(
    "INSERT INTO products (category_id, name, slug, sku, product_type, short_description, description, status, meta_title, meta_description, robots_index)
     VALUES (?, ?, ?, ?, 'fresh', ?, ?, 'published', ?, ?, ?)"
)->execute([
    $catId,
    'AT-SEO13 Product ' . $token,
    'at-seo13-prod-' . $token,
    'AT-SEO13-SKU-' . $token,
    'Short AT-SEO13 product blurb.',
    'Long description ignored when meta set.',
    'AT-SEO13 Custom Product Title',
    'AT-SEO13 custom product meta.',
    1,
]);
$productId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO product_variants (product_id, name, sku, price, stock_qty, low_stock_threshold, is_active, sort_order)
     VALUES (?, ?, ?, 10, 5, 1, 1, 1)'
)->execute([$productId, 'Default', 'AT-SEO13-VSKU-' . $token]);

$prodRow = ProductRepository::findBySlug('at-seo13-prod-' . $token, true);
ok($prodRow !== null, 'Product fixture');
$prodSeo = SeoResolver::forProductRow($prodRow);
ok(str_contains($prodSeo->documentTitle, 'AT-SEO13 Custom Product Title'), 'Product SEO title');
ok(str_contains((string) $prodSeo->metaDescription, 'AT-SEO13 custom product meta'), 'Product description from meta');
ok($prodSeo->canonicalUrl !== null && str_contains($prodSeo->canonicalUrl, 'at-seo13-prod-' . $token), 'Product canonical URL');

$pdo->prepare('UPDATE products SET meta_title = NULL, meta_description = NULL WHERE id = ?')->execute([$productId]);
$prodRow2 = ProductRepository::findBySlug('at-seo13-prod-' . $token, true);
$prodFallback = SeoResolver::forProductRow($prodRow2);
ok(str_contains((string) $prodFallback->metaDescription, 'Short AT-SEO13'), 'Product description fallback');

$pdo->prepare('UPDATE products SET robots_index = 0 WHERE id = ?')->execute([$productId]);
$prodNoIndex = SeoResolver::forProductRow(ProductRepository::findBySlug('at-seo13-prod-' . $token, true));
ok(str_contains((string) $prodNoIndex->robots, 'noindex'), 'Product robots noindex when robots_index=0');

$cmsCreate = CmsPageService::create([
    'title' => 'AT-SEO13 CMS ' . $token,
    'slug' => 'at-seo13-' . $token,
    'body_html' => '<p>SEO test page</p>',
], null);
$cmsId = (int) ($cmsCreate['id'] ?? 0);
CmsPageService::publish($cmsId, [
    'title' => 'AT-SEO13 CMS ' . $token,
    'slug' => 'at-seo13-' . $token,
    'body_html' => '<p>SEO test page</p>',
    'meta_title' => 'AT-SEO13 CMS Meta Title',
    'meta_description' => 'AT-SEO13 CMS meta description.',
    'og_title' => 'AT-SEO13 OG Title',
    'og_description' => 'AT-SEO13 OG description.',
    'canonical_url' => 'javascript:alert(1)',
    'robots_index' => 1,
    'robots_follow' => 1,
    'preserve_slug' => 1,
], null);
$cmsPub = CmsPageService::publishedViewableBySlug('at-seo13-' . $token);
ok($cmsPub !== null, 'CMS page published for SEO test');
$cmsSeo = SeoResolver::forCmsPage($cmsPub);
ok(str_contains($cmsSeo->documentTitle, 'AT-SEO13 CMS Meta Title'), 'CMS custom SEO metadata');
ok($cmsSeo->canonicalUrl !== null && !str_contains(strtolower((string) $cmsSeo->canonicalUrl), 'javascript'), 'Invalid canonical URL rejection/fallback');
ok(str_contains((string) $cmsSeo->ogTitle, 'AT-SEO13 OG Title'), 'CMS OG title');

$cmsFallback = SeoResolver::forCmsPage([
    'title' => 'Fallback Title',
    'slug' => 'at-seo13-fb-' . $token,
    'status' => PublishStatus::PUBLISHED,
    'visibility' => CmsPageService::VISIBILITY_PUBLIC,
    'meta_title' => '',
    'meta_description' => '',
    'robots_index' => 1,
    'robots_follow' => 1,
]);
ok(str_contains($cmsFallback->documentTitle, 'Fallback Title'), 'CMS fallback metadata title');

$cmsNoIndex = SeoResolver::forCmsPage([
    'title' => 'Noindex',
    'slug' => 'x',
    'status' => PublishStatus::PUBLISHED,
    'visibility' => CmsPageService::VISIBILITY_PUBLIC,
    'robots_index' => 0,
    'robots_follow' => 0,
]);
ok(str_contains((string) $cmsNoIndex->robots, 'noindex') && str_contains((string) $cmsNoIndex->robots, 'nofollow'), 'CMS noindex/nofollow');

$privateSeo = SeoResolver::forCmsPage([
    'title' => 'Private',
    'slug' => 'priv',
    'status' => PublishStatus::PUBLISHED,
    'visibility' => CmsPageService::VISIBILITY_PRIVATE,
    'robots_index' => 1,
    'robots_follow' => 1,
]);
ok(str_contains((string) $privateSeo->robots, 'noindex'), 'Private CMS page robots behaviour');

$previewSeo = SeoResolver::forCmsPreview();
ok(str_contains((string) $previewSeo->robots, 'noindex'), 'Preview noindex/nofollow');

$draftSeo = SeoResolver::notFound();
ok(str_contains((string) $draftSeo->robots, 'noindex'), 'Draft/404 not publicly indexable');

$searchSeo = SeoResolver::forSearchPage('tomato', true);
ok(str_contains((string) $searchSeo->robots, 'noindex'), 'Search/filter page robots behaviour');

$escaped = SeoMetadata::fromLegacy('Title', 'Say "hello" <script> & amp');
$html = $escaped->renderHeadTags();
ok(str_contains($html, '&quot;') && !str_contains($html, '<script>'), 'HTML attribute escaping');

$noOg = new SeoMetadata('T', 'D', null, null, null, null, null);
ok(!str_contains($noOg->renderHeadTags(), 'og:image'), 'Missing OG image handling');

ok(assertNoDuplicateTags($cmsSeo), 'No duplicate metadata tags');

stage13_p4_cleanup($pdo);

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Stage 13 Phase 4 SEO metadata.\n";
exit(0);
