<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('settings');

$errors = [];
$form = BusinessSettings::adminFormState();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!Install::isSchemaReady() || !AdminAuth::check()) {
        admin_flash_set('Business settings require an installed database and admin login.', 'error');
        header('Location: ' . url_base() . '/admin/settings/index.php', true, 302);
        exit;
    }

    $result = BusinessSettings::saveFromPost($_POST);
    if (!empty($result['ok'])) {
        admin_flash_set((string) ($result['message'] ?? 'Business settings saved.'));
    } else {
        if (!empty($result['errors']) && is_array($result['errors'])) {
            $_SESSION['business_form_errors'] = $result['errors'];
            $_SESSION['business_form_post'] = $_POST;
        }
        admin_flash_set((string) ($result['message'] ?? 'Could not save business settings.'), 'error');
    }
    header('Location: ' . url_base() . '/admin/settings/index.php', true, 302);
    exit;
}

if (!empty($_SESSION['business_form_post']) && is_array($_SESSION['business_form_post'])) {
    $posted = $_SESSION['business_form_post'];
    unset($_SESSION['business_form_post']);
    $form['store_name'] = trim((string) ($posted['store_name'] ?? $form['store_name']));
    $form['support_phone'] = trim((string) ($posted['support_phone'] ?? $form['support_phone']));
    $form['support_whatsapp'] = trim((string) ($posted['support_whatsapp'] ?? $form['support_whatsapp']));
    $form['support_email'] = trim((string) ($posted['support_email'] ?? $form['support_email']));
    $form['fssai_licence'] = trim((string) ($posted['fssai_licence'] ?? $form['fssai_licence']));
}
if (!empty($_SESSION['business_form_errors']) && is_array($_SESSION['business_form_errors'])) {
    $errors = $_SESSION['business_form_errors'];
    unset($_SESSION['business_form_errors']);
}

admin_layout_start(['pageTitle' => 'Settings', 'activeAdminNav' => 'settings']);
admin_flash_render();
$pageHeading = 'Business settings';
$pageDescription = 'Store identity and customer support contact details shown on the storefront footer and contact page. Website display name and logo are managed under Website branding.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$settingsTab = 'business';
require APNI_TOKRI_ROOT . '/includes/partials/admin/settings-nav.php';
?>

<form method="post" class="at-card max-w-2xl">
    <div class="at-card__body space-y-4">
        <?= Csrf::field() ?>
        <p class="text-sm at-muted">Header logo text and SEO site name are configured on <a class="underline font-semibold" href="<?= Security::escape(url_base()) ?>/admin/settings/branding.php">Website branding</a>. This form stores your legal/business name and support contacts.</p>

        <div>
            <label class="at-label" for="store_name">Store / business name</label>
            <input id="store_name" name="store_name" class="at-input at-focusable" maxlength="160" autocomplete="organization"
                   value="<?= Security::escape($form['store_name']) ?>" placeholder="<?= Security::escape(BusinessSettings::legalName()) ?>">
            <?php if (isset($errors['store_name'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['store_name']) ?></p><?php endif; ?>
        </div>
        <div>
            <label class="at-label" for="support_phone">Support phone</label>
            <input id="support_phone" name="support_phone" class="at-input at-focusable" inputmode="tel" autocomplete="tel"
                   value="<?= Security::escape($form['support_phone']) ?>" placeholder="e.g. 9876543210">
            <?php if (isset($errors['support_phone'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['support_phone']) ?></p><?php endif; ?>
        </div>
        <div>
            <label class="at-label" for="support_whatsapp">Support WhatsApp</label>
            <input id="support_whatsapp" name="support_whatsapp" class="at-input at-focusable" inputmode="tel" autocomplete="tel"
                   value="<?= Security::escape($form['support_whatsapp']) ?>" placeholder="10-digit mobile for wa.me link">
            <?php if (isset($errors['support_whatsapp'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['support_whatsapp']) ?></p><?php endif; ?>
        </div>
        <div>
            <label class="at-label" for="support_email">Support email</label>
            <input id="support_email" name="support_email" type="email" class="at-input at-focusable" autocomplete="email"
                   value="<?= Security::escape($form['support_email']) ?>" placeholder="support@example.com">
            <?php if (isset($errors['support_email'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['support_email']) ?></p><?php endif; ?>
        </div>
        <div>
            <label class="at-label" for="fssai_licence">FSSAI licence number (display)</label>
            <input id="fssai_licence" name="fssai_licence" class="at-input at-focusable" maxlength="64"
                   value="<?= Security::escape($form['fssai_licence']) ?>" placeholder="Shown in footer when configured">
            <?php if (isset($errors['fssai_licence'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['fssai_licence']) ?></p><?php endif; ?>
        </div>
        <p class="text-xs at-muted">Clear a field and save to remove it from the storefront. All fields are saved together on each submit.</p>
        <button type="submit" class="at-btn at-btn--primary at-focusable">Save settings</button>
    </div>
</form>
<?php admin_layout_end(); ?>
