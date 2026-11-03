<?php

declare(strict_types=1);

/** Paginated admin lists for subscription operations. */
final class SubscriptionAdminListService
{
    /**
     * Customer daily baskets in a date window.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public static function searchCustomerBaskets(
        string $fromYmd,
        string $toYmd,
        string $query,
        int $page,
        int $perPage,
        string $sortColumn = 'delivery_date',
        string $sortDirection = 'asc'
    ): array {
        if (!Install::isSchemaReady()) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 1];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $where = ['d.delivery_date BETWEEN ? AND ?'];
        $params = [$fromYmd, $toYmd];
        $q = trim($query);
        if ($q !== '') {
            $like = '%' . AdminServerList::escapeLike($q) . '%';
            $where[] = '(cs.subscription_number LIKE ? OR c.mobile LIKE ? OR CAST(d.basket_status AS CHAR) LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);
        $sortMap = [
            'delivery_date' => 'd.delivery_date',
            'subscription_number' => 'cs.subscription_number',
            'total_inr' => 'b.total_inr',
        ];
        $orderSql = AdminServerList::orderBySql($sortColumn, $sortDirection, $sortMap, 'delivery_date');
        $from = ' FROM subscription_delivery_days d
             INNER JOIN customer_subscriptions cs ON cs.id = d.customer_subscription_id
             INNER JOIN customers c ON c.id = cs.customer_id
             LEFT JOIN subscription_delivery_baskets b ON b.subscription_delivery_day_id = d.id
             WHERE ' . $whereSql;
        $countStmt = $pdo->prepare('SELECT COUNT(*)' . $from);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $sql = 'SELECT cs.subscription_number, c.mobile, d.delivery_date, d.basket_status, b.status AS basket_row_status,
                b.total_inr, b.allowance_inr, b.source_type' . $from . ' ORDER BY ' . $orderSql . ' LIMIT ? OFFSET ?';
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
}
