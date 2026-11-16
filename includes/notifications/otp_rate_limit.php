<?php

declare(strict_types=1);

/**
 * Rolling-window OTP request/SMS limits (mobile + IP + daily + global).
 * Counts surviving rows in customer_otp_attempts — a row exists only while the
 * OTP reservation is open or after a successful send (failed sends delete it).
 * Play Review throttle-sentinel rows are never counted: they are not SMS sends.
 */
final class OtpRateLimit
{
    public static function mobileMax(): int
    {
        return OtpSecuritySettings::mobileLimit();
    }

    public static function ipMax(): int
    {
        return OtpSecuritySettings::ipLimit();
    }

    public static function assertWithinLimits(string $mobile, ?string $ip = null): void
    {
        $block = self::activeBlock($mobile, $ip);
        if ($block === null) {
            return;
        }
        throw new OtpRequestException(
            OtpSmsService::ERR_RATE_LIMITED,
            $block['message'],
            $block['remaining_seconds']
        );
    }

    /**
     * @return array{scope: string, remaining_seconds: int, message: string}|null
     */
    public static function activeBlock(string $mobile, ?string $ip = null): ?array
    {
        $mobile = Validation::normalizeMobileIndia($mobile) ?? '';
        if ($mobile === '') {
            return null;
        }
        $mobileBlock = self::windowBlock(
            'mobile = ?',
            [$mobile],
            self::mobileMax(),
            OtpSecuritySettings::MOBILE_WINDOW_SECONDS,
            'mobile'
        );
        if ($mobileBlock !== null) {
            return $mobileBlock;
        }
        $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            return null;
        }

        return self::windowBlock(
            'ip_address = ?',
            [$ip],
            self::ipMax(),
            OtpSecuritySettings::IP_WINDOW_SECONDS,
            'ip'
        );
    }

    /** Daily per-mobile successful-send limit. Call inside the send guard lock. */
    public static function assertDailyMobileWithinLimit(string $mobile): void
    {
        $block = self::dailyMobileBlock($mobile);
        if ($block === null) {
            return;
        }
        throw new OtpRequestException(
            OtpSmsService::ERR_RATE_LIMITED,
            $block['message'],
            $block['remaining_seconds']
        );
    }

    /**
     * @return array{scope: string, remaining_seconds: int, message: string}|null
     */
    public static function dailyMobileBlock(string $mobile): ?array
    {
        $mobile = Validation::normalizeMobileIndia($mobile) ?? '';
        if ($mobile === '') {
            return null;
        }

        return self::windowBlock(
            'mobile = ?',
            [$mobile],
            OtpSecuritySettings::dailyMobileLimit(),
            OtpSecuritySettings::DAILY_MOBILE_WINDOW_SECONDS,
            'mobile_daily'
        );
    }

    /** Global successful-send ceiling across all mobiles. 0 in settings disables it. */
    public static function assertGlobalWithinLimit(): void
    {
        $block = self::globalBlock();
        if ($block === null) {
            return;
        }
        throw new OtpRequestException(
            OtpSmsService::ERR_RATE_LIMITED,
            $block['message'],
            $block['remaining_seconds']
        );
    }

    /**
     * @return array{scope: string, remaining_seconds: int, message: string}|null
     */
    public static function globalBlock(): ?array
    {
        $max = OtpSecuritySettings::globalHourlyLimit();
        if ($max <= 0) {
            return null;
        }

        return self::windowBlock(
            '1=1',
            [],
            $max,
            OtpSecuritySettings::GLOBAL_WINDOW_SECONDS,
            'global'
        );
    }

    /**
     * @param list<string> $params
     * @return array{scope: string, remaining_seconds: int, message: string}|null
     */
    private static function windowBlock(string $whereCol, array $params, int $max, int $windowSec, string $scope): ?array
    {
        if ($max <= 0) {
            return null;
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS cnt,
                    MIN(created_at) AS oldest
             FROM customer_otp_attempts
             WHERE {$whereCol}
               AND otp_hash <> ?
               AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)"
        );
        $bind = array_merge($params, [PlayReviewAccess::THROTTLE_OTP_HASH, $windowSec]);
        $stmt->execute($bind);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $cnt = (int) ($row['cnt'] ?? 0);
        if ($cnt < $max) {
            return null;
        }
        $oldest = $row['oldest'] ?? null;
        if (!is_string($oldest) || $oldest === '') {
            return [
                'scope' => $scope,
                'remaining_seconds' => $windowSec,
                'message' => self::formatMessage($scope, $windowSec),
            ];
        }
        $retryStmt = $pdo->prepare(
            'SELECT GREATEST(1, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(?, INTERVAL ? SECOND))) AS retry_sec'
        );
        $retryStmt->execute([$oldest, $windowSec]);
        $remaining = (int) ($retryStmt->fetchColumn() ?: $windowSec);
        $remaining = max(1, min($windowSec, $remaining));

        return [
            'scope' => $scope,
            'remaining_seconds' => $remaining,
            'message' => self::formatMessage($scope, $remaining),
        ];
    }

    public static function formatMessage(string $scope, int $remainingSeconds): string
    {
        if ($scope === 'mobile_daily') {
            return 'Too many OTPs sent to this number today. Please try again tomorrow.';
        }
        if ($scope === 'global') {
            return 'OTP service is busy right now. Please try again shortly.';
        }
        $wait = OtpResendCooldown::formatMmSs($remainingSeconds);
        if ($scope === 'mobile') {
            return 'Too many OTP requests for this number. Try again in ' . $wait . '.';
        }

        return 'Too many OTP requests from your network. Try again in ' . $wait . '.';
    }
}
