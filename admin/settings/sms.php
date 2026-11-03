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
        admin_flash_set('SMS settings require an installed database.', 'error');
        header('Location: ' . url_base() . '/admin/settings/sms.php', true, 302);
        exit;
    }

    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : 'save';

    if ($action === 'send_test') {
        $mobile = trim((string) ($_POST['test_mobile'] ?? ''));
        $testResult = SmsSettingsService::sendTestSms($mobile);
        if (!empty($testResult['ok'])) {
            admin_flash_set((string) ($testResult['message'] ?? 'Test SMS sent.'));
        } else {
            if (!empty($testResult['errors']) && is_array($testResult['errors'])) {
                $_SESSION['sms_test_errors'] = $testResult['errors'];
            }
            admin_flash_set((string) ($testResult['message'] ?? 'Test SMS failed.'), 'error');
        }
        header('Location: ' . url_base() . '/admin/settings/sms.php', true, 302);
        exit;
    }

    $save = SmsSettingsService::saveFromRequest($_POST);
    if (!empty($save['ok'])) {
        admin_flash_set((string) ($save['message'] ?? 'SMS settings saved.'));
    } else {
        if (!empty($save['errors']) && is_array($save['errors'])) {
            $errors = $save['errors'];
        }
        admin_flash_set((string) ($save['message'] ?? 'Could not save SMS settings.'), 'error');
        $_SESSION['sms_form_errors'] = $errors;
        $_SESSION['sms_form_post'] = $_POST;
    }
    header('Location: ' . url_base() . '/admin/settings/sms.php', true, 302);
    exit;
}

$form = SmsSettingsService::adminFormState();
if (!empty($_SESSION['sms_form_post']) && is_array($_SESSION['sms_form_post'])) {
    $posted = $_SESSION['sms_form_post'];
    unset($_SESSION['sms_form_post']);
    $form['operation_mode'] = (string) ($posted['operation_mode'] ?? $form['operation_mode']);
    $form['test_fixed_otp'] = trim((string) ($posted['test_fixed_otp'] ?? $form['test_fixed_otp']));
    $form['api_url'] = trim((string) ($posted['api_url'] ?? $form['api_url']));
    $form['sender_id'] = trim((string) ($posted['sender_id'] ?? $form['sender_id']));
}
if (!empty($_SESSION['sms_form_errors']) && is_array($_SESSION['sms_form_errors'])) {
    $errors = $_SESSION['sms_form_errors'];
    unset($_SESSION['sms_form_errors']);
}
if (!empty($_SESSION['sms_test_errors']) && is_array($_SESSION['sms_test_errors'])) {
    $testErrors = $_SESSION['sms_test_errors'];
    unset($_SESSION['sms_test_errors']);
}

$diag = $form['diagnostics'] ?? [];
$mode = $form['operation_mode'];
$isLive = $mode === OtpSmsService::MODE_LIVE;
$isTest = $mode === OtpSmsService::MODE_TEST;

admin_layout_start(['pageTitle' => 'SMS settings', 'activeAdminNav' => 'settings']);
$pageHeading = 'SMS settings';
$pageDescription = 'OTP login and delivery SMS. Operation mode is enforced server-side by app environment.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$settingsTab = 'sms';
require APNI_TOKRI_ROOT . '/includes/partials/admin/settings-nav.php';
?>

<?php if ($isTest): ?>
    <div class="at-alert at-alert--warning max-w-3xl mb-4" role="status"><strong>TEST MODE — No real SMS is sent.</strong> OTP login uses hashed codes in the database only. Configure an optional fixed 6-digit test OTP below for QA.</div>
<?php elseif ($isLive): ?>
    <div class="at-alert at-alert--success max-w-3xl mb-4" role="status"><strong>Live Mode.</strong> OTP and test SMS use your HTTP provider. Messages are sent only after the provider accepts them.</div>
<?php else: ?>
    <div class="at-alert max-w-3xl mb-4" role="status"><strong>Disabled.</strong> Customer OTP requests are rejected with a service-unavailable message.</div>
<?php endif; ?>

<div class="at-card max-w-3xl mb-6">
    <div class="at-card__body text-sm grid sm:grid-cols-2 gap-3">
        <p><span class="at-muted">App environment</span><br><strong><?= Security::escape($form['environment']) ?></strong><?= $form['is_production'] ? ' (production)' : '' ?></p>
        <p><span class="at-muted">Effective operation mode</span><br><strong><?= Security::escape($mode) ?></strong></p>
        <p><span class="at-muted">SMS enabled flag</span><br><strong><?= $form['enabled'] ? 'Yes' : 'No' ?></strong></p>
        <p><span class="at-muted">Provider</span><br><strong><?= Security::escape($form['provider']) ?></strong></p>
        <p><span class="at-muted">API credentials</span><br><strong><?= $form['has_api_key'] ? 'Configured' : 'Not configured' ?></strong></p>
        <p><span class="at-muted">Sender ID</span><br><strong><?= $form['sender_id'] !== '' ? Security::escape($form['sender_id']) : 'Not set' ?></strong></p>
        <p><span class="at-muted">OTP DLT template</span><br><strong><?= ($form['dlt']['otp'] ?? '') !== '' ? 'Set' : 'Optional / not set' ?></strong></p>
        <p><span class="at-muted">Last SMS attempt</span><br><strong><?= Security::escape($diag['last_attempt_at'] !== '' ? $diag['last_attempt_at'] : '—') ?></strong></p>
        <p><span class="at-muted">Last success</span><br><strong><?= Security::escape($diag['last_success_at'] !== '' ? $diag['last_success_at'] : '—') ?></strong></p>
        <p class="sm:col-span-2"><span class="at-muted">Last provider error</span><br><strong><?= Security::escape($diag['last_error_code'] !== '' ? $diag['last_error_code'] : '—') ?></strong>
            <?php if (($diag['last_error_message'] ?? '') !== ''): ?><span class="at-muted"> — <?= Security::escape($diag['last_error_message']) ?></span><?php endif; ?></p>
    </div>
</div>

<form method="post" class="at-card max-w-3xl mb-6">
    <div class="at-card__body space-y-4">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save">

        <div>
            <label class="at-label" for="operation_mode">SMS operation mode</label>
            <select id="operation_mode" name="operation_mode" class="at-input at-focusable">
                <option value="<?= Security::escape(OtpSmsService::MODE_DISABLED) ?>" <?= $form['configured_operation_mode'] === OtpSmsService::MODE_DISABLED ? 'selected' : '' ?>>Disabled — block OTP SMS</option>
                <option value="<?= Security::escape(OtpSmsService::MODE_TEST) ?>" <?= $form['configured_operation_mode'] === OtpSmsService::MODE_TEST ? 'selected' : '' ?> <?= !$form['allows_test_mode'] ? 'disabled' : '' ?>>Test Mode — no external SMS (local/staging only)</option>
                <option value="<?= Security::escape(OtpSmsService::MODE_LIVE) ?>" <?= $form['configured_operation_mode'] === OtpSmsService::MODE_LIVE ? 'selected' : '' ?>>Live Mode — real provider</option>
            </select>
            <?php if (!$form['allows_test_mode']): ?><p class="text-xs at-muted mt-1">Test Mode is hidden in production environments.</p><?php endif; ?>
            <?php if (isset($errors['operation_mode'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['operation_mode']) ?></p><?php endif; ?>
        </div>

        <div id="test-mode-fields" class="<?= $form['configured_operation_mode'] === OtpSmsService::MODE_TEST ? '' : 'hidden' ?> grid gap-2">
            <label class="at-label" for="test_fixed_otp">Fixed test OTP <span class="at-muted font-normal">(optional, 6 digits)</span></label>
            <input id="test_fixed_otp" name="test_fixed_otp" class="at-input at-focusable" inputmode="numeric" maxlength="6" autocomplete="off" placeholder="Leave blank for random OTP" value="<?= Security::escape($form['test_fixed_otp']) ?>">
            <?php if (isset($errors['test_fixed_otp'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['test_fixed_otp']) ?></p><?php endif; ?>
            <p class="text-xs at-muted">Never shown on the customer login page. Share only through your internal QA process.</p>
        </div>

        <div id="live-mode-fields" class="<?= $form['configured_operation_mode'] === OtpSmsService::MODE_LIVE ? '' : 'hidden' ?>">
            <p class="text-sm at-muted mb-3">Live mode uses the HTTP provider below. Simulated provider is not used for OTP in Live Mode.</p>
            <div class="space-y-3">
                <div>
                    <label class="at-label" for="sms_api_url">HTTP API URL (HTTPS only)</label>
                    <input id="sms_api_url" name="api_url" class="at-input at-focusable" autocomplete="off" value="<?= Security::escape($form['api_url']) ?>">
                    <?php if (isset($errors['api_url'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['api_url']) ?></p><?php endif; ?>
                </div>
                <div>
                    <label class="at-label" for="sms_api_key">API key / bearer token</label>
                    <input id="sms_api_key" name="api_key" type="password" class="at-input at-focusable" autocomplete="new-password"
                           placeholder="<?= $form['has_api_key'] ? 'Saved (enter to replace)' : 'Enter API key' ?>">
                    <?php if (isset($errors['api_key'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['api_key']) ?></p><?php endif; ?>
                </div>
                <div>
                    <label class="at-label" for="sms_sender">Sender ID</label>
                    <input id="sms_sender" name="sender_id" class="at-input at-focusable" maxlength="11" value="<?= Security::escape($form['sender_id']) ?>">
                    <?php if (isset($errors['sender_id'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['sender_id']) ?></p><?php endif; ?>
                </div>
            </div>
        </div>

        <input type="hidden" name="enabled" value="<?= $isLive ? '1' : '0' ?>">
        <input type="hidden" name="provider" value="<?= Security::escape($isLive ? SmsSettingsService::PROVIDER_HTTP : SmsSettingsService::PROVIDER_SIMULATE) ?>">

        <hr>
        <h3 class="font-bold text-sm">HTTP provider response contract</h3>
        <div class="grid md:grid-cols-2 gap-3">
            <div>
                <label class="at-label">Allowed HTTP status codes</label>
                <input name="http_allowed_status_codes" class="at-input at-focusable" value="<?= Security::escape($form['http_allowed_status_codes']) ?>">
            </div>
            <div>
                <label class="at-label">Timeout (seconds)</label>
                <input name="http_timeout_seconds" type="number" min="3" max="60" class="at-input at-focusable" value="<?= Security::escape((string) $form['http_timeout_seconds']) ?>">
            </div>
            <div>
                <label class="at-label">Success JSON path</label>
                <input name="http_success_json_path" class="at-input at-focusable" value="<?= Security::escape($form['http_success_json_path']) ?>">
            </div>
            <div>
                <label class="at-label">Success JSON value</label>
                <input name="http_success_json_value" class="at-input at-focusable" value="<?= Security::escape($form['http_success_json_value']) ?>">
            </div>
            <div class="md:col-span-2">
                <label class="at-label">Message ID JSON path</label>
                <input name="http_message_id_json_path" class="at-input at-focusable" value="<?= Security::escape($form['http_message_id_json_path']) ?>">
                <?php if (isset($errors['http_message_id_json_path'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['http_message_id_json_path']) ?></p><?php endif; ?>
            </div>
            <label class="flex gap-2 text-sm md:col-span-2"><input type="checkbox" name="http_require_json" value="1" <?= $form['http_require_json'] ? 'checked' : '' ?>> Require JSON response body</label>
        </div>

        <hr>
        <h3 class="font-bold text-sm">Message templates</h3>
        <p class="text-xs at-muted">Delivery templates use <code>{order_number}</code>. OTP template uses <code>{otp}</code> and <code>{minutes}</code>.</p>
        <?php foreach (array_merge(SmsSettingsService::deliveryTemplateKeys(), ['otp']) as $tKey): ?>
            <div>
                <label class="at-label" for="tpl_<?= Security::escape($tKey) ?>"><?= Security::escape(str_replace('_', ' ', $tKey)) ?></label>
                <textarea id="tpl_<?= Security::escape($tKey) ?>" name="template_<?= Security::escape($tKey) ?>" class="at-input at-focusable" rows="2"><?= Security::escape($form['templates'][$tKey] ?? '') ?></textarea>
                <input name="dlt_<?= Security::escape($tKey) ?>" class="at-input at-focusable mt-1" placeholder="DLT template ID (optional)" value="<?= Security::escape($form['dlt'][$tKey] ?? '') ?>">
            </div>
        <?php endforeach; ?>

        <button type="submit" class="at-btn at-btn--primary at-focusable">Save SMS settings</button>
    </div>
</form>

<form method="post" class="at-card max-w-3xl">
    <div class="at-card__body space-y-4">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="send_test">
        <h3 class="font-bold">Send test SMS</h3>
        <p class="text-sm at-muted">Available only in <strong>Live Mode</strong>. Verifies real provider delivery using saved credentials.</p>
        <div>
            <label class="at-label" for="test_mobile">Mobile number</label>
            <input id="test_mobile" name="test_mobile" type="tel" class="at-input at-focusable" required autocomplete="off" <?= !$isLive ? 'disabled' : '' ?>>
            <?php if (isset($testErrors['test_mobile'])): ?><p class="text-xs text-red-600"><?= Security::escape($testErrors['test_mobile']) ?></p><?php endif; ?>
        </div>
        <button type="submit" class="at-btn at-btn--secondary at-focusable" <?= !$isLive ? 'disabled' : '' ?>>Send test SMS</button>
    </div>
</form>
<script>
(function () {
  var sel = document.getElementById('operation_mode');
  var testFields = document.getElementById('test-mode-fields');
  var liveFields = document.getElementById('live-mode-fields');
  if (!sel) return;
  function sync() {
    var v = sel.value;
    if (testFields) testFields.classList.toggle('hidden', v !== 'test');
    if (liveFields) liveFields.classList.toggle('hidden', v !== 'live');
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>
<?php admin_layout_end(); ?>
