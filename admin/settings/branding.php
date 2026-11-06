<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('settings');

$errors = [];
$form = BrandingSettings::adminFormState();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!Install::isSchemaReady()) {
        admin_flash_set('Branding settings require an installed database.', 'error');
        header('Location: ' . url_base() . '/admin/settings/branding.php', true, 302);
        exit;
    }

    $result = BrandingSettings::saveFromRequest($_POST, $_FILES);
    if (!empty($result['ok'])) {
        admin_flash_set((string) ($result['message'] ?? 'Website branding saved.'));
    } else {
        if (!empty($result['errors']) && is_array($result['errors'])) {
            $errors = $result['errors'];
            $_SESSION['branding_form_errors'] = $errors;
            $_SESSION['branding_form_post'] = $_POST;
        }
        admin_flash_set((string) ($result['message'] ?? 'Could not save branding.'), 'error');
    }
    header('Location: ' . url_base() . '/admin/settings/branding.php', true, 302);
    exit;
}

if (!empty($_SESSION['branding_form_post']) && is_array($_SESSION['branding_form_post'])) {
    $posted = $_SESSION['branding_form_post'];
    unset($_SESSION['branding_form_post']);
    $form['site_name'] = trim((string) ($posted['site_name'] ?? $form['site_name']));
    $form['tagline'] = trim((string) ($posted['tagline'] ?? $form['tagline']));
    $form['default_seo_title'] = trim((string) ($posted['default_seo_title'] ?? $form['default_seo_title']));
    $form['default_meta_description'] = trim((string) ($posted['default_meta_description'] ?? $form['default_meta_description']));
    $form['logo_alt'] = trim((string) ($posted['logo_alt'] ?? $form['logo_alt']));
    $form['footer_brand_description'] = trim((string) ($posted['footer_brand_description'] ?? $form['footer_brand_description']));
    foreach (['social_instagram', 'social_facebook', 'social_youtube', 'social_whatsapp', 'social_x'] as $socialField) {
        if (array_key_exists($socialField, $posted)) {
            $form[$socialField] = trim((string) $posted[$socialField]);
        }
    }
}
if (!empty($_SESSION['branding_form_errors']) && is_array($_SESSION['branding_form_errors'])) {
    $errors = $_SESSION['branding_form_errors'];
    unset($_SESSION['branding_form_errors']);
}

admin_layout_start(['pageTitle' => 'Website branding', 'activeAdminNav' => 'settings']);
$pageHeading = 'Website branding';
$pageDescription = 'Website display name (default invoice brand), tagline, logos, favicon and default SEO fallbacks. Legal name, address and support contacts are under Business profile.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$settingsTab = 'branding';
require APNI_TOKRI_ROOT . '/includes/partials/admin/settings-nav.php';
?>

<form method="post" enctype="multipart/form-data" class="grid gap-4 lg:grid-cols-2 max-w-5xl">
    <?= Csrf::field() ?>

    <div class="at-card lg:col-span-2">
        <div class="at-card__body space-y-4">
            <h3 class="font-bold">Website identity</h3>
            <p class="text-sm at-muted">The website / brand name is used in the header and as the default <strong>invoice brand name</strong> on new invoices unless you set a separate legal name under <a class="underline font-semibold" href="<?= Security::escape(url_base()) ?>/admin/settings/index.php">Business profile</a>.</p>
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="at-label" for="site_name">Website / brand name</label>
                    <input id="site_name" name="site_name" class="at-input at-focusable" maxlength="120"
                           value="<?= Security::escape($form['site_name']) ?>" placeholder="<?= Security::escape(BrandingSettings::siteName()) ?>">
                    <?php if (isset($errors['site_name'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['site_name']) ?></p><?php endif; ?>
                </div>
                <div>
                    <label class="at-label" for="tagline">Website tagline</label>
                    <input id="tagline" name="tagline" class="at-input at-focusable" maxlength="160"
                           value="<?= Security::escape($form['tagline']) ?>" placeholder="Freshness, Ready for Your Kitchen.">
                    <?php if (isset($errors['tagline'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['tagline']) ?></p><?php endif; ?>
                </div>
                <div>
                    <label class="at-label" for="default_seo_title">Browser title / default SEO title</label>
                    <input id="default_seo_title" name="default_seo_title" class="at-input at-focusable" maxlength="70"
                           value="<?= Security::escape($form['default_seo_title']) ?>" placeholder="Used when a page has no custom title">
                    <?php if (isset($errors['default_seo_title'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['default_seo_title']) ?></p><?php endif; ?>
                </div>
                <div>
                    <label class="at-label" for="logo_alt">Logo alt text</label>
                    <input id="logo_alt" name="logo_alt" class="at-input at-focusable" maxlength="191"
                           value="<?= Security::escape($form['logo_alt']) ?>" placeholder="<?= Security::escape(BrandingSettings::siteName()) ?>">
                    <?php if (isset($errors['logo_alt'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['logo_alt']) ?></p><?php endif; ?>
                </div>
            </div>
            <div>
                <label class="at-label" for="default_meta_description">Default meta description</label>
                <textarea id="default_meta_description" name="default_meta_description" class="at-textarea at-focusable" maxlength="320" rows="3"
                          placeholder="Used when a page does not define its own description"><?= Security::escape($form['default_meta_description']) ?></textarea>
                <?php if (isset($errors['default_meta_description'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['default_meta_description']) ?></p><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="at-card lg:col-span-2">
        <div class="at-card__body space-y-3">
            <h3 class="font-bold">Footer brand description</h3>
            <div>
                <label class="at-label" for="footer_brand_description">Footer brand description</label>
                <textarea id="footer_brand_description" name="footer_brand_description" class="at-textarea at-focusable" maxlength="500" rows="3"><?= Security::escape($form['footer_brand_description']) ?></textarea>
                <p class="text-xs at-muted mt-1">This description appears below the Veggie Tokri logo in the website footer.</p>
                <?php if (isset($errors['footer_brand_description'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['footer_brand_description']) ?></p><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="at-card lg:col-span-2">
        <div class="at-card__body space-y-4">
            <h3 class="font-bold">Social media links</h3>
            <p class="text-sm at-muted">Only configured social media links will appear in your website footer. Leave a field empty to hide its icon.</p>
            <div class="grid md:grid-cols-2 gap-4">
                <?php
                $socialFieldNames = [
                    'instagram' => 'social_instagram',
                    'facebook' => 'social_facebook',
                    'youtube' => 'social_youtube',
                    'whatsapp' => 'social_whatsapp',
                    'x' => 'social_x',
                ];
                foreach (BrandingSocialLinks::platformSpecs() as $platformId => $spec):
                    $fieldName = $socialFieldNames[$platformId] ?? ('social_' . $platformId);
                    $fieldId = $fieldName;
                    ?>
                <div>
                    <label class="at-label flex items-center gap-2" for="<?= Security::escape($fieldId) ?>">
                        <?= at_social_icon($platformId, 'w-4 h-4 text-[var(--at-green-deep)]') ?>
                        <span><?= Security::escape($spec['label']) ?></span>
                    </label>
                    <input id="<?= Security::escape($fieldId) ?>" name="<?= Security::escape($fieldName) ?>"
                           type="<?= $platformId === 'whatsapp' ? 'text' : 'url' ?>" inputmode="url"
                           class="at-input at-focusable" maxlength="500" autocomplete="off"
                           placeholder="<?= Security::escape($spec['placeholder']) ?>"
                           value="<?= Security::escape((string) ($form[$fieldName] ?? '')) ?>">
                    <?php if ($platformId === 'whatsapp'): ?>
                    <p class="text-xs at-muted mt-1">Use a full <code>https://wa.me/…</code> link or an international number starting with <strong>+</strong> and country code (e.g. +91…).</p>
                    <?php endif; ?>
                    <?php if (isset($errors[$fieldName])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors[$fieldName]) ?></p><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="at-card">
        <div class="at-card__body space-y-3">
            <h3 class="font-bold">Main website logo</h3>
            <p class="text-sm at-muted">Header and mobile menu. PNG, JPEG, WebP or GIF up to 2&nbsp;MB, max 1200×1200px.</p>
            <div class="flex flex-wrap items-center gap-4">
                <img src="<?= Security::escape($form['logo_preview']) ?>" alt="" width="72" height="72" class="rounded-lg border border-[var(--at-line)] bg-white object-contain p-1">
                <div class="space-y-2 flex-1 min-w-[12rem]">
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" class="at-input at-focusable !py-2">
                    <?php if (isset($errors['logo'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['logo']) ?></p><?php endif; ?>
                    <?php if (!$form['using_default_logo']): ?>
                        <label class="flex gap-2 text-sm"><input type="checkbox" name="remove[logo]" value="1"> Remove and use default logo</label>
                    <?php else: ?>
                        <p class="text-xs at-muted">Using default bundled logo.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="at-card">
        <div class="at-card__body space-y-3">
            <h3 class="font-bold">Footer logo (optional)</h3>
            <p class="text-sm at-muted">Falls back to the main logo when not set.</p>
            <div class="flex flex-wrap items-center gap-4">
                <img src="<?= Security::escape($form['footer_logo_preview']) ?>" alt="" width="72" height="72" class="rounded-lg border border-[var(--at-line)] bg-white object-contain p-1">
                <div class="space-y-2 flex-1 min-w-[12rem]">
                    <input type="file" name="footer_logo" accept="image/png,image/jpeg,image/webp,image/gif" class="at-input at-focusable !py-2">
                    <?php if (isset($errors['footer_logo'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['footer_logo']) ?></p><?php endif; ?>
                    <?php if (!$form['using_default_footer_logo']): ?>
                        <label class="flex gap-2 text-sm"><input type="checkbox" name="remove[footer_logo]" value="1"> Remove footer logo</label>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="at-card">
        <div class="at-card__body space-y-3">
            <h3 class="font-bold">Favicon</h3>
            <p class="text-sm at-muted">PNG, ICO, JPEG or WebP up to 512&nbsp;KB, max 512×512px. SVG is not accepted.</p>
            <div class="flex flex-wrap items-center gap-4">
                <img src="<?= Security::escape($form['favicon_preview']) ?>" alt="" width="48" height="48" class="rounded border border-[var(--at-line)] bg-white object-contain p-1">
                <div class="space-y-2 flex-1 min-w-[12rem]">
                    <input type="file" name="favicon" accept="image/png,image/jpeg,image/webp,image/x-icon,.ico" class="at-input at-focusable !py-2">
                    <?php if (isset($errors['favicon'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['favicon']) ?></p><?php endif; ?>
                    <?php if (!$form['using_default_favicon']): ?>
                        <label class="flex gap-2 text-sm"><input type="checkbox" name="remove[favicon]" value="1"> Remove and use default favicon</label>
                    <?php else: ?>
                        <p class="text-xs at-muted">Using default favicon (main logo).</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="at-card">
        <div class="at-card__body space-y-3">
            <h3 class="font-bold">Default social sharing image</h3>
            <p class="text-sm at-muted">Open Graph fallback when a page has no image. JPG/PNG/WebP/GIF up to 3&nbsp;MB.</p>
            <div class="flex flex-wrap items-center gap-4">
                <img src="<?= Security::escape($form['og_preview']) ?>" alt="" width="120" height="63" class="rounded-lg border border-[var(--at-line)] bg-white object-cover">
                <div class="space-y-2 flex-1 min-w-[12rem]">
                    <input type="file" name="og_image" accept="image/png,image/jpeg,image/webp,image/gif" class="at-input at-focusable !py-2">
                    <?php if (isset($errors['og_image'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['og_image']) ?></p><?php endif; ?>
                    <?php if (!$form['using_default_og']): ?>
                        <label class="flex gap-2 text-sm"><input type="checkbox" name="remove[og_image]" value="1"> Remove custom social image</label>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="lg:col-span-2">
        <button type="submit" class="at-btn at-btn--primary at-focusable">Save branding</button>
        <p class="text-xs at-muted mt-2">Requires the <code>settings.manage</code> permission (typically Super Admin).</p>
    </div>
</form>
<?php admin_layout_end(); ?>
