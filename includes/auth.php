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
        $_SESSION['admin_auth_issued_at'] = time();
        $_SESSION['admin_auth_last_activity'] = time();

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
                self::clearSessionState();

                return false;
            }
            if (AdminLoginSecurity::authEpochColumnReady()) {
                $epoch = (int) ($row['auth_epoch'] ?? 1);
                $sessionEpoch = (int) ($_SESSION['admin_auth_epoch'] ?? 1);
                if ($epoch !== $sessionEpoch) {
                    self::clearSessionState();

                    return false;
                }
            }
        } catch (Throwable $e) {
            return false;
        }

        // Server-side session lifetime enforcement (VT-2026-07). Evaluated only
        // after the session is proven valid; timestamps live in the session so
        // no clock state is client-controlled.
        $now = time();
        $issuedAt = (int) ($_SESSION['admin_auth_issued_at'] ?? 0);
        $lastActivity = (int) ($_SESSION['admin_auth_last_activity'] ?? 0);
        if ($issuedAt <= 0) {
            // Backfill for sessions created before this check existed.
            $issuedAt = $now;
            $_SESSION['admin_auth_issued_at'] = $now;
        }
        if ($lastActivity <= 0) {
            $lastActivity = $now;
            $_SESSION['admin_auth_last_activity'] = $now;
        }
        $absolute = self::absoluteTimeoutSeconds();
        $idle = self::idleTimeoutSeconds();
        if (($absolute > 0 && $now - $issuedAt >= $absolute)
            || ($idle > 0 && $now - $lastActivity >= $idle)) {
            self::clearSessionState();
            $_SESSION['admin_login_notice'] = 'Your admin session expired. Please sign in again.';

            return false;
        }
        // Activity counts only for requests inside the admin area — a customer
        // browsing the storefront does not extend an admin session.
        if (str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/')) {
            $_SESSION['admin_auth_last_activity'] = $now;
        }

        return true;
    }

    /** Admin idle timeout (seconds); overrides via security.admin_session.idle_timeout_seconds. */
    public static function idleTimeoutSeconds(): int
    {
        try {
            $v = (int) (App::config()['security']['admin_session']['idle_timeout_seconds'] ?? 1800);
        } catch (Throwable $e) {
            $v = 1800;
        }

        return max(0, $v);
    }

    /** Admin absolute session cap (seconds); overrides via security.admin_session.absolute_timeout_seconds. */
    public static function absoluteTimeoutSeconds(): int
    {
        try {
            $v = (int) (App::config()['security']['admin_session']['absolute_timeout_seconds'] ?? 28800);
        } catch (Throwable $e) {
            $v = 28800;
        }

        return max(0, $v);
    }

    /** Drop every admin-auth session key (expiry, deactivation, epoch mismatch, logout). */
    private static function clearSessionState(): void
    {
        unset(
            $_SESSION[self::SESSION_USER_ID],
            $_SESSION['admin_user_email'],
            $_SESSION['admin_user_name'],
            $_SESSION['admin_demo_role'],
            $_SESSION['admin_auth_epoch'],
            $_SESSION['admin_auth_issued_at'],
            $_SESSION['admin_auth_last_activity']
        );
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
            $_SESSION['admin_auth_issued_at'] = time();
            $_SESSION['admin_auth_last_activity'] = time();
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
        self::clearSessionState();
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
        if (!isset($_SESSION[self::SESSION_CUSTOMER_ID])) {
            return false;
        }
        $id = (int) $_SESSION[self::SESSION_CUSTOMER_ID];
        if ($id <= 0) {
            return false;
        }

        return self::customerIsActive($id);
    }

    private static function customerIsActive(int $customerId): bool
    {
        static $cache = [];
        if (isset($cache[$customerId])) {
            return $cache[$customerId];
        }
        if (!Install::isSchemaReady()) {
            $cache[$customerId] = true;

            return true;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare('SELECT is_active FROM customers WHERE id = ? LIMIT 1');
            $stmt->execute([$customerId]);
            $v = $stmt->fetchColumn();
            if ($v === false) {
                unset($_SESSION[self::SESSION_CUSTOMER_ID]);
                $cache[$customerId] = false;

                return false;
            }
            $active = (int) $v === 1;
            if (!$active) {
                unset($_SESSION[self::SESSION_CUSTOMER_ID]);
            }
            $cache[$customerId] = $active;

            return $active;
        } catch (Throwable $e) {
            $cache[$customerId] = true;

            return true;
        }
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

        if (PlayReviewAccess::isReviewLoginEnabledForMobile($mobile)) {
            $reviewer = PlayReviewAccess::validateReviewerCustomer($mobile);
            if (empty($reviewer['ok'])) {
                $code = (string) ($reviewer['code'] ?? '');
                if ($code === 'inactive') {
                    throw new OtpRequestException(OtpSmsService::ERR_ACCOUNT_INACTIVE);
                }
                throw new OtpRequestException(OtpSmsService::ERR_INVALID_MOBILE);
            }
            OtpRateLimit::assertWithinLimits($mobile);
            OtpResendCooldown::assertCanSend($mobile);
            OtpResendCooldown::recordSuccessfulSend($mobile);

            return ['mobile' => $mobile, 'expires_in' => $ttlSeconds];
        }

        if (PlayReviewAccess::isEnabled() && PlayReviewAccess::isConfiguredReviewerMobile($mobile)) {
            throw new OtpRequestException(OtpSmsService::ERR_INVALID_MOBILE);
        }

        if (OtpSmsService::operationMode() === OtpSmsService::MODE_DISABLED) {
            throw new OtpRequestException(OtpSmsService::ERR_SMS_DISABLED);
        }

        self::assertExistingCustomerActiveForOtp($mobile);

        OtpRateLimit::assertWithinLimits($mobile);

        $pdo = Database::pdo();
        $lockKey = 'at_otp_issue_' . $mobile;
        $lockStmt = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockKey) . ', 10)');
        $gotLock = (int) ($lockStmt ? $lockStmt->fetchColumn() : 0);
        if ($gotLock !== 1) {
            throw new OtpRequestException(OtpSmsService::ERR_SERVER_ERROR);
        }

        $attemptId = 0;
        try {
            OtpRateLimit::assertWithinLimits($mobile);

            OtpResendCooldown::assertCanSend($mobile);

            $otp = OtpSmsService::generateOtpPlain();
            $hash = password_hash($otp, PASSWORD_DEFAULT);
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $stmt = $pdo->prepare(
                'INSERT INTO customer_otp_attempts (mobile, otp_hash, expires_at, ip_address) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?)'
            );
            $stmt->execute([$mobile, $hash, $ttlSeconds, $ip]);
            $attemptId = (int) $pdo->lastInsertId();

            $send = OtpSmsService::sendLoginOtp($mobile, $otp, $ttlSeconds);
            if (empty($send['ok'])) {
                $pdo->prepare('DELETE FROM customer_otp_attempts WHERE id = ?')->execute([$attemptId]);
                $attemptId = 0;
                throw new OtpRequestException((string) ($send['error'] ?? OtpSmsService::ERR_SERVER_ERROR));
            }

            OtpResendCooldown::recordSuccessfulSend($mobile);

            return ['mobile' => $mobile, 'expires_in' => $ttlSeconds];
        } catch (Throwable $e) {
            if ($attemptId > 0) {
                try {
                    $pdo->prepare('DELETE FROM customer_otp_attempts WHERE id = ?')->execute([$attemptId]);
                } catch (Throwable $cleanup) {
                    error_log('OTP attempt rollback failed: ' . $cleanup->getMessage());
                }
            }
            throw $e;
        } finally {
            $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockKey) . ')');
        }
    }

    /** True when a customer row exists for this mobile and is_active is off. */
    public static function isExistingInactiveCustomer(string $mobile): bool
    {
        $mobile = Validation::normalizeMobileIndia($mobile) ?? '';
        if ($mobile === '' || !Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare('SELECT is_active FROM customers WHERE mobile = ? LIMIT 1');
            $stmt->execute([$mobile]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return false;
            }

            return (int) ($row['is_active'] ?? 1) !== 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @throws OtpRequestException when an existing customer account is deactivated
     */
    private static function assertExistingCustomerActiveForOtp(string $mobile): void
    {
        if (self::isExistingInactiveCustomer($mobile)) {
            throw new OtpRequestException(OtpSmsService::ERR_ACCOUNT_INACTIVE);
        }
    }

    /** Saved name for a mobile from the server-side customer record ('' when none/unknown). */
    public static function savedNameForMobile(string $mobile): string
    {
        $ctx = CustomerOtpRegistration::uiContextForMobile($mobile);

        return $ctx['name'] ?? '';
    }

    /**
     * @param array{name?: string, email?: string, consent?: mixed} $registration
     * @return array{ok: bool, error?: string, field_errors?: array<string, string>, customer_id?: int}
     */
    public static function verifyOtpWithRegistration(
        string $mobile,
        string $otp,
        array $registration = [],
        int $maxAttempts = 5
    ): array {
        $precheck = CustomerOtpRegistration::validateForMobileBeforeOtpConsume($mobile, $registration);
        if (empty($precheck['ok'])) {
            $out = ['ok' => false, 'field_errors' => $precheck['field_errors'] ?? []];
            if (!empty($precheck['error'])) {
                $out['error'] = (string) $precheck['error'];
            }

            return $out;
        }

        $otpAttempt = self::verifyOtpCredentials($mobile, $otp, $maxAttempts);
        if (empty($otpAttempt['ok'])) {
            return ['ok' => false, 'error' => (string) ($otpAttempt['error'] ?? 'Incorrect or expired OTP.')];
        }

        $complete = CustomerOtpRegistration::completeAfterOtp($mobile, $registration);
        if (!empty($complete['ok'])) {
            self::markOtpAttemptVerified((int) $otpAttempt['attempt_id']);
        }

        return $complete;
    }

    public static function verifyOtp(string $mobile, string $otp, ?string $name = null, int $maxAttempts = 5): bool
    {
        $registration = [];
        if ($name !== null && trim($name) !== '') {
            $registration['name'] = trim($name);
        }
        $result = self::verifyOtpWithRegistration($mobile, $otp, $registration, $maxAttempts);

        return !empty($result['ok']);
    }

    private static function attemptOtpVerification(string $mobile, string $otp, int $maxAttempts): bool
    {
        $result = self::verifyOtpCredentials($mobile, $otp, $maxAttempts);
        if (empty($result['ok'])) {
            return false;
        }
        self::markOtpAttemptVerified((int) $result['attempt_id']);

        return true;
    }

    /** @return array{ok: bool, attempt_id?: int, error?: string} */
    private static function verifyOtpCredentials(string $mobile, string $otp, int $maxAttempts): array
    {
        Install::ensureConnection();
        $mobile = Validation::normalizeMobileIndia($mobile) ?? '';
        if ($mobile === '' || !preg_match('/^\d{6}$/', $otp)) {
            return ['ok' => false, 'error' => 'Incorrect or expired OTP.'];
        }

        if (PlayReviewAccess::isReviewLoginEnabledForMobile($mobile)) {
            return PlayReviewAccess::verifyCredentials($mobile, $otp, $maxAttempts);
        }

        if (PlayReviewAccess::isEnabled() && PlayReviewAccess::isConfiguredReviewerMobile($mobile)) {
            return ['ok' => false, 'error' => 'Incorrect or expired OTP.'];
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id, otp_hash, attempt_count FROM customer_otp_attempts
             WHERE mobile = ? AND verified_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$mobile]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['ok' => false, 'error' => 'Incorrect or expired OTP.'];
        }
        if ((int) $row['attempt_count'] >= $maxAttempts) {
            return ['ok' => false, 'error' => 'Incorrect or expired OTP.'];
        }
        if (!password_verify($otp, (string) $row['otp_hash'])) {
            Database::pdo()->prepare(
                'UPDATE customer_otp_attempts SET attempt_count = attempt_count + 1, expires_at = expires_at WHERE id = ?'
            )->execute([(int) $row['id']]);

            return ['ok' => false, 'error' => 'Incorrect or expired OTP.'];
        }
        if (self::isExistingInactiveCustomer($mobile)) {
            Database::pdo()->prepare(
                'UPDATE customer_otp_attempts SET attempt_count = attempt_count + 1, expires_at = expires_at WHERE id = ?'
            )->execute([(int) $row['id']]);

            return ['ok' => false, 'error' => 'Incorrect or expired OTP.'];
        }

        return ['ok' => true, 'attempt_id' => (int) $row['id']];
    }

    private static function markOtpAttemptVerified(int $attemptId): void
    {
        if ($attemptId <= 0) {
            return;
        }
        Database::pdo()->prepare(
            'UPDATE customer_otp_attempts SET attempt_count = attempt_count + 1, verified_at = NOW(), expires_at = expires_at WHERE id = ? AND verified_at IS NULL'
        )->execute([$attemptId]);
    }
}
