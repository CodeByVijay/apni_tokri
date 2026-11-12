<?php

declare(strict_types=1);

/**
 * Google Play / app reviewer login — fixed OTP for one configured customer mobile (Super Admin settings).
 */
final class PlayReviewAccess
{
    public const KEY_ENABLED = 'play_review_enabled';
    public const KEY_MOBILE = 'play_review_mobile';
    public const KEY_OTP_HASH = 'play_review_otp_hash';

    /** Sentinel hash stored in customer_otp_attempts for verify-attempt throttling only. */
    public const THROTTLE_OTP_HASH = '$2y$12$ApniTokriPlayReviewThrottleOnly000000000000000000';

    public const THROTTLE_MAX_ATTEMPTS = 5;
    public const THROTTLE_WINDOW_SECONDS = 900;

    public static function isEnabled(): bool
    {
        return trim((string) (AppSettings::get(self::KEY_ENABLED, '0') ?? '0')) === '1';
    }

    public static function configuredMobile(): ?string
    {
        return Validation::normalizeMobileIndia(trim((string) (AppSettings::get(self::KEY_MOBILE, '') ?? '')));
    }

    public static function hasOtpHash(): bool
    {
        $hash = trim((string) (AppSettings::get(self::KEY_OTP_HASH, '') ?? ''));

        return $hash !== '' && str_starts_with($hash, '$2y$');
    }

    public static function isConfiguredReviewerMobile(string $mobile): bool
    {
        $mobile = Validation::normalizeMobileIndia($mobile) ?? '';
        $configured = self::configuredMobile();
        if ($mobile === '' || $configured === null) {
            return false;
        }

        return Security::hashEquals($configured, $mobile);
    }

    public static function isReviewLoginEnabledForMobile(string $mobile): bool
    {
        if (!self::isEnabled() || !self::isConfiguredReviewerMobile($mobile) || !self::hasOtpHash()) {
            return false;
        }

        return !empty(self::validateReviewerCustomer($mobile)['ok']);
    }

    /**
     * @return array{ok: bool, error?: string, code?: string, customer_id?: int}
     */
    public static function validateReviewerCustomer(string $mobile): array
    {
        $mobile = Validation::normalizeMobileIndia($mobile) ?? '';
        if ($mobile === '') {
            return ['ok' => false, 'error' => 'Invalid mobile number.', 'code' => 'invalid_mobile'];
        }
        if (!Install::isSchemaReady()) {
            return ['ok' => false, 'error' => 'Service unavailable.', 'code' => 'unavailable'];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT id, is_active FROM customers WHERE mobile = ? LIMIT 1');
        $stmt->execute([$mobile]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'error' => 'Incorrect or expired OTP.', 'code' => 'no_customer'];
        }
        if ((int) ($row['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => 'Incorrect or expired OTP.', 'code' => 'inactive'];
        }

        return ['ok' => true, 'customer_id' => (int) $row['id']];
    }

    public static function verifyOtpAgainstStoredHash(string $otp): bool
    {
        if (!preg_match('/^\d{6}$/', $otp)) {
            return false;
        }
        $hash = trim((string) (AppSettings::get(self::KEY_OTP_HASH, '') ?? ''));
        if ($hash === '') {
            return false;
        }

        return password_verify($otp, $hash);
    }

    /**
     * Play review verify path (called from CustomerAuth::verifyOtpCredentials).
     *
     * @return array{ok: bool, attempt_id?: int, error?: string}
     */
    public static function verifyCredentials(string $mobile, string $otp, int $maxAttempts = self::THROTTLE_MAX_ATTEMPTS): array
    {
        $mobile = Validation::normalizeMobileIndia($mobile) ?? '';
        if ($mobile === '' || !preg_match('/^\d{6}$/', $otp)) {
            return ['ok' => false, 'error' => 'Incorrect or expired OTP.'];
        }

        $customer = self::validateReviewerCustomer($mobile);
        if (empty($customer['ok'])) {
            return ['ok' => false, 'error' => (string) ($customer['error'] ?? 'Incorrect or expired OTP.')];
        }

        if (self::isVerifyThrottled($mobile, $maxAttempts)) {
            return ['ok' => false, 'error' => 'Incorrect or expired OTP.'];
        }

        if (!self::verifyOtpAgainstStoredHash($otp)) {
            self::recordFailedVerify($mobile, $maxAttempts);

            return ['ok' => false, 'error' => 'Incorrect or expired OTP.'];
        }

        self::clearVerifyThrottle($mobile);

        return ['ok' => true, 'attempt_id' => 0];
    }

    public static function isVerifyThrottled(string $mobile, int $maxAttempts = self::THROTTLE_MAX_ATTEMPTS): bool
    {
        $row = self::throttleRow($mobile);
        if ($row === null) {
            return false;
        }
        if ((int) ($row['attempt_count'] ?? 0) < $maxAttempts) {
            return false;
        }

        return self::throttleWindowActive($row);
    }

    /** @return array<string, mixed>|null */
    private static function throttleRow(string $mobile): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT id, attempt_count, expires_at FROM customer_otp_attempts
             WHERE mobile = ? AND otp_hash = ? AND verified_at IS NULL
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$mobile, self::THROTTLE_OTP_HASH]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row */
    private static function throttleWindowActive(array $row): bool
    {
        $exp = $row['expires_at'] ?? null;
        if (!is_string($exp) || $exp === '') {
            return true;
        }
        $ts = strtotime($exp);

        return $ts !== false && $ts > time();
    }

    private static function recordFailedVerify(string $mobile, int $maxAttempts): void
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $row = self::throttleRow($mobile);
        if ($row !== null
            && (int) ($row['attempt_count'] ?? 0) >= $maxAttempts
            && !self::throttleWindowActive($row)) {
            $pdo->prepare('DELETE FROM customer_otp_attempts WHERE id = ?')->execute([(int) $row['id']]);
            $row = null;
        }
        if ($row === null) {
            $pdo->prepare(
                'INSERT INTO customer_otp_attempts (mobile, otp_hash, expires_at, attempt_count, ip_address)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), 1, ?)'
            )->execute([
                $mobile,
                self::THROTTLE_OTP_HASH,
                self::THROTTLE_WINDOW_SECONDS,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            return;
        }
        $newCount = (int) ($row['attempt_count'] ?? 0) + 1;
        if ($newCount >= $maxAttempts) {
            $pdo->prepare(
                'UPDATE customer_otp_attempts SET attempt_count = ?, expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?'
            )->execute([$newCount, self::THROTTLE_WINDOW_SECONDS, (int) $row['id']]);
        } else {
            $pdo->prepare(
                'UPDATE customer_otp_attempts SET attempt_count = ? WHERE id = ?'
            )->execute([$newCount, (int) $row['id']]);
        }
    }

    private static function clearVerifyThrottle(string $mobile): void
    {
        Install::ensureConnection();
        Database::pdo()->prepare(
            'DELETE FROM customer_otp_attempts WHERE mobile = ? AND otp_hash = ? AND verified_at IS NULL'
        )->execute([$mobile, self::THROTTLE_OTP_HASH]);
    }

    /**
     * @return array{
     *   enabled: bool,
     *   mobile: string,
     *   has_otp: bool,
     *   customer_ok: bool,
     *   customer_label: string
     * }
     */
    public static function adminFormState(): array
    {
        $mobile = trim((string) (AppSettings::get(self::KEY_MOBILE, '') ?? ''));
        $customerLabel = 'No matching customer';
        $customerOk = false;
        $normalized = Validation::normalizeMobileIndia($mobile);
        if ($normalized !== null && Install::isSchemaReady()) {
            $check = self::validateReviewerCustomer($normalized);
            $customerOk = !empty($check['ok']);
            if ($customerOk) {
                $customerLabel = 'Active customer #' . (int) ($check['customer_id'] ?? 0);
            } elseif (($check['code'] ?? '') === 'inactive') {
                $customerLabel = 'Customer exists but is inactive';
            } elseif (($check['code'] ?? '') === 'no_customer') {
                $customerLabel = 'No customer for this mobile — create one first';
            }
        }

        return [
            'enabled' => self::isEnabled(),
            'mobile' => $mobile,
            'has_otp' => self::hasOtpHash(),
            'customer_ok' => $customerOk,
            'customer_label' => $customerLabel,
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message?: string, errors?: array<string, string>}
     */
    public static function saveFromRequest(array $post): array
    {
        if (!AdminAuth::check() || !AdminRbacService::userHasSuperAdminRole((int) AdminAuth::id())) {
            return ['ok' => false, 'message' => 'Forbidden.'];
        }

        $enabled = !empty($post['play_review_enabled']);
        $mobileRaw = trim((string) ($post['play_review_mobile'] ?? ''));
        $otpRaw = preg_replace('/\D+/', '', (string) ($post['play_review_otp'] ?? '')) ?? '';
        $otpProvided = $otpRaw !== '';

        $errors = [];
        $mobile = Validation::normalizeMobileIndia($mobileRaw);
        if ($enabled) {
            if ($mobile === null) {
                $errors['play_review_mobile'] = 'Enter a valid 10-digit Indian mobile number.';
            } else {
                $cust = self::validateReviewerCustomer($mobile);
                if (empty($cust['ok'])) {
                    if (($cust['code'] ?? '') === 'no_customer') {
                        $errors['play_review_mobile'] = 'No active customer exists for this mobile. Create the reviewer customer first.';
                    } else {
                        $errors['play_review_mobile'] = 'Reviewer customer must be active.';
                    }
                }
            }
            if (!$otpProvided && !self::hasOtpHash()) {
                $errors['play_review_otp'] = 'Enter a 6-digit review OTP.';
            } elseif ($otpProvided && !preg_match('/^\d{6}$/', $otpRaw)) {
                $errors['play_review_otp'] = 'Review OTP must be exactly 6 digits.';
            }
        } elseif ($mobileRaw !== '' && $mobile === null) {
            $errors['play_review_mobile'] = 'Enter a valid 10-digit Indian mobile number or clear the field.';
        }

        if ($errors !== []) {
            return [
                'ok' => false,
                'errors' => $errors,
                'message' => 'Please correct the highlighted fields.',
            ];
        }

        $batch = [
            self::KEY_ENABLED => ['value' => $enabled ? '1' : '0'],
            self::KEY_MOBILE => ['value' => $mobile ?? ''],
        ];
        AppSettings::setMany($batch);

        $otpRotated = false;
        if ($otpProvided && preg_match('/^\d{6}$/', $otpRaw)) {
            AppSettings::set(self::KEY_OTP_HASH, password_hash($otpRaw, PASSWORD_DEFAULT));
            $otpRotated = true;
        }

        AuditLog::write('settings.play_review_access', 'settings', null, [
            'enabled' => $enabled ? 1 : 0,
            'mobile_suffix' => $mobile !== null && $mobile !== '' ? substr($mobile, -4) : '',
            'otp_rotated' => $otpRotated ? 1 : 0,
        ]);

        AppSettings::resetCache();

        return ['ok' => true, 'message' => 'App Review Access settings saved.'];
    }
}
