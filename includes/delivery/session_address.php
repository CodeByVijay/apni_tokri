<?php

declare(strict_types=1);

/**
 * Session-scoped "currently selected" delivery address.
 *
 * Works for guests (temporary selection, Phase 2) and logged-in customers (a saved address chosen
 * from the header selector). Phase 3 migrates the guest snapshot into customer_addresses after OTP
 * login. The snapshot keeps fields + coordinates together so they can never go out of sync.
 *
 * Snapshot shape (stored verbatim in $_SESSION[self::SESSION_KEY]):
 *   version, kind ('guest'|'saved'), source_address_id (?int),
 *   label ('Home'|'Work'|'Other'|custom), receiver_name, receiver_phone,
 *   house_number, building_name, street, area, landmark, city, state, pincode,
 *   latitude, longitude, location_confirmed,
 *   serviceable (?bool), service_zone (?string), service_message (?string), checked_at, selected_at
 */
final class DeliverySessionAddress
{
    public const SESSION_KEY = 'delivery_check_address';
    public const VERSION = '2.0';
    public const ADDRESS_TYPES = ['Home', 'Work', 'Other'];

    /**
     * Validate manual/map/GPS input for the header selector. Self-contained (AddressInput's helpers
     * are private) and intentionally lighter than AddressInput::validate — receiver details stay
     * optional because this is a delivery-location selection, not yet an order.
     *
     * @param array<string, mixed> $input
     * @return array{errors: array<string, string>, clean: array<string, mixed>}
     */
    public static function validate(array $input): array
    {
        $errors = [];

        $type = self::str($input, 'address_type');
        if ($type === '' && self::str($input, 'label') !== '') {
            $type = self::str($input, 'label');
        }
        if ($type === '') {
            $type = 'Home';
        }
        if (!in_array($type, self::ADDRESS_TYPES, true)) {
            $errors['address_type'] = 'Choose Home, Work or Other.';
            $type = 'Home';
        }

        $house = self::opt($input, 'house_number', 100, 'house_number', 'Flat / house number', $errors);
        $building = self::opt($input, 'building_name', 150, 'building_name', 'Building / apartment', $errors);
        $street = self::opt($input, 'street', 150, 'street', 'Street / road', $errors);
        $area = self::req($input, 'area', 150, 'area', 'Enter the area or locality.', $errors);
        $landmark = self::opt($input, 'landmark', 191, 'landmark', 'Landmark', $errors);
        $city = self::req($input, 'city', 128, 'city', 'Enter your city.', $errors);
        $state = self::req($input, 'state', 128, 'state', 'Enter your state.', $errors);

        if ($house === '' && $street === '') {
            $errors['house_number'] = 'Enter your flat / house number or street so we can find the address.';
        }

        $pin = preg_replace('/\D+/', '', self::str($input, 'pincode')) ?? '';
        if ($pin === '') {
            $errors['pincode'] = 'Enter your 6-digit pincode.';
        } elseif (!preg_match('/^\d{6}$/', $pin)) {
            $errors['pincode'] = 'Enter a valid 6-digit pincode.';
        }

        $receiver = self::opt($input, 'receiver_name', 100, 'receiver_name', 'Receiver name', $errors);
        if ($receiver !== '' && self::len($receiver) < 2) {
            $errors['receiver_name'] = 'Receiver name must be at least 2 characters.';
        }
        $phoneRaw = self::str($input, 'receiver_phone');
        $phone = null;
        if ($phoneRaw !== '') {
            $phone = Validation::normalizeMobileIndia($phoneRaw);
            if ($phone === null) {
                $errors['receiver_phone'] = 'Enter a valid 10-digit Indian mobile number or leave this blank.';
            }
        }

        // Coordinates are optional; when present they must parse and be map-confirmed so the
        // browser cannot smuggle in an unverified location.
        $coords = AddressInput::parseCoordinates(self::str($input, 'latitude'), self::str($input, 'longitude'));
        if ($coords['error'] !== null) {
            $errors['location'] = $coords['error'];
        }
        $confirmedPin = self::str($input, 'location_confirmed') === '1';
        if ($coords['error'] === null && $coords['lat'] !== null && !$confirmedPin) {
            $errors['location'] = 'Please confirm the pin on the map, or clear the location.';
        }

        return [
            'errors' => $errors,
            'clean' => [
                'kind' => 'guest',
                'source_address_id' => null,
                'label' => $type,
                'receiver_name' => $receiver !== '' ? $receiver : null,
                'receiver_phone' => $phone,
                'house_number' => $house !== '' ? $house : null,
                'building_name' => $building !== '' ? $building : null,
                'street' => $street !== '' ? $street : null,
                'area' => $area !== '' ? $area : null,
                'landmark' => $landmark !== '' ? $landmark : null,
                'city' => $city !== '' ? $city : null,
                'state' => $state !== '' ? $state : null,
                'pincode' => $pin !== '' ? $pin : null,
                'latitude' => $coords['lat'],
                'longitude' => $coords['lng'],
                'location_confirmed' => $coords['lat'] !== null && $confirmedPin,
            ],
        ];
    }

    /**
     * Snapshot for a saved customer address chosen from the selector (row already ownership-checked).
     *
     * @param array<string, mixed> $row customer_addresses row
     * @return array<string, mixed>
     */
    public static function snapshotFromSavedRow(array $row): array
    {
        $coords = AddressInput::parseCoordinates(
            isset($row['latitude']) ? (string) $row['latitude'] : '',
            isset($row['longitude']) ? (string) $row['longitude'] : ''
        );
        $landmark = AddressInput::landmarkOf($row);

        return [
            'version' => self::VERSION,
            'kind' => 'saved',
            'source_address_id' => isset($row['id']) ? (int) $row['id'] : null,
            'label' => (string) ($row['label'] ?? 'Home'),
            'receiver_name' => ($row['receiver_name'] ?? '') !== '' ? (string) $row['receiver_name'] : null,
            'receiver_phone' => ($row['receiver_phone'] ?? '') !== '' ? (string) $row['receiver_phone'] : null,
            'house_number' => ($row['house_number'] ?? '') !== '' ? (string) $row['house_number'] : null,
            'building_name' => ($row['building_name'] ?? '') !== '' ? (string) $row['building_name'] : null,
            'street' => ($row['street'] ?? '') !== '' ? (string) $row['street'] : null,
            'area' => ($row['area'] ?? '') !== '' ? (string) $row['area'] : null,
            'landmark' => $landmark !== '' ? $landmark : null,
            'city' => ($row['city'] ?? '') !== '' ? (string) $row['city'] : null,
            'state' => ($row['state'] ?? '') !== '' ? (string) $row['state'] : null,
            'pincode' => ($row['pincode'] ?? '') !== '' ? preg_replace('/\D+/', '', (string) $row['pincode']) : null,
            'latitude' => $coords['lat'],
            'longitude' => $coords['lng'],
            'location_confirmed' => $coords['lat'] !== null && !empty($row['location_confirmed_at']),
        ];
    }

    /**
     * Evaluate serviceability under the *current* resolver mode and attach the result to the
     * snapshot. Keeps the legacy `delivery_check_pin` session pin coherent: set when serviceable,
     * cleared when the selected address is not (so stale pins cannot falsely confirm delivery).
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public static function refreshServiceability(array $snapshot): array
    {
        $snapshot['serviceable'] = null;
        $snapshot['service_zone'] = null;
        $snapshot['service_message'] = null;
        $snapshot['checked_at'] = date(DATE_ATOM);

        $pin = (string) ($snapshot['pincode'] ?? '');
        if ($pin === '' || !preg_match('/^\d{6}$/', $pin)) {
            DeliveryContext::clearPin();
            $snapshot['service_message'] = 'We could not check delivery without a pincode.';

            return $snapshot;
        }

        $lat = isset($snapshot['latitude']) && $snapshot['latitude'] !== null ? (float) $snapshot['latitude'] : null;
        $lng = isset($snapshot['longitude']) && $snapshot['longitude'] !== null ? (float) $snapshot['longitude'] : null;
        $result = DeliveryService::evaluatePincode($pin, $lat, $lng);

        $snapshot['serviceable'] = !empty($result['serviceable']);
        $snapshot['service_zone'] = isset($result['zone']['name']) ? (string) $result['zone']['name'] : null;
        $snapshot['service_message'] = $snapshot['serviceable']
            ? (string) ($result['message'] ?? '')
            : DeliveryCustomerMessages::pinCheckUnavailable();

        if ($snapshot['serviceable']) {
            DeliveryContext::setPin($pin);
        } else {
            DeliveryContext::clearPin();
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    public static function store(array $snapshot): void
    {
        $snapshot['version'] = self::VERSION;
        $snapshot['selected_at'] = date(DATE_ATOM);
        $_SESSION[self::SESSION_KEY] = $snapshot;
    }

    /** @return array<string, mixed>|null */
    public static function current(): ?array
    {
        $s = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($s) || ($s['version'] ?? '') !== self::VERSION) {
            return null;
        }

        return $s;
    }

    public static function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * Short header title, e.g. "Vijay Nagar" (area) or the pincode/city fallback.
     *
     * @param array<string, mixed> $s snapshot
     */
    public static function title(array $s): string
    {
        foreach (['area', 'city', 'pincode'] as $k) {
            $v = trim((string) ($s[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return 'Delivery address';
    }

    /**
     * Header subtitle, e.g. "Home · 452010".
     *
     * @param array<string, mixed> $s snapshot
     */
    public static function subtitle(array $s): string
    {
        $parts = [];
        $label = trim((string) ($s['label'] ?? ''));
        if ($label !== '') {
            $parts[] = $label;
        }
        $pin = trim((string) ($s['pincode'] ?? ''));
        if ($pin !== '') {
            $parts[] = $pin;
        }

        return implode(' · ', $parts);
    }

    /**
     * One-line readable address for the modal's "current selection" banner.
     *
     * @param array<string, mixed> $s snapshot
     */
    public static function readableLine(array $s): string
    {
        $parts = array_filter([
            trim((string) ($s['house_number'] ?? '')),
            trim((string) ($s['building_name'] ?? '')),
            trim((string) ($s['street'] ?? '')),
            trim((string) ($s['area'] ?? '')),
        ], static function (string $v): bool {
            return $v !== '';
        });
        $tail = trim((string) ($s['city'] ?? ''));
        $pin = trim((string) ($s['pincode'] ?? ''));
        if ($pin !== '') {
            $tail .= ($tail !== '' ? ' — ' : '') . $pin;
        }
        if ($tail !== '') {
            $parts[] = $tail;
        }

        return implode(', ', $parts);
    }

    /* ---------------------------------------------------------- helpers */

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
        if ($v !== '' && (self::len($v) > $max || self::unsafe($v))) {
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
