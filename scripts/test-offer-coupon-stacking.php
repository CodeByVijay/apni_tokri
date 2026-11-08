<?php

declare(strict_types=1);

/**
 * Automatic offer `stack_with_coupons` vs cart coupon eligibility.
 * CLI: php scripts/run-in-disposable-db.php scripts/test-offer-coupon-stacking.php
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

$couponPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/commerce/coupon.php');
$offersPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/commerce/coupon_offers.php');
$admin = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/offers/edit.php');
$ok(!str_contains($couponPhp, "empty(\$row['stackable_with_offers'])"), 'static: coupon validate does not second-gate via coupon stackable flag');
$ok(str_contains($couponPhp, 'offerStacksWithCoupons'), 'static: offer stack flag passed into validate');
$ok(str_contains($offersPhp, 'offerStacks'), 'static: cart offers discovery uses winning offer stack flag');
$ok(str_contains($offersPhp, 'Cannot be combined with the automatic offer'), 'static: offer_conflict hint preserved');
$ok(str_contains($admin, 'customers must use this offer alone'), 'static: admin offer stacking help text');

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "SKIP DB: schema not ready.\n");
    exit($fail > 0 ? 1 : 0);
}

Install::ensureConnection();
$pdo = Database::pdo();
$tok = strtolower(bin2hex(random_bytes(3)));

$pdo->exec("INSERT INTO categories (name, slug, status) VALUES ('Stack$tok', 'stack-$tok', 'published')");
$cat = (int) $pdo->lastInsertId();
$prod = ProductRepository::saveGeneral(null, [
    'name' => "Stack$tok",
    'slug' => "stack-prod-$tok",
    'status' => 'published',
    'category_id' => (string) $cat,
]);
$pid = (int) $prod['id'];
ProductRepository::saveVariants($pid, [[
    'id' => '',
    'name' => '1 kg',
    'sku' => 'STK' . strtoupper($tok),
    'price' => 500,
    'sale_price' => '',
    'stock_qty' => 50,
    'low_stock_threshold' => 5,
    'is_active' => 1,
    'sort_order' => 0,
]]);
$slug = (string) ProductRepository::find($pid)['slug'];
$vid = (string) ProductRepository::variants($pid)[0]['id'];

$codeOn = 'STON' . strtoupper($tok);
$codeOff = 'STOF' . strtoupper($tok);
$codeMin = 'STMIN' . strtoupper($tok);
$codeExp = 'STEXP' . strtoupper($tok);
$pdo->prepare(
    'INSERT INTO coupons (code, internal_name, discount_type, discount_value, min_cart_value, is_active, stackable_with_offers)
     VALUES (?, ?, ?, ?, ?, 1, 0)'
)->execute([$codeOn, 'on', 'fixed', 50, 0]);
$pdo->prepare(
    'INSERT INTO coupons (code, internal_name, discount_type, discount_value, min_cart_value, is_active, stackable_with_offers)
     VALUES (?, ?, ?, ?, ?, 1, 0)'
)->execute([$codeOff, 'off', 'fixed', 50, 0]);
$pdo->prepare(
    'INSERT INTO coupons (code, internal_name, discount_type, discount_value, min_cart_value, is_active, stackable_with_offers)
     VALUES (?, ?, ?, ?, ?, 1, 0)'
)->execute([$codeMin, 'min', 'fixed', 10, 1000]);
$pdo->prepare(
    'INSERT INTO coupons (code, internal_name, discount_type, discount_value, min_cart_value, is_active, stackable_with_offers, ends_at)
     VALUES (?, ?, ?, ?, ?, 0, 0, DATE_SUB(NOW(), INTERVAL 1 DAY))'
)->execute([$codeExp, 'exp', 'fixed', 10, 0]);

$lines = [['qty' => 1, 'unit_price' => 500.0, 'product_id' => $pid, 'category_id' => $cat, 'variant_id' => (int) $vid]];

$pdo->prepare(
    'INSERT INTO offers (name, scope, discount_type, discount_value, priority, is_active, stack_with_coupons)
     VALUES (?, ?, ?, ?, 100, 1, 1)'
)->execute(["Stack ON $tok", 'store', 'percent', 10]);
$offerOnId = (int) $pdo->lastInsertId();

$offer = PromotionService::bestAutomaticOffer($lines, null);
$ok((float) ($offer['discount'] ?? 0) > 0 && !empty($offer['stack_with_coupons']), 'db: winning offer has stack_with_coupons ON');

$rOn = CouponService::validate($codeOn, 500.0, null, $lines, (float) $offer['discount'], true);
$ok(!empty($rOn['ok']), 'db: stacking ON + eligible coupon (coupon stackable_with_offers=0) applies');

$pdo->exec("UPDATE offers SET stack_with_coupons = 0 WHERE id = $offerOnId");
AppSettings::resetCache();
$offerOff = PromotionService::bestAutomaticOffer($lines, null);
$ok(empty($offerOff['stack_with_coupons']), 'db: offer stack flag OFF in bestAutomaticOffer');
$rOff = CouponService::validate($codeOff, 500.0, null, $lines, (float) $offerOff['discount'], false);
$ok(empty($rOff['ok']) && ($rOff['reason'] ?? '') === 'offer_conflict', 'db: stacking OFF blocks coupon with offer_conflict');

$pdo->exec("UPDATE offers SET stack_with_coupons = 1 WHERE id = $offerOnId");
$offerOn2 = PromotionService::bestAutomaticOffer($lines, null);
$rMin = CouponService::validate($codeMin, 500.0, null, $lines, (float) $offerOn2['discount'], true);
$ok(empty($rMin['ok']) && ($rMin['reason'] ?? '') === 'min_cart', 'db: stacking ON but below coupon minimum still locked');

$rExp = CouponService::validate($codeExp, 500.0, null, $lines, (float) $offerOn2['discount'], true);
$ok(empty($rExp['ok']) && ($rExp['reason'] ?? '') === 'invalid', 'db: stacking ON + inactive/expired coupon rejected');

$discBefore = (float) ($offerOn2['discount'] ?? 0);
$noOffer = PromotionService::bestAutomaticOffer($lines, null);
$pdo->exec("DELETE FROM offers WHERE id = $offerOnId");
$noOfferAfter = PromotionService::bestAutomaticOffer($lines, null);
$ok((float) ($noOfferAfter['discount'] ?? 0) === 0.0, 'db: coupon-only cart — no automatic offer discount');
$rSolo = CouponService::validate($codeOn, 500.0, null, $lines, 0.0, false);
$ok(!empty($rSolo['ok']), 'db: coupon-only cart unchanged');

$pdo->prepare(
    'INSERT INTO offers (name, scope, discount_type, discount_value, priority, is_active, stack_with_coupons)
     VALUES (?, ?, ?, ?, 5, 1, 0)'
)->execute(["Low pri $tok", 'store', 'fixed', 10]);
$lowId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO offers (name, scope, discount_type, discount_value, priority, is_active, stack_with_coupons)
     VALUES (?, ?, ?, ?, 200, 1, 1)'
)->execute(["High pri $tok", 'store', 'percent', 20]);
$highId = (int) $pdo->lastInsertId();
$multi = PromotionService::bestAutomaticOffer($lines, null);
$ok((int) ($multi['offer_id'] ?? 0) === $highId && (float) ($multi['discount'] ?? 0) > $discBefore / 2, 'db: higher discount offer wins priority rules');
$ok(!empty($multi['stack_with_coupons']), 'db: stacking follows winning offer row');
$oList = CouponOfferService::forCart($lines, null, null);
$found = null;
foreach ($oList as $item) {
    if (($item['code'] ?? '') === $codeOn) {
        $found = $item;
        break;
    }
}
$ok($found !== null && !empty($found['eligible']), 'db: cart offers popup eligible when offer stacks');

$pdo->exec("UPDATE offers SET stack_with_coupons = 0 WHERE id = $highId");
$multi2 = PromotionService::bestAutomaticOffer($lines, null);
$oList2 = CouponOfferService::forCart($lines, null, null);
$found2 = null;
foreach ($oList2 as $item) {
    if (($item['code'] ?? '') === $codeOff) {
        $found2 = $item;
        break;
    }
}
$ok($found2 !== null && empty($found2['eligible']) && ($found2['reason'] ?? '') === 'offer_conflict', 'db: popup shows offer_conflict when stacking OFF');

$pdo->exec("DELETE FROM offers WHERE id IN ($lowId, $highId)");
$pdo->exec("DELETE FROM coupons WHERE code IN (" . $pdo->quote($codeOn) . ',' . $pdo->quote($codeOff) . ',' . $pdo->quote($codeMin) . ',' . $pdo->quote($codeExp) . ')');

exit($fail > 0 ? 1 : 0);
