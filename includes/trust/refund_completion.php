<?php

declare(strict_types=1);

/**
 * Idempotent refund completion — only after gateway webhook (or simulate mode at initiation).
 */
final class RefundCompletionService
{
    /**
     * Complete an order refund request after verified gateway success.
     *
     * @return array{ok: bool, idempotent?: bool, message?: string}
     */
    public static function completeOrderRefund(
        int $paymentRefundId,
        string $gatewayStatus,
        ?float $amountInr = null
    ): array {
        $terminal = self::isTerminalSuccess($gatewayStatus);
        if (!$terminal) {
            return ['ok' => false, 'message' => 'Refund not in completed state.'];
        }
        try {
            return Database::transaction(static function () use ($paymentRefundId, $gatewayStatus, $amountInr): array {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare('SELECT * FROM payment_refunds WHERE id = ? FOR UPDATE');
                $stmt->execute([$paymentRefundId]);
                $pr = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$pr) {
                    return ['ok' => false, 'message' => 'Refund record not found.'];
                }
                if (self::isTerminalSuccess((string) ($pr['gateway_status'] ?? ''))) {
                    return ['ok' => true, 'idempotent' => true];
                }
                $amount = $amountInr !== null ? $amountInr : (float) ($pr['amount_inr'] ?? 0);
                $paymentId = (int) ($pr['payment_id'] ?? 0);
                if ($paymentId <= 0) {
                    return ['ok' => false, 'message' => 'Invalid payment link.'];
                }
                $payStmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
                $payStmt->execute([$paymentId]);
                $payment = $payStmt->fetch(PDO::FETCH_ASSOC);
                if (!$payment) {
                    return ['ok' => false, 'message' => 'Payment missing.'];
                }
                $captured = (float) ($payment['amount'] ?? 0);
                $already = (float) ($payment['amount_refunded_inr'] ?? 0);
                if ($amount + $already > $captured + 0.01) {
                    return ['ok' => false, 'message' => 'Refund would exceed captured amount.'];
                }

                self::markRefundGatewayTerminal($pdo, $paymentRefundId, $gatewayStatus);

                $requestId = isset($pr['refund_request_id']) ? (int) $pr['refund_request_id'] : 0;
                if ($requestId > 0) {
                    self::applyOrderPaymentRefund($pdo, $requestId, $paymentId, $amount);
                }

                return ['ok' => true];
            });
        } catch (Throwable $e) {
            error_log('Refund completion: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not complete refund.'];
        }
    }

    /**
     * Complete subscription day credit refund linked to payment_refunds row.
     *
     * @return array{ok: bool, idempotent?: bool, message?: string}
     */
    public static function completeSubscriptionRefund(
        int $paymentRefundId,
        string $gatewayStatus,
        ?float $amountInr = null
    ): array {
        if (!self::isTerminalSuccess($gatewayStatus)) {
            return ['ok' => false, 'message' => 'Refund not completed.'];
        }
        try {
            return Database::transaction(static function () use ($paymentRefundId, $gatewayStatus, $amountInr): array {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare('SELECT * FROM payment_refunds WHERE id = ? FOR UPDATE');
                $stmt->execute([$paymentRefundId]);
                $pr = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$pr) {
                    return ['ok' => false, 'message' => 'Not found.'];
                }
                if (self::isTerminalSuccess((string) ($pr['gateway_status'] ?? ''))) {
                    return ['ok' => true, 'idempotent' => true];
                }
                $creditId = (int) ($pr['subscription_day_credit_id'] ?? 0);
                $subPayId = (int) ($pr['subscription_payment_id'] ?? 0);
                if ($creditId <= 0 || $subPayId <= 0) {
                    return ['ok' => false, 'message' => 'Invalid subscription refund link.'];
                }
                $amount = $amountInr !== null ? $amountInr : (float) ($pr['amount_inr'] ?? 0);

                $sp = $pdo->prepare('SELECT * FROM subscription_payments WHERE id = ? FOR UPDATE');
                $sp->execute([$subPayId]);
                $subPayment = $sp->fetch(PDO::FETCH_ASSOC);
                if (!$subPayment || (string) ($subPayment['status'] ?? '') !== 'captured') {
                    return ['ok' => false, 'message' => 'Subscription payment not refundable.'];
                }
                $captured = (float) ($subPayment['amount'] ?? 0);
                $already = (float) ($subPayment['amount_refunded_inr'] ?? 0);
                if ($amount + $already > $captured + 0.01) {
                    return ['ok' => false, 'message' => 'Subscription refund exceeds captured amount.'];
                }

                $cred = $pdo->prepare('SELECT * FROM subscription_day_credits WHERE id = ? FOR UPDATE');
                $cred->execute([$creditId]);
                $credit = $cred->fetch(PDO::FETCH_ASSOC);
                if (!$credit) {
                    return ['ok' => false, 'message' => 'Credit row missing.'];
                }
                if ((string) ($credit['credit_type'] ?? '') === 'refund_completed') {
                    self::markRefundGatewayTerminal($pdo, $paymentRefundId, $gatewayStatus);

                    return ['ok' => true, 'idempotent' => true];
                }
                if ((string) ($credit['credit_type'] ?? '') !== 'refund_pending') {
                    return ['ok' => false, 'message' => 'Credit not pending refund.'];
                }

                $pdo->prepare(
                    'UPDATE subscription_payments SET amount_refunded_inr = amount_refunded_inr + ?, updated_at = NOW() WHERE id = ?'
                )->execute([$amount, $subPayId]);
                $pdo->prepare(
                    "UPDATE subscription_day_credits SET credit_type = 'refund_completed', gateway_refund_status = 'completed',
                     gateway_refund_id = COALESCE(gateway_refund_id, ?), gateway_refund_completed_at = NOW(), updated_at = NOW()
                     WHERE id = ? AND credit_type = 'refund_pending'"
                )->execute([(string) ($pr['gateway_refund_id'] ?? ''), $creditId]);
                self::markRefundGatewayTerminal($pdo, $paymentRefundId, $gatewayStatus);

                SubscriptionRecoveryLog::record('refund_reconcile', (string) ($credit['idempotency_key'] ?? ''), 'completed', [
                    'payment_refund_id' => $paymentRefundId,
                ]);

                return ['ok' => true];
            });
        } catch (Throwable $e) {
            error_log('Subscription refund completion: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not complete subscription refund.'];
        }
    }

    public static function markOrderRefundFailed(int $paymentRefundId, string $reason): void
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $pdo->prepare(
            'UPDATE payment_refunds SET gateway_status = ?, updated_at = NOW() WHERE id = ?'
        )->execute(['failed', $paymentRefundId]);
        $stmt = $pdo->prepare('SELECT refund_request_id FROM payment_refunds WHERE id = ?');
        $stmt->execute([$paymentRefundId]);
        $reqId = (int) ($stmt->fetchColumn() ?: 0);
        if ($reqId > 0) {
            $pdo->prepare(
                'UPDATE refund_requests SET status = ?, updated_at = NOW() WHERE id = ? AND status IN (?, ?, ?)'
            )->execute([
                RefundStatus::REFUND_FAILED,
                $reqId,
                RefundStatus::REFUND_INITIATED,
                RefundStatus::PROCESSING,
                RefundStatus::APPROVED,
            ]);
            RefundRequestService::appendHistoryPublic($pdo, $reqId, 'system', null, 'gateway_failed', ['reason' => $reason]);
        }
    }

    public static function applyOrderPaymentRefund(PDO $pdo, int $requestId, int $paymentId, float $amount): void
    {
        $pay = $pdo->prepare('SELECT order_id, amount, amount_refunded_inr FROM payments WHERE id = ? FOR UPDATE');
        $pay->execute([$paymentId]);
        $prow = $pay->fetch(PDO::FETCH_ASSOC);
        if (!$prow) {
            throw new RuntimeException('Payment missing');
        }
        $captured = (float) $prow['amount'];
        $refunded = (float) $prow['amount_refunded_inr'] + $amount;
        if ($refunded > $captured + 0.01) {
            throw new RuntimeException('Refund exceeds captured amount');
        }
        $pdo->prepare(
            'UPDATE payments SET amount_refunded_inr = amount_refunded_inr + ?, updated_at = NOW() WHERE id = ?'
        )->execute([$amount, $paymentId]);
        $orderId = (int) $prow['order_id'];
        $orderStatus = $refunded >= $captured - 0.01 ? OrderStatus::REFUNDED : OrderStatus::PARTIALLY_REFUNDED;
        $payStatus = $refunded >= $captured - 0.01 ? PaymentStatus::REFUNDED : PaymentStatus::PARTIALLY_REFUNDED;
        $pdo->prepare('UPDATE orders SET status = ?, payment_status = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$orderStatus, $payStatus, $orderId]);
        $pdo->prepare('UPDATE refund_requests SET status = ?, updated_at = NOW() WHERE id = ?')
            ->execute([RefundStatus::REFUNDED, $requestId]);
        RefundRequestService::appendHistoryPublic($pdo, $requestId, 'system', null, 'refunded', ['amount' => $amount]);
    }

    public static function isTerminalSuccess(string $status): bool
    {
        $s = strtolower($status);

        return in_array($s, self::terminalSuccessGatewayStatuses(), true);
    }

    /** @return list<string> */
    public static function terminalSuccessGatewayStatuses(): array
    {
        return ['processed', 'completed', 'success', 'refunded'];
    }

    private static function markRefundGatewayTerminal(PDO $pdo, int $paymentRefundId, string $gatewayStatus): void
    {
        $pdo->prepare(
            'UPDATE payment_refunds SET gateway_status = ?, updated_at = NOW(), completed_at = COALESCE(completed_at, NOW()) WHERE id = ?'
        )->execute([self::normalizeStatus($gatewayStatus), $paymentRefundId]);
    }

    private static function normalizeStatus(string $status): string
    {
        return self::isTerminalSuccess($status) ? 'processed' : strtolower($status);
    }
}
