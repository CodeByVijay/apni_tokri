<?php

declare(strict_types=1);

/** @var string $seoSection */
$seoSection = SeoAdminCenter::normalizeSection($seoSection ?? 'dashboard');
$simple = SeoAdminCenter::isSimple();
?>
<div class="flex flex-col gap-3 mb-4 lg:flex-row lg:items-center lg:justify-between">
    <nav class="at-admin-tabs at-seo-center-tabs" aria-label="SEO Management Center">
        <?php foreach (SeoAdminCenter::SECTIONS as $key => $label): ?>
            <a href="<?= Security::escape(SeoAdminCenter::sectionUrl($key)) ?>"
               class="at-focusable<?= $seoSection === $key ? ' is-active' : '' ?>"
               <?= $seoSection === $key ? 'aria-current="page"' : '' ?>><?= Security::escape($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <form method="post" action="<?= Security::escape(SeoAdminCenter::sectionUrl($seoSection)) ?>" class="flex items-center gap-2 shrink-0">
        <?= Csrf::field() ?>
        <input type="hidden" name="seo_action" value="set_ui_mode">
        <span class="text-sm at-muted hidden sm:inline">View:</span>
        <button type="submit" name="ui_mode" value="simple" class="at-btn at-btn--sm at-focusable<?= $simple ? ' at-btn--primary' : ' at-btn--secondary' ?>">Simple</button>
        <button type="submit" name="ui_mode" value="advanced" class="at-btn at-btn--sm at-focusable<?= !$simple ? ' at-btn--primary' : ' at-btn--secondary' ?>">Advanced</button>
    </form>
</div>
<p class="text-xs at-muted -mt-2 mb-4">SEO settings can improve visibility and technical quality; they do not guarantee Google rankings.</p>
