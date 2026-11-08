<?php

declare(strict_types=1);

/**
 * Checkout and subscription delivery snapshot (delivery_snapshot_json) — historical quote record (D4/D5).
 *
 * Address drift policy at payment:
 * - Checkout snapshot is the accepted delivery arrangement (pin + coordinates, fee, zone).
 * - If the customer's saved address changes materially after checkout, payment completion is blocked
 *   and routed to payment_completion_exceptions (admin must re-checkout, update order, or manual fulfil).
 * - Revalidation uses snapshot coordinates/pin, not the changed address, so fees are never silently recalculated.
 */
final class DeliveryOrderSnapshot
{
    /** @return array<string, mixed>|null */
    public static function parse(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /** @return array<string, mixed>|null */
    public static function fromOrderRow(array $order): ?array
    {
        $raw = $order['delivery_snapshot_json'] ?? null;
        if (is_string($raw)) {
            return self::parse($raw);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $currentAddress customer_addresses row
     * @return string|null drift code or null if unchanged
     */
    public static function detectAddressDrift(?array $snapshot, array $currentAddress): ?string
    {
        if ($snapshot === null) {
            return null;
        }

        $snapPin = preg_replace('/\D+/', '', (string) ($snapshot['pincode'] ?? '')) ?? '';
        $curPin = preg_replace('/\D+/', '', (string) ($currentAddress['pincode'] ?? '')) ?? '';
        if ($snapPin !== '' && $curPin !== '' && $snapPin !== $curPin) {
            return 'pincode_changed';
        }

        $snapLat = isset($snapshot['customer_latitude']) && $snapshot['customer_latitude'] !== ''
            ? (float) $snapshot['customer_latitude'] : null;
        $snapLon = isset($snapshot['customer_longitude']) && $snapshot['customer_longitude'] !== ''
            ? (float) $snapshot['customer_longitude'] : null;
        $curLat = isset($currentAddress['latitude']) && $currentAddress['latitude'] !== ''
            && $currentAddress['latitude'] !== null ? (float) $currentAddress['latitude'] : null;
        $curLon = isset($currentAddress['longitude']) && $currentAddress['longitude'] !== ''
            && $currentAddress['longitude'] !== null ? (float) $currentAddress['longitude'] : null;

        if ($snapLat !== null && $snapLon !== null && $curLat !== null && $curLon !== null) {
            if (abs($snapLat - $curLat) > 0.0005 || abs($snapLon - $curLon) > 0.0005) {
                return 'coordinates_changed';
            }
        } elseif (($snapLat !== null || $snapLon !== null) && ($curLat === null || $curLon === null)) {
            return 'coordinates_removed';
        } elseif ($snapLat === null && $snapLon === null && $curLat !== null && $curLon !== null) {
            return 'coordinates_added';
        }

        return null;
    }

    /**
     * Address context for payment revalidation (snapshot wins over live address when present).
     *
     * @param array<string, mixed> $currentAddress
     * @return array<string, mixed>
     */
    public static function addressForRevalidation(?array $snapshot, array $currentAddress): array
    {
        if ($snapshot === null) {
            return $currentAddress;
        }
        $merged = $currentAddress;
        if (!empty($snapshot['pincode'])) {
            $merged['pincode'] = (string) $snapshot['pincode'];
        }
        if (array_key_exists('customer_latitude', $snapshot) && $snapshot['customer_latitude'] !== null && $snapshot['customer_latitude'] !== '') {
            $merged['latitude'] = $snapshot['customer_latitude'];
        }
        if (array_key_exists('customer_longitude', $snapshot) && $snapshot['customer_longitude'] !== null && $snapshot['customer_longitude'] !== '') {
            $merged['longitude'] = $snapshot['customer_longitude'];
        }

        return $merged;
    }

    /**
     * Admin / reporting: prefer snapshot checkout quote over live zone config.
     *
     * @param array<string, mixed> $order orders row
     * @return array{delivery_fee: float|null, zone_label: string|null, from_snapshot: bool, quoted_at: string|null}
     */
    public static function reportingDeliveryQuote(array $order): array
    {
        $snap = self::fromOrderRow($order);
        if ($snap !== null && isset($snap['delivery_fee'])) {
            $zone = (string) ($snap['zone_name'] ?? '');
            if ($zone === '' && isset($snap['zone_id'])) {
                $zone = 'Zone #' . (int) $snap['zone_id'];
            }

            return [
                'delivery_fee' => (float) $snap['delivery_fee'],
                'zone_label' => $zone !== '' ? $zone : null,
                'from_snapshot' => true,
                'quoted_at' => isset($snap['quoted_at']) ? (string) $snap['quoted_at'] : null,
            ];
        }

        return [
            'delivery_fee' => isset($order['delivery_fee']) ? (float) $order['delivery_fee'] : null,
            'zone_label' => null,
            'from_snapshot' => false,
            'quoted_at' => null,
        ];
    }

    /**
     * Patch delivery slot fields after admin reschedule (fee/zone/pin unchanged).
     *
     * @param array<string, mixed>|null $snapshot
     * @param array<string, mixed> $slotRow delivery_slots row
     * @return array<string, mixed>
     */
    public static function applyAdminReschedule(?array $snapshot, array $slotRow, string $deliveryDate): array
    {
        $snap = $snapshot ?? [];
        if (!isset($snap['schema_version'])) {
            $snap['schema_version'] = DeliveryQuoteService::SNAPSHOT_SCHEMA;
        }
        $snap['slot_id'] = (int) ($slotRow['id'] ?? 0);
        $snap['slot_slug'] = (string) ($slotRow['slug'] ?? '');
        $snap['slot_label'] = (string) ($slotRow['label'] ?? '');
        $snap['slot_window'] = (string) ($slotRow['window_label'] ?? '');
        $snap['delivery_date'] = $deliveryDate;
        $snap['admin_rescheduled_at'] = DeliveryConfig::now()->format(DATE_ATOM);

        return $snap;
    }

    /**
     * Admin custom delivery window during payment fulfilment reconciliation (capacity bucket + customer-agreed times).
     *
     * @param array<string, mixed>|null $snapshot
     * @param array<string, mixed> $slotRow delivery_slots row used for capacity reservation
     * @return array<string, mixed>
     */
    public static function applyAdminCustomDeliveryWindow(
        ?array $snapshot,
        array $slotRow,
        string $deliveryDate,
        string $customStart,
        string $customEnd,
        string $windowLabel,
        bool $capacityOverride
    ): array {
        $snap = self::applyAdminReschedule($snapshot, $slotRow, $deliveryDate);
        $snap['admin_delivery_override'] = true;
        $snap['admin_capacity_override'] = $capacityOverride;
        $snap['custom_delivery_start'] = $customStart;
        $snap['custom_delivery_end'] = $customEnd;
        $snap['custom_delivery_window_label'] = $windowLabel;
        $snap['slot_window'] = $windowLabel;

        return $snap;
    }
}
