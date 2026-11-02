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
     * Persist gateway form (blank secret fields retain existing encrypted values).
     *
     * @param array<string, mixed> $post
     */
    public static function saveFromPost(array $post): void
    {
        AppSettings::setMany([
            'payment.active_gateway' => ['value' => (string) ($post['active_gateway'] ?? 'simulate')],
            'payment.test_mode' => ['value' => !empty($post['test_mode']) ? '1' : '0'],
            'razorpay.enabled' => ['value' => !empty($post['razorpay_enabled']) ? '1' : '0'],
            'cashfree.enabled' => ['value' => !empty($post['cashfree_enabled']) ? '1' : '0'],
            'razorpay.key_id' => ['value' => trim((string) ($post['razorpay_key_id'] ?? ''))],
        ]);

        $newCfId = trim((string) ($post['cashfree_app_id'] ?? ''));
        if ($newCfId !== '') {
            AppSettings::set(self::KEY_CASHFREE_APP_ID, $newCfId);
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
            }
        }

        AuditLog::write('settings.payment_gateways', null, null, ['gateway' => $post['active_gateway'] ?? '']);
    }
}
