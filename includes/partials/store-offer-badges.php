<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $applicableOffers */
$applicableOffers = $applicableOffers ?? [];
if ($applicableOffers === []) {
    return;
}
?>
<div class="at-offer-deal-stack mb-4" aria-label="Active offers">
    <?php foreach ($applicableOffers as $offer): ?>
        <?php require APNI_TOKRI_ROOT . '/includes/partials/offer-deal-compact.php'; ?>
    <?php endforeach; ?>
</div>
