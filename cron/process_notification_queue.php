#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Process pending notification_queue jobs (email / SMS / in-app).
 *
 * Shared-hosting design: one short CLI run per cron tick (no daemon, no Redis, no pcntl).
 * See docs/cpanel-notification-cron.md for cPanel setup.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady() || !NotificationQueueService::tableReady()) {
    fwrite(STDERR, "Notification queue not ready. Apply migration 1.13.0_stage14_notifications_phase4.sql\n");
    exit(1);
}

$batch = NotificationQueueService::resolveBatchSize();
$maxRuntime = NotificationQueueService::resolveMaxRuntimeSeconds();
$started = microtime(true);

$result = NotificationQueueService::runWorker($batch, $maxRuntime, $started);

echo sprintf(
    "[%s] recovered=%d claimed=%d sent=%d failed=%d retry_scheduled=%d stop=%s batch=%d max_runtime=%d\n",
    date('c'),
    $result['recovered'],
    $result['claimed'],
    $result['sent'],
    $result['failed'],
    $result['retry_scheduled'],
    $result['stop_reason'],
    $batch,
    $maxRuntime
);

exit($result['failed'] > 0 && $result['sent'] === 0 && $result['claimed'] > 0 ? 1 : 0);
