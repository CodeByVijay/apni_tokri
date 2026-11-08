<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $storefrontOffers */
$storefrontOffers = $storefrontOffers ?? [];
if ($storefrontOffers === []) {
    return;
}
$base = url_base();
?>
<section class="at-container pb-4" aria-label="Active offers">
    <div class="at-card at-card--flat">
        <div class="at-card__body space-y-4">
            <h2 class="at-h3 font-bold flex items-center gap-2"><?= at_icon('tag', 'w-5 h-5 text-[var(--at-green)]') ?> Deals for you</h2>
            <ul class="at-offer-deal-stack grid gap-3 lg:grid-cols-2">
                <?php foreach ($storefrontOffers as $offer): ?>
                    <li>
                        <?php require APNI_TOKRI_ROOT . '/includes/partials/offer-deal-compact.php'; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <a href="<?= Security::escape($base) ?>/cart.php" class="at-btn at-btn--secondary at-btn--sm at-focusable">Shop and save</a>
        </div>
    </div>
</section>
