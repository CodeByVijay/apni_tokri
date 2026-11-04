<?php

declare(strict_types=1);

/**
 * CMS footer link visibility (published + show_in_footer). Disposable database only.
 * CLI: php7.3 scripts/test-cms-footer-visibility.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pass = 0;
$fail = 0;

function okF(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if ($c) {
        $pass++;
    } else {
        $fail++;
    }
}

function mysqlF(array $db, string $extra): int
{
    $cmd = 'mysql -h ' . escapeshellarg((string) $db['host']) . ' -P ' . (int) ($db['port'] ?? 3306)
        . ' -u ' . escapeshellarg((string) ($db['user'] ?? 'root'));
    $p = (string) ($db['pass'] ?? $db['password'] ?? '');
    if ($p !== '') {
        $cmd .= ' -p' . escapeshellarg($p);
    }
    passthru($cmd . ' ' . $extra, $code);

    return (int) $code;
}

/** @return list<string> */
function footerSlugs(): array
{
    $slugs = [];
    foreach (CmsPageService::footerLinksForPublic() as $link) {
        $slugs[] = (string) $link['slug'];
    }

    return $slugs;
}

function setCmsPage(PDO $pdo, string $slug, string $title, string $status, int $showInFooter): void
{
    $pubAt = $status === PublishStatus::PUBLISHED ? date('Y-m-d H:i:s') : null;
    $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM cms_pages WHERE slug = ?');
    $existsStmt->execute([$slug]);
    $exists = (int) $existsStmt->fetchColumn();
    if ($exists > 0) {
        $pdo->prepare(
            'UPDATE cms_pages SET title = ?, status = ?, show_in_footer = ?, visibility = ?, published_at = ? WHERE slug = ?'
        )->execute([$title, $status, $showInFooter, CmsPageService::VISIBILITY_PUBLIC, $pubAt, $slug]);

        return;
    }
    $pdo->prepare(
        'INSERT INTO cms_pages (title, slug, body_html, status, show_in_footer, visibility, published_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $title,
        $slug,
        '<p>Test body for ' . $slug . '</p>',
        $status,
        $showInFooter,
        CmsPageService::VISIBILITY_PUBLIC,
        $pubAt,
    ]);
}

$root = dirname(__DIR__);
$sqlFile = $root . '/database/production/apni_tokri_production.sql';
$dbCfg = App::config()['database'];
$freshDb = 'apni_tokri_cms_footer_' . date('YmdHis');

if (!is_readable($sqlFile)) {
    fwrite(STDERR, "Missing {$sqlFile}\n");
    exit(1);
}

okF(mysqlF($dbCfg, '-e ' . escapeshellarg("CREATE DATABASE `{$freshDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) === 0, 'Create disposable DB');
okF(mysqlF($dbCfg, escapeshellarg($freshDb) . ' < ' . escapeshellarg($sqlFile)) === 0, 'Import production SQL');

$orig = App::config();
App::setConfig(array_replace_recursive($orig, ['database' => array_merge($dbCfg, ['name' => $freshDb])]));
Database::disconnect();

try {
    Install::ensureConnection();
    $pdo = Database::pdo();
    $token = strtolower(substr(Security::randomToken(4), 0, 8));

    setCmsPage($pdo, 'at-cms-footer-pub-yes-' . $token, 'Pub Yes ' . $token, PublishStatus::PUBLISHED, 1);
    setCmsPage($pdo, 'at-cms-footer-pub-no-' . $token, 'Pub No ' . $token, PublishStatus::PUBLISHED, 0);
    setCmsPage($pdo, 'at-cms-footer-draft-yes-' . $token, 'Draft Yes ' . $token, PublishStatus::DRAFT, 1);
    setCmsPage($pdo, 'at-cms-footer-draft-no-' . $token, 'Draft No ' . $token, PublishStatus::DRAFT, 0);

    $slugs = footerSlugs();
    okF(in_array('at-cms-footer-pub-yes-' . $token, $slugs, true), 'Published + footer checked → visible');
    okF(!in_array('at-cms-footer-pub-no-' . $token, $slugs, true), 'Published + footer unchecked → hidden');
    okF(!in_array('at-cms-footer-draft-yes-' . $token, $slugs, true), 'Draft + footer checked → hidden');
    okF(!in_array('at-cms-footer-draft-no-' . $token, $slugs, true), 'Draft + footer unchecked → hidden');

    setCmsPage($pdo, 'at-cms-footer-toggle-' . $token, 'Toggle ' . $token, PublishStatus::PUBLISHED, 1);
    okF(in_array('at-cms-footer-toggle-' . $token, footerSlugs(), true), 'Published toggle page initially visible');
    setCmsPage($pdo, 'at-cms-footer-toggle-' . $token, 'Toggle ' . $token, PublishStatus::DRAFT, 1);
    okF(!in_array('at-cms-footer-toggle-' . $token, footerSlugs(), true), 'Unpublish removes footer link');
    setCmsPage($pdo, 'at-cms-footer-toggle-' . $token, 'Toggle ' . $token, PublishStatus::PUBLISHED, 1);
    okF(in_array('at-cms-footer-toggle-' . $token, footerSlugs(), true), 'Publish again restores footer link');

    setCmsPage($pdo, 'at-cms-footer-hidden-' . $token, 'Hidden ' . $token, PublishStatus::PUBLISHED, 0);
    okF(!in_array('at-cms-footer-hidden-' . $token, footerSlugs(), true), 'Published hidden stays out of footer');
    setCmsPage($pdo, 'at-cms-footer-hidden-' . $token, 'Hidden ' . $token, PublishStatus::PUBLISHED, 1);
    okF(in_array('at-cms-footer-hidden-' . $token, footerSlugs(), true), 'Enable footer checkbox adds link');

    foreach (CmsPageService::footerLinksForPublic() as $link) {
        okF(
            str_contains((string) $link['href'], '/page.php?slug=' . rawurlencode((string) $link['slug'])),
            'Footer href matches slug for ' . $link['slug']
        );
        okF(CmsPageService::publishedPublicBySlug((string) $link['slug']) !== null, 'Footer target is publicly viewable: ' . $link['slug']);
    }

    setCmsPage($pdo, 'subscription-terms', 'Subscription Terms', PublishStatus::DRAFT, 0);
    setCmsPage($pdo, 'disclaimer', 'Disclaimer', PublishStatus::PUBLISHED, 1);
    setCmsPage($pdo, 'about-us', 'About Us', PublishStatus::DRAFT, 1);

    ob_start();
    require APNI_TOKRI_ROOT . '/includes/layout/footer-public.php';
    $footerHtml = (string) ob_get_clean();

    okF(!str_contains($footerHtml, 'page.php?slug=subscription-terms'), 'Draft subscription terms not in footer HTML');
    okF(str_contains($footerHtml, 'page.php?slug=disclaimer'), 'Published disclaimer in footer HTML');
    okF(!str_contains($footerHtml, 'page.php?slug=about-us'), 'Draft about-us not duplicated in footer');
    okF(substr_count($footerHtml, 'page.php?slug=disclaimer') === 1, 'Disclaimer link not duplicated');

    $edit = CmsPageRepository::findBySlug('disclaimer');
    okF($edit !== null && (int) ($edit['show_in_footer'] ?? 0) === 1, 'Admin row: disclaimer footer flag persisted');
    okF((string) ($edit['status'] ?? '') === PublishStatus::PUBLISHED, 'Admin row: disclaimer published');

    okF(CmsPageService::publishedPublicBySlug('subscription-terms') === null, 'Draft subscription terms not public');
    okF(CmsPageService::publishedPublicBySlug('disclaimer') !== null, 'Published disclaimer is public');

    okF(str_contains($footerHtml, 'at-footer'), 'Footer layout class preserved');
    okF(str_contains($footerHtml, __('footer_policies')), 'Policies column heading preserved');
} finally {
    App::setConfig($orig);
    Database::disconnect();
    mysqlF($dbCfg, '-e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$freshDb}`"));
}

echo $fail === 0 ? "PASS: CMS footer visibility ({$pass} checks).\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
