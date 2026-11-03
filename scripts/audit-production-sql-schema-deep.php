<?php

declare(strict_types=1);

/**
 * Deep schema parity: consolidated SQL import vs migration-built DB.
 * SELECT / information_schema only on disposable databases.
 * CLI: php scripts/audit-production-sql-schema-deep.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/build-apni-tokri-production-sql.php';

$root = dirname(__DIR__);
$sqlFile = $root . '/database/production/apni_tokri_production.sql';
$db = App::config()['database'];
$host = (string) ($db['host'] ?? '127.0.0.1');
$port = (int) ($db['port'] ?? 3306);
$user = (string) ($db['user'] ?? 'root');
$pass = (string) ($db['pass'] ?? $db['password'] ?? '');

function mc(array $db, string $extra = ''): string
{
    $cmd = 'mysql -h ' . escapeshellarg((string) $db['host']) . ' -P ' . (int) ($db['port'] ?? 3306)
        . ' -u ' . escapeshellarg((string) ($db['user'] ?? 'root'));
    $p = (string) ($db['pass'] ?? $db['password'] ?? '');
    if ($p !== '') {
        $cmd .= ' -p' . escapeshellarg($p);
    }

    return $cmd . ($extra !== '' ? ' ' . $extra : '');
}

function deep_fingerprint(PDO $pdo): array
{
    $tables = $pdo->query(
        "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
         ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $autoIncrements = $pdo->query(
        "SELECT TABLE_NAME, AUTO_INCREMENT
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND AUTO_INCREMENT IS NOT NULL
         ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $columns = $pdo->query(
        "SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE,
                COLUMN_DEFAULT, COLUMN_KEY, EXTRA, COLLATION_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         ORDER BY TABLE_NAME, ORDINAL_POSITION"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stats = $pdo->query(
        "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, INDEX_TYPE
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
         ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $fks = $pdo->query(
        "SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
         ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $checks = $pdo->query(
        "SELECT tc.CONSTRAINT_NAME, tc.TABLE_NAME, cc.CHECK_CLAUSE
         FROM information_schema.TABLE_CONSTRAINTS tc
         JOIN information_schema.CHECK_CONSTRAINTS cc
           ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
         WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.CONSTRAINT_TYPE = 'CHECK'
         ORDER BY tc.TABLE_NAME, tc.CONSTRAINT_NAME"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $triggers = $pdo->query(
        "SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING
         FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $views = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $routines = $pdo->query(
        "SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES
         WHERE ROUTINE_SCHEMA = DATABASE() ORDER BY ROUTINE_NAME"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return [
        'tables_hash' => hash('sha256', json_encode($tables, JSON_THROW_ON_ERROR)),
        'auto_increment_hash' => hash('sha256', json_encode($autoIncrements, JSON_THROW_ON_ERROR)),
        'columns_hash' => hash('sha256', json_encode($columns, JSON_THROW_ON_ERROR)),
        'statistics_hash' => hash('sha256', json_encode($stats, JSON_THROW_ON_ERROR)),
        'fk_hash' => hash('sha256', json_encode($fks, JSON_THROW_ON_ERROR)),
        'check_constraints_hash' => hash('sha256', json_encode($checks, JSON_THROW_ON_ERROR)),
        'table_count' => count($tables),
        'index_stat_rows' => count($stats),
        'fk_rows' => count($fks),
        'check_constraint_rows' => count($checks),
        'trigger_count' => count($triggers),
        'view_count' => count($views),
        'routine_count' => count($routines),
        'triggers' => $triggers,
        'views' => $views,
        'routines' => $routines,
        'engines' => array_count_values(array_column($tables, 'ENGINE')),
        'collations' => array_count_values(array_column($tables, 'TABLE_COLLATION')),
    ];
}

$ts = date('YmdHis');
$dbCons = 'apni_tokri_deep_cons_' . $ts;
$dbIncr = 'apni_tokri_deep_incr_' . $ts;
$fail = 0;

foreach ([$dbCons, $dbIncr] as $name) {
    passthru(mc($db, '-e ' . escapeshellarg("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")), $c);
    if ($c !== 0) {
        exit(1);
    }
}

passthru(mc($db, escapeshellarg($dbCons)) . ' < ' . escapeshellarg($sqlFile), $c);
if ($c !== 0) {
    fwrite(STDERR, "Consolidated import failed\n");
    exit(1);
}

$use = mc($db, escapeshellarg($dbIncr));
foreach (production_migration_sequence() as $rel) {
    passthru($use . ' < ' . escapeshellarg($root . '/' . $rel), $c);
    if ($c !== 0) {
        exit(1);
    }
}

$pdoCons = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbCons), $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdoIncr = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbIncr), $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$fpC = deep_fingerprint($pdoCons);
$fpI = deep_fingerprint($pdoIncr);

$checks = [
    'tables_hash' => $fpC['tables_hash'] === $fpI['tables_hash'],
    // Seed INSERTs with explicit ids shift AUTO_INCREMENT; informational only.
    'auto_increment_hash' => ($fpC['auto_increment_hash'] ?? '') === ($fpI['auto_increment_hash'] ?? ''),
    'columns_hash' => $fpC['columns_hash'] === $fpI['columns_hash'],
    'statistics_hash' => $fpC['statistics_hash'] === $fpI['statistics_hash'],
    'fk_hash' => $fpC['fk_hash'] === $fpI['fk_hash'],
    'check_constraints_hash' => $fpC['check_constraints_hash'] === $fpI['check_constraints_hash'],
    'trigger_count' => $fpC['trigger_count'] === $fpI['trigger_count'],
    'view_count' => $fpC['view_count'] === $fpI['view_count'],
    'routine_count' => $fpC['routine_count'] === $fpI['routine_count'],
];

echo json_encode([
    'generated_at' => date('c'),
    'consolidated_db' => $dbCons,
    'checks' => $checks,
    'consolidated' => $fpC,
    'all_pass' => !in_array(false, array_diff_key($checks, ['auto_increment_hash' => true]), true),
    'notes' => [
        'auto_increment_hash' => 'May differ when mysqldump replays explicit INSERT ids; not a structural schema drift.',
    ],
], JSON_PRETTY_PRINT) . "\n";

foreach ([$dbCons, $dbIncr] as $name) {
    passthru(mc($db, '-e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$name}`")), $c);
}

$hardFail = !in_array(false, array_diff_key($checks, ['auto_increment_hash' => true]), true);
exit($hardFail ? 0 : 1);
