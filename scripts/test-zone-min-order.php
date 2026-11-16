<?php

declare(strict_types=1);

/**
 * Zone-wise minimum order amount (schema 1.42.0) — resolver, admin validation,
 * pricing pipeline and checkout rejection, against a disposable database.
 *
 * CLI: php scripts/run-in-disposable-db.php scripts/test-zone-min-order.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/disposable_db_runner.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();
TestScriptSafety::assertSafeToMutateSettings();

$fail = 0;
function zmoOk(bool $c, string $l): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $l . PHP_EOL;
    if (!$c) {
        ++$fail;
    }
}

$pdo = Database::pdo();
$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

// Apply the feature migration into the disposable DB (production SQL predates it).
$mig = disposable_apply_migration_file($pdo, $dbName, dirname(__DIR__) . '/database/migrations/1.42.0_delivery_zone_min_order.sql');
if (empty($mig['ok'])) {
    echo 'MIGRATION ERROR: ' . (string) ($mig['message'] ?? '?') . PHP_EOL;
}
zmoOk(!empty($mig['ok']), 'migration 1.42.0 applies cleanly');

$col = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_zones' AND COLUMN_NAME = 'minimum_order_amount'"
)->fetchColumn();
zmoOk($col === 1, 'delivery_zones.minimum_order_amount column exists');

// Re-run — must be idempotent.
$mig2 = disposable_apply_migration_file($pdo, $dbName, dirname(__DIR__) . '/database/migrations/1.42.0_delivery_zone_min_order.sql');
zmoOk(!empty($mig2['ok']), 'migration is safe to run twice');

$nullCount = (int) $pdo->query('SELECT COUNT(*) FROM delivery_zones WHERE minimum_order_amount IS NULL')->fetchColumn();
$totalCount = (int) $pdo->query('SELECT COUNT(*) FROM delivery_zones')->fetchColumn();
zmoOk($totalCount > 0 && $nullCount === $totalCount, 'existing zones preserved with NULL minimum (global fallback)');

// ---------- MarketingSettings::zoneMinimumOrderAmount resolver ----------
zmoOk(MarketingSettings::zoneMinimumOrderAmount(null) === null, 'null zone -> null (global fallback)');
zmoOk(MarketingSettings::zoneMinimumOrderAmount(['minimum_order_amount' => null]) === null, 'NULL column -> null');
zmoOk(MarketingSettings::zoneMinimumOrderAmount(['minimum_order_amount' => '']) === null, 'blank column -> null');
zmoOk(MarketingSettings::zoneMinimumOrderAmount(['minimum_order_amount' => '0.00']) === null, 'zero -> not a configured minimum');
zmoOk(MarketingSettings::zoneMinimumOrderAmount(['minimum_order_amount' => '-5']) === null, 'negative -> not a configured minimum');
zmoOk(MarketingSettings::zoneMinimumOrderAmount(['minimum_order_amount' => '149.00']) === 149.0, '149 -> 149');
zmoOk(MarketingSettings::zoneMinimumOrderAmount(['name' => 'x']) === null, 'missing key (pre-migration row shape) -> null');

// ---------- Global setting: enable ₹99 ----------
AppSettings::set(MarketingSettings::KEY_MIN_ORDER_ENABLED, '1');
AppSettings::set(MarketingSettings::KEY_MIN_ORDER_AMOUNT_INR, '99');
AppSettings::set(MarketingSettings::KEY_MIN_ORDER_MESSAGE, 'Minimum order value after discounts is {amount}.');

$mkTotals = static function (float $sub, ?float $zoneMin): array {
    $t = ['subtotal' => $sub, 'offer_discount' => 0.0, 'coupon_discount' => 0.0];
    if ($zoneMin !== null) {
        $t['zone_min_order_amount'] = $zoneMin;
    }

    return $t;
};

// Phase-6 matrix
$s = MarketingSettings::minimumOrderStatusFromTotals($mkTotals(50, null));
zmoOk($s['min_order_enabled'] && $s['min_order_amount_inr'] === 99.0 && $s['min_order_source'] === 'global',
    'zone NULL + global 99 -> 99');
$s = MarketingSettings::minimumOrderStatusFromTotals($mkTotals(50, 149.0));
zmoOk($s['min_order_amount_inr'] === 149.0 && $s['min_order_source'] === 'zone',
    'zone 149 + global 99 -> 149 (zone wins)');
$s = MarketingSettings::minimumOrderStatusFromTotals($mkTotals(250, 199.0));
zmoOk($s['min_order_amount_inr'] === 199.0 && $s['min_order_met'],
    'zone 199 + global 99 -> 199, met at 250');

AppSettings::set(MarketingSettings::KEY_MIN_ORDER_AMOUNT_INR, '149');
$s = MarketingSettings::minimumOrderStatusFromTotals($mkTotals(50, null));
zmoOk($s['min_order_amount_inr'] === 149.0, 'zone NULL + global 149 -> 149');
AppSettings::set(MarketingSettings::KEY_MIN_ORDER_AMOUNT_INR, '99');

$s = MarketingSettings::minimumOrderStatusFromTotals($mkTotals(179, 199.0));
zmoOk(!$s['min_order_met'] && abs($s['min_order_shortfall'] - 20.0) < 0.01,
    'zone 199, merch 179 -> shortfall 20 (delivery fee excluded)');

$rej = MarketingSettings::checkoutMinimumRejection($mkTotals(179, 199.0));
zmoOk(is_string($rej) && str_contains($rej, 'delivery area') && str_contains($rej, '20'),
    'rejection message names the delivery area and the shortfall');
$rej = MarketingSettings::checkoutMinimumRejection($mkTotals(200, 199.0));
zmoOk($rej === null, 'merch 200 >= zone 199 -> checkout allowed');

// Zero zone value must fall back to global (and admin rejects it — tested below).
$s = MarketingSettings::minimumOrderStatusFromTotals($mkTotals(50, 0.0));
zmoOk($s['min_order_amount_inr'] === 99.0 && $s['min_order_source'] === 'global',
    'zone 0 -> global fallback 99');

// Global disabled: zone value still enforces; blank zone disables check as before.
AppSettings::set(MarketingSettings::KEY_MIN_ORDER_ENABLED, '0');
$s = MarketingSettings::minimumOrderStatusFromTotals($mkTotals(50, 149.0));
zmoOk($s['min_order_enabled'] && $s['min_order_amount_inr'] === 149.0, 'global off + zone 149 -> zone applies');
$s = MarketingSettings::minimumOrderStatusFromTotals($mkTotals(50, null));
zmoOk(!$s['min_order_enabled'] && $s['min_order_met'], 'global off + zone NULL -> no min check (unchanged)');
AppSettings::set(MarketingSettings::KEY_MIN_ORDER_ENABLED, '1');

// ---------- Admin form validation ----------
zmoOk(DeliveryAdminUi::validateZoneInput([
    'name' => 'Test', 'zone_kind' => 'pincode', 'match_value' => '4520',
    'flat_fee' => '39', 'free_above' => '699', 'minimum_order_amount' => '149',
]) === [], 'valid zone form incl. minimum_order_amount accepted');

zmoOk(DeliveryAdminUi::validateZoneInput([
    'name' => 'Test', 'zone_kind' => 'pincode', 'match_value' => '4520',
    'flat_fee' => '39', 'free_above' => '699', 'minimum_order_amount' => '',
]) === [], 'blank minimum_order_amount accepted (global fallback)');

zmoOk(DeliveryAdminUi::validateZoneInput([
    'name' => 'Test', 'zone_kind' => 'pincode', 'match_value' => '4520',
    'flat_fee' => '39', 'free_above' => '699', 'minimum_order_amount' => '-10',
]) !== [], 'negative minimum_order_amount rejected');

zmoOk(DeliveryAdminUi::validateZoneInput([
    'name' => 'Test', 'zone_kind' => 'pincode', 'match_value' => '4520',
    'flat_fee' => '39', 'free_above' => '699', 'minimum_order_amount' => '0',
]) !== [], 'zero minimum_order_amount rejected');

zmoOk(DeliveryAdminUi::validateZoneInput([
    'name' => 'Test', 'zone_kind' => 'pincode', 'match_value' => '4520',
    'flat_fee' => '39', 'free_above' => '699', 'minimum_order_amount' => 'abc',
]) !== [], 'non-numeric minimum_order_amount rejected');

// ---------- Admin save path (create + edit) ----------
$post = [
    'name' => 'ZMO Test Area', 'zone_kind' => 'pincode', 'match_value' => '7777',
    'flat_fee' => '49', 'free_above' => '699', 'minimum_order_amount' => '199',
    'sort_order' => '50', 'is_active' => '1',
];
$res = DeliveryZoneAdminService::saveFromPost($post, null);
zmoOk(!empty($res['ok']), 'zone create with minimum saves');
$zoneId = 0;
if (!empty($res['ok'])) {
    foreach (DeliveryZoneRepository::allZones() as $z) {
        if ((string) $z['match_value'] === '7777') {
            $zoneId = (int) $z['id'];
        }
    }
    $row = DeliveryZoneRepository::find($zoneId);
    zmoOk($row !== null && (float) $row['minimum_order_amount'] === 199.0, 'created zone stores minimum_order_amount=199');
}

$post['minimum_order_amount'] = '';
$res = DeliveryZoneAdminService::saveFromPost($post, $zoneId);
$row = DeliveryZoneRepository::find($zoneId);
zmoOk(!empty($res['ok']) && $row !== null && $row['minimum_order_amount'] === null,
    'edit to blank stores NULL (global fallback)');

$post['minimum_order_amount'] = '149.50';
$res = DeliveryZoneAdminService::saveFromPost($post, $zoneId);
$row = DeliveryZoneRepository::find($zoneId);
zmoOk(!empty($res['ok']) && $row !== null && (float) $row['minimum_order_amount'] === 149.5,
    'edit to 149.50 stores correctly');

// ---------- Pricing pipeline: session-pin zone minimum ----------
// Use a pincode prefix that matches exactly one seeded zone.
$zone = DeliveryZoneRepository::find($zoneId); // 7777 prefix, min 149.50
$lines = [['qty' => 1, 'unit_price' => 160.0, 'regular_price' => 160.0, 'on_sale' => false]];
$totals = PricingService::totalsFromLines($lines, null, '777701');
zmoOk(isset($totals['zone_min_order_amount']) && (float) $totals['zone_min_order_amount'] === 149.5,
    'totalsFromLines resolves zone minimum from matched pincode zone');
zmoOk($totals['min_order_amount_inr'] === 149.5 && $totals['min_order_source'] === 'zone',
    'totals min_order_amount_inr uses zone value');
zmoOk($totals['min_order_met'] === true, 'merch 160 >= 149.50 -> met');

$totals = PricingService::totalsFromLines($lines, null, '452001');
$z = $totals['zone_min_order_amount'] ?? null;
zmoOk($z === null && $totals['min_order_amount_inr'] === 99.0 && $totals['min_order_source'] === 'global',
    'zone without configured min -> global 99');

// ---------- Quote preview: authoritative address zone ----------
$pdo->exec('UPDATE delivery_store SET slots_enabled = 0 WHERE id = 1');
DeliveryConfig::resetCache();
$address = ['pincode' => '777701'];
$totals = PricingService::totalsFromLines($lines, null, '452001');
$totals = PricingService::applyDeliveryQuotePreview($totals, $lines, $address, 'auto');
zmoOk(!empty($totals['delivery_quote_accurate']) && (float) $totals['zone_min_order_amount'] === 149.5
    && $totals['min_order_source'] === 'zone',
    'applyDeliveryQuotePreview refreshes zone minimum from quote zone');

// Address to a zone-less pincode -> global fallback preserved.
$totals = PricingService::totalsFromLines($lines, null, '777701');
$totals = PricingService::applyDeliveryQuotePreview($totals, $lines, ['pincode' => '999999'], 'auto');
zmoOk(array_key_exists('zone_min_order_amount', $totals)
    && $totals['zone_min_order_amount'] === null
    && $totals['min_order_amount_inr'] === 99.0 && $totals['min_order_source'] === 'global',
    'unmatched address -> global fallback (existing behaviour)');

// ---------- cleanup ----------
$pdo->prepare('DELETE FROM delivery_zones WHERE id = ?')->execute([$zoneId]);
$pdo->exec('UPDATE delivery_store SET slots_enabled = 1 WHERE id = 1');
DeliveryConfig::resetCache();
AppSettings::set(MarketingSettings::KEY_MIN_ORDER_ENABLED, '0');

echo ($fail === 0 ? 'ALL OK' : "FAILURES: {$fail}") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
