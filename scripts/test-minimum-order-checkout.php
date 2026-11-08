<?php

declare(strict_types=1);

/**
 * Configurable minimum order amount (marketing settings + CheckoutService).
 * CLI: php scripts/run-in-disposable-db.php scripts/test-minimum-order-checkout.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/catalogue/helpers.php';

$fail = 0;
$ok = static function (bool $c, string $m) use (&$fail): void {
    echo ($c ? 'PASS: ' : 'FAIL: ') . $m . "\n";
    if (!$c) {
        $fail++;
    }
};

$marketing = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/settings/marketing.php');
$checkout = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/commerce/checkout.php');
$pricing = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/commerce/pricing.php');
$sub = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/subscription/checkout.php');
$admin = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/marketing/index.php');

$ok(str_contains($marketing, 'KEY_MIN_ORDER_ENABLED'), 'static: min order settings keys');
$ok(str_contains($checkout, 'checkoutMinimumRejection'), 'static: checkout enforces minimum');
$ok(str_contains($pricing, 'attachMinimumOrderTotals'), 'static: totals include minimum order fields');
$ok(str_contains($admin, 'Minimum order amount'), 'static: admin marketing card');
$ok(!str_contains($sub, 'checkoutMinimumRejection'), 'static: subscription checkout not gated by min order');
$ok(str_contains((string) file_get_contents(APNI_TOKRI_ROOT . '/ajax/checkout/quote.php'), 'min_order_met'), 'static: quote AJAX respects minimum');

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "SKIP DB: schema not ready.\n");
    exit($fail > 0 ? 1 : 0);
}

AppSession::start(App::config()['session'] ?? []);
Install::ensureConnection();
$pdo = Database::pdo();
AppSettings::set('payment.active_gateway', 'simulate');
AppSettings::resetCache();

$saveMinOff = static function () use ($pdo): void {
    AppSettings::set(MarketingSettings::KEY_MIN_ORDER_ENABLED, '0');
    AppSettings::resetCache();
};
$saveMinOn = static function (float $min, string $msg) use ($pdo): void {
    AppSettings::set(MarketingSettings::KEY_MIN_ORDER_ENABLED, '1');
    AppSettings::set(MarketingSettings::KEY_MIN_ORDER_AMOUNT_INR, (string) $min);
    AppSettings::set(MarketingSettings::KEY_MIN_ORDER_MESSAGE, $msg);
    AppSettings::resetCache();
};

$tok = strtolower(bin2hex(random_bytes(2)));
$pdo->exec("INSERT INTO customers (mobile, name) VALUES ('98110{$tok}', 'MinOrd')");
$cust = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO customer_addresses (customer_id, label, receiver_name, receiver_phone, line1, city, state, pincode, is_default)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
)->execute([$cust, 'Home', 'Min Test', '9876543210', 'Line 1', 'Indore', 'MP', '452001']);
$addr = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO categories (name, slug, status) VALUES ('MO$tok', 'mo-$tok', 'published')");
$cat = (int) $pdo->lastInsertId();

$makeProduct = static function (float $price, string $suffix) use ($pdo, $cat, $tok): array {
    $slug = "mo-$tok-$suffix";
    $prod = ProductRepository::saveGeneral(null, ['name' => "MO$suffix", 'slug' => $slug, 'status' => 'published', 'category_id' => (string) $cat]);
    $pid = (int) $prod['id'];
    ProductRepository::saveVariants($pid, [[
        'id' => '',
        'name' => '1 kg',
        'sku' => 'MO' . strtoupper($suffix),
        'price' => $price,
        'sale_price' => '',
        'stock_qty' => 100,
        'low_stock_threshold' => 5,
        'is_active' => 1,
        'sort_order' => 0,
    ]]);
    $slug = (string) ProductRepository::find($pid)['slug'];
    $vid = (string) ProductRepository::variants($pid)[0]['id'];

    return ['slug' => $slug, 'vid' => $vid, 'price' => $price];
};

$low = $makeProduct(80.0, 'low');
$exact = $makeProduct(149.0, 'ex');
$high = $makeProduct(200.0, 'hi');
$couponCode = 'MIN' . strtoupper($tok);
$pdo->exec("INSERT INTO coupons (code, internal_name, discount_type, discount_value, min_cart_value, is_active, stackable_with_offers)
    VALUES ({$pdo->quote($couponCode)}, 't', 'fixed', 70, 0, 1, 1)");

$_SESSION['customer_id'] = $cust;

$slotRow = DeliveryConfig::slotsEnabled() ? (DeliverySlotService::availableForCheckout()[0] ?? null) : null;
$slot = $slotRow !== null ? (string) ($slotRow['slug'] ?? 'auto') : 'auto';
$slotDate = $slotRow !== null ? (string) ($slotRow['delivery_date'] ?? '') : '';

$place = static function (int $addressId) use ($slot, $slotDate): array {
    $nonce = CheckoutService::freshNonce();

    return CheckoutService::placeOrder([
        'checkout_nonce' => $nonce,
        'address_id' => (string) $addressId,
        'delivery_slot' => $slot,
        'delivery_date' => $slotDate,
        'terms' => '1',
        'payment_sim' => 'success',
    ]);
};

$countOrders = static function () use ($pdo, $cust): int {
    return (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE customer_id = ' . (int) $cust)->fetchColumn();
};

$msg = 'Minimum order value after discounts is {amount}.';

// 1. Disabled — small cart allowed
$saveMinOff();
CartService::clear();
CartService::add($low['slug'], $low['vid'], 1);
$totalsOff = CartService::totals();
$ok(!empty($totalsOff['min_order_met']), 'DB: disabled — min_order_met true in totals');
$before = $countOrders();
$placedOff = $place($addr);
$ok(!empty($placedOff['ok']), 'DB: disabled — checkout succeeds below former minimum');
$ok($countOrders() === $before + 1, 'DB: disabled — order created');

// 2–4. Enabled threshold 149
$saveMinOn(149.0, $msg);
AppSettings::resetCache();

CartService::clear();
CartService::add($low['slug'], $low['vid'], 1);
$tLow = CartService::totals();
$merchLow = MarketingSettings::merchandiseSubtotalAfterDiscounts($tLow);
$ok($merchLow < 149 && empty($tLow['min_order_met']), 'DB: below minimum flagged in totals');
$paysBefore = (int) $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn();
$holdsBefore = DeliverySlotReservationService::tableReady()
    ? (int) $pdo->query('SELECT COUNT(*) FROM delivery_slot_reservations')->fetchColumn()
    : 0;
$before = $countOrders();
$rej = $place($addr);
$ok(empty($rej['ok']), 'DB: below minimum checkout rejected');
$ok($countOrders() === $before, 'DB: below minimum creates no order');
$ok((int) $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn() === $paysBefore, 'DB: reject — no payment row');
if (DeliverySlotReservationService::tableReady()) {
    $ok(
        (int) $pdo->query('SELECT COUNT(*) FROM delivery_slot_reservations')->fetchColumn() === $holdsBefore,
        'DB: reject — no slot hold'
    );
}

CartService::clear();
CartService::add($exact['slug'], $exact['vid'], 1);
$tExact = CartService::totals();
$ok(!empty($tExact['min_order_met']) && abs(MarketingSettings::merchandiseSubtotalAfterDiscounts($tExact) - 149) < 0.02, 'DB: exactly at minimum met');
$placedExact = $place($addr);
$ok(!empty($placedExact['ok']), 'DB: exactly at minimum checkout accepted');

CartService::clear();
CartService::add($high['slug'], $high['vid'], 1);
$tHigh = CartService::totals();
$ok(!empty($tHigh['min_order_met']), 'DB: above minimum met');
$placedHigh = $place($addr);
$ok(!empty($placedHigh['ok']), 'DB: above minimum accepted');

// 5. Coupon pushes below minimum
CartService::clear();
CartService::add($high['slug'], $high['vid'], 1);
CartService::applyCoupon($couponCode);
$tCoupon = CartService::totals();
$ok((float) ($tCoupon['coupon_discount'] ?? 0) > 0, 'DB: coupon applied');
$ok(empty($tCoupon['min_order_met']), 'DB: coupon discount below minimum rejected in totals');
$beforeC = $countOrders();
$rejC = $place($addr);
$ok(empty($rejC['ok']), 'DB: coupon below minimum checkout rejected');
$ok($countOrders() === $beforeC, 'DB: coupon reject — no new order');

// 6. Delivery fee does not count (merch 200, min 149, delivery may apply)
$merch6 = MarketingSettings::merchandiseSubtotalAfterDiscounts($tHigh);
$ok($merch6 >= 149 && (float) ($tHigh['delivery_fee'] ?? 0) >= 0, 'DB: delivery fee separate from merchandise baseline');

// 8. Change setting without code deploy
$saveMinOn(250.0, $msg);
CartService::clear();
CartService::add($high['slug'], $high['vid'], 1);
$totNewMin = CartService::totals();
$ok(empty($totNewMin['min_order_met']), 'DB: raised minimum takes effect immediately');

$saveMinOff();

echo $fail > 0 ? "Failures: {$fail}\n" : "All minimum order checks passed.\n";
exit($fail > 0 ? 1 : 0);
