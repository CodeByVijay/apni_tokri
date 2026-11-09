<?php

declare(strict_types=1);

/**
 * Admin product attribute & variant display order (disposable DB).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/product_admin_form.php';

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

$tok = strtolower(bin2hex(random_bytes(4)));
$editPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/products/edit.php');
$adminJs = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/admin.js');
$attrPartial = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/admin/product-attribute-row.php');
$varPartial = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/admin/product-variant-row.php');

t(strpos($editPhp, 'data-at-product-attribute-reorder') !== false, 'Attributes reorder root marker');
t(strpos($editPhp, 'data-at-product-variant-reorder') !== false, 'Variants reorder root marker');
t(strpos($adminJs, 'initProductInlineReorder') !== false, 'Inline product reorder init in admin.js');
t(strpos($attrPartial, 'data-at-product-reorder-drag-handle') !== false, 'Attribute drag handle');
t(strpos($attrPartial, 'data-at-product-reorder-up') !== false, 'Attribute up control');
t(strpos($varPartial, 'data-at-product-reorder-seq') !== false, 'Variant position column');

$create = ProductRepository::saveGeneral(null, [
    'name' => 'Reorder ' . $tok,
    'slug' => 'reorder-' . $tok,
    'status' => 'draft',
]);
t($create['ok'] ?? false, 'Create base product');
$pid = (int) ($create['id'] ?? 0);

$variantRows = [
    ['id' => '', 'name' => 'Small', 'sku' => 'RO-' . $tok . '-s', 'price' => 10, 'sale_price' => '', 'stock_qty' => 5, 'low_stock_threshold' => 2, 'is_active' => 1],
    ['id' => '', 'name' => 'Medium', 'sku' => 'RO-' . $tok . '-m', 'price' => 20, 'sale_price' => '', 'stock_qty' => 5, 'low_stock_threshold' => 2, 'is_active' => 1],
    ['id' => '', 'name' => 'Large', 'sku' => 'RO-' . $tok . '-l', 'price' => 30, 'sale_price' => '', 'stock_qty' => 5, 'low_stock_threshold' => 2, 'is_active' => 1],
];
t((ProductRepository::saveVariants($pid, $variantRows))['ok'] ?? false, 'Save three variants');
$loaded = ProductRepository::variants($pid);
t(array_column($loaded, 'name') === ['Small', 'Medium', 'Large'], 'Variants load in insert order');

$reordered = [
    [
        'id' => (string) $loaded[2]['id'],
        'name' => 'Large',
        'sku' => (string) $loaded[2]['sku'],
        'price' => (float) $loaded[2]['price'],
        'sale_price' => $loaded[2]['sale_price'],
        'stock_qty' => (int) $loaded[2]['stock_qty'],
        'low_stock_threshold' => (int) $loaded[2]['low_stock_threshold'],
        'is_active' => 1,
    ],
    [
        'id' => (string) $loaded[0]['id'],
        'name' => 'Small',
        'sku' => (string) $loaded[0]['sku'],
        'price' => (float) $loaded[0]['price'],
        'sale_price' => $loaded[0]['sale_price'],
        'stock_qty' => (int) $loaded[0]['stock_qty'],
        'low_stock_threshold' => (int) $loaded[0]['low_stock_threshold'],
        'is_active' => 1,
    ],
    [
        'id' => (string) $loaded[1]['id'],
        'name' => 'Medium',
        'sku' => (string) $loaded[1]['sku'],
        'price' => (float) $loaded[1]['price'],
        'sale_price' => $loaded[1]['sale_price'],
        'stock_qty' => (int) $loaded[1]['stock_qty'],
        'low_stock_threshold' => (int) $loaded[1]['low_stock_threshold'],
        'is_active' => 1,
    ],
];
t((ProductRepository::saveVariants($pid, $reordered))['ok'] ?? false, 'Save reordered variants');
$afterVar = ProductRepository::variants($pid);
t(array_column($afterVar, 'name') === ['Large', 'Small', 'Medium'], 'Variants persist reordered names sequence');
t((int) ($afterVar[0]['sort_order'] ?? -1) === 1 && (int) ($afterVar[2]['sort_order'] ?? -1) === 3, 'Variant sort_order is contiguous from 1');
$t((int) $afterVar[0]['id'] === (int) $loaded[2]['id'], 'Reorder keeps variant IDs');
$t((float) $afterVar[1]['price'] === (float) $loaded[0]['price'], 'Reorder keeps variant prices');

$attrs = [
    ['label' => 'Alpha', 'value' => 'A'],
    ['label' => 'Beta', 'value' => 'B'],
    ['label' => 'Gamma', 'value' => 'C'],
];
ProductRepository::saveAttributes($pid, $attrs);
$loadedAttr = ProductRepository::attributes($pid);
t(array_column($loadedAttr, 'attr_key') === ['Alpha', 'Beta', 'Gamma'], 'Attributes load in save order');

$attrsReordered = [
    ['label' => 'Gamma', 'value' => 'C'],
    ['label' => 'Alpha', 'value' => 'A'],
    ['label' => 'Beta', 'value' => 'B'],
];
ProductRepository::saveAttributes($pid, $attrsReordered);
$afterAttr = ProductRepository::attributes($pid);
t(array_column($afterAttr, 'attr_key') === ['Gamma', 'Alpha', 'Beta'], 'Attributes persist reordered sequence');
t((int) ($afterAttr[0]['sort_order'] ?? -1) === 1 && (int) ($afterAttr[2]['sort_order'] ?? -1) === 3, 'Attribute sort_order contiguous from 1');
t((string) ($afterAttr[1]['attr_value'] ?? '') === 'A', 'Attribute values unchanged after reorder');

$row = ProductRepository::find($pid);
$store = $row !== null ? ProductRepository::toPublicArray($row) : [];
$t(array_column($store['variants'] ?? [], 'label') === ['Large', 'Small', 'Medium'], 'Storefront variant order matches sort_order');
$t(array_column($store['attributes'] ?? [], 'label') === ['Gamma', 'Alpha', 'Beta'], 'Storefront attribute order matches sort_order');

$bogus = ProductRepository::saveVariants($pid, [
    [
        'id' => '999999991',
        'name' => 'Bad',
        'sku' => 'RO-' . $tok . '-bad',
        'price' => 1,
        'sale_price' => '',
        'stock_qty' => 0,
        'low_stock_threshold' => 1,
        'is_active' => 1,
    ],
]);
t(empty($bogus['ok']), 'Foreign variant id rejected on save');

$pdo->exec('DELETE FROM product_attributes WHERE product_id = ' . $pid);
$pdo->exec('DELETE FROM product_variants WHERE product_id = ' . $pid);
$pdo->exec('DELETE FROM products WHERE id = ' . $pid);

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
