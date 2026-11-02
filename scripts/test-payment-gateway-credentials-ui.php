<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$fail = 0;
$testSecret = 'rzp_test_secret_' . bin2hex(random_bytes(8));
$replaceSecret = 'rzp_replace_' . bin2hex(random_bytes(8));

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

AppSettings::set(PaymentGatewaySettingsService::KEY_RAZORPAY_KEY_SECRET, $testSecret, true);
AppSettings::resetCache();
ok(PaymentGatewaySettingsService::hasStoredSecret(PaymentGatewaySettingsService::KEY_RAZORPAY_KEY_SECRET), 'Saved secret detected server-side');
ok(AppSettings::getSecret(PaymentGatewaySettingsService::KEY_RAZORPAY_KEY_SECRET, '') === $testSecret, 'Secret decrypts server-side only');

PaymentGatewaySettingsService::saveFromPost([
    'active_gateway' => 'razorpay',
    'razorpay_key_id' => 'rzp_test_ui',
    'razorpay_key_secret' => '',
    'razorpay_webhook_secret' => '',
    'cashfree_app_id' => '',
    'cashfree_secret_key' => '',
    'cashfree_webhook_secret' => '',
]);
AppSettings::resetCache();
ok(AppSettings::getSecret(PaymentGatewaySettingsService::KEY_RAZORPAY_KEY_SECRET, '') === $testSecret, 'Blank POST retains existing Razorpay secret');

PaymentGatewaySettingsService::saveFromPost([
    'active_gateway' => 'razorpay',
    'razorpay_key_secret' => $replaceSecret,
]);
AppSettings::resetCache();
ok(AppSettings::getSecret(PaymentGatewaySettingsService::KEY_RAZORPAY_KEY_SECRET, '') === $replaceSecret, 'Non-empty POST replaces encrypted secret');

// HTML must not contain plaintext secrets
$_SESSION['admin_user_id'] = 1;
Permission::clearCache();
ob_start();
$credSaved = true;
$credId = 'test_secret';
$credName = 'test_secret';
$credLabel = 'Test secret';
require APNI_TOKRI_ROOT . '/includes/partials/admin/secret-credential-field.php';
$partialHtml = (string) ob_get_clean();
ok(str_contains($partialHtml, 'at-cred-badge--saved'), 'Saved partial shows Saved badge');
ok(str_contains($partialHtml, '••••••••••••'), 'Saved partial shows bullet mask');
ok(!str_contains($partialHtml, $replaceSecret), 'Partial HTML never contains stored secret');
ok(str_contains($partialHtml, 'data-secret-toggle'), 'Partial includes show/hide control');

ob_start();
$credSaved = false;
require APNI_TOKRI_ROOT . '/includes/partials/admin/secret-credential-field.php';
$emptyPartial = (string) ob_get_clean();
ok(str_contains($emptyPartial, 'at-cred-badge--empty'), 'Unsaved partial shows Not configured');
ok(!str_contains($emptyPartial, '••••••••••••'), 'Unsaved partial has no mask row');

AppSettings::set(PaymentGatewaySettingsService::KEY_CASHFREE_SECRET_KEY, 'cf_secret_test', true);
AppSettings::resetCache();
ok(PaymentGatewaySettingsService::secretPresence()['cashfree_secret_key'], 'Cashfree secret presence works');

// Cleanup test secrets (leave empty or restore - use delete by setting empty only works for non-secret keys)
AppSettings::set(PaymentGatewaySettingsService::KEY_RAZORPAY_KEY_SECRET, $replaceSecret, true);

echo $fail === 0 ? "PASS: payment gateway credential UI.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
