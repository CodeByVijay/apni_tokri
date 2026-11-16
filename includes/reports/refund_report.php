<?php

declare(strict_types=1);

/**
 * Refund reports (Stage 14.6) — read-only aggregates.
 */
final class RefundReportService
{
    public const DETAIL_PER_PAGE = 50;
    public const EXPORT_MAX_ROWS = 5000;

    /** @return string Comma-separated quoted statuses for SQL IN (...) */
    public static function terminalGatewayStatusSqlIn(): string
    {
        $parts = [];
        foreach (RefundCompletionService::terminalSuccessGatewayStatuses() as $s) {
            $parts[] = "'" . str_replace("'", "''", $s) . "'";
        }

        return implode(', ', $parts);
    }

    /**
     * Unified completed-refund period filter (matches net sales refund total).
     * Prefer completed_at; legacy terminal rows use updated_at when completed_at IS NULL.
     */
    public static function sqlUnifiedCompletedPeriodWhere(string $alias = 'pr'): string
    {
        $terminalIn = self::terminalGatewayStatusSqlIn();

        return "{$alias}.gateway_status IN ({$terminalIn}) AND (
            ({$alias}.completed_at IS NOT NULL AND {$alias}.completed_at >= ? AND {$alias}.completed_at < ?)
            OR ({$alias}.completed_at IS NULL AND {$alias}.updated_at >= ? AND {$alias}.updated_at < ?)
        )";
    }

    /** @return list<string> */
    public static function unifiedCompletedPeriodBind(ReportDateRange $range): array
    {
        $start = $range->sqlStart();
        $end = $range->sqlEnd();

        return [$start, $end, $start, $end];
    }

    /**
     * @return array{count: int, amount_inr: float, legacy_count: int, legacy_amount_inr: float}
     */
    public static function unifiedCompletedRefundTotals(ReportDateRange $range): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $where = self::sqlUnifiedCompletedPeriodWhere('pr');
        $bind = self::unifiedCompletedPeriodBind($range);
        $terminalIn = self::terminalGatewayStatusSqlIn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_inr), 0) AS amt FROM payment_refunds pr WHERE {$where}"
        );
        $stmt->execute($bind);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $legacyStmt = $pdo->prepare(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_inr), 0) AS amt FROM payment_refunds pr
             WHERE pr.gateway_status IN ({$terminalIn})
               AND pr.completed_at IS NULL
               AND pr.updated_at >= ? AND pr.updated_at < ?"
        );
        $legacyStmt->execute([$range->sqlStart(), $range->sqlEnd()]);
        $legacy = $legacyStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'count' => (int) ($row['cnt'] ?? 0),
            'amount_inr' => round((float) ($row['amt'] ?? 0), 2),
            'legacy_count' => (int) ($legacy['cnt'] ?? 0),
            'legacy_amount_inr' => round((float) ($legacy['amt'] ?? 0), 2),
        ];
    }

    /**
     * Refund request workflow counts (date basis: refund_requests.created_at).
     *
     * @return array<string, int|float|string>
     */
    public static function requestSummary(ReportDateRange $range): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $start = $range->sqlStart();
        $end = $range->sqlEnd();

        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total_requests,
                SUM(CASE WHEN status IN ('requested', 'under_review') THEN 1 ELSE 0 END) AS open_requests,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_requests,
                SUM(CASE WHEN status IN ('refund_initiated', 'processing') THEN 1 ELSE 0 END) AS processing_requests,
                SUM(CASE WHEN status IN ('refund_failed', 'rejected') THEN 1 ELSE 0 END) AS failed_rejected_requests,
                SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) AS workflow_refunded_requests
             FROM refund_requests
             WHERE created_at >= ? AND created_at < ?"
        );
        $stmt->execute([$start, $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'range_label' => $range->label(),
            'date_basis' => 'refund_requests.created_at',
            'total_refund_requests' => (int) ($row['total_requests'] ?? 0),
            'open_requests' => (int) ($row['open_requests'] ?? 0),
            'approved_requests' => (int) ($row['approved_requests'] ?? 0),
            'processing_requests' => (int) ($row['processing_requests'] ?? 0),
            'failed_rejected_requests' => (int) ($row['failed_rejected_requests'] ?? 0),
            'workflow_refunded_requests' => (int) ($row['workflow_refunded_requests'] ?? 0),
        ];
    }

    /**
     * Completed gateway refunds (date basis: payment_refunds.completed_at).
     *
     * @return array<string, int|float|string|list<array<string, mixed>>>
     */
    public static function completedSummary(ReportDateRange $range): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $start = $range->sqlStart();
        $end = $range->sqlEnd();
        $terminalIn = self::terminalGatewayStatusSqlIn();

        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS completed_count,
                COALESCE(SUM(amount_inr), 0) AS completed_amount_inr,
                SUM(CASE WHEN refund_request_id IS NOT NULL THEN 1 ELSE 0 END) AS order_refund_count,
                COALESCE(SUM(CASE WHEN refund_request_id IS NOT NULL THEN amount_inr ELSE 0 END), 0) AS order_refund_amount_inr,
                SUM(CASE WHEN subscription_payment_id IS NOT NULL THEN 1 ELSE 0 END) AS subscription_refund_count,
                COALESCE(SUM(CASE WHEN subscription_payment_id IS NOT NULL THEN amount_inr ELSE 0 END), 0) AS subscription_refund_amount_inr
             FROM payment_refunds
             WHERE gateway_status IN ({$terminalIn})
               AND completed_at IS NOT NULL
               AND completed_at >= ? AND completed_at < ?"
        );
        $stmt->execute([$start, $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $gwStmt = $pdo->prepare(
            "SELECT gateway, COUNT(*) AS refund_count, COALESCE(SUM(amount_inr), 0) AS amount_inr
             FROM payment_refunds
             WHERE gateway_status IN ({$terminalIn})
               AND completed_at IS NOT NULL
               AND completed_at >= ? AND completed_at < ?
             GROUP BY gateway
             ORDER BY amount_inr DESC"
        );
        $gwStmt->execute([$start, $end]);
        $byGateway = $gwStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $partialStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM (
                SELECT payment_id
                FROM payment_refunds
                WHERE gateway_status IN ({$terminalIn})
                  AND completed_at IS NOT NULL
                  AND completed_at >= ? AND completed_at < ?
                  AND payment_id IS NOT NULL
                GROUP BY payment_id
                HAVING COUNT(*) > 1
             ) AS multi"
        );
        $partialStmt->execute([$start, $end]);
        $partialPayments = (int) $partialStmt->fetchColumn();

        $legacyStmt = $pdo->query(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_inr), 0) AS amount_inr
             FROM payment_refunds
             WHERE gateway_status IN ({$terminalIn})
               AND completed_at IS NULL"
        );
        $legacy = $legacyStmt ? ($legacyStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        return [
            'range_label' => $range->label(),
            'date_basis' => 'payment_refunds.completed_at',
            'completed_refund_count' => (int) ($row['completed_count'] ?? 0),
            'completed_refund_amount_inr' => round((float) ($row['completed_amount_inr'] ?? 0), 2),
            'order_refund_count' => (int) ($row['order_refund_count'] ?? 0),
            'order_refund_amount_inr' => round((float) ($row['order_refund_amount_inr'] ?? 0), 2),
            'subscription_refund_count' => (int) ($row['subscription_refund_count'] ?? 0),
            'subscription_refund_amount_inr' => round((float) ($row['subscription_refund_amount_inr'] ?? 0), 2),
            'gateway_breakdown' => $byGateway,
            'payments_with_multiple_completed_refunds' => $partialPayments,
            'legacy_terminal_null_completed_at_count' => (int) ($legacy['cnt'] ?? 0),
            'legacy_terminal_null_completed_at_amount_inr' => round((float) ($legacy['amount_inr'] ?? 0), 2),
        ];
    }

    /**
     * Pending refund liability (not completed cash out — open approved pipeline).
     *
     * @return array<string, float|int|string>
     */
    public static function pendingLiabilitySnapshot(): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->query(
            "SELECT COALESCE(SUM(
                CASE WHEN rr.approved_amount_inr IS NOT NULL AND rr.approved_amount_inr > 0
                     THEN rr.approved_amount_inr ELSE 0 END
             ), 0) AS approved_inr,
             COUNT(*) AS open_request_rows
             FROM refund_requests rr
             WHERE rr.status NOT IN ('rejected', 'cancelled', 'refunded', 'refund_failed')"
        );
        $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        return [
            'date_basis' => 'point-in-time (not period-filtered)',
            'pending_liability_approved_inr' => round((float) ($row['approved_inr'] ?? 0), 2),
            'open_refund_request_count' => (int) ($row['open_request_rows'] ?? 0),
        ];
    }

    /**
     * Paginated completed gateway refunds in period (unified date policy for list ordering).
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function completedRefunds(ReportDateRange $range, int $page): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $where = self::sqlUnifiedCompletedPeriodWhere('pr');
        $bind = self::unifiedCompletedPeriodBind($range);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM payment_refunds pr WHERE {$where}");
        $countStmt->execute($bind);
        $total = (int) $countStmt->fetchColumn();

        $offset = max(0, ($page - 1) * self::DETAIL_PER_PAGE);
        $limit = (int) self::DETAIL_PER_PAGE;
        $listStmt = $pdo->prepare(
            "SELECT pr.id, pr.amount_inr, pr.gateway, pr.gateway_status, pr.gateway_refund_id,
                    pr.completed_at, pr.updated_at, pr.payment_id, pr.subscription_payment_id,
                    rr.request_number, rr.status AS request_status,
                    o.order_number,
                    cs.subscription_number,
                    CASE WHEN pr.completed_at IS NOT NULL THEN 'completed_at' ELSE 'updated_at_legacy' END AS period_date_basis,
                    COALESCE(pr.completed_at, pr.updated_at) AS period_sort_at
             FROM payment_refunds pr
             LEFT JOIN refund_requests rr ON rr.id = pr.refund_request_id
             LEFT JOIN payments p ON p.id = pr.payment_id
             LEFT JOIN orders o ON o.id = p.order_id
             LEFT JOIN subscription_payments sp ON sp.id = pr.subscription_payment_id
             LEFT JOIN customer_subscriptions cs ON cs.id = sp.customer_subscription_id
             WHERE {$where}
             ORDER BY period_sort_at DESC, pr.id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $listStmt->execute($bind);
        $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function completedRefundsForExport(ReportDateRange $range): array
    {
        $all = [];
        $page = 1;
        do {
            $batch = self::completedRefunds($range, $page);
            $all = array_merge($all, $batch['rows']);
            if (count($all) >= self::EXPORT_MAX_ROWS) {
                $all = array_slice($all, 0, self::EXPORT_MAX_ROWS);
                break;
            }
            $page++;
        } while (count($batch['rows']) === self::DETAIL_PER_PAGE && ($page - 1) * self::DETAIL_PER_PAGE < $batch['total']);

        return $all;
    }

    public static function formatRefundTimestamp(?string $raw): string
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
