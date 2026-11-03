<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

if (!AdminNotificationService::viewerCanAccess()) {
    Ajax::error('Forbidden.', 403);
}

if (!AdminNotificationService::tableReady()) {
    Ajax::error('Notifications not available.', 503);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    Ajax::success([
        'unread' => AdminNotificationService::unreadCountForCurrentAdmin(),
        'items' => AdminNotificationService::recentForCurrentAdmin(8),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed.', 405);
}

Csrf::requireValid();
$action = isset($_POST['action']) && is_string($_POST['action']) ? trim($_POST['action']) : '';

if ($action === 'mark_read') {
    $id = (int) ($_POST['notification_id'] ?? 0);
    if ($id <= 0) {
        Ajax::error('Invalid notification.');
    }
    if (!AdminNotificationService::markRead($id)) {
        Ajax::error('Could not update notification.');
    }
    Ajax::success(['unread' => AdminNotificationService::unreadCountForCurrentAdmin()]);
}

if ($action === 'mark_all_read') {
    AdminNotificationService::markAllReadForCurrentAdmin();
    Ajax::success(['unread' => 0]);
}

Ajax::error('Invalid action.');
