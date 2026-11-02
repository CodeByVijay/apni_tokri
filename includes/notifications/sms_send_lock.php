<?php

declare(strict_types=1);

/**
 * Atomic claim for one in-flight SMS per (delivery_id, event_key). Short DB transactions only.
 */
final class DeliverySmsSendLockService
{
    public const STATUS_IDLE = 'idle';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';

    /** Default lease while HTTP provider call runs (released after send completes). */
    public const LEASE_SECONDS = 180;

    public static function tableReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $n = (int) Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_sms_send_locks'"
            )->fetchColumn();

            return $n > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return array{token: string, log_id: int, attempt: int}|null null when send should be skipped
     */
    public static function claim(
        PDO $pdo,
        int $deliveryId,
        string $eventKey,
        bool $forceRetry,
        callable $insertLogRow
    ): ?array {
        if (!self::tableReady()) {
            return null;
        }

        return Database::transaction(static function () use ($pdo, $deliveryId, $eventKey, $forceRetry, $insertLogRow): ?array {
            $pdo->prepare(
                'INSERT IGNORE INTO delivery_sms_send_locks (delivery_id, event_key, lock_status, lease_token)
                 VALUES (?, ?, ?, ?)'
            )->execute([$deliveryId, $eventKey, self::STATUS_IDLE, '']);

            $stmt = $pdo->prepare(
                'SELECT * FROM delivery_sms_send_locks WHERE delivery_id = ? AND event_key = ? LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$deliveryId, $eventKey]);
            $lock = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lock) {
                return null;
            }

            $successLogId = isset($lock['success_log_id']) ? (int) $lock['success_log_id'] : 0;
            if ($successLogId > 0 && !$forceRetry) {
                return null;
            }
            if ($forceRetry && $successLogId > 0) {
                return null;
            }

            $lockStatus = (string) ($lock['lock_status'] ?? self::STATUS_IDLE);
            $leaseExpires = (string) ($lock['lease_expires_at'] ?? '');
            $leaseActive = $lockStatus === self::STATUS_SENDING
                && $leaseExpires !== ''
                && $leaseExpires !== '0000-00-00 00:00:00'
                && strtotime($leaseExpires) > time();

            if ($leaseActive) {
                return null;
            }

            if ($lockStatus === self::STATUS_SENT && $successLogId > 0 && !$forceRetry) {
                return null;
            }

            $attemptStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(attempt_number), 0) + 1 FROM delivery_sms_logs WHERE delivery_id = ? AND event_key = ?'
            );
            $attemptStmt->execute([$deliveryId, $eventKey]);
            $attempt = max(1, (int) $attemptStmt->fetchColumn());

            $logId = (int) $insertLogRow($pdo, $attempt);
            if ($logId <= 0) {
                return null;
            }

            $token = bin2hex(random_bytes(16));
            $pdo->prepare(
                'UPDATE delivery_sms_send_locks SET lock_status = ?, lease_token = ?, lease_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                 active_log_id = ?, updated_at = NOW() WHERE delivery_id = ? AND event_key = ?'
            )->execute([
                self::STATUS_SENDING,
                $token,
                self::LEASE_SECONDS,
                $logId,
                $deliveryId,
                $eventKey,
            ]);

            return ['token' => $token, 'log_id' => $logId, 'attempt' => $attempt];
        });
    }

    public static function completeSuccess(int $deliveryId, string $eventKey, string $token, int $logId): bool
    {
        if (!self::tableReady()) {
            return false;
        }

        return Database::transaction(static function () use ($deliveryId, $eventKey, $token, $logId): bool {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'SELECT * FROM delivery_sms_send_locks WHERE delivery_id = ? AND event_key = ? LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$deliveryId, $eventKey]);
            $lock = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lock || (string) ($lock['lease_token'] ?? '') !== $token) {
                return false;
            }
            $pdo->prepare(
                'UPDATE delivery_sms_send_locks SET lock_status = ?, success_log_id = ?, active_log_id = NULL,
                 lease_token = ?, lease_expires_at = NULL, updated_at = NOW() WHERE delivery_id = ? AND event_key = ?'
            )->execute([self::STATUS_SENT, $logId, '', $deliveryId, $eventKey]);

            return true;
        });
    }

    public static function releaseFailure(int $deliveryId, string $eventKey, string $token): void
    {
        if (!self::tableReady()) {
            return;
        }
        try {
            Database::transaction(static function () use ($deliveryId, $eventKey, $token): void {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare(
                    'SELECT lease_token FROM delivery_sms_send_locks WHERE delivery_id = ? AND event_key = ? LIMIT 1 FOR UPDATE'
                );
                $stmt->execute([$deliveryId, $eventKey]);
                $held = (string) ($stmt->fetchColumn() ?: '');
                if ($held !== $token) {
                    return;
                }
                $pdo->prepare(
                    'UPDATE delivery_sms_send_locks SET lock_status = ?, active_log_id = NULL, lease_token = ?,
                     lease_expires_at = NULL, updated_at = NOW() WHERE delivery_id = ? AND event_key = ?'
                )->execute([self::STATUS_IDLE, '', $deliveryId, $eventKey]);
            });
        } catch (Throwable $e) {
            error_log('DeliverySmsSendLockService::releaseFailure: ' . $e->getMessage());
        }
    }

    /** @return array{recovered: bool, lock_status: string|null} */
    public static function recoverStaleSending(int $deliveryId, string $eventKey, int $maxAgeSeconds = 0): array
    {
        if (!self::tableReady()) {
            return ['recovered' => false, 'lock_status' => null];
        }
        $maxAgeSeconds = $maxAgeSeconds > 0 ? $maxAgeSeconds : self::LEASE_SECONDS;

        return Database::transaction(static function () use ($deliveryId, $eventKey, $maxAgeSeconds): array {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'SELECT * FROM delivery_sms_send_locks WHERE delivery_id = ? AND event_key = ? LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$deliveryId, $eventKey]);
            $lock = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lock || (string) ($lock['lock_status'] ?? '') !== self::STATUS_SENDING) {
                return ['recovered' => false, 'lock_status' => $lock['lock_status'] ?? null];
            }
            $expires = (string) ($lock['lease_expires_at'] ?? '');
            if ($expires === '' || strtotime($expires) > time()) {
                return ['recovered' => false, 'lock_status' => self::STATUS_SENDING];
            }
            $pdo->prepare(
                'UPDATE delivery_sms_send_locks SET lock_status = ?, active_log_id = NULL, lease_token = ?,
                 lease_expires_at = NULL, updated_at = NOW() WHERE delivery_id = ? AND event_key = ?'
            )->execute([self::STATUS_IDLE, '', $deliveryId, $eventKey]);

            return ['recovered' => true, 'lock_status' => self::STATUS_IDLE];
        });
    }
}
