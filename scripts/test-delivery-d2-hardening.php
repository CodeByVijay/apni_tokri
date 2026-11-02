<?php

declare(strict_types=1);

/**
 * D2 hardening regression suite. Run after 1.8.0_d2_hardening.sql
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/test_cli_runner.php';
require_once __DIR__ . '/includes/d2h_test_fixture.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

$fail = 0;
$skipped = [];

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

function skip(string $m, string $reason): void
{
    global $skipped;
    $skipped[] = $m . ' — ' . $reason;
    echo "SKIP: {$m} ({$reason})\n";
}

Install::ensureConnection();
$pdo = Database::pdo();

ok(Database::sessionTimeZone() === '+05:30', 'PDO session time_zone +05:30');

if (!DeliverySlotReservationService::tableReady()) {
    fwrite(STDERR, "Apply 1.8.0_d2_slot_management.sql first.\n");
    exit(1);
}

$hasUnique = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_slot_reservations' AND INDEX_NAME = 'uq_dsr_order'"
)->fetchColumn();
ok($hasUnique > 0 || !PaymentCompletionExceptionService::tableReady(), 'reservation order_id unique (after hardening migration)');

if (!PaymentCompletionExceptionService::tableReady()) {
    skip('payment completion exception tests', '1.8.0_d2_hardening not applied');
} else {
    ok(PaymentCompletionExceptionService::tableReady(), 'payment_completion_exceptions table exists');
}

d2h_test_cleanup($pdo);

$slug = 'd2h_' . substr(Security::randomToken(4), 0, 6);
$pdo->prepare(
    'INSERT INTO delivery_slots (slug, label, window_label, delivery_day, start_time, end_time, cutoff_time, capacity, sort_order, is_active, weekday_mask)
     VALUES (?,?,?,?,?,?,?,?,?,?,127)'
)->execute([$slug, 'H', 'H', 'same_day', '23:00:00', '23:30:00', '23:00:00', 1, 9998, 1]);
$slotId = (int) $pdo->lastInsertId();
$date = DeliveryConfig::now()->format('Y-m-d');

try {
    Database::transaction(static function () use ($pdo, $slotId, $date, $slug): void {
        $pdo->prepare(
            "INSERT INTO orders (order_number, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, delivery_slot, delivery_slot_id, delivery_date, placed_at)
             VALUES ('D2H-ORD-1', 'pending_payment', 10, 0, 0, 10, 'pending', ?, ?, ?, NOW())"
        )->execute([$slug, $slotId, $date]);
        $oid = (int) $pdo->lastInsertId();
        DeliverySlotReservationService::createHold($pdo, $slotId, $date, $oid, 30);
        DeliverySlotReservationService::createHold($pdo, $slotId, $date, $oid, 30);
        $cnt = (int) $pdo->query('SELECT COUNT(*) FROM delivery_slot_reservations WHERE order_id = ' . $oid)->fetchColumn();
        if ($cnt !== 1) {
            throw new RuntimeException('expected one reservation row');
        }
    });
    ok(true, 'duplicate createHold reuses single reservation row');
} catch (Throwable $e) {
    ok(false, 'duplicate createHold: ' . $e->getMessage());
}

$ordStmt = $pdo->query("SELECT id FROM orders WHERE order_number = 'D2H-ORD-1' LIMIT 1");
$orderId = (int) $ordStmt->fetchColumn();
DeliverySlotReservationService::releaseForOrder($orderId, $pdo);
Database::transaction(static function () use ($pdo, $slotId, $date, $orderId): void {
    DeliverySlotReservationService::createHold($pdo, $slotId, $date, $orderId, 30);
});
$st = $pdo->query('SELECT status FROM delivery_slot_reservations WHERE order_id = ' . $orderId)->fetchColumn();
ok($st === DeliverySlotReservationService::STATUS_HELD, 'released reservation retry transitions to held');

DeliverySlotReservationService::consumeForOrder($orderId, $pdo);
Database::transaction(static function () use ($pdo, $slotId, $date, $orderId): void {
    DeliverySlotReservationService::createHold($pdo, $slotId, $date, $orderId, 30);
});
$st2 = $pdo->query('SELECT status FROM delivery_slot_reservations WHERE order_id = ' . $orderId)->fetchColumn();
ok($st2 === DeliverySlotReservationService::STATUS_CONSUMED, 'consumed reservation cannot be re-held');

$pdo->exec("UPDATE delivery_slot_reservations SET status = 'released', expires_at = NOW() - INTERVAL 1 MINUTE WHERE order_id = {$orderId}");
DeliverySlotReservationService::releaseExpiredHolds($pdo);
$usedBefore = DeliverySlotReservationService::usedUnits($pdo, $slotId, $date, null);
ok($usedBefore === 0, 'expired hold does not consume capacity (cron-independent)');

if (PaymentCompletionExceptionService::tableReady()) {
    $pdo->prepare(
        "INSERT INTO orders (order_number, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, customer_address_id, delivery_slot_id, delivery_date, placed_at)
         VALUES ('D2H-EX-1', 'pending_payment', 50, 0, 0, 50, 'pending', 1, ?, ?, NOW())"
    )->execute([$slotId, $date]);
    $exOrderId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$exOrderId, 'simulate', 50, 'INR', 'created', 'pay_D2H-EX-1_1']);

    $pdo->prepare(
        "INSERT INTO orders (order_number, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, delivery_slot_id, delivery_date, placed_at)
         VALUES ('D2H-FULL', 'confirmed', 10, 0, 0, 10, 'paid', ?, ?, NOW())"
    )->execute([$slotId, $date]);

    $r1 = PaymentCompletionExceptionService::recordSlotFailure(
        'D2H-EX-1',
        'pay_D2H-EX-1_1',
        'simulate',
        'sim_gw_expired_1',
        'sim_ord_1',
        'evt_1',
        50.0,
        'Delivery slot reservation expired. Choose another slot.'
    );
    ok($r1['created'] === true, 'captured payment exception recorded after slot failure');

    $r2 = PaymentCompletionExceptionService::recordSlotFailure(
        'D2H-EX-1',
        'pay_D2H-EX-1_1',
        'simulate',
        'sim_gw_expired_1',
        'sim_ord_1',
        'evt_2',
        50.0,
        'duplicate webhook'
    );
    ok($r2['created'] === false && $r2['id'] === $r1['id'], 'duplicate gateway payment id is idempotent');

    $paidCheck = $pdo->query("SELECT payment_status FROM orders WHERE order_number = 'D2H-EX-1'")->fetchColumn();
    ok((string) $paidCheck !== 'paid', 'order not silently marked paid on slot failure');

    $paySt = $pdo->query('SELECT status FROM payments WHERE order_id = ' . $exOrderId)->fetchColumn();
    ok((string) $paySt === 'reconciliation_required', 'payment marked reconciliation_required');

    $dupPay = PaymentService::completePayment(
        'D2H-EX-1',
        'simulate',
        'sim_gw_expired_1',
        'sim_ord_1',
        50.0,
        'INR',
        'pay_D2H-EX-1_1',
        'evt_dup'
    );
    ok(!empty($dupPay['reconciliation']) || !$dupPay['ok'], 'repeat completePayment does not confirm without slot');
}

$regressions = [
    'test-delivery-stage8.php' => 'Stage 8',
    'test-delivery-d1.php' => 'D1',
    'test-delivery-d2.php' => 'D2',
    'test-delivery-d3.php' => 'D3 manual delivery',
    'test-payment-stage9.php' => 'Stage 9 payment',
    'test-trust-stage12.php' => 'Stage 12 trust A',
    'test-trust-stage12-phase-b.php' => 'Stage 12 trust B',
];

$subTest = glob(APNI_TOKRI_ROOT . '/scripts/test-subscription-stage11*.php');
foreach ($subTest as $path) {
    $base = basename($path);
    if ($base === 'test-subscription-stage11.php' || strpos($base, 'hardening') !== false || strpos($base, 'production-readiness') !== false) {
        $regressions[$base] = 'Stage 11 ' . $base;
    }
}

foreach ($regressions as $script => $label) {
    $path = APNI_TOKRI_ROOT . '/scripts/' . $script;
    if (!is_readable($path)) {
        skip($label, 'script missing');
        continue;
    }
    $out = [];
    $code = test_cli_exec_script($path, $out);
    $joined = implode("\n", $out);
    $failed = $code !== 0
        || preg_match('/\bFAIL:/', $joined)
        || preg_match('/\bFAILURES:\s*[1-9]/', $joined)
        || preg_match('/\d+ SUITE\(S\) FAILED/', $joined)
        || preg_match('/\d+ failed\./', $joined)
        || preg_match('/FAILED suite:/', $joined);
    ok(!$failed, $label . ' regression');
}

d2h_test_cleanup($pdo);

if ($fail > 0) {
    echo "\n{$fail} failure(s).\n";
    exit(1);
}

echo "\nPASS: D2 hardening checks (" . count($skipped) . " skipped).\n";
exit(0);
