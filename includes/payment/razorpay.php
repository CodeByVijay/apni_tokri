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
    public static function createOrder(int $amountPaise, string $receipt, string $idempotencyKey): array
    {
        $resp = PaymentHttp::request(
            'POST',
            'https://api.razorpay.com/v1/orders',
            [
                'amount' => $amountPaise,
                'currency' => 'INR',
                'receipt' => $receipt,
                'notes' => ['idempotency_key' => $idempotencyKey],
            ],
            [],
            self::keyId(),
            self::keySecret()
        );
        if (!$resp['ok'] || !is_array($resp['json'])) {
            error_log('Razorpay create order HTTP ' . $resp['status']);

            return ['ok' => false, 'error' => 'Could not start Razorpay payment.'];
        }
        $id = (string) ($resp['json']['id'] ?? '');
        if ($id === '') {
            return ['ok' => false, 'error' => 'Invalid Razorpay response.'];
        }

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
