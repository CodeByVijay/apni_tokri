<?php

declare(strict_types=1);

/**
 * Product share + product Open Graph regression.
 * CLI: php scripts/test-product-share.php
 * Disposable: php scripts/run-in-disposable-db.php scripts/test-product-share.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/lib/test_script_safety.php';

$fail = 0;
function okShare(bool $cond, string $msg): void
{
    global $fail;
    echo ($cond ? 'OK' : 'FAIL') . ': ' . $msg . "\n";
    if (!$cond) {
        $fail++;
    }
}

if (getenv('APNI_TOKRI_DISPOSABLE_DB') === '1') {
    TestScriptSafety::assertDisposableDatabaseOrAbort();
}

$productA = [
    'slug' => 'peeled-garlic-demo',
    'name' => 'Peeled Garlic & "Fresh"',
    'short_description' => 'Ready <em>to use</em> cloves',
    'description' => '',
    'variants' => [
        ['price' => 99, 'sale_price' => null, 'stock' => 10],
        ['price' => 120, 'sale_price' => 89, 'stock' => 5],
    ],
    'images' => [asset_url('assets/images/demo/p-tomato-2.jpg')],
];

$productB = [
    'slug' => 'chopped-onion-demo',
    'name' => 'Chopped Onion',
    'short_description' => '',
    'description' => 'Pre-cut for quick cooking.',
    'variants' => [],
    'images' => [],
];

$urlA = ProductShare::canonicalUrl($productA);
$urlB = ProductShare::canonicalUrl($productB);
okShare($urlA !== $urlB, 'distinct products produce distinct canonical URLs');
okShare(str_contains($urlA, 'product.php?slug=' . rawurlencode('peeled-garlic-demo')), 'canonical uses product.php slug route');
okShare(str_contains($urlB, 'slug=chopped-onion-demo'), 'second product slug in URL');
okShare(!str_contains($urlA, 'index.php'), 'share URL is not homepage');

$wa = ProductShare::whatsAppShareUrl($productA);
okShare(str_starts_with($wa, 'https://wa.me/?text='), 'WhatsApp share URL scheme');
$decodedWa = rawurldecode(substr($wa, strlen('https://wa.me/?text=')));
okShare(str_contains($decodedWa, $urlA), 'WhatsApp message includes product URL');
okShare(str_contains($decodedWa, 'Peeled Garlic'), 'WhatsApp message includes product name');

$tg = ProductShare::telegramShareUrl($productA);
okShare(str_contains($tg, 't.me/share/url?url='), 'Telegram share URL scheme');
parse_str((string) parse_url($tg, PHP_URL_QUERY), $tgQ);
okShare(($tgQ['url'] ?? '') === $urlA, 'Telegram url param is canonical product URL');

$textWithPrice = ProductShare::shareText($productA);
okShare(str_contains($textWithPrice, 'From ₹'), 'starting price in share text when variant in stock');
okShare(!str_contains($textWithPrice, 'SKU'), 'share text excludes SKU');

$textNoVariants = ProductShare::shareText($productB);
okShare(!str_contains($textNoVariants, 'From ₹'), 'no starting price without valid variants');

$draftRow = [
    'status' => 'draft',
    'name' => 'Hidden',
    'slug' => 'hidden-draft',
    'short_description' => 'x',
    'meta_title' => '',
    'meta_description' => '',
    'robots_index' => 1,
    'id' => 1,
];
$draftSeo = SeoResolver::forProductRow($draftRow);
okShare(str_contains((string) $draftSeo->documentTitle, 'not found'), 'draft product SEO is not found');

$publishedRow = [
    'status' => 'published',
    'name' => 'Tomato & Basil',
    'slug' => 'tomato-basil-' . bin2hex(random_bytes(2)),
    'short_description' => 'Juicy "red" tomatoes',
    'meta_title' => 'Custom meta title',
    'meta_description' => '',
    'robots_index' => 1,
    'id' => 0,
];
$pubSeo = SeoResolver::forProductRow($publishedRow);
$head = $pubSeo->headTagLines();
$joined = implode("\n", $head);
$site = SeoUrl::siteName();
okShare(str_contains($joined, 'property="og:title" content="Tomato &amp; Basil | ' . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . '"')
    || str_contains($joined, 'og:title" content="Tomato &amp; Basil | '), 'OG title escaped with site name');
okShare(str_contains($joined, 'property="og:type" content="product"'), 'og:type product');
okShare(str_contains($joined, 'twitter:card" content="summary_large_image"'), 'twitter card large image');
$canonicalExpected = SeoUrl::absolute('/product.php?slug=' . rawurlencode((string) $publishedRow['slug']));
okShare(str_contains($joined, Security::escape($canonicalExpected)), 'og:url canonical matches product slug');
okShare(str_contains($joined, 'og:description') && str_contains($joined, 'Juicy'), 'og:description from short description');

$storeSeo = SeoResolver::forStorefrontProduct($productA);
okShare(str_contains((string) $storeSeo->ogTitle, 'Peeled Garlic'), 'storefront OG title includes product name');

$productPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/product.php');
okShare(str_contains($productPhp, 'product-share.php'), 'PDP includes share partial');
okShare(str_contains($productPhp, 'ProductShare::clientPayload'), 'PDP builds share payload');

$catalogueJs = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/catalogue.js');
okShare(str_contains($catalogueJs, 'initProductShare'), 'catalogue.js initializes product share');
okShare(str_contains($catalogueJs, 'navigator.share'), 'native share API used');
okShare(str_contains($catalogueJs, 'data-at-share-copy'), 'copy link fallback');

$partial = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/product-share.php');
okShare(str_contains($partial, 'at-product-share-data'), 'share JSON payload in HTML');
okShare(str_contains($partial, 'data-at-share-whatsapp'), 'WhatsApp action in fallback menu');

if (getenv('APNI_TOKRI_DISPOSABLE_DB') === '1' && Install::isSchemaReady()) {
    Install::ensureConnection();
    $tok = bin2hex(random_bytes(3));
    $pdo = Database::pdo();
    $pdo->exec("INSERT INTO categories (name, slug, status, sort_order) VALUES ('Share Cat', 'share-cat-{$tok}', 'published', 1)");
    $catId = (int) $pdo->lastInsertId();
    $slug1 = 'share-a-' . $tok;
    $slug2 = 'share-b-' . $tok;
    $pdo->prepare(
        "INSERT INTO products (name, slug, sku, category_id, product_type, status, short_description)
         VALUES ('Share A', ?, ?, ?, ?, 'published', 'Short A'),
                ('Share B', ?, ?, ?, ?, 'published', 'Short B')"
    )->execute([$slug1, 'SKU-SA-' . $tok, $catId, ProductTypeRepository::defaultCode(), $slug2, 'SKU-SB-' . $tok, $catId, ProductTypeRepository::defaultCode()]);
    okShare(!ProductShare::isPublishedForShare('draft-only-' . $tok), 'unpublished slug not shareable');
    okShare(ProductShare::isPublishedForShare($slug1), 'published slug shareable');
    $u1 = ProductShare::canonicalUrl(['slug' => $slug1]);
    $u2 = ProductShare::canonicalUrl(['slug' => $slug2]);
    okShare($u1 !== $u2 && str_contains($u1, $slug1) && str_contains($u2, $slug2), 'DB products: distinct canonical URLs');
}

echo "\nSUMMARY fail={$fail}\n";
exit($fail > 0 ? 1 : 0);
