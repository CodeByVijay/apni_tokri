<?php

declare(strict_types=1);

/** @var array<string, mixed> $offer */
$offer = $offer ?? [];
?>
<article class="at-offer-deal">
    <div class="at-offer-deal__info">
        <p class="at-offer-deal__headline"><?= Security::escape((string) ($offer['headline'] ?? '')) ?></p>
        <p class="at-offer-deal__desc"><?= Security::escape((string) ($offer['description'] ?? '')) ?></p>
    </div>
    <?php if (!empty($offer['countdown_end_unix'])): ?>
        <div class="at-offer-deal__timer">
            <?php
            $countdownEndUnix = (int) $offer['countdown_end_unix'];
            require APNI_TOKRI_ROOT . '/includes/partials/offer-countdown.php';
            ?>
        </div>
    <?php endif; ?>
</article>
