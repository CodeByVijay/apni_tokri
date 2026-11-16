<?php

declare(strict_types=1);

/**
 * Admin login throttling (AT-SEC-002). Requires migration 1.21.0 when tables are present.
 */
final class AdminLoginSecurity
{
    public static function tablesReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'admin_login_attempts'"
            );

            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function authEpochColumnReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND column_name = 'auth_epoch'"
            );

            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return array<string, int> */
    private static function config(): array
    {
        $cfg = (array) (App::config()['security']['admin_login'] ?? []);

        return [
            'max_failures_email' => max(1, (int) ($cfg['max_failures_email'] ?? 5)),
            'max_failures_ip' => max(1, (int) ($cfg['max_failures_ip'] ?? 20)),
            'window_minutes' => max(1, (int) ($cfg['window_minutes'] ?? 15)),
            'lock_minutes' => max(1, (int) ($cfg['lock_minutes'] ?? 15)),
        ];
    }

    public static function clientIp(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';

        return $ip !== '' ? substr($ip, 0, 45) : '0.0.0.0';
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /** Progressive delay (seconds) from recent failures for this email+IP pair. */
    public static function throttleDelaySeconds(string $email): int
    {
        if (!self::tablesReady()) {
            return 0;
        }
        $cfg = self::config();
        $emailNorm = self::normalizeEmail($email);
        $ip = self::clientIp();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM admin_login_attempts
             WHERE success = 0 AND email_normalized = ?
               AND (ip_address = ? OR ip_address IS NULL)
               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );
        $stmt->execute([$emailNorm, $ip, $cfg['window_minutes']]);
        $failures = (int) $stmt->fetchColumn();
        if ($failures <= 0) {
            return 0;
        }

        return min(8, $failures);
    }

    /** @return 'throttled'|null */
    public static function blockReason(string $email): ?string
    {
        if (!self::tablesReady()) {
            return null;
        }
        $cfg = self::config();
        $emailNorm = self::normalizeEmail($email);
        if ($emailNorm === '') {
            return null;
        }
        $pdo = Database::pdo();
        $emailStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM admin_login_attempts
             WHERE success = 0 AND email_normalized = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );
        $emailStmt->execute([$emailNorm, $cfg['window_minutes']]);
        $emailFailures = (int) $emailStmt->fetchColumn();
        if ($emailFailures >= $cfg['max_failures_email']) {
            return 'throttled';
        }
        $ip = self::clientIp();
        $ipStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM admin_login_attempts
             WHERE success = 0 AND ip_address = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );
        $ipStmt->execute([$ip, $cfg['window_minutes']]);
        if ((int) $ipStmt->fetchColumn() >= $cfg['max_failures_ip']) {
            return 'throttled';
        }

        return null;
    }

    /**
     * Remove recent failed attempts for this email after a successful login so typos do not
     * lock the account until the window expires. Does not clear other emails on the same IP.
     */
    public static function clearRecentFailuresForEmail(string $email): void
    {
        if (!self::tablesReady()) {
            return;
        }
        $emailNorm = self::normalizeEmail($email);
        if ($emailNorm === '') {
            return;
        }
        try {
            $cfg = self::config();
            $pdo = Database::pdo();
            $pdo->prepare(
                'DELETE FROM admin_login_attempts
                 WHERE success = 0 AND email_normalized = ?
                   AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
            )->execute([$emailNorm, $cfg['window_minutes']]);
        } catch (Throwable $e) {
            error_log('AdminLoginSecurity::clearRecentFailuresForEmail: ' . $e->getMessage());
        }
    }

    public static function recordAttempt(string $email, bool $success): void
    {
        if (!self::tablesReady()) {
            return;
        }
        $emailNorm = self::normalizeEmail($email);
        if ($emailNorm === '') {
            return;
        }
        try {
            $pdo = Database::pdo();
            if ($success) {
                self::clearRecentFailuresForEmail($emailNorm);
            }
            $pdo->prepare(
                'INSERT INTO admin_login_attempts (email_normalized, ip_address, success) VALUES (?, ?, ?)'
            )->execute([$emailNorm, self::clientIp(), $success ? 1 : 0]);
            if (!$success) {
                AuditLog::write(
                    'admin.login_failed',
                    'admin_user',
                    null,
                    ['email' => $emailNorm],
                    null
                );
            }
        } catch (Throwable $e) {
            error_log('AdminLoginSecurity::recordAttempt: ' . $e->getMessage());
        }
    }

    /** Failed attempts in the active window (for tests/diagnostics). */
    public static function recentFailureCountForEmail(string $email): int
    {
        if (!self::tablesReady()) {
            return 0;
        }
        $emailNorm = self::normalizeEmail($email);
        if ($emailNorm === '') {
            return 0;
        }
        $cfg = self::config();
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM admin_login_attempts
             WHERE success = 0 AND email_normalized = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );
        $stmt->execute([$emailNorm, $cfg['window_minutes']]);

        return (int) $stmt->fetchColumn();
    }

    public static function recentFailureCountForIp(?string $ip = null): int
    {
        if (!self::tablesReady()) {
            return 0;
        }
        $ip = $ip ?? self::clientIp();
        $cfg = self::config();
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM admin_login_attempts
             WHERE success = 0 AND ip_address = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );
        $stmt->execute([$ip, $cfg['window_minutes']]);

        return (int) $stmt->fetchColumn();
    }

    public static function applyThrottleDelay(string $email): void
    {
        $sec = self::throttleDelaySeconds($email);
        if ($sec > 0) {
            sleep($sec);
        }
    }
}
