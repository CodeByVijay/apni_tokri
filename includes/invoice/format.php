<?php

declare(strict_types=1);

final class InvoiceFormat
{
    /** Indian digit grouping: 1234567.5 => 12,34,567.50 */
    public static function inr(float $amount): string
    {
        $neg = $amount < 0;
        $amount = abs(round($amount, 2));
        $whole = (string) (int) floor($amount);
        $dec = sprintf('%02d', (int) round(($amount - floor($amount)) * 100));
        if ($dec === '100') {
            $dec = '00';
            $whole = (string) ((int) $whole + 1);
        }
        if (strlen($whole) > 3) {
            $last3 = substr($whole, -3);
            $rest = substr($whole, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) ?? $rest;
            $whole = $rest . ',' . $last3;
        }

        return ($neg ? '-' : '') . $whole . '.' . $dec;
    }

    public static function money(float $amount, string $symbol = 'Rs. '): string
    {
        return $symbol . self::inr($amount);
    }

    public static function date(?string $mysql, string $fmt = 'j M Y'): string
    {
        if ($mysql === null || trim($mysql) === '') {
            return '';
        }
        $ts = strtotime($mysql);

        return $ts !== false ? date($fmt, $ts) : '';
    }

    public static function dateTime(?string $mysql): string
    {
        return self::date($mysql, 'j M Y, g:i A');
    }

    /** Indian financial year (Apr–Mar) label, e.g. 2026-27, for a Y-m-d date. */
    public static function financialYear(string $ymd): string
    {
        $ts = strtotime($ymd);
        if ($ts === false) {
            throw new InvalidArgumentException('Invalid date');
        }
        $y = (int) date('Y', $ts);
        $m = (int) date('n', $ts);
        $start = $m >= 4 ? $y : $y - 1;

        return $start . '-' . substr((string) ($start + 1), -2);
    }
}
