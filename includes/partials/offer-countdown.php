<?php

declare(strict_types=1);

/** @var int $countdownEndUnix server-side expiry (Asia/Kolkata → unix) */
if ($countdownEndUnix <= 0) {
    return;
}
?>
<div class="at-offer-countdown" data-at-offer-countdown data-countdown-end="<?= (int) $countdownEndUnix ?>" role="status" aria-label="Offer countdown">
    <span class="at-offer-countdown__head">Offer ends in</span>
    <span class="at-offer-countdown__sr" data-at-offer-countdown-value aria-live="polite"></span>
    <div class="at-offer-countdown__units" aria-hidden="true">
        <span class="at-offer-countdown__unit">
            <span class="at-offer-countdown__num" data-at-offer-countdown-days>00</span>
            <span class="at-offer-countdown__lbl">Days</span>
        </span>
        <span class="at-offer-countdown__unit">
            <span class="at-offer-countdown__num" data-at-offer-countdown-hours>00</span>
            <span class="at-offer-countdown__lbl">Hours</span>
        </span>
        <span class="at-offer-countdown__unit">
            <span class="at-offer-countdown__num" data-at-offer-countdown-mins>00</span>
            <span class="at-offer-countdown__lbl">Minutes</span>
        </span>
        <span class="at-offer-countdown__unit">
            <span class="at-offer-countdown__num" data-at-offer-countdown-secs>00</span>
            <span class="at-offer-countdown__lbl">Seconds</span>
        </span>
    </div>
</div>
