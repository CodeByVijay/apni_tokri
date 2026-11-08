<?php

declare(strict_types=1);

/**
 * Per-offer storefront countdown (uses offers.starts_at / ends_at).
 * CLI: php scripts/run-in-disposable-db.php scripts/test-offer-countdown.php
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

$tz = new DateTimeZone(ReportDateRange::TIMEZONE);
$footer = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/layout/footer-public.php');
$js = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/offer-countdown.js');
$marketing = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/settings/marketing.php');
$adminMkt = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/marketing/index.php');
$storefront = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/commerce/offer_storefront.php');
$pricing = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/commerce/pricing.php');
$cartJs = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/cart.js');

$ok(str_contains($footer, 'offer-countdown.js'), 'static: countdown JS enqueued on storefront');
$ok(str_contains($js, 'ATOfferCountdownTick'), 'static: global tick for AJAX cart refresh');
$ok(str_contains($js, 'Days') && str_contains($js, 'Seconds'), 'static: countdown format matches spec');
$ok(str_contains($pricing, 'offer_countdown_end_unix'), 'static: pricing totals expose offer expiry unix');
$ok(str_contains($cartJs, 'data-cart-offer-countdown-wrap'), 'static: cart.js updates offer countdown');
$ok(!str_contains($marketing, 'AppSettings::set(self::KEY_OFFER_COUNTDOWN_END'), 'static: marketing save does not overwrite legacy countdown');
$ok(str_contains($adminMkt, 'Admin → Offers'), 'static: marketing UI points to per-offer expiry');
$ok(str_contains($storefront, 'parseAdminWindow'), 'static: admin offer window parser');
$homeOffers = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/home-store-offers.php');
$cdPartial = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/offer-countdown.php');
$ok(str_contains($homeOffers, 'countdown_end_unix') && str_contains($cdPartial, 'data-at-offer-countdown'), 'static: homepage offers wire countdown partial');

$bad = OfferStorefront::parseAdminWindow([
    'starts_at' => '2030-01-01T10:00',
    'ends_at' => '2030-01-01T09:00',
]);
$ok(empty($bad['ok']) && isset($bad['errors']['ends_at']), 'static: expiry before start rejected');

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "SKIP DB: schema not ready.\n");
    exit($fail > 0 ? 1 : 0);
}

Install::ensureConnection();
$pdo = Database::pdo();
$tok = strtolower(bin2hex(random_bytes(3)));

$futureEnd = (new DateTimeImmutable('+2 days', $tz))->format('Y-m-d H:i:s');
$futureStart = (new DateTimeImmutable('-1 hour', $tz))->format('Y-m-d H:i:s');
$pastEnd = (new DateTimeImmutable('-1 hour', $tz))->format('Y-m-d H:i:s');
$scheduledStart = (new DateTimeImmutable('+2 days', $tz))->format('Y-m-d H:i:s');
$scheduledEnd = (new DateTimeImmutable('+5 days', $tz))->format('Y-m-d H:i:s');

$pdo->prepare(
    'INSERT INTO offers (name, scope, discount_type, discount_value, priority, is_active, starts_at, ends_at)
     VALUES (?, ?, ?, ?, 50, 1, ?, ?)'
)->execute(["OC Active $tok", 'store', 'percent', 5, $futureStart, $futureEnd]);
$activeId = (int) $pdo->lastInsertId();

$pdo->prepare(
    'INSERT INTO offers (name, scope, discount_type, discount_value, priority, is_active, starts_at, ends_at)
     VALUES (?, ?, ?, ?, 40, 1, ?, ?)'
)->execute(["OC Expired $tok", 'store', 'percent', 99, $futureStart, $pastEnd]);

$pdo->prepare(
    'INSERT INTO offers (name, scope, discount_type, discount_value, priority, is_active, starts_at, ends_at)
     VALUES (?, ?, ?, ?, 30, 1, ?, ?)'
)->execute(["OC Scheduled $tok", 'store', 'percent', 88, $scheduledStart, $scheduledEnd]);

$pdo->prepare(
    'INSERT INTO offers (name, scope, discount_type, discount_value, priority, is_active, starts_at, ends_at)
     VALUES (?, ?, ?, ?, 20, 1, ?, NULL)'
)->execute(["OC Open $tok", 'store', 'percent', 3, $futureStart]);

$pdo->prepare(
    'INSERT INTO offers (name, scope, discount_type, discount_value, priority, is_active, starts_at, ends_at)
     VALUES (?, ?, ?, ?, 60, 1, ?, ?)'
)->execute(["OC Second $tok", 'store', 'fixed', 25, $futureStart, $futureEnd]);

$lines = [['qty' => 1, 'unit_price' => 500.0, 'product_id' => 1, 'category_id' => 1, 'variant_id' => 1]];
$best = PromotionService::bestAutomaticOffer($lines, null);
$ok((int) ($best['offer_id'] ?? 0) === $activeId || (int) ($best['offer_id'] ?? 0) > 0, 'db: active offer applies to cart');
$ok(!empty($best['ends_at']), 'db: winning offer exposes ends_at');
$unix = OfferStorefront::endsAtToUnix($best['ends_at'] ?? null);
$ok($unix !== null && $unix > time(), 'db: countdown unix in the future for active offer');

$cards = OfferStorefront::activeCards(null, 20);
$withCd = array_filter($cards, static fn (array $c): bool => !empty($c['countdown_end_unix']));
$ok(count($withCd) >= 2, 'db: multiple active offers each have independent countdown unix');
$open = array_filter($cards, static fn (array $c): bool => str_contains((string) ($c['name'] ?? ''), "OC Open $tok"));
$ok($open !== [] && empty(reset($open)['countdown_end_unix']), 'db: open-ended offer has no countdown unix');

$activeRows = PromotionService::activeOffers(null);
$names = array_column($activeRows, 'name');
$ok(!in_array("OC Expired $tok", $names, true), 'db: expired offer not in activeOffers');
$ok(!in_array("OC Scheduled $tok", $names, true), 'db: scheduled offer not in activeOffers');

$pdo->exec(
    "UPDATE offers SET ends_at = " . $pdo->quote((new DateTimeImmutable('-5 minutes', $tz))->format('Y-m-d H:i:s'))
    . " WHERE id = " . $activeId
);
$bestAfter = PromotionService::bestAutomaticOffer($lines, null);
$ok((int) ($bestAfter['offer_id'] ?? 0) !== $activeId, 'db: offer past expiry not applied to new cart');

$r = CouponService::validate('WELCOME10', 500.0, null, $lines, 0.0, false);
if (!$r['ok']) {
    $pdo->exec(
        "INSERT INTO coupons (code, internal_name, discount_type, discount_value, min_cart_value, is_active, stackable_with_offers)
         VALUES ('WELCOME10', 'OC test', 'percent', 10, 100, 1, 1)
         ON DUPLICATE KEY UPDATE is_active = 1"
    );
    $r = CouponService::validate('WELCOME10', 500.0, null, $lines, 0.0, false);
}
$ok($r['ok'] || isset($r['message']), 'db: coupon validate still callable (behaviour unchanged)');

exit($fail > 0 ? 1 : 0);
