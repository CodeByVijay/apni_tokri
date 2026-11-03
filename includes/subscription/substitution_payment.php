<?php

declare(strict_types=1);

final class SubscriptionSubstitutionPaymentService
{
    public static function nextIdempotencyKey(int $substitutionId): string
    {
        return 'substpay_' . $substitutionId . '_1';
    }

    /**
     * @return array{ok: bool, payment_id?: int, checkout?: array<string, mixed>, error?: string}
     */
    public static function initiateForSubstitution(int $substitutionId, int $customerId): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT s.*, cs.customer_id, cs.id AS customer_subscription_id, cs.subscription_number
             FROM subscription_basket_substitutions s
             INNER JOIN subscription_delivery_days d ON d.id = s.subscription_delivery_day_id
             INNER JOIN customer_subscriptions cs ON cs.id = d.customer_subscription_id
             WHERE s.id = ? LIMIT 1"
        );
        $stmt->execute([$substitutionId]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sub || (int) $sub['customer_id'] !== $customerId) {
            return ['ok' => false, 'error' => 'Substitution not found.'];
        }
        if ((string) $sub['status'] !== 'pending_customer') {
            return ['ok' => false, 'error' => 'Substitution is not awaiting approval.'];
        }
        $amount = round((float) $sub['price_difference_inr'], 2);
        if ($amount <= 0.01) {
            return ['ok' => false, 'error' => 'No additional payment required.'];
        }

        $live = PaymentGatewayConfig::assertLivePaymentsReady();
        if (!$live['ok'] && !PaymentGatewayConfig::useSimulatedCheckout()) {
            return ['ok' => false, 'error' => $live['message'] ?? 'Payments unavailable.'];
        }

        $existing = $pdo->prepare('SELECT * FROM subscription_substitution_payments WHERE substitution_id = ? LIMIT 1');
        $existing->execute([$substitutionId]);
        $payRow = $existing->fetch(PDO::FETCH_ASSOC);
        if ($payRow !== false && (string) $payRow['status'] === 'captured') {
            return ['ok' => false, 'error' => 'Already paid.'];
        }

        $subId = (int) $sub['customer_subscription_id'];
        $idempotency = self::nextIdempotencyKey($substitutionId);
        $gateway = PaymentGatewayConfig::useSimulatedCheckout() ? 'simulate' : PaymentGatewayConfig::activeGateway();

        if ($payRow === false) {
            $pdo->prepare(
                'INSERT INTO subscription_substitution_payments
                 (substitution_id, customer_subscription_id, gateway, amount, currency, status, idempotency_key)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$substitutionId, $subId, $gateway, $amount, 'INR', 'created', $idempotency]);
            $paymentId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE subscription_basket_substitutions SET substitution_payment_id = ? WHERE id = ?')
                ->execute([$paymentId, $substitutionId]);
        } else {
            $paymentId = (int) $payRow['id'];
            $idempotency = (string) $payRow['idempotency_key'];
        }

        $mobile = (string) (CustomerService::profile()['mobile'] ?? '');
        if ($gateway === 'simulate') {
            $token = PaymentService::issueSimulateToken('SUBST-' . $substitutionId);

            return [
                'ok' => true,
                'payment_id' => $paymentId,
                'checkout' => [
                    'mode' => 'simulate',
                    'token' => $token,
                    'idempotency_key' => $idempotency,
                    'payment_context' => 'substitution',
                    'substitution_id' => $substitutionId,
                    'amount_inr' => $amount,
                ],
            ];
        }

        if ($gateway === 'razorpay') {
            $payRef = PaymentInitDiagnostics::referenceId();
            $created = RazorpayGateway::createOrder(
                PaymentService::amountToPaise($amount),
                'SUBST-' . $substitutionId,
                $idempotency,
                $payRef,
                null
            );
            if (!$created['ok']) {
                return ['ok' => false, 'error' => $created['error'] ?? 'Gateway error'];
            }
            $gwOrderId = (string) $created['gateway_order_id'];
            $pdo->prepare('UPDATE subscription_substitution_payments SET gateway_order_id = ?, status = ? WHERE id = ?')
                ->execute([$gwOrderId, 'pending', $paymentId]);

            return [
                'ok' => true,
                'payment_id' => $paymentId,
                'checkout' => [
                    'mode' => 'razorpay',
                    'key_id' => RazorpayGateway::keyId(),
                    'amount_paise' => PaymentService::amountToPaise($amount),
                    'gateway_order_id' => $gwOrderId,
                    'substitution_id' => $substitutionId,
                    'idempotency_key' => $idempotency,
                    'payment_context' => 'substitution',
                ],
            ];
        }

        return ['ok' => false, 'error' => 'Gateway not configured for substitution payments.'];
    }

    /**
     * @return array{ok: bool, idempotent?: bool, message?: string}
     */
    public static function completePayment(
        int $substitutionId,
        float $amountInr,
        string $gateway,
        string $gatewayPaymentId,
        string $gatewayOrderId,
        string $idempotencyKey,
        ?string $gatewayEventId = null
    ): array {
        $exec = PaymentGatewayConfig::assertPaymentExecutionAllowed($gateway);
        if (!$exec['ok']) {
            return ['ok' => false, 'message' => (string) ($exec['message'] ?? 'Payments are not available.')];
        }

        try {
            return Database::transaction(static function () use (
                $substitutionId,
                $amountInr,
                $gateway,
                $gatewayPaymentId,
                $gatewayOrderId,
                $idempotencyKey,
                $gatewayEventId
            ): array {
                $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT s.*, cs.customer_id, cs.id AS customer_subscription_id, cs.subscription_number, d.id AS day_id
             FROM subscription_basket_substitutions s
             INNER JOIN subscription_delivery_days d ON d.id = s.subscription_delivery_day_id
             INNER JOIN customer_subscriptions cs ON cs.id = d.customer_subscription_id
             WHERE s.id = ? FOR UPDATE"
        );
                $stmt->execute([$substitutionId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    throw new RuntimeException('Substitution not found.');
                }
                $payStmt = $pdo->prepare(
                    'SELECT * FROM subscription_substitution_payments WHERE substitution_id = ? LIMIT 1 FOR UPDATE'
                );
                $payStmt->execute([$substitutionId]);
                $payRow = $payStmt->fetch(PDO::FETCH_ASSOC);
                if ((string) $row['status'] === 'approved') {
                    return ['ok' => true, 'idempotent' => true];
                }
                if ((string) $row['status'] !== 'pending_customer') {
                    throw new RuntimeException('Substitution is not awaiting payment.');
                }
                $expected = round((float) $row['price_difference_inr'], 2);
                if (abs($expected - $amountInr) > 0.02) {
                    throw new RuntimeException('Amount mismatch.');
                }
                if ($payRow === false || $payRow === null) {
                    throw new RuntimeException('Payment record missing.');
                }
                if ((string) ($payRow['currency'] ?? '') !== PaymentGatewayConfig::currency()) {
                    throw new RuntimeException('Invalid currency.');
                }
                if ((string) ($payRow['status'] ?? '') === 'captured') {
                    return ['ok' => true, 'idempotent' => true];
                }
                if (!in_array((string) ($payRow['status'] ?? ''), ['created', 'pending'], true)) {
                    throw new RuntimeException('Payment is not pending.');
                }
                $storedGw = (string) ($payRow['gateway_order_id'] ?? '');
                if ($gatewayOrderId !== '' && $storedGw !== '' && !Security::hashEquals($storedGw, $gatewayOrderId)) {
                    throw new RuntimeException('Gateway order mismatch.');
                }
                $payId = (int) ($payRow['id'] ?? 0);
                if ($payId <= 0) {
                    throw new RuntimeException('Payment record missing.');
                }
                $pdo->prepare(
                    'UPDATE subscription_substitution_payments SET gateway_payment_id = ?, gateway_order_id = COALESCE(NULLIF(gateway_order_id, \'\'), ?),
                     gateway_event_id = COALESCE(?, gateway_event_id), status = ?, updated_at = NOW() WHERE id = ? AND idempotency_key = ?'
                )->execute([
                    $gatewayPaymentId,
                    $gatewayOrderId,
                    $gatewayEventId,
                    'captured',
                    $payId,
                    $idempotencyKey,
                ]);
                $pdo->prepare(
                    "UPDATE subscription_basket_substitutions SET status = 'approved', customer_decision_at = NOW() WHERE id = ?"
                )->execute([$substitutionId]);
                SubscriptionSubstitutionService::applyApprovedSubstitutionPublic($pdo, $row);
                SubscriptionAudit::log(
                    (int) $row['customer_subscription_id'],
                    null,
                    'customer',
                    null,
                    'substitution_paid_and_applied',
                    ['substitution_id' => $substitutionId, 'amount_inr' => $amountInr, 'gateway' => $gateway]
                );

                return ['ok' => true];
            });
        } catch (Throwable $e) {
            error_log('Substitution payment capture failed.');

            return ['ok' => false, 'message' => 'Could not confirm payment.'];
        }
    }

    /** @return array<string, mixed>|null */
    public static function paymentByIdempotency(string $key): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM subscription_substitution_payments WHERE idempotency_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Verify Razorpay checkout response and capture substitution payment (customer AJAX).
     *
     * @return array{ok: bool, idempotent?: bool, message?: string, subscription_number?: string}
     */
    public static function verifyRazorpayCapture(
        int $substitutionId,
        int $customerId,
        string $razorpayOrderId,
        string $razorpayPaymentId,
        string $idempotencyKey
    ): array {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            "SELECT s.id, s.status, s.price_difference_inr, cs.customer_id, cs.subscription_number
             FROM subscription_basket_substitutions s
             INNER JOIN subscription_delivery_days d ON d.id = s.subscription_delivery_day_id
             INNER JOIN customer_subscriptions cs ON cs.id = d.customer_subscription_id
             WHERE s.id = ? LIMIT 1"
        );
        $stmt->execute([$substitutionId]);
        $subRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$subRow || (int) $subRow['customer_id'] !== $customerId) {
            return ['ok' => false, 'message' => 'Substitution not found.'];
        }
        if ((string) $subRow['status'] === 'approved') {
            return [
                'ok' => true,
                'idempotent' => true,
                'subscription_number' => (string) $subRow['subscription_number'],
            ];
        }
        if ((string) $subRow['status'] !== 'pending_customer') {
            return ['ok' => false, 'message' => 'Substitution is not awaiting payment.'];
        }

        $pay = self::paymentByIdempotency($idempotencyKey);
        if ($pay === null || (int) ($pay['substitution_id'] ?? 0) !== $substitutionId) {
            return ['ok' => false, 'message' => 'Payment session invalid.'];
        }
        if ((string) ($pay['currency'] ?? '') !== PaymentGatewayConfig::currency()) {
            return ['ok' => false, 'message' => 'Invalid currency.'];
        }
        $storedOrder = (string) ($pay['gateway_order_id'] ?? '');
        if ($storedOrder === '' || !Security::hashEquals($storedOrder, $razorpayOrderId)) {
            return ['ok' => false, 'message' => 'Gateway order mismatch.'];
        }
        if (!in_array((string) ($pay['status'] ?? ''), ['created', 'pending'], true)
            && (string) ($pay['status'] ?? '') !== 'captured') {
            return ['ok' => false, 'message' => 'Payment is not in a payable state.'];
        }
        if ((string) ($pay['status'] ?? '') === 'captured') {
            return [
                'ok' => true,
                'idempotent' => true,
                'subscription_number' => (string) $subRow['subscription_number'],
            ];
        }

        $amount = round((float) $pay['amount'], 2);
        $expected = round((float) $subRow['price_difference_inr'], 2);
        if (abs($amount - $expected) > 0.02) {
            return ['ok' => false, 'message' => 'Amount mismatch.'];
        }

        $result = self::completePayment(
            $substitutionId,
            $amount,
            'razorpay',
            $razorpayPaymentId,
            $razorpayOrderId,
            $idempotencyKey
        );
        if (!$result['ok']) {
            return ['ok' => false, 'message' => $result['message'] ?? 'Could not confirm payment.'];
        }

        return [
            'ok' => true,
            'idempotent' => !empty($result['idempotent']),
            'subscription_number' => (string) $subRow['subscription_number'],
        ];
    }
}
