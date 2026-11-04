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
        SubscriptionCreditScheduleService::resolveSubscription((int) $sub['id']);
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
                $pdo->prepare('SELECT id FROM subscription_skips WHERE subscription_delivery_day_id = ? FOR UPDATE')
                    ->execute([$dayId]);
                $chkEarly = $pdo->prepare('SELECT id FROM subscription_skips WHERE subscription_delivery_day_id = ?');
                $chkEarly->execute([$dayId]);
                if ($chkEarly->fetchColumn()) {
                    return ['ok' => true, 'message' => 'Already skipped.', 'carry_forward_date' => ''];
                }
                if ((string) $day['status'] !== 'scheduled') {
                    throw new RuntimeException('This day cannot be skipped.');
                }
                if (!empty($day['generated_order_id'])) {
                    throw new RuntimeException('An order was already created for this day.');
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

                self::prepareSkippedDay($dayId, $day, $pdo);

                $carryForwardId = null;
                $carryDate = null;
                $cf = SubscriptionCreditScheduleService::appendNextScheduledDay($sub, $snapshot, $pdo);
                if ($cf !== null) {
                    $carryDate = $cf['date'];
                    $carryForwardId = $cf['id'];
                } else {
                    SubscriptionCreditScheduleService::ensureScheduleCoversRemainingCredits($subId, $pdo);
                    $subFresh = CustomerSubscriptionRepository::findById($subId) ?? $sub;
                    $cf2 = SubscriptionCreditScheduleService::appendNextScheduledDay($subFresh, $snapshot, $pdo);
                    if ($cf2 !== null) {
                        $carryDate = $cf2['date'];
                        $carryForwardId = $cf2['id'];
                    }
                }

                $pdo->prepare('UPDATE subscription_delivery_days SET status = ?, basket_status = ?, updated_at = NOW() WHERE id = ?')
                    ->execute(['skipped', 'none', $dayId]);
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

    /**
     * Release holds and block fulfilment for a skipped day (keeps audit/history rows).
     *
     * @param array<string, mixed> $dayRow
     */
    public static function prepareSkippedDay(int $dayId, array $dayRow, PDO $pdo): void
    {
        SubscriptionInventoryReserveService::releaseDay($dayId, $pdo);
        $basket = SubscriptionBasketRepository::basketByDayId($dayId);
        if ($basket !== null && empty($dayRow['generated_order_id'])) {
            $pdo->prepare(
                "UPDATE subscription_delivery_baskets SET status = 'cancelled', updated_at = NOW() WHERE id = ?"
            )->execute([(int) $basket['id']]);
            $pdo->prepare(
                "UPDATE subscription_delivery_days SET basket_status = 'none', updated_at = NOW() WHERE id = ?"
            )->execute([$dayId]);
        }
        $pdo->prepare(
            "UPDATE subscription_fulfilment_events SET status = 'failed', error_message = 'Delivery day skipped', updated_at = NOW()
             WHERE subscription_delivery_day_id = ? AND status <> 'completed'"
        )->execute([$dayId]);
        $pdo->prepare(
            "UPDATE subscription_basket_substitutions SET status = 'rejected', rejection_resolution = 'skip_day'
             WHERE subscription_delivery_day_id = ? AND status = 'pending_customer'"
        )->execute([$dayId]);
    }

    /** @return array{ok: bool, message?: string} */
    public static function pause(int $customerId, string $subscriptionNumber, string $note = ''): array
    {
        $tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $resumeDefault = (new DateTimeImmutable('now', $tz))->modify('+7 days')->format('Y-m-d');

        return self::pauseWithDates($customerId, $subscriptionNumber, $today, $resumeDefault, $note);
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public static function pauseWithDates(
        int $customerId,
        string $subscriptionNumber,
        string $pauseStartYmd,
        string $resumeOnYmd,
        string $note = ''
    ): array {
        $sub = CustomerSubscriptionRepository::findByNumberForCustomer($subscriptionNumber, $customerId);
        if ($sub === null || (string) $sub['status'] !== 'active') {
            return ['ok' => false, 'message' => 'Only active subscriptions can be paused.'];
        }
        SubscriptionCreditScheduleService::resolveSubscription((int) $sub['id']);
        $sub = CustomerSubscriptionRepository::findByNumberForCustomer($subscriptionNumber, $customerId);
        if ($sub === null || (string) $sub['status'] !== 'active') {
            return ['ok' => false, 'message' => 'Only active subscriptions can be paused.'];
        }
        $snapshot = CustomerSubscriptionRepository::decodeSnapshot($sub);
        $policy = is_array($snapshot['skip_pause_policy_json'] ?? null) ? $snapshot['skip_pause_policy_json'] : [];
        if (empty($policy['pause_allowed'])) {
            return ['ok' => false, 'message' => 'Pause is not enabled on your plan.'];
        }

        $valid = self::validatePauseDates($sub, $pauseStartYmd, $resumeOnYmd);
        if (!$valid['ok']) {
            return ['ok' => false, 'message' => $valid['message'] ?? 'Invalid pause dates.'];
        }

        $subId = (int) $sub['id'];
        try {
            Database::transaction(static function () use ($subId, $customerId, $note, $pauseStartYmd, $resumeOnYmd): void {
                $pdo = Database::pdo();
                $open = $pdo->prepare(
                    "SELECT id FROM subscription_pauses WHERE customer_subscription_id = ? AND status IN ('open', 'pending') LIMIT 1 FOR UPDATE"
                );
                $open->execute([$subId]);
                if ($open->fetchColumn()) {
                    throw new RuntimeException('A pause is already scheduled or active.');
                }

                $tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);
                $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
                $pausedAt = (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
                $phase = $pauseStartYmd > $today ? 'pending' : 'open';

                if (SubscriptionCreditScheduleService::pauseColumnsReady($pdo)) {
                    $pdo->prepare(
                        'INSERT INTO subscription_pauses (customer_subscription_id, pause_start_date, resume_on_date, paused_at, status, note)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $subId,
                        $pauseStartYmd,
                        $resumeOnYmd,
                        $pausedAt,
                        $phase,
                        trim($note) !== '' ? trim($note) : null,
                    ]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO subscription_pauses (customer_subscription_id, paused_at, status, note) VALUES (?, ?, ?, ?)'
                    )->execute([$subId, $pausedAt, $phase, trim($note) !== '' ? trim($note) : null]);
                }

                if ($phase === 'open') {
                    $pdo->prepare('UPDATE customer_subscriptions SET status = ?, updated_at = NOW() WHERE id = ?')
                        ->execute(['paused', $subId]);
                    self::releasePauseWindowInventory($subId, $pauseStartYmd, $resumeOnYmd, $pdo);
                }

                SubscriptionAudit::log($subId, null, 'customer', $customerId, 'subscription_pause_scheduled', [
                    'pause_start_date' => $pauseStartYmd,
                    'resume_on_date' => $resumeOnYmd,
                    'phase' => $phase,
                ]);
            });

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $sub
     * @return array{ok: bool, message?: string}
     */
    public static function validatePauseDates(array $sub, string $pauseStartYmd, string $resumeOnYmd): array
    {
        $tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);
        $start = DateTimeImmutable::createFromFormat('Y-m-d', $pauseStartYmd, $tz);
        $resume = DateTimeImmutable::createFromFormat('Y-m-d', $resumeOnYmd, $tz);
        if (!$start || !$resume) {
            return ['ok' => false, 'message' => 'Invalid date format.'];
        }
        if ($resume <= $start) {
            return ['ok' => false, 'message' => 'Resume date must be after pause start date.'];
        }
        $span = (int) $start->diff($resume)->days;
        if ($span > SubscriptionCreditScheduleService::MAX_PAUSE_CALENDAR_DAYS) {
            return [
                'ok' => false,
                'message' => 'Pause cannot exceed ' . SubscriptionCreditScheduleService::MAX_PAUSE_CALENDAR_DAYS . ' consecutive calendar days.',
            ];
        }
        $subStart = (string) ($sub['start_date'] ?? '');
        if ($subStart !== '' && $pauseStartYmd < $subStart) {
            return ['ok' => false, 'message' => 'Pause cannot start before your subscription start date.'];
        }
        $subId = (int) $sub['id'];
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM subscription_delivery_days
             WHERE customer_subscription_id = ? AND status IN ('scheduled','skipped','delivered') AND delivery_date >= ?"
        );
        $stmt->execute([$subId, $pauseStartYmd]);
        if ((int) $stmt->fetchColumn() === 0) {
            return ['ok' => false, 'message' => 'Pause start must fall within your delivery schedule.'];
        }

        return ['ok' => true];
    }

    /** @return array{ok: bool, message?: string} */
    public static function resume(int $customerId, string $subscriptionNumber): array
    {
        $sub = CustomerSubscriptionRepository::findByNumberForCustomer($subscriptionNumber, $customerId);
        if ($sub === null || (string) $sub['status'] !== 'paused') {
            return ['ok' => false, 'message' => 'Subscription is not paused.'];
        }

        return self::closePauseAndResume((int) $sub['id'], $customerId, 'customer', false);
    }

    /**
     * Lazy pause/resume resolution (no dedicated cron).
     */
    public static function resolvePauseAndResumeForSubscription(int $subId, PDO $pdo): void
    {
        $tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

        $stmt = $pdo->prepare(
            "SELECT * FROM subscription_pauses WHERE customer_subscription_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$subId]);
        $pending = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($pending) {
            $start = self::pauseStartDateFromRow($pending);
            if ($start !== '' && $start <= $today) {
                $pdo->prepare("UPDATE subscription_pauses SET status = 'open' WHERE id = ?")->execute([(int) $pending['id']]);
                $pdo->prepare('UPDATE customer_subscriptions SET status = ?, updated_at = NOW() WHERE id = ?')
                    ->execute(['paused', $subId]);
                $resumeOn = self::resumeOnDateFromRow($pending);
                if ($resumeOn !== '') {
                    self::releasePauseWindowInventory($subId, $start, $resumeOn, $pdo);
                }
            }
        }

        $stmt2 = $pdo->prepare(
            "SELECT * FROM subscription_pauses WHERE customer_subscription_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1 FOR UPDATE"
        );
        $stmt2->execute([$subId]);
        $open = $stmt2->fetch(PDO::FETCH_ASSOC);
        if (!$open) {
            return;
        }
        $resumeOn = self::resumeOnDateFromRow($open);
        if ($resumeOn === '' || $today < $resumeOn) {
            return;
        }
        self::finishPauseResume($subId, $open, $pdo, null, 'system', true);
    }

    /**
     * @param array<string, mixed> $pauseRow
     */
    private static function finishPauseResume(
        int $subId,
        array $pauseRow,
        PDO $pdo,
        ?int $actorId,
        string $actorType,
        bool $automatic
    ): void {
        $sub = CustomerSubscriptionRepository::findById($subId);
        if ($sub === null) {
            return;
        }
        $snapshot = CustomerSubscriptionRepository::decodeSnapshot($sub);
        $tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);
        $pauseStart = self::pauseStartDateFromRow($pauseRow);
        if ($pauseStart === '') {
            $pausedAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $pauseRow['paused_at'], $tz)
                ?: new DateTimeImmutable((string) $pauseRow['paused_at'], $tz);
            $pauseStart = $pausedAt->format('Y-m-d');
        }
        $resumedAt = new DateTimeImmutable('now', $tz);
        $resumeOn = self::resumeOnDateFromRow($pauseRow);
        if ($automatic && $resumeOn !== '') {
            $resumedAt = DateTimeImmutable::createFromFormat('Y-m-d', $resumeOn, $tz) ?: $resumedAt;
        }
        $shiftDays = max(1, (int) DateTimeImmutable::createFromFormat('Y-m-d', $pauseStart, $tz)->diff($resumedAt)->days);

        self::shiftFutureScheduledDays($subId, $pauseStart, $shiftDays, $snapshot, $pdo);

        $subFresh = CustomerSubscriptionRepository::findById($subId);
        if ($subFresh !== null) {
            $maxStmt = $pdo->prepare('SELECT MAX(delivery_date) FROM subscription_delivery_days WHERE customer_subscription_id = ?');
            $maxStmt->execute([$subId]);
            $maxDate = (string) ($maxStmt->fetchColumn() ?: '');
            if ($maxDate !== '') {
                SubscriptionCreditScheduleService::touchCalendarEndDate($subId, $maxDate, $pdo);
            }
            $pdo->prepare('UPDATE customer_subscriptions SET status = ?, updated_at = NOW() WHERE id = ?')
                ->execute(['active', $subId]);
        }

        $pdo->prepare(
            'UPDATE subscription_pauses SET status = ?, resumed_at = ? WHERE id = ?'
        )->execute(['closed', $resumedAt->format('Y-m-d H:i:s'), (int) $pauseRow['id']]);

        SubscriptionAudit::log($subId, null, $actorType, $actorId, $automatic ? 'subscription_auto_resumed' : 'subscription_resumed', [
            'pause_start_date' => $pauseStart,
            'resume_on_date' => $resumeOn,
            'shift_days' => $shiftDays,
            'deliveries_remaining' => (int) ($subFresh['deliveries_remaining'] ?? 0),
        ]);

        SubscriptionCreditScheduleService::ensureScheduleCoversRemainingCredits($subId, $pdo);
    }

    /** @return array{ok: bool, message?: string} */
    private static function closePauseAndResume(int $subId, ?int $customerId, string $actorType, bool $automatic): array
    {
        try {
            Database::transaction(static function () use ($subId, $customerId, $actorType, $automatic): void {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare(
                    "SELECT * FROM subscription_pauses WHERE customer_subscription_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1 FOR UPDATE"
                );
                $stmt->execute([$subId]);
                $pause = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$pause) {
                    throw new RuntimeException('Pause record not found.');
                }
                self::finishPauseResume($subId, $pause, $pdo, $customerId, $actorType, $automatic);
            });

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @param array<string, mixed> $pauseRow */
    private static function pauseStartDateFromRow(array $pauseRow): string
    {
        if (!empty($pauseRow['pause_start_date'])) {
            return (string) $pauseRow['pause_start_date'];
        }
        $raw = (string) ($pauseRow['paused_at'] ?? '');

        return $raw !== '' ? substr($raw, 0, 10) : '';
    }

    /** @param array<string, mixed> $pauseRow */
    private static function resumeOnDateFromRow(array $pauseRow): string
    {
        if (!empty($pauseRow['resume_on_date'])) {
            return (string) $pauseRow['resume_on_date'];
        }

        return '';
    }

    private static function releasePauseWindowInventory(int $subId, string $pauseStart, string $resumeOn, PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            "SELECT id FROM subscription_delivery_days
             WHERE customer_subscription_id = ? AND status = 'scheduled'
               AND delivery_date >= ? AND delivery_date < ?"
        );
        $stmt->execute([$subId, $pauseStart, $resumeOn]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $dayId) {
            SubscriptionInventoryReserveService::releaseDay((int) $dayId, $pdo);
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
        $subRow = CustomerSubscriptionRepository::findById($subId);
        $snapshot['_schedule_anchor_date'] = (string) (($subRow['start_date'] ?? null) ?: $pausedFromYmd);

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
                if (!SubscriptionScheduleGenerator::isDeliveryDateOnFrequency(
                    $candidate,
                    (string) $snapshot['_schedule_anchor_date'],
                    (string) ($snapshot['frequency_type'] ?? 'daily'),
                    is_array($snapshot['frequency_config'] ?? null) ? $snapshot['frequency_config'] : null
                )) {
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
