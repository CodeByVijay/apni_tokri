<?php

declare(strict_types=1);

final class PricingService
{
    /**
     * @param list<array<string, mixed>> $lines qty, unit_price, regular_price, on_sale, grants_free_delivery?, product_id?, category_id?, variant_id?
     * @param int|null $forCustomerId When set (e.g. admin-assisted orders), promotions/coupons use this customer instead of the session.
     * @return array<string, float|int|string|null|bool>
     */
    public static function totalsFromLines(
        array $lines,
        ?string $couponCode,
        ?string $deliveryPin = null,
        ?int $forCustomerId = null
    ): array
    {
        $subtotal = 0.0;
        $savings = 0.0;
        foreach ($lines as $line) {
            $qty = (int) ($line['qty'] ?? 0);
            $unit = (float) ($line['unit_price'] ?? 0);
            $regular = (float) ($line['regular_price'] ?? $unit);
            $subtotal += $unit * $qty;
            if (!empty($line['on_sale']) && $regular > $unit) {
                $savings += ($regular - $unit) * $qty;
            }
        }

        $customerId = $forCustomerId ?? CustomerAuth::id();
        $offer = PromotionService::bestAutomaticOffer($lines, $customerId);
        $offerDiscount = (float) ($offer['discount'] ?? 0);
        $offerStacks = !empty($offer['stack_with_coupons']);

        $couponResult = CouponService::validate(
            $couponCode,
            $subtotal,
            $customerId,
            $lines,
            $offerDiscount,
            $offerStacks
        );
        $discount = $couponResult['ok'] ? (float) ($couponResult['discount'] ?? 0) : 0.0;
        $appliedCode = $couponResult['ok'] ? ($couponResult['code'] ?? null) : null;

        $afterDiscount = max(0, $subtotal - $offerDiscount - $discount);
        $pin = $deliveryPin ?? DeliveryContext::sessionPin() ?? '';
        $delivery = DeliveryService::computeDeliveryFee($lines, $afterDiscount, $pin !== '' ? $pin : '000000');

        $deliveryFee = (float) $delivery['fee'];
        $threshold = (float) $delivery['threshold'];
        $eligible = (bool) $delivery['free_delivery_eligible'];
        $toFree = $eligible && $deliveryFee > 0
            ? max(0, $threshold - (float) $delivery['eligible_subtotal'])
            : 0.0;

        $total = $afterDiscount + $deliveryFee;

        $totals = [
            'subtotal' => round($subtotal, 2),
            'product_savings' => round($savings, 2),
            'offer_discount' => round($offerDiscount, 2),
            'offer_label' => $offer['label'] ?? null,
            'offer_id' => $offer['offer_id'] ?? null,
            'offer_countdown_end_unix' => OfferStorefront::endsAtToUnix($offer['ends_at'] ?? null),
            'coupon_code' => $appliedCode,
            'coupon_discount' => round($discount, 2),
            'coupon_error' => !$couponResult['ok'] && $couponCode ? ($couponResult['message'] ?? '') : null,
            'delivery_fee' => round($deliveryFee, 2),
            'free_delivery_threshold' => $threshold,
            'amount_to_free_delivery' => round($toFree, 2),
            'free_delivery_eligible' => $eligible,
            'delivery_serviceable' => (bool) ($delivery['serviceable'] ?? false),
            'delivery_zone' => $delivery['zone']['name'] ?? null,
            'total' => round($total, 2),
        ];

        return MarketingSettings::withZoneMinimumOrder(
            $totals,
            is_array($delivery['zone'] ?? null) ? $delivery['zone'] : null
        );
    }

    /**
     * Align cart/checkout preview totals with DeliveryQuoteService when address (and slot) are known.
     *
     * @param array<string, mixed> $totals base totals from totalsFromLines
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed>|null $address customer_addresses row or null
     */
    public static function applyDeliveryQuotePreview(
        array $totals,
        array $lines,
        ?array $address = null,
        ?string $deliverySlotSlug = null,
        ?string $deliverySlotDate = null
    ): array {
        $mode = DeliveryZoneResolver::zoneResolutionMode();
        $totals['delivery_quote_accurate'] = true;
        $totals['delivery_quote_message'] = null;

        if ($lines === []) {
            return $totals;
        }

        if ($mode === 'pincode_legacy') {
            if ($address !== null) {
                $quote = DeliveryQuoteService::quoteForCheckout(
                    $address,
                    $lines,
                    $deliverySlotSlug ?? 'auto',
                    $totals,
                    null,
                    null,
                    $deliverySlotDate
                );
                if (!empty($quote['ok'])) {
                    return self::mergeQuoteIntoTotals($totals, $quote);
                }
                $totals['delivery_quote_accurate'] = false;
                $totals['delivery_quote_message'] = (string) ($quote['message'] ?? 'Delivery unavailable for this address.');
                $totals['delivery_serviceable'] = false;
                $totals['delivery_fee'] = 0.0;
                $totals['total'] = round((float) ($totals['subtotal'] ?? 0)
                    - (float) ($totals['offer_discount'] ?? 0)
                    - (float) ($totals['coupon_discount'] ?? 0), 2);

                return MarketingSettings::withZoneMinimumOrder($totals, null);
            }

            $totals['delivery_quote_accurate'] = false;
            $totals['delivery_quote_message'] = CartDeliveryBanner::MSG_SELECT_ADDRESS;
            $totals['delivery_fee'] = 0.0;
            $after = max(0, (float) ($totals['subtotal'] ?? 0) - (float) ($totals['offer_discount'] ?? 0)
                - (float) ($totals['coupon_discount'] ?? 0));
            $totals['total'] = round($after, 2);
            $totals['amount_to_free_delivery'] = 0.0;

            return $totals;
        }

        if ($address === null) {
            $totals['delivery_quote_accurate'] = false;
            $totals['delivery_quote_message'] = CartDeliveryBanner::MSG_SELECT_ADDRESS;
            $totals['delivery_fee'] = 0.0;
            $after = max(0, (float) ($totals['subtotal'] ?? 0) - (float) ($totals['offer_discount'] ?? 0)
                - (float) ($totals['coupon_discount'] ?? 0));
            $totals['total'] = round($after, 2);
            $totals['delivery_serviceable'] = false;

            return $totals;
        }

        $store = DeliveryConfig::store();
        $requireCoords = !empty($store['require_coordinates_for_checkout']);
        $hasCoords = isset($address['latitude'], $address['longitude'])
            && $address['latitude'] !== null && $address['longitude'] !== null
            && $address['latitude'] !== '' && $address['longitude'] !== '';

        if (($requireCoords || DeliveryZoneResolver::zoneResolutionMode() !== 'pincode_legacy') && !$hasCoords) {
            $totals['delivery_quote_accurate'] = false;
            $totals['delivery_quote_message'] = 'Pin your delivery location on the map for an accurate delivery fee.';
            $totals['delivery_fee'] = 0.0;
            $after = max(0, (float) ($totals['subtotal'] ?? 0) - (float) ($totals['offer_discount'] ?? 0)
                - (float) ($totals['coupon_discount'] ?? 0));
            $totals['total'] = round($after, 2);
            $totals['delivery_serviceable'] = false;

            return MarketingSettings::withZoneMinimumOrder($totals, null);
        }

        if (DeliveryConfig::slotsEnabled() && ($deliverySlotSlug === null || $deliverySlotSlug === '')) {
            $totals['delivery_quote_accurate'] = false;
            $totals['delivery_quote_message'] = 'Select a delivery slot for an accurate total.';
            $totals['delivery_fee'] = 0.0;
            $after = max(0, (float) ($totals['subtotal'] ?? 0) - (float) ($totals['offer_discount'] ?? 0)
                - (float) ($totals['coupon_discount'] ?? 0));
            $totals['total'] = round($after, 2);

            return MarketingSettings::withZoneMinimumOrder($totals, null);
        }

        $quote = DeliveryQuoteService::quoteForCheckout(
            $address,
            $lines,
            $deliverySlotSlug ?? 'auto',
            $totals,
            null,
            null,
            $deliverySlotDate
        );
        if (empty($quote['ok'])) {
            $totals['delivery_quote_accurate'] = false;
            $totals['delivery_quote_message'] = (string) ($quote['message'] ?? 'Delivery unavailable.');
            $totals['delivery_serviceable'] = false;
            $totals['delivery_fee'] = 0.0;
            $after = max(0, (float) ($totals['subtotal'] ?? 0) - (float) ($totals['offer_discount'] ?? 0)
                - (float) ($totals['coupon_discount'] ?? 0));
            $totals['total'] = round($after, 2);

            return MarketingSettings::withZoneMinimumOrder($totals, null);
        }

        return self::mergeQuoteIntoTotals($totals, $quote);
    }

    /** @param array<string, mixed> $totals @param array<string, mixed> $quote */
    private static function mergeQuoteIntoTotals(array $totals, array $quote): array
    {
        $totals['delivery_fee'] = round((float) ($quote['delivery_fee'] ?? 0), 2);
        $totals['total'] = round((float) ($quote['grand_total'] ?? 0), 2);
        $totals['delivery_serviceable'] = true;
        $totals['delivery_zone'] = $quote['zone_name'] ?? ($totals['delivery_zone'] ?? null);
        $totals['free_delivery_threshold'] = (float) ($quote['free_delivery_threshold'] ?? $totals['free_delivery_threshold'] ?? 0);
        $totals['amount_to_free_delivery'] = max(
            0,
            (float) ($quote['free_delivery_threshold'] ?? 0) - (float) ($quote['eligible_subtotal'] ?? 0)
        );
        $totals['delivery_quote_accurate'] = true;

        return MarketingSettings::withZoneMinimumOrder(
            $totals,
            is_array($quote['zone'] ?? null) ? $quote['zone'] : null
        );
    }
}
