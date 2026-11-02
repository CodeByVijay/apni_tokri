<?php

declare(strict_types=1);

/**
 * Isolated Stage 12 Phase C.1 CLI fixtures — identifiable test orders only.
 */

/**
 * @return array{
 *   token: string,
 *   customer_a: int,
 *   customer_b: int,
 *   order_id: int,
 *   order_number: string,
 *   payment_id: int,
 *   order_item_id: int,
 *   subscription_number: string|null,
 *   subscription_payment_id: int|null,
 *   customer_subscription_id: int|null
 * }
 */
function stage12_phase_c1_create_fixture(PDO $pdo): array
{
    $token = bin2hex(random_bytes(4));
    $orderNumber = 'AT-C1FIX-' . strtoupper($token);
    $mobileA = '987C1A' . substr($token, 0, 4);
    $mobileB = '987C1B' . substr($token, 0, 4);

    $pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute([
        $mobileA,
        'Stage12 C1 Test Customer A',
    ]);
    $customerA = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute([
        $mobileB,
        'Stage12 C1 Test Customer B',
    ]);
    $customerB = (int) $pdo->lastInsertId();

    $amount = 150.00;
    $pdo->prepare(
        'INSERT INTO orders
         (order_number, order_source, customer_id, status, subtotal, discount_total, delivery_fee, grand_total,
          currency, payment_status, placed_at)
         VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, ?, NOW())'
    )->execute([
        $orderNumber,
        'storefront',
        $customerA,
        'delivered',
        $amount,
        $amount,
        'INR',
        'paid',
    ]);
    $orderId = (int) $pdo->lastInsertId();

    $variantId = (int) ($pdo->query(
        'SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id LIMIT 1'
    )->fetchColumn() ?: 0);

    $pdo->prepare(
        'INSERT INTO order_items
         (order_id, variant_id, product_name, variant_name, quantity, unit_price, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $orderId,
        $variantId > 0 ? $variantId : null,
        'C1 fixture product',
        'unit',
        1,
        $amount,
        $amount,
    ]);
    $orderItemId = (int) $pdo->lastInsertId();

    $idempotency = 'pay_c1fix_' . $token;
    $pdo->prepare(
        'INSERT INTO payments
         (order_id, gateway, gateway_payment_id, amount, amount_refunded_inr, currency, status, idempotency_key)
         VALUES (?, ?, ?, ?, 0, ?, ?, ?)'
    )->execute([
        $orderId,
        'simulate',
        'gw_c1fix_' . $token,
        $amount,
        'INR',
        'captured',
        $idempotency,
    ]);
    $paymentId = (int) $pdo->lastInsertId();

    $subPaymentId = null;
    $subId = null;
    $subNumber = null;
    $plan = SubscriptionPlanRepository::findByTemplateKey('veg_7');
    if ($plan !== null) {
        $subNumber = 'SUB-C1FIX-' . strtoupper($token);
        $snapshot = SubscriptionPlanRepository::toSnapshotArray($plan, (float) $plan['price']);
        $pdo->prepare(
            'INSERT INTO customer_subscriptions
             (subscription_number, customer_id, subscription_plan_id, plan_snapshot_json, price_paid, status,
              deliveries_remaining, start_date, calendar_end_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), CURDATE() + INTERVAL 7 DAY)'
        )->execute([
            $subNumber,
            $customerA,
            (int) $plan['id'],
            json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            (float) $plan['price'],
            'active',
            7,
        ]);
        $subId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO subscription_payments
             (customer_subscription_id, amount, amount_refunded_inr, currency, status, gateway, idempotency_key)
             VALUES (?, ?, 0, ?, ?, ?, ?)'
        )->execute([
            $subId,
            (float) $plan['price'],
            'INR',
            'captured',
            'simulate',
            'subpay_c1fix_' . $token,
        ]);
        $subPaymentId = (int) $pdo->lastInsertId();
    }

    return [
        'token' => $token,
        'customer_a' => $customerA,
        'customer_b' => $customerB,
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'payment_id' => $paymentId,
        'order_item_id' => $orderItemId,
        'subscription_number' => $subNumber,
        'subscription_payment_id' => $subPaymentId,
        'customer_subscription_id' => $subId,
    ];
}

/**
 * Remove only rows created by {@see stage12_phase_c1_create_fixture()} and C1 test refund rows.
 *
 * @param list<int> $refundRequestIds
 */
function stage12_phase_c1_teardown(PDO $pdo, array $fixture, array $refundRequestIds = []): void
{
    $pdo->prepare(
        "DELETE FROM payment_refunds WHERE gateway = 'simulate' AND (gateway_refund_id = 'rf_test_id' OR gateway_refund_id = ?)"
    )->execute(['rf_c1_' . ($fixture['token'] ?? '')]);

    foreach ($refundRequestIds as $rid) {
        $rid = (int) $rid;
        if ($rid <= 0) {
            continue;
        }
        $pdo->prepare('DELETE FROM payment_refunds WHERE refund_request_id = ?')->execute([$rid]);
        $pdo->prepare('DELETE FROM refund_request_history WHERE refund_request_id = ?')->execute([$rid]);
        $pdo->prepare('DELETE FROM refund_request_items WHERE refund_request_id = ?')->execute([$rid]);
        $pdo->prepare('DELETE FROM refund_request_evidence WHERE refund_request_id = ?')->execute([$rid]);
        $pdo->prepare('DELETE FROM refund_requests WHERE id = ? AND request_number LIKE ?')->execute([$rid, 'RF-C1%']);
    }

    $orderId = (int) ($fixture['order_id'] ?? 0);
    if ($orderId > 0) {
        $pdo->prepare('DELETE FROM payment_refunds WHERE payment_id = ?')->execute([(int) $fixture['payment_id']]);
        $pdo->prepare('DELETE FROM payments WHERE id = ? AND idempotency_key LIKE ?')->execute([
            (int) $fixture['payment_id'],
            'pay_c1fix_%',
        ]);
        $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderId]);
        $pdo->prepare('DELETE FROM orders WHERE id = ? AND order_number LIKE ?')->execute([$orderId, 'AT-C1FIX-%']);
    }

    $subId = (int) ($fixture['customer_subscription_id'] ?? 0);
    if ($subId > 0) {
        $pdo->prepare('DELETE FROM subscription_payments WHERE customer_subscription_id = ? AND idempotency_key LIKE ?')
            ->execute([$subId, 'subpay_c1fix_%']);
        $pdo->prepare('DELETE FROM customer_subscriptions WHERE id = ? AND subscription_number LIKE ?')
            ->execute([$subId, 'SUB-C1FIX-%']);
    }

    foreach (['customer_a', 'customer_b'] as $key) {
        $cid = (int) ($fixture[$key] ?? 0);
        if ($cid > 0) {
            $pdo->prepare('DELETE FROM customers WHERE id = ? AND mobile LIKE ?')->execute([$cid, '987C1%']);
        }
    }
}
