<?php

declare(strict_types=1);

/**
 * Central order_number allocation (storefront checkout, subscription fulfilment).
 * Must be called inside an active DB transaction when using sequential formats.
 */
final class OrderNumberService
{
    /**
     * @param 'storefront'|'subscription' $series
     */
    public static function allocate(PDO $pdo, string $series = 'storefront'): string
    {
        if (!OrderNumberSettings::customFormatEnabled()) {
            return self::legacyRandom($series);
        }

        $format = OrderNumberSettings::format();
        if ($format === OrderNumberSettings::FORMAT_LEGACY_RANDOM) {
            return self::legacyRandomConfigured($series);
        }

        return self::allocateSequential($pdo, $series);
    }

    /**
     * Preview next number for admin UI (read-only peek).
     *
     * @param 'storefront'|'subscription' $series
     */
    public static function preview(string $series = 'storefront', ?PDO $pdo = null): string
    {
        if (!OrderNumberSettings::customFormatEnabled()) {
            if ($series === 'subscription') {
                return 'AT-SUB-' . date('ymd') . '-XXXXXX';
            }

            return 'AT-' . date('ymd') . '-XXXXXX';
        }

        $format = OrderNumberSettings::format();
        if ($format === OrderNumberSettings::FORMAT_LEGACY_RANDOM) {
            return self::legacyRandomConfigured($series, true);
        }

        $pdo = $pdo ?? Database::pdo();
        $next = self::peekNextSequence($pdo, $series);

        return self::formatSequential($next);
    }

    /**
     * @param 'storefront'|'subscription' $series
     */
    private static function legacyRandom(string $series): string
    {
        $ymd = date('ymd');
        $rand = strtoupper(substr(Security::randomToken(4), 0, 6));
        if ($series === 'subscription') {
            return 'AT-SUB-' . $ymd . '-' . $rand;
        }

        return 'AT-' . $ymd . '-' . $rand;
    }

    /**
     * @param 'storefront'|'subscription' $series
     */
    private static function legacyRandomConfigured(string $series, bool $maskRandom = false): string
    {
        $prefix = OrderNumberSettings::prefix();
        $ymd = date('ymd');
        $rand = $maskRandom ? 'XXXXXX' : strtoupper(substr(Security::randomToken(4), 0, 6));
        if ($series === 'subscription') {
            return $prefix . '-SUB-' . $ymd . '-' . $rand;
        }

        return $prefix . '-' . $ymd . '-' . $rand;
    }

    /**
     * @param 'storefront'|'subscription' $series
     */
    private static function allocateSequential(PDO $pdo, string $series): string
    {
        $prefix = OrderNumberSettings::prefix();
        $period = self::periodKey(OrderNumberSettings::sequenceReset());
        $start = OrderNumberSettings::startSequence();

        $pdo->prepare(
            'INSERT INTO order_sequences (prefix, period_key, series, last_number) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE last_number = last_number'
        )->execute([$prefix, $period, $series, $start - 1]);

        $sel = $pdo->prepare('SELECT last_number FROM order_sequences WHERE prefix = ? AND period_key = ? AND series = ? FOR UPDATE');
        $sel->execute([$prefix, $period, $series]);
        $next = (int) $sel->fetchColumn() + 1;
        $pdo->prepare('UPDATE order_sequences SET last_number = ? WHERE prefix = ? AND period_key = ? AND series = ?')
            ->execute([$next, $prefix, $period, $series]);

        return self::formatSequential($next);
    }

    /**
     * @param 'storefront'|'subscription' $series
     */
    private static function peekNextSequence(PDO $pdo, string $series): int
    {
        $prefix = OrderNumberSettings::prefix();
        $period = self::periodKey(OrderNumberSettings::sequenceReset());
        $start = OrderNumberSettings::startSequence();
        $stmt = $pdo->prepare('SELECT last_number FROM order_sequences WHERE prefix = ? AND period_key = ? AND series = ? LIMIT 1');
        $stmt->execute([$prefix, $period, $series]);
        $cur = $stmt->fetchColumn();
        if ($cur === false) {
            return $start;
        }

        return (int) $cur + 1;
    }

    private static function formatSequential(int $sequence): string
    {
        $prefix = OrderNumberSettings::prefix();
        $format = OrderNumberSettings::format();
        $digits = OrderNumberSettings::sequenceDigits();
        $seq = str_pad((string) $sequence, $digits, '0', STR_PAD_LEFT);
        if ($format === OrderNumberSettings::FORMAT_CY_SEQUENTIAL) {
            return $prefix . '-' . date('Y') . '-' . $seq;
        }

        return $prefix . '-' . InvoiceFormat::financialYear(date('Y-m-d')) . '-' . $seq;
    }

    public static function periodKey(string $reset): string
    {
        if ($reset === OrderNumberSettings::RESET_CALENDAR_YEAR) {
            return date('Y');
        }
        if ($reset === OrderNumberSettings::RESET_FISCAL_YEAR) {
            return InvoiceFormat::financialYear(date('Y-m-d'));
        }

        return 'ALL';
    }
}
