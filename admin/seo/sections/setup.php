<?php

declare(strict_types=1);

$progress = SeoAdminCenter::wizardProgress();
$simple = SeoAdminCenter::isSimple();
$sitemapStepDone = !empty($progress['sitemap']);
if (!$sitemapStepDone) {
    try {
        $sitemapStepDone = count(SeoSitemap::collectIndexableUrls()) > 0;
    } catch (Throwable $e) {
        $sitemapStepDone = false;
    }
}
$settingsBranding = url_base() . '/admin/settings/branding.php';
$settingsBusiness = url_base() . '/admin/settings/business.php';
$home = SeoResolver::forHomepage();
$steps = [
    'identity' => [
        'title' => 'Website identity',
        'help' => 'Your shop name and logo help Google understand your brand.',
        'done' => trim(BrandingSettings::siteName()) !== '',
        'link' => $settingsBranding,
        'link_label' => 'Website branding',
    ],
    'business' => [
        'title' => 'Business information',
        'help' => 'Address and phone support local search in Indore.',
        'done' => trim(BusinessSettings::addressLine() ?? '') !== '' && trim(BusinessSettings::city() ?? '') !== '',
        'link' => $settingsBusiness,
        'link_label' => 'Business profile',
    ],
    'homepage' => [
        'title' => 'Homepage SEO',
        'help' => 'Write the title and summary shoppers may see on Google.',
        'done' => !empty($progress['homepage']),
        'anchor' => 'setup-home',
    ],
    'products' => [
        'title' => 'Products SEO',
        'help' => 'Each product can have its own search title and description.',
        'done' => !empty($progress['products']),
        'link' => SeoAdminCenter::sectionUrl('catalog'),
        'link_label' => 'Products & categories',
    ],
    'categories' => [
        'title' => 'Categories SEO',
        'help' => 'Category pages help people browse vegetables by type.',
        'done' => !empty($progress['categories']),
        'link' => SeoAdminCenter::sectionUrl('catalog') . '#categories',
        'link_label' => 'Categories list',
    ],
    'gsc' => [
        'title' => 'Google Search Console',
        'help' => 'Verify you own the site so Google can share indexing data.',
        'done' => SeoAdminCenter::gscConfigured(),
        'link' => SeoAdminCenter::sectionUrl('google'),
        'link_label' => 'Google integrations',
    ],
    'sitemap' => [
        'title' => 'Sitemap & indexing',
        'help' => 'An XML sitemap lists pages Google can crawl.',
        'done' => $sitemapStepDone,
        'link' => SeoAdminCenter::sectionUrl('technical'),
        'link_label' => 'Technical SEO',
    ],
];
$completed = 0;
foreach ($steps as $s) {
    if (!empty($s['done'])) {
        $completed++;
    }
}
$homeTitle = AppSettings::get('seo.home.meta_title', '') ?? '';
$homeDesc = AppSettings::get('seo.home.meta_description', '') ?? '';
$homeIndex = AppSettings::get('seo.home.robots_index', '1') !== '0';
$previewUrl = SeoUrl::absolute('/');
?>
<div class="at-seo-saas__stack">
<div class="at-seo-saas__panel">
        <p class="at-seo-saas__panel-sub mb-2">Progress: <strong><?= $completed ?></strong> of <?= count($steps) ?> steps complete</p>
        <div class="at-seo-saas__wizard-progress" role="progressbar" aria-valuenow="<?= (int) $completed ?>" aria-valuemin="0" aria-valuemax="<?= count($steps) ?>">
            <div class="at-seo-saas__wizard-progress-fill" style="width:<?= count($steps) > 0 ? (int) round(($completed / count($steps)) * 100) : 0 ?>%"></div>
        </div>
</div>
<ol class="at-seo-saas__stack" style="list-style:none;margin:0;padding:0">
    <?php foreach ($steps as $key => $step): ?>
        <li class="at-seo-saas__wizard-step<?= !empty($step['done']) ? ' is-done' : '' ?>">
            <div class="min-w-0 flex-1">
                <h3 class="font-bold flex items-center gap-2">
                    <?php if (!empty($step['done'])): ?><span class="text-green-700">✓</span><?php endif; ?>
                    <?= Security::escape($step['title']) ?>
                </h3>
                <p class="text-sm at-muted"><?= Security::escape($step['help']) ?></p>
                <?php if (!empty($step['link'])): ?>
                    <a class="text-sm text-brand-green-800 underline at-focusable" href="<?= Security::escape((string) $step['link']) ?>"><?= Security::escape((string) ($step['link_label'] ?? 'Open')) ?></a>
                <?php endif; ?>
            </div>
            <form method="post" class="shrink-0">
                <?= Csrf::field() ?>
                <input type="hidden" name="section" value="setup">
                <input type="hidden" name="seo_action" value="wizard_mark">
                <input type="hidden" name="wizard_step" value="<?= Security::escape($key) ?>">
                <input type="hidden" name="wizard_done" value="1">
                <button type="submit" class="at-btn at-btn--ghost at-btn--sm at-focusable">Mark done</button>
            </form>
        </li>
    <?php endforeach; ?>
</ol>

<div id="setup-home" class="at-seo-saas__panel max-w-2xl">
        <h3 class="at-seo-saas__panel-title"><?= $simple ? 'Homepage search appearance' : 'Homepage SEO' ?></h3>
        <p class="at-seo-saas__panel-sub">This is what people may see when they find your shop on Google.</p>
        <form method="post" class="space-y-3 mt-4">
            <?= Csrf::field() ?>
            <input type="hidden" name="section" value="setup">
            <input type="hidden" name="seo_action" value="save_homepage">
            <div>
                <label class="at-label"><?= $simple ? 'Search title' : 'Meta title' ?></label>
                <input class="at-input at-focusable" name="home_meta_title" maxlength="70" value="<?= Security::escape((string) $homeTitle) ?>">
                <p class="text-xs at-muted mt-1">Aim for about 70 characters.</p>
            </div>
            <div>
                <label class="at-label"><?= $simple ? 'Short summary for Google' : 'Meta description' ?></label>
                <textarea class="at-input at-focusable" name="home_meta_description" rows="3" maxlength="320"><?= Security::escape((string) $homeDesc) ?></textarea>
            </div>
            <?php if (!SeoAdminCenter::isSimple()): ?>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="home_robots_index" value="1"<?= $homeIndex ? ' checked' : '' ?>> Allow Google to index the homepage</label>
            <?php else: ?>
            <input type="hidden" name="home_robots_index" value="<?= $homeIndex ? '1' : '0' ?>">
            <?php endif; ?>
            <div>
                <p class="text-sm font-medium mb-1">Preview (approximate)</p>
                <?= SeoAdminCenter::searchPreview((string) $homeTitle, (string) $homeDesc, $previewUrl) ?>
            </div>
            <button type="submit" class="at-btn at-btn--primary at-focusable"<?= $useDb ? '' : ' disabled' ?>>Save homepage SEO</button>
        </form>
</div>
</div>
