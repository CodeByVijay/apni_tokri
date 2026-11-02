<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready — run migrations through 1.3.0.\n");
    exit(1);
}

$fail = 0;

function assertTrue(bool $cond, string $label): void
{
    global $fail;
    if ($cond) {
        echo "OK: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $fail++;
    }
}

Install::ensureConnection();

assertTrue(DeliveryConfig::timezone() === 'Asia/Kolkata', 'timezone Asia/Kolkata');

$inside = DeliveryService::evaluatePincode('452010');
assertTrue($inside['serviceable'], '452010 serviceable');
$outside = DeliveryService::evaluatePincode('110001');
assertTrue(!$outside['serviceable'], '110001 not serviceable');
assertTrue(!DeliveryService::isPincodeServiceable('110001'), 'checkout block outside pin');

$mixed = [
    ['qty' => 1, 'unit_price' => 500.0, 'grants_free_delivery' => true],
    ['qty' => 1, 'unit_price' => 50.0, 'grants_free_delivery' => false],
];
$feeMixed = DeliveryService::computeDeliveryFee($mixed, 550.0, '452010');
assertTrue((float) $feeMixed['eligible_subtotal'] === 50.0, 'mixed cart eligible subtotal excludes free-delivery SKU');
assertTrue((float) $feeMixed['fee'] === 40.0, 'mixed cart still charged delivery when eligible subtotal below threshold');

$eligibleOnly = [
    ['qty' => 1, 'unit_price' => 520.0, 'grants_free_delivery' => false],
];
$feeFree = DeliveryService::computeDeliveryFee($eligibleOnly, 520.0, '452010');
assertTrue((float) $feeFree['fee'] === 0.0, 'free delivery when eligible subtotal meets threshold');

$allFreeSkus = [
    ['qty' => 2, 'unit_price' => 100.0, 'grants_free_delivery' => true],
];
$feeAllFree = DeliveryService::computeDeliveryFee($allFreeSkus, 200.0, '452010');
assertTrue((float) $feeAllFree['fee'] === 0.0, 'all free-delivery SKUs → zero fee');

$slots = DeliverySlotService::availableForCheckout();
if ($slots !== []) {
    $first = $slots[0];
    $resolved = DeliverySlotService::resolveBySlug((string) $first['slug']);
    assertTrue($resolved !== null && isset($resolved['row']), 'slot resolve includes row for capacity check');
    $err = DeliverySlotService::assertBookable($resolved['row'], (string) $resolved['delivery_date']);
    assertTrue($err === null, 'first available slot is bookable');
} else {
    echo "SKIP: no slots before cutoff (capacity/cutoff OK if empty)\n";
}

$stmt = Database::pdo()->query('SELECT id, capacity FROM delivery_slots WHERE is_active = 1 LIMIT 1');
$slotRow = $stmt->fetch(PDO::FETCH_ASSOC);
if ($slotRow) {
    $slotId = (int) $slotRow['id'];
    $date = DeliveryConfig::now()->format('Y-m-d');
    $remaining = DeliverySlotService::remainingCapacity($slotId, $date);
    assertTrue($remaining >= 0 && $remaining <= (int) $slotRow['capacity'], 'remaining capacity within bounds');
}

if ($fail > 0) {
    echo "\n{$fail} assertion(s) failed.\n";
    exit(1);
}

echo "\nPASS: Stage 8 delivery checks.\n";
exit(0);
