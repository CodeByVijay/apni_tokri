<?php

declare(strict_types=1);

/**
 * Product add/save flow (disposable DB only): validation, SKU, CSRF wiring, form markup.
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
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

$tok = strtolower(bin2hex(random_bytes(4)));
$editPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/products/edit.php');
$adminJs = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/admin.js');

$variantPartial = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/admin/product-variant-row.php');
t(strpos($variantPartial, 'name="variant_name[]"') !== false, 'Variant name inputs present');
t(!preg_match('/name="variant_name\[\]"[^>]*\srequired/', $variantPartial), 'Variant name not HTML5-required (hidden tab blocked submit)');
t(strpos($editPhp, 'data-at-admin-tabbed-form') !== false, 'Product form uses tabbed validation helper');
t(strpos($adminJs, 'initAdminTabbedFormValidation') !== false, 'admin.js clears required on hidden tab panels before submit');
t(strpos($editPhp, 'data-at-submit-once') !== false && strpos($adminJs, 'initSubmitOnce') !== false, 'Double-submit guard on Save product');

// 1. Valid product creation (General only — empty variant row like browser form)
$rOk = ProductRepository::saveGeneral(null, [
    'name' => 'Flow Valid ' . $tok,
    'slug' => '',
    'status' => 'draft',
    'category_id' => '',
    'sku' => '',
]);
t($rOk['ok'] ?? false, 'Valid product creation with empty SKU and no variants');
$idOk = (int) ($rOk['id'] ?? 0);
$rowOk = $idOk > 0 ? ProductRepository::find($idOk) : null;
t($rowOk !== null && (string) $rowOk['name'] === 'Flow Valid ' . $tok, 'Product persisted in database');
$vEmpty = ProductRepository::saveVariants($idOk, [
    ['id' => '', 'name' => '', 'sku' => '', 'price' => 0, 'sale_price' => '', 'stock_qty' => 0, 'low_stock_threshold' => 5],
]);
t($vEmpty['ok'] ?? true, 'Empty variant row skipped without error');

// 2. Missing product name
$rName = ProductRepository::saveGeneral(null, ['name' => '', 'slug' => 'x-' . $tok]);
t(empty($rName['ok']) && !empty($rName['errors']['name']), 'Missing name returns name validation error');

// 3. Missing category (allowed — nullable FK)
$rNoCat = ProductRepository::saveGeneral(null, [
    'name' => 'Flow No Cat ' . $tok,
    'slug' => 'flow-no-cat-' . $tok,
    'category_id' => '',
]);
t($rNoCat['ok'] ?? false, 'Missing category still saves (nullable category_id)');

// 4. Invalid product type
$rBadType = ProductRepository::saveGeneral(null, [
    'name' => 'Flow Bad Type ' . $tok,
    'slug' => 'flow-bad-type-' . $tok,
    'product_type' => 'not_a_real_type_' . $tok,
]);
t(empty($rBadType['ok']) && !empty($rBadType['errors']['product_type']), 'Invalid product type rejected');

// 5. Duplicate SKU
$skuDup = 'DUP-' . strtoupper($tok);
ProductRepository::saveGeneral(null, [
    'name' => 'Flow Dup A ' . $tok,
    'slug' => 'flow-dup-a-' . $tok,
    'sku' => $skuDup,
]);
$rDup = ProductRepository::saveGeneral(null, [
    'name' => 'Flow Dup B ' . $tok,
    'slug' => 'flow-dup-b-' . $tok,
    'sku' => $skuDup,
]);
t(empty($rDup['ok']) && !empty($rDup['errors']['sku']), 'Duplicate SKU rejected');

// 6. CSRF (unit behaviour)
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [];
t(!Csrf::validateRequest(), 'CSRF fails without token in POST');
$_POST[Csrf::fieldName()] = Csrf::token();
t(Csrf::validateRequest(), 'CSRF passes with valid token');

// 7. Database insert failure (invalid category FK) must not return ok
$badCatOk = false;
try {
    $badCat = ProductRepository::saveGeneral(null, [
        'name' => 'Flow Bad Cat ' . $tok,
        'slug' => 'flow-bad-cat-' . $tok,
        'category_id' => '999999999',
    ]);
    $badCatOk = !empty($badCat['ok']);
} catch (Throwable $e) {
    $badCatOk = false;
}
t(!$badCatOk, 'Invalid category id does not silently succeed');

// Cleanup
$slugs = [
    'flow-no-cat-' . $tok,
    'flow-bad-type-' . $tok,
    'flow-dup-a-' . $tok,
    'flow-dup-b-' . $tok,
    'flow-bad-cat-' . $tok,
];
if ($rowOk !== null) {
    $slugs[] = (string) $rowOk['slug'];
}
foreach ($slugs as $s) {
    $pdo->exec('DELETE FROM product_variants WHERE product_id IN (SELECT id FROM products WHERE slug = ' . $pdo->quote($s) . ')');
    $pdo->exec('DELETE FROM products WHERE slug = ' . $pdo->quote($s));
}
$pdo->exec("DELETE FROM products WHERE name LIKE 'Flow %' AND name LIKE '%" . $tok . "'");

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
