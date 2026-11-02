<?php

declare(strict_types=1);

/**
 * Cashfree PG refund webhook helpers (Stage 12 Phase C.3).
 *
 * Event identity: deterministic key from webhook type, cf_refund_id (or merchant refund_id),
 * normalized refund_status, and event_time — capped for payment_webhook_events.event_id (191).
 * Exact duplicate deliveries share the same key; status transitions get distinct keys.
 *
 * Webhook signing secret: Cashfree PG docs use the client secret (x-client-secret). An optional
 * cashfree.webhook_secret setting overrides when the dashboard uses a separate secret.
 */
final class CashfreeRefundWebhook
{
    /** @return array{refund: array<string, mixed>, type: string, event_time: string}|null */
    public static function parseRefundPayload(array $payload): ?array
    {
        $type = (string) ($payload['type'] ?? '');
        if (stripos($type, 'REFUND') === false) {
            return null;
        }
        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }
        $refund = $data['refund'] ?? null;
        if (!is_array($refund)) {
            return null;
        }

        return [
            'refund' => $refund,
            'type' => $type,
            'event_time' => (string) ($payload['event_time'] ?? ''),
        ];
    }

    public static function buildEventId(array $payload): string
    {
        $parsed = self::parseRefundPayload($payload);
        if ($parsed === null) {
            $type = (string) ($payload['type'] ?? 'unknown');
            $orderId = '';
            if (is_array($payload['data']['order'] ?? null)) {
                $orderId = (string) ($payload['data']['order']['order_id'] ?? '');
            }

            return self::truncateEventId('cashfree:pay:' . $type . ':' . $orderId);
        }
        $refund = $parsed['refund'];
        $cfRefundId = (string) ($refund['cf_refund_id'] ?? '');
        $merchantRefundId = (string) ($refund['refund_id'] ?? '');
        $status = self::normalizeStatus((string) ($refund['refund_status'] ?? $refund['status'] ?? ''));
        $eventTime = $parsed['event_time'];
        $identity = $cfRefundId !== '' ? $cfRefundId : $merchantRefundId;
        if ($identity === '') {
            $identity = hash('sha256', json_encode($refund, JSON_UNESCAPED_UNICODE));
        }

        return self::truncateEventId(
            'cashfree:refund:' . $parsed['type'] . ':' . $identity . ':' . $status . ':' . $eventTime
        );
    }

    public static function normalizeStatus(string $status): string
    {
        return strtolower(trim($status));
    }

    /**
     * @return 'success'|'failure'|'pending'|'unknown'
     */
    public static function classifyStatus(string $status, string $webhookType): string
    {
        $s = self::normalizeStatus($status);
        if ($s === '') {
            if (stripos($webhookType, 'FAIL') !== false || stripos($webhookType, 'CANCEL') !== false) {
                return 'failure';
            }

            return 'unknown';
        }
        if ($s === 'success') {
            return 'success';
        }
        if (in_array($s, ['cancelled', 'canceled', 'rejected', 'failed', 'failure'], true)) {
            return 'failure';
        }
        if (in_array($s, ['pending', 'pending_approval', 'onhold'], true)) {
            return 'pending';
        }

        return 'unknown';
    }

    /**
     * @param array<string, mixed> $paymentRefundRow from payment_refunds (+ optional joined fields)
     * @param array<string, mixed> $refund Cashfree refund object
     * @return array{ok: true}|array{ok: false, message: string, retryable?: bool}
     */
    public static function validateAgainstLocalRow(array $paymentRefundRow, array $refund): array
    {
        $merchantRefundId = (string) ($refund['refund_id'] ?? '');
        $expectedKey = (string) ($paymentRefundRow['idempotency_key'] ?? '');
        if ($merchantRefundId !== '' && $expectedKey !== '') {
            $sanitized = substr(preg_replace('/[^a-zA-Z0-9_]/', '_', $expectedKey), 0, 40);
            if ($merchantRefundId !== $expectedKey && $merchantRefundId !== $sanitized) {
                return ['ok' => false, 'message' => 'Merchant refund_id mismatch', 'retryable' => false];
            }
        }

        $cfRefundId = (string) ($refund['cf_refund_id'] ?? '');
        $storedGwRefund = (string) ($paymentRefundRow['gateway_refund_id'] ?? '');
        if ($cfRefundId !== '' && $storedGwRefund !== '' && $cfRefundId !== $storedGwRefund) {
            return ['ok' => false, 'message' => 'Cashfree refund id mismatch', 'retryable' => false];
        }

        $currency = strtoupper(trim((string) ($refund['refund_currency'] ?? 'INR')));
        if ($currency !== '' && $currency !== 'INR') {
            return ['ok' => false, 'message' => 'Unsupported refund currency', 'retryable' => false];
        }

        $expectedAmount = (float) ($paymentRefundRow['amount_inr'] ?? 0);
        if (isset($refund['refund_amount']) && $refund['refund_amount'] !== '' && $expectedAmount > 0) {
            $webhookAmount = round((float) $refund['refund_amount'], 2);
            if (abs($webhookAmount - round($expectedAmount, 2)) > 0.02) {
                return ['ok' => false, 'message' => 'Refund amount mismatch', 'retryable' => false];
            }
        }

        Install::ensureConnection();
        $pdo = Database::pdo();
        $paymentId = (int) ($paymentRefundRow['payment_id'] ?? 0);
        $subPayId = (int) ($paymentRefundRow['subscription_payment_id'] ?? 0);
        $merchantOrderId = (string) ($refund['order_id'] ?? '');
        if ($merchantOrderId !== '') {
            if ($paymentId > 0) {
                $stmt = $pdo->prepare(
                    'SELECT o.order_number, p.gateway_payment_id FROM payments p
                     INNER JOIN orders o ON o.id = p.order_id WHERE p.id = ? LIMIT 1'
                );
                $stmt->execute([$paymentId]);
                $pay = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$pay || (string) ($pay['order_number'] ?? '') !== $merchantOrderId) {
                    return ['ok' => false, 'message' => 'Merchant order_id mismatch', 'retryable' => false];
                }
                $cfPaymentId = (string) ($refund['cf_payment_id'] ?? '');
                $storedPay = (string) ($pay['gateway_payment_id'] ?? '');
                if ($cfPaymentId !== '' && $storedPay !== '' && $cfPaymentId !== $storedPay) {
                    return ['ok' => false, 'message' => 'Cashfree payment id mismatch', 'retryable' => false];
                }
            } elseif ($subPayId > 0) {
                $stmt = $pdo->prepare(
                    'SELECT subscription_number, gateway_order_id, gateway_payment_id FROM subscription_payments WHERE id = ? LIMIT 1'
                );
                $stmt->execute([$subPayId]);
                $sp = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$sp) {
                    return ['ok' => false, 'message' => 'Subscription payment missing', 'retryable' => false];
                }
                $matchesOrder = $merchantOrderId === (string) ($sp['subscription_number'] ?? '')
                    || $merchantOrderId === (string) ($sp['gateway_order_id'] ?? '');
                if (!$matchesOrder) {
                    return ['ok' => false, 'message' => 'Subscription order_id mismatch', 'retryable' => false];
                }
            }
        }

        return ['ok' => true];
    }

    private static function truncateEventId(string $eventId): string
    {
        if (strlen($eventId) <= 191) {
            return $eventId;
        }

        return 'cashfree:ev:' . hash('sha256', $eventId);
    }
}
