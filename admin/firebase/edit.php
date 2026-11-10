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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors !== []) {
    foreach ($_POST as $k => $v) {
        if (is_string($v) && array_key_exists($k, $form)) {
            $form[$k] = $v;
        }
    }
    $platforms = FirebaseProjectForm::platformsFromInput($_POST);
} elseif (is_array($row)) {
    $platforms = FirebaseProjectForm::platformsFromRow($form);
} else {
    $platforms = ['web' => true, 'android' => true];
}

$hasStoredServiceAccount = $id > 0 && trim((string) ($form['service_account_client_email'] ?? '')) !== '';

admin_layout_start(['pageTitle' => $id > 0 ? 'Edit Firebase project' : 'Add Firebase project', 'activeAdminNav' => 'firebase']);
$pageHeading = $id > 0 ? 'Edit Firebase project' : 'Add Firebase project';
$pageDescription = 'Configure platform-specific Firebase settings. Encrypted server credentials; setup guide below the form.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
firebase_admin_subnav('projects');

$progress = FirebaseSetupProgress::build(is_array($row) ? $row : null, $platforms);
?>

<?php foreach ($errors as $err): ?>
<div class="at-alert at-alert--error mb-3 max-w-[56rem] mx-auto w-full"><?= Security::escape($err) ?></div>
<?php endforeach; ?>

<div class="at-firebase-project-page">
<form method="post" enctype="multipart/form-data" class="at-admin-card at-firebase-project-form">
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
                <?php firebase_field_help('Friendly name shown in Admin — not sent to Firebase.'); ?>
            </div>
            <div>
                <label class="at-label" for="firebase_project_id"><?= firebase_label_required('Firebase Project ID') ?></label>
                <input class="at-input at-focusable w-full" id="firebase_project_id" name="firebase_project_id" required
                       placeholder="e.g. veggie-tokri-prod"
                       value="<?= Security::escape((string) $form['firebase_project_id']) ?>" <?= $id > 0 ? 'readonly' : '' ?>>
                <?php if ($id > 0): ?>
                    <p class="text-xs at-muted mt-1">Project ID cannot be changed after creation.</p>
                <?php else: ?>
                    <?php firebase_field_help('Firebase Console → Project settings → General → Project ID. Cannot be changed after the project is saved.'); ?>
                <?php endif; ?>
            </div>
            <div class="span-full">
                <label class="at-label" for="firebase_project_number" id="label-firebase_project_number">
                    <?= $platforms['web'] ? firebase_label_required('Sender ID / Project number') : firebase_label_optional('Sender ID / Project number') ?>
                </label>
                <input class="at-input at-focusable w-full" id="firebase_project_number" name="firebase_project_number"
                       placeholder="e.g. 123456789012"
                       value="<?= Security::escape((string) ($form['firebase_project_number'] ?? '')) ?>"
                       data-required-platform="web">
                <?php firebase_field_help('Project settings → General → Project number. Must match the number inside Web/Android App IDs (1:NUMBER:…).'); ?>
            </div>
        </div>
        <?php firebase_form_section_end(); ?>

        <?php firebase_form_section_start(
            'Push platforms',
            'Choose which channels this Firebase project config is used for. One project remains globally active for all selected platforms.'
        ); ?>
        <fieldset class="at-firebase-platform-pick">
            <legend class="sr-only">Enabled push platforms</legend>
            <label class="at-firebase-platform-pick__item">
                <input type="checkbox" name="platform_web" value="1" class="at-focusable js-platform-toggle" data-platform="web"
                    <?= $platforms['web'] ? 'checked' : '' ?>>
                <span>Website Push</span>
            </label>
            <label class="at-firebase-platform-pick__item">
                <input type="checkbox" name="platform_android" value="1" class="at-focusable js-platform-toggle" data-platform="android"
                    <?= $platforms['android'] ? 'checked' : '' ?>>
                <span>Android Native Push</span>
            </label>
        </fieldset>
        <p class="text-xs at-muted mt-1">Unchecking a platform hides its fields but preserved values stay saved when you submit.</p>
        <?php firebase_form_section_end(); ?>

        <?php firebase_form_section_start(
            'Website configuration',
            'Public web config from Firebase Console → Project settings → Your apps → Web.'
        ); ?>
        <div id="firebase-section-web" class="js-platform-section" data-platform-section="web"<?= $platforms['web'] ? '' : ' hidden' ?>>
        <div class="at-firebase-form__grid at-firebase-form__grid--2">
            <div>
                <label class="at-label" for="web_api_key"><?= firebase_label_required('Web API Key') ?></label>
                <input class="at-input at-focusable w-full" id="web_api_key" name="web_api_key"
                       placeholder="e.g. AIzaSy…"
                       value="<?= Security::escape((string) ($form['web_api_key'] ?? '')) ?>"
                       data-required-platform="web">
                <?php firebase_field_help('Web app config → apiKey'); ?>
            </div>
            <div>
                <label class="at-label" for="web_app_id"><?= firebase_label_required('Web App ID') ?></label>
                <input class="at-input at-focusable w-full" id="web_app_id" name="web_app_id"
                       placeholder="e.g. 1:123456789012:web:abcdef123456"
                       value="<?= Security::escape((string) ($form['web_app_id'] ?? '')) ?>"
                       data-required-platform="web">
                <?php firebase_field_help('Format: 1:PROJECT_NUMBER:web:…'); ?>
            </div>
            <div>
                <label class="at-label" for="web_auth_domain"><?= firebase_label_required('Auth domain') ?></label>
                <input class="at-input at-focusable w-full" id="web_auth_domain" name="web_auth_domain"
                       placeholder="e.g. veggie-tokri-prod.firebaseapp.com"
                       value="<?= Security::escape((string) ($form['web_auth_domain'] ?? '')) ?>"
                       data-required-platform="web">
                <?php firebase_field_help('Usually PROJECT_ID.firebaseapp.com'); ?>
            </div>
            <div>
                <label class="at-label" for="web_storage_bucket"><?= firebase_label_required('Storage bucket') ?></label>
                <input class="at-input at-focusable w-full" id="web_storage_bucket" name="web_storage_bucket"
                       placeholder="e.g. veggie-tokri-prod.firebasestorage.app"
                       value="<?= Security::escape((string) ($form['web_storage_bucket'] ?? '')) ?>"
                       data-required-platform="web">
                <?php firebase_field_help('projectId.appspot.com or *.firebasestorage.app'); ?>
            </div>
            <div>
                <label class="at-label" for="web_measurement_id"><?= firebase_label_required('Measurement ID') ?></label>
                <input class="at-input at-focusable w-full" id="web_measurement_id" name="web_measurement_id"
                       placeholder="e.g. G-XXXXXXXXXX"
                       value="<?= Security::escape((string) ($form['web_measurement_id'] ?? '')) ?>"
                       data-required-platform="web">
                <?php firebase_field_help('Google Analytics measurement ID; use G-NOTUSED if Analytics is off.'); ?>
            </div>
            <div class="span-full">
                <label class="at-label" for="vapid_public_key"><?= firebase_label_required('VAPID public key (Web Push)') ?></label>
                <input class="at-input at-focusable w-full at-input--truncate-placeholder" id="vapid_public_key" name="vapid_public_key"
                       placeholder="e.g. BDHxMk3Vn4qP8wY… (base64 URL-safe public key)"
                       value="<?= Security::escape((string) ($form['vapid_public_key'] ?? '')) ?>"
                       data-required-platform="web">
                <?php firebase_field_help('Project settings → Cloud Messaging → Web Push certificates — public key only.'); ?>
            </div>
        </div>
        </div>
        <?php firebase_form_section_end(); ?>

        <?php firebase_form_section_start('Android configuration', 'Native app registration from Firebase Console → Your apps → Android.'); ?>
        <div id="firebase-section-android" class="js-platform-section" data-platform-section="android"<?= $platforms['android'] ? '' : ' hidden' ?>>
        <div class="at-firebase-form__grid at-firebase-form__grid--2">
            <div>
                <label class="at-label" for="android_app_id"><?= firebase_label_required('Android Firebase App ID') ?></label>
                <input class="at-input at-focusable w-full" id="android_app_id" name="android_app_id"
                       placeholder="e.g. 1:123456789012:android:abcdef123456"
                       value="<?= Security::escape((string) ($form['android_app_id'] ?? '')) ?>"
                       data-required-platform="android">
                <?php firebase_field_help('1:PROJECT_NUMBER:android:… from Project settings → Your apps → Android.'); ?>
            </div>
            <div>
                <label class="at-label" for="android_package_name"><?= firebase_label_required('Package name') ?></label>
                <input class="at-input at-focusable w-full" id="android_package_name" name="android_package_name"
                       placeholder="e.g. com.veggietokri.app"
                       value="<?= Security::escape((string) ($form['android_package_name'] ?? '')) ?>"
                       data-required-platform="android">
                <?php firebase_field_help('Must match Android applicationId exactly (e.g. com.veggietokri.app).'); ?>
            </div>
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
                <label class="at-label" for="service_account"><?= $id > 0 ? firebase_label_optional('Upload JSON key') : firebase_label_required('Upload JSON key') ?></label>
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

<?php firebase_render_setup_guide($id, $platforms, $progress, $base); ?>
</div>

<script>
(function () {
    var toggles = document.querySelectorAll('.js-platform-toggle');
    var sections = document.querySelectorAll('.js-platform-section');
    var webFields = document.querySelectorAll('[data-required-platform="web"]');
    var andFields = document.querySelectorAll('[data-required-platform="android"]');
    var senderLabel = document.getElementById('label-firebase_project_number');
    var guideWeb = document.querySelector('.js-guide-platform-web');
    var guideAndroid = document.querySelector('.js-guide-platform-android');

    function platformState() {
        var web = false;
        var android = false;
        toggles.forEach(function (el) {
            if (el.dataset.platform === 'web') web = el.checked;
            if (el.dataset.platform === 'android') android = el.checked;
        });
        return { web: web, android: android };
    }

    function apply() {
        var state = platformState();
        sections.forEach(function (sec) {
            var key = sec.getAttribute('data-platform-section');
            var show = key === 'web' ? state.web : state.android;
            sec.hidden = !show;
        });
        webFields.forEach(function (el) { el.required = state.web; });
        andFields.forEach(function (el) { el.required = state.android; });
        if (senderLabel) {
            senderLabel.innerHTML = state.web
                ? 'Sender ID / Project number <span class="at-label__required" aria-hidden="true">*</span>'
                : 'Sender ID / Project number <span class="at-label__optional">(optional)</span>';
        }
        if (guideWeb) {
            guideWeb.hidden = !state.web;
        }
        if (guideAndroid) {
            guideAndroid.hidden = !state.android;
        }
    }

    toggles.forEach(function (el) { el.addEventListener('change', apply); });
    apply();

    var guidePanel = document.getElementById('firebase-setup-guide-panel');
    var guideLabel = document.querySelector('.js-guide-toggle-label');
    if (guidePanel && guideLabel) {
        function syncGuideToggle() {
            guideLabel.textContent = guidePanel.open ? 'Hide Setup Guide' : 'View Setup Guide';
            guidePanel.setAttribute('aria-expanded', guidePanel.open ? 'true' : 'false');
        }
        guidePanel.addEventListener('toggle', syncGuideToggle);
        guidePanel.setAttribute('aria-expanded', guidePanel.open ? 'true' : 'false');
        syncGuideToggle();
    }
})();
</script>

<?php admin_layout_end(); ?>
