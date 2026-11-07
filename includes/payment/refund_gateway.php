<?php

declare(strict_types=1);

final class GatewayRefundService
{
    /**
     * Initiate a gateway refund against a captured payment row.
     *
     * @return array{ok: bool, gateway_refund_id?: string, status?: string, message?: string, simulated?: bool, ambiguous?: bool, explicit_failure?: bool}
     */
    public static function refundPayment(int $paymentId, float $amountInr, string $idempotencyKey): array
    {
        if ($amountInr <= 0) {
            return ['ok' => false, 'message' => 'Invalid refund amount.', 'explicit_failure' => true];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment || (string) ($payment['status'] ?? '') !== 'captured') {
            return ['ok' => false, 'message' => 'Payment is not refundable.', 'explicit_failure' => true];
        }
        $captured = (float) ($payment['amount'] ?? 0);
        $already = (float) ($payment['amount_refunded_inr'] ?? 0);
        if ($amountInr + $already > $captured + 0.01) {
            return ['ok' => false, 'message' => 'Refund exceeds captured amount.', 'explicit_failure' => true];
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
            return ['ok' => false, 'message' => 'Missing gateway payment reference.', 'explicit_failure' => true];
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
                return ['ok' => false, 'message' => 'Missing merchant order id for Cashfree refund.', 'explicit_failure' => true];
            }

            return self::cashfreeRefund($merchantOrderId, $amountInr, $idempotencyKey);
        }

        return ['ok' => false, 'message' => 'Unsupported payment gateway for refunds.', 'explicit_failure' => true];
    }

    /**
     * @param array{ok?: bool, status?: int, json?: mixed, error?: string} $resp
     * @return array{ok: false, message: string, ambiguous: bool, explicit_failure: bool}
     */
    private static function refundHttpFailure(array $resp, string $logLabel): array
    {
        $status = (int) ($resp['status'] ?? 0);
        $ambiguous = $status === 0 || ($status >= 500 && $status < 600);
        error_log($logLabel . ' HTTP ' . $status);

        return [
            'ok' => false,
            'message' => $ambiguous
                ? 'Gateway refund response was inconclusive. Reconcile status before retrying.'
                : 'Gateway refund failed.',
            'ambiguous' => $ambiguous,
            'explicit_failure' => !$ambiguous,
        ];
    }

    /** @return array{ok: bool, gateway_refund_id?: string, status?: string, message?: string, ambiguous?: bool, explicit_failure?: bool} */
    private static function razorpayRefund(string $gatewayPaymentId, float $amountInr, string $idempotencyKey): array
    {
        if (!RazorpayGateway::isConfigured()) {
            return ['ok' => false, 'message' => 'Razorpay is not configured.', 'explicit_failure' => true];
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
            return self::refundHttpFailure($resp, 'Razorpay refund');
        }
        $refId = (string) ($resp['json']['id'] ?? '');
        $status = (string) ($resp['json']['status'] ?? 'pending');

        return ['ok' => true, 'gateway_refund_id' => $refId, 'status' => $status];
    }

    /** @return array{ok: bool, gateway_refund_id?: string, status?: string, message?: string, ambiguous?: bool, explicit_failure?: bool} */
    private static function cashfreeRefund(string $merchantOrderId, float $amountInr, string $idempotencyKey): array
    {
        if (!CashfreeGateway::isConfigured()) {
            return ['ok' => false, 'message' => 'Cashfree is not configured.', 'explicit_failure' => true];
        }
        try {
            $refundUrl = CashfreeGateway::ordersRefundApiPath($merchantOrderId);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => 'Cashfree is not configured.', 'explicit_failure' => true];
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
            return self::refundHttpFailure($resp, 'Cashfree refund');
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

    /**
     * Read-only gateway refund status lookup — never initiates a refund.
     *
     * @param array<string, mixed> $paymentRefundRow payment_refunds row with optional order_number join
     * @return array{
     *     ok: bool,
     *     missing_reference?: bool,
     *     api_error?: bool,
     *     gateway?: string,
     *     lookup_reference?: string,
     *     refund_status?: string,
     *     gateway_refund_id?: string|null,
     *     refund_amount?: float|null,
     *     snapshot?: array<string, mixed>,
     *     message?: string
     * }
     */
    public static function lookupRefundStatus(array $paymentRefundRow): array
    {
        $gateway = (string) ($paymentRefundRow['gateway'] ?? '');
        if ($gateway === 'simulate') {
            $status = strtolower((string) ($paymentRefundRow['gateway_status'] ?? 'pending'));

            return [
                'ok' => true,
                'gateway' => 'simulate',
                'lookup_reference' => (string) ($paymentRefundRow['gateway_refund_id'] ?? ''),
                'refund_status' => $status,
                'gateway_refund_id' => $paymentRefundRow['gateway_refund_id'] ?? null,
                'refund_amount' => isset($paymentRefundRow['amount_inr']) ? (float) $paymentRefundRow['amount_inr'] : null,
                'snapshot' => ['source' => 'local_simulate', 'gateway_status' => $status],
            ];
        }
        if ($gateway === 'cashfree') {
            $merchantOrderId = trim((string) ($paymentRefundRow['order_number'] ?? ''));
            if ($merchantOrderId === '') {
                return ['ok' => false, 'message' => 'Missing merchant order id for Cashfree lookup.', 'api_error' => true];
            }
            $lookup = trim((string) ($paymentRefundRow['gateway_refund_id'] ?? ''));
            if ($lookup === '') {
                $idempotency = trim((string) ($paymentRefundRow['idempotency_key'] ?? ''));
                if ($idempotency === '') {
                    return [
                        'ok' => false,
                        'missing_reference' => true,
                        'message' => 'Gateway refund reference is missing — status reconciliation is unavailable.',
                    ];
                }
                $lookup = substr(preg_replace('/[^a-zA-Z0-9_]/', '_', $idempotency), 0, 40);
            }
            $resp = CashfreeGateway::fetchRefund($merchantOrderId, $lookup);
            if (!$resp['ok']) {
                return [
                    'ok' => false,
                    'api_error' => true,
                    'gateway' => 'cashfree',
                    'lookup_reference' => $lookup,
                    'message' => $resp['error'] ?? 'Could not fetch Cashfree refund status.',
                ];
            }

            return [
                'ok' => true,
                'gateway' => 'cashfree',
                'lookup_reference' => $lookup,
                'refund_status' => (string) ($resp['refund_status'] ?? ''),
                'gateway_refund_id' => $resp['gateway_refund_id'] ?? null,
                'refund_amount' => $resp['refund_amount'] ?? null,
                'snapshot' => self::sanitizeStatusSnapshot($resp['snapshot'] ?? []),
            ];
        }
        if ($gateway === 'razorpay') {
            $lookup = trim((string) ($paymentRefundRow['gateway_refund_id'] ?? ''));
            if ($lookup === '') {
                return [
                    'ok' => false,
                    'missing_reference' => true,
                    'message' => 'Gateway refund reference is missing — status reconciliation is unavailable.',
                ];
            }
            $resp = RazorpayGateway::fetchRefund($lookup);
            if (!$resp['ok']) {
                return [
                    'ok' => false,
                    'api_error' => true,
                    'gateway' => 'razorpay',
                    'lookup_reference' => $lookup,
                    'message' => $resp['error'] ?? 'Could not fetch Razorpay refund status.',
                ];
            }

            return [
                'ok' => true,
                'gateway' => 'razorpay',
                'lookup_reference' => $lookup,
                'refund_status' => (string) ($resp['refund_status'] ?? ''),
                'gateway_refund_id' => $resp['gateway_refund_id'] ?? null,
                'refund_amount' => $resp['refund_amount'] ?? null,
                'snapshot' => self::sanitizeStatusSnapshot($resp['snapshot'] ?? []),
            ];
        }

        return ['ok' => false, 'message' => 'Unsupported gateway for status lookup.'];
    }

    /** @param array<string, mixed> $json */
    public static function sanitizeStatusSnapshot(array $json): array
    {
        $allowed = [
            'refund_status', 'status', 'cf_refund_id', 'refund_id', 'refund_amount', 'refund_currency',
            'id', 'amount', 'currency', 'created_at', 'payment_id',
        ];
        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $json)) {
                $out[$key] = $json[$key];
            }
        }

        return $out;
    }
}
