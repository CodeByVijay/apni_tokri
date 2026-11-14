<?php

declare(strict_types=1);

/**
 * Cross-mobile serialization for OTP SMS sends (VT-P-03).
 *
 * The global advisory lock makes the "count rows in window" checks and the
 * reservation INSERT into customer_otp_attempts atomic with respect to every
 * other OTP sender: a surviving row is the reservation; it is finalized when
 * the provider accepts the SMS and released when the row is deleted on failure.
 * The lock is held for milliseconds only — never across the external SMS call.
 */
final class OtpSendGuard
{
    public const GLOBAL_LOCK_NAME = 'at_otp_sms_global';
    public const GLOBAL_LOCK_TIMEOUT_SECONDS = 5;

    /** @throws OtpRequestException when the lock cannot be acquired */
    public static function acquireGlobalLock(PDO $pdo): void
    {
        $stmt = $pdo->query('SELECT GET_LOCK(' . $pdo->quote(self::GLOBAL_LOCK_NAME) . ', ' . self::GLOBAL_LOCK_TIMEOUT_SECONDS . ')');
        $got = (int) ($stmt ? $stmt->fetchColumn() : 0);
        if ($got !== 1) {
            throw new OtpRequestException(OtpSmsService::ERR_SERVER_ERROR);
        }
    }

    public static function releaseGlobalLock(PDO $pdo): void
    {
        try {
            $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote(self::GLOBAL_LOCK_NAME) . ')');
        } catch (Throwable $e) {
            error_log('OtpSendGuard::releaseGlobalLock: ' . $e->getMessage());
        }
    }

    /**
     * Every window check that must be evaluated under the global lock, in the
     * order required before a reservation row may be inserted.
     *
     * @throws OtpRequestException
     */
    public static function assertAllLimitsUnderLock(string $mobile, ?string $ip = null): void
    {
        OtpRateLimit::assertWithinLimits($mobile, $ip);
        OtpResendCooldown::assertCanSend($mobile);
        OtpRateLimit::assertDailyMobileWithinLimit($mobile);
        OtpRateLimit::assertGlobalWithinLimit();
    }
}
