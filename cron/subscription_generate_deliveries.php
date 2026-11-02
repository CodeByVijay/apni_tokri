#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate subscription delivery orders from confirmed baskets (Asia/Kolkata).
 * CLI only — schedule via cron every 15–30 minutes.
 *
 * Example crontab (every 15 minutes):
 *   5,20,35,50 * * * * /usr/bin/php /var/www/html/Apni\ Tokri/cron/subscription_generate_deliveries.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

$job = 'generate_deliveries';
if (!SubscriptionCronLock::acquire($job)) {
    fwrite(STDERR, "Another fulfilment generation run is in progress.\n");
    exit(2);
}

try {
    $result = SubscriptionFulfilmentService::run();
    $line = sprintf(
        "[%s] run_id=%s processed=%d skipped=%d awaiting_substitution=%d errors=%d\n",
        date('c'),
        $result['run_id'] ?? '',
        (int) ($result['processed'] ?? 0),
        (int) ($result['skipped'] ?? 0),
        (int) ($result['awaiting_substitution'] ?? 0),
        count($result['errors'] ?? [])
    );
    echo $line;
    if (!empty($result['errors'])) {
        foreach ($result['errors'] as $err) {
            fwrite(STDERR, $err . "\n");
        }
    }
    exit(empty($result['errors']) ? 0 : 1);
} finally {
    SubscriptionCronLock::release($job);
}
