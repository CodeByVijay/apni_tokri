<?php

declare(strict_types=1);

/**
 * Single server-side validation + formatting for customer delivery addresses.
 * Used by my-addresses.php and checkout.php (via CustomerAccountService); the browser is never trusted.
 *
 * Two input modes:
 *  - structured: house_number/building_name/street/area/receiver_* fields present (new form);
 *  - legacy: only label/line1/line2/pincode (old single-line form, existing tests, demo flows).
 */
final class AddressInput
{
    public const LABEL_TYPES = ['Home', 'Office', 'Other'];

    /** @param array<string, mixed> $input */
    public static function isStructured(array $input): bool
    {
        foreach (['house_number', 'building_name', 'street', 'area', 'receiver_name', 'receiver_phone'] as $k) {
            if (array_key_exists($k, $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{errors: array<string, string>, clean: array<string, mixed>}
     */
    public static function validate(array $input): array
    {
        return self::isStructured($input) ? self::validateStructured($input) : self::validateLegacy($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{errors: array<string, string>, clean: array<string, mixed>}
     */
    private static function validateLegacy(array $input): array
    {
        $errors = [];
        $label = self::str($input, 'label');
        $line1 = self::str($input, 'line1');
        $line2 = self::str($input, 'line2');
        $pin = preg_replace('/\D+/', '', self::str($input, 'pincode')) ?? '';

        if ($label === '') {
            $label = 'Home';
        }
        if (strlen($label) > 64 || self::unsafe($label)) {
            $errors['label'] = 'Label must be up to 64 characters.';
        }
        if ($line1 === '') {
            $errors['line1'] = 'Enter the house / flat, street and area.';
        } elseif (strlen($line1) > 255 || self::unsafe($line1)) {
            $errors['line1'] = 'Address is too long or has invalid characters.';
        }
        if (strlen($line2) > 255 || self::unsafe($line2)) {
            $errors['line2'] = 'Landmark is too long or has invalid characters.';
        }
        self::checkPin($pin, $errors);

        return [
            'errors' => $errors,
            'clean' => self::blankClean([
                'mode' => 'legacy',
                'label' => $label,
                'line1' => $line1,
                'line2' => $line2,
                'landmark' => $line2 !== '' ? $line2 : null,
                'pincode' => $pin,
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{errors: array<string, string>, clean: array<string, mixed>}
     */
    private static function validateStructured(array $input): array
    {
        $errors = [];

        // Step A — label
        $type = self::str($input, 'label_type');
        $custom = self::str($input, 'label_custom');
        if ($type === '' && self::str($input, 'label') !== '') {
            $legacy = self::str($input, 'label');
            $type = in_array($legacy, self::LABEL_TYPES, true) ? $legacy : 'Other';
            $custom = $type === 'Other' ? $legacy : '';
        }
        if ($type === '') {
            $type = 'Home';
        }
        if (!in_array($type, self::LABEL_TYPES, true)) {
            $errors['label'] = 'Choose Home, Office or Other.';
            $label = 'Home';
        } elseif ($type === 'Other') {
            if ($custom === '') {
                $errors['label'] = 'Enter a name for this address (for example “Parents’ house”).';
            } elseif (self::len($custom) > 64 || self::unsafe($custom)) {
                $errors['label'] = 'Label must be up to 64 characters.';
            }
            $label = $custom !== '' ? $custom : 'Other';
        } else {
            $label = $type;
        }

        // Step C — structured address
        $house = self::req($input, 'house_number', 100, 'house_number', 'Please enter your house / flat number so the delivery partner can locate your address.', $errors);
        $building = self::opt($input, 'building_name', 150, 'building_name', 'Building / apartment / society', $errors);
        $street = self::req($input, 'street', 150, 'street', 'Enter the street or road.', $errors);
        $area = self::req($input, 'area', 150, 'area', 'Enter the area or locality.', $errors);
        $city = self::req($input, 'addr_city', 128, 'addr_city', 'Enter your city.', $errors);
        $state = self::req($input, 'addr_state', 128, 'addr_state', 'Enter your state.', $errors);
        $landmark = self::opt($input, 'landmark', 191, 'landmark', 'Landmark', $errors);
        $instructions = self::opt($input, 'delivery_instructions', 500, 'delivery_instructions', 'Delivery instructions', $errors);

        $pin = preg_replace('/\D+/', '', self::str($input, 'pincode')) ?? '';
        self::checkPin($pin, $errors);

        // Step D — receiver
        $receiver = preg_replace('/\s+/u', ' ', self::str($input, 'receiver_name')) ?? '';
        if ($receiver === '') {
            $errors['receiver_name'] = 'Enter the name of the person who will receive the delivery.';
        } elseif (self::len($receiver) < 2 || self::len($receiver) > 100 || self::unsafe($receiver)) {
            $errors['receiver_name'] = 'Receiver name must be 2 to 100 characters.';
        }
        $phoneRaw = self::str($input, 'receiver_phone');
        $phone = $phoneRaw === '' ? null : Validation::normalizeMobileIndia($phoneRaw);
        if ($phone === null) {
            $errors['receiver_phone'] = 'Enter a valid 10-digit Indian mobile number.';
        }
        $altRaw = self::str($input, 'alternate_phone');
        $alt = null;
        if ($altRaw !== '') {
            $alt = Validation::normalizeMobileIndia($altRaw);
            if ($alt === null) {
                $errors['alternate_phone'] = 'Enter a valid 10-digit Indian mobile number or leave this blank.';
            }
        }

        // Step B — map pin (checkout may use autocomplete + coords without embedded map)
        $coords = self::parseCoordinates(self::str($input, 'latitude'), self::str($input, 'longitude'));
        if ($coords['error'] !== null) {
            $errors['location'] = $coords['error'];
        }
        $confirmedPin = self::str($input, 'location_confirmed') === '1';
        $checkoutAutocomplete = self::str($input, 'addr_checkout_autocomplete') === '1';
        if ($coords['error'] === null && $coords['lat'] !== null && !$confirmedPin && !$checkoutAutocomplete) {
            $errors['location'] = 'Please confirm the pin location on the map (tap “Confirm this location”) or remove the pin.';
        }

        // Step E — explicit confirmation of the whole address
        if (self::str($input, 'address_confirmed') !== '1') {
            $errors['confirm'] = 'Please review the address and tick the confirmation box before saving.';
        }

        $line1 = implode(', ', array_filter([$house, $building, $street, $area], static function ($v) {
            return $v !== '';
        }));
        if (strlen($line1) > 255) {
            $errors['street'] = $errors['street'] ?? 'House, building, street and area together are too long.';
        }

        return [
            'errors' => $errors,
            'clean' => self::blankClean([
                'mode' => 'structured',
                'label' => $label,
                'receiver_name' => $receiver,
                'receiver_phone' => $phone,
                'alternate_phone' => $alt,
                'house_number' => $house,
                'building_name' => $building,
                'street' => $street,
                'area' => $area,
                'city' => $city,
                'state' => $state,
                'landmark' => $landmark !== '' ? $landmark : null,
                'delivery_instructions' => $instructions !== '' ? $instructions : null,
                'line1' => $line1,
                'line2' => $landmark !== '' ? $landmark : '',
                'pincode' => $pin,
                'latitude' => $coords['lat'],
                'longitude' => $coords['lng'],
                'location_confirmed' => $coords['lat'] !== null && ($confirmedPin || $checkoutAutocomplete),
            ]),
        ];
    }

    /**
     * Strict coordinate parsing. Both blank = no pin. Never trusts browser GPS.
     *
     * @return array{lat: ?float, lng: ?float, error: ?string}
     */
    public static function parseCoordinates(string $lat, string $lng): array
    {
        $lat = trim($lat);
        $lng = trim($lng);
        if ($lat === '' && $lng === '') {
            return ['lat' => null, 'lng' => null, 'error' => null];
        }
        $bad = static function (string $m): array {
            return ['lat' => null, 'lng' => null, 'error' => $m];
        };
        if ($lat === '' || $lng === '') {
            return $bad('The map pin is incomplete. Move the pin again.');
        }
        $re = '/^-?\d{1,3}(\.\d{1,12})?$/';
        if (!preg_match($re, $lat) || !preg_match($re, $lng)) {
            return $bad('The map pin is not valid. Move the pin again.');
        }
        $la = (float) $lat;
        $lo = (float) $lng;
        if ($la < -90.0 || $la > 90.0) {
            return $bad('Latitude must be between -90 and 90.');
        }
        if ($lo < -180.0 || $lo > 180.0) {
            return $bad('Longitude must be between -180 and 180.');
        }
        if ($la == 0.0 && $lo == 0.0) {
            return $bad('The map pin is not valid. Move the pin again.');
        }

        return ['lat' => round($la, 7), 'lng' => round($lo, 7), 'error' => null];
    }

    /** @return array<string, string> */
    public static function blankFormValues(?array $profile = null): array
    {
        return [
            'label_type' => 'Home',
            'label_custom' => '',
            'receiver_name' => $profile !== null ? (string) ($profile['name'] ?? '') : '',
            'receiver_phone' => $profile !== null ? (string) ($profile['mobile'] ?? '') : '',
            'alternate_phone' => '',
            'house_number' => '',
            'building_name' => '',
            'street' => '',
            'area' => '',
            'addr_city' => '',
            'addr_state' => '',
            'pincode' => '',
            'landmark' => '',
            'delivery_instructions' => '',
            'latitude' => '',
            'longitude' => '',
            'location_confirmed' => '0',
            'address_confirmed' => '',
            'make_default' => '',
            'addr_checkout_autocomplete' => '',
        ];
    }

    /**
     * Prefill the form from a saved address. Legacy rows put their single line into "street" so nothing is lost.
     *
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    public static function formValuesFromRow(array $row, ?array $profile = null): array
    {
        $v = self::blankFormValues($profile);
        $label = (string) ($row['label'] ?? 'Home');
        if (in_array($label, self::LABEL_TYPES, true)) {
            $v['label_type'] = $label;
        } else {
            $v['label_type'] = 'Other';
            $v['label_custom'] = $label;
        }
        $structured = !empty($row['house_number']) || !empty($row['building_name']) || !empty($row['street']) || !empty($row['area']);
        foreach (['receiver_name', 'receiver_phone', 'alternate_phone', 'house_number', 'building_name', 'street', 'area', 'delivery_instructions'] as $k) {
            if (isset($row[$k]) && $row[$k] !== null && $row[$k] !== '') {
                $v[$k] = (string) $row[$k];
            }
        }
        if (isset($row['city']) && $row['city'] !== null && (string) $row['city'] !== '') {
            $v['addr_city'] = (string) $row['city'];
        }
        if (isset($row['state']) && $row['state'] !== null && (string) $row['state'] !== '') {
            $v['addr_state'] = (string) $row['state'];
        }
        if (!$structured) {
            $v['street'] = (string) ($row['line1'] ?? '');
        }
        $v['landmark'] = self::landmarkOf($row);
        $v['pincode'] = (string) ($row['pincode'] ?? '');
        if (self::hasPin($row)) {
            $v['latitude'] = rtrim(rtrim(sprintf('%.7F', (float) $row['latitude']), '0'), '.');
            $v['longitude'] = rtrim(rtrim(sprintf('%.7F', (float) $row['longitude']), '0'), '.');
            $v['location_confirmed'] = !empty($row['location_confirmed_at']) ? '1' : '0';
        }

        return $v;
    }

    /**
     * Echo-back of a failed POST (strings only, never trusted).
     *
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    public static function formValuesFromPost(array $post): array
    {
        $v = self::blankFormValues(null);
        foreach (array_keys($v) as $k) {
            if (isset($post[$k]) && is_string($post[$k])) {
                $v[$k] = $post[$k];
            }
        }

        return $v;
    }

    /** @param array<string, mixed> $row customer_addresses row */
    public static function hasPin(array $row): bool
    {
        if (!isset($row['latitude'], $row['longitude']) || $row['latitude'] === '' || $row['longitude'] === '') {
            return false;
        }
        $p = self::parseCoordinates((string) $row['latitude'], (string) $row['longitude']);

        return $p['lat'] !== null;
    }

    /** Readable one-line address (structured or legacy), excluding landmark. */
    public static function fullAddress(array $row): string
    {
        $pin = (string) ($row['pincode'] ?? '');
        $city = (string) ($row['city'] ?? '');
        $parts = [];
        if (!empty($row['house_number']) || !empty($row['building_name']) || !empty($row['street']) || !empty($row['area'])) {
            foreach (['house_number', 'building_name', 'street', 'area'] as $k) {
                if (!empty($row[$k])) {
                    $parts[] = (string) $row[$k];
                }
            }
        } else {
            if (!empty($row['line1'])) {
                $parts[] = (string) $row['line1'];
            }
        }
        if ($city !== '') {
            $parts[] = $city;
        }
        $line = implode(', ', $parts);

        return $pin !== '' ? $line . ' — ' . $pin : $line;
    }

    /** Landmark for display: structured column, falling back to legacy line2. */
    public static function landmarkOf(array $row): string
    {
        $l = (string) ($row['landmark'] ?? '');

        return $l !== '' ? $l : (string) ($row['line2'] ?? '');
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function blankClean(array $over): array
    {
        return array_merge([
            'mode' => 'legacy',
            'label' => 'Home',
            'receiver_name' => null,
            'receiver_phone' => null,
            'alternate_phone' => null,
            'house_number' => null,
            'building_name' => null,
            'street' => null,
            'area' => null,
            'city' => 'Indore',
            'state' => 'Madhya Pradesh',
            'landmark' => null,
            'delivery_instructions' => null,
            'line1' => '',
            'line2' => '',
            'pincode' => '',
            'latitude' => null,
            'longitude' => null,
            'location_confirmed' => false,
        ], $over);
    }

    /** @param array<string, string> $errors */
    private static function checkPin(string $pin, array &$errors): void
    {
        if (!preg_match('/^\d{6}$/', $pin)) {
            $errors['pincode'] = 'Enter a valid 6-digit pincode.';
        } elseif (!DeliveryService::isPincodeServiceable($pin)) {
            $errors['pincode'] = 'We do not deliver to this pincode yet.';
        }
    }

    /** @param array<string, mixed> $input */
    private static function str(array $input, string $key): string
    {
        return isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';
    }

    /** @param array<string, string> $errors */
    private static function req(array $input, string $key, int $max, string $errKey, string $emptyMsg, array &$errors): string
    {
        $v = preg_replace('/\s+/u', ' ', self::str($input, $key)) ?? '';
        if ($v === '') {
            $errors[$errKey] = $emptyMsg;
        } elseif (self::len($v) > $max || self::unsafe($v)) {
            $errors[$errKey] = 'Use up to ' . $max . ' characters, without special symbols like < or >.';
        } elseif (preg_match('/[\p{L}\p{N}]/u', $v) !== 1) {
            $errors[$errKey] = 'This does not look like a real value — please enter actual text.';
        }

        return $v;
    }

    /** @param array<string, string> $errors */
    private static function opt(array $input, string $key, int $max, string $errKey, string $label, array &$errors): string
    {
        $v = preg_replace('/[ \t]+/u', ' ', self::str($input, $key)) ?? '';
        if ($v !== '' && (self::len($v) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F<>]/', $v))) {
            $errors[$errKey] = $label . ' must be up to ' . $max . ' characters, without < or >.';
        }

        return $v;
    }

    private static function unsafe(string $v): bool
    {
        return (bool) preg_match('/[\x00-\x1F<>]/', $v);
    }

    private static function len(string $v): int
    {
        return function_exists('mb_strlen') ? mb_strlen($v) : strlen($v);
    }
}
