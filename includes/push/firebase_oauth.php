<?php

declare(strict_types=1);

/**
 * Service-account OAuth2 for FCM HTTP v1 (no Composer).
 * Scope: https://www.googleapis.com/auth/firebase.messaging
 */
final class FirebaseOAuth
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const CACHE_SETTING_PREFIX = 'firebase.oauth.cache.';

    /** @var callable|null */
    public static $testHttpHandler = null;

    /**
     * @param array<string, mixed> $serviceAccount decoded JSON
     * @return array{ok: bool, access_token?: string, expires_at?: int, error?: string, error_code?: string}
     */
    public static function obtainAccessToken(array $serviceAccount, int $firebaseProjectRowId): array
    {
        $cached = self::readCache($firebaseProjectRowId);
        if ($cached !== null) {
            return ['ok' => true, 'access_token' => $cached['token'], 'expires_at' => $cached['exp']];
        }

        $clientEmail = (string) ($serviceAccount['client_email'] ?? '');
        $privateKey = (string) ($serviceAccount['private_key'] ?? '');
        if ($clientEmail === '' || $privateKey === '') {
            return ['ok' => false, 'error' => 'Service account missing client_email or private_key.', 'error_code' => 'invalid_service_account'];
        }

        $now = time();
        $jwt = self::buildJwt($clientEmail, $privateKey, $now);
        if ($jwt === null) {
            return ['ok' => false, 'error' => 'Could not sign JWT.', 'error_code' => 'jwt_sign_failed'];
        }

        $body = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        $resp = self::httpPost(self::TOKEN_URL, $body, ['Content-Type: application/x-www-form-urlencoded']);
        if (!$resp['ok']) {
            return [
                'ok' => false,
                'error' => 'OAuth token request failed.',
                'error_code' => 'oauth_http_' . (string) $resp['status'],
            ];
        }
        $json = $resp['json'];
        if (!is_array($json) || empty($json['access_token'])) {
            return ['ok' => false, 'error' => 'OAuth response missing access_token.', 'error_code' => 'oauth_invalid_response'];
        }
        $expiresIn = (int) ($json['expires_in'] ?? 3600);
        $exp = $now + max(60, $expiresIn - 120);
        self::writeCache($firebaseProjectRowId, (string) $json['access_token'], $exp);

        return ['ok' => true, 'access_token' => (string) $json['access_token'], 'expires_at' => $exp];
    }

  /**
     * @return array{ok: bool, status: int, body: string, json: array<string, mixed>|null}
     */
    private static function httpPost(string $url, string $body, array $headers): array
    {
        if (self::$testHttpHandler !== null) {
            return (self::$testHttpHandler)('POST', $url, $body, $headers);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
        ]);
        $respBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($respBody)) {
            $respBody = '';
        }
        $json = null;
        try {
            $json = json_decode($respBody, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $json = null;
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $respBody,
            'json' => is_array($json) ? $json : null,
        ];
    }

    private static function buildJwt(string $clientEmail, string $privateKey, int $now): ?string
    {
        $header = self::base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = [
            'iss' => $clientEmail,
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        $payload = self::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        $input = $header . '.' . $payload;
        $sig = '';
        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            return null;
        }
        $ok = openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            return null;
        }

        return $input . '.' . self::base64UrlEncode($sig);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** @return array{token: string, exp: int}|null */
    private static function readCache(int $projectRowId): ?array
    {
        if (!Install::isSchemaReady()) {
            return null;
        }
        $raw = AppSettings::getSecret(self::CACHE_SETTING_PREFIX . $projectRowId, '');
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            $data = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($data) || empty($data['token']) || empty($data['exp'])) {
            return null;
        }
        if ((int) $data['exp'] <= time() + 30) {
            return null;
        }

        return ['token' => (string) $data['token'], 'exp' => (int) $data['exp']];
    }

    private static function writeCache(int $projectRowId, string $token, int $exp): void
    {
        if (!Install::isSchemaReady() || !Install::hasLocalConfig()) {
            return;
        }
        try {
            AppSettings::set(
                self::CACHE_SETTING_PREFIX . $projectRowId,
                json_encode(['token' => $token, 'exp' => $exp], JSON_THROW_ON_ERROR),
                true
            );
        } catch (Throwable $e) {
            error_log('FirebaseOAuth::writeCache');
        }
    }

    public static function invalidateCache(int $projectRowId): void
    {
        if (!Install::isSchemaReady()) {
            return;
        }
        try {
            Install::ensureConnection();
            Database::pdo()->prepare('DELETE FROM settings WHERE setting_key = ?')
                ->execute([self::CACHE_SETTING_PREFIX . $projectRowId]);
            AppSettings::resetCache();
        } catch (Throwable $e) {
            error_log('FirebaseOAuth::invalidateCache');
        }
    }
}
