<?php

declare(strict_types=1);

/**
 * Compact delivery address form for admin-assisted order creation.
 *
 * Reuses the storefront autocomplete module (assets/js/address-autocomplete.js):
 * hooks data-at-address-form + data-addr-checkout-autocomplete bind it here.
 * No provider keys are exposed — search goes through /ajax/address/*.
 */
$acCfg = AddressAutocompleteSettings::publicClientConfig();
$acOn = !empty($acCfg['searchEnabled']);
$gpsOn = !empty($acCfg['gpsEnabled']);
$acProviderLabel = ((string) ($acCfg['provider'] ?? '')) === AddressAutocompleteSettings::PROVIDER_GOOGLE
    ? 'Google'
    : 'OpenStreetMap';
?>
<div class="at-assisted-order-address-form space-y-4" data-at-aorder-address-form data-at-address-form data-addr-checkout-autocomplete="1" hidden>
    <input type="hidden" name="latitude" value="">
    <input type="hidden" name="longitude" value="">
    <input type="hidden" name="location_confirmed" value="0">
    <p class="text-sm at-muted">Saved to the customer account. Delivery zones use the same rules as storefront checkout.</p>

    <?php if ($acOn): ?>
    <div class="at-addr-search" data-addr-search-wrap>
        <label class="at-label" for="at-aorder-addr-ac-search">Search building, area or landmark…</label>
        <div class="at-addr-search__field">
            <input id="at-aorder-addr-ac-search" type="text" class="at-input at-focusable" data-addr-ac-search
                   role="combobox" autocomplete="off" maxlength="200"
                   placeholder="Search building, area or landmark..."
                   aria-autocomplete="list" aria-expanded="false" aria-controls="at-aorder-addr-ac-list">
            <span class="at-addr-search__spinner at-addr-spinner" data-addr-ac-busy aria-hidden="true" hidden></span>
        </div>
        <p class="at-addr-search__hint text-xs at-muted mt-1">Suggestions from <?= Security::escape($acProviderLabel) ?>. Verify every field before saving.</p>
        <p class="at-addr-search__status text-sm" data-addr-ac-status role="status" aria-live="polite" hidden></p>
        <ul id="at-aorder-addr-ac-list" class="at-addr-search__list" data-addr-ac-list role="listbox" aria-label="Address suggestions" hidden></ul>
    </div>
    <?php else: ?>
    <p class="text-sm at-muted">Address search is not configured. Enter the address manually below.</p>
    <?php endif; ?>
    <?php if ($gpsOn): ?>
    <div class="at-addr-gps">
        <button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable at-addr-gps__btn" data-addr-gps>
            <?= at_icon('pin', 'w-5 h-5') ?><span data-label>Use customer's current location</span><span class="at-addr-spinner" aria-hidden="true"></span>
        </button>
        <?php if ($acOn): ?>
        <button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable at-addr-gps__btn at-addr-geo-search" data-addr-geo-search hidden>
            <?= at_icon('search', 'w-5 h-5') ?><span data-label>Search this area on <?= Security::escape($acProviderLabel) ?></span>
        </button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <p class="at-addr-msg text-sm" data-addr-map-msg role="status" aria-live="polite" hidden></p>
    <p class="at-addr-msg text-sm" data-addr-detected-hint hidden role="status">Some fields were filled from the address search. Please verify and complete everything before saving.</p>

    <div class="at-addr-grid grid gap-3 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <span class="at-label">Address type</span>
            <div class="flex flex-wrap gap-3 mt-1" role="radiogroup" aria-label="Address type">
                <?php foreach (['Home', 'Office', 'Other'] as $lt): ?>
                <label class="flex items-center gap-2 text-sm">
                    <input type="radio" name="label_type" value="<?= Security::escape($lt) ?>" class="at-focusable" data-at-aorder-addr-label-type <?= $lt === 'Home' ? 'checked' : '' ?>>
                    <?= Security::escape($lt) ?>
                </label>
                <?php endforeach; ?>
            </div>
            <div data-at-aorder-addr-label-custom-wrap data-addr-label-custom hidden class="mt-2">
                <label class="at-label" for="at-aorder-addr-label-custom">Custom label</label>
                <input id="at-aorder-addr-label-custom" type="text" class="at-input at-focusable w-full max-w-md" name="label_custom" maxlength="64" data-at-aorder-addr-label-custom>
            </div>
            <p class="text-xs text-red-700 mt-1" data-at-aorder-err="label" hidden></p>
        </div>
        <div>
            <label class="at-label" for="at-aorder-addr-house">House / flat / door no. *</label>
            <input id="at-aorder-addr-house" class="at-input at-focusable w-full" name="house_number" maxlength="100" data-at-aorder-addr-field required>
            <p class="text-xs text-red-700 mt-1" data-at-aorder-err="house_number" hidden></p>
        </div>
        <div>
            <label class="at-label" for="at-aorder-addr-building">Building / society (optional)</label>
            <input id="at-aorder-addr-building" class="at-input at-focusable w-full" name="building_name" maxlength="150" data-at-aorder-addr-field>
            <p class="text-xs text-red-700 mt-1" data-at-aorder-err="building_name" hidden></p>
        </div>
        <div>
            <label class="at-label" for="at-aorder-addr-street">Street / road *</label>
            <input id="at-aorder-addr-street" class="at-input at-focusable w-full" name="street" maxlength="150" data-at-aorder-addr-field required>
            <p class="text-xs text-red-700 mt-1" data-at-aorder-err="street" hidden></p>
        </div>
        <div>
            <label class="at-label" for="at-aorder-addr-area">Area / locality *</label>
            <input id="at-aorder-addr-area" class="at-input at-focusable w-full" name="area" maxlength="150" data-at-aorder-addr-field required>
            <p class="text-xs text-red-700 mt-1" data-at-aorder-err="area" hidden></p>
        </div>
        <div>
            <label class="at-label" for="at-aorder-addr-city">City *</label>
            <input id="at-aorder-addr-city" class="at-input at-focusable w-full" name="addr_city" maxlength="128" value="Indore" data-at-aorder-addr-field required>
            <p class="text-xs text-red-700 mt-1" data-at-aorder-err="addr_city" hidden></p>
        </div>
        <div>
            <label class="at-label" for="at-aorder-addr-state">State *</label>
            <input id="at-aorder-addr-state" class="at-input at-focusable w-full" name="addr_state" maxlength="128" value="Madhya Pradesh" data-at-aorder-addr-field required>
            <p class="text-xs text-red-700 mt-1" data-at-aorder-err="addr_state" hidden></p>
        </div>
        <div>
            <label class="at-label" for="at-aorder-addr-pin">Pincode *</label>
            <input id="at-aorder-addr-pin" class="at-input at-focusable w-full" name="pincode" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" data-at-aorder-addr-field required>
            <p class="text-xs mt-1" data-at-aorder-pin-preview role="status" aria-live="polite" hidden></p>
            <p class="text-xs text-red-700 mt-1" data-at-aorder-err="pincode" hidden></p>
        </div>
        <div class="sm:col-span-2">
            <label class="at-label" for="at-aorder-addr-landmark">Landmark</label>
            <input id="at-aorder-addr-landmark" class="at-input at-focusable w-full" name="landmark" maxlength="191" data-at-aorder-addr-field>
        </div>
        <div>
            <label class="at-label" for="at-aorder-addr-rname">Receiver name *</label>
            <input id="at-aorder-addr-rname" class="at-input at-focusable w-full" name="receiver_name" maxlength="100" data-at-aorder-addr-field required>
            <p class="text-xs text-red-700 mt-1" data-at-aorder-err="receiver_name" hidden></p>
        </div>
        <div>
            <label class="at-label" for="at-aorder-addr-rphone">Receiver mobile *</label>
            <input id="at-aorder-addr-rphone" class="at-input at-focusable w-full" name="receiver_phone" type="tel" maxlength="16" data-at-aorder-addr-field required>
            <p class="text-xs text-red-700 mt-1" data-at-aorder-err="receiver_phone" hidden></p>
        </div>
        <div class="sm:col-span-2">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" class="at-focusable" name="make_default" value="1" data-at-aorder-addr-make-default>
                Set as customer default address
            </label>
        </div>
    </div>
    <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm" data-at-aorder-addr-dupes hidden role="alert"></div>
    <div class="flex flex-wrap gap-2">
        <button type="button" class="at-btn at-btn--primary at-focusable" data-at-aorder-addr-save>Save and select address</button>
        <button type="button" class="at-btn at-btn--ghost at-focusable" data-at-aorder-addr-cancel>Back to saved addresses</button>
    </div>
    <p class="text-sm text-red-700" data-at-aorder-addr-form-error hidden role="alert"></p>
</div>
