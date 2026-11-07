<?php

declare(strict_types=1);

/**
 * Mobile app store branding + footer badges.
 * CLI: php scripts/test-branding-app-stores.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$fail = 0;

function okApp(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function renderFooter(): string
{
    ob_start();
    $showBottomNav = false;
    $activeNav = '';
    require APNI_TOKRI_ROOT . '/includes/layout/footer-public.php';
    return (string) ob_get_clean();
}

function brandingBasePost(): array
{
    return [
        'site_name' => BrandingSettings::siteName(),
        'tagline' => BrandingSettings::tagline(),
        'default_seo_title' => '',
        'default_meta_description' => '',
        'logo_alt' => '',
        'footer_brand_description' => BrandingSettings::footerBrandDescriptionForForm(),
        'social_instagram' => trim(AppSettings::get(BrandingSocialLinks::KEY_INSTAGRAM, '') ?? ''),
        'social_facebook' => trim(AppSettings::get(BrandingSocialLinks::KEY_FACEBOOK, '') ?? ''),
        'social_youtube' => '',
        'social_whatsapp' => '',
        'social_x' => '',
    ];
}

/** @var array<string, string|null> */
$origApp = [];
/** @var array<string, string|null> */
$origSocial = [];
foreach (BrandingAppStores::allSettingKeys() as $key) {
    $origApp[$key] = AppSettings::has($key) ? AppSettings::get($key, '') : null;
}
foreach (BrandingSocialLinks::allSettingKeys() as $key) {
    $origSocial[$key] = AppSettings::has($key) ? AppSettings::get($key, '') : null;
}

foreach (BrandingAppStores::allSettingKeys() as $key) {
    Database::pdo()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$key]);
}
AppSettings::resetCache();
okApp(BrandingAppStores::visibleFooterBadges() === [], 'Both disabled: section hidden (no badges)');
$footerNone = renderFooter();
okApp(!str_contains($footerNone, 'at-footer-apps'), 'Both disabled: footer section absent');
okApp(!str_contains($footerNone, 'Download Our App'), 'Both disabled: heading absent');

$googleUrl = 'https://play.google.com/store/apps/details?id=com.veggietokri.app';
$postGoogle = array_merge(brandingBasePost(), [
    'app_google_enabled' => '1',
    'app_google_play_url' => $googleUrl,
    'app_apple_enabled' => '0',
    'app_apple_app_store_url' => '',
]);
$saveG = BrandingSettings::saveFromRequest($postGoogle, []);
AppSettings::resetCache();
okApp(!empty($saveG['ok']), 'Save Google enabled + valid URL');
$badgesG = BrandingAppStores::visibleFooterBadges();
okApp(count($badgesG) === 1 && $badgesG[0]['id'] === 'google_play', 'Google only: one badge');
$footerG = renderFooter();
okApp(str_contains($footerG, 'at-footer-apps'), 'Google only: section visible');
okApp(str_contains($footerG, 'google-play-badge.svg'), 'Google only: Google badge art');
okApp(!str_contains($footerG, 'apple-app-store-badge.svg'), 'Google only: no Apple badge');

$appleUrl = 'https://apps.apple.com/in/app/veggie-tokri/id1234567890';
$postApple = array_merge(brandingBasePost(), [
    'app_google_enabled' => '0',
    'app_google_play_url' => '',
    'app_apple_enabled' => '1',
    'app_apple_app_store_url' => $appleUrl,
]);
$saveA = BrandingSettings::saveFromRequest($postApple, []);
AppSettings::resetCache();
okApp(!empty($saveA['ok']), 'Save Apple enabled + valid URL');
okApp(count(BrandingAppStores::visibleFooterBadges()) === 1, 'Apple only: one badge');
$footerA = renderFooter();
okApp(str_contains($footerA, 'apple-app-store-badge.svg'), 'Apple only: Apple badge');
okApp(!str_contains($footerA, 'google-play-badge.svg'), 'Apple only: no Google badge');

$postBoth = array_merge(brandingBasePost(), [
    'app_google_enabled' => '1',
    'app_google_play_url' => $googleUrl,
    'app_apple_enabled' => '1',
    'app_apple_app_store_url' => $appleUrl,
]);
$saveBoth = BrandingSettings::saveFromRequest($postBoth, []);
AppSettings::resetCache();
okApp(!empty($saveBoth['ok']), 'Save both enabled');
okApp(count(BrandingAppStores::visibleFooterBadges()) === 2, 'Both enabled: two badges');
$footerBoth = renderFooter();
okApp(substr_count($footerBoth, 'at-footer-apps__badge') === 2, 'Both enabled: two footer badge links');

$postBlankEnabled = array_merge(brandingBasePost(), [
    'app_google_enabled' => '1',
    'app_google_play_url' => '',
    'app_apple_enabled' => '1',
    'app_apple_app_store_url' => '',
]);
$saveBlank = BrandingSettings::saveFromRequest($postBlankEnabled, []);
AppSettings::resetCache();
okApp(!empty($saveBlank['ok']), 'Enabled with blank URLs saves');
okApp(BrandingAppStores::visibleFooterBadges() === [], 'Enabled but blank URLs: no badges');
$footerBlank = renderFooter();
okApp(!str_contains($footerBlank, 'at-footer-apps'), 'Enabled blank: section hidden');

$badHttp = BrandingAppStores::validateFromPost([
    'app_google_enabled' => '1',
    'app_google_play_url' => 'http://play.google.com/store/apps/details?id=com.bad',
    'app_apple_enabled' => '0',
    'app_apple_app_store_url' => '',
]);
okApp(isset($badHttp['errors']['app_google_play_url']), 'Non-HTTPS Google URL rejected');

$badDomain = BrandingAppStores::validateFromPost([
    'app_google_enabled' => '1',
    'app_google_play_url' => 'https://evil.example/store/apps/details?id=com.bad',
    'app_apple_enabled' => '0',
    'app_apple_app_store_url' => '',
]);
okApp(isset($badDomain['errors']['app_google_play_url']), 'Non-official Google domain rejected');

$badApple = BrandingAppStores::validateFromPost([
    'app_google_enabled' => '0',
    'app_google_play_url' => '',
    'app_apple_enabled' => '1',
    'app_apple_app_store_url' => 'https://apps.apple.com/us/developer/foo',
]);
okApp(isset($badApple['errors']['app_apple_app_store_url']), 'Apple URL without /app/ rejected');

$postPersist = array_merge(brandingBasePost(), [
    'app_google_enabled' => '1',
    'app_google_play_url' => $googleUrl,
    'app_apple_enabled' => '1',
    'app_apple_app_store_url' => $appleUrl,
]);
BrandingSettings::saveFromRequest($postPersist, []);
AppSettings::resetCache();
okApp(BrandingAppStores::isGoogleEnabled() && BrandingAppStores::isAppleEnabled(), 'Settings persist after save');
$form = BrandingSettings::adminFormState();
okApp($form['app_google_play_url'] === $googleUrl, 'Admin form reloads Google URL');
okApp($form['app_apple_app_store_url'] === 'https://apps.apple.com/in/app/veggie-tokri/id1234567890', 'Admin form reloads Apple URL');

AppSettings::set(BrandingSocialLinks::KEY_FACEBOOK, 'https://www.facebook.com/persist-check');
AppSettings::resetCache();
BrandingSettings::saveFromRequest(array_merge($postBoth, [
    'social_facebook' => 'https://www.facebook.com/persist-check',
]), []);
AppSettings::resetCache();
okApp(trim(AppSettings::get(BrandingSocialLinks::KEY_FACEBOOK, '') ?? '') === 'https://www.facebook.com/persist-check', 'Social settings unaffected by app save');
okApp(BrandingAppStores::isGoogleEnabled(), 'App settings saved alongside social');

Permission::clearCache();
$_SESSION['admin_user_id'] = 1;
okApp(Permission::can('settings.manage'), 'Super admin has settings.manage');
$limitedId = (int) (Database::pdo()->query(
    "SELECT u.id FROM admin_users u
     INNER JOIN admin_user_roles ur ON ur.user_id = u.id
     INNER JOIN admin_roles r ON r.id = ur.role_id
     WHERE r.slug != 'super_admin' LIMIT 1"
)->fetchColumn() ?: 0);
if ($limitedId > 0) {
    $_SESSION['admin_user_id'] = $limitedId;
    Permission::clearCache();
    okApp(!Permission::can('settings.manage'), 'Unauthorized admin cannot manage settings');
} else {
    okApp(true, 'RBAC limited admin check skipped (no limited admin user)');
}

foreach (array_merge($origApp, $origSocial) as $key => $value) {
    if ($value === null) {
        Database::pdo()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$key]);
    } else {
        AppSettings::set($key, $value);
    }
}
AppSettings::resetCache();

echo $fail === 0 ? "PASS: branding app stores.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
