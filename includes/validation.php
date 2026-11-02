<?php

declare(strict_types=1);

final class Validation
{
    public static function isNonEmptyString($value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    public static function mobileIndia($value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return (bool) preg_match('/^[6-9]\d{9}$/', $digits);
    }

    public static function normalizeMobileIndia(string $value): ?string
    {
        $value = trim($value, " \t\r\n");
        // Only digits and common phone separators are tolerated; letters/symbols mean a malformed request.
        if (preg_match('/[^0-9\s+\-().]/', $value) || (strpos($value, '+') !== false && strpos($value, '+') !== 0)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return null;
        }
        // Reject overflow before optional +91 handling — never truncate an 11+ digit local entry.
        if (strlen($digits) > 12) {
            return null;
        }
        if (strlen($digits) === 12) {
            if (substr($digits, 0, 2) !== '91') {
                return null;
            }
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11) {
            return null;
        }
        if (!preg_match('/^[6-9]\d{9}$/', $digits)) {
            return null;
        }

        return $digits;
    }

    public static function email($value): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function positiveInt($value): bool
    {
        return is_numeric($value) && (int) $value > 0 && (string) (int) $value === (string) $value;
    }

    public static function slug($value): bool
    {
        return is_string($value) && (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value);
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $requiredKeys
     * @return array<string, string> field => error message
     */
    public static function requiredFields(array $input, array $requiredKeys): array
    {
        $errors = [];
        foreach ($requiredKeys as $key) {
            if (!self::isNonEmptyString($input[$key] ?? null)) {
                $errors[$key] = 'This field is required.';
            }
        }

        return $errors;
    }
}
