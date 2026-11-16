<?php

declare(strict_types=1);

/**
 * Server-side idempotent storefront checkout — reuse eligible unpaid orders instead of creating duplicates.
 */
final class CheckoutIdempotencyService
{
    /**
     * @param list<array<string, mixed>> $cartLines CartService::items() shape
     * @return array{
     *     signature: string,
     *     customer_id: int,
     *     address_id: int,
     *     delivery_slot: string,
     *     delivery_date: string,
     *     delivery_slot_id: int,
     *     grand_total: float,
     *     coupon_code: string
     * }
     */
    public static function buildSpec(
        int $customerId,
        int $addressId,
        string $deliverySlot,
        ?string $deliveryDateYmd,
        ?int $deliverySlotId,
        float $grandTotal,
        ?string $couponCode,
        array $cartLines
    ): array {
        $deliveryDate = $deliveryDateYmd ?? '';
        $slotId = $deliverySlotId ?? 0;
        $coupon = $couponCode ?? '';
        $payload = [
            'customer_id' => $customerId,
            'address_id' => $addressId,
            'slot' => $deliverySlot,
            'delivery_date' => $deliveryDate,
            'slot_id' => $slotId,
            'grand_total' => round($grandTotal, 2),
            'coupon' => $coupon,
            'lines' => self::cartFingerprintLines($cartLines),
        ];

        return [
            'signature' => 'sig:' . hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE)),
            'customer_id' => $customerId,
            'address_id' => $addressId,
            'delivery_slot' => $deliverySlot,
            'delivery_date' => $deliveryDate,
            'delivery_slot_id' => $slotId,
            'grand_total' => round($grandTotal, 2),
            'coupon_code' => $coupon,
        ];
    }

    /**
     * @param list<array<string, mixed>> $cartLines
     * @return list<array{variant_id: int, qty: int}>
     */
    private static function cartFingerprintLines(array $cartLines): array
    {
        $out = [];
        foreach ($cartLines as $line) {
            $out[] = ['variant_id' => (int) ($line['variant_id'] ?? 0), 'qty' => (int) ($line['qty'] ?? 0)];
        }
        usort($out, static function (array $a, array $b): int {
            return $a['variant_id'] <=> $b['variant_id'];
        });

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $cartLines
     * @param array<string, mixed> $spec Result of buildSpec()
     * @return array{ok: bool, order_id?: int, order_number?: string, reused?: bool, message?: string}
     */
    public static function resolveOrCreateOrder(PDO $pdo, array $spec, array $cartLines, callable $createNewOrder): array
    {
        $customerId = (int) $spec['customer_id'];
        $signature = (string) $spec['signature'];
        $pdo->prepare('SELECT id FROM customers WHERE id = ? FOR UPDATE')->execute([$customerId]);

        $existing = self::findEligibleUnpaidOrderForUpdate($pdo, $customerId, $signature, $spec, $cartLines);
        if ($existing !== null) {
            if (self::orderHasCapturedPayment($pdo, (int) $existing['id'])) {
                return ['ok' => false, 'message' => 'This order already has a completed payment.'];
            }

            return [
                'ok' => true,
                'order_id' => (int) $existing['id'],
                'order_number' => (string) $existing['order_number'],
                'reused' => true,
            ];
        }

        self::voidIncompatibleUnpaidOrders($pdo, $customerId, $spec, $cartLines);

        $created = $createNewOrder();
        if (empty($created['ok'])) {
            return $created;
        }

        return $created + ['reused' => false];
    }

    /**
     * @param list<array<string, mixed>> $cartLines
     * @param array<string, mixed> $spec
     * @return array<string, mixed>|null orders row
     */
    public static function findEligibleUnpaidOrderForUpdate(
        PDO $pdo,
        int $customerId,
        string $checkoutSignature,
        array $spec,
        array $cartLines
    ): ?array {
        $stmt = $pdo->prepare(
            "SELECT * FROM orders WHERE customer_id = ? AND status = 'pending_payment'
             AND payment_status IN ('pending', 'failed')
             AND checkout_nonce = ?
             ORDER BY id DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$customerId, $checkoutSignature]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $row = self::findLegacyEligibleOrder($pdo, $customerId, $checkoutSignature, $spec, $cartLines);
        }
        if ($row === false || $row === null) {
            return null;
        }
        if (!self::orderMatchesSpec($row, $spec)) {
            return null;
        }
        if (!self::orderLinesMatchCart($pdo, (int) $row['id'], $cartLines)) {
            return null;
        }
        if (self::orderHasCapturedPayment($pdo, (int) $row['id'])) {
            return null;
        }

        return $row;
    }

    /**
     * @param list<array<string, mixed>> $cartLines
     * @param array<string, mixed> $spec
     * @return array<string, mixed>|null
     */
    private static function findLegacyEligibleOrder(
        PDO $pdo,
        int $customerId,
        string $checkoutSignature,
        array $spec,
        array $cartLines
    ): ?array {
        $stmt = $pdo->prepare(
            "SELECT * FROM orders WHERE customer_id = ? AND status = 'pending_payment'
             AND payment_status IN ('pending', 'failed')
             AND (checkout_nonce IS NULL OR checkout_nonce = '' OR checkout_nonce NOT LIKE 'sig:%')
             ORDER BY id DESC LIMIT 15 FOR UPDATE"
        );
        $stmt->execute([$customerId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!self::orderMatchesSpec($row, $spec)) {
                continue;
            }
            if (!self::orderLinesMatchCart($pdo, (int) $row['id'], $cartLines)) {
                continue;
            }
            if (self::orderHasCapturedPayment($pdo, (int) $row['id'])) {
                continue;
            }
            $pdo->prepare('UPDATE orders SET checkout_nonce = ? WHERE id = ?')->execute([$checkoutSignature, (int) $row['id']]);

            return $row;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $orderRow
     * @param array<string, mixed> $spec
     */
    public static function orderMatchesSpec(array $orderRow, array $spec): bool
    {
        if ((int) ($orderRow['customer_address_id'] ?? 0) !== (int) $spec['address_id']) {
            return false;
        }
        if (abs((float) ($orderRow['grand_total'] ?? 0) - (float) $spec['grand_total']) > 0.009) {
            return false;
        }
        $orderSlot = (string) ($orderRow['delivery_slot'] ?? '');
        if ($orderSlot !== (string) $spec['delivery_slot']) {
            return false;
        }
        $orderDate = (string) ($orderRow['delivery_date'] ?? '');
        if ($orderDate !== '' && (string) $spec['delivery_date'] !== '' && $orderDate !== (string) $spec['delivery_date']) {
            return false;
        }
        $orderSlotId = (int) ($orderRow['delivery_slot_id'] ?? 0);
        $specSlotId = (int) ($spec['delivery_slot_id'] ?? 0);
        if ($specSlotId > 0 && $orderSlotId > 0 && $orderSlotId !== $specSlotId) {
            return false;
        }
        $orderCoupon = (string) ($orderRow['coupon_code'] ?? '');
        $specCoupon = (string) ($spec['coupon_code'] ?? '');
        if ($orderCoupon !== $specCoupon) {
            return false;
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $cartLines
     */
    public static function orderLinesMatchCart(PDO $pdo, int $orderId, array $cartLines): bool
    {
        $stmt = $pdo->prepare(
            'SELECT variant_id, quantity FROM order_items WHERE order_id = ? ORDER BY variant_id ASC'
        );
        $stmt->execute([$orderId]);
        $dbLines = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dbLines[] = ['variant_id' => (int) $row['variant_id'], 'qty' => (int) $row['quantity']];
        }

        return $dbLines === self::cartFingerprintLines($cartLines);
    }

    public static function orderHasCapturedPayment(PDO $pdo, int $orderId): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE order_id = ? AND status = 'captured'");
        $stmt->execute([$orderId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function orderHasInFlightPayment(PDO $pdo, int $orderId): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM payments WHERE order_id = ? AND status IN ('created', 'pending')"
        );
        $stmt->execute([$orderId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @param list<array<string, mixed>> $cartLines
     * @param array<string, mixed> $spec
     */
    public static function voidIncompatibleUnpaidOrders(PDO $pdo, int $customerId, array $spec, array $cartLines): void
    {
        $signature = (string) $spec['signature'];
        $stmt = $pdo->prepare(
            "SELECT * FROM orders WHERE customer_id = ? AND status = 'pending_payment'
             AND payment_status IN ('pending', 'failed')"
        );
        $stmt->execute([$customerId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $oid = (int) $row['id'];
            $storedSig = (string) ($row['checkout_nonce'] ?? '');
            if ($storedSig === $signature) {
                continue;
            }
            $matches = self::orderMatchesSpec($row, $spec) && self::orderLinesMatchCart($pdo, $oid, $cartLines);
            if ($matches) {
                $pdo->prepare('UPDATE orders SET checkout_nonce = ? WHERE id = ?')->execute([$signature, $oid]);
                continue;
            }
            if ($storedSig !== '' && str_starts_with($storedSig, 'sig:')) {
                self::voidUnpaidOrder($pdo, $oid, 'Superseded by a new checkout with different cart or delivery details.');
                continue;
            }
            if (!self::orderLinesMatchCart($pdo, $oid, $cartLines) || !self::orderMatchesSpec($row, $spec)) {
                self::voidUnpaidOrder($pdo, $oid, 'Superseded by a new checkout (cart or delivery changed).');
            }
        }
    }

    private static function voidUnpaidOrder(PDO $pdo, int $orderId, string $note): void
    {
        if (self::orderHasCapturedPayment($pdo, $orderId)) {
            return;
        }
        if (self::orderHasInFlightPayment($pdo, $orderId)) {
            return;
        }
        $pdo->prepare(
            "UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND status = 'pending_payment'"
        )->execute([$orderId]);
        $pdo->prepare('INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)')
            ->execute([$orderId, OrderStatus::CANCELLED, $note]);
        DeliverySlotReservationService::releaseForOrder($orderId, $pdo);
    }
}
