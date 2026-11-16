<?php

declare(strict_types=1);

/**
 * FCM HTTP v1 send API.
 * @see https://firebase.google.com/docs/cloud-messaging/send/v1-api
 */
final class FirebaseFcmClient
{
    /** @var callable|null function(string $method, string $url, ?array $json, array $headers): array */
    public static $testHandler = null;

    /**
     * @param array<string, mixed> $message FCM v1 message body (without outer "message" if $wrap true)
     * @return array{ok: bool, status: int, message_name?: string, error_code?: string, error_message?: string, token_invalid?: bool}
     */
    public static function send(string $firebaseProjectId, string $accessToken, array $message): array
    {
        $firebaseProjectId = trim($firebaseProjectId);
        if ($firebaseProjectId === '' || $accessToken === '') {
            return ['ok' => false, 'status' => 0, 'error_code' => 'missing_params'];
        }
        $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($firebaseProjectId) . '/messages:send';
        $payload = ['message' => $message];
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=UTF-8',
        ];

        if (self::$testHandler !== null) {
            $resp = (self::$testHandler)('POST', $url, $payload, $headers);
        } else {
            $resp = PaymentHttp::request('POST', $url, $payload, $headers, null, null, 25);
        }

        $status = (int) ($resp['status'] ?? 0);
        $json = is_array($resp['json'] ?? null) ? $resp['json'] : null;
        if ($status >= 200 && $status < 300 && is_array($json) && !empty($json['name'])) {
            return ['ok' => true, 'status' => $status, 'message_name' => (string) $json['name']];
        }

        $errorCode = '';
        $errorMessage = '';
        $tokenInvalid = false;
        if (is_array($json) && isset($json['error']) && is_array($json['error'])) {
            $errorCode = (string) ($json['error']['status'] ?? '');
            $errorMessage = (string) ($json['error']['message'] ?? '');
            if ($errorCode === 'NOT_FOUND' || $errorCode === 'INVALID_ARGUMENT') {
                $tokenInvalid = stripos($errorMessage, 'registration token') !== false
                    || stripos($errorMessage, 'not a valid FCM') !== false;
            }
            if ($errorCode === 'UNAUTHENTICATED' || $errorCode === 'PERMISSION_DENIED') {
                $tokenInvalid = false;
            }
        }

        return [
            'ok' => false,
            'status' => $status,
            'error_code' => $errorCode !== '' ? $errorCode : 'fcm_http_' . $status,
            'error_message' => $errorMessage !== '' ? $errorMessage : 'FCM request failed.',
            'token_invalid' => $tokenInvalid,
        ];
    }
}
