<?php

declare(strict_types=1);

/**
 * Aligns delivery slot reservation expiry with active gateway payment sessions (Cashfree + Razorpay).
 *
 * Checkout keeps the short DEFAULT_HOLD_MINUTES window. Payment session start/resume extends the hold
 * to the gateway session horizon, capped by ABSOLUTE_MAX_HOLD_MINUTES from reservation created_at.
 */
final class PaymentSlotHoldService
{
    /** Prevents recursive refresh → synchronize → lockSlot → releaseExpired → refresh loops. */
    private static bool $refreshHoldsInProgress = false;

    /** Maximum total hold duration from first reservation row (prevents indefinite extension). */
    public const ABSOLUTE_MAX_HOLD_MINUTES = 240;

    /** Bounded Cashfree PG order/session horizon when API expiry is unavailable. */
    public const CASHFREE_SESSION_MINUTES = 120;

    /** Razorpay order expiry (API default window; each new order resets this). */
    public const RAZORPAY_SESSION_MINUTES = 15;

    /**
     * Call after a gateway payment session is created (new attempt).
     *
     * @param DateTimeInterface|null $gatewaySessionExpires Explicit PG expiry when known (Cashfree order_expiry_time, Razorpay expire_at).
     */
    public static function afterPaymentSessionStarted(
        PDO $pdo,
        int $orderId,
        string $gateway,
        ?DateTimeInterface $gatewaySessionExpires = null
    ): void {
        if ($orderId <= 0 || !DeliverySlotReservationService::tableReady()) {
            return;
        }
        if (!self::orderAllowsHoldExtension($pdo, $orderId)) {
            return;
        }
        $target = self::targetExpiryForGateway($gateway, $gatewaySessionExpires);
        self::applyHoldTarget($pdo, $orderId, $target);
    }

    /** Call when resuming an existing Cashfree session (no new PG order). */
    public static function afterPaymentSessionResumed(PDO $pdo, int $orderId, ?DateTimeInterface $gatewaySessionExpires = null): void
    {
        self::afterPaymentSessionStarted($pdo, $orderId, 'cashfree', $gatewaySessionExpires);
    }

    /**
     * Before releasing expired holds, extend reservations that still have an open gateway attempt.
     */
    public static function refreshHoldsForOpenPayments(PDO $pdo): void
    {
        if (self::$refreshHoldsInProgress || !DeliverySlotReservationService::tableReady()) {
            return;
        }
        self::$refreshHoldsInProgress = true;
        try {
            $stmt = $pdo->query(
                "SELECT DISTINCT r.order_id FROM delivery_slot_reservations r
                 WHERE r.status IN ('held', 'released') AND r.order_id IS NOT NULL
                 AND r.expires_at <= DATE_ADD(NOW(), INTERVAL 2 MINUTE)"
            );
            if ($stmt === false) {
                return;
            }
            while (($orderId = $stmt->fetchColumn()) !== false) {
                $oid = (int) $orderId;
                if ($oid > 0) {
                    self::synchronizeOpenPaymentHold($pdo, $oid);
                }
            }
        } finally {
            self::$refreshHoldsInProgress = false;
        }
    }

    public static function synchronizeOpenPaymentHold(PDO $pdo, int $orderId): bool
    {
        if ($orderId <= 0 || !DeliverySlotReservationService::tableReady()) {
            return false;
        }
        if (!self::orderAllowsHoldExtension($pdo, $orderId)) {
            return false;
        }
        $payment = self::latestOpenPayment($pdo, $orderId);
        if ($payment === null) {
            return false;
        }
        $gateway = (string) ($payment['gateway'] ?? '');
        $sessionExpires = self::sessionExpiryFromPaymentMetadata($payment);
        $target = self::targetExpiryForGateway($gateway, $sessionExpires);

        return self::applyHoldTarget($pdo, $orderId, $target);
    }

    public static function cashfreeOrderExpiryTime(): DateTimeImmutable
    {
        return DeliveryConfig::now()->modify('+' . self::CASHFREE_SESSION_MINUTES . ' minutes');
    }

    public static function razorpayExpireAtUnix(): int
    {
        return DeliveryConfig::now()->getTimestamp() + (self::RAZORPAY_SESSION_MINUTES * 60);
    }

    /**
     * @param array<string, mixed> $fetchOrder CashfreeGateway::fetchOrder result
     */
    public static function cashfreeExpiryFromFetchOrder(array $fetchOrder): ?DateTimeImmutable
    {
        $raw = (string) ($fetchOrder['order_expiry_time'] ?? '');
        if ($raw === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function hasOpenPaymentCompletionException(PDO $pdo, int $orderId): bool
    {
        try {
            $n = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_completion_exceptions'"
            )->fetchColumn();
            if ($n === 0) {
                return false;
            }
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM payment_completion_exceptions WHERE order_id = ? AND status = 'open'"
            );
            $st->execute([$orderId]);

            return (int) $st->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function orderAllowsHoldExtension(PDO $pdo, int $orderId): bool
    {
        $stmt = $pdo->prepare('SELECT payment_status, status FROM orders WHERE id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $pay = (string) ($row['payment_status'] ?? '');
        if ($pay === 'paid') {
            return false;
        }
        if (self::hasOpenPaymentCompletionException($pdo, $orderId)) {
            return false;
        }
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM payments WHERE order_id = ? AND status = 'reconciliation_required'"
        );
        $st->execute([$orderId]);
        if ((int) $st->fetchColumn() > 0) {
            return false;
        }
        if (!in_array($pay, ['pending', 'failed'], true)) {
            return false;
        }
        $status = (string) ($row['status'] ?? '');
        if (in_array($status, ['cancelled', 'confirmed', 'delivered', 'completed'], true)) {
            return false;
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    private static function latestOpenPayment(PDO $pdo, int $orderId): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM payments WHERE order_id = ? AND status IN ('created', 'pending')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @param array<string, mixed> $payment */
    private static function sessionExpiryFromPaymentMetadata(array $payment): ?DateTimeInterface
    {
        $raw = (string) ($payment['metadata_json'] ?? '');
        if ($raw === '') {
            return null;
        }
        try {
            $meta = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($meta)) {
            return null;
        }
        $iso = (string) ($meta['payment_session_expires_at'] ?? '');
        if ($iso === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($iso);
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function targetExpiryForGateway(string $gateway, ?DateTimeInterface $gatewaySessionExpires): DateTimeImmutable
    {
        $now = DeliveryConfig::now();
        $policyMinutes = match ($gateway) {
            'cashfree' => self::CASHFREE_SESSION_MINUTES,
            'razorpay' => self::RAZORPAY_SESSION_MINUTES,
            default => DeliverySlotReservationService::DEFAULT_HOLD_MINUTES,
        };
        $policyEnd = $now->modify('+' . $policyMinutes . ' minutes');
        if ($gatewaySessionExpires !== null) {
            $gw = DateTimeImmutable::createFromInterface($gatewaySessionExpires);

            return $gw < $policyEnd ? $gw : $policyEnd;
        }

        return $policyEnd;
    }

    private static function applyHoldTarget(PDO $pdo, int $orderId, DateTimeInterface $targetExpires): bool
    {
        $stmt = $pdo->prepare(
            'SELECT delivery_slot_id, delivery_date FROM orders WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return false;
        }
        $slotId = (int) ($order['delivery_slot_id'] ?? 0);
        $deliveryDate = (string) ($order['delivery_date'] ?? '');
        if ($slotId <= 0 || $deliveryDate === '') {
            return false;
        }

        $holdStmt = $pdo->prepare('SELECT * FROM delivery_slot_reservations WHERE order_id = ? FOR UPDATE');
        $holdStmt->execute([$orderId]);
        $hold = $holdStmt->fetch(PDO::FETCH_ASSOC);

        $cap = null;
        if ($hold !== false) {
            try {
                $created = new DateTimeImmutable((string) ($hold['created_at'] ?? 'now'));
                $cap = $created->modify('+' . self::ABSOLUTE_MAX_HOLD_MINUTES . ' minutes');
            } catch (Throwable $e) {
                $cap = null;
            }
        }
        $target = DateTimeImmutable::createFromInterface($targetExpires);
        if ($cap !== null && $target > $cap) {
            $target = $cap;
        }
        $now = DeliveryConfig::now();
        if ($target <= $now) {
            return false;
        }

        if ($hold === false || (string) ($hold['status'] ?? '') === DeliverySlotReservationService::STATUS_RELEASED) {
            $minutes = (int) max(5, ceil(($target->getTimestamp() - $now->getTimestamp()) / 60));
            try {
                DeliverySlotReservationService::lockSlotAndAssertCapacity($pdo, $slotId, $deliveryDate, $orderId);
            } catch (DeliverySlotBookingException $e) {
                return false;
            }
            DeliverySlotReservationService::createHold($pdo, $slotId, $deliveryDate, $orderId, $minutes);

            return true;
        }

        return DeliverySlotReservationService::extendHoldExpiry($pdo, $orderId, $target);
    }

    public static function storeSessionExpiryOnPayment(PDO $pdo, int $paymentId, DateTimeInterface $expires): void
    {
        if ($paymentId <= 0) {
            return;
        }
        $iso = $expires->format(DateTimeInterface::ATOM);
        $stmt = $pdo->prepare('SELECT metadata_json FROM payments WHERE id = ? LIMIT 1');
        $stmt->execute([$paymentId]);
        $raw = (string) ($stmt->fetchColumn() ?: '');
        $meta = [];
        if ($raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            } catch (Throwable $e) {
                $meta = [];
            }
        }
        $meta['payment_session_expires_at'] = $iso;
        $pdo->prepare('UPDATE payments SET metadata_json = ? WHERE id = ?')
            ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $paymentId]);
    }
}
