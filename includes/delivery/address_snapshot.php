<?php

declare(strict_types=1);

/**
 * Immutable per-order delivery address snapshot (stored in orders.delivery_snapshot_json["address"]).
 *
 * Delivery assignment / courier hand-off must read from this snapshot, never from the customer's latest
 * saved address. Orders created before 1.19.0 have no "address" block; for those we fall back to the
 * (archive-protected, never mutated) customer_addresses row referenced by orders.customer_address_id.
 */
final class DeliveryAddressSnapshot
{
    public const VERSION = '1.19.0';

    /**
     * @param array<string, mixed> $addr customer_addresses row
     * @param array{name?: string, mobile?: string}|null $customerFallback used only when the address has no receiver
     * @return array<string, mixed>
     */
    public static function fromAddressRow(array $addr, ?array $customerFallback = null): array
    {
        $pin = null;
        if (isset($addr['latitude'], $addr['longitude']) && $addr['latitude'] !== '' && $addr['longitude'] !== '') {
            $p = AddressInput::parseCoordinates((string) $addr['latitude'], (string) $addr['longitude']);
            if ($p['lat'] !== null) {
                $pin = [$p['lat'], $p['lng']];
            }
        }
        $structured = !empty($addr['house_number']) || !empty($addr['building_name']) || !empty($addr['street']) || !empty($addr['area']);
        $receiverName = trim((string) ($addr['receiver_name'] ?? ''));
        $receiverPhone = trim((string) ($addr['receiver_phone'] ?? ''));
        $fallbackUsed = false;
        if ($receiverName === '' && $customerFallback !== null) {
            $receiverName = trim((string) ($customerFallback['name'] ?? ''));
            $fallbackUsed = true;
        }
        if ($receiverPhone === '' && $customerFallback !== null) {
            $receiverPhone = trim((string) ($customerFallback['mobile'] ?? ''));
            $fallbackUsed = true;
        }
        $landmark = AddressInput::landmarkOf($addr);

        return [
            'version' => self::VERSION,
            'captured_at' => date(DATE_ATOM),
            'source_address_id' => isset($addr['id']) ? (int) $addr['id'] : null,
            'structured' => $structured,
            'label' => (string) ($addr['label'] ?? ''),
            'receiver_name' => $receiverName,
            'receiver_phone' => $receiverPhone,
            'receiver_from_account' => $fallbackUsed,
            'alternate_phone' => ($addr['alternate_phone'] ?? '') !== '' ? (string) $addr['alternate_phone'] : null,
            'house_number' => $structured ? (string) ($addr['house_number'] ?? '') : null,
            'building_name' => $structured ? (string) ($addr['building_name'] ?? '') : null,
            'street' => $structured ? (string) ($addr['street'] ?? '') : null,
            'area' => $structured ? (string) ($addr['area'] ?? '') : null,
            'line1' => (string) ($addr['line1'] ?? ''),
            'landmark' => $landmark !== '' ? $landmark : null,
            'delivery_instructions' => ($addr['delivery_instructions'] ?? '') !== '' ? (string) $addr['delivery_instructions'] : null,
            'city' => (string) ($addr['city'] ?? ''),
            'state' => (string) ($addr['state'] ?? ''),
            'pincode' => preg_replace('/\D+/', '', (string) ($addr['pincode'] ?? '')) ?? '',
            'latitude' => $pin !== null ? $pin[0] : null,
            'longitude' => $pin !== null ? $pin[1] : null,
            'location_confirmed' => $pin !== null && !empty($addr['location_confirmed_at']),
            'location_confirmed_at' => $pin !== null && !empty($addr['location_confirmed_at']) ? (string) $addr['location_confirmed_at'] : null,
        ];
    }

    /**
     * Snapshot for an order row (needs delivery_snapshot_json; customer_address_id/customer_id for legacy fallback).
     *
     * @param array<string, mixed> $order orders row
     * @return array<string, mixed>|null
     */
    public static function forOrder(array $order, ?PDO $pdo = null): ?array
    {
        $snap = DeliveryOrderSnapshot::fromOrderRow($order);
        if ($snap !== null && isset($snap['address']) && is_array($snap['address'])) {
            return $snap['address'];
        }

        // Legacy order (pre-1.19.0): referenced address rows are never mutated after ordering (archived on edit).
        $addrId = (int) ($order['customer_address_id'] ?? 0);
        if ($addrId <= 0 || !Install::isSchemaReady()) {
            return null;
        }
        Install::ensureConnection();
        $pdo = $pdo ?? Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM customer_addresses WHERE id = ? LIMIT 1');
        $stmt->execute([$addrId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $fallback = null;
        $cid = (int) ($order['customer_id'] ?? 0);
        if ($cid > 0) {
            $c = $pdo->prepare('SELECT name, mobile FROM customers WHERE id = ? LIMIT 1');
            $c->execute([$cid]);
            $fallback = $c->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $out = self::fromAddressRow($row, $fallback);
        $out['legacy_order'] = true;
        // Pre-1.19.0 snapshots carried only the quote coordinates; prefer them as the order-time location.
        if ($snap !== null && isset($snap['customer_latitude'], $snap['customer_longitude'])
            && $snap['customer_latitude'] !== null && $snap['customer_longitude'] !== null) {
            $p = AddressInput::parseCoordinates((string) $snap['customer_latitude'], (string) $snap['customer_longitude']);
            if ($p['lat'] !== null) {
                $out['latitude'] = $p['lat'];
                $out['longitude'] = $p['lng'];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $a address snapshot */
    public static function readableLines(array $a): array
    {
        $lines = [];
        if (!empty($a['structured'])) {
            foreach (['house_number', 'building_name', 'street', 'area'] as $k) {
                if (!empty($a[$k])) {
                    $lines[] = (string) $a[$k];
                }
            }
        } elseif (!empty($a['line1'])) {
            $lines[] = (string) $a['line1'];
        }
        $cityPin = trim((string) ($a['city'] ?? ''));
        if (!empty($a['pincode'])) {
            $cityPin .= ($cityPin !== '' ? ' — ' : '') . $a['pincode'];
        }
        if ($cityPin !== '') {
            $lines[] = $cityPin;
        }

        return $lines;
    }

    /**
     * Minimum data a courier / delivery person needs. No customer profile, email, or order value.
     *
     * @param array<string, mixed> $a address snapshot
     * @return array<string, mixed>
     */
    public static function courierPayload(array $a): array
    {
        $lat = isset($a['latitude']) && $a['latitude'] !== null ? (float) $a['latitude'] : null;
        $lng = isset($a['longitude']) && $a['longitude'] !== null ? (float) $a['longitude'] : null;
        $hasPin = $lat !== null && $lng !== null;

        return [
            'receiver_name' => (string) ($a['receiver_name'] ?? ''),
            'receiver_phone' => (string) ($a['receiver_phone'] ?? ''),
            'alternate_phone' => $a['alternate_phone'] ?? null,
            'address_lines' => self::readableLines($a),
            'landmark' => $a['landmark'] ?? null,
            'delivery_instructions' => $a['delivery_instructions'] ?? null,
            'pincode' => (string) ($a['pincode'] ?? ''),
            'latitude' => $hasPin ? $lat : null,
            'longitude' => $hasPin ? $lng : null,
            'location_confirmed' => !empty($a['location_confirmed']),
            'map_link' => $hasPin ? MapConfig::osmLink($lat, $lng) : null,
        ];
    }

    /** Plain-text hand-off message for a courier (WhatsApp / booking form). */
    public static function courierText(array $a, string $orderNumber = ''): string
    {
        $p = self::courierPayload($a);
        $out = [];
        if ($orderNumber !== '') {
            $out[] = 'Order: ' . $orderNumber;
        }
        $out[] = 'Receiver: ' . $p['receiver_name'] . ' (' . $p['receiver_phone'] . ')';
        if (!empty($p['alternate_phone'])) {
            $out[] = 'Alternate phone: ' . $p['alternate_phone'];
        }
        $out[] = 'Address: ' . implode(', ', $p['address_lines']);
        if (!empty($p['landmark'])) {
            $out[] = 'Landmark: ' . $p['landmark'];
        }
        if (!empty($p['delivery_instructions'])) {
            $out[] = 'Instructions: ' . $p['delivery_instructions'];
        }
        if ($p['latitude'] !== null) {
            $out[] = 'Pin (customer-selected, approximate): ' . sprintf('%.6F, %.6F', $p['latitude'], $p['longitude']);
            $out[] = 'Map: ' . $p['map_link'];
        } else {
            $out[] = 'Map pin: not provided';
        }

        return implode("\n", $out);
    }
}
