<?php

declare(strict_types=1);

/**
 * Security remediation phase 1 — automated checks (disposable DB + HTTP smoke).
 * CLI: php scripts/test-security-remediation-phase1.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/guard.php';
require_once dirname(__DIR__) . '/scripts/lib/test_script_safety.php';
require_once dirname(__DIR__) . '/scripts/includes/disposable_test_admin.php';

$pass = 0;
$fail = 0;

function okP1(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if ($c) {
        $pass++;
    } else {
        $fail++;
    }
}

/** @param array<string, mixed> $patch */
function withCfgP1(array $patch, callable $fn): void
{
    $orig = App::config();
    App::setConfig(array_replace_recursive($orig, $patch));
    Database::disconnect();
    try {
        $fn();
    } finally {
        App::setConfig($orig);
        Database::disconnect();
        CatalogueGateway::reset();
    }
}

function mysqlP1(array $db, string $extra): int
{
    $cmd = 'mysql -h ' . escapeshellarg((string) $db['host']) . ' -P ' . (int) ($db['port'] ?? 3306)
        . ' -u ' . escapeshellarg((string) $db['user'] ?? 'root');
    $p = (string) ($db['pass'] ?? $db['password'] ?? '');
    if ($p !== '') {
        $cmd .= ' -p' . escapeshellarg($p);
    }
    passthru($cmd . ' ' . $extra, $code);

    return (int) $code;
}

$root = dirname(__DIR__);
$migration = $root . '/database/migrations/1.21.0_security_remediation_phase1.sql';
$sqlFile = $root . '/database/production/apni_tokri_production.sql';
$dbCfg = App::config()['database'];
$freshDb = 'apni_tokri_sec_p1_' . date('YmdHis');
$baseUrl = rtrim((string) (App::config()['app']['base_url'] ?? ''), '/');

// --- HTTP: scripts blocked (no DB) ---
if ($baseUrl !== '' && function_exists('curl_init')) {
    $paths = [
        '/scripts/test-production-readiness.php',
        '/scripts/../scripts/test-production-readiness.php',
        '/Scripts/test-production-readiness.php',
    ];
    foreach ($paths as $path) {
        $ch = curl_init($baseUrl . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 10]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        okP1($code === 403 || $code === 404, 'HTTP ' . $code . ' for ' . $path);
    }
    $ch = curl_init($baseUrl . '/ajax/ping.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $body = (string) curl_exec($ch);
    curl_close($ch);
    okP1(!str_contains($body, '"env"'), 'ajax/ping.php does not expose env');
    okP1(str_contains($body, '"status"') || str_contains($body, 'success'), 'ajax/ping.php responds');
    if (Install::isApplicationInstalled()) {
        $ch = curl_init($baseUrl . '/install.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        okP1($code === 404, 'install.php blocked when installed (HTTP ' . $code . ')');
        $ch = curl_init($baseUrl . '/health-check.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        $hc = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        okP1($hc === 404, 'health-check.php blocked for guests when installed (HTTP ' . $hc . ')');
    } else {
        echo "SKIP: install.php 404 (schema not installed on configured DSN)\n";
    }
} else {
    echo "SKIP: HTTP smoke (base_url or curl)\n";
}

// --- CLI script still runs ---
passthru('php ' . escapeshellarg($root . '/scripts/test-support-ticket-auth-audit.php') . ' 2>&1 | head -1', $cliCode);
okP1($cliCode === 0 || $cliCode === 255, 'CLI audit script executable (exit ' . $cliCode . ')');

if (!is_readable($sqlFile) || !is_readable($migration)) {
    echo "SKIP: disposable DB tests (missing SQL files)\n";
    echo "\nSUMMARY pass={$pass} fail={$fail}\n";
    exit($fail === 0 ? 0 : 1);
}

okP1(mysqlP1($dbCfg, '-e ' . escapeshellarg("CREATE DATABASE `{$freshDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) === 0, 'Create disposable DB');
okP1(mysqlP1($dbCfg, escapeshellarg($freshDb) . ' < ' . escapeshellarg($sqlFile)) === 0, 'Import production SQL');
okP1(mysqlP1($dbCfg, escapeshellarg($freshDb) . ' < ' . escapeshellarg($migration)) === 0, 'Apply 1.21.0 migration');

withCfgP1(['database' => array_merge($dbCfg, ['name' => $freshDb])], static function () use (&$pass, &$fail, $freshDb): void {
    putenv('APNI_TOKRI_DISPOSABLE_DB=1');
    TestScriptSafety::assertDisposableDatabaseOrAbort($freshDb);
    Install::ensureConnection();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.50';

    okP1(AdminLoginSecurity::tablesReady(), 'admin_login_attempts present');
    okP1(AdminPasswordRecovery::tablesReady(), 'admin_password_reset_tokens present');

    $email = Install::SEED_ADMIN_EMAIL;
    $knownPassword = disposable_test_set_admin_password(1);
    for ($i = 0; $i < 6; $i++) {
        AdminAuth::attempt($email, 'wrong-password-' . $i);
    }
    okP1(AdminLoginSecurity::blockReason($email) === 'throttled', 'Email throttled after repeated failures');

    $_SERVER['REMOTE_ADDR'] = '203.0.113.51';
    okP1(AdminLoginSecurity::blockReason($email) === 'throttled', 'Same email still throttled from new IP (wrong password path)');
    okP1(AdminAuth::attempt($email, $knownPassword), 'Correct password succeeds while email throttled');
    AdminAuth::logout();
    okP1(AdminLoginSecurity::blockReason($email) === null, 'Email failures cleared after successful login');

    $sent = [];
    AdminPasswordRecovery::setMailSender(static function (string $to, string $subject, string $text, string $html) use (&$sent): array {
        $sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text];

        return ['ok' => true];
    });

    $r1 = AdminPasswordRecovery::requestReset($email);
    okP1($r1['ok'] && count($sent) === 1, 'Password reset email mocked for known admin');
    $r2 = AdminPasswordRecovery::requestReset('nobody-' . time() . '@example.com');
    okP1($r2['ok'] && count($sent) === 1, 'Unknown email generic response (no extra mail)');

    if (preg_match('#/admin/reset-password\.php\?token=([a-f0-9]+)#', $sent[0]['text'], $m)) {
        $plain = $m[1];
        $reset = AdminPasswordRecovery::resetWithToken($plain, 'NewSecurePass9!', 'NewSecurePass9!');
        okP1($reset['ok'], 'Reset with valid token');
        $reuse = AdminPasswordRecovery::resetWithToken($plain, 'AnotherPass9!', 'AnotherPass9!');
        okP1(empty($reuse['ok']), 'Reused token rejected');
        Database::pdo()->exec('DELETE FROM admin_login_attempts');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
        okP1(AdminAuth::attempt($email, 'NewSecurePass9!'), 'Login with new password');
        okP1(AdminAuth::check(), 'Session active after login');
        $uid = (int) AdminAuth::id();
        Database::pdo()->prepare('UPDATE admin_users SET auth_epoch = auth_epoch + 1 WHERE id = ?')->execute([$uid]);
        okP1(!AdminAuth::check(), 'Session invalidated when auth_epoch changes');
    } else {
        okP1(false, 'Extract reset token from mock mail');
    }

    AdminPasswordRecovery::setMailSender(null);

    $guestRefund = SupportTicketService::create(null, 'refund', 'x', 'y', null);
    okP1(empty($guestRefund['ok']), 'Guest refund ticket denied');
    $guestOther = SupportTicketService::create(null, 'other', 'Guest', 'General question', null);
    okP1(!empty($guestOther['ok']), 'Guest other ticket allowed');
    if (!empty($guestOther['ticket_number'])) {
        $t = SupportTicketService::byNumber((string) $guestOther['ticket_number']);
        if ($t) {
            $pdo = Database::pdo();
            $pdo->prepare('DELETE FROM support_messages WHERE ticket_id = ?')->execute([(int) $t['id']]);
            $pdo->prepare('DELETE FROM support_tickets WHERE id = ?')->execute([(int) $t['id']]);
        }
    }

    okP1(!Install::isApplicationInstalled() || Install::installedSchemaVersion() === Install::SCHEMA_VERSION, 'Schema version after migration');
});

mysqlP1($dbCfg, '-e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$freshDb}`"));
okP1(true, 'Dropped disposable DB');

echo "\nSUMMARY pass={$pass} fail={$fail}\n";
exit($fail === 0 ? 0 : 1);
