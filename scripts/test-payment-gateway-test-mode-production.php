<?php

declare(strict_types=1);

/**
 * Payment test_mode persistence, env normalization, production sandbox blocking, Cashfree API base.
 * CLI: php7.3 scripts/run-in-disposable-db.php scripts/test-payment-gateway-test-mode-production.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;

function ok(bool $cond, string $msg): void
{
    global $fail;
    echo ($cond ? 'OK' : 'FAIL') . ': ' . $msg . "\n";
    if (!$cond) {
        $fail++;
    }
}

/** @param array<string, mixed> $patch */
function withConfig(array $patch, callable $fn): void
{
    $orig = App::config();
    App::setConfig(array_replace_recursive($orig, $patch));
    try {
        $fn();
    } finally {
        App::setConfig($orig);
        AppSettings::resetCache();
    }
}

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();

foreach (['production', 'prod', 'PROD'] as $envVal) {
    withConfig(['app' => ['env' => $envVal]], static function () use ($envVal): void {
        ok(AppEnvironment::isProduction(), 'isProduction for app.env=' . $envVal);
    });
}

withConfig(['app' => ['env' => 'staging']], static function (): void {
    ok(!AppEnvironment::isProduction(), 'staging is not production');
});

$origTestMode = AppSettings::get('payment.test_mode', '1');
$origActive = AppSettings::get('payment.active_gateway', 'simulate');
$origCfEnabled = AppSettings::get('cashfree.enabled', '0');
$origCfAppId = AppSettings::get('cashfree.app_id', '');

AppSettings::set('payment.test_mode', '1');
AppSettings::set('payment.active_gateway', 'cashfree');
AppSettings::set('cashfree.enabled', '1');
AppSettings::set('cashfree.app_id', 'CFTESTAPP12345678');
AppSettings::set(PaymentGatewaySettingsService::KEY_CASHFREE_SECRET_KEY, 'cf_test_secret_' . bin2hex(random_bytes(4)), true);
AppSettings::resetCache();

// Unchecked checkbox simulation: hidden field only (test_mode=0).
$saveOff = PaymentGatewaySettingsService::saveFromPost([
    'active_gateway' => 'cashfree',
    'test_mode' => '0',
    'cashfree_enabled' => '1',
    'cashfree_app_id' => 'CFTESTAPP12345678',
]);
AppSettings::resetCache();
ok(!empty($saveOff['ok']), 'saveFromPost persists test_mode=0 from hidden field');
ok(AppSettings::get('payment.test_mode', '1') === '0', 'payment.test_mode stored as 0 after save');

withConfig(['app' => ['env' => 'prod']], static function (): void {
    AppSettings::set('payment.test_mode', '1');
    AppSettings::resetCache();
    ok(PaymentGatewayConfig::storedTestModeEnabled(), 'stored test mode reads as enabled');
    ok(!PaymentGatewayConfig::usesSandboxPayments(), 'production never uses sandbox payments for API');
    $ready = AppEnvironment::assertProductionPaymentsReady();
    ok(empty($ready['ok']), 'production blocks checkout when stored test mode on');
    ok(
        isset($ready['message']) && str_contains((string) $ready['message'], 'sandbox'),
        'production blocker message mentions sandbox/test mode'
    );
    try {
        PaymentGatewayConfig::cashfreePgApiBase();
        ok(false, 'cashfreePgApiBase should throw on production with stored test mode');
    } catch (RuntimeException $e) {
        ok(true, 'cashfreePgApiBase throws on production with stored test mode');
    }
    ok(
        PaymentGatewayConfig::cashfreePgApiBaseForRefundOperations() === 'https://sandbox.cashfree.com/pg',
        'refund operations use sandbox base on production when stored test mode on'
    );
    ok(
        str_contains(PaymentGatewayConfig::cashfreeApiHostLabel(), 'checkout blocked'),
        'admin API host label warns when production has stored test mode'
    );

    AppSettings::set('payment.test_mode', '0');
    AppSettings::resetCache();
    ok(PaymentGatewayConfig::cashfreePgApiBase() === 'https://api.cashfree.com/pg', 'production live API base when test mode off');
    ok(PaymentGatewayConfig::cashfreeApiHostLabel() === 'production', 'admin API host label is production when test mode off');
});

withConfig(['app' => ['env' => 'local']], static function (): void {
    AppSettings::set('payment.test_mode', '1');
    AppSettings::resetCache();
    ok(PaymentGatewayConfig::usesSandboxPayments(), 'local uses sandbox when stored test mode on');
    ok(
        PaymentGatewayConfig::cashfreePgApiBase() === 'https://sandbox.cashfree.com/pg',
        'local sandbox API base when test mode on'
    );
    AppSettings::set('payment.test_mode', '0');
    AppSettings::resetCache();
    ok(
        PaymentGatewayConfig::cashfreePgApiBase() === 'https://api.cashfree.com/pg',
        'local production API base when test mode off'
    );
});

withConfig(['app' => ['env' => 'prod']], static function (): void {
    AppSettings::set('payment.test_mode', '0');
    AppSettings::resetCache();
    $reject = PaymentGatewaySettingsService::saveFromPost([
        'active_gateway' => 'cashfree',
        'test_mode' => '1',
        'cashfree_enabled' => '1',
        'cashfree_app_id' => 'CFTESTAPP12345678',
    ]);
    ok(empty($reject['ok']), 'production rejects save that enables test mode');
    ok(AppSettings::get('payment.test_mode', '0') === '0', 'production save rejection does not flip stored test mode');
});

// Restore settings touched by this script.
AppSettings::set('payment.test_mode', $origTestMode ?? '1');
AppSettings::set('payment.active_gateway', $origActive ?? 'simulate');
AppSettings::set('cashfree.enabled', $origCfEnabled ?? '0');
AppSettings::set('cashfree.app_id', $origCfAppId ?? '');
AppSettings::resetCache();

$gatewaysHtml = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/settings/gateways.php');
ok(str_contains($gatewaysHtml, 'type="hidden" name="test_mode" value="0"'), 'gateways form includes hidden test_mode=0');

echo $fail === 0 ? "All payment test-mode production checks passed.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
