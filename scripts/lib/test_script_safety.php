<?php

declare(strict_types=1);

/**
 * Guards payment-related test scripts from mutating production settings or DB without isolation.
 */
final class TestScriptSafety
{
    /** Refuse tests that mutate auth/admin state against production or protected DB names. */
    public static function assertDisposableDatabaseOrAbort(?string $databaseName = null): void
    {
        $name = $databaseName ?? (string) (App::config()['database']['name'] ?? '');
        if (getenv('APNI_TOKRI_DISPOSABLE_DB') === '1') {
            require_once dirname(__DIR__) . '/includes/disposable_db_runner.php';
            $expected = getenv('APNI_TOKRI_TEST_DB_NAME');
            if (is_string($expected) && $expected !== '' && $name !== $expected) {
                fwrite(STDERR, "ABORT: disposable DB name mismatch (config vs APNI_TOKRI_TEST_DB_NAME).\n");
                exit(2);
            }
            if (!disposable_is_tmp_db_name($name)) {
                fwrite(STDERR, "ABORT: APNI_TOKRI_DISPOSABLE_DB set but database name is not apni_tokri_tmp_*.\n");
                exit(2);
            }
            $original = getenv('APNI_TOKRI_ORIGINAL_DB_NAME');
            if (is_string($original) && $original !== '' && $name === $original) {
                fwrite(STDERR, "ABORT: disposable guard would run against application database.\n");
                exit(2);
            }

            return;
        }
        if ($name !== '' && preg_match('/^apni_tokri_(tmp|phase4|phase41|rl|sec_p1)_/i', $name)) {
            return;
        }
        $expected = getenv('APNI_EXPECTED_DB');
        if (is_string($expected) && $expected !== '' && hash_equals($expected, $name)) {
            fwrite(STDERR, "ABORT: Refusing test against protected database (APNI_EXPECTED_DB).\n");
            exit(2);
        }
        if (AppEnvironment::isProduction()) {
            fwrite(STDERR, "ABORT: Test requires a disposable database when app.env is production.\n");
            exit(2);
        }
        fwrite(STDERR, "ABORT: Run via scripts/run-in-disposable-db.php or use a throwaway database name prefix.\n");
        exit(2);
    }

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
