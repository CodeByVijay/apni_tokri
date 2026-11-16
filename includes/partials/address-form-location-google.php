<?php

declare(strict_types=1);

/**
 * Shared server-side address search block (Google Places or Photon via /ajax/address/).
 * Expects: $uid, $acCfg (AddressAutocompleteSettings::publicClientConfig()), $err('location')
 */
$acCfg = $acCfg ?? AddressAutocompleteSettings::publicClientConfig();
$acOn = !empty($acCfg['searchEnabled']);
$gpsOn = !empty($acCfg['gpsEnabled']);
$provider = (string) ($acCfg['provider'] ?? '');
$providerLabel = $provider === AddressAutocompleteSettings::PROVIDER_GOOGLE ? 'Google' : 'OpenStreetMap';
?>
    <section class="at-addr-step" data-addr-step="location" aria-labelledby="<?= Security::escape($uid) ?>-h-loc">
        <h3 class="at-addr-step__title" id="<?= Security::escape($uid) ?>-h-loc"><span class="at-addr-step__num">2</span>Search your address</h3>
        <p class="at-addr-hint">Start typing your building, street or area. Select a suggestion, then complete house and flat details below.</p>
        <?php if ($acOn): ?>
        <div class="at-addr-search" data-addr-search-wrap>
            <label class="at-label" for="<?= Security::escape($uid) ?>-addr-ac-search">Search address</label>
            <div class="at-addr-search__field">
                <input id="<?= Security::escape($uid) ?>-addr-ac-search" type="text" class="at-input at-focusable" data-addr-ac-search role="combobox" autocomplete="off" maxlength="200" placeholder="Search house, building, area or PIN code..." aria-autocomplete="list" aria-expanded="false" aria-controls="<?= Security::escape($uid) ?>-addr-ac-list">
                <span class="at-addr-search__spinner at-addr-spinner" data-addr-ac-busy aria-hidden="true" hidden></span>
            </div>
            <p class="at-addr-search__hint at-addr-hint text-sm">Suggestions from <?= Security::escape($providerLabel) ?>. Verify fields before saving.</p>
            <p class="at-addr-search__status" data-addr-ac-status role="status" aria-live="polite" hidden></p>
            <ul id="<?= Security::escape($uid) ?>-addr-ac-list" class="at-addr-search__list" data-addr-ac-list role="listbox" aria-label="Address suggestions" hidden></ul>
        </div>
        <?php else: ?>
        <p class="at-addr-msg text-sm" data-kind="info">Address search is unavailable. Enter your full address in the fields below.</p>
        <?php endif; ?>
        <?php if ($gpsOn): ?>
        <div class="at-addr-gps">
            <button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable at-addr-gps__btn" data-addr-gps>
                <?= at_icon('pin', 'w-5 h-5') ?><span data-label>Use my current location</span><span class="at-addr-spinner" aria-hidden="true"></span>
            </button>
            <?php if ($acOn): ?>
            <button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable at-addr-gps__btn at-addr-geo-search" data-addr-geo-search hidden>
                <?= at_icon('search', 'w-5 h-5') ?><span data-label>Search this area on <?= Security::escape($providerLabel) ?></span>
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <p class="at-addr-msg" data-addr-map-msg role="status" aria-live="polite" hidden></p>
        <?= $err('location') ?>
    </section>
