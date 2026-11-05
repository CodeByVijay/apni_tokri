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
        $email = trim($email);
        $throttled = AdminLoginSecurity::blockReason($email) === 'throttled';
        AdminLoginSecurity::applyThrottleDelay($email);
        Install::ensureConnection();
        $userSql = AdminLoginSecurity::authEpochColumnReady()
            ? 'SELECT id, email, password_hash, name, is_active, auth_epoch FROM admin_users WHERE email = ? LIMIT 1'
            : 'SELECT id, email, password_hash, name, is_active FROM admin_users WHERE email = ? LIMIT 1';
        $stmt = Database::pdo()->prepare($userSql);
        $stmt->execute([AdminLoginSecurity::normalizeEmail($email)]);
        $user = $stmt->fetch();
        if (!$user || !(int) $user['is_active']) {
            if (!$throttled) {
                AdminLoginSecurity::recordAttempt($email, false);
            }

            return false;
        }
        if (!password_verify($password, (string) $user['password_hash'])) {
            if (!$throttled) {
                AdminLoginSecurity::recordAttempt($email, false);
            }

            return false;
        }

        AdminLoginSecurity::recordAttempt($email, true);
        AppSession::regenerate();
        $_SESSION[self::SESSION_USER_ID] = (int) $user['id'];
        $_SESSION['admin_user_email'] = (string) $user['email'];
        $_SESSION['admin_user_name'] = (string) $user['name'];
        $_SESSION['admin_auth_epoch'] = (int) ($user['auth_epoch'] ?? 1);

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
            $checkSql = AdminLoginSecurity::authEpochColumnReady()
                ? 'SELECT is_active, auth_epoch FROM admin_users WHERE id = ? LIMIT 1'
                : 'SELECT is_active FROM admin_users WHERE id = ? LIMIT 1';
            $stmt = Database::pdo()->prepare($checkSql);
            $stmt->execute([(int) $_SESSION[self::SESSION_USER_ID]]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || !(int) $row['is_active']) {
                unset(
                    $_SESSION[self::SESSION_USER_ID],
                    $_SESSION['admin_user_email'],
                    $_SESSION['admin_user_name'],
                    $_SESSION['admin_demo_role'],
                    $_SESSION['admin_auth_epoch']
                );

                return false;
            }
            if (AdminLoginSecurity::authEpochColumnReady()) {
                $epoch = (int) ($row['auth_epoch'] ?? 1);
                $sessionEpoch = (int) ($_SESSION['admin_auth_epoch'] ?? 1);
                if ($epoch !== $sessionEpoch) {
                    unset(
                        $_SESSION[self::SESSION_USER_ID],
                        $_SESSION['admin_user_email'],
                        $_SESSION['admin_user_name'],
                        $_SESSION['admin_demo_role'],
                        $_SESSION['admin_auth_epoch']
                    );

                    return false;
                }
            }
        } catch (Throwable $e) {
            return false;
        }

        return true;
    }

    /** True when the signed-in admin still uses the factory seed password hash. */
    public static function requiresMandatoryPasswordChange(): bool
    {
        if (!self::check()) {
            return false;
        }
        $userId = (int) $_SESSION[self::SESSION_USER_ID];
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare('SELECT password_hash FROM admin_users WHERE id = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$userId]);
            $hash = $stmt->fetchColumn();
            if ($hash === false || !is_string($hash)) {
                return false;
            }

            return hash_equals(Install::SEED_ADMIN_PASSWORD_HASH, $hash);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return array{ok: bool, message?: string, errors?: array<string, string>}
     */
    public static function changePasswordMandatory(string $currentPassword, string $newPassword, string $confirmPassword): array
    {
        if (!self::check()) {
            return ['ok' => false, 'message' => 'Session expired. Sign in again.'];
        }
        $userId = (int) $_SESSION[self::SESSION_USER_ID];
        if ($newPassword !== $confirmPassword) {
            return ['ok' => false, 'message' => 'New password and confirmation do not match.', 'errors' => ['confirm' => 'Must match new password.']];
        }
        $policy = Validation::adminPasswordPolicy($newPassword);
        if ($policy !== null) {
            return ['ok' => false, 'message' => $policy, 'errors' => ['new_password' => $policy]];
        }
        if (password_verify($newPassword, Install::SEED_ADMIN_PASSWORD_HASH)) {
            return ['ok' => false, 'message' => 'Choose a different password than the factory default.', 'errors' => ['new_password' => 'Not allowed.']];
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare('SELECT password_hash FROM admin_users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || !password_verify($currentPassword, (string) $row['password_hash'])) {
                return ['ok' => false, 'message' => 'Current password is incorrect.', 'errors' => ['current_password' => 'Incorrect.']];
            }
            if (password_verify($newPassword, (string) $row['password_hash'])) {
                return ['ok' => false, 'message' => 'New password must be different from your current password.', 'errors' => ['new_password' => 'Must differ.']];
            }
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            if (AdminLoginSecurity::authEpochColumnReady()) {
                Database::pdo()->prepare(
                    'UPDATE admin_users SET password_hash = ?, auth_epoch = auth_epoch + 1, updated_at = NOW() WHERE id = ?'
                )->execute([$hash, $userId]);
            } else {
                Database::pdo()->prepare('UPDATE admin_users SET password_hash = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$hash, $userId]);
            }
            AuditLog::write('admin.password_change_mandatory', 'admin_user', (string) $userId);
            AppSession::regenerate();
            $_SESSION[self::SESSION_USER_ID] = $userId;
            if (AdminLoginSecurity::authEpochColumnReady()) {
                $epochStmt = Database::pdo()->prepare('SELECT auth_epoch FROM admin_users WHERE id = ?');
                $epochStmt->execute([$userId]);
                $_SESSION['admin_auth_epoch'] = (int) ($epochStmt->fetchColumn() ?: 1);
            }

            return ['ok' => true];
        } catch (Throwable $e) {
            error_log('AdminAuth::changePasswordMandatory: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not update password. Try again.'];
        }
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
            $_SESSION['admin_demo_role'],
            $_SESSION['admin_auth_epoch']
        );
        AppSession::regenerate();
    }

    /** Generic failed-login copy (does not reveal throttle vs bad password). */
    public static function loginErrorMessage(string $email, string $password): string
    {
        if ($email === '' || $password === '') {
            return 'Enter your email and password.';
        }

        return 'Invalid email or password.';
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

    /** Saved name for a mobile from the server-side customer record ('' when none/unknown). */
    public static function savedNameForMobile(string $mobile): string
    {
        $mobile = Validation::normalizeMobileIndia($mobile) ?? '';
        if ($mobile === '') {
            return '';
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT name FROM customers WHERE mobile = ? LIMIT 1');
        $stmt->execute([$mobile]);
        $v = $stmt->fetchColumn();

        return is_string($v) ? trim($v) : '';
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

        $cust = Database::pdo()->prepare('SELECT id, name FROM customers WHERE mobile = ? LIMIT 1');
        $cust->execute([$mobile]);
        $custRow = $cust->fetch(PDO::FETCH_ASSOC);
        $customerId = $custRow ? $custRow['id'] : null;
        $savedName = $custRow ? trim((string) ($custRow['name'] ?? '')) : '';
        if (!$customerId) {
            $ins = Database::pdo()->prepare('INSERT INTO customers (mobile) VALUES (?)');
            $ins->execute([$mobile]);
            $customerId = Database::pdo()->lastInsertId();
        }
        $customerId = (int) $customerId;
        // A saved name is never overwritten here; profile page is the place to change it.
        if ($savedName === '' && $name !== null && trim($name) !== '') {
            Database::pdo()->prepare('UPDATE customers SET name = ?, updated_at = NOW() WHERE id = ?')
                ->execute([trim($name), $customerId]);
        }
        self::loginById($customerId);
        CartService::mergeGuestIntoCustomer($customerId);

        return true;
    }
}
