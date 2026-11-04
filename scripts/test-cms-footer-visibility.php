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
$orderMig = $root . '/database/migrations/1.24.0_cms_footer_display_order.sql';
okF(mysqlF($dbCfg, escapeshellarg($freshDb) . ' < ' . escapeshellarg($orderMig)) === 0, 'Apply 1.24.0 footer order migration');
okF(mysqlF($dbCfg, escapeshellarg($freshDb) . ' < ' . escapeshellarg($orderMig)) === 0, 'Migration 1.24.0 is idempotent');
CmsPageRepository::resetSchemaCache();

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

    // ---- Footer display order ----
    okF(CmsPageRepository::hasFooterOrderColumn(), 'footer_display_order column exists');
    $pdo->exec("UPDATE cms_pages SET show_in_footer = 0, footer_display_order = NULL");
    $mk = static function (string $slug, string $title, string $status, int $foot, $order) use ($pdo): int {
        setCmsPage($pdo, $slug, $title, $status, $foot);
        $row = CmsPageRepository::findBySlug($slug);
        $id = (int) $row['id'];
        $res = CmsPageService::update($id, [
            'title' => $title,
            'slug' => $slug,
            'body_html' => '<p>x</p>',
            'show_in_footer' => $foot === 1,
            'footer_display_order' => $order,
        ], null, 'order test');
        if (!$res['ok']) {
            fwrite(STDERR, 'update failed: ' . json_encode($res) . "\n");
        }
        // update() keeps status from existing row (set by setCmsPage)
        return $id;
    };
    $a = $mk('at-ord-a-' . $token, 'Ord A', PublishStatus::PUBLISHED, 1, '3');
    $b = $mk('at-ord-b-' . $token, 'Ord B', PublishStatus::PUBLISHED, 1, '1');
    $c = $mk('at-ord-c-' . $token, 'Ord C', PublishStatus::PUBLISHED, 1, '2');
    $mk('at-ord-hid-' . $token, 'Ord Hidden', PublishStatus::PUBLISHED, 0, '0');
    $mk('at-ord-dr-' . $token, 'Ord Draft', PublishStatus::DRAFT, 1, '0');
    $mine = static function () use ($token): array {
        return array_values(array_filter(footerSlugs(), static function ($s) use ($token) {
            return strpos($s, 'at-ord-') === 0 && substr($s, -strlen($token)) === $token;
        }));
    };
    okF($mine() === ['at-ord-b-' . $token, 'at-ord-c-' . $token, 'at-ord-a-' . $token], 'Lower order numbers appear first');
    okF(!in_array('at-ord-hid-' . $token, $mine(), true), 'Unchecked page hidden despite order 0');
    okF(!in_array('at-ord-dr-' . $token, $mine(), true), 'Draft page hidden despite order 0');

    CmsPageService::update($a, [
        'title' => 'Ord A', 'slug' => 'at-ord-a-' . $token, 'body_html' => '<p>x</p>',
        'show_in_footer' => true, 'footer_display_order' => '0',
    ], null, 'reorder');
    okF($mine()[0] === 'at-ord-a-' . $token, 'Admin can update order (A moved first)');
    okF((int) CmsPageRepository::findById($a)['footer_display_order'] === 0, 'Order 0 persisted for admin edit form');

    CmsPageService::update($b, [
        'title' => 'Ord B', 'slug' => 'at-ord-b-' . $token, 'body_html' => '<p>x</p>',
        'show_in_footer' => true, 'footer_display_order' => '2',
    ], null, 'tie');
    $tie = $mine();
    okF($tie === ['at-ord-a-' . $token, 'at-ord-b-' . $token, 'at-ord-c-' . $token], 'Equal order values tie-break by id (B before C)');
    okF($tie === $mine(), 'Tie ordering is stable across calls');

    $bad = CmsPageService::update($b, [
        'title' => 'Ord B', 'slug' => 'at-ord-b-' . $token, 'body_html' => '<p>x</p>',
        'show_in_footer' => true, 'footer_display_order' => '-5',
    ], null, 'bad');
    okF(!$bad['ok'] && isset($bad['errors']['footer_display_order']), 'Invalid order rejected');

    CmsPageService::update($b, [
        'title' => 'Ord B', 'slug' => 'at-ord-b-' . $token, 'body_html' => '<p>x</p>',
        'show_in_footer' => true,
    ], null, 'no order key');
    okF((int) CmsPageRepository::findById($b)['footer_display_order'] === 2, 'Omitted order input preserves stored value');

    // Unset order falls back to id (existing pages keep id order)
    $pdo->exec("UPDATE cms_pages SET show_in_footer = 0, footer_display_order = NULL");
    $pdo->exec("UPDATE cms_pages SET status = 'published', show_in_footer = 1, visibility = 'public' WHERE slug IN ('privacy-policy','terms','shipping-policy')");
    $idOrder = $pdo->query("SELECT slug FROM cms_pages WHERE slug IN ('privacy-policy','terms','shipping-policy') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $got = array_values(array_intersect(footerSlugs(), ['privacy-policy', 'terms', 'shipping-policy']));
    okF($got === $idOrder, 'Unset order falls back to page id');

    // About Us in About column only; no duplicates
    $pdo->exec("UPDATE cms_pages SET status = 'published', show_in_footer = 1, visibility = 'public', footer_display_order = 1 WHERE slug = 'about-us'");
    ob_start();
    require APNI_TOKRI_ROOT . '/includes/layout/footer-public.php';
    $footerOrdHtml = (string) ob_get_clean();
    okF(substr_count($footerOrdHtml, 'page.php?slug=about-us') === 1, 'About Us appears exactly once');
    $posAbout = strpos($footerOrdHtml, 'page.php?slug=about-us');
    $posPolicies = strpos($footerOrdHtml, __('footer_policies'));
    $posPrivacy = strpos($footerOrdHtml, 'page.php?slug=privacy-policy');
    okF($posAbout !== false && $posPolicies !== false && $posAbout < $posPolicies, 'About Us rendered in About column (before Policies)');
    okF($posPrivacy !== false && $posPrivacy > $posPolicies, 'Policy pages rendered in Policies column');
    preg_match_all('/page\.php\?slug=([a-z0-9-]+)/', $footerOrdHtml, $m);
    okF(count($m[1]) === count(array_unique($m[1])), 'No duplicate footer links');

    okF(str_contains($footerHtml, 'at-footer'), 'Footer layout class preserved');
    okF(str_contains($footerHtml, __('footer_policies')), 'Policies column heading preserved');
} finally {
    App::setConfig($orig);
    Database::disconnect();
    mysqlF($dbCfg, '-e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$freshDb}`"));
}

echo $fail === 0 ? "PASS: CMS footer visibility ({$pass} checks).\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
