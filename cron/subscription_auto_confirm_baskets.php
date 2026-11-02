#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Auto-confirm subscription baskets after selection cutoff (Asia/Kolkata).
 * CLI only — schedule via cron every 5–15 minutes.
 *
 * Example crontab (every 10 minutes):
 *   0,10,20,30,40,50 * * * * /usr/bin/php /var/www/html/Apni\ Tokri/cron/subscription_auto_confirm_baskets.php
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

$job = 'auto_confirm_baskets';
if (!SubscriptionCronLock::acquire($job)) {
    fwrite(STDERR, "Another auto-confirm run is in progress.\n");
    exit(2);
}

try {
    $result = SubscriptionAutoConfirmService::run();
    $line = sprintf(
        "[%s] run_id=%s processed=%d skipped=%d errors=%d\n",
        date('c'),
        $result['run_id'] ?? '',
        (int) ($result['processed'] ?? 0),
        (int) ($result['skipped'] ?? 0),
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
