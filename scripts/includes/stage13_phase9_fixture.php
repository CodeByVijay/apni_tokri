<?php

declare(strict_types=1);

function stage13_p9_cleanup(PDO $pdo): void
{
    Database::transaction(static function () use ($pdo): void {
        $pdo->exec(
            "DELETE v FROM cms_page_versions v
             INNER JOIN cms_pages p ON p.id = v.cms_page_id
             WHERE p.slug LIKE 'at-int13-%'"
        );
        $pdo->exec("DELETE FROM cms_pages WHERE slug LIKE 'at-int13-%'");
        $pdo->exec("DELETE FROM seo_redirects WHERE from_path LIKE '%at-int13-%' OR to_path LIKE '%at-int13-%'");
        $pdo->exec('DELETE FROM product_variants WHERE product_id IN (SELECT id FROM products WHERE slug LIKE \'at-int13-%\')');
        $pdo->exec('DELETE FROM products WHERE slug LIKE \'at-int13-%\'');
        $pdo->exec('DELETE FROM categories WHERE slug LIKE \'at-int13-%\'');
        $pdo->exec("DELETE FROM faqs WHERE question LIKE 'AT-INT13 %'");
        $pdo->exec("DELETE FROM faq_categories WHERE slug LIKE 'at-int13-%'");
        $pdo->exec("DELETE FROM settings WHERE setting_key = '" . HomepageTrustService::SETTING_KEY . "'");
        $pdo->exec("DELETE FROM testimonials WHERE customer_display_name LIKE 'AT-INT13 %'");
    });
}

function stage13_p9_locs(): array
{
    return array_column(SeoSitemap::collectIndexableUrls(), 'loc');
}
