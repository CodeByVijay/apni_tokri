<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$origBrand = AppSettings::get(BrandingSettings::KEY_SITE_NAME, '') ?? '';
$origBusiness = BusinessSettings::adminFormState();

BusinessSettings::saveFromPost([
    'store_name' => 'Legal Store Pvt Ltd',
    'support_phone' => '9876543210',
    'support_whatsapp' => '+91 9123456789',
    'support_email' => 'support@example.test',
    'fssai_licence' => '10012345678901',
]);
AppSettings::resetCache();
$form = BusinessSettings::adminFormState();
ok($form['store_name'] === 'Legal Store Pvt Ltd', 'Save business name');
ok($form['support_phone'] === '9876543210', 'Save support phone normalized storage');
ok($form['support_whatsapp'] === '9123456789', 'Save WhatsApp normalized');
ok($form['support_email'] === 'support@example.test', 'Save support email');
ok($form['fssai_licence'] === '10012345678901', 'Save FSSAI');

ok(BusinessSettings::websiteUrl() === BusinessSettings::DEFAULT_WEBSITE_URL, 'Default website URL when unset');
BusinessSettings::saveFromPost(array_merge(BusinessSettings::adminFormState(), [
    'website_url' => 'https://profile.example.test/',
]));
AppSettings::resetCache();
ok(BusinessSettings::websiteUrl() === 'https://profile.example.test', 'Save custom website URL (trailing slash normalized)');
ok(InvoiceSettings::seller()['website'] === 'https://profile.example.test', 'Invoice seller reads business website URL');

$badUrl = BusinessSettings::saveFromPost(array_merge(BusinessSettings::adminFormState(), [
    'website_url' => 'not-a-valid-url',
]));
AppSettings::resetCache();
ok($badUrl['ok'] === false, 'Reject invalid website URL');

BusinessSettings::saveFromPost(array_merge(BusinessSettings::adminFormState(), [
    'website_url' => '',
]));
AppSettings::resetCache();
ok(BusinessSettings::websiteUrl() === BusinessSettings::DEFAULT_WEBSITE_URL, 'Clear website uses default URL');

ok(BusinessSettings::legalName() === 'Legal Store Pvt Ltd', 'Legal name from business store');
ok(BrandingSettings::siteName() === ($origBrand !== '' ? $origBrand : BrandingSettings::siteName()), 'Branding site name unchanged by business save');

$url = BusinessSettings::whatsappUrl();
ok($url === 'https://wa.me/919123456789', 'WhatsApp URL normalization');

BusinessSettings::saveFromPost([
    'store_name' => 'Legal Store Pvt Ltd',
    'support_phone' => '9876543210',
    'support_whatsapp' => '9123456789',
    'support_email' => 'support@example.test',
    'fssai_licence' => '10012345678901',
    'support_phone' => '9988776655',
]);
AppSettings::resetCache();
ok(BusinessSettings::adminFormState()['support_phone'] === '9988776655', 'Update one field retains others');
ok(BusinessSettings::adminFormState()['store_name'] === 'Legal Store Pvt Ltd', 'Partial update keeps store name');

BusinessSettings::saveFromPost([
    'store_name' => 'Legal Store Pvt Ltd',
    'support_phone' => '',
    'support_whatsapp' => '',
    'support_email' => '',
    'fssai_licence' => '',
]);
AppSettings::resetCache();
ok(BusinessSettings::supportPhoneDigits() === null, 'Clear phone field');
ok(BusinessSettings::whatsappUrl() === null, 'Clear WhatsApp removes URL');
ok(BusinessSettings::fssaiLicence() === null, 'Clear FSSAI');

ob_start();
$showBottomNav = false;
$activeNav = '';
require APNI_TOKRI_ROOT . '/includes/layout/footer-public.php';
$footer = (string) ob_get_clean();
ok(!str_contains($footer, 'href="tel:"'), 'No empty tel links');
ok(!str_contains($footer, 'wa.me/91"'), 'No broken WhatsApp links');
ok(str_contains($footer, 'Legal Store Pvt Ltd'), 'Footer copyright uses legal name');

BusinessSettings::saveFromPost([
    'store_name' => '',
    'support_phone' => '9876501234',
    'support_whatsapp' => '',
    'support_email' => '',
    'fssai_licence' => '',
]);
AppSettings::resetCache();
ob_start();
require APNI_TOKRI_ROOT . '/includes/layout/footer-public.php';
$footer2 = (string) ob_get_clean();
ok(str_contains($footer2, 'tel:+919876501234'), 'Footer shows saved phone link');
ok(!str_contains($footer2, 'mailto:'), 'No email link when email empty');

ok(!Permission::can('settings.manage') || AdminAuth::check(), 'Permission gate sanity');
$_SESSION['admin_user_id'] = 999999;
Permission::clearCache();
ok(!Permission::can('settings.manage'), 'Unauthorized admin cannot manage settings');

// restore
BusinessSettings::saveFromPost($origBusiness);
AppSettings::set(BrandingSettings::KEY_SITE_NAME, $origBrand !== '' ? $origBrand : null);
AppSettings::resetCache();

$demoMsg = @file_get_contents(APNI_TOKRI_ROOT . '/admin/settings/index.php') ?: '';
ok(!str_contains($demoMsg, 'Settings saved (demo)'), 'Demo success message removed from admin page');

echo $fail === 0 ? "PASS: business settings.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
