<?php

declare(strict_types=1);

/** Homepage shelves hide when empty (disposable DB). */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$pass = 0;
$fail = 0;
function t(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

$tok = strtolower(bin2hex(random_bytes(3)));
$pdo->exec("UPDATE products SET status = 'archived'");

function makeProduct(string $tok, string $n, string $catSlug, string $status, bool $best, array $variant): int
{
    $cat = CategoryRepository::findBySlug($catSlug);
    $catId = $cat ? (int) $cat['id'] : null;
    $r = ProductRepository::saveGeneral(null, [
        'name' => "HS $n $tok", 'slug' => "hs-$n-$tok", 'status' => $status,
        'category_id' => $catId === null ? '' : (string) $catId,
        'is_bestseller' => $best ? '1' : null,
    ]);
    $id = (int) $r['id'];
    ProductRepository::saveVariants($id, [array_merge([
        'id' => '', 'name' => '1 kg', 'sku' => '', 'price' => 50, 'sale_price' => '',
        'stock_qty' => 10, 'low_stock_threshold' => 5, 'is_active' => 1, 'sort_order' => 0,
    ], $variant)]);

    return $id;
}

// ensure categories published
foreach (['vegetables', 'fruits', 'cut', 'pickles'] as $slug) {
    if (CategoryRepository::findBySlug($slug) === null) {
        CategoryRepository::save(null, ['name' => ucfirst($slug), 'slug' => $slug, 'status' => 'published']);
    }
    $pdo->exec("UPDATE categories SET status='published', parent_id=NULL WHERE slug=" . $pdo->quote($slug));
}
CatalogueGateway::reset();
$cat = new DbCatalogue();

$rails = static function () use ($cat): array {
    return [
        catalogue_home_eligible_products(array_merge($cat->productsInCategory('vegetables'), $cat->productsInCategory('fruits')), 5),
        catalogue_home_eligible_products(array_merge($cat->productsInCategory('cut'), $cat->productsInCategory('pickles')), 5),
        catalogue_home_eligible_products($cat->productsWithFlag('bestseller', PHP_INT_MAX), 5),
    ];
};

// Zero products
[$veg, $cut, $best] = $rails();
t($veg === [] && $cut === [] && $best === [], 'Zero products: all three shelves empty');

// Draft / out-of-stock / zero price ignored
makeProduct($tok, 'draft', 'vegetables', 'draft', true, []);
makeProduct($tok, 'oos', 'vegetables', 'published', true, ['stock_qty' => 0]);
makeProduct($tok, 'free', 'cut', 'published', true, ['price' => 0]);
[$veg, $cut, $best] = $rails();
t($veg === [] && $cut === [] && $best === [], 'Draft, out-of-stock and zero-price products excluded');

// One product each
makeProduct($tok, 'veg1', 'vegetables', 'published', false, []);
[$veg, $cut, $best] = $rails();
t(count($veg) === 1 && $cut === [] && $best === [], 'One vegetable: only fresh shelf has products');
makeProduct($tok, 'pick1', 'pickles', 'published', true, []);
[$veg, $cut, $best] = $rails();
t(count($cut) === 1 && count($best) === 1, 'Pickle product fills pantry and bestsellers');

// Multiple
for ($i = 0; $i < 7; $i++) {
    makeProduct($tok, "f$i", 'fruits', 'published', true, []);
}
[$veg, $cut, $best] = $rails();
t(count($veg) === 5 && count($best) === 5, 'Multiple products capped at 5 per shelf');

// Categories independent of products
t(count($cat->categories()) >= 4, 'Categories still listed regardless of product counts');

$src = (string) file_get_contents(APNI_TOKRI_ROOT . '/index.php');
t(substr_count($src, 'catalogue_home_eligible_products') === 2, 'index.php filters both bestseller and rail shelves');
t(strpos($src, 'if ($products === [])') !== false && strpos($src, 'if ($products !== [])') !== false, 'index.php skips empty shelves');

$pdo->exec("DELETE FROM products WHERE slug LIKE 'hs-%-$tok'");
echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
