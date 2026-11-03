<?php

declare(strict_types=1);

/** Regression tests for centralized admin slug generation (CatalogueSlug). */
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
    echo ($c ? 'PASS' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

$tok = strtolower(bin2hex(random_bytes(3)));

// --- fromName examples (English) ---
t(CatalogueSlug::fromName('Mix Veg Curry Pack') === 'mix-veg-curry-pack', 'Mix Veg Curry Pack slug');
t(CatalogueSlug::fromName('Fresh Vegetables') === 'fresh-vegetables', 'Fresh Vegetables slug');
t(CatalogueSlug::fromName('Veg Pulao Mix') === 'veg-pulao-mix', 'Veg Pulao Mix slug');
t(CatalogueSlug::fromName('About Us') === 'about-us', 'About Us slug');

// Special characters
t(CatalogueSlug::fromName('  Hello!!!   World  ') === 'hello-world', 'Special chars collapsed');
t(CatalogueSlug::fromName('') === '', 'Empty name yields empty slug');

// Hindi / Unicode (intl transliteration)
$hindi = CatalogueSlug::fromName('प्याज और आलू');
t($hindi !== '' && $hindi !== 'item' && Validation::slug($hindi), 'Hindi title transliterates to valid slug');
t(strpos($hindi, 'pyaj') !== false || strpos($hindi, 'pyaja') !== false, 'Hindi slug contains pyaj transliteration');

// normalizeInput
t(CatalogueSlug::normalizeInput('My-Custom_Slug') === 'my-custom-slug', 'Manual slug normalized');

// Auto from empty slug on create (category)
$catA = CategoryRepository::save(null, [
    'name' => 'ZT Slug Auto ' . $tok,
    'slug' => '',
    'status' => 'published',
]);
t($catA['ok'] && Validation::slug((string) CategoryRepository::find((int) $catA['id'])['slug']), 'Category auto-generates slug when empty');

// Duplicate category slug rejected
$dupCat = CategoryRepository::save(null, [
    'name' => 'ZT Slug Dup',
    'slug' => (string) CategoryRepository::find((int) $catA['id'])['slug'],
    'status' => 'published',
]);
t(!$dupCat['ok'] && isset($dupCat['errors']['slug']), 'Duplicate category slug rejected');

// Edit same record keeps slug without manual change (explicit slug in POST)
$catId = (int) $catA['id'];
$existingSlug = (string) CategoryRepository::find($catId)['slug'];
$catEdit = CategoryRepository::save($catId, [
    'name' => 'ZT Slug Auto Renamed ' . $tok,
    'slug' => $existingSlug,
    'slug_manual' => false,
    'status' => 'published',
]);
t($catEdit['ok'] && CategoryRepository::find($catId)['slug'] === $existingSlug, 'Edit with unchanged slug field preserves slug');

// Manual slug
$manualSlug = 'zt-manual-cat-' . $tok;
$catManual = CategoryRepository::save($catId, [
    'name' => 'ZT Slug Auto Renamed ' . $tok,
    'slug' => $manualSlug,
    'slug_manual' => true,
    'status' => 'published',
]);
t($catManual['ok'] && CategoryRepository::find($catId)['slug'] === $manualSlug, 'Manual category slug saved');

// Product duplicate independence
$prodA = ProductRepository::saveGeneral(null, [
    'name' => 'ZT Prod ' . $tok,
    'slug' => 'zt-shared-' . $tok,
    'status' => 'draft',
]);
t($prodA['ok'], 'Product create with slug');
$prodDup = ProductRepository::saveGeneral(null, [
    'name' => 'ZT Prod 2',
    'slug' => 'zt-shared-' . $tok,
    'status' => 'draft',
]);
t(!$prodDup['ok'] && isset($prodDup['errors']['slug']), 'Duplicate product slug rejected');

// Same slug allowed on category vs product (different tables)
$prodCross = ProductRepository::saveGeneral(null, [
    'name' => 'ZT Cross',
    'slug' => $manualSlug,
    'status' => 'draft',
]);
t($prodCross['ok'], 'Product may reuse category slug (independent uniqueness)');

// CMS duplicate (via resolve when DOM sanitizer unavailable in CLI)
$cmsSlug = 'zt-cms-' . $tok;
$cmsResolve = CatalogueSlug::resolveForAdminSave('cms_page', 'ZT CMS ' . $tok, ['slug' => $cmsSlug], null, null);
t($cmsResolve['ok'], 'CMS slug resolves for new page');
if ($cmsResolve['ok']) {
    $pdo->prepare('INSERT INTO cms_pages (title, slug, body_html, status, show_in_footer, visibility) VALUES (?,?,?,?,0,?)')
        ->execute(['ZT CMS ' . $tok, $cmsSlug, '<p>x</p>', PublishStatus::DRAFT, 'public']);
    $cmsPageId = (int) $pdo->lastInsertId();
    $cmsDupRes = CatalogueSlug::resolveForAdminSave('cms_page', 'Other', ['slug' => $cmsSlug], null, null);
    t(!$cmsDupRes['ok'], 'Duplicate CMS slug rejected via resolveForAdminSave');
} else {
    $cmsPageId = 0;
    t(false, 'CMS slug resolves for new page (skipped dup test)');
}

// CMS slug change + SEO redirect (direct DB row, no sanitizer)
if ($cmsPageId > 0 && CmsPageService::schemaReady()) {
    $pageId = $cmsPageId;
    $pdo->prepare('UPDATE cms_pages SET status = ?, published_at = NOW() WHERE id = ?')->execute([PublishStatus::PUBLISHED, $pageId]);
    $oldPath = SeoRedirectPath::cmsPage($cmsSlug);
    $newSlug = 'zt-cms-new-' . $tok;
    $existing = CmsPageRepository::findById($pageId);
    $data = array_merge($existing, ['slug' => $newSlug, 'title' => 'ZT CMS ' . $tok]);
    Database::transaction(static function () use ($pageId, $existing, $data): void {
        $redirect = SeoRedirectService::registerCmsSlugChangeIfNeeded($existing, $data);
        if (!$redirect['ok']) {
            throw new RuntimeException('redirect failed');
        }
        CmsPageRepository::update($pageId, $data);
    });
    t((string) (CmsPageRepository::findById($pageId)['slug'] ?? '') === $newSlug, 'CMS explicit slug change saves');
    t(
        SeoRedirectService::resolveFinalDestination($oldPath) === SeoRedirectPath::cmsPage($newSlug),
        'CMS old URL redirects after slug change'
    );
}

// Category slug change redirect
$catOldPath = SeoRedirectPath::categoryPage($manualSlug);
$catNewSlug = 'zt-manual-cat-new-' . $tok;
$catSlugChange = CategoryRepository::save($catId, [
    'name' => 'ZT Cat redirect',
    'slug' => $catNewSlug,
    'slug_manual' => true,
    'status' => 'published',
]);
t($catSlugChange['ok'], 'Category slug change saves');
t(
    SeoRedirectService::resolveFinalDestination($catOldPath) === SeoRedirectPath::categoryPage($catNewSlug),
    'Category old URL redirects after slug change'
);

// UI wiring
$adminJs = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/admin.js');
t(strpos($adminJs, 'initAdminSlugSync') !== false, 'admin.js registers slug sync');
t(is_file(APNI_TOKRI_ROOT . '/admin/ajax/slug-from-name.php'), 'Slug AJAX endpoint exists');
t(strpos((string) file_get_contents(APNI_TOKRI_ROOT . '/admin/categories/edit.php'), 'data-at-admin-slug') !== false, 'Category form wired');
t(strpos((string) file_get_contents(APNI_TOKRI_ROOT . '/admin/products/edit.php'), 'data-at-admin-slug') !== false, 'Product form wired');
t(strpos((string) file_get_contents(APNI_TOKRI_ROOT . '/admin/cms/edit.php'), 'data-at-admin-slug') !== false, 'CMS form wired');

// Cleanup
$pdo->exec("DELETE FROM products WHERE slug LIKE 'zt-%' OR slug = " . $pdo->quote($manualSlug));
$pdo->exec("DELETE FROM categories WHERE slug LIKE 'zt-%'");
$pdo->exec("DELETE FROM cms_pages WHERE slug LIKE 'zt-cms%'");
$pdo->exec('DELETE FROM seo_redirects WHERE from_path LIKE ' . $pdo->quote('%zt-%'));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
