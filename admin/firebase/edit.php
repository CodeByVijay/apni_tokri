<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

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
?>

<?php foreach ($errors as $err): ?>
<div class="at-alert at-alert--error mb-3"><?= Security::escape($err) ?></div>
<?php endforeach; ?>

<form method="post" enctype="multipart/form-data" class="at-card max-w-3xl space-y-0">
    <div class="at-card__body space-y-4">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) $id ?>">

        <div>
            <label class="at-label" for="name">Display name</label>
            <input class="at-input at-focusable" id="name" name="name" required
                   placeholder="e.g. Veggie Tokri Production"
                   value="<?= Security::escape((string) $form['name']) ?>">
            <p class="text-xs at-muted mt-1">Admin-friendly label — not sent to Firebase.</p>
        </div>
        <div>
            <label class="at-label" for="firebase_project_id">Firebase Project ID</label>
            <input class="at-input at-focusable" id="firebase_project_id" name="firebase_project_id" required
                   placeholder="e.g. veggie-tokri-prod"
                   value="<?= Security::escape((string) $form['firebase_project_id']) ?>" <?= $id > 0 ? 'readonly' : '' ?>>
            <?php if ($id > 0): ?>
                <p class="text-xs at-muted mt-1">Project ID cannot be changed after creation.</p>
            <?php else: ?>
                <p class="text-xs at-muted mt-1">From Firebase Console → Project settings → General.</p>
            <?php endif; ?>
        </div>
        <div>
            <label class="at-label" for="firebase_project_number">Sender ID / Project number</label>
            <input class="at-input at-focusable" id="firebase_project_number" name="firebase_project_number"
                   placeholder="e.g. 123456789012"
                   value="<?= Security::escape((string) ($form['firebase_project_number'] ?? '')) ?>">
            <p class="text-xs at-muted mt-1">Also used as the Web Push messaging sender ID (<code>messagingSenderId</code>).</p>
        </div>

        <h3 class="font-bold pt-2">Website (FCM Web Push)</h3>
        <p class="text-xs at-muted">Values from Firebase Console → Project settings → Your apps → Web app config.</p>
        <div class="grid sm:grid-cols-2 gap-3">
            <div>
                <label class="at-label" for="web_api_key">Web API Key</label>
                <input class="at-input at-focusable" id="web_api_key" name="web_api_key"
                       placeholder="e.g. AIzaSy…"
                       value="<?= Security::escape((string) ($form['web_api_key'] ?? '')) ?>">
            </div>
            <div>
                <label class="at-label" for="web_app_id">Web App ID</label>
                <input class="at-input at-focusable" id="web_app_id" name="web_app_id"
                       placeholder="e.g. 1:123456789012:web:abcdef123456"
                       value="<?= Security::escape((string) ($form['web_app_id'] ?? '')) ?>">
            </div>
            <div>
                <label class="at-label" for="web_auth_domain">Auth domain</label>
                <input class="at-input at-focusable" id="web_auth_domain" name="web_auth_domain"
                       placeholder="e.g. veggie-tokri-prod.firebaseapp.com"
                       value="<?= Security::escape((string) ($form['web_auth_domain'] ?? '')) ?>">
            </div>
            <div>
                <label class="at-label" for="web_storage_bucket">Storage bucket</label>
                <input class="at-input at-focusable" id="web_storage_bucket" name="web_storage_bucket"
                       placeholder="e.g. veggie-tokri-prod.firebasestorage.app"
                       value="<?= Security::escape((string) ($form['web_storage_bucket'] ?? '')) ?>">
            </div>
            <div>
                <label class="at-label" for="web_measurement_id">Measurement ID</label>
                <input class="at-input at-focusable" id="web_measurement_id" name="web_measurement_id"
                       placeholder="e.g. G-XXXXXXXXXX"
                       value="<?= Security::escape((string) ($form['web_measurement_id'] ?? '')) ?>">
                <p class="text-xs at-muted mt-1">Optional — only if Google Analytics is linked.</p>
            </div>
            <div class="sm:col-span-2">
                <label class="at-label" for="vapid_public_key">VAPID public key (Web Push)</label>
                <input class="at-input at-focusable" id="vapid_public_key" name="vapid_public_key"
                       placeholder="e.g. BDHxMk3Vn4qP8wY… (base64 URL-safe key pair from Cloud Messaging → Web configuration)"
                       value="<?= Security::escape((string) ($form['vapid_public_key'] ?? '')) ?>">
                <p class="text-xs at-muted mt-1">Public key only — generate or copy from Firebase Console. Never paste the private VAPID key here.</p>
            </div>
        </div>

        <h3 class="font-bold pt-2">Android</h3>
        <div class="grid sm:grid-cols-2 gap-3">
            <div>
                <label class="at-label" for="android_app_id">Android Firebase App ID</label>
                <input class="at-input at-focusable" id="android_app_id" name="android_app_id"
                       placeholder="e.g. 1:123456789012:android:abcdef123456"
                       value="<?= Security::escape((string) ($form['android_app_id'] ?? '')) ?>">
            </div>
            <div>
                <label class="at-label" for="android_package_name">Package name</label>
                <input class="at-input at-focusable" id="android_package_name" name="android_package_name"
                       placeholder="e.g. com.veggietokri.app"
                       value="<?= Security::escape((string) ($form['android_package_name'] ?? '')) ?>">
            </div>
        </div>

        <h3 class="font-bold pt-2">Service account (server only)</h3>
        <p class="text-sm at-muted">
            Download a JSON key from <strong>Firebase Console → Project settings → Service accounts → Generate new private key</strong>.
            This file is a <strong>private server credential</strong> — encrypt-at-rest on save, never shown in the browser or storefront, and never committed to Git.
        </p>
        <?php if ($hasStoredServiceAccount): ?>
            <p class="text-sm at-muted">A service account is already stored for this project. Upload or paste JSON only when rotating credentials.</p>
        <?php endif; ?>
        <div>
            <label class="at-label" for="service_account">Upload JSON key</label>
            <input type="file" id="service_account" name="service_account" accept="application/json,.json" class="at-input at-focusable max-w-full">
            <p class="text-xs at-muted mt-1">Example filename: <code>firebase-service-account.json</code></p>
        </div>
        <div>
            <label class="at-label" for="service_account_paste">Or paste JSON</label>
            <textarea class="at-input at-focusable" id="service_account_paste" name="service_account_paste" rows="4"
                      placeholder="Paste full service account JSON only when adding or replacing credentials — never share this key."></textarea>
        </div>

        <div>
            <label class="at-label" for="platform_notes">Notes</label>
            <textarea class="at-input at-focusable" id="platform_notes" name="platform_notes" rows="2"
                      placeholder="e.g. Staging Firebase project — dry-run testing only"><?= Security::escape((string) ($form['platform_notes'] ?? '')) ?></textarea>
            <p class="text-xs at-muted mt-1">Internal notes for your team (not exposed to customers).</p>
        </div>
    </div>
    <div class="at-card__footer flex gap-2">
        <button type="submit" class="at-btn at-btn--primary">Save</button>
        <a href="<?= Security::escape($base) ?>/index.php" class="at-btn at-btn--secondary">Back</a>
    </div>
</form>

<?php admin_layout_end(); ?>
