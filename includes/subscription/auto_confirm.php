<?php

declare(strict_types=1);

final class SubscriptionAutoConfirmService
{
    /**
     * Confirm baskets whose selection cutoff has passed (Asia/Kolkata).
     *
     * @return array{ok: bool, processed: int, skipped: int, errors: list<string>, run_id: string}
     */
    public static function run(?DateTimeImmutable $now = null, ?string $runId = null): array
    {
        if (PHP_SAPI !== 'cli') {
            return ['ok' => false, 'processed' => 0, 'skipped' => 0, 'errors' => ['CLI only.'], 'run_id' => ''];
        }
        Install::ensureConnection();
        $tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);
        if ($now === null) {
            $now = new DateTimeImmutable('now', $tz);
        }
        if ($runId === null) {
            $runId = 'auto_confirm_' . $now->format('YmdHis') . '_' . substr(Security::randomToken(4), 0, 6);
        }
        $nowSql = $now->format('Y-m-d H:i:s');

        $stmt = Database::pdo()->prepare(
            'SELECT d.id AS day_id, d.customer_subscription_id, d.delivery_date, d.status AS day_status,
                    cs.subscription_number, cs.plan_snapshot_json, cs.status AS sub_status
             FROM subscription_delivery_days d
             INNER JOIN customer_subscriptions cs ON cs.id = d.customer_subscription_id
             LEFT JOIN subscription_delivery_baskets b ON b.subscription_delivery_day_id = d.id
             LEFT JOIN subscription_auto_confirm_events ace ON ace.subscription_delivery_day_id = d.id
             WHERE cs.status = \'active\'
               AND d.status IN (\'scheduled\')
               AND d.selection_cutoff_at IS NOT NULL
               AND d.selection_cutoff_at <= ?
               AND ace.id IS NULL
               AND (b.id IS NULL OR b.status IN (\'suggested\', \'draft\'))'
        );
        $stmt->execute([$nowSql]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $processed = 0;
        $skipped = 0;
        $errors = [];
        foreach ($rows as $row) {
            $result = self::confirmDay((int) $row['day_id'], $runId, $now);
            if (!empty($result['idempotent'])) {
                $skipped++;
            } elseif ($result['ok']) {
                $processed++;
            } else {
                $errors[] = 'Day ' . $row['day_id'] . ': ' . ($result['message'] ?? 'failed');
            }
        }

        return ['ok' => true, 'processed' => $processed, 'skipped' => $skipped, 'errors' => $errors, 'run_id' => $runId];
    }

    /**
     * @return array{ok: bool, idempotent?: bool, message?: string}
     */
    public static function confirmDay(int $dayId, string $runId, ?DateTimeImmutable $now = null): array
    {
        $tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);
        if ($now === null) {
            $now = new DateTimeImmutable('now', $tz);
        }

        try {
            return Database::transaction(static function () use ($dayId, $runId, $now): array {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare(
                    'SELECT d.*, cs.id AS sub_id, cs.subscription_number, cs.customer_id, cs.plan_snapshot_json
                     FROM subscription_delivery_days d
                     INNER JOIN customer_subscriptions cs ON cs.id = d.customer_subscription_id
                     WHERE d.id = ? FOR UPDATE'
                );
                $stmt->execute([$dayId]);
                $day = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$day) {
                    return ['ok' => false, 'message' => 'Day not found.'];
                }
                if ((string) $day['status'] !== 'scheduled') {
                    return ['ok' => true, 'idempotent' => true];
                }

                $cutoffRaw = (string) ($day['selection_cutoff_at'] ?? '');
                if ($cutoffRaw === '') {
                    return ['ok' => false, 'message' => 'No cutoff configured.'];
                }
                $cutoff = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $cutoffRaw, new DateTimeZone(SubscriptionScheduleGenerator::TZ));
                if (!$cutoff || $now < $cutoff) {
                    return ['ok' => false, 'message' => 'Before cutoff.'];
                }

                $ev = $pdo->prepare('SELECT id FROM subscription_auto_confirm_events WHERE subscription_delivery_day_id = ? LIMIT 1 FOR UPDATE');
                $ev->execute([$dayId]);
                if ($ev->fetchColumn()) {
                    return ['ok' => true, 'idempotent' => true];
                }

                $sub = [
                    'id' => (int) $day['sub_id'],
                    'subscription_number' => (string) $day['subscription_number'],
                    'customer_id' => (int) $day['customer_id'],
                    'plan_snapshot_json' => $day['plan_snapshot_json'],
                ];
                $snapshot = CustomerSubscriptionRepository::decodeSnapshot($sub);

                $basket = SubscriptionBasketRepository::basketByDayId($dayId);
                if ($basket !== null && (string) $basket['status'] === 'confirmed') {
                    $pdo->prepare(
                        'INSERT IGNORE INTO subscription_auto_confirm_events (subscription_delivery_day_id, basket_id, cron_run_id) VALUES (?, ?, ?)'
                    )->execute([$dayId, (int) $basket['id'], $runId]);

                    return ['ok' => true, 'idempotent' => true];
                }

                if ($basket === null || (string) $basket['status'] === 'suggested') {
                    $gen = SubscriptionBasketService::ensureSuggestedBasket($sub, $day, $snapshot);
                    if (!$gen['ok']) {
                        return ['ok' => false, 'message' => $gen['message'] ?? 'No suggestion.'];
                    }
                    $basket = $gen['basket'];
                }

                if ($basket === null) {
                    return ['ok' => false, 'message' => 'Basket missing.'];
                }
                if ((string) $basket['status'] === 'confirmed') {
                    return ['ok' => true, 'idempotent' => true];
                }

                $basketId = (int) $basket['id'];
                $total = (float) $basket['total_inr'];
                $allowance = SubscriptionBasketService::dailyAllowance($snapshot);
                if ($total > $allowance + 0.01) {
                    return ['ok' => false, 'message' => 'Over allowance; cannot auto-confirm.'];
                }

                $confirmItems = SubscriptionBasketRepository::basketItems($basketId);
                if ($confirmItems === []) {
                    return ['ok' => false, 'message' => 'Incomplete basket (no items); cannot auto-confirm.'];
                }

                $pdo->prepare(
                    'UPDATE subscription_delivery_baskets SET status = ?, confirmed_at = NOW(), updated_at = NOW() WHERE id = ? AND status <> ?'
                )->execute(['confirmed', $basketId, 'confirmed']);
                $pdo->prepare(
                    'UPDATE subscription_delivery_days SET basket_status = ?, updated_at = NOW() WHERE id = ?'
                )->execute(['confirmed', $dayId]);

                $pdo->prepare(
                    'INSERT INTO subscription_auto_confirm_events (subscription_delivery_day_id, basket_id, cron_run_id) VALUES (?, ?, ?)'
                )->execute([$dayId, $basketId, $runId]);

                SubscriptionAudit::log(
                    (int) $sub['id'],
                    (string) $day['delivery_date'],
                    'system',
                    null,
                    'basket_auto_confirmed',
                    ['basket_id' => $basketId, 'total_inr' => $total, 'cron_run_id' => $runId]
                );

                return ['ok' => true];
            });
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
