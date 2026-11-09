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
        if ($name !== '' && $email !== '') {
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
        if ($email === null) {
            $fieldErrors['email'] = trim($emailRaw) === ''
                ? 'Enter your email address.'
                : 'Enter a valid email address.';
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
        /** @var string $email */
        $email = $validated['email'];

        Install::ensureConnection();
        $pdo = Database::pdo();
        $dup = $pdo->prepare('SELECT id FROM customers WHERE email = ? AND id <> ? LIMIT 1');
        $dup->execute([$email, $customerId]);
        if ($dup->fetchColumn()) {
            return ['ok' => false, 'field_errors' => ['email' => 'This email is already used by another account.']];
        }

        $stmt = $pdo->prepare(
            'UPDATE customers SET name = ?, email = ?, updated_at = NOW() WHERE id = ? AND is_active = 1'
        );
        $stmt->execute([$name, $email, $customerId]);
        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'error' => 'Account not found or inactive.'];
        }

        return self::loginExisting($customerId);
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
        /** @var string $name */
        $name = $validated['name'];
        /** @var string $email */
        $email = $validated['email'];

        Install::ensureConnection();
        $pdo = Database::pdo();
        $lockKey = 'at_customer_reg_' . $mobile;
        $lockStmt = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockKey) . ', 10)');
        $gotLock = (int) ($lockStmt ? $lockStmt->fetchColumn() : 0);
        if ($gotLock !== 1) {
            return ['ok' => false, 'error' => 'Please try again in a moment.'];
        }

        try {
            $existing = self::customerRowByMobile($mobile);
            if ($existing !== null) {
                $ctx = self::uiContextForMobile($mobile);
                if ($ctx['mode'] === self::MODE_RETURNING) {
                    return self::loginExisting((int) $existing['id']);
                }

                return self::completeLegacyProfile((int) $existing['id'], $input);
            }

            $dup = $pdo->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
            $dup->execute([$email]);
            if ($dup->fetchColumn()) {
                return ['ok' => false, 'field_errors' => ['email' => 'This email is already used by another account.']];
            }

            $pdo->beginTransaction();
            try {
                $ins = $pdo->prepare('INSERT INTO customers (mobile, name, email) VALUES (?, ?, ?)');
                $ins->execute([$mobile, $name, $email]);
                $customerId = (int) $pdo->lastInsertId();
                self::recordRegistrationConsent($pdo, $customerId);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
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
            $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockKey) . ')');
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

    private static function recordRegistrationConsent(PDO $pdo, int $customerId): void
    {
        if (!self::consentTableReady()) {
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
}
