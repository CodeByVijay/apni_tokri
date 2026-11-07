<?php

declare(strict_types=1);

/** @var string $seoSection */
$intros = SeoAdminUi::sectionIntros();
$intro = $intros[$seoSection] ?? ['title' => 'SEO', 'lead' => ''];
if ($seoSection === 'dashboard') {
    return;
}
?>
<div class="at-seo-saas__section-intro">
    <h3 class="at-seo-saas__section-title"><?= Security::escape($intro['title']) ?></h3>
    <p class="at-seo-saas__section-lead"><?= Security::escape($intro['lead']) ?></p>
</div>
