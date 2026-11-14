<?php

declare(strict_types=1);

/**
 * Assisted-order smart address UX: autocomplete hooks, serviceability preview,
 * duplicate-warning flow, receiver prefill, and mode separation checks.
 *
 * php scripts/test-admin-assisted-order-address-ux.php
 */
$root = dirname(__DIR__);
$fail = 0;
function auxOk(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . PHP_EOL;
    if (!$c) {
        ++$fail;
    }
}

$create = (string) file_get_contents($root . '/admin/orders/create.php');
$partial = (string) file_get_contents($root . '/includes/partials/admin/assisted-order-address-form.php');
$js = (string) file_get_contents($root . '/assets/js/admin-assisted-order.js');
$svc = (string) file_get_contents($root . '/includes/commerce/admin_assisted_order.php');
$previewEp = (string) file_get_contents($root . '/admin/ajax/assisted-order-address-preview.php');
$createEp = (string) file_get_contents($root . '/admin/ajax/assisted-order-address-create.php');

// --- Address form: autocomplete module hooks (reuses storefront module, no secrets) ---
auxOk(str_contains($partial, 'data-at-address-form'), 'partial binds address-autocomplete module (data-at-address-form)');
auxOk(str_contains($partial, 'data-addr-checkout-autocomplete="1"'), 'partial opts into checkout autocomplete mode');
foreach (['latitude', 'longitude', 'location_confirmed'] as $hidden) {
    auxOk(str_contains($partial, 'name="' . $hidden . '"'), "hidden coordinate input present: {$hidden}");
}
foreach (['data-addr-ac-search', 'data-addr-ac-list', 'data-addr-ac-status', 'data-addr-ac-busy', 'data-addr-map-msg', 'data-addr-detected-hint'] as $hook) {
    auxOk(str_contains($partial, $hook), "autocomplete hook present: {$hook}");
}
auxOk(str_contains($partial, 'Search building, area or landmark'), 'search field uses required placeholder');
auxOk(str_contains($partial, 'AddressAutocompleteSettings::publicClientConfig'), 'partial reads existing provider config (no API keys)');
auxOk(!str_contains($partial, 'api_key') && !str_contains($partial, 'apiKey'), 'no API key material in partial');
auxOk(str_contains($partial, 'Address search is not configured'), 'manual-entry fallback note when autocomplete disabled');
auxOk(str_contains($partial, 'data-at-aorder-pin-preview'), 'pincode serviceability preview element');
auxOk(str_contains($partial, 'data-at-aorder-addr-dupes'), 'duplicate-warning container');
auxOk(str_contains($partial, 'Save and select address'), 'primary action is save-and-select');
auxOk(str_contains($partial, 'Back to saved addresses'), 'cancel returns to saved-address mode');

// --- create.php wiring ---
auxOk(str_contains($create, 'AT_ADDRESS_AUTOCOMPLETE'), 'page emits client autocomplete config');
auxOk(str_contains($create, 'address-autocomplete.js'), 'page loads existing autocomplete module');
auxOk(str_contains($create, 'data-url-address-preview'), 'page exposes preview endpoint URL');
auxOk(str_contains($create, 'data-at-aorder-addr-preview'), 'selected-address serviceability preview element');

// --- JS behavior ---
foreach (['previewServiceability', 'prefillReceiverFields', 'showAddrDupes', 'hideAddrDupes', 'allowDuplicateOnce', 'receiverPrefill', 'addrMode'] as $fn) {
    auxOk(str_contains($js, $fn), "JS address hook present: {$fn}");
}
auxOk(str_contains($js, "state.addressSubmissionKey = '';"), 'submission key cleared after successful save (idempotency fix)');
auxOk(str_contains($js, "body.append('allow_duplicate', '1')"), 'explicit save-anyway passes allow_duplicate');
auxOk(str_contains($js, 'duplicate_warning'), 'JS handles duplicate-warning response');
auxOk(str_contains($js, "previewServiceability(addr.pincode"), 'saved-address selection triggers serviceability preview');
auxOk(str_contains($js, 'Use this address'), 'duplicate warning offers select-existing action');
auxOk(str_contains($js, 'Save anyway as a new address'), 'duplicate warning offers explicit save-anyway action');
auxOk(!preg_match('/flat_fee\s*[*+\/]|delivery_fee\s*=/', $js), 'JS does not calculate delivery fee itself');

// --- Service ---
auxOk(str_contains($svc, 'function previewAddressServiceability'), 'service preview method exists');
auxOk(str_contains($svc, 'DeliveryService::evaluatePincode'), 'preview reuses existing delivery zone resolver');
auxOk(str_contains($svc, 'function similarSavedAddresses'), 'service duplicate-similarity check exists');
auxOk(str_contains($svc, "'code' => 'duplicate_address'"), 'service returns advisory duplicate code');
auxOk(str_contains($svc, "'latitude'") && str_contains($svc, "'longitude'"), 'address list items expose coordinates');

// --- Endpoints ---
auxOk(str_contains($previewEp, 'denyUnlessCanCreate'), 'preview endpoint is permission-guarded');
auxOk(str_contains($previewEp, 'Csrf::requireValid'), 'preview endpoint enforces CSRF');
auxOk(str_contains($previewEp, "REQUEST_METHOD'] !== 'POST'"), 'preview endpoint is POST-only');
auxOk(str_contains($previewEp, 'previewAddressServiceability'), 'preview endpoint delegates to service');
auxOk(str_contains($createEp, 'duplicate_address') && str_contains($createEp, 'duplicate_warning'), 'create endpoint surfaces advisory duplicate warning');

echo $fail === 0 ? "All assisted-order address UX checks passed.\n" : "{$fail} check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
