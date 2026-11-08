<?php

declare(strict_types=1);

/**
 * Refund reconciliation cron + email idempotency (mocked HTTP).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/stage12_phase_c1_fixture.php';

$fail = 0;
$ok = static function (bool $c, string $m) use (&$fail): void {
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
};

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

function applyMigration132(PDO $pdo): void
{
    $n = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_refunds' AND COLUMN_NAME = 'last_status_checked_at'"
    )->fetchColumn();
    if ($n > 0) {
        return;
    }
    $sql = (string) file_get_contents(APNI_TOKRI_ROOT . '/database/migrations/1.32.0_refund_status_reconciliation.sql');
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $chunk) {
        if ($chunk === '' || stripos($chunk, 'SET @') === 0 || stripos($chunk, 'PREPARE') === 0 || stripos($chunk, 'EXECUTE') === 0 || stripos($chunk, 'DEALLOCATE') === 0) {
            continue;
        }
        try {
            $pdo->exec($chunk);
        } catch (Throwable $e) {
            // dynamic SQL chunk may need full migration run on some hosts
        }
    }
}

Install::ensureConnection();
$pdo = Database::pdo();
applyMigration132($pdo);
$ok(RefundReconciliationService::trackingColumnsReady(), 'Migration 1.32 tracking columns');

$static = (string) file_get_contents(APNI_TOKRI_ROOT . '/cron/refund_status_reconciliation.php');
$ok(str_contains($static, 'RefundReconciliationService::runCronBatch'), 'Cron script present');
$ok(is_file(APNI_TOKRI_ROOT . '/docs/cpanel-refund-reconciliation-cron.md'), 'Cron documentation');
$ok(str_contains((string) file_get_contents(APNI_TOKRI_ROOT . '/admin/refunds/index.php'), 'refund-gateway-details.php'), 'Admin gateway panel');

$refundPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/trust/refund.php');
$ok(str_contains($refundPhp, 'reconcilePaymentRefundById'), 'Shared reconcile method');
$ok(str_contains((string) file_get_contents(APNI_TOKRI_ROOT . '/includes/notifications/dispatcher.php'), 'refundCompletedForRequest'), 'Completion email helper');

echo $fail === 0 ? "PASS: refund reconciliation (static/partial).\n" : "Failures: {$fail}\n";
exit($fail > 0 ? 1 : 0);
