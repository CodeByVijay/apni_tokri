<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/firebase-ui.php';

AdminDemoPerms::denyIfCannot('firebase');

$listSelf = url_base() . '/admin/push/logs.php';
$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$filterStatus = isset($_GET['status']) ? (string) $_GET['status'] : '';
$queryBase = array_filter([
    'q' => $listQ !== '' ? $listQ : null,
    'status' => $filterStatus !== '' ? $filterStatus : null,
]);
$list = PushAdminList::logs($listQ, $filterStatus, $listPage, $listPerPage);

admin_layout_start(['pageTitle' => 'Push logs', 'activeAdminNav' => 'firebase']);
$pageHeading = 'Push notification logs';
$pageDescription = 'Delivery outcomes only — titles are redacted; secrets and full device tokens are never logged.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
firebase_admin_subnav('logs');
?>

<?php if (!PushSchema::tableReady('push_notification_logs')): ?>
<div class="at-alert at-alert--warning mb-4">Push logs table is not available.</div>
<?php else: ?>

<?php
firebase_admin_list_toolbar($listSelf, $listQ, $queryBase, $listPerPage, [
    [
        'name' => 'status',
        'label' => 'Status',
        'value' => $filterStatus,
        'options' => [
            '' => 'All statuses',
            'dry_run' => 'Dry run',
            'sent' => 'Sent',
            'failed' => 'Failed',
            'skipped' => 'Skipped',
            'pending' => 'Pending',
        ],
    ],
]);
$tableRows = [];
foreach ($list['rows'] as $r) {
    $statusCell = admin_status_badge((string) $r['status']);
    if (!empty($r['failure_category'])) {
        $statusCell .= ' <span class="at-muted text-xs">(' . Security::escape((string) $r['failure_category']) . ')</span>';
    }
    $idem = (string) $r['idempotency_key'];
  if (strlen($idem) > 36) {
        $idem = substr($idem, 0, 18) . '…' . substr($idem, -8);
    }
    $tableRows[] = [
        'when' => Security::escape((string) $r['created_at']),
        'type' => Security::escape((string) $r['notification_type']),
        'status' => $statusCell,
        'customer' => $r['customer_id'] !== null ? (string) (int) $r['customer_id'] : '—',
        'title' => Security::escape((string) ($r['title_redacted'] ?? '')),
        'idempotency' => '<code class="text-xs" title="' . Security::escape((string) $r['idempotency_key']) . '">' . Security::escape($idem) . '</code>',
    ];
}
$columns = [
    ['label' => 'When', 'key' => 'when'],
    ['label' => 'Type', 'key' => 'type'],
    ['label' => 'Status', 'key' => 'status', 'raw' => true],
    ['label' => 'Customer', 'key' => 'customer', 'align' => 'num'],
    ['label' => 'Title', 'key' => 'title', 'class' => 'max-w-[12rem] truncate'],
    ['label' => 'Idempotency', 'key' => 'idempotency', 'raw' => true],
];
if ($list['total'] === 0 && $listQ === '' && $filterStatus === ''):
    firebase_admin_empty_state(
        'No notification logs yet',
        'Logs are created when push events run (including dry-run mode).',
        url_base() . '/admin/push/settings.php',
        'Push settings'
    );
else:
    $emptyMessage = $listQ !== '' || $filterStatus !== '' ? 'No logs match your filters.' : 'No notification logs yet.';
    require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
    admin_list_pagination(
        (int) $list['page'],
        (int) $list['total'],
        $listPerPage,
        $queryBase,
        $listSelf,
        AdminServerList::PER_PAGE_OPTIONS,
        'log entries',
        $list['total'] > 0
    );
endif;
endif;
admin_layout_end();
