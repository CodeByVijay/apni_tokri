<?php

declare(strict_types=1);

/**
 * Admin forgot / reset password (AT-SEC-003).
 */
final class AdminPasswordRecovery
{
    /** @var callable(string, string, string, string): array{ok: bool, error?: string}|null */
    private static $mailSender;

    /** @param callable(string, string, string, string): array{ok: bool, error?: string}|null $sender */
    public static function setMailSender(?callable $sender): void
    {
        self::$mailSender = $sender;
    }

    /** @return array<string, int> */
    private static function config(): array
    {
        $cfg = (array) (App::config()['security']['admin_password_reset'] ?? []);

        return [
            'token_ttl_seconds' => max(300, (int) ($cfg['token_ttl_seconds'] ?? 3600)),
            'max_requests_email' => max(1, (int) ($cfg['max_requests_email'] ?? 3)),
            'max_requests_ip' => max(1, (int) ($cfg['max_requests_ip'] ?? 10)),
            'window_minutes' => max(1, (int) ($cfg['window_minutes'] ?? 60)),
        ];
    }

    public static function tablesReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'admin_password_reset_tokens'"
            );

            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function requestReset(string $email): array
    {
        $generic = 'If an account exists for that email, password reset instructions have been sent.';
        if (!Install::isSchemaReady()) {
            return ['ok' => true, 'message' => $generic];
        }
        if (!self::tablesReady()) {
            return ['ok' => false, 'message' => 'Password reset is not available until the database migration is applied.'];
        }
        $emailNorm = AdminLoginSecurity::normalizeEmail($email);
        if ($emailNorm === '' || !filter_var($emailNorm, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => true, 'message' => $generic];
        }
        if (self::isRateLimited($emailNorm)) {
            AuditLog::write('admin.password_reset_rate_limited', 'admin_user', null, ['email' => $emailNorm], null);

            return ['ok' => true, 'message' => $generic];
        }

        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, email, name, is_active FROM admin_users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$emailNorm]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !(int) $user['is_active']) {
            return ['ok' => true, 'message' => $generic];
        }

        $plain = bin2hex(random_bytes(32));
        $hash = self::hashToken($plain);
        $cfg = self::config();
        $ttl = $cfg['token_ttl_seconds'];
        $pdo->prepare(
            'INSERT INTO admin_password_reset_tokens (admin_user_id, token_hash, expires_at, request_ip)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?)'
        )->execute([(int) $user['id'], $hash, $ttl, AdminLoginSecurity::clientIp()]);

        $resetUrl = url_to('/admin/reset-password.php?token=' . rawurlencode($plain));
        $subject = 'Reset your Veggie Tokri admin password';
        $text = "Hello " . ($user['name'] ?? '') . ",\n\n"
            . "Use this link to reset your password (valid for " . (int) ($ttl / 60) . " minutes):\n"
            . $resetUrl . "\n\n"
            . "If you did not request this, ignore this email.\n";
        $html = '<p>Hello ' . Security::escape((string) ($user['name'] ?? '')) . ',</p>'
            . '<p><a href="' . Security::escape($resetUrl) . '">Reset your password</a> (expires in '
            . (int) ($ttl / 60) . ' minutes).</p><p>If you did not request this, ignore this email.</p>';

        $send = self::sendMail((string) $user['email'], $subject, $text, $html);
        if (empty($send['ok'])) {
            $pdo->prepare('DELETE FROM admin_password_reset_tokens WHERE token_hash = ?')->execute([$hash]);
            error_log('AdminPasswordRecovery: mail send failed');

            return ['ok' => false, 'message' => 'Could not send reset email. Try again later or contact another administrator.'];
        }

        AuditLog::write(
            'admin.password_reset_requested',
            'admin_user',
            (string) $user['id'],
            null,
            (int) $user['id']
        );

        return ['ok' => true, 'message' => $generic];
    }

    private static function isRateLimited(string $emailNorm): bool
    {
        $cfg = self::config();
        $pdo = Database::pdo();
        $emailStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM admin_password_reset_tokens t
             INNER JOIN admin_users u ON u.id = t.admin_user_id
             WHERE u.email = ? AND t.created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );
        $emailStmt->execute([$emailNorm, $cfg['window_minutes']]);
        if ((int) $emailStmt->fetchColumn() >= $cfg['max_requests_email']) {
            return true;
        }
        $ip = AdminLoginSecurity::clientIp();
        $ipStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM admin_password_reset_tokens
             WHERE request_ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );
        $ipStmt->execute([$ip, $cfg['window_minutes']]);

        return (int) $ipStmt->fetchColumn() >= $cfg['max_requests_ip'];
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    private static function sendMail(string $to, string $subject, string $text, string $html): array
    {
        if (self::$mailSender !== null) {
            return (self::$mailSender)($to, $subject, $text, $html);
        }

        return (new SmtpMailer())->send($to, $subject, $text, $html);
    }

    /**
     * @return array{ok: bool, message?: string, errors?: array<string, string>}
     */
    public static function resetWithToken(string $plainToken, string $newPassword, string $confirmPassword): array
    {
        if (!self::tablesReady()) {
            return ['ok' => false, 'message' => 'Password reset is not available.'];
        }
        $plainToken = trim($plainToken);
        if ($plainToken === '' || strlen($plainToken) < 32) {
            return ['ok' => false, 'message' => 'Invalid or expired reset link.'];
        }
        if ($newPassword !== $confirmPassword) {
            return ['ok' => false, 'message' => 'Passwords do not match.', 'errors' => ['confirm' => 'Must match.']];
        }
        $policy = Validation::adminPasswordPolicy($newPassword);
        if ($policy !== null) {
            return ['ok' => false, 'message' => $policy, 'errors' => ['new_password' => $policy]];
        }
        if (password_verify($newPassword, Install::SEED_ADMIN_PASSWORD_HASH)) {
            return ['ok' => false, 'message' => 'Choose a different password than the factory default.', 'errors' => ['new_password' => 'Not allowed.']];
        }

        $hash = self::hashToken($plainToken);
        Install::ensureConnection();
        $pdo = Database::pdo();

        try {
            $result = Database::transaction(static function () use ($pdo, $hash, $newPassword): array {
                $stmt = $pdo->prepare(
                    'SELECT t.id, t.admin_user_id, t.used_at, t.expires_at, u.password_hash
                     FROM admin_password_reset_tokens t
                     INNER JOIN admin_users u ON u.id = t.admin_user_id
                     WHERE t.token_hash = ? AND u.is_active = 1
                     LIMIT 1 FOR UPDATE'
                );
                $stmt->execute([$hash]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    throw new RuntimeException('invalid_token');
                }
                if ($row['used_at'] !== null) {
                    throw new RuntimeException('used_token');
                }
                if (strtotime((string) $row['expires_at']) < time()) {
                    throw new RuntimeException('expired_token');
                }
                $userId = (int) $row['admin_user_id'];
                if (password_verify($newPassword, (string) $row['password_hash'])) {
                    throw new RuntimeException('same_password');
                }
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                if (AdminLoginSecurity::authEpochColumnReady()) {
                    $pdo->prepare(
                        'UPDATE admin_users SET password_hash = ?, auth_epoch = auth_epoch + 1, updated_at = NOW() WHERE id = ?'
                    )->execute([$newHash, $userId]);
                } else {
                    $pdo->prepare('UPDATE admin_users SET password_hash = ?, updated_at = NOW() WHERE id = ?')
                        ->execute([$newHash, $userId]);
                }
                $pdo->prepare('UPDATE admin_password_reset_tokens SET used_at = NOW() WHERE id = ?')
                    ->execute([(int) $row['id']]);
                $pdo->prepare(
                    'UPDATE admin_password_reset_tokens SET used_at = NOW()
                     WHERE admin_user_id = ? AND used_at IS NULL AND id <> ?'
                )->execute([$userId, (int) $row['id']]);

                return ['ok' => true, 'user_id' => $userId];
            });
        } catch (Throwable $e) {
            $code = $e->getMessage();
            if (in_array($code, ['invalid_token', 'used_token', 'expired_token'], true)) {
                return ['ok' => false, 'message' => 'Invalid or expired reset link.'];
            }
            if ($code === 'same_password') {
                return ['ok' => false, 'message' => 'New password must differ from your current password.', 'errors' => ['new_password' => 'Must differ.']];
            }
            error_log('AdminPasswordRecovery::resetWithToken: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not reset password. Try again.'];
        }

        if (!empty($result['user_id'])) {
            AuditLog::write(
                'admin.password_reset_completed',
                'admin_user',
                (string) $result['user_id'],
                null,
                (int) $result['user_id']
            );
        }

        return ['ok' => true];
    }
}
