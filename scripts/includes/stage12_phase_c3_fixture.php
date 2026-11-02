<?php

declare(strict_types=1);

function stage12_c3_cleanup(PDO $pdo): void
{
    Database::transaction(static function () use ($pdo): void {
        $pdo->exec(
            "DELETE FROM payment_webhook_events WHERE gateway = 'cashfree'
             AND (event_id LIKE 'cashfree:c3:%' OR event_id LIKE 'cashfree:ev:%')"
        );
        $pdo->exec(
            "DELETE pr FROM payment_refunds pr
             INNER JOIN payments p ON p.id = pr.payment_id
             INNER JOIN orders o ON o.id = p.order_id
             WHERE o.order_number LIKE 'AT-C3-%'"
        );
        $pdo->exec(
            "DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE 'AT-C3-%')"
        );
        $pdo->exec("DELETE FROM refund_request_history WHERE refund_request_id IN (
            SELECT id FROM refund_requests WHERE order_id IN (
                SELECT id FROM orders WHERE order_number LIKE 'AT-C3-%'
            ))");
        $pdo->exec(
            "DELETE FROM refund_requests WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE 'AT-C3-%')"
        );
        $pdo->exec("DELETE FROM orders WHERE order_number LIKE 'AT-C3-%'");
    });
}

/** @return array{payment_refund_id: int, payment_id: int, order_number: string, idempotency: string, amount: float} */
function stage12_c3_seed_order_refund(PDO $pdo, string $suffix = 'A'): array
{
    $customerId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
    if ($customerId <= 0) {
        $pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute([
            '97' . random_int(10000000, 99999999),
            'AT-C3',
        ]);
        $customerId = (int) $pdo->lastInsertId();
    }
    $orderNumber = 'AT-C3-' . $suffix . '-' . strtoupper(substr(Security::randomToken(3), 0, 5));
    $pdo->prepare(
        "INSERT INTO orders (order_number, customer_id, status, payment_status, grand_total, currency)
         VALUES (?, ?, 'confirmed', 'paid', 100.00, 'INR')"
    )->execute([$orderNumber, $customerId]);
    $orderId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO payments (order_id, gateway, amount, currency, status, gateway_payment_id, gateway_order_id, idempotency_key)
         VALUES (?, 'cashfree', 100.00, 'INR', 'captured', ?, ?, ?)"
    )->execute([
        $orderId,
        'cf_pay_c3_' . $suffix,
        'cf_ord_c3_' . $suffix,
        'pay_c3_' . $suffix,
    ]);
    $paymentId = (int) $pdo->lastInsertId();
    $idempotency = 'refund_req_c3_' . $suffix;
    $cfRefundId = 'cf_ref_c3_' . $suffix;
    $pdo->prepare(
        'INSERT INTO payment_refunds (refund_request_id, payment_id, gateway, amount_inr, idempotency_key, gateway_refund_id, gateway_status)
         VALUES (NULL, ?, ?, 50.00, ?, ?, ?)'
    )->execute([$paymentId, 'cashfree', $idempotency, $cfRefundId, 'pending']);
    $prId = (int) $pdo->lastInsertId();

    return [
        'payment_refund_id' => $prId,
        'payment_id' => $paymentId,
        'order_number' => $orderNumber,
        'idempotency' => substr(preg_replace('/[^a-zA-Z0-9_]/', '_', $idempotency), 0, 40),
        'cf_refund_id' => $cfRefundId,
        'cf_payment_id' => 'cf_pay_c3_' . $suffix,
        'amount' => 50.0,
        'suffix' => $suffix,
    ];
}

function stage12_c3_refund_payload(array $fixture, string $status, array $overrides = []): array
{
    $refund = array_merge([
        'cf_refund_id' => $fixture['cf_refund_id'] ?? ('cf_ref_c3_' . ($fixture['suffix'] ?? 'A')),
        'cf_payment_id' => $fixture['cf_payment_id'] ?? ('cf_pay_c3_' . ($fixture['suffix'] ?? 'A')),
        'refund_id' => $fixture['idempotency'],
        'order_id' => $fixture['order_number'],
        'refund_amount' => $fixture['amount'],
        'refund_currency' => 'INR',
        'refund_status' => $status,
    ], $overrides['refund'] ?? []);
    unset($overrides['refund'], $overrides['suffix']);

    return array_merge([
        'type' => 'REFUND_STATUS_WEBHOOK',
        'event_time' => '2026-11-01T12:00:00+05:30',
        'data' => ['refund' => $refund],
    ], $overrides);
}

function stage12_c3_sign(string $raw, string $secret, string $ts): string
{
    return base64_encode(hash_hmac('sha256', $ts . $raw, $secret, true));
}
