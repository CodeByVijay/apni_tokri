<?php

declare(strict_types=1);

/** @var string $seoSection */
$seoSection = SeoAdminCenter::normalizeSection($seoSection ?? 'dashboard');
$simple = SeoAdminCenter::isSimple();
$sectionLabel = SeoAdminCenter::SECTIONS[$seoSection] ?? 'SEO';
?>
<header class="at-seo-saas__mast">
    <div class="at-seo-saas__mast-inner">
        <div class="at-seo-saas__mast-copy">
            <p class="at-seo-saas__eyebrow">Search visibility</p>
            <p class="at-seo-saas__disclaimer">Better SEO can help Google find your pages; it does not guarantee rankings.</p>
        </div>
        <form method="post" action="<?= Security::escape(SeoAdminCenter::sectionUrl($seoSection)) ?>" class="at-seo-saas__mode" aria-label="Simple or Advanced view">
            <?= Csrf::field() ?>
            <input type="hidden" name="section" value="<?= Security::escape($seoSection) ?>">
            <input type="hidden" name="seo_action" value="set_ui_mode">
            <span class="at-seo-saas__mode-label">View</span>
            <div class="at-seo-saas__mode-toggle" role="group" aria-label="View mode">
                <button type="submit" name="ui_mode" value="simple" class="at-seo-saas__mode-btn<?= $simple ? ' is-active' : '' ?>">Simple</button>
                <button type="submit" name="ui_mode" value="advanced" class="at-seo-saas__mode-btn<?= !$simple ? ' is-active' : '' ?>">Advanced</button>
            </div>
        </form>
    </div>

    <div class="at-seo-saas__nav-wrap">
        <label class="at-seo-saas__nav-select-label" for="at-seo-section-select">Section</label>
        <select id="at-seo-section-select" class="at-seo-saas__nav-select at-focusable" aria-label="Jump to SEO section">
            <?php foreach (SeoAdminCenter::SECTIONS as $key => $label): ?>
                <option value="<?= Security::escape(SeoAdminCenter::sectionUrl($key)) ?>"<?= $seoSection === $key ? ' selected' : '' ?>><?= Security::escape($label) ?></option>
            <?php endforeach; ?>
        </select>
        <nav class="at-seo-saas__nav" aria-label="SEO Management Center sections">
            <?php foreach (SeoAdminCenter::SECTIONS as $key => $label): ?>
                <?php $short = SeoAdminUi::NAV_SHORT[$key] ?? $label; ?>
                <a href="<?= Security::escape(SeoAdminCenter::sectionUrl($key)) ?>"
                   class="at-seo-saas__nav-link at-focusable<?= $seoSection === $key ? ' is-active' : '' ?>"
                   <?= $seoSection === $key ? 'aria-current="page"' : '' ?>>
                    <span class="at-seo-saas__nav-link-short"><?= Security::escape($short) ?></span>
                    <span class="at-seo-saas__nav-link-long"><?= Security::escape($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
</header>

<script>
(function () {
    var sel = document.getElementById('at-seo-section-select');
    if (sel) {
        sel.addEventListener('change', function () {
            if (this.value) {
                window.location.href = this.value;
            }
        });
    }
})();
</script>
