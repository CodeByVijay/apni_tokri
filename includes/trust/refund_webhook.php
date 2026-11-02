<?php

declare(strict_types=1);

final class RefundWebhookService
{
    /**
     * Razorpay refund.* events.
     *
     * @param array<string, mixed> $payload
     * @return array{ok: bool, message?: string}
     */
    public static function handleRazorpay(array $payload, string $eventId): array
    {
        $event = (string) ($payload['event'] ?? '');
        if (strpos($event, 'refund.') !== 0) {
            return ['ok' => false, 'message' => 'Not a refund event'];
        }
        $entity = $payload['payload']['refund']['entity'] ?? null;
        if (!is_array($entity)) {
            return ['ok' => false, 'message' => 'Missing refund entity'];
        }
        $gatewayRefundId = (string) ($entity['id'] ?? '');
        $status = (string) ($entity['status'] ?? '');
        $amountPaise = (int) ($entity['amount'] ?? 0);
        $notes = $entity['notes'] ?? [];
        $idempotency = is_array($notes) ? (string) ($notes['idempotency_key'] ?? '') : '';

        $row = self::findPaymentRefund('razorpay', $gatewayRefundId, $idempotency);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Unknown refund'];
        }

        if ($event === 'refund.failed' || $status === 'failed') {
            RefundCompletionService::markOrderRefundFailed((int) $row['id'], 'Razorpay refund failed');
            self::markSubscriptionFailed((int) $row['id'], 'Razorpay refund failed');

            return ['ok' => true];
        }

        if (!RefundCompletionService::isTerminalSuccess($status)) {
            Install::ensureConnection();
            Database::pdo()->prepare(
                'UPDATE payment_refunds SET gateway_status = ?, updated_at = NOW() WHERE id = ?'
            )->execute([$status, (int) $row['id']]);

            return ['ok' => true, 'message' => 'Refund pending'];
        }

        return self::completeRow($row, $status, $amountPaise / 100);
    }

    /**
     * Cashfree refund webhooks.
     *
     * @param array<string, mixed> $payload
     * @return array{ok: bool, message?: string, retryable?: bool}
     */
    public static function handleCashfree(array $payload, string $eventId): array
    {
        $parsed = CashfreeRefundWebhook::parseRefundPayload($payload);
        if ($parsed === null) {
            return ['ok' => false, 'message' => 'Not refund webhook', 'retryable' => false];
        }
        $type = $parsed['type'];
        $refund = $parsed['refund'];
        $gatewayRefundId = (string) ($refund['cf_refund_id'] ?? '');
        $merchantRefundId = (string) ($refund['refund_id'] ?? '');
        $statusRaw = (string) ($refund['refund_status'] ?? $refund['status'] ?? '');

        $row = self::findPaymentRefund('cashfree', $gatewayRefundId, $merchantRefundId);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Unknown refund', 'retryable' => true];
        }

        $validation = CashfreeRefundWebhook::validateAgainstLocalRow($row, $refund);
        if (!$validation['ok']) {
            error_log('Cashfree refund webhook validation: ' . ($validation['message'] ?? ''));

            return [
                'ok' => false,
                'message' => $validation['message'] ?? 'Validation failed',
                'retryable' => !empty($validation['retryable']),
            ];
        }

        $class = CashfreeRefundWebhook::classifyStatus($statusRaw, $type);
        if ($class === 'failure') {
            RefundCompletionService::markOrderRefundFailed((int) $row['id'], 'Cashfree refund ' . CashfreeRefundWebhook::normalizeStatus($statusRaw));
            self::markSubscriptionFailed((int) $row['id'], 'Cashfree refund failed');

            return ['ok' => true];
        }
        if ($class === 'pending') {
            Install::ensureConnection();
            Database::pdo()->prepare(
                'UPDATE payment_refunds SET gateway_status = ?, updated_at = NOW() WHERE id = ?'
            )->execute([CashfreeRefundWebhook::normalizeStatus($statusRaw), (int) $row['id']]);

            return ['ok' => true];
        }
        if ($class === 'unknown') {
            error_log('Cashfree refund unknown status: ' . $statusRaw . ' type=' . $type);
            Install::ensureConnection();
            Database::pdo()->prepare(
                'UPDATE payment_refunds SET gateway_status = ?, updated_at = NOW() WHERE id = ?'
            )->execute(['unknown', (int) $row['id']]);

            return ['ok' => true];
        }

        $amount = isset($refund['refund_amount']) ? (float) $refund['refund_amount'] : null;
        $done = self::completeRow($row, $statusRaw, $amount !== null && $amount > 0 ? $amount : null);
        if (!$done['ok']) {
            return [
                'ok' => false,
                'message' => $done['message'] ?? 'Completion failed',
                'retryable' => true,
            ];
        }

        return $done;
    }

    /** @param array<string, mixed> $row */
    private static function completeRow(array $row, string $gatewayStatus, ?float $amount): array
    {
        $id = (int) $row['id'];
        if (!empty($row['subscription_day_credit_id'])) {
            return RefundCompletionService::completeSubscriptionRefund($id, $gatewayStatus, $amount);
        }

        return RefundCompletionService::completeOrderRefund($id, $gatewayStatus, $amount);
    }

    /** @return array<string, mixed>|null */
    private static function findPaymentRefund(string $gateway, string $gatewayRefundId, string $idempotencyKey): ?array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        if ($gatewayRefundId !== '') {
            $stmt = $pdo->prepare(
                'SELECT * FROM payment_refunds WHERE gateway = ? AND gateway_refund_id = ? LIMIT 1'
            );
            $stmt->execute([$gateway, $gatewayRefundId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }
        if ($idempotencyKey !== '') {
            $stmt = $pdo->prepare('SELECT * FROM payment_refunds WHERE idempotency_key = ? LIMIT 1');
            $stmt->execute([$idempotencyKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if ($gatewayRefundId !== '' && empty($row['gateway_refund_id'])) {
                    $pdo->prepare(
                        'UPDATE payment_refunds SET gateway_refund_id = ?, updated_at = NOW() WHERE id = ?'
                    )->execute([$gatewayRefundId, (int) $row['id']]);
                    $row['gateway_refund_id'] = $gatewayRefundId;
                }

                return $row;
            }
        }

        return null;
    }

    private static function markSubscriptionFailed(int $paymentRefundId, string $reason): void
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT subscription_day_credit_id FROM payment_refunds WHERE id = ?');
        $stmt->execute([$paymentRefundId]);
        $creditId = (int) ($stmt->fetchColumn() ?: 0);
        if ($creditId <= 0) {
            return;
        }
        $pdo->prepare(
            'UPDATE subscription_day_credits SET gateway_refund_status = ?, updated_at = NOW() WHERE id = ?'
        )->execute(['failed', $creditId]);
        $pdo->prepare(
            'UPDATE payment_refunds SET gateway_status = ?, updated_at = NOW() WHERE id = ?'
        )->execute(['failed', $paymentRefundId]);
    }
}
