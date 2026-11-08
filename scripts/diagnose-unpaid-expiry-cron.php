<?php

declare(strict_types=1);

/**
 * Read-only staging helper: unpaid expiry cron eligibility + migration 1.33.0 checks.
 * CLI: php scripts/diagnose-unpaid-expiry-cron.php [order_number]
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$batch = UnpaidOrderExpiryService::resolveBatchSize();
$lookup = isset($argv[1]) ? trim((string) $argv[1]) : '';

echo "payment_expires_at ready: " . (UnpaidOrderExpiryService::columnReady() ? 'yes' : 'no') . "\n";
echo "cron batch size (env/default): {$batch}\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM order_cancellation_events LIKE 'requested_by'")->fetch(PDO::FETCH_ASSOC);
    $type = (string) ($col['Type'] ?? '');
    echo "order_cancellation_events.requested_by: {$type}\n";
    echo "enum includes system: " . (stripos($type, 'system') !== false ? 'yes' : 'NO — run migration 1.33.0') . "\n";
} catch (Throwable $e) {
    echo "order_cancellation_events check failed: " . $e->getMessage() . "\n";
}

$ids = UnpaidOrderExpiryService::cronEligibleOrder($pdo, $batch);
echo 'cron-eligible count (up to batch): ' . count($ids) . "\n";

if ($ids === []) {
    exit(0);
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$st = $pdo->prepare(
    "SELECT o.id, o.order_number, o.status, o.payment_status, o.payment_expires_at,
            (SELECT GROUP_CONCAT(DISTINCT p.gateway ORDER BY p.id) FROM payments p WHERE p.order_id = o.id) AS gateways
     FROM orders o WHERE o.id IN ({$placeholders}) ORDER BY o.payment_expires_at ASC"
);
$st->execute($ids);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo sprintf(
        "- %s id=%d status=%s pay=%s expires=%s gateways=%s\n",
        (string) ($row['order_number'] ?? ''),
        (int) ($row['id'] ?? 0),
        (string) ($row['status'] ?? ''),
        (string) ($row['payment_status'] ?? ''),
        (string) ($row['payment_expires_at'] ?? ''),
        (string) ($row['gateways'] ?? '')
    );
}

if ($lookup !== '') {
    $one = $pdo->prepare('SELECT id, order_number, status, payment_status, payment_expires_at FROM orders WHERE order_number = ? LIMIT 1');
    $one->execute([$lookup]);
    $ord = $one->fetch(PDO::FETCH_ASSOC);
    if (!$ord) {
        echo "Order {$lookup} not found.\n";
        exit(0);
    }
    $oid = (int) ($ord['id'] ?? 0);
    $inBatch = in_array($oid, $ids, true);
    echo "\nLookup {$lookup}: id={$oid} in_cron_batch=" . ($inBatch ? 'yes' : 'no') . "\n";
    if (!$inBatch) {
        echo "Reason hints: status/payment_status, payment_expires_at NULL/future, subscription/COD, or outside LIMIT {$batch}.\n";
    }
}
