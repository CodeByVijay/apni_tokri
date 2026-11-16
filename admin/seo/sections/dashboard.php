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
$tone = SeoAdminUi::healthTone($score);
$circ = 2 * M_PI * 54;
$dashOffset = $circ * (1 - min(100, max(0, $score)) / 100);

$kpis = [
    ['icon' => 'bag', 'label' => $simple ? 'Products need SEO text' : 'Product SEO gaps', 'value' => (int) ($counts['products_missing_title'] ?? 0) + (int) ($counts['products_missing_description'] ?? 0), 'section' => 'catalog', 'tone' => ''],
    ['icon' => 'grid', 'label' => 'Categories missing titles', 'value' => (int) ($counts['categories_missing_title'] ?? 0), 'section' => 'catalog', 'tone' => ''],
    ['icon' => 'file', 'label' => 'Pages need SEO', 'value' => (int) ($counts['cms_missing_meta'] ?? 0), 'section' => 'pages', 'tone' => ''],
    ['icon' => 'chart', 'label' => 'Sitemap URLs', 'value' => (int) ($counts['sitemap_urls'] ?? 0), 'section' => 'technical', 'tone' => 'ok'],
    ['icon' => 'refresh', 'label' => 'Redirects', 'value' => (int) ($counts['redirects'] ?? 0), 'section' => 'redirects', 'tone' => ''],
    ['icon' => 'shield', 'label' => 'Search Console', 'value' => (string) ($counts['gsc_status'] ?? 'Not configured yet'), 'section' => 'google', 'text' => true, 'tone' => (string) ($counts['gsc_status'] ?? '') === 'Configured' ? 'ok' : 'warn'],
    ['icon' => 'chart', 'label' => 'Google Analytics', 'value' => (string) ($counts['ga4_status'] ?? 'Not configured yet'), 'section' => 'google', 'text' => true, 'tone' => (string) ($counts['ga4_status'] ?? '') === 'Configured' ? 'ok' : 'warn'],
];
?>
<div class="at-seo-saas__dashboard">
    <div class="at-seo-saas__hero">
        <div class="at-seo-saas__score-card at-seo-saas__panel at-seo-saas__panel--hero">
            <div class="at-seo-saas__score-ring-wrap" data-tone="<?= Security::escape($tone) ?>">
                <svg class="at-seo-saas__score-ring" viewBox="0 0 120 120" role="img" aria-label="SEO health <?= (int) $score ?> percent">
                    <circle class="at-seo-saas__score-ring-track" cx="60" cy="60" r="54" />
                    <circle class="at-seo-saas__score-ring-fill" cx="60" cy="60" r="54"
                            stroke-dasharray="<?= Security::escape((string) round($circ, 2)) ?>"
                            stroke-dashoffset="<?= Security::escape((string) round($dashOffset, 2)) ?>" />
                </svg>
                <div class="at-seo-saas__score-value">
                    <span class="at-seo-saas__score-num"><?= (int) $score ?></span>
                    <span class="at-seo-saas__score-suffix">%</span>
                </div>
            </div>
            <div class="at-seo-saas__score-copy">
                <h3 class="at-seo-saas__score-title"><?= $simple ? 'Your SEO setup score' : 'SEO health score' ?></h3>
                <p class="at-seo-saas__score-lead">
                    <?= (int) $passed ?> of <?= (int) $totalChecks ?> checks passed in your store.
                    This measures completeness — not your rank on Google.
                </p>
                <a href="<?= Security::escape(SeoAdminCenter::sectionUrl('setup')) ?>" class="at-btn at-btn--primary at-btn--sm at-focusable">Continue setup</a>
            </div>
        </div>

        <div class="at-seo-saas__kpi-grid">
            <?php foreach ($kpis as $kpi):
                $valTone = (string) ($kpi['tone'] ?? '');
                if ($valTone === '' && !empty($kpi['text'])) {
                    $valTone = 'neutral';
                } elseif ($valTone === '' && (int) ($kpi['value'] ?? 0) > 0 && in_array($kpi['section'], ['catalog', 'pages'], true)) {
                    $valTone = 'warn';
                } elseif ($valTone === '') {
                    $valTone = 'ok';
                }
                ?>
                <a href="<?= Security::escape(SeoAdminCenter::sectionUrl((string) $kpi['section'])) ?>"
                   class="at-seo-saas__kpi at-seo-saas__panel at-focusable" data-tone="<?= Security::escape($valTone) ?>">
                    <span class="at-seo-saas__kpi-icon" aria-hidden="true"><?= at_icon((string) $kpi['icon'], 'w-5 h-5') ?></span>
                    <span class="at-seo-saas__kpi-label"><?= Security::escape($kpi['label']) ?></span>
                    <span class="at-seo-saas__kpi-value"><?= !empty($kpi['text']) ? Security::escape((string) $kpi['value']) : (int) $kpi['value'] ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="at-seo-saas__dashboard-split">
        <section class="at-seo-saas__panel at-seo-saas__panel--actions" aria-labelledby="seo-actions-title">
            <div class="at-seo-saas__panel-head">
                <h3 id="seo-actions-title" class="at-seo-saas__panel-title">Recommended next steps</h3>
                <p class="at-seo-saas__panel-sub">Fix these first for the biggest impact.</p>
            </div>
            <?php if ($report['recommendations'] === []): ?>
                <p class="at-seo-saas__muted">You're in good shape. Keep product descriptions fresh as you add items.</p>
            <?php else: ?>
                <ul class="at-seo-saas__action-list">
                    <?php foreach ($report['recommendations'] as $rec): ?>
                        <li class="at-seo-saas__action-item <?= Security::escape(SeoAdminUi::severityClass((string) ($rec['severity'] ?? 'info'))) ?>">
                            <div class="at-seo-saas__action-body">
                                <p class="at-seo-saas__action-text"><?= Security::escape((string) ($rec['message'] ?? '')) ?></p>
                            </div>
                            <a class="at-btn at-btn--primary at-btn--sm at-focusable shrink-0"
                               href="<?= Security::escape(SeoAdminCenter::sectionUrl((string) ($rec['section'] ?? 'dashboard'))) ?>">
                                <?= Security::escape((string) ($rec['action_label'] ?? 'Fix now')) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="at-seo-saas__panel" aria-labelledby="seo-checks-title">
            <div class="at-seo-saas__panel-head">
                <h3 id="seo-checks-title" class="at-seo-saas__panel-title">What we checked</h3>
                <p class="at-seo-saas__panel-sub">Based on data in your Veggie Tokri admin.</p>
            </div>
            <ul class="at-seo-saas__check-list">
                <?php foreach ($report['checks'] as $check):
                    $section = match ((string) ($check['id'] ?? '')) {
                        'home_title', 'home_desc' => 'pages',
                        'products_title', 'products_desc', 'dup_titles', 'product_index' => 'catalog',
                        'categories_title', 'categories_desc' => 'catalog',
                        'pages_meta' => 'pages',
                        'sitemap', 'robots_safe' => 'technical',
                        default => 'dashboard',
                    };
                    ?>
                    <li class="at-seo-saas__check <?= Security::escape(SeoAdminUi::checkStateClass(!empty($check['passed']))) ?>">
                        <span class="at-seo-saas__check-mark" aria-hidden="true"></span>
                        <div class="at-seo-saas__check-text">
                            <p class="at-seo-saas__check-label"><?= Security::escape((string) ($check['label'] ?? '')) ?></p>
                            <p class="at-seo-saas__check-detail"><?= Security::escape((string) ($check['detail'] ?? '')) ?></p>
                            <?php if (empty($check['passed']) && $section !== 'dashboard'): ?>
                                <a class="at-seo-saas__check-link at-focusable" href="<?= Security::escape(SeoAdminCenter::sectionUrl($section)) ?>">Open settings</a>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>
</div>
