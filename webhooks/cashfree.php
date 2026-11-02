<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$raw = file_get_contents('php://input');
if (!is_string($raw)) {
    $raw = '';
}

$signature = (string) ($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '');
$timestamp = (string) ($_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '');

if (!CashfreeGateway::verifyWebhookSignature($raw, $signature, $timestamp)) {
    http_response_code(401);
    exit;
}

try {
    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(400);
    exit;
}

if (!is_array($payload)) {
    http_response_code(400);
    exit;
}

$type = (string) ($payload['type'] ?? '');
$eventId = stripos($type, 'REFUND') !== false
    ? CashfreeRefundWebhook::buildEventId($payload)
    : CashfreeRefundWebhook::buildEventId($payload);

$begin = PaymentService::beginWebhookEvent('cashfree', $eventId, $raw);
if ($begin['action'] === 'duplicate_success') {
    http_response_code(200);
    echo 'duplicate';
    exit;
}
if ($begin['action'] === 'in_flight') {
    http_response_code(503);
    echo 'processing';
    exit;
}

$workerToken = PaymentService::markWebhookEventProcessing('cashfree', $eventId);
if ($workerToken === null) {
    $begin = PaymentService::beginWebhookEvent('cashfree', $eventId, $raw);
    if ($begin['action'] === 'duplicate_success') {
        http_response_code(200);
        echo 'duplicate';
        exit;
    }
    if ($begin['action'] !== 'process') {
        http_response_code(503);
        echo 'processing';
        exit;
    }
    $workerToken = PaymentService::markWebhookEventProcessing('cashfree', $eventId);
    if ($workerToken === null) {
        http_response_code(503);
        echo 'processing';
        exit;
    }
}

$ok = false;
$err = null;
$retryable = true;
if (stripos($type, 'REFUND') !== false) {
    $result = RefundWebhookService::handleCashfree($payload, $eventId);
    $ok = $result['ok'];
    $err = $result['message'] ?? null;
    $retryable = !empty($result['retryable']);
} elseif ($type === 'PAYMENT_SUCCESS_WEBHOOK' || $type === 'PAYMENT_CHARGES_WEBHOOK') {
    $orderData = $payload['data']['order'] ?? [];
    $paymentData = $payload['data']['payment'] ?? [];
    if (is_array($orderData) && is_array($paymentData)) {
        $merchantOrderId = (string) ($orderData['order_id'] ?? '');
        $cfOrderId = (string) ($orderData['cf_order_id'] ?? '');
        $paymentId = (string) ($paymentData['cf_payment_id'] ?? $paymentData['payment_id'] ?? '');
        $amount = (float) ($orderData['order_amount'] ?? 0);
        $orderRow = $merchantOrderId !== '' ? PaymentRepository::orderByNumber($merchantOrderId) : null;
        if ($orderRow) {
            $pay = PaymentRepository::latestPaymentForOrder((int) $orderRow['id']);
            $idempotency = $pay ? (string) ($pay['idempotency_key'] ?? '') : '';
            $result = PaymentService::completePayment(
                $merchantOrderId,
                'cashfree',
                $paymentId,
                $cfOrderId,
                $amount,
                'INR',
                $idempotency,
                $eventId
            );
            $ok = $result['ok'];
            $err = $result['message'] ?? null;
            $retryable = false;
        } else {
            $ok = false;
            $err = 'Order not found';
            $retryable = false;
        }
    } else {
        $ok = true;
        $retryable = false;
    }
} else {
    $ok = true;
    $retryable = false;
}

PaymentService::finishWebhookEvent('cashfree', $eventId, $ok, $err, $workerToken);
if ($ok) {
    http_response_code(200);
    echo 'ok';
    exit;
}
if ($retryable) {
    http_response_code(500);
    echo 'error';
    exit;
}
http_response_code(200);
echo 'ignored';
