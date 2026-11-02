<?php

declare(strict_types=1);

/**
 * Seed categories and products from the static demo catalogue into MySQL.
 * CLI: php scripts/seed-catalogue.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/demo/catalogue.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/slug.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/category_repository.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/product_repository.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready. Import database/database.sql first.\n");
    exit(1);
}

$migration = APNI_TOKRI_ROOT . '/database/migrations/1.1.0_catalogue_attributes.sql';
if (is_readable($migration)) {
    $sql = file_get_contents($migration);
    if ($sql !== false) {
        try {
            Install::ensureConnection();
            Database::pdo()->exec($sql);
        } catch (Throwable $e) {
            // Tables may already exist
        }
    }
}

Install::ensureConnection();
$pdo = Database::pdo();

if (ProductRepository::productCount() > 0) {
    echo "Catalogue already seeded (" . ProductRepository::productCount() . " products). Skipping.\n";
    exit(0);
}

$demo = DemoCatalogue::instance();
$categoryIdBySlug = [];

foreach ($demo->categories() as $cat) {
    $res = CategoryRepository::save(null, [
        'name' => $cat['name'],
        'slug' => $cat['slug'],
        'status' => 'published',
        'show_on_homepage' => 1,
        'sort_order' => count($categoryIdBySlug),
    ]);
    if ($res['ok'] && isset($res['id'])) {
        $categoryIdBySlug[$cat['slug']] = (int) $res['id'];
    }
}

$productIdBySlug = [];

foreach ($demo->products() as $p) {
    $catSlug = (string) ($p['category_slug'] ?? '');
    $catId = $categoryIdBySlug[$catSlug] ?? null;
    $flags = $p['flags'] ?? [];
    $res = ProductRepository::saveGeneral(null, [
        'name' => $p['name'],
        'slug' => $p['slug'],
        'sku' => $p['sku'] ?? '',
        'category_id' => $catId,
        'short_description' => $p['short_description'] ?? '',
        'description' => $p['description'] ?? '',
        'product_type' => $p['type'] ?? 'fresh',
        'status' => 'published',
        'is_featured' => in_array('featured', $flags, true),
        'is_bestseller' => in_array('bestseller', $flags, true),
        'is_new_arrival' => in_array('new', $flags, true),
        'robots_index' => 1,
    ]);
    if (!$res['ok'] || !isset($res['id'])) {
        continue;
    }
    $pid = (int) $res['id'];
    $productIdBySlug[$p['slug']] = $pid;

    $variantRows = [];
    foreach ($p['variants'] ?? [] as $i => $v) {
        $variantRows[] = [
            'name' => $v['label'] ?? 'Default',
            'sku' => ($p['sku'] ?? '') . '-' . ($i + 1),
            'price' => $v['price'] ?? 0,
            'sale_price' => $v['sale_price'] ?? '',
            'stock_qty' => $v['stock'] ?? 0,
            'low_stock_threshold' => $v['low_stock'] ?? 5,
            'is_active' => 1,
            'sort_order' => $i,
        ];
    }
    ProductRepository::saveVariants($pid, $variantRows);
    ProductRepository::saveAttributes($pid, $p['attributes'] ?? []);

    $sort = 0;
    $first = true;
    foreach ($p['images'] ?? [] as $imgUrl) {
        $path = catalogue_seed_image_path((string) $imgUrl);
        if ($path !== null) {
            ProductRepository::addImage($pid, $path, $first);
            $first = false;
            $sort++;
        }
    }
}

foreach ($demo->products() as $p) {
    $pid = $productIdBySlug[$p['slug']] ?? null;
    if ($pid === null) {
        continue;
    }
    $pdo->prepare('DELETE FROM product_related WHERE product_id = ?')->execute([$pid]);
    $sort = 0;
    foreach ($p['related_slugs'] ?? [] as $relSlug) {
        $relId = $productIdBySlug[$relSlug] ?? null;
        if ($relId === null || $relId === $pid) {
            continue;
        }
        $pdo->prepare(
            'INSERT IGNORE INTO product_related (product_id, related_product_id, sort_order) VALUES (?, ?, ?)'
        )->execute([$pid, $relId, $sort++]);
    }
}

CatalogueGateway::reset();
echo 'Seeded ' . ProductRepository::productCount() . ' products in ' . count($categoryIdBySlug) . " categories.\n";

function catalogue_seed_image_path(string $url): ?string
{
    if (strpos($url, 'assets/images/') !== false) {
        $pos = strpos($url, 'assets/images/');
        return substr($url, $pos);
    }
    if (strpos($url, '/assets/images/') !== false) {
        return 'assets/images/' . basename(parse_url($url, PHP_URL_PATH) ?: '');
    }

    return null;
}
