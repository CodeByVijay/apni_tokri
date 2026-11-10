<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/firebase-ui.php';

AdminDemoPerms::denyIfCannot('firebase');
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$base = url_base() . '/admin/firebase';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $postId = (int) ($_POST['id'] ?? 0);
    $jsonContents = null;
    if (!empty($_FILES['service_account']['tmp_name']) && is_uploaded_file($_FILES['service_account']['tmp_name'])) {
        $jsonContents = file_get_contents($_FILES['service_account']['tmp_name']);
    } elseif (!empty($_POST['service_account_paste'])) {
        $jsonContents = (string) $_POST['service_account_paste'];
    }
    $save = FirebaseAdminService::saveProject($_POST, $jsonContents, $postId > 0 ? $postId : null);
    if (!empty($save['ok'])) {
        admin_flash_set((string) ($save['message'] ?? 'Saved.'));
        header('Location: ' . $base . '/edit.php?id=' . (int) ($save['id'] ?? $postId), true, 302);
        exit;
    }
    $errors = $save['errors'] ?? [(string) ($save['message'] ?? 'Save failed.')];
}

$row = $id > 0 ? FirebaseProjectManager::findById($id, false) : null;
$form = $row ?? [
    'name' => '',
    'firebase_project_id' => '',
    'firebase_project_number' => '',
    'web_api_key' => '',
    'web_app_id' => '',
    'web_auth_domain' => '',
    'web_storage_bucket' => '',
    'web_measurement_id' => '',
    'vapid_public_key' => '',
    'android_app_id' => '',
    'android_package_name' => '',
    'platform_notes' => '',
    'service_account_client_email' => '',
];

$hasStoredServiceAccount = $id > 0 && trim((string) ($form['service_account_client_email'] ?? '')) !== '';

admin_layout_start(['pageTitle' => $id > 0 ? 'Edit Firebase project' : 'Add Firebase project', 'activeAdminNav' => 'firebase']);
$pageHeading = $id > 0 ? 'Edit Firebase project' : 'Add Firebase project';
$pageDescription = 'Web config is exposed to the storefront; service account stays encrypted server-side.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
firebase_admin_subnav('projects');
?>

<?php foreach ($errors as $err): ?>
<div class="at-alert at-alert--error mb-3"><?= Security::escape($err) ?></div>
<?php endforeach; ?>

<form method="post" enctype="multipart/form-data" class="at-admin-card at-firebase-form-wrap">
    <div class="at-admin-card__body">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) $id ?>">

        <?php firebase_form_section_start(
            'General information',
            'Identify this Firebase project in admin. The Firebase Project ID is fixed after the project is created.'
        ); ?>
        <div class="at-firebase-form__grid at-firebase-form__grid--2">
            <div>
                <label class="at-label" for="name"><?= firebase_label_required('Display name') ?></label>
                <input class="at-input at-focusable w-full" id="name" name="name" required
                       placeholder="e.g. Veggie Tokri Production"
                       value="<?= Security::escape((string) $form['name']) ?>">
                <p class="text-xs at-muted mt-1">Admin-friendly label — not sent to Firebase.</p>
            </div>
            <div>
                <label class="at-label" for="firebase_project_id"><?= firebase_label_required('Firebase Project ID') ?></label>
                <input class="at-input at-focusable w-full" id="firebase_project_id" name="firebase_project_id" required
                       placeholder="e.g. veggie-tokri-prod"
                       value="<?= Security::escape((string) $form['firebase_project_id']) ?>" <?= $id > 0 ? 'readonly' : '' ?>>
                <?php if ($id > 0): ?>
                    <p class="text-xs at-muted mt-1">Project ID cannot be changed after creation.</p>
                <?php else: ?>
                    <p class="text-xs at-muted mt-1">From Firebase Console → Project settings → General.</p>
                <?php endif; ?>
            </div>
            <div class="span-full">
                <label class="at-label" for="firebase_project_number"><?= firebase_label_optional('Sender ID / Project number') ?></label>
                <input class="at-input at-focusable w-full" id="firebase_project_number" name="firebase_project_number"
                       placeholder="e.g. 123456789012"
                       value="<?= Security::escape((string) ($form['firebase_project_number'] ?? '')) ?>">
                <p class="text-xs at-muted mt-1">Used as Web Push <code>messagingSenderId</code>.</p>
            </div>
        </div>
        <?php firebase_form_section_end(); ?>

        <?php firebase_form_section_start(
            'Website configuration',
            'Public web config from Firebase Console → Project settings → Your apps → Web.'
        ); ?>
        <div class="at-firebase-form__grid at-firebase-form__grid--2">
            <div>
                <label class="at-label" for="web_api_key"><?= firebase_label_optional('Web API Key') ?></label>
                <input class="at-input at-focusable w-full" id="web_api_key" name="web_api_key"
                       placeholder="e.g. AIzaSy…"
                       value="<?= Security::escape((string) ($form['web_api_key'] ?? '')) ?>">
            </div>
            <div>
                <label class="at-label" for="web_app_id"><?= firebase_label_optional('Web App ID') ?></label>
                <input class="at-input at-focusable w-full" id="web_app_id" name="web_app_id"
                       placeholder="e.g. 1:123456789012:web:abcdef123456"
                       value="<?= Security::escape((string) ($form['web_app_id'] ?? '')) ?>">
            </div>
            <div>
                <label class="at-label" for="web_auth_domain"><?= firebase_label_optional('Auth domain') ?></label>
                <input class="at-input at-focusable w-full" id="web_auth_domain" name="web_auth_domain"
                       placeholder="e.g. veggie-tokri-prod.firebaseapp.com"
                       value="<?= Security::escape((string) ($form['web_auth_domain'] ?? '')) ?>">
            </div>
            <div>
                <label class="at-label" for="web_storage_bucket"><?= firebase_label_optional('Storage bucket') ?></label>
                <input class="at-input at-focusable w-full" id="web_storage_bucket" name="web_storage_bucket"
                       placeholder="e.g. veggie-tokri-prod.firebasestorage.app"
                       value="<?= Security::escape((string) ($form['web_storage_bucket'] ?? '')) ?>">
            </div>
            <div>
                <label class="at-label" for="web_measurement_id"><?= firebase_label_optional('Measurement ID') ?></label>
                <input class="at-input at-focusable w-full" id="web_measurement_id" name="web_measurement_id"
                       placeholder="e.g. G-XXXXXXXXXX"
                       value="<?= Security::escape((string) ($form['web_measurement_id'] ?? '')) ?>">
            </div>
            <div class="span-full">
                <label class="at-label" for="vapid_public_key"><?= firebase_label_optional('VAPID public key (Web Push)') ?></label>
                <input class="at-input at-focusable w-full at-input--truncate-placeholder" id="vapid_public_key" name="vapid_public_key"
                       placeholder="e.g. BDHxMk3Vn4qP8wY… (base64 URL-safe public key)"
                       value="<?= Security::escape((string) ($form['vapid_public_key'] ?? '')) ?>">
                <p class="text-xs at-muted mt-1">From Cloud Messaging → Web configuration. Public key only — never paste the private VAPID key.</p>
            </div>
        </div>
        <?php firebase_form_section_end(); ?>

        <?php firebase_form_section_start('Android configuration', 'Optional — required when sending to the native Android app.'); ?>
        <div class="at-firebase-form__grid at-firebase-form__grid--2">
            <div>
                <label class="at-label" for="android_app_id"><?= firebase_label_optional('Android Firebase App ID') ?></label>
                <input class="at-input at-focusable w-full" id="android_app_id" name="android_app_id"
                       placeholder="e.g. 1:123456789012:android:abcdef123456"
                       value="<?= Security::escape((string) ($form['android_app_id'] ?? '')) ?>">
            </div>
            <div>
                <label class="at-label" for="android_package_name"><?= firebase_label_optional('Package name') ?></label>
                <input class="at-input at-focusable w-full" id="android_package_name" name="android_package_name"
                       placeholder="e.g. com.veggietokri.app"
                       value="<?= Security::escape((string) ($form['android_package_name'] ?? '')) ?>">
            </div>
        </div>
        <?php firebase_form_section_end(); ?>

        <?php firebase_form_section_start(
            'Service account credentials',
            'Private server credential — encrypted at rest. Never shown in the browser or storefront.'
        ); ?>
        <p class="text-sm at-muted mb-2">
            Download JSON from <strong>Firebase Console → Project settings → Service accounts → Generate new private key</strong>.
        </p>
        <?php if ($hasStoredServiceAccount): ?>
            <p class="text-sm at-muted mb-3">A service account is already stored. Upload or paste JSON only when rotating credentials.</p>
        <?php endif; ?>
        <div class="at-firebase-form__grid at-firebase-form__grid--2">
            <div class="span-full">
                <label class="at-label" for="service_account"><?= firebase_label_optional('Upload JSON key') ?></label>
                <input type="file" id="service_account" name="service_account" accept="application/json,.json"
                       class="at-input at-input--file at-focusable w-full">
                <p class="text-xs at-muted mt-1">Example filename: <code>firebase-service-account.json</code></p>
            </div>
            <div class="span-full">
                <label class="at-label" for="service_account_paste"><?= firebase_label_optional('Or paste JSON') ?></label>
                <textarea class="at-input at-input--mono at-focusable w-full" id="service_account_paste" name="service_account_paste" rows="5"
                          placeholder="Paste full service account JSON only when adding or replacing credentials."></textarea>
            </div>
        </div>
        <?php firebase_form_section_end(); ?>

        <?php firebase_form_section_start('Additional notes', 'Internal reference for your team (not exposed to customers).'); ?>
        <div>
            <label class="at-label" for="platform_notes"><?= firebase_label_optional('Notes') ?></label>
            <textarea class="at-input at-focusable w-full" id="platform_notes" name="platform_notes" rows="3"
                      placeholder="e.g. Staging Firebase project — dry-run testing only"><?= Security::escape((string) ($form['platform_notes'] ?? '')) ?></textarea>
        </div>
        <?php firebase_form_section_end(); ?>
    </div>
    <div class="at-admin-card__footer flex flex-wrap gap-2 justify-end">
        <a href="<?= Security::escape($base) ?>/index.php" class="at-btn at-btn--secondary at-focusable">Back</a>
        <button type="submit" class="at-btn at-btn--primary at-focusable">Save project</button>
    </div>
</form>

<?php admin_layout_end(); ?>
