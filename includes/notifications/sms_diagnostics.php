<?php

declare(strict_types=1);

/**
 * Sanitized SMS provider diagnostics for admin (no secrets, no full mobile, no OTP).
 */
final class SmsDiagnosticsService
{
    private const PREFIX = 'sms.diag.';

    /** @param array<string, mixed> $context */
    public static function record(bool $success, string $channel, array $context = []): void
    {
        if (!Install::isSchemaReady()) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $batch = [
            self::PREFIX . 'last_attempt_at' => ['value' => $now],
            self::PREFIX . 'last_channel' => ['value' => self::sanitizeToken($channel, 32)],
            self::PREFIX . 'last_provider' => ['value' => self::sanitizeToken((string) ($context['provider'] ?? ''), 32)],
            self::PREFIX . 'last_http_status' => ['value' => isset($context['http_status']) ? (string) (int) $context['http_status'] : ''],
            self::PREFIX . 'last_error_code' => ['value' => self::sanitizeToken((string) ($context['error_code'] ?? ''), 64)],
            self::PREFIX . 'last_error_message' => ['value' => self::sanitizeMessage((string) ($context['error_message'] ?? ''))],
            self::PREFIX . 'last_delivery_status' => ['value' => $success ? 'accepted' : 'failed'],
        ];
        if ($success) {
            $batch[self::PREFIX . 'last_success_at'] = ['value' => $now];
        }
        if (!empty($context['mobile_suffix']) && preg_match('/^\d{4}$/', (string) $context['mobile_suffix'])) {
            $batch[self::PREFIX . 'last_mobile_suffix'] = ['value' => (string) $context['mobile_suffix']];
        }
        try {
            AppSettings::setMany($batch);
        } catch (Throwable $e) {
            error_log('SmsDiagnosticsService: could not persist diagnostics');
        }
    }

    /** @return array<string, string> */
    public static function adminSnapshot(): array
    {
        $keys = [
            'last_attempt_at', 'last_success_at', 'last_channel', 'last_provider',
            'last_http_status', 'last_error_code', 'last_error_message',
            'last_delivery_status', 'last_mobile_suffix',
        ];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = trim(AppSettings::get(self::PREFIX . $k, '') ?? '');
        }

        return $out;
    }

    /**
     * @param array{ok?: bool, error?: string, message_id?: string, response?: string} $result
     */
    public static function recordFromGateway(string $provider, ?int $httpStatus, array $result, string $channel, ?string $mobile = null): void
    {
        $suffix = null;
        if ($mobile !== null) {
            $n = Validation::normalizeMobileIndia($mobile) ?? preg_replace('/\D+/', '', $mobile);
            if (is_string($n) && strlen($n) >= 4) {
                $suffix = substr($n, -4);
            }
        }
        $err = (string) ($result['error'] ?? '');
        self::record(!empty($result['ok']), $channel, [
            'provider' => $provider,
            'http_status' => $httpStatus,
            'error_code' => self::classifyProviderError($err, $httpStatus),
            'error_message' => $err !== '' ? $err : (string) ($result['response'] ?? ''),
            'mobile_suffix' => $suffix,
        ]);
    }

    public static function classifyProviderError(string $internal, ?int $httpStatus): string
    {
        $t = strtolower($internal);
        if ($httpStatus === 0 || strpos($t, 'timeout') !== false || strpos($t, 'transport') !== false) {
            return 'provider_unavailable';
        }
        if (strpos($t, 'not configured') !== false || strpos($t, 'contract') !== false) {
            return 'configuration_missing';
        }
        if (strpos($t, 'rejected') !== false || strpos($t, 'failed') !== false) {
            return 'provider_rejected';
        }

        return 'send_failed';
    }

    public static function sanitizeMessage(string $msg): string
    {
        $msg = preg_replace('/\b(?:api[_-]?key|bearer|authorization|token|secret|password)\b[^\s]*/i', '[redacted]', $msg) ?? $msg;
        $msg = preg_replace('/\b\d{6}\b/', '[otp]', $msg) ?? $msg;
        $msg = preg_replace('/\b[6-9]\d{9}\b/', '[mobile]', $msg) ?? $msg;

        return mb_substr(trim($msg), 0, 500);
    }

    private static function sanitizeToken(string $value, int $max): string
    {
        $value = preg_replace('/[^\w\.\-]/', '_', strtolower(trim($value))) ?? '';

        return mb_substr($value, 0, $max);
    }
}
