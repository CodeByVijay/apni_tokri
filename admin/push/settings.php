<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('firebase');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $save = PushSettings::saveFromRequest($_POST);
    admin_flash_set(
        (string) ($save['message'] ?? ($save['ok'] ? 'Saved.' : 'Could not save.')),
        !empty($save['ok']) ? 'success' : 'error'
    );
    header('Location: ' . url_base() . '/admin/push/settings.php', true, 302);
    exit;
}

$form = PushSettings::adminFormValues();
$rollbackName = '';
if ($form['rollback_project_id'] > 0) {
    $rb = FirebaseProjectManager::findById((int) $form['rollback_project_id']);
    $rollbackName = $rb ? (string) $rb['name'] : '';
}

admin_layout_start(['pageTitle' => 'Push settings', 'activeAdminNav' => 'firebase']);
$pageHeading = 'Push notification settings';
$pageDescription = 'Operational flags only — Firebase credentials are managed under Firebase projects.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>

<form method="post" class="at-card max-w-2xl">
    <div class="at-card__body space-y-4">
        <?= Csrf::field() ?>
        <p class="text-sm"><span class="at-muted">Environment</span><br><strong><?= Security::escape($form['environment']) ?></strong></p>

        <div>
            <label class="flex items-center gap-2">
                <input type="checkbox" name="push_enabled" value="1" <?= $form['enabled'] === '1' ? 'checked' : '' ?>>
                <span>Enable push sending</span>
            </label>
            <p class="text-xs at-muted mt-1 ml-6">
                When off, automated and manual pushes are skipped (no FCM calls). On production, default is off until you enable it here.
                Example: enable after migration <code>1.39.0</code> and a successful credential test on the active Firebase project.
            </p>
        </div>

        <div>
            <label class="flex items-center gap-2">
                <input type="checkbox" name="push_dry_run" value="1" <?= $form['dry_run'] === '1' ? 'checked' : '' ?>>
                <span>Dry-run mode</span>
            </label>
            <p class="text-xs at-muted mt-1 ml-6">
                <strong>Dry-run</strong> records push attempts in <code>push_notification_logs</code> with status <code>dry_run</code> but does <strong>not</strong> call the FCM HTTP API —
                customers receive nothing. Use while testing templates, idempotency, and admin flows on staging.
                Turn off dry-run only when you intend to deliver real notifications (production go-live).
            </p>
        </div>

        <p class="text-xs at-muted">Manual sends: up to <?= (int) PushSettings::MANUAL_SEND_DAILY_MAX ?> per admin per day. Remaining today: <?= (int) PushSettings::manualSendRemainingToday() ?>.</p>
        <?php if ($form['rollback_project_id'] > 0 && $rollbackName !== ''): ?>
            <p class="text-sm at-muted">Rollback target after last project switch: <strong><?= Security::escape($rollbackName) ?></strong> (ID <?= (int) $form['rollback_project_id'] ?>). Use the rollback control on the Firebase projects list.</p>
        <?php endif; ?>
        <button type="submit" class="at-btn at-btn--primary">Save</button>
    </div>
</form>

<?php admin_layout_end(); ?>
