<?php

declare(strict_types=1);

/**
 * Shared promotional offer card (home, category, product).
 *
 * @var array<string, mixed> $offer
 * @var string|null $offerDealContext 'home'|'category'|'product' — hides a CTA that would link to the current page
 */
$offer = $offer ?? [];
$offerDealContext = $offerDealContext ?? 'home';
$hasTimer = !empty($offer['countdown_end_unix']);
$scope = (string) ($offer['scope'] ?? 'store');
$ctaUrl = isset($offer['cta_url']) ? (string) $offer['cta_url'] : '';
if (($offerDealContext === 'category' && $scope === 'category') || ($offerDealContext === 'product' && $scope === 'product')) {
    $ctaUrl = '';
}
$badge = $hasTimer ? 'Limited time offer' : 'Special offer';
?>
<article class="at-offer-deal<?= $hasTimer ? '' : ' at-offer-deal--no-timer' ?>">
    <div class="at-offer-deal__info">
        <span class="at-offer-deal__badge"><?= Security::escape($badge) ?></span>
        <p class="at-offer-deal__headline"><?= Security::escape((string) ($offer['headline'] ?? '')) ?></p>
        <p class="at-offer-deal__name"><?= Security::escape((string) ($offer['name'] ?? '')) ?></p>
        <?php if (trim((string) ($offer['description'] ?? '')) !== '' && (string) $offer['description'] !== (string) ($offer['name'] ?? '')): ?>
            <p class="at-offer-deal__desc"><?= Security::escape((string) $offer['description']) ?></p>
        <?php endif; ?>
        <?php if ($ctaUrl !== ''): ?>
            <a href="<?= Security::escape($ctaUrl) ?>" class="at-offer-deal__cta at-focusable">Shop this offer <?= at_icon('arrow-right', 'w-4 h-4') ?></a>
        <?php endif; ?>
    </div>
    <?php if ($hasTimer): ?>
        <div class="at-offer-deal__timer">
            <?php
            $countdownEndUnix = (int) $offer['countdown_end_unix'];
            require APNI_TOKRI_ROOT . '/includes/partials/offer-countdown.php';
            ?>
        </div>
    <?php endif; ?>
</article>
