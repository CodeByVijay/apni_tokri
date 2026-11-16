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
 *   version, kind ('guest'|'saved'), source_address_id (?int), source_address_ref (?string),
 *   address_type ('Home'|'Work'|'Other'), address_name (?string, custom name for Other),
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

        // "Other" requires a customer-facing name (e.g. Parents' Home). It is
        // stored as the snapshot/account label so it is never overwritten by
        // the generic "Other". Home/Work ignore the field entirely.
        $addressName = '';
        if ($type === 'Other') {
            $addressName = preg_replace('/\s+/u', ' ', self::str($input, 'address_name')) ?? '';
            if ($addressName === '') {
                $addressName = preg_replace('/\s+/u', ' ', self::str($input, 'label_custom')) ?? '';
            }
            if ($addressName === '') {
                $errors['address_name'] = 'Enter a name for this address (for example “Parents’ home”, “Friend’s house”, “Shop” or “Office”).';
            } elseif (self::len($addressName) > 64 || self::unsafe($addressName)) {
                $errors['address_name'] = 'Address name must be up to 64 characters, without < or >.';
            } elseif (preg_match('/[\p{L}\p{N}]/u', $addressName) !== 1) {
                $errors['address_name'] = 'This does not look like a real name — please enter actual text.';
            }
        }
        $label = $type === 'Other' && $addressName !== '' ? $addressName : $type;

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
                'address_type' => $type,
                'address_name' => $type === 'Other' ? ($addressName !== '' ? $addressName : null) : null,
                'label' => $label,
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
        $rowLabel = trim((string) ($row['label'] ?? 'Home'));
        $rowType = in_array($rowLabel, self::ADDRESS_TYPES, true) ? $rowLabel : 'Other';

        return [
            'version' => self::VERSION,
            'kind' => 'saved',
            'source_address_id' => isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null,
            // String ref keeps non-numeric ids (session-demo backend) comparable to saved lists.
            'source_address_ref' => isset($row['id']) && (string) $row['id'] !== '' ? (string) $row['id'] : null,
            'address_type' => $rowType,
            'address_name' => $rowType === 'Other' && $rowLabel !== 'Other' ? $rowLabel : null,
            'label' => $rowLabel !== '' ? $rowLabel : 'Home',
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
     * Saved customer_addresses id the current selection points at, or null for guest
     * selections / selections with no linked row. Callers must still verify ownership.
     */
    public static function selectedAddressId(): ?int
    {
        $ref = self::selectedAddressRef();

        return $ref !== null && ctype_digit($ref) ? (int) $ref : null;
    }

    /**
     * Saved-address identifier (string) the current selection points at — works for both
     * integer DB ids and the session-demo backend's string ids. Null for guest selections.
     */
    public static function selectedAddressRef(): ?string
    {
        $s = self::current();
        if ($s === null || ($s['kind'] ?? '') !== 'saved') {
            return null;
        }
        $ref = $s['source_address_ref'] ?? null;
        if (is_string($ref) && $ref !== '') {
            return $ref;
        }
        $id = (int) ($s['source_address_id'] ?? 0);

        return $id > 0 ? (string) $id : null;
    }

    /**
     * Phase 3: persist a guest-selected session address into the authenticated customer's
     * account after successful OTP login, keeping it selected.
     *
     * - Only runs for kind === 'guest' snapshots in persisted mode.
     * - Re-validates the snapshot server-side; reuses an equivalent existing address
     *   instead of inserting a duplicate.
     * - Never makes the migrated address the customer's default (makeDefault = false).
     * - On any failure the guest snapshot is left untouched and stays selected.
     */
    public static function migrateToCustomerAccount(int $customerId): void
    {
        $snap = self::current();
        if ($snap === null || ($snap['kind'] ?? '') !== 'guest' || $customerId <= 0) {
            return;
        }
        if (!AppEnvironment::usePersistedStorefront()) {
            return;
        }

        try {
            $v = self::validate(self::snapshotToValidateInput($snap));
            if ($v['errors'] !== []) {
                return;
            }
            $c = $v['clean'];

            $row = null;
            $equivalentId = self::findEquivalentAddressId($customerId, $c);
            if ($equivalentId !== null) {
                $row = CustomerService::address($equivalentId, $customerId);
            } else {
                $input = self::snapshotToAccountInput($c, $customerId);
                if ($input === null) {
                    return;
                }
                $res = CustomerAccountService::addAddress($customerId, $input, false);
                if (empty($res['ok']) || empty($res['id'])) {
                    return;
                }
                $row = CustomerService::address((int) $res['id'], $customerId);
            }
            if ($row === null) {
                return;
            }

            self::store(self::refreshServiceability(self::snapshotFromSavedRow($row)));
        } catch (Throwable $e) {
            // Migration must never break login; the guest snapshot stays selected.
            return;
        }
    }

    /**
     * Locate an existing active address equivalent to the guest selection: same pincode,
     * same normalized address text, and (when both sides carry coordinates) within ~150 m.
     *
     * @param array<string, mixed> $c validated clean snapshot
     */
    private static function findEquivalentAddressId(int $customerId, array $c): ?int
    {
        $newText = self::normAddressText(
            (string) ($c['house_number'] ?? ''),
            (string) ($c['building_name'] ?? ''),
            (string) ($c['street'] ?? ''),
            (string) ($c['area'] ?? '')
        );
        if ($newText === '') {
            return null;
        }
        $newPin = (string) ($c['pincode'] ?? '');
        $newLat = isset($c['latitude']) ? (float) $c['latitude'] : null;
        $newLng = isset($c['longitude']) ? (float) $c['longitude'] : null;

        foreach (CustomerAccountService::listAddresses($customerId) as $row) {
            if ((string) ($row['pincode'] ?? '') !== $newPin) {
                continue;
            }
            $rowText = self::normAddressText(
                (string) ($row['house_number'] ?? ''),
                (string) ($row['building_name'] ?? ''),
                (string) ($row['street'] ?? ''),
                (string) ($row['area'] ?? '')
            );
            if ($rowText === '') {
                $rowText = self::normAddressText((string) ($row['line1'] ?? ''));
            }
            if ($rowText !== $newText) {
                continue;
            }
            $rowLat = isset($row['latitude']) && $row['latitude'] !== null ? (float) $row['latitude'] : null;
            $rowLng = isset($row['longitude']) && $row['longitude'] !== null ? (float) $row['longitude'] : null;
            if ($newLat !== null && $newLng !== null && $rowLat !== null && $rowLng !== null) {
                if (DeliveryService::haversineKm($newLat, $newLng, $rowLat, $rowLng) > 0.15) {
                    continue;
                }
            }

            return (int) $row['id'];
        }

        return null;
    }

    /** @param array<string, mixed> $snap */
    private static function snapshotToValidateInput(array $snap): array
    {
        // Rebuild the type/name pair: newer snapshots carry them explicitly;
        // older ones only have `label`, so a non-standard label implies Other.
        $lbl = (string) ($snap['label'] ?? 'Home');
        $at = (string) ($snap['address_type'] ?? '');
        if ($at === '') {
            $at = in_array($lbl, self::ADDRESS_TYPES, true) ? $lbl : 'Other';
        }
        $an = trim((string) ($snap['address_name'] ?? ''));
        if ($at === 'Other' && $an === '') {
            $an = (!in_array($lbl, self::ADDRESS_TYPES, true) || $lbl === 'Other') ? $lbl : '';
        }

        return [
            'address_type' => $at,
            'address_name' => $an,
            'house_number' => (string) ($snap['house_number'] ?? ''),
            'building_name' => (string) ($snap['building_name'] ?? ''),
            'street' => (string) ($snap['street'] ?? ''),
            'area' => (string) ($snap['area'] ?? ''),
            'landmark' => (string) ($snap['landmark'] ?? ''),
            'city' => (string) ($snap['city'] ?? ''),
            'state' => (string) ($snap['state'] ?? ''),
            'pincode' => (string) ($snap['pincode'] ?? ''),
            'latitude' => isset($snap['latitude']) ? (string) $snap['latitude'] : '',
            'longitude' => isset($snap['longitude']) ? (string) $snap['longitude'] : '',
            'receiver_name' => (string) ($snap['receiver_name'] ?? ''),
            'receiver_phone' => (string) ($snap['receiver_phone'] ?? ''),
            'location_confirmed' => !empty($snap['location_confirmed']) ? '1' : '',
        ];
    }

    /**
     * Build CustomerAccountService::addAddress input from a validated snapshot, filling
     * receiver details from the customer profile when the guest did not provide them.
     * Returns null when no valid receiver phone can be determined.
     *
     * @param array<string, mixed> $c validated clean snapshot
     * @return array<string, mixed>|null
     */
    private static function snapshotToAccountInput(array $c, int $customerId): ?array
    {
        $profile = CustomerAccountService::profile($customerId) ?? [];
        $receiverName = trim((string) ($c['receiver_name'] ?? ''));
        if ($receiverName === '') {
            $receiverName = trim((string) ($profile['name'] ?? ''));
        }
        $receiverPhone = Validation::normalizeMobileIndia((string) ($c['receiver_phone'] ?? ''));
        if ($receiverPhone === null) {
            $receiverPhone = Validation::normalizeMobileIndia((string) ($profile['mobile'] ?? ''));
        }
        if ($receiverPhone === null) {
            return null;
        }
        if ($receiverName === '') {
            $receiverName = 'Customer';
        }

        $label = (string) ($c['label'] ?? 'Home');
        $labelType = in_array($label, ['Home', 'Office', 'Other'], true) ? $label : 'Other';
        $labelCustom = $labelType === 'Other' && $label !== 'Other' ? $label : '';

        return [
            'label_type' => $labelType,
            'label_custom' => $labelCustom,
            'house_number' => (string) ($c['house_number'] ?? ''),
            'building_name' => (string) ($c['building_name'] ?? ''),
            'street' => (string) ($c['street'] ?? ''),
            'area' => (string) ($c['area'] ?? ''),
            'landmark' => (string) ($c['landmark'] ?? ''),
            'addr_city' => (string) ($c['city'] ?? ''),
            'addr_state' => (string) ($c['state'] ?? ''),
            'pincode' => (string) ($c['pincode'] ?? ''),
            'latitude' => isset($c['latitude']) ? (string) $c['latitude'] : '',
            'longitude' => isset($c['longitude']) ? (string) $c['longitude'] : '',
            'location_confirmed' => !empty($c['location_confirmed']) ? '1' : '',
            'receiver_name' => $receiverName,
            'receiver_phone' => $receiverPhone,
            'address_confirmed' => '1',
        ];
    }

    /** Lowercase alphanumeric-only comparison key for address equivalence. */
    private static function normAddressText(string ...$parts): string
    {
        $joined = strtolower(implode(' ', array_filter(array_map('trim', $parts), static function (string $p): bool {
            return $p !== '';
        })));

        return (string) preg_replace('/[^a-z0-9]/', '', $joined);
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
