<?php

declare(strict_types=1);

/** Public catalogue product visibility vs category visibility. Fixtures: slug prefix zt-pv- / zt-pvc- */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

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
    echo ($c ? 'PASS' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

$tok = strtolower(bin2hex(random_bytes(3)));
CatalogueGateway::reset();
ProductRepository::resetPublicVisibilityCache();

function mkCat(string $name, ?int $parent, string $status): int
{
    global $tok;
    $slug = 'zt-pvc-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($name)) . '-' . $tok;
    $r = CategoryRepository::save(null, ['name' => $name, 'slug' => $slug, 'parent_id' => $parent === null ? '' : (string) $parent, 'status' => $status, 'sort_order' => 900]);
    if (!$r['ok']) {
        echo 'cat fixture fail' . json_encode($r) . "\n";
        exit(1);
    }

    return (int) $r['id'];
}

function mkProd(int $catId, string $key, bool $bestseller = false): array
{
    global $tok;
    $slug = 'zt-pv-' . $key . '-' . $tok;
    $r = ProductRepository::saveGeneral(null, [
        'name' => 'PV ' . $key,
        'slug' => $slug,
        'category_id' => (string) $catId,
        'status' => 'published',
        'is_bestseller' => $bestseller ? '1' : null,
    ]);
    if (!$r['ok']) {
        echo 'prod fixture fail' . json_encode($r) . "\n";
        exit(1);
    }
    $id = (int) $r['id'];
    ProductRepository::saveVariants($id, [
        ['name' => 'Default', 'price' => 99, 'stock_qty' => 5, 'low_stock_threshold' => 1, 'is_active' => 1],
    ]);

    return ['id' => $id, 'slug' => $slug];
}

$cat = new DbCatalogue();
$visTop = mkCat('Vis Top', null, 'published');
$hidOnly = mkCat('Hid Only', null, 'hidden');
$visChild = mkCat('Vis Child', $visTop, 'published');
$hidChild = mkCat('Hid Child', $visTop, 'hidden');
$hidParent = mkCat('Hid Parent', null, 'hidden');
$kidPub = mkCat('Kid Pub', $hidParent, 'published');
$g1 = mkCat('L1', null, 'published');
$g2 = mkCat('L2', $g1, 'published');
$g3 = mkCat('L3', $g2, 'published');

$pHiddenOnly = mkProd($hidOnly, 'hidden-only', true);
$pVisible = mkProd($visChild, 'visible', true);
$pHiddenChild = mkProd($hidChild, 'hidden-child', true);
$pUnderHiddenParent = mkProd($kidPub, 'under-hidden-parent', true);
$pDeep = mkProd($g3, 'deep-three', true);
$leafDel = mkCat('Leaf Del', $visTop, 'published');
$pLeafDel = mkProd($leafDel, 'leaf-delete', false);

CatalogueGateway::reset();
ProductRepository::resetPublicVisibilityCache();
$cat = new DbCatalogue();

$slug = static fn (array $p): string => $p['slug'];
$hasSlug = static function (array $list, string $s): bool {
    foreach ($list as $p) {
        if (($p['slug'] ?? '') === $s) {
            return true;
        }
    }

    return false;
};

// Hidden category only
t($cat->product($slug($pHiddenOnly)) === null, 'Direct URL: product in hidden category returns not found');
t(!empty(ProductRepository::find($pHiddenOnly['id'])), 'Admin can still load product in hidden category');
t(!$hasSlug($cat->products(), $slug($pHiddenOnly)), 'Hidden-category product excluded from public product list');
t(!$hasSlug($cat->search('PV hidden-only'), $slug($pHiddenOnly)), 'Hidden-category product excluded from search');
t(!$hasSlug($cat->productsWithFlag('bestseller', 50), $slug($pHiddenOnly)), 'Hidden-category product excluded from best sellers');

// Visible category
t($cat->product($slug($pVisible)) !== null, 'Direct URL: product in visible child category works');
t($hasSlug($cat->search('PV visible'), $slug($pVisible)), 'Visible product appears in search');
t($hasSlug($cat->productsWithFlag('bestseller', 50), $slug($pVisible)), 'Visible product appears in best sellers');
t($hasSlug($cat->productsInCategory(CategoryRepository::find($visChild)['slug']), $slug($pVisible)), 'Product listed on its visible category page');

// Hidden child under active parent
t($cat->product($slug($pHiddenChild)) === null, 'Direct URL: product in hidden child category blocked');
t(!$hasSlug($cat->products(), $slug($pHiddenChild)), 'Hidden child category product excluded from catalogue');

// Child of hidden parent
t($cat->product($slug($pUnderHiddenParent)) === null, 'Direct URL: product under hidden parent blocked');
t(!$hasSlug($cat->search('under-hidden-parent'), $slug($pUnderHiddenParent)), 'Product under hidden parent excluded from search');

// Three-level visible hierarchy
t($cat->product($slug($pDeep)) !== null, 'Three-level hierarchy: product in visible L3 category is public');
$trail = CategoryRepository::ancestors($g3);
t(count($trail) === 2, 'Three-level hierarchy: ancestors depth is 2 for L3 leaf');

// Multi-category rule (assignment helper — schema has single category_id today)
$hidId = $hidOnly;
$visId = $visChild;
t(ProductRepository::hasPublicCategoryAssignment([$hidId, $visId]), 'Multi-assign rule: visible among assignments keeps product public');
t(!ProductRepository::hasPublicCategoryAssignment([$hidId]), 'Only hidden assignment is not public');
t(ProductRepository::hasPublicCategoryAssignment([$visId]), 'Single visible assignment is public');

// Parent deletion: deleting leaf category sets product.category_id NULL (FK ON DELETE SET NULL)
$pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$leafDel]);
ProductRepository::resetPublicVisibilityCache();
CatalogueGateway::reset();
$cat = new DbCatalogue();
$rowAfter = ProductRepository::find($pLeafDel['id']);
t($rowAfter !== null && ($rowAfter['category_id'] === null || $rowAfter['category_id'] === ''), 'Deleting leaf category nulls product.category_id (FK)');
t($cat->product($slug($pLeafDel)) === null, 'Product with no category assignment is not public');
t(ProductRepository::find($pLeafDel['id']) !== null, 'Admin still sees product after category deleted');

// Pickles (seed hidden category) — products should not appear publicly if any exist
$pickles = CategoryRepository::findBySlug('pickles');
if ($pickles !== null && (string) $pickles['status'] === 'hidden') {
    $pickleProds = $pdo->query("SELECT slug FROM products WHERE category_id = " . (int) $pickles['id'] . " AND status = 'published' LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($pickleProds as $ps) {
        t($cat->product((string) $ps) === null, 'Seed product in hidden pickles category not public: ' . $ps);
        t(!in_array((string) $ps, array_column($cat->products(), 'slug'), true), 'Seed pickles product not in public catalogue');
    }
}

// Cleanup
$pdo->exec("DELETE FROM product_variants WHERE product_id IN (SELECT id FROM products WHERE slug LIKE 'zt-pv-%')");
$pdo->exec("DELETE FROM products WHERE slug LIKE 'zt-pv-%'");
$pdo->exec("DELETE FROM categories WHERE slug LIKE 'zt-pvc-%'");

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
