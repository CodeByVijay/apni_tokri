<?php

declare(strict_types=1);

/**
 * Cart add regression: non-positive display prices cannot enter either cart backend.
 *
 * php scripts/run-in-disposable-db.php scripts/test-cart-price-validation.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();

$fail = 0;
$check = static function (bool $condition, string $label): void {
    global $fail;
    echo ($condition ? 'OK' : 'FAIL') . ': ' . $label . PHP_EOL;
    if (!$condition) {
        ++$fail;
    }
};

$pdo = Database::pdo();
$categoryId = (int) $pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$check($categoryId > 0, 'seeded category available');
if ($categoryId <= 0) {
    exit(1);
}

$slug = 'tmp-cart-price-guard';
$sku = 'TMP-CART-PRICE-GUARD';
$pdo->prepare("INSERT INTO products (category_id, name, slug, sku, status) VALUES (?, 'Cart Price Guard', ?, ?, 'published')")
    ->execute([$categoryId, $slug, $sku]);
$productId = (int) $pdo->lastInsertId();

$variants = [
    'zero' => ['label' => 'Zero price', 'price' => 0, 'sale_price' => null, 'stock' => 10],
    'negative' => ['label' => 'Negative display', 'price' => -10, 'sale_price' => null, 'stock' => 10],
    'positive' => ['label' => 'Positive regular', 'price' => 100, 'sale_price' => null, 'stock' => 10],
    'sale' => ['label' => 'Valid sale', 'price' => 100, 'sale_price' => 80, 'stock' => 10],
    'invalid_sale' => ['label' => 'Invalid sale fallback', 'price' => 100, 'sale_price' => 150, 'stock' => 10],
];
$variantIds = [];
foreach ($variants as $key => $v) {
    $pdo->prepare(
        'INSERT INTO product_variants (product_id, name, sku, price, sale_price, stock_qty, is_active, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
    )->execute([
        $productId,
        $v['label'],
        $sku . '-' . strtoupper($key),
        $v['price'],
        $v['sale_price'],
        $v['stock'],
        count($variantIds),
    ]);
    $variantIds[$key] = (int) $pdo->lastInsertId();
}

CatalogueGateway::reset();
$check(AppEnvironment::usePersistedStorefront(), 'database-backed cart path selected');

$cartItemsFor = static function (int $variantId) use ($pdo): array {
    $stmt = $pdo->prepare(
        'SELECT ci.quantity, ci.unit_price FROM cart_items ci
         INNER JOIN carts c ON c.id = ci.cart_id
         WHERE ci.variant_id = ? AND c.session_id = ?'
    );
    $stmt->execute([$variantId, (string) ($_SESSION['guest_cart_sid'] ?? '')]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

foreach (['zero', 'negative'] as $key) {
    $before = $cartItemsFor($variantIds[$key]);
    $result = CartService::add($slug, (string) $variantIds[$key], 1);
    $check(empty($result['ok']), 'db rejects ' . $key . ' display price');
    $check($cartItemsFor($variantIds[$key]) === $before, 'db does not insert ' . $key . ' display price item');
}

$expectedDbPrices = [
    'positive' => 100.0,
    'sale' => 80.0,
    'invalid_sale' => 100.0,
];
foreach ($expectedDbPrices as $key => $expectedPrice) {
    $result = CartService::add($slug, (string) $variantIds[$key], 1);
    $items = $cartItemsFor($variantIds[$key]);
    $check(!empty($result['ok']), 'db accepts ' . $key . ' variant');
    $check(
        count($items) === 1 && abs((float) $items[0]['unit_price'] - $expectedPrice) < 0.001,
        'db stores expected unit price for ' . $key
    );
}

$_SESSION['demo_cart'] = ['items' => [], 'coupon_code' => null];
$sessionItemsFor = static function (int $variantId): array {
    return array_values(array_filter(
        $_SESSION['demo_cart']['items'] ?? [],
        static fn (array $item): bool => (string) ($item['variant_id'] ?? '') === (string) $variantId
    ));
};

foreach (['zero', 'negative'] as $key) {
    $before = $sessionItemsFor($variantIds[$key]);
    $result = SessionCartBackend::add($slug, (string) $variantIds[$key], 1);
    $check(empty($result['ok']), 'session rejects ' . $key . ' display price');
    $check($sessionItemsFor($variantIds[$key]) === $before, 'session does not store ' . $key . ' display price item');
}

foreach ($expectedDbPrices as $key => $expectedPrice) {
    $result = SessionCartBackend::add($slug, (string) $variantIds[$key], 1);
    $items = $sessionItemsFor($variantIds[$key]);
    $check(!empty($result['ok']), 'session accepts ' . $key . ' variant');
    $check(
        count($items) === 1 && abs((float) $items[0]['unit_price'] - $expectedPrice) < 0.001,
        'session stores expected unit price for ' . $key
    );
}

echo json_encode([
    'ok' => $fail === 0,
    'product_slug' => $slug,
    'variant_ids' => $variantIds,
    'failures' => $fail,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if ($fail > 0) {
    exit(1);
}
