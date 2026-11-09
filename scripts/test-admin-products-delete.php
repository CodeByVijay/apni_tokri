<?php

declare(strict_types=1);

/**
 * Products admin delete/archive + flash markup (disposable DB).
 * Run: php scripts/run-in-disposable-db.php scripts/test-admin-products-delete.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/lib/test_script_safety.php';
require_once dirname(__DIR__) . '/includes/admin/list_actions.php';
require_once dirname(__DIR__) . '/includes/partials/admin/flash-messages.php';

$fail = 0;
function okPd(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

TestScriptSafety::assertDisposableDatabaseOrAbort();
Install::ensureConnection();
$pdo = Database::pdo();
$tok = bin2hex(random_bytes(3));
$ptype = ProductTypeRepository::defaultCode();

$pdo->exec("INSERT INTO categories (name, slug, status, sort_order) VALUES ('Del Cat', 'del-cat-{$tok}', 'published', 1)");
$catId = (int) $pdo->lastInsertId();

$slugDel = "del-prod-{$tok}";
$skuDel = "DEL-SKU-{$tok}";
$pdo->prepare(
    'INSERT INTO products (name, slug, sku, category_id, product_type, status, short_description, description, meta_title, meta_description, robots_index)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    'Delete Me ' . $tok, $slugDel, $skuDel, $catId, $ptype, 'published',
    'Short', 'Full', 'Meta', 'Desc', 1,
]);
$productDelId = (int) $pdo->lastInsertId();

$resDel = ProductRepository::deleteOrArchiveForAdmin($productDelId);
okPd(!empty($resDel['ok']) && empty($resDel['archived']), 'product without orders deletes permanently');
okPd(
    strpos((string) ($resDel['message'] ?? ''), 'deleted successfully') !== false,
    'delete success message wording'
);
okPd(ProductRepository::find($productDelId) === null, 'deleted product row removed');

$slugArc = "arc-prod-{$tok}";
$skuArc = "ARC-SKU-{$tok}";
$pdo->prepare(
    'INSERT INTO products (name, slug, sku, category_id, product_type, status, short_description, description, meta_title, meta_description, robots_index)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    'Archive Me ' . $tok, $slugArc, $skuArc, $catId, $ptype, 'published',
    'Short', 'Full', 'Meta', 'Desc', 1,
]);
$productArcId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO product_variants (product_id, name, sku, price, stock_qty, low_stock_threshold, is_active, sort_order)
     VALUES (?, ?, ?, 50, 10, 2, 1, 1)'
)->execute([$productArcId, '500 g', 'ARC-V-' . $tok]);
$variantId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute(['98' . substr($tok, 0, 8), 'Del test']);
$customerId = (int) $pdo->lastInsertId();
$orderNo = 'AT-DEL-' . strtoupper($tok);
$pdo->prepare(
    'INSERT INTO orders (order_number, order_source, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, currency, payment_status, placed_at)
     VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, ?, NOW())'
)->execute([$orderNo, 'storefront', $customerId, 'delivered', 50.0, 50.0, 'INR', 'paid']);
$orderId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, quantity, unit_price, line_total, sku)
     VALUES (?, ?, ?, ?, 1, 50, 50, ?)'
)->execute([$orderId, $variantId, 'Archive Me', '500 g', $skuArc]);

$resArc = ProductRepository::deleteOrArchiveForAdmin($productArcId);
okPd(!empty($resArc['ok']) && !empty($resArc['archived']), 'product with orders archives instead of delete');
okPd(
    strpos((string) ($resArc['message'] ?? ''), 'archived') !== false,
    'archive warning message mentions archived'
);
$rowArc = ProductRepository::find($productArcId);
okPd($rowArc !== null && ($rowArc['status'] ?? '') === 'archived', 'product status set to archived');

$simFlashType = !empty($resArc['archived']) ? 'warning' : 'success';
okPd($simFlashType === 'warning', 'list delete handler would use warning flash for archive');

$htmlSuccess = admin_flash_render_html([['type' => 'success', 'message' => 'Product created successfully.']]);
okPd(strpos($htmlSuccess, 'data-at-admin-flash-autodismiss="6000"') !== false, 'success flash autodismiss attribute');
okPd(strpos($htmlSuccess, 'at-alert--success') !== false && strpos($htmlSuccess, 'm8.5 12.5 3 3 6-6') !== false, 'success flash styling and icon');

$htmlError = admin_flash_render_html([['type' => 'error', 'message' => '<script>x</script>']]);
okPd(strpos($htmlError, 'data-at-admin-flash-autodismiss') === false, 'error flash is not autodismissed');
okPd(strpos($htmlError, 'M12 8v4') !== false, 'error flash uses alert-circle icon');
okPd(strpos($htmlError, '&lt;script&gt;') !== false, 'flash message escaped');

$form = admin_delete_form('product', 99, 'Demo "Garlic"', 1, 25, '');
okPd(strpos($form, 'data-at-product-delete-form') !== false, 'product delete form marker');
okPd(strpos($form, 'data-at-product-delete-trigger') !== false, 'product delete trigger button');
okPd(strpos($form, 'type="button"') !== false && strpos($form, 'Delete product') !== false, 'delete trigger is button with tooltip');
okPd(strpos($form, 'onsubmit=') === false, 'product delete form has no inline confirm');

$indexPhp = file_get_contents(APNI_TOKRI_ROOT . '/admin/products/index.php') ?: '';
okPd(strpos($indexPhp, 'at-product-delete-modal') !== false, 'products index includes delete modal');
okPd(strpos($indexPhp, 'Delete product?') !== false, 'modal title present');

$js = file_get_contents(APNI_TOKRI_ROOT . '/assets/js/admin-products-list.js') ?: '';
okPd(strpos($js, 'Deleting...') !== false && strpos($js, 'deleting = true') !== false, 'delete modal loading guard');

echo "\nTotal failures: {$fail}\n";
exit($fail > 0 ? 1 : 0);
