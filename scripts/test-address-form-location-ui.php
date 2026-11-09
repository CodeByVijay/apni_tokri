<?php

declare(strict_types=1);

/**
 * Regression: address form location partial shows search for any enabled provider.
 *
 * CLI: php scripts/test-address-form-location-ui.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
function okAddrUi(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . PHP_EOL;
    if (!$c) {
        $fail++;
    }
}

/**
 * @param array<string, mixed> $acCfg
 */
function renderAddressLocationPartial(array $acCfg, string $uid = 'tst'): string
{
    $err = static function (string $k): string {
        return '';
    };
    ob_start();
    require APNI_TOKRI_ROOT . '/includes/partials/address-form-location-google.php';

    return (string) ob_get_clean();
}

$cases = [
    'enabled_google' => [
        'searchEnabled' => true,
        'provider' => AddressAutocompleteSettings::PROVIDER_GOOGLE,
        'expectSearch' => true,
        'expectLabel' => 'Suggestions from Google',
    ],
    'enabled_photon' => [
        'searchEnabled' => true,
        'provider' => AddressAutocompleteSettings::PROVIDER_PHOTON,
        'expectSearch' => true,
        'expectLabel' => 'Suggestions from OpenStreetMap',
    ],
    'disabled_google' => [
        'searchEnabled' => false,
        'provider' => AddressAutocompleteSettings::PROVIDER_GOOGLE,
        'expectSearch' => false,
        'expectLabel' => null,
    ],
    'disabled_photon' => [
        'searchEnabled' => false,
        'provider' => AddressAutocompleteSettings::PROVIDER_PHOTON,
        'expectSearch' => false,
        'expectLabel' => null,
    ],
];

foreach ($cases as $name => $case) {
    $html = renderAddressLocationPartial([
        'enabled' => $case['searchEnabled'],
        'searchEnabled' => $case['searchEnabled'],
        'provider' => $case['provider'],
    ]);
    $hasSearch = str_contains($html, 'data-addr-ac-search');
    $hasFallback = str_contains($html, 'Address search is unavailable. Enter your full address in the fields below.');
    okAddrUi($hasSearch === $case['expectSearch'], $name . ' search input ' . ($case['expectSearch'] ? 'present' : 'absent'));
    okAddrUi($hasFallback === !$case['expectSearch'], $name . ' fallback message ' . ($case['expectSearch'] ? 'absent' : 'present'));
    if ($case['expectLabel'] !== null) {
        okAddrUi(str_contains($html, $case['expectLabel']), $name . ' provider hint: ' . $case['expectLabel']);
    }
}

okAddrUi(
    str_contains(file_get_contents(APNI_TOKRI_ROOT . '/assets/js/address-autocomplete.js') ?: '', 'autocompleteSearchUrl'),
    'address-autocomplete.js uses unified autocompleteSearchUrl'
);
okAddrUi(
    str_contains(file_get_contents(APNI_TOKRI_ROOT . '/assets/js/address-autocomplete.js') ?: '', "s.provider === 'google'"),
    'address-autocomplete.js branches Google details only when provider is google'
);

exit($fail === 0 ? 0 : 1);
