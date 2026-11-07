<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('settings');

$errors = [];
$form = BusinessSettings::adminFormState();
$settingsBase = url_base() . '/admin/settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!Install::isSchemaReady() || !AdminAuth::check()) {
        admin_flash_set('Business settings require an installed database and admin login.', 'error');
        header('Location: ' . $settingsBase . '/index.php', true, 302);
        exit;
    }

    $result = BusinessSettings::saveFromPost($_POST, $_FILES['udyam_certificate'] ?? null);
    if (!empty($result['ok'])) {
        admin_flash_set((string) ($result['message'] ?? 'Business profile saved.'));
    } else {
        if (!empty($result['errors']) && is_array($result['errors'])) {
            $_SESSION['business_form_errors'] = $result['errors'];
            $_SESSION['business_form_post'] = $_POST;
        }
        admin_flash_set((string) ($result['message'] ?? 'Could not save business profile.'), 'error');
    }
    header('Location: ' . $settingsBase . '/index.php', true, 302);
    exit;
}

if (!empty($_SESSION['business_form_post']) && is_array($_SESSION['business_form_post'])) {
    $posted = $_SESSION['business_form_post'];
    unset($_SESSION['business_form_post']);
    foreach (array_keys($form) as $k) {
        if (array_key_exists($k, $posted)) {
            $form[$k] = trim((string) $posted[$k]);
        }
    }
    $form['udyam_enabled'] = !empty($posted['udyam_enabled']) ? '1' : '0';
}
if (!empty($_SESSION['business_form_errors']) && is_array($_SESSION['business_form_errors'])) {
    $errors = $_SESSION['business_form_errors'];
    unset($_SESSION['business_form_errors']);
}

admin_layout_start(['pageTitle' => 'Settings', 'activeAdminNav' => 'settings']);
$pageHeading = 'Business profile';
$pageDescription = 'Single source of truth for store identity, registered address and support contacts. Used on the storefront, checkout support links, and new invoices.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$settingsTab = 'business';
require APNI_TOKRI_ROOT . '/includes/partials/admin/settings-nav.php';
?>

<?php $udyamCertOnFile = BusinessSettings::udyamCertificatePath() !== null; ?>
<form method="post" enctype="multipart/form-data" class="grid gap-4 lg:grid-cols-2 max-w-5xl">
    <div class="at-card lg:col-span-2">
        <div class="at-card__body space-y-4">
            <?= Csrf::field() ?>
            <p class="text-sm at-muted">Website display name, tagline and logos are managed under <a class="underline font-semibold" href="<?= Security::escape($settingsBase) ?>/branding.php">Website branding</a>. Invoice numbering and PDF options are under <a class="underline font-semibold" href="<?= Security::escape($settingsBase) ?>/invoices.php">Invoice settings</a>.</p>

            <h3 class="font-bold">Business identity</h3>
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="at-label" for="store_name">Store / business name</label>
                    <input id="store_name" name="store_name" class="at-input at-focusable" maxlength="160" autocomplete="organization"
                           value="<?= Security::escape($form['store_name']) ?>" placeholder="Trading name shown to customers">
                    <?php if (isset($errors['store_name'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['store_name']) ?></p><?php endif; ?>
                </div>
                <div>
                    <label class="at-label" for="legal_name">Legal business name</label>
                    <input id="legal_name" name="legal_name" class="at-input at-focusable" maxlength="160"
                           value="<?= Security::escape($form['legal_name']) ?>" placeholder="Registered entity on invoices and footer">
                    <?php if (isset($errors['legal_name'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['legal_name']) ?></p><?php endif; ?>
                </div>
            </div>

            <h3 class="font-bold">Registered address</h3>
            <p class="text-sm at-muted">Printed on new invoices and available for customer-facing contact details.</p>
            <div class="grid md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="at-label" for="address">Store address</label>
                    <input id="address" name="address" class="at-input at-focusable" maxlength="300"
                           value="<?= Security::escape($form['address']) ?>" placeholder="Building, street, area">
                    <?php if (isset($errors['address'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['address']) ?></p><?php endif; ?>
                </div>
                <div>
                    <label class="at-label" for="city">City</label>
                    <input id="city" name="city" class="at-input at-focusable" maxlength="80" value="<?= Security::escape($form['city']) ?>">
                    <?php if (isset($errors['city'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['city']) ?></p><?php endif; ?>
                </div>
                <div>
                    <label class="at-label" for="state">State</label>
                    <input id="state" name="state" class="at-input at-focusable" maxlength="80" value="<?= Security::escape($form['state']) ?>">
                    <?php if (isset($errors['state'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['state']) ?></p><?php endif; ?>
                </div>
                <div>
                    <label class="at-label" for="pincode">PIN code</label>
                    <input id="pincode" name="pincode" class="at-input at-focusable" maxlength="6" inputmode="numeric" value="<?= Security::escape($form['pincode']) ?>">
                    <?php if (isset($errors['pincode'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['pincode']) ?></p><?php endif; ?>
                </div>
            </div>

            <h3 class="font-bold">Support contacts</h3>
            <div class="grid md:grid-cols-2 gap-4">
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
                <div class="md:col-span-2">
                    <label class="at-label" for="support_email">Support email</label>
                    <input id="support_email" name="support_email" type="email" class="at-input at-focusable" autocomplete="email"
                           value="<?= Security::escape($form['support_email']) ?>" placeholder="support@example.com">
                    <?php if (isset($errors['support_email'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['support_email']) ?></p><?php endif; ?>
                </div>
                <div class="md:col-span-2">
                    <label class="at-label" for="website_url">Website URL</label>
                    <input id="website_url" name="website_url" type="url" class="at-input at-focusable" maxlength="500" inputmode="url"
                           value="<?= Security::escape($form['website_url'] ?? '') ?>"
                           placeholder="<?= Security::escape(BusinessSettings::DEFAULT_WEBSITE_URL) ?>">
                    <p class="text-xs at-muted mt-1">Shown on new invoices and invoice settings. Leave blank to use the default (<span class="font-mono"><?= Security::escape(BusinessSettings::DEFAULT_WEBSITE_URL) ?></span>).</p>
                    <?php if (isset($errors['website_url'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['website_url']) ?></p><?php endif; ?>
                </div>
                <div class="md:col-span-2">
                    <label class="at-label" for="fssai_licence">FSSAI licence number</label>
                    <input id="fssai_licence" name="fssai_licence" class="at-input at-focusable" maxlength="64"
                           value="<?= Security::escape($form['fssai_licence']) ?>" placeholder="Shown in footer and on invoices when set">
                    <?php if (isset($errors['fssai_licence'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['fssai_licence']) ?></p><?php endif; ?>
                </div>
            </div>

            <h3 class="font-bold">MSME Udyam registration</h3>
            <p class="text-sm at-muted">Registration number and enterprise name appear in the website footer (when enabled) and on newly generated invoices. Certificate PDF is stored for admin reference only — not published on the storefront.</p>
            <label class="flex gap-2 text-sm items-start"><input type="checkbox" name="udyam_enabled" value="1" class="mt-1"<?= ($form['udyam_enabled'] ?? '0') === '1' ? ' checked' : '' ?>>
                <span>Show Udyam registration in footer and on new invoices</span></label>
            <?php if (isset($errors['udyam_enabled'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['udyam_enabled']) ?></p><?php endif; ?>
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="at-label" for="udyam_registration_number">Udyam registration number</label>
                    <input id="udyam_registration_number" name="udyam_registration_number" class="at-input at-focusable font-mono" maxlength="32"
                           value="<?= Security::escape($form['udyam_registration_number'] ?? '') ?>" placeholder="UDYAM-XX-00-0000000">
                    <?php if (isset($errors['udyam_registration_number'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['udyam_registration_number']) ?></p><?php endif; ?>
                </div>
                <div>
                    <label class="at-label" for="udyam_enterprise_name">Enterprise name (as on certificate)</label>
                    <input id="udyam_enterprise_name" name="udyam_enterprise_name" class="at-input at-focusable" maxlength="160"
                           value="<?= Security::escape($form['udyam_enterprise_name'] ?? '') ?>">
                    <?php if (isset($errors['udyam_enterprise_name'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['udyam_enterprise_name']) ?></p><?php endif; ?>
                </div>
                <div class="md:col-span-2">
                    <label class="at-label" for="udyam_verify_url">Official verification URL</label>
                    <input id="udyam_verify_url" name="udyam_verify_url" type="url" class="at-input at-focusable" maxlength="500"
                           value="<?= Security::escape($form['udyam_verify_url'] ?? '') ?>"
                           placeholder="<?= Security::escape(BusinessSettings::DEFAULT_UDYAM_VERIFY_URL) ?>">
                    <p class="text-xs at-muted mt-1">Linked from the footer “Verify” action. Leave blank to use the default government URL.</p>
                    <?php if (isset($errors['udyam_verify_url'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['udyam_verify_url']) ?></p><?php endif; ?>
                </div>
                <div class="md:col-span-2 flex flex-wrap items-start gap-4">
                    <?php if ($udyamCertOnFile): ?>
                        <div class="flex flex-col gap-2 w-full sm:w-auto">
                            <span class="text-sm">A Udyam certificate PDF is on file (admin only).</span>
                            <div class="flex flex-wrap gap-2">
                                <a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="<?= Security::escape($settingsBase) ?>/udyam-certificate.php?inline=1" target="_blank" rel="noopener">View certificate</a>
                                <a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="<?= Security::escape($settingsBase) ?>/udyam-certificate.php">Download certificate</a>
                            </div>
                        </div>
                        <label class="flex gap-2 text-sm"><input type="checkbox" name="remove_udyam_certificate" value="1"> Remove stored certificate</label>
                    <?php else: ?>
                        <span class="text-sm at-muted">Optional: upload the Udyam certificate PDF for your records.</span>
                    <?php endif; ?>
                    <div class="min-w-[16rem] flex-1">
                        <label class="at-label" for="udyam_certificate">Udyam certificate (PDF, max 2 MB)</label>
                        <input id="udyam_certificate" type="file" name="udyam_certificate" accept="application/pdf" class="at-input at-focusable !py-2">
                        <?php if (isset($errors['udyam_certificate'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['udyam_certificate']) ?></p><?php endif; ?>
                    </div>
                </div>
            </div>

            <p class="text-xs at-muted">Clear a field and save to remove it from the storefront. All fields are saved together on each submit.</p>
            <button type="submit" class="at-btn at-btn--primary at-focusable">Save business profile</button>
        </div>
    </div>
</form>
<?php admin_layout_end(); ?>
