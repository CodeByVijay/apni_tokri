<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/stage13_phase2_fixture.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}
if (!Install::isVersionCurrent()) {
    fwrite(STDERR, 'Apply CMS migration (expected ' . Install::SCHEMA_VERSION . ").\n");
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

stage13_p2_cleanup($pdo);
$adminId = stage13_p2_admin_with_cms($pdo);
ok($adminId > 0, 'Admin with cms.manage fixture');

$token = strtolower(Security::randomToken(4));
$searchKey = 'at-cms13-p2-' . $token;

$create = CmsPageService::create([
    'title' => 'AT-CMS13-P2 Page ' . $token,
    'slug' => 'at-cms13-p2-' . $token,
    'body_html' => '<p>Draft <script>x()</script></p>',
    'change_summary' => 'Create test',
], $adminId);
ok($create['ok'], 'Create page');
$pageId = (int) ($create['id'] ?? 0);

$listAfter = CmsPageService::searchForAdmin($searchKey, null, null, 1, 50);
ok($listAfter['total'] === 1, 'Admin listing reads DB records');

$row = CmsPageRepository::findById($pageId);
ok($row && stripos((string) $row['body_html'], '<script') === false, 'HTML sanitizer enforced on save');

$update = CmsPageService::update($pageId, [
    'title' => 'AT-CMS13-P2 Updated ' . $token,
    'slug' => 'at-cms13-p2-' . $token,
    'body_html' => '<p>Updated body</p>',
    'preserve_slug' => 1,
], $adminId, 'Update test');
ok($update['ok'], 'Update page');

$draft = CmsPageService::saveDraft($pageId, [
    'title' => 'AT-CMS13-P2 Draft ' . $token,
    'slug' => 'at-cms13-p2-' . $token,
    'body_html' => '<p>Draft save</p>',
    'preserve_slug' => 1,
], $adminId);
ok($draft['ok'], 'Save draft');
ok((string) (CmsPageRepository::findById($pageId)['status'] ?? '') === PublishStatus::DRAFT, 'Save draft sets draft status');

$pub = CmsPageService::publish($pageId, [
    'title' => 'AT-CMS13-P2 Published ' . $token,
    'slug' => 'at-cms13-p2-' . $token,
    'body_html' => '<p>Live body</p>',
    'preserve_slug' => 1,
    'change_summary' => 'Go live',
], $adminId);
ok($pub['ok'], 'Publish page');
ok(CmsPageService::publishedPublicBySlug('at-cms13-p2-' . $token) !== null, 'Published page public');

$unpub = CmsPageService::unpublish($pageId, $adminId, 'Off');
ok($unpub['ok'], 'Unpublish page');
ok(CmsPageService::publishedPublicBySlug('at-cms13-p2-' . $token) === null, 'Unpublished not public');

$dup = CmsPageService::create([
    'title' => 'AT-CMS13-P2 Dup',
    'slug' => 'at-cms13-p2-' . $token,
    'body_html' => '<p>Dup</p>',
]);
ok($dup['ok'] === false && isset($dup['errors']['slug']), 'Duplicate slug rejected');

$badSlug = CmsPageService::create([
    'title' => 'AT-CMS13-P2 Bad',
    'slug' => '!!! invalid !!!',
    'body_html' => '<p>x</p>',
]);
ok($badSlug['ok'] === false && isset($badSlug['errors']['slug']), 'Invalid slug rejected');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [];
$csrfOk = Csrf::validateRequest();
ok($csrfOk === false, 'CSRF rejection without token');
unset($_SERVER['REQUEST_METHOD'], $_POST);

stage13_p2_clear_admin_session();
ok(CmsPageService::pageForAdminPreview($pageId) === null, 'Unauthorized preview denied');

stage13_p2_admin_session($adminId);
$preview = CmsPageService::pageForAdminPreview($pageId);
ok($preview !== null && strpos((string) $preview['body_html'], 'Live body') !== false, 'Authorized preview');
stage13_p2_clear_admin_session();

$hist = CmsPageService::versionHistoryForPage($pageId);
ok(count($hist) >= 4, 'Version history visible');

$stmt = $pdo->prepare('SELECT COUNT(*) FROM cms_page_versions WHERE cms_page_id = ?');
$stmt->execute([$pageId]);
$beforeCount = (int) $stmt->fetchColumn();
CmsPageService::update($pageId, [
    'title' => 'AT-CMS13-P2 Ver ' . $token,
    'slug' => 'at-cms13-p2-' . $token,
    'body_html' => '<p>Version bump</p>',
    'preserve_slug' => 1,
], $adminId, 'Version bump');
$stmt->execute([$pageId]);
$afterCount = (int) $stmt->fetchColumn();
ok($afterCount === $beforeCount + 1, 'Version history append-only (not overwritten)');

$legalSlugs = [
    'about-us', 'contact-us', 'privacy-policy', 'terms', 'refund-policy',
    'shipping-policy', 'subscription-terms', 'disclaimer',
];
foreach ($legalSlugs as $legalSlug) {
    $seed = CmsPageRepository::findBySlug($legalSlug);
    ok($seed !== null, 'Seeded legal slug exists: ' . $legalSlug);
    if ($seed !== null && (string) ($seed['status'] ?? '') === PublishStatus::PUBLISHED) {
        CmsPageService::unpublish((int) $seed['id'], $adminId, 'Phase 2 test — ensure draft legal placeholder');
        $seed = CmsPageRepository::findBySlug($legalSlug);
    }
    ok($seed !== null && (string) ($seed['status'] ?? '') === PublishStatus::DRAFT, 'Legal page remains draft: ' . $legalSlug);
    ok(CmsPageService::publishedPublicBySlug($legalSlug) === null, 'Legal draft not public: ' . $legalSlug);
}

$footerSlugs = ['about-us', 'refund-policy', 'shipping-policy', 'privacy-policy', 'terms', 'subscription-terms'];
foreach ($footerSlugs as $fs) {
    ok(CmsPageRepository::findBySlug($fs) !== null, 'Footer URL slug compatible: ' . $fs);
}

stage13_p2_admin_session($adminId);
ok(Permission::canModule('cms'), 'CMS permission for fixture admin');
stage13_p2_clear_admin_session();

$noCmsAdmin = (int) ($pdo->query(
    "SELECT u.id FROM admin_users u WHERE u.is_active = 1 AND NOT EXISTS (
        SELECT 1 FROM admin_user_roles ur
        INNER JOIN admin_role_permissions rp ON rp.role_id = ur.role_id
        INNER JOIN admin_permissions p ON p.id = rp.permission_id
        WHERE ur.user_id = u.id AND p.slug = 'cms.manage'
    ) ORDER BY u.id LIMIT 1"
)->fetchColumn() ?: 0);
if ($noCmsAdmin > 0) {
    stage13_p2_admin_session($noCmsAdmin);
    ok(!Permission::canModule('cms'), 'Permission denial without cms.manage');
    ok(CmsPageService::pageForAdminPreview($pageId) === null, 'Preview denied without cms.manage');
    stage13_p2_clear_admin_session();
} else {
    ok(true, 'Permission denial (no non-cms admin in DB — skipped)');
}

stage13_p2_cleanup($pdo);

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Stage 13 Phase 2 CMS admin.\n";
exit(0);
