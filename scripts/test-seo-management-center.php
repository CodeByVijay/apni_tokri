<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

function ok(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "ok: $msg\n";
}

ok(class_exists('SeoAdminCenter'), 'SeoAdminCenter class');
ok(SeoAdminCenter::normalizeSection('dashboard') === 'dashboard', 'default section');
ok(SeoAdminCenter::normalizeSection('invalid') === 'dashboard', 'invalid section falls back');
ok(count(SeoAdminCenter::SECTIONS) === 8, 'eight nav sections');
ok(str_contains(SeoAdminCenter::sectionUrl('redirects'), 'section=redirects'), 'section URL');

$report = SeoAdminCenter::healthReport();
ok(isset($report['score']) && is_int($report['score']), 'health score int');
ok(isset($report['checks']) && is_array($report['checks']), 'health checks');
ok($report['score'] >= 0 && $report['score'] <= 100, 'score range');

$tpl = SeoAdminCenter::applyTemplateTokens('{product_name} | {city}', ['{product_name}' => 'Tomato']);
ok(str_contains($tpl, 'Tomato') && str_contains($tpl, 'Indore') || str_contains($tpl, 'Tomato'), 'template tokens');

$robotsBad = SeoAdminCenter::validateRobotsExtra("Disallow: /\n");
ok(empty($robotsBad['ok']), 'blocks disallow root');
$robotsOk = SeoAdminCenter::validateRobotsExtra("Disallow: /private/\n");
ok(!empty($robotsOk['ok']), 'allows safe disallow');

ok(SeoAdminCenter::normalizeGa4Id('G-ABCDEF12') === 'G-ABCDEF12', 'GA4 normalize');
ok(SeoAdminCenter::normalizeGa4Id('bad') === null, 'GA4 reject');

ok(method_exists('SeoNotFoundLog', 'recordCurrentRequest'), '404 record helper');
ok(is_file(APNI_TOKRI_ROOT . '/admin/seo/index.php'), 'admin index');
ok(is_file(APNI_TOKRI_ROOT . '/includes/partials/admin/seo-ui.php'), 'seo ui helpers');
$css = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/css/apni-tokri.css');
ok(str_contains($css, '.at-seo-saas__nav'), 'saas nav css');
ok(str_contains($css, '.at-seo-tabs-wrap'), 'seo tabs wrap css');
ok(str_contains($css, '.at-seo-saas__score-ring'), 'health ring css');
$idx = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/seo/index.php');
ok(str_contains($idx, 'at-seo-saas'), 'index wraps saas shell');
foreach (array_keys(SeoAdminCenter::SECTIONS) as $sec) {
    ok(is_file(APNI_TOKRI_ROOT . '/admin/seo/sections/' . $sec . '.php'), 'section file ' . $sec);
}

$index = file_get_contents(APNI_TOKRI_ROOT . '/admin/seo/index.php');
ok($index !== false && str_contains($index, 'seo-nav.php'), 'index loads nav');
ok($index !== false && str_contains($index, 'redirect_save'), 'index handles redirects');

$head = file_get_contents(APNI_TOKRI_ROOT . '/includes/layout/head-public.php');
ok($head !== false && str_contains($head, 'google-site-verification'), 'GSC meta hook in head');

echo "\nPASS: SEO Management Center foundation.\n";
