<?php

declare(strict_types=1);

/**
 * Keeps scheduled delivery days aligned with remaining purchased credits (not calendar expiry).
 */
final class SubscriptionCreditScheduleService
{
    public const MAX_PAUSE_CALENDAR_DAYS = 30;

    /** Scheduled days that can still be fulfilled (not skipped/cancelled/delivered). */
    public static function countScheduledDays(int $subscriptionId, ?PDO $pdo = null): int
    {
        Install::ensureConnection();
        $pdo = $pdo ?? Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM subscription_delivery_days
             WHERE customer_subscription_id = ? AND status = 'scheduled'"
        );
        $stmt->execute([$subscriptionId]);

        return (int) $stmt->fetchColumn();
    }

    /** Purchased delivery credits from plan snapshot. */
    public static function purchasedCredits(array $subscriptionRow): int
    {
        $snapshot = CustomerSubscriptionRepository::decodeSnapshot($subscriptionRow);

        return max(0, (int) ($snapshot['delivery_count'] ?? 0));
    }

    public static function deliveredCount(array $subscriptionRow): int
    {
        $purchased = self::purchasedCredits($subscriptionRow);
        $remaining = max(0, (int) ($subscriptionRow['deliveries_remaining'] ?? 0));

        return max(0, $purchased - $remaining);
    }

    /**
     * @return list<string> Y-m-d dates already used on this subscription
     */
    public static function existingDeliveryDates(int $subscriptionId, ?PDO $pdo = null): array
    {
        Install::ensureConnection();
        $pdo = $pdo ?? Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT delivery_date FROM subscription_delivery_days WHERE customer_subscription_id = ?'
        );
        $stmt->execute([$subscriptionId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $d) {
            $out[] = (string) $d;
        }

        return $out;
    }

    /**
     * Ensure at least as many scheduled days as remaining credits (extends beyond original calendar window).
     */
    public static function ensureScheduleCoversRemainingCredits(int $subscriptionId, ?PDO $pdo = null): void
    {
        Install::ensureConnection();
        $pdo = $pdo ?? Database::pdo();
        $sub = CustomerSubscriptionRepository::findById($subscriptionId);
        if ($sub === null) {
            return;
        }
        $status = (string) $sub['status'];
        if (!in_array($status, ['active', 'paused'], true)) {
            return;
        }
        $remaining = (int) $sub['deliveries_remaining'];
        if ($remaining <= 0) {
            return;
        }

        $snapshot = CustomerSubscriptionRepository::decodeSnapshot($sub);
        $scheduled = self::countScheduledDays($subscriptionId, $pdo);
        $guard = 0;
        while ($scheduled < $remaining && $guard < 500) {
            $guard++;
            $added = self::appendNextScheduledDay($sub, $snapshot, $pdo);
            if ($added === null) {
                break;
            }
            $sub = CustomerSubscriptionRepository::findById($subscriptionId) ?? $sub;
            $scheduled = self::countScheduledDays($subscriptionId, $pdo);
        }
    }

    /**
     * @param array<string, mixed> $sub
     * @param array<string, mixed> $snapshot
     * @return array{date: string, id: int}|null
     */
    public static function appendNextScheduledDay(array $sub, array $snapshot, PDO $pdo): ?array
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
        $exclude = self::existingDeliveryDates($subId, $pdo);
        $after = (new DateTimeImmutable($maxDate, new DateTimeZone(SubscriptionScheduleGenerator::TZ)))
            ->modify('+1 day')
            ->format('Y-m-d');
        $snapshot['_schedule_anchor_date'] = (string) ($sub['start_date'] ?? $after);
        $newDate = SubscriptionScheduleGenerator::nextDeliveryDateAfter($snapshot, $after, $exclude);
        if ($newDate === null) {
            return null;
        }

        self::touchCalendarEndDate($subId, $newDate, $pdo);

        $cutoffHours = SubscriptionCutoff::cutoffHoursFromSnapshot($snapshot);
        $cutoffAt = SubscriptionCutoff::cutoffDateTime($newDate, $cutoffHours)->format('Y-m-d H:i:s');
        $pdo->prepare(
            'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, selection_cutoff_at, basket_status)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$subId, $newDate, $maxIndex + 1, 'scheduled', $cutoffAt, 'none']);
        $newId = (int) $pdo->lastInsertId();

        return ['date' => $newDate, 'id' => $newId];
    }

    public static function touchCalendarEndDate(int $subscriptionId, string $dateYmd, PDO $pdo): void
    {
        $pdo->prepare(
            'UPDATE customer_subscriptions SET calendar_end_date = GREATEST(COALESCE(calendar_end_date, ?), ?), updated_at = NOW()
             WHERE id = ?'
        )->execute([$dateYmd, $dateYmd, $subscriptionId]);
    }

    /**
     * Idempotent completion when no credits remain and subscription is still active/paused.
     */
    public static function maybeCompleteSubscription(int $subscriptionId, ?PDO $pdo = null): bool
    {
        Install::ensureConnection();
        $pdo = $pdo ?? Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM customer_subscriptions WHERE id = ? FOR UPDATE');
        $stmt->execute([$subscriptionId]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sub) {
            return false;
        }
        $status = (string) $sub['status'];
        if (!in_array($status, ['active', 'paused'], true)) {
            return false;
        }
        if ((int) $sub['deliveries_remaining'] > 0) {
            return false;
        }
        $openPause = $pdo->prepare(
            "SELECT id FROM subscription_pauses WHERE customer_subscription_id = ? AND status IN ('open', 'pending') LIMIT 1"
        );
        $openPause->execute([$subscriptionId]);
        if ($openPause->fetchColumn()) {
            return false;
        }

        $upd = $pdo->prepare(
            "UPDATE customer_subscriptions SET status = 'completed', updated_at = NOW() WHERE id = ? AND status IN ('active', 'paused')"
        );
        $upd->execute([$subscriptionId]);
        if ($upd->rowCount() === 0) {
            return false;
        }
        SubscriptionAudit::log($subscriptionId, null, 'system', null, 'subscription_completed', [
            'deliveries_remaining' => 0,
        ]);

        return true;
    }

    /**
     * Lazy lifecycle: pending pause start, automatic resume, schedule top-up, completion.
     */
    public static function resolveSubscription(int $subscriptionId, ?PDO $pdo = null): void
    {
        if (!Install::isSchemaReady()) {
            return;
        }
        Install::ensureConnection();
        try {
            Database::transaction(static function () use ($subscriptionId): void {
                $pdo = Database::pdo();
                SubscriptionLifecycleService::resolvePauseAndResumeForSubscription($subscriptionId, $pdo);
                $sub = CustomerSubscriptionRepository::findById($subscriptionId);
                if ($sub !== null && in_array((string) $sub['status'], ['active', 'paused'], true)) {
                    self::ensureScheduleCoversRemainingCredits($subscriptionId, $pdo);
                }
                self::maybeCompleteSubscription($subscriptionId, $pdo);
            });
        } catch (Throwable $e) {
            error_log('Subscription resolve: ' . $e->getMessage());
        }
    }

    /** @return array<string, mixed>|null Open or pending pause row */
    public static function currentPauseRow(int $subscriptionId, ?PDO $pdo = null): ?array
    {
        Install::ensureConnection();
        $pdo = $pdo ?? Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT * FROM subscription_pauses
             WHERE customer_subscription_id = ? AND status IN ('open', 'pending')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$subscriptionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function pauseColumnsReady(?PDO $pdo = null): bool
    {
        Install::ensureConnection();
        $pdo = $pdo ?? Database::pdo();
        try {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
            );
            $stmt->execute(['subscription_pauses', 'pause_start_date']);

            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}
