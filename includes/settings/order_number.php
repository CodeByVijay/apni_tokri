<?php

declare(strict_types=1);

final class OrderNumberSettings
{
    public const KEY_CUSTOM_ENABLED = 'order.custom_format_enabled';
    public const KEY_PREFIX = 'order.prefix';
    public const KEY_FORMAT = 'order.format';
    public const KEY_START_SEQUENCE = 'order.start_sequence';
    public const KEY_SEQUENCE_DIGITS = 'order.sequence_digits';
    public const KEY_SEQUENCE_RESET = 'order.sequence_reset';

    public const FORMAT_DATE_RANDOM = 'date_random';
    public const FORMAT_FY_SEQUENCE = 'fy_sequence';
    public const FORMAT_CY_SEQUENCE = 'cy_sequence';

    /** @deprecated Use FORMAT_DATE_RANDOM */
    public const FORMAT_LEGACY_RANDOM = 'date_random';
    /** @deprecated Use FORMAT_FY_SEQUENCE */
    public const FORMAT_FY_SEQUENTIAL = 'fy_sequence';
    /** @deprecated Use FORMAT_CY_SEQUENCE */
    public const FORMAT_CY_SEQUENTIAL = 'cy_sequence';

    public const RESET_NEVER = 'never';
    public const RESET_FISCAL_YEAR = 'fiscal_year';
    public const RESET_CALENDAR_YEAR = 'calendar_year';

    public const DEFAULT_PREFIX = 'AT';
    public const DEFAULT_SEQUENCE_DIGITS = 6;

    public static function customFormatEnabled(): bool
    {
        return (AppSettings::get(self::KEY_CUSTOM_ENABLED, '') ?? '') === '1';
    }

    public static function prefix(): string
    {
        $p = strtoupper(trim(AppSettings::get(self::KEY_PREFIX, '') ?? ''));

        return $p !== '' ? $p : self::DEFAULT_PREFIX;
    }

    public static function format(): string
    {
        $f = self::normalizeFormat(trim(AppSettings::get(self::KEY_FORMAT, '') ?? ''));
        if (in_array($f, [self::FORMAT_DATE_RANDOM, self::FORMAT_FY_SEQUENCE, self::FORMAT_CY_SEQUENCE], true)) {
            return $f;
        }

        return self::FORMAT_FY_SEQUENCE;
    }

    public static function isSequentialFormat(string $format): bool
    {
        $f = self::normalizeFormat($format);

        return $f === self::FORMAT_FY_SEQUENCE || $f === self::FORMAT_CY_SEQUENCE;
    }

    public static function normalizeFormat(string $format): string
    {
        $format = trim($format);
        $legacy = [
            'legacy_random' => self::FORMAT_DATE_RANDOM,
            'fy_sequential' => self::FORMAT_FY_SEQUENCE,
            'cy_sequential' => self::FORMAT_CY_SEQUENCE,
        ];

        return $legacy[$format] ?? $format;
    }

    public static function startSequence(): int
    {
        $v = (int) trim(AppSettings::get(self::KEY_START_SEQUENCE, '') ?? '1');

        return max(1, $v);
    }

    public static function sequenceDigits(): int
    {
        return self::clampSequenceDigits(trim(AppSettings::get(self::KEY_SEQUENCE_DIGITS, '') ?? ''));
    }

    public static function clampSequenceDigits(string $raw): int
    {
        $d = (int) trim($raw);

        return max(4, min(10, $d > 0 ? $d : self::DEFAULT_SEQUENCE_DIGITS));
    }

    public static function sequenceReset(): string
    {
        $r = trim(AppSettings::get(self::KEY_SEQUENCE_RESET, '') ?? '');
        if (in_array($r, [self::RESET_NEVER, self::RESET_FISCAL_YEAR, self::RESET_CALENDAR_YEAR], true)) {
            return $r;
        }

        return self::RESET_FISCAL_YEAR;
    }

    /** @return array<string, string> */
    public static function adminFormState(): array
    {
        return [
            'custom_format_enabled' => self::customFormatEnabled() ? '1' : '0',
            'prefix' => trim(AppSettings::get(self::KEY_PREFIX, '') ?? '') !== '' ? self::prefix() : '',
            'format' => self::format(),
            'start_sequence' => (string) self::startSequence(),
            'sequence_digits' => (string) self::sequenceDigits(),
            'sequence_reset' => self::sequenceReset(),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message?: string, errors?: array<string, string>}
     */
    public static function saveFromPost(array $post): array
    {
        if (!Install::isSchemaReady()) {
            return ['ok' => false, 'message' => 'Database schema not ready.'];
        }

        $errors = [];
        $custom = !empty($post['custom_format_enabled']) ? '1' : '0';
        $prefix = strtoupper(trim((string) ($post['prefix'] ?? '')));
        $format = self::normalizeFormat(trim((string) ($post['format'] ?? self::FORMAT_FY_SEQUENCE)));
        $sequential = self::isSequentialFormat($format);
        $start = trim((string) ($post['start_sequence'] ?? ''));
        $digits = trim((string) ($post['sequence_digits'] ?? ''));
        $reset = trim((string) ($post['sequence_reset'] ?? ''));

        if ($custom === '1') {
            if ($prefix === '') {
                $prefix = self::DEFAULT_PREFIX;
            }
            if (preg_match('/^[A-Z0-9]{1,8}$/', $prefix) !== 1) {
                $errors['prefix'] = 'Prefix must be 1–8 letters or numbers.';
            }
            if (!in_array($format, [self::FORMAT_DATE_RANDOM, self::FORMAT_FY_SEQUENCE, self::FORMAT_CY_SEQUENCE], true)) {
                $errors['format'] = 'Choose a valid format.';
            }
            if ($sequential) {
                if ($start === '' || preg_match('/^[1-9][0-9]{0,7}$/', $start) !== 1) {
                    $errors['start_sequence'] = 'Starting sequence must be a positive whole number (up to 8 digits).';
                }
                if ($digits === '' || preg_match('/^[0-9]{1,2}$/', $digits) !== 1) {
                    $errors['sequence_digits'] = 'Sequence length must be 4–10.';
                } else {
                    $d = (int) $digits;
                    if ($d < 4 || $d > 10) {
                        $errors['sequence_digits'] = 'Sequence length must be between 4 and 10.';
                    }
                }
                if ($reset === '') {
                    $reset = $format === self::FORMAT_CY_SEQUENCE
                        ? self::RESET_CALENDAR_YEAR
                        : self::RESET_FISCAL_YEAR;
                }
                if (!in_array($reset, [self::RESET_NEVER, self::RESET_FISCAL_YEAR, self::RESET_CALENDAR_YEAR], true)) {
                    $errors['sequence_reset'] = 'Choose a valid reset policy.';
                }
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Fix the highlighted fields.', 'errors' => $errors];
        }

        AppSettings::set(self::KEY_CUSTOM_ENABLED, $custom);
        AppSettings::set(self::KEY_PREFIX, $custom === '1' && $prefix !== '' ? $prefix : null);
        if ($custom === '1') {
            AppSettings::set(self::KEY_FORMAT, $format);
            if ($sequential) {
                AppSettings::set(self::KEY_START_SEQUENCE, $start);
                AppSettings::set(self::KEY_SEQUENCE_DIGITS, (string) self::clampSequenceDigits($digits));
                AppSettings::set(self::KEY_SEQUENCE_RESET, $reset);
            }
        }

        return ['ok' => true, 'message' => 'Order settings saved. Existing order numbers are unchanged.'];
    }
}
