<?php

declare(strict_types=1);

/**
 * Admin notification delivery log detail view — static + optional DB.
 * CLI: php scripts/test-admin-notification-delivery-view.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
function okv(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$root = dirname(__DIR__);
$logs = (string) file_get_contents($root . '/admin/notifications/delivery-logs.php');
okv(str_contains($logs, 'delivery-view.php'), 'Delivery logs link to detail view');
okv(str_contains($logs, 'View</a>'), 'View action on each row');
okv(str_contains($logs, 'value="retry"'), 'Retry action preserved');

$view = (string) file_get_contents($root . '/admin/notifications/delivery-view.php');
okv(str_contains($view, 'denyIfCannot(\'notifications\')'), 'Detail page requires notifications permission');
okv(str_contains($view, 'NotificationQueueAdminDetailService::viewModel'), 'Detail uses admin detail service');
okv(str_contains($view, 'sandbox=""'), 'HTML preview uses sandboxed iframe');
okv(str_contains($view, 're-rendered'), 'UI marks regenerated preview');

okv(class_exists(NotificationQueueAdminDetailService::class), 'NotificationQueueAdminDetailService loaded');
okv(method_exists(NotificationQueueAdminDetailService::class, 'viewModel'), 'viewModel exists');

okv(str_contains((string) file_get_contents($root . '/includes/notifications/queue_admin_detail.php'), 'stored_body_available'), 'Detail documents no stored body');
okv(str_contains((string) file_get_contents($root . '/includes/notifications/queue_admin_detail.php'), 'NotificationEmailHtmlSanitizer'), 'Preview HTML sanitized');

if (Install::isSchemaReady() && NotificationQueueService::tableReady()) {
    okv(NotificationQueueAdminDetailService::viewModel(0) === null, 'Invalid id returns null');
    okv(NotificationQueueAdminDetailService::viewModel(999999999) === null, 'Missing id returns null');
    $sampleId = (int) (Database::pdo()->query('SELECT id FROM notification_queue ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 0);
    if ($sampleId > 0) {
        $vm = NotificationQueueAdminDetailService::viewModel($sampleId);
        okv(is_array($vm) && isset($vm['overview']['id']), 'Sample queue row loads detail model');
        okv(isset($vm['preview']['is_regenerated']) && !empty($vm['preview']['is_regenerated']), 'Preview flagged regenerated');
        if (($vm['overview']['channel'] ?? '') === 'email' && !empty($vm['preview']['html'])) {
            okv(!str_contains((string) $vm['preview']['html'], '<script'), 'Sanitized HTML strips script tags');
        }
    } else {
        echo "SKIP: No notification_queue rows for DB sample.\n";
    }
} else {
    echo "SKIP: Schema / notification_queue not ready.\n";
}

echo $fail === 0 ? "PASS: admin notification delivery view.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
