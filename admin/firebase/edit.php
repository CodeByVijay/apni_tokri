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
            <input class="at-input at-focusable" id="name" name="name" required value="<?= Security::escape((string) $form['name']) ?>">
        </div>
        <div>
            <label class="at-label" for="firebase_project_id">Firebase Project ID</label>
            <input class="at-input at-focusable" id="firebase_project_id" name="firebase_project_id" required
                   value="<?= Security::escape((string) $form['firebase_project_id']) ?>" <?= $id > 0 ? 'readonly' : '' ?>>
        </div>
        <div>
            <label class="at-label" for="firebase_project_number">Sender ID / Project number</label>
            <input class="at-input at-focusable" id="firebase_project_number" name="firebase_project_number"
                   value="<?= Security::escape((string) ($form['firebase_project_number'] ?? '')) ?>">
        </div>

        <h3 class="font-bold pt-2">Website (FCM Web Push)</h3>
        <div class="grid sm:grid-cols-2 gap-3">
            <div><label class="at-label" for="web_api_key">Web API Key</label><input class="at-input" id="web_api_key" name="web_api_key" value="<?= Security::escape((string) ($form['web_api_key'] ?? '')) ?>"></div>
            <div><label class="at-label" for="web_app_id">Web App ID</label><input class="at-input" id="web_app_id" name="web_app_id" value="<?= Security::escape((string) ($form['web_app_id'] ?? '')) ?>"></div>
            <div><label class="at-label" for="web_auth_domain">Auth domain</label><input class="at-input" id="web_auth_domain" name="web_auth_domain" value="<?= Security::escape((string) ($form['web_auth_domain'] ?? '')) ?>"></div>
            <div><label class="at-label" for="web_storage_bucket">Storage bucket</label><input class="at-input" id="web_storage_bucket" name="web_storage_bucket" value="<?= Security::escape((string) ($form['web_storage_bucket'] ?? '')) ?>"></div>
            <div><label class="at-label" for="web_measurement_id">Measurement ID</label><input class="at-input" id="web_measurement_id" name="web_measurement_id" value="<?= Security::escape((string) ($form['web_measurement_id'] ?? '')) ?>"></div>
            <div class="sm:col-span-2"><label class="at-label" for="vapid_public_key">VAPID public key (Web Push)</label><input class="at-input" id="vapid_public_key" name="vapid_public_key" value="<?= Security::escape((string) ($form['vapid_public_key'] ?? '')) ?>"></div>
        </div>

        <h3 class="font-bold pt-2">Android</h3>
        <div class="grid sm:grid-cols-2 gap-3">
            <div><label class="at-label" for="android_app_id">Android Firebase App ID</label><input class="at-input" id="android_app_id" name="android_app_id" value="<?= Security::escape((string) ($form['android_app_id'] ?? '')) ?>"></div>
            <div><label class="at-label" for="android_package_name">Package name</label><input class="at-input" id="android_package_name" name="android_package_name" value="<?= Security::escape((string) ($form['android_package_name'] ?? '')) ?>"></div>
        </div>

        <h3 class="font-bold pt-2">Service account (server only)</h3>
        <?php if (trim((string) ($form['service_account_client_email'] ?? '')) !== ''): ?>
            <p class="text-sm at-muted">Stored credentials: <?= Security::escape((string) $form['service_account_client_email']) ?> (not shown in full)</p>
        <?php endif; ?>
        <div>
            <label class="at-label" for="service_account">Upload JSON key</label>
            <input type="file" id="service_account" name="service_account" accept="application/json,.json">
        </div>
        <div>
            <label class="at-label" for="service_account_paste">Or paste JSON</label>
            <textarea class="at-input" id="service_account_paste" name="service_account_paste" rows="4" placeholder="Paste only when replacing credentials…"></textarea>
        </div>

        <div>
            <label class="at-label" for="platform_notes">Notes</label>
            <textarea class="at-input" id="platform_notes" name="platform_notes" rows="2"><?= Security::escape((string) ($form['platform_notes'] ?? '')) ?></textarea>
        </div>
    </div>
    <div class="at-card__footer flex gap-2">
        <button type="submit" class="at-btn at-btn--primary">Save</button>
        <a href="<?= Security::escape($base) ?>/index.php" class="at-btn at-btn--secondary">Back</a>
    </div>
</form>

<?php admin_layout_end(); ?>
