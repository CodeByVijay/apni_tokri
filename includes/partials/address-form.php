<?php

declare(strict_types=1);

/**
 * Delivery address form with Google Places search (my-addresses.php).
 *
 * Expected variables:
 * @var string               $addrFormAction     form action URL
 * @var string               $addrFormMode       'add' | 'update'
 * @var int                  $addrFormId         address id when updating
 * @var array<string,string> $addrFormValues     field values (AddressInput::blankFormValues / formValuesFromRow / formValuesFromPost)
 * @var array<string,string> $addrFormErrors     field => message
 * @var string               $addrFormCancelUrl
 * @var bool                 $addrFormShowDefault show "make default" checkbox (add mode)
 * @var array<string,string> $addrFormHidden     extra hidden fields (e.g. return flow markers)
 * @var string               $addrFormUid        unique DOM id prefix
 */
$uid = isset($addrFormUid) && is_string($addrFormUid) && $addrFormUid !== '' ? $addrFormUid : 'af';
$vals = isset($addrFormValues) && is_array($addrFormValues) ? $addrFormValues : AddressInput::blankFormValues(null);
$errs = isset($addrFormErrors) && is_array($addrFormErrors) ? $addrFormErrors : [];
$mode = ($addrFormMode ?? 'add') === 'update' ? 'update' : 'add';
$hidden = isset($addrFormHidden) && is_array($addrFormHidden) ? $addrFormHidden : [];
$v = static function (string $k) use ($vals): string {
    return Security::escape((string) ($vals[$k] ?? ''));
};
$err = static function (string $k) use ($errs): string {
    return isset($errs[$k])
        ? '<p class="at-field-hint at-field-hint--error" role="alert">' . Security::escape((string) $errs[$k]) . '</p>'
        : '';
};
$invalid = static function (string $k) use ($errs): string {
    return isset($errs[$k]) ? ' aria-invalid="true"' : '';
};
$labelType = (string) ($vals['label_type'] ?? 'Home');
$acCfg = $addrAutocompleteClientConfig ?? AddressAutocompleteSettings::publicClientConfig();
?>
<form method="post" action="<?= Security::escape((string) $addrFormAction) ?>" class="at-addr-form" data-at-address-form data-addr-checkout-autocomplete="1" id="<?= Security::escape($uid) ?>-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="<?= Security::escape($mode === 'update' ? (string) ($addrFormUpdateAction ?? 'update') : (string) ($addrFormAddAction ?? 'add')) ?>">
    <?php if ($mode === 'update'): ?><input type="hidden" name="address_id" value="<?= (int) ($addrFormId ?? 0) ?>"><?php endif; ?>
    <?php foreach ($hidden as $hk => $hv): ?>
        <input type="hidden" name="<?= Security::escape((string) $hk) ?>" value="<?= Security::escape((string) $hv) ?>">
    <?php endforeach; ?>
    <input type="hidden" name="addr_checkout_autocomplete" value="1">
    <input type="hidden" name="latitude" value="<?= $v('latitude') ?>">
    <input type="hidden" name="longitude" value="<?= $v('longitude') ?>">
    <input type="hidden" name="location_confirmed" value="<?= $v('location_confirmed') === '1' || ($v('latitude') !== '' && $v('longitude') !== '') ? '1' : '0' ?>">

    <?php if ($errs !== []): ?>
        <div class="at-alert at-alert--error" role="alert"><span>Please check the highlighted fields and try again.</span></div>
    <?php endif; ?>
    <?php if (isset($errs['form'])): ?>
        <div class="at-alert at-alert--error" role="alert"><span><?= Security::escape((string) $errs['form']) ?></span></div>
    <?php endif; ?>

    <!-- Step A -->
    <section class="at-addr-step" data-addr-step="type" aria-labelledby="<?= Security::escape($uid) ?>-h-type">
        <h3 class="at-addr-step__title" id="<?= Security::escape($uid) ?>-h-type"><span class="at-addr-step__num">1</span>Address type</h3>
        <div class="at-addr-types" role="radiogroup" aria-label="Address type">
            <?php foreach ([['Home', 'Home'], ['Office', 'Office'], ['Other', 'Other']] as [$tv, $tl]): ?>
                <label class="at-addr-type<?= $labelType === $tv ? ' is-selected' : '' ?>">
                    <input type="radio" name="label_type" value="<?= $tv ?>" <?= $labelType === $tv ? 'checked' : '' ?>>
                    <span class="at-addr-type__icon" aria-hidden="true"><?= $tv === 'Home' ? at_icon('home', 'w-5 h-5') : ($tv === 'Office' ? at_icon('bag', 'w-5 h-5') : at_icon('pin', 'w-5 h-5')) ?></span>
                    <span class="at-addr-type__text"><?= Security::escape($tl) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <div data-addr-label-custom <?= $labelType === 'Other' ? '' : 'hidden' ?> class="mt-3">
            <label class="at-label" for="<?= Security::escape($uid) ?>-label-custom">Name this address</label>
            <input id="<?= Security::escape($uid) ?>-label-custom" name="label_custom" class="at-input at-focusable" maxlength="64" value="<?= $v('label_custom') ?>" placeholder="e.g. Parents’ house"<?= $invalid('label') ?>>
        </div>
        <?= $err('label') ?>
    </section>

    <?php require APNI_TOKRI_ROOT . '/includes/partials/address-form-location-google.php'; ?>

    <!-- Step C -->
    <section class="at-addr-step" data-addr-step="details" aria-labelledby="<?= Security::escape($uid) ?>-h-details">
        <h3 class="at-addr-step__title" id="<?= Security::escape($uid) ?>-h-details"><span class="at-addr-step__num">3</span>Address details</h3>
        <p class="at-addr-msg text-sm" data-addr-detected-hint hidden role="status">Some fields were filled from your search. Please verify and complete everything before saving.</p>
        <div class="at-addr-grid">
            <div>
                <label class="at-label" for="<?= Security::escape($uid) ?>-house">House / Flat / Door number <span class="at-req">*</span></label>
                <input id="<?= Security::escape($uid) ?>-house" name="house_number" class="at-input at-focusable" maxlength="100" required autocomplete="address-line1" value="<?= $v('house_number') ?>" placeholder="Flat, door or unit number"<?= $invalid('house_number') ?>>
                <?= $err('house_number') ?>
            </div>
            <div>
                <label class="at-label" for="<?= Security::escape($uid) ?>-building">Building / Apartment / Society <span class="at-muted font-normal">(optional)</span></label>
                <input id="<?= Security::escape($uid) ?>-building" name="building_name" class="at-input at-focusable" maxlength="150" autocomplete="address-line2" value="<?= $v('building_name') ?>" placeholder="Building, apartment or society"<?= $invalid('building_name') ?>>
                <p class="text-xs at-muted mt-1">Leave blank for an independent house.</p>
                <?= $err('building_name') ?>
            </div>
            <div>
                <label class="at-label" for="<?= Security::escape($uid) ?>-street">Street / Road <span class="at-req">*</span></label>
                <input id="<?= Security::escape($uid) ?>-street" name="street" class="at-input at-focusable" maxlength="150" required value="<?= $v('street') ?>" placeholder="Street or road"<?= $invalid('street') ?>>
                <?= $err('street') ?>
            </div>
            <div>
                <label class="at-label" for="<?= Security::escape($uid) ?>-area">Area / Locality <span class="at-req">*</span></label>
                <input id="<?= Security::escape($uid) ?>-area" name="area" class="at-input at-focusable" maxlength="150" required value="<?= $v('area') ?>" placeholder="Area or locality"<?= $invalid('area') ?>>
                <?= $err('area') ?>
            </div>
            <div>
                <label class="at-label" for="<?= Security::escape($uid) ?>-city">City <span class="at-req">*</span></label>
                <input id="<?= Security::escape($uid) ?>-city" name="addr_city" class="at-input at-focusable" maxlength="128" required value="<?= $v('addr_city') ?>" placeholder="City"<?= $invalid('addr_city') ?>>
                <?= $err('addr_city') ?>
            </div>
            <div>
                <label class="at-label" for="<?= Security::escape($uid) ?>-state">State <span class="at-req">*</span></label>
                <input id="<?= Security::escape($uid) ?>-state" name="addr_state" class="at-input at-focusable" maxlength="128" required value="<?= $v('addr_state') ?>" placeholder="State"<?= $invalid('addr_state') ?>>
                <?= $err('addr_state') ?>
            </div>
            <div>
                <label class="at-label" for="<?= Security::escape($uid) ?>-pin">Pincode <span class="at-req">*</span></label>
                <input id="<?= Security::escape($uid) ?>-pin" name="pincode" class="at-input at-focusable" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="postal-code" value="<?= $v('pincode') ?>" placeholder="6-digit PIN code"<?= $invalid('pincode') ?>>
                <?= $err('pincode') ?>
            </div>
            <div>
                <label class="at-label" for="<?= Security::escape($uid) ?>-landmark">Landmark <span class="at-muted font-normal">(recommended)</span></label>
                <input id="<?= Security::escape($uid) ?>-landmark" name="landmark" class="at-input at-focusable" maxlength="191" value="<?= $v('landmark') ?>" placeholder="Near Bombay Hospital"<?= $invalid('landmark') ?>>
                <?= $err('landmark') ?>
            </div>
            <div class="at-addr-grid__full">
                <label class="at-label" for="<?= Security::escape($uid) ?>-instr">Delivery instructions <span class="at-muted font-normal">(optional)</span></label>
                <textarea id="<?= Security::escape($uid) ?>-instr" name="delivery_instructions" class="at-textarea at-focusable" rows="2" maxlength="500" placeholder="Call before arriving. Use Gate No. 2."<?= $invalid('delivery_instructions') ?>><?= $v('delivery_instructions') ?></textarea>
                <?= $err('delivery_instructions') ?>
            </div>
        </div>
    </section>

    <!-- Step D -->
    <section class="at-addr-step" data-addr-step="receiver" aria-labelledby="<?= Security::escape($uid) ?>-h-recv">
        <h3 class="at-addr-step__title" id="<?= Security::escape($uid) ?>-h-recv"><span class="at-addr-step__num">4</span>Who will receive the delivery?</h3>
        <p class="at-addr-hint">The receiver can be someone other than you. <strong>This number will be shared with the delivery person for delivery coordination.</strong></p>
        <div class="at-addr-grid">
            <div>
                <label class="at-label" for="<?= Security::escape($uid) ?>-rname">Receiver name <span class="at-req">*</span></label>
                <input id="<?= Security::escape($uid) ?>-rname" name="receiver_name" class="at-input at-focusable" maxlength="100" required autocomplete="name" value="<?= $v('receiver_name') ?>"<?= $invalid('receiver_name') ?>>
                <?= $err('receiver_name') ?>
            </div>
            <div>
                <label class="at-label" for="<?= Security::escape($uid) ?>-rphone">Delivery contact number <span class="at-req">*</span></label>
                <input id="<?= Security::escape($uid) ?>-rphone" name="receiver_phone" class="at-input at-focusable" type="tel" inputmode="tel" maxlength="16" required autocomplete="tel" value="<?= $v('receiver_phone') ?>" placeholder="98765 43210"<?= $invalid('receiver_phone') ?>>
                <?= $err('receiver_phone') ?>
            </div>
            <div>
                <label class="at-label" for="<?= Security::escape($uid) ?>-aphone">Alternate number <span class="at-muted font-normal">(optional)</span></label>
                <input id="<?= Security::escape($uid) ?>-aphone" name="alternate_phone" class="at-input at-focusable" type="tel" inputmode="tel" maxlength="16" value="<?= $v('alternate_phone') ?>"<?= $invalid('alternate_phone') ?>>
                <?= $err('alternate_phone') ?>
            </div>
        </div>
    </section>

    <!-- Step E -->
    <section class="at-addr-step at-addr-step--review" data-addr-step="review" aria-labelledby="<?= Security::escape($uid) ?>-h-review">
        <h3 class="at-addr-step__title" id="<?= Security::escape($uid) ?>-h-review"><span class="at-addr-step__num">5</span>Review &amp; save</h3>
        <?php if ($addrFormShowPreview ?? true): ?>
        <div class="at-addr-preview" data-addr-preview aria-live="polite"></div>
        <?php endif; ?>
        <label class="at-addr-confirm">
            <input type="checkbox" name="address_confirmed" value="1" required <?= ($vals['address_confirmed'] ?? '') === '1' ? 'checked' : '' ?>>
            <span>I have checked this address and the receiver details.</span>
        </label>
        <?= $err('confirm') ?>
        <?php if ($mode === 'add' && !empty($addrFormShowDefault)): ?>
            <label class="at-addr-confirm"><input type="checkbox" name="make_default" value="1" <?= ($vals['make_default'] ?? '') === '1' ? 'checked' : '' ?>><span>Make this my default address</span></label>
        <?php endif; ?>
        <div class="at-addr-actions at-addr-actions--sticky">
            <button type="submit" class="at-btn at-btn--primary at-focusable"><?= $mode === 'update' ? 'Save changes' : 'Save address' ?></button>
            <?php if (!empty($addrFormCancelUrl)): ?>
                <a href="<?= Security::escape((string) $addrFormCancelUrl) ?>" class="at-btn at-btn--ghost at-focusable">Cancel</a>
            <?php endif; ?>
        </div>
    </section>
</form>
