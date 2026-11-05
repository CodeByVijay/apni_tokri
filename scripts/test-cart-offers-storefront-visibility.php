<?php

declare(strict_types=1);

/**
 * Coupon storefront visibility (storefront_visible + customer assignments).
 * Run: php7.3 scripts/run-in-disposable-db.php scripts/test-cart-offers-storefront-visibility.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

AppSession::start(App::config()['session'] ?? []);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;

function okV(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function ensureStorefrontColumn(PDO $pdo): void
{
    if (CouponStorefront::hasStorefrontVisibleColumn()) {
        return;
    }
    $pdo->exec(
        'ALTER TABLE coupons ADD COLUMN storefront_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active'
    );
    CouponStorefront::resetSchemaCacheForTests();
}

function mkCoupon(PDO $pdo, string $code, int $visible, array $customerIds = []): int
{
    $pdo->prepare(
        'INSERT INTO coupons (code, internal_name, discount_type, discount_value, is_active, storefront_visible, stackable_with_offers)
         VALUES (?, ?, "fixed", 10, 1, ?, 1)'
    )->execute([$code, 'INTERNAL-' . $code, $visible]);
    $id = (int) $pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO customer_coupon_assignments (coupon_id, customer_id) VALUES (?, ?)');
    foreach ($customerIds as $cid) {
        $ins->execute([$id, $cid]);
    }

    return $id;
}

function codesInOffers(?int $customerId): array
{
    $lines = [['qty' => 2, 'unit_price' => 100.0, 'product_id' => 1, 'category_id' => 1]];
    $offers = CouponOfferService::forCart($lines, $customerId, null);

    return array_column($offers, 'code');
}

ensureStorefrontColumn($pdo);
$tok = strtoupper(bin2hex(random_bytes(2)));
$pdo->exec("INSERT INTO customers (mobile, name) VALUES ('98100{$tok}', 'A')");
$custA = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO customers (mobile, name) VALUES ('98101{$tok}', 'B')");
$custB = (int) $pdo->lastInsertId();

$pub = 'PUB' . $tok;
$hid = 'HID' . $tok;
$asg = 'ASG' . $tok;
mkCoupon($pdo, $pub, 1);
mkCoupon($pdo, $hid, 0);
mkCoupon($pdo, $asg, 1, [$custA]);

$guest = codesInOffers(null);
okV(in_array($pub, $guest, true), 'Public visible coupon listed for guests');
okV(!in_array($hid, $guest, true), 'Manual-only (storefront_visible=0) hidden from offers');
okV(!in_array($asg, $guest, true), 'Assigned coupon hidden from guests');
okV(strpos(implode(',', $guest), 'INTERNAL') === false, 'Internal names not exposed in offer codes');

$_SESSION['customer_id'] = $custA;
$forA = codesInOffers($custA);
okV(in_array($asg, $forA, true), 'Assigned coupon visible in offers for assigned customer');
okV(!in_array($hid, $forA, true), 'Manual-only still hidden for assigned customer');

$_SESSION['customer_id'] = $custB;
$forB = codesInOffers($custB);
okV(!in_array($asg, $forB, true), 'Assigned coupon hidden from other customers');

// Manual code still applies when hidden from offers
CartService::clear();
$pdo->exec("INSERT INTO categories (name, slug, status) VALUES ('T$tok', 't-$tok', 'published')");
$cat = (int) $pdo->lastInsertId();
$prod = ProductRepository::saveGeneral(null, ['name' => "P$tok", 'slug' => "p-$tok", 'status' => 'published', 'category_id' => (string) $cat]);
$pid = (int) $prod['id'];
ProductRepository::saveVariants($pid, [['id' => '', 'name' => '1', 'sku' => 'S' . $tok, 'price' => 100, 'sale_price' => '', 'stock_qty' => 50, 'low_stock_threshold' => 5, 'is_active' => 1, 'sort_order' => 0]]);
$slug = (string) ProductRepository::find($pid)['slug'];
$vid = (string) ProductRepository::variants($pid)[0]['id'];
CartService::add($slug, $vid, 2);
$r = CartCouponHandler::process(['coupon_code' => $hid]);
okV(!empty($r['ok']), 'Manual-only coupon still applies via code entry');

$pdo->exec("DELETE FROM coupons WHERE code IN (" . $pdo->quote($pub) . ',' . $pdo->quote($hid) . ',' . $pdo->quote($asg) . ')');
$pdo->exec("DELETE FROM customers WHERE id IN ($custA, $custB)");
unset($_SESSION['customer_id']);
CartService::clear();

exit($fail > 0 ? 1 : 0);
