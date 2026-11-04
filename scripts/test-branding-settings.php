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

$origName = BrandingSettings::siteName();
$origTag = BrandingSettings::tagline();
$origHadFooterDesc = AppSettings::has(BrandingSettings::KEY_FOOTER_BRAND_DESCRIPTION);
$origFooterDesc = $origHadFooterDesc
    ? AppSettings::get(BrandingSettings::KEY_FOOTER_BRAND_DESCRIPTION, '')
    : null;

AppSettings::set(BrandingSettings::KEY_SITE_NAME, 'Verify Brand Co');
AppSettings::resetCache();
ok(BrandingSettings::siteName() === 'Verify Brand Co', 'Update website name');
ok(__('brand_name') === 'Verify Brand Co', 'Header lang key uses branding name');

AppSettings::set(BrandingSettings::KEY_TAGLINE, 'Fresh Every Day');
AppSettings::resetCache();
ok(BrandingSettings::tagline() === 'Fresh Every Day', 'Update tagline');

AppSettings::set(BrandingSettings::KEY_DEFAULT_SEO_TITLE, 'Custom Default Title');
AppSettings::set(BrandingSettings::KEY_DEFAULT_META_DESC, 'Custom default meta for tests.');
AppSettings::resetCache();
ok(BrandingSettings::defaultSeoTitle() === 'Custom Default Title', 'Default SEO title stored');
ok(BrandingSettings::defaultMetaDescription() === 'Custom default meta for tests.', 'Default meta description stored');

$homeSeo = SeoResolver::forHomepage();
ok(str_contains($homeSeo->documentTitle, 'Custom Default Title') || $homeSeo->documentTitle === 'Custom Default Title', 'Homepage SEO uses branding default when seo.home empty');

$productMeta = SeoResolver::forProductRow([
    'status' => 'published',
    'name' => 'Test Tomato',
    'slug' => 'test-tomato',
    'meta_title' => 'Page Specific Tomato Title',
    'meta_description' => 'Page specific description only.',
]);
ok($productMeta->documentTitle === 'Page Specific Tomato Title', 'Page-specific product SEO title preserved');

// Simulated upload path (safe filename)
$destDir = APNI_TOKRI_ROOT . '/uploads/branding';
if (!is_dir($destDir)) {
    mkdir($destDir, 0775, true);
}
$src = APNI_TOKRI_ROOT . '/assets/images/demo/p-banana.jpg';
$fname = bin2hex(random_bytes(16)) . '.jpg';
$rel = 'uploads/branding/' . $fname;
copy($src, $destDir . '/' . $fname);
AppSettings::set(BrandingSettings::KEY_LOGO_PATH, $rel);
AppSettings::resetCache();
$logoUrl = BrandingSettings::logoUrl();
ok(str_contains($logoUrl, $fname) && str_contains($logoUrl, 'v='), 'Uploaded logo URL cache-busted');

AppSettings::set(BrandingSettings::KEY_LOGO_PATH, null);
@unlink($destDir . '/' . $fname);
AppSettings::resetCache();
ok(str_contains(BrandingSettings::logoUrl(), 'logo.svg'), 'Remove logo restores default');

// Invalid path rejected
AppSettings::set(BrandingSettings::KEY_FAVICON_PATH, '../../../etc/passwd');
AppSettings::resetCache();
ok(str_contains(BrandingSettings::faviconUrl(), 'logo.svg'), 'Path traversal favicon ignored');

// RBAC
Permission::clearCache();
$_SESSION['admin_user_id'] = 1;
ok(Permission::can('settings.manage'), 'Super admin has settings.manage');
$limitedId = (int) (Database::pdo()->query(
    "SELECT u.id FROM admin_users u
     INNER JOIN admin_user_roles ur ON ur.user_id = u.id
     INNER JOIN admin_roles r ON r.id = ur.role_id
     WHERE r.slug != 'super_admin' LIMIT 1"
)->fetchColumn() ?: 0);
if ($limitedId > 0) {
    $_SESSION['admin_user_id'] = $limitedId;
    Permission::clearCache();
    ok(!Permission::can('settings.manage'), 'Non-super admin denied settings.manage');
} else {
    ok(true, 'Non-super admin RBAC check skipped (no limited admin user)');
}

Database::pdo()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([
    BrandingSettings::KEY_FOOTER_BRAND_DESCRIPTION,
]);
AppSettings::resetCache();
ok(
    BrandingSettings::footerBrandDescription() === BrandingSettings::DEFAULT_FOOTER_BRAND_DESCRIPTION,
    'Missing footer description uses code default'
);
ok(
    BrandingSettings::footerBrandDescriptionForForm() === BrandingSettings::DEFAULT_FOOTER_BRAND_DESCRIPTION,
    'Admin form shows default before first save'
);

AppSettings::set(BrandingSettings::KEY_FOOTER_BRAND_DESCRIPTION, 'Custom footer blurb for tests.');
AppSettings::resetCache();
ok(BrandingSettings::footerBrandDescription() === 'Custom footer blurb for tests.', 'Stored footer description on storefront');

ob_start();
require APNI_TOKRI_ROOT . '/includes/layout/footer-public.php';
$footer = (string) ob_get_clean();
ok(str_contains($footer, 'Custom footer blurb for tests.'), 'Footer partial renders saved description');
ok(!str_contains($footer, 'pantry favourites for Indore'), 'Footer omits default when custom saved');

AppSettings::set(BrandingSettings::KEY_FOOTER_BRAND_DESCRIPTION, '');
AppSettings::resetCache();
ok(BrandingSettings::footerBrandDescription() === null, 'Empty saved footer description is hidden');

ob_start();
require APNI_TOKRI_ROOT . '/includes/layout/footer-public.php';
$footerEmpty = (string) ob_get_clean();
ok(!str_contains($footerEmpty, 'max-w-xs leading-relaxed'), 'Footer hides description paragraph when empty');

$saveResult = BrandingSettings::saveFromRequest([
    'site_name' => '',
    'tagline' => '',
    'default_seo_title' => '',
    'default_meta_description' => '',
    'logo_alt' => '',
    'footer_brand_description' => str_repeat('x', 501),
], []);
ok(isset($saveResult['errors']['footer_brand_description']), 'Footer description over 500 chars rejected');

// Restore
AppSettings::set(BrandingSettings::KEY_SITE_NAME, null);
AppSettings::set(BrandingSettings::KEY_TAGLINE, null);
AppSettings::set(BrandingSettings::KEY_DEFAULT_SEO_TITLE, null);
AppSettings::set(BrandingSettings::KEY_DEFAULT_META_DESC, null);
if ($origHadFooterDesc) {
    AppSettings::set(BrandingSettings::KEY_FOOTER_BRAND_DESCRIPTION, $origFooterDesc);
} else {
    Database::pdo()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([
        BrandingSettings::KEY_FOOTER_BRAND_DESCRIPTION,
    ]);
}
AppSettings::resetCache();

// Frontend snippet checks (CLI render)
ob_start();
require APNI_TOKRI_ROOT . '/includes/layout/header-public.php';
$header = (string) ob_get_clean();
ok(str_contains($header, 'at-logo'), 'Header partial renders with branding hooks');

ob_start();
$seo = SeoMetadata::fromLegacy('Legacy Page Title');
require APNI_TOKRI_ROOT . '/includes/layout/head-public.php';
$head = (string) ob_get_clean();
ok(str_contains($head, 'rel="icon"'), 'Favicon link in public head');
ok(str_contains($head, 'Legacy Page Title'), 'Page-specific legacy title preserved');

echo $fail === 0 ? "PASS: branding settings.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
