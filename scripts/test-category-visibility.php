<?php

declare(strict_types=1);

/** Category visibility + parent/child hierarchy regression tests. Fixtures use slug prefix zt-cv- and are removed. */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

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
$_SESSION['admin_user_id'] = 1;

function mk(string $name, ?int $parent, string $status, int $home, int $sort = 500): int
{
    global $tok;
    $slug = 'zt-cv-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)) . '-' . $tok;
    $r = CategoryRepository::save(null, ['name' => $name, 'slug' => $slug, 'parent_id' => $parent === null ? '' : (string) $parent, 'status' => $status, 'show_on_homepage' => $home ? '1' : null, 'sort_order' => $sort]);
    if (!$r['ok']) {
        echo 'fixture failed: ' . json_encode($r) . "\n";
        exit(1);
    }

    return (int) $r['id'];
}
function slugOf(int $id): string
{
    return (string) (CategoryRepository::find($id)['slug'] ?? '');
}
function flatSlugs(array $nodes): array
{
    $o = [];
    foreach ($nodes as $n) {
        $o[] = $n['slug'];
        $o = array_merge($o, flatSlugs($n['children'] ?? []));
    }

    return $o;
}

// Products/category relationships snapshot (must not change)
$relBefore = $pdo->query('SELECT id, category_id FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

$visParent = mk('Parent A', null, 'published', 1);
$visChild = mk('Child A1', $visParent, 'published', 0);
$visGrand = mk('Grand A1a', $visChild, 'published', 1);
$hidChild = mk('Child A2 hidden', $visParent, 'hidden', 1);
$hidTop = mk('Hidden Top', null, 'hidden', 1);
$kidOfHidden = mk('Kid of hidden', $hidTop, 'published', 1);
$hidHomeOff = mk('Visible No Home', null, 'published', 0);

$tree = CategoryRepository::navigationTree();
$navSlugs = flatSlugs($tree);
$topSlugs = array_column($tree, 'slug');

// Navigation
t(!in_array(slugOf($hidTop), $navSlugs, true), 'Hidden category excluded from navigation');
t(in_array(slugOf($visParent), $topSlugs, true), 'Active parent appears as top-level nav item');
t(!in_array(slugOf($visChild), $topSlugs, true), 'Child is not a top-level nav item');
$parentNode = null;
foreach ($tree as $n) {
    if ($n['slug'] === slugOf($visParent)) {
        $parentNode = $n;
    }
}
$childSlugs = array_column($parentNode['children'] ?? [], 'slug');
t(in_array(slugOf($visChild), $childSlugs, true), 'Active child rendered under correct parent');
t(!in_array(slugOf($hidChild), $childSlugs, true), 'Hidden child excluded from active parent');
$grandSlugs = array_column($parentNode['children'][0]['children'] ?? [], 'slug');
t(in_array(slugOf($visGrand), $grandSlugs, true), 'Multi-level hierarchy supported (grandchild under child)');
t(!in_array(slugOf($kidOfHidden), $navSlugs, true), 'Hidden parent hides its published children from navigation');

// Homepage
$home = array_column(CategoryRepository::homepage(), 'slug');
t(!in_array(slugOf($hidTop), $home, true), 'Hidden category excluded from homepage even with homepage checked');
t(!in_array(slugOf($hidChild), $home, true), 'Hidden child excluded from homepage even with homepage checked');
t(!in_array(slugOf($kidOfHidden), $home, true), 'Child of hidden parent excluded from homepage');
t(!in_array(slugOf($hidHomeOff), $home, true), 'Visible category with homepage unchecked excluded from homepage');
t(in_array(slugOf($visParent), $home, true), 'Visible + homepage checked appears on homepage');
t(in_array(slugOf($hidHomeOff), $navSlugs, true), 'Homepage unchecked does not remove category from navigation');

// Gateway / direct URL
$cat = new DbCatalogue();
t($cat->category(slugOf($hidTop)) === null, 'Direct URL to hidden category returns not found');
t($cat->category(slugOf($kidOfHidden)) === null, 'Direct URL to child of hidden parent returns not found');
t($cat->category(slugOf($hidChild)) === null, 'Direct URL to hidden child returns not found');
t($cat->category(slugOf($visChild)) !== null, 'Direct URL to active child works');
t(!in_array(slugOf($hidTop), array_column($cat->categories(), 'slug'), true), 'Public category listing excludes hidden');
t(CategoryRepository::findBySlug(slugOf($hidTop)) !== null, 'Admin lookup still finds hidden category');
t(in_array(slugOf($hidTop), array_column(CategoryRepository::allForAdmin(), 'slug'), true), 'Admin list still includes hidden category');
$pubChild = $cat->category(slugOf($visChild));
t($pubChild !== null && (int) $pubChild['parent_id'] === $visParent, 'Public category exposes parent_id');
t(array_column(CategoryRepository::visibleChildren($visParent), 'slug') === [slugOf($visChild)], 'Parent page child list: only visible direct children');
t(array_column(CategoryRepository::ancestors($visGrand), 'slug') === [slugOf($visParent), slugOf($visChild)], 'Breadcrumb ancestors ordered root first');

// Hidden parent only: toggling parent hides subtree then restores
CategoryRepository::save($visParent, ['name' => 'Parent A', 'slug' => slugOf($visParent), 'status' => 'hidden', 'sort_order' => 500]);
$navAfter = flatSlugs(CategoryRepository::navigationTree());
t(!in_array(slugOf($visParent), $navAfter, true) && !in_array(slugOf($visChild), $navAfter, true) && !in_array(slugOf($visGrand), $navAfter, true), 'Hidden parent and all descendants excluded');
t($cat->category(slugOf($visGrand)) === null, 'Direct URL to descendant of hidden parent returns not found');
CategoryRepository::save($visParent, ['name' => 'Parent A', 'slug' => slugOf($visParent), 'status' => 'published', 'show_on_homepage' => '1', 'sort_order' => 500]);
t(in_array(slugOf($visChild), flatSlugs(CategoryRepository::navigationTree()), true), 'Re-publishing parent restores children');

// Admin parent validation
$self = CategoryRepository::save($visParent, ['name' => 'Parent A', 'slug' => slugOf($visParent), 'parent_id' => (string) $visParent, 'status' => 'published']);
t(!$self['ok'] && isset($self['errors']['parent_id']), 'Category cannot be its own parent');
$desc = CategoryRepository::save($visParent, ['name' => 'Parent A', 'slug' => slugOf($visParent), 'parent_id' => (string) $visGrand, 'status' => 'published']);
t(!$desc['ok'] && isset($desc['errors']['parent_id']), 'Category cannot be moved under its descendant');
t(CategoryRepository::find($visParent)['parent_id'] === null, 'Rejected parent change leaves data unchanged');
$descIds = CategoryRepository::descendantIds($visParent);
t(in_array($visChild, $descIds, true) && in_array($visGrand, $descIds, true) && !in_array($visParent, $descIds, true), 'descendantIds returns all levels');

// Empty parent behaves consistently
$emptyParent = mk('Empty Parent', null, 'published', 0);
t(in_array(slugOf($emptyParent), array_column(CategoryRepository::navigationTree(), 'slug'), true) && $cat->category(slugOf($emptyParent)) !== null, 'Empty published parent is still navigable');

// Static checks: nav no longer hard-coded
$hdr = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/layout/header-public.php');
t(strpos($hdr, "'pickles' => ['en'") === false && strpos($hdr, 'catalogue_nav_tree') !== false, 'Header navigation is catalogue-driven (no hard-coded category list)');
t(strpos((string) file_get_contents(APNI_TOKRI_ROOT . '/index.php'), 'catalogue_homepage_categories') !== false, 'Homepage uses homepage-category query');

// Slugs & product assignments preserved
$relAfter = $pdo->query('SELECT id, category_id FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
t($relBefore === $relAfter, 'Existing product/category assignments unchanged');
t(CategoryRepository::findBySlug('pickles') !== null && CategoryRepository::findBySlug('pickles', true) === null || (string) CategoryRepository::findBySlug('pickles')['status'] === 'published', 'Existing slug (pickles) preserved; hidden stays hidden publicly');

// Cleanup
$pdo->exec("DELETE FROM categories WHERE slug LIKE 'zt-cv-%'");
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
