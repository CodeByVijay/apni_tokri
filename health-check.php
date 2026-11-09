<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

AppEnvironment::denyProductionDevelopmentSurface();

if (Install::isApplicationInstalled()) {
    if (!AdminAuth::check()) {
        Install::denyPublicInstallSurface();
    }
}

Security::sendSecurityHeaders(Security::isHttps());

$config = App::config();
$report = Install::statusReport();

$checks = [];
$checks['php_version'] = [
    'label' => 'PHP 8.2+ (recommended)',
    'ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
    'detail' => PHP_VERSION . (version_compare(PHP_VERSION, '8.2.0', '>=') ? '' : ' — upgrade Apache module to 8.2+ for production'),
];
$checks['pdo_mysql'] = ['label' => 'PDO MySQL', 'ok' => extension_loaded('pdo_mysql'), 'detail' => extension_loaded('pdo_mysql') ? 'loaded' : 'missing'];
$checks['sodium'] = ['label' => 'Sodium', 'ok' => extension_loaded('sodium'), 'detail' => extension_loaded('sodium') ? 'loaded' : 'missing'];
$checks['config_local'] = [
    'label' => 'config.local.php',
    'ok' => $report['config'],
    'detail' => $report['config'] ? 'present' : 'copy includes/config.example.php',
];
$checks['database'] = [
    'label' => 'Database connection',
    'ok' => $report['connect'],
    'detail' => $report['connect'] ? 'connected' : 'check credentials / create database',
];
$checks['schema'] = [
    'label' => 'Schema imported',
    'ok' => $report['schema_ready'],
    'detail' => $report['schema_ready'] ? 'core tables OK' : 'import database/database.sql',
];
$checks['schema_version'] = [
    'label' => 'Schema version',
    'ok' => $report['schema_current'],
    'detail' => $report['schema_version'] ? ('installed ' . $report['schema_version']) : 'not set',
];
$checks['storage_logs'] = [
    'label' => 'storage/logs writable',
    'ok' => is_dir(APNI_TOKRI_ROOT . '/storage/logs') && is_writable(APNI_TOKRI_ROOT . '/storage/logs'),
    'detail' => 'required for error logging',
];
$checks['uploads'] = [
    'label' => 'uploads/ writable',
    'ok' => is_dir(APNI_TOKRI_ROOT . '/uploads') && is_writable(APNI_TOKRI_ROOT . '/uploads'),
    'detail' => 'required for product/CMS images',
];

$allOk = true;
foreach ($checks as $c) {
    if (!$c['ok']) {
        $allOk = false;
    }
}

layout_public_start(['pageTitle' => 'Health check — Veggie Tokri', 'showBottomNav' => false]);
?>
<div class="max-w-2xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-brand-green">Installation health check</h1>
    <p class="mt-2 text-sm text-neutral-600">Environment and schema checks — see <a class="underline text-brand-green" href="<?= Security::escape(url_base()) ?>/install.php">install guide</a>.</p>
    <ul class="mt-8 space-y-3">
        <?php foreach ($checks as $check): ?>
            <li class="flex items-start gap-3 rounded-lg border bg-white p-4 <?= $check['ok'] ? 'border-green-200' : 'border-amber-300' ?>">
                <span class="mt-0.5 font-bold <?= $check['ok'] ? 'text-green-700' : 'text-amber-700' ?>"><?= $check['ok'] ? '✓' : '!' ?></span>
                <div>
                    <p class="font-medium"><?= Security::escape($check['label']) ?></p>
                    <p class="text-sm text-neutral-600"><?= Security::escape($check['detail']) ?></p>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <p class="mt-8 text-sm">Overall: <strong class="<?= $allOk ? 'text-green-700' : 'text-amber-700' ?>"><?= $allOk ? 'Ready for storefront use' : 'Needs attention' ?></strong></p>
</div>
<?php layout_public_end(); ?>
