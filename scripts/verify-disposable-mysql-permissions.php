<?php

declare(strict_types=1);

/**
 * Verify disposable DB privileges on a throwaway database (never touches app DB).
 * CLI: php scripts/verify-disposable-mysql-permissions.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/disposable_db_runner.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$db = App::config()['database'];
$db = disposable_apply_cli_credential_override($db);
$appDb = (string) ($db['name'] ?? '');
$tmp = disposable_generate_tmp_db_name();

$ok = true;
function step(bool $cond, string $label): void
{
    global $ok;
    fwrite(STDOUT, ($cond ? 'OK  ' : 'FAIL ') . $label . "\n");
    if (!$cond) {
        $ok = false;
    }
}

if (disposable_mysql_exec($db, '-e ' . escapeshellarg(
    "CREATE DATABASE `{$tmp}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
)) !== 0) {
    step(false, 'CREATE DATABASE disposable');
    exit(1);
}
step(true, 'CREATE DATABASE disposable');

try {
    $pdo = disposable_connect_pdo($db, $tmp);
    $pdo->exec('CREATE TABLE perm_probe (id INT PRIMARY KEY)');
    step(true, 'CREATE TABLE in disposable DB');
    $pdo->exec('ALTER TABLE perm_probe ADD COLUMN extra VARCHAR(8) NULL');
    step(true, 'ALTER TABLE in disposable DB');
} catch (Throwable $e) {
    step(false, 'CREATE/ALTER TABLE: ' . $e->getMessage());
}

if (disposable_mysql_exec($db, '-e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$tmp}`")) !== 0) {
    step(false, 'DROP DATABASE disposable');
} else {
    step(true, 'DROP DATABASE disposable');
}

step($appDb !== '' && !disposable_is_tmp_db_name($appDb), 'Application database is not a disposable name');

exit($ok ? 0 : 1);
