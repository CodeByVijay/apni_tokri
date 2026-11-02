<?php

declare(strict_types=1);

/**
 * Database-backed SMS configuration (AppSettings + SecretStore for API key).
 */
final class SmsSettingsService
{
    public const KEY_ENABLED = 'sms.enabled';
    public const KEY_PROVIDER = 'sms.provider';
    public const KEY_API_URL = 'sms.api_url';
    public const KEY_API_KEY = 'sms.api_key';
    public const KEY_SENDER_ID = 'sms.sender_id';
    public const KEY_OPERATION_MODE = 'sms.operation_mode';
    public const KEY_TEST_FIXED_OTP = 'sms.test.fixed_otp';

    public const PROVIDER_SIMULATE = 'simulate';
    public const PROVIDER_HTTP = 'http';

    /** @return list<string> */
    public static function supportedProviders(): array
    {
        return [self::PROVIDER_SIMULATE, self::PROVIDER_HTTP];
    }

    /** @return list<string> */
    public static function deliveryTemplateKeys(): array
    {
        return ['order_confirmed', 'ready', 'dispatched', 'delivered', 'failed'];
    }

    /**
     * @return array{
     *   enabled: bool,
     *   provider: string,
     *   api_url: string,
     *   sender_id: string,
     *   has_api_key: bool,
     *   http_allowed_status_codes: string,
     *   http_require_json: bool,
     *   http_success_json_path: string,
     *   http_success_json_value: string,
     *   http_message_id_json_path: string,
     *   http_timeout_seconds: int,
     *   templates: array<string, string>,
     *   dlt: array<string, string>
     * }
     */
    public static function adminFormState(): array
    {
        $provider = (string) (AppSettings::get(self::KEY_PROVIDER, self::PROVIDER_SIMULATE) ?? self::PROVIDER_SIMULATE);
        if (!in_array($provider, self::supportedProviders(), true)) {
            $provider = self::PROVIDER_SIMULATE;
        }

        $templates = [];
        foreach (array_merge(self::deliveryTemplateKeys(), ['otp']) as $key) {
            $templates[$key] = AppSettings::get('sms.template.' . $key, '') ?? '';
        }
        $dlt = [];
        foreach (array_merge(self::deliveryTemplateKeys(), ['otp']) as $key) {
            $dlt[$key] = AppSettings::get('sms.dlt.' . $key, '') ?? '';
        }

        return [
            'enabled' => (AppSettings::get(self::KEY_ENABLED, '0') ?? '0') === '1',
            'operation_mode' => OtpSmsService::operationMode(),
            'configured_operation_mode' => strtolower(trim((string) (AppSettings::get(self::KEY_OPERATION_MODE, OtpSmsService::MODE_DISABLED) ?? OtpSmsService::MODE_DISABLED))),
            'test_fixed_otp' => trim(AppSettings::get(self::KEY_TEST_FIXED_OTP, '') ?? ''),
            'environment' => SmsEnvironmentService::environmentLabel(),
            'allows_test_mode' => SmsEnvironmentService::allowsTestMode(),
            'is_production' => SmsEnvironmentService::isProduction(),
            'diagnostics' => SmsDiagnosticsService::adminSnapshot(),
            'provider' => $provider,
            'api_url' => trim(AppSettings::get(self::KEY_API_URL, '') ?? ''),
            'sender_id' => trim(AppSettings::get(self::KEY_SENDER_ID, '') ?? ''),
            'has_api_key' => self::hasStoredApiKey(),
            'http_allowed_status_codes' => AppSettings::get('sms.http.allowed_status_codes', '200') ?? '200',
            'http_require_json' => (AppSettings::get('sms.http.require_json', '1') ?? '1') === '1',
            'http_success_json_path' => AppSettings::get('sms.http.success_json_path', 'status') ?? 'status',
            'http_success_json_value' => AppSettings::get('sms.http.success_json_value', 'success') ?? 'success',
            'http_message_id_json_path' => AppSettings::get('sms.http.message_id_json_path', 'message_id') ?? 'message_id',
            'http_timeout_seconds' => max(3, min(60, (int) (AppSettings::get('sms.http.timeout_seconds', '15') ?? 15))),
            'templates' => $templates,
            'dlt' => $dlt,
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
        $provider = trim((string) ($post['provider'] ?? self::PROVIDER_SIMULATE));
        $operationMode = strtolower(trim((string) ($post['operation_mode'] ?? OtpSmsService::MODE_DISABLED)));
        if (!in_array($operationMode, [OtpSmsService::MODE_TEST, OtpSmsService::MODE_LIVE, OtpSmsService::MODE_DISABLED], true)) {
            $operationMode = OtpSmsService::MODE_DISABLED;
        }
        if ($operationMode === OtpSmsService::MODE_TEST && !SmsEnvironmentService::allowsTestMode()) {
            return [
                'ok' => false,
                'errors' => ['operation_mode' => 'Test Mode is not allowed in production.'],
                'message' => 'Test Mode cannot be enabled in this environment.',
            ];
        }

        $testFixedOtp = preg_replace('/\D+/', '', (string) ($post['test_fixed_otp'] ?? '')) ?? '';
        if ($testFixedOtp !== '' && !preg_match('/^\d{6}$/', $testFixedOtp)) {
            return ['ok' => false, 'errors' => ['test_fixed_otp' => 'Enter a 6-digit test OTP or leave blank for random codes.']];
        }

        if ($operationMode === OtpSmsService::MODE_LIVE) {
            $enabled = true;
            $provider = self::PROVIDER_HTTP;
        } elseif ($operationMode === OtpSmsService::MODE_TEST) {
            $enabled = false;
            $provider = self::PROVIDER_SIMULATE;
        } else {
            $enabled = false;
        }

        $apiUrl = trim((string) ($post['api_url'] ?? ''));
        $senderId = trim((string) ($post['sender_id'] ?? ''));
        $newApiKey = trim((string) ($post['api_key'] ?? ''));

        $errors = self::validateSaveFields($enabled, $provider, $apiUrl, $senderId, $newApiKey);
        $msgIdPath = trim((string) ($post['http_message_id_json_path'] ?? ''));
        if ($provider === self::PROVIDER_HTTP && $msgIdPath === '') {
            $errors['http_message_id_json_path'] = 'Message ID JSON path is required for HTTP SMS.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'message' => 'Please correct the highlighted fields.'];
        }

        $batch = [
            self::KEY_OPERATION_MODE => ['value' => $operationMode],
            self::KEY_TEST_FIXED_OTP => ['value' => $operationMode === OtpSmsService::MODE_TEST ? $testFixedOtp : ''],
            self::KEY_ENABLED => ['value' => $enabled ? '1' : '0'],
            self::KEY_PROVIDER => ['value' => $provider],
            self::KEY_API_URL => ['value' => $apiUrl],
            self::KEY_SENDER_ID => ['value' => $senderId],
            'sms.http.allowed_status_codes' => ['value' => trim((string) ($post['http_allowed_status_codes'] ?? '200'))],
            'sms.http.require_json' => ['value' => !empty($post['http_require_json']) ? '1' : '0'],
            'sms.http.success_json_path' => ['value' => trim((string) ($post['http_success_json_path'] ?? ''))],
            'sms.http.success_json_value' => ['value' => trim((string) ($post['http_success_json_value'] ?? ''))],
            'sms.http.message_id_json_path' => ['value' => trim((string) ($post['http_message_id_json_path'] ?? ''))],
            'sms.http.timeout_seconds' => ['value' => (string) max(3, min(60, (int) ($post['http_timeout_seconds'] ?? 15)))],
        ];

        foreach (array_merge(self::deliveryTemplateKeys(), ['otp']) as $key) {
            $field = 'template_' . $key;
            if (array_key_exists($field, $post)) {
                $batch['sms.template.' . $key] = ['value' => trim((string) ($post[$field] ?? ''))];
            }
            $dltField = 'dlt_' . $key;
            if (array_key_exists($dltField, $post)) {
                $batch['sms.dlt.' . $key] = ['value' => trim((string) ($post[$dltField] ?? ''))];
            }
        }

        AppSettings::setMany($batch);

        if ($newApiKey !== '') {
            AppSettings::set(self::KEY_API_KEY, $newApiKey, true);
        } elseif ($provider === self::PROVIDER_HTTP && !self::hasStoredApiKey()) {
            return [
                'ok' => false,
                'errors' => ['api_key' => 'API key is required for the HTTP SMS provider.'],
                'message' => 'Please correct the highlighted fields.',
            ];
        }

        AuditLog::write('settings.sms', null, null, [
            'enabled' => $enabled ? 1 : 0,
            'provider' => $provider,
            'operation_mode' => $operationMode,
            'api_key_updated' => $newApiKey !== '' ? 1 : 0,
        ]);

        return ['ok' => true, 'message' => 'SMS settings saved.'];
    }

    /**
     * @return array{ok: bool, message?: string, errors?: array<string, string>}
     */
    public static function sendTestSms(string $mobileRaw): array
    {
        $mobile = Validation::normalizeMobileIndia(trim($mobileRaw));
        if ($mobile === null) {
            return ['ok' => false, 'errors' => ['test_mobile' => 'Enter a valid 10-digit Indian mobile number.']];
        }

        if (OtpSmsService::operationMode() !== OtpSmsService::MODE_LIVE) {
            return ['ok' => false, 'message' => 'Test SMS is available only when SMS operation mode is Live.'];
        }

        if (!OtpSmsService::isOutboundAllowed()) {
            return ['ok' => false, 'message' => 'SMS is disabled. Enable Live mode with valid configuration.'];
        }

        $ready = self::readinessErrorForSend();
        if ($ready !== null) {
            return ['ok' => false, 'message' => self::publicSendError($ready)];
        }

        $adapter = SmsGatewayFactory::adapter();
        $sender = trim(AppSettings::get(self::KEY_SENDER_ID, '') ?? '');
        $message = 'Apni Tokri SMS test message. If you received this, SMS settings are working.';
        $result = $adapter->send($mobile, $message, $sender, null);
        SmsDiagnosticsService::recordFromGateway(
            (string) (AppSettings::get(self::KEY_PROVIDER, '') ?? ''),
            isset($result['http_status']) ? (int) $result['http_status'] : null,
            $result,
            'admin_test',
            $mobile
        );

        if (!empty($result['ok']) && trim((string) ($result['message_id'] ?? '')) !== '') {
            AuditLog::write('settings.sms_test', null, null, ['mobile_suffix' => substr($mobile, -4)]);

            return ['ok' => true, 'message' => 'Test SMS accepted by the provider.'];
        }

        AuditLog::write('settings.sms_test_failed', null, null, ['mobile_suffix' => substr($mobile, -4)]);

        return ['ok' => false, 'message' => self::publicSendError((string) ($result['error'] ?? 'send_failed'))];
    }

    public static function readinessErrorForSend(): ?string
    {
        $provider = AppSettings::get(self::KEY_PROVIDER, self::PROVIDER_SIMULATE) ?? self::PROVIDER_SIMULATE;
        if ($provider === self::PROVIDER_SIMULATE) {
            return null;
        }
        if ((AppSettings::get(self::KEY_ENABLED, '0') ?? '0') !== '1') {
            return 'SMS is disabled.';
        }
        $url = trim(AppSettings::get(self::KEY_API_URL, '') ?? '');
        if ($url === '' || self::validateHttpApiUrl($url) !== null) {
            return 'SMS HTTP endpoint is not configured.';
        }
        if (trim(AppSettings::get(self::KEY_SENDER_ID, '') ?? '') === '') {
            return 'Sender ID is not configured.';
        }
        if ((AppSettings::getSecret(self::KEY_API_KEY, '') ?? '') === '') {
            return 'SMS API key is not configured.';
        }
        if (!SmsHttpResponsePolicy::isConfigured()) {
            return 'HTTP SMS success contract is not configured.';
        }

        return null;
    }

    public static function publicSendError(string $internal): string
    {
        $t = strtolower($internal);
        if (strpos($t, 'disabled') !== false) {
            return 'SMS is disabled. Enable it in settings before sending.';
        }
        if (strpos($t, 'not configured') !== false || strpos($t, 'contract') !== false) {
            return 'SMS is not fully configured. Check provider, URL, sender ID, and HTTP response mapping.';
        }
        if (strpos($t, 'rejected') !== false || strpos($t, 'status') !== false) {
            return 'The SMS provider rejected the message.';
        }
        if (strpos($t, 'timeout') !== false || strpos($t, 'transport') !== false) {
            return 'Could not reach the SMS provider. Check URL and network connectivity.';
        }

        return 'Could not send the SMS. Check settings and try again.';
    }

    public static function hasStoredApiKey(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1'
            );
            $stmt->execute([self::KEY_API_KEY]);
            $val = $stmt->fetchColumn();

            return $val !== false && trim((string) $val) !== '';
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    public static function validateSaveFields(
        bool $enabled,
        string $provider,
        string $apiUrl,
        string $senderId,
        string $newApiKey
    ): array {
        $errors = [];
        if (!in_array($provider, self::supportedProviders(), true)) {
            $errors['provider'] = 'Unsupported SMS provider.';
        }

        if ($provider === self::PROVIDER_HTTP && ($enabled || $apiUrl !== '' || $senderId !== '')) {
            if ($apiUrl === '') {
                $errors['api_url'] = 'HTTP API URL is required.';
            } else {
                $urlErr = self::validateHttpApiUrl($apiUrl);
                if ($urlErr !== null) {
                    $errors['api_url'] = $urlErr;
                }
            }
            if ($senderId === '') {
                $errors['sender_id'] = 'Sender ID is required for HTTP SMS.';
            } elseif (strlen($senderId) > 11) {
                $errors['sender_id'] = 'Sender ID is too long.';
            }
            if ($newApiKey === '' && !self::hasStoredApiKey()) {
                $errors['api_key'] = 'API key is required for the HTTP SMS provider.';
            }
        }

        return $errors;
    }

    public static function validateHttpApiUrl(string $url): ?string
    {
        if ($url === '' || strlen($url) > 2048) {
            return 'Enter a valid HTTPS API URL.';
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return 'Enter a valid HTTPS API URL.';
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return 'Enter a valid HTTPS API URL.';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            return 'SMS API URL must use HTTPS.';
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || self::isBlockedSmsHost($host)) {
            return 'SMS API URL host is not allowed.';
        }

        return null;
    }

    private static function isBlockedSmsHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.local')) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return !filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        return false;
    }
}
