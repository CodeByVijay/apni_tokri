<?php

declare(strict_types=1);

/**
 * Database-backed sales and order reports (Stage 14.5).
 */
final class SalesReportService
{
    public const ORDERS_PER_PAGE = 50;
    public const EXPORT_MAX_ROWS = 5000;

    /**
     * @return array<string, int|float|string>
     */
    public static function summary(ReportDateRange $range): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $ts = ReportDateRange::orderBusinessTimestampSql();
        $start = $range->sqlStart();
        $end = $range->sqlEnd();

        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total_orders,
                SUM(CASE WHEN o.payment_status = 'paid' AND o.status <> 'cancelled' THEN 1 ELSE 0 END) AS paid_orders,
                SUM(CASE WHEN o.payment_status = 'pending' THEN 1 ELSE 0 END) AS pending_payment_orders,
                SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders,
                COALESCE(SUM(CASE WHEN o.payment_status = 'paid' AND o.status <> 'cancelled' THEN o.grand_total ELSE 0 END), 0) AS gross_order_value,
                COALESCE(SUM(CASE WHEN o.payment_status = 'paid' AND o.status <> 'cancelled' THEN o.discount_total ELSE 0 END), 0) AS discounts,
                COALESCE(SUM(CASE WHEN o.payment_status = 'paid' AND o.status <> 'cancelled' THEN o.delivery_fee ELSE 0 END), 0) AS delivery_charges
             FROM orders o
             WHERE {$ts} >= ? AND {$ts} < ?"
        );
        $stmt->execute([$start, $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $refWhere = RefundReportService::sqlUnifiedCompletedPeriodWhere('pr');
        $refStmt = $pdo->prepare("SELECT COALESCE(SUM(pr.amount_inr), 0) FROM payment_refunds pr WHERE {$refWhere}");
        $refStmt->execute(RefundReportService::unifiedCompletedPeriodBind($range));
        $refundAmount = (float) $refStmt->fetchColumn();

        $gross = (float) ($row['gross_order_value'] ?? 0);
        $paidOrders = (int) ($row['paid_orders'] ?? 0);
        $avg = $paidOrders > 0 ? round($gross / $paidOrders, 2) : 0.0;

        return [
            'range_label' => $range->label(),
            'gross_order_value' => round($gross, 2),
            'discounts' => round((float) ($row['discounts'] ?? 0), 2),
            'delivery_charges' => round((float) ($row['delivery_charges'] ?? 0), 2),
            'refund_amount' => round($refundAmount, 2),
            'net_sales' => round($gross - $refundAmount, 2),
            'total_orders' => (int) ($row['total_orders'] ?? 0),
            'average_order_value' => $avg,
            'paid_orders' => $paidOrders,
            'pending_payment_orders' => (int) ($row['pending_payment_orders'] ?? 0),
            'cancelled_orders' => (int) ($row['cancelled_orders'] ?? 0),
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function orders(
        ReportDateRange $range,
        int $page,
        ?string $orderStatus = null,
        ?string $paymentStatus = null
    ): array {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $ts = ReportDateRange::orderBusinessTimestampSql();
        $start = $range->sqlStart();
        $end = $range->sqlEnd();

        $where = ["{$ts} >= ?", "{$ts} < ?"];
        $params = [$start, $end];
        if ($orderStatus !== null && $orderStatus !== '') {
            $where[] = 'o.status = ?';
            $params[] = $orderStatus;
        }
        if ($paymentStatus !== null && $paymentStatus !== '') {
            $where[] = 'o.payment_status = ?';
            $params[] = $paymentStatus;
        }
        $sqlWhere = implode(' AND ', $where);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = max(0, ($page - 1) * self::ORDERS_PER_PAGE);
        $limit = self::ORDERS_PER_PAGE;
        $listSql = "SELECT o.id, o.order_number, o.status, o.payment_status, o.subtotal, o.discount_total,
                           o.delivery_fee, o.grand_total, o.placed_at, o.created_at,
                           c.mobile AS customer_mobile,
                           (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count,
                           (SELECT p.gateway FROM payments p WHERE p.order_id = o.id AND p.status = 'captured'
                            ORDER BY p.id DESC LIMIT 1) AS payment_method,
                           (SELECT rr.status FROM refund_requests rr WHERE rr.order_id = o.id
                            ORDER BY rr.id DESC LIMIT 1) AS refund_status
                    FROM orders o
                    LEFT JOIN customers c ON c.id = o.customer_id
                    WHERE {$sqlWhere}
                    ORDER BY {$ts} DESC, o.id DESC
                    LIMIT {$limit} OFFSET {$offset}";
        $listStmt = $pdo->prepare($listSql);
        $listStmt->execute($params);
        $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function ordersForExport(
        ReportDateRange $range,
        ?string $orderStatus = null,
        ?string $paymentStatus = null
    ): array {
        $all = [];
        $page = 1;
        do {
            $batch = self::orders($range, $page, $orderStatus, $paymentStatus);
            $all = array_merge($all, $batch['rows']);
            if (count($all) >= self::EXPORT_MAX_ROWS) {
                $all = array_slice($all, 0, self::EXPORT_MAX_ROWS);
                break;
            }
            $page++;
        } while (count($batch['rows']) === self::ORDERS_PER_PAGE && ($page - 1) * self::ORDERS_PER_PAGE < $batch['total']);

        return $all;
    }

    public static function formatOrderDate(array $orderRow): string
    {
        $raw = (string) ($orderRow['placed_at'] ?? $orderRow['created_at'] ?? '');
        if ($raw === '') {
            return '';
        }
        try {
            $dt = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
            return $dt->setTimezone(new DateTimeZone(ReportDateRange::TIMEZONE))->format('Y-m-d H:i');
        } catch (Throwable $e) {
            return $raw;
        }
    }

    public static function customerLabel(array $orderRow): string
    {
        $mobile = trim((string) ($orderRow['customer_mobile'] ?? ''));

        return $mobile !== '' ? $mobile : 'Guest';
    }

    public static function refundStatusLabel(?string $status): string
    {
        if ($status === null || $status === '') {
            return '—';
        }
        if (class_exists('RefundRequestService', false)) {
            return RefundRequestService::publicStatusLabel($status);
        }

        return $status;
    }
}
