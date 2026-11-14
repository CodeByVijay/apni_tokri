<?php

declare(strict_types=1);

/**
 * OTP verification → first-time registration or returning login (server decides from DB).
 */
final class CustomerOtpRegistration
{
    public const CONSENT_TYPE_ACCOUNT = 'account_registration';
    public const MODE_NEW = 'new';
    public const MODE_RETURNING = 'returning';
    public const MODE_LEGACY_PROFILE = 'legacy_profile';

    public static function consentTableReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $n = (int) Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_registration_consents'"
            )->fetchColumn();

            return $n > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return array{
     *   mode: string,
     *   name: string,
     *   email: string,
     *   show_consent: bool,
     *   customer_id: int|null
     * }
     */
    public static function uiContextForMobile(string $mobile): array
    {
        $mobile = Validation::normalizeMobileIndia($mobile) ?? '';
        if ($mobile === '' || !Install::isSchemaReady()) {
            return [
                'mode' => self::MODE_NEW,
                'name' => '',
                'email' => '',
                'show_consent' => true,
                'customer_id' => null,
            ];
        }
        $row = self::customerRowByMobile($mobile);
        if ($row === null) {
            return [
                'mode' => self::MODE_NEW,
                'name' => '',
                'email' => '',
                'show_consent' => true,
                'customer_id' => null,
            ];
        }
        $name = trim((string) ($row['name'] ?? ''));
        $email = trim((string) ($row['email'] ?? ''));
        if ($name !== '') {
            return [
                'mode' => self::MODE_RETURNING,
                'name' => $name,
                'email' => $email,
                'show_consent' => false,
                'customer_id' => (int) $row['id'],
            ];
        }

        return [
            'mode' => self::MODE_LEGACY_PROFILE,
            'name' => $name,
            'email' => $email,
            'show_consent' => false,
            'customer_id' => (int) $row['id'],
        ];
    }

    /**
     * @param array{name?: string, email?: string, consent?: mixed} $input
     * @return array{ok: bool, error?: string, field_errors?: array<string, string>, customer_id?: int}
     */
    /**
     * Pre-OTP field validation for the server-detected mode (does not mutate DB).
     *
     * @param array{name?: string, email?: string, consent?: mixed} $input
     * @return array{ok: bool, field_errors?: array<string, string>}
     */
    public static function validateForMobileBeforeOtpConsume(string $mobile, array $input): array
    {
        $mobile = Validation::normalizeMobileIndia($mobile) ?? '';
        if ($mobile === '') {
            return ['ok' => false, 'field_errors' => ['form' => 'Invalid mobile number.']];
        }
        $context = self::uiContextForMobile($mobile);
        if ($context['mode'] === self::MODE_RETURNING) {
            return ['ok' => true];
        }
        $mode = $context['mode'] === self::MODE_LEGACY_PROFILE ? self::MODE_LEGACY_PROFILE : self::MODE_NEW;
        $validated = self::validateRegistrationInput($input, $mode);
        if (empty($validated['ok'])) {
            return ['ok' => false, 'field_errors' => $validated['field_errors'] ?? []];
        }
        if ($mode === self::MODE_NEW) {
            $block = self::newRegistrationBlockedReason();
            if ($block !== null) {
                return ['ok' => false, 'error' => $block];
            }
        }

        return ['ok' => true];
    }

    public static function completeAfterOtp(string $mobile, array $input): array
    {
        $mobile = Validation::normalizeMobileIndia($mobile) ?? '';
        if ($mobile === '') {
            return ['ok' => false, 'error' => 'Invalid mobile number.'];
        }

        $context = self::uiContextForMobile($mobile);

        if ($context['mode'] === self::MODE_RETURNING) {
            return self::loginExisting((int) $context['customer_id']);
        }

        if ($context['mode'] === self::MODE_LEGACY_PROFILE) {
            return self::completeLegacyProfile((int) $context['customer_id'], $input);
        }

        return self::registerNewCustomer($mobile, $input);
    }

    /** Human-safe duplicate-email audit (no raw addresses). @return list<array{norm_hash: string, count: int, customer_ids: list<int>}> */
    public static function auditDuplicateNormalizedEmailsReport(): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->query(
            "SELECT LOWER(TRIM(email)) AS norm_email, COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY id) AS ids
             FROM customers
             WHERE email IS NOT NULL AND TRIM(email) <> ''
             GROUP BY norm_email
             HAVING cnt > 1"
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $out = [];
        foreach ($rows as $row) {
            $norm = (string) ($row['norm_email'] ?? '');
            if ($norm === '') {
                continue;
            }
            $ids = array_map('intval', array_filter(explode(',', (string) ($row['ids'] ?? ''))));
            $out[] = [
                'norm_hash' => substr(hash('sha256', $norm), 0, 16),
                'count' => (int) ($row['cnt'] ?? 0),
                'customer_ids' => $ids,
            ];
        }

        return $out;
    }

    public static function newRegistrationBlockedReason(): ?string
    {
        if (!AppEnvironment::isProduction()) {
            return null;
        }
        if (!self::consentTableReady()) {
            return 'Account registration is temporarily unavailable. Please try again later.';
        }

        return null;
    }

    /**
     * @param array{name?: string, email?: string, consent?: mixed} $input
     * @return array{ok: bool, error?: string, field_errors?: array<string, string>}
     */
    public static function validateRegistrationInput(array $input, string $mode): array
    {
        $fieldErrors = [];
        if ($mode === self::MODE_RETURNING) {
            return ['ok' => true, 'field_errors' => []];
        }

        $name = isset($input['name']) && is_string($input['name'])
            ? trim(preg_replace('/\s+/u', ' ', $input['name']) ?? '')
            : '';
        $emailRaw = isset($input['email']) && is_string($input['email']) ? $input['email'] : '';
        $email = Validation::normalizeEmail($emailRaw);

        $nameErr = self::validateCustomerName($name);
        if ($nameErr !== null) {
            $fieldErrors['name'] = $nameErr;
        }
        if (trim($emailRaw) !== '' && $email === null) {
            $fieldErrors['email'] = 'Enter a valid email address.';
        }

        if ($mode === self::MODE_NEW) {
            $consent = !empty($input['consent']) || (isset($input['consent']) && (string) $input['consent'] === '1');
            if (!$consent) {
                $fieldErrors['consent'] = 'You must agree to the Terms & Conditions and Privacy Policy.';
            }
        }

        if ($fieldErrors !== []) {
            return ['ok' => false, 'field_errors' => $fieldErrors];
        }

        return ['ok' => true, 'field_errors' => [], 'name' => $name, 'email' => $email];
    }

    /** @return array{ok: bool, error?: string, field_errors?: array<string, string>, customer_id?: int} */
    private static function loginExisting(int $customerId): array
    {
        if ($customerId <= 0) {
            return ['ok' => false, 'error' => 'Account not found.'];
        }
        CustomerAuth::loginById($customerId);
        CartService::mergeGuestIntoCustomer($customerId);

        return ['ok' => true, 'customer_id' => $customerId];
    }

    /**
     * @param array{name?: string, email?: string} $input
     * @return array{ok: bool, error?: string, field_errors?: array<string, string>, customer_id?: int}
     */
    private static function completeLegacyProfile(int $customerId, array $input): array
    {
        $validated = self::validateRegistrationInput($input, self::MODE_LEGACY_PROFILE);
        if (empty($validated['ok'])) {
            return ['ok' => false, 'field_errors' => $validated['field_errors'] ?? []];
        }
        /** @var string $name */
        $name = $validated['name'];
        /** @var string|null $email */
        $email = $validated['email'];

        Install::ensureConnection();
        $pdo = Database::pdo();
        $emailLock = $email !== null ? self::emailLockKey($email) : null;
        if ($emailLock !== null && !self::acquireAdvisoryLock($pdo, $emailLock)) {
            return ['ok' => false, 'error' => 'Please try again in a moment.'];
        }

        try {
            $pdo->beginTransaction();
            try {
                if ($email !== null && self::emailUsedByOtherCustomer($pdo, $email, $customerId)) {
                    $pdo->rollBack();

                    return ['ok' => false, 'field_errors' => ['email' => 'This email is already used by another account.']];
                }
                $stmt = $pdo->prepare(
                    'UPDATE customers SET name = ?, email = ?, updated_at = NOW() WHERE id = ? AND is_active = 1'
                );
                $stmt->execute([$name, $email, $customerId]);
                if ($stmt->rowCount() === 0) {
                    $pdo->rollBack();

                    return ['ok' => false, 'error' => 'Account not found or inactive.'];
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            return self::loginExisting($customerId);
        } catch (Throwable $e) {
            error_log('legacy profile completion: ' . get_class($e));

            return ['ok' => false, 'error' => 'We could not update your profile. Please try again.'];
        } finally {
            if ($emailLock !== null) {
                self::releaseAdvisoryLock($pdo, $emailLock);
            }
        }
    }

    /**
     * @param array{name?: string, email?: string, consent?: mixed} $input
     * @return array{ok: bool, error?: string, field_errors?: array<string, string>, customer_id?: int}
     */
    private static function registerNewCustomer(string $mobile, array $input): array
    {
        $validated = self::validateRegistrationInput($input, self::MODE_NEW);
        if (empty($validated['ok'])) {
            return ['ok' => false, 'field_errors' => $validated['field_errors'] ?? []];
        }
        $block = self::newRegistrationBlockedReason();
        if ($block !== null) {
            return ['ok' => false, 'error' => $block];
        }
        /** @var string $name */
        $name = $validated['name'];
        /** @var string|null $email */
        $email = $validated['email'];

        Install::ensureConnection();
        $pdo = Database::pdo();
        $mobileLock = self::mobileLockKey($mobile);
        $emailLock = $email !== null ? self::emailLockKey($email) : null;
        if (!self::acquireAdvisoryLock($pdo, $mobileLock)) {
            return ['ok' => false, 'error' => 'Please try again in a moment.'];
        }
        $emailLockHeld = false;
        try {
            $existing = self::customerRowByMobile($mobile);
            if ($existing !== null) {
                self::releaseAdvisoryLock($pdo, $mobileLock);
                $ctx = self::uiContextForMobile($mobile);
                if ($ctx['mode'] === self::MODE_RETURNING) {
                    return self::loginExisting((int) $existing['id']);
                }

                return self::completeLegacyProfile((int) $existing['id'], $input);
            }

            if ($emailLock !== null && !self::acquireAdvisoryLock($pdo, $emailLock)) {
                return ['ok' => false, 'error' => 'Please try again in a moment.'];
            }
            $emailLockHeld = $emailLock !== null;

            if ($email !== null && self::emailUsedByOtherCustomer($pdo, $email, null)) {
                return ['ok' => false, 'field_errors' => ['email' => 'This email is already used by another account.']];
            }

            $pdo->beginTransaction();
            try {
                if ($email !== null && self::emailUsedByOtherCustomer($pdo, $email, null)) {
                    $pdo->rollBack();

                    return ['ok' => false, 'field_errors' => ['email' => 'This email is already used by another account.']];
                }
                $ins = $pdo->prepare('INSERT INTO customers (mobile, name, email) VALUES (?, ?, ?)');
                $ins->execute([$mobile, $name, $email]);
                $customerId = (int) $pdo->lastInsertId();
                self::recordRegistrationConsent($pdo, $customerId, true);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e instanceof PDOException && self::isDuplicateMobileException($e)) {
                    $row = self::customerRowByMobile($mobile);
                    if ($row !== null) {
                        return self::completeAfterOtp($mobile, $input);
                    }
                }
                throw $e;
            }

            return self::loginExisting($customerId);
        } catch (Throwable $e) {
            error_log('customer registration: ' . get_class($e));

            return ['ok' => false, 'error' => 'We could not create your account. Please try again.'];
        } finally {
            if ($emailLockHeld) {
                self::releaseAdvisoryLock($pdo, $emailLock);
            }
            self::releaseAdvisoryLock($pdo, $mobileLock);
        }
    }

    private static function validateCustomerName(string $name): ?string
    {
        $len = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($name === '') {
            return 'Enter your full name.';
        }
        if ($len < 2 || $len > 120) {
            return 'Name must be 2 to 120 characters.';
        }
        if (preg_match('/[\x00-\x1F<>]/', $name)) {
            return 'Name contains invalid characters.';
        }

        return null;
    }

    private static function recordRegistrationConsent(PDO $pdo, int $customerId, bool $required): void
    {
        if (!self::consentTableReady()) {
            if (AppEnvironment::isProduction()) {
                throw new RuntimeException('customer_registration_consents table unavailable');
            }

            return;
        }
        $termsSlug = 'terms';
        $privacySlug = 'privacy-policy';
        $termsVersion = self::cmsPublishedVersion($termsSlug);
        $privacyVersion = self::cmsPublishedVersion($privacySlug);
        $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : null;
        $ua = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])
            ? substr($_SERVER['HTTP_USER_AGENT'], 0, 512)
            : null;
        $pdo->prepare(
            'INSERT INTO customer_registration_consents (
                customer_id, consent_type, terms_slug, terms_version, privacy_slug, privacy_version, ip_address, user_agent
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $customerId,
            self::CONSENT_TYPE_ACCOUNT,
            $termsSlug,
            $termsVersion,
            $privacySlug,
            $privacyVersion,
            $ip,
            $ua,
        ]);
    }

    private static function cmsPublishedVersion(string $slug): ?int
    {
        if (!Validation::slug($slug)) {
            return null;
        }
        try {
            Install::ensureConnection();
            $pdo = Database::pdo();
            $page = CmsPageService::publishedPublicBySlug($slug);
            if ($page === null) {
                return null;
            }
            $cmsPageId = (int) ($page['id'] ?? 0);
            if ($cmsPageId <= 0) {
                return null;
            }
            $stmt = $pdo->prepare(
                'SELECT COALESCE(MAX(version_number), 0) FROM cms_page_versions WHERE cms_page_id = ?'
            );
            $stmt->execute([$cmsPageId]);
            $v = (int) $stmt->fetchColumn();

            return $v > 0 ? $v : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private static function customerRowByMobile(string $mobile): ?array
    {
        $mobile = Validation::normalizeMobileIndia($mobile) ?? '';
        if ($mobile === '') {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT id, mobile, name, email, is_active FROM customers WHERE mobile = ? LIMIT 1'
        );
        $stmt->execute([$mobile]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function isDuplicateMobileException(PDOException $e): bool
    {
        $code = (int) ($e->errorInfo[1] ?? 0);

        return $code === 1062 && str_contains(strtolower($e->getMessage()), 'uq_customers_mobile');
    }

    private static function mobileLockKey(string $mobile): string
    {
        return 'at_customer_reg_m_' . $mobile;
    }

    private static function emailLockKey(string $normalizedEmail): string
    {
        return 'at_customer_reg_e_' . substr(hash('sha256', strtolower(trim($normalizedEmail))), 0, 32);
    }

    private static function acquireAdvisoryLock(PDO $pdo, string $lockKey): bool
    {
        $lockStmt = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockKey) . ', 10)');
        $gotLock = (int) ($lockStmt ? $lockStmt->fetchColumn() : 0);

        return $gotLock === 1;
    }

    private static function releaseAdvisoryLock(PDO $pdo, string $lockKey): void
    {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockKey) . ')');
    }

    private static function emailUsedByOtherCustomer(PDO $pdo, string $normalizedEmail, ?int $excludeCustomerId): bool
    {
        if ($excludeCustomerId !== null && $excludeCustomerId > 0) {
            $stmt = $pdo->prepare(
                'SELECT id FROM customers WHERE LOWER(TRIM(email)) = ? AND id <> ? LIMIT 1'
            );
            $stmt->execute([$normalizedEmail, $excludeCustomerId]);
        } else {
            $stmt = $pdo->prepare('SELECT id FROM customers WHERE LOWER(TRIM(email)) = ? LIMIT 1');
            $stmt->execute([$normalizedEmail]);
        }

        return $stmt->fetchColumn() !== false;
    }
}
