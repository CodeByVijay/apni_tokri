<?php

declare(strict_types=1);

/** Admin payment detail view — read-only aggregates; no gateway API calls. */
final class PaymentAdminDetailService
{
    /** @return array<string, mixed>|null */
    public static function findPayment(int $paymentId): ?array
    {
        if ($paymentId <= 0 || !Install::isSchemaReady()) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT p.*, o.order_number, o.id AS order_id
             FROM payments p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE p.id = ?
             LIMIT 1'
        );
        $stmt->execute([$paymentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array{
     *   payment: array<string, mixed>,
     *   order: array<string, mixed>|null,
     *   gateway: array<string, mixed>,
     *   timeline: list<array<string, string>>,
     *   refunds: list<array<string, mixed>>,
     *   limitations: list<string>
     * }|null
     */
    public static function viewModel(int $paymentId): ?array
    {
        $payment = self::findPayment($paymentId);
        if ($payment === null) {
            return null;
        }
        $orderId = (int) ($payment['order_id'] ?? 0);
        $order = $orderId > 0 ? AdminOrderService::findById($orderId) : null;

        return [
            'payment' => $payment,
            'order' => $order,
            'gateway' => self::gatewayFields($payment),
            'timeline' => self::statusTimeline($payment),
            'refunds' => self::refundsForPayment($paymentId),
            'limitations' => self::limitations($payment),
        ];
    }

    /**
     * Safe gateway-facing fields from the payment row and non-secret metadata keys only.
     *
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    public static function gatewayFields(array $payment): array
    {
        $meta = self::parseMetadata($payment);
        $out = [
            'gateway_order_id' => self::dash((string) ($payment['gateway_order_id'] ?? '')),
            'gateway_payment_id' => self::dash((string) ($payment['gateway_payment_id'] ?? '')),
            'gateway_event_id' => self::dash((string) ($payment['gateway_event_id'] ?? '')),
            'idempotency_key' => self::dash((string) ($payment['idempotency_key'] ?? '')),
            'gateway_status' => self::dash((string) ($meta['gateway_status'] ?? $meta['cf_order_status'] ?? '')),
            'response_code' => self::dash((string) ($meta['response_code'] ?? $meta['error_code'] ?? '')),
            'response_message' => self::dash((string) ($meta['response_message'] ?? $meta['error_message'] ?? $meta['detail'] ?? '')),
            'payment_method' => self::dash((string) ($meta['payment_method'] ?? $meta['payment_group'] ?? '')),
            'payment_environment' => self::dash((string) ($meta['payment_environment'] ?? '')),
            'status_fetched_at' => '—',
            'last_sync_at' => self::formatTs((string) ($payment['updated_at'] ?? '')),
            'captured_at' => self::formatTs((string) ($payment['captured_at'] ?? '')),
        ];
        foreach (['status_fetched_at', 'last_gateway_sync_at', 'reconciled_at'] as $k) {
            if (!empty($meta[$k])) {
                $out['status_fetched_at'] = self::formatTs((string) $meta[$k]);
                break;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function refundsForPayment(int $paymentId): array
    {
        if ($paymentId <= 0 || !Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $hasReconcile = self::paymentRefundsHasColumn($pdo, 'last_status_checked_at');
        $cols = 'pr.id, pr.refund_request_id, pr.amount_inr, pr.gateway_status, pr.gateway_refund_id,
                 pr.created_at, pr.updated_at';
        if ($hasReconcile) {
            $cols .= ', pr.completed_at, pr.last_status_checked_at';
        } else {
            $cols .= ', pr.completed_at';
        }
        $stmt = $pdo->prepare(
            "SELECT {$cols}, rr.request_number
             FROM payment_refunds pr
             LEFT JOIN refund_requests rr ON rr.id = pr.refund_request_id
             WHERE pr.payment_id = ?
             ORDER BY pr.id ASC"
        );
        $stmt->execute([$paymentId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string, mixed> $payment
     * @return list<array{event: string, description: string, source: string, at: string}>
     */
    public static function statusTimeline(array $payment): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $events = [];
        $paymentId = (int) ($payment['id'] ?? 0);
        $orderId = (int) ($payment['order_id'] ?? 0);
        $orderNumber = (string) ($payment['order_number'] ?? '');
        $gateway = (string) ($payment['gateway'] ?? '');
        $gwPaymentId = trim((string) ($payment['gateway_payment_id'] ?? ''));
        $gwOrderId = trim((string) ($payment['gateway_order_id'] ?? ''));
        $idempotency = trim((string) ($payment['idempotency_key'] ?? ''));
        $gatewayEventId = trim((string) ($payment['gateway_event_id'] ?? ''));

        if (self::webhookTableReady($pdo)) {
            $needles = array_values(array_filter([$orderNumber, $gwOrderId, $gwPaymentId, $idempotency], static fn (string $v): bool => $v !== ''));
            $params = [$gateway];
            $or = [];
            if ($gatewayEventId !== '') {
                $or[] = 'event_id = ?';
                $params[] = $gatewayEventId;
            }
            foreach ($needles as $needle) {
                $or[] = 'payload_json LIKE ?';
                $params[] = '%' . AdminServerList::escapeLike($needle) . '%';
            }
            if ($or !== []) {
                $sql = 'SELECT event_id, process_status, error_message, processed_at, created_at
                        FROM payment_webhook_events
                        WHERE gateway = ? AND (' . implode(' OR ', $or) . ')
                        ORDER BY created_at ASC, id ASC
                        LIMIT 80';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $desc = (string) ($row['process_status'] ?? '');
                    $err = trim((string) ($row['error_message'] ?? ''));
                    if ($err !== '') {
                        $desc = $desc !== '' ? $desc . ' — ' . $err : $err;
                    }
                    $events[] = [
                        'event' => 'Webhook · ' . (string) ($row['event_id'] ?? 'event'),
                        'description' => $desc !== '' ? $desc : 'Webhook received',
                        'source' => 'webhook',
                        'at' => (string) ($row['created_at'] ?? ''),
                    ];
                }
            }
        }

        if (PaymentCompletionExceptionService::tableReady()) {
            $hasFailureCat = self::paymentRefundsHasColumn($pdo, 'failure_category')
                && (int) $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_completion_exceptions' AND COLUMN_NAME = 'failure_category'"
                )->fetchColumn() > 0;
            $exCols = $hasFailureCat
                ? 'status, failure_reason, failure_category, created_at, resolved_at'
                : 'status, failure_reason, created_at, resolved_at';
            $exStmt = $pdo->prepare(
                "SELECT {$exCols}
                 FROM payment_completion_exceptions
                 WHERE payment_id = ?
                    OR (order_id = ? AND gateway = ? AND gateway_payment_id = ? AND gateway_payment_id <> '')
                 ORDER BY created_at ASC"
            );
            $exStmt->execute([$paymentId, $orderId, $gateway, $gwPaymentId]);
            foreach ($exStmt->fetchAll(PDO::FETCH_ASSOC) as $ex) {
                $cat = $hasFailureCat ? trim((string) ($ex['failure_category'] ?? '')) : '';
                $reason = trim((string) ($ex['failure_reason'] ?? ''));
                $events[] = [
                    'event' => 'Fulfilment exception · ' . (string) ($ex['status'] ?? 'open'),
                    'description' => ($cat !== '' ? $cat . ': ' : '') . $reason,
                    'source' => 'system',
                    'at' => (string) ($ex['created_at'] ?? ''),
                ];
            }
        }

        if (self::auditTableReady($pdo) && $orderId > 0) {
            $auditStmt = $pdo->prepare(
                "SELECT action, payload_json, created_at
                 FROM admin_audit_logs
                 WHERE entity_type = 'orders' AND entity_id = ? AND action = 'payment.cashfree_reconcile'
                 ORDER BY created_at ASC
                 LIMIT 40"
            );
            $auditStmt->execute([(string) $orderId]);
            foreach ($auditStmt->fetchAll(PDO::FETCH_ASSOC) as $log) {
                $payload = self::parseJsonObject((string) ($log['payload_json'] ?? ''));
                if (!$payload) {
                    continue;
                }
                $matchesPayment = false;
                if ($idempotency !== '' && (string) ($payload['idempotency_key'] ?? '') === $idempotency) {
                    $matchesPayment = true;
                }
                if ($gwPaymentId !== '' && (string) ($payload['gateway_payment_id'] ?? '') === $gwPaymentId) {
                    $matchesPayment = true;
                }
                if ($paymentId > 0 && (int) ($payload['payment_id'] ?? 0) === $paymentId) {
                    $matchesPayment = true;
                }
                if (!$matchesPayment && $idempotency === '' && $gwPaymentId === '') {
                    $oldSt = (string) ($payload['old_payment_row_status'] ?? '');
                    $newSt = (string) ($payload['new_payment_row_status'] ?? '');
                    $payStatus = (string) ($payment['status'] ?? '');
                    if ($newSt === $payStatus || $oldSt === $payStatus) {
                        $matchesPayment = true;
                    }
                }
                if (!$matchesPayment) {
                    continue;
                }
                $source = (string) ($payload['source'] ?? 'reconcile');
                $outcome = (string) ($payload['outcome'] ?? '');
                $detail = trim((string) ($payload['detail'] ?? ''));
                $desc = $outcome !== '' ? $outcome : 'Reconciliation';
                if ($detail !== '') {
                    $desc .= ' — ' . $detail;
                }
                $events[] = [
                    'event' => 'Reconciliation · ' . $outcome,
                    'description' => $desc,
                    'source' => $source === 'admin_refresh' ? 'admin sync' : str_replace('_', ' ', $source),
                    'at' => (string) ($log['created_at'] ?? ''),
                ];
            }
        }

        usort($events, static function (array $a, array $b): int {
            return strcmp($a['at'], $b['at']);
        });

        return $events;
    }

    /** @param array<string, mixed> $payment @return list<string> */
    private static function limitations(array $payment): array
    {
        $notes = [];
        if (trim((string) ($payment['gateway_payment_id'] ?? '')) === '') {
            $notes[] = 'No gateway payment ID stored — some webhook events may not be linked to this attempt.';
        }
        $notes[] = 'Payment method and gateway response code appear only when stored in payment metadata or gateway columns.';
        $notes[] = 'There is no per-payment status history table; the timeline uses webhooks, fulfilment exceptions, and reconciliation audit entries.';

        return $notes;
    }

    /** @param array<string, mixed> $payment @return array<string, mixed> */
    private static function parseMetadata(array $payment): array
    {
        $raw = $payment['metadata_json'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            return self::sanitizeMetadata($raw);
        }
        $decoded = self::parseJsonObject((string) $raw);

        return $decoded !== null ? self::sanitizeMetadata($decoded) : [];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function sanitizeMetadata(array $meta): array
    {
        $allow = [
            'payment_environment', 'payment_method', 'payment_group', 'gateway_status', 'cf_order_status',
            'response_code', 'error_code', 'response_message', 'error_message', 'detail',
            'status_fetched_at', 'last_gateway_sync_at', 'reconciled_at', 'source',
        ];
        $out = [];
        foreach ($allow as $key) {
            if (isset($meta[$key]) && (is_string($meta[$key]) || is_numeric($meta[$key]))) {
                $out[$key] = (string) $meta[$key];
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private static function parseJsonObject(string $json): ?array
    {
        if ($json === '') {
            return null;
        }
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function dash(string $value): string
    {
        return $value !== '' ? $value : '—';
    }

    private static function formatTs(string $value): string
    {
        return $value !== '' ? customer_format_datetime($value) : '—';
    }

    private static function webhookTableReady(PDO $pdo): bool
    {
        try {
            return (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_webhook_events'"
            )->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function auditTableReady(PDO $pdo): bool
    {
        try {
            return (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_audit_logs'"
            )->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function paymentRefundsHasColumn(PDO $pdo, string $column): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute(['payment_refunds', $column]);

            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function publicReference(array $payment): string
    {
        $gw = trim((string) ($payment['gateway_payment_id'] ?? ''));
        if ($gw !== '') {
            return $gw;
        }
        $idem = trim((string) ($payment['idempotency_key'] ?? ''));

        return $idem !== '' ? $idem : '#' . (int) ($payment['id'] ?? 0);
    }
}
