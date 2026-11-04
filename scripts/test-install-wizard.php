<?php

declare(strict_types=1);

/**
 * Installation wizard tests — disposable database only.
 * CLI: INSTALL_TEST_DB=apni_tokri_wizard_test php scripts/test-install-wizard.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$dbCfg = App::config()['database'];
$host = (string) ($dbCfg['host'] ?? '127.0.0.1');
$port = (int) ($dbCfg['port'] ?? 3306);
$user = (string) ($dbCfg['user'] ?? 'root');
$pass = (string) ($dbCfg['pass'] ?? $dbCfg['password'] ?? '');
$testDb = getenv('INSTALL_TEST_DB') ?: ('apni_tokri_wizard_' . date('YmdHis'));

$fail = 0;
function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK: ' : 'FAIL: ') . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function mysql_cmd(string $extra): string
{
    global $host, $port, $user, $pass;
    $cmd = 'mysql -h ' . escapeshellarg($host) . ' -P ' . $port . ' -u ' . escapeshellarg($user);
    if ($pass !== '') {
        $cmd .= ' -p' . escapeshellarg($pass);
    }

    return $cmd . ' ' . $extra;
}

passthru(mysql_cmd('-e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$testDb}`")), $code);
passthru(mysql_cmd('-e ' . escapeshellarg("CREATE DATABASE `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")), $code);

$tmpRoot = sys_get_temp_dir() . '/apni_tokri_wizard_' . bin2hex(random_bytes(4));
@mkdir($tmpRoot . '/secrets', 0750, true);
@mkdir($tmpRoot . '/invoices', 0750, true);
$configPath = sys_get_temp_dir() . '/apni_tokri_wizard_cfg_' . bin2hex(random_bytes(4)) . '.php';
$lockPath = sys_get_temp_dir() . '/apni_tokri_wizard_lock_' . bin2hex(random_bytes(4));
$runLock = sys_get_temp_dir() . '/apni_tokri_wizard_run_' . bin2hex(random_bytes(4));

InstallWizardService::$testConfigPath = $configPath;
InstallWizardService::$testLockPath = $lockPath;
InstallWizardService::$testRunLockPath = $runLock;
InstallWizardService::$testBypassInstallGuard = true;
InstallHostingPaths::$testHostingHomeOverride = $tmpRoot;

$db = [
    'host' => $host,
    'port' => $port,
    'name' => $testDb,
    'user' => $user,
    'password' => $pass,
];

ok(InstallWizardService::requirementsMet(), 'Server requirements met for wizard');
ok(InstallWizardService::testDatabaseConnection($db)['ok'] === true, 'Database connection to disposable DB');
$empty = InstallWizardService::assertDatabaseEmpty($db);
ok($empty['ok'] === true, 'Disposable database is empty');

$nonEmpty = InstallWizardService::importProductionSchema($db);
ok($nonEmpty['ok'] === true, 'Production SQL import on empty database');

$pdoTest = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $testDb),
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$ver = (string) $pdoTest->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version' LIMIT 1")->fetchColumn();
ok($ver === Install::SCHEMA_VERSION, 'Schema version recorded in disposable database after import');

$repeat = InstallWizardService::assertDatabaseEmpty($db);
ok($repeat['ok'] === false, 'Refuse import over non-empty database');

Database::disconnect();
$masterPath = $tmpRoot . '/secrets/master.key';
$invPath = $tmpRoot . '/invoices';
$keyPdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $testDb),
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$key1 = InstallWizardService::prepareEncryptionKey($masterPath, $keyPdo);
ok($key1['ok'] === true && !empty($key1['created']), 'Generate master key when no invoices');
$key2 = InstallWizardService::prepareEncryptionKey($masterPath, $keyPdo);
ok($key2['ok'] === true && empty($key2['created']), 'Never overwrite existing master key');

$finish = InstallWizardService::completeInstallation([
    'host' => $host,
    'port' => $port,
    'name' => $testDb,
    'user' => $user,
    'password' => $pass,
    'admin_email' => 'wizard-admin@example.test',
    'admin_password' => 'WizardPass99',
    'admin_password_confirm' => 'WizardPass99',
    'admin_name' => 'Wizard Admin',
    'master_key_path' => $masterPath,
    'invoices_path' => $invPath,
    'production_env' => false,
]);
ok(!empty($finish['ok']), 'Complete installation (config + lock + admin)');

ok(is_file($configPath), 'config.local.php written');
ok(is_file($lockPath), 'install.lock written');
ok(Install::hasInstallLock(), 'Install lock detected');
$configText = (string) file_get_contents($configPath);
ok(strpos($configText, $pass) !== false && strpos($configText, 'WizardPass') === false, 'Config stores DB password only — not admin password in file');
ok(strpos($configText, 'master.key') !== false, 'Config references master key path');

$email = (string) $pdoTest->query('SELECT email FROM admin_users ORDER BY id LIMIT 1')->fetchColumn();
$hash = (string) $pdoTest->query('SELECT password_hash FROM admin_users ORDER BY id LIMIT 1')->fetchColumn();
ok($email === AdminLoginSecurity::normalizeEmail('wizard-admin@example.test'), 'Admin email updated in disposable database');
ok(password_verify('WizardPass99', $hash), 'Admin password hash updated in disposable database');

ok(Install::isPublicInstallBlocked(), 'Live dev site with config+schema remains install-blocked (regression)');

$index = (string) file_get_contents(dirname(__DIR__) . '/install.php');
ok(strpos($index, 'InstallWizardService::completeInstallation') !== false, 'install.php uses wizard service');

@unlink($configPath);
@unlink($lockPath);
@unlink($runLock);
@unlink($masterPath);
InstallWizardService::$testConfigPath = null;
InstallWizardService::$testLockPath = null;
InstallWizardService::$testRunLockPath = null;
InstallWizardService::$testBypassInstallGuard = false;
InstallHostingPaths::$testHostingHomeOverride = null;

passthru(mysql_cmd('-e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$testDb}`")), $code);

echo $fail === 0 ? "All install wizard tests passed.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
