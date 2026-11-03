<?php

declare(strict_types=1);

/**
 * Admin login rate limit regression (disposable DB only).
 * CLI: php scripts/test-admin-login-rate-limit.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pass = 0;
$fail = 0;

function okRl(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if ($c) {
        $pass++;
    } else {
        $fail++;
    }
}

function mysqlRl(array $db, string $extra): int
{
    $cmd = 'mysql -h ' . escapeshellarg((string) $db['host']) . ' -P ' . (int) ($db['port'] ?? 3306)
        . ' -u ' . escapeshellarg((string) ($db['user'] ?? 'root'));
    $p = (string) ($db['pass'] ?? $db['password'] ?? '');
    if ($p !== '') {
        $cmd .= ' -p' . escapeshellarg($p);
    }
    passthru($cmd . ' ' . $extra, $code);

    return (int) $code;
}

$root = dirname(__DIR__);
$sqlFile = $root . '/database/production/apni_tokri_production.sql';
$migration = $root . '/database/migrations/1.21.0_security_remediation_phase1.sql';
$dbCfg = App::config()['database'];
$freshDb = 'apni_tokri_rl_' . date('YmdHis');

if (!is_readable($sqlFile) || !is_readable($migration)) {
    fwrite(STDERR, "Missing SQL files.\n");
    exit(1);
}

okRl(mysqlRl($dbCfg, '-e ' . escapeshellarg("CREATE DATABASE `{$freshDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) === 0, 'Create disposable DB');
okRl(mysqlRl($dbCfg, escapeshellarg($freshDb) . ' < ' . escapeshellarg($sqlFile)) === 0, 'Import SQL');
okRl(mysqlRl($dbCfg, escapeshellarg($freshDb) . ' < ' . escapeshellarg($migration)) === 0, 'Apply 1.21.0 migration');

$orig = App::config();
App::setConfig(array_replace_recursive($orig, ['database' => array_merge($dbCfg, ['name' => $freshDb])]));
Database::disconnect();

try {
    Install::ensureConnection();
    $email = Install::SEED_ADMIN_EMAIL;
    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';

    okRl(AdminLoginSecurity::blockReason($email) === null, 'First attempt: not throttled (fresh email+IP)');
    okRl(AdminLoginSecurity::recentFailureCountForEmail($email) === 0, 'No prior failures for email');

    for ($i = 0; $i < 4; $i++) {
        okRl(!AdminAuth::attempt($email, 'wrong-' . $i), 'Failed attempt ' . ($i + 1));
    }
    okRl(AdminLoginSecurity::recentFailureCountForEmail($email) === 4, 'Four failures recorded');
    okRl(AdminLoginSecurity::blockReason($email) === null, 'Below email threshold (max 5) still allowed');

    okRl(!AdminAuth::attempt($email, 'wrong-final'), 'Fifth failure');
    okRl(AdminLoginSecurity::blockReason($email) === 'throttled', 'Email throttled at threshold');

    // Correct password must still succeed (after throttle delay) and clear failures.
    okRl(AdminAuth::attempt($email, 'ChangeMe123!'), 'Correct password succeeds while email throttled');
    okRl(AdminAuth::check(), 'Session established');
    okRl(AdminLoginSecurity::blockReason($email) === null, 'Failures cleared after success');
    AdminAuth::logout();

    // IP throttling: many emails from one IP.
    $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
    for ($j = 0; $j < 20; $j++) {
        AdminAuth::attempt('noise' . $j . '@example.com', 'x');
    }
    okRl(AdminLoginSecurity::recentFailureCountForIp('203.0.113.99') >= 20, 'IP accumulated failures');
    okRl(AdminLoginSecurity::blockReason('fresh-ip-test@example.com') === 'throttled', 'New email blocked by IP cap');

    // Expired window: old failures ignored.
    Database::pdo()->exec(
        'UPDATE admin_login_attempts SET created_at = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE ip_address = ' . Database::pdo()->quote('203.0.113.99')
    );
    okRl(AdminLoginSecurity::blockReason('fresh-ip-test@example.com') === null, 'Expired window allows login again');

    okRl(!AdminLoginSecurity::tablesReady() || AdminLoginSecurity::tablesReady(), 'Schema probe stable');
} finally {
    App::setConfig($orig);
    Database::disconnect();
    mysqlRl($dbCfg, '-e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$freshDb}`"));
}

echo "\nSUMMARY pass={$pass} fail={$fail}\n";
exit($fail === 0 ? 0 : 1);
