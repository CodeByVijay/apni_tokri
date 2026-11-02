<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/test_cli_runner.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

if (!DeliverySlotReservationService::tableReady()) {
    fwrite(STDERR, "Apply 1.8.0_d2_slot_management.sql first.\n");
    exit(1);
}

$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    if ($c) {
        echo "OK: {$m}\n";
    } else {
        echo "FAIL: {$m}\n";
        $fail++;
    }
}

Install::ensureConnection();
$pdo = Database::pdo();
DeliverySlotReservationService::releaseExpiredHolds($pdo);
$pdo->exec("DELETE FROM delivery_slot_reservations WHERE order_id IN (SELECT id FROM orders WHERE order_number = 'D2-HOLD-A')");
$pdo->exec("DELETE FROM orders WHERE order_number = 'D2-HOLD-A'");

$morning = DeliverySlotRepository::findBySlug('morning');
ok($morning !== null, 'seed morning slot exists');

$testSlug = 'd2_test_' . substr(Security::randomToken(4), 0, 6);
$maskWeekendsOnly = (1 << 5) | (1 << 6);
$pdo->prepare(
    'INSERT INTO delivery_slots (slug, label, window_label, delivery_day, start_time, end_time, cutoff_time, capacity,
     sort_order, is_active, weekday_mask, booking_cutoff_minutes, allowed_provider_slugs_json)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
)->execute([
    $testSlug,
    'D2 Test',
    'Test window',
    'same_day',
    '23:00:00',
    '23:30:00',
    '22:00:00',
    1,
    9999,
    1,
    127,
    30,
    json_encode(['manual']),
]);
$testId = (int) $pdo->lastInsertId();
$testRow = DeliverySlotRepository::find($testId);
ok($testRow !== null, 'test slot created');

$weekdayRow = $testRow;
$weekdayRow['weekday_mask'] = $maskWeekendsOnly;
$today = DeliveryConfig::now()->format('Y-m-d');
$iso = (int) DeliveryConfig::now()->format('N');
$allowedToday = DeliverySlotService::isWeekdayAllowed($weekdayRow, $today);
ok($allowedToday === ($iso >= 6), 'weekday mask Sat/Sun only');

$tomorrow = DeliveryConfig::now()->modify('+1 day')->format('Y-m-d');
$deadline = DeliverySlotService::bookingDeadline($testRow, $today);
ok($deadline !== null, 'booking deadline computed');

$dateForTest = $today;

try {
    Database::transaction(static function () use ($pdo, $testId, $dateForTest, $testSlug): void {
        DeliverySlotReservationService::lockSlotAndAssertCapacity($pdo, $testId, $dateForTest, null);
        $pdo->prepare(
            "INSERT INTO orders (order_number, status, subtotal, discount_total, delivery_fee, grand_total,
             payment_status, delivery_slot, delivery_slot_id, delivery_date, placed_at)
             VALUES ('D2-HOLD-A', 'pending_payment', 10, 0, 0, 10, 'pending', ?, ?, ?, NOW())"
        )->execute([$testSlug, $testId, $dateForTest]);
        $orderA = (int) $pdo->lastInsertId();
        DeliverySlotReservationService::createHold($pdo, $testId, $dateForTest, $orderA, 30);
        $remaining = DeliverySlotService::remainingCapacity($testId, $dateForTest, $pdo);
        if ($remaining > 0) {
            throw new RuntimeException('capacity should be full after hold on cap=1');
        }
    });
    ok(true, 'hold consumes capacity on cap=1 slot');
} catch (Throwable $e) {
    ok(false, 'hold consumes capacity: ' . $e->getMessage());
}

$fullErr = DeliverySlotService::assertBookable($testRow, $dateForTest);
ok($fullErr !== null, 'full slot rejected');

try {
    $secondOk = false;
    Database::transaction(static function () use ($pdo, $testId, $dateForTest, &$secondOk): void {
        DeliverySlotReservationService::lockSlotAndAssertCapacity($pdo, $testId, $dateForTest, null);
        $secondOk = true;
    });
    ok(!$secondOk, 'concurrent booking blocked when slot full');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'full') || str_contains($e->getMessage(), 'not available'), 'concurrent booking blocked when slot full');
}

$orderAStmt = $pdo->prepare("SELECT id FROM orders WHERE order_number = 'D2-HOLD-A' LIMIT 1");
$orderAStmt->execute();
$orderAId = (int) $orderAStmt->fetchColumn();
DeliverySlotReservationService::releaseForOrder($orderAId, $pdo);
DeliverySlotReservationService::releaseExpiredHolds($pdo);
$afterRelease = DeliverySlotService::remainingCapacity($testId, $dateForTest, $pdo);
ok($afterRelease >= 1, 'payment failure releases slot hold');

ok(SubscriptionSlotService::validatePreferredSlug('morning') === null, 'subscription preferred morning slug valid');

$pdo->prepare('DELETE FROM delivery_slot_reservations WHERE delivery_slot_id = ?')->execute([$testId]);
$pdo->prepare('DELETE FROM orders WHERE order_number = ?')->execute(['D2-HOLD-A']);
$pdo->prepare('DELETE FROM delivery_slots WHERE id = ?')->execute([$testId]);

$out8 = [];
$code8 = test_cli_exec_script(APNI_TOKRI_ROOT . '/scripts/test-delivery-stage8.php', $out8);
ok($code8 === 0, 'Stage 8 delivery regression');
$outD1 = [];
$codeD1 = test_cli_exec_script(APNI_TOKRI_ROOT . '/scripts/test-delivery-d1.php', $outD1);
ok($codeD1 === 0, 'D1 zone regression');

if ($fail > 0) {
    echo "\n{$fail} failure(s).\n";
    exit(1);
}

echo "\nPASS: Delivery D2 slot checks.\n";
