<?php

declare(strict_types=1);

/**
 * Checkout with applied coupon (simulated payment only).
 * Run: php7.3 scripts/run-in-disposable-db.php scripts/test-checkout-coupon-simulate.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

AppSession::start(App::config()['session'] ?? []);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;

function okCh(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

AppSettings::set('payment.active_gateway', 'simulate');
AppSettings::resetCache();

$tok = strtolower(bin2hex(random_bytes(2)));
$pdo->exec("INSERT INTO customers (mobile, name) VALUES ('98200$tok', 'Checkout')");
$cust = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO customer_addresses (customer_id, label, receiver_name, receiver_phone, line1, city, state, pincode, is_default)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
)->execute([$cust, 'Home', 'Checkout Test', '9876543210', 'Line 1', 'Indore', 'MP', '452001']);
$addr = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO categories (name, slug, status) VALUES ('CH$tok', 'ch-$tok', 'published')");
$cat = (int) $pdo->lastInsertId();
$prod = ProductRepository::saveGeneral(null, ['name' => "CH$tok", 'slug' => "ch-$tok", 'status' => 'published', 'category_id' => (string) $cat]);
$pid = (int) $prod['id'];
ProductRepository::saveVariants($pid, [['id' => '', 'name' => '1 kg', 'sku' => 'CH' . strtoupper($tok), 'price' => 200, 'sale_price' => '', 'stock_qty' => 100, 'low_stock_threshold' => 5, 'is_active' => 1, 'sort_order' => 0]]);
$slug = (string) ProductRepository::find($pid)['slug'];
$vid = (string) ProductRepository::variants($pid)[0]['id'];

$code = 'CHCK' . strtoupper($tok);
$pdo->exec("INSERT INTO coupons (code, internal_name, discount_type, discount_value, min_cart_value, is_active, stackable_with_offers)
    VALUES (" . $pdo->quote($code) . ", 't', 'percent', 10, 0, 1, 1)");

$_SESSION['customer_id'] = $cust;
CartService::clear();
CartService::add($slug, $vid, 2);
$apply = CartCouponHandler::process(['coupon_code' => $code]);
okCh(!empty($apply['ok']), 'Coupon applies before checkout');
$totals = CartService::totals();
$sub = (float) $totals['subtotal'];
$cDisc = (float) $totals['coupon_discount'];
okCh($cDisc > 0 && abs($cDisc - round($sub * 0.10, 2)) < 0.02, 'Coupon discount matches server calculation');
okCh((string) $totals['coupon_code'] === $code, 'Totals include coupon code');

$slot = DeliveryConfig::slotsEnabled() ? (string) ((DeliverySlotService::availableForCheckout()[0]['slug'] ?? '') ?: 'auto') : 'auto';
$nonce = CheckoutService::freshNonce();
$placed = CheckoutService::placeOrder([
    'checkout_nonce' => $nonce,
    'address_id' => (string) $addr,
    'delivery_slot' => $slot,
    'terms' => '1',
    'payment_sim' => 'success',
]);
okCh(!empty($placed['ok']), 'Checkout placeOrder succeeds with simulated payment');
$orderNo = (string) ($placed['order_number'] ?? '');
$ord = $pdo->prepare('SELECT subtotal, discount_total, delivery_fee, grand_total, coupon_code FROM orders WHERE order_number = ?');
$ord->execute([$orderNo]);
$row = $ord->fetch(PDO::FETCH_ASSOC);
okCh(is_array($row), 'Order row loaded');
okCh((string) $row['coupon_code'] === $code, 'Order stores coupon_code');
okCh((float) $row['discount_total'] >= $cDisc - 0.01, 'Order discount_total includes coupon savings');

// Expired coupon cannot checkout
CartService::clear();
CartService::add($slug, $vid, 2);
CartCouponHandler::process(['coupon_code' => $code]);
$pdo->exec('UPDATE coupons SET ends_at = \'2020-01-01 00:00:00\' WHERE code = ' . $pdo->quote($code));
$badTotals = CartService::totals();
okCh(empty($badTotals['coupon_code']), 'Expired coupon cleared from cart on totals()');
$nonce2 = CheckoutService::freshNonce();
$blocked = CheckoutService::placeOrder([
    'checkout_nonce' => $nonce2,
    'address_id' => (string) $addr,
    'delivery_slot' => $slot,
    'terms' => '1',
    'payment_sim' => 'success',
]);
// If cart had no invalid coupon, order may still proceed without discount — ensure no stale coupon on order
if (!empty($blocked['ok'])) {
    $ord2 = $pdo->prepare('SELECT coupon_code, discount_total FROM orders WHERE order_number = ?');
    $ord2->execute([$blocked['order_number'] ?? '']);
    $r2 = $ord2->fetch(PDO::FETCH_ASSOC);
    okCh(empty($r2['coupon_code']) || (float) $r2['discount_total'] < 0.01, 'Expired coupon not applied on new order');
} else {
    okCh(true, 'Checkout blocked or cart invalid after expired coupon');
}

CartService::clear();
$pdo->exec("DELETE FROM coupons WHERE code = " . $pdo->quote($code));
$pdo->exec("DELETE FROM customers WHERE id = $cust");

exit($fail > 0 ? 1 : 0);
