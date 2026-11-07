<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
$ok = static function (bool $cond, string $msg) use (&$fail): void {
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        $fail++;
    } else {
        echo "PASS: $msg\n";
    }
};

$ok(SeoAdminCenter::normalizeProductSeoFilter('missing_meta') === 'missing', 'legacy filter alias');
$ok(SeoAdminCenter::normalizeProductSeoFilter('') === 'all', 'default seo filter all');

$hComplete = SeoAdminCenter::productSeoHealth([
    'meta_title' => 'Fresh Tomatoes Online in Indore | Veggie Tokri',
    'meta_description' => str_repeat('Fresh quality vegetables delivered to your doorstep in Indore. ', 2),
]);
$ok($hComplete['bucket'] === 'complete', 'health complete');

$hMissing = SeoAdminCenter::productSeoHealth(['meta_title' => '', 'meta_description' => '']);
$ok($hMissing['bucket'] === 'missing', 'health missing');

$hImprove = SeoAdminCenter::productSeoHealth([
    'meta_title' => 'Short',
    'meta_description' => 'Only a tiny description',
]);
$ok($hImprove['bucket'] === 'needs_improvement' || $hImprove['bucket'] === 'missing', 'health needs improvement path');

$ok(str_contains(SeoAdminCenter::catalogSectionUrl(['q' => 'Cut Lauki']), 'q=Cut'), 'catalog URL preserves search');

$catalog = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/seo/sections/catalog.php');
$ok(str_contains($catalog, 'searchProductSeoCatalog'), 'catalog uses unified search');
$ok(str_contains($catalog, 'No products found'), 'empty state message');
$ok(str_contains($catalog, 'catalog-product-editor.php'), 'editor drawer');
$ok(str_contains($catalog, 'at-seo-saas__catalog-toolbar'), 'catalog toolbar markup');
$ok(str_contains($catalog, 'at-seo-catalog-card'), 'responsive catalog cards');
$css = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/css/apni-tokri.css');
$ok(str_contains($css, 'at-seo-saas__catalog-stats'), 'catalog KPI grid styles');
$ok(str_contains($css, 'grid-template-columns: repeat(8'), 'nav eight-column desktop grid');

if (Install::isSchemaReady()) {
    Install::ensureConnection();
    $pdo = Database::pdo();
    $mobile = '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    $name = 'Cut Lauki SEO Test';
    $sku = 'SEO-LAU-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $slug = 'cut-lauki-seo-' . strtolower(substr(bin2hex(random_bytes(2)), 0, 4));
    $pdo->prepare(
        'INSERT INTO products (name, slug, sku, status, meta_title, meta_description, subtotal, grand_total)
         VALUES (?, ?, ?, ?, ?, ?, 1, 1)'
    )->execute([$name, $slug, $sku, 'published', 'Cut Lauki Online', str_repeat('Order fresh lauki in Indore. ', 4)]);
    $pid = (int) $pdo->lastInsertId();

    $byName = SeoAdminCenter::searchProductSeoCatalog('Cut Lauki', 'all', 'published', 1, 20);
    $ok($byName['total'] >= 1, 'search by product name');
    $bySku = SeoAdminCenter::searchProductSeoCatalog($sku, 'all', '', 1, 20);
    $ok($bySku['total'] >= 1, 'search by SKU');
    $bySlug = SeoAdminCenter::searchProductSeoCatalog($slug, 'all', '', 1, 20);
    $ok($bySlug['total'] >= 1, 'search by slug');

    $completeOnly = SeoAdminCenter::searchProductSeoCatalog('Cut Lauki', 'complete', '', 1, 20);
    $ok($completeOnly['total'] >= 1, 'complete filter with search finds SEO-complete product');

    $missingOnly = SeoAdminCenter::searchProductSeoCatalog('Cut Lauki', 'missing', '', 1, 20);
    $ok($missingOnly['total'] === 0, 'missing filter excludes complete product (Cut Lauki fix)');

    $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$pid]);
} else {
    echo "SKIP: DB search integration (schema not ready)\n";
}

exit($fail > 0 ? 1 : 0);
