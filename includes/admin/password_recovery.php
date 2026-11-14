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

        // Reset links must be absolute and come only from configured app.base_url —
        // never from the request Host header (prevents host-header link poisoning).
        $base = App::baseUrl();
        $scheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));
        $baseHost = (string) parse_url($base, PHP_URL_HOST);
        if ($base === '' || $baseHost === '' || !in_array($scheme, ['http', 'https'], true)) {
            error_log('AdminPasswordRecovery: app.base_url is not a valid absolute URL; reset email not sent.');

            return ['ok' => false, 'message' => 'Password reset is temporarily unavailable. Contact an administrator.'];
        }

        $pdo->prepare(
            'INSERT INTO admin_password_reset_tokens (admin_user_id, token_hash, expires_at, request_ip)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?)'
        )->execute([(int) $user['id'], $hash, $ttl, AdminLoginSecurity::clientIp()]);

        $resetUrl = absolute_url('/admin/reset-password?token=' . rawurlencode($plain));
        $mail = self::resetEmail((string) ($user['name'] ?? ''), $resetUrl, $ttl);

        $send = self::sendMail((string) $user['email'], $mail['subject'], $mail['text'], $mail['html']);
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
     * Branded admin password-reset email (text + HTML parts share the same content).
     * The button and the plain-text fallback both carry the real, absolute reset URL.
     *
     * @return array{subject: string, text: string, html: string}
     */
    private static function resetEmail(string $name, string $resetUrl, int $ttlSeconds): array
    {
        $minutes = (int) ($ttlSeconds / 60);
        $greeting = trim($name) !== '' ? trim($name) : 'Admin';
        $safeGreeting = Security::escape($greeting);
        $safeUrl = Security::escape($resetUrl);

        $subject = 'Reset your Veggie Tokri Admin Password';

        $text = "Hello {$greeting},\n\n"
            . "We received a request to reset your Veggie Tokri admin password.\n\n"
            . "Reset your password:\n{$resetUrl}\n\n"
            . "This link expires in {$minutes} minutes.\n\n"
            . "If you did not request this password reset, please ignore this email.\n\n"
            . "Regards,\nVeggie Tokri Team\n";

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . Security::escape($subject) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f4f1e6;font-family:Arial,Helvetica,sans-serif;">'
            . '<div style="display:none;max-height:0;overflow:hidden;">Reset your Veggie Tokri admin password — link expires in '
            . $minutes . ' minutes.</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1e6;padding:24px 12px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;'
            . 'background:#fffdf8;border:1px solid #e3dcc8;border-radius:14px;overflow:hidden;">'
            // Brand header
            . '<tr><td style="background:#245c3a;padding:22px 32px;">'
            . '<span style="font-size:20px;font-weight:800;color:#ffffff;letter-spacing:0.2px;">Veggie Tokri</span>'
            . '<span style="font-size:11px;font-weight:700;color:#f5d9a0;text-transform:uppercase;letter-spacing:1.4px;'
            . 'display:block;margin-top:2px;">Admin Console</span>'
            . '</td></tr>'
            // Body
            . '<tr><td style="padding:32px 32px 8px;color:#292d29;">'
            . '<p style="margin:0 0 14px;font-size:16px;line-height:1.5;">Hello ' . $safeGreeting . ',</p>'
            . '<p style="margin:0 0 22px;font-size:15px;line-height:1.6;color:#4d554b;">'
            . 'We received a request to reset your Veggie Tokri admin password.</p>'
            . '</td></tr>'
            // Button
            . '<tr><td align="center" style="padding:0 32px 10px;">'
            . '<a href="' . $safeUrl . '" style="display:inline-block;background:#245c3a;color:#ffffff;'
            . 'font-size:16px;font-weight:700;text-decoration:none;padding:14px 42px;border-radius:10px;"'
            . ' target="_blank" rel="noopener">Reset Password</a>'
            . '</td></tr>'
            // Fallback URL + expiry + note
            . '<tr><td style="padding:14px 32px 30px;">'
            . '<p style="margin:0 0 14px;font-size:13px;line-height:1.6;color:#66705f;">This link expires in '
            . $minutes . ' minutes.</p>'
            . '<p style="margin:0 0 14px;font-size:13px;line-height:1.6;color:#66705f;">'
            . 'If the button does not work, copy and paste this URL into your browser:<br>'
            . '<a href="' . $safeUrl . '" style="color:#1d4e89;word-break:break-all;">' . $safeUrl . '</a></p>'
            . '<p style="margin:0;font-size:13px;line-height:1.6;color:#66705f;">'
            . 'If you did not request this password reset, please ignore this email.</p>'
            . '</td></tr>'
            // Footer
            . '<tr><td style="background:#f0f6f2;border-top:1px solid #e3dcc8;padding:16px 32px;">'
            . '<p style="margin:0;font-size:12px;color:#66705f;line-height:1.5;">Regards,<br>Veggie Tokri Team</p>'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';

        return ['subject' => $subject, 'text' => $text, 'html' => $html];
    }

    /**
     * Read-only status of a reset token for the reset form (never consumes it).
     *
     * @return 'ok'|'invalid'|'used'|'expired'|'unavailable'
     */
    public static function tokenStatus(string $plainToken): string
    {
        if (!self::tablesReady()) {
            return 'unavailable';
        }
        $plainToken = trim($plainToken);
        if ($plainToken === '' || strlen($plainToken) < 32) {
            return 'invalid';
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT used_at, expires_at FROM admin_password_reset_tokens
                 WHERE token_hash = ? LIMIT 1'
            );
            $stmt->execute([self::hashToken($plainToken)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return 'invalid';
            }
            if ($row['used_at'] !== null) {
                return 'used';
            }

            return strtotime((string) $row['expires_at']) < time() ? 'expired' : 'ok';
        } catch (Throwable $e) {
            return 'unavailable';
        }
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
