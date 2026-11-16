<?php

declare(strict_types=1);

require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

/**
 * Customer product sharing (canonical URLs, share text, social links).
 */
final class ProductShare
{
    /** @param array<string, mixed> $product Storefront product array. */
    public static function canonicalUrl(array $product): string
    {
        $slug = trim((string) ($product['slug'] ?? $product['id'] ?? ''));
        if ($slug === '') {
            return SeoUrl::absolute('/product.php');
        }

        return SeoUrl::absolute('/product.php?slug=' . rawurlencode($slug));
    }

    /**
     * Lowest display price among variants with stock and a positive price.
     *
     * @param array<string, mixed> $product
     */
    public static function startingPrice(array $product): ?float
    {
        $min = null;
        foreach ($product['variants'] ?? [] as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            if ((int) ($variant['stock'] ?? 0) <= 0) {
                continue;
            }
            $prices = catalogue_variant_prices($variant);
            $display = (float) ($prices['display'] ?? 0);
            if ($display <= 0) {
                continue;
            }
            if ($min === null || $display < $min) {
                $min = $display;
            }
        }

        return $min;
    }

    /** @param array<string, mixed> $product */
    public static function shareText(array $product): string
    {
        $site = SeoUrl::siteName();
        $name = trim((string) ($product['name'] ?? ''));
        if ($name === '') {
            $name = 'Product';
        }
        $short = trim((string) ($product['short_description'] ?? ''));
        if ($short === '') {
            $short = trim(strip_tags((string) ($product['description'] ?? '')));
        }
        $short = SeoMetadata::cleanDescription($short, 120) ?? '';

        $segments = [$name];
        if ($short !== '') {
            $segments[] = $short;
        }
        $from = self::startingPrice($product);
        if ($from !== null) {
            $segments[] = 'From ' . catalogue_format_price($from);
        }
        $segments[] = $site;

        return implode(' — ', $segments);
    }

    /** @param array<string, mixed> $product */
    public static function whatsAppShareUrl(array $product): string
    {
        $url = self::canonicalUrl($product);
        $message = self::shareText($product) . "\n\n" . $url;

        return 'https://wa.me/?text=' . rawurlencode($message);
    }

    /** @param array<string, mixed> $product */
    public static function telegramShareUrl(array $product): string
    {
        $url = self::canonicalUrl($product);
        $text = self::shareText($product);

        return 'https://t.me/share/url?url=' . rawurlencode($url) . '&text=' . rawurlencode($text);
    }

    /**
     * @param array<string, mixed> $product
     * @return array{url: string, title: string, text: string, whatsapp: string, telegram: string}
     */
    public static function clientPayload(array $product): array
    {
        return [
            'url' => self::canonicalUrl($product),
            'title' => trim((string) ($product['name'] ?? 'Product')),
            'text' => self::shareText($product),
            'whatsapp' => self::whatsAppShareUrl($product),
            'telegram' => self::telegramShareUrl($product),
        ];
    }

    public static function isPublishedForShare(string $slug): bool
    {
        if ($slug === '') {
            return false;
        }
        if (!Install::isSchemaReady()) {
            return true;
        }
        try {
            $row = ProductRepository::findBySlug($slug, true);
        } catch (Throwable $e) {
            return false;
        }

        return $row !== null;
    }
}
