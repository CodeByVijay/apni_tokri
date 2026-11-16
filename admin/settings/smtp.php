<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('settings');
$useDb = Install::isSchemaReady() && AdminAuth::check();

$errors = [];
$testErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!$useDb) {
        admin_flash_set('SMTP settings require an installed database.', 'error');
        header('Location: ' . url_base() . '/admin/settings/smtp.php', true, 302);
        exit;
    }

    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : 'save';

    if ($action === 'send_test') {
        $recipient = trim((string) ($_POST['test_recipient'] ?? ''));
        $testResult = SmtpSettingsService::sendTestEmail($recipient);
        if (!empty($testResult['ok'])) {
            admin_flash_set((string) ($testResult['message'] ?? 'Test email sent.'));
        } else {
            if (!empty($testResult['errors']) && is_array($testResult['errors'])) {
                $_SESSION['smtp_test_errors'] = $testResult['errors'];
            }
            admin_flash_set((string) ($testResult['message'] ?? 'Test email failed.'), 'error');
        }
        header('Location: ' . url_base() . '/admin/settings/smtp.php', true, 302);
        exit;
    }

    $save = SmtpSettingsService::saveFromRequest($_POST);
    if (!empty($save['ok'])) {
        admin_flash_set((string) ($save['message'] ?? 'SMTP settings saved.'));
    } else {
        if (!empty($save['errors']) && is_array($save['errors'])) {
            $errors = $save['errors'];
        }
        admin_flash_set((string) ($save['message'] ?? 'Could not save SMTP settings.'), 'error');
        $_SESSION['smtp_form_errors'] = $errors;
        $_SESSION['smtp_form_post'] = $_POST;
    }
    header('Location: ' . url_base() . '/admin/settings/smtp.php', true, 302);
    exit;
}

$form = SmtpSettingsService::adminFormState();
if (!empty($_SESSION['smtp_form_post']) && is_array($_SESSION['smtp_form_post'])) {
    $posted = $_SESSION['smtp_form_post'];
    unset($_SESSION['smtp_form_post']);
    $form['enabled'] = !empty($posted['enabled']);
    $form['host'] = trim((string) ($posted['host'] ?? $form['host']));
    $form['port'] = (int) ($posted['port'] ?? $form['port']);
    $form['encryption'] = (string) ($posted['encryption'] ?? $form['encryption']);
    $form['auth_enabled'] = !empty($posted['auth_enabled']);
    $form['username'] = trim((string) ($posted['username'] ?? $form['username']));
    $form['from_email'] = trim((string) ($posted['from_email'] ?? $form['from_email']));
    $form['from_name'] = trim((string) ($posted['from_name'] ?? $form['from_name']));
    $form['timeout_seconds'] = (int) ($posted['timeout_seconds'] ?? $form['timeout_seconds']);
}
if (!empty($_SESSION['smtp_form_errors']) && is_array($_SESSION['smtp_form_errors'])) {
    $errors = $_SESSION['smtp_form_errors'];
    unset($_SESSION['smtp_form_errors']);
}

$testErrors = [];
if (!empty($_SESSION['smtp_test_errors']) && is_array($_SESSION['smtp_test_errors'])) {
    $testErrors = $_SESSION['smtp_test_errors'];
    unset($_SESSION['smtp_test_errors']);
}

admin_layout_start(['pageTitle' => 'SMTP settings', 'activeAdminNav' => 'settings']);
$pageHeading = 'SMTP settings';
$pageDescription = 'Outbound email transport for admin test messages. Passwords are encrypted and never shown after save.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$settingsTab = 'smtp';
require APNI_TOKRI_ROOT . '/includes/partials/admin/settings-nav.php';
?>

<form method="post" class="at-card max-w-2xl mb-6">
    <div class="at-card__body space-y-4">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save">

        <label class="flex gap-2 text-sm">
            <input type="checkbox" name="enabled" value="1" <?= $form['enabled'] ? 'checked' : '' ?>>
            SMTP enabled
        </label>

        <div>
            <label class="at-label" for="smtp_host">SMTP host</label>
            <input id="smtp_host" name="host" class="at-input at-focusable" autocomplete="off" value="<?= Security::escape($form['host']) ?>">
            <?php if (isset($errors['host'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['host']) ?></p><?php endif; ?>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="at-label" for="smtp_port">Port</label>
                <input id="smtp_port" name="port" type="number" min="1" max="65535" class="at-input at-focusable" value="<?= Security::escape((string) $form['port']) ?>">
                <?php if (isset($errors['port'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['port']) ?></p><?php endif; ?>
            </div>
            <div>
                <label class="at-label" for="smtp_encryption">Encryption</label>
                <select id="smtp_encryption" name="encryption" class="at-input at-focusable">
                    <?php foreach (SmtpSettingsService::encryptionModes() as $mode): ?>
                        <option value="<?= Security::escape($mode) ?>" <?= $form['encryption'] === $mode ? 'selected' : '' ?>><?= Security::escape(strtoupper($mode)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['encryption'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['encryption']) ?></p><?php endif; ?>
            </div>
        </div>

        <label class="flex gap-2 text-sm">
            <input type="checkbox" name="auth_enabled" value="1" <?= $form['auth_enabled'] ? 'checked' : '' ?>>
            SMTP authentication
        </label>

        <div>
            <label class="at-label" for="smtp_username">Username</label>
            <input id="smtp_username" name="username" class="at-input at-focusable" autocomplete="off" value="<?= Security::escape($form['username']) ?>">
            <?php if (isset($errors['username'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['username']) ?></p><?php endif; ?>
        </div>

        <div>
            <label class="at-label" for="smtp_password">Password</label>
            <input id="smtp_password" name="password" type="password" class="at-input at-focusable" autocomplete="new-password"
                   placeholder="<?= $form['has_password'] ? 'Saved (enter to replace)' : 'Enter SMTP password' ?>">
            <?php if (isset($errors['password'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['password']) ?></p><?php endif; ?>
        </div>

        <div>
            <label class="at-label" for="smtp_from_email">Sender email</label>
            <input id="smtp_from_email" name="from_email" type="email" class="at-input at-focusable" autocomplete="off" value="<?= Security::escape($form['from_email']) ?>">
            <?php if (isset($errors['from_email'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['from_email']) ?></p><?php endif; ?>
        </div>

        <div>
            <label class="at-label" for="smtp_from_name">Sender display name</label>
            <input id="smtp_from_name" name="from_name" class="at-input at-focusable" value="<?= Security::escape($form['from_name']) ?>">
        </div>

        <div>
            <label class="at-label" for="smtp_timeout">Connection timeout (seconds)</label>
            <input id="smtp_timeout" name="timeout_seconds" type="number" min="1" max="120" class="at-input at-focusable" value="<?= Security::escape((string) $form['timeout_seconds']) ?>">
            <?php if (isset($errors['timeout_seconds'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['timeout_seconds']) ?></p><?php endif; ?>
        </div>

        <button type="submit" class="at-btn at-btn--primary at-focusable">Save SMTP settings</button>
    </div>
</form>

<form method="post" class="at-card max-w-2xl">
    <div class="at-card__body space-y-4">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="send_test">
        <h3 class="font-bold">Send test email</h3>
        <p class="text-sm at-muted">Uses the saved SMTP configuration above. Does not change settings.</p>
        <div>
            <label class="at-label" for="test_recipient">Recipient email</label>
            <input id="test_recipient" name="test_recipient" type="email" class="at-input at-focusable" autocomplete="off" required>
            <?php if (isset($testErrors['test_recipient'])): ?><p class="text-xs text-red-600"><?= Security::escape($testErrors['test_recipient']) ?></p><?php endif; ?>
        </div>
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Send test email</button>
    </div>
</form>
<?php admin_layout_end(); ?>
