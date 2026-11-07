<?php

declare(strict_types=1);

final class AdminCustomerService
{
    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public static function searchForAdmin(
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
            'email' => 'c.email',
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
        $sql = 'SELECT c.id, c.name, c.mobile, c.email, c.is_active, c.created_at,
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

    /** @return array<string, mixed>|null */
    public static function findById(int $customerId): ?array
    {
        if ($customerId <= 0 || !Install::isSchemaReady()) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT id, mobile, name, email, is_active, created_at, updated_at FROM customers WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public static function ordersForCustomer(int $customerId, int $limit = 50): array
    {
        if ($customerId <= 0) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT id, order_number, placed_at, created_at, grand_total, payment_status, status
             FROM orders WHERE customer_id = ? ORDER BY COALESCE(placed_at, created_at) DESC, id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $customerId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function invoicesForCustomer(int $customerId, int $limit = 50): array
    {
        if ($customerId <= 0) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            "SELECT i.id, i.invoice_number, i.invoice_date, i.grand_total, i.document_type
             FROM invoices i
             WHERE i.customer_id = ? AND i.document_type = 'retail_invoice'
             ORDER BY i.invoice_date DESC, i.id DESC LIMIT ?"
        );
        $stmt->bindValue(1, $customerId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function paymentsForCustomer(int $customerId, int $limit = 50): array
    {
        if ($customerId <= 0) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT p.id, p.gateway, p.status, p.amount, p.created_at, p.updated_at, o.order_number, o.id AS order_id
             FROM payments p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE o.customer_id = ?
             ORDER BY p.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $customerId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function refundsForCustomer(int $customerId, int $limit = 50): array
    {
        if ($customerId <= 0) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT pr.id, pr.amount_inr, pr.gateway_status, pr.completed_at, pr.gateway, pr.created_at,
                    o.order_number, o.id AS order_id
             FROM payment_refunds pr
             INNER JOIN payments p ON p.id = pr.payment_id
             INNER JOIN orders o ON o.id = p.order_id
             WHERE o.customer_id = ?
             ORDER BY pr.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $customerId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function subscriptionsForCustomer(int $customerId, int $limit = 20): array
    {
        if ($customerId <= 0) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT id, subscription_number, status, start_date, end_date, price_paid, deliveries_remaining, created_at
             FROM customer_subscriptions
             WHERE customer_id = ?
             ORDER BY id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $customerId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(50, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array{active_subscriptions: int, pending_orders: int}
     */
    public static function deactivationWarnings(int $customerId): array
    {
        if ($customerId <= 0) {
            return ['active_subscriptions' => 0, 'pending_orders' => 0];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $sub = $pdo->prepare(
            "SELECT COUNT(*) FROM customer_subscriptions
             WHERE customer_id = ? AND status IN ('active', 'paused', 'pending_payment')"
        );
        $sub->execute([$customerId]);
        $ord = $pdo->prepare(
            "SELECT COUNT(*) FROM orders
             WHERE customer_id = ? AND status = 'pending_payment' AND payment_status IN ('pending', 'failed')"
        );
        $ord->execute([$customerId]);

        return [
            'active_subscriptions' => (int) $sub->fetchColumn(),
            'pending_orders' => (int) $ord->fetchColumn(),
        ];
    }

    /**
     * @return array{ok: bool, message?: string, errors?: array<string, string>}
     */
    public static function updateProfile(int $customerId, string $name, string $email, int $adminUserId): array
    {
        if ($customerId <= 0) {
            return ['ok' => false, 'message' => 'Customer not found.'];
        }
        $existing = self::findById($customerId);
        if ($existing === null) {
            return ['ok' => false, 'message' => 'Customer not found.'];
        }

        $errors = [];
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $email = trim($email);
        $len = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($name === '') {
            $errors['name'] = 'Enter customer name.';
        } elseif ($len < 2 || $len > 100) {
            $errors['name'] = 'Name must be 2 to 100 characters.';
        } elseif (preg_match('/[\x00-\x1F<>]/', $name)) {
            $errors['name'] = 'Name contains invalid characters.';
        }
        if ($email !== '') {
            if (strlen($email) > 191 || !Validation::email($email)) {
                $errors['email'] = 'Enter a valid email address.';
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Fix the highlighted fields.', 'errors' => $errors];
        }

        Install::ensureConnection();
        $pdo = Database::pdo();
        if ($email !== '') {
            $dup = $pdo->prepare('SELECT id FROM customers WHERE email = ? AND id <> ? LIMIT 1');
            $dup->execute([$email, $customerId]);
            if ($dup->fetchColumn()) {
                return ['ok' => false, 'message' => 'This email is already used by another account.', 'errors' => ['email' => 'Duplicate email.']];
            }
        }

        $oldName = trim((string) ($existing['name'] ?? ''));
        $oldEmail = trim((string) ($existing['email'] ?? ''));
        $pdo->prepare('UPDATE customers SET name = ?, email = ?, updated_at = NOW() WHERE id = ?')
            ->execute([
                $name,
                $email !== '' ? $email : null,
                $customerId,
            ]);

        AuditLog::write(
            'customer.profile_update',
            'customers',
            (string) $customerId,
            [
                'old' => ['name' => $oldName, 'email' => $oldEmail],
                'new' => ['name' => $name, 'email' => $email],
            ],
            $adminUserId > 0 ? $adminUserId : null
        );

        return ['ok' => true, 'message' => 'Customer updated.'];
    }

    /**
     * @return array{ok: bool, message?: string, warnings?: array<string, int>}
     */
    public static function setActive(int $customerId, bool $active, int $adminUserId): array
    {
        if ($customerId <= 0) {
            return ['ok' => false, 'message' => 'Customer not found.'];
        }
        $row = self::findById($customerId);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Customer not found.'];
        }
        $wasActive = !empty($row['is_active']);
        if ($wasActive === $active) {
            return ['ok' => false, 'message' => $active ? 'Customer is already active.' : 'Customer is already inactive.'];
        }

        $warnings = [];
        if (!$active) {
            $warnings = self::deactivationWarnings($customerId);
        }

        Install::ensureConnection();
        Database::pdo()->prepare('UPDATE customers SET is_active = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$active ? 1 : 0, $customerId]);

        if (!$active && CustomerAuth::id() === $customerId) {
            CustomerAuth::logout();
        }

        AuditLog::write(
            $active ? 'customer.activate' : 'customer.deactivate',
            'customers',
            (string) $customerId,
            [
                'old' => ['is_active' => $wasActive ? 1 : 0],
                'new' => ['is_active' => $active ? 1 : 0],
                'warnings' => $warnings,
            ],
            $adminUserId > 0 ? $adminUserId : null
        );

        $msg = $active ? 'Customer account reactivated.' : 'Customer account deactivated.';
        if (!$active && ($warnings['active_subscriptions'] > 0 || $warnings['pending_orders'] > 0)) {
            $msg .= ' Existing subscriptions and pending orders were not changed.';
        }

        return ['ok' => true, 'message' => $msg, 'warnings' => $warnings];
    }
}
