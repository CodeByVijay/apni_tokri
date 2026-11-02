<?php

declare(strict_types=1);

/** Format INR amounts for display. */
function catalogue_format_price(float $amount): string
{
    return '₹' . number_format($amount, 0, '.', ',');
}

/**
 * @param array<string, mixed> $variant
 * @return array{display: float, regular: float, on_sale: bool}
 */
function catalogue_variant_prices(array $variant): array
{
    $regular = (float) ($variant['price'] ?? 0);
    $sale = isset($variant['sale_price']) && $variant['sale_price'] !== null
        ? (float) $variant['sale_price']
        : null;
    $onSale = $sale !== null && $sale < $regular;

    return [
        'display' => $onSale ? $sale : $regular,
        'regular' => $regular,
        'on_sale' => $onSale,
    ];
}

/** @param array<string, mixed> $product */
function catalogue_default_variant(array $product): ?array
{
    $variants = $product['variants'] ?? [];
    if ($variants === []) {
        return null;
    }

    return $variants[0];
}

/** @param array<string, mixed> $product */
function catalogue_is_in_stock(array $product): bool
{
    foreach ($product['variants'] ?? [] as $v) {
        if ((int) ($v['stock'] ?? 0) > 0) {
            return true;
        }
    }

    return false;
}

/** @param array<string, mixed> $variant */
function catalogue_stock_label(array $variant): ?string
{
    $stock = (int) ($variant['stock'] ?? 0);
    $low = (int) ($variant['low_stock'] ?? 5);
    if ($stock <= 0) {
        return 'Out of stock';
    }
    if ($stock <= $low) {
        return 'Low stock';
    }

    return null;
}

/** @param array<string, mixed> $product */
function catalogue_product_url(array $product): string
{
    return url_base() . '/product.php?slug=' . rawurlencode((string) $product['slug']);
}

/** @param array<string, mixed> $category */
function catalogue_category_url(array $category): string
{
    return url_base() . '/category.php?slug=' . rawurlencode((string) $category['slug']);
}

/** Single-variant quick add allowed when no ambiguity. */
function catalogue_can_quick_add(array $product): bool
{
    $variants = $product['variants'] ?? [];
    if (count($variants) !== 1) {
        return false;
    }

    return (int) ($variants[0]['stock'] ?? 0) > 0;
}
