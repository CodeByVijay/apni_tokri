<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready — run migration 1.6.1.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

/** @return array{sub_id: int, number: string, idempotency: string, price: float, snapshot: array<string, mixed>} */
function fixturePendingSub(PDO $pdo, int $planId, int $customerId, ?float $priceOverride = null): array
{
    $plan = SubscriptionPlanRepository::find($planId);
    if ($plan === null) {
        throw new RuntimeException('Plan missing');
    }
    $price = $priceOverride ?? (float) $plan['price'];
    $snapshot = SubscriptionPlanRepository::toSnapshotArray($plan, $price);
    $number = 'SUB-T-' . strtoupper(substr(Security::randomToken(6), 0, 8));
    $pdo->prepare(
        'INSERT INTO customer_subscriptions (subscription_number, customer_id, subscription_plan_id,
         plan_snapshot_json, price_paid, status, deliveries_remaining)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $number,
        $customerId,
        $planId,
        json_encode($snapshot, JSON_UNESCAPED_UNICODE),
        $price,
        'pending_payment',
        (int) $plan['delivery_count'],
    ]);
    $subId = (int) $pdo->lastInsertId();
    $idempotency = 'subpay_test_' . $number;
    $pdo->prepare(
        'INSERT INTO subscription_payments (customer_subscription_id, gateway, amount, currency, status, idempotency_key)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$subId, 'simulate', $price, 'INR', 'created', $idempotency]);

    return [
        'sub_id' => $subId,
        'number' => $number,
        'idempotency' => $idempotency,
        'price' => $price,
        'snapshot' => $snapshot,
    ];
}

$customerId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
if ($customerId <= 0) {
    $mobile = '98' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
    $pdo->prepare('INSERT INTO customers (mobile, name, created_at) VALUES (?, ?, NOW())')->execute([$mobile, 'Stage 11 B Test']);
    $customerId = (int) $pdo->lastInsertId();
}
ok($customerId > 0, 'Test customer exists');

$plan = SubscriptionPlanRepository::findByTemplateKey('veg_7');
ok($plan !== null, 'veg_7 plan present');
if ($plan === null) {
    exit(1);
}
$planId = (int) $plan['id'];

// Successful payment + calendar
$fx = fixturePendingSub($pdo, $planId, $customerId);
$act = SubscriptionActivationService::activateFromPayment(
    $fx['number'],
    $fx['price'],
    'simulate',
    'sim_pay_' . Security::randomToken(4),
    'sim_ord_' . $fx['number'],
    $fx['idempotency']
);
ok($act['ok'], 'Successful payment activates subscription');
$subRow = CustomerSubscriptionRepository::findByNumber($fx['number']);
ok($subRow !== null && (string) $subRow['status'] === 'active', 'Status is active');
$days = CustomerSubscriptionRepository::deliveryDays((int) $subRow['id']);
ok(count($days) === (int) $fx['snapshot']['delivery_count'], 'Calendar day count matches snapshot delivery_count');
$expectedDates = SubscriptionScheduleGenerator::generateDeliveryDates($fx['snapshot'], (string) $subRow['start_date']);
ok($expectedDates['ok'], 'Snapshot schedule validates');
$actualDates = array_column($days, 'delivery_date');
ok($actualDates === ($expectedDates['dates'] ?? []), 'Calendar dates match snapshot generator');

// Duplicate activation (webhook retry)
$dup = SubscriptionActivationService::activateFromPayment(
    $fx['number'],
    $fx['price'],
    'simulate',
    'sim_pay_dup',
    'sim_ord_' . $fx['number'],
    $fx['idempotency'],
    'evt_dup_' . Security::randomToken(3)
);
ok($dup['ok'] && !empty($dup['idempotent']), 'Duplicate payment is idempotent');
$days2 = CustomerSubscriptionRepository::deliveryDays((int) $subRow['id']);
ok(count($days2) === count($days), 'Duplicate webhook did not add calendar rows');

// Failed payment — no calendar
$fxFail = fixturePendingSub($pdo, $planId, $customerId);
SubscriptionActivationService::markPaymentFailed($fxFail['number'], $fxFail['idempotency'], 'test fail');
$failSub = CustomerSubscriptionRepository::findByNumber($fxFail['number']);
ok($failSub !== null && (string) $failSub['status'] === 'payment_failed', 'Failed payment marks subscription failed');
ok(CustomerSubscriptionRepository::deliveryDays((int) $failSub['id']) === [], 'Failed payment creates no delivery days');

// Price tampering
$fxTamper = fixturePendingSub($pdo, $planId, $customerId);
$bad = SubscriptionActivationService::activateFromPayment(
    $fxTamper['number'],
    1.0,
    'simulate',
    'sim_bad',
    'sim_ord_bad',
    $fxTamper['idempotency']
);
ok(!$bad['ok'], 'Wrong amount rejected');
$pending = CustomerSubscriptionRepository::findByNumber($fxTamper['number']);
ok($pending !== null && (string) $pending['status'] === 'pending_payment', 'Tampered amount leaves subscription pending');

// Inactive plan checkout
$pdo->prepare('UPDATE subscription_plans SET is_active = 0 WHERE id = ?')->execute([$planId]);
$inactiveTry = SubscriptionCheckoutService::beginPurchase($planId, $customerId);
ok(!$inactiveTry['ok'], 'Inactive plan cannot begin purchase');
$pdo->prepare('UPDATE subscription_plans SET is_active = 1 WHERE id = ?')->execute([$planId]);

// Snapshot immutability vs admin plan edit
$fxSnap = fixturePendingSub($pdo, $planId, $customerId, 1234.56);
$oldSnap = CustomerSubscriptionRepository::decodeSnapshot(CustomerSubscriptionRepository::findByNumber($fxSnap['number']) ?? []);
$pdo->prepare('UPDATE subscription_plans SET price = price + 500 WHERE id = ?')->execute([$planId]);
SubscriptionActivationService::activateFromPayment(
    $fxSnap['number'],
    1234.56,
    'simulate',
    'sim_snap',
    'sim_ord_snap',
    $fxSnap['idempotency']
);
$after = CustomerSubscriptionRepository::findByNumber($fxSnap['number']);
$newSnap = CustomerSubscriptionRepository::decodeSnapshot($after ?? []);
ok((float) ($newSnap['price_paid'] ?? 0) === 1234.56, 'Activated subscription keeps snapshotted price');
ok($oldSnap['price_paid'] === $newSnap['price_paid'], 'plan_snapshot_json price unchanged by admin edit');

// Storefront lists only active
$activeCount = count(SubscriptionPlanRepository::activeForStorefront());
ok($activeCount >= 1, 'Active plans visible for storefront');

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Stage 11 Phase B.\n";
exit(0);
