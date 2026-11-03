<?php

declare(strict_types=1);

final class SubscriptionCheckoutService
{
    /**
     * @return array{ok: bool, message?: string, subscription_number?: string, redirect?: string}
     */
    public static function beginPurchase(int $planId, int $customerId, string $preferredSlotSlug = ''): array
    {
        if (!Install::isSchemaReady() || !CommerceGateway::useDatabase()) {
            return ['ok' => false, 'message' => 'Subscriptions are not available yet.'];
        }
        Install::ensureConnection();
        $plan = SubscriptionPlanRepository::find($planId);
        if ($plan === null || empty($plan['is_active'])) {
            return ['ok' => false, 'message' => 'This plan is not available.'];
        }

        $price = round((float) $plan['price'], 2);
        $snapshot = SubscriptionPlanRepository::toSnapshotArray($plan, $price);
        $valid = SubscriptionPlanValidator::validate(array_merge($plan, [
            'frequency_config' => json_decode((string) ($plan['frequency_config'] ?? '{}'), true),
            'basket_rules_json' => $snapshot['basket_rules_json'],
        ]));
        if (!$valid['ok']) {
            return ['ok' => false, 'message' => 'Plan configuration is invalid. Please contact support.'];
        }

        $preferredSlotSlug = trim($preferredSlotSlug);
        if ($preferredSlotSlug !== '') {
            $slotErr = SubscriptionSlotService::validatePreferredSlug($preferredSlotSlug);
            if ($slotErr !== null) {
                return ['ok' => false, 'message' => $slotErr];
            }
        }

        $number = 'SUB-' . date('ymd') . '-' . strtoupper(substr(Security::randomToken(4), 0, 6));
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO customer_subscriptions (subscription_number, customer_id, subscription_plan_id,
             plan_snapshot_json, price_paid, status, deliveries_remaining, preferred_delivery_slot_slug)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $number,
            $customerId,
            $planId,
            json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            $price,
            'pending_payment',
            (int) $plan['delivery_count'],
            $preferredSlotSlug !== '' ? $preferredSlotSlug : null,
        ]);
        $subId = (int) $pdo->lastInsertId();

        $mobile = (string) (CustomerService::profile()['mobile'] ?? '');
        $init = SubscriptionPaymentService::initiateForSubscription($subId, $number, $price, $mobile);
        if (!$init['ok']) {
            return ['ok' => false, 'message' => $init['error'] ?? 'Could not start payment.'];
        }
        $_SESSION['checkout_payment'][$number] = array_merge($init['checkout'] ?? [], [
            'payment_context' => 'subscription',
        ]);

        return [
            'ok' => true,
            'subscription_number' => $number,
            'redirect' => url_base() . '/subscription-payment.php?sub=' . rawurlencode($number),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function publicPlanCard(array $planRow): ?array
    {
        if (empty($planRow['is_active'])) {
            return null;
        }
        $basket = json_decode((string) ($planRow['basket_rules_json'] ?? '{}'), true) ?: [];
        $freq = (string) ($planRow['frequency_type'] ?? 'daily');

        return [
            'id' => (int) $planRow['id'],
            'name' => (string) $planRow['name'],
            'description' => (string) ($planRow['description'] ?? ''),
            'calendar_duration_days' => (int) $planRow['calendar_duration_days'],
            'delivery_count' => (int) $planRow['delivery_count'],
            'frequency_label' => self::frequencyLabel($freq),
            'daily_allowance_inr' => (float) ($basket['daily_allowance_inr'] ?? 0),
            'unused_allowance_policy' => (string) ($basket['unused_allowance_policy'] ?? 'expire'),
            'price' => (float) $planRow['price'],
            'compare_at_price' => isset($planRow['compare_at_price']) ? (float) $planRow['compare_at_price'] : null,
        ];
    }

    private static function frequencyLabel(string $freq): string
    {
        if ($freq === 'alternate') {
            return 'Alternate days';
        }
        if ($freq === 'weekdays') {
            return 'Selected weekdays';
        }
        if ($freq === 'custom_interval') {
            return 'Custom interval';
        }

        return 'Daily';
    }
}

final class SubscriptionPaymentService
{
    public static function nextIdempotencyKey(string $subscriptionNumber): string
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM subscription_payments sp
             INNER JOIN customer_subscriptions cs ON cs.id = sp.customer_subscription_id
             WHERE cs.subscription_number = ?'
        );
        $stmt->execute([$subscriptionNumber]);

        return 'subpay_' . $subscriptionNumber . '_' . ((int) $stmt->fetchColumn() + 1);
    }

    /** @return array<string, mixed>|null */
    public static function latestOpenPayment(int $subId): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM subscription_payments WHERE customer_subscription_id = ?
             AND status IN (\'created\', \'pending\') ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$subId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Rebuild checkout payload for an existing open payment (no new payment row).
     *
     * @return array{ok: bool, checkout?: array<string, mixed>, error?: string}
     */
    public static function checkoutForOpenPayment(array $payment, string $subNumber, float $amountInr, string $customerMobile): array
    {
        $gateway = (string) ($payment['gateway'] ?? 'simulate');
        $idempotency = (string) ($payment['idempotency_key'] ?? '');
        if ($idempotency === '') {
            return ['ok' => false, 'error' => 'Payment session invalid.'];
        }

        if ($gateway === 'simulate') {
            return [
                'ok' => true,
                'checkout' => [
                    'mode' => 'simulate',
                    'token' => PaymentService::issueSimulateToken($subNumber),
                    'idempotency_key' => $idempotency,
                    'payment_context' => 'subscription',
                ],
            ];
        }

        if ($gateway === 'razorpay') {
            $gwOrderId = (string) ($payment['gateway_order_id'] ?? '');
            if ($gwOrderId === '') {
                return ['ok' => false, 'error' => 'Gateway order missing. Refresh or contact support.'];
            }

            return [
                'ok' => true,
                'checkout' => [
                    'mode' => 'razorpay',
                    'key_id' => RazorpayGateway::keyId(),
                    'amount_paise' => PaymentService::amountToPaise($amountInr),
                    'gateway_order_id' => $gwOrderId,
                    'subscription_number' => $subNumber,
                    'idempotency_key' => $idempotency,
                    'payment_context' => 'subscription',
                ],
            ];
        }

        $sessionId = '';
        $metaRaw = $payment['metadata_json'] ?? null;
        if (is_string($metaRaw) && $metaRaw !== '') {
            $meta = json_decode($metaRaw, true);
            if (is_array($meta)) {
                $sessionId = (string) ($meta['payment_session_id'] ?? '');
            }
        }
        if ($sessionId === '') {
            return ['ok' => false, 'error' => 'Payment session expired. Contact support.'];
        }

        return [
            'ok' => true,
            'checkout' => [
                'mode' => 'cashfree',
                'app_id' => CashfreeGateway::appId(),
                'payment_session_id' => $sessionId,
                'subscription_number' => $subNumber,
                'idempotency_key' => $idempotency,
                'payment_context' => 'subscription',
            ],
        ];
    }

    /**
     * @return array{ok: bool, payment_id?: int, checkout?: array<string, mixed>, error?: string}
     */
    public static function initiateForSubscription(int $subId, string $subNumber, float $amountInr, string $customerMobile): array
    {
        $existing = self::latestOpenPayment($subId);
        if ($existing !== null) {
            $resume = self::checkoutForOpenPayment($existing, $subNumber, $amountInr, $customerMobile);
            if ($resume['ok']) {
                return [
                    'ok' => true,
                    'payment_id' => (int) $existing['id'],
                    'checkout' => $resume['checkout'],
                ];
            }
        }

        $gateway = PaymentGatewayConfig::activeGateway();
        if (PaymentGatewayConfig::useSimulatedCheckout()) {
            $gateway = 'simulate';
        }
        $idempotency = self::nextIdempotencyKey($subNumber);
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO subscription_payments (customer_subscription_id, gateway, amount, currency, status, idempotency_key)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$subId, $gateway, round($amountInr, 2), 'INR', 'created', $idempotency]);
        $paymentId = (int) $pdo->lastInsertId();

        if ($gateway === 'simulate') {
            $token = PaymentService::issueSimulateToken($subNumber);

            return [
                'ok' => true,
                'payment_id' => $paymentId,
                'checkout' => [
                    'mode' => 'simulate',
                    'token' => $token,
                    'idempotency_key' => $idempotency,
                    'payment_context' => 'subscription',
                ],
            ];
        }

        if ($gateway === 'razorpay') {
            $payRef = PaymentInitDiagnostics::referenceId();
            $created = RazorpayGateway::createOrder(
                PaymentService::amountToPaise($amountInr),
                $subNumber,
                $idempotency,
                $payRef,
                null
            );
            if (!$created['ok']) {
                return ['ok' => false, 'error' => $created['error'] ?? 'Gateway error'];
            }
            $gwOrderId = (string) $created['gateway_order_id'];
            $pdo->prepare('UPDATE subscription_payments SET gateway_order_id = ?, status = ? WHERE id = ?')
                ->execute([$gwOrderId, 'pending', $paymentId]);

            return [
                'ok' => true,
                'payment_id' => $paymentId,
                'checkout' => [
                    'mode' => 'razorpay',
                    'key_id' => RazorpayGateway::keyId(),
                    'amount_paise' => PaymentService::amountToPaise($amountInr),
                    'gateway_order_id' => $gwOrderId,
                    'subscription_number' => $subNumber,
                    'idempotency_key' => $idempotency,
                    'payment_context' => 'subscription',
                ],
            ];
        }

        $returnUrl = SeoUrl::absolute('/subscription-payment.php?sub=' . rawurlencode($subNumber));
        $payRef = PaymentInitDiagnostics::referenceId();
        $created = CashfreeGateway::createOrder($amountInr, $subNumber, $customerMobile, $returnUrl, $payRef, null);
        if (!$created['ok']) {
            return ['ok' => false, 'error' => $created['error'] ?? 'Gateway error'];
        }
        $sessionId = (string) ($created['payment_session_id'] ?? '');
        $pdo->prepare(
            'UPDATE subscription_payments SET gateway_order_id = ?, status = ?, metadata_json = ? WHERE id = ?'
        )->execute([
            (string) $created['gateway_order_id'],
            'pending',
            json_encode(['payment_session_id' => $sessionId], JSON_UNESCAPED_UNICODE),
            $paymentId,
        ]);

        return [
            'ok' => true,
            'payment_id' => $paymentId,
            'checkout' => [
                'mode' => 'cashfree',
                'app_id' => CashfreeGateway::appId(),
                'payment_session_id' => (string) ($created['payment_session_id'] ?? ''),
                'subscription_number' => $subNumber,
                'idempotency_key' => $idempotency,
                'payment_context' => 'subscription',
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function paymentByIdempotency(string $key): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM subscription_payments WHERE idempotency_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function paymentByGatewayOrderId(string $gatewayOrderId): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT sp.*, cs.subscription_number FROM subscription_payments sp
             INNER JOIN customer_subscriptions cs ON cs.id = sp.customer_subscription_id
             WHERE sp.gateway_order_id = ? LIMIT 1'
        );
        $stmt->execute([$gatewayOrderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
