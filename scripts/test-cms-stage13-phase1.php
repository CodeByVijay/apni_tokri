<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/stage13_phase1_fixture.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}
if (!Install::isVersionCurrent()) {
    fwrite(STDERR, 'Apply database/migrations/1.9.0_stage13_cms_foundation.sql (expected '
        . Install::SCHEMA_VERSION . ', got ' . (Install::installedSchemaVersion() ?? 'null') . ").\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

stage13_p1_cleanup($pdo);

ok(Install::installedSchemaVersion() === Install::SCHEMA_VERSION, 'Schema version 1.9.0-stage13-cms-1');

$verTable = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cms_page_versions'"
)->fetchColumn();
ok($verTable === 1, 'cms_page_versions table exists');

$visCol = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'cms_pages' AND column_name = 'visibility'"
)->fetchColumn();
ok($visCol === 1, 'cms_pages.visibility column exists');

$token = strtolower(Security::randomToken(4));
$create = CmsPageService::create([
    'title' => 'AT-CMS13 Test ' . $token,
    'slug' => 'at-cms13-' . $token,
    'body_html' => '<p>Draft body</p><script>bad()</script>',
], null);
ok($create['ok'], 'CMS create page');
$pageId = (int) ($create['id'] ?? 0);
ok($pageId > 0, 'CMS create returned id');

$row = CmsPageRepository::findById($pageId);
ok($row !== null && stripos((string) ($row['body_html'] ?? ''), '<script') === false, 'HTML sanitized on save');

ok(CmsPageService::publishedPublicBySlug('at-cms13-' . $token) === null, 'Draft not publicly accessible');

$pub = CmsPageService::publish($pageId, [
    'title' => 'AT-CMS13 Published ' . $token,
    'slug' => 'at-cms13-' . $token,
    'body_html' => '<h2>About</h2><p>Published CMS body for AT-CMS13.</p>',
    'show_in_footer' => 1,
], null);
ok($pub['ok'], 'CMS publish page');

$public = CmsPageService::publishedPublicBySlug('at-cms13-' . $token);
ok($public !== null && strpos((string) ($public['body_html'] ?? ''), 'Published CMS body') !== false, 'Published page readable by slug');

$about = CmsPageRepository::findBySlug('about-us');
if ($about !== null) {
    $aboutOrigStatus = (string) ($about['status'] ?? PublishStatus::DRAFT);
    CmsPageService::publish((int) $about['id'], [
        'title' => 'About Us',
        'slug' => 'about-us',
        'body_html' => '<p>CMS-backed About Us placeholder for Stage 13 Phase 1 test.</p>',
        'show_in_footer' => 1,
    ], null);
    $aboutPub = CmsPageService::publishedPublicBySlug('about-us');
    ok($aboutPub !== null && strpos((string) $aboutPub['body_html'], 'CMS-backed About Us') !== false, 'About Us CMS-backed when published');
    CmsPageService::saveDraft((int) $about['id'], [
        'title' => 'About Us',
        'slug' => 'about-us',
        'body_html' => (string) ($about['body_html'] ?? '<p>Content placeholder.</p>'),
        'show_in_footer' => (int) ($about['show_in_footer'] ?? 1),
    ], null);
    if ($aboutOrigStatus === PublishStatus::PUBLISHED) {
        CmsPageService::publish((int) $about['id'], [
            'title' => 'About Us',
            'slug' => 'about-us',
            'body_html' => (string) ($about['body_html'] ?? ''),
            'show_in_footer' => (int) ($about['show_in_footer'] ?? 1),
        ], null);
    }
} else {
    ok(false, 'Seeded about-us row exists');
}

ok(CmsPageService::publishedPublicBySlug('at-cms13-unknown-' . $token) === null, 'Unknown slug not found');

$hist = CmsPageService::versionHistoryForPage($pageId);
ok(count($hist) >= 2, 'Version history records created on create and publish');

CmsPageService::testThrowOnNextVersionInsert(true);
$rollback = CmsPageService::update($pageId, [
    'title' => 'AT-CMS13 Rollback ' . $token,
    'slug' => 'at-cms13-' . $token,
    'body_html' => '<p>Should rollback</p>',
], null, 'Rollback test');
ok($rollback['ok'] === false, 'Update fails when version insert throws');
$after = CmsPageRepository::findById($pageId);
ok((string) ($after['title'] ?? '') !== 'AT-CMS13 Rollback ' . $token, 'Transaction rolled back page row on version failure');

$dup1 = CmsPageService::create([
    'title' => 'AT-CMS13 Dup',
    'slug' => 'at-cms13-dup-base',
    'body_html' => '<p>One</p>',
]);
$dup2 = CmsPageService::create([
    'title' => 'AT-CMS13 Dup Two',
    'body_html' => '<p>Two</p>',
]);
ok($dup1['ok'] && $dup2['ok'], 'Auto slug from title when slug omitted');
$r1 = CmsPageRepository::findById((int) $dup1['id']);
$r2 = CmsPageRepository::findById((int) $dup2['id']);
ok($r1 && $r2 && (string) $r1['slug'] !== (string) $r2['slug'], 'Auto slug assigns unique slugs');
$dupReject = CmsPageService::create([
    'title' => 'AT-CMS13 Dup Reject',
    'slug' => 'at-cms13-dup-base',
    'body_html' => '<p>Reject</p>',
]);
ok($dupReject['ok'] === false && isset($dupReject['errors']['slug']), 'Explicit duplicate slug rejected');

$pdo->prepare('INSERT INTO faq_categories (name, slug, sort_order, is_active) VALUES (?, ?, 1, 1)')
    ->execute(['AT-CMS13 Cat', 'at-cms13-cat-' . $token]);
$catId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO faqs (category_id, question, answer, sort_order, is_active) VALUES (?, ?, ?, 1, 1)')
    ->execute([$catId, 'AT-CMS13-Q delivery?', 'Answer about delivery windows.']);
$activeCats = FaqRepository::activeCategories();
ok(count($activeCats) >= 1, 'FAQ active categories list');
$faqs = FaqRepository::activeFaqsByCategory($catId);
ok(count($faqs) === 1 && strpos((string) $faqs[0]['question'], 'AT-CMS13-Q') !== false, 'FAQ by category');
$search = FaqRepository::searchActive('delivery windows');
ok(count($search) >= 1, 'FAQ search active');

stage13_p1_cleanup($pdo);

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Stage 13 Phase 1 CMS foundation.\n";
exit(0);
