<?php

declare(strict_types=1);

/** @return array{ok: bool, message_id?: string, response?: string, error?: string} */
interface SmsGatewayAdapter
{
    public function send(string $mobile, string $message, string $senderId, ?string $dltTemplateId): array;
}

final class SimulateSmsGateway implements SmsGatewayAdapter
{
    public function send(string $mobile, string $message, string $senderId, ?string $dltTemplateId): array
    {
        $id = 'sim_' . bin2hex(random_bytes(8));

        return [
            'ok' => true,
            'message_id' => $id,
            'response' => json_encode(['simulate' => true, 'sender' => $senderId]),
        ];
    }
}

final class HttpSmsGateway implements SmsGatewayAdapter
{
    public function send(string $mobile, string $message, string $senderId, ?string $dltTemplateId): array
    {
        $url = trim(AppSettings::get('sms.api_url', '') ?? '');
        $key = AppSettings::getSecret('sms.api_key', '') ?? '';
        if ($url === '' || $key === '') {
            return ['ok' => false, 'error' => 'SMS API URL or key not configured.'];
        }
        $urlError = SmsSettingsService::validateHttpApiUrl($url);
        if ($urlError !== null) {
            return ['ok' => false, 'error' => 'SMS API URL is not allowed.'];
        }
        if (!SmsHttpResponsePolicy::isConfigured()) {
            return ['ok' => false, 'error' => 'HTTP SMS success contract not configured (sms.http.* settings).'];
        }

        $payload = [
            'sender' => $senderId,
            'mobile' => $mobile,
            'message' => $message,
            'template_id' => $dltTemplateId,
        ];
        try {
            $resp = PaymentHttp::request('POST', $url, $payload, [
                'Authorization: Bearer ' . $key,
            ], null, null, SmsHttpResponsePolicy::timeoutSeconds());
            $body = (string) ($resp['body'] ?? '');
            if (!empty($resp['error']) && (int) ($resp['status'] ?? 0) === 0) {
                return ['ok' => false, 'error' => 'Gateway timeout or transport error.', 'response' => mb_substr($body, 0, 2000), 'http_status' => 0];
            }

            $eval = SmsHttpResponsePolicy::evaluate((int) ($resp['status'] ?? 0), $resp['json'], $body);
            if (empty($eval['ok'])) {
                return [
                    'ok' => false,
                    'error' => (string) ($eval['error'] ?? 'Send rejected.'),
                    'response' => mb_substr($body, 0, 2000),
                    'http_status' => (int) ($resp['status'] ?? 0),
                ];
            }

            return [
                'ok' => true,
                'message_id' => (string) ($eval['message_id'] ?? ''),
                'response' => mb_substr($body, 0, 2000),
                'http_status' => (int) ($resp['status'] ?? 0),
            ];
        } catch (Throwable $e) {
            error_log('HttpSmsGateway: request failed');

            return ['ok' => false, 'error' => 'Gateway transport error.', 'http_status' => 0];
        }
    }
}

final class SmsGatewayFactory
{
    /** @var SmsGatewayAdapter|null */
    private static $testAdapter = null;

    public static function setTestAdapter(?SmsGatewayAdapter $adapter): void
    {
        self::$testAdapter = $adapter;
    }

    public static function adapter(): SmsGatewayAdapter
    {
        if (self::$testAdapter instanceof SmsGatewayAdapter) {
            return self::$testAdapter;
        }
        $provider = AppSettings::get('sms.provider', 'simulate') ?? 'simulate';
        if ($provider === 'http') {
            return new HttpSmsGateway();
        }

        return new SimulateSmsGateway();
    }
}
