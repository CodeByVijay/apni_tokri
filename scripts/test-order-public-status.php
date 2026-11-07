<?php

declare(strict_types=1);

/**
 * Verifies OrderRepository::publicStatus mapping (via mapSummary / SQL) without full schema.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
$ok = static function (bool $cond, string $msg) use (&$fail): void {
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        $fail++;
    } else {
        echo "PASS: $msg\n";
    }
};

$reflect = new ReflectionClass(OrderRepository::class);
$method = $reflect->getMethod('publicStatus');
$method->setAccessible(true);
$pub = static function (string $status, string $payment) use ($method): string {
    return $method->invoke(null, $status, $payment);
};

$ok($pub('confirmed', 'paid') === 'confirmed', 'Normal confirmed order (confirmed + paid)');
$ok($pub('cancelled', 'paid') === 'cancelled', 'Cancelled + paid stays cancelled (refund requested scenario)');
$ok($pub('cancelled', 'refunded') === 'cancelled', 'Cancelled + payment refunded stays cancelled');
$ok($pub('pending_payment', 'pending') === 'pending_payment', 'Pending payment order unchanged');
$ok($pub('delivered', 'paid') === 'delivered', 'Delivered + paid stays delivered');
$ok($pub('refunded', 'paid') === 'refunded', 'Order status refunded preserved');

$sql = $reflect->getMethod('publicStatusSql');
$sql->setAccessible(true);
$expr = (string) $sql->invoke(null, 'o');
$ok(str_contains($expr, "'cancelled'"), 'SQL CASE includes cancelled before paid→confirmed');

if (Install::isSchemaReady()) {
    Install::ensureConnection();
    $pdo = Database::pdo();
    $stmt = $pdo->query(
        "SELECT order_number, status, payment_status, (" . str_replace('o.', 'orders.', $expr) . ") AS pub
         FROM orders WHERE status = 'cancelled' LIMIT 5"
    );
    if ($stmt) {
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ok(
                (string) ($row['pub'] ?? '') === 'cancelled',
                'DB cancelled order ' . ($row['order_number'] ?? '') . ' pub=cancelled (was '
                . ($row['status'] ?? '') . '/' . ($row['payment_status'] ?? '') . ')'
            );
        }
    }
} else {
    echo "SKIP: live DB cancelled row checks (schema not ready in CLI)\n";
}

exit($fail > 0 ? 1 : 0);
