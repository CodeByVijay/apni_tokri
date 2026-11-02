<?php

declare(strict_types=1);

final class AdminAuth
{
    private const SESSION_USER_ID = 'admin_user_id';

    public static function attempt(string $email, string $password): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT id, email, password_hash, name, is_active FROM admin_users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([trim($email)]);
        $user = $stmt->fetch();
        if (!$user || !(int) $user['is_active']) {
            return false;
        }
        if (!password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        AppSession::regenerate();
        $_SESSION[self::SESSION_USER_ID] = (int) $user['id'];
        $_SESSION['admin_user_email'] = (string) $user['email'];
        $_SESSION['admin_user_name'] = (string) $user['name'];

        $upd = Database::pdo()->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?');
        $upd->execute([(int) $user['id']]);

        AuditLog::write('admin.login', 'admin_user', (string) $user['id']);

        return true;
    }

    public static function check(): bool
    {
        if (!isset($_SESSION[self::SESSION_USER_ID]) || (int) $_SESSION[self::SESSION_USER_ID] <= 0) {
            return false;
        }
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT is_active FROM admin_users WHERE id = ? LIMIT 1'
            );
            $stmt->execute([(int) $_SESSION[self::SESSION_USER_ID]]);
            $active = $stmt->fetchColumn();
            if ($active === false || !(int) $active) {
                unset(
                    $_SESSION[self::SESSION_USER_ID],
                    $_SESSION['admin_user_email'],
                    $_SESSION['admin_user_name'],
                    $_SESSION['admin_demo_role']
                );

                return false;
            }
        } catch (Throwable $e) {
            return false;
        }

        return true;
    }

    public static function id(): ?int
    {
        return self::check() ? (int) $_SESSION[self::SESSION_USER_ID] : null;
    }

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }

        return [
            'id' => self::id(),
            'email' => (string) ($_SESSION['admin_user_email'] ?? ''),
            'name' => (string) ($_SESSION['admin_user_name'] ?? ''),
        ];
    }

    public static function logout(): void
    {
        $userId = isset($_SESSION[self::SESSION_USER_ID]) ? (int) $_SESSION[self::SESSION_USER_ID] : 0;
        if ($userId > 0 && Install::isSchemaReady()) {
            try {
                AuditLog::write('admin.logout', 'admin_user', (string) $userId);
            } catch (Throwable $e) {
                // ignore audit failures on logout
            }
        }
        unset(
            $_SESSION[self::SESSION_USER_ID],
            $_SESSION['admin_user_email'],
            $_SESSION['admin_user_name'],
            $_SESSION['admin_demo_role']
        );
        AppSession::regenerate();
    }
}

final class CustomerAuth
{
    private const SESSION_CUSTOMER_ID = 'customer_id';

    public static function loginById(int $customerId): void
    {
        AppSession::regenerate();
        $_SESSION[self::SESSION_CUSTOMER_ID] = $customerId;
    }

    public static function check(): bool
    {
        return isset($_SESSION[self::SESSION_CUSTOMER_ID]) && (int) $_SESSION[self::SESSION_CUSTOMER_ID] > 0;
    }

    public static function id(): ?int
    {
        return self::check() ? (int) $_SESSION[self::SESSION_CUSTOMER_ID] : null;
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_CUSTOMER_ID]);
        AppSession::regenerate();
    }

    /**
     * Create OTP record (hashed). Returns plain OTP for SMS layer (local debug only).
     *
     * @return array{mobile: string, expires_in: int, otp?: string}
     */
    public static function issueOtp(string $mobile, int $ttlSeconds = 300): array
    {
        if (!Install::isSchemaReady()) {
            throw new RuntimeException('Database schema not ready for OTP.');
        }
        Install::ensureConnection();
        $mobile = Validation::normalizeMobileIndia($mobile) ?? '';
        if ($mobile === '') {
            throw new OtpRequestException(OtpSmsService::ERR_INVALID_MOBILE);
        }

        if (OtpSmsService::operationMode() === OtpSmsService::MODE_DISABLED) {
            throw new OtpRequestException(OtpSmsService::ERR_SMS_DISABLED);
        }

        $limitMsg = self::otpRateLimitMessage($mobile);
        if ($limitMsg !== null) {
            throw new OtpRequestException(OtpSmsService::ERR_RATE_LIMITED);
        }

        $otp = OtpSmsService::generateOtpPlain();
        $hash = password_hash($otp, PASSWORD_DEFAULT);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = Database::pdo()->prepare(
            'INSERT INTO customer_otp_attempts (mobile, otp_hash, expires_at, ip_address) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?)'
        );
        $stmt->execute([$mobile, $hash, $ttlSeconds, $ip]);
        $attemptId = (int) Database::pdo()->lastInsertId();

        $send = OtpSmsService::sendLoginOtp($mobile, $otp, $ttlSeconds);
        if (empty($send['ok'])) {
            Database::pdo()->prepare('DELETE FROM customer_otp_attempts WHERE id = ?')->execute([$attemptId]);
            throw new OtpRequestException((string) ($send['error'] ?? OtpSmsService::ERR_SERVER_ERROR));
        }

        return ['mobile' => $mobile, 'expires_in' => $ttlSeconds];
    }

    private static function otpRateLimitMessage(string $mobile): ?string
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM customer_otp_attempts WHERE mobile = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
        );
        $stmt->execute([$mobile]);
        if ((int) $stmt->fetchColumn() >= 3) {
            return 'Too many OTP requests for this number. Try again in 15 minutes.';
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip !== '') {
            $ipStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM customer_otp_attempts WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
            );
            $ipStmt->execute([$ip]);
            if ((int) $ipStmt->fetchColumn() >= 10) {
                return 'Too many OTP requests from this network. Please wait and try again.';
            }
        }

        return null;
    }

    public static function verifyOtp(string $mobile, string $otp, ?string $name = null, int $maxAttempts = 5): bool
    {
        Install::ensureConnection();
        $mobile = Validation::normalizeMobileIndia($mobile) ?? '';
        if ($mobile === '' || !preg_match('/^\d{6}$/', $otp)) {
            return false;
        }
        $stmt = Database::pdo()->prepare(
            'SELECT id, otp_hash, attempt_count FROM customer_otp_attempts
             WHERE mobile = ? AND verified_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$mobile]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        if ((int) $row['attempt_count'] >= $maxAttempts) {
            return false;
        }
        $ok = password_verify($otp, (string) $row['otp_hash']);
        $upd = Database::pdo()->prepare(
            'UPDATE customer_otp_attempts SET attempt_count = attempt_count + 1, verified_at = IF(?, NOW(), verified_at) WHERE id = ?'
        );
        $upd->execute([$ok ? 1 : 0, (int) $row['id']]);
        if (!$ok) {
            return false;
        }

        $cust = Database::pdo()->prepare('SELECT id FROM customers WHERE mobile = ? LIMIT 1');
        $cust->execute([$mobile]);
        $customerId = $cust->fetchColumn();
        if (!$customerId) {
            $ins = Database::pdo()->prepare('INSERT INTO customers (mobile) VALUES (?)');
            $ins->execute([$mobile]);
            $customerId = Database::pdo()->lastInsertId();
        }
        $customerId = (int) $customerId;
        if ($name !== null && trim($name) !== '') {
            Database::pdo()->prepare('UPDATE customers SET name = ?, updated_at = NOW() WHERE id = ?')
                ->execute([trim($name), $customerId]);
        }
        self::loginById($customerId);
        CartService::mergeGuestIntoCustomer($customerId);

        return true;
    }
}
