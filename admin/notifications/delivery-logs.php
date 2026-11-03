<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('notifications');

$statusFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
$channelFilter = isset($_GET['channel']) ? trim((string) $_GET['channel']) : '';
$eventFilter = isset($_GET['event']) ? trim((string) $_GET['event']) : '';
$page = max(1, (int) ($_GET['page'] ?? 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Install::isSchemaReady()) {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');
    $jobId = (int) ($_POST['job_id'] ?? 0);
    $flashOk = false;
    $flashMsg = 'Invalid action.';
    if ($jobId > 0 && NotificationQueueService::tableReady()) {
        if ($action === 'retry') {
            $flashOk = NotificationQueueService::adminRetry($jobId);
            $flashMsg = $flashOk ? 'Notification queued for retry.' : 'Could not retry (only failed jobs are eligible).';
            if ($flashOk) {
                AuditLog::write('notification.retry', 'notification_queue', (string) $jobId);
            }
        } elseif ($action === 'cancel') {
            $flashOk = NotificationQueueService::adminCancel($jobId);
            $flashMsg = $flashOk ? 'Pending notification cancelled.' : 'Could not cancel (only pending jobs can be cancelled).';
            if ($flashOk) {
                AuditLog::write('notification.cancel', 'notification_queue', (string) $jobId);
            }
        }
    }
    admin_flash_set($flashMsg, $flashOk ? 'success' : 'error');
    $qs = http_build_query(array_filter([
        'status' => $statusFilter !== '' ? $statusFilter : null,
        'channel' => $channelFilter !== '' ? $channelFilter : null,
        'event' => $eventFilter !== '' ? $eventFilter : null,
        'page' => $page > 1 ? (string) $page : null,
    ]));
    header('Location: ' . url_base() . '/admin/notifications/delivery-logs.php' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

admin_layout_start(['pageTitle' => 'Notification delivery logs', 'activeAdminNav' => 'notifications']);

$pageHeading = 'Notification delivery logs';
$pageDescription = 'Email, SMS, and customer in-app delivery queue. Recipients such as customer:1 are storefront customers — not admin bell alerts.';
$breadcrumbs = [
    ['label' => 'Notifications', 'href' => url_base() . '/admin/notifications/index.php'],
    ['label' => 'Delivery logs'],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if (!Install::isSchemaReady() || !NotificationQueueService::tableReady()) {
    echo '<div class="at-alert at-alert--error">Apply migration <code>1.13.0_stage14_notifications_phase4.sql</code> to enable the notification queue.</div>';
    admin_layout_end();
    exit;
}

$counts = NotificationQueueService::countsByStatus();
?>
<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3 mb-5">
    <?php
    $statuses = ['pending', 'processing', 'sent', 'failed', 'cancelled'];
    foreach ($statuses as $st):
        $total = 0;
        foreach (['email', 'sms', 'in_app'] as $ch) {
            $total += (int) ($counts[$st . ':' . $ch] ?? 0);
        }
        ?>
    <div class="at-card"><div class="at-card__body">
        <p class="text-xs at-muted uppercase"><?= Security::escape($st) ?></p>
        <p class="text-2xl font-semibold"><?= (int) $total ?></p>
    </div></div>
    <?php endforeach; ?>
</div>

<form method="get" class="at-admin-toolbar">
    <div class="contents">
        <label class="text-sm">Status
            <select name="status" class="at-select at-focusable">
                <option value="">All</option>
                <?php foreach ($statuses as $st): ?>
                    <option value="<?= Security::escape($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= Security::escape($st) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="text-sm">Channel
            <select name="channel" class="at-select at-focusable">
                <option value="">All</option>
                <?php foreach (['email', 'sms', 'in_app'] as $ch): ?>
                    <option value="<?= Security::escape($ch) ?>" <?= $channelFilter === $ch ? 'selected' : '' ?>><?= Security::escape($ch) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="text-sm">Event contains
            <input type="text" name="event" class="at-input at-focusable" value="<?= Security::escape($eventFilter) ?>" placeholder="payment.success">
        </label>
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Filter</button>
    </div>
</form>

<?php
$list = NotificationQueueService::adminList(
    $statusFilter !== '' ? $statusFilter : null,
    $channelFilter !== '' ? $channelFilter : null,
    $eventFilter !== '' ? $eventFilter : null,
    $page,
    50
);
$rows = [];
foreach ($list['rows'] as $job) {
    $actions = '—';
    if (($job['status'] ?? '') === NotificationQueueService::STATUS_FAILED) {
        $actions = 'retry';
    } elseif (($job['status'] ?? '') === NotificationQueueService::STATUS_PENDING) {
        $actions = 'cancel';
    }
    $rows[] = [
        'id' => (string) ($job['id'] ?? ''),
        'event' => (string) ($job['event_type'] ?? ''),
        'channel' => (string) ($job['channel'] ?? ''),
        'recipient' => Security::escape((string) ($job['recipient'] ?? '')),
        'status' => (string) ($job['status'] ?? ''),
        'attempts' => (string) (($job['attempts'] ?? 0) . '/' . ($job['max_attempts'] ?? 0)),
        'error' => Security::escape((string) ($job['error_code'] ?? '')),
        'created' => (string) ($job['created_at'] ?? ''),
        'actions' => $actions,
        '_job_id' => (int) ($job['id'] ?? 0),
        '_action' => $actions,
    ];
}
?>
<div class="at-admin-table-wrap">
    <table class="at-admin-table at-admin-table--dense">
        <thead>
            <tr>
                <th>ID</th><th>Event</th><th>Channel</th><th>Recipient</th><th>Status</th><th class="is-num">Attempts</th><th>Error</th><th>Created</th><th class="is-actions"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="is-id"><?= Security::escape($r['id']) ?></td>
                <td><?= Security::escape($r['event']) ?></td>
                <td><?= Security::escape($r['channel']) ?></td>
                <td><?= $r['recipient'] ?></td>
                <td><?= admin_status_badge($r['status']) ?></td>
                <td class="is-num"><?= Security::escape($r['attempts']) ?></td>
                <td><?= $r['error'] ?></td>
                <td class="is-nowrap"><?= Security::escape($r['created']) ?></td>
                <td class="is-actions">
                    <?php if ($r['_action'] === 'retry'): ?>
                        <form method="post" class="inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="retry">
                            <input type="hidden" name="job_id" value="<?= (int) $r['_job_id'] ?>">
                            <button type="submit" class="at-btn at-btn--sm at-btn--secondary at-focusable">Retry</button>
                        </form>
                    <?php elseif ($r['_action'] === 'cancel'): ?>
                        <form method="post" class="inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="job_id" value="<?= (int) $r['_job_id'] ?>">
                            <button type="submit" class="at-btn at-btn--sm at-btn--danger at-focusable">Cancel</button>
                        </form>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?>
            <tr><td colspan="9" class="at-muted p-4">No delivery jobs match this filter.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<p class="at-admin-pager">Showing page <?= (int) $page ?> · <?= (int) $list['total'] ?> total (payloads and secrets are never shown here).</p>
<?php
admin_layout_end();
