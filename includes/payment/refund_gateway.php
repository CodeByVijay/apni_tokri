<?php

declare(strict_types=1);

final class GatewayRefundService
{
    /**
     * Initiate a gateway refund against a captured payment row.
     *
     * @return array{ok: bool, gateway_refund_id?: string, status?: string, message?: string, simulated?: bool}
     */
    public static function refundPayment(int $paymentId, float $amountInr, string $idempotencyKey): array
    {
        if ($amountInr <= 0) {
            return ['ok' => false, 'message' => 'Invalid refund amount.'];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment || (string) ($payment['status'] ?? '') !== 'captured') {
            return ['ok' => false, 'message' => 'Payment is not refundable.'];
        }
        $captured = (float) ($payment['amount'] ?? 0);
        $already = (float) ($payment['amount_refunded_inr'] ?? 0);
        if ($amountInr + $already > $captured + 0.01) {
            return ['ok' => false, 'message' => 'Refund exceeds captured amount.'];
        }

        $gateway = (string) ($payment['gateway'] ?? '');
        if ($gateway === 'simulate') {
            return [
                'ok' => true,
                'gateway_refund_id' => 'sim_ref_' . substr($idempotencyKey, 0, 40),
                'status' => 'processed',
                'simulated' => true,
            ];
        }

        $gwPaymentId = (string) ($payment['gateway_payment_id'] ?? '');
        if ($gwPaymentId === '') {
            return ['ok' => false, 'message' => 'Missing gateway payment reference.'];
        }

        if ($gateway === 'razorpay') {
            return self::razorpayRefund($gwPaymentId, $amountInr, $idempotencyKey);
        }
        if ($gateway === 'cashfree') {
            $orderId = (int) ($payment['order_id'] ?? 0);
            $merchantOrderId = '';
            if ($orderId > 0) {
                $oStmt = Database::pdo()->prepare('SELECT order_number FROM orders WHERE id = ? LIMIT 1');
                $oStmt->execute([$orderId]);
                $merchantOrderId = (string) ($oStmt->fetchColumn() ?: '');
            }
            if ($merchantOrderId === '') {
                return ['ok' => false, 'message' => 'Missing merchant order id for Cashfree refund.'];
            }

            return self::cashfreeRefund($merchantOrderId, $amountInr, $idempotencyKey);
        }

        return ['ok' => false, 'message' => 'Unsupported payment gateway for refunds.'];
    }

    /** @return array{ok: bool, gateway_refund_id?: string, status?: string, message?: string} */
    private static function razorpayRefund(string $gatewayPaymentId, float $amountInr, string $idempotencyKey): array
    {
        if (!RazorpayGateway::isConfigured()) {
            return ['ok' => false, 'message' => 'Razorpay is not configured.'];
        }
        $paise = PaymentService::amountToPaise($amountInr);
        $resp = PaymentHttp::request(
            'POST',
            'https://api.razorpay.com/v1/payments/' . rawurlencode($gatewayPaymentId) . '/refund',
            [
                'amount' => $paise,
                'notes' => ['idempotency_key' => $idempotencyKey],
            ],
            [],
            RazorpayGateway::keyId(),
            RazorpayGateway::keySecret()
        );
        if (!$resp['ok'] || !is_array($resp['json'])) {
            error_log('Razorpay refund HTTP ' . $resp['status']);

            return ['ok' => false, 'message' => 'Gateway refund failed.'];
        }
        $refId = (string) ($resp['json']['id'] ?? '');
        $status = (string) ($resp['json']['status'] ?? 'pending');

        return ['ok' => true, 'gateway_refund_id' => $refId, 'status' => $status];
    }

    /** @return array{ok: bool, gateway_refund_id?: string, status?: string, message?: string} */
    private static function cashfreeRefund(string $merchantOrderId, float $amountInr, string $idempotencyKey): array
    {
        if (!CashfreeGateway::isConfigured()) {
            return ['ok' => false, 'message' => 'Cashfree is not configured.'];
        }
        try {
            $refundUrl = CashfreeGateway::ordersRefundApiPath($merchantOrderId);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => 'Cashfree is not configured.'];
        }
        $body = [
            'refund_amount' => round($amountInr, 2),
            'refund_id' => substr(preg_replace('/[^a-zA-Z0-9_]/', '_', $idempotencyKey), 0, 40),
            'refund_note' => 'Customer refund',
            'refund_type' => 'MERCHANT_INITIATED',
        ];
        $headers = [
            'x-client-id: ' . CashfreeGateway::appId(),
            'x-client-secret: ' . CashfreeGateway::secretKey(),
            'x-api-version: 2023-08-01',
        ];
        $resp = PaymentHttp::request(
            'POST',
            $refundUrl,
            $body,
            $headers
        );
        if (!$resp['ok'] || !is_array($resp['json'])) {
            error_log('Cashfree refund HTTP ' . $resp['status']);

            return ['ok' => false, 'message' => 'Gateway refund failed.'];
        }
        $refId = (string) ($resp['json']['cf_refund_id'] ?? $resp['json']['refund_id'] ?? '');
        $status = (string) ($resp['json']['refund_status'] ?? 'PENDING');

        return ['ok' => true, 'gateway_refund_id' => $refId, 'status' => strtolower($status)];
    }

    /**
     * Refund against subscription_payments row (Stage 11 subscriptions).
     *
     * @return array{ok: bool, gateway_refund_id?: string, status?: string, message?: string, simulated?: bool}
     */
    public static function refundSubscriptionPayment(int $subscriptionPaymentId, float $amountInr, string $idempotencyKey): array
    {
        if ($amountInr <= 0) {
            return ['ok' => false, 'message' => 'Invalid refund amount.'];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM subscription_payments WHERE id = ? LIMIT 1');
        $stmt->execute([$subscriptionPaymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment || (string) ($payment['status'] ?? '') !== 'captured') {
            return ['ok' => false, 'message' => 'Subscription payment is not refundable.'];
        }
        $captured = (float) ($payment['amount'] ?? 0);
        $already = (float) ($payment['amount_refunded_inr'] ?? 0);
        if ($amountInr + $already > $captured + 0.01) {
            return ['ok' => false, 'message' => 'Refund exceeds captured amount.'];
        }
        $gateway = (string) ($payment['gateway'] ?? '');
        if ($gateway === 'simulate') {
            return [
                'ok' => true,
                'gateway_refund_id' => 'sim_sub_ref_' . substr($idempotencyKey, 0, 36),
                'status' => 'processed',
                'simulated' => true,
            ];
        }
        $gwPaymentId = (string) ($payment['gateway_payment_id'] ?? '');
        if ($gwPaymentId === '') {
            return ['ok' => false, 'message' => 'Missing gateway payment reference.'];
        }
        if ($gateway === 'razorpay') {
            return self::razorpayRefund($gwPaymentId, $amountInr, $idempotencyKey);
        }
        if ($gateway === 'cashfree') {
            $merchantOrderId = (string) ($payment['subscription_number'] ?? '');
            if ($merchantOrderId === '') {
                $merchantOrderId = (string) ($payment['gateway_order_id'] ?? '');
            }
            if ($merchantOrderId === '') {
                return ['ok' => false, 'message' => 'Missing merchant order id for subscription Cashfree refund.'];
            }

            return self::cashfreeRefund($merchantOrderId, $amountInr, $idempotencyKey);
        }

        return ['ok' => false, 'message' => 'Unsupported gateway.'];
    }

    public static function liveGatewayRefundsEnabled(): bool
    {
        if (PaymentGatewayConfig::useSimulatedCheckout()) {
            return true;
        }

        return PaymentGatewayConfig::isProductionGatewayMode();
    }
}
