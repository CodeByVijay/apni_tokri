<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$customerId = 80;
$list = OrderRepository::listAllRowsForCustomer($customerId);
$found = null;
foreach ($list as $row) {
    if (($row['id'] ?? '') === 'AT-261102-5DB0C9') {
        $found = $row;
        break;
    }
}
ok($found !== null, 'List includes paid fixture order');
ok($found !== null && (int) ($found['item_qty'] ?? 0) === 3, 'My Orders item_qty matches total units (3)');

$pdo = Database::pdo();
$orderStmt = $pdo->prepare('SELECT id FROM orders WHERE order_number = ? LIMIT 1');
$orderStmt->execute(['AT-261102-5DB0C9']);
$orderId = (int) ($orderStmt->fetchColumn() ?: 0);
ok($orderId > 0, 'Paid fixture order exists');

$linesStmt = $pdo->prepare('SELECT variant_id, quantity FROM order_items WHERE order_id = ?');
$linesStmt->execute([$orderId]);
$orderLines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);
ok($orderLines !== [], 'Fixture order has order_items');

$cartStmt = $pdo->prepare('SELECT id FROM carts WHERE customer_id = ? LIMIT 1');
$cartStmt->execute([$customerId]);
$cartId = (int) ($cartStmt->fetchColumn() ?: 0);
if ($cartId === 0) {
    $pdo->prepare('INSERT INTO carts (customer_id) VALUES (?)')->execute([$customerId]);
    $cartId = (int) $pdo->lastInsertId();
}

$orderVariantIds = array_map(static fn ($l) => (int) $l['variant_id'], $orderLines);
$otherStmt = $pdo->prepare(
    'SELECT id FROM product_variants WHERE is_active = 1 AND id NOT IN (' .
    implode(',', array_fill(0, max(1, count($orderVariantIds)), '?')) . ') LIMIT 1'
);
$otherStmt->execute($orderVariantIds !== [] ? $orderVariantIds : [0]);
$otherVariant = (int) ($otherStmt->fetchColumn() ?: 0);

$pdo->prepare('DELETE FROM cart_items WHERE cart_id = ?')->execute([$cartId]);
foreach ($orderLines as $line) {
    $pdo->prepare('INSERT INTO cart_items (cart_id, variant_id, quantity, unit_price) VALUES (?, ?, ?, ?)')
        ->execute([$cartId, (int) $line['variant_id'], (int) $line['quantity'], 10.0]);
}
if ($otherVariant > 0) {
    $pdo->prepare('INSERT INTO cart_items (cart_id, variant_id, quantity, unit_price) VALUES (?, ?, ?, ?)')
        ->execute([$cartId, $otherVariant, 2, 5.0]);
}

function cartUnitsForCustomer(int $customerId): int
{
    $stmt = Database::pdo()->prepare(
        'SELECT COALESCE(SUM(ci.quantity), 0) FROM cart_items ci
         INNER JOIN carts c ON c.id = ci.cart_id WHERE c.customer_id = ?'
    );
    $stmt->execute([$customerId]);

    return (int) $stmt->fetchColumn();
}

$beforeCount = cartUnitsForCustomer($customerId);
CartService::removeLinesForPaidOrder($orderId, $customerId);
$afterCount = cartUnitsForCustomer($customerId);
ok($beforeCount > $afterCount, 'Paid order removal reduces cart count');

if ($otherVariant > 0) {
    ok($afterCount === 2, 'Only unrelated cart units remain (2)');
} else {
    ok($afterCount === 0, 'Cart empty when no unrelated variants in catalogue');
}

CartService::removeLinesForPaidOrder($orderId, $customerId);
ok(cartUnitsForCustomer($customerId) === $afterCount, 'Duplicate cart cleanup is idempotent');

// Idempotent completePayment path still runs cart reconciliation.
$dup = PaymentService::completePayment(
    'AT-261102-5DB0C9',
    'cashfree',
    '1470693222979701248',
    '1470692671118346752',
    335.0,
    'INR',
    'pay_AT-261102-5DB0C9_1',
    'cashfree:api:dup_test'
);
ok(!empty($dup['ok']) && !empty($dup['idempotent']), 'Duplicate payment completion is idempotent');
ok(cartUnitsForCustomer($customerId) === $afterCount, 'Idempotent completion does not corrupt cart');

echo $fail === 0 ? "PASS: cart and My Orders post-payment checks.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
