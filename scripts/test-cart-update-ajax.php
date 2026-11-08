<?php

declare(strict_types=1);

/**
 * Cart quantity AJAX regression (POST-only endpoint, extensionless JS URLs, offers refresh payload).
 */

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
$pdo->exec("INSERT INTO categories (name, slug, status) VALUES ('Qty Cat $tok', 'qty-cat-$tok', 'published')");
$catId = (int) $pdo->lastInsertId();
$prod = ProductRepository::saveGeneral(null, [
    'name' => "Qty Cart $tok",
    'slug' => "qty-cart-$tok",
    'status' => 'published',
    'category_id' => (string) $catId,
]);
$pid = (int) $prod['id'];
ProductRepository::saveVariants($pid, [[
    'id' => '', 'name' => '1 kg', 'sku' => 'QC-' . strtoupper($tok), 'price' => 100, 'sale_price' => '',
    'stock_qty' => 50, 'low_stock_threshold' => 5, 'is_active' => 1, 'sort_order' => 0,
]]);
$variants = ProductRepository::variants($pid);
$slug = (string) ProductRepository::find($pid)['slug'];
$vid = (string) $variants[0]['id'];

CartService::clear();
$add = CartService::add($slug, $vid, 2);
t($add['ok'] ?? false, 'Seed cart');
$lines = CartService::items();
$lineKey = (string) ($lines[0]['line_key'] ?? '');
t($lineKey !== '', 'Line key');

$up = CartService::updateQty($lineKey, 3);
t($up['ok'] ?? false, 'updateQty increases quantity');
$payload = CouponOfferService::cartAjaxPayload($up['totals'] ?? DemoCart::totals());
t(isset($payload['totals']['subtotal']), 'cartAjaxPayload includes totals');
t(array_key_exists('offers_html', $payload), 'cartAjaxPayload includes offers_html key');

$stockFail = CartService::updateQty($lineKey, 999);
t(empty($stockFail['ok']) && str_contains((string) ($stockFail['message'] ?? ''), 'available'), 'Stock limit message on excessive qty');

$src = (string) file_get_contents(APNI_TOKRI_ROOT . '/ajax/cart/update.php');
t(str_contains($src, "REQUEST_METHOD'] !== 'POST'") && str_contains($src, 'cartAjaxPayload'), 'update.php POST-only and returns offers payload');

$js = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/cart.js');
t(strpos($js, 'qtyBusy') !== false && strpos($js, 'postCartLine') !== false, 'cart.js duplicate-request guard');
t(strpos($js, "postCartLine('update'") !== false && strpos($js, '/ajax/cart/update.php') === false, 'cart.js extensionless update URL');
t(str_contains($js, 'data-cart-min-order-alert'), 'cart.js updates cart min-order alert');
t(str_contains($js, 'cartMutationSeq'), 'cart.js stale cart AJAX guard');
t(str_contains($js, 'minOrderMetFromTotals'), 'cart.js authoritative min_order_met parsing');
$cartPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/cart.php');
t(str_contains($cartPhp, 'data-cart-min-order-alert'), 'cart.php min-order alert hook for AJAX');

$ht = (string) file_get_contents(APNI_TOKRI_ROOT . '/.htaccess');
t(str_contains($ht, 'REQUEST_METHOD') && str_contains($ht, '!POST'), '.htaccess skips .php redirect for POST');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
