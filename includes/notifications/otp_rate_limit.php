<?php

declare(strict_types=1);

/** Rolling-window OTP request limits (mobile + IP). Counts rows in customer_otp_attempts only. */
final class OtpRateLimit
{
    public const MOBILE_MAX = 3;
    public const MOBILE_WINDOW_SECONDS = 900;
    public const IP_MAX = 10;
    public const IP_WINDOW_SECONDS = 3600;

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
            self::MOBILE_MAX,
            self::MOBILE_WINDOW_SECONDS,
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
            self::IP_MAX,
            self::IP_WINDOW_SECONDS,
            'ip'
        );
    }

    /**
     * @param list<string> $params
     * @return array{scope: string, remaining_seconds: int, message: string}|null
     */
    private static function windowBlock(string $whereCol, array $params, int $max, int $windowSec, string $scope): ?array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS cnt,
                    MIN(created_at) AS oldest
             FROM customer_otp_attempts
             WHERE {$whereCol}
               AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)"
        );
        $bind = array_merge($params, [$windowSec]);
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
        $wait = OtpResendCooldown::formatMmSs($remainingSeconds);
        if ($scope === 'mobile') {
            return 'Too many OTP requests for this number. Try again in ' . $wait . '.';
        }

        return 'Too many OTP requests from your network. Try again in ' . $wait . '.';
    }
}
