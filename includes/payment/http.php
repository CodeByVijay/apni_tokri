<?php

declare(strict_types=1);

final class PaymentHttp
{
    /**
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int, body: string, json: array<string, mixed>|null, error?: string}
     */
    public static function request(
        string $method,
        string $url,
        ?array $jsonBody = null,
        array $headers = [],
        ?string $basicUser = null,
        ?string $basicPass = null,
        ?int $timeoutSeconds = null
    ): array {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error' => 'curl init failed'];
        }
        $hdrs = $headers;
        if ($jsonBody !== null) {
            $hdrs[] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_TIMEOUT => $timeoutSeconds ?? 30,
        ]);
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody, JSON_UNESCAPED_UNICODE));
        }
        if ($basicUser !== null && $basicPass !== null) {
            curl_setopt($ch, CURLOPT_USERPWD, $basicUser . ':' . $basicPass);
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (!is_string($body)) {
            $body = '';
        }
        $decoded = null;
        if ($body !== '') {
            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                $decoded = null;
            }
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $body,
            'json' => is_array($decoded) ? $decoded : null,
            'error' => $err !== '' ? $err : null,
        ];
    }
}
