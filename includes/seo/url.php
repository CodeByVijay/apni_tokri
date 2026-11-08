<?php

declare(strict_types=1);

final class SeoUrl
{
    public static function siteName(): string
    {
        return BrandingSettings::siteName();
    }

    /** Absolute site URL for a public app path (deployment {@see App::baseUrl()}, not the request Host header). */
    public static function absolute(string $path): string
    {
        return self::absoluteWithBase(rtrim(App::baseUrl(), '/'), $path);
    }

    /**
     * Customer-facing absolute URL (admin Website URL + url_path on production).
     * Use for payment gateway return/callback URLs and customer notification links.
     */
    public static function storefrontAbsolute(string $path): string
    {
        return self::absoluteWithBase(BusinessSettings::storefrontBaseUrl(), $path);
    }

    private static function absoluteWithBase(string $base, string $path): string
    {
        if ($path === '') {
            return $base !== '' ? $base . '/' : '/';
        }
        if (preg_match('#^https?://#i', $path)) {
            return self::normalizeAbsolute($path) ?? $base;
        }
        $relative = url_to($path);
        if ($base === '') {
            return $relative;
        }
        $basePath = parse_url($base, PHP_URL_PATH);
        $basePath = is_string($basePath) ? rtrim($basePath, '/') : '';
        if ($basePath !== '' && str_starts_with($relative, $basePath)) {
            $relative = substr($relative, strlen($basePath)) ?: '/';
        }

        return $base . (str_starts_with($relative, '/') ? $relative : '/' . $relative);
    }

    /**
     * Validate user-supplied canonical / OG URLs. Returns normalized absolute URL or null.
     */
    public static function validatePublicUrl(?string $url): ?string
    {
        $url = trim((string) ($url ?? ''));
        if ($url === '') {
            return null;
        }
        $lower = strtolower($url);
        if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:') || str_starts_with($lower, 'vbscript:')) {
            return null;
        }
        if (preg_match('#^https?://#i', $url)) {
            return self::normalizeAbsolute($url);
        }
        if (str_starts_with($url, '//')) {
            return null;
        }
        if (str_starts_with($url, '/')) {
            return self::absolute($url);
        }
        if (preg_match('#^uploads/[a-zA-Z0-9/_.\-]+$#', $url) === 1) {
            return self::absolute('/' . $url);
        }

        return null;
    }

    public static function validateOgImage(?string $url): ?string
    {
        $validated = self::validatePublicUrl($url);
        if ($validated === null) {
            return null;
        }
        $path = parse_url($validated, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }
        if (preg_match('#\.(jpg|jpeg|png|webp|gif)$#i', $path) !== 1) {
            return null;
        }

        return $validated;
    }

    private static function normalizeAbsolute(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $url;
    }
}
