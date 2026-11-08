<?php

declare(strict_types=1);

/**
 * Targeted cleanup: customer IDs 2 and 3 only. Customer ID 1 (Vijay Amule) is protected.
 *
 * PRODUCTION: set config.local.php to manconne_apnitokri_live, then:
 *   php scripts/execute-production-customer-cleanup-ids-2-3.php --dry-run
 *   php scripts/execute-production-customer-cleanup-ids-2-3.php --execute
 *
 * Requires mysqldump on PATH (XAMPP / Linux).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

/**
 * Optional one-off DSN (no config.local.php edit): APNI_DB_HOST, APNI_DB_PORT, APNI_DB_NAME,
 * APNI_DB_USER, APNI_DB_PASSWORD — used when APNI_DB_NAME is set.
 */
function cleanup_connect_pdo(): PDO
{
    $dbNameOverride = getenv('APNI_DB_NAME');
    if ($dbNameOverride === false || $dbNameOverride === '') {
        Install::ensureConnection();

        return Database::pdo();
    }
    Database::disconnect();
    $cfg = App::config()['database'];
    $cfg['name'] = $dbNameOverride;
    $host = getenv('APNI_DB_HOST');
    if ($host !== false && $host !== '') {
        $cfg['host'] = $host;
    }
    $port = getenv('APNI_DB_PORT');
    if ($port !== false && $port !== '') {
        $cfg['port'] = (int) $port;
    }
    $user = getenv('APNI_DB_USER');
    if ($user !== false && $user !== '') {
        $cfg['user'] = $user;
    }
    $pass = getenv('APNI_DB_PASSWORD');
    if ($pass !== false) {
        $cfg['password'] = $pass;
    }

    return Database::connect($cfg);
}

const PROTECTED_CUSTOMER_IDS = [1];
const TARGET_CUSTOMER_IDS = [2, 3];
const PROTECTED_MOBILE_DIGITS = '9407005023';
const PROTECTED_EMAIL = 'vijayamule7@gmail.com';
const PROTECTED_NAME = 'Vijay Amule';

$execute = in_array('--execute', $argv ?? [], true);
$dryRun = !$execute;
$allowMissingTargets = in_array('--allow-missing-targets', $argv ?? [], true);

if (getenv('APNI_DB_NAME') === false || getenv('APNI_DB_NAME') === '') {
    if (!Install::canConnect()) {
        fwrite(STDERR, "Database not connected. Set config.local.php or APNI_DB_* env.\n");
        exit(1);
    }
}
$pdo = cleanup_connect_pdo();

$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$expectedDb = getenv('APNI_EXPECTED_DB') ?: 'manconne_apnitokri_live';
if ($dbName !== $expectedDb) {
    fwrite(STDERR, "SAFETY: connected database is '{$dbName}', expected '{$expectedDb}'. Set APNI_EXPECTED_DB to override or fix config.local.php.\n");
    exit(1);
}

/** @return int */
function exec_count(PDO $pdo, string $sql, array $params = []): int
{
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return (int) $st->fetchColumn();
}

/** @return int */
function exec_delete(PDO $pdo, string $sql, array $params = []): int
{
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->rowCount();
}

function table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $st->execute([$table]);

    return (int) $st->fetchColumn() > 0;
}

/** @return list<int> */
function order_ids_for_customer(PDO $pdo, int $customerId): array
{
    $st = $pdo->prepare('SELECT id FROM orders WHERE customer_id = ?');
    $st->execute([$customerId]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

    return array_map('intval', $ids);
}

/** @return array{live_captured:int,invoices:int,simulate_captured:int,refund_requests:int} */
function financial_snapshot(PDO $pdo, int $customerId): array
{
    $liveCaptured = exec_count(
        $pdo,
        "SELECT COUNT(*) FROM payments p
         INNER JOIN orders o ON o.id = p.order_id
         WHERE o.customer_id = ? AND p.status = 'captured'
           AND p.gateway IN ('razorpay','cashfree')",
        [$customerId]
    );
    $simulateCaptured = exec_count(
        $pdo,
        "SELECT COUNT(*) FROM payments p
         INNER JOIN orders o ON o.id = p.order_id
         WHERE o.customer_id = ? AND p.status = 'captured' AND p.gateway = 'simulate'",
        [$customerId]
    );
    $invoices = 0;
    if (table_exists($pdo, 'invoices')) {
        $invoices = exec_count($pdo, 'SELECT COUNT(*) FROM invoices WHERE customer_id = ?', [$customerId]);
    }
    $refunds = exec_count($pdo, 'SELECT COUNT(*) FROM refund_requests WHERE customer_id = ?', [$customerId]);

    return [
        'live_captured' => $liveCaptured,
        'simulate_captured' => $simulateCaptured,
        'invoices' => $invoices,
        'refund_requests' => $refunds,
    ];
}

function must_preserve_finance(array $snap): bool
{
    return $snap['live_captured'] > 0 || $snap['invoices'] > 0 || $snap['refund_requests'] > 0;
}

/**
 * @param array<string, int> $counts
 * @param list<int> $orderIds
 */
function delete_order_subgraph(PDO $pdo, array $orderIds, array &$counts, bool $dryRun): void
{
    if ($orderIds === []) {
        return;
    }
    $in = implode(',', array_map('intval', $orderIds));
    $steps = [];
    if (table_exists($pdo, 'delivery_email_logs')) {
        $steps[] = ["delivery_email_logs", "DELETE FROM delivery_email_logs WHERE order_id IN ($in)"];
    }
    if (table_exists($pdo, 'delivery_sms_logs')) {
        $steps[] = ['delivery_sms_logs', "DELETE FROM delivery_sms_logs WHERE order_id IN ($in)"];
    }
    if (table_exists($pdo, 'payment_completion_exceptions')) {
        $steps[] = ['payment_completion_exceptions', "DELETE FROM payment_completion_exceptions WHERE order_id IN ($in)"];
    }
    $steps[] = [
        'delivery_status_history',
        "DELETE h FROM delivery_status_history h
         INNER JOIN deliveries d ON d.id = h.delivery_id WHERE d.order_id IN ($in)",
    ];
    $steps[] = ['delivery_slot_reservations', "DELETE FROM delivery_slot_reservations WHERE order_id IN ($in)"];
    $steps[] = ['deliveries', "DELETE FROM deliveries WHERE order_id IN ($in)"];
    if (table_exists($pdo, 'order_cancellation_events')) {
        $steps[] = ['order_cancellation_events', "DELETE FROM order_cancellation_events WHERE order_id IN ($in)"];
    }
    if (table_exists($pdo, 'order_stock_restock_events')) {
        $steps[] = ['order_stock_restock_events', "DELETE FROM order_stock_restock_events WHERE order_id IN ($in)"];
    }
    $steps[] = ['order_status_history', "DELETE FROM order_status_history WHERE order_id IN ($in)"];
    $steps[] = [
        'payment_refunds_rr',
        "DELETE pr FROM payment_refunds pr
         INNER JOIN refund_requests rr ON rr.id = pr.refund_request_id WHERE rr.order_id IN ($in)",
    ];
    $steps[] = [
        'payment_refunds_pay',
        "DELETE pr FROM payment_refunds pr
         INNER JOIN payments p ON p.id = pr.payment_id WHERE p.order_id IN ($in)",
    ];
    if (table_exists($pdo, 'refund_request_history')) {
        $steps[] = [
            'refund_request_history',
            "DELETE h FROM refund_request_history h
             INNER JOIN refund_requests rr ON rr.id = h.refund_request_id WHERE rr.order_id IN ($in)",
        ];
    }
    if (table_exists($pdo, 'refund_request_items')) {
        $steps[] = [
            'refund_request_items',
            "DELETE ri FROM refund_request_items ri
             INNER JOIN refund_requests rr ON rr.id = ri.refund_request_id WHERE rr.order_id IN ($in)",
        ];
    }
    if (table_exists($pdo, 'refund_request_evidence')) {
        $steps[] = [
            'refund_request_evidence',
            "DELETE e FROM refund_request_evidence e
             INNER JOIN refund_requests rr ON rr.id = e.refund_request_id WHERE rr.order_id IN ($in)",
        ];
    }
    $steps[] = ['refund_requests', "DELETE FROM refund_requests WHERE order_id IN ($in)"];
    $steps[] = ['payments', "DELETE FROM payments WHERE order_id IN ($in)"];
    if (table_exists($pdo, 'invoices')) {
        $steps[] = ['invoices', "DELETE FROM invoices WHERE order_id IN ($in)"];
    }
    if (table_exists($pdo, 'coupon_usages')) {
        $steps[] = ['coupon_usages', "DELETE FROM coupon_usages WHERE order_id IN ($in)"];
    }
    $steps[] = ['order_items', "DELETE FROM order_items WHERE order_id IN ($in)"];
    $steps[] = ['orders', "DELETE FROM orders WHERE id IN ($in)"];

    foreach ($steps as [$label, $sql]) {
        $counts[$label] = ($counts[$label] ?? 0) + exec_delete($pdo, $sql);
    }
}

/**
 * @param array<string, int> $counts
 */
function delete_subscription_subgraph_for_customer(PDO $pdo, int $customerId, array &$counts, bool $dryRun): void
{
    $st = $pdo->prepare('SELECT id FROM customer_subscriptions WHERE customer_id = ?');
    $st->execute([$customerId]);
    $subIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($subIds === []) {
        return;
    }
    $in = implode(',', $subIds);

    $steps = [
        ['subscription_substitution_payments', "DELETE sp FROM subscription_substitution_payments sp
            INNER JOIN subscription_basket_substitutions sbs ON sbs.id = sp.substitution_id
            INNER JOIN subscription_delivery_days sdd ON sdd.id = sbs.subscription_delivery_day_id
            WHERE sdd.customer_subscription_id IN ($in)"],
        ['subscription_basket_substitutions', "DELETE sbs FROM subscription_basket_substitutions sbs
            INNER JOIN subscription_delivery_days sdd ON sdd.id = sbs.subscription_delivery_day_id
            WHERE sdd.customer_subscription_id IN ($in)"],
        ['subscription_stock_reservations', "DELETE FROM subscription_stock_reservations
            WHERE subscription_delivery_day_id IN (SELECT id FROM subscription_delivery_days WHERE customer_subscription_id IN ($in))"],
        ['subscription_fulfilment_events', "DELETE FROM subscription_fulfilment_events
            WHERE subscription_delivery_day_id IN (SELECT id FROM subscription_delivery_days WHERE customer_subscription_id IN ($in))"],
        ['deliveries_sub_orders', "DELETE d FROM deliveries d INNER JOIN orders o ON o.id = d.order_id
            WHERE o.subscription_delivery_day_id IN (SELECT id FROM subscription_delivery_days WHERE customer_subscription_id IN ($in))"],
        ['orders_sub_fulfil', "DELETE FROM orders WHERE subscription_delivery_day_id IN
            (SELECT id FROM subscription_delivery_days WHERE customer_subscription_id IN ($in))"],
        ['subscription_payments', "DELETE FROM subscription_payments WHERE customer_subscription_id IN ($in)"],
        ['customer_subscriptions', "DELETE FROM customer_subscriptions WHERE id IN ($in)"],
    ];

    foreach ($steps as [$label, $sql]) {
        try {
            $counts[$label] = ($counts[$label] ?? 0) + exec_delete($pdo, $sql);
        } catch (Throwable $e) {
            throw new RuntimeException($label . ': ' . $e->getMessage(), 0, $e);
        }
    }
}

/**
 * @param array<string, int> $counts
 */
function delete_peripheral_customer_data(PDO $pdo, int $customerId, ?string $mobile, array &$counts, bool $dryRun): void
{
    $peripheral = [
        'customer_addresses' => 'DELETE FROM customer_addresses WHERE customer_id = ?',
        'customer_coupon_assignments' => 'DELETE FROM customer_coupon_assignments WHERE customer_id = ?',
        'customer_notifications' => 'DELETE FROM customer_notifications WHERE customer_id = ?',
        'offer_customer_assignments' => 'DELETE FROM offer_customer_assignments WHERE customer_id = ?',
        'subscription_customer_preferences' => 'DELETE FROM subscription_customer_preferences WHERE customer_id = ?',
    ];
    foreach ($peripheral as $label => $sql) {
        $table = str_replace('DELETE FROM ', '', explode(' ', $sql)[0]);
        if (!table_exists($pdo, $table)) {
            continue;
        }
        $counts[$label] = exec_delete($pdo, $sql, [$customerId]);
    }
    if (table_exists($pdo, 'carts')) {
        $cartIds = $pdo->prepare('SELECT id FROM carts WHERE customer_id = ?');
        $cartIds->execute([$customerId]);
        $cids = array_map('intval', $cartIds->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($cids !== [] && table_exists($pdo, 'cart_items')) {
            $cin = implode(',', $cids);
            $counts['cart_items'] = exec_delete($pdo, "DELETE FROM cart_items WHERE cart_id IN ($cin)");
        }
        $counts['carts'] = exec_delete($pdo, 'DELETE FROM carts WHERE customer_id = ?', [$customerId]);
    }
    if ($mobile !== null && $mobile !== '' && table_exists($pdo, 'customer_otp_attempts')) {
        $counts['customer_otp_attempts'] = exec_delete($pdo, 'DELETE FROM customer_otp_attempts WHERE mobile = ?', [$mobile]);
    }
}

/**
 * @param array<string, int> $counts
 */
function full_customer_removal(PDO $pdo, int $customerId, array &$counts, bool $dryRun): void
{
    if (in_array($customerId, PROTECTED_CUSTOMER_IDS, true)) {
        throw new RuntimeException('Refusing to remove protected customer ' . $customerId);
    }
    $st = $pdo->prepare('SELECT mobile FROM customers WHERE id = ?');
    $st->execute([$customerId]);
    $mobile = (string) ($st->fetchColumn() ?: '');

    delete_subscription_subgraph_for_customer($pdo, $customerId, $counts, $dryRun);
    $orderIds = order_ids_for_customer($pdo, $customerId);
    delete_order_subgraph($pdo, $orderIds, $counts, $dryRun);

    if (table_exists($pdo, 'product_reviews')) {
        if (table_exists($pdo, 'review_moderation_history')) {
            $counts['review_moderation_history'] = exec_delete(
                $pdo,
                'DELETE h FROM review_moderation_history h
                 INNER JOIN product_reviews r ON r.id = h.review_id WHERE r.customer_id = ?',
                [$customerId]
            );
        }
        if (table_exists($pdo, 'product_review_photos')) {
            $counts['product_review_photos'] = exec_delete(
                $pdo,
                'DELETE p FROM product_review_photos p
                 INNER JOIN product_reviews r ON r.id = p.review_id WHERE r.customer_id = ?',
                [$customerId]
            );
        }
        $counts['product_reviews'] = exec_delete($pdo, 'DELETE FROM product_reviews WHERE customer_id = ?', [$customerId]);
    }

    if (table_exists($pdo, 'support_tickets')) {
        $ticketIds = $pdo->prepare('SELECT id FROM support_tickets WHERE customer_id = ?');
        $ticketIds->execute([$customerId]);
        $tids = array_map('intval', $ticketIds->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($tids !== []) {
            $tin = implode(',', $tids);
            if (table_exists($pdo, 'support_message_attachments')) {
                $counts['support_message_attachments'] = exec_delete(
                    $pdo,
                    "DELETE a FROM support_message_attachments a
                     INNER JOIN support_messages m ON m.id = a.message_id WHERE m.ticket_id IN ($tin)"
                );
            }
            $counts['support_messages'] = exec_delete($pdo, "DELETE FROM support_messages WHERE ticket_id IN ($tin)");
            $counts['support_tickets'] = exec_delete($pdo, "DELETE FROM support_tickets WHERE id IN ($tin)");
        }
    }

    delete_peripheral_customer_data($pdo, $customerId, $mobile, $counts, $dryRun);

    $counts['customers'] = exec_delete($pdo, 'DELETE FROM customers WHERE id = ?', [$customerId]);
}

/**
 * @param array<string, int> $counts
 */
function anonymize_preserve_finance(PDO $pdo, int $customerId, array &$counts, bool $dryRun): void
{
    if (in_array($customerId, PROTECTED_CUSTOMER_IDS, true)) {
        throw new RuntimeException('Refusing to anonymize protected customer ' . $customerId);
    }
    $st = $pdo->prepare('SELECT mobile FROM customers WHERE id = ?');
    $st->execute([$customerId]);
    $mobile = (string) ($st->fetchColumn() ?: '');

    // Remove login/PII surface area; keep orders, payments, invoices, refund_requests.
    delete_peripheral_customer_data($pdo, $customerId, $mobile, $counts, $dryRun);

    if (table_exists($pdo, 'product_reviews')) {
        if (table_exists($pdo, 'review_moderation_history')) {
            $counts['review_moderation_history'] = exec_delete(
                $pdo,
                'DELETE h FROM review_moderation_history h
                 INNER JOIN product_reviews r ON r.id = h.review_id WHERE r.customer_id = ?',
                [$customerId]
            );
        }
        if (table_exists($pdo, 'product_review_photos')) {
            $counts['product_review_photos'] = exec_delete(
                $pdo,
                'DELETE p FROM product_review_photos p
                 INNER JOIN product_reviews r ON r.id = p.review_id WHERE r.customer_id = ?',
                [$customerId]
            );
        }
        $counts['product_reviews'] = exec_delete($pdo, 'DELETE FROM product_reviews WHERE customer_id = ?', [$customerId]);
    }

    if (table_exists($pdo, 'support_tickets')) {
        $ticketIds = $pdo->prepare('SELECT id FROM support_tickets WHERE customer_id = ?');
        $ticketIds->execute([$customerId]);
        $tids = array_map('intval', $ticketIds->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($tids !== []) {
            $tin = implode(',', $tids);
            if (table_exists($pdo, 'support_message_attachments')) {
                $counts['support_message_attachments'] = exec_delete(
                    $pdo,
                    "DELETE a FROM support_message_attachments a
                     INNER JOIN support_messages m ON m.id = a.message_id WHERE m.ticket_id IN ($tin)"
                );
            }
            $counts['support_messages'] = exec_delete($pdo, "DELETE FROM support_messages WHERE ticket_id IN ($tin)");
            $counts['support_tickets'] = exec_delete($pdo, "DELETE FROM support_tickets WHERE id IN ($tin)");
        }
    }

    $newMobile = 'DEL' . $customerId . 'T' . time();
    $counts['customers_anonymized'] = exec_delete(
        $pdo,
        'UPDATE customers SET mobile = ?, name = ?, email = NULL, is_active = 0 WHERE id = ?',
        [$newMobile, 'Removed account (data retained)', $customerId]
    );
}

function verify_protected(PDO $pdo): array
{
    $row = snapshot_customer($pdo, 1);
    if ($row === []) {
        return ['row' => [], 'ok' => false, 'reason' => 'customer_id_1_missing'];
    }
    $digits = preg_replace('/\D+/', '', (string) ($row['mobile'] ?? ''));
    $ok = $digits === PROTECTED_MOBILE_DIGITS
        && strtolower(trim((string) ($row['email'] ?? ''))) === strtolower(PROTECTED_EMAIL)
        && (string) ($row['name'] ?? '') === PROTECTED_NAME;

    return ['row' => $row, 'ok' => $ok, 'reason' => $ok ? 'ok' : 'customer_id_1_mismatch'];
}

function snapshot_customer(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT id, mobile, name, email FROM customers WHERE id = ?');
    $st->execute([$id]);

    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

function snapshot_protected_counts(PDO $pdo, int $id): array
{
    return [
        'orders' => exec_count($pdo, 'SELECT COUNT(*) FROM orders WHERE customer_id = ?', [$id]),
        'payments' => exec_count(
            $pdo,
            'SELECT COUNT(*) FROM payments p INNER JOIN orders o ON o.id = p.order_id WHERE o.customer_id = ?',
            [$id]
        ),
    ];
}

// --- Preflight ---
$protected = verify_protected($pdo);
if (!$protected['ok']) {
    fwrite(STDERR, "STOP: customer id=1 does not match Vijay Amule evidence.\n");
    fwrite(STDERR, json_encode($protected['row'], JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$activeTargets = [];
foreach (TARGET_CUSTOMER_IDS as $tid) {
    if (in_array($tid, PROTECTED_CUSTOMER_IDS, true)) {
        fwrite(STDERR, "Target overlaps protected id {$tid}\n");
        exit(1);
    }
    if (snapshot_customer($pdo, $tid) === []) {
        if ($allowMissingTargets) {
            fwrite(STDERR, "Note: target customer {$tid} not found — skipping.\n");
            continue;
        }
        fwrite(STDERR, "Target customer {$tid} not found (use --allow-missing-targets if already cleaned).\n");
        exit(1);
    }
    $activeTargets[] = $tid;
}
if ($activeTargets === []) {
    fwrite(STDERR, "No target customers to process.\n");
    exit(1);
}

$auditJsonPath = dirname(__DIR__) . '/storage/production-test-data-audit.json';
$auditPayload = null;
if (is_readable($auditJsonPath)) {
    $auditPayload = json_decode((string) file_get_contents($auditJsonPath), true);
    $auditDb = (string) ($auditPayload['database'] ?? '');
    if ($auditDb !== '' && $auditDb !== $dbName) {
        fwrite(STDERR, "WARNING: audit JSON database '{$auditDb}' != connected '{$dbName}'.\n");
    }
    $auditProtected = $auditPayload['protected_customer']['customer_ids'] ?? [];
    if (is_array($auditProtected) && $auditProtected !== [] && !in_array(1, array_map('intval', $auditProtected), true)) {
        fwrite(STDERR, "STOP: audit JSON protected_customer_ids does not include id 1.\n");
        exit(1);
    }
}

$report = [
    'generated_at' => date('c'),
    'database' => $dbName,
    'mode' => $dryRun ? 'dry_run' : 'execute',
    'protected_customer' => $protected,
    'target_customer_ids' => $activeTargets,
    'audit_json' => $auditPayload !== null ? $auditJsonPath : null,
    'targets_before' => [],
    'financial' => [],
    'deleted_counts' => [],
    'preserved_finance' => [],
    'protected_counts_before' => snapshot_protected_counts($pdo, 1),
    'protected_counts_after' => null,
    'verification' => [],
];

foreach ($activeTargets as $tid) {
    $report['targets_before'][$tid] = snapshot_customer($pdo, $tid);
    $report['financial'][$tid] = financial_snapshot($pdo, $tid);
}

// --- Backup ---
$cfg = App::config()['database'];
if (getenv('APNI_DB_NAME')) {
    $cfg['name'] = (string) getenv('APNI_DB_NAME');
}
if (($h = getenv('APNI_DB_HOST')) !== false && $h !== '') {
    $cfg['host'] = $h;
}
if (($p = getenv('APNI_DB_PORT')) !== false && $p !== '') {
    $cfg['port'] = (int) $p;
}
if (($u = getenv('APNI_DB_USER')) !== false && $u !== '') {
    $cfg['user'] = $u;
}
if (getenv('APNI_DB_PASSWORD') !== false) {
    $cfg['password'] = (string) getenv('APNI_DB_PASSWORD');
}
$backupDir = dirname(__DIR__) . '/storage/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}
$backupFile = $backupDir . '/pre_customer_cleanup_2_3_' . date('Ymd_His') . '.sql';
$report['backup_file'] = $backupFile;

if ($execute) {
    $host = (string) ($cfg['host'] ?? '127.0.0.1');
    $port = (int) ($cfg['port'] ?? 3306);
    $user = (string) ($cfg['user'] ?? 'root');
    $pass = (string) ($cfg['pass'] ?? $cfg['password'] ?? '');
    $dumpBin = getenv('MYSQLDUMP_BIN') ?: '';
    if ($dumpBin === '' && is_file('d:\\xampp\\mysql\\bin\\mysqldump.exe')) {
        $dumpBin = 'd:\\xampp\\mysql\\bin\\mysqldump.exe';
    }
    if ($dumpBin === '') {
        $dumpBin = 'mysqldump';
    }
    $args = [
        $dumpBin,
        '-h', $host,
        '-P', (string) $port,
        '-u', $user,
        '--single-transaction',
        '--routines',
        '--triggers',
        $dbName,
    ];
    if ($pass !== '') {
        $args = [
            $dumpBin,
            '-h', $host,
            '-P', (string) $port,
            '-u', $user,
            '-p' . $pass,
            '--single-transaction',
            '--routines',
            '--triggers',
            $dbName,
        ];
    }
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $backupFile, 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($args, $descriptors, $pipes);
    $code = 1;
    $err = '';
    if (is_resource($proc)) {
        fclose($pipes[0]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($proc);
    }
    if ($code !== 0 || !is_file($backupFile) || filesize($backupFile) < 1024) {
        fwrite(STDERR, "Backup failed (code {$code}). " . trim($err) . " Aborting.\n");
        exit(1);
    }
    $report['backup_bytes'] = filesize($backupFile);
}

try {
    $pdo->beginTransaction();

    foreach ($activeTargets as $tid) {
        $counts = [];
        $fin = $report['financial'][$tid];
        if (must_preserve_finance($fin)) {
            $report['preserved_finance'][$tid] = $fin;
            $report['strategy'][$tid] = 'anonymize_preserve_finance';
            anonymize_preserve_finance($pdo, $tid, $counts, $dryRun);
        } else {
            $report['strategy'][$tid] = 'full_customer_removal';
            full_customer_removal($pdo, $tid, $counts, $dryRun);
        }
        $report['deleted_counts'][$tid] = $counts;
    }

    if ($dryRun) {
        $pdo->rollBack();
        $report['transaction'] = 'rolled_back_dry_run';
    } else {
        $pdo->commit();
        $report['transaction'] = 'committed';
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $report['error'] = $e->getMessage();
    $report['transaction'] = 'rolled_back_error';
    file_put_contents(
        dirname(__DIR__) . '/storage/production-customer-cleanup-2-3-report.json',
        json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
    );
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$report['protected_counts_after'] = snapshot_protected_counts($pdo, 1);
foreach (TARGET_CUSTOMER_IDS as $tid) {
    if (!in_array($tid, $activeTargets, true) && snapshot_customer($pdo, $tid) === []) {
        $report['verification']["customer_{$tid}_after"] = [];
        $report['verification']["customer_{$tid}_removed_or_anonymized"] = true;
        continue;
    }
    if (!in_array($tid, $activeTargets, true)) {
        continue;
    }
    $row = snapshot_customer($pdo, $tid);
    $report['verification']["customer_{$tid}_after"] = $row;
    $report['verification']["customer_{$tid}_removed_or_anonymized"] =
        $row === [] || str_starts_with((string) ($row['mobile'] ?? ''), 'DEL');
}
$report['verification']['protected_unchanged'] =
    $report['protected_counts_before'] === $report['protected_counts_after'];

$outJson = dirname(__DIR__) . '/storage/production-customer-cleanup-2-3-report.json';
file_put_contents($outJson, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit(($report['verification']['protected_unchanged'] ?? false) ? 0 : 2);
