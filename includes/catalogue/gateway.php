<?php

declare(strict_types=1);

/**
 * Database-backed catalogue for storefront (Stage 6).
 * Shape matches DemoCatalogue for drop-in use.
 */
final class DbCatalogue
{
    public function isDemo(): bool
    {
        return false;
    }

    /** @return list<array<string, mixed>> */
    public function categories(): array
    {
        $out = [];
        foreach (CategoryRepository::published() as $row) {
            $out[] = CategoryRepository::toPublicArray($row);
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function category(string $slug): ?array
    {
        $row = CategoryRepository::findBySlug($slug, true);
        if ($row === null) {
            return null;
        }

        return CategoryRepository::toPublicArray($row);
    }

    /** @return list<array<string, mixed>> */
    public function products(): array
    {
        return ProductRepository::publishedProducts();
    }

    /** @return array<string, mixed>|null */
    public function product(string $slug): ?array
    {
        $row = ProductRepository::findBySlug($slug, true);
        if ($row === null) {
            return null;
        }

        return ProductRepository::toPublicArray($row);
    }

    /** @return list<array<string, mixed>> */
    public function productsInCategory(string $categorySlug): array
    {
        $out = [];
        foreach ($this->products() as $p) {
            if (($p['category_slug'] ?? '') === $categorySlug) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function search(string $query): array
    {
        $q = mb_strtolower(trim($query));
        if ($q === '') {
            return [];
        }
        $out = [];
        foreach ($this->products() as $product) {
            $hay = mb_strtolower(
                ($product['name'] ?? '') . ' ' .
                ($product['short_description'] ?? '') . ' ' .
                ($product['sku'] ?? '')
            );
            if (mb_strpos($hay, $q) !== false) {
                $out[] = $product;
            }
        }

        return $out;
    }

    /** @param list<string> $flags */
    public function productsWithFlag(string $flag, int $limit = 8): array
    {
        $map = [
            'featured' => 'featured',
            'bestseller' => 'bestseller',
            'new' => 'new',
        ];
        $needle = $map[$flag] ?? $flag;
        if (isset($map[$flag])) {
            return array_slice(ProductRepository::publishedProductsWithFlag($flag, $flag === 'new'), 0, $limit);
        }
        $out = [];
        foreach ($this->products() as $product) {
            if (in_array($needle, $product['flags'] ?? [], true)) {
                $out[] = $product;
            }
        }

        return array_slice($out, 0, $limit);
    }

    /** @return list<array<string, mixed>> */
    public function relatedFor(array $product, int $limit = 4): array
    {
        $slugs = $product['related_slugs'] ?? [];
        $out = [];
        foreach ($slugs as $slug) {
            $p = $this->product($slug);
            if ($p !== null) {
                $out[] = $p;
            }
        }
        if (count($out) >= $limit) {
            return array_slice($out, 0, $limit);
        }
        $seen = [];
        foreach ($out as $o) {
            $seen[$o['slug']] = true;
        }
        foreach ($this->productsInCategory($product['category_slug'] ?? '') as $p) {
            if ($p['slug'] === $product['slug'] || isset($seen[$p['slug']])) {
                continue;
            }
            $out[] = $p;
            if (count($out) >= $limit) {
                break;
            }
        }

        return array_slice($out, 0, $limit);
    }

    public function approvedReviewsFor(string $productSlug): array
    {
        $row = ProductRepository::findBySlug($productSlug, true);
        if ($row === null) {
            return [];
        }
        $reviews = ReviewService::approvedForProduct((int) $row['id'], 20);
        $out = [];
        foreach ($reviews as $r) {
            $out[] = [
                'author' => (string) ($r['display_name'] ?? 'Customer'),
                'rating' => (int) ($r['rating'] ?? 0),
                'body' => (string) ($r['body'] ?? ''),
                'date' => (string) ($r['created_at'] ?? ''),
                'verified' => !empty($r['verified_purchase']),
                'admin_reply' => (string) ($r['admin_reply'] ?? ''),
                'photos' => array_map(static function ($p) {
                    return url_base() . '/' . ltrim((string) $p['file_path'], '/');
                }, $r['photos'] ?? []),
            ];
        }

        return $out;
    }

    public function ratingSummary(string $productSlug): ?array
    {
        $row = ProductRepository::findBySlug($productSlug, true);
        if ($row === null) {
            return null;
        }

        return ReviewService::ratingSummary((int) $row['id']);
    }

    /** @return list<array<string, mixed>> */
    public function faqs(int $limit = 0): array
    {
        if (!Install::isSchemaReady()) {
            if (AppEnvironment::isProduction()) {
                return [];
            }

            return demo_catalogue_raw()->faqs($limit);
        }
        $result = FaqRepository::listActivePublic(null, null, 1, $limit > 0 ? $limit : 500);
        $rows = $result['rows'] ?? [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'q' => (string) ($row['question'] ?? ''),
                'a' => (string) ($row['answer'] ?? ''),
                'cat' => (string) ($row['category_slug'] ?? 'general'),
            ];
        }

        return $limit > 0 ? array_slice($out, 0, $limit) : $out;
    }
}

/** Access demo FAQs without switching catalogue source. */
function demo_catalogue_raw(): DemoCatalogue
{
    return DemoCatalogue::instance();
}

final class CatalogueGateway
{
    /** @var DbCatalogue|DemoCatalogue|null */
    private static $instance = null;

    public static function useDatabase(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            return ProductRepository::productCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return DbCatalogue|DemoCatalogue */
    public static function instance()
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        if (self::useDatabase() || AppEnvironment::isProduction()) {
            self::$instance = new DbCatalogue();
        } elseif (AppEnvironment::allowsDemoCatalogueFallback()) {
            self::$instance = DemoCatalogue::instance();
        } else {
            self::$instance = new DbCatalogue();
        }

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}

function app_catalogue()
{
    return CatalogueGateway::instance();
}

function demo_catalogue()
{
    return app_catalogue();
}
