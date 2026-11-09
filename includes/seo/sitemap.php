<?php

declare(strict_types=1);

final class SeoSitemap
{
    public const MAX_URLS_PER_FILE = 50000;

    public const CACHE_TTL_SECONDS = 3600;

    public const CONTENT_TYPE = 'application/xml; charset=UTF-8';

    private const CACHE_DIR_RELATIVE = '/storage/cache/seo-sitemap';

    public const STATIC_FILENAME = 'sitemap.xml';

    /** @var list<array{loc: string, lastmod?: string, images?: list<string>}>|null */
    private static ?array $collectedEntriesMemo = null;

    /** @return list<array{loc: string, lastmod?: string, images?: list<string>}> */
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
            $publishedProductRows = [];
            if ($prodStmt !== false) {
                foreach ($prodStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (!ProductRepository::isPubliclyVisibleRow($row)) {
                        continue;
                    }
                    $meta = SeoResolver::forProductRow($row);
                    if (!$meta->isIndexableForSitemap() || $meta->canonicalUrl === null || $meta->canonicalUrl === '') {
                        continue;
                    }
                    $productId = (int) ($row['id'] ?? 0);
                    if ($productId > 0) {
                        $publishedProductRows[$productId] = $row;
                    }
                    self::addEntry($byLoc, [
                        'loc' => $meta->canonicalUrl,
                        'lastmod' => self::formatLastmod($row['updated_at'] ?? null),
                    ], $redirectSources);
                }
            }

            if ($publishedProductRows !== []) {
                self::attachProductImagesToEntries($byLoc, $publishedProductRows, $pdo);
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

            $faqMeta = SeoResolver::forFaqPage(false);
            if ($faqMeta->isIndexableForSitemap() && $faqMeta->canonicalUrl !== null && $faqMeta->canonicalUrl !== '') {
                self::addEntry($byLoc, [
                    'loc' => $faqMeta->canonicalUrl,
                ], $redirectSources);
            }
        } catch (Throwable $e) {
            error_log('SeoSitemap::collectIndexableUrls: ' . $e->getMessage());
        }

        $entries = array_values($byLoc);
        self::$collectedEntriesMemo = $entries;

        return $entries;
    }

    public static function renderForRequest(?int $part = null, bool $bypassCache = false): string
    {
        if (!$bypassCache) {
            $cached = self::readResponseCache($part);
            if ($cached !== null) {
                return $cached;
            }
        }

        $xml = self::renderForEntries(self::collectIndexableUrls(), $part);
        if (!$bypassCache && self::isCacheableXml($xml)) {
            self::writeResponseCache($part, $xml);
        }

        return $xml;
    }

    /**
     * @param list<array{loc: string, lastmod?: string, images?: list<string>}> $entries
     */
    public static function renderForEntries(array $entries, ?int $part = null): string
    {
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

    public static function clearResponseCache(): void
    {
        $dir = self::cacheDirectory();
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*.xml') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /** Clear rendered XML cache after catalogue or CMS mutations (TTL remains fallback). */
    public static function invalidateResponseCache(): void
    {
        self::clearResponseCache();
        self::resetCollectMemo();
        self::writeStaticXmlFiles();
    }

    public static function staticRootPath(): string
    {
        return APNI_TOKRI_ROOT . '/' . self::STATIC_FILENAME;
    }

    public static function staticPartPath(int $part): string
    {
        return APNI_TOKRI_ROOT . '/sitemap-' . $part . '.xml';
    }

    /**
     * Write sitemap.xml (and sitemap-N.xml parts when required) to the web document root.
     *
     * @return array{ok: bool, message?: string, path?: string, url_count?: int, files?: list<string>, parts?: int}
     */
    public static function writeStaticXmlFiles(): array
    {
        try {
            $entries = self::collectIndexableUrls();
            $total = count($entries);
            $written = [];
            $mainPath = self::staticRootPath();

            if ($total <= self::MAX_URLS_PER_FILE) {
                $xml = self::renderUrlset($entries);
                if (!self::isCacheableXml($xml) || !self::writeStaticFileAtomic($mainPath, $xml)) {
                    return ['ok' => false, 'message' => 'Could not write sitemap.xml to the document root.'];
                }
                $written[] = $mainPath;
                self::removeAllStaticPartFiles();

                return [
                    'ok' => true,
                    'path' => $mainPath,
                    'url_count' => $total,
                    'files' => $written,
                    'parts' => 1,
                ];
            }

            $chunks = (int) ceil($total / self::MAX_URLS_PER_FILE);
            for ($i = 1; $i <= $chunks; $i++) {
                $offset = ($i - 1) * self::MAX_URLS_PER_FILE;
                $slice = array_slice($entries, $offset, self::MAX_URLS_PER_FILE);
                $partXml = self::renderUrlset($slice);
                $partPath = self::staticPartPath($i);
                if (!self::isCacheableXml($partXml) || !self::writeStaticFileAtomic($partPath, $partXml)) {
                    return ['ok' => false, 'message' => 'Could not write sitemap part ' . $i . '.'];
                }
                $written[] = $partPath;
            }
            self::removeStaticPartFilesAbove($chunks);

            $locs = [];
            for ($i = 1; $i <= $chunks; $i++) {
                $locs[] = SeoUrl::absolute('/sitemap-' . $i . '.xml');
            }
            $indexXml = self::renderSitemapIndex($locs);
            if (!self::isCacheableXml($indexXml) || !self::writeStaticFileAtomic($mainPath, $indexXml)) {
                return ['ok' => false, 'message' => 'Could not write sitemap index to sitemap.xml.'];
            }
            $written[] = $mainPath;

            return [
                'ok' => true,
                'path' => $mainPath,
                'url_count' => $total,
                'files' => $written,
                'parts' => $chunks,
            ];
        } catch (Throwable $e) {
            error_log('SeoSitemap::writeStaticXmlFiles: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Sitemap file generation failed.'];
        }
    }

    private static function writeStaticFileAtomic(string $path, string $xml): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            return false;
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $xml, LOCK_EX) === false) {
            @unlink($tmp);

            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    private static function removeAllStaticPartFiles(): void
    {
        foreach (glob(APNI_TOKRI_ROOT . '/sitemap-[0-9]*.xml') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private static function removeStaticPartFilesAbove(int $maxPart): void
    {
        foreach (glob(APNI_TOKRI_ROOT . '/sitemap-[0-9]*.xml') ?: [] as $file) {
            if (!preg_match('#/sitemap-([0-9]+)\.xml$#', str_replace('\\', '/', $file), $m)) {
                continue;
            }
            if ((int) $m[1] > $maxPart && is_file($file)) {
                @unlink($file);
            }
        }
    }

    public static function resetCollectMemo(): void
    {
        self::$collectedEntriesMemo = null;
    }

    /**
     * @param list<array{loc: string, lastmod?: string, images?: list<string>}> $entries
     */
    public static function renderUrlset(array $entries): string
    {
        $hasImages = false;
        foreach ($entries as $entry) {
            if (!empty($entry['images'])) {
                $hasImages = true;
                break;
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        if ($hasImages) {
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
                . ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        } else {
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        }
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
            foreach ($entry['images'] ?? [] as $imageLoc) {
                if (!is_string($imageLoc) || $imageLoc === '') {
                    continue;
                }
                $xml .= "    <image:image>\n";
                $xml .= '      <image:loc>' . self::xmlEscape($imageLoc) . "</image:loc>\n";
                $xml .= "    </image:image>\n";
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
            '/payment.php',
            '/subscription-payment.php',
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
        $base = SeoUrl::publicSiteBaseUrl();
        if ($base !== '' && !str_starts_with($absoluteUrl, $base)) {
            $legacyBase = rtrim(App::baseUrl(), '/');
            if ($legacyBase === '' || $legacyBase === $base || !str_starts_with($absoluteUrl, $legacyBase)) {
                return false;
            }
            $relative = substr($absoluteUrl, strlen($legacyBase)) ?: '/';
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
     * @param array<string, array{loc: string, lastmod?: string, images?: list<string>}> $byLoc
     * @param array{loc: string, lastmod?: string, images?: list<string>} $entry
     * @param array<string, true> $redirectSources
     */
    private static function addEntry(array &$byLoc, array $entry, array $redirectSources): void
    {
        $loc = SeoUrl::normalizeSitemapLoc((string) ($entry['loc'] ?? ''));
        if ($loc === '' || !self::isEligibleAbsoluteUrl($loc)) {
            return;
        }
        if (isset($redirectSources[$loc])) {
            return;
        }
        if (!isset($byLoc[$loc])) {
            $byLoc[$loc] = [
                'loc' => $loc,
                'lastmod' => $entry['lastmod'] ?? null,
                'images' => $entry['images'] ?? [],
            ];

            return;
        }
        if (isset($entry['lastmod']) && is_string($entry['lastmod']) && $entry['lastmod'] !== '') {
            $byLoc[$loc]['lastmod'] = $entry['lastmod'];
        }
        if (!empty($entry['images'])) {
            $merged = array_values(array_unique(array_merge($byLoc[$loc]['images'] ?? [], $entry['images'])));
            $byLoc[$loc]['images'] = $merged;
        }
    }

    /**
     * @param array<string, array{loc: string, lastmod?: string, images?: list<string>}> $byLoc
     * @param array<int, array<string, mixed>> $productRows
     */
    private static function attachProductImagesToEntries(array &$byLoc, array $productRows, PDO $pdo): void
    {
        $ids = array_keys($productRows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $useMedia = class_exists('MediaLibrary', false) && MediaLibrary::isEnabled();
        $sql = $useMedia
            ? "SELECT pi.product_id, pi.file_path, pi.media_id, pi.is_primary, pi.sort_order,
                      m.file_sha256 AS media_file_sha256
               FROM product_images pi
               LEFT JOIN media_assets m ON m.id = pi.media_id AND m.deleted_at IS NULL
               WHERE pi.product_id IN ({$placeholders})
               ORDER BY pi.product_id, pi.is_primary DESC, pi.sort_order ASC, pi.id ASC"
            : "SELECT pi.product_id, pi.file_path, pi.media_id, pi.is_primary, pi.sort_order, NULL AS media_file_sha256
               FROM product_images pi
               WHERE pi.product_id IN ({$placeholders})
               ORDER BY pi.product_id, pi.is_primary DESC, pi.sort_order ASC, pi.id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return;
        }

        $imagesByProduct = [];
        foreach ($rows as $imgRow) {
            $productId = (int) ($imgRow['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $mediaId = (int) ($imgRow['media_id'] ?? 0);
            if ($useMedia && $mediaId > 0 && empty($imgRow['media_file_sha256'])) {
                continue;
            }
            $absolute = self::productImageAbsoluteUrl($imgRow);
            if ($absolute === null) {
                continue;
            }
            $imagesByProduct[$productId][] = $absolute;
        }

        foreach ($productRows as $productId => $row) {
            $imageLocs = $imagesByProduct[$productId] ?? [];
            if ($imageLocs === []) {
                continue;
            }
            $meta = SeoResolver::forProductRow($row);
            $loc = (string) ($meta->canonicalUrl ?? '');
            if ($loc === '' || !isset($byLoc[$loc])) {
                continue;
            }
            $byLoc[$loc]['images'] = array_values(array_unique(array_merge($byLoc[$loc]['images'] ?? [], $imageLocs)));
        }
    }

    /** @param array<string, mixed> $imageRow */
    private static function productImageAbsoluteUrl(array $imageRow): ?string
    {
        $path = (string) ($imageRow['file_path'] ?? '');
        if ($path === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $path)) {
            return SeoUrl::validateOgImage($path);
        }

        return SeoUrl::validateOgImage(SeoUrl::absolute('/' . ltrim($path, '/')));
    }

    private static function cacheDirectory(): string
    {
        return APNI_TOKRI_ROOT . self::CACHE_DIR_RELATIVE;
    }

    private static function cacheFilePath(?int $part): string
    {
        $key = hash('sha256', SeoUrl::publicSiteBaseUrl() . '|' . url_base() . '|' . ($part ?? 0));

        return self::cacheDirectory() . '/' . $key . '.xml';
    }

    private static function readResponseCache(?int $part): ?string
    {
        $path = self::cacheFilePath($part);
        if (!is_file($path)) {
            return null;
        }
        $mtime = filemtime($path);
        if ($mtime === false || (time() - $mtime) > self::CACHE_TTL_SECONDS) {
            @unlink($path);

            return null;
        }
        $body = file_get_contents($path);

        return is_string($body) && $body !== '' && self::isCacheableXml($body) ? $body : null;
    }

    private static function writeResponseCache(?int $part, string $xml): void
    {
        $dir = self::cacheDirectory();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n");
        }
        @file_put_contents(self::cacheFilePath($part), $xml, LOCK_EX);
    }

    private static function isCacheableXml(string $xml): bool
    {
        return str_contains($xml, '<urlset')
            || str_contains($xml, '<sitemapindex');
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

        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    private static function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
