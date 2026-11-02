<?php

declare(strict_types=1);

final class AdminNotificationService
{
    public const EVENT_SUPPORT_TICKET_CREATED = 'admin.support.ticket_created';
    public const EVENT_SUPPORT_CUSTOMER_REPLY = 'admin.support.ticket_customer_reply';

    public static function tableReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $n = (int) Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_notifications'"
            )->fetchColumn();

            return $n > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function viewerCanAccess(): bool
    {
        return AdminAuth::check() && AdminDemoPerms::can('support');
    }

    public static function notifySupportTicketCreated(int $ticketId): void
    {
        if (!self::tableReady() || $ticketId <= 0) {
            return;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT t.id, t.ticket_number, t.subject, t.category, c.name AS customer_name
                 FROM support_tickets t
                 LEFT JOIN customers c ON c.id = t.customer_id
                 WHERE t.id = ? LIMIT 1'
            );
            $stmt->execute([$ticketId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return;
            }
            $ticketNumber = (string) $row['ticket_number'];
            $category = SupportTicketService::categoryLabel((string) ($row['category'] ?? ''));
            $subject = trim((string) ($row['subject'] ?? ''));
            $customerName = trim((string) ($row['customer_name'] ?? ''));
            if ($customerName === '') {
                $customerName = 'Customer';
            }
            $title = 'New support ticket ' . $ticketNumber;
            $body = $category . ($subject !== '' ? ' — ' . $subject : '') . ' · ' . $customerName;
            self::insert(
                self::EVENT_SUPPORT_TICKET_CREATED,
                $title,
                $body,
                [
                    'ticket_id' => $ticketId,
                    'ticket_number' => $ticketNumber,
                    'category' => (string) ($row['category'] ?? ''),
                    'subject' => $subject,
                    'customer_name' => $customerName,
                ],
                'admin:support.ticket_created:' . $ticketId
            );
        } catch (Throwable $e) {
            error_log('AdminNotificationService::notifySupportTicketCreated');
        }
    }

    public static function notifySupportCustomerReply(int $ticketId, int $messageId): void
    {
        if (!self::tableReady() || $ticketId <= 0 || $messageId <= 0) {
            return;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT t.ticket_number, c.name AS customer_name
                 FROM support_tickets t
                 LEFT JOIN customers c ON c.id = t.customer_id
                 WHERE t.id = ? LIMIT 1'
            );
            $stmt->execute([$ticketId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return;
            }
            $ticketNumber = (string) $row['ticket_number'];
            $customerName = trim((string) ($row['customer_name'] ?? ''));
            if ($customerName === '') {
                $customerName = 'Customer';
            }
            $title = 'Customer replied on ' . $ticketNumber;
            $body = $customerName . ' added a message to the conversation.';
            self::insert(
                self::EVENT_SUPPORT_CUSTOMER_REPLY,
                $title,
                $body,
                [
                    'ticket_id' => $ticketId,
                    'ticket_number' => $ticketNumber,
                    'message_id' => $messageId,
                    'customer_name' => $customerName,
                ],
                'admin:support.ticket_customer_reply:' . $messageId
            );
        } catch (Throwable $e) {
            error_log('AdminNotificationService::notifySupportCustomerReply');
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function insert(
        string $eventType,
        string $title,
        string $body,
        array $context,
        string $idempotencyKey
    ): void {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        $ticketId = isset($context['ticket_id']) ? (int) $context['ticket_id'] : null;
        if ($ticketId !== null && $ticketId <= 0) {
            $ticketId = null;
        }
        try {
            Database::pdo()->prepare(
                'INSERT INTO admin_notifications (event_type, ticket_id, title, body, context_json, idempotency_key)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$eventType, $ticketId, $title, $body, $json, $idempotencyKey]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
        }
    }

    public static function unreadCountForCurrentAdmin(): int
    {
        $adminId = AdminAuth::id();
        if ($adminId === null || !self::viewerCanAccess() || !self::tableReady()) {
            return 0;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM admin_notifications n
             LEFT JOIN admin_notification_reads r
               ON r.notification_id = n.id AND r.admin_user_id = ?
             WHERE r.notification_id IS NULL'
        );
        $stmt->execute([$adminId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recentForCurrentAdmin(int $limit = 8): array
    {
        $adminId = AdminAuth::id();
        if ($adminId === null || !self::viewerCanAccess() || !self::tableReady()) {
            return [];
        }
        $limit = max(1, min(20, $limit));
        $stmt = Database::pdo()->prepare(
            'SELECT n.id, n.event_type, n.title, n.body, n.context_json, n.created_at,
                    r.read_at IS NOT NULL AS is_read
             FROM admin_notifications n
             LEFT JOIN admin_notification_reads r
               ON r.notification_id = n.id AND r.admin_user_id = ?
             ORDER BY n.id DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute([$adminId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ctx = [];
            if (!empty($row['context_json'])) {
                try {
                    $decoded = json_decode((string) $row['context_json'], true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $ctx = $decoded;
                    }
                } catch (Throwable $e) {
                    $ctx = [];
                }
            }
            $ticketId = (int) ($ctx['ticket_id'] ?? 0);
            $eventType = (string) $row['event_type'];
            $out[] = [
                'id' => (int) $row['id'],
                'event_type' => $eventType,
                'event_kind' => self::eventKind($eventType),
                'title' => (string) $row['title'],
                'body' => (string) $row['body'],
                'ticket_number' => (string) ($ctx['ticket_number'] ?? ''),
                'customer_name' => (string) ($ctx['customer_name'] ?? ''),
                'href' => $ticketId > 0
                    ? url_base() . '/admin/support/index.php?id=' . $ticketId
                    : url_base() . '/admin/support/index.php',
                'created_at' => (string) $row['created_at'],
                'relative_time' => self::formatRelativeTime((string) ($row['created_at'] ?? '')),
                'is_read' => !empty($row['is_read']),
            ];
        }

        return $out;
    }

    public static function markRead(int $notificationId, ?int $adminId = null): bool
    {
        if (!self::viewerCanAccess() || !self::tableReady()) {
            return false;
        }
        $adminId = $adminId ?? AdminAuth::id();
        if ($adminId === null || $notificationId <= 0) {
            return false;
        }
        Install::ensureConnection();
        $exists = Database::pdo()->prepare('SELECT id FROM admin_notifications WHERE id = ? LIMIT 1');
        $exists->execute([$notificationId]);
        if (!$exists->fetchColumn()) {
            return false;
        }
        Database::pdo()->prepare(
            'INSERT INTO admin_notification_reads (notification_id, admin_user_id, read_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)'
        )->execute([$notificationId, $adminId]);

        return true;
    }

    public static function markAllReadForCurrentAdmin(): int
    {
        if (!self::viewerCanAccess() || !self::tableReady()) {
            return 0;
        }
        $adminId = AdminAuth::id();
        if ($adminId === null) {
            return 0;
        }
        $stmt = Database::pdo()->prepare(
            'INSERT INTO admin_notification_reads (notification_id, admin_user_id, read_at)
             SELECT n.id, ?, NOW()
             FROM admin_notifications n
             LEFT JOIN admin_notification_reads r
               ON r.notification_id = n.id AND r.admin_user_id = ?
             WHERE r.notification_id IS NULL'
        );
        $stmt->execute([$adminId, $adminId]);

        return $stmt->rowCount();
    }

    public static function markReadForTicket(int $ticketId, ?int $adminId = null): void
    {
        if ($ticketId <= 0 || !self::tableReady() || !self::viewerCanAccess()) {
            return;
        }
        $adminId = $adminId ?? AdminAuth::id();
        if ($adminId === null) {
            return;
        }
        $stmt = Database::pdo()->prepare('SELECT id FROM admin_notifications WHERE ticket_id = ?');
        $stmt->execute([$ticketId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $nid) {
            self::markRead((int) $nid, $adminId);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * @return array{total: int, unread: int, read: int}
     */
    public static function summaryForCurrentAdmin(): array
    {
        $adminId = AdminAuth::id();
        if ($adminId === null || !self::viewerCanAccess() || !self::tableReady()) {
            return ['total' => 0, 'unread' => 0, 'read' => 0];
        }
        $total = (int) Database::pdo()->query('SELECT COUNT(*) FROM admin_notifications')->fetchColumn();
        $unread = self::unreadCountForCurrentAdmin();

        return [
            'total' => $total,
            'unread' => $unread,
            'read' => max(0, $total - $unread),
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function listFilteredForCurrentAdmin(string $filter, int $page = 1, int $perPage = 30): array
    {
        $adminId = AdminAuth::id();
        if ($adminId === null || !self::viewerCanAccess() || !self::tableReady()) {
            return ['rows' => [], 'total' => 0];
        }
        $filter = trim($filter);
        if ($filter === '') {
            $filter = 'all';
        }
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $offset = ($page - 1) * $perPage;

        $join = 'FROM admin_notifications n
             LEFT JOIN admin_notification_reads r
               ON r.notification_id = n.id AND r.admin_user_id = ?';
        $where = '';
        $params = [$adminId];

        if ($filter === 'unread') {
            $where = ' WHERE r.read_at IS NULL';
        } elseif ($filter === 'read') {
            $where = ' WHERE r.read_at IS NOT NULL';
        } elseif ($filter === 'ticket_created') {
            $where = ' WHERE n.event_type = ?';
            $params[] = self::EVENT_SUPPORT_TICKET_CREATED;
        } elseif ($filter === 'customer_reply') {
            $where = ' WHERE n.event_type = ?';
            $params[] = self::EVENT_SUPPORT_CUSTOMER_REPLY;
        }

        $countStmt = Database::pdo()->prepare('SELECT COUNT(*) ' . $join . $where);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $listParams = $params;
        $listStmt = Database::pdo()->prepare(
            'SELECT n.id, n.event_type, n.title, n.body, n.context_json, n.created_at,
                    r.read_at IS NOT NULL AS is_read '
            . $join . $where
            . ' ORDER BY n.id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        $listStmt->execute($listParams);

        return [
            'rows' => self::mapRows($listStmt->fetchAll(PDO::FETCH_ASSOC) ?: []),
            'total' => $total,
        ];
    }

    public static function listForCurrentAdmin(int $page = 1, int $perPage = 30): array
    {
        return self::listFilteredForCurrentAdmin('all', $page, $perPage)['rows'];
    }

    public static function formatRelativeTime(string $datetime): string
    {
        $datetime = trim($datetime);
        if ($datetime === '') {
            return '';
        }
        try {
            $dt = new DateTimeImmutable($datetime);
        } catch (Throwable $e) {
            return $datetime;
        }
        $sec = time() - $dt->getTimestamp();
        if ($sec < 60) {
            return 'Just now';
        }
        if ($sec < 3600) {
            return (int) floor($sec / 60) . 'm ago';
        }
        if ($sec < 86400) {
            return (int) floor($sec / 3600) . 'h ago';
        }

        return (int) floor($sec / 86400) . 'd ago';
    }

    public static function eventKind(string $eventType): string
    {
        if ($eventType === self::EVENT_SUPPORT_TICKET_CREATED) {
            return 'ticket_created';
        }
        if ($eventType === self::EVENT_SUPPORT_CUSTOMER_REPLY) {
            return 'customer_reply';
        }

        return 'generic';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function mapRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $ctx = [];
            if (!empty($row['context_json'])) {
                try {
                    $decoded = json_decode((string) $row['context_json'], true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $ctx = $decoded;
                    }
                } catch (Throwable $e) {
                    $ctx = [];
                }
            }
            $ticketId = (int) ($ctx['ticket_id'] ?? 0);
            $eventType = (string) ($row['event_type'] ?? '');
            $out[] = [
                'id' => (int) $row['id'],
                'event_type' => $eventType,
                'event_kind' => self::eventKind($eventType),
                'title' => (string) $row['title'],
                'body' => (string) $row['body'],
                'ticket_number' => (string) ($ctx['ticket_number'] ?? ''),
                'customer_name' => (string) ($ctx['customer_name'] ?? ''),
                'href' => $ticketId > 0
                    ? url_base() . '/admin/support/index.php?id=' . $ticketId
                    : url_base() . '/admin/support/index.php',
                'created_at' => (string) $row['created_at'],
                'relative_time' => self::formatRelativeTime((string) ($row['created_at'] ?? '')),
                'is_read' => !empty($row['is_read']),
            ];
        }

        return $out;
    }
}
