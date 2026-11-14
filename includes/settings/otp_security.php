<?php

declare(strict_types=1);

/**
 * Configurable OTP & SMS abuse limits (Super Admin settings, VT-P-03).
 *
 * Values live in the existing settings store. Missing or invalid values always
 * fall back to safe defaults — an unreadable setting must never mean "unlimited".
 */
final class OtpSecuritySettings
{
    public const KEY_MOBILE_LIMIT = 'security.otp.mobile_limit';
    public const KEY_IP_LIMIT = 'security.otp.ip_limit';
    public const KEY_DAILY_MOBILE_LIMIT = 'security.otp.daily_mobile_limit';
    public const KEY_GLOBAL_HOURLY_LIMIT = 'security.otp.global_hourly_limit';
    public const KEY_RESEND_COOLDOWN_SECONDS = 'security.otp.resend_cooldown_seconds';

    public const DEFAULT_MOBILE_LIMIT = 3;
    public const DEFAULT_IP_LIMIT = 10;
    public const DEFAULT_DAILY_MOBILE_LIMIT = 6;
    public const DEFAULT_GLOBAL_HOURLY_LIMIT = 200;
    public const DEFAULT_RESEND_COOLDOWN_SECONDS = 120;

    public const MIN_MOBILE_LIMIT = 1;
    public const MAX_MOBILE_LIMIT = 10;
    public const MIN_IP_LIMIT = 1;
    public const MAX_IP_LIMIT = 100;
    public const MIN_DAILY_MOBILE_LIMIT = 1;
    public const MAX_DAILY_MOBILE_LIMIT = 50;
    public const MIN_GLOBAL_HOURLY_LIMIT = 0;
    public const MAX_GLOBAL_HOURLY_LIMIT = 10000;
    public const MIN_RESEND_COOLDOWN = 30;
    public const MAX_RESEND_COOLDOWN = 600;

    /** Fixed rolling windows (seconds) — only the counts are configurable. */
    public const MOBILE_WINDOW_SECONDS = 900;
    public const IP_WINDOW_SECONDS = 3600;
    public const DAILY_MOBILE_WINDOW_SECONDS = 86400;
    public const GLOBAL_WINDOW_SECONDS = 3600;

    /** @var list<int> */
    private const COOLDOWN_PRESETS = [60, 120, 180, 300];

    /** Stored value must sit inside [min,max]; anything else falls back to the default. */
    private static function intSetting(string $key, int $default, int $min, int $max): int
    {
        $raw = AppSettings::get($key, null);
        if ($raw === null || !is_string($raw) || !preg_match('/^-?\d+$/', trim($raw))) {
            return $default;
        }
        $value = (int) trim($raw);
        if ($value < $min || $value > $max) {
            return $default;
        }

        return $value;
    }

    public static function mobileLimit(): int
    {
        return self::intSetting(
            self::KEY_MOBILE_LIMIT,
            self::DEFAULT_MOBILE_LIMIT,
            self::MIN_MOBILE_LIMIT,
            self::MAX_MOBILE_LIMIT
        );
    }

    public static function ipLimit(): int
    {
        return self::intSetting(
            self::KEY_IP_LIMIT,
            self::DEFAULT_IP_LIMIT,
            self::MIN_IP_LIMIT,
            self::MAX_IP_LIMIT
        );
    }

    public static function dailyMobileLimit(): int
    {
        return self::intSetting(
            self::KEY_DAILY_MOBILE_LIMIT,
            self::DEFAULT_DAILY_MOBILE_LIMIT,
            self::MIN_DAILY_MOBILE_LIMIT,
            self::MAX_DAILY_MOBILE_LIMIT
        );
    }

    /** 0 means the global ceiling is disabled (explicit, admin-confirmed choice only). */
    public static function globalHourlyLimit(): int
    {
        return self::intSetting(
            self::KEY_GLOBAL_HOURLY_LIMIT,
            self::DEFAULT_GLOBAL_HOURLY_LIMIT,
            self::MIN_GLOBAL_HOURLY_LIMIT,
            self::MAX_GLOBAL_HOURLY_LIMIT
        );
    }

    public static function resendCooldownSeconds(): int
    {
        return self::intSetting(
            self::KEY_RESEND_COOLDOWN_SECONDS,
            self::DEFAULT_RESEND_COOLDOWN_SECONDS,
            self::MIN_RESEND_COOLDOWN,
            self::MAX_RESEND_COOLDOWN
        );
    }

    /** @return list<int> */
    public static function cooldownPresets(): array
    {
        return self::COOLDOWN_PRESETS;
    }

    /**
     * @return array{
     *   mobile_limit: int, ip_limit: int, daily_mobile_limit: int,
     *   global_hourly_limit: int, resend_cooldown_seconds: int,
     *   resend_cooldown_preset: string, cooldown_presets: list<int>,
     *   bounds: array<string, array{min: int, max: int}>
     * }
     */
    public static function adminFormState(): array
    {
        $cooldown = self::resendCooldownSeconds();

        return [
            'mobile_limit' => self::mobileLimit(),
            'ip_limit' => self::ipLimit(),
            'daily_mobile_limit' => self::dailyMobileLimit(),
            'global_hourly_limit' => self::globalHourlyLimit(),
            'resend_cooldown_seconds' => $cooldown,
            'resend_cooldown_preset' => in_array($cooldown, self::COOLDOWN_PRESETS, true)
                ? (string) $cooldown
                : 'custom',
            'cooldown_presets' => self::COOLDOWN_PRESETS,
            'bounds' => [
                'mobile_limit' => ['min' => self::MIN_MOBILE_LIMIT, 'max' => self::MAX_MOBILE_LIMIT],
                'ip_limit' => ['min' => self::MIN_IP_LIMIT, 'max' => self::MAX_IP_LIMIT],
                'daily_mobile_limit' => ['min' => self::MIN_DAILY_MOBILE_LIMIT, 'max' => self::MAX_DAILY_MOBILE_LIMIT],
                'global_hourly_limit' => ['min' => self::MIN_GLOBAL_HOURLY_LIMIT, 'max' => self::MAX_GLOBAL_HOURLY_LIMIT],
                'resend_cooldown_seconds' => ['min' => self::MIN_RESEND_COOLDOWN, 'max' => self::MAX_RESEND_COOLDOWN],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message?: string, errors?: array<string, string>}
     */
    public static function saveFromRequest(array $post): array
    {
        if (!AdminAuth::check() || !AdminRbacService::userHasSuperAdminRole((int) AdminAuth::id())) {
            return ['ok' => false, 'message' => 'Forbidden.'];
        }
        if (!Install::isSchemaReady()) {
            return ['ok' => false, 'message' => 'Database schema is not ready.'];
        }

        $errors = [];
        $mobileLimit = self::parseBoundedInt(
            $post['mobile_limit'] ?? null,
            self::MIN_MOBILE_LIMIT,
            self::MAX_MOBILE_LIMIT,
            'mobile_limit',
            'Requests per mobile per 15 minutes',
            $errors
        );
        $ipLimit = self::parseBoundedInt(
            $post['ip_limit'] ?? null,
            self::MIN_IP_LIMIT,
            self::MAX_IP_LIMIT,
            'ip_limit',
            'Requests per IP per hour',
            $errors
        );
        $dailyLimit = self::parseBoundedInt(
            $post['daily_mobile_limit'] ?? null,
            self::MIN_DAILY_MOBILE_LIMIT,
            self::MAX_DAILY_MOBILE_LIMIT,
            'daily_mobile_limit',
            'Successful SMS per mobile per 24 hours',
            $errors
        );
        $globalLimit = self::parseBoundedInt(
            $post['global_hourly_limit'] ?? null,
            self::MIN_GLOBAL_HOURLY_LIMIT,
            self::MAX_GLOBAL_HOURLY_LIMIT,
            'global_hourly_limit',
            'Global SMS per hour',
            $errors
        );

        if ($globalLimit === 0 && empty($post['global_limit_disable_confirm'])) {
            $errors['global_hourly_limit'] = 'Confirm that you understand disabling the global SMS ceiling removes the last-resort spend cap.';
        }

        $preset = trim((string) ($post['resend_cooldown_preset'] ?? ''));
        if (in_array($preset, array_map('strval', self::COOLDOWN_PRESETS), true)) {
            $cooldown = (int) $preset;
        } elseif ($preset === 'custom') {
            $cooldown = self::parseBoundedInt(
                $post['resend_cooldown_seconds'] ?? null,
                self::MIN_RESEND_COOLDOWN,
                self::MAX_RESEND_COOLDOWN,
                'resend_cooldown_seconds',
                'Resend cooldown',
                $errors
            );
        } else {
            $cooldown = null;
            $errors['resend_cooldown_preset'] = 'Choose a resend cooldown duration.';
        }

        if ($errors !== []) {
            return [
                'ok' => false,
                'errors' => $errors,
                'message' => 'Please correct the highlighted fields.',
            ];
        }

        $newValues = [
            self::KEY_MOBILE_LIMIT => (string) $mobileLimit,
            self::KEY_IP_LIMIT => (string) $ipLimit,
            self::KEY_DAILY_MOBILE_LIMIT => (string) $dailyLimit,
            self::KEY_GLOBAL_HOURLY_LIMIT => (string) $globalLimit,
            self::KEY_RESEND_COOLDOWN_SECONDS => (string) $cooldown,
        ];

        foreach ($newValues as $key => $newValue) {
            $oldValue = AppSettings::get($key, null);
            $oldDisplay = $oldValue === null ? '(default)' : $oldValue;
            if ($oldValue === $newValue) {
                continue;
            }
            AppSettings::set($key, $newValue);
            AuditLog::write('settings.otp_security', 'settings', $key, [
                'old' => $oldDisplay,
                'new' => $newValue,
            ]);
        }

        return ['ok' => true, 'message' => 'OTP & SMS security settings saved.'];
    }

    /**
     * @param array<string, string> $errors
     */
    private static function parseBoundedInt(
        $raw,
        int $min,
        int $max,
        string $errorKey,
        string $label,
        array &$errors
    ): ?int {
        $value = is_string($raw) ? trim($raw) : (is_int($raw) ? (string) $raw : '');
        if ($value === '' || !preg_match('/^-?\d+$/', $value)) {
            $errors[$errorKey] = $label . ': enter a whole number.';

            return null;
        }
        $int = (int) $value;
        if ($int < $min || $int > $max) {
            $errors[$errorKey] = $label . ': enter a value between ' . $min . ' and ' . $max . '.';

            return null;
        }

        return $int;
    }
}
