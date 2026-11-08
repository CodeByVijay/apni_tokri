<?php

declare(strict_types=1);

/** @var int $countdownEndUnix server-side expiry (Asia/Kolkata → unix) */
/** @var string|null $countdownPrefix */
$countdownPrefix = $countdownPrefix ?? 'Offer ends in';
if ($countdownEndUnix <= 0) {
    return;
}
?>
<p class="at-offer-countdown text-sm font-semibold text-[var(--at-yellow-ink)] mt-1" data-at-offer-countdown data-countdown-end="<?= (int) $countdownEndUnix ?>" role="status">
    <?= Security::escape((string) $countdownPrefix) ?>
    <span data-at-offer-countdown-value class="tabular-nums" aria-live="polite"></span>
</p>
