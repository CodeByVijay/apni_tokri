<?php

declare(strict_types=1);

/**
 * CLI test subprocess helper — uses PHP_BINARY (Windows/Linux safe, no PATH dependency).
 */

function test_cli_php_binary(): string
{
    static $cached = null;
    if (is_string($cached)) {
        return $cached;
    }
    $bin = PHP_BINARY;
    if ($bin === '' || !is_file($bin) || !is_readable($bin)) {
        throw new RuntimeException(
            'PHP_BINARY is missing or not readable (' . ($bin !== '' ? $bin : 'empty') . ').'
        );
    }
    $cached = $bin;

    return $cached;
}

/**
 * When running inside scripts/run-in-disposable-db.php, forward DB env to child PHP processes
 * so nested test_cli_exec_script() calls never touch the configured application database.
 */
function test_cli_disposable_env_prefix(): string
{
    if (getenv('APNI_TOKRI_DISPOSABLE_DB') !== '1') {
        return '';
    }
    $parts = [];
    foreach ([
        'APNI_TOKRI_DISPOSABLE_DB',
        'APNI_TOKRI_TEST_DB_NAME',
        'APNI_TOKRI_ORIGINAL_DB_NAME',
        'APNI_TOKRI_TEST_DB_HOST',
        'APNI_TOKRI_TEST_DB_PORT',
        'APNI_TOKRI_TEST_DB_USER',
        'APNI_TOKRI_TEST_DB_PASS',
    ] as $key) {
        $v = getenv($key);
        if ($v !== false) {
            $parts[] = $key . '=' . escapeshellarg((string) $v);
        }
    }

    return $parts === [] ? '' : implode(' ', $parts) . ' ';
}

/**
 * Run a PHP test script in a subprocess; capture merged stdout/stderr lines.
 *
 * @param list<string> $outputLines
 */
function test_cli_exec_script(string $scriptPath, array &$outputLines = []): int
{
    if (!is_readable($scriptPath)) {
        throw new RuntimeException('Test script not readable: ' . $scriptPath);
    }
    $cmd = test_cli_disposable_env_prefix()
        . escapeshellarg(test_cli_php_binary()) . ' ' . escapeshellarg($scriptPath) . ' 2>&1';
    $outputLines = [];
    exec($cmd, $outputLines, $code);

    return $code;
}
