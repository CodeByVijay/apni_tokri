<?php

declare(strict_types=1);

/** Normalized internal redirect paths (path + query, app-relative). */
final class SeoRedirectPath
{
    public const MAX_RESOLVE_DEPTH = 10;

    /** @var list<string> */
    private const SLUG_ROUTE_SCRIPTS = ['page.php', 'product.php', 'category.php'];

    public static function cmsPage(string $slug): string
    {
        return self::redirectIdentity('/page.php?slug=' . rawurlencode($slug));
    }

    public static function productPage(string $slug): string
    {
        return self::redirectIdentity('/product.php?slug=' . rawurlencode($slug));
    }

    public static function categoryPage(string $slug): string
    {
        return self::redirectIdentity('/category.php?slug=' . rawurlencode($slug));
    }

    /** Normalize stored or incoming path for lookup (includes url_path prefix). */
    public static function normalize(string $pathAndQuery): string
    {
        $pathAndQuery = trim($pathAndQuery);
        if ($pathAndQuery === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $pathAndQuery)) {
            $parsed = parse_url($pathAndQuery);
            $path = is_string($parsed['path'] ?? null) ? $parsed['path'] : '/';
            $query = is_string($parsed['query'] ?? null) && $parsed['query'] !== '' ? '?' . $parsed['query'] : '';

            return self::normalize($path . $query);
        }
        if (str_starts_with($pathAndQuery, '//')) {
            return '';
        }
        $normalized = url_to($pathAndQuery);
        if (!str_starts_with($normalized, '/')) {
            $normalized = '/' . ltrim($normalized, '/');
        }
        if (str_contains($normalized, "\0") || str_contains($normalized, "\r") || str_contains($normalized, "\n")) {
            return '';
        }

        return $normalized;
    }

    /**
     * Canonical redirect identity (path + slug query only on slug routes).
     * Tracking and other query params are excluded from identity; duplicate/conflicting slug params yield ''.
     */
    public static function redirectIdentity(string $pathAndQuery): string
    {
        $normalized = self::normalize($pathAndQuery);
        if ($normalized === '') {
            return '';
        }
        $pathOnly = parse_url($normalized, PHP_URL_PATH);
        if (!is_string($pathOnly) || $pathOnly === '') {
            return $normalized;
        }
        $script = strtolower(basename($pathOnly));
        if (!in_array($script, self::SLUG_ROUTE_SCRIPTS, true)) {
            return $normalized;
        }
        $query = parse_url($normalized, PHP_URL_QUERY);
        $query = is_string($query) ? $query : '';
        $slug = self::canonicalSlugFromQuery($query);
        if ($slug === null) {
            return '';
        }
        if ($slug === '') {
            return $pathOnly;
        }

        return $pathOnly . '?slug=' . rawurlencode($slug);
    }

    /** App-relative paths that should 301 to the storefront homepage canonical URL. */
    public static function isHomepageAlias(string $identity): bool
    {
        if ($identity === '') {
            return false;
        }
        $relative = self::normalize($identity);
        $pathOnly = parse_url($relative, PHP_URL_PATH);
        if (!is_string($pathOnly) || $pathOnly === '') {
            $pathOnly = $relative;
        }
        $base = url_base();
        if ($base !== '' && (str_starts_with($pathOnly, $base . '/') || $pathOnly === $base)) {
            $pathOnly = substr($pathOnly, strlen($base)) ?: '/';
        }

        return in_array($pathOnly, ['/index.php', '/index'], true);
    }

    /** Destination path for homepage alias redirects (respects url_path prefix). */
    public static function homepageRedirectDestination(): string
    {
        return self::normalize('/');
    }

    /** Identity key for the current HTTP request (redirect lookup). */
    public static function currentRedirectIdentity(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
            ? $_SERVER['REQUEST_URI'] : '/';
        $relative = url_app_relative($uri);

        return self::redirectIdentity($relative !== '' ? $relative : $uri);
    }

    /** Current request path for redirect lookup (GET/HEAD only). */
    public static function currentRequestPath(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
            ? $_SERVER['REQUEST_URI'] : '/';

        return self::normalize(url_app_relative($uri) !== '' ? url_app_relative($uri) : $uri);
    }

    /**
     * Allowlisted utm_* params from a request URI for appending to a 301 Location (destination identity unchanged).
     */
    public static function safeTrackingQueryForRedirect(string $requestUri): string
    {
        if ($requestUri === '') {
            return '';
        }
        $relative = url_app_relative($requestUri);
        $query = parse_url($relative !== '' ? $relative : $requestUri, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return '';
        }
        $params = [];
        parse_str($query, $params);
        if (!is_array($params)) {
            return '';
        }
        $parts = [];
        foreach ($params as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (!preg_match('/^utm_[a-z_]+$/i', $key)) {
                continue;
            }
            if ($value === '' || strlen($value) > 200 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                continue;
            }
            $parts[] = rawurlencode($key) . '=' . rawurlencode($value);
        }
        sort($parts);

        return implode('&', $parts);
    }

    /**
     * @return string|null Canonical slug, '' when slug param absent, null when ambiguous/invalid.
     */
    private static function canonicalSlugFromQuery(string $query): ?string
    {
        if ($query === '') {
            return '';
        }
        if (!preg_match_all('/(?:^|&)slug=([^&]*)/', $query, $matches)) {
            return '';
        }
        $distinct = [];
        foreach ($matches[1] as $raw) {
            $decoded = rawurldecode(str_replace('+', ' ', (string) $raw));
            $distinct[$decoded] = true;
        }
        if (count($distinct) !== 1) {
            return null;
        }
        $slug = (string) array_key_first($distinct);
        if ($slug === '' || !Validation::slug($slug)) {
            return null;
        }

        return $slug;
    }

    /**
     * Validate internal destination (no external open redirect).
     *
     * @return array{ok: true, path: string}|array{ok: false, message: string}
     */
    public static function validateInternalPath(string $path): array
    {
        $path = trim($path);
        if ($path === '') {
            return ['ok' => false, 'message' => 'Empty path.'];
        }
        $lower = strtolower($path);
        if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:') || str_starts_with($lower, 'vbscript:')) {
            return ['ok' => false, 'message' => 'Unsafe scheme.'];
        }
        if (preg_match('#^https?://#i', $path) || str_starts_with($path, '//')) {
            return ['ok' => false, 'message' => 'External destinations are not allowed.'];
        }
        $identity = self::redirectIdentity($path);
        if ($identity === '' || !str_starts_with($identity, '/')) {
            return ['ok' => false, 'message' => 'Invalid path.'];
        }

        return ['ok' => true, 'path' => $identity];
    }
}
