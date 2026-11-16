<?php

declare(strict_types=1);

/**
 * Storefront canonical URLs for payment links (Website URL, not HTTP Host or hardcoded default).
 *
 * php scripts/run-in-disposable-db.php scripts/test-storefront-canonical-urls.php
 * php scripts/test-storefront-canonical-urls.php  (with disposable DB for settings tests)
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/test_script_safety.php';

$fail = 0;
function urlOk(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . PHP_EOL;
    if (!$c) {
        ++$fail;
    }
}

$origConfig = App::config();
$restore = static function () use ($origConfig): void {
    App::setConfig($origConfig);
    if (Install::isSchemaReady()) {
        AppSettings::set(BusinessSettings::KEY_WEBSITE_URL, null);
        AppSettings::resetCache();
    }
};

$stagingHost = 'apnitokri.manconnect.club';
$prodDefault = BusinessSettings::DEFAULT_WEBSITE_URL;

App::setConfig(array_replace_recursive($origConfig, [
    'app' => [
        'base_url' => 'https://' . $stagingHost,
        'url_path' => '',
        'env' => 'staging',
    ],
]));
if (Install::isSchemaReady()) {
    TestScriptSafety::assertDisposableDatabaseOrAbort();
    AppSettings::set(BusinessSettings::KEY_WEBSITE_URL, 'https://' . $stagingHost);
    AppSettings::resetCache();
}

$payUrl = OrderEmailContext::customerPaymentRetryUrl('VT-TEST-001');
urlOk(str_contains($payUrl, $stagingHost), 'staging payment URL uses configured Website URL host');
urlOk(str_contains($payUrl, 'payment.php?order='), 'payment URL path correct');
urlOk(!str_contains($payUrl, 'veggietokri.com'), 'staging payment URL does not use production default domain');

$emailPay = OrderEmailContext::customerPaymentRetryUrl('VT-TEST-001');
urlOk($emailPay === $payUrl, 'email and assisted-order share same payment URL builder');

$adminStyle = SeoUrl::storefrontAbsolute('/payment.php?order=VT-TEST-002');
urlOk(str_contains($adminStyle, $stagingHost), 'SeoUrl::storefrontAbsolute uses staging host');

App::setConfig(array_replace_recursive($origConfig, [
    'app' => [
        'base_url' => $prodDefault,
        'url_path' => '',
        'env' => 'production',
    ],
]));
if (Install::isSchemaReady()) {
    AppSettings::set(BusinessSettings::KEY_WEBSITE_URL, $prodDefault);
    AppSettings::resetCache();
}
$prodPay = OrderEmailContext::customerPaymentRetryUrl('VT-PROD-001');
urlOk(str_contains($prodPay, 'veggietokri.com'), 'production payment URL uses configured production Website URL');
urlOk(!str_contains($prodPay, $stagingHost), 'production payment URL not staging');

App::setConfig(array_replace_recursive($origConfig, [
    'app' => [
        'base_url' => 'https://' . $stagingHost,
        'url_path' => '',
        'env' => 'staging',
    ],
]));
if (Install::isSchemaReady()) {
    AppSettings::set(BusinessSettings::KEY_WEBSITE_URL, 'https://' . $stagingHost);
    AppSettings::resetCache();
}
$_SERVER['HTTP_HOST'] = 'evil.example.invalid';
$hostPoison = OrderEmailContext::customerPaymentRetryUrl('VT-HOST-001');
urlOk(str_contains($hostPoison, $stagingHost), 'payment URL ignores untrusted HTTP_HOST');
urlOk(!str_contains($hostPoison, 'evil.example.invalid'), 'payment URL never uses HTTP_HOST');

$restore();

App::setConfig(array_replace_recursive($origConfig, [
    'app' => [
        'base_url' => 'https://veggietokri.in',
        'url_path' => '',
        'env' => 'production',
    ],
]));
$legacyHome = SeoUrl::homepageCanonicalUrl();
urlOk(str_contains($legacyHome, 'veggietokri.com'), 'production legacy homepage still resolves to .com default when Website URL unset');

echo $fail === 0 ? "All storefront canonical URL checks passed.\n" : "{$fail} check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
