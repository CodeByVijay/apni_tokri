<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('firebase');
$base = url_base() . '/admin/firebase';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
  $result = ['ok' => false, 'message' => 'Unknown action.'];
    if ($action === 'activate' && $id > 0) {
        $result = FirebaseAdminService::activate($id);
    } elseif ($action === 'deactivate' && $id > 0) {
        $result = FirebaseAdminService::deactivate($id);
    } elseif ($action === 'delete' && $id > 0) {
        $result = FirebaseAdminService::delete($id);
    } elseif ($action === 'test' && $id > 0) {
        $result = FirebaseAdminService::testExisting($id);
    } elseif ($action === 'rollback_previous') {
        $result = FirebaseAdminService::rollbackToPrevious();
    }
    admin_flash_set(
        (string) ($result['message'] ?? 'Done.'),
        !empty($result['ok']) ? 'success' : 'error'
    );
    header('Location: ' . $base . '/index.php', true, 302);
    exit;
}

$projects = FirebaseProjectManager::listAll();
$active = FirebaseProjectManager::activeProject(false);

admin_layout_start(['pageTitle' => 'Firebase projects', 'activeAdminNav' => 'firebase']);
$pageHeading = 'Firebase projects';
$pageDescription = 'Manage multiple Firebase projects; one active project globally for Web + Android.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>

<div class="at-alert at-alert--info mb-4 max-w-3xl text-sm">
    <p class="mb-2"><strong>Activate</strong> runs a credential test, then switches the global project (Web + Android). Customers may need to allow notifications again or re-register devices.</p>
    <p class="mb-2"><strong>Test</strong> checks OAuth using the stored service account without changing which project is active.</p>
    <p class="mb-0"><strong>Rollback</strong> (when shown) re-activates the project that was active immediately before the last successful switch.</p>
</div>

<div class="at-admin-actions mb-4">
    <a class="at-btn at-btn--primary at-focusable" href="<?= Security::escape($base) ?>/edit.php">Add Firebase project</a>
    <a class="at-btn at-btn--secondary at-focusable" href="<?= Security::escape(url_base()) ?>/admin/push/templates.php">Push templates</a>
    <a class="at-btn at-btn--secondary at-focusable" href="<?= Security::escape(url_base()) ?>/admin/push/logs.php">Push logs</a>
    <a class="at-btn at-btn--secondary at-focusable" href="<?= Security::escape(url_base()) ?>/admin/push/devices.php">Device registrations</a>
    <a class="at-btn at-btn--secondary at-focusable" href="<?= Security::escape(url_base()) ?>/admin/push/send.php">Send notification</a>
    <a class="at-btn at-btn--secondary at-focusable" href="<?= Security::escape(url_base()) ?>/admin/push/settings.php">Push settings</a>
</div>

<?php
$rollbackId = (int) (AppSettings::get(PushSettings::KEY_ROLLBACK_PROJECT_ID, '0') ?? '0');
if ($rollbackId > 0):
    $rb = FirebaseProjectManager::findById($rollbackId);
?>
<form method="post" class="mb-4" onsubmit="return confirm('Rollback to the previously active Firebase project?');">
    <?= Csrf::field() ?>
    <button type="submit" name="action" value="rollback_previous" class="at-btn at-btn--secondary at-focusable">
        Rollback to <?= $rb ? Security::escape((string) $rb['name']) : 'previous project' ?>
    </button>
</form>
<?php endif; ?>

<?php if (!PushSchema::ready()): ?>
<div class="at-alert at-alert--warning mb-4">Migration <code>1.39.0_firebase_push_notifications.sql</code> is not applied.</div>
<?php endif; ?>

<?php if ($active !== null): ?>
<div class="at-alert at-alert--success mb-4">
    Active project: <strong><?= Security::escape((string) $active['name']) ?></strong>
    (<?= Security::escape((string) $active['firebase_project_id']) ?>)
</div>
<?php else: ?>
<div class="at-alert at-alert--warning mb-4">No active Firebase project. Push registration and sending are disabled.</div>
<?php endif; ?>

<div class="at-card">
    <div class="at-card__body overflow-x-auto">
        <table class="at-data-table w-full text-sm">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Project ID</th>
                    <th>Status</th>
                    <th>Web</th>
                    <th>Android</th>
                    <th>Last test</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($projects as $p): ?>
                <tr>
                    <td><?= Security::escape((string) $p['name']) ?><?php if (!empty($p['is_active_global'])): ?> <span class="at-badge">Active</span><?php endif; ?></td>
                    <td><code><?= Security::escape((string) $p['firebase_project_id']) ?></code></td>
                    <td><?= Security::escape((string) $p['status']) ?></td>
                    <td><?= trim((string) ($p['web_app_id'] ?? '')) !== '' ? 'Configured' : '—' ?></td>
                    <td><?= trim((string) ($p['android_app_id'] ?? '')) !== '' ? 'Configured' : '—' ?></td>
                    <td><?= Security::escape((string) ($p['last_test_status'] ?? 'unknown')) ?></td>
                    <td class="whitespace-nowrap">
                        <a class="at-link" href="<?= Security::escape($base) ?>/edit.php?id=<?= (int) $p['id'] ?>">Edit</a>
                        <form method="post" class="inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <button type="submit" name="action" value="test" class="at-link">Test</button>
                        </form>
                        <?php if (empty($p['is_active_global'])): ?>
                        <form method="post" class="inline" onsubmit="return confirm('Activate this Firebase project? Devices may need to re-register.');">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <button type="submit" name="action" value="activate" class="at-link">Activate</button>
                        </form>
                        <?php else: ?>
                        <form method="post" class="inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <button type="submit" name="action" value="deactivate" class="at-link">Deactivate</button>
                        </form>
                        <?php endif; ?>
                        <?php if (empty($p['is_active_global'])): ?>
                        <form method="post" class="inline" onsubmit="return confirm('Delete this project permanently?');">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <button type="submit" name="action" value="delete" class="at-link text-red-700">Delete</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($projects === []): ?>
                <tr><td colspan="7" class="at-muted">No Firebase projects yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php admin_layout_end(); ?>
