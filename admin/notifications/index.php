<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('support');

$filter = isset($_GET['filter']) ? trim((string) $_GET['filter']) : 'all';
$allowedFilters = ['all', 'unread', 'read', 'ticket_created', 'customer_reply'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}
$page = max(1, (int) ($_GET['page'] ?? 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'mark_all_read' && AdminNotificationService::tableReady()) {
        AdminNotificationService::markAllReadForCurrentAdmin();
        admin_flash_set('All notifications marked as read.', 'success');
    }
    $qs = http_build_query(array_filter([
        'filter' => $filter !== 'all' ? $filter : null,
        'page' => $page > 1 ? (string) $page : null,
    ]));
    header('Location: ' . url_base() . '/admin/notifications/index.php' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

admin_layout_start(['pageTitle' => 'Notifications', 'activeAdminNav' => 'admin_alerts']);
admin_flash_render();

$summary = AdminNotificationService::tableReady()
    ? AdminNotificationService::summaryForCurrentAdmin()
    : ['total' => 0, 'unread' => 0, 'read' => 0];
$list = AdminNotificationService::tableReady()
    ? AdminNotificationService::listFilteredForCurrentAdmin($filter, $page, 25)
    : ['rows' => [], 'total' => 0];
$items = $list['rows'];
$total = (int) $list['total'];
$totalPages = max(1, (int) ceil($total / 25));

$pageHeading = 'Notifications';
$pageDescription = 'Support ticket alerts for your admin account. Shared with all support staff; read state is personal.';
$pageActionsHtml = '';
if ($summary['unread'] > 0 && AdminNotificationService::tableReady()) {
    $pageActionsHtml .= '<form method="post" class="inline">';
    $pageActionsHtml .= Csrf::field();
    $pageActionsHtml .= '<input type="hidden" name="action" value="mark_all_read">';
    $pageActionsHtml .= '<button type="submit" class="at-btn at-btn--secondary at-btn--sm at-focusable">Mark all as read</button>';
    $pageActionsHtml .= '</form>';
}
if (AdminDemoPerms::can('notifications')) {
    $pageActionsHtml .= '<a href="' . Security::escape(url_base()) . '/admin/notifications/delivery-logs.php" class="at-btn at-btn--ghost at-btn--sm at-focusable">Delivery logs</a>';
}
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if (!AdminNotificationService::tableReady()) {
    echo '<div class="at-alert at-alert--error">Apply migration <code>1.16.0_admin_support_notifications.sql</code>.</div>';
    admin_layout_end();
    exit;
}
?>
<div id="at-notification-center"
     data-poll-url="<?= Security::escape(url_base() . '/admin/ajax/notifications.php') ?>"
     data-csrf="<?= Security::escape(Csrf::token()) ?>">
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
        <div class="at-kpi">
            <p class="at-kpi__label">All notifications</p>
            <p class="at-kpi__value" id="at-notif-summary-total"><?= (int) $summary['total'] ?></p>
        </div>
        <div class="at-kpi">
            <p class="at-kpi__label">Unread</p>
            <p class="at-kpi__value text-brand-green" id="at-notif-summary-unread"><?= (int) $summary['unread'] ?></p>
        </div>
        <div class="at-kpi">
            <p class="at-kpi__label">Read</p>
            <p class="at-kpi__value" id="at-notif-summary-read"><?= (int) $summary['read'] ?></p>
        </div>
    </div>

    <nav class="flex flex-wrap gap-2 mb-4" aria-label="Filter notifications">
        <?php
        $filterLabels = [
            'all' => 'All',
            'unread' => 'Unread',
            'read' => 'Read',
            'ticket_created' => 'Support tickets',
            'customer_reply' => 'Customer replies',
        ];
        foreach ($filterLabels as $key => $label):
            $qs = http_build_query(array_filter(['filter' => $key !== 'all' ? $key : null]));
            $href = url_base() . '/admin/notifications/index.php' . ($qs !== '' ? '?' . $qs : '');
            $active = $filter === $key ? ' is-active' : '';
            ?>
            <a class="at-chip at-focusable<?= $active ?>" href="<?= Security::escape($href) ?>"><?= Security::escape($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="at-card">
        <div class="at-card__body p-0">
            <?php if ($items === []): ?>
                <p class="text-sm at-muted p-6">No notifications in this view.</p>
            <?php else: ?>
                <div class="divide-y divide-[var(--at-line)]">
                    <?php foreach ($items as $item): ?>
                        <?php require APNI_TOKRI_ROOT . '/includes/partials/admin/notification-card.php'; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="flex flex-wrap gap-2 mt-4 text-sm" aria-label="Pagination">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php
                $qs = http_build_query(array_filter([
                    'filter' => $filter !== 'all' ? $filter : null,
                    'page' => $p > 1 ? (string) $p : null,
                ]));
                $href = url_base() . '/admin/notifications/index.php' . ($qs !== '' ? '?' . $qs : '');
                ?>
                <a class="at-chip at-focusable<?= $p === $page ? ' is-active' : '' ?>" href="<?= Security::escape($href) ?>"><?= (int) $p ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>

    <p class="text-sm at-muted mt-4">
        <a class="hover:underline" href="<?= Security::escape(url_base()) ?>/admin/support/index.php">Open support tickets</a>
    </p>
</div>
<?php admin_layout_end(); ?>
