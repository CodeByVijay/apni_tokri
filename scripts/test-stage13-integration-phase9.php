<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/catalogue/helpers.php';
require_once __DIR__ . '/includes/stage13_phase9_fixture.php';
require_once __DIR__ . '/includes/stage13_phase2_fixture.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}
if (!Install::isVersionCurrent()) {
    fwrite(STDERR, 'Schema version mismatch (expected ' . Install::SCHEMA_VERSION . ').' . "\n");
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

stage13_p9_cleanup($pdo);
$adminId = stage13_p2_admin_with_cms($pdo);
$token = strtolower(Security::randomToken(4));

$draft = CmsPageService::create([
    'title' => 'AT-INT13 Draft',
    'slug' => 'at-int13-draft-' . $token,
    'body_html' => '<p>Draft</p>',
], $adminId);
$draftId = (int) ($draft['id'] ?? 0);
ok(CmsPageService::publishedPublicBySlug('at-int13-draft-' . $token) === null, 'CMS draft not publicly accessible');
stage13_p2_admin_session($adminId);
ok(CmsPageService::pageForAdminPreview($draftId) !== null, 'CMS draft preview available to authorized admin');
stage13_p2_clear_admin_session();

$pub = CmsPageService::create([
    'title' => 'AT-INT13 Pub',
    'slug' => 'at-int13-pub-' . $token,
    'body_html' => '<p>Pub</p>',
], null);
$pubId = (int) ($pub['id'] ?? 0);
CmsPageService::publish($pubId, [
    'title' => 'AT-INT13 Pub',
    'slug' => 'at-int13-pub-' . $token,
    'body_html' => '<p>Pub</p>',
    'preserve_slug' => 1,
    'robots_index' => 1,
    'robots_follow' => 1,
], null);
$cmsRow = CmsPageRepository::findBySlug('at-int13-pub-' . $token);
$cmsSeo = SeoResolver::forCmsPage($cmsRow);
ok($cmsSeo->canonicalUrl !== null && $cmsSeo->isIndexableForSitemap(), 'Published CMS canonical metadata');
ok(in_array((string) $cmsSeo->canonicalUrl, stage13_p9_locs(), true), 'Published CMS in sitemap');

CmsPageService::update($pubId, [
    'title' => 'AT-INT13 Pub',
    'slug' => 'at-int13-pub-new-' . $token,
    'body_html' => '<p>Pub</p>',
    'slug_manual' => 1,
    'visibility' => CmsPageService::VISIBILITY_PUBLIC,
    'robots_index' => 1,
], null, 'Slug change');
$oldCmsUrl = SeoUrl::absolute('/page.php?slug=' . rawurlencode('at-int13-pub-' . $token));
$newCmsUrl = SeoUrl::absolute('/page.php?slug=' . rawurlencode('at-int13-pub-new-' . $token));
ok(SeoRedirectService::resolveFinalDestination(SeoRedirectPath::redirectIdentity($oldCmsUrl)) === SeoRedirectPath::redirectIdentity($newCmsUrl), 'CMS slug change redirects old URL');

CmsPageService::unpublish($pubId, null, 'Unpublish');
ok(CmsPageService::publishedPublicBySlug('at-int13-pub-new-' . $token) === null, 'Unpublished CMS not public');
ok(!in_array($newCmsUrl, stage13_p9_locs(), true), 'Unpublished CMS excluded from sitemap');

$pdo->prepare('INSERT INTO faq_categories (name, slug, sort_order, is_active) VALUES (?, ?, 0, 1)')->execute([
    'AT-INT13 Cat', 'at-int13-faq-' . $token,
]);
$catId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO faqs (category_id, question, answer, sort_order, is_active) VALUES (?, ?, ?, 0, 1)')->execute([
    $catId, 'AT-INT13 Q?', 'Answer text.',
]);
$list = FaqRepository::listActivePublic('at-int13-faq-' . $token, null, 1, 10);
ok(count($list['rows'] ?? []) >= 1, 'Active FAQ visible publicly');
$pdo->prepare('UPDATE faq_categories SET is_active = 0 WHERE id = ?')->execute([$catId]);
$listOff = FaqRepository::listActivePublic('at-int13-faq-' . $token, null, 1, 10);
ok(count($listOff['rows'] ?? []) === 0, 'Inactive FAQ category hidden publicly');

$cat = CategoryRepository::save(null, ['name' => 'AT-INT13 Cat', 'slug' => 'at-int13-cat-' . $token, 'status' => 'published']);
$catIdProd = (int) ($cat['id'] ?? 0);
$prod = ProductRepository::saveGeneral(null, [
    'name' => 'AT-INT13 Prod',
    'slug' => 'at-int13-prod-' . $token,
    'category_id' => $catIdProd,
    'status' => 'published',
    'product_type' => 'fresh',
    'robots_index' => 1,
]);
$prodId = (int) ($prod['id'] ?? 0);
$pdo->prepare(
    'INSERT INTO product_variants (product_id, name, sku, price, stock_qty, low_stock_threshold, is_active, sort_order) VALUES (?, ?, ?, 20, 3, 1, 1, 1)'
)->execute([$prodId, 'Default', 'AT-INT13-SKU',]);
$prodRow = ProductRepository::findBySlug('at-int13-prod-' . $token, true);
$prodSeo = SeoResolver::forProductRow($prodRow);
$store = ProductRepository::toPublicArray($prodRow);
$schema = SeoStructuredData::forProductPage($store, CategoryRepository::toPublicArray(CategoryRepository::find($catIdProd)), null, [], $prodSeo);
ok($schema !== null && $schema->hasType('Product'), 'Published product Product schema');
ok(str_contains(implode(',', stage13_p9_locs()), 'at-int13-prod-' . $token), 'Published product in sitemap');

ProductRepository::saveGeneral($prodId, [
    'name' => 'AT-INT13 Prod',
    'slug' => 'at-int13-prod-new-' . $token,
    'category_id' => $catIdProd,
    'status' => 'published',
    'product_type' => 'fresh',
    'robots_index' => 1,
]);
$oldProd = SeoRedirectPath::productPage('at-int13-prod-' . $token);
$newProd = SeoRedirectPath::productPage('at-int13-prod-new-' . $token);
ok(SeoRedirectService::resolveFinalDestination($oldProd) === $newProd, 'Product slug redirect');

CategoryRepository::save($catIdProd, ['name' => 'AT-INT13 Cat', 'slug' => 'at-int13-cat-new-' . $token, 'status' => 'published']);
$oldCat = SeoRedirectPath::categoryPage('at-int13-cat-' . $token);
$newCat = SeoRedirectPath::categoryPage('at-int13-cat-new-' . $token);
ok($oldCat !== $newProd && SeoRedirectService::resolveFinalDestination($oldCat) === $newCat, 'Category redirect identity distinct from product');

$withUtm = SeoRedirectPath::redirectIdentity($oldProd . '&utm_source=email');
ok(SeoRedirectService::resolveFinalDestination($withUtm) === $newProd, 'Tracking params on old product URL redirect safely');

$config = HomepageTrustService::defaultConfig();
foreach ($config['sections'] as &$sec) {
    if (($sec['id'] ?? '') === 'why_features') {
        $sec['enabled'] = false;
    }
}
unset($sec);
AppSettings::set(HomepageTrustService::SETTING_KEY, json_encode($config, JSON_UNESCAPED_UNICODE));
$html = HomepageTrustRenderer::renderAll(url_base(), demo_catalogue());
ok(!str_contains($html, 'Why Veggie Tokri') && str_contains($html, 'at-trust'), 'Disabled trust section hidden; others remain');

$pdo->prepare(
    "INSERT INTO testimonials (customer_display_name, body, status, consent_recorded_at, featured, sort_order) VALUES ('AT-INT13 OK', 'Good.', ?, NOW(), 1, 0)"
)->execute([ReviewStatus::APPROVED]);
$pdo->prepare(
    "INSERT INTO testimonials (customer_display_name, body, status, consent_recorded_at, featured, sort_order) VALUES ('AT-INT13 Bad', 'No.', ?, NOW(), 1, 0)"
)->execute([ReviewStatus::PENDING]);
$featured = TestimonialService::featuredPublic(10);
$names = array_column($featured, 'customer_display_name');
ok(in_array('AT-INT13 OK', $names, true) && !in_array('AT-INT13 Bad', $names, true), 'Testimonials require approval and consent');

$seoHome = SeoResolver::forHomepage();
$json = SeoStructuredData::forHomepage($seoHome);
ok($json !== null && $json->hasType('WebSite'), 'Homepage JSON-LD after trust settings change');

$sitemapUrl = SeoRobots::sitemapAbsoluteUrl();
$robots = SeoRobots::render();
ok(str_contains($sitemapUrl, '/sitemap.xml') && str_contains($robots, $sitemapUrl), 'Sitemap and robots URLs under configured base');

stage13_p9_cleanup($pdo);

if ($fail > 0) {
    echo "\n{$fail} integration checks failed.\n";
    exit(1);
}
echo "\nPASS: Stage 13 Phase 9 integration.\n";
exit(0);
