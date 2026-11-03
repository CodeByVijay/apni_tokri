<?php

declare(strict_types=1);

final class PaymentRepository
{
    /** @return array<string, mixed>|null */
    public static function orderByNumberForCustomer(string $orderNumber, int $customerId): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM orders WHERE order_number = ? AND customer_id = ? LIMIT 1'
        );
        $stmt->execute([$orderNumber, $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function orderByNumber(string $orderNumber): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM orders WHERE order_number = ? LIMIT 1');
        $stmt->execute([$orderNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function latestPaymentForOrder(int $orderId): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function paymentByIdempotency(string $key): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM payments WHERE idempotency_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public static function listRecent(int $limit = 100): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT p.*, o.order_number FROM payments p
             INNER JOIN orders o ON o.id = p.order_id
             ORDER BY p.created_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

final class PaymentService
{
    /** Default Cashfree webhook processing lease (seconds). Override via AppSettings payment.webhook_processing_lease_seconds. */
    public const WEBHOOK_PROCESSING_LEASE_SECONDS_DEFAULT = 600;

    public static function amountToPaise(float $inr): int
    {
        return (int) round($inr * 100);
    }

    public static function nextIdempotencyKey(string $orderNumber): string
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM payments p INNER JOIN orders o ON o.id = p.order_id WHERE o.order_number = ?'
        );
        $stmt->execute([$orderNumber]);
        $n = (int) $stmt->fetchColumn();

        return 'pay_' . $orderNumber . '_' . ($n + 1);
    }

    /**
     * @return array{ok: bool, payment_id?: int, checkout?: array<string, mixed>, error?: string}
     */
    public static function initiateForOrder(int $orderId, string $orderNumber, float $amountInr, string $customerMobile): array
    {
        $referenceId = PaymentInitDiagnostics::referenceId();
        $gateway = PaymentGatewayConfig::activeGateway();
        if (PaymentGatewayConfig::useSimulatedCheckout()) {
            $gateway = 'simulate';
        } else {
            $ready = PaymentGatewayConfig::readinessFor($gateway);
            if (!$ready['ok']) {
                PaymentInitDiagnostics::logInitFailure(
                    $gateway,
                    $referenceId,
                    'configuration',
                    $orderId,
                    0,
                    ['code' => (string) ($ready['code'] ?? ''), 'message' => (string) ($ready['message'] ?? '')]
                );

                return [
                    'ok' => false,
                    'error' => PaymentInitDiagnostics::customerMessage(PaymentGatewayConfig::gatewayLabel($gateway), $referenceId),
                    'reference_id' => $referenceId,
                ];
            }
        }

        $idempotency = self::nextIdempotencyKey($orderNumber);
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$orderId, $gateway, round($amountInr, 2), 'INR', 'created', $idempotency]);
        $paymentId = (int) $pdo->lastInsertId();

        if ($gateway === 'simulate') {
            $token = self::issueSimulateToken($orderNumber);

            return [
                'ok' => true,
                'payment_id' => $paymentId,
                'checkout' => ['mode' => 'simulate', 'token' => $token, 'idempotency_key' => $idempotency],
            ];
        }

        if ($gateway === 'razorpay') {
            $created = RazorpayGateway::createOrder(self::amountToPaise($amountInr), $orderNumber, $idempotency, $referenceId, $orderId);
            if (!$created['ok']) {
                $pdo->prepare('UPDATE payments SET status = ? WHERE id = ?')->execute(['failed', $paymentId]);

                return [
                    'ok' => false,
                    'error' => $created['error'] ?? PaymentInitDiagnostics::customerMessage('Razorpay', $referenceId),
                    'reference_id' => $created['reference_id'] ?? $referenceId,
                ];
            }
            $gwOrderId = (string) $created['gateway_order_id'];
            $pdo->prepare('UPDATE payments SET gateway_order_id = ?, status = ? WHERE id = ?')
                ->execute([$gwOrderId, 'pending', $paymentId]);

            return [
                'ok' => true,
                'payment_id' => $paymentId,
                'checkout' => [
                    'mode' => 'razorpay',
                    'key_id' => RazorpayGateway::keyId(),
                    'amount_paise' => self::amountToPaise($amountInr),
                    'gateway_order_id' => $gwOrderId,
                    'order_number' => $orderNumber,
                    'idempotency_key' => $idempotency,
                    'reference_id' => $referenceId,
                ],
            ];
        }

        $returnUrl = SeoUrl::absolute('/payment.php?order=' . rawurlencode($orderNumber));
        $created = CashfreeGateway::createOrder($amountInr, $orderNumber, $customerMobile, $returnUrl, $referenceId, $orderId);
        if (!$created['ok']) {
            $pdo->prepare('UPDATE payments SET status = ? WHERE id = ?')->execute(['failed', $paymentId]);

            return [
                'ok' => false,
                'error' => $created['error'] ?? PaymentInitDiagnostics::customerMessage('Cashfree', $referenceId),
                'reference_id' => $created['reference_id'] ?? $referenceId,
            ];
        }
        $pdo->prepare('UPDATE payments SET gateway_order_id = ?, status = ? WHERE id = ?')
            ->execute([(string) $created['gateway_order_id'], 'pending', $paymentId]);

        return [
            'ok' => true,
            'payment_id' => $paymentId,
            'checkout' => [
                'mode' => 'cashfree',
                'app_id' => CashfreeGateway::appId(),
                'payment_session_id' => (string) ($created['payment_session_id'] ?? ''),
                'order_number' => $orderNumber,
                'idempotency_key' => $idempotency,
                'reference_id' => $referenceId,
            ],
        ];
    }

    /** @param array<string, mixed>|null $payment */
    public static function hasOpenCashfreePayment(?array $payment): bool
    {
        if ($payment === null) {
            return false;
        }
        if ((string) ($payment['gateway'] ?? '') !== 'cashfree') {
            return false;
        }

        return in_array((string) ($payment['status'] ?? ''), ['created', 'pending'], true);
    }

    /**
     * Rebuild storefront checkout payload for an existing Cashfree attempt (no new PG order).
     *
     * @param array<string, mixed> $payment
     * @return array{ok: bool, checkout?: array<string, mixed>, error?: string}
     */
    public static function resumeCashfreeCheckout(string $orderNumber, array $payment): array
    {
        $fetched = CashfreeGateway::fetchOrder($orderNumber);
        if (!$fetched['ok']) {
            return ['ok' => false, 'error' => $fetched['error'] ?? 'Could not load payment session.'];
        }
        $storedCf = trim((string) ($payment['gateway_order_id'] ?? ''));
        $cfOrderId = trim((string) ($fetched['cf_order_id'] ?? ''));
        if ($storedCf !== '' && $cfOrderId !== '' && $storedCf !== $cfOrderId) {
            return ['ok' => false, 'error' => 'Payment reference mismatch.'];
        }
        $session = trim((string) ($fetched['payment_session_id'] ?? ''));
        if ($session === '') {
            return ['ok' => false, 'error' => 'Cashfree session unavailable.'];
        }

        return [
            'ok' => true,
            'checkout' => [
                'mode' => 'cashfree',
                'app_id' => CashfreeGateway::appId(),
                'payment_session_id' => $session,
                'order_number' => $orderNumber,
                'idempotency_key' => (string) ($payment['idempotency_key'] ?? ''),
            ],
        ];
    }

    /**
     * Confirm Cashfree payment using authenticated PG order/payment APIs (localhost-safe; not browser-trusted).
     *
     * @return array{ok: bool, state: string, redirect?: string, message?: string, idempotent?: bool}
     */
    public static function syncCashfreeOrderPayment(string $orderNumber, int $customerId): array
    {
        if (!CashfreeGateway::isConfigured()) {
            return ['ok' => false, 'state' => 'error', 'message' => 'Cashfree is not configured.'];
        }

        $order = PaymentRepository::orderByNumberForCustomer($orderNumber, $customerId);
        if ($order === null) {
            return ['ok' => false, 'state' => 'error', 'message' => 'Order not found.'];
        }
        if ((string) ($order['payment_status'] ?? '') === 'paid') {
            return [
                'ok' => true,
                'state' => 'confirmed',
                'redirect' => url_base() . '/order.php?id=' . rawurlencode($orderNumber),
            ];
        }

        $payment = PaymentRepository::latestPaymentForOrder((int) $order['id']);
        if ($payment === null || (string) ($payment['gateway'] ?? '') !== 'cashfree') {
            return ['ok' => true, 'state' => 'noop'];
        }
        if ((string) ($payment['status'] ?? '') === 'captured') {
            return [
                'ok' => true,
                'state' => 'confirmed',
                'redirect' => url_base() . '/order.php?id=' . rawurlencode($orderNumber),
            ];
        }

        $idempotency = (string) ($payment['idempotency_key'] ?? '');
        if ($idempotency === '') {
            return ['ok' => false, 'state' => 'error', 'message' => 'Payment record invalid.'];
        }

        $orderFetch = CashfreeGateway::fetchOrder($orderNumber);
        if (!$orderFetch['ok']) {
            return ['ok' => false, 'state' => 'error', 'message' => $orderFetch['error'] ?? 'Could not verify payment status.'];
        }

        $storedCf = trim((string) ($payment['gateway_order_id'] ?? ''));
        $cfOrderId = trim((string) ($orderFetch['cf_order_id'] ?? ''));
        if ($storedCf !== '' && $cfOrderId !== '' && $storedCf !== $cfOrderId) {
            return ['ok' => false, 'state' => 'error', 'message' => 'Gateway order reference mismatch.'];
        }

        $paymentsFetch = CashfreeGateway::fetchPayments($orderNumber);
        if (!$paymentsFetch['ok']) {
            return ['ok' => false, 'state' => 'error', 'message' => $paymentsFetch['error'] ?? 'Could not verify payment status.'];
        }

        $payments = $paymentsFetch['payments'] ?? [];
        $success = CashfreeGateway::findSuccessfulCapturedPayment($payments);
        if ($success !== null) {
            $cfPaymentId = (string) ($success['cf_payment_id'] ?? $success['payment_id'] ?? '');
            if ($cfPaymentId === '') {
                return ['ok' => false, 'state' => 'error', 'message' => 'Invalid Cashfree payment reference.'];
            }
            $amount = (float) ($success['payment_amount'] ?? $orderFetch['order_amount'] ?? 0);
            $currency = strtoupper((string) ($success['payment_currency'] ?? 'INR'));
            $gwOrderId = $cfOrderId !== '' ? $cfOrderId : $storedCf;
            $eventId = 'cashfree:api:' . $cfPaymentId;
            $result = self::completePayment(
                $orderNumber,
                'cashfree',
                $cfPaymentId,
                $gwOrderId,
                $amount,
                $currency,
                $idempotency,
                $eventId
            );
            if (!$result['ok']) {
                if (!empty($result['reconciliation'])) {
                    return [
                        'ok' => false,
                        'state' => 'reconciliation',
                        'message' => $result['message'] ?? 'Payment received; fulfilment pending.',
                    ];
                }

                return ['ok' => false, 'state' => 'error', 'message' => $result['message'] ?? 'Could not confirm payment.'];
            }

            unset($_SESSION['checkout_payment'][$orderNumber]);

            return [
                'ok' => true,
                'state' => 'confirmed',
                'idempotent' => !empty($result['idempotent']),
                'redirect' => url_base() . '/order.php?id=' . rawurlencode($orderNumber),
            ];
        }

        $orderStatus = strtoupper((string) ($orderFetch['order_status'] ?? ''));
        if ($orderStatus === 'PAID') {
            return [
                'ok' => true,
                'state' => 'pending',
                'message' => 'Payment is processing. Refresh in a moment.',
            ];
        }

        if (CashfreeGateway::hasTerminalFailure($payments)) {
            self::markPaymentFailed($orderNumber, $idempotency, 'Cashfree reported payment failure.');
            unset($_SESSION['checkout_payment'][$orderNumber]);

            return [
                'ok' => true,
                'state' => 'failed',
                'redirect' => url_base() . '/payment-failed.php?order=' . rawurlencode($orderNumber),
            ];
        }

        return [
            'ok' => true,
            'state' => 'pending',
            'message' => 'Complete payment below or wait if you already paid — we will verify with Cashfree.',
        ];
    }

    public static function issueSimulateToken(string $orderNumber): string
    {
        $token = Security::randomToken(16);
        $_SESSION['payment_simulate'][$orderNumber] = [
            'token' => $token,
            'expires' => time() + 3600,
        ];

        return $token;
    }

    public static function verifySimulateToken(string $orderNumber, string $token): bool
    {
        $bag = $_SESSION['payment_simulate'][$orderNumber] ?? null;
        if (!is_array($bag)) {
            return false;
        }
        if ((int) ($bag['expires'] ?? 0) < time()) {
            unset($_SESSION['payment_simulate'][$orderNumber]);

            return false;
        }

        return Security::hashEquals((string) ($bag['token'] ?? ''), $token);
    }

    /**
     * @return array{ok: bool, idempotent?: bool, message?: string, order_number?: string}
     */
    public static function completePayment(
        string $orderNumber,
        string $gateway,
        string $gatewayPaymentId,
        string $gatewayOrderId,
        float $amountInr,
        string $currency,
        string $idempotencyKey,
        ?string $gatewayEventId = null
    ): array {
        if (strtoupper($currency) !== 'INR') {
            return ['ok' => false, 'message' => 'Invalid currency.'];
        }

        try {
            $orderIdOut = Database::transaction(static function () use (
                $orderNumber,
                $gateway,
                $gatewayPaymentId,
                $gatewayOrderId,
                $amountInr,
                $idempotencyKey,
                $gatewayEventId
            ): array {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ? LIMIT 1 FOR UPDATE');
                $stmt->execute([$orderNumber]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$order) {
                    throw new RuntimeException('Order not found.');
                }
                $orderId = (int) $order['id'];
                if ((string) $order['payment_status'] === 'paid') {
                    return [
                        'ok' => true,
                        'idempotent' => true,
                        'order_id' => $orderId,
                        'customer_id' => (int) $order['customer_id'],
                    ];
                }

                $expected = (float) $order['grand_total'];
                if (abs($expected - $amountInr) > 0.02) {
                    throw new RuntimeException('Amount mismatch.');
                }

                $payStmt = $pdo->prepare(
                    'SELECT * FROM payments WHERE order_id = ? AND idempotency_key = ? LIMIT 1 FOR UPDATE'
                );
                $payStmt->execute([$orderId, $idempotencyKey]);
                $payment = $payStmt->fetch(PDO::FETCH_ASSOC);
                if (!$payment) {
                    throw new RuntimeException('Payment record missing.');
                }
                if ((string) ($payment['status'] ?? '') === 'captured') {
                    return [
                        'ok' => true,
                        'idempotent' => true,
                        'order_id' => $orderId,
                        'customer_id' => (int) $order['customer_id'],
                    ];
                }

                $storedGwOrder = (string) ($payment['gateway_order_id'] ?? '');
                if ($storedGwOrder !== '' && $gatewayOrderId !== '' && $storedGwOrder !== $gatewayOrderId) {
                    throw new RuntimeException('Gateway order reference mismatch.');
                }

                $itemsStmt = $pdo->prepare(
                    'SELECT variant_id, quantity, product_name, unit_price FROM order_items WHERE order_id = ?'
                );
                $itemsStmt->execute([$orderId]);
                $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

                DeliveryQuoteService::revalidateBeforePaymentComplete($order, $items, $pdo);
                foreach ($items as $it) {
                    $vid = (int) $it['variant_id'];
                    $qty = (int) $it['quantity'];
                    if ($vid <= 0) {
                        continue;
                    }
                    if (InventoryService::availableQty($vid) < $qty) {
                        throw new RuntimeException('[inventory] Insufficient stock for ' . ($it['product_name'] ?? 'item'));
                    }
                }
                foreach ($items as $it) {
                    $vid = (int) $it['variant_id'];
                    $qty = (int) $it['quantity'];
                    if ($vid > 0 && !InventoryService::decrement($vid, $qty, 'Order ' . $orderNumber)) {
                        throw new RuntimeException('[inventory] Stock decrement failed.');
                    }
                }

                $meta = [
                    'gateway' => $gateway,
                    'gateway_payment_id' => $gatewayPaymentId,
                    'gateway_order_id' => $gatewayOrderId,
                ];
                if ($gatewayEventId !== null) {
                    $meta['gateway_event_id'] = $gatewayEventId;
                }

                $pdo->prepare(
                    'UPDATE payments SET gateway_payment_id = ?, gateway_order_id = COALESCE(NULLIF(gateway_order_id, \'\'), ?),
                     gateway_event_id = COALESCE(?, gateway_event_id), status = ?, metadata_json = ?,
                     captured_at = COALESCE(captured_at, NOW()), updated_at = NOW()
                     WHERE id = ?'
                )->execute([
                    $gatewayPaymentId,
                    $gatewayOrderId,
                    $gatewayEventId,
                    'captured',
                    json_encode($meta, JSON_UNESCAPED_UNICODE),
                    (int) $payment['id'],
                ]);

                $pdo->prepare(
                    'UPDATE orders SET status = ?, payment_status = ?, placed_at = COALESCE(placed_at, NOW()), updated_at = NOW() WHERE id = ?'
                )->execute(['confirmed', 'paid', $orderId]);

                $pdo->prepare(
                    'INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)'
                )->execute([$orderId, 'Order placed', 'Payment verified server-side']);

                DeliverySlotReservationService::consumeForOrder($orderId, $pdo);

                $coupon = $order['coupon_code'] ?? null;
                if ($coupon) {
                    CouponService::recordUsage((string) $coupon, $orderId, (int) $order['customer_id']);
                }

                return [
                    'ok' => true,
                    'order_id' => $orderId,
                    'customer_id' => (int) $order['customer_id'],
                ];
            });

            if (!empty($orderIdOut['order_id'])) {
                $oid = (int) $orderIdOut['order_id'];
                $custId = (int) ($orderIdOut['customer_id'] ?? 0);
                if (empty($orderIdOut['idempotent'])) {
                    DeliveryAssignmentService::createForOrder($oid);
                    NotificationDispatcher::orderPaymentSuccess($oid);
                    CatalogueGateway::reset();
                }
                if ($custId > 0) {
                    CartService::removeLinesForPaidOrder($oid, $custId);
                }
            }
            unset($_SESSION['payment_simulate'][$orderNumber]);

            return [
                'ok' => true,
                'idempotent' => !empty($orderIdOut['idempotent']),
                'order_number' => $orderNumber,
            ];
        } catch (DeliverySlotBookingException $e) {
            return self::returnReconciliationFailure(
                $orderNumber,
                $idempotencyKey,
                $gateway,
                $gatewayPaymentId,
                $gatewayOrderId,
                $gatewayEventId,
                $amountInr,
                $e->getMessage(),
                PaymentCompletionExceptionService::classifyFailureReason($e->getMessage())
            );
        } catch (RuntimeException $e) {
            if ($gatewayPaymentId !== '' && PaymentCompletionExceptionService::isRecoverableAfterCapture($e)) {
                return self::returnReconciliationFailure(
                    $orderNumber,
                    $idempotencyKey,
                    $gateway,
                    $gatewayPaymentId,
                    $gatewayOrderId,
                    $gatewayEventId,
                    $amountInr,
                    $e->getMessage(),
                    PaymentCompletionExceptionService::classifyFailureReason($e->getMessage())
                );
            }
            error_log('Payment complete failed: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Payment could not be confirmed. Contact support if amount was debited.'];
        } catch (Throwable $e) {
            error_log('Payment complete failed: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Payment could not be confirmed. Contact support if amount was debited.'];
        }
    }

    /**
     * @return array{ok: false, message: string, reconciliation: true}
     */
    private static function returnReconciliationFailure(
        string $orderNumber,
        string $idempotencyKey,
        string $gateway,
        string $gatewayPaymentId,
        string $gatewayOrderId,
        ?string $gatewayEventId,
        float $amountInr,
        string $failureReason,
        ?string $failureCategory = null
    ): array {
        if ($gatewayPaymentId !== '') {
            PaymentCompletionExceptionService::recordCapturedFulfilmentFailure(
                $orderNumber,
                $idempotencyKey,
                $gateway,
                $gatewayPaymentId,
                $gatewayOrderId,
                $gatewayEventId,
                $amountInr,
                $failureReason,
                $failureCategory
            );
        }
        error_log('Payment complete fulfilment failure: ' . $failureReason);

        return [
            'ok' => false,
            'message' => 'Payment received but order fulfilment could not be completed. Our team will reconcile shortly.',
            'reconciliation' => true,
        ];
    }

    /**
     * @return array{ok: bool, idempotent?: bool, message?: string}
     */
    public static function markPaymentFailed(string $orderNumber, string $idempotencyKey, string $note): array
    {
        try {
            Database::transaction(static function () use ($orderNumber, $idempotencyKey, $note): void {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare('SELECT id, payment_status FROM orders WHERE order_number = ? FOR UPDATE');
                $stmt->execute([$orderNumber]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$order || (string) $order['payment_status'] === 'paid') {
                    return;
                }
                $orderId = (int) $order['id'];
                $pdo->prepare('UPDATE orders SET status = ?, payment_status = ?, updated_at = NOW() WHERE id = ?')
                    ->execute(['payment_failed', 'failed', $orderId]);
                $pdo->prepare('UPDATE payments SET status = ? WHERE order_id = ? AND idempotency_key = ?')
                    ->execute(['failed', $orderId, $idempotencyKey]);
                $pdo->prepare('INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)')
                    ->execute([$orderId, 'Payment failed', $note]);
                DeliverySlotReservationService::releaseForOrder($orderId, $pdo);
            });

            NotificationDispatcher::orderPaymentFailed($orderNumber);

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not update payment status.'];
        }
    }

    public static function storeWebhookEvent(string $gateway, string $eventId, string $payloadJson): bool
    {
        return self::beginWebhookEvent($gateway, $eventId, $payloadJson)['action'] === 'process';
    }

    /**
     * Reserve a webhook event for processing (insert or safe retry after failure).
     *
     * @return array{action: 'process'|'duplicate_success'|'in_flight', event_id: string}
     */
    public static function beginWebhookEvent(string $gateway, string $eventId, string $payloadJson): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        try {
            $pdo->prepare(
                'INSERT INTO payment_webhook_events (gateway, event_id, payload_json, process_status) VALUES (?, ?, ?, ?)'
            )->execute([$gateway, $eventId, $payloadJson, 'pending']);

            return ['action' => 'process', 'event_id' => $eventId];
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
        }

        $stmt = $pdo->prepare(
            'SELECT process_status FROM payment_webhook_events WHERE gateway = ? AND event_id = ? LIMIT 1'
        );
        $stmt->execute([$gateway, $eventId]);
        $existing = (string) ($stmt->fetchColumn() ?: '');
        if ($existing === 'processed') {
            return ['action' => 'duplicate_success', 'event_id' => $eventId];
        }

        $claim = $pdo->prepare(
            'UPDATE payment_webhook_events SET payload_json = ?, process_status = ?, error_message = NULL, processed_at = NULL,
             processing_started_at = NULL, worker_token = NULL
             WHERE gateway = ? AND event_id = ? AND process_status IN (?, ?)'
        );
        $claim->execute([$payloadJson, 'pending', $gateway, $eventId, 'pending', 'failed']);
        if ($claim->rowCount() > 0) {
            return ['action' => 'process', 'event_id' => $eventId];
        }

        if ($gateway === 'cashfree') {
            $lease = self::webhookProcessingLeaseSeconds();
            $reclaimStale = $pdo->prepare(
                'UPDATE payment_webhook_events SET payload_json = ?, process_status = ?, error_message = NULL, processed_at = NULL,
                 processing_started_at = NULL, worker_token = NULL
                 WHERE gateway = ? AND event_id = ? AND process_status = ?
                 AND (processing_started_at IS NULL OR processing_started_at < DATE_SUB(NOW(), INTERVAL '
                . (int) $lease . ' SECOND))'
            );
            $reclaimStale->execute([$payloadJson, 'pending', $gateway, $eventId, 'processing']);
            if ($reclaimStale->rowCount() > 0) {
                return ['action' => 'process', 'event_id' => $eventId];
            }
        }

        $stmt->execute([$gateway, $eventId]);
        $existing = (string) ($stmt->fetchColumn() ?: '');
        if ($existing === 'processed') {
            return ['action' => 'duplicate_success', 'event_id' => $eventId];
        }

        return ['action' => 'in_flight', 'event_id' => $eventId];
    }

    public static function webhookProcessingLeaseSeconds(): int
    {
        $raw = AppSettings::get('payment.webhook_processing_lease_seconds') ?? '';
        if ($raw !== '' && ctype_digit($raw)) {
            $sec = (int) $raw;

            return max(60, min($sec, 86400));
        }

        return self::WEBHOOK_PROCESSING_LEASE_SECONDS_DEFAULT;
    }

    /**
     * Claim an event for processing. Cashfree assigns a worker token for lease fencing.
     *
     * @return string|null Worker token when claim succeeds; null when another worker holds the lease.
     */
    public static function markWebhookEventProcessing(string $gateway, string $eventId): ?string
    {
        Install::ensureConnection();
        $token = bin2hex(random_bytes(16));
        $stmt = Database::pdo()->prepare(
            'UPDATE payment_webhook_events SET process_status = ?, processing_started_at = NOW(), worker_token = ?
             WHERE gateway = ? AND event_id = ? AND process_status = ?'
        );
        $stmt->execute(['processing', $token, $gateway, $eventId, 'pending']);

        return $stmt->rowCount() > 0 ? $token : null;
    }

    /**
     * Finalize webhook handling. When $workerToken is set (Cashfree), only the owning worker may finish.
     *
     * @return bool True when a row was updated.
     */
    public static function finishWebhookEvent(
        string $gateway,
        string $eventId,
        bool $ok,
        ?string $error = null,
        ?string $workerToken = null
    ): bool {
        Install::ensureConnection();
        $status = $ok ? 'processed' : 'failed';
        if ($workerToken !== null && $workerToken !== '') {
            $stmt = Database::pdo()->prepare(
                'UPDATE payment_webhook_events SET processed_at = NOW(), process_status = ?, error_message = ?,
                 processing_started_at = NULL, worker_token = NULL
                 WHERE gateway = ? AND event_id = ? AND worker_token = ?'
            );
            $stmt->execute([$status, $error, $gateway, $eventId, $workerToken]);
        } else {
            $stmt = Database::pdo()->prepare(
                'UPDATE payment_webhook_events SET processed_at = NOW(), process_status = ?, error_message = ?,
                 processing_started_at = NULL, worker_token = NULL
                 WHERE gateway = ? AND event_id = ? AND process_status IN (?, ?, ?) AND worker_token IS NULL'
            );
            $stmt->execute([$status, $error, $gateway, $eventId, 'pending', 'processing', 'failed']);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Razorpay payment.captured handler.
     *
     * Order vs subscription: subscription rows are resolved first via `subscription_payments.gateway_order_id`
     * (Stage 11). Only if no match is found does the handler use `payments` + `completePayment()` (Stage 9 orders).
     * Idempotency: webhook event dedupe in `payment_webhook_events`; orders via `completePayment()`; subscriptions
     * via captured payment row / active subscription state in `SubscriptionActivationService`.
     *
     * @param array<string, mixed> $payload
     * @return array{ok: bool, message?: string}
     */
    public static function handleRazorpayWebhook(array $payload, string $eventId): array
    {
        $entity = $payload['payload']['payment']['entity'] ?? null;
        if (!is_array($entity)) {
            return ['ok' => false, 'message' => 'Ignored event'];
        }
        $paymentId = (string) ($entity['id'] ?? '');
        $orderId = (string) ($entity['order_id'] ?? '');
        $amountPaise = (int) ($entity['amount'] ?? 0);
        $currency = (string) ($entity['currency'] ?? 'INR');
        $notes = $entity['notes'] ?? [];
        $idempotency = is_array($notes) ? (string) ($notes['idempotency_key'] ?? '') : '';

        $subPay = SubscriptionPaymentService::paymentByGatewayOrderId($orderId);
        if ($subPay !== null) {
            $subNumber = (string) ($subPay['subscription_number'] ?? '');
            if ($idempotency === '') {
                $idempotency = (string) ($subPay['idempotency_key'] ?? '');
            }

            return SubscriptionActivationService::activateFromPayment(
                $subNumber,
                $amountPaise / 100,
                'razorpay',
                $paymentId,
                $orderId,
                $idempotency,
                $eventId
            );
        }

        $payRow = null;
        Install::ensureConnection();
        if ($orderId !== '') {
            $stmt = Database::pdo()->prepare(
                'SELECT p.*, o.order_number FROM payments p
                 INNER JOIN orders o ON o.id = p.order_id WHERE p.gateway_order_id = ? LIMIT 1'
            );
            $stmt->execute([$orderId]);
            $payRow = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$payRow && $idempotency !== '') {
            $p = PaymentRepository::paymentByIdempotency($idempotency);
            if ($p) {
                $stmt = Database::pdo()->prepare(
                    'SELECT p.*, o.order_number FROM payments p INNER JOIN orders o ON o.id = p.order_id WHERE p.id = ?'
                );
                $stmt->execute([(int) $p['id']]);
                $payRow = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
        if (!$payRow) {
            return ['ok' => false, 'message' => 'Unknown payment'];
        }
        $orderNumber = (string) ($payRow['order_number'] ?? '');
        if ($idempotency === '') {
            $idempotency = (string) ($payRow['idempotency_key'] ?? '');
        }

        $result = self::completePayment(
            $orderNumber,
            'razorpay',
            $paymentId,
            $orderId,
            $amountPaise / 100,
            $currency,
            $idempotency,
            $eventId
        );

        return $result;
    }
}
