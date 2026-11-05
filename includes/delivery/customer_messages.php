<?php

declare(strict_types=1);

/** Storefront copy when distance-based delivery needs a map pin (not a pricing rule). */
final class DeliveryCustomerMessages
{
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
}
