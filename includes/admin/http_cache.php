<?php

declare(strict_types=1);

/** Admin HTML cache policy and safe soft-refresh URLs (GET only). */
final class AdminHttpCache
{
    public static function sendNoStoreHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    /**
     * Path + query for reload (preserves filters; strips prior cache-bust params).
     * Relative to application base (includes url_path when present).
     */
    public static function refreshTargetFromRequest(): string
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return self::fallbackGetUrlAfterNonGet();
        }

        return self::buildGetUrlFromQuery($_GET);
    }

    /** @param array<string, mixed> $query */
    public static function buildGetUrlFromQuery(array $query): string
    {
        $path = self::requestPath();
        unset($query['_at_r'], $query['_at_cb']);
        $qs = http_build_query($query);

        return $path . ($qs !== '' ? '?' . $qs : '');
    }

    private static function requestPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = (string) ($_SERVER['SCRIPT_NAME'] ?? '/');
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return $path;
    }

    private static function fallbackGetUrlAfterNonGet(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        if ($script !== '' && str_contains($script, '/admin/')) {
            return $script;
        }
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer !== '') {
            $refParts = parse_url($referer);
            $refHost = is_array($refParts) ? (string) ($refParts['host'] ?? '') : '';
            $reqHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
            $refPath = is_array($refParts) ? (string) ($refParts['path'] ?? '') : '';
            if ($refPath !== '' && str_contains($refPath, '/admin/')
                && ($refHost === '' || strcasecmp($refHost, $reqHost) === 0)) {
                $refQuery = is_array($refParts) ? (string) ($refParts['query'] ?? '') : '';
                $query = [];
                if ($refQuery !== '') {
                    parse_str($refQuery, $query);
                }

                return self::buildGetUrlFromQuery($query);
            }
        }

        $base = url_base();

        return ($base !== '' ? $base : '') . '/admin/dashboard.php';
    }
}
