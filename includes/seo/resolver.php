<?php

declare(strict_types=1);

final class SeoResolver
{
    public static function forHomepage(): SeoMetadata
    {
        $site = SeoUrl::siteName();
        $title = trim((string) (AppSettings::get('seo.home.meta_title', '') ?? ''));
        $desc = trim((string) (AppSettings::get('seo.home.meta_description', '') ?? ''));
        if ($title === '') {
            $title = BrandingSettings::defaultSeoTitle();
        }
        if ($desc === '') {
            $desc = BrandingSettings::defaultMetaDescription();
        }
        $robots = (AppSettings::get('seo.home.robots_index', '1') ?? '1') === '1' ? 'index, follow' : 'noindex, nofollow';

        return new SeoMetadata(
            $title,
            SeoMetadata::cleanDescription($desc),
            SeoUrl::absolute('/index.php'),
            $robots,
            $title,
            SeoMetadata::cleanDescription($desc),
            self::defaultOgImage(),
            'website'
        );
    }

    /** @param array<string, mixed> $row Product DB row (published). */
    public static function forProductRow(array $row): SeoMetadata
    {
        if ((string) ($row['status'] ?? '') !== 'published') {
            return self::notFound();
        }
        $site = SeoUrl::siteName();
        $name = (string) ($row['name'] ?? 'Product');
        $metaTitle = trim((string) ($row['meta_title'] ?? ''));
        $documentTitle = $metaTitle !== '' ? $metaTitle : $name . ' — ' . $site;
        $metaDesc = trim((string) ($row['meta_description'] ?? ''));
        if ($metaDesc === '') {
            $metaDesc = (string) ($row['short_description'] ?? $row['description'] ?? '');
        }
        $slug = (string) ($row['slug'] ?? '');
        $canonical = SeoUrl::absolute('/product.php?slug=' . rawurlencode($slug));
        $robots = !empty($row['robots_index']) ? 'index, follow' : 'noindex, nofollow';
        $ogImage = self::productOgImage((int) ($row['id'] ?? 0));
        $ogTitle = $name . ' | ' . $site;
        $shareDescription = trim((string) ($row['short_description'] ?? ''));
        if ($shareDescription === '') {
            $shareDescription = $metaDesc;
        }

        return new SeoMetadata(
            $documentTitle,
            SeoMetadata::cleanDescription($metaDesc),
            $canonical,
            $robots,
            $ogTitle,
            SeoMetadata::cleanDescription($shareDescription !== '' ? $shareDescription : $metaDesc),
            $ogImage,
            'product',
            'summary_large_image'
        );
    }

    public static function forProductSlug(string $slug): ?SeoMetadata
    {
        if ($slug === '' || !Install::isSchemaReady()) {
            return null;
        }
        try {
            $row = ProductRepository::findBySlug($slug, true);
        } catch (Throwable $e) {
            return null;
        }

        return $row !== null ? self::forProductRow($row) : null;
    }

    /** @param array<string, mixed> $row Category DB row (published). */
    public static function forCategoryRow(array $row, bool $filteredListing = false): SeoMetadata
    {
        if ((string) ($row['status'] ?? '') !== 'published') {
            return self::notFound();
        }
        $site = SeoUrl::siteName();
        $name = (string) ($row['name'] ?? 'Category');
        $metaTitle = trim((string) ($row['meta_title'] ?? ''));
        $documentTitle = $metaTitle !== '' ? $metaTitle : $name . ' — ' . $site;
        $metaDesc = trim((string) ($row['meta_description'] ?? ''));
        if ($metaDesc === '') {
            $metaDesc = (string) ($row['description'] ?? '');
        }
        $slug = (string) ($row['slug'] ?? '');
        $canonical = SeoUrl::absolute('/category.php?slug=' . rawurlencode($slug));
        $robots = $filteredListing ? 'noindex, follow' : 'index, follow';

        return new SeoMetadata(
            $documentTitle,
            SeoMetadata::cleanDescription($metaDesc),
            $canonical,
            $robots,
            $metaTitle !== '' ? $metaTitle : $name,
            SeoMetadata::cleanDescription($metaDesc),
            self::defaultOgImage(),
            'website'
        );
    }

    public static function forCategorySlug(string $slug, bool $filteredListing = false): ?SeoMetadata
    {
        if ($slug === '' || !Install::isSchemaReady()) {
            return null;
        }
        try {
            $row = CategoryRepository::findBySlug($slug, true);
        } catch (Throwable $e) {
            return null;
        }

        return $row !== null ? self::forCategoryRow($row, $filteredListing) : null;
    }

    /**
     * @param array<string, mixed> $cmsPage Normalized published CMS page row.
     */
    public static function forCmsPage(array $cmsPage): SeoMetadata
    {
        $visibility = (string) ($cmsPage['visibility'] ?? CmsPageService::VISIBILITY_PUBLIC);
        $status = (string) ($cmsPage['status'] ?? '');
        if ($status !== PublishStatus::PUBLISHED || $visibility === CmsPageService::VISIBILITY_PRIVATE) {
            return SeoMetadata::noindex(
                (string) ($cmsPage['title'] ?? 'Page') . ' — ' . SeoUrl::siteName(),
            );
        }
        $site = SeoUrl::siteName();
        $title = (string) ($cmsPage['title'] ?? 'Page');
        $metaTitle = trim((string) ($cmsPage['meta_title'] ?? ''));
        $documentTitle = $metaTitle !== '' ? $metaTitle : $title . ' — ' . $site;
        $metaDesc = trim((string) ($cmsPage['meta_description'] ?? ''));
        $slug = (string) ($cmsPage['slug'] ?? '');
        $defaultCanonical = SeoUrl::absolute('/page.php?slug=' . rawurlencode($slug));
        $canonical = SeoUrl::validatePublicUrl($cmsPage['canonical_url'] ?? null) ?? $defaultCanonical;
        $index = !empty($cmsPage['robots_index']);
        $follow = !empty($cmsPage['robots_follow']);
        $robots = ($index ? 'index' : 'noindex') . ', ' . ($follow ? 'follow' : 'nofollow');
        $ogTitle = trim((string) ($cmsPage['og_title'] ?? ''));
        $ogDesc = trim((string) ($cmsPage['og_description'] ?? ''));
        $ogImage = SeoUrl::validateOgImage($cmsPage['og_image'] ?? null) ?? self::defaultOgImage();

        return new SeoMetadata(
            $documentTitle,
            SeoMetadata::cleanDescription($metaDesc !== '' ? $metaDesc : null),
            $canonical,
            $robots,
            $ogTitle !== '' ? $ogTitle : ($metaTitle !== '' ? $metaTitle : $title),
            SeoMetadata::cleanDescription($ogDesc !== '' ? $ogDesc : $metaDesc),
            $ogImage,
            'article'
        );
    }

    public static function forCmsPreview(): SeoMetadata
    {
        return SeoMetadata::noindex('Preview — ' . SeoUrl::siteName());
    }

    public static function forSearchPage(?string $query, bool $hasFilters = false): SeoMetadata
    {
        $site = SeoUrl::siteName();
        $q = trim((string) ($query ?? ''));
        $title = ($q !== '' ? 'Search: ' . $q : 'Search') . ' — ' . $site;
        $robots = ($q !== '' || $hasFilters) ? 'noindex, follow' : 'noindex, follow';

        return new SeoMetadata(
            $title,
            SeoMetadata::cleanDescription('Search products on ' . $site . '.'),
            null,
            $robots,
            null,
            null,
            null,
            'website'
        );
    }

    public static function forFaqPage(bool $searchOrFilterActive): SeoMetadata
    {
        $site = SeoUrl::siteName();
        $robots = $searchOrFilterActive ? 'noindex, follow' : 'index, follow';

        return new SeoMetadata(
            'FAQs — ' . $site,
            SeoMetadata::cleanDescription('Quick answers about delivery, payments, products and reviews.'),
            SeoUrl::absolute('/faqs.php'),
            $robots,
            null,
            null,
            null,
            'website'
        );
    }

    public static function notFound(): SeoMetadata
    {
        return SeoMetadata::noindex('Page not found — ' . SeoUrl::siteName());
    }

    public static function accessDenied(): SeoMetadata
    {
        return SeoMetadata::noindex('Access denied — ' . SeoUrl::siteName());
    }

    /** @param array<string, mixed> $product Storefront product array (demo or DB-mapped). */
    public static function forStorefrontProduct(array $product): SeoMetadata
    {
        $slug = (string) ($product['slug'] ?? $product['id'] ?? '');
        if ($slug !== '' && Install::isSchemaReady()) {
            $fromDb = self::forProductSlug($slug);
            if ($fromDb !== null) {
                return $fromDb;
            }
        }
        $site = SeoUrl::siteName();
        $name = (string) ($product['name'] ?? 'Product');
        $desc = (string) ($product['short_description'] ?? $product['description'] ?? '');
        $og = self::storefrontProductOgImage($product);

        return new SeoMetadata(
            $name . ' — ' . $site,
            SeoMetadata::cleanDescription($desc),
            SeoUrl::absolute('/product.php?slug=' . rawurlencode($slug)),
            'index, follow',
            $name . ' | ' . $site,
            SeoMetadata::cleanDescription($desc),
            $og,
            'product',
            'summary_large_image'
        );
    }

    /** @param array<string, mixed> $product */
    private static function storefrontProductOgImage(array $product): ?string
    {
        $slug = trim((string) ($product['slug'] ?? ''));
        if ($slug !== '' && Install::isSchemaReady()) {
            try {
                $row = ProductRepository::findBySlug($slug, true);
                if ($row !== null) {
                    return self::productOgImage((int) ($row['id'] ?? 0));
                }
            } catch (Throwable $e) {
                // fall through to storefront image list
            }
        }
        foreach ($product['images'] ?? [] as $img) {
            if (!is_string($img) || $img === '') {
                continue;
            }
            $valid = SeoUrl::validateOgImage($img);
            if ($valid !== null) {
                return $valid;
            }
        }

        return self::defaultOgImage();
    }

    /** @param array<string, mixed> $category Storefront category array. */
    public static function forStorefrontCategory(array $category, bool $filteredListing = false): SeoMetadata
    {
        $slug = (string) ($category['slug'] ?? '');
        if ($slug !== '' && Install::isSchemaReady()) {
            $fromDb = self::forCategorySlug($slug, $filteredListing);
            if ($fromDb !== null) {
                return $fromDb;
            }
        }
        $site = SeoUrl::siteName();
        $name = (string) ($category['name'] ?? 'Category');

        return new SeoMetadata(
            $name . ' — ' . $site,
            SeoMetadata::cleanDescription((string) ($category['description'] ?? '')),
            SeoUrl::absolute('/category.php?slug=' . rawurlencode($slug)),
            $filteredListing ? 'noindex, follow' : 'index, follow',
            $name,
            null,
            null,
            'website'
        );
    }

    private static function defaultOgImage(): ?string
    {
        return BrandingSettings::defaultOgImageUrl();
    }

    private static function productOgImage(int $productId): ?string
    {
        if ($productId <= 0 || !Install::isSchemaReady()) {
            return self::defaultOgImage();
        }
        try {
            $images = ProductRepository::images($productId);
            $chosen = null;
            foreach ($images as $img) {
                if (!empty($img['is_primary'])) {
                    $chosen = $img;
                    break;
                }
            }
            if ($chosen === null && $images !== []) {
                $chosen = $images[0];
            }
            if ($chosen !== null) {
                $path = (string) ($chosen['file_path'] ?? '');
                if ($path !== '') {
                    $url = str_starts_with($path, 'http') ? $path : SeoUrl::absolute('/' . ltrim($path, '/'));
                    $valid = SeoUrl::validateOgImage($url);

                    return $valid ?? self::defaultOgImage();
                }
            }
        } catch (Throwable $e) {
            return self::defaultOgImage();
        }

        return self::defaultOgImage();
    }
}
