<?php

declare(strict_types=1);

/**
 * Phase 3.1 — cart delivery-charge calculation without slot selection,
 * plus guest/session-address quoting, against a disposable database.
 *
 * CLI: php scripts/run-in-disposable-db.php scripts/test-cart-delivery-fee.php
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
function feeOk(bool $c, string $l): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $l . PHP_EOL;
    if (!$c) {
        ++$fail;
    }
}

$pdo = Database::pdo();
$zone = '452010';
$zoneProbe = DeliveryService::evaluatePincode($zone);
$zoneFee = (float) ($zoneProbe['zone']['flat_fee'] ?? 0);
$zoneFree = (float) ($zoneProbe['zone']['free_above'] ?? 0);
feeOk($zoneFee > 0 && $zoneFree > 0, "fixture: zone resolves (fee=$zoneFee free_above=$zoneFree)");

// Seeded variant from the disposable runner
$variantId = (int) $pdo->query("SELECT id FROM product_variants WHERE sku='TMP-0-500' LIMIT 1")->fetchColumn();
feeOk($variantId > 0, 'fixture: seeded variant exists');
$variantPrice = (float) $pdo->query("SELECT price FROM product_variants WHERE id=$variantId")->fetchColumn();

// Fixture customers
$pdo->exec("DELETE FROM customer_addresses WHERE customer_id IN (SELECT id FROM customers WHERE mobile IN ('9700222001','9700222002'))");
$pdo->exec("DELETE FROM customers WHERE mobile IN ('9700222001','9700222002')");
$pdo->exec("INSERT INTO customers (mobile, name, is_active) VALUES ('9700222001','Fee Guest',1),('9700222002','Fee Default',1)");
$cg = (int) $pdo->query("SELECT id FROM customers WHERE mobile='9700222001'")->fetchColumn();
$cd = (int) $pdo->query("SELECT id FROM customers WHERE mobile='9700222002'")->fetchColumn();
$pdo->prepare("INSERT INTO customer_addresses (customer_id,label,receiver_name,receiver_phone,line1,area,city,state,pincode,is_default) VALUES (?,'Home','Fee Default','9700222002','9 Default Lane','Vijay Nagar','Indore','MP',?,1)")
    ->execute([$cd, $zone]);
$defAddrId = (int) $pdo->lastInsertId();

function guestCart(int $variantId, int $qty, float $price): int
{
    $sid = 'feetest_' . bin2hex(random_bytes(6));
    $_SESSION['guest_cart_sid'] = $sid;
    $pdo = Database::pdo();
    $pdo->prepare('INSERT INTO carts (session_id, created_at, updated_at) VALUES (?, NOW(), NOW())')->execute([$sid]);
    $cid = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO cart_items (cart_id, variant_id, quantity, unit_price, created_at) VALUES (?,?,?,?,NOW())')
        ->execute([$cid, $variantId, $qty, $price]);
    return $cid;
}

function guestSelect(array $over): array
{
    $v = DeliverySessionAddress::validate(array_merge([
        'address_type' => 'Home', 'house_number' => '42-B', 'street' => 'Scheme 54',
        'area' => 'Vijay Nagar', 'city' => 'Indore', 'state' => 'Madhya Pradesh',
        'pincode' => '452010', 'latitude' => '22.7197', 'longitude' => '75.8577',
        'location_confirmed' => '1',
    ], $over));
    if ($v['errors'] !== []) {
        return ['__invalid' => true];
    }
    $snap = DeliverySessionAddress::refreshServiceability($v['clean']);
    DeliverySessionAddress::store($snap);
    return $snap;
}

CustomerAuth::logout();
DeliverySessionAddress::clear();

// ---- 1. Guest + selected address → fee without slot -------------------------
guestCart($variantId, 2, $variantPrice);
guestSelect([]);
$totals = DemoCart::totals();
feeOk(!empty($totals['delivery_quote_accurate']), 'guest+address: quote accurate without slot');
feeOk(!empty($totals['delivery_serviceable']), 'guest+address: serviceable');
feeOk(abs((float) ($totals['delivery_fee'] ?? 0) - $zoneFee) < 0.01, 'guest+address: fee is zone flat fee');
feeOk(strpos((string) ($totals['delivery_quote_message'] ?? ''), 'slot') === false, 'guest+address: no slot message');
$banner = CartDeliveryBanner::fromTotals($totals);
feeOk(strpos(strtolower($banner['message']), 'slot') === false, 'banner: no slot prompt');
feeOk(strpos($banner['message'], 'Delivery charge') !== false || strpos($banner['message'], 'Free') !== false, 'banner: shows charge/free');

// ---- 2. Guest with NO address → select-address message (unchanged) ----------
DeliverySessionAddress::clear();
DeliveryContext::clearPin();
$totals = DemoCart::totals();
feeOk(empty($totals['delivery_quote_accurate']), 'no-address: quote not accurate');
feeOk(strpos((string) ($totals['delivery_quote_message'] ?? ''), 'delivery address') !== false, 'no-address: select-address message');

// ---- 3. Unserviceable address → honest message ------------------------------
guestSelect(['pincode' => '700001', 'latitude' => '22.5726', 'longitude' => '88.3639']);
$totals = DemoCart::totals();
feeOk(empty($totals['delivery_serviceable']), 'unserviceable: not serviceable');
feeOk((float) ($totals['delivery_fee'] ?? 1) === 0.0, 'unserviceable: fee zero');
feeOk(strpos((string) ($totals['delivery_quote_message'] ?? ''), 'slot') === false, 'unserviceable: no slot message');

// ---- 4. Free-delivery threshold reached -------------------------------------
guestSelect([]);
$bigQty = (int) ceil($zoneFree / $variantPrice) + 1;
$bigCart = guestCart($variantId, $bigQty, $variantPrice); // subtotal above free_above
$totals = DemoCart::totals();
feeOk((float) ($totals['delivery_fee'] ?? 1) === 0.0, 'free-threshold: fee is zero');
feeOk(!empty($totals['free_delivery_eligible']) || (float) ($totals['amount_to_free_delivery'] ?? 1) === 0.0, 'free-threshold: eligible/zero remaining');

// ---- 5. Below threshold → progress remaining --------------------------------
$pdo->exec("DELETE FROM cart_items WHERE cart_id=$bigCart");
$smallQty = max(1, (int) floor(($zoneFree / 2) / $variantPrice));
$pdo->prepare('INSERT INTO cart_items (cart_id, variant_id, quantity, unit_price, created_at) VALUES (?,?,?,?,NOW())')->execute([$bigCart, $variantId, $smallQty, $variantPrice]);
$totals = DemoCart::totals();
feeOk(abs((float) ($totals['delivery_fee'] ?? 0) - $zoneFee) < 0.01, 'below-threshold: flat fee applies');
feeOk(abs((float) ($totals['amount_to_free_delivery'] ?? 0) - ($zoneFree - $smallQty * $variantPrice)) < 0.01, 'below-threshold: correct remaining to free');

// ---- 6. Logged-in customer, default address, migrated-style saved selection --
CustomerAuth::loginById($cd);
guestCart($variantId, 2, $variantPrice); // becomes session guest cart; merge happens on real login, but resolveCartId prefers customer cart — create one
$cCart = guestCart($variantId, 2, $variantPrice);
$pdo->exec("UPDATE carts SET customer_id=$cd, session_id=NULL WHERE id=$cCart");
DeliverySessionAddress::clear();
$totals = DemoCart::totals();
feeOk(!empty($totals['delivery_serviceable']), 'logged-in default: serviceable');
feeOk(abs((float) ($totals['delivery_fee'] ?? 0) - $zoneFee) < 0.01, 'logged-in default: zone flat fee');

// Non-default saved address selected in session
$pdo->prepare("INSERT INTO customer_addresses (customer_id,label,receiver_name,receiver_phone,line1,area,city,state,pincode,is_default) VALUES (?,'Work','Fee Default','9700222002','1 Work St','Palasia','Indore','MP','452001',0)")
    ->execute([$cd]);
$workId = (int) $pdo->lastInsertId();
$row = CustomerService::address($workId, $cd);
DeliverySessionAddress::store(DeliverySessionAddress::refreshServiceability(DeliverySessionAddress::snapshotFromSavedRow($row)));
$totals = DemoCart::totals();
feeOk((string) ($totals['delivery_zone'] ?? '') !== '', 'saved-selection: zone resolved');
feeOk(!empty($totals['delivery_serviceable']), 'saved-selection: serviceable');

// ---- 7. Slot machinery unchanged --------------------------------------------
$lines = CartService::items();
$addrRow = CustomerService::address($defAddrId, $cd);
$qAuto = DeliveryQuoteService::quoteForCheckout($addrRow, $lines, 'auto');
feeOk(!empty($qAuto['ok']), 'quote auto: succeeds as preview');
feeOk(!isset($qAuto['slot_id']) || $qAuto['slot_id'] === null, 'quote auto: no slot_id');
$qBad = DeliveryQuoteService::quoteForCheckout($addrRow, $lines, 'not-a-slot');
feeOk(empty($qBad['ok']), 'quote bogus-slot: still rejected');
$qReal = DeliveryQuoteService::quoteForCheckout($addrRow, $lines, 'bad-slot-xyz', null, null, null, '2030-01-01');
feeOk(empty($qReal['ok']), 'quote bogus-slot+date: still rejected');

// ---- 8. Non-legacy zone mode: fee without slot (staging path) ----------------
$prevMode = AppSettings::get('delivery.zone_resolution_mode');
AppSettings::set('delivery.zone_resolution_mode', 'distance_hybrid');
CustomerAuth::logout();
DeliverySessionAddress::clear();
$_SESSION['guest_cart_sid'] = null;
guestCart($variantId, 2, $variantPrice);
guestSelect([]); // 452010 + coords
$totals = DemoCart::totals();
$nlMsg = (string) ($totals['delivery_quote_message'] ?? '');
feeOk(strpos($nlMsg, 'slot') === false, 'non-legacy: no slot gate (msg=' . ($nlMsg === '' ? 'none' : $nlMsg) . ')');
feeOk(!empty($totals['delivery_quote_accurate']) && !empty($totals['delivery_serviceable']), 'non-legacy: quote accurate+serviceable without slot');
feeOk((float) ($totals['delivery_fee'] ?? 0) > 0 || !empty($totals['free_delivery_eligible']), 'non-legacy: fee resolved');
feeOk(trim((string)($totals['delivery_zone'] ?? '')) !== '', 'non-legacy: zone resolved');
// same address+cart: explicit valid slot must not change the fee
$noSlotFee = (float) ($totals['delivery_fee'] ?? -1);
$slotSlugRow = $pdo->query("SELECT slug FROM delivery_slots WHERE is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
$guestRow = [
    'pincode' => '452010', 'latitude' => 22.7197, 'longitude' => 75.8577,
];
$linesQ = CartService::items();
if ($slotSlugRow !== false && $slotSlugRow !== null && $slotSlugRow !== '') {
    $qNoSlot = DeliveryQuoteService::quoteForCheckout($guestRow, $linesQ, 'auto');
    $future = date('Y-m-d', strtotime('+3 days'));
    $qSlot = DeliveryQuoteService::quoteForCheckout($guestRow, $linesQ, (string) $slotSlugRow, null, null, null, $future);
    if (!empty($qSlot['ok']) && !empty($qNoSlot['ok'])) {
        feeOk(abs((float) $qSlot['delivery_fee'] - (float) $qNoSlot['delivery_fee']) < 0.01,
            "slot vs no-slot: same fee (" . (float) $qNoSlot['delivery_fee'] . ")");
    } else {
        echo 'INFO: slot quote ' . (empty($qSlot['ok']) ? 'rejected(' . ($qSlot['message'] ?? '?') . ')' : 'ok')
            . ', auto quote ' . (empty($qNoSlot['ok']) ? 'rejected' : 'ok') . PHP_EOL;
        feeOk(!empty($qNoSlot['ok']), 'auto quote ok regardless');
    }
} else {
    echo "SKIP: no active slot rows for fee-equality check" . PHP_EOL;
}
AppSettings::set('delivery.zone_resolution_mode', $prevMode);

echo ($fail === 0 ? 'ALL OK' : "FAILURES: $fail") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
