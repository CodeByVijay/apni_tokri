<?php

declare(strict_types=1);

function stage13_p1_cleanup(PDO $pdo): void
{
    Database::transaction(static function () use ($pdo): void {
        $pdo->exec(
            "DELETE v FROM cms_page_versions v
             INNER JOIN cms_pages p ON p.id = v.cms_page_id
             WHERE p.slug LIKE 'at-cms13-%'"
        );
        $pdo->exec("DELETE FROM cms_pages WHERE slug LIKE 'at-cms13-%'");
        $pdo->exec("DELETE FROM faqs WHERE question LIKE 'AT-CMS13-%'");
        $pdo->exec("DELETE FROM faq_categories WHERE slug LIKE 'at-cms13-%'");
    });
}
