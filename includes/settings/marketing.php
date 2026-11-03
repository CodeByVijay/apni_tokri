<?php

declare(strict_types=1);

/** Marketing & conversion settings (`marketing.*` AppSettings keys). */
final class MarketingSettings
{
    public const KEY_LOW_STOCK_ENABLED = 'marketing.low_stock_enabled';
    public const KEY_LOW_STOCK_THRESHOLD = 'marketing.low_stock_threshold_default';
    public const KEY_FREE_DELIVERY_THRESHOLD = 'marketing.free_delivery_threshold_inr';
    public const KEY_OFFER_COUNTDOWN_END = 'marketing.offer_countdown_end_at';

    public static function lowStockEnabled(): bool
    {
        return AppSettings::get(self::KEY_LOW_STOCK_ENABLED, '1') === '1';
    }

    public static function defaultLowStockThreshold(): int
    {
        $v = (int) (AppSettings::get(self::KEY_LOW_STOCK_THRESHOLD, '10') ?? '10');

        return max(0, min(9999, $v));
    }

    public static function freeDeliveryThresholdInr(): ?float
    {
        $raw = trim(AppSettings::get(self::KEY_FREE_DELIVERY_THRESHOLD, '') ?? '');
        if ($raw === '') {
            return null;
        }
        $v = round((float) $raw, 2);

        return $v > 0 ? $v : null;
    }

    /** @return DateTimeImmutable|null Asia/Kolkata instant when countdown is active */
    public static function offerCountdownEnd(): ?DateTimeImmutable
    {
        $raw = trim(AppSettings::get(self::KEY_OFFER_COUNTDOWN_END, '') ?? '');
        if ($raw === '') {
            return null;
        }
        try {
            $tz = new DateTimeZone(ReportDateRange::TIMEZONE);
            $dt = new DateTimeImmutable($raw, $tz);
        } catch (Throwable $e) {
            return null;
        }
        if ($dt <= new DateTimeImmutable('now', $tz)) {
            return null;
        }

        return $dt;
    }

    /** @return array{low_stock: bool, threshold: int, free_threshold: string, offer_end_local: string} */
    public static function adminFormState(): array
    {
        $offerLocal = '';
        $raw = trim(AppSettings::get(self::KEY_OFFER_COUNTDOWN_END, '') ?? '');
        if ($raw !== '') {
            try {
                $tz = new DateTimeZone(ReportDateRange::TIMEZONE);
                $dt = new DateTimeImmutable($raw, $tz);
                $offerLocal = $dt->format('Y-m-d\TH:i');
            } catch (Throwable $e) {
                $offerLocal = '';
            }
        }
        $free = AppSettings::get(self::KEY_FREE_DELIVERY_THRESHOLD, '499') ?? '499';

        return [
            'low_stock' => self::lowStockEnabled(),
            'threshold' => self::defaultLowStockThreshold(),
            'free_threshold' => $free !== '' ? (string) $free : '',
            'offer_end_local' => $offerLocal,
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message?: string, errors?: array<string, string>}
     */
    public static function saveFromPost(array $post): array
    {
        if (!Install::isSchemaReady()) {
            return ['ok' => false, 'message' => AdminPersistence::unavailableMessage()];
        }

        $errors = [];
        $lowStock = isset($post['low_stock']);
        $threshold = isset($post['threshold']) ? (int) $post['threshold'] : 10;
        if ($threshold < 0 || $threshold > 9999) {
            $errors['threshold'] = 'Threshold must be between 0 and 9999.';
        }
        $freeRaw = trim((string) ($post['free_threshold'] ?? ''));
        $freeVal = null;
        if ($freeRaw === '') {
            $errors['free_threshold'] = 'Enter a free delivery threshold amount.';
        } else {
            $freeVal = round((float) $freeRaw, 2);
            if ($freeVal <= 0 || $freeVal > 999999) {
                $errors['free_threshold'] = 'Enter a valid amount between 1 and 999999.';
            }
        }
        $offerEndIso = null;
        $offerLocal = trim((string) ($post['offer_end'] ?? ''));
        if ($offerLocal !== '') {
            try {
                $tz = new DateTimeZone(ReportDateRange::TIMEZONE);
                $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $offerLocal, $tz);
                if ($dt === false) {
                    $errors['offer_end'] = 'Enter a valid date and time.';
                } else {
                    $offerEndIso = $dt->format(DateTimeInterface::ATOM);
                }
            } catch (Throwable $e) {
                $errors['offer_end'] = 'Enter a valid date and time.';
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Fix the highlighted fields.', 'errors' => $errors];
        }

        AppSettings::set(self::KEY_LOW_STOCK_ENABLED, $lowStock ? '1' : '0');
        AppSettings::set(self::KEY_LOW_STOCK_THRESHOLD, (string) $threshold);
        AppSettings::set(self::KEY_FREE_DELIVERY_THRESHOLD, $freeVal !== null ? (string) $freeVal : null);
        AppSettings::set(self::KEY_OFFER_COUNTDOWN_END, $offerEndIso);

        return ['ok' => true, 'message' => 'Marketing settings saved.'];
    }
}
