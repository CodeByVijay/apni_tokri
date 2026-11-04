<?php

declare(strict_types=1);

/**
 * Post-implementation verification for 1.28 credit lifecycle (disposable DB only).
 * Run: php7.3 scripts/run-in-disposable-db.php scripts/test-subscription-credit-lifecycle-verify.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/subscription_test_fulfilment_fixture.php';

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;
$pass = 0;
$tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);

function okV(bool $c, string $m): void
{
    global $fail, $pass;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if ($c) {
        $pass++;
    } else {
        $fail++;
    }
}

function sectionV(string $t): void
{
    echo "\n--- {$t} ---\n";
}

if (!SubscriptionCreditScheduleService::pauseColumnsReady($pdo)) {
    try {
        $pdo->exec('ALTER TABLE subscription_pauses ADD COLUMN pause_start_date DATE NULL, ADD COLUMN resume_on_date DATE NULL');
    } catch (Throwable $e) {
    }
}

function ensurePlanTemplates(PDO $pdo): void
{
    foreach ([
        ['veg_15', 15, 30],
        ['veg_30', 30, 45],
    ] as $spec) {
        if (SubscriptionPlanRepository::findByTemplateKey($spec[0]) !== null) {
            continue;
        }
        $base = SubscriptionPlanRepository::findByTemplateKey('veg_7');
        if ($base === null) {
            continue;
        }
        $pdo->prepare(
            'INSERT INTO subscription_plans (name, calendar_duration_days, delivery_count, duration_model, frequency_type,
             frequency_config, basket_rules_json, price, skip_pause_policy_json, selection_cutoff_hours, is_active, template_key, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
        )->execute([
            'Verify ' . $spec[0],
            $spec[2],
            $spec[1],
            'hybrid',
            'daily',
            json_encode(['interval_days' => 1]),
            $base['basket_rules_json'],
            (float) $base['price'],
            $base['skip_pause_policy_json'],
            (int) $base['selection_cutoff_hours'],
            $spec[0],
            $spec[1],
        ]);
    }
}

ensurePlanTemplates($pdo);

$variantId = (int) ($pdo->query(
    'SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id
     WHERE v.is_active = 1 AND p.status = \'published\' LIMIT 1'
)->fetchColumn() ?: 0);
$customerId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
if ($customerId <= 0) {
    $pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute(['96' . random_int(10000000, 99999999), 'Verify']);
    $customerId = (int) $pdo->lastInsertId();
}

function seedSubV(PDO $pdo, int $customerId, string $templateKey, string $prefix): array
{
    $plan = SubscriptionPlanRepository::findByTemplateKey($templateKey);
    if ($plan === null) {
        return ['ok' => false];
    }
    $snapshot = SubscriptionPlanRepository::toSnapshotArray($plan, (float) $plan['price']);
    $count = (int) $plan['delivery_count'];
    $start = (new DateTimeImmutable('+1 day', new DateTimeZone(SubscriptionScheduleGenerator::TZ)))->format('Y-m-d');
    $gen = SubscriptionScheduleGenerator::generateDeliveryDates($snapshot, $start);
    $dates = $gen['dates'] ?? [];
    $windowEnd = $start;
    if ($dates !== []) {
        $windowEnd = $dates[count($dates) - 1];
    }
    $num = $prefix . strtoupper(substr(Security::randomToken(3), 0, 4));
    $pdo->prepare(
        'INSERT INTO customer_subscriptions (subscription_number, customer_id, subscription_plan_id, plan_snapshot_json,
         price_paid, status, deliveries_remaining, start_date, calendar_end_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $num,
        $customerId,
        (int) $plan['id'],
        json_encode($snapshot, JSON_UNESCAPED_UNICODE),
        (float) $plan['price'],
        'active',
        $count,
        $start,
        $windowEnd,
    ]);
    $subId = (int) $pdo->lastInsertId();
    $idx = 1;
    foreach ($gen['dates'] ?? [] as $d) {
        $cut = SubscriptionCutoff::cutoffDateTime($d, 18)->format('Y-m-d H:i:s');
        $pdo->prepare(
            'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, selection_cutoff_at, basket_status)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$subId, $d, $idx++, 'scheduled', $cut, 'none']);
    }
    SubscriptionCreditScheduleService::ensureScheduleCoversRemainingCredits($subId, $pdo);

    return [
        'ok' => true,
        'id' => $subId,
        'number' => $num,
        'count' => $count,
        'snapshot' => $snapshot,
        'window_end' => $windowEnd,
    ];
}

function futureDayId(PDO $pdo, int $subId, int $minDaysAhead = 5): int
{
    $d = (new DateTimeImmutable('+' . $minDaysAhead . ' days', new DateTimeZone(SubscriptionScheduleGenerator::TZ)))->format('Y-m-d');
    $stmt = $pdo->prepare(
        'SELECT id FROM subscription_delivery_days WHERE customer_subscription_id = ? AND status = ? AND delivery_date >= ? ORDER BY delivery_date LIMIT 1'
    );
    $stmt->execute([$subId, 'scheduled', $d]);
    $id = (int) ($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }
    $cut = SubscriptionCutoff::cutoffDateTime($d, 18)->format('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, selection_cutoff_at, basket_status)
         VALUES (?, ?, 999, ?, ?, ?)'
    )->execute([$subId, $d, 'scheduled', $cut, 'none']);

    return (int) $pdo->lastInsertId();
}

sectionV('Plan templates 7 / 15 / 30');
foreach (['veg_7' => 7, 'veg_15' => 15, 'veg_30' => 30] as $key => $expect) {
    $ctx = seedSubV($pdo, $customerId, $key, 'V-' . $key . '-');
    okV($ctx['ok'] ?? false, "Seed {$key}");
    if (!($ctx['ok'] ?? false)) {
        continue;
    }
    okV((int) $ctx['count'] === $expect, "{$key} purchased credits = {$expect}");
    okV((int) $pdo->query('SELECT deliveries_remaining FROM customer_subscriptions WHERE id = ' . (int) $ctx['id'])->fetchColumn() === $expect, "{$key} deliveries_remaining initial");
    $maxDate = (string) $pdo->query('SELECT MAX(delivery_date) FROM subscription_delivery_days WHERE customer_subscription_id = ' . (int) $ctx['id'])->fetchColumn();
    okV($maxDate >= (string) $ctx['window_end'], "{$key} schedule can extend to horizon {$maxDate}");
    $dup = $pdo->query(
        'SELECT delivery_date, COUNT(*) c FROM subscription_delivery_days WHERE customer_subscription_id = ' . (int) $ctx['id'] . ' GROUP BY delivery_date HAVING c > 1'
    )->fetchColumn();
    okV($dup === false, "{$key} no duplicate delivery dates");
}

sectionV('Skip + inventory');
$skipCtx = seedSubV($pdo, $customerId, 'veg_7', 'V-SKIP-');
$subId = (int) $skipCtx['id'];
$subNum = (string) $skipCtx['number'];
$subRow = CustomerSubscriptionRepository::findById($subId);
$snap = $skipCtx['snapshot'];

$dayA = futureDayId($pdo, $subId, 6);
$rem0 = (int) $subRow['deliveries_remaining'];
$r1 = SubscriptionLifecycleService::skipDeliveryDay($customerId, $subNum, $dayA);
okV($r1['ok'] ?? false, 'Skip before basket');
okV((int) $pdo->query('SELECT deliveries_remaining FROM customer_subscriptions WHERE id = ' . $subId)->fetchColumn() === $rem0, 'Credits unchanged skip before basket');
$r1b = SubscriptionLifecycleService::skipDeliveryDay($customerId, $subNum, $dayA);
okV($r1b['ok'] ?? false, 'Duplicate skip idempotent');

$dayB = futureDayId($pdo, $subId, 8);
$pdo->prepare(
    'INSERT INTO subscription_delivery_baskets (subscription_delivery_day_id, source_type, status, allowance_inr, total_inr)
     VALUES (?, ?, ?, ?, ?)'
)->execute([$dayB, 'suggested', 'suggested', 100, 10]);
okV(SubscriptionBasketRepository::basketByDayId($dayB) !== null, 'Suggested basket exists');
$r2 = SubscriptionLifecycleService::skipDeliveryDay($customerId, $subNum, $dayB);
okV($r2['ok'] ?? false, 'Skip after suggested basket');

$skipCtx2 = seedSubV($pdo, $customerId, 'veg_7', 'V-SK2-');
$subId2 = (int) $skipCtx2['id'];
$subNum2 = (string) $skipCtx2['number'];
$dayC = futureDayId($pdo, $subId2, 10);
$pdo->prepare(
    'INSERT INTO subscription_delivery_baskets (subscription_delivery_day_id, source_type, status, allowance_inr, total_inr)
     VALUES (?, ?, ?, ?, ?)'
)->execute([$dayC, 'suggested', 'confirmed', 100, 10]);
$bC = SubscriptionBasketRepository::basketByDayId($dayC);
$pdo->prepare('UPDATE subscription_delivery_baskets SET status = ? WHERE id = ?')->execute(['confirmed', (int) $bC['id']]);
$pdo->prepare('UPDATE subscription_delivery_days SET basket_status = ? WHERE id = ?')->execute(['confirmed', $dayC]);
$variants = $pdo->query('SELECT id FROM product_variants ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN) ?: [$variantId];
$varHeld = (int) $variants[0];
$varDed = (int) (isset($variants[1]) ? $variants[1] : $variants[0]);
$pdo->prepare(
    'INSERT INTO subscription_stock_reservations (subscription_delivery_day_id, variant_id, qty, status) VALUES (?, ?, ?, ?)'
)->execute([$dayC, $varHeld, 2, 'held']);
if ($varDed !== $varHeld) {
    $pdo->prepare(
        'INSERT INTO subscription_stock_reservations (subscription_delivery_day_id, variant_id, qty, status) VALUES (?, ?, ?, ?)'
    )->execute([$dayC, $varDed, 1, 'deducted']);
}
$otherDay = futureDayId($pdo, $subId2, 11);
$pdo->prepare(
    'INSERT INTO subscription_stock_reservations (subscription_delivery_day_id, variant_id, qty, status) VALUES (?, ?, ?, ?)'
)->execute([$otherDay, $variantId, 3, 'held']);
$r3 = SubscriptionLifecycleService::skipDeliveryDay($customerId, $subNum2, $dayC);
okV($r3['ok'] ?? false, 'Skip after confirmed basket + reservations');
$stH = $pdo->prepare("SELECT COUNT(*) FROM subscription_stock_reservations WHERE subscription_delivery_day_id = ? AND status = 'held'");
$stH->execute([$dayC]);
okV((int) $stH->fetchColumn() === 0, 'Held reservations released on skipped day');
$stD = $pdo->prepare("SELECT COUNT(*) FROM subscription_stock_reservations WHERE subscription_delivery_day_id = ? AND status = 'deducted'");
$stD->execute([$dayC]);
if ($varDed !== $varHeld) {
    okV((int) $stD->fetchColumn() === 1, 'Deducted reservation not released on skip');
} else {
    okV(true, 'Deducted reservation case skipped (single variant in fixture)');
}
$stO = $pdo->prepare("SELECT COUNT(*) FROM subscription_stock_reservations WHERE subscription_delivery_day_id = ? AND status = 'held'");
$stO->execute([$otherDay]);
okV((int) $stO->fetchColumn() === 1, 'Unrelated day reservation untouched');
$aud = $pdo->prepare('SELECT COUNT(*) FROM subscription_skips WHERE subscription_delivery_day_id = ?');
$aud->execute([$dayC]);
okV((int) $aud->fetchColumn() >= 1, 'Skip audit row retained');

okV((string) $pdo->query('SELECT status FROM subscription_delivery_days WHERE id = ' . $dayC)->fetchColumn() === 'skipped', 'Skipped day status persisted');
$fulDay = futureDayId($pdo, $subId2, 12);
$pdo->prepare(
    "UPDATE subscription_delivery_days SET basket_status = 'confirmed', selection_cutoff_at = ? WHERE id = ?"
)->execute([(new DateTimeImmutable('-1 day', $tz))->format('Y-m-d H:i:s'), $fulDay]);
$pdo->prepare(
    'INSERT INTO subscription_delivery_baskets (subscription_delivery_day_id, source_type, status, allowance_inr, total_inr, confirmed_at)
     VALUES (?, ?, ?, ?, ?, NOW())'
)->execute([$fulDay, 'suggested', 'confirmed', 100, 10]);
$pdo->prepare(
    'INSERT INTO subscription_delivery_basket_items (basket_id, variant_id, product_name, variant_name, unit_label, qty, unit_price_inr, line_total_inr)
     SELECT id, ?, \'P\', \'V\', \'kg\', 1, 10, 10 FROM subscription_delivery_baskets WHERE subscription_delivery_day_id = ? LIMIT 1'
)->execute([$variantId, $fulDay]);
$runF = SubscriptionFulfilmentService::run(new DateTimeImmutable('+60 days', $tz));
$skippedOrder = (int) $pdo->query('SELECT generated_order_id FROM subscription_delivery_days WHERE id = ' . $dayC)->fetchColumn();
okV($skippedOrder === 0, 'No order generated for skipped day');

sectionV('Pause Nov 10 – Dec 5 2026');
$pauseCtx = seedSubV($pdo, $customerId, 'veg_7', 'V-PAUSE-');
$pSubId = (int) $pauseCtx['id'];
$pNum = (string) $pauseCtx['number'];
$beforeNov10 = $pdo->prepare(
    'SELECT COUNT(*) FROM subscription_delivery_days WHERE customer_subscription_id = ? AND delivery_date < ? AND status = ?'
);
$beforeNov10->execute([$pSubId, '2026-11-10', 'scheduled']);
okV((int) $beforeNov10->fetchColumn() >= 1, 'Deliveries exist before 2026-11-10');

$thirtyOne = SubscriptionLifecycleService::validatePauseDates(
    CustomerSubscriptionRepository::findById($pSubId),
    '2026-11-01',
    '2026-12-02'
);
okV(!($thirtyOne['ok'] ?? true), 'Reject 31+ day pause span');
$thirtyOk = SubscriptionLifecycleService::validatePauseDates(
    CustomerSubscriptionRepository::findById($pSubId),
    '2026-11-10',
    '2026-12-09'
);
okV($thirtyOk['ok'] ?? false, 'Accept 30-day pause span');

$pRes = SubscriptionLifecycleService::pauseWithDates($customerId, $pNum, '2026-11-10', '2026-12-05', 'verify');
okV($pRes['ok'] ?? false, 'Schedule pause 2026-11-10 to 2026-12-05');
okV((string) CustomerSubscriptionRepository::findById($pSubId)['status'] === 'active', 'Still active before pause start (pending)');
$dupPause = SubscriptionLifecycleService::pauseWithDates($customerId, $pNum, '2026-11-12', '2026-12-01', 'dup');
okV(!($dupPause['ok'] ?? true), 'Reject overlapping pause');

$pdo->prepare('UPDATE subscription_pauses SET pause_start_date = ? WHERE customer_subscription_id = ? AND status = ?')
    ->execute(['2026-11-04', $pSubId, 'pending']);
SubscriptionLifecycleService::resolvePauseAndResumeForSubscription($pSubId, $pdo);
okV((string) CustomerSubscriptionRepository::findById($pSubId)['status'] === 'paused', 'Pending pause activates when start reached');

$pdo->prepare('UPDATE subscription_pauses SET resume_on_date = ? WHERE customer_subscription_id = ? AND status = ?')
    ->execute(['2026-11-04', $pSubId, 'open']);
SubscriptionLifecycleService::resolvePauseAndResumeForSubscription($pSubId, $pdo);
okV((string) CustomerSubscriptionRepository::findById($pSubId)['status'] === 'active', 'Auto-resume when resume_on reached');
$shiftOnce = (int) $pdo->query('SELECT COUNT(*) FROM subscription_selection_audit WHERE customer_subscription_id = ' . $pSubId . " AND action = 'subscription_auto_resumed'")->fetchColumn();
SubscriptionLifecycleService::resolvePauseAndResumeForSubscription($pSubId, $pdo);
$shiftTwice = (int) $pdo->query('SELECT COUNT(*) FROM subscription_selection_audit WHERE customer_subscription_id = ' . $pSubId . " AND action = 'subscription_auto_resumed'")->fetchColumn();
okV($shiftTwice === $shiftOnce, 'No double auto-resume on repeat resolve');

$earlyCtx = seedSubV($pdo, $customerId, 'veg_7', 'V-EARLY-');
$eSubId = (int) $earlyCtx['id'];
$eNum = (string) $earlyCtx['number'];
$pauseStartEarly = (new DateTimeImmutable('+1 day', $tz))->format('Y-m-d');
SubscriptionLifecycleService::pauseWithDates($customerId, $eNum, $pauseStartEarly, (new DateTimeImmutable('+6 days', $tz))->format('Y-m-d'), 'early');
$pdo->prepare('UPDATE subscription_pauses SET pause_start_date = ? WHERE customer_subscription_id = ? AND status = ?')
    ->execute([(new DateTimeImmutable('now', $tz))->format('Y-m-d'), $eSubId, 'pending']);
SubscriptionLifecycleService::resolvePauseAndResumeForSubscription($eSubId, $pdo);
okV((string) CustomerSubscriptionRepository::findById($eSubId)['status'] === 'paused', 'Immediate pause for early resume test');
$er = SubscriptionLifecycleService::resume($customerId, $eNum);
okV($er['ok'] ?? false, 'Early manual resume');
$er2 = SubscriptionLifecycleService::resume($customerId, $eNum);
okV(!($er2['ok'] ?? true), 'Repeated resume rejected');

sectionV('Completion + reversal');
$compCtx = seedSubV($pdo, $customerId, 'veg_7', 'V-COMP-');
$cSubId = (int) $compCtx['id'];
okV(!SubscriptionCreditScheduleService::maybeCompleteSubscription($cSubId, $pdo), 'Not completed while credits remain');
$pdo->prepare('UPDATE customer_subscriptions SET deliveries_remaining = 0 WHERE id = ?')->execute([$cSubId]);
okV(SubscriptionCreditScheduleService::maybeCompleteSubscription($cSubId, $pdo), 'Completes at zero credits');
okV((string) $pdo->query('SELECT status FROM customer_subscriptions WHERE id = ' . $cSubId)->fetchColumn() === 'completed', 'Status completed');

foreach ([['veg_15', 15], ['veg_30', 30]] as $pair) {
    $cx = seedSubV($pdo, $customerId, $pair[0], 'V-CPL-' . $pair[0] . '-');
    if ($cx['ok'] ?? false) {
        $pdo->prepare('UPDATE customer_subscriptions SET deliveries_remaining = 0 WHERE id = ?')->execute([(int) $cx['id']]);
        SubscriptionCreditScheduleService::maybeCompleteSubscription((int) $cx['id'], $pdo);
        okV((string) $pdo->query('SELECT status FROM customer_subscriptions WHERE id = ' . (int) $cx['id'])->fetchColumn() === 'completed', $pair[0] . ' completes at zero credits');
    }
}

sectionV('Entitlement idempotency (fixture)');
SubscriptionTestFulfilmentFixture::purgeTestSubscriptionsByNumberPrefix($pdo, 'V-ENT-');
$todayYmd = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$plan = SubscriptionPlanRepository::findByTemplateKey('veg_7');
$snapshot = SubscriptionPlanRepository::toSnapshotArray($plan, (float) $plan['price']);
$entNum = 'V-ENT-' . strtoupper(substr(Security::randomToken(3), 0, 4));
$pdo->prepare(
    'INSERT INTO customer_subscriptions (subscription_number, customer_id, subscription_plan_id, plan_snapshot_json,
     price_paid, status, deliveries_remaining, start_date, calendar_end_date) VALUES (?,?,?,?,?,?,?,?,?)'
)->execute([
    $entNum,
    $customerId,
    (int) $plan['id'],
    json_encode($snapshot),
    (float) $plan['price'],
    'active',
    1,
    $todayYmd,
    (new DateTimeImmutable('+14 days', $tz))->format('Y-m-d'),
]);
$eSub = (int) $pdo->lastInsertId();
$today = $todayYmd;
$pdo->prepare(
    'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, selection_cutoff_at, basket_status)
     VALUES (?, ?, 1, ?, ?, ?)'
)->execute([$eSub, $today, 'scheduled', (new DateTimeImmutable('-1 day', $tz))->format('Y-m-d H:i:s'), 'confirmed']);
$eDay = (int) $pdo->lastInsertId();
SubscriptionTestFulfilmentFixture::prepareDeliveryDay($pdo, $eDay);
$pdo->prepare(
    'INSERT INTO subscription_delivery_baskets (subscription_delivery_day_id, source_type, status, allowance_inr, total_inr, confirmed_at) VALUES (?,?,?,?,?,NOW())'
)->execute([$eDay, 'suggested', 'confirmed', 100, 10]);
$pdo->prepare(
    'INSERT INTO subscription_delivery_basket_items (basket_id, variant_id, product_name, variant_name, unit_label, qty, unit_price_inr, line_total_inr)
     SELECT id, ?, \'P\', \'V\', \'kg\', 1, 10, 10 FROM subscription_delivery_baskets WHERE subscription_delivery_day_id = ? LIMIT 1'
)->execute([$variantId, $eDay]);
$genE = SubscriptionFulfilmentService::generateForDay($eDay, 'verify_ent');
okV($genE['ok'] ?? false, 'Fulfilment for entitlement test');
$orderId = (int) $pdo->query('SELECT generated_order_id FROM subscription_delivery_days WHERE id = ' . $eDay)->fetchColumn();
$st = $pdo->prepare('SELECT id FROM deliveries WHERE order_id = ?');
$st->execute([$orderId]);
$delId = (int) ($st->fetchColumn() ?: 0);
if ($delId > 0) {
    DeliveryAssignmentService::updateStatus($delId, 'delivered', null, 'v1');
    DeliveryAssignmentService::updateStatus($delId, 'delivered', null, 'v2');
    okV((int) $pdo->query('SELECT deliveries_remaining FROM customer_subscriptions WHERE id = ' . $eSub)->fetchColumn() === 0, 'Single credit consumed');
    okV((string) $pdo->query('SELECT status FROM customer_subscriptions WHERE id = ' . $eSub)->fetchColumn() === 'completed', 'Completed after last delivery');
    DeliveryAssignmentService::updateStatus($delId, 'failed', null, 'reverse');
    okV((int) $pdo->query('SELECT deliveries_remaining FROM customer_subscriptions WHERE id = ' . $eSub)->fetchColumn() === 1, 'Reversal restores credit');
    okV((string) $pdo->query('SELECT status FROM customer_subscriptions WHERE id = ' . $eSub)->fetchColumn() === 'active', 'Reversal reactivates subscription');
    $sched = SubscriptionCreditScheduleService::countScheduledDays($eSub, $pdo);
    okV($sched >= 1, 'Schedule recovery after reversal');
}

echo "\n=== Totals: passed={$pass}, failed={$fail} ===\n";
exit($fail > 0 ? 1 : 0);
