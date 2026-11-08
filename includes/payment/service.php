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

    /** @return list<array<string, mixed>> */
    public static function listPaymentsForOrder(int $orderId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM payments WHERE order_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$orderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /** Latest Cashfree attempt that is still open (created/pending). */
    public static function latestOpenCashfreePayment(int $orderId): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM payments WHERE order_id = ? AND gateway = 'cashfree'
             AND status IN ('created', 'pending') ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function latestFailedCashfreePayment(int $orderId): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM payments WHERE order_id = ? AND gateway = 'cashfree'
             AND status = 'failed' ORDER BY id DESC LIMIT 1"
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
        $result = self::searchForAdmin('', 1, min(100, $limit), 'created_at', 'desc');

        return $result['rows'];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public static function searchForAdmin(
        string $query,
        int $page,
        int $perPage,
        string $sortColumn = 'created_at',
        string $sortDirection = 'desc'
    ): array {
        if (!Install::isSchemaReady()) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 1];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $where = ['1=1'];
        $params = [];
        $q = trim($query);
        if ($q !== '') {
            $like = '%' . AdminServerList::escapeLike($q) . '%';
            $where[] = '(o.order_number LIKE ? OR p.gateway_payment_id LIKE ? OR p.idempotency_key LIKE ? OR p.gateway LIKE ? OR p.status LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $whereSql = implode(' AND ', $where);
        $sortMap = [
            'created_at' => 'p.created_at',
            'amount' => 'p.amount',
            'status' => 'p.status',
            'id' => 'p.id',
        ];
        $orderSql = AdminServerList::orderBySql($sortColumn, $sortDirection, $sortMap, 'created_at');
        $from = ' FROM payments p INNER JOIN orders o ON o.id = p.order_id WHERE ' . $whereSql;
        $countStmt = $pdo->prepare('SELECT COUNT(*)' . $from);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $sql = 'SELECT p.*, o.order_number' . $from . ' ORDER BY ' . $orderSql . ' LIMIT ? OFFSET ?';
        $stmt = $pdo->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p);
        }
        $stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($i, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
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
        }
        $exec = PaymentGatewayConfig::assertPaymentExecutionAllowed($gateway);
        if (!$exec['ok']) {
            return [
                'ok' => false,
                'error' => (string) ($exec['message'] ?? 'Payments are not available.'),
                'reference_id' => $referenceId,
            ];
        }
        if (!PaymentGatewayConfig::useSimulatedCheckout()) {
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

        $pdo = Database::pdo();
        $resume = self::tryResumeCashfreeBeforeNewAttempt($pdo, $orderId, $orderNumber, $amountInr, $customerMobile, $referenceId);
        if ($resume !== null && !empty($resume['ok'])) {
            return $resume;
        }

        $attempt = self::allocatePaymentAttemptRow($pdo, $orderId, $orderNumber, $gateway, $amountInr);
        $paymentId = (int) $attempt['payment_id'];
        $idempotency = (string) $attempt['idempotency_key'];

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
            $sessionExpires = isset($created['expire_at'])
                ? (new DateTimeImmutable())->setTimestamp((int) $created['expire_at'])
                : null;
            PaymentSlotHoldService::afterPaymentSessionStarted($pdo, $orderId, 'razorpay', $sessionExpires);
            if ($sessionExpires !== null) {
                PaymentSlotHoldService::storeSessionExpiryOnPayment($pdo, $paymentId, $sessionExpires);
            }

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

        $returnUrl = SeoUrl::storefrontAbsolute('/payment.php?order=' . rawurlencode($orderNumber));
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
        $sessionExpires = null;
        if (!empty($created['order_expiry_time'])) {
            try {
                $sessionExpires = new DateTimeImmutable((string) $created['order_expiry_time']);
            } catch (Throwable $e) {
                $sessionExpires = null;
            }
        }
        PaymentSlotHoldService::afterPaymentSessionStarted($pdo, $orderId, 'cashfree', $sessionExpires);
        if ($sessionExpires !== null) {
            PaymentSlotHoldService::storeSessionExpiryOnPayment($pdo, $paymentId, $sessionExpires);
        }

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

    /**
     * Reuse an existing Cashfree session / open payment row instead of creating a duplicate PG order.
     *
     * @return array{ok: bool, payment_id?: int, checkout?: array<string, mixed>, error?: string, reference_id?: string}|null
     */
    private static function tryResumeCashfreeBeforeNewAttempt(
        PDO $pdo,
        int $orderId,
        string $orderNumber,
        float $amountInr,
        string $customerMobile,
        string $referenceId
    ): ?array {
        if (PaymentGatewayConfig::useSimulatedCheckout() || PaymentGatewayConfig::activeGateway() !== 'cashfree') {
            return null;
        }
        $open = PaymentRepository::latestOpenCashfreePayment($orderId);
        if ($open !== null && abs((float) ($open['amount'] ?? 0) - round($amountInr, 2)) < 0.02) {
            $resume = self::resumeCashfreeCheckout($orderNumber, $open);
            if (!empty($resume['ok'])) {
                return [
                    'ok' => true,
                    'payment_id' => (int) ($open['id'] ?? 0),
                    'checkout' => ($resume['checkout'] ?? []) + ['reference_id' => $referenceId],
                ];
            }
        }

        $existing = CashfreeGateway::fetchOrder($orderNumber);
        if (empty($existing['ok'])) {
            return null;
        }
        if (abs((float) ($existing['order_amount'] ?? 0) - round($amountInr, 2)) > 0.02) {
            return null;
        }
        $session = trim((string) ($existing['payment_session_id'] ?? ''));
        $gwStatus = strtoupper((string) ($existing['order_status'] ?? ''));
        if ($session === '' || !in_array($gwStatus, ['ACTIVE', 'PENDING'], true)) {
            return null;
        }

        $latest = PaymentRepository::latestPaymentForOrder($orderId);
        $paymentRow = $open ?? $latest;
        $paymentId = 0;
        $idempotencyKey = '';
        if ($paymentRow !== null && (string) ($paymentRow['gateway'] ?? '') === 'cashfree'
            && (string) ($paymentRow['status'] ?? '') === 'failed') {
            $paymentId = (int) $paymentRow['id'];
            $idempotencyKey = (string) ($paymentRow['idempotency_key'] ?? '');
            $pdo->prepare('UPDATE payments SET status = ?, gateway_order_id = ? WHERE id = ?')
                ->execute(['pending', (string) ($existing['cf_order_id'] ?? ''), $paymentId]);
        } elseif ($paymentRow === null || (string) ($paymentRow['status'] ?? '') === 'failed') {
            $idempotencyKey = self::nextIdempotencyKey($orderNumber);
            $envMeta = json_encode(
                ['payment_environment' => PaymentGatewayConfig::recordedPaymentEnvironment('cashfree')],
                JSON_UNESCAPED_UNICODE
            );
            $pdo->prepare(
                'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key, gateway_order_id, metadata_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $orderId,
                'cashfree',
                round($amountInr, 2),
                'INR',
                'pending',
                $idempotencyKey,
                (string) ($existing['cf_order_id'] ?? ''),
                $envMeta,
            ]);
            $paymentId = (int) $pdo->lastInsertId();
        } else {
            $paymentId = (int) ($paymentRow['id'] ?? 0);
            $idempotencyKey = (string) ($paymentRow['idempotency_key'] ?? '');
        }

        $cfExpiry = PaymentSlotHoldService::cashfreeExpiryFromFetchOrder($existing);
        PaymentSlotHoldService::afterPaymentSessionResumed($pdo, $orderId, $cfExpiry);
        if ($paymentId > 0 && $cfExpiry !== null) {
            PaymentSlotHoldService::storeSessionExpiryOnPayment($pdo, $paymentId, $cfExpiry);
        }

        if ($paymentId <= 0 || $session === '') {
            return null;
        }

        return [
            'ok' => true,
            'payment_id' => $paymentId,
            'checkout' => [
                'mode' => 'cashfree',
                'app_id' => CashfreeGateway::appId(),
                'payment_session_id' => $session,
                'order_number' => $orderNumber,
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : self::nextIdempotencyKey($orderNumber),
                'reference_id' => $referenceId,
            ],
        ];
    }

    /**
     * Reuse an open/failed payment row when retrying; otherwise insert one new attempt row.
     *
     * @return array{payment_id: int, idempotency_key: string, reopened: bool}
     */
    private static function allocatePaymentAttemptRow(
        PDO $pdo,
        int $orderId,
        string $orderNumber,
        string $gateway,
        float $amountInr
    ): array {
        $amountInr = round($amountInr, 2);
        if ($gateway === 'cashfree') {
            $open = PaymentRepository::latestOpenCashfreePayment($orderId);
            if ($open !== null && abs((float) ($open['amount'] ?? 0) - $amountInr) < 0.02) {
                return [
                    'payment_id' => (int) $open['id'],
                    'idempotency_key' => (string) ($open['idempotency_key'] ?? ''),
                    'reopened' => false,
                ];
            }
            $failed = PaymentRepository::latestFailedCashfreePayment($orderId);
            if ($failed !== null && abs((float) ($failed['amount'] ?? 0) - $amountInr) < 0.02
                && PaymentRepository::latestOpenCashfreePayment($orderId) === null) {
                $pid = (int) $failed['id'];
                $pdo->prepare('UPDATE payments SET status = ?, updated_at = NOW() WHERE id = ? AND status = ?')
                    ->execute(['created', $pid, 'failed']);

                return [
                    'payment_id' => $pid,
                    'idempotency_key' => (string) ($failed['idempotency_key'] ?? ''),
                    'reopened' => true,
                ];
            }
        }

        $idempotency = self::nextIdempotencyKey($orderNumber);
        $envMeta = json_encode(
            ['payment_environment' => PaymentGatewayConfig::recordedPaymentEnvironment($gateway)],
            JSON_UNESCAPED_UNICODE
        );
        $pdo->prepare(
            'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$orderId, $gateway, $amountInr, 'INR', 'created', $idempotency, $envMeta]);

        return [
            'payment_id' => (int) $pdo->lastInsertId(),
            'idempotency_key' => $idempotency,
            'reopened' => false,
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
        $recordedEnv = self::cashfreeRecordedEnvironmentFromPaymentRow($payment);
        $fetched = CashfreeGateway::fetchOrder($orderNumber, $recordedEnv);
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

        $orderRow = PaymentRepository::orderByNumber($orderNumber);
        if ($orderRow !== null) {
            $pdo = Database::pdo();
            $cfExpiry = PaymentSlotHoldService::cashfreeExpiryFromFetchOrder($fetched);
            PaymentSlotHoldService::afterPaymentSessionResumed($pdo, (int) $orderRow['id'], $cfExpiry);
            $paymentId = (int) ($payment['id'] ?? 0);
            if ($paymentId > 0 && $cfExpiry !== null) {
                PaymentSlotHoldService::storeSessionExpiryOnPayment($pdo, $paymentId, $cfExpiry);
            }
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
        return self::reconcileCashfreeOrderFromGateway($orderNumber, $customerId, 'customer_sync', null);
    }

    /**
     * Admin-only Cashfree reconciliation (no customer ownership check).
     *
     * @return array{ok: bool, state: string, message?: string, idempotent?: bool}
     */
    public static function adminRefreshCashfreePayment(string $orderNumber, int $adminUserId): array
    {
        $result = self::reconcileCashfreeOrderFromGateway($orderNumber, null, 'admin_refresh', $adminUserId);
        unset($result['redirect']);

        return $result;
    }

    /**
     * Poll Razorpay for captured payments before auto-expiry cancellation (no new charges).
     *
     * @return array{ok: bool, state: string, message?: string, idempotent?: bool}
     */
    public static function reconcileRazorpayOrderForExpiry(string $orderNumber): array
    {
        if (!RazorpayGateway::isConfigured()) {
            return ['ok' => true, 'state' => 'noop'];
        }
        $order = PaymentRepository::orderByNumber($orderNumber);
        if ($order === null) {
            return ['ok' => false, 'state' => 'error', 'message' => 'Order not found.'];
        }
        if ((string) ($order['payment_status'] ?? '') === 'paid') {
            return ['ok' => true, 'state' => 'confirmed'];
        }
        $orderPayments = PaymentRepository::listPaymentsForOrder((int) $order['id']);
        $hasRazorpay = false;
        $targetRow = null;
        foreach ($orderPayments as $row) {
            if ((string) ($row['gateway'] ?? '') !== 'razorpay') {
                continue;
            }
            $hasRazorpay = true;
            $gwOid = trim((string) ($row['gateway_order_id'] ?? ''));
            if ($gwOid !== '' && in_array((string) ($row['status'] ?? ''), ['created', 'pending', 'failed'], true)) {
                $targetRow = $row;
                break;
            }
        }
        if (!$hasRazorpay) {
            return ['ok' => true, 'state' => 'noop'];
        }
        if ($targetRow === null) {
            foreach ($orderPayments as $row) {
                if ((string) ($row['gateway'] ?? '') === 'razorpay') {
                    $targetRow = $row;
                    break;
                }
            }
        }
        if ($targetRow === null) {
            return ['ok' => true, 'state' => 'noop'];
        }
        $recordedEnv = self::paymentRecordedEnvironmentFromPaymentRow($targetRow);
        if ($recordedEnv !== null && !self::razorpayCredentialsMatchRecordedEnvironment($recordedEnv)) {
            $keyHint = self::razorpayKeyEnvironmentHint();
            error_log(
                'Unpaid expiry deferred: Razorpay credential/environment mismatch order='
                . $orderNumber
                . ' recorded_env=' . $recordedEnv
                . ' active_key=' . $keyHint
            );

            return [
                'ok' => false,
                'state' => 'defer',
                'message' => 'Expiry verification deferred: Razorpay credentials ('
                    . $keyHint
                    . ') do not match payment environment “'
                    . $recordedEnv
                    . '”. Align gateway settings — order not auto-cancelled.',
            ];
        }
        $gwOrderId = trim((string) ($targetRow['gateway_order_id'] ?? ''));
        if ($gwOrderId === '') {
            return ['ok' => true, 'state' => 'unpaid'];
        }
        $fetch = RazorpayGateway::fetchOrderPayments($gwOrderId);
        if (empty($fetch['ok'])) {
            if (!empty($fetch['network_error']) || in_array((int) ($fetch['http_status'] ?? 0), [401, 403, 429], true)
                || ((int) ($fetch['http_status'] ?? 0) >= 500)) {
                return ['ok' => false, 'state' => 'defer', 'message' => (string) ($fetch['error'] ?? 'Gateway verification deferred.')];
            }

            return ['ok' => false, 'state' => 'defer', 'message' => (string) ($fetch['error'] ?? 'Gateway verification deferred.')];
        }
        $captured = RazorpayGateway::findCapturedPayment($fetch['payments'] ?? []);
        if ($captured !== null) {
            $paymentId = (string) ($captured['id'] ?? '');
            $amountPaise = (int) ($captured['amount'] ?? 0);
            $currency = (string) ($captured['currency'] ?? 'INR');
            $idempotency = (string) ($targetRow['idempotency_key'] ?? '');
            if ($idempotency === '' || $paymentId === '') {
                return ['ok' => false, 'state' => 'defer', 'message' => 'Captured Razorpay payment missing idempotency reference.'];
            }
            $eventId = 'razorpay:expiry_cron:' . $paymentId;
            $result = self::completePayment(
                $orderNumber,
                'razorpay',
                $paymentId,
                $gwOrderId,
                $amountPaise / 100,
                $currency,
                $idempotency,
                $eventId
            );
            if (!empty($result['ok'])) {
                return ['ok' => true, 'state' => 'confirmed', 'idempotent' => !empty($result['idempotent'])];
            }
            if (!empty($result['reconciliation'])) {
                return ['ok' => false, 'state' => 'defer', 'message' => (string) ($result['message'] ?? 'Fulfilment pending.')];
            }

            return ['ok' => false, 'state' => 'defer', 'message' => (string) ($result['message'] ?? 'Could not confirm captured payment.')];
        }
        foreach ($fetch['payments'] ?? [] as $p) {
            if (!is_array($p)) {
                continue;
            }
            $st = (string) ($p['status'] ?? '');
            if ($st === 'authorized' || $st === 'created') {
                return ['ok' => false, 'state' => 'defer', 'message' => 'Razorpay payment still processing.'];
            }
        }

        return ['ok' => true, 'state' => 'unpaid'];
    }

    /** @param 'production'|'sandbox'|'test'|'simulate'|string $recordedEnvironment */
    private static function razorpayCredentialsMatchRecordedEnvironment(string $recordedEnvironment): bool
    {
        $kid = RazorpayGateway::keyId();
        $isTestKey = str_starts_with($kid, 'rzp_test_');
        $rec = strtolower(trim($recordedEnvironment));
        if ($rec === 'test' || $rec === 'sandbox') {
            return $isTestKey;
        }
        if ($rec === 'production') {
            return str_starts_with($kid, 'rzp_live_');
        }

        return true;
    }

    private static function razorpayKeyEnvironmentHint(): string
    {
        $kid = RazorpayGateway::keyId();
        if (str_starts_with($kid, 'rzp_test_')) {
            return 'test';
        }
        if (str_starts_with($kid, 'rzp_live_')) {
            return 'live';
        }

        return 'unknown';
    }

    /**
     * @param list<array<string, mixed>> $orderPayments
     */
    public static function paymentRecordedEnvironmentFromPayments(array $orderPayments): ?string
    {
        for ($i = count($orderPayments) - 1; $i >= 0; $i--) {
            $env = self::paymentRecordedEnvironmentFromPaymentRow($orderPayments[$i]);
            if ($env !== null) {
                return $env;
            }
        }

        return null;
    }

    /**
     * Resume or create a gateway checkout session for storefront payment (amount from DB order only).
     * Mirrors payment.php initiation rules; sets $_SESSION['checkout_payment'] when successful.
     *
     * @return array{
     *     ok: bool,
     *     error?: string,
     *     info?: string,
     *     checkout?: array<string, mixed>,
     *     redirect?: string,
     *     order?: array<string, mixed>
     * }
     */
    public static function prepareStorefrontOrderPayment(string $orderNumber, int $customerId, bool $freshAttempt = false): array
    {
        if (!CommerceGateway::useDatabase()) {
            return ['ok' => false, 'error' => 'Payments require a live order.'];
        }

        $order = PaymentRepository::orderByNumberForCustomer($orderNumber, $customerId);
        if ($order === null) {
            return ['ok' => false, 'error' => 'Order not found.'];
        }

        if ((string) ($order['payment_status'] ?? '') === 'paid') {
            return [
                'ok' => false,
                'redirect' => url_base() . '/order.php?id=' . rawurlencode($orderNumber),
                'order' => $order,
            ];
        }

        if (!self::customerCanRetryStorefrontPayment($order)) {
            return ['ok' => false, 'error' => 'This order is not eligible for payment.', 'order' => $order];
        }
        if (self::storefrontFulfilmentReconciliationContext((int) $order['id']) !== null) {
            return [
                'ok' => false,
                'error' => 'Payment was already received. Confirmation is pending — use Check payment status or contact support.',
                'order' => $order,
            ];
        }

        if ($freshAttempt) {
            $pendingAuto = !empty($_SESSION['checkout_payment_autolaunch'][$orderNumber]);
            $existingCheckout = $_SESSION['checkout_payment'][$orderNumber] ?? null;
            if ($pendingAuto && is_array($existingCheckout)) {
                $modeExisting = (string) ($existingCheckout['mode'] ?? '');
                $gatewayReady = ($modeExisting === 'cashfree' && trim((string) ($existingCheckout['payment_session_id'] ?? '')) !== '')
                    || ($modeExisting === 'razorpay' && trim((string) ($existingCheckout['gateway_order_id'] ?? '')) !== '')
                    || $modeExisting === 'simulate';
                if ($gatewayReady) {
                    return ['ok' => true, 'checkout' => $existingCheckout, 'order' => $order];
                }
            }
            unset($_SESSION['checkout_payment'][$orderNumber]);
            $_SESSION['checkout_payment_autolaunch'][$orderNumber] = true;
        }

        $error = '';
        $info = '';
        $latestPayment = PaymentRepository::latestPaymentForOrder((int) $order['id']);
        $cashfreeActive = PaymentGatewayConfig::activeGateway() === 'cashfree' && !PaymentGatewayConfig::useSimulatedCheckout();
        if ($cashfreeActive || self::hasOpenCashfreePayment($latestPayment)) {
            $sync = self::syncCashfreeOrderPayment($orderNumber, $customerId);
            if (!empty($sync['redirect'])) {
                return ['ok' => false, 'redirect' => (string) $sync['redirect'], 'order' => $order];
            }
            if (($sync['state'] ?? '') === 'reconciliation') {
                $error = (string) ($sync['message'] ?? 'Payment received; fulfilment pending.');
            } elseif (($sync['state'] ?? '') === 'error') {
                $error = (string) ($sync['message'] ?? 'Could not verify payment status.');
            } elseif (($sync['state'] ?? '') === 'pending') {
                $info = (string) ($sync['message'] ?? '');
            }
            $order = PaymentRepository::orderByNumberForCustomer($orderNumber, $customerId) ?? $order;
            if ((string) ($order['payment_status'] ?? '') === 'paid') {
                return [
                    'ok' => false,
                    'redirect' => url_base() . '/order.php?id=' . rawurlencode($orderNumber),
                    'order' => $order,
                ];
            }
            $latestPayment = PaymentRepository::latestPaymentForOrder((int) $order['id']);
        }

        $checkout = $_SESSION['checkout_payment'][$orderNumber] ?? null;
        if ($checkout === null || !is_array($checkout)) {
            if (self::hasOpenCashfreePayment($latestPayment) && is_array($latestPayment)) {
                $resume = self::resumeCashfreeCheckout($orderNumber, $latestPayment);
                if (!$resume['ok']) {
                    $error = $resume['error'] ?? 'Could not resume Cashfree payment.';
                } else {
                    $checkout = $resume['checkout'] ?? [];
                }
            } elseif ($latestPayment === null || (string) ($latestPayment['status'] ?? '') === 'failed') {
                $mobile = (string) (CustomerService::profile()['mobile'] ?? '');
                $init = self::initiateForOrder(
                    (int) $order['id'],
                    $orderNumber,
                    (float) $order['grand_total'],
                    $mobile
                );
                if (!$init['ok']) {
                    $error = $init['error'] ?? 'Could not start payment.';
                } else {
                    $checkout = $init['checkout'] ?? [];
                }
            }
        }

        if ($error !== '' || !is_array($checkout) || $checkout === []) {
            return [
                'ok' => false,
                'error' => $error !== '' ? $error : 'Could not start payment.',
                'info' => $info,
                'order' => $order,
            ];
        }

        $_SESSION['checkout_payment'][$orderNumber] = $checkout;

        $pdo = Database::pdo();
        PaymentSlotHoldService::synchronizeOpenPaymentHold($pdo, (int) $order['id']);

        return ['ok' => true, 'checkout' => $checkout, 'info' => $info, 'order' => $order];
    }

    public static function customerCanRetryStorefrontPayment(array $orderRow): bool
    {
        if ((string) ($orderRow['payment_status'] ?? '') === 'paid') {
            return false;
        }
        $orderId = (int) ($orderRow['id'] ?? 0);
        if ($orderId > 0 && self::storefrontFulfilmentReconciliationContext($orderId) !== null) {
            return false;
        }
        $status = (string) ($orderRow['status'] ?? '');
        if (in_array($status, ['cancelled', 'confirmed', 'delivered', 'completed'], true)) {
            return false;
        }
        if (!UnpaidOrderExpiryService::isPaymentWindowOpen($orderRow)) {
            return false;
        }

        return in_array((string) ($orderRow['payment_status'] ?? ''), ['pending', 'failed'], true);
    }

    /**
     * @return array{ok: bool, state: string, redirect?: string, message?: string, idempotent?: bool}
     */
    public static function reconcileCashfreeOrderFromGateway(
        string $orderNumber,
        ?int $restrictCustomerId,
        string $source,
        ?int $actingAdminUserId
    ): array {
        if (!CashfreeGateway::isConfigured()) {
            return ['ok' => false, 'state' => 'error', 'message' => 'Cashfree is not configured.'];
        }

        $order = $restrictCustomerId !== null
            ? PaymentRepository::orderByNumberForCustomer($orderNumber, $restrictCustomerId)
            : PaymentRepository::orderByNumber($orderNumber);
        if ($order === null) {
            return ['ok' => false, 'state' => 'error', 'message' => 'Order not found.'];
        }

        $orderId = (int) $order['id'];
        $oldOrderPayStatus = (string) ($order['payment_status'] ?? '');
        $orderPayments = PaymentRepository::listPaymentsForOrder($orderId);
        $openCashfree = PaymentRepository::latestOpenCashfreePayment($orderId);
        $latestPayment = $orderPayments !== [] ? $orderPayments[count($orderPayments) - 1] : null;

        if ($oldOrderPayStatus === 'paid') {
            return [
                'ok' => true,
                'state' => 'confirmed',
                'redirect' => url_base() . '/order.php?id=' . rawurlencode($orderNumber),
            ];
        }

        $hasCashfree = false;
        foreach ($orderPayments as $p) {
            if ((string) ($p['gateway'] ?? '') === 'cashfree') {
                $hasCashfree = true;
                break;
            }
        }
        if (!$hasCashfree) {
            return ['ok' => true, 'state' => 'noop', 'message' => 'No Cashfree payment attempts on this order.'];
        }

        foreach ($orderPayments as $p) {
            if ((string) ($p['status'] ?? '') === 'captured') {
                return [
                    'ok' => true,
                    'state' => 'confirmed',
                    'redirect' => url_base() . '/order.php?id=' . rawurlencode($orderNumber),
                ];
            }
        }

        $recordedCashfreeEnv = self::cashfreeRecordedEnvironmentFromPayments($orderPayments);
        $orderFetch = CashfreeGateway::fetchOrder($orderNumber, $recordedCashfreeEnv);
        if (!$orderFetch['ok']) {
            $httpStatus = (int) ($orderFetch['http_status'] ?? 0);
            $networkError = !empty($orderFetch['network_error']) || ($httpStatus === 0 && !empty($orderFetch['error']));
            $adminMsg = (string) ($orderFetch['error'] ?? 'Could not verify payment status.');
            if (self::cashfreePaymentsNeverReachedGateway($orderPayments) && $httpStatus === 404) {
                $adminMsg = 'No Cashfree order exists for this storefront order. The payment row shows a failed attempt '
                    . 'without a gateway order ID — Cashfree checkout was not created successfully. '
                    . 'Customer can use Retry payment; refresh cannot recover a missing gateway order.';
            }

            if ($source === 'expiry_cron' && $httpStatus === 404 && !$networkError) {
                $safe = self::evaluateCashfreeExpiryCron404AsUnpaid($orderId, $orderNumber, $orderPayments);
                if ($safe['ok']) {
                    self::recordCashfreeReconciliationAudit(
                        $orderId,
                        $orderNumber,
                        $source,
                        $oldOrderPayStatus,
                        $oldOrderPayStatus,
                        $openCashfree,
                        $openCashfree,
                        'expiry_unpaid_verified_404',
                        null,
                        null,
                        $safe['detail'],
                        $actingAdminUserId,
                        [
                            'http_status' => 404,
                            'expiry_treat_as_unpaid' => true,
                            'safety_checks' => $safe['checks'],
                        ]
                    );

                    return ['ok' => true, 'state' => 'failed', 'message' => $safe['detail']];
                }
            }

            self::recordCashfreeReconciliationAudit(
                $orderId,
                $orderNumber,
                $source,
                $oldOrderPayStatus,
                $oldOrderPayStatus,
                $openCashfree,
                $openCashfree,
                'api_error',
                null,
                null,
                $adminMsg,
                $actingAdminUserId,
                $httpStatus > 0 ? ['http_status' => $httpStatus] : null
            );

            return ['ok' => false, 'state' => 'error', 'message' => $adminMsg];
        }

        $cfOrderId = trim((string) ($orderFetch['cf_order_id'] ?? ''));
        if ($cfOrderId !== '') {
            self::backfillCashfreeGatewayOrderIds($orderId, $cfOrderId);
        }
        if (!self::cashfreeGatewayOrderIdMatches($orderPayments, $cfOrderId)) {
            return ['ok' => false, 'state' => 'error', 'message' => 'Gateway order reference mismatch.'];
        }

        $paymentsFetch = CashfreeGateway::fetchPayments($orderNumber, $recordedCashfreeEnv);
        if (!$paymentsFetch['ok']) {
            self::recordCashfreeReconciliationAudit(
                $orderId,
                $orderNumber,
                $source,
                $oldOrderPayStatus,
                $oldOrderPayStatus,
                $openCashfree,
                $openCashfree,
                'api_error',
                $cfOrderId !== '' ? $cfOrderId : null,
                null,
                $paymentsFetch['error'] ?? 'Could not verify payment status.',
                $actingAdminUserId
            );

            return ['ok' => false, 'state' => 'error', 'message' => $paymentsFetch['error'] ?? 'Could not verify payment status.'];
        }

        $gatewayPayments = $paymentsFetch['payments'] ?? [];
        $analysis = CashfreeGateway::analyzeGatewayPayments($gatewayPayments);
        $success = $analysis['success'];

        if ($success !== null) {
            $idempotency = self::resolveIdempotencyKeyForCashfreeCapture($orderPayments, $success, $openCashfree);
            if ($idempotency === '') {
                return ['ok' => false, 'state' => 'error', 'message' => 'Payment record invalid.'];
            }
            $cfPaymentId = (string) ($success['cf_payment_id'] ?? $success['payment_id'] ?? '');
            if ($cfPaymentId === '') {
                return ['ok' => false, 'state' => 'error', 'message' => 'Invalid Cashfree payment reference.'];
            }
            $amount = (float) ($success['payment_amount'] ?? $orderFetch['order_amount'] ?? 0);
            $currency = strtoupper((string) ($success['payment_currency'] ?? 'INR'));
            $storedCf = trim((string) ($openCashfree['gateway_order_id'] ?? $latestPayment['gateway_order_id'] ?? ''));
            $gwOrderId = $cfOrderId !== '' ? $cfOrderId : $storedCf;
            $eventId = 'cashfree:' . $source . ':' . $cfPaymentId;
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
            $orderAfter = PaymentRepository::orderByNumber($orderNumber);
            $newOrderPayStatus = (string) ($orderAfter['payment_status'] ?? $oldOrderPayStatus);
            $payAfter = PaymentRepository::paymentByIdempotency($idempotency);
            self::recordCashfreeReconciliationAudit(
                $orderId,
                $orderNumber,
                $source,
                $oldOrderPayStatus,
                $newOrderPayStatus,
                $openCashfree,
                $payAfter,
                $result['ok'] ? 'confirmed' : (!empty($result['reconciliation']) ? 'reconciliation' : 'error'),
                $gwOrderId,
                $cfPaymentId,
                $result['message'] ?? null,
                $actingAdminUserId
            );
            if (!$result['ok']) {
                if (!empty($result['reconciliation'])) {
                    self::clearStorefrontCheckoutSession($orderNumber);

                    return [
                        'ok' => false,
                        'state' => 'reconciliation',
                        'message' => $result['message'] ?? 'Payment received; fulfilment pending.',
                    ];
                }

                return ['ok' => false, 'state' => 'error', 'message' => $result['message'] ?? 'Could not confirm payment.'];
            }

            self::clearCheckoutPaymentSession($orderNumber);

            return [
                'ok' => true,
                'state' => 'confirmed',
                'idempotent' => !empty($result['idempotent']),
                'redirect' => url_base() . '/order.php?id=' . rawurlencode($orderNumber),
            ];
        }

        $orderStatus = strtoupper((string) ($orderFetch['order_status'] ?? ''));
        if ($orderStatus === 'PAID') {
            self::recordCashfreeReconciliationAudit(
                $orderId,
                $orderNumber,
                $source,
                $oldOrderPayStatus,
                $oldOrderPayStatus,
                $openCashfree,
                $openCashfree,
                'pending',
                $cfOrderId !== '' ? $cfOrderId : null,
                null,
                'Cashfree order PAID; payment row not yet captured.',
                $actingAdminUserId
            );

            return [
                'ok' => true,
                'state' => 'pending',
                'message' => 'Payment is processing. Refresh in a moment.',
            ];
        }

        if (!empty($analysis['should_mark_order_failed'])) {
            $failKey = self::resolveIdempotencyKeyForCashfreeFailure($orderPayments, $openCashfree);
            if ($failKey !== '') {
                self::markPaymentFailed($orderNumber, $failKey, 'Cashfree reported payment failure (server verification).');
            }
            self::clearCheckoutPaymentSession($orderNumber);
            $orderAfter = PaymentRepository::orderByNumber($orderNumber);
            self::recordCashfreeReconciliationAudit(
                $orderId,
                $orderNumber,
                $source,
                $oldOrderPayStatus,
                (string) ($orderAfter['payment_status'] ?? $oldOrderPayStatus),
                $openCashfree,
                PaymentRepository::paymentByIdempotency($failKey),
                'failed',
                $cfOrderId !== '' ? $cfOrderId : null,
                null,
                'Hard gateway failure confirmed via API.',
                $actingAdminUserId
            );

            return [
                'ok' => true,
                'state' => 'failed',
                'redirect' => url_base() . '/payment-failed.php?order=' . rawurlencode($orderNumber),
            ];
        }

        self::recordCashfreeReconciliationAudit(
            $orderId,
            $orderNumber,
            $source,
            $oldOrderPayStatus,
            $oldOrderPayStatus,
            $openCashfree,
            $openCashfree,
            'pending',
            $cfOrderId !== '' ? $cfOrderId : null,
            null,
            'No captured payment yet (retryable).',
            $actingAdminUserId
        );

        return [
            'ok' => true,
            'state' => 'pending',
            'message' => $source === 'admin_refresh'
                ? 'Still pending at Cashfree — customer can retry payment. No status change.'
                : 'Complete payment below or wait if you already paid — we will verify with Cashfree.',
        ];
    }

    /**
     * @param list<array<string, mixed>> $orderPayments
     */
    private static function cashfreeRecordedEnvironmentFromPayments(array $orderPayments): ?string
    {
        for ($i = count($orderPayments) - 1; $i >= 0; $i--) {
            $env = self::cashfreeRecordedEnvironmentFromPaymentRow($orderPayments[$i]);
            if ($env !== null) {
                return $env;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $paymentRow */
    private static function paymentRecordedEnvironmentFromPaymentRow(array $paymentRow): ?string
    {
        $raw = trim((string) ($paymentRow['metadata_json'] ?? ''));
        if ($raw === '') {
            return null;
        }
        try {
            $meta = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($meta)) {
                return null;
            }
            $env = strtolower(trim((string) ($meta['payment_environment'] ?? '')));

            return $env !== '' ? $env : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @param array<string, mixed> $paymentRow */
    private static function cashfreeRecordedEnvironmentFromPaymentRow(array $paymentRow): ?string
    {
        if ((string) ($paymentRow['gateway'] ?? '') !== 'cashfree') {
            return null;
        }

        return self::paymentRecordedEnvironmentFromPaymentRow($paymentRow);
    }

    /**
     * @param list<array<string, mixed>> $orderPayments
     */
    private static function cashfreePaymentsNeverReachedGateway(array $orderPayments): bool
    {
        $sawCashfree = false;
        foreach ($orderPayments as $row) {
            if ((string) ($row['gateway'] ?? '') !== 'cashfree') {
                continue;
            }
            $sawCashfree = true;
            $stored = trim((string) ($row['gateway_order_id'] ?? ''));
            if ($stored !== '') {
                return false;
            }
            $status = (string) ($row['status'] ?? '');
            if (in_array($status, ['pending', 'captured'], true)) {
                return false;
            }
        }

        return $sawCashfree;
    }

    /**
     * Expiry cron only: verify Cashfree HTTP 404 (no merchant order at PG) is safe to treat as unpaid.
     *
     * @param list<array<string, mixed>> $orderPayments
     * @return array{ok: bool, detail: string, checks: array<string, bool>}
     */
    private static function evaluateCashfreeExpiryCron404AsUnpaid(
        int $orderId,
        string $orderNumber,
        array $orderPayments
    ): array {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $checks = [
            'cashfree_attempts_all_failed' => self::allCashfreePaymentAttemptsFailed($orderPayments),
            'no_gateway_references_on_payments' => self::orderPaymentsLackGatewayReferences($orderPayments),
            'no_captured_payment_row' => !CheckoutIdempotencyService::orderHasCapturedPayment($pdo, $orderId),
            'no_open_completion_exception' => !self::orderHasOpenPaymentCompletionException($orderId, $pdo),
            'no_cashfree_success_webhook' => !self::cashfreeOrderHasCapturedSuccessWebhookEvidence($pdo, $orderNumber),
        ];
        $failedChecks = array_keys(array_filter($checks, static fn (bool $v): bool => !$v));
        $ok = $failedChecks === [];
        $detail = $ok
            ? 'Expiry cron: Cashfree HTTP 404 for merchant order with failed checkout initiation (no gateway order at PG). '
                . 'All safety checks passed — treating as unpaid for payment-timeout cancellation.'
            : 'Expiry cron: Cashfree HTTP 404 but not treated as unpaid; failed checks: ' . implode(', ', $failedChecks) . '.';

        return ['ok' => $ok, 'detail' => $detail, 'checks' => $checks];
    }

    /** @param list<array<string, mixed>> $orderPayments */
    private static function allCashfreePaymentAttemptsFailed(array $orderPayments): bool
    {
        $sawCashfree = false;
        foreach ($orderPayments as $row) {
            if ((string) ($row['gateway'] ?? '') !== 'cashfree') {
                continue;
            }
            $sawCashfree = true;
            if ((string) ($row['status'] ?? '') !== 'failed') {
                return false;
            }
        }

        return $sawCashfree;
    }

    /** @param list<array<string, mixed>> $orderPayments */
    private static function orderPaymentsLackGatewayReferences(array $orderPayments): bool
    {
        foreach ($orderPayments as $row) {
            if (trim((string) ($row['gateway_order_id'] ?? '')) !== '') {
                return false;
            }
            if (trim((string) ($row['gateway_payment_id'] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function orderHasOpenPaymentCompletionException(int $orderId, PDO $pdo): bool
    {
        try {
            $n = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_completion_exceptions'"
            )->fetchColumn();
            if ($n === 0) {
                return false;
            }
            $st = $pdo->prepare("SELECT COUNT(*) FROM payment_completion_exceptions WHERE order_id = ? AND status = 'open'");
            $st->execute([$orderId]);

            return (int) $st->fetchColumn() > 0;
        } catch (Throwable $e) {
            return true;
        }
    }

    private static function cashfreeOrderHasCapturedSuccessWebhookEvidence(PDO $pdo, string $orderNumber): bool
    {
        $orderNumber = trim($orderNumber);
        if ($orderNumber === '') {
            return false;
        }
        try {
            $n = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_webhook_events'"
            )->fetchColumn();
            if ($n === 0) {
                return false;
            }
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM payment_webhook_events
                 WHERE gateway = 'cashfree'
                   AND (
                     LOWER(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.type'))) IN (
                       'payment_success_webhook', 'payment_charges_webhook'
                     )
                     OR UPPER(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.data.payment.payment_status'))) = 'SUCCESS'
                   )
                   AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.data.order.order_id')) = ?"
            );
            $st->execute([$orderNumber]);

            return (int) $st->fetchColumn() > 0;
        } catch (Throwable $e) {
            return true;
        }
    }

    private static function backfillCashfreeGatewayOrderIds(int $orderId, string $cfOrderId): void
    {
        $cfOrderId = trim($cfOrderId);
        if ($orderId <= 0 || $cfOrderId === '') {
            return;
        }
        Install::ensureConnection();
        Database::pdo()->prepare(
            "UPDATE payments SET gateway_order_id = ?, updated_at = NOW()
             WHERE order_id = ? AND gateway = 'cashfree' AND (gateway_order_id IS NULL OR gateway_order_id = '')"
        )->execute([$cfOrderId, $orderId]);
    }

    /**
     * @param list<array<string, mixed>> $orderPayments
     */
    private static function cashfreeGatewayOrderIdMatches(array $orderPayments, string $cfOrderId): bool
    {
        if ($cfOrderId === '') {
            return true;
        }
        foreach ($orderPayments as $payment) {
            if ((string) ($payment['gateway'] ?? '') !== 'cashfree') {
                continue;
            }
            $stored = trim((string) ($payment['gateway_order_id'] ?? ''));
            if ($stored !== '' && $stored !== $cfOrderId) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $orderPayments
     * @param array<string, mixed> $successPayment
     * @param array<string, mixed>|null $openCashfree
     */
    private static function resolveIdempotencyKeyForCashfreeCapture(
        array $orderPayments,
        array $successPayment,
        ?array $openCashfree
    ): string {
        $cfPaymentId = (string) ($successPayment['cf_payment_id'] ?? $successPayment['payment_id'] ?? '');
        if ($cfPaymentId !== '') {
            foreach ($orderPayments as $row) {
                if ((string) ($row['gateway_payment_id'] ?? '') === $cfPaymentId) {
                    return (string) ($row['idempotency_key'] ?? '');
                }
            }
        }
        if ($openCashfree !== null && (string) ($openCashfree['idempotency_key'] ?? '') !== '') {
            return (string) $openCashfree['idempotency_key'];
        }
        for ($i = count($orderPayments) - 1; $i >= 0; $i--) {
            $row = $orderPayments[$i];
            if ((string) ($row['gateway'] ?? '') !== 'cashfree') {
                continue;
            }
            if (in_array((string) ($row['status'] ?? ''), ['created', 'pending'], true)) {
                return (string) ($row['idempotency_key'] ?? '');
            }
        }
        for ($i = count($orderPayments) - 1; $i >= 0; $i--) {
            if ((string) ($orderPayments[$i]['gateway'] ?? '') === 'cashfree') {
                return (string) ($orderPayments[$i]['idempotency_key'] ?? '');
            }
        }

        return '';
    }

    /**
     * @param list<array<string, mixed>> $orderPayments
     * @param array<string, mixed>|null $openCashfree
     */
    private static function resolveIdempotencyKeyForCashfreeFailure(array $orderPayments, ?array $openCashfree): string
    {
        if ($openCashfree !== null && (string) ($openCashfree['idempotency_key'] ?? '') !== '') {
            return (string) $openCashfree['idempotency_key'];
        }
        for ($i = count($orderPayments) - 1; $i >= 0; $i--) {
            $row = $orderPayments[$i];
            if ((string) ($row['gateway'] ?? '') === 'cashfree'
                && in_array((string) ($row['status'] ?? ''), ['created', 'pending'], true)) {
                return (string) ($row['idempotency_key'] ?? '');
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed>|null $paymentBefore
     * @param array<string, mixed>|null $paymentAfter
     */
    public static function clearStorefrontCheckoutSession(string $orderNumber): void
    {
        self::clearCheckoutPaymentSession($orderNumber);
        if (PHP_SAPI !== 'cli' && isset($_SESSION) && is_array($_SESSION)) {
            unset($_SESSION['checkout_payment_autolaunch'][$orderNumber]);
        }
    }

    private static function clearCheckoutPaymentSession(string $orderNumber): void
    {
        if (PHP_SAPI === 'cli' || !isset($_SESSION) || !is_array($_SESSION)) {
            return;
        }
        unset($_SESSION['checkout_payment'][$orderNumber]);
    }

    /**
     * Gateway captured funds but order fulfilment did not complete (open exception or reconciliation_required payment).
     *
     * @return array{
     *     customer_message: string,
     *     idempotency_key: string,
     *     gateway: string,
     *     failure_reason?: string,
     *     failure_category?: string
     * }|null
     */
    public static function storefrontFulfilmentReconciliationContext(int $orderId): ?array
    {
        if ($orderId <= 0 || !Install::isSchemaReady()) {
            return null;
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $payStmt = $pdo->prepare(
            "SELECT * FROM payments WHERE order_id = ? AND status = 'reconciliation_required' ORDER BY id DESC LIMIT 1"
        );
        $payStmt->execute([$orderId]);
        $payRow = $payStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $exception = PaymentCompletionExceptionService::openForOrderId($orderId);
        if ($payRow === null && $exception === null) {
            return null;
        }
        $idempotency = '';
        if ($payRow !== null) {
            $idempotency = (string) ($payRow['idempotency_key'] ?? '');
        }
        if ($idempotency === '' && $exception !== null && isset($exception['payment_id'])) {
            $pid = (int) $exception['payment_id'];
            if ($pid > 0) {
                $p2 = $pdo->prepare('SELECT idempotency_key FROM payments WHERE id = ? LIMIT 1');
                $p2->execute([$pid]);
                $idempotency = (string) ($p2->fetchColumn() ?: '');
            }
        }
        $gateway = (string) ($payRow['gateway'] ?? $exception['gateway'] ?? 'cashfree');

        $category = $exception !== null ? (string) ($exception['failure_category'] ?? '') : '';
        $customerMessage = 'Payment received — order confirmation pending. Our team is completing your order; you will not be charged again.';
        if ($category === PaymentCompletionExceptionService::CATEGORY_DELIVERY) {
            $customerMessage = 'Payment received — we are arranging your delivery slot. You will not be charged again.';
        }

        return [
            'customer_message' => $customerMessage,
            'idempotency_key' => $idempotency,
            'gateway' => $gateway,
            'failure_reason' => $exception !== null ? (string) ($exception['failure_reason'] ?? '') : '',
            'failure_category' => $category,
        ];
    }

    public static function paymentAttemptStatusLabel(string $status): string
    {
        return match ($status) {
            'captured' => 'Captured',
            'reconciliation_required' => 'Payment captured — reconciliation required',
            'created', 'pending', 'authorized' => 'Pending',
            'failed' => 'Failed',
            'refunded' => 'Refunded',
            default => $status !== '' ? ucwords(str_replace('_', ' ', $status)) : '—',
        };
    }

    /**
     * @param array<string, mixed>|null $paymentBefore
     * @param array<string, mixed>|null $paymentAfter
     */
    private static function recordCashfreeReconciliationAudit(
        int $orderId,
        string $orderNumber,
        string $source,
        string $oldOrderPaymentStatus,
        string $newOrderPaymentStatus,
        ?array $paymentBefore,
        ?array $paymentAfter,
        string $outcomeState,
        ?string $gatewayOrderId,
        ?string $gatewayPaymentId,
        ?string $detailMessage,
        ?int $actingAdminUserId,
        ?array $extraPayload = null
    ): void {
        $payload = [
            'order_number' => $orderNumber,
            'source' => $source,
            'outcome' => $outcomeState,
            'old_order_payment_status' => $oldOrderPaymentStatus,
            'new_order_payment_status' => $newOrderPaymentStatus,
            'old_payment_row_status' => $paymentBefore !== null ? (string) ($paymentBefore['status'] ?? '') : null,
            'new_payment_row_status' => $paymentAfter !== null ? (string) ($paymentAfter['status'] ?? '') : null,
            'gateway_order_id' => $gatewayOrderId,
            'gateway_payment_id' => $gatewayPaymentId,
            'detail' => $detailMessage,
            'reconciled_at' => date('c'),
        ];
        if ($extraPayload !== null && $extraPayload !== []) {
            $payload = array_merge($payload, $extraPayload);
        }
        AuditLog::write('payment.cashfree_reconcile', 'orders', (string) $orderId, $payload, $actingAdminUserId);

        if ($oldOrderPaymentStatus !== $newOrderPaymentStatus && $detailMessage !== null && $detailMessage !== '') {
            try {
                Install::ensureConnection();
                Database::pdo()->prepare(
                    'INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)'
                )->execute([
                    $orderId,
                    'Payment reconciliation',
                    ucfirst(str_replace('_', ' ', $source)) . ': ' . $oldOrderPaymentStatus . ' → ' . $newOrderPaymentStatus . '. ' . $detailMessage,
                ]);
            } catch (Throwable $e) {
                error_log('Payment reconciliation history: ' . $e->getMessage());
            }
        }
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
        $exec = PaymentGatewayConfig::assertPaymentExecutionAllowed($gateway);
        if (!$exec['ok']) {
            return ['ok' => false, 'message' => (string) ($exec['message'] ?? 'Payments are not available.')];
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
                $statusBeforePayment = (string) ($order['status'] ?? '');
                if ((string) $order['payment_status'] === 'paid') {
                    return [
                        'ok' => true,
                        'idempotent' => true,
                        'order_id' => $orderId,
                        'customer_id' => (int) $order['customer_id'],
                    ];
                }
                if ((string) ($order['status'] ?? '') === OrderStatus::CANCELLED) {
                    throw new RuntimeException(
                        '[reconciliation] Payment captured at gateway but order is already cancelled.'
                    );
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
                    $check = CartQuantityRules::validateForVariantId($vid, $qty);
                    if (!$check['ok']) {
                        throw new RuntimeException(
                            '[inventory] ' . ($check['message'] ?? 'Insufficient stock') . ' for ' . ($it['product_name'] ?? 'item')
                        );
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
                    'payment_environment' => PaymentGatewayConfig::recordedPaymentEnvironment($gateway),
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
                    'status_from' => $statusBeforePayment,
                    'status_to' => OrderStatus::CONFIRMED,
                ];
            });

            if (!empty($orderIdOut['order_id'])) {
                $oid = (int) $orderIdOut['order_id'];
                $custId = (int) ($orderIdOut['customer_id'] ?? 0);
                if (empty($orderIdOut['idempotent'])) {
                    DeliveryAssignmentService::createForOrder($oid);
                    NotificationDispatcher::orderPaymentSuccess($oid);
                    $from = (string) ($orderIdOut['status_from'] ?? '');
                    $to = (string) ($orderIdOut['status_to'] ?? '');
                    if ($to !== '') {
                        NotificationDispatcher::orderStatusChanged($oid, $from, $to);
                    }
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
            $markOrderFailed = false;
            Database::transaction(static function () use ($orderNumber, $idempotencyKey, $note, &$markOrderFailed): void {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare('SELECT id, status, payment_status FROM orders WHERE order_number = ? FOR UPDATE');
                $stmt->execute([$orderNumber]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$order || (string) $order['payment_status'] === 'paid') {
                    return;
                }
                $orderId = (int) $order['id'];
                $orderStatus = (string) ($order['status'] ?? '');
                $pdo->prepare('UPDATE payments SET status = ? WHERE order_id = ? AND idempotency_key = ?')
                    ->execute(['failed', $orderId, $idempotencyKey]);
                $openStmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM payments WHERE order_id = ? AND status IN ('created', 'pending')
                     AND idempotency_key <> ?"
                );
                $openStmt->execute([$orderId, $idempotencyKey]);
                $otherOpen = (int) $openStmt->fetchColumn();
                $awaitingCheckout = $orderStatus === OrderStatus::PENDING_PAYMENT;
                if ($otherOpen === 0 && $awaitingCheckout) {
                    $pdo->prepare('UPDATE orders SET payment_status = ?, updated_at = NOW() WHERE id = ?')
                        ->execute(['failed', $orderId]);
                    $pdo->prepare('INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)')
                        ->execute([$orderId, 'Payment attempt failed', $note]);
                } elseif ($otherOpen === 0) {
                    $pdo->prepare('UPDATE orders SET status = ?, payment_status = ?, updated_at = NOW() WHERE id = ?')
                        ->execute(['payment_failed', 'failed', $orderId]);
                    $pdo->prepare('INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)')
                        ->execute([$orderId, 'Payment failed', $note]);
                    DeliverySlotReservationService::releaseForOrder($orderId, $pdo);
                    $markOrderFailed = true;
                } else {
                    $pdo->prepare('INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)')
                        ->execute([$orderId, 'Payment attempt failed', $note]);
                }
            });

            if ($markOrderFailed) {
                NotificationDispatcher::orderPaymentFailed($orderNumber);
            }

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
