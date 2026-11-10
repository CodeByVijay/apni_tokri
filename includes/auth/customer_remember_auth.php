<?php

declare(strict_types=1);

/**
 * Optional 30-day customer remember-login (VT_REMEMBER cookie).
 * Separate from PHP session cookie; persisted storefront + DB migration only.
 *
 * Cookie path matches storefront scope (url_base() or '/'), consistent for issue/clear.
 * setcookie() success is not guaranteed to reach the client (proxies, output order).
 */
final class CustomerRememberAuth
{
    public const COOKIE_NAME = 'VT_REMEMBER';

    private const MAX_AGE_SECONDS = 2592000;

    private const SELECTOR_BYTES = 12;

    private const VALIDATOR_BYTES = 32;

    private const LOCK_WAIT_SECONDS = 5;

    public static function tablesReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $n = (int) Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_remember_tokens'"
            )->fetchColumn();

            return $n > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function enabled(): bool
    {
        return AppEnvironment::usePersistedStorefront() && self::tablesReady();
    }

    public static function cookiePath(): string
    {
        $base = url_base();

        return $base !== '' ? $base : '/';
    }

    public static function hashValidator(string $validatorHex): string
    {
        return hash('sha256', $validatorHex);
    }

    /**
     * Issue a new remember token for the customer (opt-in after OTP). Does not revoke other devices.
     */
    public static function issueForCustomer(int $customerId): bool
    {
        if ($customerId <= 0 || !self::enabled()) {
            return false;
        }
        if (headers_sent()) {
            error_log('CustomerRememberAuth::issueForCustomer: headers already sent; cannot set remember cookie.');

            return false;
        }
        try {
            Install::ensureConnection();
            $pdo = Database::pdo();
            if (!self::customerMayRemember($customerId, $pdo)) {
                return false;
            }
            $selector = bin2hex(random_bytes(self::SELECTOR_BYTES));
            $validator = bin2hex(random_bytes(self::VALIDATOR_BYTES));
            $hash = self::hashValidator($validator);
            $pdo->prepare(
                'INSERT INTO customer_remember_tokens (customer_id, selector, token_hash, expires_at)
                 VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
            )->execute([$customerId, $selector, $hash, self::MAX_AGE_SECONDS]);
            $tokenId = (int) $pdo->lastInsertId();
            if (!self::sendCookie($selector, $validator, time() + self::MAX_AGE_SECONDS)) {
                $pdo->prepare('DELETE FROM customer_remember_tokens WHERE id = ?')->execute([$tokenId]);
                error_log('CustomerRememberAuth::issueForCustomer: removed orphan token after cookie issuance failed.');

                return false;
            }

            return true;
        } catch (Throwable $e) {
            error_log('CustomerRememberAuth::issueForCustomer: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Restore customer session from VT_REMEMBER when PHP session has no valid customer identity.
     */
    public static function maybeRestoreFromCookie(): void
    {
        if (PHP_SAPI === 'cli' || !self::enabled()) {
            return;
        }
        if (CustomerAuth::check()) {
            return;
        }
        if (self::isSkippableRequest()) {
            return;
        }

        $raw = self::readCookieRaw();
        if ($raw === null) {
            return;
        }
        $parsed = self::parseCookieValue($raw);
        if ($parsed === null) {
            self::clearCookie();

            return;
        }

        $lockKey = 'at_crt_restore_' . $parsed['selector'];
        $pdo = null;
        try {
            Install::ensureConnection();
            $pdo = Database::pdo();
        } catch (Throwable $e) {
            error_log('CustomerRememberAuth::maybeRestoreFromCookie: database unavailable.');

            return;
        }

        $gotLock = (int) ($pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockKey) . ', ' . self::LOCK_WAIT_SECONDS . ')')->fetchColumn() ?: 0);
        if ($gotLock !== 1) {
            error_log('CustomerRememberAuth::maybeRestoreFromCookie: remember restore lock timeout.');

            return;
        }

        try {
            if (CustomerAuth::check()) {
                return;
            }

            $validation = self::validateForRestore($pdo, $parsed['selector'], $parsed['validator']);
            if ($validation['clear_cookie']) {
                self::clearCookie();

                return;
            }
            if ($validation['customer_id'] === null) {
                return;
            }

            $customerId = $validation['customer_id'];
            $rowId = $validation['row_id'];
            $expectedHash = $validation['token_hash'];

            try {
                CustomerAuth::loginById($customerId);
                CartService::mergeGuestIntoCustomer($customerId);
            } catch (Throwable $e) {
                error_log('CustomerRememberAuth::maybeRestoreFromCookie: login or cart merge failed.');
                throw $e;
            }

            if (!headers_sent()) {
                self::rotateTokenAfterSuccessfulRestore($pdo, $rowId, $expectedHash, $parsed['selector']);
            } else {
                error_log('CustomerRememberAuth::maybeRestoreFromCookie: headers already sent; session restored without token rotation.');
            }
        } catch (Throwable $e) {
            error_log('CustomerRememberAuth::maybeRestoreFromCookie: ' . $e->getMessage());
        } finally {
            $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockKey) . ')');
        }
    }

    /** Revoke the token presented in the current request cookie (current device only). */
    public static function revokeCurrentFromRequest(): void
    {
        if (!self::tablesReady()) {
            self::clearCookie();

            return;
        }
        $parsed = self::parseCookieValue(self::readCookieRaw());
        if ($parsed === null) {
            self::clearCookie();

            return;
        }
        try {
            Install::ensureConnection();
            Database::pdo()->prepare(
                'UPDATE customer_remember_tokens SET revoked_at = NOW()
                 WHERE selector = ? AND revoked_at IS NULL'
            )->execute([$parsed['selector']]);
        } catch (Throwable $e) {
            error_log('CustomerRememberAuth::revokeCurrentFromRequest: ' . $e->getMessage());
        }
        self::clearCookie();
    }

    /** Revoke all remember tokens for a customer (deactivation, etc.). */
    public static function revokeAllForCustomer(int $customerId): void
    {
        if ($customerId <= 0 || !self::tablesReady()) {
            return;
        }
        try {
            Install::ensureConnection();
            Database::pdo()->prepare(
                'UPDATE customer_remember_tokens SET revoked_at = NOW()
                 WHERE customer_id = ? AND revoked_at IS NULL'
            )->execute([$customerId]);
        } catch (Throwable $e) {
            error_log('CustomerRememberAuth::revokeAllForCustomer: ' . $e->getMessage());
        }
    }

    /**
     * @return array{selector: string, validator: string}|null
     */
    public static function parseCookieValue(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $parts = explode('.', $raw, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$selector, $validator] = $parts;
        if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
            return null;
        }

        return ['selector' => $selector, 'validator' => $validator];
    }

    private static function readCookieRaw(): ?string
    {
        $name = self::COOKIE_NAME;
        if (!isset($_COOKIE[$name]) || !is_string($_COOKIE[$name])) {
            return null;
        }
        $v = trim($_COOKIE[$name]);

        return $v !== '' ? $v : null;
    }

    /**
     * @return array{customer_id: ?int, row_id: int, token_hash: string, clear_cookie: bool}
     */
    private static function validateForRestore(PDO $pdo, string $selector, string $validator): array
    {
        $empty = ['customer_id' => null, 'row_id' => 0, 'token_hash' => '', 'clear_cookie' => false];
        try {
            $stmt = $pdo->prepare(
                'SELECT id, customer_id, token_hash, revoked_at
                 FROM customer_remember_tokens
                 WHERE selector = ?
                 LIMIT 1'
            );
            $stmt->execute([$selector]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return array_merge($empty, ['clear_cookie' => true]);
            }
            if ($row['revoked_at'] !== null) {
                return array_merge($empty, ['clear_cookie' => true]);
            }
            $expStmt = $pdo->prepare(
                'SELECT id, customer_id, token_hash
                 FROM customer_remember_tokens
                 WHERE selector = ? AND revoked_at IS NULL AND expires_at > NOW()
                 LIMIT 1'
            );
            $expStmt->execute([$selector]);
            $live = $expStmt->fetch(PDO::FETCH_ASSOC);
            if (!$live) {
                return array_merge($empty, ['clear_cookie' => true]);
            }
            $expected = (string) $live['token_hash'];
            $actual = self::hashValidator($validator);
            if (!Security::hashEquals($expected, $actual)) {
                return $empty;
            }
            $customerId = (int) $live['customer_id'];
            if ($customerId <= 0 || !self::customerMayRemember($customerId, $pdo)) {
                return array_merge($empty, ['clear_cookie' => true]);
            }

            return [
                'customer_id' => $customerId,
                'row_id' => (int) $live['id'],
                'token_hash' => $expected,
                'clear_cookie' => false,
            ];
        } catch (Throwable $e) {
            error_log('CustomerRememberAuth::validateForRestore: database error.');

            return $empty;
        }
    }

    private static function rotateTokenAfterSuccessfulRestore(PDO $pdo, int $rowId, string $expectedHash, string $selector): void
    {
        try {
            $newValidator = bin2hex(random_bytes(self::VALIDATOR_BYTES));
            $newHash = self::hashValidator($newValidator);
            $expires = time() + self::MAX_AGE_SECONDS;
            $upd = $pdo->prepare(
                'UPDATE customer_remember_tokens
                 SET token_hash = ?, last_used_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
                 WHERE id = ? AND token_hash = ? AND revoked_at IS NULL AND expires_at > NOW()'
            );
            $upd->execute([$newHash, self::MAX_AGE_SECONDS, $rowId, $expectedHash]);
            if ($upd->rowCount() !== 1) {
                error_log('CustomerRememberAuth::rotateTokenAfterSuccessfulRestore: concurrent rotation; token left unchanged.');

                return;
            }
            if (!self::sendCookie($selector, $newValidator, $expires)) {
                $pdo->prepare(
                    'UPDATE customer_remember_tokens SET token_hash = ? WHERE id = ? AND token_hash = ?'
                )->execute([$expectedHash, $rowId, $newHash]);
                error_log(
                    'CustomerRememberAuth::rotateTokenAfterSuccessfulRestore: cookie not sent; DB rotation rolled back.'
                );
            }
        } catch (Throwable $e) {
            error_log('CustomerRememberAuth::rotateTokenAfterSuccessfulRestore: ' . $e->getMessage());
        }
    }

    private static function customerMayRemember(int $customerId, PDO $pdo): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT is_active FROM customers WHERE id = ? LIMIT 1');
            $stmt->execute([$customerId]);
            $v = $stmt->fetchColumn();
            if ($v === false) {
                return false;
            }

            return (int) $v === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function sendCookie(string $selector, string $validator, int $expiresUnix): bool
    {
        if (headers_sent()) {
            return false;
        }
        $value = $selector . '.' . $validator;
        $ok = setcookie(self::COOKIE_NAME, $value, [
            'expires' => $expiresUnix,
            'path' => self::cookiePath(),
            'secure' => Security::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return $ok;
    }

    public static function clearCookie(): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => self::cookiePath(),
            'secure' => Security::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function isSkippableRequest(): bool
    {
        if (self::isAdminRequest()) {
            return true;
        }
        $path = self::requestPath();
        if ($path === '') {
            return false;
        }
        if (preg_match('#/(assets|storage/uploads)/#', $path)) {
            return true;
        }

        return false;
    }

    /** True when the request targets admin UI or admin AJAX under /admin/. */
    public static function isAdminRequest(): bool
    {
        $path = self::requestPath();
        if ($path === '') {
            return false;
        }
        $base = url_base();
        if ($base !== '') {
            $prefix = $base . '/admin';
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }

            return false;
        }

        return preg_match('#^/admin(?:/|$)#', $path) === 1;
    }

    private static function requestPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($uri === '') {
            return '';
        }
        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }
}
