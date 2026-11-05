<?php

declare(strict_types=1);

/** Map pin step for the “Check delivery area” modal (distance-hybrid mode only). */
?>
<div id="at-delivery-map-step" class="hidden space-y-3" data-at-delivery-map-step>
    <button type="button" class="text-sm font-semibold text-[var(--at-green)] at-focusable" data-at-delivery-back-pin>&larr; Back to pincode</button>
    <p class="text-sm font-semibold">Select delivery location on map</p>
    <p class="text-sm at-muted">Tap the map or use your current location. We use this point to check distance-based delivery — not your pincode alone.</p>
    <form id="at-delivery-map-form" class="at-delivery-map-form" data-at-address-form novalidate>
        <input type="hidden" name="latitude" value="">
        <input type="hidden" name="longitude" value="">
        <input type="hidden" name="location_confirmed" value="0">
        <div class="at-addr-gps">
            <button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable at-addr-gps__btn" data-addr-gps>
                <?= at_icon('pin', 'w-5 h-5') ?><span data-label>Use my current location</span><span class="at-addr-spinner" aria-hidden="true"></span>
            </button>
        </div>
        <p class="at-addr-msg" data-addr-map-msg role="status" aria-live="polite" hidden></p>
        <div class="at-addr-mapwrap at-delivery-mapwrap">
            <div class="at-addr-map" data-addr-map role="application" aria-label="Map for choosing your delivery location. Tap to place the pin or drag it."></div>
            <div class="at-addr-map__loading" data-addr-map-loading><span class="at-addr-spinner is-visible" aria-hidden="true"></span> Loading map…</div>
        </div>
        <p class="at-addr-status text-sm" data-addr-pin-status data-state="none" aria-live="polite">No pin placed yet. Tap the map or use your current location.</p>
        <div class="at-addr-actions flex flex-wrap gap-2">
            <button type="button" class="at-btn at-btn--primary at-focusable" data-addr-confirm-loc disabled>Confirm this location</button>
            <button type="button" class="at-btn at-btn--ghost at-btn--sm at-focusable" data-addr-clear-pin hidden>Remove pin</button>
        </div>
    </form>
</div>
