<?php

declare(strict_types=1);

/** True when the active catalogue is the static demo dataset (local dev only). */
function storefront_uses_demo_catalogue(): bool
{
    return function_exists('app_catalogue') && app_catalogue()->isDemo();
}

/** Production storefront with schema but no published public products yet. */
function storefront_catalogue_coming_soon(): bool
{
    return AppEnvironment::isProduction()
        && Install::isSchemaReady()
        && !StorefrontReadiness::cataloguePublished();
}

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

/** Public navigation tree: top-level categories with nested 'children' (hidden/ancestor-hidden excluded). */
function catalogue_nav_tree(): array
{
    if (app_catalogue() instanceof DbCatalogue) {
        return CategoryRepository::navigationTree();
    }
    $out = [];
    foreach (app_catalogue()->categories() as $c) {
        $c['children'] = [];
        $out[] = $c;
    }

    return $out;
}

/** Homepage category tiles: visible AND "show on homepage". */
function catalogue_homepage_categories(): array
{
    if (app_catalogue() instanceof DbCatalogue) {
        return CategoryRepository::homepage();
    }

    return app_catalogue()->categories();
}

/** Visible child categories of a category (DB catalogue only). */
function catalogue_child_categories(array $category): array
{
    if (app_catalogue() instanceof DbCatalogue && isset($category['id'])) {
        return CategoryRepository::visibleChildren((int) $category['id']);
    }

    return [];
}

/** Ancestor trail, root first (DB catalogue only). */
function catalogue_category_trail(array $category): array
{
    if (app_catalogue() instanceof DbCatalogue && isset($category['id'])) {
        return CategoryRepository::ancestors((int) $category['id']);
    }

    return [];
}

/** Localised display name for a nav category node. */
function catalogue_nav_label(array $node): string
{
    static $hi = [
        'vegetables' => 'सब्ज़ियाँ',
        'fruits' => 'फल',
        'cut' => 'कटा हुआ',
        'pickles' => 'अचार और पापड़',
    ];
    if (Lang::locale() === 'hi') {
        return (string) ($node['name_hi'] ?? $hi[$node['slug']] ?? $node['name']);
    }

    return (string) $node['name'];
}

/** True when $slug is this node or any descendant. */
function catalogue_nav_contains(array $node, string $slug): bool
{
    if ($slug === '') {
        return false;
    }
    if ((string) $node['slug'] === $slug) {
        return true;
    }
    foreach ($node['children'] ?? [] as $c) {
        if (catalogue_nav_contains($c, $slug)) {
            return true;
        }
    }

    return false;
}

/**
 * Desktop menu markup (nested <ul>, any depth). CSS opens menus on hover/focus; JS flips near viewport edges.
 *
 * @param list<array<string, mixed>> $nodes
 */
function catalogue_render_desktop_nav(array $nodes, string $currentSlug, bool $isCategoryPage, int $depth = 0): string
{
    $base = url_base();
    $html = '';
    foreach ($nodes as $n) {
        $kids = $n['children'] ?? [];
        $slug = (string) $n['slug'];
        $active = $isCategoryPage && $currentSlug === $slug;
        $inPath = $isCategoryPage && catalogue_nav_contains($n, $currentSlug);
        $href = $base . '/category.php?slug=' . rawurlencode($slug);
        $html .= '<li class="at-dnav__item' . ($kids !== [] ? ' has-children' : '') . '" data-depth="' . $depth . '">';
        $html .= '<a class="at-dnav__link at-focusable' . ($active ? ' is-active' : ($inPath ? ' is-in-path' : '')) . '" href="' . Security::escape($href) . '"'
            . ($kids !== [] ? ' aria-haspopup="true"' : '') . ($active ? ' aria-current="page"' : '') . '>'
            . '<span>' . Security::escape(catalogue_nav_label($n)) . '</span>';
        if ($kids !== []) {
            $html .= $depth === 0
                ? '<svg class="at-dnav__chev" viewBox="0 0 20 20" width="14" height="14" aria-hidden="true" focusable="false"><path d="M5 7.5l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                : '<svg class="at-dnav__arrow" viewBox="0 0 20 20" width="14" height="14" aria-hidden="true" focusable="false"><path d="M7.5 5l5 5-5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        }
        $html .= '</a>';
        if ($kids !== []) {
            $html .= '<ul class="at-dnav__menu' . ($depth > 0 ? ' at-dnav__menu--nested' : '') . '">'
                . catalogue_render_desktop_nav($kids, $currentSlug, $isCategoryPage, $depth + 1) . '</ul>';
        }
        $html .= '</li>';
    }

    return $html;
}

/**
 * Mobile accordion markup. Parent link stays clickable; a separate 44px toggle expands children (no hover).
 *
 * @param list<array<string, mixed>> $nodes
 */
function catalogue_render_mobile_nav(array $nodes, string $currentSlug, bool $isCategoryPage, int $depth = 0): string
{
    static $seq = 0;
    $base = url_base();
    $html = '';
    foreach ($nodes as $n) {
        $kids = $n['children'] ?? [];
        $slug = (string) $n['slug'];
        $active = $isCategoryPage && $currentSlug === $slug;
        $open = $kids !== [] && $isCategoryPage && catalogue_nav_contains($n, $currentSlug) && !$active;
        $href = $base . '/category.php?slug=' . rawurlencode($slug);
        $label = catalogue_nav_label($n);
        $html .= '<div class="at-macc" style="--d:' . $depth . '"><div class="at-macc__row">'
            . '<a class="at-drawer-link at-macc__link at-focusable' . ($active ? ' is-active' : '') . '" href="' . Security::escape($href) . '"'
            . ($active ? ' aria-current="page"' : '') . '>' . Security::escape($label) . '</a>';
        if ($kids !== []) {
            $id = 'at-macc-' . (++$seq);
            $html .= '<button type="button" class="at-macc__toggle at-focusable" data-at-macc-toggle aria-expanded="' . ($open ? 'true' : 'false') . '" aria-controls="' . $id . '"'
                . ' aria-label="' . Security::escape($label) . ' sub-categories">'
                . '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false"><path d="M5 7.5l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button></div>'
                . '<div class="at-macc__panel" id="' . $id . '"' . ($open ? '' : ' hidden') . '>'
                . catalogue_render_mobile_nav($kids, $currentSlug, $isCategoryPage, $depth + 1) . '</div></div>';
        } else {
            $html .= '</div></div>';
        }
    }

    return $html;
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
