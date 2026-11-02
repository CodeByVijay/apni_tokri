<?php

declare(strict_types=1);

/**
 * Storefront payment reports (Stage 14.6) — read-only aggregates.
 */
final class PaymentReportService
{
    public const PAYMENTS_PER_PAGE = 50;
    public const EXPORT_MAX_ROWS = 5000;

    /** SQL fragment: storefront orders only (excludes subscription fulfilment). */
    public static function storefrontOrderSourceSql(string $orderAlias = 'o'): string
    {
        return "({$orderAlias}.order_source IS NULL OR {$orderAlias}.order_source = '' OR {$orderAlias}.order_source = 'storefront')";
    }

    /**
     * Payment attempts cohort (date basis: payments.created_at).
     *
     * @return array<string, int|float|string>
     */
    public static function attemptSummary(ReportDateRange $range): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $start = $range->sqlStart();
        $end = $range->sqlEnd();
        $storefront = self::storefrontOrderSourceSql('o');

        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total_attempts,
                SUM(CASE WHEN p.status = 'captured' THEN 1 ELSE 0 END) AS captured_attempts,
                SUM(CASE WHEN p.status = 'failed' THEN 1 ELSE 0 END) AS failed_attempts,
                SUM(CASE WHEN p.status IN ('created', 'pending') THEN 1 ELSE 0 END) AS pending_attempts
             FROM payments p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE {$storefront}
               AND p.created_at >= ? AND p.created_at < ?"
        );
        $stmt->execute([$start, $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $total = (int) ($row['total_attempts'] ?? 0);
        $captured = (int) ($row['captured_attempts'] ?? 0);
        $rate = $total > 0 ? round(100.0 * $captured / $total, 2) : 0.0;

        return [
            'range_label' => $range->label(),
            'date_basis' => 'payments.created_at (attempt cohort)',
            'total_attempts' => $total,
            'captured_attempts' => $captured,
            'failed_attempts' => (int) ($row['failed_attempts'] ?? 0),
            'pending_attempts' => (int) ($row['pending_attempts'] ?? 0),
            'attempt_cohort_success_rate_pct' => $rate,
        ];
    }

    /**
     * Confirmed storefront collections (date basis: payments.captured_at).
     *
     * @return array<string, int|float|string|list<array<string, mixed>>>
     */
    public static function collectionSummary(ReportDateRange $range): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $start = $range->sqlStart();
        $end = $range->sqlEnd();
        $storefront = self::storefrontOrderSourceSql('o');

        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS captured_payment_rows,
                COUNT(DISTINCT o.id) AS unique_paid_orders,
                COALESCE(SUM(p.amount), 0) AS captured_amount_inr
             FROM payments p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE p.status = 'captured'
               AND p.captured_at IS NOT NULL
               AND p.captured_at >= ? AND p.captured_at < ?
               AND {$storefront}"
        );
        $stmt->execute([$start, $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $dupStmt = $pdo->prepare(
            "SELECT o.id, o.order_number, COUNT(*) AS captured_rows
             FROM payments p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE p.status = 'captured'
               AND p.captured_at IS NOT NULL
               AND p.captured_at >= ? AND p.captured_at < ?
               AND {$storefront}
             GROUP BY o.id, o.order_number
             HAVING captured_rows > 1
             LIMIT 20"
        );
        $dupStmt->execute([$start, $end]);
        $duplicateOrders = $dupStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $gwStmt = $pdo->prepare(
            "SELECT p.gateway, COUNT(*) AS payment_count, COALESCE(SUM(p.amount), 0) AS amount_inr
             FROM payments p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE p.status = 'captured'
               AND p.captured_at IS NOT NULL
               AND p.captured_at >= ? AND p.captured_at < ?
               AND {$storefront}
             GROUP BY p.gateway
             ORDER BY amount_inr DESC"
        );
        $gwStmt->execute([$start, $end]);
        $byGateway = $gwStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $legacyStmt = $pdo->prepare(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(p.amount), 0) AS amount_inr
             FROM payments p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE p.status = 'captured'
               AND p.captured_at IS NULL
               AND {$storefront}
               AND o.placed_at >= ? AND o.placed_at < ?"
        );
        $legacyStmt->execute([$start, $end]);
        $legacy = $legacyStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'range_label' => $range->label(),
            'date_basis' => 'payments.captured_at (capture confirmation)',
            'captured_payment_count' => (int) ($row['captured_payment_rows'] ?? 0),
            'unique_paid_order_count' => (int) ($row['unique_paid_orders'] ?? 0),
            'captured_amount_inr' => round((float) ($row['captured_amount_inr'] ?? 0), 2),
            'gateway_breakdown' => $byGateway,
            'duplicate_captured_orders' => $duplicateOrders,
            'duplicate_captured_order_count' => count($duplicateOrders),
            'legacy_captured_null_timestamp_count' => (int) ($legacy['cnt'] ?? 0),
            'legacy_captured_null_timestamp_amount_inr' => round((float) ($legacy['amount_inr'] ?? 0), 2),
        ];
    }

    /**
     * Payment reconciliation exceptions (separate from confirmed collections).
     *
     * @return array<string, int|float|string>
     */
    public static function reconciliationSummary(ReportDateRange $range): array
    {
        if (!PaymentCompletionExceptionService::tableReady()) {
            return [
                'range_label' => $range->label(),
                'open_exceptions' => 0,
                'resolved_exceptions' => 0,
                'opened_captured_amount_inr' => 0.0,
                'resolved_captured_amount_inr' => 0.0,
            ];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $start = $range->sqlStart();
        $end = $range->sqlEnd();

        $openStmt = $pdo->prepare(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(captured_amount_inr), 0) AS amt
             FROM payment_completion_exceptions
             WHERE status = ?
               AND created_at >= ? AND created_at < ?"
        );
        $openStmt->execute([PaymentCompletionExceptionService::STATUS_OPEN, $start, $end]);
        $open = $openStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $resolvedStmt = $pdo->prepare(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(captured_amount_inr), 0) AS amt
             FROM payment_completion_exceptions
             WHERE status <> ?
               AND resolved_at IS NOT NULL
               AND resolved_at >= ? AND resolved_at < ?"
        );
        $resolvedStmt->execute([PaymentCompletionExceptionService::STATUS_OPEN, $start, $end]);
        $resolved = $resolvedStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'range_label' => $range->label(),
            'open_exceptions' => (int) ($open['cnt'] ?? 0),
            'opened_captured_amount_inr' => round((float) ($open['amt'] ?? 0), 2),
            'resolved_exceptions' => (int) ($resolved['cnt'] ?? 0),
            'resolved_captured_amount_inr' => round((float) ($resolved['amt'] ?? 0), 2),
        ];
    }

    /**
     * Storefront payment attempts in period (date basis: payments.created_at).
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function paymentAttempts(ReportDateRange $range, int $page): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $start = $range->sqlStart();
        $end = $range->sqlEnd();
        $storefront = self::storefrontOrderSourceSql('o');

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM payments p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE {$storefront} AND p.created_at >= ? AND p.created_at < ?"
        );
        $countStmt->execute([$start, $end]);
        $total = (int) $countStmt->fetchColumn();

        $offset = max(0, ($page - 1) * self::PAYMENTS_PER_PAGE);
        $limit = (int) self::PAYMENTS_PER_PAGE;
        $exReady = PaymentCompletionExceptionService::tableReady();
        $exJoin = $exReady
            ? "LEFT JOIN payment_completion_exceptions pce ON pce.payment_id = p.id AND pce.status = 'open'"
            : '';
        $exCol = $exReady ? ', pce.status AS open_exception_status' : ', NULL AS open_exception_status';

        $listStmt = $pdo->prepare(
            "SELECT p.id, p.gateway, p.status, p.amount, p.currency, p.gateway_payment_id, p.gateway_order_id,
                    p.created_at, p.captured_at, o.order_number {$exCol}
             FROM payments p
             INNER JOIN orders o ON o.id = p.order_id
             {$exJoin}
             WHERE {$storefront} AND p.created_at >= ? AND p.created_at < ?
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $listStmt->execute([$start, $end]);
        $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function paymentAttemptsForExport(ReportDateRange $range): array
    {
        $all = [];
        $page = 1;
        do {
            $batch = self::paymentAttempts($range, $page);
            $all = array_merge($all, $batch['rows']);
            if (count($all) >= self::EXPORT_MAX_ROWS) {
                $all = array_slice($all, 0, self::EXPORT_MAX_ROWS);
                break;
            }
            $page++;
        } while (count($batch['rows']) === self::PAYMENTS_PER_PAGE && ($page - 1) * self::PAYMENTS_PER_PAGE < $batch['total']);

        return $all;
    }

    public static function reconciliationLabel(array $row): string
    {
        if ((string) ($row['status'] ?? '') === 'reconciliation_required') {
            return 'Reconciliation required';
        }
        if (!empty($row['open_exception_status'])) {
            return 'Open exception';
        }

        return '—';
    }

    public static function formatPaymentTimestamp(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '—';
        }
        try {
            $dt = new DateTimeImmutable($raw, new DateTimeZone('UTC'));

            return $dt->setTimezone(new DateTimeZone(ReportDateRange::TIMEZONE))->format('Y-m-d H:i');
        } catch (Throwable $e) {
            return $raw;
        }
    }
}
