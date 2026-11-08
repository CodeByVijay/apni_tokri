<?php

declare(strict_types=1);

/**
 * CustomerOrderTimeline reconciliation (rolled-back fixtures only).
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

function tok(bool $c, string $m): void
{
    global $fail, $pass;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if ($c) {
        $pass++;
    } else {
        $fail++;
    }
}

/** @return list<string> */
function pendingLabels(array $timeline): array
{
    $out = [];
    foreach ($timeline as $step) {
        if (empty($step['done'])) {
            $out[] = (string) ($step['status'] ?? '');
        }
    }

    return $out;
}

/** @param list<array{status: string, at: string, done: bool}> $timeline */
function timestampedSorted(array $timeline): bool
{
    $ts = [];
    foreach ($timeline as $step) {
        if (!empty($step['done']) && !empty($step['at'])) {
            $ts[] = (string) $step['at'];
        }
    }
    $copy = $ts;
    sort($copy);

    return $ts === $copy;
}

function insertOrder(PDO $pdo, int $cid, string $num, string $status, string $payStatus = 'paid'): int
{
    $pdo->prepare(
        'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, placed_at)
         VALUES (?, ?, ?, 100, 0, 0, 100, ?, NOW())'
    )->execute([$num, $cid, $status, $payStatus]);
    $oid = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO order_items (order_id, product_name, variant_name, quantity, unit_price, line_total) VALUES (?, ?, ?, 1, 100, 100)'
    )->execute([$oid, 'T', 'v']);

    return $oid;
}

function insertDelivery(PDO $pdo, int $orderId, string $status, ?string $deliveredAt = null): int
{
    $pdo->prepare(
        'INSERT INTO deliveries (order_id, status, delivered_at, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())'
    )->execute([$orderId, $status, $deliveredAt]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO delivery_status_history (delivery_id, status, note, created_at) VALUES (?, ?, ?, NOW())'
    )->execute([$id, $status, 'test']);

    return $id;
}

function timelineForOrder(PDO $pdo, int $oid, int $cid, string $num): array
{
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $stmt->execute([$oid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $base = OrderRepository::forCustomerDetail($num, $cid);

    return $base['timeline'] ?? [];
}

$cid = (int) $pdo->query('SELECT id FROM customers ORDER BY id ASC LIMIT 1')->fetchColumn();
tok($cid > 0, 'Customer fixture exists');

$tag = substr(Security::randomToken(4), 0, 4);

$pdo->beginTransaction();
try {
    // 1. Confirmed order + delivered delivery
    $n1 = 'TL-D1-' . $tag;
    $o1 = insertOrder($pdo, $cid, $n1, OrderStatus::CONFIRMED);
    insertDelivery($pdo, $o1, DeliveryStatus::DELIVERED);
    $tl1 = timelineForOrder($pdo, $o1, $cid, $n1);
    tok(pendingLabels($tl1) === [], '1 confirmed order + delivered delivery hides pending milestones');
    tok(timestampedSorted($tl1), '1 chronological timestamp events');

    // 2. Confirmed + delivered_at on delivery row (history minimal)
    $n2 = 'TL-D2-' . $tag;
    $o2 = insertOrder($pdo, $cid, $n2, OrderStatus::CONFIRMED);
    $pdo->prepare(
        'INSERT INTO deliveries (order_id, status, delivered_at, created_at, updated_at) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 1 HOUR), NOW(), NOW())'
    )->execute([$o2, DeliveryStatus::DELIVERED]);
    $tl2 = timelineForOrder($pdo, $o2, $cid, $n2);
    tok(pendingLabels($tl2) === [], '2 delivered_at suppresses pending milestones');
    $hasDeliveredAt = false;
    foreach ($tl2 as $s) {
        if (!empty($s['done']) && stripos((string) $s['status'], 'deliver') !== false && !empty($s['at'])) {
            $hasDeliveredAt = true;
        }
    }
    tok($hasDeliveredAt, '2 timeline includes a delivered milestone with timestamp');

    // 3. Cancelled order
    $n3 = 'TL-CX-' . $tag;
    $o3 = insertOrder($pdo, $cid, $n3, OrderStatus::CANCELLED, 'failed');
    $pdo->prepare('INSERT INTO order_status_history (order_id, status, note, created_at) VALUES (?, ?, ?, NOW())')
        ->execute([$o3, OrderStatus::CANCELLED, 'Customer cancel']);
    $tl3 = timelineForOrder($pdo, $o3, $cid, $n3);
    tok(pendingLabels($tl3) === [], '3 cancelled order has no pending milestones');

    // 4. Failed attempt then successful delivery (separate history rows)
    $n4 = 'TL-FD-' . $tag;
    $o4 = insertOrder($pdo, $cid, $n4, OrderStatus::CONFIRMED);
    $pdo->prepare(
        'INSERT INTO deliveries (order_id, status, delivered_at, created_at, updated_at) VALUES (?, ?, NOW(), NOW(), NOW())'
    )->execute([$o4, DeliveryStatus::DELIVERED]);
    $del4 = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO delivery_status_history (delivery_id, status, note, created_at) VALUES (?, ?, ?, DATE_SUB(NOW(), INTERVAL 2 HOUR))'
    )->execute([$del4, DeliveryStatus::FAILED, 'attempt 1']);
    $pdo->prepare(
        'INSERT INTO delivery_status_history (delivery_id, status, note, created_at) VALUES (?, ?, ?, DATE_SUB(NOW(), INTERVAL 1 HOUR))'
    )->execute([$del4, DeliveryStatus::DELIVERED, 'attempt 2 ok']);
    $tl4 = timelineForOrder($pdo, $o4, $cid, $n4);
    tok(pendingLabels($tl4) === [], '4 failed then delivered hides pending');
    $failedSeen = false;
    $deliveredCount = 0;
    foreach ($tl4 as $s) {
        if (stripos((string) $s['status'], 'could not complete') !== false) {
            $failedSeen = true;
        }
        if (strtolower(trim((string) $s['status'])) === 'delivered' && !empty($s['done'])) {
            $deliveredCount++;
        }
    }
    tok($failedSeen, '4 failed attempt remains visible');
    tok($deliveredCount === 1, '4 single delivered milestone after dedupe');

    // 5. Equivalent delivered from order + delivery history
    $n5 = 'TL-DUP-' . $tag;
    $o5 = insertOrder($pdo, $cid, $n5, OrderStatus::CONFIRMED);
    $t5 = date('Y-m-d H:i:s', time() - 3600);
    $pdo->prepare('INSERT INTO order_status_history (order_id, status, created_at) VALUES (?, ?, ?)')
        ->execute([$o5, OrderStatus::DELIVERED, $t5]);
    $pdo->prepare(
        'INSERT INTO deliveries (order_id, status, delivered_at, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())'
    )->execute([$o5, DeliveryStatus::DELIVERED, $t5]);
    $del5 = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO delivery_status_history (delivery_id, status, created_at) VALUES (?, ?, ?)')
        ->execute([$del5, DeliveryStatus::DELIVERED, $t5]);
    $tl5 = timelineForOrder($pdo, $o5, $cid, $n5);
    $deliveredLabels = 0;
    foreach ($tl5 as $s) {
        if (strtolower(trim((string) $s['status'])) === 'delivered' && !empty($s['done'])) {
            $deliveredLabels++;
        }
    }
    tok($deliveredLabels === 1, '5 equivalent delivered events deduped to one');
    tok(pendingLabels($tl5) === [], '5 deduped delivered hides pending');

    // 6. Two distinct events in the same minute
    $n6 = 'TL-SM-' . $tag;
    $o6 = insertOrder($pdo, $cid, $n6, OrderStatus::PREPARING);
    $same = date('Y-m-d H:i:00');
    $pdo->prepare('INSERT INTO order_status_history (order_id, status, created_at) VALUES (?, ?, ?)')
        ->execute([$o6, OrderStatus::PREPARING, $same]);
    $pdo->prepare(
        'INSERT INTO deliveries (order_id, status, created_at, updated_at) VALUES (?, ?, NOW(), NOW())'
    )->execute([$o6, DeliveryStatus::DISPATCHED]);
    $del6 = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO delivery_status_history (delivery_id, status, created_at) VALUES (?, ?, ?)')
        ->execute([$del6, DeliveryStatus::DISPATCHED, $same]);
    $tl6 = timelineForOrder($pdo, $o6, $cid, $n6);
    tok(count(array_filter($tl6, static fn ($s) => !empty($s['done']) && !empty($s['at']))) >= 2, '6 distinct same-minute events both kept');

    // 7. Legacy order without delivery row
    $n7 = 'TL-LEG-' . $tag;
    $o7 = insertOrder($pdo, $cid, $n7, OrderStatus::CONFIRMED);
    $tl7 = timelineForOrder($pdo, $o7, $cid, $n7);
    tok($tl7 !== [], '7 legacy order without delivery has timeline');
    tok(pendingLabels($tl7) !== [], '7 legacy confirmed order may show pending milestones');

    // 8. Missing timestamps skipped (history row with empty created_at is not inserted in production)
    $n8 = 'TL-MS-' . $tag;
    $o8 = insertOrder($pdo, $cid, $n8, OrderStatus::CONFIRMED);
    $payments = [];
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $stmt->execute([$o8]);
    $orderRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $tl8 = CustomerOrderTimeline::build($orderRow ?: [], $payments, $pdo);
    $bad = false;
    foreach ($tl8 as $s) {
        if (!empty($s['done']) && empty($s['at']) && ($s['status'] ?? '') !== 'Order received') {
            $bad = true;
        }
    }
    tok(!$bad, '8 missing timestamps not shown as completed steps');

    // Failed-only delivery does not hide pending
    $n9 = 'TL-FF-' . $tag;
    $o9 = insertOrder($pdo, $cid, $n9, OrderStatus::CONFIRMED);
    insertDelivery($pdo, $o9, DeliveryStatus::FAILED);
    $tl9 = timelineForOrder($pdo, $o9, $cid, $n9);
    tok(pendingLabels($tl9) !== [], 'Failed delivery alone does not hide pending milestones');

    // 10. Unpaid pending_payment highlights Awaiting payment, not Order confirmed
    $n10 = 'TL-PP-' . $tag;
    $o10 = insertOrder($pdo, $cid, $n10, OrderStatus::PENDING_PAYMENT, 'pending');
    $tl10 = timelineForOrder($pdo, $o10, $cid, $n10);
    tok(in_array('Awaiting payment', pendingLabels($tl10), true), '10 pending_payment shows Awaiting payment step');
    tok(!in_array('Order confirmed', pendingLabels($tl10), true), '10 pending_payment never highlights Order confirmed');

    // 11. Failed Cashfree attempts are attempt history, not confirmation
    $pdo->prepare(
        'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key) VALUES (?, ?, 100, ?, ?, ?)'
    )->execute([$o10, 'cashfree', 'INR', 'failed', 'idem-fail-1']);
    $pdo->prepare(
        'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key) VALUES (?, ?, 100, ?, ?, ?)'
    )->execute([$o10, 'cashfree', 'INR', 'failed', 'idem-fail-2']);
    $tl11 = timelineForOrder($pdo, $o10, $cid, $n10);
    $attemptFails = 0;
    $confirmedDone = false;
    foreach ($tl11 as $s) {
        if (!empty($s['done']) && stripos((string) $s['status'], 'attempt failed') !== false) {
            $attemptFails++;
        }
        if (!empty($s['done']) && (string) $s['status'] === 'Order confirmed') {
            $confirmedDone = true;
        }
    }
    tok($attemptFails >= 1, '11 failed payments appear as attempt history');
    tok(!$confirmedDone, '11 failed attempts do not mark Order confirmed done');

    // 12. Paid confirmed order may show Order confirmed pending milestone chain
    $n12 = 'TL-PAID-' . $tag;
    $o12 = insertOrder($pdo, $cid, $n12, OrderStatus::CONFIRMED, 'paid');
    $pdo->prepare(
        'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key, captured_at) VALUES (?, ?, 100, ?, ?, ?, NOW())'
    )->execute([$o12, 'cashfree', 'INR', 'captured', 'idem-ok-1']);
    $tl12 = timelineForOrder($pdo, $o12, $cid, $n12);
    tok(in_array('Order processing', pendingLabels($tl12), true) || in_array('Out for delivery', pendingLabels($tl12), true), '12 paid confirmed shows fulfilment pending steps');

    // 13. Auto payment-timeout cancel — dedicated timeline step, no checkout payment prompt
    $n13 = 'TL-PTX-' . $tag;
    $o13 = insertOrder($pdo, $cid, $n13, OrderStatus::CANCELLED, 'failed');
    $t13Place = date('Y-m-d H:i:s', time() - 1200);
    $t13Hist = date('Y-m-d H:i:s', time() - 900);
    $t13Cancel = date('Y-m-d H:i:s', time() - 600);
    $pdo->prepare('UPDATE orders SET placed_at = ? WHERE id = ?')->execute([$t13Place, $o13]);
    $pdo->prepare('INSERT INTO order_status_history (order_id, status, note, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$o13, 'Awaiting payment', 'Order created — complete payment to confirm', $t13Hist]);
    $pdo->prepare('INSERT INTO order_status_history (order_id, status, note, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$o13, OrderStatus::CANCELLED, 'Cancelled by system: Payment not completed within the allowed time', $t13Cancel]);
    if (OrderCancellationService::eventsTableReady()) {
        $pdo->prepare(
            'INSERT INTO order_cancellation_events (order_id, reason_code, reason_detail, requested_by, customer_id, idempotency_key, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $o13,
            OrderCancellationService::REASON_PAYMENT_TIMEOUT,
            '',
            'system',
            $cid,
            'payment_timeout:order:' . $o13,
            $t13Cancel,
        ]);
    }
    $tl13 = timelineForOrder($pdo, $o13, $cid, $n13);
    $hasTimeoutStep = false;
    $hasCheckoutPrompt = false;
    $timeoutAt = '';
    foreach ($tl13 as $s) {
        $label = (string) ($s['status'] ?? '');
        if ($label === 'Payment window expired — Order automatically cancelled' && !empty($s['done'])) {
            $hasTimeoutStep = true;
            $timeoutAt = (string) ($s['at'] ?? '');
        }
        if (str_contains($label, 'complete payment to confirm')) {
            $hasCheckoutPrompt = true;
        }
    }
    tok(OrderCancellationService::eventsTableReady(), '13 cancellation events table present');
    if (OrderCancellationService::eventsTableReady()) {
        tok($hasTimeoutStep, '13 payment_timeout shows auto-cancel timeline step');
        tok($timeoutAt === $t13Cancel, '13 auto-cancel step uses cancellation event timestamp');
        tok(!$hasCheckoutPrompt, '13 payment_timeout hides checkout payment prompt history');
    }

    // 14. Customer cancellation — preserve checkout prompt and generic cancelled history
    $n14 = 'TL-CC-' . $tag;
    $o14 = insertOrder($pdo, $cid, $n14, OrderStatus::CANCELLED, 'failed');
    $pdo->prepare('INSERT INTO order_status_history (order_id, status, note, created_at) VALUES (?, ?, ?, NOW())')
        ->execute([$o14, 'Awaiting payment', 'Order created — complete payment to confirm']);
    $pdo->prepare('INSERT INTO order_status_history (order_id, status, note, created_at) VALUES (?, ?, ?, NOW())')
        ->execute([$o14, OrderStatus::CANCELLED, 'Cancelled by customer: Ordered by mistake']);
    if (OrderCancellationService::eventsTableReady()) {
        $pdo->prepare(
            'INSERT INTO order_cancellation_events (order_id, reason_code, reason_detail, requested_by, customer_id, idempotency_key)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$o14, 'ordered_by_mistake', '', 'customer', $cid, 'idem-cc-' . $o14]);
    }
    $tl14 = timelineForOrder($pdo, $o14, $cid, $n14);
    $custHasPrompt = false;
    $custHasTimeoutWording = false;
    foreach ($tl14 as $s) {
        if (str_contains((string) ($s['status'] ?? ''), 'complete payment to confirm')) {
            $custHasPrompt = true;
        }
        if ((string) ($s['status'] ?? '') === 'Payment window expired — Order automatically cancelled') {
            $custHasTimeoutWording = true;
        }
    }
    tok($custHasPrompt, '14 customer cancel keeps checkout payment prompt in timeline');
    tok(!$custHasTimeoutWording, '14 customer cancel does not show payment_timeout auto-cancel label');
    $custCancelLabel = '';
    foreach ($tl14 as $s) {
        if (str_starts_with((string) ($s['status'] ?? ''), 'Cancelled by customer:')) {
            $custCancelLabel = (string) ($s['status'] ?? '');
            break;
        }
    }
    tok($custCancelLabel === 'Cancelled by customer: Ordered by mistake', '14 customer cancel shows full cancellation description');

    $pdo->rollBack();
    tok(true, 'All fixtures rolled back (no order rows persisted)');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    tok(false, 'Fixture block: ' . $e->getMessage());
}

echo "PASS: {$pass} FAIL: {$fail}\n";
exit($fail > 0 ? 1 : 0);
