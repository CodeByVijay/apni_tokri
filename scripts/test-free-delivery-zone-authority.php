<?php

declare(strict_types=1);

/**
 * Free delivery: delivery_zones.free_above is authoritative; marketing global threshold is not used in pricing.
 * CLI: php scripts/run-in-disposable-db.php scripts/test-free-delivery-zone-authority.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
$ok = static function (bool $c, string $m) use (&$fail): void {
    echo ($c ? 'PASS: ' : 'FAIL: ') . $m . "\n";
    if (!$c) {
        $fail++;
    }
};

$marketingPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/settings/marketing.php');
$marketingAdmin = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/marketing/index.php');
$pricing = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/commerce/pricing.php');
$quote = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/delivery/quote.php');

$ok(!str_contains($marketingAdmin, 'name="free_threshold"'), 'static: marketing admin has no free_threshold input');
$ok(!str_contains($marketingPhp, "KEY_FREE_DELIVERY_THRESHOLD, \$freeVal"), 'static: marketing save does not persist global free threshold');
$ok(str_contains($quote, "\$zone['free_above']"), 'static: checkout quote uses zone free_above');
$ok(str_contains($pricing, 'DeliveryService::computeDeliveryFee'), 'static: cart pricing uses zone-based delivery helper');
$ok(!preg_match('/MarketingSettings::freeDeliveryThresholdInr\s*\(/', $pricing), 'static: pricing does not call marketing free threshold');

$glob = [];
foreach (glob(APNI_TOKRI_ROOT . '/includes/**/*.php') ?: [] as $f) {
    $src = (string) file_get_contents($f);
    if (str_contains($src, 'MarketingSettings::freeDeliveryThresholdInr(')) {
        $glob[] = str_replace(APNI_TOKRI_ROOT . '/', '', $f);
    }
}
foreach (glob(APNI_TOKRI_ROOT . '/includes/*.php') ?: [] as $f) {
    $src = (string) file_get_contents($f);
    if (str_contains($src, 'MarketingSettings::freeDeliveryThresholdInr(')) {
        $glob[] = str_replace(APNI_TOKRI_ROOT . '/', '', $f);
    }
}
$glob = array_unique($glob);
$ok($glob === [] || $glob === ['includes/settings/marketing.php'], 'static: freeDeliveryThresholdInr only defined in marketing settings');

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "SKIP DB: schema not ready.\n");
    exit($fail > 0 ? 1 : 0);
}

Install::ensureConnection();
$pdo = Database::pdo();
$tag = substr(bin2hex(random_bytes(2)), 0, 4);

$flat = 40.0;
$zones = [
    ['0–3 km', 0.0, 3.0, 399.0],
    ['3–5 km', 3.0, 5.0, 499.0],
    ['8–15 km', 8.0, 15.0, 599.0],
];
$ins = $pdo->prepare(
    'INSERT INTO delivery_zones (name, match_type, match_value, zone_kind, min_distance_km, max_distance_km, flat_fee, free_above, sort_order, is_active)
     VALUES (?,?,?,?,?,?,?,?,?,1)'
);
$zoneIds = [];
foreach ($zones as $i => $z) {
    $ins->execute(['FD' . $tag . $i, 'pincode_prefix', '', 'distance_band', $z[1], $z[2], $flat, $z[3], 9600 + $i]);
    $zoneIds[] = (int) $pdo->lastInsertId();
}

$lineBelow = static function (float $unit, bool $grantsFree = false): array {
    return ['qty' => 1, 'unit_price' => $unit, 'grants_free_delivery' => $grantsFree];
};

foreach ($zones as $i => $z) {
    $zoneRow = ['flat_fee' => $flat, 'free_above' => $z[3]];
    $thr = (float) $z[3];
    $below = DeliveryQuoteService::deliveryFeeForZone($zoneRow, [$lineBelow($thr - 50)], $thr - 50);
    $ok((float) $below['fee'] === $flat && (float) $below['threshold'] === $thr, "DB: {$z[0]} below threshold charges delivery");
    $at = DeliveryQuoteService::deliveryFeeForZone($zoneRow, [$lineBelow($thr)], $thr);
    $ok((float) $at['fee'] === 0.0, "DB: {$z[0]} at threshold free delivery");
    $above = DeliveryQuoteService::deliveryFeeForZone($zoneRow, [$lineBelow($thr + 100)], $thr + 100);
    $ok((float) $above['fee'] === 0.0, "DB: {$z[0]} above threshold free delivery");
}

$mixed = [
    ['qty' => 1, 'unit_price' => 500.0, 'grants_free_delivery' => true],
    ['qty' => 1, 'unit_price' => 50.0, 'grants_free_delivery' => false],
];
$mixFee = DeliveryQuoteService::deliveryFeeForZone(['flat_fee' => $flat, 'free_above' => 499.0], $mixed, 550.0);
$ok((float) $mixFee['eligible_subtotal'] === 50.0 && (float) $mixFee['fee'] === $flat, 'DB: mixed cart excludes grants-free lines from eligible subtotal');

// Marketing key must not override zone threshold in computeDeliveryFee
AppSettings::set(MarketingSettings::KEY_FREE_DELIVERY_THRESHOLD, '99999');
AppSettings::resetCache();
$pinEval = DeliveryService::evaluatePincode('452010');
$zoneThr = (float) ($pinEval['zone']['free_above'] ?? 0);
$feeSvc = DeliveryService::computeDeliveryFee([$lineBelow(100)], 100.0, '452010');
$ok($zoneThr > 0 && $zoneThr < 99999, 'DB: live zone threshold not marketing 99999');
$ok((float) $feeSvc['threshold'] === $zoneThr, 'DB: computeDeliveryFee threshold matches zone free_above');

$lines = [['qty' => 1, 'unit_price' => 100.0, 'grants_free_delivery' => false]];
$totals = PricingService::totalsFromLines($lines, null, '452010');
$ok((float) ($totals['free_delivery_threshold'] ?? 0) === $zoneThr, 'DB: cart totals free_delivery_threshold equals zone');

$minWas = AppSettings::get(MarketingSettings::KEY_MIN_ORDER_ENABLED, '0');
AppSettings::set(MarketingSettings::KEY_MIN_ORDER_ENABLED, '1');
AppSettings::set(MarketingSettings::KEY_MIN_ORDER_AMOUNT_INR, '149');
AppSettings::set(MarketingSettings::KEY_MIN_ORDER_MESSAGE, 'Min order {amount} for checkout test.');
AppSettings::resetCache();
$totMin = PricingService::totalsFromLines($lines, null, '452010');
$ok(!empty($totMin['min_order_enabled']) && empty($totMin['min_order_met']), 'DB: minimum order independent of free delivery threshold');
AppSettings::set(MarketingSettings::KEY_MIN_ORDER_ENABLED, $minWas ?? '0');
AppSettings::resetCache();

foreach ($zoneIds as $id) {
    $pdo->exec('DELETE FROM delivery_zones WHERE id = ' . (int) $id);
}
AppSettings::set(MarketingSettings::KEY_FREE_DELIVERY_THRESHOLD, '499');

echo $fail > 0 ? "Failures: {$fail}\n" : "All free-delivery zone authority checks passed.\n";
exit($fail > 0 ? 1 : 0);
