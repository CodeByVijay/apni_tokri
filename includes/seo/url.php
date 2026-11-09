<?php

declare(strict_types=1);

final class SeoUrl
{
    public static function siteName(): string
    {
        return BrandingSettings::siteName();
    }

    /**
     * Canonical public site origin (scheme + host + optional url_path), not the HTTP request host.
     * Admin → Settings → Website URL on production; local dev keeps {@see App::baseUrl()}.
     */
    public static function publicSiteBaseUrl(): string
    {
        return rtrim(BusinessSettings::storefrontBaseUrl(), '/');
    }

    /** Absolute storefront URL for a public app path (canonical domain + relative path). */
    public static function absolute(string $path): string
    {
        return self::absoluteWithBase(self::publicSiteBaseUrl(), $path);
    }

    /**
     * Homepage canonical: site root when installed at web root (production), else /index.php under a url_path prefix.
     */
    public static function homepageCanonicalUrl(): string
    {
        if (url_base() === '') {
            return self::absolute('/');
        }

        return self::absolute('/index.php');
    }

    /** Collapse /index.php homepage loc to root canonical when they are equivalent for sitemap deduplication. */
    public static function normalizeSitemapLoc(string $absoluteUrl): string
    {
        $canonical = self::homepageCanonicalUrl();
        $legacyIndex = self::absolute('/index.php');
        if ($absoluteUrl === $legacyIndex && $canonical !== $legacyIndex) {
            return $canonical;
        }

        return $absoluteUrl;
    }

    /**
     * Customer-facing absolute URL (admin Website URL + url_path on production).
     * Use for payment gateway return/callback URLs and customer notification links.
     */
    public static function storefrontAbsolute(string $path): string
    {
        return self::absolute($path);
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
            $normalized = self::normalizeAbsolute($url);

            return $normalized !== null ? self::rewriteLegacyStorefrontOrigin($normalized) : null;
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

    /**
     * Remap stored absolute URLs on a retired Veggie Tokri hostname to the configured Website URL.
     * Does not alter legitimate third-party URLs.
     */
    public static function rewriteLegacyStorefrontOrigin(string $absoluteUrl): string
    {
        $parts = parse_url($absoluteUrl);
        if ($parts === false || empty($parts['host'])) {
            return $absoluteUrl;
        }
        $host = strtolower((string) $parts['host']);
        $legacyHosts = ['veggietokri.in', 'www.veggietokri.in', 'apnitokri.in', 'www.apnitokri.in'];
        if (!in_array($host, $legacyHosts, true)) {
            return $absoluteUrl;
        }
        $targetBase = self::publicSiteBaseUrl();
        if ($targetBase === '') {
            return $absoluteUrl;
        }
        $targetHost = strtolower((string) parse_url($targetBase, PHP_URL_HOST));
        if ($targetHost === '' || $targetHost === $host) {
            return $absoluteUrl;
        }
        $scheme = strtolower((string) (parse_url($targetBase, PHP_URL_SCHEME) ?: 'https'));
        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';
        $port = parse_url($targetBase, PHP_URL_PORT);
        $authority = $targetHost . (is_int($port) ? ':' . $port : '');

        return $scheme . '://' . $authority . ($path !== '' ? $path : '/') . $query . $fragment;
    }
}
