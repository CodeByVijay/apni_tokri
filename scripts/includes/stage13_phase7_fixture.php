<?php

declare(strict_types=1);

function stage13_p7_cleanup(PDO $pdo): void
{
    Database::transaction(static function () use ($pdo): void {
        $pdo->exec('DELETE FROM product_reviews WHERE product_id IN (SELECT id FROM products WHERE slug LIKE \'at-sd13-%\')');
        $pdo->exec(
            "DELETE v FROM cms_page_versions v
             INNER JOIN cms_pages p ON p.id = v.cms_page_id
             WHERE p.slug LIKE 'at-sd13-%'"
        );
        $pdo->exec("DELETE FROM cms_pages WHERE slug LIKE 'at-sd13-%'");
        $pdo->exec('DELETE FROM product_variants WHERE product_id IN (SELECT id FROM products WHERE slug LIKE \'at-sd13-%\')');
        $pdo->exec('DELETE FROM products WHERE slug LIKE \'at-sd13-%\'');
        $pdo->exec('DELETE FROM categories WHERE slug LIKE \'at-sd13-%\'');
        $pdo->exec('DELETE FROM faqs WHERE question LIKE \'AT-SD13 %\'');
        $pdo->exec('DELETE FROM faq_categories WHERE slug LIKE \'at-sd13-%\'');
    });
}

/** @return array<string, mixed>|null */
function stage13_p7_graph_type(SeoJsonLd $graph, string $type): ?array
{
    foreach ($graph->graphNodes() as $node) {
        if (($node['@type'] ?? '') === $type) {
            return $node;
        }
    }

    return null;
}

/** @return array<string, mixed> */
function stage13_p7_decode_graph(SeoJsonLd $graph): array
{
    $tag = $graph->renderScriptTag();
    if (!preg_match('#<script[^>]*>(.*)</script>#s', $tag, $m)) {
        return [];
    }
    $decoded = json_decode($m[1], true);

    return is_array($decoded) ? $decoded : [];
}
