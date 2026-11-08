<?php

declare(strict_types=1);

/** Live admin dashboard aggregates (Asia/Kolkata day boundaries). */
final class AdminDashboardService
{
    private const PAYMENT_PENDING_ORDER = ['pending', 'pending_payment', 'authorized'];

    /** @return array{orders_today: int, pending_payments: int, low_stock: int, open_tickets: int} */
    public static function kpiTiles(): array
    {
        if (!Install::isSchemaReady()) {
            return ['orders_today' => 0, 'pending_payments' => 0, 'low_stock' => 0, 'open_tickets' => 0];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $today = DeliveryConfig::now()->format('Y-m-d');

        $ordersToday = (int) $pdo->query(
            "SELECT COUNT(*) FROM orders WHERE DATE(placed_at) = " . $pdo->quote($today)
        )->fetchColumn();

        $pendingPayments = (int) $pdo->query(
            "SELECT COUNT(*) FROM orders WHERE payment_status IN ('pending', 'pending_payment', 'authorized')"
        )->fetchColumn();

        $lowStock = CommerceGateway::useDatabase() ? InventoryService::countLowStockVariants() : 0;

        $openTickets = 0;
        try {
            $openTickets = (int) $pdo->query(
                "SELECT COUNT(*) FROM support_tickets WHERE status NOT IN ('closed', 'resolved')"
            )->fetchColumn();
        } catch (Throwable $e) {
            $openTickets = 0;
        }

        return [
            'orders_today' => $ordersToday,
            'pending_payments' => $pendingPayments,
            'low_stock' => $lowStock,
            'open_tickets' => $openTickets,
        ];
    }

    /**
     * Primary payload for the redesigned admin dashboard (single round-trip).
     *
     * @return array<string, mixed>
     */
    public static function dashboardOverview(ReportDateRange $range): array
    {
        if (!Install::isSchemaReady()) {
            return self::emptyDashboardPayload();
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $ts = ReportDateRange::orderBusinessTimestampSql();
        $start = $range->sqlStart();
        $end = $range->sqlEnd();
        $prevRange = self::previousBusinessRange($range);

        $period = self::fetchOrderPeriodMetrics($pdo, $ts, $start, $end);
        $prev = $prevRange !== null
            ? self::fetchOrderPeriodMetrics($pdo, $ts, $prevRange->sqlStart(), $prevRange->sqlEnd())
            : null;

        $todayYmd = (new DateTimeImmutable('now', new DateTimeZone(ReportDateRange::TIMEZONE)))->format('Y-m-d');
        $todayStart = $todayYmd . ' 00:00:00';
        $todayEnd = (new DateTimeImmutable($todayYmd . ' 00:00:00', new DateTimeZone(ReportDateRange::TIMEZONE)))
            ->modify('+1 day')->format('Y-m-d H:i:s');
        $yesterdayStart = (new DateTimeImmutable($todayYmd . ' 00:00:00', new DateTimeZone(ReportDateRange::TIMEZONE)))
            ->modify('-1 day')->format('Y-m-d H:i:s');
        $todayPaid = self::fetchOrderPeriodMetrics($pdo, $ts, $todayStart, $todayEnd);
        $yesterdayPaid = self::fetchOrderPeriodMetrics($pdo, $ts, $yesterdayStart, $todayStart);
        $todayAll = self::fetchAllOrderPeriodMetrics($pdo, $ts, $todayStart, $todayEnd);
        $yesterdayAll = self::fetchAllOrderPeriodMetrics($pdo, $ts, $yesterdayStart, $todayStart);

        $trends = self::dailyTrendForRange($pdo, $ts, $start, $end);
        $deliveriesToday = self::deliveriesTodaySummary();

        return [
            'range_label' => $range->label(),
            'kpis' => [
                'revenue' => self::kpiMoney($todayPaid['revenue_paid'], $yesterdayPaid['revenue_paid']),
                'orders' => self::kpiCount($todayAll['orders_all'], $yesterdayAll['orders_all']),
                'aov' => self::kpiMoney($todayPaid['aov_paid'], $yesterdayPaid['aov_paid']),
                'pending_payments' => self::kpiCount(
                    (int) self::scalar(
                        $pdo,
                        'SELECT COUNT(*) FROM orders o WHERE o.payment_status IN (' . self::sqlInPlaceholders(self::PAYMENT_PENDING_ORDER) . ')',
                        self::PAYMENT_PENDING_ORDER
                    ),
                    null
                ),
                'active_subscriptions' => self::kpiCount(
                    (int) self::scalar(
                        $pdo,
                        "SELECT COUNT(*) FROM customer_subscriptions WHERE status = 'active'",
                        []
                    ),
                    null
                ),
                'low_stock' => self::kpiCount(
                    CommerceGateway::useDatabase() ? InventoryService::countLowStockVariants() : 0,
                    null
                ),
            ],
            'period_summary' => [
                'revenue' => self::kpiMoney($period['revenue_paid'], $prev['revenue_paid'] ?? null),
                'orders' => self::kpiCount($period['orders_paid'], $prev['orders_paid'] ?? null),
                'aov' => self::kpiMoney($period['aov_paid'], $prev['aov_paid'] ?? null),
            ],
            'trends' => $trends,
            'order_status' => self::orderStatusDistribution($pdo, $ts, $start, $end),
            'order_payment' => self::orderPaymentDistribution($pdo, $ts, $start, $end),
            'payment_records' => self::paymentRecordDistribution($pdo, $start, $end),
            'top_products' => self::topSellingProducts($pdo, $ts, $start, $end, 8),
            'subscription' => self::subscriptionDeliveryMetrics($pdo),
            'inventory' => self::inventoryAlerts($pdo),
            'recent_orders' => self::recentOrdersForDashboard(8),
            'open_tickets' => self::openTicketsCount($pdo),
            'cancellation_refunds' => RefundRequestService::adminCancellationRefundDashboardCounts(),
            'deliveries_today' => $deliveriesToday,
        ];
    }

    /** @return array<string, mixed> */
    public static function emptyDashboardPayloadForView(): array
    {
        return self::emptyDashboardPayload();
    }

    /** @return array<string, mixed> */
    private static function emptyDashboardPayload(): array
    {
        $zero = static function (): array {
            return ['value' => 0, 'formatted' => '—', 'delta' => null, 'delta_label' => null];
        };

        return [
            'range_label' => '',
            'kpis' => [
                'revenue' => $zero(),
                'orders' => $zero(),
                'aov' => $zero(),
                'pending_payments' => $zero(),
                'active_subscriptions' => $zero(),
                'low_stock' => $zero(),
            ],
            'period_summary' => [
                'revenue' => $zero(),
                'orders' => $zero(),
                'aov' => $zero(),
            ],
            'trends' => ['days' => [], 'revenue_max' => 1, 'orders_max' => 1],
            'order_status' => ['segments' => [], 'total' => 0],
            'order_payment' => ['rows' => []],
            'payment_records' => ['rows' => []],
            'top_products' => [],
            'subscription' => [
                'active' => 0,
                'upcoming' => 0,
                'delivered' => 0,
                'skipped' => 0,
            ],
            'inventory' => ['low' => 0, 'out' => 0, 'attention' => 0],
            'recent_orders' => [],
            'open_tickets' => 0,
            'cancellation_refunds' => [
                'new_cancellations' => 0,
                'approval_required' => 0,
                'processing' => 0,
                'failed' => 0,
                'completed' => 0,
            ],
            'deliveries_today' => ['scheduled_today' => 0, 'subscription_skips' => 0],
        ];
    }

    private static function openTicketsCount(PDO $pdo): int
    {
        try {
            return (int) $pdo->query(
                "SELECT COUNT(*) FROM support_tickets WHERE status NOT IN ('closed', 'resolved')"
            )->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Previous period of equal length immediately before $range (for trend comparison).
     */
    private static function previousBusinessRange(ReportDateRange $range): ?ReportDateRange
    {
        $tss = strtotime($range->sqlStart());
        $tse = strtotime($range->sqlEnd());
        if ($tss === false || $tse === false || $tse <= $tss) {
            return null;
        }
        $len = $tse - $tss;
        $prevEnd = $tss;
        $prevStart = $tss - $len;
        $tz = new DateTimeZone(ReportDateRange::TIMEZONE);
        $from = (new DateTimeImmutable('@' . $prevStart))->setTimezone($tz)->format('Y-m-d');
        $to = (new DateTimeImmutable('@' . $prevEnd))->setTimezone($tz)->modify('-1 day')->format('Y-m-d');
        $parsed = ReportDateRange::fromRequest(['preset' => 'custom', 'from' => $from, 'to' => $to]);

        return $parsed['ok'] ? $parsed['range'] : null;
    }

    /**
     * @return array{revenue_paid: float, orders_paid: int, aov_paid: float}
     */
    private static function fetchOrderPeriodMetrics(PDO $pdo, string $ts, string $start, string $end): array
    {
        $stmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN o.payment_status = 'paid' AND o.status <> 'cancelled' THEN o.grand_total ELSE 0 END), 0) AS revenue_paid,
                SUM(CASE WHEN o.payment_status = 'paid' AND o.status <> 'cancelled' THEN 1 ELSE 0 END) AS orders_paid
             FROM orders o
             WHERE {$ts} >= ? AND {$ts} < ?"
        );
        $stmt->execute([$start, $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $rev = (float) ($row['revenue_paid'] ?? 0);
        $ord = (int) ($row['orders_paid'] ?? 0);
        $aov = $ord > 0 ? round($rev / $ord, 2) : 0.0;

        return ['revenue_paid' => $rev, 'orders_paid' => $ord, 'aov_paid' => $aov];
    }

    /**
     * All orders in range (any status): count and sum of grand_total — for “orders placed” KPIs.
     *
     * @return array{revenue_all: float, orders_all: int, aov_all: float}
     */
    private static function fetchAllOrderPeriodMetrics(PDO $pdo, string $ts, string $start, string $end): array
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(o.grand_total), 0) AS revenue_all, COUNT(*) AS orders_all
             FROM orders o
             WHERE {$ts} >= ? AND {$ts} < ?"
        );
        $stmt->execute([$start, $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $rev = (float) ($row['revenue_all'] ?? 0);
        $ord = (int) ($row['orders_all'] ?? 0);
        $aov = $ord > 0 ? round($rev / $ord, 2) : 0.0;

        return ['revenue_all' => $rev, 'orders_all' => $ord, 'aov_all' => $aov];
    }

    /**
     * @param array{revenue_paid?: float, orders_paid?: int, aov_paid?: float}|null $prev
     * @return array{value: float, formatted: string, delta: ?float, delta_label: ?string}
     */
    private static function kpiMoney(float $current, ?float $previous): array
    {
        $formatted = $current <= 0 && $previous === null ? '₹0' : '₹' . number_format($current, 0, '.', ',');
        if ($previous === null || $previous <= 0) {
            return ['value' => $current, 'formatted' => $formatted, 'delta' => null, 'delta_label' => null];
        }
        $delta = (($current - $previous) / $previous) * 100;

        return [
            'value' => $current,
            'formatted' => $formatted,
            'delta' => round($delta, 1),
            'delta_label' => sprintf('%s%.1f%% vs prior period', $delta >= 0 ? '+' : '', $delta),
        ];
    }

    /**
     * @return array{value: int, formatted: string, delta: ?float, delta_label: ?string}
     */
    private static function kpiCount(int $current, ?int $previous): array
    {
        $formatted = (string) max(0, $current);
        if ($previous === null) {
            return ['value' => $current, 'formatted' => $formatted, 'delta' => null, 'delta_label' => null];
        }
        if ($previous === 0) {
            return $current === 0
                ? ['value' => 0, 'formatted' => '0', 'delta' => null, 'delta_label' => null]
                : ['value' => $current, 'formatted' => $formatted, 'delta' => null, 'delta_label' => 'No prior period data'];
        }
        $delta = (($current - $previous) / $previous) * 100;

        return [
            'value' => $current,
            'formatted' => $formatted,
            'delta' => round($delta, 1),
            'delta_label' => sprintf('%s%.1f%% vs prior period', $delta >= 0 ? '+' : '', $delta),
        ];
    }

    /**
     * @return array{days: list<array{ymd: string, label: string, revenue: float, orders: int}>, revenue_max: float, orders_max: int}
     */
    private static function dailyTrendForRange(PDO $pdo, string $ts, string $start, string $end): array
    {
        $stmt = $pdo->prepare(
            "SELECT DATE({$ts}) AS d,
                    COALESCE(SUM(CASE WHEN o.payment_status = 'paid' AND o.status <> 'cancelled' THEN o.grand_total ELSE 0 END), 0) AS revenue,
                    SUM(CASE WHEN o.payment_status = 'paid' AND o.status <> 'cancelled' THEN 1 ELSE 0 END) AS orders_n
             FROM orders o
             WHERE {$ts} >= ? AND {$ts} < ?
             GROUP BY DATE({$ts})
             ORDER BY d ASC"
        );
        $stmt->execute([$start, $end]);
        $byDay = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byDay[(string) $r['d']] = [
                'revenue' => (float) ($r['revenue'] ?? 0),
                'orders' => (int) ($r['orders_n'] ?? 0),
            ];
        }
        $tz = new DateTimeZone(ReportDateRange::TIMEZONE);
        $cur = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $start, $tz);
        $endDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $end, $tz);
        if (!$cur || !$endDt) {
            return ['days' => [], 'revenue_max' => 1, 'orders_max' => 1];
        }
        $days = [];
        $revMax = 1.0;
        $ordMax = 1;
        while ($cur < $endDt) {
            $ymd = $cur->format('Y-m-d');
            $rev = $byDay[$ymd]['revenue'] ?? 0.0;
            $ord = $byDay[$ymd]['orders'] ?? 0;
            $revMax = max($revMax, $rev);
            $ordMax = max($ordMax, $ord);
            $days[] = [
                'ymd' => $ymd,
                'label' => $cur->format('j M'),
                'revenue' => $rev,
                'orders' => $ord,
            ];
            $cur = $cur->modify('+1 day');
        }

        return ['days' => $days, 'revenue_max' => $revMax, 'orders_max' => $ordMax];
    }

    /**
     * @return array{segments: list<array{key: string, label: string, count: int, color: string}>, total: int}
     */
    private static function orderStatusDistribution(PDO $pdo, string $ts, string $start, string $end): array
    {
        $buckets = [
            'pending' => [
                'label' => 'Pending',
                'statuses' => [OrderStatus::PENDING_PAYMENT],
                'color' => '#94a3b8',
            ],
            'confirmed' => ['label' => 'Confirmed', 'statuses' => [OrderStatus::CONFIRMED], 'color' => '#2d7048'],
            'processing' => [
                'label' => 'Processing',
                'statuses' => [OrderStatus::ACCEPTED, OrderStatus::PREPARING, OrderStatus::READY, OrderStatus::OUT_FOR_DELIVERY],
                'color' => '#245C3A',
            ],
            'delivered' => ['label' => 'Delivered', 'statuses' => [OrderStatus::DELIVERED], 'color' => '#166534'],
            'cancelled' => ['label' => 'Cancelled', 'statuses' => [OrderStatus::CANCELLED], 'color' => '#b91c1c'],
        ];
        $segments = [];
        $total = 0;
        foreach ($buckets as $key => $meta) {
            $placeholders = self::sqlInPlaceholders($meta['statuses']);
            $sql = "SELECT COUNT(*) FROM orders o WHERE {$ts} >= ? AND {$ts} < ? AND o.status IN ({$placeholders})";
            $params = array_merge([$start, $end], $meta['statuses']);
            $c = (int) self::scalar($pdo, $sql, $params);
            $total += $c;
            $segments[] = ['key' => $key, 'label' => $meta['label'], 'count' => $c, 'color' => $meta['color']];
        }

        return ['segments' => $segments, 'total' => $total];
    }

    /**
     * Order-level payment_status distribution for the selected period.
     *
     * @return array{rows: list<array{label: string, count: int}>}
     */
    private static function orderPaymentDistribution(PDO $pdo, string $ts, string $start, string $end): array
    {
        $defs = [
            ['label' => 'Paid orders', 'sql' => "o.payment_status = 'paid' AND o.status <> 'cancelled'"],
            ['label' => 'Pending payment', 'sql' => 'o.payment_status IN (' . self::sqlInQuoted(self::PAYMENT_PENDING_ORDER) . ')'],
            ['label' => 'Payment failed', 'sql' => "(o.payment_status = 'payment_failed' OR o.status = 'payment_failed')"],
        ];
        $rows = [];
        foreach ($defs as $def) {
            $sql = "SELECT COUNT(*) FROM orders o WHERE {$ts} >= ? AND {$ts} < ? AND ({$def['sql']})";
            $rows[] = [
                'label' => $def['label'],
                'count' => (int) self::scalar($pdo, $sql, [$start, $end]),
            ];
        }

        $onlinePaid = (int) self::scalar(
            $pdo,
            "SELECT COUNT(DISTINCT o.id) FROM orders o
             INNER JOIN payments p ON p.order_id = o.id AND p.status = 'captured'
             WHERE {$ts} >= ? AND {$ts} < ?
               AND o.payment_status = 'paid' AND o.status <> 'cancelled'
               AND LOWER(p.gateway) NOT IN ('simulate','cod','cash_on_delivery')",
            [$start, $end]
        );
        $rows[] = ['label' => 'Online (captured PG)', 'count' => $onlinePaid];
        $codPaid = (int) self::scalar(
            $pdo,
            "SELECT COUNT(DISTINCT o.id) FROM orders o
             INNER JOIN payments p ON p.order_id = o.id AND p.status = 'captured'
             WHERE {$ts} >= ? AND {$ts} < ?
               AND o.payment_status = 'paid' AND o.status <> 'cancelled'
               AND LOWER(p.gateway) IN ('cod','cash_on_delivery')",
            [$start, $end]
        );
        $rows[] = ['label' => 'Cash on delivery (captured)', 'count' => $codPaid];

        return ['rows' => $rows];
    }

    /**
     * @return array{rows: list<array{label: string, count: int}>}
     */
    private static function paymentRecordDistribution(PDO $pdo, string $start, string $end): array
    {
        $stmt = $pdo->prepare(
            "SELECT p.status, COUNT(*) AS c
             FROM payments p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE COALESCE(o.placed_at, o.created_at) >= ? AND COALESCE(o.placed_at, o.created_at) < ?
             GROUP BY p.status"
        );
        $stmt->execute([$start, $end]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(string) ($r['status'] ?? '')] = (int) ($r['c'] ?? 0);
        }
        $rows = [];
        foreach (['captured', 'pending', 'failed', 'created', 'authorized'] as $st) {
            if (empty($map[$st])) {
                continue;
            }
            $lb = CustomerOrderDetailService::paymentRowStatusLabel($st);
            $rows[] = ['label' => $lb . ' (' . $st . ')', 'count' => $map[$st]];
        }

        return ['rows' => $rows];
    }

    /**
     * @return list<array{product_name: string, image_url: string, qty_sold: int, revenue: float}>
     */
    private static function topSellingProducts(PDO $pdo, string $ts, string $start, string $end, int $limit): array
    {
        $limit = max(1, min(20, $limit));
        $stmt = $pdo->prepare(
            "SELECT oi.product_name,
                    SUM(oi.quantity) AS qty_sold,
                    COALESCE(SUM(oi.line_total), 0) AS revenue,
                    MIN(pv.product_id) AS product_id
             FROM order_items oi
             INNER JOIN orders o ON o.id = oi.order_id
             LEFT JOIN product_variants pv ON pv.id = oi.variant_id
             WHERE {$ts} >= ? AND {$ts} < ?
               AND o.payment_status = 'paid' AND o.status <> 'cancelled'
             GROUP BY oi.product_name
             ORDER BY qty_sold DESC
             LIMIT " . $limit
        );
        $stmt->execute([$start, $end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $productIds = [];
        foreach ($rows as $r) {
            $pid = isset($r['product_id']) ? (int) $r['product_id'] : 0;
            if ($pid > 0) {
                $productIds[$pid] = true;
            }
        }
        $imagesByPid = self::primaryProductImageUrls($pdo, array_keys($productIds));
        $out = [];
        foreach ($rows as $r) {
            $pid = isset($r['product_id']) ? (int) $r['product_id'] : 0;
            $img = $pid > 0 && isset($imagesByPid[$pid]) ? $imagesByPid[$pid] : '';
            $out[] = [
                'product_name' => (string) ($r['product_name'] ?? ''),
                'image_url' => $img,
                'qty_sold' => (int) ($r['qty_sold'] ?? 0),
                'revenue' => round((float) ($r['revenue'] ?? 0), 2),
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $productIds
     * @return array<int, string>
     */
    private static function primaryProductImageUrls(PDO $pdo, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $placeholders = self::sqlInPlaceholders($productIds);
        $stmt = $pdo->prepare(
            "SELECT product_id, file_path FROM product_images
             WHERE product_id IN ({$placeholders})
             ORDER BY product_id ASC, is_primary DESC, sort_order ASC, id ASC"
        );
        $stmt->execute($productIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pid = (int) ($r['product_id'] ?? 0);
            if ($pid > 0 && !isset($out[$pid])) {
                $path = (string) ($r['file_path'] ?? '');
                if ($path !== '') {
                    $out[$pid] = asset_url($path);
                }
            }
        }

        return $out;
    }

    /**
     * @return array{active: int, upcoming: int, delivered: int, skipped: int}
     */
    private static function subscriptionDeliveryMetrics(PDO $pdo): array
    {
        $tz = new DateTimeZone(ReportDateRange::TIMEZONE);
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $active = (int) self::scalar($pdo, "SELECT COUNT(*) FROM customer_subscriptions WHERE status = 'active'", []);
        $upcoming = (int) self::scalar(
            $pdo,
            "SELECT COUNT(*) FROM subscription_delivery_days WHERE delivery_date >= ? AND status = 'scheduled'",
            [$today]
        );
        $delivered = (int) self::scalar(
            $pdo,
            "SELECT COUNT(*) FROM subscription_delivery_days WHERE status = 'delivered'",
            []
        );
        $skipped = (int) self::scalar(
            $pdo,
            'SELECT COUNT(*) FROM subscription_delivery_days WHERE status IN (' . self::sqlInQuoted([
                SubscriptionDeliveryStatus::SKIPPED_CARRY_FORWARD,
                'skipped',
            ]) . ')',
            []
        );

        return ['active' => $active, 'upcoming' => $upcoming, 'delivered' => $delivered, 'skipped' => $skipped];
    }

    /**
     * @return array{low: int, out: int, attention: int}
     */
    private static function inventoryAlerts(PDO $pdo): array
    {
        $low = CommerceGateway::useDatabase() ? InventoryService::countLowStockVariants() : 0;
        $out = 0;
        $attention = 0;
        try {
            $out = (int) $pdo->query(
                'SELECT COUNT(*) FROM product_variants v INNER JOIN products p ON p.id = v.product_id
                 WHERE v.is_active = 1 AND p.status = \'published\' AND v.stock_qty = 0'
            )->fetchColumn();
        } catch (Throwable $e) {
            $out = 0;
        }
        $attention = $low + $out;

        return ['low' => $low, 'out' => $out, 'attention' => $attention];
    }

    /**
     * @return list<array{order_number: string, customer: string, amount: string, status: string, status_class: string, placed_at: string, href: string}>
     */
    private static function recentOrdersForDashboard(int $limit): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $limit = max(1, min(50, $limit));
        $stmt = $pdo->prepare(
            'SELECT o.id, o.order_number, o.status, o.grand_total, o.placed_at, o.created_at,
                    c.name AS customer_name, c.mobile AS customer_mobile
             FROM orders o
             LEFT JOIN customers c ON c.id = o.customer_id
             ORDER BY COALESCE(o.placed_at, o.created_at) DESC, o.id DESC
             LIMIT ' . $limit
        );
        $stmt->execute();
        $base = url_base() . '/admin/orders/view.php?id=';
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $customer = trim((string) ($r['customer_name'] ?? ''));
            $mobile = trim((string) ($r['customer_mobile'] ?? ''));
            if ($customer === '' && $mobile === '') {
                $cust = 'Guest';
            } elseif ($customer !== '' && $mobile !== '') {
                $cust = $customer . ' · ' . $mobile;
            } else {
                $cust = $customer !== '' ? $customer : $mobile;
            }
            $ts = (string) ($r['placed_at'] ?? $r['created_at'] ?? '');
            $rows[] = [
                'order_number' => (string) ($r['order_number'] ?? ''),
                'customer' => $cust,
                'amount' => catalogue_format_price((float) ($r['grand_total'] ?? 0)),
                'status' => AdminOrderService::statusLabel((string) ($r['status'] ?? '')),
                'status_class' => admin_status_class((string) ($r['status'] ?? '')),
                'placed_at' => $ts !== '' ? date('d M Y, H:i', strtotime($ts)) : '—',
                'href' => $base . (int) ($r['id'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * @param list<string> $vals
     */
    private static function sqlInPlaceholders(array $vals): string
    {
        return implode(',', array_fill(0, count($vals), '?'));
    }

    /**
     * @param list<string> $vals
     */
    private static function sqlInQuoted(array $vals): string
    {
        $out = [];
        foreach ($vals as $v) {
            $out[] = "'" . str_replace("'", "''", $v) . "'";
        }

        return implode(',', $out);
    }

    /**
     * @param list<int|string|float>|array{} $params
     */
    private static function scalar(PDO $pdo, string $sql, array $params): string|int|float|false|null
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    /**
     * Orders placed per day for the last 7 calendar days (Mon–Sun labels).
     *
     * @return list<array{label: string, count: int, is_today: bool}>
     */
    public static function ordersLastSevenDays(): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $now = DeliveryConfig::now();
        $out = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = $now->modify('-' . $i . ' days');
            $ymd = $day->format('Y-m-d');
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE DATE(placed_at) = ?');
            $stmt->execute([$ymd]);
            $out[] = [
                'label' => $day->format('D'),
                'count' => (int) $stmt->fetchColumn(),
                'is_today' => $i === 0,
            ];
        }

        return $out;
    }

    /** @return array{scheduled_today: int, subscription_skips: int} */
    public static function deliveriesTodaySummary(): array
    {
        if (!Install::isSchemaReady()) {
            return ['scheduled_today' => 0, 'subscription_skips' => 0];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $today = DeliveryConfig::now()->format('Y-m-d');
        $scheduled = 0;
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM subscription_delivery_days WHERE delivery_date = ? AND status = 'scheduled'"
            );
            $stmt->execute([$today]);
            $scheduled = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $scheduled = 0;
        }
        $skips = 0;
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM subscription_skips WHERE DATE(skipped_at) = ?'
            );
            $stmt->execute([$today]);
            $skips = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $skips = 0;
        }

        return ['scheduled_today' => $scheduled, 'subscription_skips' => $skips];
    }

    /**
     * @return list<array{title: string, subtitle: string, status_class: string}>
     */
    public static function recentActivity(int $limit = 8): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $out = [];
        $stmt = $pdo->query(
            'SELECT order_number, status, placed_at FROM orders ORDER BY placed_at DESC, id DESC LIMIT ' . (int) max(1, $limit)
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $st = (string) ($row['status'] ?? '');
            $out[] = [
                'title' => 'Order ' . (string) ($row['order_number'] ?? ''),
                'subtitle' => ucwords(str_replace('_', ' ', $st)),
                'status_class' => admin_status_class($st),
            ];
        }
        if ($out !== []) {
            return $out;
        }
        try {
            $rstmt = $pdo->query(
                'SELECT id, rating, created_at FROM product_reviews ORDER BY created_at DESC LIMIT ' . (int) max(1, $limit)
            );
            foreach ($rstmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[] = [
                    'title' => 'Review #' . (int) $row['id'],
                    'subtitle' => (int) $row['rating'] . ' stars',
                    'status_class' => 'at-status--warn',
                ];
            }
        } catch (Throwable $e) {
            // optional
        }

        return $out;
    }

    /**
     * Customer list rows for admin index (newest first).
     *
     * @return list<array{name: string, mobile: string, orders: string, joined: string}>
     */
    public static function customerIndexRows(int $limit = 100): array
    {
        $result = self::searchCustomersForAdmin('', 1, $limit, 'created_at', 'desc');
        $rows = [];
        foreach ($result['rows'] as $row) {
            $rows[] = self::customerRowToTable($row);
        }

        return $rows;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public static function searchCustomersForAdmin(
        string $query,
        int $page,
        int $perPage,
        string $sortColumn = 'created_at',
        string $sortDirection = 'desc'
    ): array {
        return AdminCustomerService::searchForAdmin($query, $page, $perPage, $sortColumn, $sortDirection);
    }

    /** @param array<string, mixed> $row */
    private static function customerRowToTable(array $row): array
    {
        return [
            'name' => (string) (($row['name'] ?? '') !== '' ? $row['name'] : 'Customer'),
            'mobile' => (string) ($row['mobile'] ?? ''),
            'orders' => (string) (int) ($row['order_count'] ?? 0),
            'joined' => date('M Y', strtotime((string) ($row['created_at'] ?? 'now'))),
        ];
    }
}
