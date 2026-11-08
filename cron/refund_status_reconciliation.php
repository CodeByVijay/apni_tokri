#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Poll Cashfree/Razorpay for in-flight refund status (GET only; never creates refunds).
 *
 * cPanel example (every 5 minutes — replace php path and project root):
 *   /usr/bin/php /home/USER/public_html/cron/refund_status_reconciliation.php
 *
 * Find PHP CLI: `which php` via SSH, or cPanel → MultiPHP Manager / Select PHP Version.
 */
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') {
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    $secret = trim((string) (AppSettings::get('cron.secret', '') ?? ''));
    $provided = (string) ($_GET['secret'] ?? $_SERVER['HTTP_X_CRON_SECRET'] ?? '');
    if ($secret === '' || !hash_equals($secret, $provided)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Forbidden';
        exit;
    }
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

$summary = RefundReconciliationService::runCronBatch();

echo sprintf(
    "[%s] checked=%d pending=%d completed=%d failed=%d skipped=%d errors=%d batch=%d notes=%s\n",
    date('c'),
    $summary['checked'],
    $summary['pending'],
    $summary['completed'],
    $summary['failed'],
    $summary['skipped'],
    $summary['errors'],
    RefundReconciliationService::resolveBatchSize(),
    $summary['messages'] !== [] ? implode(',', $summary['messages']) : '-'
);

exit($summary['errors'] > 0 && $summary['completed'] === 0 ? 1 : 0);
