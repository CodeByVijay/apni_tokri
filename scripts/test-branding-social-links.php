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

/** @var array<string, string|null> */
$orig = [];
foreach (BrandingSocialLinks::allSettingKeys() as $key) {
    $orig[$key] = AppSettings::has($key) ? AppSettings::get($key, '') : null;
}

function restoreSocial(array $orig): void
{
    foreach ($orig as $key => $value) {
        if ($value === null) {
            Database::pdo()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$key]);
        } else {
            AppSettings::set($key, $value);
        }
    }
    AppSettings::resetCache();
}

foreach (BrandingSocialLinks::allSettingKeys() as $key) {
    Database::pdo()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$key]);
}
AppSettings::resetCache();
ok(BrandingSocialLinks::visibleFooterLinks() === [], 'No social links when all unset');

$ig = 'https://www.instagram.com/veggietokri/';
AppSettings::set(BrandingSocialLinks::KEY_INSTAGRAM, $ig);
AppSettings::resetCache();
$links = BrandingSocialLinks::visibleFooterLinks();
ok(count($links) === 1 && $links[0]['id'] === 'instagram', 'Instagram alone shows one icon');
ok(str_contains($links[0]['url'], 'instagram.com/veggietokri'), 'Instagram URL normalized');

AppSettings::set(BrandingSocialLinks::KEY_FACEBOOK, 'https://www.facebook.com/veggietokri');
AppSettings::set(BrandingSocialLinks::KEY_YOUTUBE, 'https://www.youtube.com/@veggietokri');
AppSettings::set(BrandingSocialLinks::KEY_X, 'https://twitter.com/veggietokri');
AppSettings::set(BrandingSocialLinks::KEY_WHATSAPP, '+919876543210');
AppSettings::resetCache();
ok(count(BrandingSocialLinks::visibleFooterLinks()) === 5, 'All five platforms visible when configured');

ob_start();
require APNI_TOKRI_ROOT . '/includes/layout/footer-public.php';
$footerAll = (string) ob_get_clean();
ok(str_contains($footerAll, 'at-footer-social'), 'Footer renders social container');
ok(str_contains($footerAll, 'aria-label="Visit our Instagram page"'), 'Instagram link has accessible label');
ok(substr_count($footerAll, 'at-footer-social__link') === 5, 'Footer renders five social anchors only');

AppSettings::set(BrandingSocialLinks::KEY_INSTAGRAM, '');
AppSettings::resetCache();
ob_start();
require APNI_TOKRI_ROOT . '/includes/layout/footer-public.php';
$footerNoIg = (string) ob_get_clean();
ok(!str_contains($footerNoIg, 'Visit our Instagram page'), 'Empty Instagram hides its icon');
ok(substr_count($footerNoIg, 'at-footer-social__link') === 4, 'Four icons after Instagram cleared');

foreach (BrandingSocialLinks::allSettingKeys() as $key) {
    AppSettings::set($key, '');
}
AppSettings::resetCache();
ob_start();
require APNI_TOKRI_ROOT . '/includes/layout/footer-public.php';
$footerNone = (string) ob_get_clean();
ok(!str_contains($footerNone, 'at-footer-social'), 'All empty hides entire social container');
ok(!str_contains($footerNone, 'at-footer-social__link'), 'No empty social anchor tags');

$bad = BrandingSocialLinks::validateFromPost([
    'social_instagram' => 'javascript:alert(1)',
    'social_facebook' => '',
    'social_youtube' => '',
    'social_whatsapp' => '',
    'social_x' => '',
]);
ok(isset($bad['errors']['social_instagram']), 'javascript: scheme rejected');

$badWa = BrandingSocialLinks::validateFromPost([
    'social_instagram' => '',
    'social_facebook' => '',
    'social_youtube' => '',
    'social_whatsapp' => '9876543210',
    'social_x' => '',
]);
ok(isset($badWa['errors']['social_whatsapp']), 'WhatsApp without + country code rejected');

$goodWa = BrandingSocialLinks::normalizePlatformInput('whatsapp', 'https://wa.me/919876543210');
ok($goodWa === 'https://wa.me/919876543210', 'WhatsApp wa.me link accepted');

$save = BrandingSettings::saveFromRequest([
    'site_name' => BrandingSettings::siteName(),
    'tagline' => BrandingSettings::tagline(),
    'default_seo_title' => '',
    'default_meta_description' => '',
    'logo_alt' => '',
    'footer_brand_description' => BrandingSettings::footerBrandDescriptionForForm(),
    'social_instagram' => 'https://www.instagram.com/onlyig/',
    'social_facebook' => 'https://www.facebook.com/keepfb',
    'social_youtube' => '',
    'social_whatsapp' => '',
    'social_x' => '',
], []);
ok(!empty($save['ok']), 'Branding save with social fields succeeds');
AppSettings::resetCache();
ok(trim(AppSettings::get(BrandingSocialLinks::KEY_INSTAGRAM, '') ?? '') !== '', 'Instagram saved');
ok(trim(AppSettings::get(BrandingSocialLinks::KEY_FACEBOOK, '') ?? '') === 'https://www.facebook.com/keepfb', 'Facebook preserved when updating Instagram');
ok(trim(AppSettings::get(BrandingSocialLinks::KEY_YOUTUBE, '') ?? '') === '', 'Empty YouTube clears icon');

$saveRemove = BrandingSettings::saveFromRequest([
    'site_name' => BrandingSettings::siteName(),
    'tagline' => BrandingSettings::tagline(),
    'default_seo_title' => '',
    'default_meta_description' => '',
    'logo_alt' => '',
    'footer_brand_description' => BrandingSettings::footerBrandDescriptionForForm(),
    'social_instagram' => '',
    'social_facebook' => 'https://www.facebook.com/keepfb',
    'social_youtube' => '',
    'social_whatsapp' => '',
    'social_x' => '',
], []);
ok(!empty($saveRemove['ok']), 'Save after removing Instagram URL succeeds');
AppSettings::resetCache();
ok(!AppSettings::has(BrandingSocialLinks::KEY_INSTAGRAM) || trim(AppSettings::get(BrandingSocialLinks::KEY_INSTAGRAM, '') ?? '') === '', 'Removed Instagram URL cleared in settings');
ok(trim(AppSettings::get(BrandingSocialLinks::KEY_FACEBOOK, '') ?? '') !== '', 'Facebook unchanged when Instagram removed');

restoreSocial($orig);

echo $fail === 0 ? "PASS: branding social links.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
