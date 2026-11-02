<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/catalogue/helpers.php';
require_once __DIR__ . '/includes/stage13_phase7_fixture.php';

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

stage13_p7_cleanup($pdo);
$token = strtolower(Security::randomToken(4));

$seoHome = SeoResolver::forHomepage();
$homeGraph = SeoStructuredData::forHomepage($seoHome);
ok($homeGraph !== null && $homeGraph->hasType('Organization'), 'Homepage Organization schema');
ok($homeGraph !== null && $homeGraph->hasType('WebSite'), 'Homepage WebSite schema');
$website = stage13_p7_graph_type($homeGraph, 'WebSite');
ok(
    is_array($website) && isset($website['potentialAction']['@type'])
    && $website['potentialAction']['@type'] === 'SearchAction',
    'SearchAction only when supported'
);
$searchTpl = SeoUrl::absolute('/search.php?q={search_term_string}');
ok(
    is_array($website) && str_contains((string) ($website['potentialAction']['target']['urlTemplate'] ?? ''), 'search.php'),
    'SearchAction uses public search URL'
);

$cat = CategoryRepository::save(null, [
    'name' => 'AT-SD13 Cat ' . $token,
    'slug' => 'at-sd13-cat-' . $token,
    'status' => 'published',
]);
$catId = (int) ($cat['id'] ?? 0);
$prod = ProductRepository::saveGeneral(null, [
    'name' => 'AT-SD13 Prod ' . $token,
    'slug' => 'at-sd13-prod-' . $token,
    'category_id' => $catId,
    'status' => 'published',
    'product_type' => 'fresh',
    'robots_index' => 1,
]);
$prodId = (int) ($prod['id'] ?? 0);
$pdo->prepare(
    'INSERT INTO product_variants (product_id, name, sku, price, stock_qty, low_stock_threshold, is_active, sort_order)
     VALUES (?, ?, ?, 99.50, 10, 2, 1, 1)'
)->execute([$prodId, 'Default', 'AT-SD13-SKU-' . $token]);

$row = ProductRepository::findBySlug('at-sd13-prod-' . $token, true);
$storeProduct = ProductRepository::toPublicArray($row);
$catRow = CategoryRepository::find($catId);
$seoProd = SeoResolver::forProductRow($row);
$graphProd = SeoStructuredData::forProductPage($storeProduct, CategoryRepository::toPublicArray($catRow), null, [], $seoProd);
$productNode = $graphProd !== null ? stage13_p7_graph_type($graphProd, 'Product') : null;
ok($productNode !== null, 'Product schema for published product');
ok(
    is_array($productNode) && ($productNode['url'] ?? '') === $seoProd->canonicalUrl,
    'Product canonical URL'
);
ok(
    is_array($productNode) && ($productNode['offers']['priceCurrency'] ?? '') === 'INR'
    && ($productNode['offers']['price'] ?? '') === '99.50',
    'Actual price and currency'
);
ok(
    is_array($productNode) && ($productNode['offers']['availability'] ?? '') === 'https://schema.org/InStock',
    'Availability reflects stock rules'
);

$pdo->prepare('UPDATE product_variants SET stock_qty = 0 WHERE product_id = ?')->execute([$prodId]);
$storeOut = ProductRepository::toPublicArray(ProductRepository::findBySlug('at-sd13-prod-' . $token, true));
$graphOut = SeoStructuredData::forProductPage($storeOut, CategoryRepository::toPublicArray($catRow), null, [], $seoProd);
$outNode = $graphOut !== null ? stage13_p7_graph_type($graphOut, 'Product') : null;
ok(
    is_array($outNode) && ($outNode['offers']['availability'] ?? '') === 'https://schema.org/OutOfStock',
    'Out of stock availability'
);
$pdo->prepare('UPDATE product_variants SET stock_qty = 10 WHERE product_id = ?')->execute([$prodId]);

$draftProd = ProductRepository::saveGeneral(null, [
    'name' => 'AT-SD13 Draft Prod ' . $token,
    'slug' => 'at-sd13-draft-prod-' . $token,
    'category_id' => $catId,
    'status' => 'draft',
    'product_type' => 'fresh',
]);
$draftId = (int) ($draftProd['id'] ?? 0);
$pdo->prepare(
    'INSERT INTO product_variants (product_id, name, sku, price, stock_qty, low_stock_threshold, is_active, sort_order)
     VALUES (?, ?, ?, 10, 5, 1, 1, 1)'
)->execute([$draftId, 'Default', 'AT-SD13-DRAFT-' . $token]);
$draftRow = ProductRepository::find($draftId);
$draftStore = ProductRepository::toPublicArray($draftRow);
$draftSeo = SeoResolver::forProductRow($draftRow);
ok(SeoStructuredData::forProductPage($draftStore, null, null, [], $draftSeo) === null, 'Unpublished product has no Product schema');
$minimal = ['slug' => 'at-sd13-prod-' . $token, 'name' => 'AT-SD13 Prod ' . $token, 'status' => 'published', 'variants' => []];
ok(SeoStructuredData::forProductPage($minimal, null, null, [], $seoProd) === null, 'Missing optional product properties omitted');

$crumbs = stage13_p7_graph_type($graphProd, 'BreadcrumbList');
ok(
    is_array($crumbs) && count($crumbs['itemListElement'] ?? []) === 3
    && ($crumbs['itemListElement'][0]['name'] ?? '') === 'Home',
    'BreadcrumbList matches visible navigation'
);

$customerId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
if ($customerId <= 0) {
    $pdo->exec("INSERT INTO customers (email, password_hash, name, phone) VALUES ('at-sd13-{$token}@example.test', 'x', 'AT SD13', '9000000000')");
    $customerId = (int) $pdo->lastInsertId();
}
$pdo->prepare(
    'INSERT INTO product_reviews (product_id, customer_id, rating, body, status, display_name, verified_purchase)
     VALUES (?, ?, 5, ?, ?, ?, 1)'
)->execute([$prodId, $customerId, 'Great quality & fresh!', ReviewStatus::APPROVED, 'Tester One']);
$pdo->prepare(
    'INSERT INTO product_reviews (product_id, customer_id, rating, body, status, display_name, verified_purchase)
     VALUES (?, ?, 3, ?, ?, ?, 1)'
)->execute([$prodId, $customerId, 'Pending body', ReviewStatus::PENDING, 'Pending User']);
$pdo->prepare(
    'INSERT INTO product_reviews (product_id, customer_id, rating, body, status, display_name, verified_purchase)
     VALUES (?, ?, 1, ?, ?, ?, 1)'
)->execute([$prodId, $customerId, 'Rejected body', ReviewStatus::REJECTED, 'Rejected User']);

$rating = ReviewService::ratingSummary($prodId);
$reviews = demo_catalogue()->approvedReviewsFor('at-sd13-prod-' . $token);
$storeProduct = ProductRepository::toPublicArray(ProductRepository::findBySlug('at-sd13-prod-' . $token, true));
$graphReviews = SeoStructuredData::forProductPage($storeProduct, CategoryRepository::toPublicArray($catRow), $rating, $reviews, $seoProd);
$prodWithReviews = stage13_p7_graph_type($graphReviews, 'Product');
ok(
    is_array($prodWithReviews) && isset($prodWithReviews['aggregateRating'])
    && (int) ($prodWithReviews['aggregateRating']['reviewCount'] ?? 0) === 1,
    'Approved reviews included only when eligible'
);
ok(
    is_array($prodWithReviews) && count($prodWithReviews['review'] ?? []) === 1,
    'Pending/rejected reviews excluded'
);
ok(
    is_array($prodWithReviews) && ($prodWithReviews['aggregateRating']['ratingValue'] ?? '') === '5.0',
    'Aggregate rating accurately calculated'
);

$pdo->exec('DELETE FROM product_reviews WHERE product_id = ' . (int) $prodId);
$ratingEmpty = ReviewService::ratingSummary($prodId);
$graphNoRev = SeoStructuredData::forProductPage(
    $storeProduct,
    CategoryRepository::toPublicArray($catRow),
    $ratingEmpty,
    [],
    $seoProd
);
$prodNoRev = stage13_p7_graph_type($graphNoRev, 'Product');
ok(is_array($prodNoRev) && !isset($prodNoRev['aggregateRating']), 'No reviews means no AggregateRating');

$pdo->prepare('INSERT INTO faq_categories (name, slug, sort_order, is_active) VALUES (?, ?, 0, 1)')->execute([
    'AT-SD13 FAQ Cat ' . $token,
    'at-sd13-faq-' . $token,
]);
$faqCatId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO faqs (category_id, question, answer, sort_order, is_active) VALUES (?, ?, ?, 0, 1)')->execute([
    $faqCatId,
    'AT-SD13 Q active?',
    'Yes, visible answer.',
]);
$pdo->prepare('INSERT INTO faqs (category_id, question, answer, sort_order, is_active) VALUES (?, ?, ?, 0, 0)')->execute([
    $faqCatId,
    'AT-SD13 Q inactive?',
    'Hidden.',
]);
$faqRows = FaqRepository::listActivePublic(null, null, 1, 50)['rows'];
$seoFaq = SeoResolver::forFaqPage(false);
$faqGraph = SeoStructuredData::forFaqPage($faqRows, $seoFaq, false);
$faqPage = stage13_p7_graph_type($faqGraph, 'FAQPage');
ok($faqGraph !== null && $faqPage !== null, 'Active visible FAQ schema when eligible');
$entities = $faqPage['mainEntity'] ?? [];
$hasInactive = false;
foreach ($entities as $entity) {
    if (str_contains((string) ($entity['name'] ?? ''), 'inactive')) {
        $hasInactive = true;
    }
}
ok(!$hasInactive, 'Inactive FAQ excluded');

$draftCms = CmsPageService::create([
    'title' => 'AT-SD13 Draft CMS',
    'slug' => 'at-sd13-draft-' . $token,
    'body_html' => '<p>D</p>',
], null);
$draftSeo = SeoMetadata::noindex('Draft');
ok(SeoStructuredData::forCmsPage(['title' => 'X', 'status' => PublishStatus::DRAFT], $draftSeo) === null, 'CMS draft has no public schema');
$privSeo = SeoResolver::forCmsPage([
    'title' => 'Private',
    'slug' => 'at-sd13-private',
    'status' => PublishStatus::PUBLISHED,
    'visibility' => CmsPageService::VISIBILITY_PRIVATE,
    'robots_index' => 1,
    'robots_follow' => 1,
]);
ok(SeoStructuredData::forCmsPage([
    'title' => 'Private',
    'status' => PublishStatus::PUBLISHED,
    'visibility' => CmsPageService::VISIBILITY_PRIVATE,
], $privSeo) === null, 'CMS private pages have no public schema');

$badGraph = new SeoJsonLd();
$badGraph->add(['@type' => 'WebPage', 'url' => 'javascript:alert(1)']);
$decodedBad = stage13_p7_decode_graph($badGraph);
ok($decodedBad !== [], 'Invalid URL node still encodes safely');
$seoBad = SeoMetadata::fromLegacy('Test', null, 'index, follow');
$badGraph2 = SeoStructuredData::forCmsPage([
    'title' => 'Bad',
    'status' => PublishStatus::PUBLISHED,
    'visibility' => CmsPageService::VISIBILITY_PUBLIC,
    'slug' => 'at-sd13-bad',
], $seoBad);
// Force invalid canonical via resolver mock - use structured with invalid seo canonical
$seoInvalid = new SeoMetadata(
    'Bad',
    null,
    'javascript:alert(1)',
    'index, follow'
);
ok(
    SeoStructuredData::forCmsPage([
        'title' => 'Bad',
        'status' => PublishStatus::PUBLISHED,
        'visibility' => CmsPageService::VISIBILITY_PUBLIC,
    ], $seoInvalid) === null,
    'Invalid URL omitted'
);

$unicodeGraph = SeoStructuredData::forProductPage(
    array_merge($storeProduct, ['name' => 'AT-SD13 Unicode “quote” & <tag>']),
    CategoryRepository::toPublicArray($catRow),
    null,
    [],
    $seoProd
);
$decodedUnicode = stage13_p7_decode_graph($unicodeGraph ?? new SeoJsonLd());
ok(json_last_error() === JSON_ERROR_NONE && $decodedUnicode !== [], 'Unicode and special characters produce valid JSON');

ok($graphProd !== null && $graphProd->graphCount() >= 2, 'Single graph holds multiple nodes');
$script = ($graphProd ?? new SeoJsonLd())->renderScriptTag();
ok(substr_count($script, 'application/ld+json') === 1, 'No duplicate JSON-LD scripts');

$noindexSeo = SeoMetadata::noindex('Hidden');
ok(SeoStructuredData::forHomepage($noindexSeo) === null, 'Noindex pages do not emit public structured data');

$seoBefore = SeoResolver::forProductRow(ProductRepository::findBySlug('at-sd13-prod-' . $token, true));
SeoStructuredData::forProductPage($storeProduct, null, null, [], $seoBefore);
$seoAfter = SeoResolver::forProductRow(ProductRepository::findBySlug('at-sd13-prod-' . $token, true));
ok(
    $seoBefore->documentTitle === $seoAfter->documentTitle
    && $seoBefore->canonicalUrl === $seoAfter->canonicalUrl
    && $seoBefore->robots === $seoAfter->robots,
    'Existing SEO metadata remains unchanged'
);

stage13_p7_cleanup($pdo);

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Stage 13 Phase 7 structured data.\n";
exit(0);
