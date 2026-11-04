<?php

declare(strict_types=1);

final class SubscriptionScheduleGenerator
{
    public const TZ = 'Asia/Kolkata';

    /**
     * @param array<string, mixed> $plan
     * @return array{ok: bool, dates?: list<string>, message?: string}
     */
    public static function generateDeliveryDates(array $plan, string $startDateYmd): array
    {
        $calendarDays = (int) ($plan['calendar_duration_days'] ?? 0);
        $deliveryCount = (int) ($plan['delivery_count'] ?? 0);
        if ($calendarDays < 1 || $deliveryCount < 1) {
            return ['ok' => false, 'message' => 'Calendar duration and delivery count must be at least 1.'];
        }

        $start = DateTimeImmutable::createFromFormat('Y-m-d', $startDateYmd, new DateTimeZone(self::TZ));
        if (!$start) {
            return ['ok' => false, 'message' => 'Invalid start date.'];
        }
        $windowEnd = $start->modify('+' . ($calendarDays - 1) . ' days');

        $frequency = (string) ($plan['frequency_type'] ?? 'daily');
        $config = is_array($plan['frequency_config'] ?? null)
            ? $plan['frequency_config']
            : self::decodeJson($plan['frequency_config'] ?? null);

        $candidates = self::candidateDates($start, $windowEnd, $frequency, $config);
        if (count($candidates) < $deliveryCount) {
            return [
                'ok' => false,
                'message' => 'Frequency cannot fit ' . $deliveryCount . ' deliveries within ' . $calendarDays . ' calendar days.',
            ];
        }

        $dates = array_slice($candidates, 0, $deliveryCount);

        return ['ok' => true, 'dates' => $dates];
    }

    /**
     * Next delivery date on plan frequency after $afterYmd, ignoring calendar window limits.
     *
     * @param array<string, mixed> $plan
     * @param list<string> $excludeYmd
     */
    public static function nextDeliveryDateAfter(array $plan, string $afterYmd, array $excludeYmd = []): ?string
    {
        $tz = new DateTimeZone(self::TZ);
        $cursor = DateTimeImmutable::createFromFormat('Y-m-d', $afterYmd, $tz);
        if (!$cursor) {
            return null;
        }
        $exclude = array_flip($excludeYmd);
        $frequency = (string) ($plan['frequency_type'] ?? 'daily');
        $config = is_array($plan['frequency_config'] ?? null)
            ? $plan['frequency_config']
            : self::decodeJson($plan['frequency_config'] ?? null);
        $planStart = (string) ($plan['_schedule_anchor_date'] ?? $afterYmd);
        $maxSteps = 800;
        for ($i = 0; $i < $maxSteps; $i++) {
            $ymd = $cursor->format('Y-m-d');
            if (self::isDeliveryDateOnFrequency($ymd, $planStart, $frequency, $config) && !isset($exclude[$ymd])) {
                return $ymd;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $config
     */
    public static function isDeliveryDateOnFrequency(
        string $dateYmd,
        string $planStartYmd,
        string $frequency,
        ?array $config
    ): bool {
        $tz = new DateTimeZone(self::TZ);
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $dateYmd, $tz);
        $start = DateTimeImmutable::createFromFormat('Y-m-d', $planStartYmd, $tz);
        if (!$date || !$start || $date < $start) {
            return false;
        }
        if ($frequency === 'daily') {
            return true;
        }
        if ($frequency === 'alternate') {
            $days = (int) $start->diff($date)->days;

            return $days % 2 === 0;
        }
        if ($frequency === 'weekdays') {
            $allowed = array_map('intval', (array) ($config['weekdays'] ?? [1, 2, 3, 4, 5, 6, 7]));
            $dow = (int) $date->format('N');

            return in_array($dow, $allowed, true);
        }
        if ($frequency === 'custom_interval') {
            $n = max(1, (int) ($config['interval_days'] ?? 1));
            $days = (int) $start->diff($date)->days;

            return $days % $n === 0;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $plan
     * @return array{ok: bool, errors?: list<string>}
     */
    public static function validatePlanSchedule(array $plan): array
    {
        $errors = [];
        $calendarDays = (int) ($plan['calendar_duration_days'] ?? 0);
        $deliveryCount = (int) ($plan['delivery_count'] ?? 0);
        if ($calendarDays < 1) {
            $errors[] = 'Calendar duration must be at least 1 day.';
        }
        if ($deliveryCount < 1) {
            $errors[] = 'Delivery count must be at least 1.';
        }
        $freq = (string) ($plan['frequency_type'] ?? '');
        if (!in_array($freq, ['daily', 'alternate', 'weekdays', 'custom_interval'], true)) {
            $errors[] = 'Invalid frequency type.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $sampleStart = (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->modify('+1 day')->format('Y-m-d');
        $gen = self::generateDeliveryDates($plan, $sampleStart);
        if (!$gen['ok']) {
            $errors[] = $gen['message'] ?? 'Schedule generation failed.';

            return ['ok' => false, 'errors' => $errors];
        }
        if (count($gen['dates'] ?? []) !== $deliveryCount) {
            $errors[] = 'Generated schedule count does not match delivery count.';

            return ['ok' => false, 'errors' => $errors];
        }

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed>|null $config
     * @return list<string> Y-m-d
     */
    private static function candidateDates(
        DateTimeImmutable $start,
        DateTimeImmutable $windowEnd,
        string $frequency,
        ?array $config
    ): array {
        $out = [];
        $current = $start;
        if ($frequency === 'daily') {
            while ($current <= $windowEnd) {
                $out[] = $current->format('Y-m-d');
                $current = $current->modify('+1 day');
            }

            return $out;
        }
        if ($frequency === 'alternate') {
            while ($current <= $windowEnd) {
                $out[] = $current->format('Y-m-d');
                $current = $current->modify('+2 days');
            }

            return $out;
        }
        if ($frequency === 'weekdays') {
            $allowed = array_map('intval', (array) ($config['weekdays'] ?? [1, 2, 3, 4, 5, 6, 7]));
            while ($current <= $windowEnd) {
                $dow = (int) $current->format('N');
                if (in_array($dow, $allowed, true)) {
                    $out[] = $current->format('Y-m-d');
                }
                $current = $current->modify('+1 day');
            }

            return $out;
        }
        if ($frequency === 'custom_interval') {
            $n = max(1, (int) ($config['interval_days'] ?? 1));
            while ($current <= $windowEnd) {
                $out[] = $current->format('Y-m-d');
                $current = $current->modify('+' . $n . ' days');
            }

            return $out;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private static function decodeJson($raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $d = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return is_array($d) ? $d : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
