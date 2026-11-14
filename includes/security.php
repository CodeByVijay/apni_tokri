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
    public static function sendSecurityHeaders(bool $isHttps = false, bool $noStore = false): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        if ($noStore) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        $csp = self::contentSecurityPolicy($isHttps);
        if ($csp !== null) {
            header('Content-Security-Policy-Report-Only: ' . $csp);
        }
    }

    /**
     * Per-request CSP nonce (base64). Generated lazily and stable for the request
     * so sendSecurityHeaders() and later layout rendering agree on one value.
     */
    public static function cspNonce(): string
    {
        static $nonce = null;
        if ($nonce === null) {
            $nonce = base64_encode(random_bytes(16));
        }

        return $nonce;
    }

    /** Rendered nonce attribute for inline <script> tags, e.g. nonce="abc…". */
    public static function cspNonceAttr(): string
    {
        return 'nonce="' . self::cspNonce() . '"';
    }

    /**
     * Report-Only policy. Deliberately omits 'unsafe-inline' from script-src so
     * violation reports surface the remaining inline event handlers before the
     * policy is enforced. style-src keeps 'unsafe-inline' (inline style=
     * attributes and Tailwind CDN runtime styles cannot be nonced).
     */
    private static function contentSecurityPolicy(bool $isHttps): ?string
    {
        try {
            $cfg = (array) (App::config()['security']['csp'] ?? []);
        } catch (Throwable $e) {
            $cfg = [];
        }
        if (($cfg['enabled'] ?? true) === false) {
            return null;
        }

        $nonce = self::cspNonce();
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "script-src 'self' 'nonce-{$nonce}' https://cdn.tailwindcss.com https://code.jquery.com https://sdk.cashfree.com https://checkout.razorpay.com https://www.googletagmanager.com https://www.gstatic.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com data:",
            "img-src 'self' data: https:",
            "connect-src 'self' https://*.googleapis.com https://*.google-analytics.com https://*.googletagmanager.com https://*.gstatic.com https://*.cashfree.com https://*.razorpay.com",
            "frame-src https://api.razorpay.com https://*.razorpay.com https://*.cashfree.com",
            "worker-src 'self'",
            "manifest-src 'self'",
            "media-src 'self'",
        ];
        if ($isHttps) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
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
