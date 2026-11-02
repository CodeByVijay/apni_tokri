<?php

declare(strict_types=1);

/**
 * Database-backed SMTP configuration (AppSettings + SecretStore for password).
 */
final class SmtpSettingsService
{
    public const KEY_ENABLED = 'smtp.enabled';
    public const KEY_HOST = 'smtp.host';
    public const KEY_PORT = 'smtp.port';
    public const KEY_ENCRYPTION = 'smtp.encryption';
    public const KEY_AUTH_ENABLED = 'smtp.auth_enabled';
    public const KEY_USERNAME = 'smtp.username';
    public const KEY_PASSWORD = 'smtp.password';
    public const KEY_FROM_EMAIL = 'smtp.from_email';
    public const KEY_FROM_NAME = 'smtp.from_name';
    public const KEY_TIMEOUT = 'smtp.timeout_seconds';

    public const ENCRYPTION_NONE = 'none';
    public const ENCRYPTION_SSL = 'ssl';
    public const ENCRYPTION_STARTTLS = 'starttls';

    /** @return list<string> */
    public static function encryptionModes(): array
    {
        return [self::ENCRYPTION_NONE, self::ENCRYPTION_SSL, self::ENCRYPTION_STARTTLS];
    }

    /**
     * Safe values for admin forms (no password).
     *
     * @return array{
     *   enabled: bool,
     *   host: string,
     *   port: int,
     *   encryption: string,
     *   auth_enabled: bool,
     *   username: string,
     *   from_email: string,
     *   from_name: string,
     *   timeout_seconds: int,
     *   has_password: bool,
     *   configured: bool
     * }
     */
    public static function adminFormState(): array
    {
        $enabled = (AppSettings::get(self::KEY_ENABLED, '0') ?? '0') === '1';
        $host = trim(AppSettings::get(self::KEY_HOST, '') ?? '');
        $port = (int) (AppSettings::get(self::KEY_PORT, '587') ?? '587');
        $encryption = (string) (AppSettings::get(self::KEY_ENCRYPTION, self::ENCRYPTION_STARTTLS) ?? self::ENCRYPTION_STARTTLS);
        if (!in_array($encryption, self::encryptionModes(), true)) {
            $encryption = self::ENCRYPTION_STARTTLS;
        }
        $authEnabled = (AppSettings::get(self::KEY_AUTH_ENABLED, '1') ?? '1') === '1';
        $username = trim(AppSettings::get(self::KEY_USERNAME, '') ?? '');
        $fromEmail = trim(AppSettings::get(self::KEY_FROM_EMAIL, '') ?? '');
        $fromName = trim(AppSettings::get(self::KEY_FROM_NAME, '') ?? '');
        $timeout = (int) (AppSettings::get(self::KEY_TIMEOUT, '15') ?? '15');
        if ($timeout < 1) {
            $timeout = 15;
        }
        if ($timeout > 120) {
            $timeout = 120;
        }
        $hasPassword = self::hasStoredPassword();

        return [
            'enabled' => $enabled,
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'auth_enabled' => $authEnabled,
            'username' => $username,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'timeout_seconds' => $timeout,
            'has_password' => $hasPassword,
            'configured' => self::isConfigured($enabled, $host, $fromEmail, $authEnabled, $username, $hasPassword),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message?: string, errors?: array<string, string>}
     */
    public static function saveFromRequest(array $post): array
    {
        if (!Install::isSchemaReady()) {
            return ['ok' => false, 'message' => 'Database schema is not ready.'];
        }

        $enabled = !empty($post['enabled']);
        $host = trim((string) ($post['host'] ?? ''));
        $portRaw = trim((string) ($post['port'] ?? ''));
        $encryption = strtolower(trim((string) ($post['encryption'] ?? self::ENCRYPTION_NONE)));
        $authEnabled = !empty($post['auth_enabled']);
        $username = trim((string) ($post['username'] ?? ''));
        $fromEmail = trim((string) ($post['from_email'] ?? ''));
        $fromName = self::sanitizeDisplayName((string) ($post['from_name'] ?? ''));
        $timeoutRaw = trim((string) ($post['timeout_seconds'] ?? '15'));
        $newPassword = trim((string) ($post['password'] ?? ''));

        $errors = self::validateFields(
            $enabled,
            $host,
            $portRaw,
            $encryption,
            $authEnabled,
            $username,
            $fromEmail,
            $timeoutRaw,
            $newPassword,
            false
        );
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'message' => 'Please correct the highlighted fields.'];
        }

        $port = (int) $portRaw;
        $timeout = (int) $timeoutRaw;

        AppSettings::setMany([
            self::KEY_ENABLED => ['value' => $enabled ? '1' : '0'],
            self::KEY_HOST => ['value' => $host],
            self::KEY_PORT => ['value' => (string) $port],
            self::KEY_ENCRYPTION => ['value' => $encryption],
            self::KEY_AUTH_ENABLED => ['value' => $authEnabled ? '1' : '0'],
            self::KEY_USERNAME => ['value' => $username],
            self::KEY_FROM_EMAIL => ['value' => $fromEmail],
            self::KEY_FROM_NAME => ['value' => $fromName],
            self::KEY_TIMEOUT => ['value' => (string) $timeout],
        ]);

        if ($newPassword !== '') {
            AppSettings::set(self::KEY_PASSWORD, $newPassword, true);
        } elseif ($authEnabled && !self::hasStoredPassword()) {
            return [
                'ok' => false,
                'errors' => ['password' => 'SMTP password is required when authentication is enabled.'],
                'message' => 'Please correct the highlighted fields.',
            ];
        }

        AuditLog::write('settings.smtp', null, null, [
            'enabled' => $enabled ? 1 : 0,
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'auth_enabled' => $authEnabled ? 1 : 0,
            'from_email' => $fromEmail,
            'timeout_seconds' => $timeout,
            'password_updated' => $newPassword !== '' ? 1 : 0,
        ]);

        return ['ok' => true, 'message' => 'SMTP settings saved.'];
    }

    /**
     * @return array{ok: bool, message?: string, errors?: array<string, string>}
     */
    public static function sendTestEmail(string $recipient): array
    {
        $recipient = trim($recipient);
        if (!Validation::email($recipient)) {
            return ['ok' => false, 'errors' => ['test_recipient' => 'Enter a valid recipient email address.']];
        }
        if (self::containsHeaderInjection($recipient)) {
            return ['ok' => false, 'message' => 'Invalid recipient address.'];
        }

        $mailer = new SmtpMailer();
        $site = SeoUrl::siteName();
        $subject = 'Apni Tokri SMTP test — ' . $site;
        $text = "This is a test message from {$site}.\n\nIf you received this email, SMTP is configured correctly.\n";
        $result = $mailer->send($recipient, $subject, $text, null);
        if (!empty($result['ok'])) {
            AuditLog::write('settings.smtp_test', null, null, ['recipient_domain' => self::emailDomain($recipient)]);

            return ['ok' => true, 'message' => 'Test email accepted by the mail server.'];
        }

        AuditLog::write('settings.smtp_test_failed', null, null, ['recipient_domain' => self::emailDomain($recipient)]);

        return [
            'ok' => false,
            'message' => SmtpMailer::publicErrorMessage((string) ($result['error'] ?? 'send_failed')),
        ];
    }

    /**
     * Runtime configuration for SmtpMailer (includes decrypted password when stored).
     *
     * @return array{
     *   enabled: bool,
     *   host: string,
     *   port: int,
     *   encryption: string,
     *   auth_enabled: bool,
     *   username: string,
     *   password: string,
     *   from_email: string,
     *   from_name: string,
     *   timeout_seconds: int
     * }
     */
    public static function runtimeConfig(): array
    {
        $state = self::adminFormState();
        $password = '';
        if ($state['auth_enabled']) {
            $password = AppSettings::getSecret(self::KEY_PASSWORD, '') ?? '';
        }

        return [
            'enabled' => $state['enabled'],
            'host' => $state['host'],
            'port' => $state['port'],
            'encryption' => $state['encryption'],
            'auth_enabled' => $state['auth_enabled'],
            'username' => $state['username'],
            'password' => $password,
            'from_email' => $state['from_email'],
            'from_name' => $state['from_name'],
            'timeout_seconds' => $state['timeout_seconds'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    public static function runtimeConfigWithOverrides(array $overrides): array
    {
        $base = self::runtimeConfig();
        foreach ($overrides as $k => $v) {
            $base[$k] = $v;
        }

        return $base;
    }

    public static function readinessError(?array $config = null): ?string
    {
        $cfg = $config ?? self::runtimeConfig();
        if (empty($cfg['enabled'])) {
            return 'SMTP is disabled.';
        }
        $host = trim((string) ($cfg['host'] ?? ''));
        if ($host === '') {
            return 'SMTP host is not configured.';
        }
        $from = trim((string) ($cfg['from_email'] ?? ''));
        if ($from === '' || !Validation::email($from)) {
            return 'Sender email is not configured.';
        }
        if (!empty($cfg['auth_enabled'])) {
            if (trim((string) ($cfg['username'] ?? '')) === '') {
                return 'SMTP username is not configured.';
            }
            if (trim((string) ($cfg['password'] ?? '')) === '') {
                return 'SMTP password is not configured.';
            }
        }

        return null;
    }

    public static function hasStoredPassword(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT setting_value, is_encrypted FROM settings WHERE setting_key = ? LIMIT 1'
            );
            $stmt->execute([self::KEY_PASSWORD]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return false;
            }

            return trim((string) ($row['setting_value'] ?? '')) !== '';
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    public static function validateFields(
        bool $enabled,
        string $host,
        string $portRaw,
        string $encryption,
        bool $authEnabled,
        string $username,
        string $fromEmail,
        string $timeoutRaw,
        string $newPassword,
        bool $isTestOnly
    ): array {
        $errors = [];
        if (!$enabled && !$isTestOnly) {
            return [];
        }

        if ($enabled || $isTestOnly) {
            if ($host === '') {
                $errors['host'] = 'SMTP host is required.';
            } elseif (strlen($host) > 253 || !self::isValidHost($host)) {
                $errors['host'] = 'Enter a valid SMTP host name.';
            }

            if ($portRaw === '' || !ctype_digit($portRaw)) {
                $errors['port'] = 'Port must be a number between 1 and 65535.';
            } else {
                $port = (int) $portRaw;
                if ($port < 1 || $port > 65535) {
                    $errors['port'] = 'Port must be between 1 and 65535.';
                }
            }

            if (!in_array($encryption, self::encryptionModes(), true)) {
                $errors['encryption'] = 'Choose a valid encryption mode.';
            }

            if ($fromEmail === '') {
                $errors['from_email'] = 'Sender email is required.';
            } elseif (!Validation::email($fromEmail)) {
                $errors['from_email'] = 'Enter a valid sender email address.';
            }

            if ($timeoutRaw === '' || !ctype_digit($timeoutRaw)) {
                $errors['timeout_seconds'] = 'Timeout must be a number of seconds (1–120).';
            } else {
                $t = (int) $timeoutRaw;
                if ($t < 1 || $t > 120) {
                    $errors['timeout_seconds'] = 'Timeout must be between 1 and 120 seconds.';
                }
            }

            if ($authEnabled) {
                if ($username === '') {
                    $errors['username'] = 'Username is required when authentication is enabled.';
                } elseif (strlen($username) > 255) {
                    $errors['username'] = 'Username is too long.';
                }
                if ($newPassword === '' && !self::hasStoredPassword() && !$isTestOnly) {
                    $errors['password'] = 'SMTP password is required when authentication is enabled.';
                }
            }
        }

        return $errors;
    }

    private static function isConfigured(
        bool $enabled,
        string $host,
        string $fromEmail,
        bool $authEnabled,
        string $username,
        bool $hasPassword
    ): bool {
        if (!$enabled) {
            return false;
        }
        if ($host === '' || $fromEmail === '') {
            return false;
        }
        if ($authEnabled && ($username === '' || !$hasPassword)) {
            return false;
        }

        return true;
    }

    private static function isValidHost(string $host): bool
    {
        if (preg_match('/[\s\r\n\0]/', $host)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        return (bool) preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-.]*[a-zA-Z0-9])?$/', $host);
    }

    public static function sanitizeDisplayName(string $name): string
    {
        $name = str_replace(["\r", "\n", "\0"], '', $name);

        return trim(mb_substr($name, 0, 200));
    }

    public static function containsHeaderInjection(string $value): bool
    {
        return preg_match('/[\r\n\0]/', $value) === 1;
    }

    private static function emailDomain(string $email): string
    {
        $pos = strrpos($email, '@');

        return $pos !== false ? substr($email, $pos + 1) : '';
    }
}
