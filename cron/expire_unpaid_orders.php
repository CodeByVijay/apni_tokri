#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cancel unpaid storefront orders after the payment window (default 15 minutes).
 *
 * cPanel example (every 5 minutes — replace php path and project root):
 *   /usr/bin/php /home/USER/public_html/cron/expire_unpaid_orders.php
 *
 * HTTP (optional, requires cron.secret in settings):
 *   GET /cron/expire_unpaid_orders.php?secret=YOUR_CRON_SECRET
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

if (!UnpaidOrderExpiryService::acquireCronLock()) {
    echo sprintf("[%s] skipped=lock_busy\n", date('c'));
    exit(0);
}

try {
    $summary = UnpaidOrderExpiryService::runCronBatch();
} finally {
    UnpaidOrderExpiryService::releaseCronLock();
}

echo sprintf(
    "[%s] cancelled=%d skipped=%d deferred=%d paid=%d errors=%d batch=%d notes=%s\n",
    date('c'),
    $summary['cancelled'],
    $summary['skipped'],
    $summary['deferred'],
    $summary['paid'],
    $summary['errors'],
    UnpaidOrderExpiryService::resolveBatchSize(),
    $summary['messages'] !== [] ? implode(',', $summary['messages']) : '-'
);

exit($summary['errors'] > 0 && $summary['cancelled'] === 0 && $summary['paid'] === 0 ? 1 : 0);
