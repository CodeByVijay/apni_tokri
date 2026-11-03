<?php

declare(strict_types=1);

/**
 * Phase 2.3 — read-only demo/test data audit (SELECT only).
 * CLI: php scripts/audit-demo-test-data-readonly.php
 * Output: JSON to stdout (redirect to file if needed).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::canConnect()) {
    echo json_encode(['error' => 'Database not connected', 'schema_ready' => Install::isSchemaReady()], JSON_PRETTY_PRINT);
    exit(0);
}

Install::ensureConnection();
$pdo = Database::pdo();

/** @param list<string> $sqls */
function audit_count(PDO $pdo, string $label, array $sqls): array
{
    $out = ['label' => $label, 'counts' => []];
    foreach ($sqls as $key => $sql) {
        try {
            $stmt = $pdo->query($sql);
            $out['counts'][$key] = $stmt ? (int) $stmt->fetchColumn() : 0;
        } catch (Throwable $e) {
            $out['counts'][$key] = null;
            $out['errors'][$key] = $e->getMessage();
        }
    }

    return $out;
}

$tableRows = $pdo->query(
    "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN) ?: [];

$tableCounts = [];
foreach ($tableRows as $table) {
    try {
        $tableCounts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn();
    } catch (Throwable $e) {
        $tableCounts[$table] = null;
    }
}

$demoSlugs = [
    'fresh-red-tomatoes', 'green-capsicum', 'onion-red', 'banana-robusta', 'apple-shimla',
    'cut-mixed-salad', 'cut-french-beans', 'mango-pickle-homestyle', 'papad-moong',
];
$demoCatSlugs = ['vegetables', 'fruits', 'cut', 'pickles'];
$productSlugList = implode(',', array_map(fn ($s) => $pdo->quote($s), $demoSlugs));
$catSlugList = implode(',', array_map(fn ($s) => $pdo->quote($s), $demoCatSlugs));

$markers = audit_count($pdo, 'evidence_markers', [
    'orders_at_c1fix' => "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'AT-C1FIX-%'",
    'orders_at_c3' => "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'AT-C3-%'",
    'orders_d2h' => "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'D2H-%'",
    'orders_at_pay' => "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'AT-PAY-%'",
    'orders_at_map' => "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'AT-MAP-%'",
    'orders_sub_pg' => "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'SUB-PG-%'",
    'customers_987c1' => "SELECT COUNT(*) FROM customers WHERE mobile LIKE '987C1%'",
    'customers_stage11_mobile' => "SELECT COUNT(*) FROM customers WHERE mobile REGEXP '^98[0-9]{8}$' AND name LIKE '%fixture%'",
    'refunds_rf_c1' => "SELECT COUNT(*) FROM refund_requests WHERE request_number LIKE 'RF-C1%'",
    'subs_sub_h' => "SELECT COUNT(*) FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-H-%'",
    'subs_zt_sub' => "SELECT COUNT(*) FROM customer_subscriptions WHERE subscription_number LIKE 'ZT-SUB-%'",
    'products_demo_slugs' => "SELECT COUNT(*) FROM products WHERE slug IN ($productSlugList)",
    'categories_demo_slugs' => "SELECT COUNT(*) FROM categories WHERE slug IN ($catSlugList)",
    'products_at_prefix' => "SELECT COUNT(*) FROM products WHERE slug LIKE 'at-%'",
    'cms_at_prefix' => "SELECT COUNT(*) FROM cms_pages WHERE slug LIKE 'at-%'",
    'faqs_at_prefix' => "SELECT COUNT(*) FROM faqs WHERE question LIKE 'AT-%'",
    'testimonials_at_prefix' => "SELECT COUNT(*) FROM testimonials WHERE customer_display_name LIKE 'AT-%'",
    'reviews_at_c21' => "SELECT COUNT(*) FROM product_reviews WHERE body LIKE 'AT-C21-%' OR display_name = 'AT-C21'",
    'payments_simulate' => "SELECT COUNT(*) FROM payments WHERE gateway = 'simulate'",
    'sub_payments_simulate' => "SELECT COUNT(*) FROM subscription_payments WHERE gateway = 'simulate'",
    'payments_test_idempotency' => "SELECT COUNT(*) FROM payments WHERE idempotency_key LIKE 'pay_test%' OR idempotency_key LIKE 'test_%'",
    'admin_roles_test' => "SELECT COUNT(*) FROM admin_roles WHERE slug LIKE 'at-rbac14-%'",
    'coupons_welcome_seed' => "SELECT COUNT(*) FROM coupons WHERE code = 'WELCOME10'",
]);

$payGw = [];
try {
    foreach ($pdo->query('SELECT gateway, COUNT(*) AS c FROM payments GROUP BY gateway')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $payGw[(string) $r['gateway']] = (int) $r['c'];
    }
} catch (Throwable $e) {
    $payGw = ['error' => $e->getMessage()];
}

$subPayGw = [];
try {
    foreach ($pdo->query('SELECT gateway, COUNT(*) AS c FROM subscription_payments GROUP BY gateway')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $subPayGw[(string) $r['gateway']] = (int) $r['c'];
    }
} catch (Throwable $e) {
    $subPayGw = ['error' => $e->getMessage()];
}

$orderSources = [];
try {
    foreach ($pdo->query('SELECT COALESCE(order_source, \'(null)\') AS src, COUNT(*) AS c FROM orders GROUP BY order_source')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $orderSources[(string) $r['src']] = (int) $r['c'];
    }
} catch (Throwable $e) {
    $orderSources = ['error' => $e->getMessage()];
}

$schemaVersion = Install::installedSchemaVersion();
$env = App::config()['app']['env'] ?? 'unknown';

echo json_encode([
    'generated_at' => date('c'),
    'read_only' => true,
    'app_env' => $env,
    'schema_version' => $schemaVersion,
    'schema_ready' => Install::isSchemaReady(),
    'table_counts' => $tableCounts,
    'table_count_total' => count($tableRows),
    'evidence_markers' => $markers['counts'],
    'payments_by_gateway' => $payGw,
    'subscription_payments_by_gateway' => $subPayGw,
    'orders_by_source' => $orderSources,
    'demo_catalogue_slug_inventory' => [
        'product_slugs' => $demoSlugs,
        'category_slugs' => $demoCatSlugs,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
