<?php

declare(strict_types=1);

final class DeliverySmsService
{
    public static function tableReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $n = (int) Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_sms_logs'"
            )->fetchColumn();

            return $n > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function maskMobile(string $mobile): string
    {
        if (strlen($mobile) < 4) {
            return '****';
        }

        return substr($mobile, 0, 2) . '******' . substr($mobile, -2);
    }

    /**
     * Send SMS for a delivery lifecycle event (never throws; does not affect caller transaction).
     */
    public static function sendDeliveryEvent(int $deliveryId, string $eventKey, bool $forceRetry = false): void
    {
        if (!self::tableReady()) {
            return;
        }
        $allowed = ['order_confirmed', 'ready', 'dispatched', 'delivered', 'failed'];
        if (!in_array($eventKey, $allowed, true)) {
            return;
        }

        $claimToken = null;
        $logId = 0;

        try {
            Install::ensureConnection();
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'SELECT d.*, o.order_number, o.customer_id FROM deliveries d
                 INNER JOIN orders o ON o.id = d.order_id WHERE d.id = ? LIMIT 1'
            );
            $stmt->execute([$deliveryId]);
            $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$delivery) {
                return;
            }
            $orderId = (int) $delivery['order_id'];
            $orderNumber = (string) $delivery['order_number'];
            $customerId = (int) ($delivery['customer_id'] ?? 0);
            if ($customerId <= 0) {
                self::logSkipped($deliveryId, $orderId, $eventKey, '', 'No customer on order');

                return;
            }
            $cust = $pdo->prepare('SELECT mobile FROM customers WHERE id = ? LIMIT 1');
            $cust->execute([$customerId]);
            $mobile = Validation::normalizeMobileIndia((string) ($cust->fetchColumn() ?: ''));
            if ($mobile === null) {
                self::logSkipped($deliveryId, $orderId, $eventKey, '', 'Invalid or missing mobile');

                return;
            }

            $templateKey = 'sms.template.' . ($eventKey === 'order_confirmed' ? 'order_confirmed' : $eventKey);
            $template = AppSettings::get($templateKey, '') ?? '';
            if ($template === '') {
                self::logSkipped($deliveryId, $orderId, $eventKey, $mobile, 'Template not configured');

                return;
            }
            $message = str_replace(['{order_number}'], [$orderNumber], $template);
            $dltId = AppSettings::get('sms.dlt.' . $eventKey, '') ?? '';

            $enabled = (AppSettings::get('sms.enabled', '0') ?? '0') === '1';
            $provider = AppSettings::get('sms.provider', 'simulate') ?? 'simulate';
            if (!$enabled && $provider !== 'simulate') {
                self::logSkipped($deliveryId, $orderId, $eventKey, $mobile, 'SMS disabled');

                return;
            }

            if (!DeliverySmsSendLockService::tableReady()) {
                return;
            }

            $claim = DeliverySmsSendLockService::claim(
                $pdo,
                $deliveryId,
                $eventKey,
                $forceRetry,
                static function (PDO $inner, int $attempt) use (
                    $deliveryId,
                    $orderId,
                    $eventKey,
                    $templateKey,
                    $mobile,
                    $provider
                ): int {
                    return self::insertPendingRow(
                        $inner,
                        $deliveryId,
                        $orderId,
                        $eventKey,
                        $templateKey,
                        $mobile,
                        $provider,
                        $attempt
                    );
                }
            );

            if ($claim === null) {
                return;
            }

            $claimToken = (string) $claim['token'];
            $logId = (int) $claim['log_id'];

            $adapter = SmsGatewayFactory::adapter();
            $sender = trim(AppSettings::get('sms.sender_id', '') ?? '');
            $result = $adapter->send($mobile, $message, $sender, $dltId !== '' ? $dltId : null);

            if (!empty($result['ok']) && trim((string) ($result['message_id'] ?? '')) !== '') {
                $pdo->prepare(
                    "UPDATE delivery_sms_logs SET status = 'sent', gateway_message_id = ?, gateway_response = ?,
                     sent_at = NOW(), error_message = NULL WHERE id = ?"
                )->execute([
                    (string) $result['message_id'],
                    mb_substr((string) ($result['response'] ?? ''), 0, 65000),
                    $logId,
                ]);
                DeliverySmsSendLockService::completeSuccess($deliveryId, $eventKey, $claimToken, $logId);
            } else {
                $pdo->prepare(
                    "UPDATE delivery_sms_logs SET status = 'failed', error_message = ?, gateway_response = ? WHERE id = ?"
                )->execute([
                    mb_substr((string) ($result['error'] ?? 'Send failed'), 0, 255),
                    mb_substr((string) ($result['response'] ?? ''), 0, 65000),
                    $logId,
                ]);
                DeliverySmsSendLockService::releaseFailure($deliveryId, $eventKey, $claimToken);
            }
        } catch (Throwable $e) {
            if ($claimToken !== null && $logId > 0) {
                DeliverySmsSendLockService::releaseFailure($deliveryId, $eventKey, $claimToken);
            }
            error_log('DeliverySmsService: ' . $e->getMessage());
        }
    }

    /** @return list<array<string, mixed>> */
    public static function logsForDelivery(int $deliveryId): array
    {
        if (!self::tableReady()) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM delivery_sms_logs WHERE delivery_id = ? ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([$deliveryId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Admin-safe view: masked mobile only, no raw gateway secrets in response body. */
    /** @return list<array<string, mixed>> */
    public static function logsForAdmin(int $deliveryId): array
    {
        $out = [];
        foreach (self::logsForDelivery($deliveryId) as $row) {
            unset($row['mobile']);
            $row['mobile_display'] = (string) ($row['mobile_masked'] ?? DeliverySmsService::maskMobile('0000000000'));
            if (isset($row['gateway_response']) && $row['gateway_response'] !== '') {
                $row['gateway_response_preview'] = mb_substr((string) $row['gateway_response'], 0, 120);
            }
            unset($row['gateway_response']);
            $out[] = $row;
        }

        return $out;
    }

    public static function retryLog(int $logId): bool
    {
        if (!self::tableReady()) {
            return false;
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM delivery_sms_logs WHERE id = ? LIMIT 1');
        $stmt->execute([$logId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (string) $row['status'] !== 'failed') {
            return false;
        }
        if (DeliverySmsSendLockService::tableReady()) {
            $lock = $pdo->prepare(
                'SELECT success_log_id FROM delivery_sms_send_locks WHERE delivery_id = ? AND event_key = ? LIMIT 1'
            );
            $lock->execute([(int) $row['delivery_id'], (string) $row['event_key']]);
            if ((int) ($lock->fetchColumn() ?: 0) > 0) {
                return false;
            }
        }
        self::sendDeliveryEvent((int) $row['delivery_id'], (string) $row['event_key'], true);

        return true;
    }

    private static function logSkipped(
        int $deliveryId,
        int $orderId,
        string $eventKey,
        string $mobile,
        string $reason
    ): void {
        if (!self::tableReady()) {
            return;
        }
        $m = $mobile !== '' ? $mobile : '0000000000';
        if (DeliverySmsSendLockService::tableReady()) {
            $claim = DeliverySmsSendLockService::claim(
                Database::pdo(),
                $deliveryId,
                $eventKey,
                false,
                static function (PDO $pdo, int $attempt) use ($deliveryId, $orderId, $eventKey, $m): int {
                    return self::insertPendingRow(
                        $pdo,
                        $deliveryId,
                        $orderId,
                        $eventKey,
                        'sms.template.' . $eventKey,
                        $m,
                        'none',
                        $attempt
                    );
                }
            );
            if ($claim === null) {
                return;
            }
            $logId = (int) $claim['log_id'];
            $token = (string) $claim['token'];
            Database::pdo()->prepare(
                "UPDATE delivery_sms_logs SET status = 'skipped', error_message = ? WHERE id = ?"
            )->execute([mb_substr($reason, 0, 255), $logId]);
            DeliverySmsSendLockService::releaseFailure($deliveryId, $eventKey, $token);

            return;
        }
        $logId = self::insertPendingRow(Database::pdo(), $deliveryId, $orderId, $eventKey, 'sms.template.' . $eventKey, $m, 'none', 1);
        Database::pdo()->prepare(
            "UPDATE delivery_sms_logs SET status = 'skipped', error_message = ? WHERE id = ?"
        )->execute([mb_substr($reason, 0, 255), $logId]);
    }

    private static function insertPendingRow(
        PDO $pdo,
        int $deliveryId,
        int $orderId,
        string $eventKey,
        string $templateKey,
        string $mobile,
        string $gateway,
        int $attempt
    ): int {
        $pdo->prepare(
            'INSERT INTO delivery_sms_logs
             (delivery_id, order_id, event_key, template_key, mobile, mobile_masked, status, gateway, attempt_number)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $deliveryId,
            $orderId,
            $eventKey,
            $templateKey,
            $mobile,
            self::maskMobile($mobile),
            'pending',
            $gateway,
            $attempt,
        ]);

        return (int) $pdo->lastInsertId();
    }
}

final class DeliveryCustomerNotifyService
{
    public static function afterStatusChange(int $deliveryId, string $newStatus): void
    {
        $event = DeliveryStatusTransitions::smsEventForStatus($newStatus);
        if ($event !== null) {
            DeliverySmsService::sendDeliveryEvent($deliveryId, $event);
        }
        self::maybeInApp($deliveryId, $newStatus);
    }

    public static function afterOrderConfirmed(int $deliveryId): void
    {
        DeliverySmsService::sendDeliveryEvent($deliveryId, 'order_confirmed');
        self::maybeInAppConfirmed($deliveryId);
    }

    private static function maybeInApp(int $deliveryId, string $status): void
    {
        if (!Install::isSchemaReady()) {
            return;
        }
        $status = DeliveryStatus::normalize($status);
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT o.customer_id, o.order_number FROM deliveries d INNER JOIN orders o ON o.id = d.order_id WHERE d.id = ?'
        );
        $stmt->execute([$deliveryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) ($row['customer_id'] ?? 0) <= 0) {
            return;
        }
        $label = DeliveryStatus::publicLabel($status);
        SubscriptionNotificationDedupeService::notifyOnce(
            (int) $row['customer_id'],
            'delivery_status',
            'delivery_' . $deliveryId . '_' . $status,
            'Delivery update',
            $label . ' — order ' . ($row['order_number'] ?? ''),
            ['delivery_id' => $deliveryId, 'order_number' => $row['order_number']]
        );
    }

    private static function maybeInAppConfirmed(int $deliveryId): void
    {
        if (!Install::isSchemaReady()) {
            return;
        }
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT o.customer_id, o.order_number FROM deliveries d INNER JOIN orders o ON o.id = d.order_id WHERE d.id = ?'
        );
        $stmt->execute([$deliveryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) ($row['customer_id'] ?? 0) <= 0) {
            return;
        }
        SubscriptionNotificationDedupeService::notifyOnce(
            (int) $row['customer_id'],
            'delivery_status',
            'delivery_' . $deliveryId . '_confirmed',
            'Order confirmed',
            'Your order ' . ($row['order_number'] ?? '') . ' is confirmed.',
            ['delivery_id' => $deliveryId]
        );
    }
}
