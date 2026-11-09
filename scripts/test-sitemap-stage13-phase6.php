<?php

declare(strict_types=1);

/**
 * XML sitemap regression (Stage 13 phase 6).
 * CLI: php scripts/run-in-disposable-db.php scripts/test-sitemap-stage13-phase6.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/lib/test_script_safety.php';
require_once dirname(__DIR__) . '/scripts/includes/stage13_phase6_fixture.php';

$fail = 0;
function okSitemap(bool $cond, string $msg): void
{
    global $fail;
    echo ($cond ? 'OK' : 'FAIL') . ': ' . $msg . "\n";
    if (!$cond) {
        $fail++;
    }
}

TestScriptSafety::assertDisposableDatabaseOrAbort();
if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready — run disposable DB with production SQL import.\n");
    exit(2);
}

Install::ensureConnection();
$pdo = Database::pdo();
$tok = bin2hex(random_bytes(4));
$ptype = ProductTypeRepository::defaultCode();

stage13_p6_cleanup($pdo);
SeoSitemap::clearResponseCache();
SeoSitemap::resetCollectMemo();

$homeCanonical = SeoResolver::forHomepage()->canonicalUrl;
okSitemap($homeCanonical !== null && $homeCanonical !== '', 'homepage has canonical URL');
$faqCanonical = SeoResolver::forFaqPage(false)->canonicalUrl;
okSitemap($faqCanonical !== null && $faqCanonical !== '', 'FAQ page has canonical URL');

$pdo->exec(
    "INSERT INTO categories (name, slug, status, sort_order, updated_at)
     VALUES ('SM13 Cat', 'at-sm13-cat-{$tok}', 'published', 1, '2026-01-10 08:00:00')"
);
$catId = (int) $pdo->lastInsertId();

$pdo->prepare(
    'INSERT INTO products (name, slug, sku, category_id, product_type, status, short_description, description, meta_title, meta_description, robots_index, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    'SM13 Published ' . $tok, 'at-sm13-pub-' . $tok, 'SM-P-' . $tok, $catId, $ptype, 'published',
    'Fresh & "crisp"', 'Body', 'Meta', 'Desc', 1, '2026-02-15 14:30:00',
]);
$pubProductId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO product_variants (product_id, name, sku, price, stock_qty, low_stock_threshold, is_active, sort_order)
     VALUES (?, ?, ?, 99, 10, 2, 1, 1)'
)->execute([$pubProductId, '1 kg', 'SM-V-' . $tok]);
$pdo->prepare(
    'INSERT INTO product_images (product_id, file_path, alt_text, sort_order, is_primary)
     VALUES (?, ?, ?, 0, 1)'
)->execute([$pubProductId, 'uploads/catalogue/at-sm13-' . $tok . '.jpg', 'Primary']);

$pdo->prepare(
    'INSERT INTO products (name, slug, sku, category_id, product_type, status, short_description, description, meta_title, meta_description, robots_index, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
)->execute([
    'SM13 Draft', 'at-sm13-draft-' . $tok, 'SM-D-' . $tok, $catId, $ptype, 'draft',
    'x', 'x', '', '', 1,
]);

$pdo->prepare(
    'INSERT INTO products (name, slug, sku, category_id, product_type, status, short_description, description, meta_title, meta_description, robots_index, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
)->execute([
    'SM13 Archived', 'at-sm13-arch-' . $tok, 'SM-A-' . $tok, $catId, $ptype, 'archived',
    'x', 'x', '', '', 1,
]);
$archProductId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO product_variants (product_id, name, sku, price, stock_qty, low_stock_threshold, is_active, sort_order)
     VALUES (?, ?, ?, 50, 5, 2, 1, 1)'
)->execute([$archProductId, '500 g', 'SM-VA-' . $tok]);

$pdo->prepare(
    "INSERT INTO cms_pages (title, slug, body_html, status, show_in_footer, visibility, meta_title, meta_description, robots_index, robots_follow, published_at, updated_at)
     VALUES (?, ?, '<p>Public</p>', 'published', 0, 'public', '', '', 1, 1, NOW(), '2026-03-01 09:00:00')"
)->execute(['SM13 CMS Pub ' . $tok, 'at-sm13-cms-pub-' . $tok]);

$pdo->prepare(
    "INSERT INTO cms_pages (title, slug, body_html, status, show_in_footer, visibility, meta_title, meta_description, robots_index, robots_follow, published_at, updated_at)
     VALUES (?, ?, '<p>Draft</p>', 'draft', 0, 'public', '', '', 1, 1, NULL, NOW())"
)->execute(['SM13 CMS Draft ' . $tok, 'at-sm13-cms-draft-' . $tok]);

$publishedSlug = 'at-sm13-pub-' . $tok;
$publishedCanonical = SeoUrl::absolute('/product.php?slug=' . rawurlencode($publishedSlug));
$categoryCanonical = SeoUrl::absolute('/category.php?slug=' . rawurlencode('at-sm13-cat-' . $tok));
$cmsCanonical = SeoUrl::absolute('/page.php?slug=' . rawurlencode('at-sm13-cms-pub-' . $tok));

SeoSitemap::resetCollectMemo();
$entries = SeoSitemap::collectIndexableUrls();
$locs = array_column($entries, 'loc');
$uniqueLocs = array_unique($locs);

$xml = SeoSitemap::renderForRequest(null, true);
$parsed = @simplexml_load_string($xml);
okSitemap($parsed instanceof SimpleXMLElement, 'generated XML parses with SimpleXML');
okSitemap(isset($parsed->url) || (string) $parsed->getName() === 'urlset', 'root urlset present');

okSitemap($homeCanonical !== null && in_array($homeCanonical, $locs, true), 'homepage URL in sitemap');
$legacyHome = SeoUrl::absolute('/index.php');
if ($homeCanonical !== $legacyHome) {
    okSitemap(!in_array($legacyHome, $locs, true), 'legacy /index.php homepage not duplicated');
}
$homeLike = 0;
foreach ($locs as $loc) {
    $path = (string) parse_url($loc, PHP_URL_PATH);
    if ($path === '/' || str_ends_with(strtolower($path), '/index.php')) {
        $homeLike++;
    }
}
okSitemap($homeLike === 1, 'homepage appears exactly once in sitemap');
okSitemap(in_array($faqCanonical, $locs, true), 'indexable FAQ page URL in sitemap');
okSitemap(!SeoSitemap::isBlockedAppPath('/faqs.php'), 'FAQ route not blocked from sitemap');
okSitemap(in_array($publishedCanonical, $locs, true), 'published product with active variant in sitemap');
okSitemap(!stage13_p6_has_loc('at-sm13-draft-' . $tok), 'draft product excluded');
okSitemap(!stage13_p6_has_loc('at-sm13-arch-' . $tok), 'archived product excluded');
okSitemap(in_array($categoryCanonical, $locs, true), 'published category in sitemap');
okSitemap(!stage13_p6_has_loc('at-sm13-cms-draft-' . $tok), 'draft CMS page excluded');

$adminBlocked = [
    SeoUrl::absolute('/admin/'),
    SeoUrl::absolute('/login.php'),
    SeoUrl::absolute('/cart.php'),
    SeoUrl::absolute('/checkout.php'),
    SeoUrl::absolute('/search.php?q=test'),
];
foreach ($adminBlocked as $blockedUrl) {
    okSitemap(!in_array($blockedUrl, $locs, true), 'blocked URL not listed: ' . $blockedUrl);
}
okSitemap(SeoSitemap::isBlockedAppPath('/admin/products/index.php'), 'isBlockedAppPath covers admin');
okSitemap(SeoSitemap::isBlockedAppPath('/payment.php'), 'isBlockedAppPath covers payment');

$base = rtrim(App::baseUrl(), '/');
$baseScheme = strtolower((string) parse_url($base !== '' ? $base : 'https://veggietokri.com', PHP_URL_SCHEME));
foreach ($locs as $loc) {
    if (!is_string($loc) || $loc === '') {
        continue;
    }
    $scheme = strtolower((string) parse_url($loc, PHP_URL_SCHEME));
    okSitemap($scheme === $baseScheme, 'canonical URL scheme matches base_url: ' . $loc);
    okSitemap($base === '' || str_starts_with($loc, $base), 'absolute URL uses configured base: ' . $loc);
    break;
}
okSitemap($base === '' || str_starts_with($publishedCanonical, $base), 'product canonical uses deployment base_url');
okSitemap(str_contains($publishedCanonical, 'product.php?slug='), 'product uses application slug canonical route');

$entryByLoc = [];
foreach ($entries as $entry) {
    $entryByLoc[$entry['loc']] = $entry;
}
$prodLastmod = $entryByLoc[$publishedCanonical]['lastmod'] ?? '';
okSitemap($prodLastmod === '2026-02-15T14:30:00Z', 'lastmod from product updated_at (ISO 8601)');

$xmlAmp = SeoSitemap::renderUrlset([[
    'loc' => SeoUrl::absolute('/page.php?slug=' . rawurlencode('test&amp;co')),
    'lastmod' => '2026-01-01T00:00:00Z',
]]);
okSitemap(str_contains($xmlAmp, '&amp;'), 'XML escapes ampersand in loc');

okSitemap(count($locs) === count($uniqueLocs), 'no duplicate loc URLs');

okSitemap(str_contains($xml, 'xmlns:image='), 'image sitemap namespace when product images exist');
okSitemap(str_contains($xml, '<image:loc>') && str_contains($xml, 'at-sm13-' . $tok . '.jpg'), 'product image loc in sitemap');

SeoSitemap::clearResponseCache();
$first = SeoSitemap::renderForRequest(null, false);
$cachePath = APNI_TOKRI_ROOT . '/storage/cache/seo-sitemap';
$cacheFiles = is_dir($cachePath) ? (glob($cachePath . '/*.xml') ?: []) : [];
okSitemap($cacheFiles !== [], 'sitemap XML written to response cache');
$second = SeoSitemap::renderForRequest(null, false);
okSitemap($first === $second, 'cached response matches rendered XML');
ProductRepository::bulkUpdateStatusForAdmin([$pubProductId], 'draft');
$cacheAfterMutation = is_dir($cachePath) ? (glob($cachePath . '/*.xml') ?: []) : [];
okSitemap($cacheAfterMutation === [], 'sitemap cache cleared after product status change');
SeoSitemap::resetCollectMemo();
$locsAfterDraft = array_column(SeoSitemap::collectIndexableUrls(), 'loc');
okSitemap(!in_array($publishedCanonical, $locsAfterDraft, true), 'unpublished product removed after cache refresh');
foreach ($cacheFiles as $cf) {
    @touch($cf, time() - SeoSitemap::CACHE_TTL_SECONDS - 10);
}
SeoSitemap::clearResponseCache();
SeoSitemap::resetCollectMemo();
$afterExpire = SeoSitemap::renderForRequest(null, false);
okSitemap($afterExpire !== '' && str_contains($afterExpire, '<urlset'), 'sitemap regenerates after cache expiry');

$bulk = [];
for ($i = 1; $i <= SeoSitemap::MAX_URLS_PER_FILE + 1; $i++) {
    $bulk[] = ['loc' => SeoUrl::absolute('/page.php?slug=sm13-bulk-' . $i)];
}
$indexXml = SeoSitemap::renderForEntries($bulk, null);
okSitemap(str_contains($indexXml, '<sitemapindex') && str_contains($indexXml, 'sitemap-1.xml') && str_contains($indexXml, 'sitemap-2.xml'), 'sitemap index when URL limit exceeded');
$part1 = SeoSitemap::renderForEntries($bulk, 1);
okSitemap(substr_count($part1, '<url>') === SeoSitemap::MAX_URLS_PER_FILE, 'sitemap part 1 respects MAX_URLS_PER_FILE');

$robots = SeoRobots::render();
okSitemap(str_contains($robots, 'Sitemap: ' . SeoRobots::sitemapAbsoluteUrl()), 'robots.txt includes sitemap directive');

$origConfig = App::config();
App::setConfig(array_replace_recursive($origConfig, [
    'app' => [
        'base_url' => 'https://veggietokri.com',
        'url_path' => '',
        'env' => 'production',
    ],
]));
CatalogueGateway::reset();
SeoSitemap::invalidateResponseCache();
$prodXml = SeoSitemap::renderForRequest(null, true);
$prodHome = SeoResolver::forHomepage()->canonicalUrl;
okSitemap($prodHome === 'https://veggietokri.com/', 'production homepage canonical is site root');
okSitemap(!str_contains(strtolower($prodXml), 'localhost'), 'production-config sitemap has no localhost URLs');
okSitemap(str_contains($prodXml, '<loc>https://veggietokri.com/</loc>'), 'production-config sitemap lists root homepage once');
App::setConfig($origConfig);
CatalogueGateway::reset();

stage13_p6_cleanup($pdo);
SeoSitemap::clearResponseCache();

echo $fail === 0 ? "All sitemap phase-6 checks passed.\n" : "{$fail} check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
