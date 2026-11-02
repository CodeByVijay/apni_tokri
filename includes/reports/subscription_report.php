<?php

declare(strict_types=1);

/**
 * Subscription reports (Stage 14.6) — read-only aggregates.
 */
final class SubscriptionReportService
{
    public const PAYMENTS_PER_PAGE = 50;
    public const EXPORT_MAX_ROWS = 5000;

    /**
     * Activations in period (date basis: customer_subscriptions.activated_at).
     *
     * @return array<string, int|float|string>
     */
    public static function activationSummary(ReportDateRange $range): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $start = $range->sqlStart();
        $end = $range->sqlEnd();

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS activations, COALESCE(SUM(price_paid), 0) AS expected_amount_inr
             FROM customer_subscriptions
             WHERE activated_at IS NOT NULL
               AND activated_at >= ? AND activated_at < ?'
        );
        $stmt->execute([$start, $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'range_label' => $range->label(),
            'date_basis' => 'customer_subscriptions.activated_at',
            'subscription_activations' => (int) ($row['activations'] ?? 0),
            'expected_activation_amount_inr' => round((float) ($row['expected_amount_inr'] ?? 0), 2),
        ];
    }

    /**
     * Collected subscription payments (date basis: subscription_payments.captured_at).
     *
     * @return array<string, int|float|string|list<array<string, mixed>>>
     */
    public static function collectionSummary(ReportDateRange $range): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $start = $range->sqlStart();
        $end = $range->sqlEnd();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS captured_count, COALESCE(SUM(amount), 0) AS collected_inr
             FROM subscription_payments
             WHERE status = 'captured'
               AND captured_at IS NOT NULL
               AND captured_at >= ? AND captured_at < ?"
        );
        $stmt->execute([$start, $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $gwStmt = $pdo->prepare(
            "SELECT gateway, COUNT(*) AS payment_count, COALESCE(SUM(amount), 0) AS amount_inr
             FROM subscription_payments
             WHERE status = 'captured'
               AND captured_at IS NOT NULL
               AND captured_at >= ? AND captured_at < ?
             GROUP BY gateway
             ORDER BY amount_inr DESC"
        );
        $gwStmt->execute([$start, $end]);
        $byGateway = $gwStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $dupStmt = $pdo->query(
            "SELECT customer_subscription_id, COUNT(*) AS captured_rows
             FROM subscription_payments
             WHERE status = 'captured'
             GROUP BY customer_subscription_id
             HAVING captured_rows > 1
             LIMIT 20"
        );
        $duplicateSubs = $dupStmt ? ($dupStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        $legacyStmt = $pdo->prepare(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS amount_inr
             FROM subscription_payments
             WHERE status = 'captured'
               AND captured_at IS NULL
               AND updated_at >= ? AND updated_at < ?"
        );
        $legacyStmt->execute([$start, $end]);
        $legacy = $legacyStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'range_label' => $range->label(),
            'date_basis' => 'subscription_payments.captured_at',
            'captured_payment_count' => (int) ($row['captured_count'] ?? 0),
            'collected_amount_inr' => round((float) ($row['collected_inr'] ?? 0), 2),
            'gateway_breakdown' => $byGateway,
            'subscriptions_with_duplicate_captured_payments' => $duplicateSubs,
            'duplicate_captured_subscription_count' => count($duplicateSubs),
            'legacy_captured_null_timestamp_count' => (int) ($legacy['cnt'] ?? 0),
            'legacy_captured_null_timestamp_amount_inr' => round((float) ($legacy['amount_inr'] ?? 0), 2),
        ];
    }

    /**
     * Checkout pipeline — not revenue (date basis: subscription_payments.created_at).
     *
     * @return array<string, int|float|string>
     */
    public static function paymentPipelineSummary(ReportDateRange $range): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $start = $range->sqlStart();
        $end = $range->sqlEnd();

        $stmt = $pdo->prepare(
            "SELECT
                SUM(CASE WHEN status IN ('created', 'pending') THEN 1 ELSE 0 END) AS pending_attempts,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_attempts,
                COALESCE(SUM(CASE WHEN status IN ('created', 'pending') THEN amount ELSE 0 END), 0) AS pending_pipeline_inr
             FROM subscription_payments
             WHERE created_at >= ? AND created_at < ?"
        );
        $stmt->execute([$start, $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $outStmt = $pdo->query(
            "SELECT COUNT(*) AS subs, COALESCE(SUM(cs.price_paid), 0) AS amount_inr
             FROM customer_subscriptions cs
             WHERE cs.status = 'pending_payment'"
        );
        $outstanding = $outStmt ? ($outStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        return [
            'range_label' => $range->label(),
            'date_basis' => 'subscription_payments.created_at (pipeline attempts)',
            'pending_payment_attempts' => (int) ($row['pending_attempts'] ?? 0),
            'failed_payment_attempts' => (int) ($row['failed_attempts'] ?? 0),
            'pending_pipeline_amount_inr' => round((float) ($row['pending_pipeline_inr'] ?? 0), 2),
            'pending_payment_subscriptions_count' => (int) ($outstanding['subs'] ?? 0),
            'pending_payment_subscriptions_price_inr' => round((float) ($outstanding['amount_inr'] ?? 0), 2),
            'pipeline_note' => 'Pending pipeline amounts are not recognized revenue or accounting receivable.',
        ];
    }

    /**
     * Stored subscription statuses (snapshot, not date-filtered).
     *
     * @return array<string, int|string>
     */
    public static function statusSnapshot(): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->query(
            "SELECT status, COUNT(*) AS cnt
             FROM customer_subscriptions
             WHERE status IN ('pending_payment', 'payment_failed', 'active', 'paused')
             GROUP BY status"
        );
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $out = [
            'date_basis' => 'point-in-time snapshot',
            'pending_payment' => 0,
            'payment_failed' => 0,
            'active' => 0,
            'paused' => 0,
        ];
        foreach ($rows as $r) {
            $st = (string) ($r['status'] ?? '');
            if (isset($out[$st])) {
                $out[$st] = (int) ($r['cnt'] ?? 0);
            }
        }

        return $out;
    }

    /**
     * Delivery-day fulfilment by scheduled calendar date (DATE in Asia/Kolkata).
     *
     * @param string $fromYmd Inclusive Y-m-d
     * @param string $toYmd Inclusive Y-m-d
     * @return array<string, int|float|string>
     */
    public static function fulfilmentByDeliveryDate(string $fromYmd, string $toYmd): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            "SELECT
                SUM(CASE WHEN d.status = 'scheduled' THEN 1 ELSE 0 END) AS scheduled_days,
                SUM(CASE WHEN d.status = 'delivered' THEN 1 ELSE 0 END) AS delivered_by_schedule_date,
                SUM(CASE WHEN d.status = 'skipped' THEN 1 ELSE 0 END) AS skipped_days,
                SUM(CASE WHEN d.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_days
             FROM subscription_delivery_days d
             WHERE d.delivery_date >= ? AND d.delivery_date <= ?"
        );
        $stmt->execute([$fromYmd, $toYmd]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $tz = new DateTimeZone(ReportDateRange::TIMEZONE);
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $missedStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM subscription_delivery_days d
             INNER JOIN customer_subscriptions cs ON cs.id = d.customer_subscription_id
             WHERE d.delivery_date >= ? AND d.delivery_date <= ?
               AND d.delivery_date < ?
               AND d.status = 'scheduled'
               AND cs.status = 'active'"
        );
        $missedStmt->execute([$fromYmd, $toYmd, $today]);
        $missed = (int) $missedStmt->fetchColumn();

        $scheduled = (int) ($row['scheduled_days'] ?? 0);
        $delivered = (int) ($row['delivered_by_schedule_date'] ?? 0);
        $skipped = (int) ($row['skipped_days'] ?? 0);
        $cancelled = (int) ($row['cancelled_days'] ?? 0);
        $denom = $delivered + $skipped + $missed + $cancelled;
        $rate = $denom > 0 ? round(100.0 * $delivered / $denom, 2) : 0.0;

        return [
            'date_basis' => 'subscription_delivery_days.delivery_date (scheduled calendar date)',
            'from_date' => $fromYmd,
            'to_date' => $toYmd,
            'scheduled_delivery_days' => $scheduled,
            'delivered_by_schedule_date' => $delivered,
            'skipped_delivery_days' => $skipped,
            'cancelled_delivery_days' => $cancelled,
            'missed_delivery_days_derived' => $missed,
            'missed_derivation' => 'scheduled date before today, status still scheduled, subscription active',
            'fulfilment_rate_pct' => $rate,
            'fulfilment_rate_denominator' => $denom,
        ];
    }

    /**
     * Actual courier completion (date basis: deliveries.delivered_at).
     *
     * @return array<string, int|string>
     */
    public static function courierCompletionSummary(ReportDateRange $range): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $start = $range->sqlStart();
        $end = $range->sqlEnd();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS deliveries_completed
             FROM deliveries del
             INNER JOIN orders o ON o.id = del.order_id
             WHERE o.order_source = 'subscription'
               AND del.status = 'delivered'
               AND del.delivered_at IS NOT NULL
               AND del.delivered_at >= ? AND del.delivered_at < ?"
        );
        $stmt->execute([$start, $end]);

        return [
            'range_label' => $range->label(),
            'date_basis' => 'deliveries.delivered_at',
            'subscription_courier_deliveries_completed' => (int) $stmt->fetchColumn(),
        ];
    }

    /**
     * Generated subscription fulfilment orders (not revenue).
     *
     * @return array<string, int|string>
     */
    public static function generatedOrdersSummary(ReportDateRange $range): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $start = $range->sqlStart();
        $end = $range->sqlEnd();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS generated_orders
             FROM orders o
             WHERE o.order_source = 'subscription'
               AND o.placed_at >= ? AND o.placed_at < ?"
        );
        $stmt->execute([$start, $end]);

        return [
            'range_label' => $range->label(),
            'date_basis' => 'orders.placed_at (fulfilment order generation)',
            'generated_subscription_orders' => (int) $stmt->fetchColumn(),
            'note' => 'Subscription fulfilment orders use grand_total = 0; not storefront payment collection.',
        ];
    }

    /**
     * Captured subscription payments in period (date basis: subscription_payments.captured_at).
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function capturedPayments(ReportDateRange $range, int $page): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $start = $range->sqlStart();
        $end = $range->sqlEnd();

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM subscription_payments sp
             WHERE sp.status = 'captured'
               AND sp.captured_at IS NOT NULL
               AND sp.captured_at >= ? AND sp.captured_at < ?"
        );
        $countStmt->execute([$start, $end]);
        $total = (int) $countStmt->fetchColumn();

        $offset = max(0, ($page - 1) * self::PAYMENTS_PER_PAGE);
        $limit = (int) self::PAYMENTS_PER_PAGE;
        $listStmt = $pdo->prepare(
            "SELECT sp.id, sp.gateway, sp.status, sp.amount, sp.currency, sp.gateway_payment_id,
                    sp.created_at, sp.captured_at, cs.subscription_number, cs.activated_at
             FROM subscription_payments sp
             INNER JOIN customer_subscriptions cs ON cs.id = sp.customer_subscription_id
             WHERE sp.status = 'captured'
               AND sp.captured_at IS NOT NULL
               AND sp.captured_at >= ? AND sp.captured_at < ?
             ORDER BY sp.captured_at DESC, sp.id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $listStmt->execute([$start, $end]);
        $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function capturedPaymentsForExport(ReportDateRange $range): array
    {
        $all = [];
        $page = 1;
        do {
            $batch = self::capturedPayments($range, $page);
            $all = array_merge($all, $batch['rows']);
            if (count($all) >= self::EXPORT_MAX_ROWS) {
                $all = array_slice($all, 0, self::EXPORT_MAX_ROWS);
                break;
            }
            $page++;
        } while (count($batch['rows']) === self::PAYMENTS_PER_PAGE && ($page - 1) * self::PAYMENTS_PER_PAGE < $batch['total']);

        return $all;
    }

    public static function formatSubscriptionTimestamp(?string $raw): string
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
