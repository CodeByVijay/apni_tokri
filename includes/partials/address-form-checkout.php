<?php

declare(strict_types=1);

/**
 * Checkout-only address form: autocomplete + coords (no embedded map).
 * Same form as my-addresses (address-form.php) minus the live preview block.
 * See address-form.php for the variable contract.
 */
$addrFormShowPreview = false;
require APNI_TOKRI_ROOT . '/includes/partials/address-form.php';
