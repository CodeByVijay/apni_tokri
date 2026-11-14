<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_init.php';

AdminDemoPerms::denyIfCannot('notifications');

$templateKey = isset($_GET['key']) ? trim((string) $_GET['key']) : '';
$template = $templateKey !== '' ? NotificationTemplateAdminService::getEmailTemplate($templateKey) : null;

if ($template === null || (string) ($template['channel'] ?? '') !== 'email') {
    admin_layout_start(['pageTitle' => 'Email template', 'activeAdminNav' => 'settings']);
    echo '<div class="at-alert at-alert--error">Email template not found.</div>';
    echo '<p class="mt-3"><a class="underline" href="' . Security::escape(url_base()) . '/admin/settings/email-templates/index.php">← Email templates</a></p>';
    admin_layout_end();
    exit;
}

$eventType = (string) ($template['event_type'] ?? '');
$variableNames = NotificationTemplateVariables::allowedForEventType($eventType);
$defaultRecipient = NotificationTemplateAdminService::adminTestRecipientDefault();

$errors = [];
$warnings = [];
$form = [
    'subject' => (string) ($template['subject'] ?? ''),
    'body_html' => (string) ($template['body_html'] ?? ''),
    'is_active' => !empty($template['is_active']),
    'test_recipient' => $defaultRecipient,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : 'save';

    if ($action === 'preview') {
        header('Content-Type: application/json; charset=utf-8');
        $preview = NotificationTemplateAdminService::preview(
            $templateKey,
            trim((string) ($_POST['subject'] ?? '')),
            (string) ($_POST['body_html'] ?? '')
        );
        echo json_encode($preview, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'send_test') {
        $recipient = trim((string) ($_POST['test_recipient'] ?? ''));
        if ($recipient === '' && $defaultRecipient !== '') {
            $recipient = $defaultRecipient;
        }
        $result = NotificationTemplateAdminService::sendTestEmail(
            $templateKey,
            $recipient,
            trim((string) ($_POST['subject'] ?? '')),
            (string) ($_POST['body_html'] ?? '')
        );
        admin_flash_set((string) ($result['message'] ?? 'Done.'), !empty($result['ok']) ? 'success' : 'error');
        header('Location: ' . url_base() . '/admin/settings/email-templates/edit.php?key=' . rawurlencode($templateKey), true, 302);
        exit;
    }

    if ($action === 'restore_default') {
        if (empty($_POST['confirm_restore'])) {
            admin_flash_set('Confirm restore to continue.', 'error');
        } else {
            $restore = NotificationTemplateAdminService::restoreDefault($templateKey);
            admin_flash_set((string) ($restore['message'] ?? 'Done.'), !empty($restore['ok']) ? 'success' : 'error');
        }
        header('Location: ' . url_base() . '/admin/settings/email-templates/edit.php?key=' . rawurlencode($templateKey), true, 302);
        exit;
    }

    $form['subject'] = trim((string) ($_POST['subject'] ?? ''));
    $form['body_html'] = (string) ($_POST['body_html'] ?? '');
    $form['is_active'] = !empty($_POST['is_active']);
    $form['test_recipient'] = trim((string) ($_POST['test_recipient'] ?? $defaultRecipient));

    $save = NotificationTemplateAdminService::saveEmailTemplate(
        $templateKey,
        $form['subject'],
        $form['body_html'],
        $form['is_active']
    );
    if (!empty($save['ok'])) {
        $msg = (string) ($save['message'] ?? 'Saved.');
        if (!empty($save['warnings'])) {
            $msg .= ' Unknown variables: ' . implode(', ', $save['warnings']);
        }
        admin_flash_set($msg, 'success');
        header('Location: ' . url_base() . '/admin/settings/email-templates/edit.php?key=' . rawurlencode($templateKey), true, 302);
        exit;
    }
    if (!empty($save['errors'])) {
        $errors = $save['errors'];
    }
    admin_flash_set((string) ($save['message'] ?? 'Could not save.'), 'error');
}

admin_layout_start(['pageTitle' => 'Edit email template', 'activeAdminNav' => 'settings']);
$pageHeading = 'Edit email template';
$pageDescription = (string) ($template['template_key'] ?? '');
$breadcrumbs = [
    ['label' => 'Settings', 'href' => url_base() . '/admin/settings/index.php'],
    ['label' => 'Email templates', 'href' => url_base() . '/admin/settings/email-templates/index.php'],
    ['label' => $templateKey],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$settingsTab = 'email_templates';
require APNI_TOKRI_ROOT . '/includes/partials/admin/settings-nav.php';
?>
<p class="mb-4 text-sm"><a class="underline at-focusable" href="<?= Security::escape(url_base()) ?>/admin/settings/email-templates/index.php">← Email templates</a></p>

<div class="grid lg:grid-cols-3 gap-4">
    <div class="lg:col-span-2 space-y-4">
        <form method="post" id="email-tpl-form" class="at-card" action="<?= Security::escape(url_base()) ?>/admin/settings/email-templates/edit.php?key=<?= rawurlencode($templateKey) ?>">
            <div class="at-card__body space-y-4">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="save">
                <p class="text-sm"><strong>Event:</strong> <?= Security::escape(NotificationTemplateVariables::eventTypeLabel($eventType)) ?></p>

                <?php
                $subject = $form['subject'];
                $bodyHtml = $form['body_html'];
                require APNI_TOKRI_ROOT . '/includes/partials/admin/email-template-editor.php';
                ?>

                <?php if (!empty($errors['subject'])): ?>
                    <p class="text-sm text-red-600"><?= Security::escape((string) $errors['subject']) ?></p>
                <?php endif; ?>
                <?php if (!empty($errors['body_html'])): ?>
                    <p class="text-sm text-red-600"><?= Security::escape((string) $errors['body_html']) ?></p>
                <?php endif; ?>

                <label class="at-label flex items-center gap-2">
                    <input type="checkbox" name="is_active" value="1" class="at-checkbox" <?= $form['is_active'] ? 'checked' : '' ?>>
                    Template active (inactive templates are not sent)
                </label>

                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="at-btn at-btn--primary at-focusable">Save template</button>
                    <button type="button" id="email-tpl-preview-btn" class="at-btn at-btn--secondary at-focusable">Preview</button>
                </div>
            </div>
        </form>

        <form method="post" class="at-card" onsubmit="return confirm('Restore factory default subject and body? Your changes will be lost.');">
            <div class="at-card__body space-y-3">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="restore_default">
                <h3 class="font-bold text-sm">Restore default</h3>
                <p class="text-sm at-muted">Replace subject and HTML body with the factory default for this template.</p>
                <label class="at-label flex items-center gap-2">
                    <input type="checkbox" name="confirm_restore" value="1" class="at-checkbox" required>
                    I understand this will overwrite my edits
                </label>
                <button type="submit" class="at-btn at-btn--secondary at-focusable">Restore default template</button>
            </div>
        </form>
    </div>

    <div class="space-y-4 self-start">
        <div class="at-card">
            <div class="at-card__body space-y-2 text-sm">
                <h3 class="font-bold">Available variables</h3>
                <p class="at-muted text-xs">Click to insert into subject or body. Unknown variables render empty and are ignored at send time.</p>
                <ul class="list-disc pl-5 space-y-1">
                    <?php foreach ($variableNames as $var): ?>
                        <li><code>{{<?= Security::escape($var) ?>}}</code></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div class="at-card">
            <div class="at-card__body">
                <h3 class="font-bold text-sm mb-2">Live preview</h3>
                <p class="text-xs at-muted mb-2">Sample customer/order data — not a real notification.</p>
                <iframe id="email-tpl-preview-frame" title="Email preview" class="w-full border rounded-lg bg-white min-h-[12rem]" sandbox=""></iframe>
            </div>
        </div>

        <form method="post" class="at-card">
            <div class="at-card__body space-y-3">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="send_test">
                <input type="hidden" name="subject" id="test-subject-sync" value="">
                <input type="hidden" name="body_html" id="test-body-sync" value="">
                <h3 class="font-bold text-sm">Send test email</h3>
                <p class="text-xs at-muted">Uses SMTP settings. Subject is prefixed with [Veggie Tokri test].</p>
                <label class="at-label" for="test_recipient">Recipient</label>
                <input type="email" id="test_recipient" name="test_recipient" class="at-input at-focusable w-full" required
                       value="<?= Security::escape($form['test_recipient']) ?>" placeholder="you@example.com">
                <button type="submit" class="at-btn at-btn--secondary at-btn--block at-focusable" onclick="return emailTemplateSyncTestFields();">Send test email</button>
            </div>
        </form>
    </div>
</div>

<script <?= Security::cspNonceAttr() ?>>
(function () {
    var previewBtn = document.getElementById('email-tpl-preview-btn');
    var frame = document.getElementById('email-tpl-preview-frame');
    var form = document.getElementById('email-tpl-form');
    if (!previewBtn || !frame || !form) return;

    function runPreview() {
        if (window.emailTemplateEditorSync) window.emailTemplateEditorSync();
        var fd = new FormData(form);
        fd.set('action', 'preview');
        fetch(form.getAttribute('action') || window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    frame.srcdoc = '<p style="font-family:sans-serif;padding:1rem;color:#b91c1c;">' + (data && data.message ? data.message : 'Preview failed') + '</p>';
                    return;
                }
                frame.srcdoc = data.html || '<p style="padding:1rem;">Empty preview</p>';
            })
            .catch(function () {
                frame.srcdoc = '<p style="padding:1rem;color:#b91c1c;">Preview request failed.</p>';
            });
    }

    previewBtn.addEventListener('click', runPreview);
    runPreview();
})();

function emailTemplateSyncTestFields() {
    if (window.emailTemplateEditorSync) window.emailTemplateEditorSync();
    var sub = document.getElementById('email-tpl-subject');
    var body = document.getElementById('email-ed-html');
    document.getElementById('test-subject-sync').value = sub ? sub.value : '';
    document.getElementById('test-body-sync').value = body ? body.value : '';
    return true;
}
</script>
<?php admin_layout_end(); ?>
