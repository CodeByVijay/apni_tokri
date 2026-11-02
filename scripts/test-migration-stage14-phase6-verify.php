<?php

declare(strict_types=1);

/**
 * Disposable-schema verification for 1.15.0_stage14_reports_phase6.sql (Phase B.1).
 * Creates apni_tokri_mig_verify_146, clones minimal structure is NOT done — requires existing apni_tokri DB.
 *
 * Safe approach: run forward migration idempotency checks on live schema + rollback/forward on disposable DB
 * when CREATE DATABASE privilege exists.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function skip(string $m): void
{
    echo "SKIP: {$m}\n";
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);

    return (int) $stmt->fetchColumn() > 0;
}

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $index]);

    return (int) $stmt->fetchColumn() > 0;
}

$migrationPath = dirname(__DIR__) . '/database/migrations/1.15.0_stage14_reports_phase6.sql';
$rollbackPath = dirname(__DIR__) . '/database/migrations/rollback/1.15.0_stage14_reports_phase6_rollback.sql';
ok(is_readable($migrationPath), 'Forward migration file readable');
ok(is_readable($rollbackPath), 'Rollback migration file readable');

ok(columnExists($pdo, 'payments', 'captured_at'), 'payments.captured_at present');
ok(columnExists($pdo, 'subscription_payments', 'captured_at'), 'subscription_payments.captured_at present');
ok(columnExists($pdo, 'payment_refunds', 'completed_at'), 'payment_refunds.completed_at present');

$nullable = $pdo->query(
    "SELECT IS_NULLABLE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'captured_at'"
)->fetchColumn();
ok((string) $nullable === 'YES', 'payments.captured_at nullable');

ok(indexExists($pdo, 'payments', 'idx_payments_captured_at'), 'idx_payments_captured_at');
ok(indexExists($pdo, 'subscription_payments', 'idx_spay_captured_at'), 'idx_spay_captured_at');
ok(indexExists($pdo, 'payment_refunds', 'idx_payment_refunds_completed_at'), 'idx_payment_refunds_completed_at');

$hist = $pdo->query(
    "SELECT COUNT(*) FROM payments WHERE status = 'captured' AND captured_at IS NULL"
)->fetchColumn();
ok($hist !== false, 'Historical captured rows query ok');
echo 'INFO: payments captured with NULL captured_at count=' . (int) $hist . "\n";

$ver = (string) ($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'")->fetchColumn() ?: '');
ok($ver === Install::SCHEMA_VERSION, 'schema_version matches Install::SCHEMA_VERSION (' . Install::SCHEMA_VERSION . ')');

// Idempotent re-apply on current database (should not error)
$sql = file_get_contents($migrationPath);
if ($sql !== false) {
    try {
        $pdo->exec($sql);
        ok(true, 'Forward migration re-apply (idempotent) on current DB');
        ok(columnExists($pdo, 'payments', 'captured_at'), 'Columns still present after re-apply');
    } catch (Throwable $e) {
        ok(false, 'Forward migration re-apply: ' . $e->getMessage());
    }
} else {
    ok(false, 'Could not read forward migration');
}

// Disposable DB rollback/forward cycle (structure-only clone; never touches production data rows)
$disposable = 'apni_tokri_mig_verify_146';
$cfg = require dirname(__DIR__) . '/includes/config.local.php';
$dbCfg = $cfg['database'] ?? [];
$mysqlBin = 'mysql';
$mysqldumpBin = 'mysqldump';
$host = (string) ($dbCfg['host'] ?? '127.0.0.1');
$user = (string) ($dbCfg['user'] ?? 'root');
$pass = (string) ($dbCfg['password'] ?? '');
$mainDb = (string) ($dbCfg['name'] ?? 'apni_tokri');

$auth = '-h' . escapeshellarg($host) . ' -u' . escapeshellarg($user);
if ($pass !== '') {
    $auth .= ' -p' . escapeshellarg($pass);
}

$canDisposable = false;
try {
    shell_exec($mysqlBin . ' ' . $auth . ' -e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$disposable}`; CREATE DATABASE `{$disposable}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;") . ' 2>/dev/null');
    $dump = $mysqldumpBin . ' ' . $auth . ' --no-data ' . escapeshellarg($mainDb)
        . ' payments subscription_payments payment_refunds settings 2>/dev/null | '
        . $mysqlBin . ' ' . $auth . ' ' . escapeshellarg($disposable) . ' 2>/dev/null';
    shell_exec($dump);
    $tblStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $tblStmt->execute([$disposable, 'payments']);
    $canDisposable = (int) $tblStmt->fetchColumn() > 0;
} catch (Throwable $e) {
    skip('Disposable DB cycle — ' . $e->getMessage());
}

if ($canDisposable) {
    $disAuth = $mysqlBin . ' ' . $auth . ' ' . escapeshellarg($disposable);
    $runSql = static function (string $file) use ($disAuth): bool {
        if (!is_readable($file)) {
            return false;
        }
        $cmd = $disAuth . ' < ' . escapeshellarg($file) . ' 2>/dev/null';

        return shell_exec($cmd) !== false;
    };
    $chkCol = static function (string $table, string $col) use ($disposable, $pdo): bool {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$disposable, $table, $col]);

        return (int) $stmt->fetchColumn() > 0;
    };

    shell_exec($disAuth . ' -e ' . escapeshellarg(
        "INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version','1.14.0-stage14-reports-5')
         ON DUPLICATE KEY UPDATE setting_value='1.14.0-stage14-reports-5'"
    ) . ' 2>/dev/null');

    ok($runSql($migrationPath), 'Disposable DB: forward migration applies on structure clone');
    ok($chkCol('payments', 'captured_at'), 'Disposable DB: captured_at present after forward');
    ok($runSql($rollbackPath), 'Disposable DB: rollback SQL executes');
    ok(!$chkCol('payments', 'captured_at'), 'Disposable DB: captured_at dropped after rollback');
    $verStmt = $pdo->prepare(
        'SELECT setting_value FROM `' . str_replace('`', '', $disposable) . '`.settings WHERE setting_key = ?'
    );
    $verStmt->execute(['schema_version']);
    ok((string) $verStmt->fetchColumn() === '1.14.0-stage14-reports-5', 'Disposable DB: schema_version after rollback');
    ok($runSql($migrationPath), 'Disposable DB: re-apply forward after rollback');
    ok($chkCol('payments', 'captured_at'), 'Disposable DB: columns restored after re-forward');

    shell_exec($mysqlBin . ' ' . $auth . ' -e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$disposable}`") . ' 2>/dev/null');
} else {
    skip('Disposable DB cycle — could not clone structure (privileges or mysqldump missing)');
}

echo $fail === 0 ? "\nPASS: Migration 1.15.0 verification.\n" : "\nFailures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
