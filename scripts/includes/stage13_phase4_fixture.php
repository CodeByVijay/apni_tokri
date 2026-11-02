<?php

declare(strict_types=1);

function stage13_p4_cleanup(PDO $pdo): void
{
    Database::transaction(static function () use ($pdo): void {
        $pdo->exec(
            "DELETE v FROM cms_page_versions v
             INNER JOIN cms_pages p ON p.id = v.cms_page_id
             WHERE p.slug LIKE 'at-seo13-%'"
        );
        $pdo->exec("DELETE FROM cms_pages WHERE slug LIKE 'at-seo13-%'");
        $pdo->exec("DELETE FROM product_images WHERE product_id IN (SELECT id FROM products WHERE slug LIKE 'at-seo13-%')");
        $pdo->exec("DELETE FROM product_variants WHERE product_id IN (SELECT id FROM products WHERE slug LIKE 'at-seo13-%')");
        $pdo->exec("DELETE FROM products WHERE slug LIKE 'at-seo13-%'");
        $pdo->exec("DELETE FROM categories WHERE slug LIKE 'at-seo13-%'");
        $pdo->exec("DELETE FROM settings WHERE setting_key IN ('seo.home.meta_title', 'seo.home.meta_description', 'seo.home.robots_index')");
    });
}
