<?php

declare(strict_types=1);

/** Storefront category navigation markup: 1/2/3/4-level trees, hidden exclusion, accessibility hooks. */
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
function mk(string $name, ?int $parent, string $status = 'published'): array
{
    global $tok;
    $slug = 'zt-nv-' . trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($name)), '-') . '-' . $tok;
    $r = CategoryRepository::save(null, ['name' => $name, 'slug' => $slug, 'parent_id' => $parent === null ? '' : (string) $parent, 'status' => $status, 'sort_order' => 800]);
    if (!$r['ok']) {
        echo "fixture fail\n";
        exit(1);
    }

    return ['id' => (int) $r['id'], 'slug' => $slug];
}

/** Balanced <li>…</li> block whose first anchor links to the slug (desktop markup). */
function liBlock(string $html, string $slug): string
{
    $p = strpos($html, 'category.php?slug=' . $slug . '"');
    if ($p === false) {
        return '';
    }
    $start = strrpos(substr($html, 0, $p), '<li');
    $depth = 0;
    $len = strlen($html);
    for ($i = $start; $i < $len; $i++) {
        if (substr($html, $i, 3) === '<li' && ($html[$i + 3] === ' ' || $html[$i + 3] === '>')) {
            $depth++;
        } elseif (substr($html, $i, 5) === '</li>') {
            $depth--;
            if ($depth === 0) {
                return substr($html, $start, $i + 5 - $start);
            }
        }
    }

    return '';
}
/** The <a …>…</a> element for a slug. */
function anchor(string $html, string $slug): string
{
    $p = strpos($html, 'category.php?slug=' . $slug . '"');
    if ($p === false) {
        return '';
    }
    $s = strrpos(substr($html, 0, $p), '<a ');
    $e = strpos($html, '</a>', $p);

    return substr($html, $s, $e + 4 - $s);
}
/** Direct menu markup of a li block (everything after its own anchor). */
function submenu(string $li): string
{
    $p = strpos($li, '</a>');

    return $p === false ? '' : substr($li, $p + 4);
}

CatalogueGateway::reset();
t(app_catalogue() instanceof DbCatalogue, 'Database catalogue is active for nav tests');

$flat = mk('Flat One', null);
$two = mk('Two Parent', null);
$twoKid = mk('Two Kid', $two['id']);
$three = mk('Three Top', null);
$threeMid = mk('Three Mid', $three['id']);
$threeLeaf = mk('Three Leaf', $threeMid['id']);
$four = mk('Four Leaf', $threeLeaf['id']);
$hidKid = mk('Hidden Kid', $two['id'], 'hidden');
$hidTop = mk('Hidden Top', null, 'hidden');
$kidOfHidden = mk('Under Hidden', $hidTop['id']);

$tree = catalogue_nav_tree();
$desk = catalogue_render_desktop_nav($tree, '', false);

// 1-level
$flatLi = liBlock($desk, $flat['slug']);
t($flatLi !== '' && strpos($flatLi, '<ul') === false, '1-level: plain link, no dropdown');
t(strpos(anchor($desk, $flat['slug']), 'aria-haspopup') === false && strpos($flatLi, '<svg') === false, '1-level: no popup attribute or arrow');

// 2-level
$twoLi = liBlock($desk, $two['slug']);
t(strpos(submenu($twoLi), 'class="at-dnav__menu"') !== false && strpos(submenu($twoLi), 'category.php?slug=' . $twoKid['slug']) !== false, '2-level: child inside parent dropdown');
t(strpos(anchor($desk, $two['slug']), 'at-dnav__chev') !== false && strpos(anchor($desk, $two['slug']), 'aria-haspopup="true"') !== false, '2-level: top-level parent shows down chevron + aria-haspopup');
t(strpos(anchor($desk, $two['slug']), 'href="') !== false && strpos(anchor($desk, $two['slug']), 'category.php?slug=' . $two['slug']) !== false, 'Parent remains a clickable category link');
t(strpos($desk, '<li class="at-dnav__item" data-depth="0"><a class="at-dnav__link at-focusable" href="' . url_base() . '/category.php?slug=' . $twoKid['slug']) === false, 'Child is not a top-level nav item');
t(strpos(anchor($desk, $twoKid['slug']), '<svg') === false, '2-level: leaf child has no arrow');

// 3-level
$midLi = liBlock($desk, $threeMid['slug']);
t(strpos(anchor($desk, $threeMid['slug']), 'at-dnav__arrow') !== false, '3-level: child with descendants shows right-facing arrow');
t(strpos(submenu($midLi), 'at-dnav__menu at-dnav__menu--nested') !== false && strpos(submenu($midLi), 'category.php?slug=' . $threeLeaf['slug']) !== false, '3-level: nested submenu holds grandchild');
t(strpos(submenu(liBlock($desk, $threeLeaf['slug'])), 'category.php?slug=' . $four['slug']) !== false, '4 levels also render (no depth cap)');
t(strpos(anchor($desk, $four['slug']), '<svg') === false, 'Deepest leaf has no arrow');
t(strpos($midLi, 'data-depth="1"') !== false && strpos($midLi, 'data-depth="2"') !== false, 'Depth markers present per level');

// Hidden exclusion
t(strpos($desk, $hidKid['slug']) === false, 'Hidden child absent from desktop nav');
t(strpos($desk, $hidTop['slug']) === false && strpos($desk, $kidOfHidden['slug']) === false, 'Hidden parent and its children absent from desktop nav');

// Active / in-path
$deskActive = catalogue_render_desktop_nav($tree, $threeLeaf['slug'], true);
t(strpos(anchor($deskActive, $threeLeaf['slug']), 'is-active') !== false && strpos(anchor($deskActive, $threeLeaf['slug']), 'aria-current="page"') !== false, 'Active category marked is-active + aria-current');
t(strpos(anchor($deskActive, $three['slug']), 'is-in-path') !== false, 'Top-level ancestor highlighted as in-path');

// Mobile accordion
$mob = catalogue_render_mobile_nav($tree, '', false);
t(substr_count($mob, 'data-at-macc-toggle') >= 4, 'Mobile: toggle buttons for parents at every level');
t(substr_count($mob, 'class="at-macc__panel"') === substr_count($mob, 'class="at-macc__panel" id=') && substr_count($mob, ' hidden>') === substr_count($mob, 'at-macc__panel'), 'Mobile: all panels collapsed by default');
t(preg_match('/<button[^>]*aria-expanded="false"[^>]*aria-controls="at-macc-\d+"/', $mob) === 1, 'Mobile: toggle exposes aria-expanded + aria-controls');
t(strpos(anchor($mob, $three['slug']), 'at-macc__link') !== false, 'Mobile: parent link navigates separately from toggle');
t(preg_match('/<div class="at-macc" style="--d:2">.{0,400}?slug=' . preg_quote($threeLeaf['slug'], '/') . '"/s', $mob) === 1, 'Mobile: third level carries depth indentation var');
t(strpos($mob, 'min-height') === false && strpos((string) file_get_contents(APNI_TOKRI_ROOT . '/assets/css/apni-tokri.css'), 'width: 2.75rem') !== false, 'Mobile: toggle is 44px+ touch target');
t(strpos($mob, $hidKid['slug']) === false && strpos($mob, $hidTop['slug']) === false && strpos($mob, $kidOfHidden['slug']) === false, 'Hidden categories absent from mobile nav');
$mobActive = catalogue_render_mobile_nav($tree, $threeLeaf['slug'], true);
t(preg_match('/aria-expanded="true"/', $mobActive) === 1 && substr_count($mobActive, 'aria-expanded="true"') === 2, 'Mobile: ancestors of current page auto-expanded (2 levels)');

// Escaping
mk('Evil <script>x</script> "q"', null);
$both = catalogue_render_desktop_nav(catalogue_nav_tree(), '', false) . catalogue_render_mobile_nav(catalogue_nav_tree(), '', false);
t(strpos($both, '<script>x</script>') === false && strpos($both, '&lt;script&gt;') !== false, 'Category names are HTML-escaped in nav');

// Wiring
$hdr = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/layout/header-public.php');
t(strpos($hdr, 'catalogue_render_desktop_nav') !== false && strpos($hdr, 'catalogue_render_mobile_nav') !== false, 'Header uses nested desktop + mobile renderers');
$css = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/css/apni-tokri.css');
t(preg_match('/\.at-dnav__menu\s*\{[^}]*z-index:\s*60/s', $css) === 1, 'Dropdown z-index defined');
t(strpos($css, '.at-dnav__item.is-flip') !== false && strpos($css, '.at-dnav__menu--nested') !== false, 'CSS has nested menu + viewport flip rules');
t(preg_match('/\.at-dnav\s*\{[^}]*overflow/s', $css) === 0, 'Desktop menu container sets no overflow (no clipping)');
t(strpos($css, 'max-width: min(19rem, calc(100vw - 2rem))') !== false, 'Dropdown width capped to viewport');
$js = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/apni-tokri.js');
t(strpos($js, 'data-at-macc-toggle') !== false && strpos($js, 'positionDnavMenu') !== false, 'JS: accordion toggle + viewport flip implemented');

// Live page
$live = @file_get_contents('http://127.0.0.1/Apni_Tokri/index.php');
if ($live !== false && $live !== '') {
    t(strpos($live, 'data-at-dnav') !== false && strpos($live, 'at-macc-list') !== false, 'Live homepage renders new nav containers');
    t(strpos($live, 'slug=pickles"') === false, 'Live homepage nav excludes hidden Pickles & Papad');
    t(strpos($live, $three['slug']) !== false && strpos($live, $four['slug']) !== false, 'Live homepage nav includes 4-level fixture tree');
}

$pdo->exec("DELETE FROM categories WHERE slug LIKE 'zt-nv-%'");
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
