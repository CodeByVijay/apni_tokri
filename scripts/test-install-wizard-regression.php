<?php

declare(strict_types=1);

/** Ensures an already-configured site is not forced through the public wizard. */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK: ' : 'FAIL: ') . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

if (Install::hasLocalConfig() && Install::isApplicationInstalled()) {
    ok(Install::isPublicInstallBlocked(), 'Configured installed site blocks public install surface');
} else {
    echo "SKIP: no local config + schema on configured DSN (dev partial setup)\n";
}

ok(InstallWizardService::requirementsMet(), 'Wizard requirements check runs on existing codebase');
ok(is_readable(InstallWizardService::productionSqlPath()), 'Production SQL available for wizard import');

echo $fail === 0 ? "Install wizard regression OK\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
