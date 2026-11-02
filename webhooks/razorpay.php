<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$raw = file_get_contents('php://input');
if (!is_string($raw)) {
    $raw = '';
}

$signature = (string) ($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');

if (!RazorpayGateway::verifyWebhookSignature($raw, $signature)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid signature']);
    exit;
}

try {
    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(400);
    exit;
}

$eventName = (string) ($payload['event'] ?? '');
$refundEntity = $payload['payload']['refund']['entity'] ?? null;
$paymentEntity = $payload['payload']['payment']['entity'] ?? null;
if ($eventName !== '' && strpos($eventName, 'refund.') === 0 && is_array($refundEntity)) {
    $eventId = $eventName . ':' . (string) ($refundEntity['id'] ?? hash('sha256', $raw));
} elseif (is_array($paymentEntity)) {
    $eventId = (string) ($payload['event'] ?? '') . ':' . (string) ($paymentEntity['id'] ?? Security::randomToken(4));
} else {
    $eventId = 'razorpay:' . hash('sha256', $raw);
}
if ($eventId === ':') {
    $eventId = 'razorpay:' . hash('sha256', $raw);
}

if (!PaymentService::storeWebhookEvent('razorpay', $eventId, $raw)) {
    http_response_code(200);
    echo json_encode(['status' => 'duplicate']);
    exit;
}

if ($eventName !== '' && strpos($eventName, 'refund.') === 0) {
    $result = RefundWebhookService::handleRazorpay(is_array($payload) ? $payload : [], $eventId);
} else {
    $result = PaymentService::handleRazorpayWebhook(is_array($payload) ? $payload : [], $eventId);
}
PaymentService::finishWebhookEvent('razorpay', $eventId, $result['ok'], $result['message'] ?? null);

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => $result['ok'] ? 'ok' : 'ignored']);
