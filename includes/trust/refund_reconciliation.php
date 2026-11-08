<?php

declare(strict_types=1);

/**
 * Scheduled + shared gateway refund status reconciliation (GET only; no refund creation).
 */
final class RefundReconciliationService
{
    private const LOCK_RELATIVE = '/storage/cache/refund_reconcile.lock';

    /** @var list<string> */
    private const IN_FLIGHT_GATEWAY_STATUSES = [
        'created',
        'initiated',
        'pending',
        'processing',
        'unknown',
        'onhold',
        'pending_approval',
    ];

    public static function trackingColumnsReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();

            return (int) Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_refunds'
                   AND COLUMN_NAME = 'last_status_checked_at'"
            )->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function resolveBatchSize(): int
    {
        $raw = getenv('APNI_TOKRI_REFUND_RECONCILE_BATCH');
        $n = is_string($raw) && $raw !== '' ? (int) $raw : 25;

        return max(1, min(100, $n));
    }

    public static function resolveHttpTimeoutSeconds(): int
    {
        $raw = getenv('APNI_TOKRI_REFUND_RECONCILE_HTTP_TIMEOUT');
        $n = is_string($raw) && $raw !== '' ? (int) $raw : 20;

        return max(5, min(60, $n));
    }

    public static function cooldownSecondsForAttempt(int $attempts): int
    {
        if ($attempts <= 1) {
            return 300;
        }
        if ($attempts <= 5) {
            return 900;
        }

        return 3600;
    }

    /**
     * @return array{
     *   checked: int,
     *   pending: int,
     *   completed: int,
     *   failed: int,
     *   skipped: int,
     *   errors: int,
     *   messages: list<string>
     * }
     */
    public static function runCronBatch(): array
    {
        $summary = [
            'checked' => 0,
            'pending' => 0,
            'completed' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'messages' => [],
        ];
        if (!Install::isSchemaReady()) {
            $summary['messages'][] = 'schema_not_ready';

            return $summary;
        }
        if (!self::trackingColumnsReady()) {
            $summary['messages'][] = 'migration_1_32_required';

            return $summary;
        }
        if (!self::acquireProcessLock()) {
            $summary['messages'][] = 'lock_busy';

            return $summary;
        }
        try {
            Install::ensureConnection();
            $pdo = Database::pdo();
            $batch = self::resolveBatchSize();
            $candidates = self::selectEligiblePaymentRefundIds($pdo, $batch * 3);
            foreach ($candidates as $paymentRefundId) {
                if ($summary['checked'] >= $batch) {
                    break;
                }
                if (!self::tryClaimPaymentRefund($pdo, $paymentRefundId)) {
                    $summary['skipped']++;

                    continue;
                }
                $summary['checked']++;
                $result = RefundRequestService::reconcilePaymentRefundById($paymentRefundId, 'cron', null);
                $bucket = (string) ($result['summary'] ?? '');
                if ($bucket === 'pending') {
                    $summary['pending']++;
                } elseif ($bucket === 'success') {
                    $summary['completed']++;
                } elseif ($bucket === 'failure') {
                    $summary['failed']++;
                } elseif ($bucket === 'skipped') {
                    $summary['skipped']++;
                } elseif (empty($result['ok'])) {
                    $summary['errors']++;
                }
            }
        } finally {
            self::releaseProcessLock();
        }

        return $summary;
    }

    /** @return list<int> */
    private static function selectEligiblePaymentRefundIds(PDO $pdo, int $limit): array
    {
        $terminalList = array_map(static fn (string $s): string => strtolower($s), RefundCompletionService::terminalSuccessGatewayStatuses());
        $terminalSql = "'" . implode("','", array_map(static fn (string $s): string => str_replace("'", "''", $s), $terminalList)) . "'";
        $sql = "SELECT pr.id
                FROM payment_refunds pr
                INNER JOIN refund_requests rr ON rr.id = pr.refund_request_id
                INNER JOIN payments p ON p.id = pr.payment_id
                WHERE pr.payment_id IS NOT NULL
                  AND pr.gateway IN ('cashfree', 'razorpay')
                  AND pr.completed_at IS NULL
                  AND LOWER(pr.gateway_status) NOT IN ({$terminalSql}, 'failed')
                  AND rr.status IN (?, ?)
                  AND (pr.next_status_check_at IS NULL OR pr.next_status_check_at <= NOW())
                  AND (pr.reconcile_lease_expires_at IS NULL OR pr.reconcile_lease_expires_at < NOW())
                ORDER BY COALESCE(pr.next_status_check_at, pr.created_at) ASC, pr.id ASC
                LIMIT ?";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, RefundStatus::PROCESSING);
        $stmt->bindValue(2, RefundStatus::REFUND_INITIATED);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    private static function tryClaimPaymentRefund(PDO $pdo, int $paymentRefundId): bool
    {
        if ($paymentRefundId <= 0) {
            return false;
        }
        $token = bin2hex(random_bytes(8));
        $stmt = $pdo->prepare(
            'UPDATE payment_refunds
             SET reconcile_lease_token = ?, reconcile_lease_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE)
             WHERE id = ?
               AND completed_at IS NULL
               AND (reconcile_lease_expires_at IS NULL OR reconcile_lease_expires_at < NOW())'
        );
        $stmt->execute([$token, $paymentRefundId]);

        return $stmt->rowCount() > 0;
    }

    public static function releasePaymentRefundLease(int $paymentRefundId): void
    {
        if (!self::trackingColumnsReady() || $paymentRefundId <= 0) {
            return;
        }
        try {
            Database::pdo()->prepare(
                'UPDATE payment_refunds SET reconcile_lease_token = NULL, reconcile_lease_expires_at = NULL WHERE id = ?'
            )->execute([$paymentRefundId]);
        } catch (Throwable $e) {
            error_log('RefundReconciliationService::releasePaymentRefundLease');
        }
    }

    private static function lockPath(): string
    {
        $path = APNI_TOKRI_ROOT . self::LOCK_RELATIVE;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $path;
    }

    private static function acquireProcessLock(): bool
    {
        $path = self::lockPath();
        $fp = fopen($path, 'c+');
        if ($fp === false) {
            return false;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);

            return false;
        }
        $GLOBALS['_apni_refund_reconcile_lock_fp'] = $fp;

        return true;
    }

    private static function releaseProcessLock(): void
    {
        $fp = $GLOBALS['_apni_refund_reconcile_lock_fp'] ?? null;
        if (!is_resource($fp)) {
            return;
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        unset($GLOBALS['_apni_refund_reconcile_lock_fp']);
    }
}
