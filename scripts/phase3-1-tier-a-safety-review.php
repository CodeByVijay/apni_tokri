<?php

declare(strict_types=1);

/**
 * Phase 3.1 — Tier A pre-execution safety review (SELECT only).
 * CLI: php scripts/phase3-1-tier-a-safety-review.php
 * Optional: CLONE_DB=apni_tokri_phase31_clone (must exist; read-only SELECT)
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::canConnect()) {
    fwrite(STDERR, "DB not connected\n");
    exit(1);
}

$clone = getenv('CLONE_DB') ?: '';
$dbName = $clone !== '' ? $clone : (string) (App::config()['database']['name'] ?? '');
$host = (string) (App::config()['database']['host'] ?? '127.0.0.1');
$port = (int) (App::config()['database']['port'] ?? 3306);
$user = (string) (App::config()['database']['user'] ?? 'root');
$pass = (string) (App::config()['database']['pass'] ?? App::config()['database']['password'] ?? '');

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbName),
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

/** @return int */
function c(PDO $pdo, string $sql, array $p = []): int
{
    $s = $pdo->prepare($sql);
    $s->execute($p);

    return (int) $s->fetchColumn();
}

/** @return list<array<string, mixed>> */
function rows(PDO $pdo, string $sql, array $p = [], int $limit = 500): array
{
    $s = $pdo->prepare($sql . ($limit > 0 ? ' LIMIT ' . (int) $limit : ''));
    $s->execute($p);

    return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$blockers = [];
$warnings = [];
$tierA = [];

// --- Tier A root inventories ---
$scopes = [
    'c1_orders' => ["SELECT id, order_number, customer_id, payment_status FROM orders WHERE order_number LIKE 'AT-C1FIX-%' ORDER BY id", 'stage12_phase_c1_fixture.php'],
    'c1_refunds' => ["SELECT id, request_number, order_id FROM refund_requests WHERE request_number LIKE 'RF-C1%' ORDER BY id", 'stage12_phase_c1_fixture.php'],
    'c1_subs' => ["SELECT id, subscription_number, customer_id, status FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-C1FIX-%' ORDER BY id", 'stage12_phase_c1_fixture.php'],
    'c1_customers' => ["SELECT id, mobile, name FROM customers WHERE mobile LIKE '987C1%' ORDER BY id", 'stage12_phase_c1_fixture.php'],
    'sub_pg' => ["SELECT id, subscription_number, customer_id, subscription_plan_id FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-PG-%' ORDER BY id", 'test-account-list-pagination.php'],
    'sub_h' => ["SELECT id, subscription_number, customer_id FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-H-%' ORDER BY id", 'SubscriptionTestFulfilmentFixture'],
    'zt_sub' => ["SELECT id, subscription_number, customer_id, subscription_plan_id FROM customer_subscriptions WHERE subscription_number LIKE 'ZT-SUB-%' ORDER BY id", 'test-subscription-admin-delete.php'],
    'at_acct_orders' => ["SELECT id, order_number, customer_id FROM orders WHERE order_number LIKE 'AT-ACCT%' ORDER BY id", 'test-customer-account.php'],
    'at_product' => ["SELECT id, slug, name FROM products WHERE slug LIKE 'at-%' ORDER BY id", 'stage13 fixtures'],
];

foreach ($scopes as $key => [$sql, $fixture]) {
    $roots = rows($pdo, $sql, [], 2000);
    $tierA[$key] = ['fixture' => $fixture, 'roots' => $roots, 'root_count' => count($roots)];
}

// --- C1 unified graph counts (deduplicated) ---
$c1OrderIds = "SELECT id FROM orders WHERE order_number LIKE 'AT-C1FIX-%'";
$c1SubIds = "SELECT id FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-C1FIX-%'";
$c1CustIds = "SELECT id FROM customers WHERE mobile LIKE '987C1%'";
$c1RrIds = "SELECT id FROM refund_requests WHERE request_number LIKE 'RF-C1%'";

$c1Graph = [
    'orders' => c($pdo, "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'AT-C1FIX-%'"),
    'order_items' => c($pdo, "SELECT COUNT(*) FROM order_items WHERE order_id IN ($c1OrderIds)"),
    'payments' => c($pdo, "SELECT COUNT(*) FROM payments WHERE order_id IN ($c1OrderIds)"),
    'payments_simulate_only' => c($pdo, "SELECT COUNT(*) FROM payments WHERE order_id IN ($c1OrderIds) AND gateway = 'simulate'"),
    'payments_non_simulate' => c($pdo, "SELECT COUNT(*) FROM payments WHERE order_id IN ($c1OrderIds) AND gateway IN ('razorpay','cashfree')"),
    'refund_requests' => c($pdo, "SELECT COUNT(*) FROM refund_requests WHERE request_number LIKE 'RF-C1%'"),
    'refund_request_items' => c($pdo, "SELECT COUNT(*) FROM refund_request_items WHERE refund_request_id IN ($c1RrIds)"),
    'refund_request_history' => c($pdo, "SELECT COUNT(*) FROM refund_request_history WHERE refund_request_id IN ($c1RrIds)"),
    'payment_refunds_on_c1' => c($pdo, "SELECT COUNT(*) FROM payment_refunds pr INNER JOIN payments p ON p.id = pr.payment_id WHERE p.order_id IN ($c1OrderIds)"),
    'invoices_on_c1_orders' => c($pdo, "SELECT COUNT(*) FROM invoices WHERE order_id IN ($c1OrderIds)"),
    'deliveries_on_c1' => c($pdo, "SELECT COUNT(*) FROM deliveries WHERE order_id IN ($c1OrderIds)"),
    'subscription_payments' => c($pdo, "SELECT COUNT(*) FROM subscription_payments WHERE customer_subscription_id IN ($c1SubIds)"),
    'customer_subscriptions' => c($pdo, "SELECT COUNT(*) FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-C1FIX-%'"),
    'customers_987c1' => c($pdo, "SELECT COUNT(*) FROM customers WHERE mobile LIKE '987C1%'"),
    'customers_non_c1_orders' => c($pdo, "SELECT COUNT(*) FROM orders WHERE customer_id IN ($c1CustIds) AND order_number NOT LIKE 'AT-C1FIX-%'"),
    'customers_linked_invoices' => c($pdo, "SELECT COUNT(*) FROM invoices i INNER JOIN orders o ON o.id = i.order_id WHERE o.customer_id IN ($c1CustIds)"),
];

if ($c1Graph['payments_non_simulate'] > 0) {
    $blockers[] = 'C1 orders have razorpay/cashfree payments — excluded from auto Tier A';
}
if ($c1Graph['invoices_on_c1_orders'] > 0) {
    $blockers[] = 'C1 orders linked to invoices';
}
if ($c1Graph['customers_non_c1_orders'] > 0) {
    $blockers[] = '987C1 customers have orders outside AT-C1FIX-%';
}

// --- SUB-PG extended subgraph ---
$subPgIds = "SELECT id FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-PG-%'";
$subPgGraph = [
    'customer_subscriptions' => c($pdo, "SELECT COUNT(*) FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-PG-%'"),
    'subscription_payments' => c($pdo, "SELECT COUNT(*) FROM subscription_payments WHERE customer_subscription_id IN ($subPgIds)"),
    'subscription_delivery_days' => c($pdo, "SELECT COUNT(*) FROM subscription_delivery_days WHERE customer_subscription_id IN ($subPgIds)"),
    'orders_fulfilment' => c($pdo, "SELECT COUNT(*) FROM orders WHERE subscription_delivery_day_id IN (SELECT id FROM subscription_delivery_days WHERE customer_subscription_id IN ($subPgIds))"),
    'plan_ids' => rows($pdo, "SELECT DISTINCT subscription_plan_id FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-PG-%'", [], 20),
    'touches_plan_1_3' => c($pdo, "SELECT COUNT(*) FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-PG-%' AND subscription_plan_id IN (1,2,3)"),
];

if ($subPgGraph['touches_plan_1_3'] > 0) {
    $warnings[] = 'Some SUB-PG subs reference default plans 1–3 (FK only — deleting subs does not delete plans)';
}

// --- SUB-H / ZT subgraph (purgeTestSubscriptions pattern) ---
foreach (['SUB-H-%' => 'sub_h', 'ZT-SUB-%' => 'zt_sub'] as $like => $label) {
    $subQ = "SELECT id FROM customer_subscriptions WHERE subscription_number LIKE " . $pdo->quote($like);
    $dayQ = "SELECT id FROM subscription_delivery_days WHERE customer_subscription_id IN ($subQ)";
    $ordQ = "SELECT id FROM orders WHERE subscription_delivery_day_id IN ($dayQ)";
    $tierA[$label . '_graph'] = [
        'subscription_delivery_days' => c($pdo, "SELECT COUNT(*) FROM subscription_delivery_days WHERE customer_subscription_id IN ($subQ)"),
        'subscription_stock_reservations' => c($pdo, "SELECT COUNT(*) FROM subscription_stock_reservations WHERE subscription_delivery_day_id IN ($dayQ)"),
        'subscription_fulfilment_events' => c($pdo, "SELECT COUNT(*) FROM subscription_fulfilment_events WHERE subscription_delivery_day_id IN ($dayQ)"),
        'orders' => c($pdo, "SELECT COUNT(*) FROM orders WHERE subscription_delivery_day_id IN ($dayQ)"),
        'deliveries' => c($pdo, "SELECT COUNT(*) FROM deliveries WHERE order_id IN ($ordQ)"),
        'invoices' => c($pdo, "SELECT COUNT(*) FROM invoices WHERE order_id IN ($ordQ)"),
        'non_simulate_payments' => c($pdo, "SELECT COUNT(*) FROM payments WHERE order_id IN ($ordQ) AND gateway IN ('razorpay','cashfree')"),
    ];
    if ($tierA[$label . '_graph']['invoices'] > 0 || $tierA[$label . '_graph']['non_simulate_payments'] > 0) {
        $blockers[] = "$like fulfilment graph has invoice or live gateway payment";
    }
}

// --- AT-ACCT orders ---
$acctOrd = "SELECT id FROM orders WHERE order_number LIKE 'AT-ACCT%'";
$acctGraph = [
    'orders' => c($pdo, "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'AT-ACCT%'"),
    'order_items' => c($pdo, "SELECT COUNT(*) FROM order_items WHERE order_id IN ($acctOrd)"),
    'payments' => c($pdo, "SELECT COUNT(*) FROM payments WHERE order_id IN ($acctOrd)"),
    'invoices' => c($pdo, "SELECT COUNT(*) FROM invoices WHERE order_id IN ($acctOrd)"),
    'non_simulate_payments' => c($pdo, "SELECT COUNT(*) FROM payments WHERE order_id IN ($acctOrd) AND gateway IN ('razorpay','cashfree')"),
];

// --- at-% product ---
$prodRows = $tierA['at_product']['roots'];
$atProductBlock = [];
foreach ($prodRows as $pr) {
    $pid = (int) $pr['id'];
    $oi = c($pdo, 'SELECT COUNT(*) FROM order_items oi INNER JOIN product_variants pv ON pv.id = oi.variant_id WHERE pv.product_id = ?', [$pid]);
    $invMov = c($pdo, 'SELECT COUNT(*) FROM inventory_movements WHERE variant_id IN (SELECT id FROM product_variants WHERE product_id = ?)', [$pid]);
    $atProductBlock[] = ['product_id' => $pid, 'slug' => $pr['slug'], 'order_items' => $oi, 'inventory_movements' => $invMov];
    if ($oi > 0) {
        $blockers[] = "Product id {$pid} ({$pr['slug']}) has {$oi} order_items references — manual only";
    }
}

// --- Protected overlap checks ---
$protected = [
    'razorpay_cashfree_payments_total' => c($pdo, "SELECT COUNT(*) FROM payments WHERE gateway IN ('razorpay','cashfree')"),
    'invoices_total' => c($pdo, 'SELECT COUNT(*) FROM invoices'),
    'invoice_sequences' => c($pdo, 'SELECT COUNT(*) FROM invoice_sequences'),
    'settings' => c($pdo, 'SELECT COUNT(*) FROM settings'),
    'admin_users' => c($pdo, 'SELECT COUNT(*) FROM admin_users'),
    'admin_audit_logs' => c($pdo, 'SELECT COUNT(*) FROM admin_audit_logs'),
    'plans_1_3' => c($pdo, 'SELECT COUNT(*) FROM subscription_plans WHERE id IN (1,2,3)'),
    'storefront_at_ymd_orders' => c($pdo, "SELECT COUNT(*) FROM orders WHERE order_number REGEXP '^AT-[0-9]{6}-' AND order_number NOT LIKE 'AT-C1FIX-%' AND order_number NOT LIKE 'AT-ACCT%'"),
    'tier_a_touch_storefront_at_ymd' => c($pdo, "SELECT COUNT(*) FROM orders WHERE order_number REGEXP '^AT-[0-9]{6}-' AND order_number NOT LIKE 'AT-C1FIX-%' AND order_number NOT LIKE 'AT-ACCT%' AND (order_number LIKE 'AT-PAY-%' OR order_number LIKE 'AT-MAP-%' OR id IN (SELECT order_id FROM refund_requests WHERE request_number LIKE 'RF-C1%'))"),
];

// Idempotency: zero-row markers
$zeroMarkers = [
    'AT-C3-%' => c($pdo, "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'AT-C3-%'"),
    'D2H-%' => c($pdo, "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'D2H-%'"),
    'AT-PAY-%' => c($pdo, "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'AT-PAY-%'"),
    'AT-MAP-%' => c($pdo, "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'AT-MAP-%'"),
];

// Compare to manifest expected (from preview dependent counts - hardcode key expectations)
$manifestPath = dirname(__DIR__) . '/storage/phase3-cleanup-manifest.json';
$manifest = is_readable($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;

$out = [
    'generated_at' => date('c'),
    'database' => $dbName,
    'read_only' => true,
    'tier_a_roots' => $tierA,
    'c1_deduplicated_graph' => $c1Graph,
    'sub_pg_graph' => $subPgGraph,
    'at_acct_graph' => $acctGraph,
    'at_product_refs' => $atProductBlock,
    'protected_counts' => $protected,
    'zero_markers' => $zeroMarkers,
    'blockers' => $blockers,
    'warnings' => $warnings,
    'recommended_tier_a_order' => [
        '1_payment_refunds_c1_and_acct',
        '2_refund_request_children_then_rf_c1',
        '3_payments_c1_acct_subgraph',
        '4_delivery_status_history_deliveries',
        '5_order_items_order_status_history',
        '6_orders_at_c1fix_and_at_acct',
        '7_subscription_fulfilment_deliveries_orders_subh_zt',
        '8_subscription_stock_reservations_fulfilment_events_baskets',
        '9_subscription_delivery_days',
        '10_subscription_payments',
        '11_customer_subscriptions_c1fix_subpg_subh_zt',
        '12_customers_987c1_after_fk_clear',
        '13_product_at_prefix_manual_if_no_order_items',
    ],
    'transaction_note' => 'Use single transaction per scope batch; stage12_phase_c1_teardown uses scoped DELETE without explicit transaction — recommend BEGIN…COMMIT with ROLLBACK on staging clone first.',
    'idempotency' => 'Re-running scoped DELETE after success yields 0 affected rows; zero-marker scopes already idempotent.',
    'status' => $blockers === [] ? 'READY FOR MANUAL APPROVAL' : 'NOT READY — BLOCKERS FOUND',
];

file_put_contents(
    dirname(__DIR__) . '/storage/phase3-1-safety-review.json',
    json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit($blockers === [] ? 0 : 1);
