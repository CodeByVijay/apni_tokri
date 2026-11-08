<?php

declare(strict_types=1);

final class SubscriptionSlotService
{
    public static function validatePreferredSlug(string $slug): ?string
    {
        if (!DeliveryConfig::slotsEnabled()) {
            return $slug === '' || $slug === 'auto' ? null : 'Delivery slots are not enabled.';
        }
        if ($slug === '' || $slug === 'auto') {
            return 'Choose a delivery slot.';
        }
        if (DeliverySlotService::resolveBySlug($slug) === null) {
            return 'Invalid delivery slot.';
        }

        return null;
    }

    /**
     * Resolve preferred slot for a subscription delivery date (Stage 8 capacity rules).
     *
     * @return array{ok: bool, slot?: array<string, mixed>, unavailable?: bool, message?: string}
     */
    public static function resolveForDay(array $dayRow, string $deliveryDate): array
    {
        if (!DeliveryConfig::slotsEnabled()) {
            return ['ok' => true, 'slot' => ['slug' => 'auto', 'id' => null, 'delivery_date' => $deliveryDate]];
        }
        $slug = (string) ($dayRow['preferred_delivery_slot_slug'] ?? '');
        if ($slug === '') {
            $slug = (string) ($dayRow['subscription_preferred_slug'] ?? '');
        }
        if ($slug === '') {
            $fallback = DeliverySlotService::firstBookableForDate($deliveryDate);

            return $fallback !== null
                ? ['ok' => true, 'slot' => $fallback]
                : ['ok' => false, 'message' => 'No bookable slot.'];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM delivery_slots WHERE slug = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$slug]);
        $slotRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$slotRow) {
            return ['ok' => false, 'unavailable' => true, 'message' => 'Preferred slot no longer exists.'];
        }
        $err = DeliverySlotService::assertBookable($slotRow, $deliveryDate);
        if ($err !== null) {
            return ['ok' => false, 'unavailable' => true, 'message' => $err];
        }

        return [
            'ok' => true,
            'slot' => [
                'id' => (int) $slotRow['id'],
                'slug' => (string) $slotRow['slug'],
                'label' => (string) $slotRow['label'],
                'window' => DeliverySlotDisplay::formatWindowRange(
                    (string) ($slotRow['start_time'] ?? ''),
                    (string) ($slotRow['end_time'] ?? '')
                ) ?? (string) $slotRow['window_label'],
                'delivery_date' => $deliveryDate,
                'row' => $slotRow,
            ],
        ];
    }

    public static function applyPreferredToDay(int $dayId, string $slug, int $customerId): array
    {
        $err = self::validatePreferredSlug($slug);
        if ($err !== null) {
            return ['ok' => false, 'message' => $err];
        }
        try {
            return Database::transaction(static function () use ($dayId, $slug, $customerId): array {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare(
                    'SELECT d.*, cs.customer_id, cs.preferred_delivery_slot_slug AS sub_slug, cs.plan_snapshot_json
                     FROM subscription_delivery_days d
                     INNER JOIN customer_subscriptions cs ON cs.id = d.customer_subscription_id
                     WHERE d.id = ? FOR UPDATE'
                );
                $stmt->execute([$dayId]);
                $day = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$day || (int) $day['customer_id'] !== $customerId) {
                    return ['ok' => false, 'message' => 'Day not found.'];
                }
                if ((string) $day['basket_status'] === 'confirmed' && SubscriptionCutoff::isLocked($day)) {
                    return ['ok' => false, 'message' => 'Cutoff passed — slot cannot be changed.'];
                }
                if (!empty($day['generated_order_id'])) {
                    return ['ok' => false, 'message' => 'Order already generated for this day.'];
                }
                $resolved = DeliverySlotService::resolveBySlug($slug);
                $slotId = $resolved !== null ? (int) $resolved['id'] : null;
                $snapshot = json_encode([
                    'slug' => $slug,
                    'slot_id' => $slotId,
                    'set_at' => date('c'),
                ], JSON_UNESCAPED_UNICODE);
                $pdo->prepare(
                    'UPDATE subscription_delivery_days SET preferred_delivery_slot_slug = ?, preferred_delivery_slot_id = ?,
                     slot_snapshot_json = ?, updated_at = NOW() WHERE id = ?'
                )->execute([$slug, $slotId, $snapshot, $dayId]);

                return ['ok' => true];
            });
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not save slot.'];
        }
    }

    public static function stampDaysFromSubscription(int $subId, string $slug): void
    {
        if ($slug === '' || !Install::isSchemaReady()) {
            return;
        }
        Install::ensureConnection();
        $resolved = DeliverySlotService::resolveBySlug($slug);
        $slotId = $resolved !== null ? (int) $resolved['id'] : null;
        $snapshot = json_encode(['slug' => $slug, 'slot_id' => $slotId, 'source' => 'subscription_checkout'], JSON_UNESCAPED_UNICODE);
        Database::pdo()->prepare(
            'UPDATE subscription_delivery_days SET preferred_delivery_slot_slug = ?, preferred_delivery_slot_id = ?,
             slot_snapshot_json = ? WHERE customer_subscription_id = ? AND generated_order_id IS NULL'
        )->execute([$slug, $slotId, $snapshot, $subId]);
    }
}
