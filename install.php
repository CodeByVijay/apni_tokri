<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Security::sendSecurityHeaders(Security::isHttps());

$report = Install::statusReport();

layout_public_start([
    'pageTitle' => 'Install — Apni Tokri',
    'showBottomNav' => false,
]);
?>
<div class="max-w-2xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-brand-green">Installation</h1>
    <p class="mt-2 text-sm text-neutral-600">Stage 5 — import schema once, configure <code>includes/config.local.php</code>, then verify below.</p>

    <ol class="mt-6 list-decimal list-inside space-y-2 text-sm">
        <li>Create database: <code>apni_tokri</code> (utf8mb4)</li>
        <li><code>mysql -u USER -p apni_tokri &lt; database/database.sql</code></li>
        <li>Copy <code>includes/config.example.php</code> → <code>config.local.php</code></li>
        <li>Set encryption master key path outside webroot (Stage 9 secrets)</li>
        <li>Sign in at <a class="text-brand-green underline" href="<?= Security::escape(url_base()) ?>/admin/login.php">admin login</a></li>
    </ol>

    <ul class="mt-8 space-y-3">
        <?php
        $items = [
            ['Config file', $report['config']],
            ['DB connection', $report['connect']],
            ['Schema tables', $report['schema_ready']],
            ['Schema version match (' . Install::SCHEMA_VERSION . ')', $report['schema_current']],
        ];
        foreach ($items as $item):
            ?>
            <li class="flex items-center gap-3 rounded-lg border bg-white p-4 <?= $item[1] ? 'border-green-200' : 'border-amber-300' ?>">
                <span class="<?= $item[1] ? 'text-green-700' : 'text-amber-700' ?> font-bold"><?= $item[1] ? '✓' : '!' ?></span>
                <span><?= Security::escape($item[0]) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($report['missing_tables'] !== []): ?>
        <p class="mt-4 text-sm text-neutral-600">Missing tables: <?= Security::escape(implode(', ', $report['missing_tables'])) ?></p>
    <?php endif; ?>

    <p class="mt-6">
        <a href="<?= Security::escape(url_base()) ?>/health-check.php" class="at-btn at-btn--secondary at-focusable">Full health check</a>
    </p>
</div>
<?php layout_public_end(); ?>
