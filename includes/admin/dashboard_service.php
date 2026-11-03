<?php

declare(strict_types=1);

/** Live admin dashboard aggregates (Asia/Kolkata day boundaries). */
final class AdminDashboardService
{
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
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM subscription_skips WHERE skip_date = ?');
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
        if (!Install::isSchemaReady()) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 1];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $where = ['1=1'];
        $params = [];
        $q = trim($query);
        if ($q !== '') {
            $like = '%' . AdminServerList::escapeLike($q) . '%';
            $where[] = '(c.name LIKE ? OR c.mobile LIKE ? OR c.email LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $whereSql = implode(' AND ', $where);
        $sortMap = [
            'created_at' => 'c.created_at',
            'name' => 'c.name',
            'mobile' => 'c.mobile',
            'id' => 'c.id',
        ];
        $orderSql = AdminServerList::orderBySql($sortColumn, $sortDirection, $sortMap, 'created_at');
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM customers c WHERE ' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $sql = 'SELECT c.id, c.name, c.mobile, c.email, c.created_at,
            (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) AS order_count
            FROM customers c WHERE ' . $whereSql . ' ORDER BY ' . $orderSql . ' LIMIT ? OFFSET ?';
        $stmt = $pdo->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p);
        }
        $stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($i, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
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
