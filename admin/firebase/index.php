<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/firebase-ui.php';

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
    header('Location: ' . $base . '/index.php' . PushAdminList::listRedirectQuery($_GET), true, 302);
    exit;
}

$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$queryBase = array_filter(['q' => $listQ !== '' ? $listQ : null]);
$list = PushAdminList::projects($listQ, $listPage, $listPerPage);
$active = FirebaseProjectManager::activeProject(false);

admin_layout_start(['pageTitle' => 'Firebase projects', 'activeAdminNav' => 'firebase']);
$pageHeading = 'Firebase projects';
$pageDescription = 'Manage multiple Firebase projects; one active project globally for Web + Android.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
firebase_admin_subnav('projects');
?>

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
<?php else: ?>

<?php if ($active !== null): ?>
<div class="at-alert at-alert--success mb-4">
    Active project: <strong><?= Security::escape((string) $active['name']) ?></strong>
    (<code><?= Security::escape((string) $active['firebase_project_id']) ?></code>)
</div>
<?php else: ?>
<div class="at-alert at-alert--warning mb-4">No active Firebase project. Push registration and sending are disabled.</div>
<?php endif; ?>

<div class="at-admin-actions mb-4">
    <a class="at-btn at-btn--primary at-focusable" href="<?= Security::escape($base) ?>/edit.php">Add Firebase project</a>
</div>

<?php
firebase_admin_list_toolbar($base . '/index.php', $listQ, $queryBase, $listPerPage);
$tableRows = [];
foreach ($list['rows'] as $p) {
    $pid = (int) $p['id'];
    $webOk = trim((string) ($p['web_app_id'] ?? '')) !== '';
    $andOk = trim((string) ($p['android_app_id'] ?? '')) !== '';
    $testStatus = (string) ($p['last_test_status'] ?? 'unknown');
    $actions = '<div class="at-admin-actions at-admin-actions--firebase">';
    $actions .= '<a class="at-btn at-btn--sm at-btn--ghost at-focusable" href="' . Security::escape($base . '/edit.php?id=' . $pid) . '">Edit</a>';
    $actions .= '<form method="post" class="inline"><input type="hidden" name="id" value="' . $pid . '">' . Csrf::field()
        . '<button type="submit" name="action" value="test" class="at-btn at-btn--sm at-btn--ghost at-focusable">Test</button></form>';
    if (empty($p['is_active_global'])) {
        $actions .= '<form method="post" class="inline" onsubmit="return confirm(\'Activate this Firebase project? Devices may need to re-register.\');">'
            . '<input type="hidden" name="id" value="' . $pid . '">' . Csrf::field()
            . '<button type="submit" name="action" value="activate" class="at-btn at-btn--sm at-btn--secondary at-focusable">Activate</button></form>';
    } else {
        $actions .= '<form method="post" class="inline"><input type="hidden" name="id" value="' . $pid . '">' . Csrf::field()
            . '<button type="submit" name="action" value="deactivate" class="at-btn at-btn--sm at-btn--ghost at-focusable">Deactivate</button></form>';
    }
    if (empty($p['is_active_global'])) {
        $actions .= '<form method="post" class="inline" onsubmit="return confirm(\'Delete this project permanently?\');">'
            . '<input type="hidden" name="id" value="' . $pid . '">' . Csrf::field()
            . '<button type="submit" name="action" value="delete" class="at-btn at-btn--sm at-btn--ghost at-focusable" style="color:var(--at-danger)">Delete</button></form>';
    }
    $actions .= '</div>';
    $nameCell = Security::escape((string) $p['name']);
    if (!empty($p['is_active_global'])) {
        $nameCell .= ' ' . admin_status_badge('active', 'Global active');
    }
    $tableRows[] = [
        'name' => $nameCell,
        'project_id' => '<code>' . Security::escape((string) $p['firebase_project_id']) . '</code>',
        'status' => (string) $p['status'],
        'web' => $webOk ? admin_status_badge('active', 'Configured') : '—',
        'android' => $andOk ? admin_status_badge('active', 'Configured') : '—',
        'last_test' => admin_status_badge($testStatus, ucfirst($testStatus)),
        'actions' => $actions,
    ];
}
$columns = [
    ['label' => 'Name', 'key' => 'name', 'raw' => true],
    ['label' => 'Project ID', 'key' => 'project_id', 'raw' => true],
    ['label' => 'Status', 'key' => 'status'],
    ['label' => 'Web', 'key' => 'web', 'raw' => true],
    ['label' => 'Android', 'key' => 'android', 'raw' => true],
    ['label' => 'Last test', 'key' => 'last_test', 'raw' => true],
    ['label' => 'Actions', 'key' => 'actions', 'align' => 'actions', 'raw' => true],
];
$emptyMessage = $listQ !== ''
    ? 'No Firebase projects match your search.'
    : 'No Firebase projects yet.';
if ($list['total'] === 0 && $listQ === ''):
    firebase_admin_empty_state(
        'No Firebase projects yet',
        'Add a project with your Firebase web config and service account to enable push registration.',
        $base . '/edit.php',
        'Add Firebase project'
    );
else:
    $emptyMessage = $listQ !== '' ? 'No Firebase projects match your search.' : $emptyMessage;
    require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
    admin_list_pagination(
        (int) $list['page'],
        (int) $list['total'],
        $listPerPage,
        $queryBase,
        $base . '/index.php',
        AdminServerList::PER_PAGE_OPTIONS,
        'projects',
        $list['total'] > 0
    );
endif;
endif;
admin_layout_end();
