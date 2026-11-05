<?php

declare(strict_types=1);

/**
 * Cart "Available offers": eligibility, ranking, apply/remove, stacking, revalidation, visibility.
 * Mock data only (no orders created through checkout, no SMS/email/payments).
 *
 * Run: php7.3 scripts/run-in-disposable-db.php scripts/test-cart-offers.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (getenv('APNI_TOKRI_DISPOSABLE_DB') !== '1') {
    fwrite(STDERR, "Run via scripts/run-in-disposable-db.php\n");
    exit(2);
}
AppSession::start(App::config()['session'] ?? []);
Install::ensureConnection();
$pdo = Database::pdo();
$pass = 0;
$fail = 0;

function ok(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

/** @return array<string, mixed>|null */
function offerFor(array $offers, string $code): ?array
{
    foreach ($offers as $o) {
        if ($o['code'] === $code) {
            return $o;
        }
    }

    return null;
}

function loginAs(?int $id): void
{
    global $pA, $pB;
    if ($id === null) {
        unset($_SESSION['customer_id']);
    } else {
        $_SESSION['customer_id'] = $id;
    }
    // Carts are per identity: rebuild the same 400 cart (3 x alpha + 2 x beta) for the new identity.
    CartService::clear();
    CartService::add($pA['slug'], $pA['vid'], 3);
    CartService::add($pB['slug'], $pB['vid'], 2);
}

$tok = strtoupper(bin2hex(random_bytes(3)));
$low = strtolower($tok);
$pdo->exec("UPDATE offers SET is_active = 0");

// --- catalogue: two products in two categories
$pdo->exec("INSERT INTO categories (name, slug, status) VALUES ('CatA $tok', 'cata-$low', 'published')");
$catA = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO categories (name, slug, status) VALUES ('CatB $tok', 'catb-$low', 'published')");
$catB = (int) $pdo->lastInsertId();
$mk = static function (string $name, int $cat, int $price) use ($low, $tok): array {
    $p = ProductRepository::saveGeneral(null, [
        'name' => $name . $tok, 'slug' => $name . '-' . $low, 'status' => 'published', 'category_id' => (string) $cat,
    ]);
    $pid = (int) $p['id'];
    ProductRepository::saveVariants($pid, [[
        'id' => '', 'name' => '1 kg', 'sku' => strtoupper($name) . '-' . $tok, 'price' => $price, 'sale_price' => '',
        'stock_qty' => 99, 'low_stock_threshold' => 5, 'is_active' => 1, 'sort_order' => 0,
    ]]);

    return ['id' => $pid, 'slug' => (string) ProductRepository::find($pid)['slug'], 'vid' => (string) ProductRepository::variants($pid)[0]['id']];
};
$pA = $mk('alpha', $catA, 100);
$pB = $mk('beta', $catB, 50);
$pC = $mk('gamma', $catB, 10); // never in cart

// --- customers
$pdo->exec("INSERT INTO customers (mobile, name) VALUES ('9810000001', 'Newbie')");
$newbie = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO customers (mobile, name) VALUES ('9810000002', 'Veteran')");
$veteran = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO orders (order_number, customer_id, status, payment_status) VALUES ('T-$tok', $veteran, 'delivered', 'paid')");
$orderId = (int) $pdo->lastInsertId();

// --- coupons (all stackable with offers unless noted)
$ins = $pdo->prepare(
    'INSERT INTO coupons (code, internal_name, discount_type, discount_value, min_cart_value, max_discount, usage_limit, used_count,
     per_customer_limit, first_order_only, stackable_with_offers, priority, starts_at, ends_at, is_active)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$mkc = static function (string $code, array $o = []) use ($ins, $pdo): int {
    $o += ['type' => 'fixed', 'value' => 10, 'min' => null, 'max' => null, 'ulimit' => null, 'used' => 0, 'pcl' => null,
        'first' => 0, 'stack' => 1, 'prio' => 0, 'start' => null, 'end' => null, 'active' => 1];
    $ins->execute([$code, 'INTERNAL-SECRET-' . $code, $o['type'], $o['value'], $o['min'], $o['max'], $o['ulimit'], $o['used'],
        $o['pcl'], $o['first'], $o['stack'], $o['prio'], $o['start'], $o['end'], $o['active']]);

    return (int) $pdo->lastInsertId();
};
$C = static function (string $s) use ($tok): string {
    return $s . $tok;
};
$mkc($C('FIX'), ['value' => 40]);
$mkc($C('PCT'), ['type' => 'percent', 'value' => 15, 'max' => 100]);       // 15% of 400 = 60
$mkc($C('CAP'), ['type' => 'percent', 'value' => 50, 'max' => 20]);          // capped at 20
$mkc($C('MIN'), ['value' => 100, 'min' => 1000]);
$idProd = $mkc($C('PRODB'), ['value' => 30]);
$idProdX = $mkc($C('PRODX'), ['value' => 30]);
$idCat = $mkc($C('CATA'), ['type' => 'percent', 'value' => 10]);              // 10% of 300 = 30
$mkc($C('NEWC'), ['value' => 25, 'first' => 1]);
$mkc($C('EXPD'), ['value' => 99, 'end' => '2020-01-01 00:00:00']);
$mkc($C('INACT'), ['value' => 99, 'active' => 0]);
$mkc($C('LIMIT'), ['value' => 99, 'ulimit' => 1, 'used' => 1]);
$idPcl = $mkc($C('ONCE'), ['value' => 15, 'pcl' => 1]);
$idPriv = $mkc($C('PRIVATE'), ['value' => 70]);
$mkc($C('NOSTACK'), ['value' => 5, 'stack' => 0]);
$pdo->prepare('INSERT INTO coupon_products (coupon_id, product_id) VALUES (?, ?)')->execute([$idProd, $pB['id']]);
$pdo->prepare('INSERT INTO coupon_products (coupon_id, product_id) VALUES (?, ?)')->execute([$idProdX, $pC['id']]);
$pdo->prepare('INSERT INTO coupon_categories (coupon_id, category_id) VALUES (?, ?)')->execute([$idCat, $catA]);
$pdo->prepare('INSERT INTO customer_coupon_assignments (coupon_id, customer_id) VALUES (?, ?)')->execute([$idPriv, $newbie]);
$pdo->prepare('INSERT INTO coupon_usages (coupon_id, customer_id, order_id) VALUES (?, ?, NULL)')->execute([$idPcl, $veteran]);

// --- cart: 3 x alpha (300) + 2 x beta (100) = 400
loginAs(null);
CartService::clear();
CartService::add($pA['slug'], $pA['vid'], 3);
CartService::add($pB['slug'], $pB['vid'], 2);
$offers = CouponOfferService::forCurrentCart();

// 1 + 2 eligible fixed / percentage
$fix = offerFor($offers, $C('FIX'));
ok($fix !== null && $fix['eligible'] && abs($fix['discount'] - 40.0) < 0.01, 'Eligible fixed coupon shows server discount (40)');
$pct = offerFor($offers, $C('PCT'));
ok($pct !== null && $pct['eligible'] && abs($pct['discount'] - 60.0) < 0.01, 'Eligible percentage coupon shows server discount (15% of 400 = 60)');
$cap = offerFor($offers, $C('CAP'));
ok($cap !== null && abs($cap['discount'] - 20.0) < 0.01, 'Max discount cap respected (50% capped at 20)');

// 3 prioritisation: highest saving first, best flagged
$mine = array_values(array_filter($offers, static function (array $o) use ($tok): bool {
    return $o['eligible'] && substr($o['code'], -6) === $tok;
}));
$sorted = true;
for ($i = 1; $i < count($mine); $i++) {
    if ($mine[$i - 1]['discount'] < $mine[$i]['discount']) {
        $sorted = false;
    }
}
ok($sorted && $mine[0]['code'] === $C('PCT') && !empty($mine[0]['best']), 'Eligible offers ordered by saving; best savings = PCT');
ok(count(array_filter($offers, static function (array $o): bool { return !empty($o['best']); })) === 1, 'Exactly one offer highlighted as best');
ok(!empty($pct['preview']) && count(array_filter($offers, static function (array $o): bool { return !empty($o['preview']); })) <= CouponOfferService::MAX_PREVIEW, 'Preview limited to 3');
$firstLockedIdx = null;
foreach ($offers as $i => $o) {
    if (!$o['eligible']) {
        $firstLockedIdx = $i;
        break;
    }
}
$lastEligibleIdx = -1;
foreach ($offers as $i => $o) {
    if ($o['eligible']) {
        $lastEligibleIdx = $i;
    }
}
ok($firstLockedIdx === null || $firstLockedIdx > $lastEligibleIdx, 'Unavailable offers sorted after eligible ones');

// 4 minimum cart
$min = offerFor($offers, $C('MIN'));
ok($min !== null && !$min['eligible'] && $min['reason'] === 'min_cart' && $min['hint'] === 'Add ₹600 more to unlock', 'Minimum not reached: unlock hint "Add ₹600 more to unlock"');
// 5 product restriction
$pb = offerFor($offers, $C('PRODB'));
ok($pb !== null && $pb['eligible'] && abs($pb['discount'] - 30.0) < 0.01, 'Product-restricted coupon eligible when product in cart');
$px = offerFor($offers, $C('PRODX'));
ok($px !== null && !$px['eligible'] && $px['hint'] === 'Selected products are not eligible', 'Product-restricted coupon explains ineligible products');
// 6 category restriction (10% of CatA items only = 30, not 40)
$ca = offerFor($offers, $C('CATA'));
ok($ca !== null && $ca['eligible'] && abs($ca['discount'] - 30.0) < 0.01, 'Category-restricted coupon discounts only eligible category (30)');
// 7 new customer
$nw = offerFor($offers, $C('NEWC'));
ok($nw !== null && !$nw['eligible'] && $nw['reason'] === 'login_required', 'Guest sees first-order coupon as locked (log in)');
loginAs($newbie);
$nw = offerFor(CouponOfferService::forCurrentCart(), $C('NEWC'));
ok($nw !== null && $nw['eligible'], 'New customer can use first-order coupon');
loginAs($veteran);
$vOffers = CouponOfferService::forCurrentCart();
$nw = offerFor($vOffers, $C('NEWC'));
ok($nw !== null && !$nw['eligible'] && $nw['hint'] === 'New customers only', 'Returning customer: "New customers only"');
// 8 + 9 expired / inactive never listed
ok(offerFor($offers, $C('EXPD')) === null, 'Expired coupon not displayed');
ok(offerFor($offers, $C('INACT')) === null, 'Inactive coupon not displayed');
// 10 usage limit
$lim = offerFor($offers, $C('LIMIT'));
ok($lim !== null && !$lim['eligible'] && $lim['hint'] === 'Usage limit reached', 'Exhausted coupon shows "Usage limit reached"');
// 11 per-customer usage
$once = offerFor($vOffers, $C('ONCE'));
ok($once !== null && !$once['eligible'] && $once['reason'] === 'per_customer_limit', 'Per-customer limit enforced for returning customer');
loginAs($newbie);
ok(offerFor(CouponOfferService::forCurrentCart(), $C('ONCE'))['eligible'] === true, 'Per-customer limit does not affect other customers');

// 19 private visibility
loginAs(null);
ok(offerFor($offers, $C('PRIVATE')) === null, 'Private coupon hidden from guests');
loginAs($veteran);
ok(offerFor($vOffers, $C('PRIVATE')) === null, 'Private coupon hidden from other customers');
loginAs($newbie);
$nOffers = CouponOfferService::forCurrentCart();
ok(offerFor($nOffers, $C('PRIVATE')) !== null && offerFor($nOffers, $C('PRIVATE'))['eligible'], 'Private coupon visible to assigned customer');
loginAs(null);
ob_start();
$offers = CouponOfferService::forCurrentCart();
require APNI_TOKRI_ROOT . '/includes/partials/cart-offers.php';
$html = (string) ob_get_clean();
ok(strpos($html, $C('PRIVATE')) === false && strpos($html, 'INTERNAL-SECRET') === false, 'Rendered HTML leaks neither private codes nor internal names');
ok(strpos($html, 'data-at-offer-apply') !== false && strpos($html, 'data-at-offers-sheet') !== false, 'Rendered HTML has Apply buttons and View-all sheet');

// 12 apply (server-side; forged client discount ignored)
$r = CartCouponHandler::process(['coupon_code' => $C('PCT'), 'discount' => '999', 'coupon_discount' => '999']);
ok($r['ok'] && abs((float) $r['totals']['coupon_discount'] - 60.0) < 0.01, 'Apply uses server-calculated discount; client discount ignored');
ok(abs((float) $r['totals']['total'] - ((float) $r['totals']['subtotal'] - 60.0 + (float) $r['totals']['delivery_fee'])) < 0.01, 'Order total reflects applied coupon');
$offers = CouponOfferService::forCurrentCart($r['totals']);
$ap = offerFor($offers, $C('PCT'));
ok($ap !== null && $ap['applied'] && $offers[0]['code'] === $C('PCT'), 'Applied coupon marked applied and listed first');
ob_start();
require APNI_TOKRI_ROOT . '/includes/partials/cart-offers.php';
$html = (string) ob_get_clean();
ok(strpos($html, 'data-at-offer-remove') !== false && strpos($html, '>Applied<') !== false, 'Applied card shows Applied + Remove');
// idempotent double apply
$r2 = CartCouponHandler::process(['coupon_code' => strtolower($C('PCT'))]);
ok($r2['ok'] && abs((float) $r2['totals']['coupon_discount'] - 60.0) < 0.01, 'Manual input (lowercase) still works; repeat apply is idempotent');

// 16 stacking: second coupon replaces, never adds
$r3 = CartCouponHandler::process(['coupon_code' => $C('FIX')]);
ok($r3['ok'] && $r3['totals']['coupon_code'] === $C('FIX') && abs((float) $r3['totals']['coupon_discount'] - 40.0) < 0.01, 'Applying a second coupon replaces the first (no coupon stacking)');

// 13 remove
$rm = CartCouponHandler::process(['remove' => '1']);
ok($rm['ok'] && (float) $rm['totals']['coupon_discount'] === 0.0 && empty($rm['totals']['coupon_code']), 'Remove clears the coupon');
$after = CouponOfferService::forCurrentCart($rm['totals']);
ok(!in_array(true, array_column($after, 'applied'), true), 'No offer shown as applied after removal');

// 14 cart value changes after application
CartCouponHandler::process(['coupon_code' => $C('PCT')]);
$key = (string) CartService::items()[0]['line_key'];
CartService::updateQty($key, 1);
$t = CartService::totals();
$expected = round(0.15 * (float) $t['subtotal'], 2);
ok(abs((float) $t['coupon_discount'] - min(100.0, $expected)) < 0.01 && $t['coupon_code'] === $C('PCT'), 'Percent coupon recalculated after cart value change');
// 15 applied coupon becomes invalid after cart change
CartCouponHandler::process(['remove' => '1']);
CartService::clear();
CartService::add($pA['slug'], $pA['vid'], 10); // 1000
$m = CartCouponHandler::process(['coupon_code' => $C('MIN')]);
ok($m['ok'], 'Minimum-order coupon applies once cart reaches threshold');
CartService::updateQty((string) CartService::items()[0]['line_key'], 1);
$t = CartService::totals();
ok(empty($t['coupon_code']) && (float) $t['coupon_discount'] === 0.0, 'Applied coupon dropped gracefully when cart falls below minimum');
$o2 = offerFor(CouponOfferService::forCurrentCart($t), $C('MIN'));
ok($o2 !== null && !$o2['applied'] && strpos((string) $o2['hint'], 'more to unlock') !== false, 'Dropped coupon shown again with unlock hint');

// 17 checkout revalidation: coupon invalidated after being applied is rejected at totals/checkout stage
CartService::clear();
CartService::add($pA['slug'], $pA['vid'], 3);
CartCouponHandler::process(['coupon_code' => $C('FIX')]);
$pdo->exec("UPDATE coupons SET is_active = 0 WHERE code = " . $pdo->quote($C('FIX')));
$t = CartService::totals();
ok(empty($t['coupon_code']) && (float) $t['coupon_discount'] === 0.0, 'Deactivated coupon discarded by authoritative totals (used by checkout)');
$pdo->exec("UPDATE coupons SET is_active = 1 WHERE code = " . $pdo->quote($C('FIX')));
$src = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/commerce/checkout.php');
ok(substr_count($src, "coupon_error") >= 2, 'Checkout paths reject invalid coupons via server totals');

// 16b automatic offer that cannot stack: coupons flagged, apply refused
$pdo->exec("INSERT INTO offers (name, scope, discount_type, discount_value, is_active, stack_with_coupons) VALUES ('Auto $tok', 'store', 'percent', 10, 1, 0)");
$offerId = (int) $pdo->lastInsertId();
CartCouponHandler::process(['remove' => '1']);
$oo = offerFor(CouponOfferService::forCurrentCart(), $C('PCT'));
ok($oo !== null && !$oo['eligible'] && $oo['reason'] === 'offer_conflict', 'Coupon blocked by non-stackable automatic offer shows as unavailable');
$blocked = CartCouponHandler::process(['coupon_code' => $C('PCT')]);
ok(!$blocked['ok'], 'Server refuses coupon that cannot combine with automatic offer');
$pdo->exec("DELETE FROM offers WHERE id = $offerId");

// 20 duplicate-request protection (client) + CSRF (server)
$js = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/cart.js');
ok(strpos($js, 'if (couponBusy) return;') !== false && strpos($js, 'setOffersBusy(true)') !== false, 'JS blocks duplicate apply/remove while a request is in flight and disables offer buttons');
$ajax = (string) file_get_contents(APNI_TOKRI_ROOT . '/ajax/cart/coupon.php');
ok(strpos($ajax, 'Csrf::requireValid()') !== false && strpos($ajax, "'POST'") !== false, 'Coupon AJAX endpoint requires POST + CSRF');
ok(strpos($js, 'pageYOffset') !== false && strpos($js, 'location.reload') !== false, 'Offers refresh preserves scroll; qty/remove reload re-evaluates eligibility');

// cleanup
CartService::clear();
loginAs(null);
$pdo->exec("DELETE FROM coupons WHERE code LIKE '%$tok'");
$pdo->exec("DELETE FROM orders WHERE id = $orderId");
$pdo->exec("DELETE FROM customers WHERE id IN ($newbie, $veteran)");
foreach ([$pA, $pB, $pC] as $p) {
    $pdo->exec('DELETE FROM product_variants WHERE product_id = ' . (int) $p['id']);
    $pdo->exec('DELETE FROM products WHERE id = ' . (int) $p['id']);
}
$pdo->exec("DELETE FROM categories WHERE id IN ($catA, $catB)");

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
