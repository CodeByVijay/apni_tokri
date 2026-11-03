<?php

declare(strict_types=1);

/**
 * Phase 3 — read-only cleanup preview (SELECT only).
 *
 * CLI:
 *   php scripts/preview-phase3-cleanup.php
 *   php scripts/preview-phase3-cleanup.php --json > storage/phase3-cleanup-manifest.json
 *
 * Never executes DELETE, UPDATE, TRUNCATE, or ALTER.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

const PHASE3_READ_ONLY = true;

if (!Install::canConnect()) {
    fwrite(STDERR, "Database not connected.\n");
    exit(1);
}
Install::ensureConnection();
$pdo = Database::pdo();

$root = dirname(__DIR__);
$jsonOnly = in_array('--json', $argv ?? [], true);
$manifestPath = $root . '/storage/phase3-cleanup-manifest.json';

/** @return int */
function qcount(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

/** @return list<array<string, mixed>> */
function qrows(PDO $pdo, string $sql, array $params = [], int $limit = 500): array
{
    if ($limit > 0 && stripos($sql, ' LIMIT ') === false) {
        $sql .= ' LIMIT ' . (int) $limit;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array<string, mixed>> */
function fk_edges(PDO $pdo): array
{
    $sql = "SELECT TABLE_NAME AS child_table, COLUMN_NAME AS child_column,
                   REFERENCED_TABLE_NAME AS parent_table, REFERENCED_COLUMN_NAME AS parent_column,
                   CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY parent_table, child_table";

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$demoProductSlugs = [
    'fresh-red-tomatoes', 'green-capsicum', 'onion-red', 'banana-robusta', 'apple-shimla',
    'cut-mixed-salad', 'cut-french-beans', 'mango-pickle-homestyle', 'papad-moong',
];
$demoCategorySlugs = ['vegetables', 'fruits', 'cut', 'pickles'];

/**
 * Fixture-scoped scopes (Tier A). Each defines root SQL + evidence.
 *
 * @return list<array<string, mixed>>
 */
function tier_a_scopes(): array
{
    return [
        [
            'scope_id' => 'stage12_c1_orders',
            'label' => 'Stage 12 C1 — AT-C1FIX orders',
            'classification' => 'confirmed_qa_test',
            'fixture' => 'scripts/includes/stage12_phase_c1_fixture.php',
            'fixture_fn' => 'stage12_phase_c1_teardown',
            'marker' => "orders.order_number LIKE 'AT-C1FIX-%'",
            'root_table' => 'orders',
            'root_ids_sql' => "SELECT id, order_number FROM orders WHERE order_number LIKE 'AT-C1FIX-%' ORDER BY id",
        ],
        [
            'scope_id' => 'stage12_c1_refunds',
            'label' => 'Stage 12 C1 — RF-C1* refund requests',
            'classification' => 'confirmed_qa_test',
            'fixture' => 'scripts/includes/stage12_phase_c1_fixture.php',
            'marker' => "refund_requests.request_number LIKE 'RF-C1%'",
            'root_table' => 'refund_requests',
            'root_ids_sql' => "SELECT id, request_number, order_id FROM refund_requests WHERE request_number LIKE 'RF-C1%' ORDER BY id",
        ],
        [
            'scope_id' => 'stage12_c1_customers',
            'label' => 'Stage 12 C1 — 987C1* mobiles',
            'classification' => 'confirmed_qa_test',
            'fixture' => 'scripts/includes/stage12_phase_c1_fixture.php',
            'marker' => "customers.mobile LIKE '987C1%'",
            'root_table' => 'customers',
            'root_ids_sql' => "SELECT id, mobile, name FROM customers WHERE mobile LIKE '987C1%' ORDER BY id",
            'approval' => 'automatic_candidate_with_dependency_check',
            'note' => 'Only delete customers after C1 orders/subs removed; customer delete CASCADE wipes subscriptions.',
        ],
        [
            'scope_id' => 'stage12_c1_subscriptions',
            'label' => 'Stage 12 C1 — SUB-C1FIX subscriptions',
            'classification' => 'confirmed_qa_test',
            'fixture' => 'scripts/includes/stage12_phase_c1_fixture.php',
            'marker' => "customer_subscriptions.subscription_number LIKE 'SUB-C1FIX-%'",
            'root_table' => 'customer_subscriptions',
            'root_ids_sql' => "SELECT id, subscription_number, customer_id FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-C1FIX-%' ORDER BY id",
        ],
        [
            'scope_id' => 'stage12_c3_orders',
            'label' => 'Stage 12 C3 — AT-C3-* orders',
            'classification' => 'confirmed_qa_test',
            'fixture' => 'scripts/includes/stage12_phase_c3_fixture.php',
            'fixture_fn' => 'stage12_c3_cleanup',
            'marker' => "orders.order_number LIKE 'AT-C3-%'",
            'root_table' => 'orders',
            'root_ids_sql' => "SELECT id, order_number FROM orders WHERE order_number LIKE 'AT-C3-%' ORDER BY id",
        ],
        [
            'scope_id' => 'd2h_orders',
            'label' => 'Delivery D2 hardening — D2H-* orders',
            'classification' => 'confirmed_qa_test',
            'fixture' => 'scripts/includes/d2h_test_fixture.php',
            'fixture_fn' => 'd2h_test_cleanup',
            'marker' => "orders.order_number LIKE 'D2H-%'",
            'root_table' => 'orders',
            'root_ids_sql' => "SELECT id, order_number FROM orders WHERE order_number LIKE 'D2H-%' ORDER BY id",
        ],
        [
            'scope_id' => 'payment_init_at_pay',
            'label' => 'Payment initiation tests — AT-PAY-*',
            'classification' => 'confirmed_qa_test',
            'fixture' => 'scripts/test-payment-initiation.php',
            'marker' => "orders.order_number LIKE 'AT-PAY-%'",
            'root_table' => 'orders',
            'root_ids_sql' => "SELECT id, order_number FROM orders WHERE order_number LIKE 'AT-PAY-%' ORDER BY id",
        ],
        [
            'scope_id' => 'address_map_at_map',
            'label' => 'Address map pin tests — AT-MAP-*',
            'classification' => 'confirmed_qa_test',
            'fixture' => 'scripts/test-address-map-pin.php',
            'marker' => "orders.order_number LIKE 'AT-MAP-%'",
            'root_table' => 'orders',
            'root_ids_sql' => "SELECT id, order_number FROM orders WHERE order_number LIKE 'AT-MAP-%' ORDER BY id",
        ],
        [
            'scope_id' => 'account_pagination_sub_pg',
            'label' => 'Account list pagination — SUB-PG-* subs',
            'classification' => 'confirmed_qa_test',
            'fixture' => 'scripts/test-account-list-pagination.php',
            'marker' => "customer_subscriptions.subscription_number LIKE 'SUB-PG-%'",
            'root_table' => 'customer_subscriptions',
            'root_ids_sql' => "SELECT id, subscription_number FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-PG-%' ORDER BY id",
        ],
        [
            'scope_id' => 'sub_fulfil_sub_h',
            'label' => 'Subscription fulfilment fixture — SUB-H-*',
            'classification' => 'confirmed_qa_test',
            'fixture' => 'scripts/includes/subscription_test_fulfilment_fixture.php',
            'fixture_fn' => 'SubscriptionTestFulfilmentFixture::purgeTestSubscriptionsByNumberPrefix',
            'marker' => "customer_subscriptions.subscription_number LIKE 'SUB-H-%'",
            'root_table' => 'customer_subscriptions',
            'root_ids_sql' => "SELECT id, subscription_number FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-H-%' ORDER BY id",
        ],
        [
            'scope_id' => 'admin_delete_zt_sub',
            'label' => 'Admin delete test — ZT-SUB-*',
            'classification' => 'confirmed_qa_test',
            'fixture' => 'scripts/test-subscription-admin-delete.php',
            'marker' => "customer_subscriptions.subscription_number LIKE 'ZT-SUB-%'",
            'root_table' => 'customer_subscriptions',
            'root_ids_sql' => "SELECT id, subscription_number FROM customer_subscriptions WHERE subscription_number LIKE 'ZT-SUB-%' ORDER BY id",
        ],
        [
            'scope_id' => 'reports_at_r145',
            'label' => 'Reports phase 5 — AT-R145-* orders',
            'classification' => 'confirmed_qa_test',
            'fixture' => 'scripts/test-reports-stage14-phase5.php',
            'marker' => "orders.order_number LIKE 'AT-R145-%'",
            'root_table' => 'orders',
            'root_ids_sql' => "SELECT id, order_number FROM orders WHERE order_number LIKE 'AT-R145-%' ORDER BY id",
        ],
        [
            'scope_id' => 'customer_account_at_acct',
            'label' => 'Customer account tests — AT-ACCT*',
            'classification' => 'confirmed_qa_test',
            'fixture' => 'scripts/test-customer-account.php',
            'marker' => "orders.order_number LIKE 'AT-ACCT%'",
            'root_table' => 'orders',
            'root_ids_sql' => "SELECT id, order_number FROM orders WHERE order_number LIKE 'AT-ACCT%' ORDER BY id",
        ],
        [
            'scope_id' => 'stage13_cms_slugs',
            'label' => 'Stage 13 CMS fixture slugs at-cms13-%',
            'classification' => 'confirmed_qa_test',
            'fixture' => 'scripts/includes/stage13_phase1_fixture.php',
            'marker' => "cms_pages.slug LIKE 'at-cms13-%' OR products.slug LIKE 'at-cms13-%'",
            'root_table' => 'cms_pages',
            'root_ids_sql' => "SELECT id, slug, title FROM cms_pages WHERE slug LIKE 'at-cms13-%' ORDER BY id",
        ],
        [
            'scope_id' => 'stage13_product_at_prefix',
            'label' => 'Stage 13/14 CLI product slugs at-%',
            'classification' => 'likely_qa_verify',
            'fixture' => 'scripts/includes/stage13_phase*_fixture.php',
            'marker' => "products.slug LIKE 'at-%'",
            'root_table' => 'products',
            'root_ids_sql' => "SELECT id, slug, name FROM products WHERE slug LIKE 'at-%' ORDER BY id",
            'approval' => 'manual_review_before_delete',
            'note' => 'May reference order_items; check FK before product delete.',
        ],
        [
            'scope_id' => 'rbac14_test_roles',
            'label' => 'RBAC stage 14 test roles at-rbac14-%',
            'classification' => 'confirmed_qa_test',
            'fixture' => 'scripts/includes/stage14_phase1_fixture.php',
            'marker' => "admin_roles.slug LIKE 'at-rbac14-%'",
            'root_table' => 'admin_roles',
            'root_ids_sql' => "SELECT id, slug, name FROM admin_roles WHERE slug LIKE 'at-rbac14-%' ORDER BY id",
        ],
    ];
}

/** Dependent row counts for order roots. */
function order_dependent_counts(PDO $pdo, string $orderIdSubquery): array
{
    return [
        'order_items' => qcount($pdo, "SELECT COUNT(*) FROM order_items WHERE order_id IN ({$orderIdSubquery})"),
        'order_status_history' => qcount($pdo, "SELECT COUNT(*) FROM order_status_history WHERE order_id IN ({$orderIdSubquery})"),
        'payments' => qcount($pdo, "SELECT COUNT(*) FROM payments WHERE order_id IN ({$orderIdSubquery})"),
        'payment_refunds_via_payment' => qcount(
            $pdo,
            "SELECT COUNT(*) FROM payment_refunds pr INNER JOIN payments p ON p.id = pr.payment_id WHERE p.order_id IN ({$orderIdSubquery})"
        ),
        'deliveries' => qcount($pdo, "SELECT COUNT(*) FROM deliveries WHERE order_id IN ({$orderIdSubquery})"),
        'delivery_status_history' => qcount(
            $pdo,
            "SELECT COUNT(*) FROM delivery_status_history h INNER JOIN deliveries d ON d.id = h.delivery_id WHERE d.order_id IN ({$orderIdSubquery})"
        ),
        'refund_requests' => qcount($pdo, "SELECT COUNT(*) FROM refund_requests WHERE order_id IN ({$orderIdSubquery})"),
        'invoices' => qcount($pdo, "SELECT COUNT(*) FROM invoices WHERE order_id IN ({$orderIdSubquery})"),
        'delivery_slot_reservations' => qcount($pdo, "SELECT COUNT(*) FROM delivery_slot_reservations WHERE order_id IN ({$orderIdSubquery})"),
    ];
}

/** Dependent counts for refund_request roots. */
function refund_dependent_counts(PDO $pdo, string $rrSubquery): array
{
    return [
        'refund_request_items' => qcount($pdo, "SELECT COUNT(*) FROM refund_request_items WHERE refund_request_id IN ({$rrSubquery})"),
        'refund_request_history' => qcount($pdo, "SELECT COUNT(*) FROM refund_request_history WHERE refund_request_id IN ({$rrSubquery})"),
        'refund_request_evidence' => qcount($pdo, "SELECT COUNT(*) FROM refund_request_evidence WHERE refund_request_id IN ({$rrSubquery})"),
        'payment_refunds' => qcount($pdo, "SELECT COUNT(*) FROM payment_refunds WHERE refund_request_id IN ({$rrSubquery})"),
    ];
}

/** Subscription subgraph counts for subscription id subquery. */
function subscription_dependent_counts(PDO $pdo, string $subIdSubquery): array
{
    $daySub = "SELECT id FROM subscription_delivery_days WHERE customer_subscription_id IN ({$subIdSubquery})";
    $orderSub = "SELECT id FROM orders WHERE subscription_delivery_day_id IN ({$daySub})";

    return [
        'subscription_payments' => qcount($pdo, "SELECT COUNT(*) FROM subscription_payments WHERE customer_subscription_id IN ({$subIdSubquery})"),
        'subscription_delivery_days' => qcount($pdo, "SELECT COUNT(*) FROM subscription_delivery_days WHERE customer_subscription_id IN ({$subIdSubquery})"),
        'subscription_delivery_baskets' => qcount($pdo, "SELECT COUNT(*) FROM subscription_delivery_baskets WHERE subscription_delivery_day_id IN ({$daySub})"),
        'subscription_delivery_basket_items' => qcount(
            $pdo,
            "SELECT COUNT(*) FROM subscription_delivery_basket_items bi
             INNER JOIN subscription_delivery_baskets b ON b.id = bi.basket_id
             WHERE b.subscription_delivery_day_id IN ({$daySub})"
        ),
        'subscription_stock_reservations' => qcount($pdo, "SELECT COUNT(*) FROM subscription_stock_reservations WHERE subscription_delivery_day_id IN ({$daySub})"),
        'subscription_fulfilment_events' => qcount($pdo, "SELECT COUNT(*) FROM subscription_fulfilment_events WHERE subscription_delivery_day_id IN ({$daySub})"),
        'subscription_fulfilment_orders' => qcount($pdo, "SELECT COUNT(*) FROM orders WHERE subscription_delivery_day_id IN ({$daySub})"),
        'subscription_fulfilment_deliveries' => qcount(
            $pdo,
            "SELECT COUNT(*) FROM deliveries WHERE order_id IN ({$orderSub})"
        ),
        'subscription_skips' => qcount($pdo, "SELECT COUNT(*) FROM subscription_skips WHERE customer_subscription_id IN ({$subIdSubquery})"),
        'subscription_pauses' => qcount($pdo, "SELECT COUNT(*) FROM subscription_pauses WHERE customer_subscription_id IN ({$subIdSubquery})"),
    ];
}

/** Customer-wide activity (for C1 customer scope safety). */
function customer_activity_counts(PDO $pdo, string $custSubquery): array
{
    return [
        'orders' => qcount($pdo, "SELECT COUNT(*) FROM orders WHERE customer_id IN ({$custSubquery})"),
        'customer_subscriptions' => qcount($pdo, "SELECT COUNT(*) FROM customer_subscriptions WHERE customer_id IN ({$custSubquery})"),
        'non_c1_orders' => qcount(
            $pdo,
            "SELECT COUNT(*) FROM orders WHERE customer_id IN ({$custSubquery}) AND order_number NOT LIKE 'AT-C1FIX-%'"
        ),
    ];
}

$scopesOut = [];
$tierATotalRoots = 0;

foreach (tier_a_scopes() as $scope) {
    $roots = qrows($pdo, $scope['root_ids_sql'], [], 2000);
    $rootCount = count($roots);
    $tierATotalRoots += $rootCount;

    $entry = [
        'scope_id' => $scope['scope_id'],
        'label' => $scope['label'],
        'classification' => $scope['classification'],
        'evidence' => [
            'fixture' => $scope['fixture'] ?? null,
            'fixture_teardown' => $scope['fixture_fn'] ?? null,
            'marker' => $scope['marker'],
        ],
        'proposed_operation' => $rootCount > 0 ? 'DELETE scoped by marker (future phase — not executed here)' : 'none — zero rows match',
        'approval' => $scope['approval'] ?? ($rootCount > 0 ? 'automatic_candidate' : 'none'),
        'backup_requirement' => $rootCount > 0 ? 'table_level_export_before_delete' : 'none',
        'rollback' => 'restore from mysqldump table extract or full DB backup',
        'root_table' => $scope['root_table'],
        'root_row_count' => $rootCount,
        'root_records_sample' => array_slice($roots, 0, 25),
        'dependent_row_counts' => [],
        'cleanup_order_hint' => [],
        'notes' => $scope['note'] ?? null,
    ];

    if ($rootCount > 0 && $scope['root_table'] === 'orders') {
        $ids = array_column($roots, 'id');
        $idList = implode(',', array_map('intval', $ids));
        $subq = "SELECT id FROM orders WHERE id IN ({$idList})";
        $entry['dependent_row_counts'] = order_dependent_counts($pdo, $subq);
        $entry['cleanup_order_hint'] = [
            'payment_refunds (via payments/refund_requests)',
            'refund_request_* children',
            'payments',
            'delivery_status_history → deliveries',
            'order_items, order_status_history',
            'invoices (if any — manual if linked)',
            'orders',
        ];
    } elseif ($rootCount > 0 && $scope['root_table'] === 'refund_requests') {
        $ids = array_column($roots, 'id');
        $idList = implode(',', array_map('intval', $ids));
        $subq = "SELECT id FROM refund_requests WHERE id IN ({$idList})";
        $entry['dependent_row_counts'] = refund_dependent_counts($pdo, $subq);
        $entry['cleanup_order_hint'] = [
            'payment_refunds',
            'refund_request_evidence, refund_request_items, refund_request_history',
            'refund_requests',
        ];
    } elseif ($rootCount > 0 && $scope['root_table'] === 'customer_subscriptions') {
        $ids = array_column($roots, 'id');
        $idList = implode(',', array_map('intval', $ids));
        $subq = "SELECT id FROM customer_subscriptions WHERE id IN ({$idList})";
        $entry['dependent_row_counts'] = subscription_dependent_counts($pdo, $subq);
        $entry['cleanup_order_hint'] = [
            'deliveries / delivery_status_history for fulfilment orders',
            'orders (subscription_delivery_day_id)',
            'subscription_stock_reservations, fulfilment_events, baskets (often CASCADE from days/subs)',
            'subscription_payments',
            'customer_subscriptions',
        ];
    } elseif ($rootCount > 0 && $scope['root_table'] === 'customers') {
        $ids = array_column($roots, 'id');
        $idList = implode(',', array_map('intval', $ids));
        $subq = "SELECT id FROM customers WHERE id IN ({$idList})";
        $entry['dependent_row_counts'] = customer_activity_counts($pdo, $subq);
        $entry['cleanup_order_hint'] = [
            'Complete C1 order/refund/sub teardown first',
            'Verify non_c1_orders = 0 before customer DELETE',
            'customers (ON DELETE CASCADE to subscriptions — dangerous)',
        ];
    }

    $scopesOut[] = $entry;
}

// --- Tier B / bulk QA (manual approval) ---
$allSubSimulate = qcount($pdo, "SELECT COUNT(*) FROM subscription_payments WHERE gateway = 'simulate'");
$allSubs = qcount($pdo, 'SELECT COUNT(*) FROM customer_subscriptions');
$subsSimulateOnly = qcount(
    $pdo,
    "SELECT COUNT(*) FROM customer_subscriptions cs
     WHERE NOT EXISTS (
       SELECT 1 FROM subscription_payments sp
       WHERE sp.customer_subscription_id = cs.id AND sp.gateway <> 'simulate'
     )
     AND EXISTS (
       SELECT 1 FROM subscription_payments sp2 WHERE sp2.customer_subscription_id = cs.id
     )"
);
$subsNoPayment = qcount(
    $pdo,
    "SELECT COUNT(*) FROM customer_subscriptions cs
     WHERE NOT EXISTS (SELECT 1 FROM subscription_payments sp WHERE sp.customer_subscription_id = cs.id)"
);

$storefrontSimulateOrders = qcount(
    $pdo,
    "SELECT COUNT(DISTINCT o.id) FROM orders o
     INNER JOIN payments p ON p.order_id = o.id AND p.gateway = 'simulate' AND p.status = 'captured'
     WHERE o.order_source = 'storefront'"
);
$storefrontOrders = qcount($pdo, "SELECT COUNT(*) FROM orders WHERE order_source = 'storefront'");
$subscriptionOrders = qcount($pdo, "SELECT COUNT(*) FROM orders WHERE order_source = 'subscription'");

$realGatewayPayments = qcount($pdo, "SELECT COUNT(*) FROM payments WHERE gateway IN ('razorpay', 'cashfree')");
$realGatewayCaptured = qcount(
    $pdo,
    "SELECT COUNT(*) FROM payments WHERE gateway IN ('razorpay', 'cashfree') AND status IN ('captured', 'refunded', 'partially_refunded')"
);

$testimonialsPending = qcount($pdo, "SELECT COUNT(*) FROM testimonials WHERE status = 'pending'");
$supportTickets = qcount($pdo, 'SELECT COUNT(*) FROM support_tickets');
$supportOpen = qcount($pdo, "SELECT COUNT(*) FROM support_tickets WHERE status = 'open'");

$webhookEvents = qcount($pdo, 'SELECT COUNT(*) FROM payment_webhook_events');
$menuTemplates = qcount($pdo, 'SELECT COUNT(*) FROM subscription_menu_templates');

$productSlugIn = implode(',', array_map(fn ($s) => $pdo->quote($s), $demoProductSlugs));
$demoProducts = qcount($pdo, "SELECT COUNT(*) FROM products WHERE slug IN ({$productSlugIn})");
$productsWithOrderLines = qcount(
    $pdo,
    "SELECT COUNT(DISTINCT p.id) FROM products p
     INNER JOIN product_variants pv ON pv.product_id = p.id
     INNER JOIN order_items oi ON oi.variant_id = pv.id
     WHERE p.slug IN ({$productSlugIn})"
);

$invoiceCount = qcount($pdo, 'SELECT COUNT(*) FROM invoices');
$invoiceLinkedOrders = qrows(
    $pdo,
    'SELECT i.id, i.public_ref, i.invoice_number, i.order_id, o.order_number
     FROM invoices i INNER JOIN orders o ON o.id = i.order_id ORDER BY i.id',
    [],
    50
);

$integrationFixture = qrows(
    $pdo,
    "SELECT o.id, o.order_number, o.customer_id, p.gateway, p.status
     FROM orders o
     LEFT JOIN payments p ON p.order_id = o.id
     WHERE o.order_number = 'AT-261102-5DB0C9' LIMIT 1"
);

$ztPlans = qrows(
    $pdo,
    "SELECT id, name, template_key, is_default_template FROM subscription_plans WHERE name LIKE '%755294%' OR template_key LIKE '%755294%' ORDER BY id",
    [],
    20
);

$bulkScopes = [
    [
        'scope_id' => 'bulk_subscription_qa_graph',
        'label' => 'Subscription QA bulk (simulate-only payments)',
        'classification' => 'qa_debug_generated',
        'approval' => 'engineering_and_finance_signoff',
        'proposed_operation' => 'Scoped purge: subscriptions with only simulate subscription_payments + fulfilment subgraph (staging clone first)',
        'root_row_count' => $allSubs,
        'evidence' => [
            'subscription_payments_simulate' => $allSubSimulate,
            'customer_subscriptions_total' => $allSubs,
            'subs_with_only_simulate_payments' => $subsSimulateOnly,
            'subs_with_no_subscription_payment_row' => $subsNoPayment,
            'subscription_sourced_orders' => $subscriptionOrders,
            'subscription_delivery_days' => qcount($pdo, 'SELECT COUNT(*) FROM subscription_delivery_days'),
            'subscription_stock_reservations' => qcount($pdo, 'SELECT COUNT(*) FROM subscription_stock_reservations'),
        ],
        'excluded_until_approved' => [
            'Any subscription linked to non-simulate subscription_payment',
            'Any subscription with invoice on fulfilment order',
        ],
        'cleanup_order_hint' => [
            'See docs/PHASE3_CLEANUP_PLAN.md § FK-ordered subscription teardown',
            'Never delete subscription_stock_reservations without fulfilment order context',
        ],
    ],
    [
        'scope_id' => 'bulk_testimonials_pending',
        'label' => 'Pending testimonials (stage tests)',
        'classification' => 'qa_debug_generated',
        'approval' => 'manual_review',
        'proposed_operation' => 'DELETE testimonials WHERE status = pending AND no production display policy',
        'root_row_count' => $testimonialsPending,
        'evidence' => ['all_pending' => $testimonialsPending, 'source' => 'test-trust-stage12, stage13 fixtures'],
    ],
    [
        'scope_id' => 'bulk_support_tickets',
        'label' => 'Support tickets from QA scripts',
        'classification' => 'qa_debug_generated',
        'approval' => 'manual_review',
        'proposed_operation' => 'DELETE support_messages then support_tickets for confirmed QA subjects/customers only',
        'root_row_count' => $supportTickets,
        'evidence' => ['open_tickets' => $supportOpen, 'source' => 'test-support-access.php, test-admin-support-notifications.php'],
        'dependent_row_counts' => ['support_messages' => qcount($pdo, 'SELECT COUNT(*) FROM support_messages')],
    ],
    [
        'scope_id' => 'bulk_payment_webhook_events',
        'label' => 'Payment webhook event log (replay tests)',
        'classification' => 'qa_debug_generated',
        'approval' => 'manual_review',
        'proposed_operation' => 'Archive or TRUNCATE on staging only after confirming no audit requirement',
        'root_row_count' => $webhookEvents,
    ],
    [
        'scope_id' => 'bulk_subscription_menu_templates',
        'label' => 'Subscription menu planner templates (test runs)',
        'classification' => 'qa_debug_generated',
        'approval' => 'manual_review',
        'proposed_operation' => 'DELETE templates not tied to plans 1–3 if confirmed test-only',
        'root_row_count' => $menuTemplates,
        'evidence' => ['subscription_menu_template_items' => qcount($pdo, 'SELECT COUNT(*) FROM subscription_menu_template_items')],
    ],
    [
        'scope_id' => 'bulk_storefront_simulate_orders',
        'label' => 'Storefront orders with captured simulate payment',
        'classification' => 'likely_qa_verify',
        'approval' => 'manual_review_each_order',
        'proposed_operation' => 'Delete per-order after confirming no invoice / no real gateway payment',
        'root_row_count' => $storefrontSimulateOrders,
        'evidence' => [
            'storefront_orders_total' => $storefrontOrders,
            'with_simulate_captured' => $storefrontSimulateOrders,
            'note' => 'AT-{ymd}-{token} is production format — do not bulk-delete all storefront orders',
        ],
    ],
];

$protectScopes = [
    [
        'scope_id' => 'protect_real_gateway_payments',
        'classification' => 'genuine_business_or_unknown',
        'approval' => 'do_not_delete',
        'root_row_count' => $realGatewayPayments,
        'evidence' => [
            'payments_razorpay_cashfree' => $realGatewayPayments,
            'captured_or_refunded' => $realGatewayCaptured,
        ],
    ],
    [
        'scope_id' => 'protect_invoices',
        'classification' => 'unknown_manual_review',
        'approval' => 'do_not_delete_without_finance',
        'root_row_count' => $invoiceCount,
        'records' => $invoiceLinkedOrders,
        'evidence' => ['source' => 'scripts/test-invoices.php likely'],
    ],
    [
        'scope_id' => 'protect_settings_and_admin',
        'classification' => 'genuine_business',
        'approval' => 'never_bulk_delete',
        'evidence' => [
            'settings_rows' => qcount($pdo, 'SELECT COUNT(*) FROM settings'),
            'admin_users' => qcount($pdo, 'SELECT COUNT(*) FROM admin_users'),
            'invoice_sequences' => qcount($pdo, 'SELECT COUNT(*) FROM invoice_sequences'),
        ],
    ],
    [
        'scope_id' => 'protect_demo_catalogue_with_order_refs',
        'classification' => 'confirmed_demo_seed',
        'approval' => 'content_migration_not_row_delete',
        'evidence' => [
            'demo_product_slugs' => $demoProductSlugs,
            'demo_products_count' => $demoProducts,
            'demo_products_with_order_items' => $productsWithOrderLines,
            'source' => 'scripts/seed-catalogue.php',
        ],
        'proposed_operation' => 'Replace catalogue via admin/import; do not DELETE products referenced by order_items',
    ],
    [
        'scope_id' => 'integration_fixture_cart_post_payment',
        'classification' => 'unknown_manual_review',
        'approval' => 'manual_review',
        'evidence' => [
            'script' => 'scripts/test-cart-order-post-payment.php',
            'order_number' => 'AT-261102-5DB0C9',
            'customer_id_in_script' => 80,
        ],
        'records' => $integrationFixture,
    ],
    [
        'scope_id' => 'subscription_plans_zt_test',
        'classification' => 'confirmed_qa_test',
        'approval' => 'manual_review',
        'evidence' => ['test-subscription-admin-delete.php'],
        'records' => $ztPlans,
    ],
];

// Classification totals (candidate rows for future DELETE — not overlapping dedupe)
$classificationTotals = [
    'confirmed_qa_test' => 0,
    'confirmed_demo_seed' => (int) $demoProducts,
    'likely_qa_verify' => (int) $storefrontSimulateOrders,
    'qa_debug_generated' => (int) ($allSubs + $testimonialsPending + $supportTickets + $webhookEvents),
    'genuine_business' => (int) qcount($pdo, 'SELECT COUNT(*) FROM admin_users') + (int) qcount($pdo, 'SELECT COUNT(*) FROM settings'),
    'unknown_manual_review' => (int) ($storefrontOrders - $storefrontSimulateOrders) + (int) $invoiceCount,
];

foreach ($scopesOut as $s) {
    if (($s['classification'] ?? '') === 'confirmed_qa_test') {
        $classificationTotals['confirmed_qa_test'] += (int) ($s['root_row_count'] ?? 0);
    }
    if (($s['classification'] ?? '') === 'likely_qa_verify') {
        $classificationTotals['likely_qa_verify'] += (int) ($s['root_row_count'] ?? 0);
    }
}

$manifest = [
    'generated_at' => date('c'),
    'read_only' => true,
    'phase' => '3_cleanup_planning',
    'app_env' => App::config()['app']['env'] ?? 'unknown',
    'schema_version' => Install::installedSchemaVersion(),
    'audit_snapshot' => $root . '/storage/audit-demo-test-data.json',
    'classification_totals' => $classificationTotals,
    'classification_legend' => [
        'confirmed_qa_test' => 'Named fixture/script marker present in DB',
        'confirmed_demo_seed' => 'seed-catalogue.php / DemoCatalogue slugs',
        'likely_qa_verify' => 'Heuristic (simulate gateway, bulk) — verify before delete',
        'qa_debug_generated' => 'Bulk automated suite side effects',
        'genuine_business' => 'Config, admin, or non-simulate payments — protect',
        'unknown_manual_review' => 'Insufficient marker — human decision',
    ],
    'tier_a_fixture_scopes' => $scopesOut,
    'tier_b_bulk_scopes' => $bulkScopes,
    'protected_and_manual' => $protectScopes,
    'foreign_key_graph' => fk_edges($pdo),
    'recommended_cleanup_order' => [
        '1_tier_a' => [
            'refund_request children → refund_requests (RF-C1*)',
            'payment_refunds → payments (pay_c1fix_*, simulate C3)',
            'delivery_status_history → deliveries → order_items → orders (prefix-scoped)',
            'subscription fulfilment orders/deliveries → subscription days → subscription_payments → customer_subscriptions (prefix-scoped)',
            'customers (987C1* only after scoped orders/subs gone)',
            'stage13 slug-scoped CMS/products (after order_items check)',
            'admin_roles at-rbac14-*',
        ],
        '2_tier_b' => 'Only after backup + signoff on staging clone',
        '3_never_without_policy' => ['settings', 'admin_users', 'invoice_sequences', 'invoices', 'razorpay/cashfree payments'],
    ],
    'backup_plan' => [
        'full_logical' => 'mysqldump --single-transaction --routines --triggers DB_NAME > apni_tokri_pre_cleanup_YYYYMMDD.sql',
        'verify' => 'mysql -e "CREATE DATABASE restore_test" && mysql restore_test < dump && php scripts/audit-demo-test-data-readonly.php',
        'table_level_finance' => [
            'orders', 'order_items', 'payments', 'payment_refunds', 'refund_requests',
            'invoices', 'invoice_sequences', 'subscription_payments', 'customer_subscriptions',
        ],
        'rollback' => 'DROP DATABASE / recreate from full dump; document pre/post audit JSON',
        'pit' => 'Enable binary log PITR on production before any cleanup',
    ],
];

file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

if ($jsonOnly) {
    echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

echo "Phase 3 cleanup preview (READ-ONLY)\n";
echo str_repeat('=', 60) . "\n";
echo 'Manifest: ' . $manifestPath . "\n";
echo 'Schema: ' . (Install::installedSchemaVersion() ?? '?') . ' | env: ' . ($manifest['app_env']) . "\n\n";

echo "Classification totals (candidate / protected rows — see manifest for overlap):\n";
foreach ($classificationTotals as $k => $v) {
    echo "  {$k}: {$v}\n";
}

echo "\n--- Tier A fixture scopes (automatic candidates) ---\n";
foreach ($scopesOut as $s) {
    echo sprintf(
        "[%s] %s — roots: %d (%s)\n",
        $s['scope_id'],
        $s['label'],
        $s['root_row_count'],
        $s['approval']
    );
    if ($s['root_row_count'] > 0 && $s['dependent_row_counts'] !== []) {
        foreach ($s['dependent_row_counts'] as $tbl => $cnt) {
            if ($cnt > 0) {
                echo "    └─ {$tbl}: {$cnt}\n";
            }
        }
    }
}

echo "\n--- Tier B bulk (manual approval) ---\n";
foreach ($bulkScopes as $b) {
    echo sprintf("  %s: %d rows — %s\n", $b['scope_id'], $b['root_row_count'], $b['approval']);
}

echo "\n--- Protected / manual review ---\n";
foreach ($protectScopes as $p) {
    $rc = $p['root_row_count'] ?? 'n/a';
    echo "  {$p['scope_id']}: {$p['approval']} (count/ref: {$rc})\n";
}

echo "\nNo DELETE/UPDATE executed. Re-run audit: php scripts/audit-demo-test-data-readonly.php\n";
exit(0);
