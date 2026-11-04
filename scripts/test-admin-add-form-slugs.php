<?php

declare(strict_types=1);

/**
 * Add Product / Add Category form slug behaviour (blank name+slug, auto-generate on save).
 */
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

$catEdit = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/categories/edit.php');
$prodEdit = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/products/edit.php');

t(strpos($catEdit, "'name' => ''") !== false && strpos($catEdit, "'slug' => ''") !== false, 'Add category form defaults name and slug to empty');
t(strpos($catEdit, 'Fresh Vegetables') === false || strpos($catEdit, 'e.g. Fresh Vegetables') !== false, 'Category add uses placeholder not prefilled name value');
t(strpos($catEdit, 'slugSourcePlaceholder') !== false, 'Category add passes name placeholder');

t(strpos($prodEdit, "'name' => ''") !== false && strpos($prodEdit, "'slug' => ''") !== false, 'Add product form defaults name and slug to empty');
t(strpos($prodEdit, "'sku' => ''") !== false, 'Add product form defaults SKU to empty (auto-assign on save)');
t(strpos($prodEdit, 'VEG-TOM-001') === false, 'Add product form must not prefill sample SKU VEG-TOM-001');
t(strpos($prodEdit, "'status' => 'draft'") !== false, 'Add product keeps draft status default');
t(strpos($prodEdit, 'slugSourcePlaceholder') !== false, 'Product add passes name placeholder');

// Server: empty slug on create → from name
$catCreate = CategoryRepository::save(null, [
    'name' => 'Mix Veg Curry Pack ' . $tok,
    'slug' => '',
    'status' => 'published',
]);
t($catCreate['ok'], 'Add category save with blank slug succeeds');
$catRow = CategoryRepository::find((int) $catCreate['id']);
$expectedCatSlug = CatalogueSlug::fromName('Mix Veg Curry Pack ' . $tok);
t($catRow !== null && (string) $catRow['slug'] === $expectedCatSlug, 'Add category slug matches CatalogueSlug::fromName');

$prodCreate = ProductRepository::saveGeneral(null, [
    'name' => 'Veg Pulao Mix ' . $tok,
    'slug' => '',
    'status' => 'draft',
]);
t($prodCreate['ok'], 'Add product save with blank slug succeeds');
$prodRow = ProductRepository::find((int) $prodCreate['id']);
$expectedProdSlug = CatalogueSlug::fromName('Veg Pulao Mix ' . $tok);
t($prodRow !== null && (string) $prodRow['slug'] === $expectedProdSlug, 'Product slug auto-generated from name');
t($prodRow !== null && (string) ($prodRow['sku'] ?? '') !== '' && (string) ($prodRow['sku'] ?? '') !== 'VEG-TOM-001', 'Product SKU auto-assigned when blank on create');
t(strpos((string) ($prodRow['sku'] ?? ''), 'PRD-') === 0, 'Auto SKU uses PRD- sequence prefix');

// Manual slug on add
$manualSlug = 'zt-manual-prod-' . $tok;
$prodManual = ProductRepository::saveGeneral(null, [
    'name' => 'Ignored Name ' . $tok,
    'slug' => $manualSlug,
    'slug_manual' => true,
    'status' => 'draft',
]);
t($prodManual['ok'] && (string) ProductRepository::find((int) $prodManual['id'])['slug'] === $manualSlug, 'Add product respects manual slug');

// Edit preserves stored name/slug (simulate loaded defaults)
$catId = (int) $catCreate['id'];
$storedName = (string) $catRow['name'];
$storedSlug = (string) $catRow['slug'];
$catUpdate = CategoryRepository::save($catId, [
    'name' => $storedName,
    'slug' => $storedSlug,
    'slug_manual' => false,
    'status' => 'published',
]);
t($catUpdate['ok'] && (string) CategoryRepository::find($catId)['slug'] === $storedSlug, 'Edit category keeps slug when unchanged in form');

$catRename = CategoryRepository::save($catId, [
    'name' => 'Renamed Category ' . $tok,
    'slug' => $storedSlug,
    'slug_manual' => false,
    'status' => 'published',
]);
t($catRename['ok'] && (string) CategoryRepository::find($catId)['slug'] === $storedSlug, 'Edit category slug unchanged when only name changes but slug field kept');

// UI wiring on both add forms only
t(strpos($catEdit, 'data-at-admin-slug') !== false, 'Category edit form has live slug sync');
t(strpos($prodEdit, 'data-at-admin-slug') !== false, 'Product edit form has live slug sync');

$pdo->exec("DELETE FROM products WHERE slug LIKE 'zt-manual-prod-%' OR slug = " . $pdo->quote($expectedProdSlug));
$pdo->exec("DELETE FROM products WHERE name LIKE 'Veg Pulao Mix%' OR name LIKE 'Ignored Name%'");
$pdo->exec("DELETE FROM categories WHERE slug = " . $pdo->quote($expectedCatSlug));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
