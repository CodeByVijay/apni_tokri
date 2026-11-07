<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

Security::sendSecurityHeaders(Security::isHttps());

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed', 405);
}

Csrf::requireValid();

$subscriptionNumber = isset($_POST['subscription_number']) && is_string($_POST['subscription_number'])
    ? trim($_POST['subscription_number']) : '';
$substitutionId = isset($_POST['substitution_id']) ? (int) $_POST['substitution_id'] : 0;
$orderNumber = isset($_POST['order_number']) && is_string($_POST['order_number']) ? trim($_POST['order_number']) : '';
$gateway = isset($_POST['gateway']) && is_string($_POST['gateway']) ? trim($_POST['gateway']) : '';
$idempotency = isset($_POST['idempotency_key']) && is_string($_POST['idempotency_key']) ? trim($_POST['idempotency_key']) : '';

if ($idempotency === '') {
    Ajax::error('Invalid request.');
}

if (!CustomerService::isLoggedIn()) {
    Ajax::error('Please log in.', 401);
}

$customerId = CustomerAuth::id();
if ($customerId === null) {
    Ajax::error('Please log in.', 401);
}

if ($substitutionId > 0) {
    if ($gateway !== 'razorpay') {
        Ajax::error('Unsupported gateway.');
    }
    $paymentId = isset($_POST['razorpay_payment_id']) && is_string($_POST['razorpay_payment_id']) ? trim($_POST['razorpay_payment_id']) : '';
    $gatewayOrderId = isset($_POST['razorpay_order_id']) && is_string($_POST['razorpay_order_id']) ? trim($_POST['razorpay_order_id']) : '';
    $signature = isset($_POST['razorpay_signature']) && is_string($_POST['razorpay_signature']) ? trim($_POST['razorpay_signature']) : '';
    if ($paymentId === '' || $gatewayOrderId === '' || $signature === '') {
        Ajax::error('Incomplete payment response.');
    }
    if (!RazorpayGateway::verifyPaymentSignature($gatewayOrderId, $paymentId, $signature)) {
        Ajax::error('Payment verification failed.', 403, 'invalid_signature');
    }
    $result = SubscriptionSubstitutionPaymentService::verifyRazorpayCapture(
        $substitutionId,
        $customerId,
        $gatewayOrderId,
        $paymentId,
        $idempotency
    );
    if (!$result['ok']) {
        Ajax::error($result['message'] ?? 'Could not confirm payment.');
    }
    $subNum = (string) ($result['subscription_number'] ?? '');
    Ajax::success([
        'redirect' => url_base() . '/subscription-details.php?sub=' . rawurlencode($subNum !== '' ? $subNum : ''),
    ]);
}

if ($subscriptionNumber !== '') {
    if ($gateway !== 'razorpay') {
        Ajax::error('Unsupported gateway.');
    }
    $sub = CustomerSubscriptionRepository::findByNumberForCustomer($subscriptionNumber, $customerId);
    if ($sub === null) {
        Ajax::error('Subscription not found.', 404);
    }
    if ((string) $sub['status'] === 'active') {
        Ajax::success(['redirect' => url_base() . '/subscription-details.php?sub=' . rawurlencode($subscriptionNumber)]);
    }
    $paymentId = isset($_POST['razorpay_payment_id']) && is_string($_POST['razorpay_payment_id']) ? trim($_POST['razorpay_payment_id']) : '';
    $gatewayOrderId = isset($_POST['razorpay_order_id']) && is_string($_POST['razorpay_order_id']) ? trim($_POST['razorpay_order_id']) : '';
    $signature = isset($_POST['razorpay_signature']) && is_string($_POST['razorpay_signature']) ? trim($_POST['razorpay_signature']) : '';
    if ($paymentId === '' || $gatewayOrderId === '' || $signature === '') {
        Ajax::error('Incomplete payment response.');
    }
    if (!RazorpayGateway::verifyPaymentSignature($gatewayOrderId, $paymentId, $signature)) {
        Ajax::error('Payment verification failed.', 403, 'invalid_signature');
    }
    $snapshot = CustomerSubscriptionRepository::decodeSnapshot($sub);
    $amount = (float) ($snapshot['price_paid'] ?? $sub['price_paid'] ?? 0);
    $result = SubscriptionActivationService::activateFromPayment(
        $subscriptionNumber,
        $amount,
        'razorpay',
        $paymentId,
        $gatewayOrderId,
        $idempotency
    );
    if (!$result['ok']) {
        Ajax::error($result['message'] ?? 'Could not confirm payment.');
    }
    Ajax::success(['redirect' => url_base() . '/subscription-details.php?sub=' . rawurlencode($subscriptionNumber)]);
}

if ($orderNumber === '' && $substitutionId <= 0) {
    Ajax::error('Invalid request.');
}

$order = PaymentRepository::orderByNumberForCustomer($orderNumber, $customerId);
if ($order === null) {
    Ajax::error('Order not found.', 404);
}

if ($gateway === 'razorpay') {
    $paymentId = isset($_POST['razorpay_payment_id']) && is_string($_POST['razorpay_payment_id']) ? trim($_POST['razorpay_payment_id']) : '';
    $gatewayOrderId = isset($_POST['razorpay_order_id']) && is_string($_POST['razorpay_order_id']) ? trim($_POST['razorpay_order_id']) : '';
    $signature = isset($_POST['razorpay_signature']) && is_string($_POST['razorpay_signature']) ? trim($_POST['razorpay_signature']) : '';
    if ($paymentId === '' || $gatewayOrderId === '' || $signature === '') {
        Ajax::error('Incomplete payment response.');
    }
    if (!RazorpayGateway::verifyPaymentSignature($gatewayOrderId, $paymentId, $signature)) {
        Ajax::error('Payment verification failed.', 403, 'invalid_signature');
    }
    $amount = (float) $order['grand_total'];
    $result = PaymentService::completePayment(
        $orderNumber,
        'razorpay',
        $paymentId,
        $gatewayOrderId,
        $amount,
        'INR',
        $idempotency
    );
    if (!$result['ok']) {
        Ajax::error($result['message'] ?? 'Could not confirm payment.');
    }
    Ajax::success(['redirect' => url_base() . '/order.php?id=' . rawurlencode($orderNumber)]);
}

if ($gateway === 'cashfree') {
    if ($orderNumber === '') {
        Ajax::error('Invalid request.');
    }
    $result = PaymentService::syncCashfreeOrderPayment($orderNumber, $customerId);
    if (!$result['ok'] && ($result['state'] ?? '') === 'error') {
        Ajax::error($result['message'] ?? 'Could not verify payment status.');
    }
    if (!empty($result['redirect'])) {
        Ajax::success(['redirect' => $result['redirect']]);
    }
    if (($result['state'] ?? '') === 'pending') {
        Ajax::success(['pending' => true, 'message' => $result['message'] ?? 'Payment still pending.']);
    }
    if (($result['state'] ?? '') === 'reconciliation') {
        Ajax::success([
            'pending' => true,
            'reconciliation' => true,
            'message' => $result['message'] ?? 'Payment received; order confirmation is still pending.',
        ]);
    }
    Ajax::error($result['message'] ?? 'Payment not confirmed yet.');
}

Ajax::error('Unsupported gateway.');
