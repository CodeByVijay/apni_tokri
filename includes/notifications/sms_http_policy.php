<?php

declare(strict_types=1);

/**
 * Configurable HTTP SMS provider success contract (see settings sms.http.*).
 */
final class SmsHttpResponsePolicy
{
    public static function isConfigured(): bool
    {
        $path = trim(AppSettings::get('sms.http.message_id_json_path', '') ?? '');

        return $path !== '';
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array{ok: bool, message_id?: string, error?: string, response_excerpt?: string}
     */
    public static function evaluate(int $httpStatus, ?array $json, string $rawBody): array
    {
        $excerpt = mb_substr($rawBody, 0, 500);
        if (!self::isConfigured()) {
            return ['ok' => false, 'error' => 'HTTP SMS success contract not configured.', 'response_excerpt' => $excerpt];
        }

        $allowed = self::allowedStatusCodes();
        if (!in_array($httpStatus, $allowed, true)) {
            return [
                'ok' => false,
                'error' => 'HTTP status ' . $httpStatus . ' not in allowed list.',
                'response_excerpt' => $excerpt,
            ];
        }

        $requireJson = (AppSettings::get('sms.http.require_json', '1') ?? '1') === '1';
        if ($requireJson && $json === null) {
            return ['ok' => false, 'error' => 'Provider response is not valid JSON.', 'response_excerpt' => $excerpt];
        }

        $successPath = trim(AppSettings::get('sms.http.success_json_path', '') ?? '');
        $successValue = AppSettings::get('sms.http.success_json_value', '') ?? '';
        if ($successPath !== '' && $json !== null) {
            $actual = self::pathGet($json, $successPath);
            if ($successValue !== '' && (string) $actual !== (string) $successValue) {
                return [
                    'ok' => false,
                    'error' => 'Provider rejected send (success field mismatch).',
                    'response_excerpt' => $excerpt,
                ];
            }
        }

        $idPath = trim(AppSettings::get('sms.http.message_id_json_path', '') ?? '');
        $messageId = '';
        if ($idPath !== '' && $json !== null) {
            $messageId = trim((string) self::pathGet($json, $idPath));
        }
        if ($messageId === '') {
            return [
                'ok' => false,
                'error' => 'Provider response missing required message id field.',
                'response_excerpt' => $excerpt,
            ];
        }

        return ['ok' => true, 'message_id' => $messageId, 'response_excerpt' => $excerpt];
    }

    public static function timeoutSeconds(): int
    {
        $n = (int) (AppSettings::get('sms.http.timeout_seconds', '15') ?? 15);

        return max(3, min(60, $n));
    }

    /** @return list<int> */
    private static function allowedStatusCodes(): array
    {
        $raw = AppSettings::get('sms.http.allowed_status_codes', '200') ?? '200';
        $parts = preg_split('/[\s,]+/', (string) $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            if ($p !== '' && ctype_digit($p)) {
                $out[] = (int) $p;
            }
        }

        return $out !== [] ? $out : [200];
    }

    /**
     * @param array<string, mixed> $data
     * @return mixed
     */
    private static function pathGet(array $data, string $path)
    {
        $cur = $data;
        foreach (explode('.', $path) as $seg) {
            if (!is_array($cur) || !array_key_exists($seg, $cur)) {
                return null;
            }
            $cur = $cur[$seg];
        }

        return $cur;
    }
}
