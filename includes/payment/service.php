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

        $idempotency = self::nextIdempotencyKey($orderNumber);
        $pdo = Database::pdo();
        $envMeta = json_encode(
            ['payment_environment' => PaymentGatewayConfig::recordedPaymentEnvironment($gateway)],
            JSON_UNESCAPED_UNICODE
        );
        $pdo->prepare(
            'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$orderId, $gateway, round($amountInr, 2), 'INR', 'created', $idempotency, $envMeta]);
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

    public static function customerCanRetryStorefrontPayment(array $orderRow): bool
    {
        if ((string) ($orderRow['payment_status'] ?? '') === 'paid') {
            return false;
        }
        $status = (string) ($orderRow['status'] ?? '');
        if (in_array($status, ['cancelled', 'confirmed', 'delivered', 'completed'], true)) {
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

        $orderFetch = CashfreeGateway::fetchOrder($orderNumber);
        if (!$orderFetch['ok']) {
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
                $orderFetch['error'] ?? 'Could not verify payment status.',
                $actingAdminUserId
            );

            return ['ok' => false, 'state' => 'error', 'message' => $orderFetch['error'] ?? 'Could not verify payment status.'];
        }

        $cfOrderId = trim((string) ($orderFetch['cf_order_id'] ?? ''));
        if (!self::cashfreeGatewayOrderIdMatches($orderPayments, $cfOrderId)) {
            return ['ok' => false, 'state' => 'error', 'message' => 'Gateway order reference mismatch.'];
        }

        $paymentsFetch = CashfreeGateway::fetchPayments($orderNumber);
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
    private static function clearCheckoutPaymentSession(string $orderNumber): void
    {
        if (PHP_SAPI === 'cli' || !isset($_SESSION) || !is_array($_SESSION)) {
            return;
        }
        unset($_SESSION['checkout_payment'][$orderNumber]);
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
        ?int $actingAdminUserId
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
                $stmt = $pdo->prepare('SELECT id, payment_status FROM orders WHERE order_number = ? FOR UPDATE');
                $stmt->execute([$orderNumber]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$order || (string) $order['payment_status'] === 'paid') {
                    return;
                }
                $orderId = (int) $order['id'];
                $pdo->prepare('UPDATE payments SET status = ? WHERE order_id = ? AND idempotency_key = ?')
                    ->execute(['failed', $orderId, $idempotencyKey]);
                $openStmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM payments WHERE order_id = ? AND status IN ('created', 'pending')
                     AND idempotency_key <> ?"
                );
                $openStmt->execute([$orderId, $idempotencyKey]);
                $otherOpen = (int) $openStmt->fetchColumn();
                if ($otherOpen === 0) {
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
