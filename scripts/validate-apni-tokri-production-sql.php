<?php

declare(strict_types=1);

/**
 * Validate consolidated production SQL vs incremental migration path.
 * CLI: php scripts/validate-apni-tokri-production-sql.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/build-apni-tokri-production-sql.php'; // functions only unless executed as main

$root = dirname(__DIR__);
$sqlFile = $root . '/database/production/apni_tokri_production.sql';
if (!is_readable($sqlFile)) {
    fwrite(STDERR, "Missing {$sqlFile} — run scripts/build-apni-tokri-production-sql.php first.\n");
    exit(1);
}

$db = App::config()['database'];
$host = (string) ($db['host'] ?? '127.0.0.1');
$port = (int) ($db['port'] ?? 3306);
$user = (string) ($db['user'] ?? 'root');
$pass = (string) ($db['pass'] ?? $db['password'] ?? '');

function mysql_cmd(array $db, string $extra = ''): string
{
    $host = (string) ($db['host'] ?? '127.0.0.1');
    $port = (int) ($db['port'] ?? 3306);
    $user = (string) ($db['user'] ?? 'root');
    $pass = (string) ($db['pass'] ?? $db['password'] ?? '');
    $cmd = 'mysql -h ' . escapeshellarg($host) . ' -P ' . $port . ' -u ' . escapeshellarg($user);
    if ($pass !== '') {
        $cmd .= ' -p' . escapeshellarg($pass);
    }

    return $cmd . ($extra !== '' ? ' ' . $extra : '');
}

function schema_fingerprint(PDO $pdo): array
{
    $tables = $pdo->query(
        "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name"
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $fp = ['tables' => $tables, 'table_count' => count($tables)];
    $indexes = 0;
    $fks = 0;
    foreach ($tables as $t) {
        $stmt = $pdo->query(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = '
            . $pdo->quote($t)
        );
        $indexes += (int) $stmt->fetchColumn();
        $fkStmt = $pdo->query(
            'SELECT COUNT(*) FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND table_name = '
            . $pdo->quote($t) . " AND referenced_table_name IS NOT NULL"
        );
        $fks += (int) $fkStmt->fetchColumn();
    }
    $fp['index_entries'] = $indexes;
    $fp['fk_entries'] = $fks;

    $cols = $pdo->query(
        'SELECT table_name, column_name, column_type, is_nullable, column_key, extra
         FROM information_schema.columns WHERE table_schema = DATABASE()
         ORDER BY table_name, ordinal_position'
    )->fetchAll(PDO::FETCH_ASSOC);
    $fp['columns_hash'] = hash('sha256', json_encode($cols, JSON_THROW_ON_ERROR));

    return $fp;
}

$fail = 0;
$ts = date('YmdHis');
$dbConsolidated = 'apni_tokri_validate_cons_' . $ts;
$dbIncremental = 'apni_tokri_validate_incr_' . $ts;

foreach ([$dbConsolidated => 'consolidated', $dbIncremental => 'incremental'] as $dbName => $label) {
    $create = mysql_cmd($db, '-e ' . escapeshellarg(
        "CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    ));
    passthru($create, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Could not create {$dbName}\n");
        exit(1);
    }
}

echo "Import consolidated SQL into {$dbConsolidated}...\n";
$importCons = mysql_cmd($db, escapeshellarg($dbConsolidated)) . ' < ' . escapeshellarg($sqlFile);
passthru($importCons, $code);
if ($code !== 0) {
    fwrite(STDERR, "Consolidated import failed.\n");
    exit(1);
}

echo "Apply incremental migrations into {$dbIncremental}...\n";
$useDb = mysql_cmd($db, escapeshellarg($dbIncremental));
foreach (production_migration_sequence() as $rel) {
    $path = $root . '/' . $rel;
    passthru($useDb . ' < ' . escapeshellarg($path), $code);
    if ($code !== 0) {
        fwrite(STDERR, "Incremental path failed at {$rel}\n");
        exit(1);
    }
}

$pdoCons = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbConsolidated),
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdoIncr = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbIncremental),
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$fpCons = schema_fingerprint($pdoCons);
$fpIncr = schema_fingerprint($pdoIncr);

$checks = [
    'table_count' => $fpCons['table_count'] === $fpIncr['table_count'],
    'columns_hash' => $fpCons['columns_hash'] === $fpIncr['columns_hash'],
    'index_entries' => $fpCons['index_entries'] === $fpIncr['index_entries'],
    'fk_entries' => $fpCons['fk_entries'] === $fpIncr['fk_entries'],
];
foreach ($checks as $name => $ok) {
    echo ($ok ? 'OK' : 'FAIL') . ": schema compare {$name}\n";
    if (!$ok) {
        $fail++;
    }
}

$ver = $pdoCons->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version' LIMIT 1")->fetchColumn();
$verOk = $ver === Install::SCHEMA_VERSION;
echo ($verOk ? 'OK' : 'FAIL') . ': schema_version is ' . Install::SCHEMA_VERSION . " (got {$ver})\n";
if (!$verOk) {
    $fail++;
}

$required = [
    'settings', 'admin_users', 'customers', 'products', 'orders',
    'seo_redirects', 'faq_categories', 'faqs', 'subscription_plans',
    'customer_subscriptions', 'subscription_payments', 'invoices',
];
foreach ($required as $table) {
    $exists = (int) $pdoCons->query(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '
        . $pdoCons->quote($table)
    )->fetchColumn() === 1;
    echo ($exists ? 'OK' : 'FAIL') . ": table exists {$table}\n";
    if (!$exists) {
        $fail++;
    }
}

$badCounts = [
    'customers' => 0,
    'orders' => 0,
    'payments' => 0,
    'customer_subscriptions' => 0,
    'subscription_payments' => 0,
    'invoices' => 0,
    'product_reviews' => 0,
    'testimonials' => 0,
    'support_tickets' => 0,
];
foreach (['sms.startmessaging.variable_app_name', 'sms.startmessaging.variable_expiry'] as $smsKey) {
    foreach ([$pdoCons, $pdoIncr] as $idx => $pdo) {
        $label = $idx === 0 ? 'consolidated' : 'incremental';
        $val = $pdo->query(
            'SELECT setting_value FROM settings WHERE setting_key = ' . $pdo->quote($smsKey) . ' LIMIT 1'
        )->fetchColumn();
        $ok = $val !== false && $val !== '';
        echo ($ok ? 'OK' : 'FAIL') . ": StartMessaging setting {$smsKey} on {$label}\n";
        if (!$ok) {
            $fail++;
        }
    }
}

$apiKeyRows = (int) $pdoCons->query(
    "SELECT COUNT(*) FROM settings WHERE setting_key = 'sms.api_key' AND setting_value != '' AND is_encrypted = 0"
)->fetchColumn();
echo ($apiKeyRows === 0 ? 'OK' : 'FAIL') . ": no plaintext sms.api_key in consolidated seed\n";
if ($apiKeyRows !== 0) {
    $fail++;
}

$smSenderOk = SmsSettingsService::validateSaveFields(
    true,
    SmsSettingsService::PROVIDER_STARTMESSAGING,
    StartMessagingSmsService::DEFAULT_BASE_URL,
    '',
    ''
) === [];
echo ($smSenderOk ? 'OK' : 'FAIL') . ": StartMessaging save validation allows empty Sender ID\n";
if (!$smSenderOk) {
    $fail++;
}

foreach ($badCounts as $table => $expect) {
    try {
        $c = (int) $pdoCons->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn();
        $ok = $c === $expect;
        echo ($ok ? 'OK' : 'FAIL') . ": {$table} row count {$c} (expected {$expect})\n";
        if (!$ok) {
            $fail++;
        }
    } catch (Throwable $e) {
        echo "SKIP: {$table} — " . $e->getMessage() . "\n";
    }
}

foreach ([$dbConsolidated, $dbIncremental] as $dbName) {
    passthru(mysql_cmd($db, '-e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$dbName}`")), $code);
}

echo "\nSummary:\n";
echo '  tables: ' . $fpCons['table_count'] . "\n";
echo '  index entries (information_schema.statistics): ' . $fpCons['index_entries'] . "\n";
echo '  FK entries (information_schema.key_column_usage): ' . $fpCons['fk_entries'] . "\n";

if ($fail === 0) {
    echo "PASS\n";
    exit(0);
}
echo "FAIL ({$fail})\n";
exit(1);
