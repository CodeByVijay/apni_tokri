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
 * Run a PHP test script in a subprocess; capture merged stdout/stderr lines.
 *
 * @param list<string> $outputLines
 */
function test_cli_exec_script(string $scriptPath, array &$outputLines = []): int
{
    if (!is_readable($scriptPath)) {
        throw new RuntimeException('Test script not readable: ' . $scriptPath);
    }
    $cmd = escapeshellarg(test_cli_php_binary()) . ' ' . escapeshellarg($scriptPath) . ' 2>&1';
    $outputLines = [];
    exec($cmd, $outputLines, $code);

    return $code;
}
