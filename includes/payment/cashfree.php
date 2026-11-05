<?php

declare(strict_types=1);

final class CashfreeGateway
{
    private static function baseUrl(): string
    {
        return PaymentGatewayConfig::cashfreePgApiBase();
    }

    public static function isEnabled(): bool
    {
        return (AppSettings::get('cashfree.enabled', '0') ?? '0') === '1';
    }

    public static function isConfigured(): bool
    {
        return self::isEnabled() && self::appId() !== '' && self::secretKey() !== '';
    }

    public static function appIdLooksValid(): bool
    {
        $id = self::appId();

        return strlen($id) >= 8 && preg_match('/^[A-Za-z0-9_-]+$/', $id) === 1;
    }

    public static function appId(): string
    {
        return trim(AppSettings::get('cashfree.app_id', '') ?? '');
    }

    public static function secretKey(): string
    {
        return trim(AppSettings::getSecret('cashfree.secret_key', '') ?? '');
    }

    public static function webhookSecret(): string
    {
        return trim(AppSettings::getSecret('cashfree.webhook_secret', '') ?? '');
    }

    /** Secret used to verify PG webhooks (client secret per Cashfree docs; optional webhook_secret override). */
    public static function webhookSigningSecret(): string
    {
        $override = self::webhookSecret();
        if ($override !== '') {
            return $override;
        }

        return self::secretKey();
    }

    /** @return array<string, string> */
    private static function headers(): array
    {
        return [
            'x-client-id: ' . self::appId(),
            'x-client-secret: ' . self::secretKey(),
            'x-api-version: 2023-08-01',
        ];
    }

    /**
     * @return array{ok: bool, gateway_order_id?: string, payment_session_id?: string, error?: string}
     */
    public static function createOrder(
        float $amountInr,
        string $orderId,
        string $customerPhone,
        string $returnUrl,
        string $referenceId,
        ?int $internalOrderId = null
    ): array {
        if ($amountInr < 1.0) {
            PaymentInitDiagnostics::logInitFailure('cashfree', $referenceId, 'validation', $internalOrderId, 0, null, 'amount_below_minimum');

            return ['ok' => false, 'error' => PaymentInitDiagnostics::customerMessage('Cashfree', $referenceId), 'reference_id' => $referenceId];
        }
        $phone = preg_replace('/\D+/', '', $customerPhone) ?: '9999999999';
        $body = [
            'order_id' => $orderId,
            'order_amount' => round($amountInr, 2),
            'order_currency' => 'INR',
            'customer_details' => [
                'customer_id' => $phone,
                'customer_phone' => $phone,
            ],
            'order_meta' => ['return_url' => $returnUrl, 'notify_url' => SeoUrl::absolute('/webhooks/cashfree.php')],
        ];
        $resp = PaymentHttp::request('POST', self::baseUrl() . '/orders', $body, self::headers());
        if (!$resp['ok'] || !is_array($resp['json'])) {
            $stage = $resp['status'] === 401 ? 'gateway_authentication' : 'gateway_api';
            PaymentInitDiagnostics::logInitFailure(
                'cashfree',
                $referenceId,
                $stage,
                $internalOrderId,
                (int) $resp['status'],
                is_array($resp['json']) ? $resp['json'] : null,
                $resp['error'] ?? null
            );
            PaymentGatewayDiagnosticState::recordInitAttempt('cashfree', (int) $resp['status'], false, $referenceId);

            return ['ok' => false, 'error' => PaymentInitDiagnostics::customerMessage('Cashfree', $referenceId), 'reference_id' => $referenceId];
        }
        $cfId = (string) ($resp['json']['cf_order_id'] ?? $resp['json']['order_id'] ?? '');
        $session = (string) ($resp['json']['payment_session_id'] ?? '');
        if ($cfId === '' || $session === '') {
            PaymentInitDiagnostics::logInitFailure('cashfree', $referenceId, 'response_parse', $internalOrderId, (int) $resp['status'], $resp['json']);

            return ['ok' => false, 'error' => PaymentInitDiagnostics::customerMessage('Cashfree', $referenceId), 'reference_id' => $referenceId];
        }

        PaymentGatewayDiagnosticState::recordInitAttempt('cashfree', (int) $resp['status'], true, $referenceId);

        return [
            'ok' => true,
            'gateway_order_id' => $cfId,
            'payment_session_id' => $session,
        ];
    }

    /**
     * @return array{ok: bool, order_status?: string, cf_order_id?: string, order_amount?: float, payment_session_id?: string, error?: string}
     */
    public static function fetchOrder(string $merchantOrderId): array
    {
        $merchantOrderId = trim($merchantOrderId);
        if ($merchantOrderId === '') {
            return ['ok' => false, 'error' => 'Invalid order reference.'];
        }
        $url = self::baseUrl() . '/orders/' . rawurlencode($merchantOrderId);
        $resp = PaymentHttp::request('GET', $url, null, self::headers());
        if (!$resp['ok'] || !is_array($resp['json'])) {
            error_log('Cashfree fetch order HTTP ' . $resp['status']);

            return ['ok' => false, 'error' => 'Could not fetch Cashfree order status.'];
        }

        return [
            'ok' => true,
            'order_status' => (string) ($resp['json']['order_status'] ?? ''),
            'cf_order_id' => (string) ($resp['json']['cf_order_id'] ?? ''),
            'order_amount' => (float) ($resp['json']['order_amount'] ?? 0),
            'payment_session_id' => (string) ($resp['json']['payment_session_id'] ?? ''),
        ];
    }

    /**
     * @return array{ok: bool, payments?: list<array<string, mixed>>, error?: string}
     */
    public static function fetchPayments(string $merchantOrderId): array
    {
        $merchantOrderId = trim($merchantOrderId);
        if ($merchantOrderId === '') {
            return ['ok' => false, 'error' => 'Invalid order reference.'];
        }
        $url = self::baseUrl() . '/orders/' . rawurlencode($merchantOrderId) . '/payments';
        $resp = PaymentHttp::request('GET', $url, null, self::headers());
        if (!$resp['ok'] || !is_array($resp['json'])) {
            error_log('Cashfree fetch payments HTTP ' . $resp['status']);

            return ['ok' => false, 'error' => 'Could not fetch Cashfree payment status.'];
        }

        $json = $resp['json'];
        if (isset($json['payments']) && is_array($json['payments'])) {
            $list = $json['payments'];
        } elseif ($json !== [] && array_keys($json) === range(0, count($json) - 1)) {
            $list = $json;
        } else {
            $list = [$json];
        }

        return ['ok' => true, 'payments' => $list];
    }

    /**
     * @param list<array<string, mixed>> $payments
     * @return array<string, mixed>|null
     */
    public static function findSuccessfulCapturedPayment(array $payments): ?array
    {
        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $status = strtoupper((string) ($payment['payment_status'] ?? ''));
            $captured = !empty($payment['is_captured']);
            if ($status === 'SUCCESS' && $captured) {
                return $payment;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $payments
     */
    public static function hasTerminalFailure(array $payments): bool
    {
        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $status = strtoupper((string) ($payment['payment_status'] ?? ''));
            if (in_array($status, ['FAILED', 'USER_DROPPED', 'VOID', 'CANCELLED'], true)) {
                return true;
            }
        }

        return false;
    }

    public static function verifyWebhookSignature(string $rawBody, string $signature, string $timestamp): bool
    {
        if ($signature === '' || trim($timestamp) === '') {
            return false;
        }
        $secret = self::webhookSigningSecret();
        if ($secret === '') {
            return false;
        }
        $payload = $timestamp . $rawBody;
        $expected = base64_encode(hash_hmac('sha256', $payload, $secret, true));

        return Security::hashEquals($expected, $signature);
    }

    public static function ordersRefundApiPath(string $merchantOrderId): string
    {
        return PaymentGatewayConfig::cashfreePgApiBase()
            . '/orders/' . rawurlencode($merchantOrderId) . '/refunds';
    }
}
