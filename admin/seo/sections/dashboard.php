<?php

declare(strict_types=1);

$report = SeoAdminCenter::healthReport();
$counts = $report['counts'];
$simple = SeoAdminCenter::isSimple();
$score = (int) $report['score'];
$passed = 0;
foreach ($report['checks'] as $c) {
    if (!empty($c['passed'])) {
        $passed++;
    }
}
$totalChecks = count($report['checks']);
?>
<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
    <div class="at-card md:col-span-2 xl:col-span-1">
        <div class="at-card__body">
            <h3 class="font-bold mb-1"><?= $simple ? 'How healthy is your SEO setup?' : 'SEO health score' ?></h3>
            <p class="text-4xl font-display text-brand-green-700"><?= $score ?>%</p>
            <p class="text-sm at-muted mt-2">
                Based on <?= $totalChecks ?> checks we can verify in your store (<?= $passed ?> passed).
                This is not a Google ranking score.
            </p>
        </div>
    </div>
    <?php
    $cards = [
        ['label' => $simple ? 'Products with SEO gaps' : 'Products missing SEO', 'value' => (int) ($counts['products_missing_title'] ?? 0) + (int) ($counts['products_missing_description'] ?? 0), 'section' => 'catalog'],
        ['label' => 'Categories missing titles', 'value' => (int) ($counts['categories_missing_title'] ?? 0), 'section' => 'catalog'],
        ['label' => 'Public pages need SEO', 'value' => (int) ($counts['cms_missing_meta'] ?? 0), 'section' => 'pages'],
        ['label' => 'Sitemap URLs', 'value' => (int) ($counts['sitemap_urls'] ?? 0), 'section' => 'technical'],
        ['label' => 'Redirects', 'value' => (int) ($counts['redirects'] ?? 0), 'section' => 'redirects'],
        ['label' => 'Search Console', 'value' => (string) ($counts['gsc_status'] ?? 'Not configured yet'), 'section' => 'google', 'text' => true],
        ['label' => 'Google Analytics', 'value' => (string) ($counts['ga4_status'] ?? 'Not configured yet'), 'section' => 'google', 'text' => true],
    ];
    foreach ($cards as $card):
        ?>
    <a href="<?= Security::escape(SeoAdminCenter::sectionUrl((string) $card['section'])) ?>" class="at-card at-focusable block hover:ring-2 ring-brand-green-600/30">
        <div class="at-card__body">
            <p class="text-sm at-muted"><?= Security::escape($card['label']) ?></p>
            <p class="text-2xl font-bold mt-1"><?= !empty($card['text']) ? Security::escape((string) $card['value']) : (int) $card['value'] ?></p>
        </div>
    </a>
    <?php endforeach; ?>
</div>

<div class="grid gap-4 lg:grid-cols-2 mt-6">
    <div class="at-card">
        <div class="at-card__body space-y-3">
            <h3 class="font-bold">Checks</h3>
            <ul class="space-y-2 text-sm">
                <?php foreach ($report['checks'] as $check): ?>
                    <li class="flex gap-2 items-start">
                        <span class="<?= !empty($check['passed']) ? 'text-green-700' : 'text-amber-700' ?>" aria-hidden="true"><?= !empty($check['passed']) ? '✓' : '!' ?></span>
                        <span>
                            <span class="font-medium"><?= Security::escape((string) ($check['label'] ?? '')) ?></span>
                            <span class="block at-muted text-xs"><?= Security::escape((string) ($check['detail'] ?? '')) ?></span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="at-card">
        <div class="at-card__body space-y-3">
            <h3 class="font-bold">Recommended actions</h3>
            <?php if ($report['recommendations'] === []): ?>
                <p class="text-sm at-muted">No urgent issues detected. Keep product and page summaries up to date.</p>
            <?php else: ?>
                <ul class="space-y-3">
                    <?php foreach ($report['recommendations'] as $rec): ?>
                        <li class="flex flex-wrap gap-2 items-center justify-between text-sm border-b border-[var(--at-line)] pb-2">
                            <span><?= Security::escape((string) ($rec['message'] ?? '')) ?></span>
                            <a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="<?= Security::escape(SeoAdminCenter::sectionUrl((string) ($rec['section'] ?? 'dashboard'))) ?>">
                                <?= Security::escape((string) ($rec['action_label'] ?? 'Review')) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <a href="<?= Security::escape(SeoAdminCenter::sectionUrl('setup')) ?>" class="at-btn at-btn--primary at-focusable">Open setup assistant</a>
        </div>
    </div>
</div>
