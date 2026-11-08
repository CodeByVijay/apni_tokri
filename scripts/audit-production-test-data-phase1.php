<?php

declare(strict_types=1);

/**
 * Phase 1 — Production test-data audit (SELECT / information_schema ONLY).
 *
 * DO NOT DELETE OR UPDATE. Run against a production CLONE first when possible.
 *
 * CLI:
 *   php scripts/audit-production-test-data-phase1.php
 *   CLONE_DB=apni_tokri_prod_clone php scripts/audit-production-test-data-phase1.php
 *
 * Output: storage/production-test-data-audit.json + human summary on stdout
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

const PROTECTED_NAME = 'Vijay Amule';
const PROTECTED_EMAIL = 'vijayamule7@gmail.com';
const PROTECTED_MOBILE_DIGITS = '9407005023';

if (!Install::canConnect()) {
    fwrite(STDERR, "Database not connected. Run on production clone or configured DSN.\n");
    exit(1);
}

Install::ensureConnection();

$clone = getenv('CLONE_DB') ?: '';
if ($clone !== '') {
    $db = App::config()['database'];
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string) ($db['host'] ?? '127.0.0.1'),
            (int) ($db['port'] ?? 3306),
            $clone
        ),
        (string) ($db['user'] ?? 'root'),
        (string) ($db['pass'] ?? $db['password'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} else {
    $pdo = Database::pdo();
}

/** @return int */
function audit_count(PDO $pdo, string $sql, array $params = []): int
{
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return (int) $st->fetchColumn();
}

/** @return list<array<string, mixed>> */
function audit_rows(PDO $pdo, string $sql, array $params = [], int $limit = 500): array
{
    $st = $pdo->prepare($sql . ' LIMIT ' . (int) $limit);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$mobileNormSql = "REPLACE(REPLACE(REPLACE(REPLACE(mobile,'+91',''),' ',''),'-',''),'\\t','')";

// --- Protected customer ---
$protectedRows = audit_rows(
    $pdo,
    "SELECT id, mobile, name, email, is_active, created_at FROM customers
     WHERE ($mobileNormSql = ? OR LOWER(TRIM(email)) = LOWER(?)
           OR (name IS NOT NULL AND name = ?))",
    [PROTECTED_MOBILE_DIGITS, PROTECTED_EMAIL, PROTECTED_NAME]
);
$protectedIds = array_map(static fn (array $r): int => (int) $r['id'], $protectedRows);

$duplicateWarning = count($protectedRows) > 1;

// --- Tier A markers (SAFE_TEST_DATA when blockers clear) ---
$tierAMarkers = [
    'orders_at_c1fix' => "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'AT-C1FIX-%'",
    'orders_at_acct' => "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'AT-ACCT%'",
    'orders_at_test' => "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'AT-TEST-%'",
    'orders_tl' => "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'TL-%'",
    'orders_vt_retry' => "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'VT-RETRY-%'",
    'orders_vt_idem' => "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'VT-IDEM-%'",
    'orders_at_404' => "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'AT-404%'",
    'refunds_rf_c1' => "SELECT COUNT(*) FROM refund_requests WHERE request_number LIKE 'RF-C1%'",
    'subs_sub_c1fix' => "SELECT COUNT(*) FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-C1FIX-%'",
    'subs_sub_pg' => "SELECT COUNT(*) FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-PG-%'",
    'subs_sub_h' => "SELECT COUNT(*) FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-H-%'",
    'subs_zt_sub' => "SELECT COUNT(*) FROM customer_subscriptions WHERE subscription_number LIKE 'ZT-SUB-%'",
    'customers_987c1' => "SELECT COUNT(*) FROM customers WHERE mobile LIKE '987C1%'",
    'customers_stage12_names' => "SELECT COUNT(*) FROM customers WHERE name IN ('Stage12 C1 Test Customer A','Stage12 C1 Test Customer B')",
    'customers_disposable_970' => "SELECT COUNT(*) FROM customers WHERE $mobileNormSql = '9700000001'",
];

$tierACounts = [];
foreach ($tierAMarkers as $key => $sql) {
    $tierACounts[$key] = audit_count($pdo, $sql);
}

// --- Candidate test customers (explicit evidence only) ---
$candidateCustomers = audit_rows(
    $pdo,
    "SELECT c.id, c.mobile, c.name, c.email, c.created_at,
            (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) AS order_count,
            (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.order_number NOT LIKE 'AT-C1FIX-%'
                AND o.order_number NOT LIKE 'AT-ACCT%' AND o.order_number NOT LIKE 'TL-%'
                AND o.order_number NOT LIKE 'AT-TEST-%' AND o.order_number NOT LIKE 'VT-%'
                AND o.order_number NOT LIKE 'AT-404%') AS non_marker_orders,
            (SELECT COUNT(*) FROM payments p INNER JOIN orders o ON o.id = p.order_id
             WHERE o.customer_id = c.id AND p.gateway IN ('razorpay','cashfree') AND p.status = 'captured') AS live_captured_payments,
            (SELECT COUNT(*) FROM invoices i WHERE i.customer_id = c.id) AS invoice_count
     FROM customers c
     WHERE c.mobile LIKE '987C1%'
        OR c.name IN ('Stage12 C1 Test Customer A','Stage12 C1 Test Customer B')
        OR $mobileNormSql = '9700000001'
        OR c.id IN (SELECT DISTINCT customer_id FROM orders WHERE order_number LIKE 'AT-C1FIX-%' OR order_number LIKE 'AT-ACCT%')
        OR c.id IN (SELECT DISTINCT customer_id FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-C1FIX-%'
                    OR subscription_number LIKE 'SUB-PG-%' OR subscription_number LIKE 'SUB-H-%'
                    OR subscription_number LIKE 'ZT-SUB-%')
     ORDER BY c.id",
    [],
    2000
);

$classified = [];
foreach ($candidateCustomers as $row) {
    $cid = (int) $row['id'];
    if (in_array($cid, $protectedIds, true)) {
        $classified[] = ['customer_id' => $cid, 'classification' => 'PROTECTED_CUSTOMER', 'reason' => 'Vijay Amule / protected match', 'row' => $row];
        continue;
    }
    if ((int) $row['live_captured_payments'] > 0) {
        $classified[] = ['customer_id' => $cid, 'classification' => 'REVIEW_REQUIRED', 'reason' => 'razorpay/cashfree captured payment', 'row' => $row];
        continue;
    }
    if ((int) $row['invoice_count'] > 0) {
        $classified[] = ['customer_id' => $cid, 'classification' => 'REVIEW_REQUIRED', 'reason' => 'has invoices', 'row' => $row];
        continue;
    }
    if ((int) $row['non_marker_orders'] > 0) {
        $classified[] = ['customer_id' => $cid, 'classification' => 'REVIEW_REQUIRED', 'reason' => 'orders outside documented test prefixes', 'row' => $row];
        continue;
    }
    $mobile = (string) $row['mobile'];
    if (str_starts_with($mobile, '987C1') || in_array((string) $row['name'], ['Stage12 C1 Test Customer A', 'Stage12 C1 Test Customer B'], true)) {
        $classified[] = ['customer_id' => $cid, 'classification' => 'SAFE_TEST_DATA', 'reason' => 'Stage12 C1 fixture mobile/name pattern', 'row' => $row];
        continue;
    }
    $classified[] = ['customer_id' => $cid, 'classification' => 'REVIEW_REQUIRED', 'reason' => 'linked to test subgraph but not fully verified', 'row' => $row];
}

// Per-customer table counts for SAFE + REVIEW candidates
$perCustomerCounts = [];
$customerTables = [
    'customer_addresses' => 'customer_id',
    'customer_coupon_assignments' => 'customer_id',
    'customer_notifications' => 'customer_id',
    'customer_subscriptions' => 'customer_id',
    'subscription_customer_preferences' => 'customer_id',
    'carts' => 'customer_id',
    'orders' => 'customer_id',
    'product_reviews' => 'customer_id',
    'refund_requests' => 'customer_id',
    'support_tickets' => 'customer_id',
    'offer_customer_assignments' => 'customer_id',
    'invoices' => 'customer_id',
];

foreach ($classified as $entry) {
    if ($entry['classification'] === 'PROTECTED_CUSTOMER') {
        continue;
    }
    $cid = (int) $entry['customer_id'];
    $counts = [];
    foreach ($customerTables as $table => $col) {
        try {
            $counts[$table] = audit_count($pdo, "SELECT COUNT(*) FROM `$table` WHERE `$col` = ?", [$cid]);
        } catch (Throwable $e) {
            $counts[$table] = null;
        }
    }
    $perCustomerCounts[$cid] = $counts;
}

// Protected customer full counts
$protectedCounts = [];
if ($protectedIds !== []) {
    $pid = $protectedIds[0];
    foreach ($customerTables as $table => $col) {
        try {
            $protectedCounts[$table] = audit_count($pdo, "SELECT COUNT(*) FROM `$table` WHERE `$col` = ?", [$pid]);
        } catch (Throwable $e) {
            $protectedCounts[$table] = null;
        }
    }
}

$fks = audit_rows(
    $pdo,
    "SELECT tc.CONSTRAINT_NAME, tc.TABLE_NAME, kcu.COLUMN_NAME,
            kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE
     FROM information_schema.TABLE_CONSTRAINTS tc
     JOIN information_schema.KEY_COLUMN_USAGE kcu
       ON tc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
     JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
       ON tc.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
     WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.CONSTRAINT_TYPE = 'FOREIGN KEY'
       AND (kcu.REFERENCED_TABLE_NAME IN ('customers','orders','customer_subscriptions','product_variants')
            OR tc.TABLE_NAME IN ('customers','orders','payments','refund_requests','invoices'))
     ORDER BY tc.TABLE_NAME, tc.CONSTRAINT_NAME",
    [],
    5000
);

$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

$report = [
    'generated_at' => date('c'),
    'database' => $dbName,
    'read_only' => true,
    'phase' => '1_audit_only',
    'no_mutations_executed' => true,
    'protected_customer' => [
        'name' => PROTECTED_NAME,
        'email' => PROTECTED_EMAIL,
        'mobile_digits' => PROTECTED_MOBILE_DIGITS,
        'matches' => $protectedRows,
        'customer_ids' => $protectedIds,
        'duplicate_accounts_warning' => $duplicateWarning,
        'per_table_counts' => $protectedCounts,
    ],
    'tier_a_marker_counts' => $tierACounts,
    'candidate_customer_classifications' => $classified,
    'per_customer_table_counts' => $perCustomerCounts,
    'foreign_keys_sample' => $fks,
    'existing_repo_tools' => [
        'phase3_plan' => 'docs/PHASE3_CLEANUP_PLAN.md',
        'tier_a_review' => 'scripts/phase3-1-tier-a-safety-review.php',
        'demo_audit' => 'scripts/audit-demo-test-data-readonly.php',
        'sql_readonly' => 'scripts/sql/production-test-data-audit-readonly.sql',
        'sql_deletion_proposed' => 'scripts/sql/production-test-data-deletion-PROPOSED.sql',
    ],
    'next_steps' => [
        'Full mysqldump backup before any DELETE',
        'Run phase3-1 on clone',
        'Human approval required',
    ],
];

$outPath = dirname(__DIR__) . '/storage/production-test-data-audit.json';
file_put_contents($outPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

echo "Production test-data audit (read-only)\n";
echo "Database: {$dbName}\n";
echo "Protected customer IDs: " . ($protectedIds === [] ? 'NOT FOUND — STOP' : implode(', ', $protectedIds)) . "\n";
if ($duplicateWarning) {
    echo "WARNING: Multiple protected customer rows — resolve duplicates before deletion.\n";
}
echo "SAFE_TEST_DATA customers: " . count(array_filter($classified, static fn ($x) => $x['classification'] === 'SAFE_TEST_DATA')) . "\n";
echo "REVIEW_REQUIRED customers: " . count(array_filter($classified, static fn ($x) => $x['classification'] === 'REVIEW_REQUIRED')) . "\n";
echo "JSON: {$outPath}\n";

exit($protectedIds === [] ? 2 : 0);
