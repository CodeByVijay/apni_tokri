<?php

declare(strict_types=1);

/** Homepage / hero category URLs use published DB slugs (not hardcoded demo slugs). */
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
$cat = new DbCatalogue();

$validSlug = 'zt-hlink-valid-' . $tok;
$validId = (int) (CategoryRepository::save(null, [
    'name' => 'Link valid ' . $tok,
    'slug' => $validSlug,
    'status' => 'published',
    'show_on_homepage' => '1',
    'sort_order' => 880,
])['id'] ?? 0);

$noHomeSlug = 'zt-hlink-nohome-' . $tok;
$noHomeId = (int) (CategoryRepository::save(null, [
    'name' => 'Link no home ' . $tok,
    'slug' => $noHomeSlug,
    'status' => 'published',
    'show_on_homepage' => null,
    'sort_order' => 881,
])['id'] ?? 0);

$hiddenSlug = 'zt-hlink-hidden-' . $tok;
$hiddenId = (int) (CategoryRepository::save(null, [
    'name' => 'Link hidden ' . $tok,
    'slug' => $hiddenSlug,
    'status' => 'hidden',
    'show_on_homepage' => '1',
    'sort_order' => 882,
])['id'] ?? 0);

CatalogueGateway::reset();

t($validId > 0 && $noHomeId > 0 && $hiddenId > 0, 'Fixture categories created');

t(catalogue_public_category($validSlug) !== null, 'Valid published slug resolves');
t(catalogue_category_url_for_slug($validSlug) !== null, 'Valid slug has category URL');
t(catalogue_public_category('zt-hlink-missing-' . $tok) === null, 'Invalid slug returns null');
t(catalogue_public_category($hiddenSlug) === null, 'Hidden/unpublished slug not public');
t($cat->category($noHomeSlug) !== null, 'Published category opens without show_on_homepage');
t(!in_array($noHomeSlug, array_column(CategoryRepository::homepage(), 'slug'), true), 'Homepage list excludes show_on_homepage=0');
t(in_array($validSlug, array_column(CategoryRepository::homepage(), 'slug'), true), 'Homepage list includes show_on_homepage=1');

$primary = catalogue_primary_category_slug();
t($primary !== null && $primary !== '', 'Primary category slug resolved from catalogue');
t(strpos(catalogue_primary_category_url(), 'category.php?slug=') !== false, 'Primary URL is a category link');

$indexSrc = (string) file_get_contents(APNI_TOKRI_ROOT . '/index.php');
t(strpos($indexSrc, 'catalogue_primary_category_url()') !== false, 'index.php uses dynamic primary category URL');
t(strpos($indexSrc, 'category.php?slug=vegetables') === false, 'index.php no hardcoded vegetables link');

$catSrc = (string) file_get_contents(APNI_TOKRI_ROOT . '/category.php');
t(strpos($catSrc, "'vegetables'") === false || strpos($catSrc, "trim(\$_GET['slug']") !== false, 'category.php does not default slug to vegetables');

CatalogueGateway::reset();
$pdo->prepare('DELETE FROM categories WHERE id IN (?, ?, ?)')->execute([$validId, $noHomeId, $hiddenId]);

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
