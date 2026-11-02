#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Release orphaned subscription stock holds and recover deducted stock without orders.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

$job = 'recover_reservations';
if (!SubscriptionCronLock::acquire($job)) {
    exit(2);
}

try {
    $holds = SubscriptionReservationRecoveryService::recoverOrphanedHolds(120);
    $deducted = SubscriptionReservationRecoveryService::recoverDeductedWithoutOrder();
    $refunds = SubscriptionRefundService::reconcilePendingRefunds();
    echo sprintf(
        "holds_released=%d hold_skipped=%d deducted_recovered=%d refunds=%d\n",
        (int) ($holds['released'] ?? 0),
        (int) ($holds['skipped'] ?? 0),
        (int) ($deducted['recovered'] ?? 0),
        (int) ($refunds['processed'] ?? 0)
    );
    $allErrors = array_merge($holds['errors'] ?? [], $deducted['errors'] ?? [], $refunds['errors'] ?? []);
    if ($allErrors !== []) {
        SubscriptionRecoveryLog::record('cron_recover_reservations', 'batch', 'failed', ['errors' => $allErrors]);
        foreach ($allErrors as $err) {
            fwrite(STDERR, $err . "\n");
        }
    }
    exit($allErrors === [] ? 0 : 1);
} finally {
    SubscriptionCronLock::release($job);
}
