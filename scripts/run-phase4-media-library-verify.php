<?php

declare(strict_types=1);

/**
 * One-shot Media Library Phase 4 verification (disposable DB only).
 * Sets empty disposable MySQL password override when XAMPP root has no password.
 */
if (getenv('APNI_TOKRI_DISPOSABLE_MYSQL_PASS') === false) {
    putenv('APNI_TOKRI_DISPOSABLE_MYSQL_PASS=');
}

$php = PHP_BINARY;
$root = dirname(__DIR__);
$runner = $root . '/scripts/run-in-disposable-db.php';

function phase4_run(string $label, array $argv, int &$fail): void
{
    global $php;
    $cmd = escapeshellarg($php) . ' ' . implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1';
    echo "\n=== {$label} ===\n";
    passthru($cmd, $code);
    if ($code !== 0) {
        $fail++;
        echo "[exit {$code}]\n";
    }
}

$fail = 0;
phase4_run('Integration: test-media-library.php', [
    $runner,
    '--migrate-media-library',
    $root . '/scripts/test-media-library.php',
], $fail);
phase4_run('Parallel: test-media-library-concurrent.php', [
    $runner,
    '--migrate-media-library',
    $root . '/scripts/test-media-library-concurrent.php',
], $fail);
phase4_run('Trash: test-media-library-trash.php', [
    $runner,
    '--migrate-media-library',
    $root . '/scripts/test-media-library-trash.php',
], $fail);
phase4_run('Production readiness', [
    $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'test-production-readiness.php',
], $fail);

echo "\n=== Phase 4 orchestrator SUMMARY fail={$fail} ===\n";
exit($fail > 0 ? 1 : 0);
