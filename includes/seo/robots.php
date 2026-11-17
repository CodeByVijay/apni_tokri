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
        // Extensionless paths are the served/canonical URLs. robots Disallow is
        // a prefix match, so each entry also covers the legacy *.php form.
        $paths = [
            '/admin/',
            '/webhooks/',
            '/cron/',
            '/scripts/',
            '/includes/',
            '/database/',
            '/storage/',
            '/api/',
            '/cart',
            '/checkout',
            '/login',
            '/register',
            '/logout',
            '/verify-otp',
            '/my-',
            '/order-details',
            '/refund-details',
            '/order',
            '/subscribe',
            '/subscription',
            '/payment',
            '/invoice-',
            '/support',
            '/submit-review',
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
            && !str_contains($disallowed, '/index.php')
            && !str_contains($disallowed, '/product/')
            && !str_contains($disallowed, '/category/')
            && !str_contains($disallowed, '/page/')
            && !str_contains($disallowed, '/product?')
            && !str_contains($disallowed, '/category?')
            && !str_contains($disallowed, '/page?');
    }
}
