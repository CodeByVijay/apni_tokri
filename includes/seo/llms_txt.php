<?php

declare(strict_types=1);

/**
 * Generates llms.txt at the web document root from the same indexability rules as {@see SeoSitemap}.
 */
final class LlmsTxt
{
    public const STATIC_FILENAME = 'llms.txt';

    /** Preferred policy / info CMS slugs (included only when indexable). */
    private const POLICY_SLUG_ORDER = [
        'about-us',
        'contact-us',
        'privacy-policy',
        'terms',
        'shipping-policy',
        'refund-policy',
    ];

    /** Public support routes (not checkout/login); omitted when URL is ineligible or a redirect source. */
    private const SUPPORT_APP_PATHS = [
        '/contact.php' => 'Contact',
        '/support.php' => 'Support tickets',
    ];

    public static function staticRootPath(): string
    {
        return APNI_TOKRI_ROOT . '/' . self::STATIC_FILENAME;
    }

    /**
     * @return array{ok: bool, message?: string, path?: string, bytes?: int}
     */
    public static function writeStaticFile(): array
    {
        try {
            $markdown = self::renderMarkdown();
            $path = self::staticRootPath();
            if (!self::writeStaticFileAtomic($path, $markdown)) {
                return ['ok' => false, 'message' => 'Could not write llms.txt to the document root.'];
            }

            return ['ok' => true, 'path' => $path, 'bytes' => strlen($markdown)];
        } catch (Throwable $e) {
            error_log('LlmsTxt::writeStaticFile: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'llms.txt generation failed.'];
        }
    }

    public static function renderMarkdown(): string
    {
        $siteName = BrandingSettings::siteName();
        $tagline = BrandingSettings::tagline();
        $base = SeoUrl::publicSiteBaseUrl();
        $baseSlash = $base !== '' ? $base . '/' : 'https://veggietokri.com/';

        $homeUrl = SeoUrl::homepageCanonicalUrl();
        $faqMeta = SeoResolver::forFaqPage(false);
        $faqUrl = ($faqMeta->isIndexableForSitemap() && $faqMeta->canonicalUrl !== null && $faqMeta->canonicalUrl !== '')
            ? SeoUrl::normalizeSitemapLoc($faqMeta->canonicalUrl)
            : null;
        $sitemapUrl = SeoRobots::sitemapAbsoluteUrl();
        $selfUrl = SeoUrl::absolute('/llms.txt');

        $categories = self::collectIndexableCategories();
        $products = self::collectIndexableProducts();
        $cmsPages = self::collectIndexableCmsPages();
        $supportLinks = self::collectSupportLinks();

        $lines = [];
        $lines[] = '# ' . $siteName;
        $lines[] = '';
        $lines[] = '> ' . $tagline;
        $lines[] = '';
        $lines[] = 'This file describes the public ' . $siteName . ' website at ' . $baseSlash
            . ' for people and tools that prefer a concise Markdown overview. '
            . 'Pack sizes, prices, inventory, delivery fees, and service areas are shown on the live site and checkout; '
            . 'only indexable public pages from the current catalogue are linked below. '
            . 'Publishing this file does not affect search or AI inclusion.';
        $lines[] = '';
        $lines[] = '## Main Website';
        $lines[] = '';
        $lines[] = '- [Homepage](' . $homeUrl . '): Storefront home with category navigation and published products.';
        if ($faqUrl !== null) {
            $lines[] = '- [FAQs](' . $faqUrl . '): Help articles about orders, delivery, and payments (content managed in admin).';
        }

        if ($categories !== []) {
            $lines[] = '';
            $lines[] = '## Product Categories';
            $lines[] = '';
            foreach ($categories as $row) {
                $lines[] = '- [' . self::markdownLinkText((string) $row['name']) . '](' . $row['url'] . '): Published category page.';
            }
        }

        if ($products !== []) {
            $lines[] = '';
            $lines[] = '## Products';
            $lines[] = '';
            $lines[] = 'Published products allowed for search indexing (active variant, visible category, indexable SEO).';
            $lines[] = '';
            $byCategory = [];
            foreach ($products as $p) {
                $cat = (string) ($p['category_name'] ?? 'Other');
                $byCategory[$cat][] = $p;
            }
            ksort($byCategory, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($byCategory as $catName => $items) {
                if (count($byCategory) > 1) {
                    $lines[] = '### ' . $catName;
                    $lines[] = '';
                }
                foreach ($items as $p) {
                    $lines[] = '- [' . self::markdownLinkText((string) $p['name']) . '](' . $p['url'] . ')';
                }
                if (count($byCategory) > 1) {
                    $lines[] = '';
                }
            }
        }

        $lines[] = '';
        $lines[] = '## Shopping and Ordering';
        $lines[] = '';
        $lines[] = '- Shoppers add items from product pages, review the cart, and complete checkout on the website when logged in and when delivery rules allow the saved address.';
        $lines[] = '- FAQs explain variants, payments, and retries when applicable.';
        $lines[] = '- Subscription purchase and management require a customer account; subscription checkout URLs are not listed here.';

        $lines[] = '';
        $lines[] = '## Customer Support';
        $lines[] = '';
        foreach ($supportLinks as $link) {
            $lines[] = '- [' . self::markdownLinkText((string) $link['label']) . '](' . $link['url'] . '): '
                . (string) ($link['description'] ?? 'Customer support.');
        }

        $policyPages = self::orderPolicyPages($cmsPages);
        $otherCms = self::cmsPagesExcludingSlugs($cmsPages, self::POLICY_SLUG_ORDER);

        if ($policyPages !== []) {
            $lines[] = '';
            $lines[] = '## Policies';
            $lines[] = '';
            foreach ($policyPages as $page) {
                $lines[] = '- [' . self::markdownLinkText((string) $page['title']) . '](' . $page['url'] . '): '
                    . 'Public CMS page.';
            }
        }

        if ($otherCms !== []) {
            $lines[] = '';
            $lines[] = '## Information Pages';
            $lines[] = '';
            foreach ($otherCms as $page) {
                $lines[] = '- [' . self::markdownLinkText((string) $page['title']) . '](' . $page['url'] . ')';
            }
        }

        $lines[] = '';
        $lines[] = '## Optional';
        $lines[] = '';
        $lines[] = '- [XML sitemap](' . $sitemapUrl . '): Indexable storefront URLs regenerated from the database.';
        $lines[] = '- [llms.txt](' . $selfUrl . '): This overview file.';

        return rtrim(implode("\n", $lines)) . "\n";
    }

    /**
     * @return list<array{name: string, slug: string, url: string}>
     */
    public static function collectIndexableCategories(): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        try {
            Install::ensureConnection();
            $redirectSources = self::redirectSourceAbsoluteUrls();
            $out = [];
            foreach (CategoryRepository::published() as $row) {
                $meta = SeoResolver::forCategoryRow($row, false);
                if (!$meta->isIndexableForSitemap() || $meta->canonicalUrl === null || $meta->canonicalUrl === '') {
                    continue;
                }
                $url = SeoUrl::normalizeSitemapLoc($meta->canonicalUrl);
                if (!SeoSitemap::isEligibleAbsoluteUrl($url) || isset($redirectSources[$url])) {
                    continue;
                }
                $out[] = [
                    'name' => (string) ($row['name'] ?? $row['slug'] ?? 'Category'),
                    'slug' => (string) ($row['slug'] ?? ''),
                    'url' => $url,
                ];
            }
            usort($out, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

            return $out;
        } catch (Throwable $e) {
            error_log('LlmsTxt::collectIndexableCategories: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @return list<array{name: string, slug: string, url: string, category_name: string}>
     */
    public static function collectIndexableProducts(): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        try {
            Install::ensureConnection();
            $pdo = Database::pdo();
            $redirectSources = self::redirectSourceAbsoluteUrls();
            $stmt = $pdo->query(
                "SELECT p.*, c.slug AS category_slug, c.name AS category_name FROM products p
                 LEFT JOIN categories c ON c.id = p.category_id
                 WHERE p.status = 'published' AND p.robots_index = 1
                   AND EXISTS (
                     SELECT 1 FROM product_variants pv
                     WHERE pv.product_id = p.id AND pv.is_active = 1
                   )
                 ORDER BY c.name, p.name"
            );
            $out = [];
            if ($stmt === false) {
                return [];
            }
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!ProductRepository::isPubliclyVisibleRow($row)) {
                    continue;
                }
                $meta = SeoResolver::forProductRow($row);
                if (!$meta->isIndexableForSitemap() || $meta->canonicalUrl === null || $meta->canonicalUrl === '') {
                    continue;
                }
                $url = SeoUrl::normalizeSitemapLoc($meta->canonicalUrl);
                if (!SeoSitemap::isEligibleAbsoluteUrl($url) || isset($redirectSources[$url])) {
                    continue;
                }
                $catName = trim((string) ($row['category_name'] ?? ''));
                if ($catName === '') {
                    $catName = 'Uncategorised';
                }
                $out[] = [
                    'name' => (string) ($row['name'] ?? ''),
                    'slug' => (string) ($row['slug'] ?? ''),
                    'url' => $url,
                    'category_name' => $catName,
                ];
            }

            return $out;
        } catch (Throwable $e) {
            error_log('LlmsTxt::collectIndexableProducts: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @return list<array{title: string, slug: string, url: string}>
     */
    public static function collectIndexableCmsPages(): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        try {
            Install::ensureConnection();
            $pdo = Database::pdo();
            $redirectSources = self::redirectSourceAbsoluteUrls();
            $cmsStmt = $pdo->prepare(
                "SELECT * FROM cms_pages
                 WHERE status = ?
                   AND robots_index = 1
                   AND (visibility = ? OR visibility = '' OR visibility IS NULL)"
            );
            $cmsStmt->execute([PublishStatus::PUBLISHED, CmsPageService::VISIBILITY_PUBLIC]);
            $out = [];
            foreach ($cmsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $meta = SeoResolver::forCmsPage($row);
                if (!$meta->isIndexableForSitemap() || $meta->canonicalUrl === null || $meta->canonicalUrl === '') {
                    continue;
                }
                $url = SeoUrl::normalizeSitemapLoc($meta->canonicalUrl);
                if (!SeoSitemap::isEligibleAbsoluteUrl($url) || isset($redirectSources[$url])) {
                    continue;
                }
                $title = trim((string) ($row['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $out[] = [
                    'title' => $title,
                    'slug' => (string) ($row['slug'] ?? ''),
                    'url' => $url,
                ];
            }
            usort($out, static fn (array $a, array $b): int => strnatcasecmp($a['title'], $b['title']));

            return $out;
        } catch (Throwable $e) {
            error_log('LlmsTxt::collectIndexableCmsPages: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @return list<array{label: string, url: string, description?: string}>
     */
    private static function collectSupportLinks(): array
    {
        $redirectSources = self::redirectSourceAbsoluteUrls();
        $out = [];
        foreach (self::SUPPORT_APP_PATHS as $path => $label) {
            $url = SeoUrl::normalizeSitemapLoc(SeoUrl::absolute($path));
            if (!SeoSitemap::isEligibleAbsoluteUrl($url) || isset($redirectSources[$url])) {
                continue;
            }
            $desc = $path === '/contact.php'
                ? 'Support phone, email, and WhatsApp when configured in business settings.'
                : 'Form to raise help requests; logged-in customers can link an order when applicable.';
            $out[] = ['label' => $label, 'url' => $url, 'description' => $desc];
        }

        return $out;
    }

    /**
     * @param list<array{title: string, slug: string, url: string}> $cmsPages
     * @return list<array{title: string, slug: string, url: string}>
     */
    private static function orderPolicyPages(array $cmsPages): array
    {
        $bySlug = [];
        foreach ($cmsPages as $page) {
            $slug = (string) ($page['slug'] ?? '');
            if ($slug !== '') {
                $bySlug[$slug] = $page;
            }
        }
        $ordered = [];
        foreach (self::POLICY_SLUG_ORDER as $slug) {
            if (isset($bySlug[$slug])) {
                $ordered[] = $bySlug[$slug];
            }
        }

        return $ordered;
    }

    /**
     * @param list<array{title: string, slug: string, url: string}> $cmsPages
     * @param list<string> $excludeSlugs
     * @return list<array{title: string, slug: string, url: string}>
     */
    private static function cmsPagesExcludingSlugs(array $cmsPages, array $excludeSlugs): array
    {
        $exclude = array_fill_keys($excludeSlugs, true);
        $out = [];
        foreach ($cmsPages as $page) {
            $slug = (string) ($page['slug'] ?? '');
            if ($slug !== '' && isset($exclude[$slug])) {
                continue;
            }
            $out[] = $page;
        }

        return $out;
    }

    /** @return array<string, true> */
    private static function redirectSourceAbsoluteUrls(): array
    {
        $redirectSources = [];
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
                $redirectSources[SeoUrl::normalizeSitemapLoc($absolute)] = true;
            }
        }

        return $redirectSources;
    }

    private static function markdownLinkText(string $text): string
    {
        $text = trim($text);

        return str_replace(['[', ']'], ['\\[', '\\]'], $text);
    }

    private static function writeStaticFileAtomic(string $path, string $content): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            return false;
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            @unlink($tmp);

            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }
}
