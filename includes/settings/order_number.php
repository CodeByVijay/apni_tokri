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

    public const FORMAT_LEGACY_RANDOM = 'legacy_random';
    public const FORMAT_FY_SEQUENTIAL = 'fy_sequential';
    public const FORMAT_CY_SEQUENTIAL = 'cy_sequential';

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
        $f = trim(AppSettings::get(self::KEY_FORMAT, '') ?? '');
        if (in_array($f, [self::FORMAT_LEGACY_RANDOM, self::FORMAT_FY_SEQUENTIAL, self::FORMAT_CY_SEQUENTIAL], true)) {
            return $f;
        }

        return self::FORMAT_FY_SEQUENTIAL;
    }

    public static function startSequence(): int
    {
        $v = (int) trim(AppSettings::get(self::KEY_START_SEQUENCE, '') ?? '1');

        return max(1, $v);
    }

    public static function sequenceDigits(): int
    {
        $d = (int) trim(AppSettings::get(self::KEY_SEQUENCE_DIGITS, '') ?? (string) self::DEFAULT_SEQUENCE_DIGITS);

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
        $format = trim((string) ($post['format'] ?? self::FORMAT_FY_SEQUENTIAL));
        $start = trim((string) ($post['start_sequence'] ?? '1'));
        $digits = trim((string) ($post['sequence_digits'] ?? (string) self::DEFAULT_SEQUENCE_DIGITS));
        $reset = trim((string) ($post['sequence_reset'] ?? self::RESET_FISCAL_YEAR));

        if ($custom === '1') {
            if ($prefix === '') {
                $prefix = self::DEFAULT_PREFIX;
            }
            if (preg_match('/^[A-Z0-9]{1,8}$/', $prefix) !== 1) {
                $errors['prefix'] = 'Prefix must be 1–8 letters or numbers.';
            }
            if (!in_array($format, [self::FORMAT_LEGACY_RANDOM, self::FORMAT_FY_SEQUENTIAL, self::FORMAT_CY_SEQUENTIAL], true)) {
                $errors['format'] = 'Choose a valid format.';
            }
            if ($start === '' || preg_match('/^[0-9]{1,8}$/', $start) !== 1 || (int) $start < 1) {
                $errors['start_sequence'] = 'Starting sequence must be a positive number up to 8 digits.';
            }
            if ($digits === '' || preg_match('/^[0-9]{1,2}$/', $digits) !== 1) {
                $errors['sequence_digits'] = 'Sequence length must be 4–10.';
            } else {
                $d = (int) $digits;
                if ($d < 4 || $d > 10) {
                    $errors['sequence_digits'] = 'Sequence length must be between 4 and 10.';
                }
            }
            if (!in_array($reset, [self::RESET_NEVER, self::RESET_FISCAL_YEAR, self::RESET_CALENDAR_YEAR], true)) {
                $errors['sequence_reset'] = 'Choose a valid reset policy.';
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Fix the highlighted fields.', 'errors' => $errors];
        }

        AppSettings::set(self::KEY_CUSTOM_ENABLED, $custom);
        AppSettings::set(self::KEY_PREFIX, $custom === '1' && $prefix !== '' ? $prefix : null);
        if ($custom === '1') {
            AppSettings::set(self::KEY_FORMAT, $format);
            AppSettings::set(self::KEY_START_SEQUENCE, $start);
            AppSettings::set(self::KEY_SEQUENCE_DIGITS, $digits);
            AppSettings::set(self::KEY_SEQUENCE_RESET, $reset);
        }

        return ['ok' => true, 'message' => 'Order settings saved. Existing order numbers are unchanged.'];
    }
}
