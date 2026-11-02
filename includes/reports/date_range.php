<?php

declare(strict_types=1);

/**
 * Report date filters in Asia/Kolkata (business calendar).
 */
final class ReportDateRange
{
    public const TIMEZONE = 'Asia/Kolkata';
    public const MAX_CUSTOM_DAYS = 366;

    /** @var list<string> */
    public const PRESETS = ['today', 'yesterday', 'last7', 'last30', 'this_month', 'custom'];

    private $preset;
    private $startUtc;
    private $endUtc;
    private $label;
    private $customFrom = '';
    private $customTo = '';

    private function __construct(string $preset, DateTimeImmutable $start, DateTimeImmutable $end, string $label, string $customFrom = '', string $customTo = '')
    {
        $this->preset = $preset;
        $this->startUtc = $start;
        $this->endUtc = $end;
        $this->label = $label;
    }

    /**
     * @param array<string, mixed> $input preset, from, to
     * @return array{ok: true, range: self}|array{ok: false, message: string}
     */
    public static function fromRequest(array $input): array
    {
        $preset = trim((string) ($input['preset'] ?? 'last7'));
        if (!in_array($preset, self::PRESETS, true)) {
            return ['ok' => false, 'message' => 'Invalid date preset.'];
        }

        $tz = new DateTimeZone(self::TIMEZONE);
        $now = new DateTimeImmutable('now', $tz);

        if ($preset === 'custom') {
            $fromStr = trim((string) ($input['from'] ?? ''));
            $toStr = trim((string) ($input['to'] ?? ''));
            if ($fromStr === '' || $toStr === '') {
                return ['ok' => false, 'message' => 'Custom range requires from and to dates.'];
            }
            $from = DateTimeImmutable::createFromFormat('Y-m-d', $fromStr, $tz);
            $to = DateTimeImmutable::createFromFormat('Y-m-d', $toStr, $tz);
            if (!$from || !$to || $from->format('Y-m-d') !== $fromStr || $to->format('Y-m-d') !== $toStr) {
                return ['ok' => false, 'message' => 'Dates must be YYYY-MM-DD.'];
            }
            if ($from > $to) {
                return ['ok' => false, 'message' => 'Start date must be on or before end date.'];
            }
            $days = (int) $from->diff($to)->days + 1;
            if ($days > self::MAX_CUSTOM_DAYS) {
                return ['ok' => false, 'message' => 'Custom range cannot exceed ' . self::MAX_CUSTOM_DAYS . ' days.'];
            }
            $start = $from->setTime(0, 0, 0);
            $end = $to->modify('+1 day')->setTime(0, 0, 0);
            $label = $fromStr . ' to ' . $toStr;

            return ['ok' => true, 'range' => new self($preset, $start, $end, $label, $fromStr, $toStr)];
        }

        if ($preset === 'today') {
            $start = $now->setTime(0, 0, 0);
            $end = $start->modify('+1 day');
            $label = 'Today';
        } elseif ($preset === 'yesterday') {
            $start = $now->modify('-1 day')->setTime(0, 0, 0);
            $end = $start->modify('+1 day');
            $label = 'Yesterday';
        } elseif ($preset === 'last7') {
            $end = $now->modify('+1 day')->setTime(0, 0, 0);
            $start = $now->modify('-6 days')->setTime(0, 0, 0);
            $label = 'Last 7 days';
        } elseif ($preset === 'last30') {
            $end = $now->modify('+1 day')->setTime(0, 0, 0);
            $start = $now->modify('-29 days')->setTime(0, 0, 0);
            $label = 'Last 30 days';
        } else {
            $start = $now->modify('first day of this month')->setTime(0, 0, 0);
            $end = $start->modify('first day of next month');
            $label = 'This month';
        }

        return ['ok' => true, 'range' => new self($preset, $start, $end, $label)];
    }

    public function preset(): string
    {
        return $this->preset;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function customFrom(): string
    {
        return $this->customFrom;
    }

    public function customTo(): string
    {
        return $this->customTo;
    }

    /** Inclusive start, exclusive end (for SQL >= start AND < end). */
    public function sqlStart(): string
    {
        return $this->startUtc->format('Y-m-d H:i:s');
    }

    public function sqlEnd(): string
    {
        return $this->endUtc->format('Y-m-d H:i:s');
    }

    /** Inclusive calendar start (Y-m-d) in business timezone. */
    public function inclusiveStartYmd(): string
    {
        return $this->startUtc->format('Y-m-d');
    }

    /** Inclusive calendar end (Y-m-d) in business timezone. */
    public function inclusiveEndYmd(): string
    {
        return $this->endUtc->modify('-1 day')->format('Y-m-d');
    }

    /** SQL expression for order business timestamp (documented in docs/reports-calculation-rules.md). */
    public static function orderBusinessTimestampSql(): string
    {
        return 'COALESCE(o.placed_at, o.created_at)';
    }
}
