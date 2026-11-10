<?php

declare(strict_types=1);

final class CustomerSubscriptionRepository
{
    /** @return array<string, mixed>|null */
    public static function findById(int $id): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM customer_subscriptions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function findByNumber(string $number): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM customer_subscriptions WHERE subscription_number = ? LIMIT 1');
        $stmt->execute([$number]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function findByNumberForCustomer(string $number, int $customerId): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM customer_subscriptions WHERE subscription_number = ? AND customer_id = ? LIMIT 1'
        );
        $stmt->execute([$number, $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function countActiveForCustomer(int $customerId): int
    {
        if ($customerId <= 0 || !Install::isSchemaReady()) {
            return 0;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM customer_subscriptions WHERE customer_id = ? AND status = 'active'"
        );
        $stmt->execute([$customerId]);

        return (int) $stmt->fetchColumn();
    }

    /** Latest subscription row for account overview (any status). */
    public static function findLatestForCustomer(int $customerId): ?array
    {
        if ($customerId <= 0 || !Install::isSchemaReady()) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM customer_subscriptions WHERE customer_id = ? ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Paginated subscriptions for a customer (newest first).
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public static function listForCustomer(
        int $customerId,
        int $page = 1,
        int $perPage = AccountListPagination::DEFAULT_PER_PAGE
    ): array {
        Install::ensureConnection();
        $norm = AccountListPagination::normalize($page, $perPage, true);
        if ($customerId <= 0) {
            return AccountListPagination::emptyResult($norm['unlimited'] ? AccountListPagination::DEFAULT_PER_PAGE : $norm['per_page']);
        }

        $pdo = Database::pdo();
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM customer_subscriptions WHERE customer_id = ?');
        $countStmt->execute([$customerId]);
        $total = (int) $countStmt->fetchColumn();
        if ($total === 0) {
            return AccountListPagination::emptyResult($norm['unlimited'] ? AccountListPagination::DEFAULT_PER_PAGE : $norm['per_page']);
        }

        if ($norm['unlimited']) {
            $pageNum = 1;
            $per = 0;
            $sql = 'SELECT * FROM customer_subscriptions WHERE customer_id = ? ORDER BY created_at DESC, id DESC';
            $params = [$customerId];
        } else {
            $totalPages = AccountListPagination::totalPages($total, $norm['per_page']);
            $pageNum = AccountListPagination::clampPage($norm['page'], $totalPages);
            $per = $norm['per_page'];
            $offset = ($pageNum - 1) * $per;
            $sql = 'SELECT * FROM customer_subscriptions WHERE customer_id = ? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?';
            $params = [$customerId, $per, $offset];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $totalPagesOut = $norm['unlimited'] ? 1 : AccountListPagination::totalPages($total, $per);

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $pageNum,
            'per_page' => $norm['unlimited'] ? $total : $per,
            'total_pages' => $totalPagesOut,
        ];
    }

    /** @return array<string, mixed> */
    public static function decodeSnapshot(array $row): array
    {
        $raw = $row['plan_snapshot_json'] ?? '{}';
        if (is_string($raw)) {
            $d = json_decode($raw, true);

            return is_array($d) ? $d : [];
        }

        return is_array($raw) ? $raw : [];
    }

    /** @return list<array<string, mixed>> */
    public static function deliveryDays(int $subscriptionId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM subscription_delivery_days WHERE customer_subscription_id = ? ORDER BY day_index'
        );
        $stmt->execute([$subscriptionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

final class SubscriptionActivationService
{
    /**
     * Activate after verified payment. Idempotent. Uses plan_snapshot_json only.
     *
     * @return array{ok: bool, idempotent?: bool, message?: string, subscription_number?: string}
     */
    public static function activateFromPayment(
        string $subscriptionNumber,
        float $amountInr,
        string $gateway,
        string $gatewayPaymentId,
        string $gatewayOrderId,
        string $idempotencyKey,
        ?string $gatewayEventId = null
    ): array {
        if ($amountInr < 0) {
            return ['ok' => false, 'message' => 'Invalid amount.'];
        }
        $exec = PaymentGatewayConfig::assertPaymentExecutionAllowed($gateway);
        if (!$exec['ok']) {
            return ['ok' => false, 'message' => (string) ($exec['message'] ?? 'Payments are not available.')];
        }

        try {
            $out = Database::transaction(static function () use (
                $subscriptionNumber,
                $amountInr,
                $gateway,
                $gatewayPaymentId,
                $gatewayOrderId,
                $idempotencyKey,
                $gatewayEventId
            ): array {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare(
                    'SELECT * FROM customer_subscriptions WHERE subscription_number = ? LIMIT 1 FOR UPDATE'
                );
                $stmt->execute([$subscriptionNumber]);
                $sub = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$sub) {
                    throw new RuntimeException('Subscription not found.');
                }
                $subId = (int) $sub['id'];
                if ((string) $sub['status'] === 'active') {
                    return ['ok' => true, 'idempotent' => true, 'subscription_id' => $subId];
                }
                if ((string) $sub['status'] !== 'pending_payment') {
                    throw new RuntimeException('Subscription is not awaiting payment.');
                }

                $snapshot = CustomerSubscriptionRepository::decodeSnapshot($sub);
                $expected = (float) ($snapshot['price_paid'] ?? $sub['price_paid'] ?? 0);
                if (abs($expected - $amountInr) > 0.02) {
                    throw new RuntimeException('Amount mismatch.');
                }

                $payStmt = $pdo->prepare(
                    'SELECT * FROM subscription_payments WHERE customer_subscription_id = ? AND idempotency_key = ? LIMIT 1 FOR UPDATE'
                );
                $payStmt->execute([$subId, $idempotencyKey]);
                $payment = $payStmt->fetch(PDO::FETCH_ASSOC);
                if (!$payment) {
                    throw new RuntimeException('Payment record missing.');
                }
                if ((string) ($payment['status'] ?? '') === 'captured') {
                    return ['ok' => true, 'idempotent' => true, 'subscription_id' => $subId];
                }
                if ($gatewayEventId !== null && $gatewayEventId !== ''
                    && (string) ($payment['gateway_event_id'] ?? '') === $gatewayEventId) {
                    return ['ok' => true, 'idempotent' => true, 'subscription_id' => $subId];
                }

                $storedGw = (string) ($payment['gateway_order_id'] ?? '');
                if ($storedGw !== '' && $gatewayOrderId !== '' && $storedGw !== $gatewayOrderId) {
                    throw new RuntimeException('Gateway order reference mismatch.');
                }

                $tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);
                $startDate = (new DateTimeImmutable('now', $tz))->modify('+1 day')->format('Y-m-d');
                $gen = SubscriptionScheduleGenerator::generateDeliveryDates($snapshot, $startDate);
                if (!$gen['ok'] || count($gen['dates'] ?? []) !== (int) ($snapshot['delivery_count'] ?? 0)) {
                    throw new RuntimeException('Calendar generation failed.');
                }
                $dates = $gen['dates'];
                $calendarEnd = $startDate;
                foreach ($dates as $d) {
                    if ($d > $calendarEnd) {
                        $calendarEnd = $d;
                    }
                }
                $windowEnd = $calendarEnd;
                foreach ($dates as $d) {
                    if ($d > $windowEnd) {
                        $windowEnd = $d;
                    }
                }

                $cutoffHours = SubscriptionCutoff::cutoffHoursFromSnapshot($snapshot);
                $dayIns = $pdo->prepare(
                    'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, selection_cutoff_at, basket_status)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $idx = 1;
                foreach ($dates as $d) {
                    $cutoffAt = SubscriptionCutoff::cutoffDateTime($d, $cutoffHours)->format('Y-m-d H:i:s');
                    $dayIns->execute([$subId, $d, $idx, 'scheduled', $cutoffAt, 'none']);
                    $idx++;
                }

                $prefSlot = (string) ($sub['preferred_delivery_slot_slug'] ?? '');
                if ($prefSlot !== '') {
                    SubscriptionSlotService::stampDaysFromSubscription($subId, $prefSlot);
                }

                $pdo->prepare(
                    'UPDATE subscription_payments SET gateway_payment_id = ?, gateway_order_id = COALESCE(NULLIF(gateway_order_id, \'\'), ?),
                     gateway_event_id = COALESCE(?, gateway_event_id), status = ?, metadata_json = ?,
                     captured_at = COALESCE(captured_at, NOW()), updated_at = NOW() WHERE id = ?'
                )->execute([
                    $gatewayPaymentId,
                    $gatewayOrderId,
                    $gatewayEventId,
                    'captured',
                    json_encode(['gateway' => $gateway, 'activated' => true], JSON_UNESCAPED_UNICODE),
                    (int) $payment['id'],
                ]);

                $pdo->prepare(
                    'UPDATE customer_subscriptions SET status = ?, start_date = ?, calendar_end_date = ?,
                     deliveries_remaining = ?, price_paid = ?, activated_at = NOW(), updated_at = NOW() WHERE id = ?'
                )->execute([
                    'active',
                    $startDate,
                    $windowEnd,
                    (int) ($snapshot['delivery_count'] ?? count($dates)),
                    round($expected, 2),
                    $subId,
                ]);

                SubscriptionCreditScheduleService::ensureScheduleCoversRemainingCredits($subId, $pdo);

                $pdo->prepare(
                    'INSERT INTO subscription_selection_audit (customer_subscription_id, delivery_date, actor_type, action, detail_json)
                     VALUES (?, NULL, ?, ?, ?)'
                )->execute([
                    $subId,
                    'system',
                    'subscription_activated',
                    json_encode(['gateway' => $gateway, 'delivery_days' => count($dates)], JSON_UNESCAPED_UNICODE),
                ]);

                return ['ok' => true, 'subscription_id' => $subId];
            });

            $response = [
                'ok' => true,
                'idempotent' => !empty($out['idempotent']),
                'subscription_number' => $subscriptionNumber,
            ];
            if (!empty($out['ok']) && empty($out['idempotent']) && !empty($out['subscription_id'])) {
                try {
                    PushEventBridge::subscriptionActivated((int) $out['subscription_id']);
                } catch (Throwable $pushEx) {
                    error_log('SubscriptionActivationService push');
                }
            }

            return $response;
        } catch (Throwable $e) {
            error_log('Subscription activation failed: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Subscription could not be activated.'];
        }
    }

    public static function markPaymentFailed(string $subscriptionNumber, string $idempotencyKey, string $note): void
    {
        Database::transaction(static function () use ($subscriptionNumber, $idempotencyKey, $note): void {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT * FROM customer_subscriptions WHERE subscription_number = ? FOR UPDATE');
            $stmt->execute([$subscriptionNumber]);
            $sub = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sub || (string) $sub['status'] === 'active') {
                return;
            }
            $subId = (int) $sub['id'];
            $pdo->prepare('UPDATE customer_subscriptions SET status = ?, updated_at = NOW() WHERE id = ?')
                ->execute(['payment_failed', $subId]);
            $pdo->prepare(
                'UPDATE subscription_payments SET status = ? WHERE customer_subscription_id = ? AND idempotency_key = ?'
            )->execute(['failed', $subId, $idempotencyKey]);
            $pdo->prepare(
                'INSERT INTO subscription_selection_audit (customer_subscription_id, actor_type, action, detail_json) VALUES (?, ?, ?, ?)'
            )->execute([$subId, 'system', 'payment_failed', json_encode(['note' => $note])]);
        });
    }
}
