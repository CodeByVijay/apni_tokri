<?php

declare(strict_types=1);

final class SeoStructuredData
{
    public const CURRENCY_INR = 'INR';

    public static function forHomepage(SeoMetadata $seo): ?SeoJsonLd
    {
        if (!$seo->isIndexableForSitemap()) {
            return null;
        }
        $graph = new SeoJsonLd();
        $siteUrl = self::validatedUrl($seo->canonicalUrl ?? SeoUrl::homepageCanonicalUrl());
        if ($siteUrl === null) {
            return null;
        }
        $name = SeoUrl::siteName();
        $org = [
            '@type' => 'Organization',
            'name' => BusinessSettings::legalName(),
            'url' => $siteUrl,
        ];
        $phone = BusinessSettings::supportPhoneDisplay();
        if ($phone !== null && $phone !== '') {
            $org['telephone'] = $phone;
        }
        $email = BusinessSettings::supportEmail();
        if ($email !== null && $email !== '') {
            $org['email'] = $email;
        }
        $street = BusinessSettings::addressLine();
        $city = BusinessSettings::city();
        if ($street !== null && $street !== '' && $city !== null && $city !== '') {
            $org['address'] = array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $street,
                'addressLocality' => $city,
                'addressRegion' => BusinessSettings::state(),
                'postalCode' => BusinessSettings::pincode(),
                'addressCountry' => 'IN',
            ], static fn ($v) => $v !== null && $v !== '');
        }
        $hours = trim(AppSettings::get(SeoAdminCenter::KEY_LOCAL_HOURS, '') ?? '');
        if ($hours !== '') {
            $org['openingHours'] = $hours;
        }
        $area = trim(AppSettings::get(SeoAdminCenter::KEY_LOCAL_SERVICE_AREA, '') ?? '');
        if ($area !== '') {
            $org['areaServed'] = $area;
        }
        $graph->add($org);
        $website = [
            '@type' => 'WebSite',
            'name' => $name,
            'url' => $siteUrl,
        ];
        $searchTemplate = self::searchUrlTemplate();
        if ($searchTemplate !== null) {
            $website['potentialAction'] = [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $searchTemplate,
                ],
                'query-input' => 'required name=search_term_string',
            ];
        }
        $graph->add($website);

        return $graph;
    }

    /**
     * @param array<string, mixed> $product Storefront product array
     * @param array<string, mixed>|null $category
     * @param array{author: string, rating: int, body: string, date: string}|list<array<string, mixed>> $reviews
     * @param array{average: float, count: int}|null $ratingSummary
     */
    public static function forProductPage(
        array $product,
        ?array $category,
        ?array $ratingSummary,
        array $reviews,
        SeoMetadata $seo
    ): ?SeoJsonLd {
        if (!$seo->isIndexableForSitemap()) {
            return null;
        }
        if (!self::isPublishedProduct($product)) {
            return null;
        }
        $canonical = self::validatedUrl($seo->canonicalUrl);
        if ($canonical === null) {
            return null;
        }
        $graph = new SeoJsonLd();
        $crumbs = [
            ['Home', SeoUrl::homepageCanonicalUrl()],
        ];
        if ($category !== null) {
            $catName = (string) ($category['name'] ?? '');
            $catUrl = self::validatedUrl(SeoUrl::absolute('/category.php?slug=' . rawurlencode((string) ($category['slug'] ?? ''))));
            if ($catName !== '' && $catUrl !== null) {
                $crumbs[] = [$catName, $catUrl];
            }
        }
        $productName = (string) ($product['name'] ?? '');
        if ($productName === '') {
            return null;
        }
        $crumbs[] = [$productName, $canonical];
        $graph->add(self::breadcrumbList($crumbs));

        $defaultVariant = catalogue_default_variant($product);
        if ($defaultVariant === null) {
            return null;
        }
        $prices = catalogue_variant_prices($defaultVariant);
        $displayPrice = (float) ($prices['display'] ?? 0);
        if ($displayPrice <= 0) {
            return null;
        }
        $stock = (int) ($defaultVariant['stock'] ?? 0);
        $availability = $stock > 0
            ? 'https://schema.org/InStock'
            : 'https://schema.org/OutOfStock';

        $description = SeoMetadata::cleanDescription(
            (string) ($product['short_description'] ?? $product['description'] ?? '')
        );
        $productNode = [
            '@type' => 'Product',
            'name' => $productName,
            'url' => $canonical,
            'offers' => [
                '@type' => 'Offer',
                'url' => $canonical,
                'priceCurrency' => self::CURRENCY_INR,
                'price' => number_format($displayPrice, 2, '.', ''),
                'availability' => $availability,
                'itemCondition' => 'https://schema.org/NewCondition',
            ],
        ];
        if ($description !== null && $description !== '') {
            $productNode['description'] = $description;
        }
        $image = self::primaryProductImageUrl($product);
        if ($image !== null) {
            $productNode['image'] = [$image];
        }
        $sku = trim((string) ($defaultVariant['sku'] ?? $product['sku'] ?? ''));
        if ($sku !== '') {
            $productNode['sku'] = $sku;
        }
        if ($ratingSummary !== null && (int) ($ratingSummary['count'] ?? 0) > 0 && $reviews !== []) {
            $productNode['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => number_format((float) $ratingSummary['average'], 1, '.', ''),
                'reviewCount' => (int) $ratingSummary['count'],
                'bestRating' => '5',
                'worstRating' => '1',
            ];
            $reviewNodes = self::reviewNodes($reviews);
            if ($reviewNodes !== []) {
                $productNode['review'] = $reviewNodes;
            }
        }
        $graph->add($productNode);

        return $graph;
    }

    /** @param array<string, mixed> $category */
    public static function forCategoryPage(array $category, SeoMetadata $seo): ?SeoJsonLd
    {
        if (!$seo->isIndexableForSitemap()) {
            return null;
        }
        $canonical = self::validatedUrl($seo->canonicalUrl);
        if ($canonical === null) {
            return null;
        }
        $name = (string) ($category['name'] ?? '');
        if ($name === '') {
            return null;
        }
        $graph = new SeoJsonLd();
        $graph->add(self::breadcrumbList([
            ['Home', SeoUrl::homepageCanonicalUrl()],
            [$name, $canonical],
        ]));

        return $graph;
    }

    /** @param array<string, mixed> $cmsPage */
    public static function forCmsPage(array $cmsPage, SeoMetadata $seo): ?SeoJsonLd
    {
        if (!$seo->isIndexableForSitemap()) {
            return null;
        }
        if ((string) ($cmsPage['status'] ?? '') !== PublishStatus::PUBLISHED) {
            return null;
        }
        if ((string) ($cmsPage['visibility'] ?? CmsPageService::VISIBILITY_PUBLIC) === CmsPageService::VISIBILITY_PRIVATE) {
            return null;
        }
        $canonical = self::validatedUrl($seo->canonicalUrl);
        if ($canonical === null) {
            return null;
        }
        $title = (string) ($cmsPage['title'] ?? '');
        if ($title === '') {
            return null;
        }
        $graph = new SeoJsonLd();
        $graph->add(self::breadcrumbList([
            ['Home', SeoUrl::homepageCanonicalUrl()],
            [$title, $canonical],
        ]));
        $graph->add([
            '@type' => 'WebPage',
            'name' => $title,
            'url' => $canonical,
        ]);

        return $graph;
    }

    /**
     * @param list<array<string, mixed>> $faqRows Visible FAQ rows on the page
     */
    public static function forFaqPage(array $faqRows, SeoMetadata $seo, bool $searchOrFilterActive): ?SeoJsonLd
    {
        if (!$seo->isIndexableForSitemap() || $searchOrFilterActive || $faqRows === []) {
            return null;
        }
        $canonical = self::validatedUrl($seo->canonicalUrl ?? SeoUrl::absolute('/faqs.php'));
        if ($canonical === null) {
            return null;
        }
        $entities = [];
        foreach ($faqRows as $row) {
            $question = trim(strip_tags((string) ($row['question'] ?? '')));
            $answer = trim(strip_tags((string) ($row['answer'] ?? '')));
            if ($question === '' || $answer === '') {
                continue;
            }
            $entities[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $answer,
                ],
            ];
        }
        if ($entities === []) {
            return null;
        }
        $graph = new SeoJsonLd();
        $graph->add([
            '@type' => 'FAQPage',
            'url' => $canonical,
            'mainEntity' => $entities,
        ]);

        return $graph;
    }

    /** @return list<array{name: string, url: string|null}> */
    private static function breadcrumbList(array $items): array
    {
        $elements = [];
        $position = 1;
        foreach ($items as $item) {
            $name = (string) ($item[0] ?? '');
            if ($name === '') {
                continue;
            }
            $el = [
                '@type' => 'ListItem',
                'position' => $position,
                'name' => $name,
            ];
            $position++;
            $url = isset($item[1]) ? self::validatedUrl((string) $item[1]) : null;
            if ($url !== null) {
                $el['item'] = $url;
            }
            $elements[] = $el;
        }

        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }

    /** @param list<array<string, mixed>> $reviews */
    private static function reviewNodes(array $reviews): array
    {
        $out = [];
        foreach ($reviews as $review) {
            $rating = (int) ($review['rating'] ?? 0);
            if ($rating < 1 || $rating > 5) {
                continue;
            }
            $body = trim(strip_tags((string) ($review['body'] ?? '')));
            if ($body === '') {
                continue;
            }
            $author = trim((string) ($review['author'] ?? ''));
            if ($author === '') {
                $author = 'Customer';
            }
            $node = [
                '@type' => 'Review',
                'author' => [
                    '@type' => 'Person',
                    'name' => $author,
                ],
                'reviewRating' => [
                    '@type' => 'Rating',
                    'ratingValue' => $rating,
                    'bestRating' => 5,
                    'worstRating' => 1,
                ],
                'reviewBody' => $body,
            ];
            $date = (string) ($review['date'] ?? '');
            if ($date !== '') {
                $ts = strtotime($date);
                if ($ts !== false) {
                    $node['datePublished'] = gmdate('Y-m-d', $ts);
                }
            }
            $out[] = $node;
        }

        return $out;
    }

    /** @param array<string, mixed> $product */
    private static function isPublishedProduct(array $product): bool
    {
        $slug = (string) ($product['slug'] ?? '');
        if ($slug !== '' && Install::isSchemaReady()) {
            $row = ProductRepository::findBySlug($slug, true);
            if ($row === null) {
                return false;
            }
            if ((string) ($row['status'] ?? '') !== 'published') {
                return false;
            }
            if (empty($row['robots_index'])) {
                return false;
            }
            foreach (ProductRepository::variants((int) $row['id']) as $v) {
                if ((int) ($v['is_active'] ?? 0) === 1) {
                    return true;
                }
            }

            return false;
        }

        return ($product['status'] ?? 'published') === 'published';
    }

    /** @param array<string, mixed> $product */
    private static function primaryProductImageUrl(array $product): ?string
    {
        $images = $product['images'] ?? [];
        if (!is_array($images) || !isset($images[0])) {
            return null;
        }
        $first = $images[0];
        if (!is_string($first) || $first === '') {
            return null;
        }
        if (str_starts_with($first, 'http://') || str_starts_with($first, 'https://')) {
            return self::validatedUrl($first);
        }
        $path = $first;
        $base = url_base();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return self::validatedUrl(SeoUrl::absolute($path));
    }

    private static function validatedUrl(?string $url): ?string
    {
        $url = trim((string) ($url ?? ''));
        if ($url === '') {
            return null;
        }
        $validated = SeoUrl::validatePublicUrl($url);
        if ($validated !== null) {
            return $validated;
        }
        if (str_starts_with($url, '/')) {
            return SeoUrl::validatePublicUrl(SeoUrl::absolute($url));
        }

        return null;
    }

    private static function searchUrlTemplate(): ?string
    {
        $template = SeoUrl::absolute('/search.php?q={search_term_string}');

        return self::validatedUrl($template);
    }
}
