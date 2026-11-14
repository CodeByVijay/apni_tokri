<?php

declare(strict_types=1);

/**
 * Server-side OTP resend cooldown.
 *
 * The database is authoritative: the latest surviving customer_otp_attempts row
 * for the mobile marks the last successful SMS send, so the cooldown survives
 * session rotation. Failed sends delete their row and never start a cooldown.
 * The session value is retained only as a UI hint (pre-DB installs, demo mode).
 */
final class OtpResendCooldown
{
    private const SESSION_KEY = 'otp_resend_cooldown';

    /** @var int|null override for automated tests */
    public static $secondsForTest;

    /** When true, cooldown checks/recording are skipped (rate-limit tests only). */
    public static $bypassForTests = false;

    public static function cooldownSeconds(): int
    {
        $n = self::$secondsForTest ?? OtpSecuritySettings::resendCooldownSeconds();

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

    /** Session-hint remaining seconds (UI only — never trusted for enforcement). */
    private static function sessionRemainingSeconds(string $mobile): int
    {
        $st = self::state();
        if ($st === null || $st['mobile'] !== $mobile) {
            return 0;
        }

        return max(0, $st['available_at'] - time());
    }

    /**
     * Remaining cooldown derived from the last successful send recorded in
     * customer_otp_attempts (sentinel rows excluded). Authoritative source.
     */
    public static function dbRemainingSeconds(string $mobile): int
    {
        $mobile = Validation::normalizeMobileIndia($mobile) ?? '';
        if ($mobile === '' || !Install::isSchemaReady()) {
            return 0;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MAX(created_at), INTERVAL ? SECOND)))
                 FROM customer_otp_attempts
                 WHERE mobile = ? AND otp_hash <> ?'
            );
            $stmt->execute([self::cooldownSeconds(), $mobile, PlayReviewAccess::THROTTLE_OTP_HASH]);
            $remaining = $stmt->fetchColumn();

            return $remaining === false || $remaining === null ? 0 : max(0, (int) $remaining);
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function remainingSeconds(string $mobile): int
    {
        $mobile = Validation::normalizeMobileIndia($mobile) ?? '';
        if ($mobile === '') {
            return 0;
        }

        return max(self::dbRemainingSeconds($mobile), self::sessionRemainingSeconds($mobile));
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

    /**
     * @param bool $dbBacked false skips the database check (Play Review fast path
     *                       sends no SMS and writes no attempt rows).
     */
    public static function assertCanSend(string $mobile, bool $dbBacked = true): void
    {
        if (self::$bypassForTests) {
            return;
        }
        $mobile = Validation::normalizeMobileIndia($mobile) ?? '';
        $rem = $dbBacked
            ? self::remainingSeconds($mobile)
            : self::sessionRemainingSeconds($mobile);
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
