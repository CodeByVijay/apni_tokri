<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

Security::sendSecurityHeaders(Security::isHttps());

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed', 405);
}

Csrf::requireValid();

if (!CustomerService::isLoggedIn()) {
    Ajax::error('Please log in.', 401);
}

$subscriptionNumber = isset($_POST['subscription_number']) && is_string($_POST['subscription_number'])
    ? trim($_POST['subscription_number']) : '';
$substitutionId = isset($_POST['substitution_id']) ? (int) $_POST['substitution_id'] : 0;
$orderNumber = isset($_POST['order_number']) && is_string($_POST['order_number']) ? trim($_POST['order_number']) : '';
$token = isset($_POST['payment_token']) && is_string($_POST['payment_token']) ? trim($_POST['payment_token']) : '';
$idempotency = isset($_POST['idempotency_key']) && is_string($_POST['idempotency_key']) ? trim($_POST['idempotency_key']) : '';
$outcome = isset($_POST['outcome']) && is_string($_POST['outcome']) ? trim($_POST['outcome']) : '';

$referenceNumber = $subscriptionNumber !== '' ? $subscriptionNumber : ($substitutionId > 0 ? 'SUBST-' . $substitutionId : $orderNumber);
if ($referenceNumber === '' || $token === '' || $idempotency === '') {
    Ajax::error('Invalid request.');
}

if (!PaymentService::verifySimulateToken($referenceNumber, $token)) {
    Ajax::error('Payment session expired. Refresh and try again.', 403);
}

$customerId = CustomerAuth::id();
if ($customerId === null) {
    Ajax::error('Please log in.', 401);
}

if ($substitutionId > 0) {
    $pay = SubscriptionSubstitutionPaymentService::paymentByIdempotency($idempotency);
    if ($pay === null) {
        Ajax::error('Payment not found.', 404);
    }
    if ($outcome === 'fail') {
        Ajax::error('Payment failed.');
    }
    $amount = (float) ($pay['amount'] ?? 0);
    $simPaymentId = 'sim_subst_' . Security::randomToken(8);
    $result = SubscriptionSubstitutionPaymentService::completePayment(
        $substitutionId,
        $amount,
        'simulate',
        $simPaymentId,
        'sim_order_subst_' . $substitutionId,
        $idempotency
    );
    if (!$result['ok']) {
        Ajax::error($result['message'] ?? 'Could not confirm payment.');
    }
    Ajax::success(['redirect' => url_base() . '/subscription-details.php']);
}

if ($subscriptionNumber !== '') {
    $sub = CustomerSubscriptionRepository::findByNumberForCustomer($subscriptionNumber, $customerId);
    if ($sub === null) {
        Ajax::error('Subscription not found.', 404);
    }
    if ($outcome === 'fail') {
        SubscriptionActivationService::markPaymentFailed($subscriptionNumber, $idempotency, 'Simulated payment failure');
        Ajax::success(['redirect' => url_base() . '/subscription-payment.php?sub=' . rawurlencode($subscriptionNumber) . '&failed=1']);
    }
    $snapshot = CustomerSubscriptionRepository::decodeSnapshot($sub);
    $amount = (float) ($snapshot['price_paid'] ?? $sub['price_paid'] ?? 0);
    $simPaymentId = 'sim_' . Security::randomToken(8);
    $result = SubscriptionActivationService::activateFromPayment(
        $subscriptionNumber,
        $amount,
        'simulate',
        $simPaymentId,
        'sim_order_' . $subscriptionNumber,
        $idempotency
    );
    if (!$result['ok']) {
        Ajax::error($result['message'] ?? 'Could not confirm payment.');
    }
    Ajax::success(['redirect' => url_base() . '/subscription-details.php?sub=' . rawurlencode($subscriptionNumber)]);
}

$order = PaymentRepository::orderByNumberForCustomer($orderNumber, $customerId);
if ($order === null) {
    Ajax::error('Order not found.', 404);
}

if ($outcome === 'fail') {
    PaymentService::markPaymentFailed($orderNumber, $idempotency, 'Simulated payment failure');
    Ajax::success(['redirect' => url_base() . '/payment-failed.php?order=' . rawurlencode($orderNumber)]);
}

$amount = (float) $order['grand_total'];
$simPaymentId = 'sim_' . Security::randomToken(8);
$result = PaymentService::completePayment(
    $orderNumber,
    'simulate',
    $simPaymentId,
    'sim_order_' . $orderNumber,
    $amount,
    'INR',
    $idempotency
);

if (!$result['ok']) {
    Ajax::error($result['message'] ?? 'Could not confirm payment.');
}

Ajax::success(['redirect' => url_base() . '/order.php?id=' . rawurlencode($orderNumber)]);
