<?php

declare(strict_types=1);

/**
 * VT-2026-06: admin order status transition matrix regression test.
 * Runs ONLY against a disposable database:
 *   php scripts/run-in-disposable-db.php scripts/test-order-status-transitions.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();
Install::ensureConnection();

$fail = 0;
$check = static function (bool $condition, string $label): void {
    global $fail;
    echo ($condition ? 'OK' : 'FAIL') . ': ' . $label . PHP_EOL;
    if (!$condition) {
        ++$fail;
    }
};

$pdo = Database::pdo();
$tok = substr(bin2hex(random_bytes(4)), 0, 8);
$orderIds = [];

$makeOrder = static function (string $status, string $paymentStatus) use ($pdo, $tok, &$orderIds): int {
    $num = 'TST-' . $tok . '-' . (count($orderIds) + 1);
    $pdo->prepare(
        "INSERT INTO orders (order_number, status, payment_status, subtotal, grand_total)
         VALUES (?, ?, ?, 100.00, 100.00)"
    )->execute([$num, $status, $paymentStatus]);
    $id = (int) $pdo->lastInsertId();
    $orderIds[] = $id;

    return $id;
};

$statusOf = static function (int $id) use ($pdo): string {
    $st = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
    $st->execute([$id]);

    return (string) $st->fetchColumn();
};

// --- pending_payment cannot reach delivered/refunded -------------------------
$o = $makeOrder('pending_payment', 'pending');
$r = AdminOrderService::updateStatus($o, 'delivered', 'x');
$check(!$r['ok'] && $statusOf($o) === 'pending_payment', 'pending_payment -> delivered rejected');
$r = AdminOrderService::updateStatus($o, 'refunded', 'x');
$check(!$r['ok'] && $statusOf($o) === 'pending_payment', 'pending_payment -> refunded rejected');
$r = AdminOrderService::updateStatus($o, 'partially_refunded', 'x');
$check(!$r['ok'] && $statusOf($o) === 'pending_payment', 'pending_payment -> partially_refunded rejected');
$r = AdminOrderService::updateStatus($o, 'cancelled', 'x');
$check(!empty($r['ok']) && $statusOf($o) === 'cancelled', 'pending_payment -> cancelled accepted');

// --- payment_failed ----------------------------------------------------------
$o = $makeOrder('payment_failed', 'failed');
$r = AdminOrderService::updateStatus($o, 'delivered', 'x');
$check(!$r['ok'] && $statusOf($o) === 'payment_failed', 'payment_failed -> delivered rejected');
$r = AdminOrderService::updateStatus($o, 'cancelled', 'x');
$check(!empty($r['ok']) && $statusOf($o) === 'cancelled', 'payment_failed -> cancelled accepted');

// --- paid order fulfils end-to-end -------------------------------------------
$o = $makeOrder('confirmed', 'paid');
foreach (['accepted', 'preparing', 'ready', 'out_for_delivery'] as $st) {
    $r = AdminOrderService::updateStatus($o, $st, 'x');
    if (empty($r['ok'])) {
        break;
    }
}
$check($statusOf($o) === 'out_for_delivery', 'paid order confirmed->accepted->preparing->ready->out_for_delivery');
$r = AdminOrderService::updateStatus($o, 'delivered', 'x');
$check(!empty($r['ok']) && $statusOf($o) === 'delivered', 'out_for_delivery -> delivered (paid) accepted');

// --- delivered is terminal ----------------------------------------------------
$r = AdminOrderService::updateStatus($o, 'preparing', 'x');
$check(!$r['ok'] && $statusOf($o) === 'delivered', 'delivered -> preparing (backward) rejected');
$r = AdminOrderService::updateStatus($o, 'cancelled', 'x');
$check(!$r['ok'] && $statusOf($o) === 'delivered', 'delivered -> cancelled rejected');
$r = AdminOrderService::updateStatus($o, 'refunded', 'x');
$check(!$r['ok'] && $statusOf($o) === 'delivered', 'delivered -> refunded rejected (refund workflow only)');

// --- unpaid confirmed order cannot be marked delivered ------------------------
$o = $makeOrder('confirmed', 'pending');
$r = AdminOrderService::updateStatus($o, 'delivered', 'x');
$check(!$r['ok'] && $statusOf($o) === 'confirmed', 'confirmed + unpaid -> delivered rejected (paid gate)');
$r = AdminOrderService::updateStatus($o, 'preparing', 'x');
$check(!empty($r['ok']) && $statusOf($o) === 'preparing', 'confirmed -> preparing accepted (fulfilment may start)');

// --- refund statuses are never manual targets ---------------------------------
$o = $makeOrder('confirmed', 'paid');
foreach (['refunded', 'partially_refunded'] as $st) {
    $r = AdminOrderService::updateStatus($o, $st, 'x');
    $check(!$r['ok'] && $statusOf($o) === 'confirmed', "confirmed -> {$st} rejected (refund workflow only)");
}

// --- terminal refund states ---------------------------------------------------
$o = $makeOrder('refunded', 'refunded');
$r = AdminOrderService::updateStatus($o, 'cancelled', 'x');
$check(!$r['ok'] && $statusOf($o) === 'refunded', 'refunded -> cancelled rejected (terminal)');
$o = $makeOrder('partially_refunded', 'partially_refunded');
$r = AdminOrderService::updateStatus($o, 'confirmed', 'x');
$check(!$r['ok'] && $statusOf($o) === 'partially_refunded', 'partially_refunded -> confirmed rejected (terminal)');

// --- cancelled is terminal ----------------------------------------------------
$o = $makeOrder('cancelled', 'pending');
$r = AdminOrderService::updateStatus($o, 'confirmed', 'x');
$check(!$r['ok'] && $statusOf($o) === 'cancelled', 'cancelled -> confirmed rejected (terminal)');

// --- delivery_exception recovery ----------------------------------------------
$o = $makeOrder('delivery_exception', 'paid');
$r = AdminOrderService::updateStatus($o, 'out_for_delivery', 'x');
$check(!empty($r['ok']) && $statusOf($o) === 'out_for_delivery', 'delivery_exception -> out_for_delivery (redispatch)');
$r = AdminOrderService::updateStatus($o, 'delivered', 'x');
$check(!empty($r['ok']) && $statusOf($o) === 'delivered', 'out_for_delivery -> delivered (paid)');

// --- same-status note still allowed (history only) -----------------------------
$o = $makeOrder('confirmed', 'paid');
$r = AdminOrderService::updateStatus($o, 'confirmed', 'manual note for audit');
$check(!empty($r['ok']), 'same status + custom note writes history (allowed)');
$r = AdminOrderService::updateStatus($o, 'confirmed', '');
$check(!$r['ok'], 'same status + no note rejected as no-op');

// --- history + audit trail -----------------------------------------------------
$hist = $pdo->prepare('SELECT COUNT(*) FROM order_status_history WHERE order_id = ?');
$hist->execute([$o]);
$check((int) $hist->fetchColumn() >= 1, 'status history rows written for accepted transitions');

// --- concurrency: second caller sees fresh locked state -------------------------
$o = $makeOrder('out_for_delivery', 'paid');
$r1 = AdminOrderService::updateStatus($o, 'delivered', 'x');
$r2 = AdminOrderService::updateStatus($o, 'ready', 'x');
$check(!empty($r1['ok']) && !$r2['ok'] && $statusOf($o) === 'delivered',
    'stale backward write rejected against post-lock status');

// --- allowedTargetsFor drives the dropdown -------------------------------------
$check(AdminOrderService::allowedTargetsFor('delivered') === [], 'delivered offers no manual targets');
$check(AdminOrderService::allowedTargetsFor('pending_payment') === ['cancelled', 'payment_failed'],
    'pending_payment offers only cancelled/payment_failed');
$check(AdminOrderService::allowedTargetsFor('nonexistent_status') === ['cancelled'],
    'unknown status offers only cancelled');
$check(!in_array('refunded', AdminOrderService::adminUpdatableStatuses(), true)
    && !in_array('partially_refunded', AdminOrderService::adminUpdatableStatuses(), true),
    'refund statuses removed from manual target list');

// --- fixtures are fixtures, not real orders ------------------------------------
$cnt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE order_number LIKE 'TST-{$tok}-%'");
$cnt->execute();
$check((int) $cnt->fetchColumn() === count($orderIds), 'fixture orders isolated to TST- prefix');

echo $fail === 0 ? "RESULT: PASS\n" : "RESULT: {$fail} FAILURES\n";
exit($fail === 0 ? 0 : 1);
