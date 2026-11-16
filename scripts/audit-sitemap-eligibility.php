<?php

declare(strict_types=1);

/**
 * Read-only sitemap eligibility audit (stdout). No database writes.
 * CLI: php scripts/audit-sitemap-eligibility.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Database schema not ready. Connect MySQL and run again.\n");
    exit(2);
}

Install::ensureConnection();
$pdo = Database::pdo();

$sitemapLocs = [];
foreach (SeoSitemap::collectIndexableUrls() as $entry) {
    $sitemapLocs[(string) ($entry['loc'] ?? '')] = true;
}

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
        $redirectSources[$absolute] = true;
    }
}

function productExclusionReason(array $row, PDO $pdo, array $redirectSources, array $sitemapLocs): array
{
    $name = (string) ($row['name'] ?? '');
    $slug = (string) ($row['slug'] ?? '');
    $id = (int) ($row['id'] ?? 0);
    $reasons = [];

    if ((string) ($row['status'] ?? '') !== 'published') {
        $reasons[] = 'status is not published (' . ($row['status'] ?? '') . ')';
    }
    if (empty($row['robots_index'])) {
        $reasons[] = 'robots_index is off (noindex)';
    }

    $varStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND is_active = 1'
    );
    $varStmt->execute([$id]);
    if ((int) $varStmt->fetchColumn() === 0) {
        $reasons[] = 'no active product variant';
    }

    $catId = $row['category_id'] ?? null;
    if ($catId === null || $catId === '') {
        $reasons[] = 'no category_id assigned';
    }
    if (!ProductRepository::isPubliclyVisibleRow($row)) {
        if ($catId === null || $catId === '') {
            if (!in_array('no category_id assigned', $reasons, true)) {
                $reasons[] = 'not publicly visible (category)';
            }
        } else {
            $cid = (int) $catId;
            $cat = CategoryRepository::find($cid);
            if ($cat === null) {
                $reasons[] = 'category_id does not exist';
            } elseif ((string) ($cat['status'] ?? '') !== 'published') {
                $reasons[] = 'category status is ' . ($cat['status'] ?? 'unknown') . ' (not published)';
            } else {
                $visible = CategoryRepository::visibleIdSet(
                    Database::pdo()->query('SELECT id, parent_id, status FROM categories')->fetchAll(PDO::FETCH_ASSOC) ?: []
                );
                if (!isset($visible[$cid])) {
                    $reasons[] = 'category not in visible tree (hidden parent or cycle)';
                } else {
                    $reasons[] = 'not publicly visible (other storefront rule)';
                }
            }
        }
    }

    $meta = SeoResolver::forProductRow($row);
    if ((string) ($row['status'] ?? '') === 'published' && !$meta->isIndexableForSitemap()) {
        $reasons[] = 'SEO robots noindex (' . ($meta->robots ?? '') . ')';
    }
    if ($meta->canonicalUrl === null || $meta->canonicalUrl === '') {
        $reasons[] = 'missing canonical URL';
    }

    $canonical = $meta->canonicalUrl ?? '';
    if ($canonical !== '') {
        $norm = SeoUrl::normalizeSitemapLoc($canonical);
        if (!SeoSitemap::isEligibleAbsoluteUrl($norm)) {
            $reasons[] = 'canonical URL blocked or wrong base (' . $norm . ')';
        }
        if (isset($redirectSources[$norm])) {
            $reasons[] = 'canonical is a 301 redirect source (excluded from sitemap)';
        }
    }

    $included = $canonical !== '' && isset($sitemapLocs[SeoUrl::normalizeSitemapLoc($canonical)]);
    if ($included && $reasons !== []) {
        $reasons = ['included despite prior checks (unexpected)'];
    }
    if (!$included && $reasons === []) {
        $reasons[] = 'excluded: duplicate canonical merged or not collected';
    }

    return [
        'id' => $id,
        'name' => $name,
        'slug' => $slug,
        'included' => $included,
        'canonical' => $canonical,
        'reasons' => $reasons,
    ];
}

echo "=== SITEMAP ELIGIBILITY AUDIT (read-only) ===\n\n";
echo 'Total indexable URLs in sitemap: ' . count($sitemapLocs) . "\n";
echo 'Base: ' . SeoUrl::publicSiteBaseUrl() . "\n\n";

$publishedStmt = $pdo->query(
    "SELECT p.*, c.slug AS category_slug, c.name AS category_name, c.status AS category_status
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.status = 'published'
     ORDER BY p.name"
);
$publishedRows = $publishedStmt ? $publishedStmt->fetchAll(PDO::FETCH_ASSOC) : [];
echo 'Admin published product count: ' . count($publishedRows) . "\n\n";

$includedProducts = 0;
$excludedProducts = 0;
echo "| # | Product | Slug | Sitemap | Reason |\n";
echo "|---|---------|------|---------|--------|\n";
$i = 0;
foreach ($publishedRows as $row) {
    $i++;
    $audit = productExclusionReason($row, $pdo, $redirectSources, $sitemapLocs);
    if ($audit['included']) {
        $includedProducts++;
        $reason = '—';
    } else {
        $excludedProducts++;
        $reason = implode('; ', $audit['reasons']);
    }
    echo '| ' . $i . ' | ' . str_replace('|', '\\|', $audit['name'])
        . ' | ' . $audit['slug']
        . ' | ' . ($audit['included'] ? 'YES' : 'NO')
        . ' | ' . str_replace('|', '\\|', $reason) . " |\n";
}

echo "\nProduct summary: {$includedProducts} included, {$excludedProducts} excluded of " . count($publishedRows) . " published.\n\n";

// Categories
$allCats = $pdo->query('SELECT id, name, slug, status, parent_id FROM categories ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$visibleIds = CategoryRepository::visibleIdSet($allCats);
$publishedCats = CategoryRepository::published();
$includedCats = 0;
echo "=== CATEGORIES ===\n";
echo 'Total categories: ' . count($allCats) . "\n";
echo 'Published + visible tree: ' . count($publishedCats) . "\n";
foreach ($publishedCats as $row) {
    $meta = SeoResolver::forCategoryRow($row, false);
    $loc = SeoUrl::normalizeSitemapLoc((string) ($meta->canonicalUrl ?? ''));
    $in = $loc !== '' && isset($sitemapLocs[$loc]);
    if ($in) {
        $includedCats++;
    }
    $why = $in ? 'included' : (
        !$meta->isIndexableForSitemap() ? 'noindex' : (
            !isset($visibleIds[(int) $row['id']]) ? 'not visible' : (
                isset($redirectSources[$loc]) ? 'redirect source' : 'not in sitemap collection'
            )
        )
    );
    echo '- ' . $row['name'] . ' (' . $row['slug'] . '): ' . $why . "\n";
}
echo "Categories in sitemap: {$includedCats}\n\n";

// CMS
$cmsRows = $pdo->query('SELECT id, title, slug, status, visibility, robots_index FROM cms_pages ORDER BY title')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$includedCms = 0;
echo "=== CMS PAGES ===\n";
foreach ($cmsRows as $row) {
    $full = $pdo->prepare('SELECT * FROM cms_pages WHERE id = ?');
    $full->execute([(int) $row['id']]);
    $cmsRow = $full->fetch(PDO::FETCH_ASSOC) ?: $row;
    $meta = SeoResolver::forCmsPage($cmsRow);
    $loc = SeoUrl::normalizeSitemapLoc((string) ($meta->canonicalUrl ?? ''));
    $in = $loc !== '' && isset($sitemapLocs[$loc]);
    if ($in) {
        $includedCms++;
    }
    $why = $in ? 'included' : (
        (string) ($row['status'] ?? '') !== 'published' ? 'status=' . ($row['status'] ?? '') : (
            (string) ($row['visibility'] ?? '') !== 'public' && ($row['visibility'] ?? '') !== '' ? 'visibility=' . $row['visibility'] : (
                empty($row['robots_index']) ? 'robots_index off' : (
                    !$meta->isIndexableForSitemap() ? 'noindex meta' : 'not collected'
                )
            )
        )
    );
    echo '- ' . ($row['title'] ?? '') . ' (' . ($row['slug'] ?? '') . '): ' . $why . "\n";
}
echo "CMS pages in sitemap: {$includedCms}\n\n";

// FAQ
$faqMeta = SeoResolver::forFaqPage(false);
$faqLoc = SeoUrl::normalizeSitemapLoc((string) ($faqMeta->canonicalUrl ?? ''));
$faqIn = $faqLoc !== '' && isset($sitemapLocs[$faqLoc]);
echo "=== FAQ ===\n";
echo 'FAQs in sitemap: ' . ($faqIn ? 'YES' : 'NO') . ' (' . $faqLoc . ")\n";

$homeMeta = SeoResolver::forHomepage();
$homeLoc = SeoUrl::normalizeSitemapLoc((string) ($homeMeta->canonicalUrl ?? ''));
$homeIn = $homeLoc !== '' && isset($sitemapLocs[$homeLoc]);
echo 'Homepage in sitemap: ' . ($homeIn ? 'YES' : 'NO') . ' (' . $homeLoc . ")\n";

echo "\n=== URL TYPE BREAKDOWN (collected) ===\n";
$byType = ['home' => 0, 'product' => 0, 'category' => 0, 'cms' => 0, 'faq' => 0, 'other' => 0];
foreach (array_keys($sitemapLocs) as $loc) {
    if ($loc === $homeLoc) {
        $byType['home']++;
    } elseif (str_contains($loc, 'product.php')) {
        $byType['product']++;
    } elseif (str_contains($loc, 'category.php')) {
        $byType['category']++;
    } elseif (str_contains($loc, 'page.php')) {
        $byType['cms']++;
    } elseif (str_contains($loc, 'faqs.php')) {
        $byType['faq']++;
    } else {
        $byType['other']++;
    }
}
foreach ($byType as $type => $n) {
    if ($n > 0) {
        echo "{$type}: {$n}\n";
    }
}
