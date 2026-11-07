<?php

declare(strict_types=1);

final class HomepageTrustRenderer
{
    /** Render all enabled trust sections (for tests and homepage). */
    public static function renderAll(string $base, ?object $catalogue = null): string
    {
        ob_start();
        self::renderSections($base, $catalogue);

        return (string) ob_get_clean();
    }

    public static function renderSections(string $base, ?object $catalogue = null, ?string $placement = null): void
    {
        foreach (HomepageTrustService::publicSections($placement) as $section) {
            self::renderSection($section, $base, $catalogue);
        }
    }

    /** @param array<string, mixed> $section */
    public static function renderSection(array $section, string $base, ?object $catalogue = null): void
    {
        $type = (string) ($section['type'] ?? '');
        if ($type === 'trust_strip') {
            self::renderTrustStrip($section);
        } elseif ($type === 'why_features') {
            self::renderWhyFeatures($section);
        } elseif ($type === 'quality') {
            self::renderQuality($section, $base);
        } elseif ($type === 'testimonials') {
            self::renderTestimonials($section, $base);
        } elseif ($type === 'faq_support') {
            self::renderFaqSupport($section, $base, $catalogue);
        }
    }

    /** @param array<string, mixed> $section */
    private static function renderTrustStrip(array $section): void
    {
        $items = [];
        foreach ($section['items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = HomepageTrustService::sanitizeText($item['title'] ?? '', 120);
            if ($title === '') {
                continue;
            }
            $icon = HomepageTrustIcons::resolveForRender((string) ($item['icon'] ?? ''));
            $items[] = [
                'icon' => $icon,
                'title' => $title,
                'subtitle' => HomepageTrustService::sanitizeText($item['subtitle'] ?? '', 160),
            ];
        }
        if ($items === []) {
            return;
        }
        ?>
<section class="at-section--white" aria-label="Why shop with us">
    <div class="at-container py-5 md:py-6">
        <div class="at-trust">
            <?php foreach ($items as $item): ?>
            <div class="at-trust__item"><span class="at-trust__icon"><?= at_icon($item['icon']) ?></span><div><strong><?= Security::escape($item['title']) ?></strong><span><?= Security::escape($item['subtitle']) ?></span></div></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
        <?php
    }

    /** @param array<string, mixed> $section */
    private static function renderWhyFeatures(array $section): void
    {
        $heading = HomepageTrustService::sanitizeText($section['heading'] ?? '', 191);
        if ($heading === '') {
            return;
        }
        $subtitle = HomepageTrustService::sanitizeText($section['subtitle'] ?? '', 255);
        $cards = [];
        foreach ($section['items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = HomepageTrustService::sanitizeText($item['title'] ?? '', 120);
            $body = HomepageTrustService::sanitizeText($item['body'] ?? '', 500);
            if ($title === '' || $body === '') {
                continue;
            }
            $icon = HomepageTrustIcons::resolveForRender((string) ($item['icon'] ?? ''));
            $cards[] = ['icon' => $icon, 'title' => $title, 'body' => $body];
        }
        if ($cards === []) {
            return;
        }
        ?>
<section class="at-section">
    <div class="at-container">
        <div class="at-section-head">
            <div>
                <h2 class="at-h2"><?= Security::escape($heading) ?></h2>
                <?php if ($subtitle !== ''): ?><p><?= Security::escape($subtitle) ?></p><?php endif; ?>
            </div>
        </div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <?php foreach ($cards as $card): ?>
            <div class="at-feature"><span class="at-feature__icon"><?= at_icon($card['icon']) ?></span><h3><?= Security::escape($card['title']) ?></h3><p><?= Security::escape($card['body']) ?></p></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
        <?php
    }

    /** @param array<string, mixed> $section */
    private static function renderQuality(array $section, string $base): void
    {
        $heading = HomepageTrustService::sanitizeText($section['heading'] ?? '', 191);
        if ($heading === '') {
            return;
        }
        $eyebrow = HomepageTrustService::sanitizeText($section['eyebrow'] ?? '', 120);
        $body = HomepageTrustService::sanitizeText($section['body'] ?? '', 2000);
        $imagePath = (string) ($section['image_path'] ?? '');
        if ($imagePath === '' || !preg_match('#^assets/images/[a-zA-Z0-9/_.-]+$#', ltrim($imagePath, '/'))) {
            $imagePath = 'assets/images/demo/about-packing.jpg';
        }
        $ctaLabel = HomepageTrustService::sanitizeText($section['cta_label'] ?? '', 120);
        $ctaHref = trim((string) ($section['cta_href'] ?? ''));
        if ($ctaHref !== '' && !str_starts_with($ctaHref, '/')) {
            $ctaHref = '/' . $ctaHref;
        }
        $ctaUrl = $ctaHref !== '' ? url_to($ctaHref) : '';
        ?>
<section class="at-section at-section--white">
    <div class="at-container grid md:grid-cols-2 gap-8 lg:gap-14 items-center">
        <div class="rounded-[var(--at-radius-lg)] overflow-hidden aspect-[4/3] bg-[var(--at-cream-2)]">
            <img src="<?= Security::escape(asset_url($imagePath)) ?>" alt="" class="w-full h-full object-cover" loading="lazy" width="1024" height="768">
        </div>
        <div>
            <?php if ($eyebrow !== ''): ?><p class="at-eyebrow"><?= Security::escape($eyebrow) ?></p><?php endif; ?>
            <h2 class="at-h2 mt-1"><?= Security::escape($heading) ?></h2>
            <?php if ($body !== ''): ?><p class="at-lead mt-3"><?= Security::escape($body) ?></p><?php endif; ?>
            <ul class="mt-5 grid gap-3 text-[0.9375rem]">
                <?php foreach ($section['bullets'] ?? [] as $bullet): ?>
                    <?php $b = HomepageTrustService::sanitizeText($bullet, 255); if ($b === '') { continue; } ?>
                <li class="flex gap-3"><span class="text-[var(--at-green)]"><?= at_icon('check', 'w-5 h-5') ?></span><?= Security::escape($b) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($ctaLabel !== '' && $ctaUrl !== ''): ?>
            <a href="<?= Security::escape($ctaUrl) ?>" class="at-btn at-btn--primary at-focusable mt-6"><?= Security::escape($ctaLabel) ?></a>
            <?php endif; ?>
        </div>
    </div>
</section>
        <?php
    }

    /** @param array<string, mixed> $section */
    private static function renderTestimonials(array $section, string $base): void
    {
        $heading = HomepageTrustService::sanitizeText($section['heading'] ?? '', 191);
        if ($heading === '') {
            return;
        }
        $subtitle = HomepageTrustService::sanitizeText($section['subtitle'] ?? '', 500);
        $featuredTestimonials = Install::isSchemaReady() ? TestimonialService::featuredPublic(4) : [];
        ?>
<section class="at-section">
    <div class="at-container">
        <div class="at-section-head">
            <div>
                <h2 class="at-h2"><?= Security::escape($heading) ?></h2>
                <?php if ($subtitle !== ''): ?><p><?= Security::escape($subtitle) ?></p><?php endif; ?>
            </div>
        </div>
        <?php if ($featuredTestimonials !== []): ?>
        <div class="grid md:grid-cols-2 gap-4 max-w-4xl">
            <?php foreach ($featuredTestimonials as $t): ?>
            <article class="at-quote">
                <p class="text-[1.0625rem] leading-relaxed">“<?= Security::escape((string) ($t['body'] ?? '')) ?>”</p>
                <p class="mt-4 flex items-center gap-3 text-sm"><span class="at-avatar"><?= Security::escape(mb_substr((string) ($t['customer_display_name'] ?? 'C'), 0, 1)) ?></span><span><strong><?= Security::escape((string) ($t['customer_display_name'] ?? 'Customer')) ?></strong><br><span class="at-muted">Customer story · consent on file</span></span></p>
            </article>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="grid md:grid-cols-2 gap-4 max-w-4xl">
            <article class="at-quote !bg-[var(--at-green-50)] border-dashed">
                <h3 class="at-h3">Real stories, when customers share them</h3>
                <p class="mt-2 text-sm at-muted">We publish only approved testimonials with recorded consent. Product reviews on each item come from delivered orders.</p>
                <?php require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php'; ?>
                <a href="<?= Security::escape(catalogue_primary_category_url()) ?>" class="at-btn at-btn--secondary at-btn--sm at-focusable mt-4">Start shopping</a>
            </article>
        </div>
        <?php endif; ?>
    </div>
</section>
        <?php
    }

    /** @param array<string, mixed> $section */
    private static function renderFaqSupport(array $section, string $base, ?object $catalogue): void
    {
        $heading = HomepageTrustService::sanitizeText($section['heading'] ?? '', 191);
        if ($heading === '') {
            return;
        }
        if ($catalogue === null) {
            require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';
            $catalogue = demo_catalogue();
        }
        $limit = max(1, min(10, (int) ($section['faq_limit'] ?? 5)));
        $faqs = $catalogue->faqs($limit);
        if ($faqs === []) {
            return;
        }
        $eyebrow = HomepageTrustService::sanitizeText($section['eyebrow'] ?? '', 120);
        $body = HomepageTrustService::sanitizeText($section['body'] ?? '', 2000);
        ?>
<section class="at-section at-section--white">
    <div class="at-container grid lg:grid-cols-[1fr_1.6fr] gap-8 lg:gap-14">
        <div>
            <?php if ($eyebrow !== ''): ?><p class="at-eyebrow"><?= Security::escape($eyebrow) ?></p><?php endif; ?>
            <h2 class="at-h2 mt-1"><?= Security::escape($heading) ?></h2>
            <?php if ($body !== ''): ?><p class="at-lead mt-3"><?= Security::escape($body) ?></p><?php endif; ?>
            <div class="mt-5 flex gap-3 flex-wrap">
                <a href="<?= Security::escape($base) ?>/faqs.php" class="at-btn at-btn--secondary at-focusable">All FAQs</a>
                <a href="<?= Security::escape($base) ?>/contact.php" class="at-btn at-btn--ghost at-focusable">Contact us</a>
            </div>
        </div>
        <div>
            <?php foreach ($faqs as $faq): ?>
                <details class="at-faq">
                    <summary><?= Security::escape($faq['q']) ?></summary>
                    <div class="at-faq__a"><?= Security::escape($faq['a']) ?></div>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>
        <?php
    }
}
