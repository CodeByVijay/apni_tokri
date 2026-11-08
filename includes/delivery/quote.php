<?php

declare(strict_types=1);

/**
 * Checkout delivery quote: zone, distance, fee, slot capacity, provider/manual fulfilment (D4).
 */
final class DeliveryQuoteService
{
    public const SNAPSHOT_SCHEMA = '1.8.0-d4';

    /**
     * Build a checkout quote from address, cart lines, and slot slug.
     *
     * @param array<string, mixed> $address customer_addresses row
     * @param list<array<string, mixed>> $cartLines
     * @param array<string, mixed>|null $pricingTotals from CartService::totals() (optional)
     * @return array<string, mixed>
     */
    public static function quoteForCheckout(
        array $address,
        array $cartLines,
        string $slotSlug,
        ?array $pricingTotals = null,
        ?PDO $pdo = null,
        ?int $excludeOrderId = null,
        ?string $slotDeliveryDate = null
    ): array {
        $store = DeliveryConfig::store();
        $pin = preg_replace('/\D+/', '', (string) ($address['pincode'] ?? '')) ?? '';
        if ($pin === '') {
            return self::fail('invalid_pin', 'Delivery pincode is missing on this address.');
        }

        [$lat, $lon, $hasCoords] = self::coordsFromAddress($address);
        $requireCoords = !empty($store['require_coordinates_for_checkout']);
        $mode = DeliveryZoneResolver::zoneResolutionMode();

        if ($requireCoords && !$hasCoords) {
            return self::fail(
                'coordinates_required',
                'Add your delivery location on the map before checkout — we need your pin to calculate delivery.'
            );
        }

        $zoneEval = $mode === 'pincode_legacy'
            ? DeliveryService::evaluatePincodeLegacy($pin)
            : DeliveryZoneResolver::resolve($pin, $lat, $lon);

        if (!$zoneEval['serviceable'] || ($zoneEval['zone'] ?? null) === null) {
            if (!empty($zoneEval['requires_map_pin']) && !$hasCoords) {
                return self::fail(
                    'coordinates_required',
                    DeliveryCustomerMessages::mapPinRequiredHeadline()
                );
            }

            return self::fail(
                'outside_zone',
                (string) ($zoneEval['message'] ?? 'Delivery is not available for this address.')
            );
        }

        $zone = $zoneEval['zone'];
        $afterDiscount = self::subtotalAfterDiscounts($cartLines, $pricingTotals);
        $feeBreakdown = self::deliveryFeeForZone($zone, $cartLines, $afterDiscount);

        $weightErr = self::assertWeightWithinZone($zone, $cartLines, $pdo);
        if ($weightErr !== null) {
            return self::fail('weight_exceeded', $weightErr);
        }

        $slotPayload = self::resolveSlotForQuote($slotSlug, $pdo, $excludeOrderId, $slotDeliveryDate);
        if (!$slotPayload['ok']) {
            return self::fail(
                (string) ($slotPayload['failure_code'] ?? 'slot_invalid'),
                (string) ($slotPayload['message'] ?? 'Invalid delivery slot.')
            );
        }

        $provider = self::resolveProvider($store, $slotPayload['slot_row'] ?? null);
        if (!$provider['ok']) {
            return self::fail(
                (string) ($provider['failure_code'] ?? 'provider_unavailable'),
                (string) ($provider['message'] ?? 'Delivery provider unavailable.')
            );
        }

        $zoneId = (int) ($zone['id'] ?? 0);
        $deliveryFee = (float) $feeBreakdown['fee'];
        $grandTotal = round($afterDiscount + $deliveryFee, 2);

        return [
            'ok' => true,
            'failure_code' => null,
            'message' => (string) ($zoneEval['message'] ?? ''),
            'pincode' => $pin,
            'zone' => $zone,
            'zone_id' => $zoneId > 0 ? $zoneId : null,
            'zone_name' => (string) ($zone['name'] ?? ''),
            'customer_latitude' => $hasCoords ? $lat : null,
            'customer_longitude' => $hasCoords ? $lon : null,
            'distance_km' => isset($zoneEval['distance_km']) ? $zoneEval['distance_km'] : null,
            'distance_source' => $zoneEval['distance_source'] ?? null,
            'delivery_fee' => $deliveryFee,
            'eligible_subtotal' => (float) $feeBreakdown['eligible_subtotal'],
            'free_delivery_threshold' => (float) $feeBreakdown['threshold'],
            'free_delivery_eligible' => (bool) $feeBreakdown['free_delivery_eligible'],
            'subtotal_after_discounts' => $afterDiscount,
            'grand_total' => $grandTotal,
            'slot_slug' => $slotPayload['slug'] ?? 'auto',
            'slot_id' => $slotPayload['slot_id'] ?? null,
            'slot_label' => $slotPayload['slot_label'] ?? null,
            'slot_window' => $slotPayload['slot_window'] ?? null,
            'slot_start_time' => $slotPayload['slot_start_time'] ?? null,
            'slot_end_time' => $slotPayload['slot_end_time'] ?? null,
            'delivery_date' => $slotPayload['delivery_date'] ?? null,
            'delivery_eta' => $slotPayload['delivery_eta'] ?? null,
            'slot_resolved' => $slotPayload['slot_resolved'] ?? null,
            'fulfilment_mode' => (string) ($provider['fulfilment_mode'] ?? 'manual'),
            'provider_slug' => (string) ($provider['provider_slug'] ?? 'manual'),
            'provider_quote_ref' => $provider['provider_quote_ref'] ?? null,
            'zone_resolution_mode' => $mode,
            'policies' => [
                'require_coordinates_for_checkout' => $requireCoords,
                'manual_delivery_when_provider_unavailable' => !empty($store['manual_delivery_when_provider_unavailable']),
            ],
            'quoted_at' => (new DateTimeImmutable('now', new DateTimeZone(DeliveryConfig::timezone())))->format(DATE_ATOM),
        ];
    }

    /**
     * Immutable snapshot persisted on the order at checkout.
     *
     * @param array<string, mixed> $quote successful quote from quoteForCheckout
     * @return array<string, mixed>
     */
    public static function buildSnapshot(array $quote, ?array $addressSnapshot = null): array
    {
        $snap = [
            'schema_version' => self::SNAPSHOT_SCHEMA,
            'quoted_at' => $quote['quoted_at'] ?? null,
            'pincode' => $quote['pincode'] ?? null,
            'zone_id' => $quote['zone_id'] ?? null,
            'zone_name' => $quote['zone_name'] ?? null,
            'customer_latitude' => $quote['customer_latitude'] ?? null,
            'customer_longitude' => $quote['customer_longitude'] ?? null,
            'distance_km' => $quote['distance_km'] ?? null,
            'distance_source' => $quote['distance_source'] ?? null,
            'delivery_fee' => $quote['delivery_fee'] ?? null,
            'eligible_subtotal' => $quote['eligible_subtotal'] ?? null,
            'free_delivery_threshold' => $quote['free_delivery_threshold'] ?? null,
            'slot_id' => $quote['slot_id'] ?? null,
            'slot_slug' => $quote['slot_slug'] ?? null,
            'slot_label' => $quote['slot_label'] ?? null,
            'slot_window' => $quote['slot_window'] ?? null,
            'slot_start_time' => $quote['slot_start_time'] ?? null,
            'slot_end_time' => $quote['slot_end_time'] ?? null,
            'delivery_date' => $quote['delivery_date'] ?? null,
            'fulfilment_mode' => $quote['fulfilment_mode'] ?? 'manual',
            'provider_slug' => $quote['provider_slug'] ?? 'manual',
            'provider_quote_ref' => $quote['provider_quote_ref'] ?? null,
            'zone_resolution_mode' => $quote['zone_resolution_mode'] ?? null,
            'policies' => $quote['policies'] ?? [],
        ];
        if ($addressSnapshot !== null) {
            // Receiver, structured address, landmark, instructions and confirmed pin as of order time.
            $snap['address'] = $addressSnapshot;
        }

        return $snap;
    }

    /**
     * Immutable delivery snapshot for subscription-generated orders (D5) — same contract as checkout plus subscription refs.
     *
     * @param array<string, mixed> $quote successful quoteForCheckout payload
     * @param array{subscription_number?: string, day_id?: int} $subscriptionMeta
     * @param array<string, mixed>|null $addressSnapshot DeliveryAddressSnapshot::fromAddressRow()
     * @return array<string, mixed>
     */
    public static function buildSubscriptionOrderSnapshot(array $quote, array $subscriptionMeta, ?array $addressSnapshot = null): array
    {
        $snap = self::buildSnapshot($quote, $addressSnapshot);
        $snap['source'] = 'subscription';
        if (!empty($subscriptionMeta['subscription_number'])) {
            $snap['subscription_number'] = (string) $subscriptionMeta['subscription_number'];
        }
        if (!empty($subscriptionMeta['day_id'])) {
            $snap['subscription_delivery_day_id'] = (int) $subscriptionMeta['day_id'];
        }

        return $snap;
    }

    /**
     * Revalidate delivery arrangement before marking an order paid. Does not mutate delivery_snapshot_json.
     *
     * @param array<string, mixed> $order orders row (FOR UPDATE)
     * @param list<array<string, mixed>> $orderItems order_items rows
     * @throws DeliverySlotBookingException
     */
    public static function revalidateBeforePaymentComplete(array $order, array $orderItems, PDO $pdo): void
    {
        $orderId = (int) ($order['id'] ?? 0);
        $addressId = (int) ($order['customer_address_id'] ?? 0);
        if ($addressId <= 0) {
            throw new DeliverySlotBookingException('Delivery address missing on order.');
        }
        $addrStmt = $pdo->prepare('SELECT * FROM customer_addresses WHERE id = ? LIMIT 1');
        $addrStmt->execute([$addressId]);
        $address = $addrStmt->fetch(PDO::FETCH_ASSOC);
        if (!$address) {
            throw new DeliverySlotBookingException('Delivery address not found.');
        }

        $snapshot = DeliveryOrderSnapshot::fromOrderRow($order);
        $drift = DeliveryOrderSnapshot::detectAddressDrift($snapshot, $address);
        if ($drift !== null) {
            throw new DeliverySlotBookingException(
                '[address_drift] Delivery address changed since checkout (' . $drift . '). Payment requires admin reconciliation or a new checkout.'
            );
        }

        $slotSlug = (string) ($order['delivery_slot'] ?? 'auto');
        $cartLines = self::cartLinesFromOrderItems($orderItems);
        $quoteAddress = DeliveryOrderSnapshot::addressForRevalidation($snapshot, $address);
        $deliveryDate = (string) ($order['delivery_date'] ?? '');
        $quote = self::quoteForCheckout(
            $quoteAddress,
            $cartLines,
            $slotSlug,
            null,
            $pdo,
            $orderId,
            $deliveryDate !== '' ? $deliveryDate : null
        );
        if (empty($quote['ok'])) {
            throw new DeliverySlotBookingException((string) ($quote['message'] ?? 'Delivery is no longer available.'));
        }
        if ($deliveryDate !== '' && (string) ($quote['delivery_date'] ?? '') !== $deliveryDate) {
            throw new DeliverySlotBookingException(
                'Delivery date changed since checkout. Payment requires admin reconciliation or a new checkout.'
            );
        }

        $adminDeliveryOverride = is_array($snapshot) && !empty($snapshot['admin_delivery_override']);
        $slotId = isset($order['delivery_slot_id']) ? (int) $order['delivery_slot_id'] : 0;
        if ($slotId > 0 && $deliveryDate !== '') {
            $hold = DeliverySlotReservationService::activeHoldForOrder($pdo, $orderId);
            if (DeliverySlotReservationService::tableReady() && $hold === null) {
                throw new DeliverySlotBookingException('Delivery slot reservation expired. Choose another slot.');
            }
            if (!$adminDeliveryOverride) {
                DeliverySlotReservationService::lockSlotAndAssertCapacity($pdo, $slotId, $deliveryDate, $orderId);
            }
        }
    }

    /**
     * @param array<string, mixed> $zone
     * @param list<array<string, mixed>> $cartLines
     * @return array{fee: float, threshold: float, eligible_subtotal: float, free_delivery_eligible: bool}
     */
    public static function deliveryFeeForZone(array $zone, array $cartLines, float $subtotalAfterCoupon): array
    {
        $flat = (float) ($zone['flat_fee'] ?? 40);
        $threshold = (float) ($zone['free_above'] ?? 499);

        $eligibleSubtotal = 0.0;
        $allGrantFree = $cartLines !== [];
        foreach ($cartLines as $line) {
            $grants = !empty($line['grants_free_delivery']);
            if (!$grants) {
                $allGrantFree = false;
                $eligibleSubtotal += (float) ($line['unit_price'] ?? 0) * (int) ($line['qty'] ?? 0);
            }
        }
        if ($cartLines === []) {
            $allGrantFree = false;
        }

        if ($allGrantFree) {
            return [
                'fee' => 0.0,
                'threshold' => $threshold,
                'eligible_subtotal' => 0.0,
                'free_delivery_eligible' => true,
            ];
        }

        $fee = ($eligibleSubtotal >= $threshold) ? 0.0 : $flat;

        return [
            'fee' => round($fee, 2),
            'threshold' => $threshold,
            'eligible_subtotal' => round($eligibleSubtotal, 2),
            'free_delivery_eligible' => true,
        ];
    }

    /** @return array{0: ?float, 1: ?float, 2: bool} */
    private static function coordsFromAddress(array $address): array
    {
        if (!array_key_exists('latitude', $address) || !array_key_exists('longitude', $address)) {
            return [null, null, false];
        }
        $latRaw = $address['latitude'];
        $lonRaw = $address['longitude'];
        if ($latRaw === null || $lonRaw === null || $latRaw === '' || $lonRaw === '') {
            return [null, null, false];
        }
        $lat = (float) $latRaw;
        $lon = (float) $lonRaw;
        if (abs($lat) > 90 || abs($lon) > 180) {
            return [null, null, false];
        }

        return [$lat, $lon, true];
    }

    /**
     * @param list<array<string, mixed>> $cartLines
     * @param array<string, mixed>|null $pricingTotals
     */
    private static function subtotalAfterDiscounts(array $cartLines, ?array $pricingTotals): float
    {
        if ($pricingTotals !== null) {
            $sub = (float) ($pricingTotals['subtotal'] ?? 0);
            $offer = (float) ($pricingTotals['offer_discount'] ?? 0);
            $coupon = (float) ($pricingTotals['coupon_discount'] ?? 0);

            return max(0, round($sub - $offer - $coupon, 2));
        }

        $totals = PricingService::totalsFromLines($cartLines, null);

        return max(0, round(
            (float) ($totals['subtotal'] ?? 0)
            - (float) ($totals['offer_discount'] ?? 0)
            - (float) ($totals['coupon_discount'] ?? 0),
            2
        ));
    }

    /** @return array<string, mixed> */
    private static function fail(string $code, string $message): array
    {
        return [
            'ok' => false,
            'failure_code' => $code,
            'message' => $message,
        ];
    }

    /**
     * @param list<array<string, mixed>> $cartLines
     */
    private static function assertWeightWithinZone(array $zone, array $cartLines, ?PDO $pdo): ?string
    {
        if (!isset($zone['max_order_weight_kg']) || $zone['max_order_weight_kg'] === null || $zone['max_order_weight_kg'] === '') {
            return null;
        }
        $max = (float) $zone['max_order_weight_kg'];
        if ($max <= 0) {
            return null;
        }
        $total = self::cartWeightKg($cartLines, $pdo);
        if ($total === null) {
            return 'We cannot confirm order weight for delivery limits. Try again or contact support.';
        }
        if ($total > $max + 0.0001) {
            return 'This order exceeds the ' . $max . ' kg delivery limit for your zone.';
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $cartLines
     */
    private static function cartWeightKg(array $cartLines, ?PDO $pdo): ?float
    {
        if ($cartLines === []) {
            return 0.0;
        }
        if ($pdo === null && Install::isSchemaReady()) {
            $pdo = Database::pdo();
        }
        if ($pdo === null) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT weight_kg FROM product_variants WHERE id = ? LIMIT 1');
        $total = 0.0;
        foreach ($cartLines as $line) {
            $vid = (int) ($line['variant_id'] ?? 0);
            $qty = (int) ($line['qty'] ?? 0);
            if ($vid <= 0 || $qty <= 0) {
                continue;
            }
            $stmt->execute([$vid]);
            $w = $stmt->fetchColumn();
            if ($w === false || $w === null) {
                return null;
            }
            $total += (float) $w * $qty;
        }

        return round($total, 3);
    }

    /** @return array<string, mixed> */
    private static function resolveSlotForQuote(
        string $slotSlug,
        ?PDO $pdo,
        ?int $excludeOrderId,
        ?string $deliveryDateOverride = null
    ): array {
        if (!DeliveryConfig::slotsEnabled()) {
            return [
                'ok' => true,
                'slug' => 'auto',
                'slot_id' => null,
                'slot_label' => null,
                'slot_window' => null,
                'delivery_date' => null,
                'delivery_eta' => DeliverySlotService::automaticEta(),
                'slot_resolved' => null,
                'slot_row' => null,
            ];
        }

        $slotResolved = DeliverySlotService::resolveBySlug($slotSlug);
        if ($slotResolved === null && ($deliveryDateOverride === null || $deliveryDateOverride === '')) {
            return ['ok' => false, 'failure_code' => 'slot_invalid', 'message' => 'Choose a valid delivery slot.'];
        }

        Install::ensureConnection();
        $conn = $pdo ?? Database::pdo();
        $stmt = $conn->prepare('SELECT * FROM delivery_slots WHERE slug = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$slotSlug]);
        $slotRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$slotRow) {
            return ['ok' => false, 'failure_code' => 'slot_invalid', 'message' => 'Choose a valid delivery slot.'];
        }

        $deliveryDate = ($deliveryDateOverride !== null && $deliveryDateOverride !== '')
            ? $deliveryDateOverride
            : (string) ($slotResolved['delivery_date'] ?? '');

        if ($deliveryDate === '') {
            return ['ok' => false, 'failure_code' => 'slot_invalid', 'message' => 'Choose a valid delivery slot.'];
        }

        $bookErr = DeliverySlotService::assertBookable($slotRow, $deliveryDate);
        if ($bookErr !== null) {
            return ['ok' => false, 'failure_code' => 'slot_unavailable', 'message' => $bookErr];
        }

        $slotId = (int) $slotRow['id'];
        if ($deliveryDateOverride !== null && $deliveryDateOverride !== '') {
            if ($slotResolved === null) {
                $slotResolved = [
                    'id' => $slotId,
                    'slug' => $slotSlug,
                    'label' => (string) ($slotRow['label'] ?? $slotSlug),
                    'window' => DeliverySlotDisplay::formatWindowRange(
                        (string) ($slotRow['start_time'] ?? ''),
                        (string) ($slotRow['end_time'] ?? '')
                    ) ?? '',
                    'delivery_date' => $deliveryDate,
                    'row' => $slotRow,
                ];
            } else {
                $slotResolved['delivery_date'] = $deliveryDate;
                $slotResolved['id'] = $slotId;
            }
        }
        if ($pdo !== null) {
            try {
                DeliverySlotReservationService::lockSlotAndAssertCapacity($pdo, $slotId, $deliveryDate, $excludeOrderId);
            } catch (DeliverySlotBookingException $e) {
                return ['ok' => false, 'failure_code' => 'slot_full', 'message' => $e->getMessage()];
            }
        }

        return [
            'ok' => true,
            'slug' => $slotSlug,
            'slot_id' => $slotId,
            'slot_label' => (string) ($slotRow['label'] ?? $slotSlug),
            'slot_window' => (string) ($slotRow['window_label'] ?? ''),
            'slot_start_time' => (string) ($slotRow['start_time'] ?? ''),
            'slot_end_time' => (string) ($slotRow['end_time'] ?? ''),
            'delivery_date' => $deliveryDate,
            'delivery_eta' => null,
            'slot_resolved' => $slotResolved,
            'slot_row' => $slotRow,
        ];
    }

    /**
     * @param array<string, mixed> $store delivery_store row
     * @param array<string, mixed>|null $slotRow
     * @return array<string, mixed>
     */
    private static function resolveProvider(array $store, ?array $slotRow): array
    {
        $allowed = ['manual'];
        if ($slotRow !== null && !empty($slotRow['allowed_provider_slugs_json'])) {
            $decoded = json_decode((string) $slotRow['allowed_provider_slugs_json'], true);
            if (is_array($decoded) && $decoded !== []) {
                $allowed = array_values(array_map('strval', $decoded));
            }
        }

        $manualFallback = !empty($store['manual_delivery_when_provider_unavailable']);
        $distanceMode = (string) ($store['default_distance_mode'] ?? 'haversine');
        $providerSlug = (string) ($store['road_distance_provider'] ?? 'none');
        $externalConfigured = $distanceMode === 'road'
            && $providerSlug !== 'none'
            && self::externalRoadProviderAvailable($providerSlug);

        if ($externalConfigured && in_array($providerSlug, $allowed, true)) {
            return [
                'ok' => true,
                'fulfilment_mode' => 'provider',
                'provider_slug' => $providerSlug,
                'provider_quote_ref' => null,
            ];
        }

        if ($externalConfigured && !in_array($providerSlug, $allowed, true)) {
            if ($manualFallback && in_array('manual', $allowed, true)) {
                return [
                    'ok' => true,
                    'fulfilment_mode' => 'manual',
                    'provider_slug' => 'manual',
                    'provider_quote_ref' => null,
                ];
            }

            return [
                'ok' => false,
                'failure_code' => 'provider_unavailable',
                'message' => 'Configured delivery provider is not available for this slot.',
            ];
        }

        if (!$externalConfigured && $providerSlug !== 'none' && $distanceMode === 'road') {
            if ($manualFallback && in_array('manual', $allowed, true)) {
                return [
                    'ok' => true,
                    'fulfilment_mode' => 'manual',
                    'provider_slug' => 'manual',
                    'provider_quote_ref' => null,
                ];
            }

            return [
                'ok' => false,
                'failure_code' => 'provider_unavailable',
                'message' => 'Delivery provider is unavailable and manual delivery is not allowed for this slot.',
            ];
        }

        if (!in_array('manual', $allowed, true)) {
            return [
                'ok' => false,
                'failure_code' => 'provider_unavailable',
                'message' => 'Manual delivery is not allowed for this delivery slot.',
            ];
        }

        return [
            'ok' => true,
            'fulfilment_mode' => 'manual',
            'provider_slug' => 'manual',
            'provider_quote_ref' => null,
        ];
    }

    private static function externalRoadProviderAvailable(string $slug): bool
    {
        return false;
    }

    /**
     * @param list<array<string, mixed>> $orderItems
     * @return list<array<string, mixed>>
     */
    private static function cartLinesFromOrderItems(array $orderItems): array
    {
        $lines = [];
        foreach ($orderItems as $it) {
            $lines[] = [
                'variant_id' => (int) ($it['variant_id'] ?? 0),
                'qty' => (int) ($it['quantity'] ?? 0),
                'unit_price' => (float) ($it['unit_price'] ?? 0),
                'grants_free_delivery' => false,
            ];
        }

        return $lines;
    }
}
