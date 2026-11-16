<?php

declare(strict_types=1);

/**
 * Disposable-database helpers for customer remember-login integration tests.
 * Never enable on production databases.
 */

function remember_integration_enable(): void
{
    putenv('APNI_TOKRI_REMEMBER_INTEGRATION_RESTORE=1');
    $_ENV['APNI_TOKRI_REMEMBER_INTEGRATION_RESTORE'] = '1';
}

/**
 * @return array{id: int, last_used_at: ?string, revoked_at: ?string, expires_ok: bool, token_hash: string}
 */
function remember_token_row_by_selector(PDO $pdo, string $selector): array
{
    $stmt = $pdo->prepare(
        'SELECT id, token_hash, last_used_at, revoked_at,
                (expires_at > DATE_ADD(NOW(), INTERVAL 29 DAY)) AS expires_ok
         FROM customer_remember_tokens WHERE selector = ? LIMIT 1'
    );
    $stmt->execute([$selector]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [
            'id' => 0,
            'last_used_at' => null,
            'revoked_at' => null,
            'expires_ok' => false,
            'token_hash' => '',
        ];
    }

    return [
        'id' => (int) $row['id'],
        'last_used_at' => $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
        'revoked_at' => $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
        'expires_ok' => (int) ($row['expires_ok'] ?? 0) === 1,
        'token_hash' => (string) $row['token_hash'],
    ];
}

function remember_store_cookie_fixture_file(string $cookieValue): string
{
    $path = tempnam(sys_get_temp_dir(), 'atrem');
    if ($path === false) {
        throw new RuntimeException('tempnam failed for remember cookie fixture.');
    }
    file_put_contents($path, $cookieValue);
    chmod($path, 0600);

    return $path;
}

/**
 * Run HTTP-style restore in a subprocess (session start + maybeRestoreFromCookie).
 *
 * @param array<string, string> $baseEnv
 * @return array{ok: bool, restored: bool, last_used_at_set: bool, hash_rotated: bool, customer_id: ?int}
 */
function remember_http_restore_subprocess(string $childScript, array $baseEnv, string $cookieValue): array
{
    if (!is_readable($childScript)) {
        return ['ok' => false, 'restored' => false, 'last_used_at_set' => false, 'hash_rotated' => false, 'customer_id' => null];
    }
    $cookieFile = remember_store_cookie_fixture_file($cookieValue);
    $outFile = tempnam(sys_get_temp_dir(), 'atremout');
    $parsed = CustomerRememberAuth::parseCookieValue($cookieValue);
    $selector = $parsed['selector'] ?? '';
    $env = $baseEnv;
    $env['AT_REMEMBER_HTTP_CHILD'] = '1';
    $env['AT_REMEMBER_HTTP_OUT'] = $outFile !== false ? $outFile : '';
    $env['AT_REMEMBER_COOKIE_FILE'] = $cookieFile;
    $env['AT_REMEMBER_SELECTOR'] = $selector;
    $env['APNI_TOKRI_REMEMBER_INTEGRATION_RESTORE'] = '1';
    $env['APNI_TOKRI_DISPOSABLE_DB'] = '1';

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open([PHP_BINARY, $childScript], $descriptors, $pipes, null, $env);
    if (!is_resource($proc)) {
        @unlink($cookieFile);
        @unlink($outFile);

        return ['ok' => false, 'restored' => false, 'last_used_at_set' => false, 'hash_rotated' => false, 'customer_id' => null];
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    $raw = is_string($outFile) ? (string) file_get_contents($outFile) : '';
    @unlink($cookieFile);
    @unlink($outFile);
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'restored' => false, 'last_used_at_set' => false, 'hash_rotated' => false, 'customer_id' => null];
    }

    return [
        'ok' => $exit === 0 && !empty($payload['ok']),
        'restored' => !empty($payload['restored']),
        'last_used_at_set' => !empty($payload['last_used_at_set']),
        'hash_rotated' => !empty($payload['hash_rotated']),
        'customer_id' => isset($payload['customer_id']) ? (int) $payload['customer_id'] : null,
    ];
}

/**
 * @return array<string, string>
 */
function remember_integration_base_env(): array
{
    $env = [];
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
    $env['APNI_TOKRI_DISPOSABLE_DB'] = '1';

    return $env;
}
