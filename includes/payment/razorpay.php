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
        $resp = PaymentHttp::request(
            'POST',
            'https://api.razorpay.com/v1/orders',
            [
                'amount' => $amountPaise,
                'currency' => 'INR',
                'receipt' => substr($receipt, 0, 40),
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

        return ['ok' => true, 'gateway_order_id' => $id];
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
}
