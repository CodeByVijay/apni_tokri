<?php

declare(strict_types=1);

final class RazorpayGateway
{
    public static function isEnabled(): bool
    {
        return (AppSettings::get('razorpay.enabled', '0') ?? '0') === '1';
    }

    public static function isConfigured(): bool
    {
        return self::isEnabled() && self::keyId() !== '' && self::keySecret() !== '';
    }

    public static function keyIdLooksValid(): bool
    {
        $kid = self::keyId();

        return $kid !== '' && preg_match('/^rzp_(test|live)_[A-Za-z0-9]+$/', $kid) === 1;
    }

    public static function keyId(): string
    {
        return trim(AppSettings::get('razorpay.key_id', '') ?? '');
    }

    public static function keySecret(): string
    {
        return trim(AppSettings::getSecret('razorpay.key_secret', '') ?? '');
    }

    public static function webhookSecret(): string
    {
        return trim(AppSettings::getSecret('razorpay.webhook_secret', '') ?? '');
    }

    /**
     * @return array{ok: bool, gateway_order_id?: string, error?: string}
     */
    public static function createOrder(int $amountPaise, string $receipt, string $idempotencyKey, string $referenceId, ?int $orderId = null): array
    {
        if ($amountPaise < 100) {
            PaymentInitDiagnostics::logInitFailure('razorpay', $referenceId, 'validation', $orderId, 0, null, 'amount_below_minimum');

            return ['ok' => false, 'error' => PaymentInitDiagnostics::customerMessage('Razorpay', $referenceId), 'reference_id' => $referenceId];
        }
        $expireAt = PaymentSlotHoldService::razorpayExpireAtUnix();
        $resp = PaymentHttp::request(
            'POST',
            'https://api.razorpay.com/v1/orders',
            [
                'amount' => $amountPaise,
                'currency' => 'INR',
                'receipt' => substr($receipt, 0, 40),
                'expire_at' => $expireAt,
                'notes' => ['idempotency_key' => $idempotencyKey, 'reference_id' => $referenceId],
            ],
            [],
            self::keyId(),
            self::keySecret()
        );
        if (!$resp['ok'] || !is_array($resp['json'])) {
            $stage = $resp['status'] === 401 ? 'gateway_authentication' : 'gateway_api';
            PaymentInitDiagnostics::logInitFailure(
                'razorpay',
                $referenceId,
                $stage,
                $orderId,
                (int) $resp['status'],
                is_array($resp['json']) ? $resp['json'] : null,
                $resp['error'] ?? null
            );
            PaymentGatewayDiagnosticState::recordInitAttempt('razorpay', (int) $resp['status'], false, $referenceId);

            return ['ok' => false, 'error' => PaymentInitDiagnostics::customerMessage('Razorpay', $referenceId), 'reference_id' => $referenceId];
        }
        $id = (string) ($resp['json']['id'] ?? '');
        if ($id === '') {
            PaymentInitDiagnostics::logInitFailure('razorpay', $referenceId, 'response_parse', $orderId, (int) $resp['status'], $resp['json']);

            return ['ok' => false, 'error' => PaymentInitDiagnostics::customerMessage('Razorpay', $referenceId), 'reference_id' => $referenceId];
        }

        PaymentGatewayDiagnosticState::recordInitAttempt('razorpay', (int) $resp['status'], true, $referenceId);

        return [
            'ok' => true,
            'gateway_order_id' => $id,
            'expire_at' => $expireAt,
        ];
    }

    public static function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        $secret = self::keySecret();
        if ($secret === '' || $signature === '') {
            return false;
        }
        $payload = $orderId . '|' . $paymentId;
        $expected = hash_hmac('sha256', $payload, $secret);

        return Security::hashEquals($expected, $signature);
    }

    public static function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $secret = self::webhookSecret();
        if ($secret === '') {
            $secret = self::keySecret();
        }
        if ($secret === '' || $signature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);

        return Security::hashEquals($expected, $signature);
    }

    /**
     * Payments for a Razorpay order (GET /v1/orders/{id}/payments).
     *
     * @return array{
     *     ok: bool,
     *     payments?: list<array<string, mixed>>,
     *     error?: string,
     *     http_status?: int,
     *     network_error?: bool
     * }
     */
    public static function fetchOrderPayments(string $razorpayOrderId): array
    {
        $razorpayOrderId = trim($razorpayOrderId);
        if ($razorpayOrderId === '') {
            return ['ok' => false, 'error' => 'Invalid Razorpay order reference.'];
        }
        if (!self::isConfigured()) {
            return ['ok' => false, 'error' => 'Razorpay is not configured.'];
        }
        $resp = PaymentHttp::request(
            'GET',
            'https://api.razorpay.com/v1/orders/' . rawurlencode($razorpayOrderId) . '/payments',
            null,
            [],
            self::keyId(),
            self::keySecret()
        );
        $status = (int) ($resp['status'] ?? 0);
        if ($status === 0 && !empty($resp['error'])) {
            error_log('Razorpay fetch order payments network error order_id=' . $razorpayOrderId);

            return [
                'ok' => false,
                'error' => 'Razorpay request timed out or could not reach the network.',
                'http_status' => 0,
                'network_error' => true,
            ];
        }
        if (!$resp['ok'] || !is_array($resp['json'])) {
            error_log('Razorpay fetch order payments HTTP ' . $status . ' order_id=' . $razorpayOrderId);

            return [
                'ok' => false,
                'error' => 'Could not fetch Razorpay payment status (HTTP ' . $status . ').',
                'http_status' => $status,
            ];
        }
        $json = $resp['json'];
        $items = $json['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        return ['ok' => true, 'payments' => $items, 'http_status' => $status];
    }

    /**
     * @param list<array<string, mixed>> $payments
     * @return array<string, mixed>|null
     */
    public static function findCapturedPayment(array $payments): ?array
    {
        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            if ((string) ($payment['status'] ?? '') === 'captured') {
                return $payment;
            }
        }

        return null;
    }

    /**
     * Fetch status of an existing Razorpay refund (GET only).
     *
     * @return array{
     *     ok: bool,
     *     refund_status?: string,
     *     gateway_refund_id?: string,
     *     refund_amount?: float,
     *     snapshot?: array<string, mixed>,
     *     error?: string
     * }
     */
    public static function fetchRefund(string $gatewayRefundId): array
    {
        $gatewayRefundId = trim($gatewayRefundId);
        if ($gatewayRefundId === '') {
            return ['ok' => false, 'error' => 'Invalid refund reference.'];
        }
        if (!self::isConfigured()) {
            return ['ok' => false, 'error' => 'Razorpay is not configured.'];
        }
        $resp = PaymentHttp::request(
            'GET',
            'https://api.razorpay.com/v1/refunds/' . rawurlencode($gatewayRefundId),
            null,
            [],
            self::keyId(),
            self::keySecret()
        );
        if (!$resp['ok'] || !is_array($resp['json'])) {
            error_log('Razorpay fetch refund HTTP ' . ($resp['status'] ?? 0));

            return ['ok' => false, 'error' => 'Could not fetch refund status from Razorpay.'];
        }
        $json = $resp['json'];
        $status = (string) ($json['status'] ?? '');
        $amountPaise = (int) ($json['amount'] ?? 0);

        return [
            'ok' => true,
            'refund_status' => $status,
            'gateway_refund_id' => (string) ($json['id'] ?? $gatewayRefundId),
            'refund_amount' => $amountPaise > 0 ? $amountPaise / 100 : null,
            'snapshot' => $json,
        ];
    }
}
