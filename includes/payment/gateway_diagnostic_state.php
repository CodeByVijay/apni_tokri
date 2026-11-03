<?php

declare(strict_types=1);

/**
 * Admin-facing gateway configuration vs API verification state (no secrets, no PII).
 * Updated only from real customer payment initiation (live HTTP), not from health probes.
 */
final class PaymentGatewayDiagnosticState
{
    public const CONFIG_NOT_CONFIGURED = 'not_configured';
    public const CONFIG_INVALID_FORMAT = 'invalid_format';
    public const CONFIG_ENV_MISMATCH = 'environment_mismatch';
    public const CONFIG_READY = 'config_ready';

    public const API_AUTH_FAILED = 'auth_failed';
    public const API_VERIFIED = 'api_verified';

    private static function settingKey(string $gateway): string
    {
        return 'payment.diag.' . $gateway;
    }

    public static function shouldPersistFromLiveApi(): bool
    {
        return PaymentHttp::$testHandler === null;
    }

    /**
     * Record outcome of a real gateway order-create call during customer checkout initiation.
     */
    public static function recordInitAttempt(string $gateway, int $httpStatus, bool $orderCreated, string $referenceId): void
    {
        if (!in_array($gateway, ['razorpay', 'cashfree'], true)) {
            return;
        }
        if (!self::shouldPersistFromLiveApi()) {
            return;
        }
        if (!Install::isSchemaReady()) {
            return;
        }
        $ready = PaymentGatewayConfig::readinessFor($gateway);
        if (empty($ready['ok'])) {
            return;
        }

        $result = self::API_VERIFIED;
        if (!$orderCreated) {
            if ($httpStatus !== 401) {
                return;
            }
            $result = self::API_AUTH_FAILED;
        }

        $payload = [
            'fp' => self::configFingerprint($gateway),
            'result' => $result,
            'http' => $httpStatus,
            'at' => date(DATE_ATOM),
            'ref' => substr(preg_replace('/[^A-Z0-9-]/', '', strtoupper($referenceId)) ?? '', 0, 24),
        ];
        AppSettings::set(self::settingKey($gateway), json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public static function invalidate(string $gateway): void
    {
        if (!Install::isSchemaReady()) {
            return;
        }
        AppSettings::set(self::settingKey($gateway), null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function loadRaw(string $gateway): ?array
    {
        $raw = AppSettings::get(self::settingKey($gateway), null);
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    public static function configFingerprint(string $gateway): string
    {
        if ($gateway === 'razorpay') {
            return hash('sha256', implode("\n", [
                'razorpay',
                (string) (AppSettings::get('razorpay.enabled', '0') ?? '0'),
                (string) (AppSettings::get('payment.test_mode', '1') ?? '1'),
                RazorpayGateway::keyId(),
                trim(AppSettings::getSecret('razorpay.key_secret', '') ?? '') !== '' ? 'secret:1' : 'secret:0',
            ]));
        }
        if ($gateway === 'cashfree') {
            return hash('sha256', implode("\n", [
                'cashfree',
                (string) (AppSettings::get('cashfree.enabled', '0') ?? '0'),
                (string) (AppSettings::get('payment.test_mode', '1') ?? '1'),
                CashfreeGateway::appId(),
                trim(AppSettings::getSecret('cashfree.secret_key', '') ?? '') !== '' ? 'secret:1' : 'secret:0',
            ]));
        }

        return '';
    }

    /**
     * Admin / summary view — never implies gateway auth from credentials alone.
     *
     * @return array{
     *   config_state: string,
     *   config_label: string,
     *   api_state: string|null,
     *   api_label: string|null,
     *   detail: string,
     *   verified_at: string|null,
     *   reference_id: string|null,
     *   http_status: int|null,
     *   config_ready: bool
     * }
     */
    public static function adminView(string $gateway): array
    {
        $config = self::resolveConfigState($gateway);
        $base = [
            'config_state' => $config['state'],
            'config_label' => $config['label'],
            'api_state' => null,
            'api_label' => null,
            'detail' => $config['detail'],
            'verified_at' => null,
            'reference_id' => null,
            'http_status' => null,
            'config_ready' => $config['state'] === self::CONFIG_READY,
        ];

        if ($config['state'] !== self::CONFIG_READY) {
            return $base;
        }

        $raw = self::loadRaw($gateway);
        $fp = self::configFingerprint($gateway);
        if ($raw === null || ($raw['fp'] ?? '') !== $fp) {
            $base['detail'] = 'Configuration ready – gateway authentication has not yet been verified.';

            return $base;
        }

        $result = (string) ($raw['result'] ?? '');
        $at = (string) ($raw['at'] ?? '');
        $ref = (string) ($raw['ref'] ?? '');
        $http = isset($raw['http']) ? (int) $raw['http'] : null;

        $base['verified_at'] = $at !== '' ? $at : null;
        $base['reference_id'] = $ref !== '' ? $ref : null;
        $base['http_status'] = $http;

        if ($result === self::API_VERIFIED) {
            $base['api_state'] = self::API_VERIFIED;
            $base['api_label'] = 'Gateway initiation successfully verified';
            $base['detail'] = 'API authentication and order creation were verified'
                . ($at !== '' ? ' at ' . self::formatAdminTime($at) : '')
                . ($ref !== '' ? '. Reference: ' . $ref : '.');
        } elseif ($result === self::API_AUTH_FAILED && $http === 401) {
            $base['api_state'] = self::API_AUTH_FAILED;
            $base['api_label'] = 'Authentication failed (HTTP 401)';
            $base['detail'] = 'The gateway rejected API credentials (HTTP 401)'
                . ($at !== '' ? ' at ' . self::formatAdminTime($at) : '')
                . ($ref !== '' ? '. Reference: ' . $ref : '.')
                . ' Update keys in the dashboard and try a customer checkout again.';
        } else {
            $base['detail'] = 'Configuration ready – gateway authentication has not yet been verified.';
        }

        return $base;
    }

    /**
     * @return array{state: string, label: string, detail: string}
     */
    private static function resolveConfigState(string $gateway): array
    {
        $ready = PaymentGatewayConfig::readinessFor($gateway);
        if (!empty($ready['ok'])) {
            return [
                'state' => self::CONFIG_READY,
                'label' => 'Configuration ready',
                'detail' => 'Configuration ready – gateway authentication has not yet been verified.',
            ];
        }
        $code = (string) ($ready['code'] ?? '');
        if ($code === 'mode_mismatch') {
            return [
                'state' => self::CONFIG_ENV_MISMATCH,
                'label' => 'Environment mismatch',
                'detail' => (string) ($ready['message'] ?? 'Credentials do not match Test/Live mode.'),
            ];
        }
        if (in_array($code, ['invalid_key_id', 'invalid_app_id'], true)) {
            return [
                'state' => self::CONFIG_INVALID_FORMAT,
                'label' => 'Invalid credential format',
                'detail' => (string) ($ready['message'] ?? 'Fix credential format in the fields below.'),
            ];
        }

        return [
            'state' => self::CONFIG_NOT_CONFIGURED,
            'label' => 'Not configured',
            'detail' => (string) ($ready['message'] ?? 'Enable the gateway and save valid credentials.'),
        ];
    }

    private static function formatAdminTime(string $iso): string
    {
        $ts = strtotime($iso);

        return $ts !== false ? date('j M Y, g:i a', $ts) : $iso;
    }
}
