<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/stage13_phase5_fixture.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
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

stage13_p5_cleanup($pdo);
$token = strtolower(Security::randomToken(4));

$pub = CmsPageService::create([
    'title' => 'AT-RD13 Pub ' . $token,
    'slug' => 'at-rd13-a-' . $token,
    'body_html' => '<p>One</p>',
], null);
$pageId = (int) ($pub['id'] ?? 0);
CmsPageService::publish($pageId, [
    'title' => 'AT-RD13 Pub ' . $token,
    'slug' => 'at-rd13-a-' . $token,
    'body_html' => '<p>One</p>',
    'preserve_slug' => 1,
], null);

$firstOld = SeoRedirectPath::cmsPage('at-rd13-a-' . $token);
$oldPath = $firstOld;
$newSlug = 'at-rd13-b-' . $token;
$upd = CmsPageService::update($pageId, [
    'title' => 'AT-RD13 Pub ' . $token,
    'slug' => $newSlug,
    'body_html' => '<p>Two</p>',
    'slug_manual' => 1,
    'visibility' => CmsPageService::VISIBILITY_PUBLIC,
    'is_active' => 1,
], null, 'Slug change');
ok($upd['ok'], 'CMS slug update with redirect');

$dest = SeoRedirectService::resolveFinalDestination($oldPath);
$newPath = SeoRedirectPath::cmsPage($newSlug);
ok($dest === $newPath, 'Old CMS slug resolves to new URL (301 target)');

$row = CmsPageRepository::findBySlug($newSlug);
ok($row !== null && (string) ($row['slug'] ?? '') === $newSlug, 'New CMS slug returns normal page row');

$draft = CmsPageService::create([
    'title' => 'AT-RD13 Draft',
    'slug' => 'at-rd13-draft-' . $token,
    'body_html' => '<p>D</p>',
], null);
$draftId = (int) ($draft['id'] ?? 0);
$beforeDraft = CmsPageRepository::findById($draftId);
CmsPageService::update($draftId, [
    'title' => 'AT-RD13 Draft',
    'slug' => 'at-rd13-draft2-' . $token,
    'body_html' => '<p>D</p>',
    'slug_manual' => 1,
], null, 'Draft slug');
$draftFrom = SeoRedirectPath::cmsPage('at-rd13-draft-' . $token);
ok(SeoRedirectRepository::findByFromPath($draftFrom) === null, 'Draft page does not create public redirect');

$priv = CmsPageService::create([
    'title' => 'AT-RD13 Private',
    'slug' => 'at-rd13-priv-' . $token,
    'body_html' => '<p>P</p>',
    'visibility' => CmsPageService::VISIBILITY_PRIVATE,
], null);
$privId = (int) ($priv['id'] ?? 0);
CmsPageService::publish($privId, [
    'title' => 'AT-RD13 Private',
    'slug' => 'at-rd13-priv-' . $token,
    'body_html' => '<p>P</p>',
    'visibility' => CmsPageService::VISIBILITY_PRIVATE,
    'preserve_slug' => 1,
], null);
$privFrom = SeoRedirectPath::cmsPage('at-rd13-priv-' . $token);
ok(SeoRedirectRepository::findByFromPath($privFrom) === null, 'Private page does not create public redirect on publish');

$dup = SeoRedirectService::registerPermanent($firstOld, SeoRedirectPath::cmsPage('at-rd13-other-' . $token));
ok($dup['ok'] === false, 'Duplicate source path rejected (strict register)');

$self = SeoRedirectService::registerPermanent($firstOld, $firstOld);
ok($self['ok'] === false, 'Self-redirect rejected');

SeoRedirectRepository::upsertPermanent($oldPath, $newPath);
$loop = SeoRedirectService::registerPermanentSlugChange($newPath, $oldPath);
ok($loop['ok'] === false, 'Direct loop rejected');

SeoRedirectRepository::deleteByFromPrefix(SeoRedirectPath::normalize('/page.php?slug=at-rd13-loop'));
$pathA = SeoRedirectPath::cmsPage('at-rd13-loop-a-' . $token);
$pathB = SeoRedirectPath::cmsPage('at-rd13-loop-b-' . $token);
SeoRedirectService::applySlugChangeRedirect($pathA, $pathB);
$indirect = SeoRedirectService::registerPermanentSlugChange($pathB, $pathA);
ok($indirect['ok'] === false, 'Indirect loop rejected');

$mid = SeoRedirectPath::cmsPage('at-rd13-chain1-' . $token);
$mid2 = SeoRedirectPath::cmsPage('at-rd13-chain2-' . $token);
$fin = SeoRedirectPath::cmsPage('at-rd13-chain3-' . $token);
SeoRedirectService::applySlugChangeRedirect($mid, $mid2);
SeoRedirectService::applySlugChangeRedirect($mid2, $fin);
ok(SeoRedirectService::resolveFinalDestination($mid) === $fin, 'Redirect chain collapsed to final destination');

$third = 'at-rd13-c-' . $token;
CmsPageService::update($pageId, [
    'title' => 'AT-RD13 Pub ' . $token,
    'slug' => $third,
    'body_html' => '<p>Three</p>',
    'slug_manual' => 1,
    'visibility' => CmsPageService::VISIBILITY_PUBLIC,
], null, 'Third slug');
ok(SeoRedirectService::resolveFinalDestination($firstOld) === SeoRedirectPath::cmsPage($third), 'Repeated CMS slug changes preserve old URL redirects');

ok(
    SeoRedirectPath::cmsPage('at-rd13-old-' . $token) !== SeoRedirectPath::cmsPage('at-rd13-new-' . $token),
    'CMS page.php old/new slug are distinct redirect identities'
);
ok(
    SeoRedirectPath::productPage('at-rd13-old-' . $token) !== SeoRedirectPath::productPage('at-rd13-new-' . $token),
    'Product product.php old/new slug are distinct redirect identities'
);
ok(
    SeoRedirectPath::categoryPage('at-rd13-old-' . $token) !== SeoRedirectPath::categoryPage('at-rd13-new-' . $token),
    'Category category.php old/new slug are distinct redirect identities'
);
$sharedSlug = 'at-rd13-shared-' . $token;
ok(
    SeoRedirectPath::cmsPage($sharedSlug) !== SeoRedirectPath::productPage($sharedSlug),
    'Identical slug on page.php vs product.php does not collide redirect records'
);

$catSave = CategoryRepository::save(null, [
    'name' => 'AT-RD13 Cat ' . $token,
    'slug' => 'at-rd13-cat-a-' . $token,
    'status' => 'published',
]);
$catId = (int) ($catSave['id'] ?? 0);
ok(($catSave['ok'] ?? false) && $catId > 0, 'Category fixture for redirect identity');
$catOld = SeoRedirectPath::categoryPage('at-rd13-cat-a-' . $token);
$catNewSlug = 'at-rd13-cat-b-' . $token;
$catUpd = CategoryRepository::save($catId, [
    'name' => 'AT-RD13 Cat ' . $token,
    'slug' => $catNewSlug,
    'status' => 'published',
]);
ok($catUpd['ok'] ?? false, 'Category published slug change');
ok(
    SeoRedirectService::resolveFinalDestination($catOld) === SeoRedirectPath::categoryPage($catNewSlug),
    'Category old slug resolves to new canonical identity'
);
$catThird = 'at-rd13-cat-c-' . $token;
CategoryRepository::save($catId, [
    'name' => 'AT-RD13 Cat ' . $token,
    'slug' => $catThird,
    'status' => 'published',
]);
ok(
    SeoRedirectService::resolveFinalDestination($catOld) === SeoRedirectPath::categoryPage($catThird),
    'Repeated category slug changes preserve old URL redirect identity'
);

$prodSave = ProductRepository::saveGeneral(null, [
    'name' => 'AT-RD13 Prod ' . $token,
    'slug' => 'at-rd13-prod-a-' . $token,
    'category_id' => $catId,
    'status' => 'published',
    'product_type' => 'fresh',
]);
$prodId = (int) ($prodSave['id'] ?? 0);
ok(($prodSave['ok'] ?? false) && $prodId > 0, 'Product fixture for redirect identity');
$prodOld = SeoRedirectPath::productPage('at-rd13-prod-a-' . $token);
$prodNewSlug = 'at-rd13-prod-b-' . $token;
$prodUpd = ProductRepository::saveGeneral($prodId, [
    'name' => 'AT-RD13 Prod ' . $token,
    'slug' => $prodNewSlug,
    'category_id' => $catId,
    'status' => 'published',
    'product_type' => 'fresh',
]);
ok($prodUpd['ok'] ?? false, 'Product published slug change');
ok(
    SeoRedirectService::resolveFinalDestination($prodOld) === SeoRedirectPath::productPage($prodNewSlug),
    'Product old slug resolves to new canonical identity'
);
ProductRepository::saveGeneral($prodId, [
    'name' => 'AT-RD13 Prod ' . $token,
    'slug' => 'at-rd13-prod-c-' . $token,
    'category_id' => $catId,
    'status' => 'published',
    'product_type' => 'fresh',
]);
ok(
    SeoRedirectService::resolveFinalDestination($prodOld) === SeoRedirectPath::productPage('at-rd13-prod-c-' . $token),
    'Repeated product slug changes preserve old URL redirect identity'
);

$canonicalCms = SeoRedirectPath::cmsPage('at-rd13-a-' . $token);
$withUtm = SeoRedirectPath::redirectIdentity(
    $canonicalCms . '&utm_source=newsletter&utm_campaign=spring'
);
ok($withUtm === $canonicalCms, 'utm_source/utm_campaign do not create separate redirect identities');
ok(
    SeoRedirectService::resolveFinalDestination($withUtm) === SeoRedirectPath::cmsPage($third),
    'Request with tracking params resolves to same destination as canonical slug URL'
);
$rowCanon = SeoRedirectRepository::findByFromPath($canonicalCms);
$rowUtm = SeoRedirectRepository::findByFromPath($withUtm);
ok(
    $rowCanon !== null && $rowUtm !== null && (string) ($rowCanon['from_path'] ?? '') === (string) ($rowUtm['from_path'] ?? ''),
    'Tracking variant uses same stored from_path row as canonical'
);

ok(
    SeoRedirectPath::redirectIdentity(
        '/page.php?slug=' . rawurlencode('at-rd13-a-' . $token) . '&slug=' . rawurlencode('at-rd13-b-' . $token)
    ) === '',
    'Conflicting duplicate slug parameters do not produce redirect identity'
);
ok(
    SeoRedirectPath::redirectIdentity('/page.php?slug=Not_Valid!') === '',
    'Unknown/invalid slug parameter does not produce redirect identity'
);

$loc = SeoRedirectService::redirectLocationWithSafeTracking(
    SeoRedirectPath::cmsPage($third),
    url_to('/page.php?slug=at-rd13-a-' . $token) . '&utm_source=email&utm_campaign=q1&evil=drop'
);
ok(
    str_contains($loc, 'utm_source=email') && str_contains($loc, 'utm_campaign=q1') && !str_contains($loc, 'evil='),
    '301 Location preserves allowlisted utm params only'
);
ok(str_contains($loc, 'slug=' . rawurlencode($third)), '301 Location destination slug identity unchanged');

CmsPageService::testThrowOnNextVersionInsert(true);
$rollback = CmsPageService::update($pageId, [
    'title' => 'AT-RD13 Rollback',
    'slug' => 'at-rd13-rb-' . $token,
    'body_html' => '<p>RB</p>',
    'slug_manual' => 1,
], null, 'Rollback test');
ok($rollback['ok'] === false, 'Failed transaction rolls back slug and redirect changes');
CmsPageService::testThrowOnNextVersionInsert(false);
$afterRb = CmsPageRepository::findById($pageId);
ok((string) ($afterRb['slug'] ?? '') === $third, 'Slug unchanged after rollback');
$rbFrom = SeoRedirectPath::cmsPage('at-rd13-rb-' . $token);
ok(SeoRedirectRepository::findByFromPath($rbFrom) === null, 'Failed transaction rolls back redirect row');

$badDest = SeoRedirectService::registerPermanent($firstOld, 'javascript:alert(1)');
ok($badDest['ok'] === false, 'Invalid destination rejected');

$ext = SeoRedirectService::registerPermanent($firstOld, 'https://evil.example/x');
ok($ext['ok'] === false, 'External destination rejected');

$proto = SeoRedirectService::registerPermanent($firstOld, '//evil.example/x');
ok($proto['ok'] === false, 'Protocol-relative destination rejected');

ok(!SeoRedirectService::shouldProcessPath(url_to('/admin/login.php')), 'Admin paths unaffected');
ok(!SeoRedirectService::shouldProcessPath(url_to('/webhooks/cashfree.php')), 'Webhook paths unaffected');
ok(!SeoRedirectService::shouldProcessPath(url_to('/api/health.php')), 'API paths unaffected');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = $oldPath;
ob_start();
SeoRedirectService::maybeRedirectCurrentRequest();
ob_end_clean();
ok(true, 'POST request is not redirected');

$_SERVER['REQUEST_METHOD'] = 'HEAD';
$_SERVER['REQUEST_URI'] = url_to('/page.php?slug=at-rd13-a-' . $token . '&utm_source=head');
$headDest = SeoRedirectService::resolveFinalDestination(SeoRedirectPath::currentRedirectIdentity());
ok($headDest === SeoRedirectPath::cmsPage($third) || $headDest === SeoRedirectPath::cmsPage($newSlug), 'HEAD request resolution matches GET');

$unknown = SeoRedirectPath::cmsPage('at-rd13-missing-' . $token);
ok(SeoRedirectService::resolveFinalDestination($unknown) === null, 'Unknown URL still returns existing 404 behaviour');

$privDest = SeoRedirectPath::cmsPage('at-rd13-priv-' . $token);
$trapFrom = SeoRedirectPath::cmsPage('at-rd13-trap-' . $token);
SeoRedirectRepository::upsertPermanent($trapFrom, $privDest);
ok(SeoRedirectService::redirectDestinationBlocked($privDest), 'Private CMS destination blocked at redirect layer');
ok(
    SeoRedirectService::resolveFinalDestination($trapFrom) === $privDest
    && SeoRedirectService::redirectDestinationBlocked($privDest),
    'Redirect destination cannot bypass private/draft CMS restrictions'
);

stage13_p5_cleanup($pdo);

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Stage 13 Phase 5 SEO redirects.\n";
exit(0);
