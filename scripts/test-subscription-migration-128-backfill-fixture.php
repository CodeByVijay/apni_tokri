<?php

declare(strict_types=1);

/**
 * Isolated legacy pause fixture + 1.28 backfill behaviour (disposable DB only).
 * Run: php7.3 scripts/run-in-disposable-db.php scripts/test-subscription-migration-128-backfill-fixture.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;
$pass = 0;
$tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);

function okB(bool $c, string $m): void
{
    global $fail, $pass;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if ($c) {
        $pass++;
    } else {
        $fail++;
    }
}

$customerId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
if ($customerId <= 0) {
    $pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute(['97' . random_int(10000000, 99999999), 'M128']);
    $customerId = (int) $pdo->lastInsertId();
}

$plan = SubscriptionPlanRepository::findByTemplateKey('veg_7');
$snapshot = SubscriptionPlanRepository::toSnapshotArray($plan, (float) $plan['price']);

function insertSub(PDO $pdo, int $customerId, array $plan, array $snapshot, string $suffix): int
{
    $num = 'SUB-M128-' . $suffix;
    $pdo->prepare(
        'INSERT INTO customer_subscriptions (subscription_number, customer_id, subscription_plan_id, plan_snapshot_json,
         price_paid, status, deliveries_remaining, start_date, calendar_end_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), CURDATE() + INTERVAL 30 DAY)'
    )->execute([
        $num,
        $customerId,
        (int) $plan['id'],
        json_encode($snapshot, JSON_UNESCAPED_UNICODE),
        (float) $plan['price'],
        'active',
        7,
    ]);

    return (int) $pdo->lastInsertId();
}

$subRecent = insertSub($pdo, $customerId, $plan, $snapshot, 'RECENT');
$subOld = insertSub($pdo, $customerId, $plan, $snapshot, 'OLD');
$subMulti = insertSub($pdo, $customerId, $plan, $snapshot, 'MULTI');
$subMissing = insertSub($pdo, $customerId, $plan, $snapshot, 'MISSING');

$now = new DateTimeImmutable('now', $tz);
$recentPaused = $now->modify('-2 days')->format('Y-m-d H:i:s');
$oldPaused = $now->modify('-45 days')->format('Y-m-d H:i:s');
$closedResumed = $now->modify('-60 days')->format('Y-m-d H:i:s');
$closedEnd = $now->modify('-55 days')->format('Y-m-d H:i:s');

$pdo->prepare(
    'INSERT INTO subscription_pauses (customer_subscription_id, paused_at, status, note) VALUES (?, ?, ?, ?)'
)->execute([$subRecent, $recentPaused, 'open', 'recent open pause']);
$recentPauseId = (int) $pdo->lastInsertId();

$pdo->prepare(
    'INSERT INTO subscription_pauses (customer_subscription_id, paused_at, status, note) VALUES (?, ?, ?, ?)'
)->execute([$subOld, $oldPaused, 'open', 'stale open pause']);
$oldPauseId = (int) $pdo->lastInsertId();

$pdo->prepare(
    'INSERT INTO subscription_pauses (customer_subscription_id, paused_at, resumed_at, status) VALUES (?, ?, ?, ?)'
)->execute([$subMulti, $closedResumed, $closedEnd, 'closed']);
$closedPauseId = (int) $pdo->lastInsertId();

$pdo->prepare(
    'INSERT INTO subscription_pauses (customer_subscription_id, paused_at, status) VALUES (?, ?, ?)'
)->execute([$subMissing, $recentPaused, 'closed']);
$missingPauseId = (int) $pdo->lastInsertId();

$pauseCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM subscription_pauses')->fetchColumn();

if (!SubscriptionCreditScheduleService::pauseColumnsReady($pdo)) {
    $pdo->exec(
        'ALTER TABLE subscription_pauses ADD COLUMN pause_start_date DATE NULL, ADD COLUMN resume_on_date DATE NULL'
    );
}

$pdo->exec('UPDATE subscription_pauses SET pause_start_date = DATE(paused_at) WHERE pause_start_date IS NULL');
$pdo->exec(
    'UPDATE subscription_pauses SET resume_on_date = DATE(resumed_at)
     WHERE resume_on_date IS NULL AND status = \'closed\' AND resumed_at IS NOT NULL'
);
$pdo->exec(
    'UPDATE subscription_pauses SET resume_on_date = DATE(DATE_ADD(COALESCE(pause_start_date, DATE(paused_at)), INTERVAL 30 DAY))
     WHERE resume_on_date IS NULL AND status IN (\'open\', \'pending\')'
);

okB((int) $pdo->query('SELECT COUNT(*) FROM subscription_pauses')->fetchColumn() === $pauseCountBefore, 'No pause rows deleted');

$recent = $pdo->query('SELECT * FROM subscription_pauses WHERE id = ' . $recentPauseId)->fetch(PDO::FETCH_ASSOC);
okB((string) ($recent['pause_start_date'] ?? '') === substr($recentPaused, 0, 10), 'Recent open: pause_start from paused_at');
okB((string) ($recent['resume_on_date'] ?? '') !== '', 'Recent open: synthetic resume_on set');

$old = $pdo->query('SELECT * FROM subscription_pauses WHERE id = ' . $oldPauseId)->fetch(PDO::FETCH_ASSOC);
$oldResume = (string) ($old['resume_on_date'] ?? '');
okB($oldResume !== '', 'Stale open: resume_on backfilled');
$oldResumePast = $oldResume !== '' && $oldResume < $now->format('Y-m-d');
okB($oldResumePast, 'Stale open: synthetic resume may already be in the past (auto-resume risk documented)');

$closed = $pdo->query('SELECT * FROM subscription_pauses WHERE id = ' . $closedPauseId)->fetch(PDO::FETCH_ASSOC);
okB((string) ($closed['resume_on_date'] ?? '') === substr($closedEnd, 0, 10), 'Closed pause: resume_on from resumed_at');

$missing = $pdo->query('SELECT * FROM subscription_pauses WHERE id = ' . $missingPauseId)->fetch(PDO::FETCH_ASSOC);
okB(empty($missing['resume_on_date']) || (string) $missing['resume_on_date'] === '', 'Closed without resumed_at: no synthetic future resume');

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
