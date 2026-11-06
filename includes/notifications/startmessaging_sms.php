<?php

declare(strict_types=1);

/**
 * StartMessaging OTP API (POST /otp/send, X-API-Key).
 * @see https://startmessaging.com/docs/sms/send-sms
 */
final class StartMessagingSmsService
{
    public const DEFAULT_BASE_URL = 'https://api.startmessaging.com';

    /** @var callable|null function(string $method, string $url, ?array $json, array $headers, ?string, ?string): array */
    public static $testRequestHandler = null;

    public static function otpSendUrl(): string
    {
        $configured = trim(AppSettings::get(SmsSettingsService::KEY_API_URL, '') ?? '');
        if ($configured === '') {
            return rtrim(self::DEFAULT_BASE_URL, '/') . '/otp/send';
        }
        if (preg_match('#/otp/send/?$#i', $configured)) {
            return $configured;
        }

        return rtrim($configured, '/') . '/otp/send';
    }

    public static function mobileToE164(string $mobileIndia): string
    {
        $m = Validation::normalizeMobileIndia($mobileIndia) ?? '';

        return $m !== '' ? '+91' . $m : '';
    }

    /**
     * @return array{ok: bool, message_id?: string, response?: string, error?: string, http_status?: int}
     */
    public static function sendOtp(string $mobileIndia, string $otpPlain, int $ttlSeconds): array
    {
        $phone = self::mobileToE164($mobileIndia);
        if ($phone === '' || !preg_match('/^\d{6}$/', $otpPlain)) {
            return ['ok' => false, 'error' => 'Invalid mobile or OTP.'];
        }

        $apiKey = AppSettings::getSecret(SmsSettingsService::KEY_API_KEY, '') ?? '';
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'SMS API key is not configured.'];
        }

        $templateId = trim(AppSettings::get('sms.dlt.otp', '') ?? '');
        $variables = ['otp' => $otpPlain];
        if (self::includeAppNameVariable()) {
            $variables['appName'] = trim((string) (App::config()['app']['name'] ?? 'Veggie Tokri'));
        }
        if (self::includeExpiryVariable()) {
            $variables['expiry'] = (string) max(1, (int) ceil($ttlSeconds / 60));
        }

        $payload = [
            'phoneNumber' => $phone,
            'variables' => $variables,
        ];
        if ($templateId !== '') {
            $payload['templateId'] = $templateId;
        }

        $url = self::otpSendUrl();
        $urlError = SmsSettingsService::validateHttpApiUrl($url);
        if ($urlError !== null) {
            return ['ok' => false, 'error' => 'SMS API URL is not allowed.'];
        }

        $headers = [
            'X-API-Key: ' . $apiKey,
            'Accept: application/json',
        ];

        try {
            if (self::$testRequestHandler !== null) {
                $resp = (self::$testRequestHandler)('POST', $url, $payload, $headers, null, null);
            } else {
                $resp = PaymentHttp::request(
                    'POST',
                    $url,
                    $payload,
                    $headers,
                    null,
                    null,
                    SmsSettingsService::startMessagingTimeoutSeconds()
                );
            }
        } catch (Throwable $e) {
            error_log('StartMessagingSmsService: request failed');

            return ['ok' => false, 'error' => 'Gateway transport error.', 'http_status' => 0];
        }

        $status = (int) ($resp['status'] ?? 0);
        $body = (string) ($resp['body'] ?? '');
        $json = isset($resp['json']) && is_array($resp['json']) ? $resp['json'] : null;

        if (!empty($resp['error']) && $status === 0) {
            return [
                'ok' => false,
                'error' => 'Gateway timeout or transport error.',
                'response' => mb_substr($body, 0, 2000),
                'http_status' => 0,
            ];
        }

        $parsed = self::parseSendResponse($status, $json, $body);
        $parsed['http_status'] = $status;
        if (!empty($parsed['ok'])) {
            $parsed['response'] = mb_substr($body, 0, 2000);
        } else {
            $parsed['response'] = mb_substr($body, 0, 2000);
            error_log('StartMessagingSmsService: send rejected (HTTP ' . $status . ')');
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array{ok: bool, message_id?: string, error?: string}
     */
    public static function parseSendResponse(int $httpStatus, ?array $json, string $rawBody): array
    {
        if ($httpStatus !== 201 && $httpStatus !== 200) {
            $msg = self::publicErrorFromJson($json);

            return ['ok' => false, 'error' => $msg ?? ('HTTP status ' . $httpStatus . ' not accepted.')];
        }
        if ($json === null) {
            return ['ok' => false, 'error' => 'Provider response is not valid JSON.'];
        }
        if (array_key_exists('success', $json) && $json['success'] !== true) {
            return ['ok' => false, 'error' => self::publicErrorFromJson($json) ?? 'Provider rejected send.'];
        }
        $data = $json['data'] ?? null;
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Provider response missing data envelope.'];
        }
        $messageId = trim((string) ($data['messageId'] ?? ''));
        if ($messageId === '') {
            return ['ok' => false, 'error' => 'Provider response missing messageId.'];
        }

        return ['ok' => true, 'message_id' => $messageId];
    }

    /** @param array<string, mixed>|null $json */
    private static function publicErrorFromJson(?array $json): ?string
    {
        if ($json === null) {
            return null;
        }
        foreach (['message', 'error', 'errorMessage'] as $key) {
            if (!empty($json[$key]) && is_string($json[$key])) {
                return 'Provider rejected send.';
            }
        }
        if (isset($json['data']) && is_array($json['data']) && !empty($json['data']['error'])) {
            return 'Provider rejected send.';
        }

        return null;
    }

    public static function includeAppNameVariable(): bool
    {
        return (AppSettings::get('sms.startmessaging.variable_app_name', '1') ?? '1') === '1';
    }

    public static function includeExpiryVariable(): bool
    {
        return (AppSettings::get('sms.startmessaging.variable_expiry', '0') ?? '0') === '1';
    }
}

/** Plain-text SMS (delivery) is not supported on StartMessaging OTP API — use generic HTTP provider for delivery. */
final class StartMessagingSmsGateway implements SmsGatewayAdapter
{
    public function send(string $mobile, string $message, string $senderId, ?string $dltTemplateId): array
    {
        return [
            'ok' => false,
            'error' => 'StartMessaging supports OTP login only. Switch SMS provider to HTTP for delivery notifications.',
        ];
    }
}
