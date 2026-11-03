<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('homepage');

$useDb = Install::isSchemaReady() && AdminAuth::check();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!$useDb) {
        admin_flash_set('Database required to save homepage trust sections.');
        header('Location: ' . url_base() . '/admin/homepage/index.php', true, 302);
        exit;
    }
    $result = HomepageTrustService::saveFromRequest($_POST);
    if ($result['ok']) {
        AuditLog::write('homepage.trust.save', 'settings', HomepageTrustService::SETTING_KEY);
        admin_flash_set('Homepage trust sections saved.');
    } else {
        admin_flash_set($result['message'] ?? 'Could not save.', 'error');
    }
    header('Location: ' . url_base() . '/admin/homepage/index.php', true, 302);
    exit;
}

$config = HomepageTrustService::getConfig();
$byId = [];
foreach ($config['sections'] ?? [] as $section) {
    $byId[(string) ($section['id'] ?? '')] = $section;
}

admin_layout_start(['pageTitle' => 'Homepage trust sections', 'activeAdminNav' => 'homepage']);
$pageHeading = 'Homepage trust sections';
$pageDescription = 'Edit trust messaging shown on the public homepage. Testimonials still come from approved, consented records only.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if (!$useDb): ?>
<div class="at-alert at-alert--warning mb-4">Connect the database to save homepage trust settings.</div>
<?php endif; ?>

<form method="post" class="space-y-6">
    <?= Csrf::field() ?>

    <?php
    $strip = $byId['trust_strip'] ?? [];
    ?>
    <fieldset class="at-card"><div class="at-card__body space-y-3">
        <legend class="font-semibold text-lg">Trust highlights (below hero)</legend>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="enabled_trust_strip" value="1" <?= !empty($strip['enabled']) ? 'checked' : '' ?>> Enabled</label>
        <label class="text-sm">Sort order <input type="number" name="sort_trust_strip" class="at-input w-20" value="<?= (int) ($strip['sort'] ?? 10) ?>"></label>
        <?php for ($i = 0; $i < 4; $i++):
            $item = ($strip['items'] ?? [])[$i] ?? []; ?>
        <div class="grid md:grid-cols-3 gap-2 border-t pt-2">
            <input type="text" name="trust_strip_icon_<?= $i ?>" class="at-input" placeholder="icon" value="<?= Security::escape((string) ($item['icon'] ?? '')) ?>">
            <input type="text" name="trust_strip_title_<?= $i ?>" class="at-input" placeholder="Title" value="<?= Security::escape((string) ($item['title'] ?? '')) ?>">
            <input type="text" name="trust_strip_subtitle_<?= $i ?>" class="at-input" placeholder="Subtitle" value="<?= Security::escape((string) ($item['subtitle'] ?? '')) ?>">
        </div>
        <?php endfor; ?>
    </div></fieldset>

    <?php $why = $byId['why_features'] ?? []; ?>
    <fieldset class="at-card"><div class="at-card__body space-y-3">
        <legend class="font-semibold text-lg">Why Apni Tokri</legend>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="enabled_why_features" value="1" <?= !empty($why['enabled']) ? 'checked' : '' ?>> Enabled</label>
        <label class="text-sm">Sort order <input type="number" name="sort_why_features" class="at-input w-20" value="<?= (int) ($why['sort'] ?? 20) ?>"></label>
        <input type="text" name="why_heading" class="at-input at-input--block" placeholder="Heading" value="<?= Security::escape((string) ($why['heading'] ?? '')) ?>">
        <input type="text" name="why_subtitle" class="at-input at-input--block" placeholder="Subtitle" value="<?= Security::escape((string) ($why['subtitle'] ?? '')) ?>">
        <?php for ($i = 0; $i < 4; $i++):
            $item = ($why['items'] ?? [])[$i] ?? []; ?>
        <div class="grid md:grid-cols-3 gap-2 border-t pt-2">
            <input type="text" name="why_features_icon_<?= $i ?>" class="at-input" value="<?= Security::escape((string) ($item['icon'] ?? '')) ?>">
            <input type="text" name="why_features_title_<?= $i ?>" class="at-input" value="<?= Security::escape((string) ($item['title'] ?? '')) ?>">
            <input type="text" name="why_features_body_<?= $i ?>" class="at-input" value="<?= Security::escape((string) ($item['body'] ?? '')) ?>">
        </div>
        <?php endfor; ?>
    </div></fieldset>

    <?php $quality = $byId['quality'] ?? []; ?>
    <fieldset class="at-card"><div class="at-card__body space-y-3">
        <legend class="font-semibold text-lg">Quality &amp; packing</legend>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="enabled_quality" value="1" <?= !empty($quality['enabled']) ? 'checked' : '' ?>> Enabled</label>
        <label class="text-sm">Sort order <input type="number" name="sort_quality" class="at-input w-20" value="<?= (int) ($quality['sort'] ?? 30) ?>"></label>
        <input type="text" name="quality_eyebrow" class="at-input at-input--block" value="<?= Security::escape((string) ($quality['eyebrow'] ?? '')) ?>">
        <input type="text" name="quality_heading" class="at-input at-input--block" value="<?= Security::escape((string) ($quality['heading'] ?? '')) ?>">
        <textarea name="quality_body" class="at-input at-input--block" rows="3"><?= Security::escape((string) ($quality['body'] ?? '')) ?></textarea>
        <?php for ($i = 0; $i < 5; $i++): $b = ($quality['bullets'] ?? [])[$i] ?? ''; ?>
        <input type="text" name="quality_bullet_<?= $i ?>" class="at-input at-input--block" placeholder="Bullet <?= $i + 1 ?>" value="<?= Security::escape((string) $b) ?>">
        <?php endfor; ?>
        <input type="text" name="quality_image_path" class="at-input at-input--block" placeholder="assets/images/demo/about-packing.jpg" value="<?= Security::escape((string) ($quality['image_path'] ?? '')) ?>">
        <div class="grid md:grid-cols-2 gap-2">
            <input type="text" name="quality_cta_label" class="at-input" value="<?= Security::escape((string) ($quality['cta_label'] ?? '')) ?>">
            <input type="text" name="quality_cta_href" class="at-input" placeholder="/page.php?slug=about-us" value="<?= Security::escape((string) ($quality['cta_href'] ?? '')) ?>">
        </div>
    </div></fieldset>

    <?php $testimonials = $byId['testimonials'] ?? []; ?>
    <fieldset class="at-card"><div class="at-card__body space-y-3">
        <legend class="font-semibold text-lg">Testimonials block</legend>
        <p class="text-sm at-muted">Quotes are loaded from approved, featured testimonials with consent on file. This form only controls headings and visibility.</p>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="enabled_testimonials" value="1" <?= !empty($testimonials['enabled']) ? 'checked' : '' ?>> Enabled</label>
        <label class="text-sm">Sort order <input type="number" name="sort_testimonials" class="at-input w-20" value="<?= (int) ($testimonials['sort'] ?? 40) ?>"></label>
        <input type="text" name="testimonials_heading" class="at-input at-input--block" value="<?= Security::escape((string) ($testimonials['heading'] ?? '')) ?>">
        <textarea name="testimonials_subtitle" class="at-input at-input--block" rows="2"><?= Security::escape((string) ($testimonials['subtitle'] ?? '')) ?></textarea>
    </div></fieldset>

    <?php $faq = $byId['faq_support'] ?? []; ?>
    <fieldset class="at-card"><div class="at-card__body space-y-3">
        <legend class="font-semibold text-lg">FAQ preview &amp; support links</legend>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="enabled_faq_support" value="1" <?= !empty($faq['enabled']) ? 'checked' : '' ?>> Enabled</label>
        <label class="text-sm">Sort order <input type="number" name="sort_faq_support" class="at-input w-20" value="<?= (int) ($faq['sort'] ?? 50) ?>"></label>
        <input type="text" name="faq_eyebrow" class="at-input at-input--block" value="<?= Security::escape((string) ($faq['eyebrow'] ?? '')) ?>">
        <input type="text" name="faq_heading" class="at-input at-input--block" value="<?= Security::escape((string) ($faq['heading'] ?? '')) ?>">
        <textarea name="faq_body" class="at-input at-input--block" rows="2"><?= Security::escape((string) ($faq['body'] ?? '')) ?></textarea>
        <label class="text-sm">FAQ items to show <input type="number" name="faq_limit" class="at-input w-20" min="1" max="10" value="<?= (int) ($faq['faq_limit'] ?? 5) ?>"></label>
    </div></fieldset>

    <button type="submit" class="at-btn at-btn--primary at-focusable" <?= $useDb ? '' : 'disabled' ?>>Save trust sections</button>
</form>
<?php admin_layout_end(); ?>
