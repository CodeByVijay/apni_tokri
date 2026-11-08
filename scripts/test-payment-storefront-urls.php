<?php

declare(strict_types=1);

/**
 * Payment return / webhook URLs must use admin canonical Website URL, not a stale app.base_url host.
 * CLI: php scripts/test-payment-storefront-urls.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
$ok = static function (bool $c, string $m) use (&$fail): void {
    echo ($c ? 'PASS: ' : 'FAIL: ') . $m . "\n";
    if (!$c) {
        $fail++;
    }
};

$cfg = App::config();

$setAppBase = static function (string $baseUrl, string $urlPath = '', string $env = 'production') use ($cfg): void {
    App::setConfig(array_replace_recursive($cfg, [
        'app' => [
            'base_url' => $baseUrl,
            'url_path' => $urlPath,
            'env' => $env,
        ],
    ]));
};

$setAppBase('https://veggietokri.in');
$pay = SeoUrl::storefrontAbsolute('/payment.php?order=AT-DEMO');
$ok(str_starts_with($pay, 'https://veggietokri.com/payment.php?order='), 'Cashfree return uses default .com when app.base_url is .in');
$ok(!str_contains($pay, 'veggietokri.in'), 'Return URL does not use NXDOMAIN .in host');

$wh = SeoUrl::storefrontAbsolute('/webhooks/cashfree.php');
$ok(str_starts_with($wh, 'https://veggietokri.com/webhooks/cashfree.php'), 'Cashfree notify_url uses canonical host');

$setAppBase('http://localhost/Apni_Tokri', '/Apni_Tokri', 'local');
$local = SeoUrl::storefrontAbsolute('/payment.php?order=DEV');
$ok(str_starts_with($local, 'http://localhost/Apni_Tokri/payment.php'), 'Local dev keeps app.base_url for payment links');

$sub = SeoUrl::storefrontAbsolute('/subscription-payment.php?sub=S-1');
$ok(str_contains($sub, 'subscription-payment.php?sub='), 'Subscription payment return path builds');

$svc = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/payment/service.php');
$ok(str_contains($svc, 'SeoUrl::storefrontAbsolute'), 'PaymentService uses storefrontAbsolute for Cashfree return');
$cf = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/payment/cashfree.php');
$ok(str_contains($cf, 'SeoUrl::storefrontAbsolute'), 'CashfreeGateway notify uses storefrontAbsolute');

if (Install::isSchemaReady()) {
  try {
        Install::ensureConnection();
        $origWebsite = AppSettings::get(BusinessSettings::KEY_WEBSITE_URL, '');
        AppSettings::set(BusinessSettings::KEY_WEBSITE_URL, 'https://canonical.example.test/');
        AppSettings::resetCache();
        $setAppBase('https://veggietokri.in');
        $custom = SeoUrl::storefrontAbsolute('/payment.php?order=X');
        $ok(str_starts_with($custom, 'https://canonical.example.test/payment.php'), 'Admin Website URL overrides wrong app.base_url');
        AppSettings::set(BusinessSettings::KEY_WEBSITE_URL, $origWebsite);
        AppSettings::resetCache();
    } catch (Throwable $e) {
        fwrite(STDERR, 'SKIP DB: ' . $e->getMessage() . "\n");
    }
} else {
    fwrite(STDERR, "SKIP DB: schema not ready (admin override test).\n");
}

App::setConfig($cfg);

exit($fail > 0 ? 1 : 0);
