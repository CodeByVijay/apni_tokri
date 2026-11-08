<?php

declare(strict_types=1);

/**
 * Guards payment-related test scripts from mutating production settings or DB without isolation.
 */
final class TestScriptSafety
{
    public static function assertSafeToMutateSettings(): void
    {
        if (getenv('APNI_TOKRI_DISPOSABLE_DB') === '1' || getenv('APNI_TOKRI_ALLOW_SETTINGS_MUTATION') === '1') {
            return;
        }
        if (AppEnvironment::isProduction()) {
            fwrite(STDERR, "ABORT: Refusing to mutate AppSettings on production. "
                . "Use scripts/run-in-disposable-db.php or set APNI_TOKRI_ALLOW_SETTINGS_MUTATION=1 only on local dev.\n");
            exit(2);
        }
    }

    /** @return array<string, string|null> */
    public static function snapshotPaymentGatewaySettings(): array
    {
        return [
            'payment.test_mode' => AppSettings::get('payment.test_mode', null),
            'payment.active_gateway' => AppSettings::get('payment.active_gateway', null),
            'cashfree.enabled' => AppSettings::get('cashfree.enabled', null),
            'cashfree.app_id' => AppSettings::get('cashfree.app_id', null),
        ];
    }

    /** @param array<string, string|null> $snap */
    public static function restorePaymentGatewaySettings(array $snap): void
    {
        foreach ($snap as $key => $val) {
            if ($val === null) {
                continue;
            }
            if ($key === PaymentGatewaySettingsService::KEY_CASHFREE_SECRET_KEY) {
                AppSettings::set($key, $val, true);
            } else {
                AppSettings::set($key, (string) $val);
            }
        }
        AppSettings::resetCache();
    }

    public static function stashCashfreeSecret(): ?string
    {
        return AppSettings::getSecret('cashfree.secret_key', null);
    }

    public static function restoreCashfreeSecret(?string $secret): void
    {
        if ($secret !== null) {
            AppSettings::set(PaymentGatewaySettingsService::KEY_CASHFREE_SECRET_KEY, $secret, true);
            AppSettings::resetCache();
        }
    }
}
