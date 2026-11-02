<?php

declare(strict_types=1);

final class SubscriptionCutoff
{
    public static function cutoffDateTime(string $deliveryDateYmd, int $cutoffHoursBeforeDelivery): DateTimeImmutable
    {
        $tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);
        $deliveryStart = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $deliveryDateYmd . ' 00:00:00', $tz);
        if (!$deliveryStart) {
            $deliveryStart = new DateTimeImmutable($deliveryDateYmd, $tz);
        }

        return $deliveryStart->modify('-' . max(0, $cutoffHoursBeforeDelivery) . ' hours');
    }

    /** @param array<string, mixed> $dayRow */
    public static function isLocked(array $dayRow, ?DateTimeImmutable $now = null): bool
    {
        if ($now === null) {
            $now = new DateTimeImmutable('now', new DateTimeZone(SubscriptionScheduleGenerator::TZ));
        }
        $cutoffRaw = $dayRow['selection_cutoff_at'] ?? null;
        if (!is_string($cutoffRaw) || $cutoffRaw === '') {
            return false;
        }
        $cutoff = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $cutoffRaw, new DateTimeZone(SubscriptionScheduleGenerator::TZ));
        if (!$cutoff) {
            return false;
        }

        return $now >= $cutoff;
    }

    public static function cutoffHoursFromSnapshot(array $snapshot): int
    {
        return max(1, (int) ($snapshot['selection_cutoff_hours'] ?? 18));
    }
}
