<?php

declare(strict_types=1);

final class SubscriptionRefundService
{
    /**
     * Apply configured credit for a cancelled delivery day (never duplicate).
     *
     * @return array{ok: bool, idempotent?: bool, message?: string, credit_inr?: float}
     */
    public static function applyDayCancellationCredit(int $dayId, int $customerId, string $reason): array
    {
        try {
            return Database::transaction(static function () use ($dayId, $customerId, $reason): array {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare(
                    'SELECT d.*, cs.customer_id, cs.id AS sub_id, cs.price_paid, cs.plan_snapshot_json, cs.deliveries_remaining
                     FROM subscription_delivery_days d
                     INNER JOIN customer_subscriptions cs ON cs.id = d.customer_subscription_id
                     WHERE d.id = ? FOR UPDATE'
                );
                $stmt->execute([$dayId]);
                $day = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$day || (int) $day['customer_id'] !== $customerId) {
                    return ['ok' => false, 'message' => 'Delivery day not found.'];
                }
                if ((string) $day['status'] === 'cancelled') {
                    return ['ok' => true, 'idempotent' => true];
                }
                if (!empty($day['generated_order_id'])) {
                    return ['ok' => false, 'message' => 'Order already generated; contact support for refund.'];
                }

                $snapshot = CustomerSubscriptionRepository::decodeSnapshot($day);
                $policy = is_array($snapshot['refund_policy_json'] ?? null) ? $snapshot['refund_policy_json'] : [];
                $creditMode = (string) ($policy['cancelled_day_credit'] ?? 'restore_entitlement');
                if ($creditMode === 'gateway_refund' && SubscriptionRefundService::gatewayRefundAllowed()) {
                    $creditMode = 'gateway_refund';
                } elseif ($creditMode === 'gateway_refund') {
                    $creditMode = 'restore_entitlement';
                }
                $deliveryCount = max(1, (int) ($snapshot['delivery_count'] ?? 1));
                $perDelivery = round((float) $day['price_paid'] / $deliveryCount, 2);

                $idempotency = 'day_cancel_credit_' . $dayId;
                $creditIns = $pdo->prepare(
                    'INSERT IGNORE INTO subscription_day_credits
                     (subscription_delivery_day_id, customer_subscription_id, credit_type, amount_inr, status, idempotency_key, detail_json)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $creditType = $creditMode === 'gateway_refund' ? 'refund_pending' : 'delivery_entitlement';
                $creditIns->execute([
                    $dayId,
                    (int) $day['sub_id'],
                    $creditType,
                    $perDelivery,
                    'applied',
                    $idempotency,
                    json_encode(['reason' => $reason, 'mode' => $creditMode], JSON_UNESCAPED_UNICODE),
                ]);
                if ($creditIns->rowCount() === 0) {
                    return ['ok' => true, 'idempotent' => true];
                }

                if ($creditMode === 'gateway_refund') {
                    self::queueGatewayRefund($pdo, (int) $day['sub_id'], $dayId, $perDelivery, $idempotency);
                } else {
                    $pdo->prepare(
                        'UPDATE customer_subscriptions SET deliveries_remaining = deliveries_remaining + 1, updated_at = NOW() WHERE id = ?'
                    )->execute([(int) $day['sub_id']]);
                }

                $pdo->prepare(
                    "UPDATE subscription_delivery_days SET status = 'cancelled', fulfilment_status = 'cancelled', updated_at = NOW() WHERE id = ?"
                )->execute([$dayId]);
                $pdo->prepare(
                    "UPDATE subscription_basket_substitutions SET status = 'rejected', rejection_resolution = 'cancel_day'
                     WHERE subscription_delivery_day_id = ? AND status = 'pending_customer'"
                )->execute([$dayId]);
                SubscriptionInventoryReserveService::releaseDay($dayId, $pdo);
                $pdo->prepare(
                    "UPDATE subscription_fulfilment_events SET status = 'failed', error_message = 'Day cancelled by customer', updated_at = NOW()
                     WHERE subscription_delivery_day_id = ? AND status <> 'completed'"
                )->execute([$dayId]);

                SubscriptionAudit::log(
                    (int) $day['sub_id'],
                    (string) $day['delivery_date'],
                    'customer',
                    $customerId,
                    'delivery_day_cancelled',
                    ['credit_inr' => $perDelivery, 'mode' => $creditMode]
                );
                CustomerNotificationService::notify(
                    $customerId,
                    'Delivery cancelled',
                    SubscriptionRefundService::customerCreditMessage($creditMode, $perDelivery, $dayId),
                    ['day_id' => $dayId]
                );

                return ['ok' => true, 'credit_inr' => $perDelivery];
            });
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not apply cancellation.'];
        }
    }

    public static function gatewayRefundAllowed(): bool
    {
        return GatewayRefundService::liveGatewayRefundsEnabled();
    }

    /** @return list<array<string, mixed>> */
    public static function creditsForCustomer(int $customerId, int $limit = 20): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT c.*, d.delivery_date, cs.subscription_number
             FROM subscription_day_credits c
             INNER JOIN subscription_delivery_days d ON d.id = c.subscription_delivery_day_id
             INNER JOIN customer_subscriptions cs ON cs.id = c.customer_subscription_id
             WHERE cs.customer_id = ?
             ORDER BY c.created_at DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute([$customerId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function customerCreditMessage(string $creditMode, float $amount, int $dayId): string
    {
        if ($creditMode === 'gateway_refund') {
            return 'Your delivery was cancelled. A refund of ₹' . number_format($amount, 2)
                . ' is pending gateway confirmation — we will notify you when it is completed.';
        }

        return 'Your delivery was cancelled. One delivery credit was restored to your subscription (₹'
            . number_format($amount, 2) . ' plan value).';
    }

    public static function publicRefundStatusLabel(array $creditRow): string
    {
        $type = (string) ($creditRow['credit_type'] ?? '');
        $gw = (string) ($creditRow['gateway_refund_status'] ?? '');
        if ($type === 'refund_pending' || ($type === 'refund_completed' && $gw !== 'completed')) {
            return 'Refund pending gateway confirmation';
        }
        if ($type === 'refund_completed' && $gw === 'completed') {
            return 'Refund completed';
        }
        if ($type === 'delivery_entitlement') {
            return 'Delivery credit applied';
        }

        return 'Credit recorded';
    }

    /**
     * Reconcile pending gateway refunds (simulate/test only — never marks live gateway refunds complete).
     *
     * @return array{processed: int, skipped: int, errors: list<string>}
     */
    public static function reconcilePendingRefunds(): array
    {
        if (!self::gatewayRefundAllowed()) {
            return ['processed' => 0, 'skipped' => 0, 'errors' => []];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->query(
            "SELECT * FROM subscription_day_credits WHERE credit_type = 'refund_pending' AND status = 'applied'
             AND (gateway_refund_status IS NULL OR gateway_refund_status = 'pending') LIMIT 50"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $processed = 0;
        $errors = [];
        foreach ($rows as $row) {
            $amount = (float) ($row['amount_inr'] ?? 0);
            $idempotency = (string) ($row['idempotency_key'] ?? '');
            $subPaymentId = (int) ($row['parent_subscription_payment_id'] ?? 0);
            $creditId = (int) ($row['id'] ?? 0);
            if ($subPaymentId <= 0 || $amount <= 0 || $creditId <= 0) {
                continue;
            }
            try {
                $result = self::processSubscriptionCreditRefund($pdo, $creditId, $subPaymentId, $amount, $idempotency);
                if (!empty($result['ok']) && !empty($result['processed'])) {
                    $processed++;
                } elseif (!empty($result['error'])) {
                    $errors[] = $result['error'];
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
                SubscriptionRecoveryLog::record('refund_reconcile', $idempotency, 'failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['processed' => $processed, 'skipped' => 0, 'errors' => $errors];
    }

    /**
     * @return array{ok: bool, processed?: bool, error?: string}
     */
    public static function processSubscriptionCreditRefund(
        PDO $pdo,
        int $creditId,
        int $subscriptionPaymentId,
        float $amount,
        string $idempotency
    ): array {
        $gwKey = 'sub_' . $idempotency;
        $existing = $pdo->prepare(
            "SELECT id, gateway_status FROM payment_refunds WHERE idempotency_key = ? OR subscription_day_credit_id = ? LIMIT 1"
        );
        $existing->execute([$gwKey, $creditId]);
        $pr = $existing->fetch(PDO::FETCH_ASSOC);
        if ($pr && in_array((string) ($pr['gateway_status'] ?? ''), ['processed', 'completed', 'success'], true)) {
            return ['ok' => true, 'processed' => false];
        }
        if (!$pr) {
            $subPay = $pdo->prepare('SELECT gateway FROM subscription_payments WHERE id = ?');
            $subPay->execute([$subscriptionPaymentId]);
            $gateway = (string) ($subPay->fetchColumn() ?: 'simulate');
            $pdo->prepare(
                'INSERT INTO payment_refunds
                 (refund_request_id, payment_id, subscription_payment_id, subscription_day_credit_id, gateway, amount_inr, idempotency_key, gateway_status)
                 VALUES (NULL, NULL, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $subscriptionPaymentId,
                $creditId,
                $gateway,
                $amount,
                $gwKey,
                'pending',
            ]);
            $prId = (int) $pdo->lastInsertId();
        } else {
            $prId = (int) $pr['id'];
        }

        $gw = GatewayRefundService::refundSubscriptionPayment($subscriptionPaymentId, $amount, $gwKey);
        if (!$gw['ok']) {
            $pdo->prepare(
                'UPDATE subscription_day_credits SET gateway_refund_status = ? WHERE id = ?'
            )->execute(['failed', $creditId]);
            $pdo->prepare('UPDATE payment_refunds SET gateway_status = ? WHERE id = ?')->execute(['failed', $prId]);

            return ['ok' => false, 'error' => $gw['message'] ?? 'Refund failed'];
        }
        $pdo->prepare(
            'UPDATE payment_refunds SET gateway_refund_id = ?, gateway_status = ?, response_json = ? WHERE id = ?'
        )->execute([
            $gw['gateway_refund_id'] ?? null,
            (string) ($gw['status'] ?? 'pending'),
            json_encode($gw, JSON_UNESCAPED_UNICODE),
            $prId,
        ]);
        $pdo->prepare(
            'UPDATE subscription_day_credits SET gateway_refund_status = ?, gateway_refund_requested_at = NOW() WHERE id = ?'
        )->execute(['pending', $creditId]);

        if (!empty($gw['simulated'])) {
            $done = RefundCompletionService::completeSubscriptionRefund($prId, 'processed', $amount);

            return ['ok' => $done['ok'], 'processed' => !empty($done['ok'])];
        }

        return ['ok' => true, 'processed' => false];
    }

    private static function queueGatewayRefund(PDO $pdo, int $subId, int $dayId, float $amount, string $idempotency): void
    {
        $pay = $pdo->prepare(
            "SELECT id FROM subscription_payments WHERE customer_subscription_id = ? AND status = 'captured' ORDER BY id DESC LIMIT 1"
        );
        $pay->execute([$subId]);
        $paymentId = $pay->fetchColumn();
        if ($paymentId !== false) {
            $pdo->prepare(
                'UPDATE subscription_day_credits SET parent_subscription_payment_id = ?, gateway_refund_status = ?,
                 gateway_refund_requested_at = NOW() WHERE idempotency_key = ?'
            )->execute([(int) $paymentId, 'pending', $idempotency]);
        }
    }
}
