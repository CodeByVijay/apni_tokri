<?php

declare(strict_types=1);

/**
 * Consolidated Stage 11 test runner (Phases A–E + Stage 7–10 regression hooks).
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

$root = dirname(__DIR__);
$suites = [
    'Phase A' => $root . '/scripts/test-subscription-stage11-phase-a.php',
    'Phase B' => $root . '/scripts/test-subscription-stage11-phase-b.php',
    'Phase C' => $root . '/scripts/test-subscription-stage11-phase-c.php',
    'Phase D' => $root . '/scripts/test-subscription-stage11-phase-d.php',
    'Phase E' => $root . '/scripts/test-subscription-stage11-phase-e.php',
    'Hardening' => $root . '/scripts/test-subscription-stage11-hardening.php',
    'Production readiness' => $root . '/scripts/test-subscription-stage11-production-readiness.php',
    'Stage 8 delivery' => $root . '/scripts/test-delivery-stage8.php',
    'Stage 9 payment' => $root . '/scripts/test-payment-stage9.php',
    'Stage 10 promotions' => $root . '/scripts/test-promotions-stage10.php',
    'Stock concurrency' => $root . '/scripts/test-stock-concurrency.php',
];

$failed = 0;
foreach ($suites as $label => $path) {
    if (!is_file($path)) {
        echo "SKIP: $label (missing $path)\n";
        continue;
    }
    echo "\n=== $label ===\n";
    passthru(PHP_BINARY . ' ' . escapeshellarg($path), $code);
    if ($code !== 0) {
        $failed++;
        echo "FAILED suite: $label (exit $code)\n";
    }
}

echo $failed === 0 ? "\nALL STAGE 11 SUITES PASSED\n" : "\n$failed SUITE(S) FAILED\n";
exit($failed > 0 ? 1 : 0);
