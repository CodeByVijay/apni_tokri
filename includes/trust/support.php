<?php

declare(strict_types=1);

final class SupportTicketService
{
    /** @return array<string, string> */
    public static function categoryLabels(): array
    {
        return [
            'quality' => 'Product quality',
            'late_delivery' => 'Late delivery',
            'payment' => 'Payment issue',
            'subscription' => 'Subscription',
            'refund' => 'Refund',
            'other' => 'Other',
        ];
    }

    public static function nextTicketNumber(): string
    {
        Install::ensureConnection();
        $n = (int) Database::pdo()->query('SELECT COUNT(*) FROM support_tickets')->fetchColumn();

        return 'TK-' . date('ymd') . '-' . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{ok: bool, message?: string, ticket_number?: string}
     */
    public static function create(
        ?int $customerId,
        string $category,
        string $subject,
        string $message,
        ?string $orderNumber = null
    ): array {
        $cats = self::categoryLabels();
        if (!isset($cats[$category])) {
            return ['ok' => false, 'message' => 'Choose a valid category.'];
        }
        $subject = trim($subject);
        $message = trim($message);
        if ($message === '') {
            return ['ok' => false, 'message' => 'Please describe the issue.'];
        }
        if ($subject === '') {
            $subject = $cats[$category];
        }

        try {
            $result = Database::transaction(static function () use (
                $customerId,
                $category,
                $subject,
                $message,
                $orderNumber
            ): array {
                $pdo = Database::pdo();
                $orderId = null;
                if ($orderNumber !== null && $orderNumber !== '' && $customerId !== null) {
                    $o = OrderFulfillmentService::orderRowForCustomer($orderNumber, $customerId);
                    if ($o === null) {
                        throw new RuntimeException('invalid_order');
                    }
                    $orderId = (int) $o['id'];
                }
                $ticketNumber = self::nextTicketNumber();
                $pdo->prepare(
                    'INSERT INTO support_tickets (ticket_number, customer_id, order_id, category, status, subject)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([
                    $ticketNumber,
                    $customerId,
                    $orderId,
                    $category,
                    TicketStatus::OPEN,
                    $subject,
                ]);
                $ticketId = (int) $pdo->lastInsertId();
                $pdo->prepare(
                    'INSERT INTO support_messages (ticket_id, sender_type, message) VALUES (?, ?, ?)'
                )->execute([$ticketId, 'customer', $message]);

                return ['ok' => true, 'ticket_number' => $ticketNumber, 'ticket_id' => $ticketId];
            });
            if (!empty($result['ok']) && !empty($result['ticket_id'])) {
                NotificationDispatcher::supportTicketCreated((int) $result['ticket_id']);
                AdminNotificationService::notifySupportTicketCreated((int) $result['ticket_id']);
            }
            unset($result['ticket_id']);

            return $result;
        } catch (Throwable $e) {
            if ($e->getMessage() === 'invalid_order') {
                return ['ok' => false, 'message' => 'That order is not linked to your account.'];
            }
            error_log('Support ticket: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not create ticket.'];
        }
    }

    /**
     * Admin ticket detail: ticket, customer, linked order, line items, conversation (single round-trip queries).
     *
     * @return array<string, mixed>|null
     */
    public static function adminDetailFor(int $ticketId): ?array
    {
        if ($ticketId <= 0) {
            return null;
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT t.*,
                    c.name AS customer_name, c.mobile AS customer_mobile, c.email AS customer_email,
                    o.order_number, o.status AS order_status, o.payment_status, o.grand_total,
                    o.placed_at AS order_placed_at, o.created_at AS order_created_at,
                    o.delivery_slot, o.delivery_date,
                    lp.gateway AS payment_gateway, lp.status AS payment_status_row
             FROM support_tickets t
             LEFT JOIN customers c ON c.id = t.customer_id
             LEFT JOIN orders o ON o.id = t.order_id
             LEFT JOIN (
                 SELECT p1.order_id, p1.gateway, p1.status
                 FROM payments p1
                 INNER JOIN (
                     SELECT order_id, MAX(id) AS max_id FROM payments GROUP BY order_id
                 ) latest ON latest.max_id = p1.id
             ) lp ON lp.order_id = o.id
             WHERE t.id = ? LIMIT 1'
        );
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) {
            return null;
        }

        $messages = self::messages($ticketId);
        $orderItems = [];
        $orderInternalId = (int) ($ticket['order_id'] ?? 0);
        if ($orderInternalId > 0) {
            $itemsStmt = $pdo->prepare(
                'SELECT oi.product_name, oi.variant_name, oi.quantity, oi.unit_price, oi.line_total, oi.variant_id,
                        (SELECT pi.file_path FROM product_images pi
                         INNER JOIN product_variants pv ON pv.product_id = pi.product_id
                         WHERE pv.id = oi.variant_id
                         ORDER BY pi.is_primary DESC, pi.sort_order ASC LIMIT 1) AS image_path
                 FROM order_items oi
                 WHERE oi.order_id = ?
                 ORDER BY oi.id ASC'
            );
            $itemsStmt->execute([$orderInternalId]);
            foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $img = (string) ($row['image_path'] ?? '');
                $orderItems[] = [
                    'name' => (string) $row['product_name'],
                    'variant' => (string) $row['variant_name'],
                    'quantity' => (int) $row['quantity'],
                    'unit_price' => (float) $row['unit_price'],
                    'line_total' => (float) $row['line_total'],
                    'image' => $img !== '' ? asset_url($img) : '',
                ];
            }
        }

        return [
            'ticket' => $ticket,
            'messages' => $messages,
            'order_items' => $orderItems,
        ];
    }

    public static function categoryLabel(string $code): string
    {
        return self::categoryLabels()[$code] ?? $code;
    }

    /** @return list<array<string, mixed>> */
    public static function listForAdmin(?string $status = null, int $limit = 100): array
    {
        Install::ensureConnection();
        $sql = 'SELECT t.*, c.mobile AS customer_mobile, o.order_number
                FROM support_tickets t
                LEFT JOIN customers c ON c.id = t.customer_id
                LEFT JOIN orders o ON o.id = t.order_id';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' WHERE t.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY t.created_at DESC LIMIT ?';
        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $i => $p) {
            $stmt->bindValue($i + 1, $p);
        }
        $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    public static function byNumber(string $ticketNumber): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM support_tickets WHERE ticket_number = ? LIMIT 1');
        $stmt->execute([$ticketNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public static function messages(int $ticketId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM support_messages WHERE ticket_id = ? ORDER BY created_at'
        );
        $stmt->execute([$ticketId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array{ok: bool, message?: string} */
    public static function adminReply(int $ticketId, string $message, ?string $newStatus = null): array
    {
        $message = trim($message);
        if ($message === '') {
            return ['ok' => false, 'message' => 'Message cannot be empty.'];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id FROM support_tickets WHERE id = ? LIMIT 1');
        $stmt->execute([$ticketId]);
        if (!$stmt->fetchColumn()) {
            return ['ok' => false, 'message' => 'Ticket not found.'];
        }
        $pdo->prepare(
            'INSERT INTO support_messages (ticket_id, sender_type, message) VALUES (?, ?, ?)'
        )->execute([$ticketId, 'admin', $message]);
        if ($newStatus !== null && in_array($newStatus, [
            TicketStatus::ASSIGNED,
            TicketStatus::IN_PROGRESS,
            TicketStatus::RESOLVED,
            TicketStatus::CLOSED,
        ], true)) {
            $closed = in_array($newStatus, [TicketStatus::RESOLVED, TicketStatus::CLOSED], true);
            $pdo->prepare(
                'UPDATE support_tickets SET status = ?, assigned_admin_id = COALESCE(assigned_admin_id, ?),
                 closed_at = IF(?, NOW(), closed_at), updated_at = NOW() WHERE id = ?'
            )->execute([$newStatus, AdminAuth::id(), $closed ? 1 : 0, $ticketId]);
        } else {
            $pdo->prepare(
                'UPDATE support_tickets SET status = IF(status = ?, ?, status), assigned_admin_id = COALESCE(assigned_admin_id, ?), updated_at = NOW() WHERE id = ?'
            )->execute([TicketStatus::OPEN, TicketStatus::IN_PROGRESS, AdminAuth::id(), $ticketId]);
        }
        AuditLog::write('support.reply', 'support_ticket', (string) $ticketId);
        NotificationDispatcher::supportTicketReplied($ticketId);

        return ['ok' => true];
    }

    /** @return array<string, mixed>|null */
    public static function forCustomer(int $ticketId, int $customerId): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM support_tickets WHERE id = ? AND customer_id = ? LIMIT 1'
        );
        $stmt->execute([$ticketId, $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function byNumberForCustomer(string $ticketNumber, int $customerId): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM support_tickets WHERE ticket_number = ? AND customer_id = ? LIMIT 1'
        );
        $stmt->execute([$ticketNumber, $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listForCustomer(int $customerId, ?string $status = null, ?string $category = null, int $limit = 50): array
    {
        Install::ensureConnection();
        $sql = 'SELECT t.*, o.order_number FROM support_tickets t
                LEFT JOIN orders o ON o.id = t.order_id
                WHERE t.customer_id = ?';
        $params = [$customerId];
        if ($status !== null && $status !== '') {
            $sql .= ' AND t.status = ?';
            $params[] = $status;
        }
        if ($category !== null && $category !== '' && isset(self::categoryLabels()[$category])) {
            $sql .= ' AND t.category = ?';
            $params[] = $category;
        }
        $sql .= ' ORDER BY COALESCE(t.updated_at, t.created_at) DESC LIMIT ?';
        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $i => $p) {
            $stmt->bindValue($i + 1, $p);
        }
        $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string, mixed>|null $attachmentFile single file from $_FILES['attachment']
     * @return array{ok: bool, message?: string}
     */
    public static function customerReply(
        int $ticketId,
        int $customerId,
        string $message,
        ?array $attachmentFile = null
    ): array {
        $message = trim($message);
        if ($message === '') {
            return ['ok' => false, 'message' => 'Message cannot be empty.'];
        }
        $ticket = self::forCustomer($ticketId, $customerId);
        if ($ticket === null) {
            return ['ok' => false, 'message' => 'Ticket not found.'];
        }
        if (in_array((string) ($ticket['status'] ?? ''), [TicketStatus::CLOSED], true)) {
            return ['ok' => false, 'message' => 'This ticket is closed. Open a new ticket if you need more help.'];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO support_messages (ticket_id, sender_type, message) VALUES (?, ?, ?)'
        )->execute([$ticketId, 'customer', $message]);
        $messageId = (int) $pdo->lastInsertId();
        AdminNotificationService::notifySupportCustomerReply($ticketId, $messageId);
        if (is_array($attachmentFile) && (int) ($attachmentFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $stored = Upload::storeSupportAttachment($attachmentFile);
            if (!$stored['ok']) {
                return ['ok' => false, 'message' => $stored['message'] ?? 'Invalid attachment.'];
            }
            $pdo->prepare(
                'INSERT INTO support_message_attachments (message_id, file_path, mime_type) VALUES (?, ?, ?)'
            )->execute([$messageId, (string) $stored['path'], (string) ($stored['mime'] ?? '')]);
        }
        $pdo->prepare(
            'UPDATE support_tickets SET status = IF(status = ?, ?, status), updated_at = NOW() WHERE id = ?'
        )->execute([TicketStatus::RESOLVED, TicketStatus::OPEN, $ticketId]);

        return ['ok' => true];
    }

    /** @return list<array<string, mixed>> */
    public static function attachmentsForMessage(int $messageId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT id, file_path, mime_type FROM support_message_attachments WHERE message_id = ?'
        );
        $stmt->execute([$messageId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Customer may download only attachments on their tickets. */
    public static function attachmentForCustomer(int $attachmentId, int $customerId): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT a.* FROM support_message_attachments a
             INNER JOIN support_messages m ON m.id = a.message_id
             INNER JOIN support_tickets t ON t.id = m.ticket_id
             WHERE a.id = ? AND t.customer_id = ? LIMIT 1'
        );
        $stmt->execute([$attachmentId, $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
