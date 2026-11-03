<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;

function ok(bool $c, string $l): void
{
    global $fail;
    echo ($c ? 'OK: ' : 'FAIL: ') . $l . "\n";
    if (!$c) {
        $fail++;
    }
}

$tag = 'pg' . substr(md5((string) microtime(true)), 0, 5);
$pdo->prepare('INSERT INTO customers (mobile, name, is_active) VALUES (?, ?, 1)')->execute(["9{$tag}001", 'Pag A']);
$custA = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO customers (mobile, name, is_active) VALUES (?, ?, 1)')->execute(["9{$tag}002", 'Pag B']);
$custB = (int) $pdo->lastInsertId();

$subIds = [];
for ($i = 0; $i < 25; $i++) {
    $num = 'SUB-PG-' . $tag . '-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $pdo->prepare(
        'INSERT INTO customer_subscriptions (subscription_number, customer_id, status, plan_snapshot_json, deliveries_remaining, created_at)
         VALUES (?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? MINUTE))'
    )->execute([
        $num,
        $custA,
        $i % 3 === 0 ? 'active' : 'pending_payment',
        json_encode(['name' => 'Plan ' . $i, 'delivery_count' => 7], JSON_UNESCAPED_UNICODE),
        3,
        $i,
    ]);
    $subIds[] = (int) $pdo->lastInsertId();
}

$orderIds = [];
for ($i = 0; $i < 22; $i++) {
    $on = 'ORD-PG-' . $tag . '-' . $i;
    $pdo->prepare(
        'INSERT INTO orders (order_number, customer_id, status, payment_status, subtotal, grand_total, placed_at)
         VALUES (?, ?, ?, ?, 100, 100, DATE_SUB(NOW(), INTERVAL ? HOUR))'
    )->execute([$on, $custA, 'confirmed', 'paid', $i]);
    $oid = (int) $pdo->lastInsertId();
    $orderIds[] = $oid;
    $pdo->prepare(
        'INSERT INTO order_items (order_id, product_name, variant_name, quantity, unit_price, line_total, variant_id)
         VALUES (?, ?, ?, 1, 50, 50, NULL)'
    )->execute([$oid, 'Tomato', '500g']);
}

try {
    // Subscriptions — total & sort (newest first = lowest minute offset first)
    $p1 = CustomerSubscriptionRepository::listForCustomer($custA, 1, 20);
    ok($p1['total'] === 25, 'Subscription total count');
    ok(count($p1['rows']) === 20, 'Subscription first page size 20');
    ok($p1['total_pages'] === 2, 'Subscription total pages');
    ok((string) $p1['rows'][0]['subscription_number'] === 'SUB-PG-' . $tag . '-00', 'Subscription sort newest first');

    $p2 = CustomerSubscriptionRepository::listForCustomer($custA, 2, 20);
    ok(count($p2['rows']) === 5, 'Subscription last page size');
    ok($p2['page'] === 2, 'Subscription last page number');

    $pm = CustomerSubscriptionRepository::listForCustomer($custA, 2, 20);
    ok(count($pm['rows']) === 5, 'Subscription middle/last page records');

    $empty = CustomerSubscriptionRepository::listForCustomer($custB, 1, 20);
    ok($empty['total'] === 0 && $empty['rows'] === [], 'Subscription empty customer');

    $badPage = CustomerSubscriptionRepository::listForCustomer($custA, 99, 20);
    ok($badPage['page'] === 2 && count($badPage['rows']) === 5, 'Subscription invalid page clamped to last');

    $neg = CustomerSubscriptionRepository::listForCustomer($custA, -3, 20);
    ok($neg['page'] === 1 && count($neg['rows']) === 20, 'Subscription negative page becomes 1');

    $big = CustomerSubscriptionRepository::listForCustomer($custA, 1, 200);
    ok($big['per_page'] === 50 && count($big['rows']) === 25, 'Subscription per_page capped at 50');

    $iso = CustomerSubscriptionRepository::listForCustomer($custB, 1, 20);
    ok($iso['total'] === 0, 'Subscription customer B isolation (empty)');

    $snap = CustomerSubscriptionRepository::decodeSnapshot($p1['rows'][0]);
    ok(($snap['name'] ?? '') === 'Plan 0', 'Subscription snapshot decode on paged row');
    $subNum = (string) $p1['rows'][0]['subscription_number'];
    ok(CustomerSubscriptionRepository::findByNumberForCustomer($subNum, $custA) !== null, 'Subscription details navigation id');

    // Orders
    $o1 = OrderRepository::listForCustomer($custA, 1, 20);
    ok($o1['total'] === 22, 'Order total count');
    ok(count($o1['rows']) === 20, 'Order first page');
    ok($o1['rows'][0]['id'] === 'ORD-PG-' . $tag . '-0', 'Order sort newest first');
    ok(isset($o1['rows'][0]['items']) && $o1['rows'][0]['items'] !== [], 'Order item preview on page');

    $oLast = OrderRepository::listForCustomer($custA, 2, 20);
    ok(count($oLast['rows']) === 2, 'Order last page');

    $oBad = OrderRepository::listForCustomer($custA, -1, 999);
    ok($oBad['per_page'] === 50 && $oBad['page'] === 1, 'Order negative page and per_page cap');

    $oB = OrderRepository::listForCustomer($custB, 1, 20);
    ok($oB['total'] === 0, 'Order customer B isolation');

    $all = OrderRepository::listAllRowsForCustomer($custA);
    ok(count($all) === 22, 'Order listAllRowsForCustomer unlimited');

    // Pagination helper
    $n = AccountListPagination::normalize(-2, 100);
    ok($n['page'] === 1 && $n['per_page'] === 50, 'AccountListPagination normalize caps');
} finally {
    foreach ($orderIds as $oid) {
        $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$oid]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$oid]);
    }
    foreach ($subIds as $sid) {
        $pdo->prepare('DELETE FROM customer_subscriptions WHERE id = ?')->execute([$sid]);
    }
    $pdo->prepare('DELETE FROM customers WHERE id IN (?, ?)')->execute([$custA, $custB]);
}

echo $fail === 0 ? "\nPASS: account list pagination\n" : "\n{$fail} check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
