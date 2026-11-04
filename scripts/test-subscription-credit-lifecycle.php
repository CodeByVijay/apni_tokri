<?php

declare(strict_types=1);

/**
 * Credit-based subscription lifecycle tests (disposable DB only).
 * Run: php7.3 scripts/run-in-disposable-db.php scripts/test-subscription-credit-lifecycle.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;
$pass = 0;
$tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);

function ok(bool $c, string $m): void
{
    global $fail, $pass;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if ($c) {
        $pass++;
    } else {
        $fail++;
    }
}

if (!SubscriptionCreditScheduleService::pauseColumnsReady($pdo)) {
    try {
        $pdo->exec(
            'ALTER TABLE subscription_pauses
             ADD COLUMN pause_start_date DATE NULL,
             ADD COLUMN resume_on_date DATE NULL'
        );
    } catch (Throwable $e) {
        // column may exist
    }
    $pdo->exec("UPDATE settings SET setting_value = '1.28.0-subscription-credit-lifecycle' WHERE setting_key = 'schema_version'");
}
$variantId = (int) ($pdo->query(
    'SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id
     WHERE v.is_active = 1 AND p.status = \'published\' LIMIT 1'
)->fetchColumn() ?: 0);
ok($variantId > 0, 'Catalogue variant');

$customerId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
if ($customerId <= 0) {
    $pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute(['98' . random_int(10000000, 99999999), 'Credit LC']);
    $customerId = (int) $pdo->lastInsertId();
}

function seedSub(PDO $pdo, int $customerId, string $templateKey, string $prefix): array
{
    $plan = SubscriptionPlanRepository::findByTemplateKey($templateKey);
    if ($plan === null) {
        return ['ok' => false];
    }
    $snapshot = SubscriptionPlanRepository::toSnapshotArray($plan, (float) $plan['price']);
    $num = $prefix . strtoupper(substr(Security::randomToken(4), 0, 5));
    $count = (int) $plan['delivery_count'];
    $start = (new DateTimeImmutable('+1 day', new DateTimeZone(SubscriptionScheduleGenerator::TZ)))->format('Y-m-d');
    $gen = SubscriptionScheduleGenerator::generateDeliveryDates($snapshot, $start);
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
        $start,
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

    return ['ok' => true, 'id' => $subId, 'number' => $num, 'count' => $count, 'snapshot' => $snapshot];
}

$ctx7 = seedSub($pdo, $customerId, 'veg_7', 'SUB-LC7-');
ok($ctx7['ok'] ?? false, 'Seed 7-day subscription');
$sub7 = (int) ($ctx7['id'] ?? 0);

$futureCutoffDate = (new DateTimeImmutable('+5 days', $tz))->format('Y-m-d');
$dayStmt = $pdo->prepare(
    'SELECT id, delivery_date FROM subscription_delivery_days WHERE customer_subscription_id = ? AND status = ?
     AND delivery_date >= ? ORDER BY delivery_date LIMIT 1'
);
$dayStmt->execute([$sub7, 'scheduled', $futureCutoffDate]);
$dayRow = $dayStmt->fetch(PDO::FETCH_ASSOC);
if (!$dayRow) {
    $cut = SubscriptionCutoff::cutoffDateTime($futureCutoffDate, 18)->format('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, selection_cutoff_at, basket_status)
         VALUES (?, ?, 99, ?, ?, ?)'
    )->execute([$sub7, $futureCutoffDate, 'scheduled', $cut, 'none']);
    $dayId = (int) $pdo->lastInsertId();
} else {
    $dayId = (int) $dayRow['id'];
}
$remBefore = (int) $pdo->query('SELECT deliveries_remaining FROM customer_subscriptions WHERE id = ' . $sub7)->fetchColumn();

SubscriptionBasketService::ensureSuggestedBasket(
    CustomerSubscriptionRepository::findById($sub7),
    SubscriptionBasketRepository::dayByIdForSubscription($dayId, $sub7),
    $ctx7['snapshot']
);
$basket = SubscriptionBasketRepository::basketByDayId($dayId);
if ($basket) {
    $pdo->prepare('UPDATE subscription_delivery_baskets SET status = ?, updated_at = NOW() WHERE id = ?')
        ->execute(['confirmed', (int) $basket['id']]);
    $pdo->prepare('UPDATE subscription_delivery_days SET basket_status = ? WHERE id = ?')->execute(['confirmed', $dayId]);
}

$skip = SubscriptionLifecycleService::skipDeliveryDay($customerId, (string) $ctx7['number'], $dayId);
ok($skip['ok'] ?? false, 'Skip after confirmed basket');
$remAfter = (int) $pdo->query('SELECT deliveries_remaining FROM customer_subscriptions WHERE id = ' . $sub7)->fetchColumn();
ok($remAfter === $remBefore, 'Skip preserves deliveries_remaining');
$hold = $pdo->prepare("SELECT COUNT(*) FROM subscription_stock_reservations WHERE subscription_delivery_day_id = ? AND status = 'held'");
$hold->execute([$dayId]);
ok((int) $hold->fetchColumn() === 0, 'Skip released reservations');
$bAfter = SubscriptionBasketRepository::basketByDayId($dayId);
ok($bAfter === null || (string) $bAfter['status'] === 'cancelled', 'Skip cancelled open basket');

$scheduled = SubscriptionCreditScheduleService::countScheduledDays($sub7, $pdo);
ok($scheduled >= $remAfter, 'Schedule covers remaining credits after skip');

$pauseStart = (new DateTimeImmutable('+3 days', $tz))->format('Y-m-d');
$pauseEnd = (new DateTimeImmutable('+10 days', $tz))->format('Y-m-d');
$badSpan = SubscriptionLifecycleService::validatePauseDates(CustomerSubscriptionRepository::findById($sub7), $pauseStart, (new DateTimeImmutable('+40 days', $tz))->format('Y-m-d'));
ok(!($badSpan['ok'] ?? true), 'Reject pause longer than 30 days');

$pauseOk = SubscriptionLifecycleService::pauseWithDates($customerId, (string) $ctx7['number'], $pauseStart, $pauseEnd, 'test');
ok($pauseOk['ok'] ?? false, 'Schedule pause with dates');
$pauseRow = $pdo->query('SELECT pause_start_date, resume_on_date FROM subscription_pauses ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
ok(
    SubscriptionCreditScheduleService::pauseColumnsReady($pdo)
    && is_array($pauseRow)
    && (string) ($pauseRow['pause_start_date'] ?? '') === $pauseStart,
    'Pause date columns store customer-selected dates'
);
$dupPause = SubscriptionLifecycleService::pauseWithDates($customerId, (string) $ctx7['number'], $pauseStart, $pauseEnd, 'dup');
ok(!($dupPause['ok'] ?? true), 'Reject overlapping pause');

$pdo->prepare("UPDATE customer_subscriptions SET status = 'active' WHERE id = ?")->execute([$sub7]);
$pdo->prepare("UPDATE subscription_pauses SET status = 'closed' WHERE customer_subscription_id = ?")->execute([$sub7]);

$rem = (int) $ctx7['count'];
$pdo->prepare('UPDATE customer_subscriptions SET deliveries_remaining = 0, status = ? WHERE id = ?')->execute(['active', $sub7]);
SubscriptionCreditScheduleService::maybeCompleteSubscription($sub7, $pdo);
$st = (string) $pdo->query('SELECT status FROM customer_subscriptions WHERE id = ' . $sub7)->fetchColumn();
ok($st === 'completed', 'Subscription completes at zero credits');

$skipCompleted = SubscriptionLifecycleService::skipDeliveryDay($customerId, (string) $ctx7['number'], $dayId);
ok(!($skipCompleted['ok'] ?? true), 'Completed subscription cannot skip');

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
