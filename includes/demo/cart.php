<?php

declare(strict_types=1);

require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

/**
 * Backward-compatible cart API — delegates to CartService (Stage 7).
 */
final class DemoCart
{
    public static function lineKey(string $productSlug, string $variantId): string
    {
        return SessionCartBackend::lineKey($productSlug, $variantId);
    }

    public static function add(string $productSlug, string $variantId, int $qty): array
    {
        return CartService::add($productSlug, $variantId, $qty);
    }

    public static function updateQty(string $lineKey, int $qty): array
    {
        return CartService::updateQty($lineKey, $qty);
    }

    public static function remove(string $lineKey): array
    {
        return CartService::remove($lineKey);
    }

    public static function applyCoupon(?string $code): array
    {
        return CartService::applyCoupon($code);
    }

    public static function items(): array
    {
        return CartService::items();
    }

    public static function itemCount(): int
    {
        return CartService::itemCount();
    }

    public static function totals(): array
    {
        return CartService::totals();
    }

    public static function clear(): void
    {
        CartService::clear();
    }
}
