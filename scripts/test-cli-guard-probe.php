<?php

declare(strict_types=1);

/**
 * Subprocess helper for scripts/test-production-readiness.php — simulates production guard, then exits 1 if blocked.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$mode = $argv[1] ?? '';
$config = App::config();
if ($mode === 'app-env-production') {
    $config['app']['env'] = 'production';
} elseif ($mode === 'db-role-production') {
    $config['database']['role'] = 'production';
    $config['app']['env'] = 'local';
} else {
    fwrite(STDERR, "Usage: test-cli-guard-probe.php app-env-production|db-role-production\n");
    exit(2);
}
App::setConfig($config);
AppEnvironment::guardCliScript(APNI_TOKRI_ROOT . '/scripts/test-production-readiness.php');
fwrite(STDOUT, "UNGUARDED\n");
