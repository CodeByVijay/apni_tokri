<?php

declare(strict_types=1);

/**
 * Phase 3.2 — Tier A cleanup rehearsal on disposable clone ONLY.
 *
 * CLI:
 *   PHASE32_REHEARSAL=1 php scripts/phase3-2-tier-a-clone-rehearsal.php
 *
 * Requires clone DB name: apni_tokri_tier_a_rehearsal_<digits>
 * Never DELETE on configured database.name unless it matches rehearsal prefix.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$root = dirname(__DIR__);
if (getenv('PHASE32_REHEARSAL') !== '1') {
    fwrite(STDERR, "Set PHASE32_REHEARSAL=1 to run clone rehearsal.\n");
    exit(1);
}

$cfg = App::config()['database'];
$sourceDb = (string) ($cfg['name'] ?? 'apni_tokri');
$host = (string) ($cfg['host'] ?? '127.0.0.1');
$port = (int) ($cfg['port'] ?? 3306);
$user = (string) ($cfg['user'] ?? 'root');
$pass = (string) ($cfg['pass'] ?? $cfg['password'] ?? '');
$ts = date('YmdHis');
$cloneDb = getenv('PHASE32_CLONE_DB') ?: ('apni_tokri_tier_a_rehearsal_' . $ts);

if (!preg_match('/^apni_tokri_tier_a_rehearsal_[0-9]+$/', $cloneDb)) {
    fwrite(STDERR, "Refusing: clone DB must match apni_tokri_tier_a_rehearsal_<timestamp>\n");
    exit(1);
}
if ($cloneDb === $sourceDb) {
    fwrite(STDERR, "Refusing: clone equals source DSN database name.\n");
    exit(1);
}

$report = [
    'generated_at' => date('c'),
    'source_database' => $sourceDb,
    'clone_database' => $cloneDb,
    'app_config_database_unchanged' => $sourceDb,
    'steps' => [],
    'delete_log' => [],
    'before' => [],
    'after' => [],
    'protected_before' => [],
    'protected_after' => [],
    'integrity' => [],
    'tier_a_remaining' => [],
    'status' => 'REHEARSAL FAILED — DO NOT EXECUTE CLEANUP',
];

function mysql_cli(array $cfg, string $sqlOrDb, ?string $fileIn = null): int
{
    $host = (string) ($cfg['host'] ?? '127.0.0.1');
    $port = (int) ($cfg['port'] ?? 3306);
    $user = (string) ($cfg['user'] ?? 'root');
    $pass = (string) ($cfg['pass'] ?? $cfg['password'] ?? '');
    $cmd = sprintf(
        'mysql -h %s -P %d -u %s %s',
        escapeshellarg($host),
        $port,
        escapeshellarg($user),
        $pass !== '' ? '-p' . escapeshellarg($pass) : ''
    );
    if ($fileIn !== null) {
        $cmd .= ' ' . escapeshellarg($sqlOrDb) . ' < ' . escapeshellarg($fileIn);

        return shell_exec_cmd($cmd);
    }
    $cmd .= ' -e ' . escapeshellarg($sqlOrDb);

    return shell_exec_cmd($cmd);
}

function mysqldump_cli(array $cfg, string $db, string $outFile): int
{
    $host = (string) ($cfg['host'] ?? '127.0.0.1');
    $port = (int) ($cfg['port'] ?? 3306);
    $user = (string) ($cfg['user'] ?? 'root');
    $pass = (string) ($cfg['pass'] ?? $cfg['password'] ?? '');
    $cmd = sprintf(
        'mysqldump -h %s -P %d -u %s %s --single-transaction --routines --triggers %s > %s',
        escapeshellarg($host),
        $port,
        escapeshellarg($user),
        $pass !== '' ? '-p' . escapeshellarg($pass) : '',
        escapeshellarg($db),
        escapeshellarg($outFile)
    );

    return shell_exec_cmd($cmd);
}

function shell_exec_cmd(string $cmd): int
{
    passthru($cmd, $code);

    return (int) $code;
}

function pdo_db(array $cfg, string $dbName): PDO
{
    $host = (string) ($cfg['host'] ?? '127.0.0.1');
    $port = (int) ($cfg['port'] ?? 3306);
    $user = (string) ($cfg['user'] ?? 'root');
    $pass = (string) ($cfg['pass'] ?? $cfg['password'] ?? '');

    return new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbName),
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

/** @return array<string, int> */
function table_counts(PDO $pdo): array
{
    $tables = $pdo->query(
        "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $out = [];
    foreach ($tables as $t) {
        $out[$t] = (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $t) . '`')->fetchColumn();
    }

    return $out;
}

/** @return array<string, int|string> */
function marker_counts(PDO $pdo): array
{
    return [
        'c1_orders' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE order_number LIKE 'AT-C1FIX-%'")->fetchColumn(),
        'c1_refunds' => (int) $pdo->query("SELECT COUNT(*) FROM refund_requests WHERE request_number LIKE 'RF-C1%'")->fetchColumn(),
        'c1_subs' => (int) $pdo->query("SELECT COUNT(*) FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-C1FIX-%'")->fetchColumn(),
        'c1_customers' => (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE mobile LIKE '987C1%'")->fetchColumn(),
        'sub_pg' => (int) $pdo->query("SELECT COUNT(*) FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-PG-%'")->fetchColumn(),
        'sub_h' => (int) $pdo->query("SELECT COUNT(*) FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-H-%'")->fetchColumn(),
        'zt_sub' => (int) $pdo->query("SELECT COUNT(*) FROM customer_subscriptions WHERE subscription_number LIKE 'ZT-SUB-%'")->fetchColumn(),
        'at_acct' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE order_number LIKE 'AT-ACCT%'")->fetchColumn(),
        'at_product' => (int) $pdo->query("SELECT COUNT(*) FROM products WHERE slug LIKE 'at-%'")->fetchColumn(),
    ];
}

/** @return array<string, int> */
function protected_snapshot(PDO $pdo): array
{
    return [
        'payments_razorpay_cashfree' => (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE gateway IN ('razorpay','cashfree')")->fetchColumn(),
        'invoices' => (int) $pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn(),
        'invoice_sequences' => (int) $pdo->query('SELECT COUNT(*) FROM invoice_sequences')->fetchColumn(),
        'settings' => (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn(),
        'admin_users' => (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn(),
        'admin_audit_logs' => (int) $pdo->query('SELECT COUNT(*) FROM admin_audit_logs')->fetchColumn(),
        'plan_1' => (int) $pdo->query('SELECT COUNT(*) FROM subscription_plans WHERE id = 1')->fetchColumn(),
        'plan_2' => (int) $pdo->query('SELECT COUNT(*) FROM subscription_plans WHERE id = 2')->fetchColumn(),
        'plan_3' => (int) $pdo->query('SELECT COUNT(*) FROM subscription_plans WHERE id = 3')->fetchColumn(),
        'storefront_at_ymd' => (int) $pdo->query(
            "SELECT COUNT(*) FROM orders WHERE order_number REGEXP '^AT-[0-9]{6}-' AND order_number NOT LIKE 'AT-C1FIX-%' AND order_number NOT LIKE 'AT-ACCT%'"
        )->fetchColumn(),
        'products_total' => (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
    ];
}

/** @return array{deleted: int, label: string} */
function exec_delete(PDO $pdo, string $label, string $sql): array
{
    $n = $pdo->exec($sql);
    if ($n === false) {
        throw new RuntimeException("DELETE failed: {$label}");
    }

    return ['label' => $label, 'deleted' => (int) $n];
}

$backupDir = $root . '/storage/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}
$backupFile = $backupDir . '/phase3_2_source_' . $ts . '.sql';

$code = mysqldump_cli($cfg, $sourceDb, $backupFile);
$report['steps'][] = ['backup_mysqldump', $code === 0 ? 'ok' : 'fail', 'file' => $backupFile, 'bytes' => is_file($backupFile) ? filesize($backupFile) : 0];
if ($code !== 0 || !is_file($backupFile) || filesize($backupFile) < 1000) {
    fwrite(STDERR, "Backup failed.\n");
    file_put_contents($root . '/storage/phase3-2-rehearsal-report.json', json_encode($report, JSON_PRETTY_PRINT));
    exit(1);
}

mysql_cli($cfg, 'DROP DATABASE IF EXISTS `' . str_replace('`', '``', $cloneDb) . '`');
$code = mysql_cli($cfg, 'CREATE DATABASE `' . str_replace('`', '``', $cloneDb) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$report['steps'][] = ['create_clone_db', $code === 0 ? 'ok' : 'fail'];
if ($code !== 0) {
    exit(1);
}

$code = mysql_cli($cfg, $cloneDb, $backupFile);
$report['steps'][] = ['restore_clone', $code === 0 ? 'ok' : 'fail'];
if ($code !== 0) {
    exit(1);
}

$pdoSource = pdo_db($cfg, $sourceDb);
$pdo = pdo_db($cfg, $cloneDb);

$srcTables = table_counts($pdoSource);
$cloneTables = table_counts($pdo);
$report['before']['table_count'] = count($cloneTables);
$report['before']['markers'] = marker_counts($pdo);
$report['protected_before'] = protected_snapshot($pdo);

$tableMatch = count($srcTables) === count($cloneTables);
$report['steps'][] = ['clone_table_count_match', $tableMatch ? 'ok' : 'fail', 'source' => count($srcTables), 'clone' => count($cloneTables)];

$expected = [
    'c1_orders' => 14,
    'c1_refunds' => 14,
    'c1_subs' => 14,
    'c1_customers' => 28,
    'sub_pg' => 75,
    'sub_h' => 1,
    'zt_sub' => 1,
    'at_acct' => 2,
];
foreach ($expected as $k => $exp) {
    if (($report['before']['markers'][$k] ?? -1) !== $exp) {
        $report['steps'][] = ['precheck_marker_' . $k, 'fail', 'expected' => $exp, 'got' => $report['before']['markers'][$k] ?? null];
        fwrite(STDERR, "Marker precheck failed: {$k}\n");
        file_put_contents($root . '/storage/phase3-2-rehearsal-report.json', json_encode($report, JSON_PRETTY_PRINT));
        exit(1);
    }
}

// --- Tier A execution (clone only), excluding manual at-% product ---
try {
    $pdo->beginTransaction();

    $steps = [
        ['payment_refunds via RF-C1', "DELETE pr FROM payment_refunds pr INNER JOIN refund_requests rr ON rr.id = pr.refund_request_id WHERE rr.request_number LIKE 'RF-C1%'"],
        ['payment_refunds via C1 orders', "DELETE pr FROM payment_refunds pr INNER JOIN payments p ON p.id = pr.payment_id INNER JOIN orders o ON o.id = p.order_id WHERE o.order_number LIKE 'AT-C1FIX-%'"],
        ['refund_request_history RF-C1', "DELETE FROM refund_request_history WHERE refund_request_id IN (SELECT id FROM refund_requests WHERE request_number LIKE 'RF-C1%')"],
        ['refund_request_items RF-C1', "DELETE FROM refund_request_items WHERE refund_request_id IN (SELECT id FROM refund_requests WHERE request_number LIKE 'RF-C1%')"],
        ['refund_request_evidence RF-C1', "DELETE FROM refund_request_evidence WHERE refund_request_id IN (SELECT id FROM refund_requests WHERE request_number LIKE 'RF-C1%')"],
        ['refund_requests RF-C1', "DELETE FROM refund_requests WHERE request_number LIKE 'RF-C1%'"],
        ['payments pay_c1fix', "DELETE FROM payments WHERE idempotency_key LIKE 'pay_c1fix_%'"],
        ['payments C1 orders', "DELETE p FROM payments p INNER JOIN orders o ON o.id = p.order_id WHERE o.order_number LIKE 'AT-C1FIX-%'"],
        ['delivery_status_history C1+ACCT', "DELETE h FROM delivery_status_history h INNER JOIN deliveries d ON d.id = h.delivery_id INNER JOIN orders o ON o.id = d.order_id WHERE o.order_number LIKE 'AT-C1FIX-%' OR o.order_number LIKE 'AT-ACCT%'"],
        ['deliveries C1+ACCT', "DELETE d FROM deliveries d INNER JOIN orders o ON o.id = d.order_id WHERE o.order_number LIKE 'AT-C1FIX-%' OR o.order_number LIKE 'AT-ACCT%'"],
        ['delivery_slot_reservations tier A orders', "DELETE r FROM delivery_slot_reservations r INNER JOIN orders o ON o.id = r.order_id WHERE o.order_number LIKE 'AT-C1FIX-%' OR o.order_number LIKE 'AT-ACCT%'"],
        ['order_items C1+ACCT', "DELETE oi FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id WHERE o.order_number LIKE 'AT-C1FIX-%' OR o.order_number LIKE 'AT-ACCT%'"],
        ['order_status_history C1+ACCT', "DELETE h FROM order_status_history h INNER JOIN orders o ON o.id = h.order_id WHERE o.order_number LIKE 'AT-C1FIX-%' OR o.order_number LIKE 'AT-ACCT%'"],
        ['orders AT-C1FIX', "DELETE FROM orders WHERE order_number LIKE 'AT-C1FIX-%'"],
        ['orders AT-ACCT', "DELETE FROM orders WHERE order_number LIKE 'AT-ACCT%'"],
        ['deliveries SUB-H fulfilment', "DELETE d FROM deliveries d INNER JOIN orders o ON o.id = d.order_id INNER JOIN subscription_delivery_days sdd ON sdd.id = o.subscription_delivery_day_id INNER JOIN customer_subscriptions cs ON cs.id = sdd.customer_subscription_id WHERE cs.subscription_number LIKE 'SUB-H-%'"],
        ['orders SUB-H fulfilment', "DELETE o FROM orders o INNER JOIN subscription_delivery_days sdd ON sdd.id = o.subscription_delivery_day_id INNER JOIN customer_subscriptions cs ON cs.id = sdd.customer_subscription_id WHERE cs.subscription_number LIKE 'SUB-H-%'"],
        ['substitution_payments SUB-H', "DELETE sp FROM subscription_substitution_payments sp INNER JOIN subscription_basket_substitutions sbs ON sbs.id = sp.substitution_id INNER JOIN subscription_delivery_days sdd ON sdd.id = sbs.subscription_delivery_day_id INNER JOIN customer_subscriptions cs ON cs.id = sdd.customer_subscription_id WHERE cs.subscription_number LIKE 'SUB-H-%'"],
        ['subscription_basket_substitutions SUB-H', "DELETE sbs FROM subscription_basket_substitutions sbs INNER JOIN subscription_delivery_days sdd ON sdd.id = sbs.subscription_delivery_day_id INNER JOIN customer_subscriptions cs ON cs.id = sdd.customer_subscription_id WHERE cs.subscription_number LIKE 'SUB-H-%'"],
        ['subscription_stock_reservations SUB-H', "DELETE ssr FROM subscription_stock_reservations ssr INNER JOIN subscription_delivery_days sdd ON sdd.id = ssr.subscription_delivery_day_id INNER JOIN customer_subscriptions cs ON cs.id = sdd.customer_subscription_id WHERE cs.subscription_number LIKE 'SUB-H-%'"],
        ['subscription_fulfilment_events SUB-H', "DELETE sfe FROM subscription_fulfilment_events sfe INNER JOIN subscription_delivery_days sdd ON sdd.id = sfe.subscription_delivery_day_id INNER JOIN customer_subscriptions cs ON cs.id = sdd.customer_subscription_id WHERE cs.subscription_number LIKE 'SUB-H-%'"],
        ['subscription_payments C1', "DELETE FROM subscription_payments WHERE idempotency_key LIKE 'subpay_c1fix_%'"],
        ['customer_subscriptions SUB-C1FIX', "DELETE FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-C1FIX-%'"],
        ['customer_subscriptions SUB-PG', "DELETE FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-PG-%'"],
        ['customer_subscriptions SUB-H', "DELETE FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-H-%'"],
        ['customer_subscriptions ZT-SUB', "DELETE FROM customer_subscriptions WHERE subscription_number LIKE 'ZT-SUB-%'"],
        ['customers 987C1', "DELETE FROM customers WHERE mobile LIKE '987C1%'"],
    ];

    foreach ($steps as [$label, $sql]) {
        $report['delete_log'][] = exec_delete($pdo, $label, $sql);
    }

    $pdo->commit();
    $report['steps'][] = ['tier_a_transaction', 'committed'];
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $report['steps'][] = ['tier_a_transaction', 'rolled_back', 'error' => $e->getMessage()];
    file_put_contents($root . '/storage/phase3-2-rehearsal-report.json', json_encode($report, JSON_PRETTY_PRINT));
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$report['after']['markers'] = marker_counts($pdo);
$report['protected_after'] = protected_snapshot($pdo);
$report['tier_a_remaining'] = $report['after']['markers'];

// Orphan FK check (sample)
$orphOrders = (int) $pdo->query(
    'SELECT COUNT(*) FROM order_items oi LEFT JOIN orders o ON o.id = oi.order_id WHERE o.id IS NULL'
)->fetchColumn();
$report['integrity']['orphan_order_items'] = $orphOrders;

$protectedOk = $report['protected_before'] === $report['protected_after'];
$report['steps'][] = ['protected_unchanged', $protectedOk ? 'ok' : 'fail'];

$tierOk = true;
foreach (['c1_orders', 'c1_refunds', 'c1_subs', 'c1_customers', 'sub_pg', 'sub_h', 'zt_sub', 'at_acct'] as $k) {
    if (($report['after']['markers'][$k] ?? -1) !== 0) {
        $tierOk = false;
    }
}
// manual scope at_product unchanged count
if (($report['after']['markers']['at_product'] ?? 0) !== ($report['before']['markers']['at_product'] ?? 0)) {
    $tierOk = false;
}
$report['steps'][] = ['tier_a_auto_scopes_zero', $tierOk ? 'ok' : 'fail'];

$sourceMarkersAfter = marker_counts($pdoSource);
$report['source_unchanged_markers'] = $sourceMarkersAfter;
$sourceUnchanged = $sourceMarkersAfter === $report['before']['markers'];
$report['steps'][] = ['source_db_markers_unchanged', $sourceUnchanged ? 'ok' : 'fail'];

if ($protectedOk && $tierOk && $sourceUnchanged && $tableMatch) {
    $report['status'] = 'REHEARSAL PASSED — READY FOR EXPLICIT CLEANUP APPROVAL';
} else {
    $report['status'] = 'REHEARSAL FAILED — DO NOT EXECUTE CLEANUP';
}

file_put_contents($root . '/storage/phase3-2-rehearsal-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode(['status' => $report['status'], 'clone' => $cloneDb, 'backup' => $backupFile], JSON_PRETTY_PRINT) . "\n";
exit($report['status'] === 'REHEARSAL PASSED — READY FOR EXPLICIT CLEANUP APPROVAL' ? 0 : 1);
