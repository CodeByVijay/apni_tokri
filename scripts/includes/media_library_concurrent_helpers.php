<?php

declare(strict_types=1);

/**
 * Parallel Media Library test helpers (disposable DB subprocesses only).
 */

/**
 * @return array<string, string>
 */
function media_library_concurrent_base_env(): array
{
    $env = [];
    foreach (['SystemRoot', 'SystemDrive', 'PATH', 'Path', 'TEMP', 'TMP', 'WINDIR', 'USERNAME'] as $inheritKey) {
        $v = getenv($inheritKey);
        if (is_string($v) && $v !== '') {
            $env[$inheritKey] = $v;
        }
    }
    foreach ($_ENV as $k => $v) {
        if (is_string($k) && is_string($v)) {
            $env[$k] = $v;
        }
    }
    foreach ([
        'APNI_TOKRI_DISPOSABLE_DB',
        'APNI_TOKRI_TEST_DB_NAME',
        'APNI_TOKRI_ORIGINAL_DB_NAME',
        'APNI_TOKRI_TEST_DB_HOST',
        'APNI_TOKRI_TEST_DB_PORT',
        'APNI_TOKRI_TEST_DB_USER',
        'APNI_TOKRI_TEST_DB_PASS',
        'APNI_TOKRI_DISPOSABLE_MYSQL_PASS',
    ] as $key) {
        $v = getenv($key);
        if ($v !== false) {
            $env[$key] = (string) $v;
        }
    }
    if (!isset($env['APNI_TOKRI_TEST_DB_PASS']) && getenv('APNI_TOKRI_DISPOSABLE_MYSQL_PASS') !== false) {
        $env['APNI_TOKRI_TEST_DB_PASS'] = (string) getenv('APNI_TOKRI_DISPOSABLE_MYSQL_PASS');
    }
    $env['APNI_TOKRI_DISPOSABLE_DB'] = '1';

    return $env;
}

/**
 * @param array<string, string> $extra
 */
function media_library_concurrent_spawn(string $childScript, array $extra): array
{
    if (!is_readable($childScript)) {
        throw new RuntimeException('Child script missing: ' . $childScript);
    }
    $out = tempnam(sys_get_temp_dir(), 'atmlc');
    if ($out === false) {
        throw new RuntimeException('Could not create temp output file.');
    }
    $env = media_library_concurrent_base_env();
    foreach ($extra as $k => $v) {
        $env[$k] = $v;
    }
    $env['AT_ML_CONCURRENT_OUT'] = $out;

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open([PHP_BINARY, $childScript], $descriptors, $pipes, null, $env);
    if (!is_resource($proc)) {
        @unlink($out);

        return ['ok' => false, 'exit' => 1, 'payload' => [], 'raw' => ''];
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    $raw = (string) file_get_contents($out);
    @unlink($out);
    $payload = json_decode($raw, true);

    return [
        'ok' => $exit === 0 && is_array($payload),
        'exit' => $exit,
        'payload' => is_array($payload) ? $payload : [],
        'raw' => $raw,
    ];
}

/**
 * @return resource
 */
function media_library_concurrent_barrier_open()
{
    $path = tempnam(sys_get_temp_dir(), 'mlbar');
    if ($path === false) {
        throw new RuntimeException('Could not create concurrency barrier file.');
    }
    $fp = fopen($path, 'c+b');
    if ($fp === false) {
        throw new RuntimeException('Could not open barrier file.');
    }
    if (!flock($fp, LOCK_EX)) {
        throw new RuntimeException('Could not lock barrier file.');
    }

    return $fp;
}

function media_library_concurrent_barrier_release($fp): void
{
    if (is_resource($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function media_library_concurrent_barrier_path($fp): string
{
    $meta = stream_get_meta_data($fp);

    return (string) ($meta['uri'] ?? '');
}
