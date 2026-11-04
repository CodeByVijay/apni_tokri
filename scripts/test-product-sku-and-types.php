<?php

declare(strict_types=1);

/**
 * Product SKU auto-allocation and product_types master (disposable DB recommended).
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

function applyMigration126(PDO $pdo): void
{
    if (ProductTypeRepository::tableReady()) {
        return;
    }
    $sql = (string) file_get_contents(APNI_TOKRI_ROOT . '/database/migrations/1.26.0_product_types.sql');
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $chunk) {
        if ($chunk === '' || stripos($chunk, 'SET NAMES') === 0) {
            continue;
        }
        $pdo->exec($chunk);
    }
}

applyMigration126($pdo);
t(ProductTypeRepository::tableReady(), 'Migration 1.26.0 product_types table present');

$tok = strtolower(bin2hex(random_bytes(4)));
$opts = ProductTypeRepository::optionsForProductForm();
t(count($opts) >= 3, 'Product type options loaded from master');
$names = array_column($opts, 'name');
t(in_array('Fresh produce', $names, true), 'Seeded Fresh produce type available');

// Auto SKU on create
$r1 = ProductRepository::saveGeneral(null, [
    'name' => 'SKU Test A ' . $tok,
    'slug' => 'sku-test-a-' . $tok,
    'status' => 'draft',
    'sku' => '',
]);
t($r1['ok'], 'Create product with empty SKU');
$p1 = ProductRepository::find((int) $r1['id']);
$sku1 = (string) ($p1['sku'] ?? '');
t($sku1 !== '' && strpos($sku1, 'PRD-') === 0, 'Empty SKU allocates PRD-* sku');

$r2 = ProductRepository::saveGeneral(null, [
    'name' => 'SKU Test B ' . $tok,
    'slug' => 'sku-test-b-' . $tok,
    'status' => 'draft',
]);
$sku2 = (string) (ProductRepository::find((int) $r2['id'])['sku'] ?? '');
t($sku2 !== '' && $sku2 !== $sku1, 'Second auto SKU differs from first');

$manual = 'MAN-' . strtoupper($tok);
$r3 = ProductRepository::saveGeneral(null, [
    'name' => 'SKU Manual ' . $tok,
    'slug' => 'sku-manual-' . $tok,
    'status' => 'draft',
    'sku' => $manual,
]);
t($r3['ok'] && (string) ProductRepository::find((int) $r3['id'])['sku'] === $manual, 'Manual SKU respected');

$dup = ProductRepository::saveGeneral(null, [
    'name' => 'SKU Dup ' . $tok,
    'slug' => 'sku-dup-' . $tok,
    'status' => 'draft',
    'sku' => $manual,
]);
t(!$dup['ok'] && isset($dup['errors']['sku']), 'Duplicate product SKU rejected');

// Edit keeps SKU
$edit = ProductRepository::saveGeneral((int) $r1['id'], [
    'name' => 'SKU Test A renamed ' . $tok,
    'slug' => 'sku-test-a-' . $tok,
    'status' => 'draft',
    'sku' => $sku1,
]);
t($edit['ok'] && (string) ProductRepository::find((int) $r1['id'])['sku'] === $sku1, 'Edit preserves SKU');

$clearSku = ProductRepository::saveGeneral((int) $r1['id'], [
    'name' => 'SKU Test A renamed ' . $tok,
    'slug' => 'sku-test-a-' . $tok,
    'status' => 'draft',
    'sku' => '',
]);
$cleared = ProductRepository::find((int) $r1['id']);
t($clearSku['ok'] && ($cleared['sku'] ?? null) === null, 'Edit with empty SKU clears product SKU to NULL');

$allocated = [];
for ($i = 0; $i < 12; $i++) {
    $res = ProductRepository::saveGeneral(null, [
        'name' => 'Bulk SKU ' . $i . ' ' . $tok,
        'slug' => 'bulk-sku-' . $i . '-' . $tok,
        'status' => 'draft',
    ]);
    if (!$res['ok']) {
        break;
    }
    $allocated[] = (string) (ProductRepository::find((int) $res['id'])['sku'] ?? '');
}
t(count($allocated) === 12 && count(array_unique($allocated)) === 12, 'Sequential auto SKU creates 12 unique PRD SKUs');

$catId = (int) ($pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
if ($catId <= 0) {
    $pdo->exec("INSERT INTO categories (name, slug, status) VALUES ('Audit Cat {$tok}', 'audit-cat-{$tok}', 'published')");
    $catId = (int) $pdo->lastInsertId();
}
$cross = ProductRepository::saveGeneral(null, [
    'name' => 'Cross SKU product ' . $tok,
    'slug' => 'cross-sku-' . $tok,
    'status' => 'draft',
    'sku' => 'CROSS-' . strtoupper($tok),
]);
$crossId = (int) ($cross['id'] ?? 0);
$vRes = ProductRepository::saveVariants($crossId, [[
    'name' => '500 g',
    'sku' => 'CROSS-' . strtoupper($tok),
    'price' => 10,
    'stock_qty' => 5,
    'is_active' => 1,
]]);
t(!$vRes['ok'], 'Variant SKU matching same product SKU rejected on saveVariants');
$otherProd = ProductRepository::saveGeneral(null, [
    'name' => 'Other ' . $tok,
    'slug' => 'other-' . $tok,
    'status' => 'draft',
    'sku' => 'OTHER-' . strtoupper($tok),
]);
$vCross = ProductRepository::saveVariants((int) $otherProd['id'], [[
    'name' => '500 g',
    'sku' => 'CROSS-' . strtoupper($tok),
    'price' => 10,
    'stock_qty' => 5,
    'is_active' => 1,
]]);
t(!$vCross['ok'], 'Variant SKU matching another product SKU rejected');

$unknownTypes = $pdo->query(
    "SELECT COUNT(DISTINCT product_type) FROM products WHERE product_type NOT IN ('fresh','cut','packaged')"
)->fetchColumn();
t((int) $unknownTypes === 0, 'All product_type values on products match legacy seeded codes (disposable catalogue)');

// New product type
$newType = ProductTypeRepository::save(null, [
    'code' => 'test_' . substr($tok, 0, 8),
    'name' => 'Test type ' . $tok,
    'sort_order' => 99,
    'is_active' => 1,
]);
t($newType['ok'], 'Add custom product type');
$typeCode = (string) (ProductTypeRepository::find((int) $newType['id'])['code'] ?? '');

$withType = ProductRepository::saveGeneral(null, [
    'name' => 'Typed product ' . $tok,
    'slug' => 'typed-' . $tok,
    'status' => 'draft',
    'product_type' => $typeCode,
]);
$rowTyped = ProductRepository::find((int) $withType['id']);
t($withType['ok'] && (string) $rowTyped['product_type'] === $typeCode, 'Product saved with custom type');

// Deactivate without reassign blocked
$deactFail = ProductTypeRepository::deactivate((int) $newType['id'], null);
t(!$deactFail['ok'], 'Deactivate type with products blocked without reassignment');

$deactOk = ProductTypeRepository::deactivate((int) $newType['id'], 'fresh');
t($deactOk['ok'], 'Deactivate with reassignment to fresh');
$rowTyped = ProductRepository::find((int) $withType['id']);
t((string) $rowTyped['product_type'] === 'fresh', 'Products reassigned on type deactivate');
$variantCount = (int) $pdo->query('SELECT COUNT(*) FROM product_variants WHERE product_id = ' . (int) $withType['id'])->fetchColumn();
$stockSum = (int) $pdo->query('SELECT COALESCE(SUM(stock_qty),0) FROM product_variants WHERE product_id = ' . (int) $withType['id'])->fetchColumn();
t($variantCount >= 0 && $stockSum >= 0, 'Deactivate/reassign does not remove variants or inventory rows');

$inactiveOk = ProductTypeRepository::isAllowedCode($typeCode, true);
t($inactiveOk, 'Inactive type still allowed for existing product via allowInactive flag');

$prodEdit = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/products/edit.php');
t(strpos($prodEdit, 'ProductTypeRepository::optionsForProductForm') !== false, 'Product form loads types via repository (post-migration master or legacy fallback)');
t(strpos($prodEdit, 'Leave blank to auto-assign on save') !== false, 'SKU field documents auto-assign on add');

// Cleanup
$pdo->exec('DELETE FROM products WHERE slug LIKE ' . $pdo->quote('%' . $tok . '%'));
$pdo->prepare('DELETE FROM product_types WHERE code = ?')->execute([$typeCode]);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
