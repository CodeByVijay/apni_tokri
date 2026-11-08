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
            <ul class="grid gap-3 md:grid-cols-2">
                <?php foreach ($storefrontOffers as $offer): ?>
                    <li class="at-card at-card--flat border border-[var(--at-line)]">
                        <div class="at-card__body !py-4">
                            <p class="font-bold text-[var(--at-green)]"><?= Security::escape((string) ($offer['headline'] ?? '')) ?></p>
                            <p class="text-sm mt-1"><?= Security::escape((string) ($offer['description'] ?? '')) ?></p>
                            <?php if (!empty($offer['countdown_end_unix'])): ?>
                                <?php
                                $countdownEndUnix = (int) $offer['countdown_end_unix'];
                                $countdownPrefix = 'Offer ends in ';
                                require APNI_TOKRI_ROOT . '/includes/partials/offer-countdown.php';
                                ?>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <a href="<?= Security::escape($base) ?>/cart.php" class="at-btn at-btn--secondary at-btn--sm at-focusable">Shop and save</a>
        </div>
    </div>
</section>
