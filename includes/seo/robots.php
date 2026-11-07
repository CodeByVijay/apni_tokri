<?php

declare(strict_types=1);

final class SeoRobots
{
    public const CONTENT_TYPE = 'text/plain; charset=UTF-8';

    public static function sitemapAbsoluteUrl(): string
    {
        return SeoUrl::absolute('/sitemap.xml');
    }

    public static function render(): string
    {
        $lines = [];
        $lines[] = 'User-agent: *';
        foreach (self::disallowPaths() as $path) {
            $lines[] = 'Disallow: ' . $path;
        }
        $extra = trim(AppSettings::get(SeoAdminCenter::KEY_ROBOTS_EXTRA, '') ?? '');
        if ($extra !== '') {
            $check = SeoAdminCenter::validateRobotsExtra($extra);
            if ($check['ok'] && ($check['lines'] ?? '') !== '') {
                foreach (explode("\n", (string) $check['lines']) as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $lines[] = $line;
                    }
                }
            }
        }
        $lines[] = 'Sitemap: ' . self::sitemapAbsoluteUrl();
        $lines[] = '';

        return implode("\n", $lines);
    }

    /** @return list<string> App-relative disallow paths (with url_path prefix when configured). */
    public static function disallowPaths(): array
    {
        $prefix = url_base();
        $paths = [
            '/admin/',
            '/webhooks/',
            '/cron/',
            '/scripts/',
            '/includes/',
            '/database/',
            '/storage/',
            '/api/',
            '/cart.php',
            '/checkout.php',
            '/login.php',
            '/register.php',
            '/my-orders.php',
            '/my-refunds.php',
            '/order-details.php',
            '/refund-details.php',
        ];
        $out = [];
        foreach ($paths as $p) {
            $out[] = ($prefix === '' ? '' : $prefix) . $p;
        }

        return $out;
    }

    public static function disallowsPublicCataloguePaths(): bool
    {
        $disallowed = implode("\n", self::disallowPaths());

        return !str_contains($disallowed, '/product.php')
            && !str_contains($disallowed, '/category.php')
            && !str_contains($disallowed, '/page.php')
            && !str_contains($disallowed, '/index.php');
    }
}
