<?php

declare(strict_types=1);

/** Server-side OTP resend cooldown (session-scoped, tied to mobile). */
final class OtpResendCooldown
{
    public const SECONDS = 120;

    private const SESSION_KEY = 'otp_resend_cooldown';

    /** @var int|null override for automated tests */
    public static $secondsForTest;

    /** When true, cooldown checks/recording are skipped (rate-limit tests only). */
    public static $bypassForTests = false;

    public static function cooldownSeconds(): int
    {
        $n = self::$secondsForTest ?? self::SECONDS;

        return max(1, (int) $n);
    }

    private static function sessionActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    /** @return array{mobile: string, available_at: int}|null */
    private static function state(): ?array
    {
        if (!self::sessionActive()) {
            return null;
        }
        $s = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($s) || !isset($s['mobile'], $s['available_at'])) {
            return null;
        }
        $mobile = Validation::normalizeMobileIndia((string) $s['mobile']) ?? '';
        if ($mobile === '') {
            return null;
        }

        return ['mobile' => $mobile, 'available_at' => (int) $s['available_at']];
    }

    public static function remainingSeconds(string $mobile): int
    {
        $mobile = Validation::normalizeMobileIndia($mobile) ?? '';
        if ($mobile === '') {
            return 0;
        }
        $st = self::state();
        if ($st === null || $st['mobile'] !== $mobile) {
            return 0;
        }

        return max(0, $st['available_at'] - time());
    }

    public static function resendAvailableAt(string $mobile): ?int
    {
        $rem = self::remainingSeconds($mobile);

        return $rem > 0 ? time() + $rem : null;
    }

    public static function recordSuccessfulSend(string $mobile): void
    {
        if (self::$bypassForTests) {
            return;
        }
        $mobile = Validation::normalizeMobileIndia($mobile) ?? '';
        if ($mobile === '') {
            return;
        }
        if (!self::sessionActive()) {
            return;
        }
        $_SESSION[self::SESSION_KEY] = [
            'mobile' => $mobile,
            'available_at' => time() + self::cooldownSeconds(),
        ];
    }

    public static function clear(): void
    {
        if (self::sessionActive()) {
            unset($_SESSION[self::SESSION_KEY]);
        }
    }

    public static function assertCanSend(string $mobile): void
    {
        if (self::$bypassForTests) {
            return;
        }
        $rem = self::remainingSeconds($mobile);
        if ($rem > 0) {
            throw new OtpRequestException(
                OtpSmsService::ERR_RESEND_COOLDOWN,
                self::formatWaitMessage($rem),
                $rem
            );
        }
    }

    public static function formatWaitMessage(int $seconds): string
    {
        return 'Please wait ' . self::formatMmSs($seconds) . ' before requesting another OTP.';
    }

    public static function formatResendLabel(int $seconds): string
    {
        return 'Resend OTP in ' . self::formatMmSs($seconds);
    }

    public static function formatMmSs(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
