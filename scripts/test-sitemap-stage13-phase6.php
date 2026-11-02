<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/stage13_phase6_fixture.php';

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

stage13_p6_cleanup($pdo);
$token = strtolower(Security::randomToken(4));

$home = SeoUrl::absolute('/index.php');
ok(stage13_p6_has_loc($home) || stage13_p6_has_loc('/index.php'), 'Homepage included');

$pub = CmsPageService::create([
    'title' => 'AT-SM13 Pub ' . $token,
    'slug' => 'at-sm13-pub-' . $token,
    'body_html' => '<p>Public</p>',
], null);
$pubId = (int) ($pub['id'] ?? 0);
CmsPageService::publish($pubId, [
    'title' => 'AT-SM13 Pub ' . $token,
    'slug' => 'at-sm13-pub-' . $token,
    'body_html' => '<p>Public</p>',
    'preserve_slug' => 1,
    'robots_index' => 1,
    'robots_follow' => 1,
], null);
ok(stage13_p6_has_loc('at-sm13-pub-' . $token), 'Published public CMS page included');

$draft = CmsPageService::create([
    'title' => 'AT-SM13 Draft',
    'slug' => 'at-sm13-draft-' . $token,
    'body_html' => '<p>D</p>',
], null);
ok(!stage13_p6_has_loc('at-sm13-draft-' . $token), 'Draft CMS page excluded');

$priv = CmsPageService::create([
    'title' => 'AT-SM13 Private',
    'slug' => 'at-sm13-priv-' . $token,
    'body_html' => '<p>P</p>',
    'visibility' => CmsPageService::VISIBILITY_PRIVATE,
], null);
$privId = (int) ($priv['id'] ?? 0);
CmsPageService::publish($privId, [
    'title' => 'AT-SM13 Private',
    'slug' => 'at-sm13-priv-' . $token,
    'body_html' => '<p>P</p>',
    'visibility' => CmsPageService::VISIBILITY_PRIVATE,
    'preserve_slug' => 1,
], null);
ok(!stage13_p6_has_loc('at-sm13-priv-' . $token), 'Private CMS page excluded');

$noindexCms = CmsPageService::create([
    'title' => 'AT-SM13 Noindex CMS',
    'slug' => 'at-sm13-noidx-' . $token,
    'body_html' => '<p>N</p>',
    'robots_index' => 0,
], null);
$noindexCmsId = (int) ($noindexCms['id'] ?? 0);
CmsPageService::publish($noindexCmsId, [
    'title' => 'AT-SM13 Noindex CMS',
    'slug' => 'at-sm13-noidx-' . $token,
    'body_html' => '<p>N</p>',
    'robots_index' => 0,
    'preserve_slug' => 1,
], null);
ok(!stage13_p6_has_loc('at-sm13-noidx-' . $token), 'Noindex CMS page excluded');

$cat = CategoryRepository::save(null, [
    'name' => 'AT-SM13 Cat ' . $token,
    'slug' => 'at-sm13-cat-' . $token,
    'status' => 'published',
]);
$catId = (int) ($cat['id'] ?? 0);
ok(stage13_p6_has_loc('at-sm13-cat-' . $token), 'Published active category included');

CategoryRepository::save($catId, [
    'name' => 'AT-SM13 Cat Hidden',
    'slug' => 'at-sm13-cat-hidden-' . $token,
    'status' => 'hidden',
]);
ok(!stage13_p6_has_loc('at-sm13-cat-hidden-' . $token), 'Inactive category excluded');

$prod = ProductRepository::saveGeneral(null, [
    'name' => 'AT-SM13 Prod ' . $token,
    'slug' => 'at-sm13-prod-' . $token,
    'category_id' => $catId,
    'status' => 'published',
    'product_type' => 'fresh',
    'robots_index' => 1,
]);
$prodId = (int) ($prod['id'] ?? 0);
$pdo->prepare(
    'INSERT INTO product_variants (product_id, name, sku, price, stock_qty, low_stock_threshold, is_active, sort_order)
     VALUES (?, ?, ?, 10, 5, 1, 1, 1)'
)->execute([$prodId, 'Default', 'AT-SM13-V-' . $token]);
ok(stage13_p6_has_loc('at-sm13-prod-' . $token), 'Published active product included');

ProductRepository::saveGeneral($prodId, [
    'name' => 'AT-SM13 Prod ' . $token,
    'slug' => 'at-sm13-prod-' . $token,
    'category_id' => $catId,
    'status' => 'draft',
    'product_type' => 'fresh',
]);
ok(!stage13_p6_has_loc('at-sm13-prod-' . $token), 'Unpublished product excluded');

$prod2 = ProductRepository::saveGeneral(null, [
    'name' => 'AT-SM13 Prod NI ' . $token,
    'slug' => 'at-sm13-prod-ni-' . $token,
    'category_id' => $catId,
    'status' => 'published',
    'product_type' => 'fresh',
    'robots_index' => 0,
]);
$prod2Id = (int) ($prod2['id'] ?? 0);
$pdo->prepare(
    'INSERT INTO product_variants (product_id, name, sku, price, stock_qty, low_stock_threshold, is_active, sort_order)
     VALUES (?, ?, ?, 10, 5, 1, 1, 1)'
)->execute([$prod2Id, 'Default', 'AT-SM13-V2-' . $token]);
ok(!stage13_p6_has_loc('at-sm13-prod-ni-' . $token), 'Noindex product excluded');

$prod3 = ProductRepository::saveGeneral(null, [
    'name' => 'AT-SM13 Prod Inactive ' . $token,
    'slug' => 'at-sm13-prod-inact-' . $token,
    'category_id' => $catId,
    'status' => 'published',
    'product_type' => 'fresh',
]);
$prod3Id = (int) ($prod3['id'] ?? 0);
$pdo->prepare(
    'INSERT INTO product_variants (product_id, name, sku, price, stock_qty, low_stock_threshold, is_active, sort_order)
     VALUES (?, ?, ?, 10, 5, 1, 0, 1)'
)->execute([$prod3Id, 'Default', 'AT-SM13-V3-' . $token]);
ok(!stage13_p6_has_loc('at-sm13-prod-inact-' . $token), 'Inactive product (no active variant) excluded');

ok(
    !SeoSitemap::isEligibleAbsoluteUrl(SeoUrl::absolute('/search.php?q=test')),
    'Search URL excluded'
);
ok(
    !SeoSitemap::isEligibleAbsoluteUrl(SeoUrl::absolute('/category.php?slug=x&sort=price')),
    'Filtered/sorted URL excluded'
);
ok(
    !SeoSitemap::isEligibleAbsoluteUrl(SeoUrl::absolute('/cart.php')),
    'Cart URL excluded'
);
ok(
    !SeoSitemap::isEligibleAbsoluteUrl(SeoUrl::absolute('/admin/login.php')),
    'Admin URL excluded'
);
ok(
    !SeoSitemap::isEligibleAbsoluteUrl(SeoUrl::absolute('/my-orders.php')),
    'Account URL excluded'
);

CmsPageService::update($pubId, [
    'title' => 'AT-SM13 Pub ' . $token,
    'slug' => 'at-sm13-pub-new-' . $token,
    'body_html' => '<p>Public</p>',
    'slug_manual' => 1,
    'visibility' => CmsPageService::VISIBILITY_PUBLIC,
    'robots_index' => 1,
    'robots_follow' => 1,
], null, 'Slug for redirect test');
$oldAbs = SeoUrl::absolute('/page.php?slug=' . rawurlencode('at-sm13-pub-' . $token));
$newAbs = SeoUrl::absolute('/page.php?slug=' . rawurlencode('at-sm13-pub-new-' . $token));
ok(!in_array($oldAbs, stage13_p6_locs(), true), 'Redirect source URL excluded');
ok(in_array($newAbs, stage13_p6_locs(), true), 'Redirect destination canonical included');

$canon = SeoUrl::absolute('/page.php?slug=' . rawurlencode('at-sm13-canonical-' . $token));
$dupA = CmsPageService::create([
    'title' => 'AT-SM13 Canon A',
    'slug' => 'at-sm13-can-a-' . $token,
    'body_html' => '<p>A</p>',
    'canonical_url' => $canon,
], null);
$dupAId = (int) ($dupA['id'] ?? 0);
CmsPageService::publish($dupAId, [
    'title' => 'AT-SM13 Canon A',
    'slug' => 'at-sm13-can-a-' . $token,
    'body_html' => '<p>A</p>',
    'canonical_url' => $canon,
    'preserve_slug' => 1,
    'robots_index' => 1,
    'robots_follow' => 1,
], null);
$dupB = CmsPageService::create([
    'title' => 'AT-SM13 Canon B',
    'slug' => 'at-sm13-can-b-' . $token,
    'body_html' => '<p>B</p>',
    'canonical_url' => $canon,
], null);
$dupBId = (int) ($dupB['id'] ?? 0);
CmsPageService::publish($dupBId, [
    'title' => 'AT-SM13 Canon B',
    'slug' => 'at-sm13-can-b-' . $token,
    'body_html' => '<p>B</p>',
    'canonical_url' => $canon,
    'preserve_slug' => 1,
    'robots_index' => 1,
    'robots_follow' => 1,
], null);
$canonCount = 0;
foreach (stage13_p6_locs() as $loc) {
    if ($loc === $canon) {
        $canonCount++;
    }
}
ok($canonCount === 1, 'Duplicate canonical URLs removed');

$xml = SeoSitemap::renderUrlset([
    ['loc' => 'http://localhost/Apni_Tokri/product.php?a=1&b=2'],
]);
ok(str_contains($xml, 'a=1&amp;b=2'), 'XML special characters escaped');

$empty = SeoSitemap::renderUrlset([]);
ok(str_contains($empty, '<urlset') && str_contains($empty, 'http://www.sitemaps.org/schemas/sitemap/0.9'), 'Empty sitemap valid');

$robots = SeoRobots::render();
ok(str_contains($robots, 'Sitemap: ' . SeoRobots::sitemapAbsoluteUrl()), 'robots.txt includes absolute sitemap URL');
ok(SeoRobots::disallowsPublicCataloguePaths(), 'robots.txt does not disallow public catalogue/CMS routes');

$base = rtrim(App::baseUrl(), '/');
ok(str_contains(SeoUrl::absolute('/index.php'), $base) && str_contains(url_base(), 'Apni'), 'Local subdirectory URL generation correct');

ok(SeoSitemap::CONTENT_TYPE === 'application/xml; charset=UTF-8', 'Sitemap response content type correct');
ok(SeoRobots::CONTENT_TYPE === 'text/plain; charset=UTF-8', 'robots.txt response content type correct');

$pubMeta = SeoResolver::forCmsPage((array) CmsPageRepository::findBySlug('at-sm13-pub-new-' . $token));
ok(
    stage13_p6_has_loc((string) $pubMeta->canonicalUrl),
    'Canonical URLs used for CMS entry'
);

stage13_p6_cleanup($pdo);

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Stage 13 Phase 6 sitemap & robots.\n";
exit(0);
