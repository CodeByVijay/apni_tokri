<?php

declare(strict_types=1);

/** Product edit tab markup (no DB writes). */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$pass = 0;
$fail = 0;

function t(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

$html = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/products/edit.php');
$js = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/admin.js');

t(strpos($html, 'data-at-product-edit') !== false, 'Product edit tab root wrapper');
t(strpos($html, 'id="at-product-edit-form"') !== false, 'Main product form has id for external submit');
t(strpos($html, 'form="at-product-edit-form"') !== false, 'Save button submits main form via form attribute');
t(preg_match('/data-admin-panel="images"[^>]*class="[^"]*hidden/', $html) === 1, 'Images panel hidden by default');
t(strpos($html, '<section id="images"') === false, 'Images no longer a always-visible section below form');
t(substr_count($html, 'data-admin-panel=') === 5, 'Five tab panels (general, variants, attributes, seo, images)');
t(strpos($html, 'action" value="upload_image"') !== false, 'Upload image POST unchanged');
t(strpos($html, 'action" value="delete_image"') !== false, 'Delete image POST unchanged');
t(strpos($js, 'data-at-product-edit') !== false, 'Tab JS scoped to product edit root');
t(strpos($js, "#images") !== false || strpos($js, '#images') !== false, 'Hash #images opens Images tab on load');

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
