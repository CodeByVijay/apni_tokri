<?php

declare(strict_types=1);

/**
 * Homepage Best sellers / Featured / New arrivals: order, conditional rendering, flag queries.
 * CLI: php scripts/run-in-disposable-db.php scripts/test-homepage-product-sections.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

$fail = 0;
$ok = static function (bool $c, string $m) use (&$fail): void {
    echo ($c ? 'PASS: ' : 'FAIL: ') . $m . "\n";
    if (!$c) {
        $fail++;
    }
};

// ---- static
$idx = (string) file_get_contents(APNI_TOKRI_ROOT . '/index.php');
$partial = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/home-section.php');
$gw = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/catalogue/gateway.php');

$pBest = strpos($idx, "productsWithFlag('bestseller'");
$pFeat = strpos($idx, "productsWithFlag('featured'");
$pNew = strpos($idx, "productsWithFlag('new'");
$pDeals = strpos($idx, 'home-store-offers.php');
$pRails = strpos($idx, '$rails = [');
$pSubs = strpos($idx, 'id="subscriptions"');
$ok($pBest !== false && $pFeat !== false && $pNew !== false && $pDeals !== false, 'static: all four sections present');
$ok($pBest < $pFeat && $pFeat < $pNew && $pNew < $pDeals && $pDeals < $pRails && $pRails < $pSubs, 'static: order Best, Featured, New, Deals, rails, Subscriptions');
$ok(substr_count($idx, "'Featured products'") === 1 && substr_count($idx, "'New arrivals'") === 1 && substr_count($idx, "'Best sellers'") === 1, 'static: no duplicated sections');
$ok(preg_match("/productsWithFlag\('featured'[^;]+;\s*if \(\\\$products !== \[\]\) \{\s*require/", $idx) === 1, 'static: featured wrapper only included when non-empty (PHP-level)');
$ok(preg_match("/productsWithFlag\('new'[^;]+;\s*if \(\\\$products !== \[\]\) \{\s*require/", $idx) === 1, 'static: new arrivals wrapper only included when non-empty (PHP-level)');
$ok(str_contains($idx, '$sectionHref = null;'), 'static: no invented Browse all route for Featured/New');
$ok(str_contains($partial, '!empty($sectionHref)'), 'static: partial renders link only with destination');
$ok(str_contains($gw, 'publishedProductsWithFlag'), 'static: DB catalogue filters flags in SQL');
$ok(str_contains((string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/product-card.php'), "'new'"), 'static: product card New badge preserved');

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "SKIP DB: schema not ready.\n");
    exit($fail > 0 ? 1 : 0);
}

// ---- DB
Install::ensureConnection();
$pdo = Database::pdo();
$tok = strtolower(bin2hex(random_bytes(3)));
$pdo->exec("UPDATE products SET status = 'archived' WHERE slug NOT LIKE 'hp-%'");
CategoryRepository::findBySlug('vegetables') ?: CategoryRepository::save(null, ['name' => 'Vegetables', 'slug' => 'vegetables', 'status' => 'published']);
$pdo->exec("UPDATE categories SET status='published', parent_id=NULL WHERE slug='vegetables'");
$catId = (int) CategoryRepository::findBySlug('vegetables')['id'];

$make = static function (string $n, string $status, array $flags, int $stock = 10) use ($tok, $catId): int {
    $r = ProductRepository::saveGeneral(null, array_merge([
        'name' => "HP $n $tok", 'slug' => "hp-$n-$tok", 'status' => $status, 'category_id' => (string) $catId,
    ], $flags));
    $id = (int) $r['id'];
    ProductRepository::saveVariants($id, [[
        'id' => '', 'name' => '1 kg', 'sku' => '', 'price' => 50, 'sale_price' => '',
        'stock_qty' => $stock, 'low_stock_threshold' => 5, 'is_active' => 1, 'sort_order' => 0,
    ]]);

    return $id;
};
$sections = static function (): array {
    CatalogueGateway::reset();
    $c = new DbCatalogue();

    return [
        'best' => catalogue_home_eligible_products($c->productsWithFlag('bestseller', PHP_INT_MAX), 5),
        'feat' => catalogue_home_eligible_products($c->productsWithFlag('featured', PHP_INT_MAX), 5),
        'new' => catalogue_home_eligible_products($c->productsWithFlag('new', PHP_INT_MAX), 5),
    ];
};
$mine = static function (array $list) use ($tok): array {
    return array_values(array_filter($list, static fn (array $p): bool => str_contains((string) $p['slug'], "-$tok")));
};

$s = $sections();
$ok($s['best'] === [] && $s['feat'] === [] && $s['new'] === [], 'db: no flagged products — all sections empty (hidden)');

$make('plain', 'published', []);
$s = $sections();
$ok($s['feat'] === [] && $s['new'] === [] && $s['best'] === [], 'db: unflagged product appears in no section');

$make('draftfeat', 'draft', ['is_featured' => '1']);
$make('oosfeat', 'published', ['is_featured' => '1'], 0);
$s = $sections();
$ok($s['feat'] === [], 'db: draft and out-of-stock featured products excluded');

$f1 = $make('feat1', 'published', ['is_featured' => '1']);
$s = $sections();
$ok(count($s['feat']) === 1 && $s['best'] === [] && $s['new'] === [], 'db: only Featured has products — one product, others empty');

$make('new1', 'published', ['is_new_arrival' => '1']);
$n2 = $make('new2', 'published', ['is_new_arrival' => '1']);
$pdo->exec("UPDATE products SET created_at = DATE_SUB(NOW(), INTERVAL 5 DAY) WHERE slug = " . $pdo->quote("hp-new1-$tok"));
$s = $sections();
$ok(count($s['new']) === 2, 'db: two New Arrivals show two products');
$ok(($s['new'][0]['slug'] ?? '') === "hp-new2-$tok", 'db: New Arrivals ordered by created_at DESC');

$make('best1', 'published', ['is_bestseller' => '1']);
$s = $sections();
$ok(count($s['best']) === 1 && count($s['feat']) === 1 && count($s['new']) === 2, 'db: all three sections populated independently');
$ok(in_array('featured', $s['feat'][0]['flags'] ?? [], true) && !in_array('new', $s['feat'][0]['flags'] ?? [], true), 'db: badges follow flags only');

$both = $make('both', 'published', ['is_featured' => '1', 'is_new_arrival' => '1']);
$s = $sections();
$ok(count($mine($s['feat'])) === 2 && count($mine($s['new'])) === 3, 'db: product with two flags appears in both sections');

$v = $s['feat'][0]['variants'][0] ?? [];
$ok((float) ($v['price'] ?? 0) === 50.0 && !empty($v['id']), 'db: price and variants unchanged in card data');

$pdo->exec("DELETE FROM products WHERE slug LIKE 'hp-%-$tok'");
exit($fail > 0 ? 1 : 0);
