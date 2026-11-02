<?php

declare(strict_types=1);

/**
 * Release expired delivery slot holds (checkout payment timeout).
 * CLI only — Asia/Kolkata expiry timestamps on reservation rows.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

if (!Install::isSchemaReady() || !DeliverySlotReservationService::tableReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$n = DeliverySlotReservationService::releaseExpiredHolds();
echo "Released {$n} expired slot hold(s).\n";
