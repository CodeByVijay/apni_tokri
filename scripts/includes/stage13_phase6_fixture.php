<?php

declare(strict_types=1);

function stage13_p6_cleanup(PDO $pdo): void
{
    Database::transaction(static function () use ($pdo): void {
        $pdo->exec(
            "DELETE v FROM cms_page_versions v
             INNER JOIN cms_pages p ON p.id = v.cms_page_id
             WHERE p.slug LIKE 'at-sm13-%'"
        );
        $pdo->exec("DELETE FROM cms_pages WHERE slug LIKE 'at-sm13-%'");
        $pdo->exec("DELETE FROM seo_redirects WHERE from_path LIKE '%at-sm13-%' OR to_path LIKE '%at-sm13-%'");
        $pdo->exec("DELETE FROM product_variants WHERE product_id IN (SELECT id FROM products WHERE slug LIKE 'at-sm13-%')");
        $pdo->exec("DELETE FROM products WHERE slug LIKE 'at-sm13-%'");
        $pdo->exec("DELETE FROM categories WHERE slug LIKE 'at-sm13-%'");
    });
}

/** @return list<string> */
function stage13_p6_locs(): array
{
    return array_column(SeoSitemap::collectIndexableUrls(), 'loc');
}

function stage13_p6_has_loc(string $needle): bool
{
    foreach (stage13_p6_locs() as $loc) {
        if (str_contains($loc, $needle)) {
            return true;
        }
    }

    return false;
}
