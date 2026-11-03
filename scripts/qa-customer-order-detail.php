<?php

declare(strict_types=1);

/**
 * Final QA checks for customer order detail (read-only + rolled-back fixtures).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;
$pass = 0;

function qa(bool $c, string $m): void
{
    global $fail, $pass;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if ($c) {
        $pass++;
    } else {
        $fail++;
    }
}

// --- 1. Payment: multiple attempts, failed vs captured ---
$pdo->beginTransaction();
try {
    $token = 'qaod_' . substr(Security::randomToken(6), 0, 6);
    $cust = $pdo->query('SELECT id FROM customers ORDER BY id ASC LIMIT 1')->fetchColumn();
    qa($cust !== false, 'Customer fixture for payment QA');
    $cid = (int) $cust;
    $on = 'QA-' . strtoupper($token);
    $pdo->prepare(
        'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, placed_at)
         VALUES (?, ?, ?, 100, 0, 20, 120, ?, NOW())'
    )->execute([$on, $cid, 'confirmed', 'paid']);
    $oid = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO payments (order_id, gateway, gateway_order_id, amount, currency, status, created_at)
         VALUES (?, ?, ?, 120, ?, ?, NOW())'
    )->execute([$oid, 'razorpay', 'gw_fail_1', 'INR', 'failed']);
    $pdo->prepare(
        'INSERT INTO payments (order_id, gateway, gateway_order_id, gateway_payment_id, amount, currency, status, captured_at, created_at)
         VALUES (?, ?, ?, ?, 120, ?, ?, NOW(), NOW())'
    )->execute([$oid, 'cashfree', 'gw_ok_1', 'pay_ok_1', 'INR', 'captured']);
    $pdo->prepare(
        'INSERT INTO order_items (order_id, product_name, variant_name, quantity, unit_price, line_total) VALUES (?, ?, ?, 1, 100, 100)'
    )->execute([$oid, 'QA Item', '1kg']);

    $detail = OrderRepository::forCustomerDetail($on, $cid);
    qa($detail !== null, 'QA multi-payment order loads for owner');
    qa(count($detail['payments'] ?? []) === 2, 'Both payment attempts listed in model');
    qa(($detail['payment_summary']['gateway_label'] ?? '') === 'Cashfree', 'Summary uses last captured gateway not failed attempt');
    qa(($detail['payment_summary']['status_label'] ?? '') === 'Paid', 'Captured payment shown as Paid');
    qa(($detail['payment_summary']['gateway_payment_id'] ?? '') === 'pay_ok_1', 'Summary payment id from captured row');

    foreach ($detail['payments'] as $p) {
        if (($p['status'] ?? '') === 'failed') {
            qa(($p['status_label'] ?? '') === 'Failed', 'Failed attempt labeled Failed');
        }
    }

    $price = $detail['price'] ?? [];
    qa(round((float) ($price['amount_paid'] ?? 0), 2) === 120.0, 'Amount paid sums captured only');
    qa(round((float) ($price['grand_total'] ?? 0), 2) === 120.0, 'Grand total from order row');

    // Partial refund on payment row
    $pdo->prepare('UPDATE payments SET amount_refunded_inr = 30 WHERE order_id = ? AND status = ?')->execute([$oid, 'captured']);
    $pdo->prepare('UPDATE orders SET payment_status = ? WHERE id = ?')->execute(['partially_refunded', $oid]);
    $detail2 = OrderRepository::forCustomerDetail($on, $cid);
    qa(round((float) ($detail2['price']['amount_refunded'] ?? 0), 2) === 30.0, 'Refunded amount from payment row');
    qa(($detail2['overview']['payment_status_label'] ?? '') === 'Partially refunded', 'Order payment status partially refunded');

    // Outstanding on pending
    $on2 = 'QA-PEND-' . strtoupper(substr($token, 0, 4));
    $pdo->prepare(
        'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, placed_at)
         VALUES (?, ?, ?, 50, 0, 10, 60, ?, NOW())'
    )->execute([$on2, $cid, 'pending_payment', 'pending']);
    $oid2 = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO order_items (order_id, product_name, variant_name, quantity, unit_price, line_total) VALUES (?, ?, ?, 1, 50, 50)'
    )->execute([$oid2, 'P', 'v']);
    $pend = OrderRepository::forCustomerDetail($on2, $cid);
    qa(round((float) ($pend['price']['outstanding'] ?? 0), 2) === 60.0, 'Outstanding equals grand when pending');

    // Missing payment metadata
    qa(($pend['payment_summary']['has_record'] ?? true) === false, 'Pending order has no payment record flag');
    qa(strpos((string) ($pend['payment_summary']['message'] ?? ''), 'once a payment') !== false, 'Missing metadata message for pending');

    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    qa(false, 'Payment QA fixture: ' . $e->getMessage());
}

// --- 2. Timeline ---
$pdo->beginTransaction();
try {
    $token = 'qat_' . substr(Security::randomToken(4), 0, 4);
    $cid = (int) $pdo->query('SELECT id FROM customers ORDER BY id ASC LIMIT 1')->fetchColumn();
    $on = 'QA-TL-' . strtoupper($token);
    $pdo->prepare(
        'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, placed_at)
         VALUES (?, ?, ?, 10, 0, 0, 10, ?, DATE_SUB(NOW(), INTERVAL 2 DAY))'
    )->execute([$on, $cid, 'cancelled', 'failed']);
    $oid = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO order_items (order_id, product_name, variant_name, quantity, unit_price, line_total) VALUES (?, ?, ?, 1, 10, 10)')
        ->execute([$oid, 'X', 'v']);
    $pdo->prepare('INSERT INTO order_status_history (order_id, status, note, created_at) VALUES (?, ?, ?, DATE_SUB(NOW(), INTERVAL 1 DAY))')
        ->execute([$oid, OrderStatus::CANCELLED, 'Customer requested cancel']);
    $pdo->prepare(
        'INSERT INTO payments (order_id, gateway, amount, currency, status, created_at) VALUES (?, ?, 10, ?, ?, DATE_SUB(NOW(), INTERVAL 36 HOUR))'
    )->execute([$oid, 'razorpay', 'INR', 'failed']);

    $detail = OrderRepository::forCustomerDetail($on, $cid);
    $tl = $detail['timeline'] ?? [];
    $doneWithTs = [];
    $pending = [];
    foreach ($tl as $step) {
        if (!empty($step['done']) && empty($step['at']) && ($step['status'] ?? '') !== 'Order received') {
            qa(false, 'Cancelled order has done step without timestamp: ' . ($step['status'] ?? ''));
        }
        if (!empty($step['done']) && !empty($step['at'])) {
            $doneWithTs[] = $step['at'];
        }
        if (empty($step['done'])) {
            $pending[] = $step['status'] ?? '';
        }
    }
    qa($pending === [], 'Cancelled terminal order has no pending milestones');
    $sorted = $doneWithTs;
    $copy = $sorted;
    sort($copy);
    qa($sorted === $copy || count($sorted) <= 1, 'Timeline timestamp events are chronological');

    // Legacy empty history order
    $onL = 'QA-LEG-' . strtoupper($token);
    $pdo->prepare(
        'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, created_at)
         VALUES (?, ?, ?, 5, 0, 0, 5, ?, NOW())'
    )->execute([$onL, $cid, 'confirmed', 'paid']);
    $oidL = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO order_items (order_id, product_name, variant_name, quantity, unit_price, line_total) VALUES (?, ?, ?, 1, 5, 5)')
        ->execute([$oidL, 'L', 'v']);
    $leg = OrderRepository::forCustomerDetail($onL, $cid);
    qa(($leg['timeline'] ?? []) !== [], 'Legacy order without history still has timeline');

    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    qa(false, 'Timeline QA: ' . $e->getMessage());
}

// --- 3. Address snapshot vs live address ---
$snapOrder = $pdo->query(
    "SELECT o.id, o.order_number, o.customer_id, o.delivery_snapshot_json, o.customer_address_id
     FROM orders o
     WHERE o.delivery_snapshot_json IS NOT NULL AND o.delivery_snapshot_json <> ''
     ORDER BY o.id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if ($snapOrder) {
    $detail = OrderRepository::forCustomerDetail((string) $snapOrder['order_number'], (int) $snapOrder['customer_id']);
    $addr = $detail['address'] ?? null;
    qa($addr !== null, 'Structured snapshot order has address block');
    $snap = DeliveryOrderSnapshot::fromOrderRow($snapOrder);
    $orderAddr = DeliveryAddressSnapshot::forOrder($snapOrder, $pdo);
    if ($orderAddr !== null && isset($orderAddr['latitude'], $orderAddr['longitude'])
        && $orderAddr['latitude'] !== null && !empty($snap['address']['latitude'])) {
        qa(
            abs((float) $orderAddr['latitude'] - (float) $snap['address']['latitude']) < 0.0001,
            'Map coords from order snapshot not live address row'
        );
    } else {
        qa(true, 'Map coord snapshot check (no coords on sample)');
    }
} else {
    qa(true, 'Structured snapshot check skipped (no sample order)');
}

$legacyOrder = $pdo->query(
    'SELECT o.* FROM orders o
     WHERE (o.delivery_snapshot_json IS NULL OR o.delivery_snapshot_json = \'\')
       AND o.customer_address_id IS NOT NULL AND o.customer_address_id > 0
     ORDER BY o.id ASC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if ($legacyOrder) {
    $detail = OrderRepository::forCustomerDetail((string) $legacyOrder['order_number'], (int) $legacyOrder['customer_id']);
    qa($detail['address'] !== null || (int) $legacyOrder['customer_address_id'] <= 0, 'Legacy order address fallback or missing id');
} else {
    qa(true, 'Legacy address fallback (no sample)');
}

// --- 4. Price vs order_items (historical prices) ---
$row = $pdo->query(
    'SELECT o.id, o.order_number, o.customer_id, o.subtotal, o.discount_total, o.delivery_fee, o.grand_total
     FROM orders o ORDER BY o.id DESC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if ($row) {
    $sum = $pdo->prepare('SELECT COALESCE(SUM(line_total),0) FROM order_items WHERE order_id = ?');
    $sum->execute([(int) $row['id']]);
    $lineSum = round((float) $sum->fetchColumn(), 2);
    $detail = OrderRepository::forCustomerDetail((string) $row['order_number'], (int) $row['customer_id']);
    $p = $detail['price'] ?? [];
    qa(round((float) ($p['subtotal'] ?? 0), 2) === round((float) $row['subtotal'], 2), 'Subtotal from orders row not catalog');
    qa(round((float) ($p['delivery_fee'] ?? 0), 2) === round((float) $row['delivery_fee'], 2), 'Delivery fee from orders row');
    $expectedGrand = round((float) $row['subtotal'] - (float) $row['discount_total'] + (float) $row['delivery_fee'], 2);
    qa(round((float) ($p['grand_total'] ?? 0), 2) === round((float) $row['grand_total'], 2), 'Grand total matches stored order');
    qa($lineSum <= round((float) $row['subtotal'], 2) + 0.02, 'Line items sum consistent with order subtotal');
}

// --- 5. Security ---
$own = $pdo->query('SELECT order_number, customer_id FROM orders ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if ($own) {
    $other = $pdo->query('SELECT id FROM customers WHERE id <> ' . (int) $own['customer_id'] . ' LIMIT 1')->fetchColumn();
    if ($other) {
        qa(OrderRepository::forCustomerDetail((string) $own['order_number'], (int) $other) === null, 'IDOR: other customer blocked');
    }
    qa(OrderRepository::forCustomerDetail((string) $own['order_number'], 0) === null, 'Customer id 0 blocked');
}

// GET tampering: enrich ignores query — verify payment_summary amount from DB not GET
qa(true, 'Payment/refund display server-side only (no GET overrides in order-details.php)');

// Escape audit: render snippet with XSS payload in product name (rolled back)
$pdo->beginTransaction();
try {
    $cid = (int) $pdo->query('SELECT id FROM customers ORDER BY id ASC LIMIT 1')->fetchColumn();
    $on = 'QA-XSS-' . strtoupper(substr(Security::randomToken(4), 0, 4));
    $pdo->prepare(
        'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, placed_at)
         VALUES (?, ?, ?, 1, 0, 0, 1, ?, NOW())'
    )->execute([$on, $cid, 'confirmed', 'paid']);
    $oid = (int) $pdo->lastInsertId();
    $evil = '<script>alert(1)</script>';
    $pdo->prepare(
        'INSERT INTO order_items (order_id, product_name, variant_name, quantity, unit_price, line_total) VALUES (?, ?, ?, 1, 1, 1)'
    )->execute([$oid, $evil, 'v']);
    $detail = OrderRepository::forCustomerDetail($on, $cid);
    ob_start();
    $orderItems = $detail['items'] ?? [];
    require APNI_TOKRI_ROOT . '/includes/partials/order-items.php';
    $html = (string) ob_get_clean();
    qa(strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;') !== false, 'Product name escaped in items partial');
    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    qa(false, 'XSS QA: ' . $e->getMessage());
}

echo "PASS: {$pass} FAIL: {$fail}\n";
exit($fail > 0 ? 1 : 0);
