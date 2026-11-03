<?php

declare(strict_types=1);

/**
 * Payment initiation diagnostics (server logs only — never secrets or PII).
 */
final class PaymentInitDiagnostics
{
    public static function referenceId(): string
    {
        return 'PAY-' . date('ymd') . '-' . strtoupper(substr(Security::randomToken(3), 0, 6));
    }

    /**
     * @param array<string, mixed>|null $json Decoded gateway JSON (safe fields only logged).
     */
    public static function logInitFailure(
        string $gateway,
        string $referenceId,
        string $stage,
        ?int $orderId = null,
        int $httpStatus = 0,
        ?array $json = null,
        ?string $curlError = null
    ): void {
        $parts = [
            'Payment init failed',
            'gateway=' . $gateway,
            'ref=' . $referenceId,
            'stage=' . $stage,
        ];
        if ($orderId !== null && $orderId > 0) {
            $parts[] = 'order_id=' . $orderId;
        }
        if ($httpStatus > 0) {
            $parts[] = 'http=' . $httpStatus;
        }
        $safe = self::safeErrorFromJson($gateway, $json);
        if ($safe['code'] !== '') {
            $parts[] = 'code=' . $safe['code'];
        }
        if ($safe['message'] !== '') {
            $parts[] = 'msg=' . self::truncate($safe['message'], 120);
        }
        if ($curlError !== null && $curlError !== '') {
            $parts[] = 'transport=' . self::truncate($curlError, 80);
        }
        error_log(implode(' ', $parts));
    }

    public static function customerMessage(string $gatewayLabel, string $referenceId): string
    {
        return 'We could not start ' . $gatewayLabel . ' payment. Please try again in a moment or choose another payment method. Reference: ' . $referenceId;
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array{code: string, message: string}
     */
    public static function safeErrorFromJson(string $gateway, ?array $json): array
    {
        if ($json === null) {
            return ['code' => '', 'message' => ''];
        }
        if ($gateway === 'razorpay') {
            $err = isset($json['error']) && is_array($json['error']) ? $json['error'] : $json;
            $code = (string) ($err['code'] ?? '');
            $message = (string) ($err['description'] ?? $err['reason'] ?? '');

            return ['code' => $code, 'message' => $message];
        }
        $code = (string) ($json['code'] ?? $json['type'] ?? '');
        $message = (string) ($json['message'] ?? '');

        return ['code' => $code, 'message' => $message];
    }

    private static function truncate(string $s, int $max): string
    {
        $s = preg_replace('/\s+/', ' ', trim($s)) ?? '';

        return strlen($s) > $max ? substr($s, 0, $max - 1) . '…' : $s;
    }
}
