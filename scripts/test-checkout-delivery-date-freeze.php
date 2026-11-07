<?php

declare(strict_types=1);

/**
 * Frozen delivery date at checkout + payment revalidation (midnight-safe).
 * Run: php scripts/run-in-disposable-db.php scripts/test-checkout-delivery-date-freeze.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$root = dirname(__DIR__);
$checkoutPhp = (string) file_get_contents($root . '/checkout.php');
$checkoutSvc = (string) file_get_contents($root . '/includes/commerce/checkout.php');
$quotePhp = (string) file_get_contents($root . '/includes/delivery/quote.php');
$cartJs = (string) file_get_contents($root . '/assets/js/cart.js');
$slotsAdmin = (string) file_get_contents($root . '/admin/settings/delivery/slots.php');

ok(str_contains($checkoutPhp, 'name="delivery_date"'), 'Checkout posts delivery_date');
ok(str_contains($checkoutPhp, 'data-delivery-date'), 'Slot radios carry resolved delivery_date');
ok(str_contains($checkoutSvc, 'validateCustomerSlotDate'), 'placeOrder validates slot + date');
ok(str_contains($checkoutSvc, 'quoteForCheckout($address, $lines, $slot, $totals, null, null, $slotDateParam)'), 'placeOrderDb passes frozen date to quote');
ok(str_contains($quotePhp, '$deliveryDate !== \'\' ? $deliveryDate : null'), 'Payment revalidation passes order delivery_date to quote');
ok(str_contains($quotePhp, 'Delivery date changed since checkout'), 'Revalidation rejects quote date drift');
ok(str_contains($cartJs, 'delivery_date'), 'Checkout AJAX sends delivery_date');
ok(str_contains($slotsAdmin, 'Orders (all dates)'), 'Admin slot list labels all-dates aggregate');

if (!Install::isSchemaReady() || !DeliverySlotReservationService::tableReady()) {
    fwrite(STDERR, "Schema not ready — static checks only.\n");
    exit($fail > 0 ? 1 : 0);
}

Install::ensureConnection();
$pdo = Database::pdo();
$tok = strtolower(bin2hex(random_bytes(4)));
$sameDay = DeliveryConfig::now()->format('Y-m-d');
$nextDay = DeliveryConfig::now()->modify('+1 day')->format('Y-m-d');
$frozenNext = $nextDay;

$pdo->exec(
    "INSERT INTO delivery_slots (slug, label, window_label, delivery_day, start_time, end_time, cutoff_time, capacity, weekday_mask, is_active, sort_order)
     VALUES ('zt-sd-$tok', 'Same', 'AM', 'same_day', '09:00:00', '12:00:00', '23:59:59', 5, 127, 1, 995)"
);
$slotSame = (int) $pdo->lastInsertId();
$pdo->exec(
    "INSERT INTO delivery_slots (slug, label, window_label, delivery_day, start_time, end_time, cutoff_time, capacity, weekday_mask, is_active, sort_order)
     VALUES ('zt-nd-$tok', 'Next', 'AM', 'next_day', '09:00:00', '12:00:00', '23:59:59', 5, 127, 1, 996)"
);
$slotNext = (int) $pdo->lastInsertId();

$customerId = (int) $pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();
if ($customerId <= 0) {
    $pdo->exec("INSERT INTO customers (mobile, name, is_active) VALUES ('9" . random_int(100000000, 999999999) . "', 'Freeze', 1)");
    $customerId = (int) $pdo->lastInsertId();
}

$addr = $pdo->query('SELECT * FROM customer_addresses WHERE customer_id = ' . $customerId . ' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$addr) {
    $pdo->prepare(
        'INSERT INTO customer_addresses (customer_id, label, line1, city, state, pincode, is_default) VALUES (?, ?, ?, ?, ?, ?, 1)'
    )->execute([$customerId, 'Home', 'Line 1', 'Indore', 'MP', '452001']);
    $addr = $pdo->query('SELECT * FROM customer_addresses WHERE customer_id = ' . $customerId . ' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
}

$lines = [['variant_id' => 0, 'qty' => 1, 'name' => 'Test', 'unit_price' => 100.0]];
$vid = (int) $pdo->query('SELECT id FROM product_variants WHERE is_active = 1 LIMIT 1')->fetchColumn();
if ($vid > 0) {
    $lines = [['variant_id' => $vid, 'qty' => 1, 'name' => 'Test', 'unit_price' => 100.0]];
}

$quoteLiveNext = DeliveryQuoteService::quoteForCheckout($addr, $lines, 'zt-nd-' . $tok, null, $pdo);
$quoteFrozen = DeliveryQuoteService::quoteForCheckout($addr, $lines, 'zt-nd-' . $tok, null, $pdo, null, $frozenNext);
ok(!empty($quoteFrozen['ok']), 'Frozen quote succeeds for next_day slot');
ok((string) ($quoteFrozen['delivery_date'] ?? '') === $frozenNext, 'Frozen quote keeps posted delivery_date');

if (!empty($quoteLiveNext['ok'])) {
    $liveDate = (string) ($quoteLiveNext['delivery_date'] ?? '');
    ok($liveDate === $nextDay, 'Live next_day quote uses calendar tomorrow');
    if ($liveDate !== $frozenNext && $frozenNext === $sameDay) {
        ok((string) ($quoteFrozen['delivery_date'] ?? '') === $sameDay, 'Override can differ from live resolveBySlug (midnight scenario)');
    } else {
        ok((string) ($quoteFrozen['delivery_date'] ?? '') === $frozenNext, 'Frozen date stable vs live next_day');
    }
}

ok(DeliverySlotService::validateCustomerSlotDate('zt-sd-' . $tok, $sameDay) === null, 'validateCustomerSlotDate accepts bookable same_day');
ok(DeliverySlotService::validateCustomerSlotDate('zt-sd-' . $tok, $nextDay) !== null, 'validateCustomerSlotDate rejects wrong calendar date for same_day slug');

// Reservation + capacity on frozen date
$pdo->prepare(
    'INSERT INTO orders (order_number, customer_id, customer_address_id, status, subtotal, discount_total, delivery_fee, grand_total,
     payment_status, delivery_slot, delivery_slot_id, delivery_date, placed_at)
     VALUES (?, ?, ?, ?, 100, 0, 0, 100, ?, ?, ?, ?, NOW())'
)->execute(['ZT-FZ-' . $tok, $customerId, (int) $addr['id'], 'pending_payment', 'pending', 'zt-nd-' . $tok, $slotNext, $frozenNext]);
$orderId = (int) $pdo->lastInsertId();
DeliverySlotReservationService::createHold($pdo, $slotNext, $frozenNext, $orderId, 30);

$usedFrozen = DeliverySlotReservationService::usedUnits($pdo, $slotNext, $frozenNext, null);
$usedLive = DeliverySlotReservationService::usedUnits($pdo, $slotNext, $liveDate ?? $nextDay, null);
ok($usedFrozen >= 1, 'Hold consumes frozen-date capacity');
if (($liveDate ?? $nextDay) !== $frozenNext) {
    ok($usedLive < $usedFrozen, 'Different calendar date does not count frozen hold');
}

$gridBreakdown = DeliverySlotReservationService::capacityBreakdown($pdo, $slotNext, $frozenNext);
$allDatesCount = DeliverySlotRepository::capacityConsumingOrderCount($slotNext);
ok((int) ($gridBreakdown['used'] ?? 0) >= 1, '7-day grid breakdown includes frozen-date hold');
ok($allDatesCount >= (int) ($gridBreakdown['used'] ?? 0), 'All-dates Orders aggregate >= today grid cell');

// Payment revalidation uses frozen order row
$orderRow = $pdo->query('SELECT * FROM orders WHERE id = ' . $orderId)->fetch(PDO::FETCH_ASSOC);
$items = [];
try {
    DeliveryQuoteService::revalidateBeforePaymentComplete($orderRow, $items, $pdo);
    ok(true, 'Revalidation with frozen delivery_date succeeds');
} catch (DeliverySlotBookingException $e) {
    ok(false, 'Revalidation should succeed: ' . $e->getMessage());
}

$pdo->exec('DELETE FROM delivery_slot_reservations WHERE order_id = ' . $orderId);
$pdo->exec('DELETE FROM orders WHERE id = ' . $orderId);
$pdo->exec('DELETE FROM delivery_slots WHERE id IN (' . $slotSame . ', ' . $slotNext . ')');

echo $fail === 0 ? "\nPASS: checkout delivery date freeze.\n" : "\nFailures: {$fail}\n";
exit($fail > 0 ? 1 : 0);
