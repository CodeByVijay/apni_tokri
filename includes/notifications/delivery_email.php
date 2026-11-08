<?php

declare(strict_types=1);

/**
 * Customer order confirmation emails linked to deliveries (admin log + retry).
 * Transport uses NotificationQueueService; this class only records outcomes.
 */
final class DeliveryOrderEmailService
{
    public const EVENT_ORDER_CONFIRMED = 'order_confirmed';

    public static function tableReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $n = (int) Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_email_logs'"
            )->fetchColumn();

            return $n > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function maskEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '' || !str_contains($email, '@')) {
            return '***';
        }
        [$local, $domain] = explode('@', $email, 2);
        if (strlen($local) <= 2) {
            return $local . '@' . $domain;
        }

        return substr($local, 0, 2) . '•••@' . $domain;
    }

    /**
     * Record confirmation email attempt after enqueue/process (never throws).
     *
     * @param array{ok?: bool, created?: bool, duplicate?: bool, processed?: bool, error?: string, permanent?: bool} $sendResult
     */
    public static function recordOrderConfirmationAttempt(
        int $orderId,
        string $recipientEmail,
        string $idempotencyKey,
        array $sendResult,
        bool $skipped = false,
        ?string $skipReason = null
    ): void {
        if (!self::tableReady() || $orderId <= 0) {
            return;
        }
        try {
            Install::ensureConnection();
            $pdo = Database::pdo();
            if (!str_contains($idempotencyKey, ':retry:')) {
                $dupLog = $pdo->prepare(
                    'SELECT id FROM delivery_email_logs WHERE order_id = ? AND event_key = ? LIMIT 1'
                );
                $dupLog->execute([$orderId, self::EVENT_ORDER_CONFIRMED]);
                $hasLog = (bool) $dupLog->fetchColumn();
                if ($hasLog && ($skipped || !empty($sendResult['duplicate']))) {
                    return;
                }
            }
            $deliveryId = self::deliveryIdForOrder($orderId);
            $queueRow = self::queueRowForKey($idempotencyKey);
            $queueId = $queueRow !== null ? (int) ($queueRow['id'] ?? 0) : null;
            $status = 'pending';
            $error = null;
            $sentAt = null;
            if ($skipped) {
                $status = 'skipped';
                $error = $skipReason !== null ? mb_substr($skipReason, 0, 255) : 'Skipped';
            } elseif ($queueRow !== null) {
                $qs = (string) ($queueRow['status'] ?? '');
                if ($qs === NotificationQueueService::STATUS_SENT) {
                    $status = 'sent';
                    $sentAt = (string) ($queueRow['sent_at'] ?? $queueRow['processed_at'] ?? '');
                } elseif ($qs === NotificationQueueService::STATUS_FAILED) {
                    $status = 'failed';
                    $error = NotificationQueueService::publicErrorMessage((string) ($queueRow['error_code'] ?? ''));
                    if ($error === '' && !empty($queueRow['error_message'])) {
                        $error = mb_substr((string) $queueRow['error_message'], 0, 255);
                    }
                } elseif ($qs === NotificationQueueService::STATUS_PENDING) {
                    $status = 'pending';
                } elseif ($qs === NotificationQueueService::STATUS_PROCESSING) {
                    $status = 'pending';
                }
            } elseif (empty($sendResult['ok'])) {
                $status = 'failed';
                $error = mb_substr((string) ($sendResult['error'] ?? 'send_failed'), 0, 255);
            } elseif (!empty($sendResult['ok'])) {
                $status = 'sent';
                $sentAt = date('Y-m-d H:i:s');
            }

            if (!$skipped && $status === 'sent') {
                $exists = $pdo->prepare(
                    "SELECT id FROM delivery_email_logs WHERE order_id = ? AND event_key = ? AND status = 'sent' LIMIT 1"
                );
                $exists->execute([$orderId, self::EVENT_ORDER_CONFIRMED]);
                if ($exists->fetchColumn()) {
                    return;
                }
            }

            $attempt = 1;
            if ($status === 'failed' || str_contains($idempotencyKey, ':retry:')) {
                $st = $pdo->prepare('SELECT COALESCE(MAX(attempt_number), 0) + 1 FROM delivery_email_logs WHERE order_id = ? AND event_key = ?');
                $st->execute([$orderId, self::EVENT_ORDER_CONFIRMED]);
                $attempt = max(1, (int) $st->fetchColumn());
            }

            $pdo->prepare(
                'INSERT INTO delivery_email_logs
                 (delivery_id, order_id, event_key, template_key, recipient_email, email_masked, status, queue_id, error_message, attempt_number, sent_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $deliveryId > 0 ? $deliveryId : null,
                $orderId,
                self::EVENT_ORDER_CONFIRMED,
                'payment.success.email',
                $recipientEmail,
                self::maskEmail($recipientEmail),
                $status,
                $queueId > 0 ? $queueId : null,
                $error,
                $attempt,
                $sentAt !== null && $sentAt !== '' ? $sentAt : null,
            ]);
        } catch (Throwable $e) {
            error_log('DeliveryOrderEmailService::recordOrderConfirmationAttempt');
        }
    }

    /** @return list<array<string, mixed>> */
    public static function logsForAdmin(int $deliveryId): array
    {
        if (!self::tableReady() || $deliveryId <= 0) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT id, delivery_id, order_id, event_key, template_key, email_masked, status, error_message,
                    attempt_number, created_at, sent_at
             FROM delivery_email_logs WHERE delivery_id = ? ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([$deliveryId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            unset($row['recipient_email']);
            $out[] = $row;
        }

        return $out;
    }

    public static function retryLog(int $logId): bool
    {
        if (!self::tableReady() || $logId <= 0) {
            return false;
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM delivery_email_logs WHERE id = ? LIMIT 1');
        $stmt->execute([$logId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (string) ($row['status'] ?? '') !== 'failed') {
            return false;
        }
        $sent = $pdo->prepare(
            "SELECT id FROM delivery_email_logs WHERE order_id = ? AND event_key = ? AND status = 'sent' LIMIT 1"
        );
        $sent->execute([(int) $row['order_id'], (string) $row['event_key']]);
        if ($sent->fetchColumn()) {
            return false;
        }

        return NotificationDispatcher::resendOrderConfirmationEmail((int) $row['order_id'], $logId);
    }

    private static function deliveryIdForOrder(int $orderId): int
    {
        $stmt = Database::pdo()->prepare('SELECT id FROM deliveries WHERE order_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$orderId]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /** @return array<string, mixed>|null */
    private static function queueRowForKey(string $idempotencyKey): ?array
    {
        if (!NotificationQueueService::tableReady() || $idempotencyKey === '') {
            return null;
        }
        $stmt = Database::pdo()->prepare(
            'SELECT id, status, sent_at, processed_at, error_code, error_message FROM notification_queue
             WHERE channel = ? AND idempotency_key = ? LIMIT 1'
        );
        $stmt->execute(['email', $idempotencyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
