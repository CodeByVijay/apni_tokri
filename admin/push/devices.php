<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/firebase-ui.php';

AdminDemoPerms::denyIfCannot('firebase');

$listSelf = url_base() . '/admin/push/devices.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $id = (int) ($_POST['id'] ?? 0);
    if (isset($_POST['action']) && $_POST['action'] === 'revoke' && $id > 0) {
        $result = PushTokenService::adminRevokeById($id);
        admin_flash_set((string) ($result['message'] ?? 'Done.'), !empty($result['ok']) ? 'success' : 'error');
    }
    header('Location: ' . $listSelf . PushAdminList::listRedirectQuery($_GET), true, 302);
    exit;
}

$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$filterPlatform = isset($_GET['platform']) ? (string) $_GET['platform'] : '';
$filterStatus = isset($_GET['status']) ? (string) $_GET['status'] : '';
$queryBase = array_filter([
    'q' => $listQ !== '' ? $listQ : null,
    'platform' => $filterPlatform !== '' ? $filterPlatform : null,
    'status' => $filterStatus !== '' ? $filterStatus : null,
]);
$list = PushAdminList::devices($listQ, $filterPlatform, $filterStatus, $listPage, $listPerPage);

admin_layout_start(['pageTitle' => 'Push devices', 'activeAdminNav' => 'firebase']);
$pageHeading = 'Device push registrations';
$pageDescription = 'Registered FIDs (Web) and FCM tokens (Android) tied to the Firebase project active at registration time.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
firebase_admin_subnav('devices');
?>

<?php if (!PushSchema::tableReady('device_push_registrations')): ?>
<div class="at-alert at-alert--warning mb-4">Push device table is not available. Apply migration 1.39.0.</div>
<?php else: ?>

<?php
firebase_admin_list_toolbar($listSelf, $listQ, $queryBase, $listPerPage, [
    [
        'name' => 'platform',
        'label' => 'Platform',
        'value' => $filterPlatform,
        'options' => ['' => 'All platforms', 'web' => 'Web', 'android' => 'Android'],
    ],
    [
        'name' => 'status',
        'label' => 'Status',
        'value' => $filterStatus,
        'options' => ['' => 'All statuses', 'active' => 'Active', 'revoked' => 'Revoked', 'invalid' => 'Invalid'],
    ],
]);
$tableRows = [];
foreach ($list['rows'] as $r) {
    $regId = (int) $r['id'];
    $projectLabel = Security::escape((string) $r['project_name']);
    if (empty($r['is_active_global'])) {
        $projectLabel .= ' <span class="at-muted text-xs">(inactive project)</span>';
    }
    $actions = '—';
    if ((string) $r['status'] === 'active') {
        $actions = '<form method="post" class="inline" onsubmit="return confirm(\'Revoke this registration?\');">'
            . Csrf::field() . '<input type="hidden" name="id" value="' . $regId . '">'
            . '<button type="submit" name="action" value="revoke" class="at-btn at-btn--sm at-btn--ghost at-focusable">Revoke</button></form>';
    }
    $tableRows[] = [
        'id' => (string) $regId,
        'project' => $projectLabel,
        'platform' => firebase_platform_badge((string) $r['platform']),
        'target' => firebase_target_badge((string) ($r['target_type'] ?? 'fcm_token'), (string) ($r['token_prefix'] ?? '')),
        'customer' => $r['customer_id'] !== null ? (string) (int) $r['customer_id'] : 'Guest',
        'status' => (string) $r['status'],
        'permission' => firebase_permission_badge((string) $r['permission_status']),
        'last_refresh' => trim((string) ($r['last_refresh_at'] ?? '')) !== ''
            ? Security::escape((string) $r['last_refresh_at'])
            : '—',
        'actions' => $actions,
    ];
}
$columns = [
    ['label' => 'ID', 'key' => 'id', 'align' => 'num'],
    ['label' => 'Project', 'key' => 'project', 'raw' => true],
    ['label' => 'Platform', 'key' => 'platform', 'raw' => true],
    ['label' => 'Target', 'key' => 'target', 'raw' => true],
    ['label' => 'Customer', 'key' => 'customer', 'align' => 'num'],
    ['label' => 'Status', 'key' => 'status'],
    ['label' => 'Permission', 'key' => 'permission', 'raw' => true],
    ['label' => 'Last refresh', 'key' => 'last_refresh'],
    ['label' => 'Actions', 'key' => 'actions', 'align' => 'actions', 'raw' => true],
];
if ($list['total'] === 0 && $listQ === '' && $filterPlatform === '' && $filterStatus === ''):
    firebase_admin_empty_state(
        'No device registrations found',
        'Devices will appear here after customers enable push notifications on the storefront or Android app.',
        url_base() . '/admin/firebase/index.php',
        'Configure Firebase project'
    );
else:
    $emptyMessage = $listQ !== '' || $filterPlatform !== '' || $filterStatus !== ''
        ? 'No device registrations match your filters.'
        : 'No device registrations found.';
    require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
    admin_list_pagination(
        (int) $list['page'],
        (int) $list['total'],
        $listPerPage,
        $queryBase,
        $listSelf,
        AdminServerList::PER_PAGE_OPTIONS,
        'registrations',
        $list['total'] > 0
    );
endif;
endif;
admin_layout_end();
