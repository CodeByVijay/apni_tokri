<?php

declare(strict_types=1);

final class Security
{
    /** Escape output for HTML context (XSS prevention). */
    public static function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, scalar|null> $headers */
    public static function sendSecurityHeaders(bool $isHttps = false): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        return isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443;
    }

    /**
     * Generate cryptographically secure random token (hex).
     */
    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** Constant-time string comparison. */
    public static function hashEquals(string $known, string $user): bool
    {
        return hash_equals($known, $user);
    }
}

final class AppSession
{
    /** @var bool */
    private static $started = false;

    /** @param array<string, mixed> $sessionConfig */
    public static function start(array $sessionConfig): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;

            return;
        }

        $name = (string) ($sessionConfig['name'] ?? 'APNI_TOKRI_SESSID');
        $lifetime = (int) ($sessionConfig['lifetime'] ?? 7200);
        $secure = (bool) ($sessionConfig['cookie_secure'] ?? Security::isHttps());
        $httponly = (bool) ($sessionConfig['cookie_httponly'] ?? true);
        $samesite = (string) ($sessionConfig['cookie_samesite'] ?? 'Lax');

        session_name($name);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => $samesite,
        ]);

        session_start();
        self::$started = true;
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
