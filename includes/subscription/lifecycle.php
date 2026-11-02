<?php

declare(strict_types=1);

final class SubscriptionLifecycleService
{
    /** @return array{ok: bool, message?: string, carry_forward_date?: string} */
    public static function skipDeliveryDay(int $customerId, string $subscriptionNumber, int $dayId): array
    {
        $sub = CustomerSubscriptionRepository::findByNumberForCustomer($subscriptionNumber, $customerId);
        if ($sub === null || (string) $sub['status'] !== 'active') {
            return ['ok' => false, 'message' => 'Subscription not available.'];
        }
        $snapshot = CustomerSubscriptionRepository::decodeSnapshot($sub);
        $policy = is_array($snapshot['skip_pause_policy_json'] ?? null) ? $snapshot['skip_pause_policy_json'] : [];
        $subId = (int) $sub['id'];

        try {
            return Database::transaction(static function () use ($customerId, $subId, $dayId, $snapshot, $policy, $sub): array {
                $pdo = Database::pdo();
                $day = SubscriptionBasketRepository::dayByIdForSubscription($dayId, $subId);
                if ($day === null) {
                    throw new RuntimeException('Delivery day not found.');
                }
                if ((string) $day['status'] !== 'scheduled') {
                    throw new RuntimeException('This day cannot be skipped.');
                }

                $skipHours = max(1, (int) ($policy['skip_cutoff_hours'] ?? 18));
                if (!self::beforeSkipCutoff((string) $day['delivery_date'], $skipHours)) {
                    throw new RuntimeException('Skip cutoff has passed for this delivery.');
                }

                $maxSkips = (int) ($policy['max_skips'] ?? 0);
                $used = self::countSkips($subId);
                if ($maxSkips > 0 && $used >= $maxSkips) {
                    throw new RuntimeException('You have used all allowed skips on this plan.');
                }

                $pdo->prepare('SELECT id FROM subscription_skips WHERE subscription_delivery_day_id = ? FOR UPDATE')
                    ->execute([$dayId]);
                $chk = $pdo->prepare('SELECT id FROM subscription_skips WHERE subscription_delivery_day_id = ?');
                $chk->execute([$dayId]);
                if ($chk->fetchColumn()) {
                    return ['ok' => true, 'message' => 'Already skipped.'];
                }

                $carryForwardId = null;
                $carryDate = null;
                if (!empty($policy['carry_forward_skips'])) {
                    $cf = self::appendCarryForwardDay($sub, $snapshot, $pdo);
                    $carryDate = $cf['date'] ?? null;
                    $carryForwardId = $cf['id'] ?? null;
                }

                $pdo->prepare('UPDATE subscription_delivery_days SET status = ?, updated_at = NOW() WHERE id = ?')
                    ->execute(['skipped', $dayId]);
                $pdo->prepare(
                    'INSERT INTO subscription_skips (customer_subscription_id, subscription_delivery_day_id, carry_forward_day_id)
                     VALUES (?, ?, ?)'
                )->execute([$subId, $dayId, $carryForwardId]);

                SubscriptionAudit::log(
                    $subId,
                    (string) $day['delivery_date'],
                    'customer',
                    $customerId,
                    'delivery_skipped',
                    [
                        'carry_forward_date' => $carryDate,
                        'skips_used' => $used + 1,
                        'deliveries_remaining' => (int) $sub['deliveries_remaining'],
                    ]
                );

                return ['ok' => true, 'carry_forward_date' => $carryDate ?? ''];
            });
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array{ok: bool, message?: string} */
    public static function pause(int $customerId, string $subscriptionNumber, string $note = ''): array
    {
        $sub = CustomerSubscriptionRepository::findByNumberForCustomer($subscriptionNumber, $customerId);
        if ($sub === null || (string) $sub['status'] !== 'active') {
            return ['ok' => false, 'message' => 'Only active subscriptions can be paused.'];
        }
        $snapshot = CustomerSubscriptionRepository::decodeSnapshot($sub);
        $policy = is_array($snapshot['skip_pause_policy_json'] ?? null) ? $snapshot['skip_pause_policy_json'] : [];
        if (empty($policy['pause_allowed'])) {
            return ['ok' => false, 'message' => 'Pause is not enabled on your plan.'];
        }

        $subId = (int) $sub['id'];
        try {
            Database::transaction(static function () use ($subId, $customerId, $note): void {
                $pdo = Database::pdo();
                $open = $pdo->prepare(
                    'SELECT id FROM subscription_pauses WHERE customer_subscription_id = ? AND status = ? LIMIT 1 FOR UPDATE'
                );
                $open->execute([$subId, 'open']);
                if ($open->fetchColumn()) {
                    throw new RuntimeException('Subscription is already paused.');
                }
                $tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);
                $pausedAt = (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
                $pdo->prepare(
                    'INSERT INTO subscription_pauses (customer_subscription_id, paused_at, status, note) VALUES (?, ?, ?, ?)'
                )->execute([$subId, $pausedAt, 'open', trim($note) !== '' ? trim($note) : null]);
                $pdo->prepare('UPDATE customer_subscriptions SET status = ?, updated_at = NOW() WHERE id = ?')
                    ->execute(['paused', $subId]);
                SubscriptionAudit::log($subId, null, 'customer', $customerId, 'subscription_paused', ['paused_at' => $pausedAt]);
            });

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array{ok: bool, message?: string} */
    public static function resume(int $customerId, string $subscriptionNumber): array
    {
        $sub = CustomerSubscriptionRepository::findByNumberForCustomer($subscriptionNumber, $customerId);
        if ($sub === null || (string) $sub['status'] !== 'paused') {
            return ['ok' => false, 'message' => 'Subscription is not paused.'];
        }
        $snapshot = CustomerSubscriptionRepository::decodeSnapshot($sub);
        $subId = (int) $sub['id'];

        try {
            Database::transaction(static function () use ($subId, $customerId, $snapshot, $sub): void {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare(
                    'SELECT * FROM subscription_pauses WHERE customer_subscription_id = ? AND status = ? ORDER BY id DESC LIMIT 1 FOR UPDATE'
                );
                $stmt->execute([$subId, 'open']);
                $pause = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$pause) {
                    throw new RuntimeException('Pause record not found.');
                }
                $tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);
                $pausedAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $pause['paused_at'], $tz)
                    ?: new DateTimeImmutable((string) $pause['paused_at'], $tz);
                $resumedAt = new DateTimeImmutable('now', $tz);
                $pauseDays = max(0, (int) $pausedAt->diff($resumedAt)->days);
                if ($pauseDays < 1) {
                    $pauseDays = 1;
                }

                $subFresh = CustomerSubscriptionRepository::findById($subId);
                if ($subFresh === null) {
                    throw new RuntimeException('Subscription missing.');
                }

                self::shiftFutureScheduledDays($subId, $pausedAt->format('Y-m-d'), $pauseDays, $snapshot, $pdo);

                $end = (string) ($subFresh['calendar_end_date'] ?? '');
                if ($end !== '') {
                    $newEnd = (new DateTimeImmutable($end, $tz))->modify('+' . $pauseDays . ' days')->format('Y-m-d');
                    $pdo->prepare('UPDATE customer_subscriptions SET calendar_end_date = ?, status = ?, updated_at = NOW() WHERE id = ?')
                        ->execute([$newEnd, 'active', $subId]);
                } else {
                    $pdo->prepare('UPDATE customer_subscriptions SET status = ?, updated_at = NOW() WHERE id = ?')
                        ->execute(['active', $subId]);
                }

                $remaining = (int) $subFresh['deliveries_remaining'];

                $pdo->prepare(
                    'UPDATE subscription_pauses SET status = ?, resumed_at = ? WHERE id = ?'
                )->execute(['closed', $resumedAt->format('Y-m-d H:i:s'), (int) $pause['id']]);

                SubscriptionAudit::log($subId, null, 'customer', $customerId, 'subscription_resumed', [
                    'pause_days' => $pauseDays,
                    'deliveries_remaining' => $remaining,
                ]);
            });

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public static function countSkips(int $subscriptionId): int
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM subscription_skips WHERE customer_subscription_id = ?');
        $stmt->execute([$subscriptionId]);

        return (int) $stmt->fetchColumn();
    }

    private static function beforeSkipCutoff(string $deliveryDateYmd, int $skipCutoffHours): bool
    {
        $tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);
        $now = new DateTimeImmutable('now', $tz);
        $cutoff = SubscriptionCutoff::cutoffDateTime($deliveryDateYmd, $skipCutoffHours);

        return $now < $cutoff;
    }

    /** @param array<string, mixed> $sub */
    /** @return array{date: string, id: int}|null */
    private static function appendCarryForwardDay(array $sub, array $snapshot, PDO $pdo): ?array
    {
        $subId = (int) $sub['id'];
        $stmt = $pdo->prepare(
            'SELECT MAX(delivery_date) AS mx, MAX(day_index) AS mi FROM subscription_delivery_days WHERE customer_subscription_id = ?'
        );
        $stmt->execute([$subId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $maxDate = (string) ($row['mx'] ?? $sub['start_date'] ?? '');
        $maxIndex = (int) ($row['mi'] ?? 0);
        if ($maxDate === '') {
            return null;
        }
        $after = (new DateTimeImmutable($maxDate, new DateTimeZone(SubscriptionScheduleGenerator::TZ)))->modify('+1 day')->format('Y-m-d');
        $gen = SubscriptionScheduleGenerator::generateDeliveryDates($snapshot, $after);
        if (!$gen['ok'] || ($gen['dates'][0] ?? null) === null) {
            $newDate = $after;
        } else {
            $newDate = (string) $gen['dates'][0];
        }

        $end = (string) ($sub['calendar_end_date'] ?? $newDate);
        if ($newDate > $end) {
            $pdo->prepare('UPDATE customer_subscriptions SET calendar_end_date = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$newDate, $subId]);
        }

        $cutoffHours = SubscriptionCutoff::cutoffHoursFromSnapshot($snapshot);
        $cutoffAt = SubscriptionCutoff::cutoffDateTime($newDate, $cutoffHours)->format('Y-m-d H:i:s');
        $pdo->prepare(
            'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, selection_cutoff_at, basket_status)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$subId, $newDate, $maxIndex + 1, 'scheduled', $cutoffAt, 'none']);
        $newId = (int) $pdo->lastInsertId();

        return ['date' => $newDate, 'id' => $newId];
    }

    private static function shiftFutureScheduledDays(
        int $subId,
        string $pausedFromYmd,
        int $shiftDays,
        array $snapshot,
        PDO $pdo
    ): void {
        $stmt = $pdo->prepare(
            'SELECT id, delivery_date FROM subscription_delivery_days
             WHERE customer_subscription_id = ? AND status = ? AND delivery_date >= ?
             ORDER BY delivery_date ASC'
        );
        $stmt->execute([$subId, 'scheduled', $pausedFromYmd]);
        $days = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($days === []) {
            return;
        }
        $tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);
        $cutoffHours = SubscriptionCutoff::cutoffHoursFromSnapshot($snapshot);

        $maxStmt = $pdo->prepare('SELECT MAX(delivery_date) FROM subscription_delivery_days WHERE customer_subscription_id = ?');
        $maxStmt->execute([$subId]);
        $maxExisting = (string) ($maxStmt->fetchColumn() ?: $pausedFromYmd);
        $cursor = (new DateTimeImmutable($maxExisting, $tz))->modify('+' . max(1, $shiftDays) . ' days');

        $used = $pdo->prepare('SELECT id FROM subscription_delivery_days WHERE customer_subscription_id = ? AND delivery_date = ? LIMIT 1');
        foreach ($days as $d) {
            do {
                $candidate = $cursor->format('Y-m-d');
                $used->execute([$subId, $candidate]);
                $taken = $used->fetchColumn();
                if ($taken && (int) $taken !== (int) $d['id']) {
                    $cursor = $cursor->modify('+1 day');
                    continue;
                }
                break;
            } while (true);
            $newCutoff = SubscriptionCutoff::cutoffDateTime($candidate, $cutoffHours)->format('Y-m-d H:i:s');
            $pdo->prepare(
                'UPDATE subscription_delivery_days SET delivery_date = ?, selection_cutoff_at = ?, updated_at = NOW() WHERE id = ?'
            )->execute([$candidate, $newCutoff, (int) $d['id']]);
            $cursor = $cursor->modify('+1 day');
        }
    }

    /**
     * Admin override with audit trail and customer notification.
     *
     * @param list<array{variant_id: int, qty: float}> $lines
     * @return array{ok: bool, message?: string}
     */
    public static function adminOverrideBasket(
        int $adminId,
        int $subscriptionId,
        int $dayId,
        array $lines,
        string $reason
    ): array {
        if (!AdminAuth::check() || $adminId !== AdminAuth::id()) {
            return ['ok' => false, 'message' => 'Unauthorized.'];
        }
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'message' => 'Reason is required.'];
        }

        $sub = CustomerSubscriptionRepository::findById($subscriptionId);
        if ($sub === null) {
            return ['ok' => false, 'message' => 'Subscription not found.'];
        }
        $day = SubscriptionBasketRepository::dayByIdForSubscription($dayId, $subscriptionId);
        if ($day === null) {
            return ['ok' => false, 'message' => 'Delivery day not found.'];
        }

        $beforeBasket = SubscriptionBasketRepository::basketByDayId($dayId);
        $beforeItems = $beforeBasket ? SubscriptionBasketRepository::basketItems((int) $beforeBasket['id']) : [];

        $result = SubscriptionBasketService::adminOverrideDayWithAudit(
            $adminId,
            $subscriptionId,
            $dayId,
            $lines,
            true,
            $reason,
            $beforeItems
        );
        if (!$result['ok']) {
            return $result;
        }

        CustomerNotificationService::notify(
            (int) $sub['customer_id'],
            'Subscription basket updated',
            'Our team updated your basket for delivery on ' . ($day['delivery_date'] ?? '') . '. Reason: ' . $reason,
            [
                'subscription_number' => (string) $sub['subscription_number'],
                'delivery_date' => (string) $day['delivery_date'],
                'admin_override' => true,
            ]
        );

        return ['ok' => true];
    }
}
