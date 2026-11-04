<?php

declare(strict_types=1);

/**
 * Admin product variants & attributes save (disposable DB).
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

t(strpos($editPhp, 'data-at-add-variant') !== false, 'Add variant button in edit form');
t(strpos($editPhp, 'data-at-add-attribute') !== false, 'Add attribute button in edit form');
t(strpos($editPhp, 'product-variant-row.php') !== false, 'Variant row partial used');
t(strpos($editPhp, 'product-attribute-row.php') !== false, 'Attribute row partial used');
t(strpos($adminJs, 'initAdminProductDynamicRows') !== false, 'Dynamic row JS registered');

$create = ProductRepository::saveGeneral(null, [
    'name' => 'Dyn Var ' . $tok,
    'slug' => 'dyn-var-' . $tok,
    'status' => 'draft',
]);
t($create['ok'] ?? false, 'Create base product');
$pid = (int) ($create['id'] ?? 0);

$variantNames = ['250 g', '500 g', '1 kg', '2 kg'];
$variantRows = [];
foreach ($variantNames as $i => $n) {
    $variantRows[] = [
        'id' => '',
        'name' => $n,
        'sku' => 'DV-' . $tok . '-' . $i,
        'price' => 10 + $i * 5,
        'sale_price' => '',
        'stock_qty' => 20 + $i,
        'low_stock_threshold' => 5,
        'is_active' => 1,
        'sort_order' => $i,
    ];
}
$vErr = ProductAdminForm::validateVariantRows($variantRows);
t($vErr === [], 'Four variants pass validation');
$vSave = ProductRepository::saveVariants($pid, $variantRows);
t($vSave['ok'] ?? false, 'Save four variants');
$loaded = ProductRepository::variants($pid);
t(count($loaded) === 4, 'Four variants loaded from DB');

$attrs = [
    ['label' => 'Storage', 'value' => 'Keep refrigerated at 2–5°C'],
    ['label' => 'Cut Type', 'value' => 'Peeled and chopped'],
    ['label' => 'Preparation', 'value' => 'Freshly prepared'],
    ['label' => 'Shelf Life', 'value' => 'Consume as soon as possible'],
];
t(ProductAdminForm::validateAttributesFromPost([
    'attr_label' => array_column($attrs, 'label'),
    'attr_value' => array_column($attrs, 'value'),
]) === [], 'Four attributes pass validation');
ProductRepository::saveAttributes($pid, $attrs);
$loadedAttr = ProductRepository::attributes($pid);
t(count($loadedAttr) === 4, 'Four attributes loaded from DB');

// Add fifth variant + attribute via save
$variantRows[] = [
    'id' => '',
    'name' => '5 kg',
    'sku' => 'DV-' . $tok . '-5',
    'price' => 99,
    'sale_price' => '',
    'stock_qty' => 3,
    'low_stock_threshold' => 2,
    'is_active' => 1,
    'sort_order' => 4,
];
$variantRows = [];
foreach ($loaded as $i => $row) {
    $variantRows[] = [
        'id' => (string) $row['id'],
        'name' => (string) $row['name'],
        'sku' => (string) ($row['sku'] ?? ''),
        'price' => (float) $row['price'],
        'sale_price' => $row['sale_price'],
        'stock_qty' => (int) $row['stock_qty'],
        'low_stock_threshold' => (int) $row['low_stock_threshold'],
        'is_active' => 1,
        'sort_order' => $i,
    ];
}
$keepId = (int) $loaded[0]['id'];
$variantRows[] = [
    'id' => '',
    'name' => '5 kg',
    'sku' => 'DV-' . $tok . '-5',
    'price' => 99,
    'sale_price' => '',
    'stock_qty' => 3,
    'low_stock_threshold' => 2,
    'is_active' => 1,
    'sort_order' => 4,
];
$vSave2 = ProductRepository::saveVariants($pid, $variantRows);
t($vSave2['ok'] ?? false, 'Add fifth variant while keeping first id');
t(count(ProductRepository::variants($pid)) === 5, 'Five variants after add');

$attrs[] = ['label' => 'Origin', 'value' => 'Local farms'];
ProductRepository::saveAttributes($pid, $attrs);
t(count(ProductRepository::attributes($pid)) === 5, 'Five attributes after add');

// Remove one variant (omit from payload) and one attribute
$loaded5 = ProductRepository::variants($pid);
$trimmedVariants = [];
foreach (array_slice($loaded5, 1) as $i => $row) {
    $trimmedVariants[] = [
        'id' => (string) $row['id'],
        'name' => (string) $row['name'],
        'sku' => (string) ($row['sku'] ?? ''),
        'price' => (float) $row['price'],
        'sale_price' => $row['sale_price'],
        'stock_qty' => (int) $row['stock_qty'],
        'low_stock_threshold' => (int) $row['low_stock_threshold'],
        'is_active' => 1,
        'sort_order' => $i,
    ];
}
$vSave3 = ProductRepository::saveVariants($pid, $trimmedVariants);
t($vSave3['ok'] ?? false, 'Save without first variant removes it from DB');
$afterVar = ProductRepository::variants($pid);
t(count($afterVar) === 4, 'Variant count after remove');
$removedStill = $pdo->prepare('SELECT id FROM product_variants WHERE id = ?');
$removedStill->execute([$keepId]);
t($removedStill->fetchColumn() === false, 'Removed variant row deleted from database');

$trimmedAttrs = array_slice($attrs, 1);
ProductRepository::saveAttributes($pid, $trimmedAttrs);
$afterAttr = ProductRepository::attributes($pid);
t(count($afterAttr) === 4, 'Attribute count after remove (replace-all save)');
t(strpos(implode('', array_column($afterAttr, 'attr_key')), 'Storage') === false, 'Removed attribute not in DB');

// Validation: name missing but price set
$bad = ProductAdminForm::validateVariantRows([['name' => '', 'sku' => '', 'price' => '10', 'sale_price' => '', 'stock_qty' => 0]]);
t($bad !== [], 'Server rejects variant row with price but no name');

// SKU uniqueness unchanged
$dupRows = [
    ['id' => '', 'name' => 'A', 'sku' => 'SAME-' . $tok, 'price' => 1, 'sale_price' => '', 'stock_qty' => 0, 'low_stock_threshold' => 5, 'is_active' => 1, 'sort_order' => 0],
    ['id' => '', 'name' => 'B', 'sku' => 'SAME-' . $tok, 'price' => 2, 'sale_price' => '', 'stock_qty' => 0, 'low_stock_threshold' => 5, 'is_active' => 1, 'sort_order' => 1],
];
$dupRes = ProductRepository::saveVariants($pid, $dupRows);
t(empty($dupRes['ok']), 'Duplicate variant SKU still rejected');

$pdo->exec('DELETE FROM product_attributes WHERE product_id = ' . $pid);
$pdo->exec('DELETE FROM product_variants WHERE product_id = ' . $pid);
$pdo->exec('DELETE FROM products WHERE id = ' . $pid);

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
