<?php

declare(strict_types=1);

final class PushUrlValidator
{
    /**
     * Allowed click targets: same canonical storefront or relative app paths.
     */
    public static function resolveClickUrl(?string $raw, array $placeholderVars = []): ?string
    {
        $url = trim((string) $raw);
        if ($url === '') {
            return null;
        }
        foreach ($placeholderVars as $key => $val) {
            $url = str_replace('{' . $key . '}', (string) $val, $url);
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '/')) {
            $path = $url;
            if (!self::isSafeRelativePath($path)) {
                return null;
            }

            return SeoUrl::absolute($path);
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['https', 'http'], true)) {
            return null;
        }
        $allowedHost = parse_url(SeoUrl::publicSiteBaseUrl(), PHP_URL_HOST);
        $host = strtolower((string) $parts['host']);
        if ($allowedHost === null || $host !== strtolower((string) $allowedHost)) {
            return null;
        }

        return $url;
    }

    private static function isSafeRelativePath(string $path): bool
    {
        if (!str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, '//')) {
            return false;
        }
        if (preg_match('/[\s\0]/', $path) === 1) {
            return false;
        }

        return true;
    }
}
