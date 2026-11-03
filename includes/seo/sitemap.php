<?php

declare(strict_types=1);

final class SeoSitemap
{
    public const MAX_URLS_PER_FILE = 50000;

    public const CONTENT_TYPE = 'application/xml; charset=UTF-8';

    /** @return list<array{loc: string, lastmod?: string}> */
    public static function collectIndexableUrls(): array
    {
        $byLoc = [];
        $redirectSources = self::redirectSourceAbsoluteUrls();

        $homeMeta = SeoResolver::forHomepage();
        if ($homeMeta->isIndexableForSitemap() && $homeMeta->canonicalUrl !== null && $homeMeta->canonicalUrl !== '') {
            self::addEntry($byLoc, ['loc' => $homeMeta->canonicalUrl], $redirectSources);
        }

        if (!Install::isSchemaReady()) {
            return array_values($byLoc);
        }

        try {
            Install::ensureConnection();
            $pdo = Database::pdo();

            $cmsStmt = $pdo->prepare(
                "SELECT * FROM cms_pages
                 WHERE status = ?
                   AND robots_index = 1
                   AND (visibility = ? OR visibility = '' OR visibility IS NULL)"
            );
            $cmsStmt->execute([PublishStatus::PUBLISHED, CmsPageService::VISIBILITY_PUBLIC]);
            foreach ($cmsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $meta = SeoResolver::forCmsPage($row);
                    if (!$meta->isIndexableForSitemap() || $meta->canonicalUrl === null || $meta->canonicalUrl === '') {
                        continue;
                    }
                    self::addEntry($byLoc, [
                        'loc' => $meta->canonicalUrl,
                        'lastmod' => self::formatLastmod($row['updated_at'] ?? null),
                    ], $redirectSources);
            }

            $prodStmt = $pdo->query(
                "SELECT p.*, c.slug AS category_slug FROM products p
                 LEFT JOIN categories c ON c.id = p.category_id
                 WHERE p.status = 'published' AND p.robots_index = 1
                   AND EXISTS (
                     SELECT 1 FROM product_variants pv
                     WHERE pv.product_id = p.id AND pv.is_active = 1
                   )
                 ORDER BY p.id"
            );
            if ($prodStmt !== false) {
                foreach ($prodStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (!ProductRepository::isPubliclyVisibleRow($row)) {
                        continue;
                    }
                    $meta = SeoResolver::forProductRow($row);
                    if (!$meta->isIndexableForSitemap() || $meta->canonicalUrl === null || $meta->canonicalUrl === '') {
                        continue;
                    }
                    self::addEntry($byLoc, [
                        'loc' => $meta->canonicalUrl,
                        'lastmod' => self::formatLastmod($row['updated_at'] ?? null),
                    ], $redirectSources);
                }
            }

            $catRows = CategoryRepository::published();
            usort($catRows, static function (array $a, array $b): int {
                return (int) $a['id'] <=> (int) $b['id'];
            });
            {
                foreach ($catRows as $row) {
                    $meta = SeoResolver::forCategoryRow($row, false);
                    if (!$meta->isIndexableForSitemap() || $meta->canonicalUrl === null || $meta->canonicalUrl === '') {
                        continue;
                    }
                    self::addEntry($byLoc, [
                        'loc' => $meta->canonicalUrl,
                        'lastmod' => self::formatLastmod($row['updated_at'] ?? null),
                    ], $redirectSources);
                }
            }
        } catch (Throwable $e) {
            error_log('SeoSitemap::collectIndexableUrls: ' . $e->getMessage());
        }

        return array_values($byLoc);
    }

    public static function renderForRequest(?int $part = null): string
    {
        $entries = self::collectIndexableUrls();
        $total = count($entries);
        if ($total <= self::MAX_URLS_PER_FILE) {
            return self::renderUrlset($entries);
        }
        if ($part === null || $part < 1) {
            $chunks = (int) ceil($total / self::MAX_URLS_PER_FILE);
            $locs = [];
            for ($i = 1; $i <= $chunks; $i++) {
                $locs[] = SeoUrl::absolute('/sitemap-' . $i . '.xml');
            }

            return self::renderSitemapIndex($locs);
        }
        $offset = ($part - 1) * self::MAX_URLS_PER_FILE;
        $slice = array_slice($entries, $offset, self::MAX_URLS_PER_FILE);

        return self::renderUrlset($slice);
    }

    /**
     * @param list<array{loc: string, lastmod?: string}> $entries
     */
    public static function renderUrlset(array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($entries as $entry) {
            $loc = self::xmlEscape((string) ($entry['loc'] ?? ''));
            if ($loc === '') {
                continue;
            }
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . $loc . "</loc>\n";
            $lastmod = $entry['lastmod'] ?? null;
            if (is_string($lastmod) && $lastmod !== '') {
                $xml .= '    <lastmod>' . self::xmlEscape($lastmod) . "</lastmod>\n";
            }
            $xml .= "  </url>\n";
        }
        $xml .= "</urlset>\n";

        return $xml;
    }

    /** @param list<string> $absoluteLocs */
    public static function renderSitemapIndex(array $absoluteLocs): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($absoluteLocs as $loc) {
            $loc = self::xmlEscape($loc);
            if ($loc === '') {
                continue;
            }
            $xml .= "  <sitemap>\n";
            $xml .= '    <loc>' . $loc . "</loc>\n";
            $xml .= "  </sitemap>\n";
        }
        $xml .= "</sitemapindex>\n";

        return $xml;
    }

    public static function isBlockedAppPath(string $appRelativePath): bool
    {
        $path = parse_url($appRelativePath, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $appRelativePath;
        }
        $path = strtolower($path);
        $blocked = [
            '/search.php',
            '/cart.php',
            '/checkout.php',
            '/login.php',
            '/register.php',
            '/my-orders.php',
            '/my-refunds.php',
            '/order-details.php',
            '/refund-details.php',
            '/account.php',
        ];
        foreach ($blocked as $b) {
            if ($path === $b || str_starts_with($path, $b . '/')) {
                return true;
            }
        }
        $prefixes = ['/admin', '/webhooks', '/api', '/scripts', '/includes'];
        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        if (str_contains($appRelativePath, 'sort=') || str_contains($appRelativePath, 'filter=')
            || str_contains($appRelativePath, 'page=') || str_contains($appRelativePath, 'q=')) {
            return true;
        }

        return false;
    }

    public static function isEligibleAbsoluteUrl(string $absoluteUrl): bool
    {
        $base = rtrim(App::baseUrl(), '/');
        if ($base !== '' && !str_starts_with($absoluteUrl, $base)) {
            return false;
        }
        $relative = $absoluteUrl;
        if ($base !== '') {
            $relative = substr($absoluteUrl, strlen($base)) ?: '/';
        }
        $pathPart = parse_url($relative, PHP_URL_PATH);
        if (!is_string($pathPart) || $pathPart === '') {
            return false;
        }
        $appPath = $pathPart;
        if (url_base() !== '' && str_starts_with($pathPart, url_base())) {
            $appPath = substr($pathPart, strlen(url_base())) ?: '/';
        }
        $query = parse_url($relative, PHP_URL_QUERY);
        $check = $appPath . (is_string($query) && $query !== '' ? '?' . $query : '');

        return !self::isBlockedAppPath($check);
    }

    /**
     * @param array<string, array{loc: string, lastmod?: string}> $byLoc
     * @param array{loc: string, lastmod?: string} $entry
     * @param array<string, true> $redirectSources
     */
    private static function addEntry(array &$byLoc, array $entry, array $redirectSources): void
    {
        $loc = (string) ($entry['loc'] ?? '');
        if ($loc === '' || !self::isEligibleAbsoluteUrl($loc)) {
            return;
        }
        if (isset($redirectSources[$loc])) {
            return;
        }
        $byLoc[$loc] = [
            'loc' => $loc,
            'lastmod' => $entry['lastmod'] ?? ($byLoc[$loc]['lastmod'] ?? null),
        ];
    }

    /** @return array<string, true> */
    private static function redirectSourceAbsoluteUrls(): array
    {
        $out = [];
        foreach (SeoRedirectRepository::listAllFromPaths() as $fromPath) {
            $relative = $fromPath;
            $base = url_base();
            if ($base !== '' && str_starts_with($fromPath, $base)) {
                $relative = substr($fromPath, strlen($base)) ?: '/';
            }
            $pathOnly = parse_url($relative, PHP_URL_PATH);
            $query = parse_url($relative, PHP_URL_QUERY);
            if (!is_string($pathOnly)) {
                continue;
            }
            $appRelative = $pathOnly;
            if (is_string($query) && $query !== '') {
                $appRelative .= '?' . $query;
            }
            $absolute = SeoUrl::absolute($appRelative);
            if ($absolute !== '') {
                $out[$absolute] = true;
            }
        }

        return $out;
    }

    private static function formatLastmod($timestamp): ?string
    {
        if (!is_string($timestamp) || trim($timestamp) === '') {
            return null;
        }
        $ts = strtotime($timestamp);
        if ($ts === false) {
            return null;
        }

        return gmdate('Y-m-d', $ts);
    }

    private static function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
