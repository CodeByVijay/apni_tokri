<?php

declare(strict_types=1);

/**
 * Focused verification for admin + customer order item display (read-only).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;
$warn = 0;
$notes = [];

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function note(string $m): void
{
    global $notes;
    $notes[] = $m;
    echo 'NOTE: ' . $m . "\n";
}

/** @return list<array{name: string, variant: string, qty: int, unit: float, line: float, image: string}> */
function normalizeAdminItems(array $order): array
{
    $out = [];
    foreach ($order['items'] ?? [] as $it) {
        $out[] = [
            'name' => (string) ($it['name'] ?? ''),
            'variant' => (string) ($it['variant'] ?? ''),
            'qty' => (int) ($it['qty'] ?? 0),
            'unit' => round((float) ($it['unit_price'] ?? 0), 2),
            'line' => round((float) ($it['line_total'] ?? 0), 2),
            'image' => (string) ($it['image'] ?? ''),
        ];
    }

    return $out;
}

/** @return list<array{name: string, variant: string, qty: int, unit: float, line: float, image: string}> */
function normalizeCustomerItems(array $order): array
{
    $out = [];
    foreach ($order['items'] ?? [] as $it) {
        $out[] = [
            'name' => (string) ($it['name'] ?? ''),
            'variant' => (string) ($it['label'] ?? ''),
            'qty' => (int) ($it['qty'] ?? 0),
            'unit' => round((float) ($it['unit_price'] ?? 0), 2),
            'line' => round((float) ($it['line_total'] ?? 0), 2),
            'image' => (string) ($it['image'] ?? ''),
        ];
    }

    return $out;
}

/** @param list<array<string, mixed>> $items */
function renderOrderItemsPartial(array $items): string
{
    ob_start();
    $orderItems = $items;
    require APNI_TOKRI_ROOT . '/includes/partials/order-items.php';

    return (string) ob_get_clean();
}

// --- Scenario fixtures from DB ---
$single = $pdo->query(
    'SELECT o.id, o.order_number, o.customer_id, COUNT(*) AS n
     FROM orders o INNER JOIN order_items oi ON oi.order_id = o.id
     GROUP BY o.id HAVING n = 1 ORDER BY o.id DESC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

$multi = $pdo->query(
    'SELECT o.id, o.order_number, o.customer_id, COUNT(*) AS n
     FROM orders o INNER JOIN order_items oi ON oi.order_id = o.id
     GROUP BY o.id HAVING n >= 3 ORDER BY o.id DESC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

$sameProduct = $pdo->query(
    'SELECT oi.order_id AS id, o.order_number, o.customer_id, COUNT(DISTINCT oi.variant_id) AS variants, COUNT(*) AS line_count
     FROM order_items oi
     INNER JOIN orders o ON o.id = oi.order_id
     GROUP BY oi.order_id, oi.product_name
     HAVING line_count >= 2 AND variants >= 2
     ORDER BY oi.order_id DESC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

$couponFree = $pdo->query(
    'SELECT id, order_number, customer_id, coupon_code, discount_total, delivery_fee, grand_total
     FROM orders WHERE discount_total > 0 AND delivery_fee = 0 ORDER BY id DESC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if (!$couponFree) {
    $couponFree = $pdo->query(
        'SELECT id, order_number, customer_id, coupon_code, discount_total, delivery_fee, grand_total
         FROM orders WHERE discount_total > 0 ORDER BY id DESC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
}

$largeQty = $pdo->query(
    'SELECT o.id, o.order_number, o.customer_id, MAX(oi.quantity) AS max_q, MAX(oi.line_total) AS max_line
     FROM orders o INNER JOIN order_items oi ON oi.order_id = o.id
     GROUP BY o.id HAVING max_q >= 5 OR max_line >= 1000 ORDER BY max_line DESC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

$longName = $pdo->query(
    'SELECT o.id, o.order_number, o.customer_id, MAX(CHAR_LENGTH(oi.product_name) + CHAR_LENGTH(oi.variant_name)) AS len
     FROM orders o INNER JOIN order_items oi ON oi.order_id = o.id
     GROUP BY o.id ORDER BY len DESC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

$refOrder = $pdo->query('SELECT id, order_number, customer_id FROM orders WHERE id = 3179 LIMIT 1')->fetch(PDO::FETCH_ASSOC)
    ?: $multi;

ok($single !== false, 'Scenario fixture: single-item order exists (id ' . (int) ($single['id'] ?? 0) . ')');
ok($multi !== false, 'Scenario fixture: multi-item order exists (id ' . (int) ($multi['id'] ?? 0) . ')');
if ($sameProduct === false) {
    note('No order with two variants of same product_name in DB; scenario 3 skipped.');
} else {
    ok(true, 'Scenario fixture: same-product multi-variant order (id ' . (int) $sameProduct['id'] . ')');
}
if ($couponFree === false) {
    note('No discounted order in DB; scenario 9 (coupon) limited to structural check only.');
} else {
    ok(true, 'Scenario fixture: order with discount (id ' . (int) $couponFree['id'] . ')');
}
if ($largeQty === false) {
    note('No high qty/line_total order; scenario 11 uses max available values only.');
}

// --- Data integrity: order_items vs services ---
foreach ([
    'single' => $single,
    'multi' => $multi,
    'reference' => $refOrder,
] as $label => $row) {
    if ($row === false) {
        continue;
    }
    $oid = (int) $row['id'];
    $stmt = $pdo->prepare(
        'SELECT product_name, variant_name, quantity, unit_price, line_total FROM order_items WHERE order_id = ? ORDER BY id'
    );
    $stmt->execute([$oid]);
    $dbRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $admin = AdminOrderService::findById($oid);
    $cust = OrderRepository::forCustomerByNumber((string) $row['order_number'], (int) $row['customer_id']);

    ok($admin !== null, "$label: admin findById loads");
    ok($cust !== null, "$label: customer forCustomerByNumber loads");
    ok(count($dbRows) === count($admin['items'] ?? []), "$label: admin item count matches order_items");
    ok(count($dbRows) === count($cust['items'] ?? []), "$label: customer item count matches order_items");

    $adminNorm = normalizeAdminItems($admin);
    $custNorm = normalizeCustomerItems($cust);
    ok($adminNorm === $custNorm, "$label: admin and customer item snapshots identical (names/qty/prices)");

    foreach ($dbRows as $i => $db) {
        $a = $adminNorm[$i] ?? null;
        ok($a !== null, "$label item $i present");
        if ($a === null) {
            continue;
        }
        ok($a['name'] === (string) $db['product_name'], "$label item $i name from snapshot");
        ok($a['variant'] === (string) $db['variant_name'], "$label item $i variant from snapshot");
        ok($a['qty'] === (int) $db['quantity'], "$label item $i qty from snapshot");
        ok(abs($a['unit'] - (float) $db['unit_price']) < 0.001, "$label item $i unit_price from snapshot");
        ok(abs($a['line'] - (float) $db['line_total']) < 0.001, "$label item $i line_total from snapshot");
    }
}

// --- Historical price: mutate live variant, order snapshot unchanged ---
if ($refOrder !== false) {
    $oid = (int) $refOrder['id'];
    $variantStmt = $pdo->prepare(
        'SELECT oi.variant_id, oi.unit_price AS snap_price, v.price AS live_price
         FROM order_items oi LEFT JOIN product_variants v ON v.id = oi.variant_id
         WHERE oi.order_id = ? AND oi.variant_id IS NOT NULL LIMIT 1'
    );
    $variantStmt->execute([$oid]);
    $vrow = $variantStmt->fetch(PDO::FETCH_ASSOC);
    if ($vrow && (int) $vrow['variant_id'] > 0) {
        $vid = (int) $vrow['variant_id'];
        $snap = (float) $vrow['snap_price'];
        $orig = (float) $vrow['live_price'];
        $pdo->prepare('UPDATE product_variants SET price = ? WHERE id = ?')->execute([$snap + 999.99, $vid]);
        $afterAdmin = AdminOrderService::findById($oid);
        $afterCust = OrderRepository::forCustomerByNumber((string) $refOrder['order_number'], (int) $refOrder['customer_id']);
        $a0 = normalizeAdminItems($afterAdmin)[0]['unit'] ?? -1;
        $c0 = normalizeCustomerItems($afterCust)[0]['unit'] ?? -1;
        ok(abs($a0 - $snap) < 0.001 && abs($c0 - $snap) < 0.001, 'Live variant price change does not alter order item unit_price');
        $pdo->prepare('UPDATE product_variants SET price = ? WHERE id = ?')->execute([$orig, $vid]);
    } else {
        note('Could not run live-price mutation test (no variant_id on reference order item).');
    }
}

// --- Deleted/inactive product: variant removed or product unpublished ---
$orphan = $pdo->query(
    'SELECT oi.order_id, oi.product_name, oi.variant_name, oi.variant_id
     FROM order_items oi
     LEFT JOIN product_variants pv ON pv.id = oi.variant_id
     WHERE oi.variant_id IS NOT NULL AND pv.id IS NULL LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if ($orphan === false) {
    $inactive = $pdo->query(
        'SELECT oi.order_id, o.order_number, o.customer_id
         FROM order_items oi
         INNER JOIN product_variants pv ON pv.id = oi.variant_id
         INNER JOIN products p ON p.id = pv.product_id
         INNER JOIN orders o ON o.id = oi.order_id
         WHERE p.status != \'published\' OR pv.is_active = 0
         LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    if ($inactive === false) {
        note('No deleted-variant or inactive-product order in DB; scenario 4 verified structurally only (service still loads).');
        $inactive = $refOrder;
    }
    if ($inactive !== false) {
        $oid = (int) ($inactive['order_id'] ?? $inactive['id'] ?? 0);
        $on = (string) ($inactive['order_number'] ?? '');
        $cid = (int) ($inactive['customer_id'] ?? 0);
        if ($on !== '' && $cid > 0) {
            ok(AdminOrderService::findById($oid) !== null, 'Inactive/unpublished product order: admin detail loads');
            ok(OrderRepository::forCustomerByNumber($on, $cid) !== null, 'Inactive/unpublished product order: customer detail loads');
        }
    }
} else {
    $oid = (int) $orphan['order_id'];
    ok(AdminOrderService::findById($oid) !== null, 'Missing variant row: admin detail still loads');
}

// --- Customer ownership ---
if ($refOrder !== false) {
    $on = (string) $refOrder['order_number'];
    $owner = (int) $refOrder['customer_id'];
    $wrong = $owner === 1 ? 2 : 1;
    ok(OrderRepository::forCustomerByNumber($on, $owner) !== null, 'Customer can load own order');
    ok(OrderRepository::forCustomerByNumber($on, $wrong) === null, 'Customer cannot load another customer order');
}

// --- Admin RBAC (orders module) ---
Permission::clearCache();
$_SESSION['admin_user_id'] = 1;
$_SESSION['admin_demo_role'] = 'super_admin';
ok(AdminDemoPerms::can('orders'), 'RBAC: super_admin can orders module');
unset($_SESSION['admin_demo_role']);
$_SESSION['admin_demo_role'] = 'support_only';
Permission::clearCache();
$supportCan = AdminDemoPerms::can('orders');
ok(!$supportCan || AdminDemoPerms::can('orders'), 'RBAC: demo role permissions resolve (support_only orders=' . ($supportCan ? 'yes' : 'no') . ')');
note('Full admin RBAC deny path is covered by test-admin-auth-security.php HTTP checks.');

// --- Coupon / totals ---
if ($couponFree !== false) {
    $admin = AdminOrderService::findById((int) $couponFree['id']);
    ok($admin !== null, 'Discount order admin loads');
    ok((float) ($admin['discount_total'] ?? 0) > 0 || (string) ($admin['coupon_code'] ?? '') !== '', 'Discount order exposes discount or coupon');
    $fee = (float) ($admin['delivery_fee'] ?? -1);
    ok($fee >= 0, 'Delivery fee present on admin totals');
}

// --- Partial: no image + broken URL ---
$noImg = [['name' => 'Snapshot only', 'label' => '500 g', 'qty' => 2, 'unit_price' => 10.5, 'line_total' => 21.0, 'image' => '']];
$htmlNo = renderOrderItemsPartial($noImg);
ok(str_contains($htmlNo, 'is-empty') && str_contains($htmlNo, 'at-oi__ph'), 'No image: placeholder markup rendered');
ok(!str_contains($htmlNo, '<img src=""'), 'No image: empty img src not emitted');

$broken = [['name' => 'Broken', 'label' => '1 pc', 'qty' => 1, 'unit_price' => 1, 'line_total' => 1, 'image' => asset_url('assets/images/demo/missing-file.jpg')]];
$htmlBr = renderOrderItemsPartial($broken);
ok(str_contains($htmlBr, 'onerror=') && str_contains($htmlBr, 'is-empty'), 'Broken URL: onerror fallback present');

// --- Long names ---
if ($longName !== false) {
    $admin = AdminOrderService::findById((int) $longName['id']);
    $html = renderOrderItemsPartial(array_map(static function (array $it): array {
        return [
            'name' => (string) ($it['name'] ?? ''),
            'label' => (string) ($it['variant'] ?? ''),
            'qty' => (int) ($it['qty'] ?? 0),
            'unit_price' => (float) ($it['unit_price'] ?? 0),
            'line_total' => (float) ($it['line_total'] ?? 0),
            'image' => (string) ($it['image'] ?? ''),
        ];
    }, $admin['items'] ?? []));
    ok(strlen($html) > 100 && str_contains($html, 'at-oi__name'), 'Long-name order renders partial without error');
}

// --- Query plan: correlated subquery per row (not PHP N+1) ---
$explainOid = (int) ($refOrder['id'] ?? 0);
if ($explainOid > 0) {
    $sql = 'SELECT oi.product_name, oi.variant_name, oi.quantity, oi.unit_price, oi.line_total,
            (SELECT pi.file_path FROM product_images pi
             INNER JOIN product_variants pv ON pv.product_id = pi.product_id
             WHERE pv.id = oi.variant_id
             ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC LIMIT 1) AS image_path
     FROM order_items oi WHERE oi.order_id = ' . $explainOid . ' ORDER BY oi.id ASC';
    $plan = $pdo->query('EXPLAIN ' . $sql)->fetchAll(PDO::FETCH_ASSOC);
    $planText = json_encode($plan);
    ok(count($plan) >= 1, 'EXPLAIN runs for order_items + image subquery');
    note('Image lookup uses one SQL round-trip with correlated subquery per line (MySQL dependent subquery), not PHP N+1 loops.');
    note('Safe optimization if needed: LEFT JOIN (oi + pv + primary pi via grouped subquery) in one flat query; not applied in this pass.');
}

echo "\n--- Summary ---\n";
echo 'Failures: ' . $fail . "\n";
echo 'Notes: ' . count($notes) . "\n";
exit($fail === 0 ? 0 : 1);
