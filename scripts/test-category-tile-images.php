<?php

declare(strict_types=1);

/** Category tile helper, homepage filter, cover image path persistence (disposable DB). */
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
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

$tok = strtolower(bin2hex(random_bytes(3)));

// Helper: legacy slug map unchanged.
$veg = catalogue_category_tile_image(['slug' => 'vegetables']);
t(strpos($veg, 'p-tomato-2.jpg') !== false, 'Legacy vegetables slug uses p-tomato-2.jpg');

$unknown = catalogue_category_tile_image(['slug' => 'zt-unknown-' . $tok]);
t(strpos($unknown, 'placeholder-category.svg') !== false, 'Unknown slug uses neutral placeholder (not p-tomato-1)');

$dbPath = 'assets/images/categories/test-' . $tok . '.png';
$custom = catalogue_category_tile_image(['slug' => 'anything', 'image_path' => $dbPath]);
t(strpos($custom, 'categories/test-' . $tok) !== false, 'image_path takes priority over slug map');

t(catalogue_category_tile_image_alt(['name' => 'Fresh Vegetables']) === 'Fresh Vegetables', 'Tile alt text uses category name');

// Homepage visibility: only show_on_homepage=1 + published.
$hideSlug = 'zt-tile-hide-' . $tok;
$showSlug = 'zt-tile-show-' . $tok;
$hideId = (int) (CategoryRepository::save(null, [
    'name' => 'Hidden home ' . $tok,
    'slug' => $hideSlug,
    'status' => 'published',
    'show_on_homepage' => null,
    'sort_order' => 900,
])['id'] ?? 0);
$showId = (int) (CategoryRepository::save(null, [
    'name' => 'Shown home ' . $tok,
    'slug' => $showSlug,
    'status' => 'published',
    'show_on_homepage' => '1',
    'sort_order' => 901,
])['id'] ?? 0);
t($hideId > 0 && $showId > 0, 'Fixture categories created');

$homeSlugs = array_map(static function (array $c): string {
    return (string) $c['slug'];
}, CategoryRepository::homepage());
t(!in_array($hideSlug, $homeSlugs, true), 'Homepage query excludes show_on_homepage=0');
t(in_array($showSlug, $homeSlugs, true), 'Homepage query includes show_on_homepage=1');

$pub = CategoryRepository::find($showId);
$arr = CategoryRepository::toPublicArray($pub !== null ? $pub : []);
t(array_key_exists('image_path', $arr), 'toPublicArray exposes image_path');

// Persist / clear image_path via CategoryCoverImage (no real upload in CLI).
Install::ensureConnection();
$pdo->prepare('UPDATE categories SET image_path = ? WHERE id = ?')->execute([$dbPath, $showId]);
CategoryCoverImage::deleteManagedFile($dbPath);
$pdo->prepare('UPDATE categories SET image_path = NULL WHERE id = ?')->execute([$showId]);
$row = CategoryRepository::find($showId);
t(empty($row['image_path']), 'image_path cleared in DB');

$indexSrc = (string) file_get_contents(APNI_TOKRI_ROOT . '/index.php');
t(strpos($indexSrc, 'catalogue_category_tile_image') !== false, 'index.php uses tile image helper');
t(strpos($indexSrc, 'p-tomato-1.jpg') === false, 'index.php no longer defaults to p-tomato-1.jpg');
t(strpos($indexSrc, 'catalogue_category_tile_image_alt') !== false, 'index.php sets category alt text');

$editSrc = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/categories/edit.php');
t(strpos($editSrc, 'cover_image') !== false && strpos($editSrc, 'remove_cover_image') !== false, 'Admin category form has cover upload and remove');

CatalogueGateway::reset();
$pdo->prepare('DELETE FROM categories WHERE id IN (?, ?)')->execute([$hideId, $showId]);

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
