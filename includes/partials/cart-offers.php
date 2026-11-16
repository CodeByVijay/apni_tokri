<?php

declare(strict_types=1);

/**
 * Cart "Available offers" (server-rendered; all eligibility comes from CouponOfferService).
 *
 * @var list<array<string, mixed>> $offers
 */
$offers = $offers ?? [];
if ($offers === []) {
    echo '<section id="at-offers" data-at-offers hidden></section>';

    return;
}
$preview = array_values(array_filter($offers, static function (array $o): bool {
    return !empty($o['preview']);
}));
if ($preview === []) {
    $preview = array_slice($offers, 0, 1);
}
$eligibleCount = count(array_filter($offers, static function (array $o): bool {
    return !empty($o['eligible']);
}));
$moreBeyondPreview = count($offers) > count($preview);
$moreOnNarrowOnly = !$moreBeyondPreview && count($preview) > CouponOfferService::MAX_PREVIEW_MOBILE;
$hasMore = $moreBeyondPreview || $moreOnNarrowOnly;

$card = static function (array $o, bool $compact, int $idx = 0): string {
    $state = !empty($o['applied']) ? 'is-applied' : (!empty($o['eligible']) ? 'is-eligible' : 'is-locked');
    $cls = 'at-coffer ' . $state . (!empty($o['best']) && empty($o['applied']) ? ' is-best' : '')
        . ($compact ? ' at-coffer--row' : '') . (!$compact && $idx >= CouponOfferService::MAX_PREVIEW_MOBILE ? ' at-coffer--wide-only' : '');
    $code = Security::escape((string) $o['code']);
    ob_start();
    ?>
    <article class="<?= $cls ?>" data-offer-code="<?= $code ?>">
        <div class="at-coffer__main">
            <p class="at-coffer__value"><?= Security::escape((string) $o['headline']) ?>
                <?php if (!empty($o['applied'])): ?><span class="at-coffer__badge at-coffer__badge--applied">Applied</span>
                <?php elseif (!empty($o['best'])): ?><span class="at-coffer__badge">Best savings</span><?php endif; ?>
            </p>
            <p class="at-coffer__code"><?= $code ?></p>
            <p class="at-coffer__desc"><?= Security::escape((string) $o['description']) ?></p>
            <?php if (!empty($o['eligible'])): ?>
                <p class="at-coffer__meta at-coffer__meta--save">You save <?= Security::escape(catalogue_format_price((float) $o['discount'])) ?> on this cart<?php if ($o['max_discount'] !== null): ?> · max ₹<?= (int) $o['max_discount'] ?><?php endif; ?></p>
            <?php else: ?>
                <p class="at-coffer__meta"><?= Security::escape((string) $o['hint']) ?></p>
            <?php endif; ?>
            <?php if (!$compact && !empty($o['tags'])): ?>
                <p class="at-coffer__tags"><?= Security::escape(implode(' · ', $o['tags'])) ?></p>
            <?php endif; ?>
        </div>
        <div class="at-coffer__action">
            <?php if (!empty($o['applied'])): ?>
                <span class="at-coffer__applied-mark" aria-label="Coupon applied"><?= at_icon('check-circle', 'w-5 h-5') ?></span>
            <?php elseif (!empty($o['eligible'])): ?>
                <button type="button" class="at-btn at-btn--primary at-btn--sm at-coffer__btn at-focusable" data-at-offer-apply data-code="<?= $code ?>" aria-label="Apply coupon <?= $code ?>">Apply</button>
            <?php else: ?>
                <button type="button" class="at-btn at-btn--ghost at-btn--sm at-coffer__btn" disabled aria-disabled="true">Locked</button>
            <?php endif; ?>
        </div>
    </article>
    <?php

    return (string) ob_get_clean();
};
?>
<section id="at-offers" class="at-offers" data-at-offers aria-labelledby="at-offers-title">
    <div class="at-offers__head">
        <h2 id="at-offers-title" class="at-offers__title"><?= at_icon('tag', 'w-4 h-4 text-[var(--at-green)]') ?>Available offers<?php if ($eligibleCount > 0): ?> <span class="at-offers__count"><?= (int) $eligibleCount ?> for you</span><?php endif; ?></h2>
        <?php if ($hasMore): ?>
            <button type="button" class="at-offers__all at-focusable<?= $moreOnNarrowOnly ? ' at-offers__all--narrow' : '' ?>" data-at-offers-open aria-haspopup="dialog" aria-controls="at-offers-sheet">View all (<?= count($offers) ?>)</button>
        <?php endif; ?>
    </div>
    <div class="at-offers__track" role="list">
        <?php foreach ($preview as $i => $o): ?>
            <div role="listitem" class="at-offers__cell"><?= $card($o, false, $i) ?></div>
        <?php endforeach; ?>
    </div>
    <?php if ($hasMore): ?>
        <div id="at-offers-sheet" class="at-modal-backdrop at-offers-sheet" data-at-offers-sheet role="dialog" aria-modal="true" aria-labelledby="at-offers-sheet-title" aria-hidden="true">
            <div class="at-modal">
                <div class="at-modal__header">
                    <h3 id="at-offers-sheet-title" class="at-modal__title">All offers</h3>
                    <button type="button" class="at-close at-focusable" data-at-offers-close aria-label="Close offers">×</button>
                </div>
                <div class="at-modal__body at-offers-sheet__list">
                    <?php foreach ($offers as $o): ?>
                        <?= $card($o, true) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
