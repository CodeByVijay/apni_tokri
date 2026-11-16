<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $storefrontOffers */
$storefrontOffers = $storefrontOffers ?? [];
if ($storefrontOffers === []) {
    return;
}
$offerDealContext = 'home';
?>
<section class="at-container at-home-offers pb-6" aria-label="Active offers">
    <h2 class="at-h3 font-bold flex items-center gap-2 mb-3"><?= at_icon('tag', 'w-5 h-5 text-[var(--at-green)]') ?> Deals for you</h2>
    <ul class="at-home-offer-list grid gap-3 lg:grid-cols-2 list-none p-0 m-0">
        <?php foreach ($storefrontOffers as $offer): ?>
            <li class="flex"><div class="w-full"><?php require APNI_TOKRI_ROOT . '/includes/partials/offer-deal-compact.php'; ?></div></li>
        <?php endforeach; ?>
    </ul>
</section>
