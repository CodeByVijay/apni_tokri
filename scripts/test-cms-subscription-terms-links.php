<?php

declare(strict_types=1);

/** Subscription terms CMS slug: published vs draft vs missing (disposable DB). */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$pass = 0;
$fail = 0;

function t(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

$tok = strtolower(bin2hex(random_bytes(3)));
$slug = 'subscription-terms';
$missingSlug = 'zt-cms-missing-' . $tok;

function upsertCms(PDO $pdo, string $slug, string $status): void
{
    $pubAt = $status === PublishStatus::PUBLISHED ? date('Y-m-d H:i:s') : null;
    $st = $pdo->prepare('SELECT id FROM cms_pages WHERE slug = ? LIMIT 1');
    $st->execute([$slug]);
    $id = $st->fetchColumn();
    if ($id) {
        $pdo->prepare(
            'UPDATE cms_pages SET status = ?, visibility = ?, published_at = ?, body_html = ? WHERE slug = ?'
        )->execute([
            $status,
            CmsPageService::VISIBILITY_PUBLIC,
            $pubAt,
            '<p>Test subscription terms body</p>',
            $slug,
        ]);

        return;
    }
    $pdo->prepare(
        'INSERT INTO cms_pages (title, slug, body_html, status, visibility, published_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        'Subscription Terms',
        $slug,
        '<p>Test subscription terms body</p>',
        $status,
        CmsPageService::VISIBILITY_PUBLIC,
        $pubAt,
    ]);
}

// Missing slug
t(CmsPageService::publishedPublicBySlug($missingSlug) === null, 'Missing slug: not public');
t(CmsPageService::publicPageUrl($missingSlug) === null, 'Missing slug: no storefront URL');

// Draft
upsertCms($pdo, $slug, PublishStatus::DRAFT);
$row = CmsPageRepository::findBySlug($slug);
t($row !== null && (string) $row['status'] === PublishStatus::DRAFT, 'Draft: row exists in CMS');
t(CmsPageService::publishedPublicBySlug($slug) === null, 'Draft: not public');
t(CmsPageService::publicPageUrl($slug) === null, 'Draft: no storefront URL (homepage should not link to 404)');

$partialDraft = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/cms-subscription-terms-link.php');
t(strpos($partialDraft, 'publicPageUrl') !== false, 'Partial uses publicPageUrl guard');

// Published
upsertCms($pdo, $slug, PublishStatus::PUBLISHED);
$pub = CmsPageService::publishedPublicBySlug($slug);
t($pub !== null && (string) $pub['slug'] === $slug, 'Published: public by slug');
$url = CmsPageService::publicPageUrl($slug);
t($url !== null && strpos($url, 'page.php?slug=' . rawurlencode($slug)) !== false, 'Published: storefront URL');

$pageSrc = (string) file_get_contents(APNI_TOKRI_ROOT . '/page.php');
t(strpos($pageSrc, 'Page not published') !== false, 'page.php distinguishes unpublished CMS rows');

$indexSrc = (string) file_get_contents(APNI_TOKRI_ROOT . '/index.php');
t(strpos($indexSrc, 'cms-subscription-terms-link.php') !== false, 'Homepage uses subscription terms partial');
t(strpos($indexSrc, 'page.php?slug=subscription-terms') === false, 'Homepage no hardcoded subscription-terms href');

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
