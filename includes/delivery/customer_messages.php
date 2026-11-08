<?php

declare(strict_types=1);

/** Storefront copy when distance-based delivery needs a map pin (not a pricing rule). */
final class DeliveryCustomerMessages
{
    public static function pinCheckUnavailable(): string
    {
        return 'Delivery not available at this location. Please try another pincode.';
    }

    public static function mapPinRequiredHeadline(): string
    {
        return 'Your pincode is valid, but we need your exact delivery location to check whether we can deliver to your address.';
    }

    public static function mapPinRequiredDetail(): string
    {
        return 'Delivery availability and charges depend on how far you are from our store, so your pincode alone is not enough.';
    }

    /** @return array{message: string, message_detail: string} */
    public static function mapPinRequired(): array
    {
        return [
            'message' => self::mapPinRequiredHeadline(),
            'message_detail' => self::mapPinRequiredDetail(),
        ];
    }

    /**
     * Storefront pin check / AJAX copy aligned with DeliveryZoneResolver (not a generic “unavailable”).
     *
     * @param array{serviceable?: bool, requires_map_pin?: bool, message?: string, message_detail?: string} $result
     * @return array{message: string, message_detail: ?string}
     */
    public static function forPinCheckResponse(array $result): array
    {
        if (!empty($result['serviceable'])) {
            return [
                'message' => (string) ($result['message'] ?? ''),
                'message_detail' => null,
            ];
        }

        if (!empty($result['requires_map_pin'])) {
            return [
                'message' => (string) ($result['message'] ?? self::mapPinRequiredHeadline()),
                'message_detail' => isset($result['message_detail']) && (string) $result['message_detail'] !== ''
                    ? (string) $result['message_detail']
                    : self::mapPinRequiredDetail(),
            ];
        }

        $msg = trim((string) ($result['message'] ?? ''));
        if ($msg !== '' && $msg !== 'Sorry — we do not deliver to this pincode yet.') {
            return ['message' => $msg, 'message_detail' => null];
        }

        return ['message' => self::pinCheckUnavailable(), 'message_detail' => null];
    }
}
