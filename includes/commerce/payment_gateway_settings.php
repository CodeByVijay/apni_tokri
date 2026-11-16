<?php

declare(strict_types=1);

/**
 * Admin payment gateway settings (non-secret reads only; secrets never exposed to HTML).
 */
final class PaymentGatewaySettingsService
{
    public const KEY_RAZORPAY_KEY_ID = 'razorpay.key_id';
    public const KEY_RAZORPAY_KEY_SECRET = 'razorpay.key_secret';
    public const KEY_RAZORPAY_WEBHOOK_SECRET = 'razorpay.webhook_secret';
    public const KEY_CASHFREE_APP_ID = 'cashfree.app_id';
    public const KEY_CASHFREE_SECRET_KEY = 'cashfree.secret_key';
    public const KEY_CASHFREE_WEBHOOK_SECRET = 'cashfree.webhook_secret';

    public static function hasStoredSecret(string $settingKey): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }

        return trim(AppSettings::getSecret($settingKey, '') ?? '') !== '';
    }

    /**
     * @return array{
     *   razorpay_key_secret: bool,
     *   razorpay_webhook_secret: bool,
     *   cashfree_secret_key: bool,
     *   cashfree_webhook_secret: bool
     * }
     */
    public static function secretPresence(): array
    {
        return [
            'razorpay_key_secret' => self::hasStoredSecret(self::KEY_RAZORPAY_KEY_SECRET),
            'razorpay_webhook_secret' => self::hasStoredSecret(self::KEY_RAZORPAY_WEBHOOK_SECRET),
            'cashfree_secret_key' => self::hasStoredSecret(self::KEY_CASHFREE_SECRET_KEY),
            'cashfree_webhook_secret' => self::hasStoredSecret(self::KEY_CASHFREE_WEBHOOK_SECRET),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function gatewayStatusSummary(): array
    {
        $active = PaymentGatewayConfig::activeGateway();
        $testMode = PaymentGatewayConfig::storedTestModeEnabled();
        $rzDiag = PaymentGatewayDiagnosticState::adminView('razorpay');
        $cfDiag = PaymentGatewayDiagnosticState::adminView('cashfree');

        return [
            'active' => $active,
            'test_mode' => $testMode,
            'razorpay' => [
                'enabled' => RazorpayGateway::isEnabled(),
                'configured' => RazorpayGateway::isConfigured(),
                'operational' => PaymentGatewayConfig::isRazorpayOperational(),
                'key_id_set' => RazorpayGateway::keyId() !== '',
                'secret_set' => self::hasStoredSecret(self::KEY_RAZORPAY_KEY_SECRET),
                'diagnostic' => $rzDiag,
            ],
            'cashfree' => [
                'enabled' => CashfreeGateway::isEnabled(),
                'configured' => CashfreeGateway::isConfigured(),
                'operational' => PaymentGatewayConfig::isCashfreeOperational(),
                'app_id_set' => CashfreeGateway::appId() !== '',
                'secret_set' => self::hasStoredSecret(self::KEY_CASHFREE_SECRET_KEY),
                'diagnostic' => $cfDiag,
            ],
            'simulate_fallback' => PaymentGatewayConfig::useSimulatedCheckout(),
        ];
    }

    /**
     * Persist gateway form (blank secret fields retain existing encrypted values).
     *
     * @param array<string, mixed> $post
     * @return array{ok: bool, message?: string}
     */
    public static function saveFromPost(array $post): array
    {
        $requested = (string) ($post['active_gateway'] ?? 'simulate');
        if (!in_array($requested, ['simulate', 'razorpay', 'cashfree'], true)) {
            $requested = 'simulate';
        }
        if (AppEnvironment::isProduction()) {
            if ($requested === 'simulate') {
                return ['ok' => false, 'message' => 'Simulated checkout cannot be enabled in production.'];
            }
            if (array_key_exists('test_mode', $post) && (string) $post['test_mode'] === '1') {
                return ['ok' => false, 'message' => 'Payment test mode must stay off in production.'];
            }
        }

        if (array_key_exists('test_mode', $post)) {
            AppSettings::set('payment.test_mode', (string) $post['test_mode'] === '1' ? '1' : '0');
        }
        if (array_key_exists('razorpay_enabled', $post)) {
            AppSettings::set('razorpay.enabled', !empty($post['razorpay_enabled']) ? '1' : '0');
        }
        if (array_key_exists('cashfree_enabled', $post)) {
            AppSettings::set('cashfree.enabled', !empty($post['cashfree_enabled']) ? '1' : '0');
        }
        if (array_key_exists('razorpay_key_id', $post)) {
            AppSettings::set('razorpay.key_id', trim((string) $post['razorpay_key_id']));
        }
        if (array_key_exists('cashfree_app_id', $post)) {
            AppSettings::set(self::KEY_CASHFREE_APP_ID, trim((string) $post['cashfree_app_id']));
        }

        $secretFields = [
            self::KEY_RAZORPAY_KEY_SECRET => 'razorpay_key_secret',
            self::KEY_RAZORPAY_WEBHOOK_SECRET => 'razorpay_webhook_secret',
            self::KEY_CASHFREE_SECRET_KEY => 'cashfree_secret_key',
            self::KEY_CASHFREE_WEBHOOK_SECRET => 'cashfree_webhook_secret',
        ];
        foreach ($secretFields as $key => $postName) {
            $value = trim((string) ($post[$postName] ?? ''));
            if ($value !== '') {
                AppSettings::set($key, $value, true);
                if ($key === self::KEY_RAZORPAY_KEY_SECRET || $key === self::KEY_RAZORPAY_WEBHOOK_SECRET) {
                    PaymentGatewayDiagnosticState::invalidate('razorpay');
                }
                if ($key === self::KEY_CASHFREE_SECRET_KEY || $key === self::KEY_CASHFREE_WEBHOOK_SECRET) {
                    PaymentGatewayDiagnosticState::invalidate('cashfree');
                }
            }
        }

        if (array_key_exists('test_mode', $post)) {
            PaymentGatewayDiagnosticState::invalidate('razorpay');
            PaymentGatewayDiagnosticState::invalidate('cashfree');
        }
        if (array_key_exists('razorpay_enabled', $post) || array_key_exists('razorpay_key_id', $post)) {
            PaymentGatewayDiagnosticState::invalidate('razorpay');
        }
        if (array_key_exists('cashfree_enabled', $post) || array_key_exists('cashfree_app_id', $post)) {
            PaymentGatewayDiagnosticState::invalidate('cashfree');
        }

        AppSettings::resetCache();

        if ($requested !== 'simulate') {
            $ready = PaymentGatewayConfig::readinessFor($requested);
            if (!$ready['ok']) {
                return ['ok' => false, 'message' => (string) ($ready['message'] ?? 'Selected gateway is not ready.')];
            }
        }

        AppSettings::set('payment.active_gateway', $requested);
        AuditLog::write('settings.payment_gateways', null, null, ['gateway' => $requested]);

        return ['ok' => true];
    }
}
