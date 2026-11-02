#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * CLI: php scripts/verify-install.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$report = Install::statusReport();
echo "Apni Tokri install verification\n";
echo str_repeat('-', 40) . "\n";
foreach ($report as $key => $value) {
    if (is_array($value)) {
        echo $key . ': ' . implode(', ', $value) . "\n";
    } elseif (is_bool($value)) {
        echo $key . ': ' . ($value ? 'yes' : 'no') . "\n";
    } else {
        echo $key . ': ' . (string) $value . "\n";
    }
}

if (!$report['schema_ready']) {
    exit(1);
}
if (AdminAuth::check() === false && Install::isSchemaReady()) {
    echo "Admin auth: not logged in (expected for CLI)\n";
}
echo "OK\n";
exit(0);
