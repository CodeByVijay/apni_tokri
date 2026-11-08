<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $applicableOffers */
$applicableOffers = $applicableOffers ?? [];
if ($applicableOffers === []) {
    return;
}
?>
<div class="mt-3 space-y-2" aria-label="Active offers on this page">
    <?php foreach ($applicableOffers as $offer): ?>
        <div class="at-card at-card--flat border border-dashed border-[var(--at-green)]/40">
            <div class="at-card__body !py-3">
                <p class="text-sm font-bold text-[var(--at-green)]"><?= Security::escape((string) ($offer['headline'] ?? '')) ?></p>
                <p class="text-xs at-muted"><?= Security::escape((string) ($offer['description'] ?? '')) ?></p>
                <?php if (!empty($offer['countdown_end_unix'])): ?>
                    <?php
                    $countdownEndUnix = (int) $offer['countdown_end_unix'];
                    $countdownPrefix = 'Offer ends in ';
                    require APNI_TOKRI_ROOT . '/includes/partials/offer-countdown.php';
                    ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
