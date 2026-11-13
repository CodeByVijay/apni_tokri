<?php

declare(strict_types=1);

/**
 * Compact delivery address form for admin-assisted order creation (no map).
 */
?>
<div class="at-assisted-order-address-form space-y-4" data-at-aorder-address-form hidden>
    <p class="text-sm at-muted">Saved to the customer account. Delivery zones use the same rules as storefront checkout.</p>
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
            <div data-at-aorder-addr-label-custom-wrap hidden class="mt-2">
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
    <div class="flex flex-wrap gap-2">
        <button type="button" class="at-btn at-btn--primary at-focusable" data-at-aorder-addr-save>Save address</button>
        <button type="button" class="at-btn at-btn--ghost at-focusable" data-at-aorder-addr-cancel>Use existing instead</button>
    </div>
    <p class="text-sm text-red-700" data-at-aorder-addr-form-error hidden role="alert"></p>
</div>
