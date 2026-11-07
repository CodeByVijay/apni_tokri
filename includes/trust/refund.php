<?php

declare(strict_types=1);

final class RefundRequestService
{
    /** @return array<string, string> */
    public static function reasonLabels(): array
    {
        return [
            'quality' => 'Quality issue',
            'damaged' => 'Damaged product',
            'wrong' => 'Wrong product',
            'missing' => 'Missing item',
            'not_delivered' => 'Order not delivered',
            'duplicate_payment' => 'Duplicate payment',
            'cancellation' => 'Cancellation',
            'other' => 'Other',
        ];
    }

    /** @return list<string> */
    public static function resolutionOptions(): array
    {
        $opts = ['refund'];
        if ((AppSettings::get('refunds.allow_replacement', '1') ?? '1') === '1') {
            $opts[] = 'replacement';
        }
        if ((AppSettings::get('refunds.allow_store_credit', '0') ?? '0') === '1') {
            $opts[] = 'store_credit';
        }

        return $opts;
    }

    public static function nextRequestNumber(): string
    {
        Install::ensureConnection();
        $n = (int) Database::pdo()->query('SELECT COUNT(*) FROM refund_requests')->fetchColumn();

        return 'RF-' . date('ymd') . '-' . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param list<int> $orderItemIds
     * @param array<int, int> $quantities item_id => qty
     * @param array<string, mixed>|null $evidenceFiles
     * @return array{ok: bool, message?: string, request_number?: string}
     */
    public static function create(
        int $customerId,
        string $orderNumber,
        string $reasonCode,
        string $description,
        string $preferredResolution,
        array $orderItemIds,
        array $quantities,
        ?array $evidenceFiles = null
    ): array {
        $labels = self::reasonLabels();
        if (!isset($labels[$reasonCode])) {
            return ['ok' => false, 'message' => 'Choose a valid reason.'];
        }
        if (!in_array($preferredResolution, self::resolutionOptions(), true)) {
            return ['ok' => false, 'message' => 'Choose a valid resolution option.'];
        }
        $order = OrderFulfillmentService::orderRowForCustomer($orderNumber, $customerId);
        if ($order === null) {
            return ['ok' => false, 'message' => 'Order not found.'];
        }
        if ((string) $order['payment_status'] !== 'paid') {
            return ['ok' => false, 'message' => 'This order is not eligible for refund.'];
        }
        if ($orderItemIds === []) {
            return ['ok' => false, 'message' => 'Select at least one item.'];
        }

        try {
            return Database::transaction(static function () use (
                $customerId,
                $order,
                $reasonCode,
                $description,
                $preferredResolution,
                $orderItemIds,
                $quantities,
                $evidenceFiles
            ): array {
                $pdo = Database::pdo();
                $orderId = (int) $order['id'];
                $openStmt = $pdo->prepare(
                    "SELECT id FROM refund_requests WHERE order_id = ? AND status NOT IN ('rejected', 'cancelled', 'refunded', 'refund_failed') LIMIT 1"
                );
                $openStmt->execute([$orderId]);
                if ($openStmt->fetchColumn()) {
                    return ['ok' => false, 'message' => 'You already have an open refund request for this order.'];
                }
                $requestNumber = self::nextRequestNumber();
                $lineAmount = 0.0;
                $lines = [];
                foreach ($orderItemIds as $itemId) {
                    $itemId = (int) $itemId;
                    $stmt = $pdo->prepare(
                        'SELECT * FROM order_items WHERE id = ? AND order_id = ? LIMIT 1'
                    );
                    $stmt->execute([$itemId, $orderId]);
                    $item = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$item) {
                        return ['ok' => false, 'message' => 'Invalid order item.'];
                    }
                    $maxQty = (int) $item['quantity'];
                    $qty = (int) ($quantities[$itemId] ?? $maxQty);
                    if ($qty < 1 || $qty > $maxQty) {
                        return ['ok' => false, 'message' => 'Invalid quantity for ' . $item['product_name']];
                    }
                    $unit = (float) $item['unit_price'];
                    $amt = round($unit * $qty, 2);
                    $lineAmount += $amt;
                    $lines[] = ['order_item_id' => $itemId, 'quantity' => $qty, 'amount_inr' => $amt];
                }
                $captured = self::capturedAmountForOrder($orderId);
                $pending = self::pendingRefundTotalForOrder($orderId);
                if ($lineAmount + $pending > $captured + 0.01) {
                    return ['ok' => false, 'message' => 'Requested amount exceeds refundable balance.'];
                }

                $pdo->prepare(
                    'INSERT INTO refund_requests
                     (request_number, customer_id, order_id, reason_code, description, preferred_resolution, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $requestNumber,
                    $customerId,
                    $orderId,
                    $reasonCode,
                    trim($description),
                    $preferredResolution,
                    RefundStatus::REQUESTED,
                ]);
                $reqId = (int) $pdo->lastInsertId();
                foreach ($lines as $line) {
                    $pdo->prepare(
                        'INSERT INTO refund_request_items (refund_request_id, order_item_id, quantity, amount_inr)
                         VALUES (?, ?, ?, ?)'
                    )->execute([$reqId, $line['order_item_id'], $line['quantity'], $line['amount_inr']]);
                }
                self::appendHistory($pdo, $reqId, 'customer', $customerId, 'requested', [
                    'amount' => $lineAmount,
                    'reason' => $reasonCode,
                ]);
                if (is_array($evidenceFiles) && isset($evidenceFiles['name']) && is_array($evidenceFiles['name'])) {
                    $n = count($evidenceFiles['name']);
                    for ($i = 0; $i < $n && $i < 5; $i++) {
                        $file = [
                            'name' => $evidenceFiles['name'][$i] ?? '',
                            'type' => $evidenceFiles['type'][$i] ?? '',
                            'tmp_name' => $evidenceFiles['tmp_name'][$i] ?? '',
                            'error' => $evidenceFiles['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                            'size' => $evidenceFiles['size'][$i] ?? 0,
                        ];
                        if ((int) $file['error'] === UPLOAD_ERR_NO_FILE) {
                            continue;
                        }
                        $stored = Upload::storeImage($file, 'refunds');
                        if ($stored['ok'] && !empty($stored['path'])) {
                            $pdo->prepare(
                                'INSERT INTO refund_request_evidence (refund_request_id, file_path, mime_type) VALUES (?, ?, ?)'
                            )->execute([$reqId, (string) $stored['path'], (string) ($file['type'] ?? '')]);
                        }
                    }
                }

                return ['ok' => true, 'request_number' => $requestNumber];
            });
        } catch (Throwable $e) {
            error_log('Refund create: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not submit refund request.'];
        }
    }

    public static function capturedAmountForOrder(int $orderId): float
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            "SELECT COALESCE(SUM(amount - amount_refunded_inr), 0) FROM payments
             WHERE order_id = ? AND status = 'captured'"
        );
        $stmt->execute([$orderId]);

        return (float) $stmt->fetchColumn();
    }

    public static function pendingRefundTotalForOrder(int $orderId): float
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            "SELECT COALESCE(SUM(
                CASE WHEN rr.approved_amount_inr IS NOT NULL AND rr.approved_amount_inr > 0
                     THEN rr.approved_amount_inr ELSE ri.amount_inr END
             ), 0)
             FROM refund_requests rr
             INNER JOIN refund_request_items ri ON ri.refund_request_id = rr.id
             WHERE rr.order_id = ? AND rr.status NOT IN ('rejected', 'cancelled', 'refunded', 'refund_failed')"
        );
        $stmt->execute([$orderId]);

        return (float) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public static function listForAdmin(?string $status = null, int $limit = 100): array
    {
        $result = self::searchForAdmin('', $status, 1, min(100, max(1, $limit)), 'created_at', 'desc');

        return $result['rows'];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public static function searchForAdmin(
        string $query,
        ?string $status,
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
        if ($status !== null && $status !== '') {
            $where[] = 'rr.status = ?';
            $params[] = $status;
        }
        $q = trim($query);
        if ($q !== '') {
            $like = '%' . AdminServerList::escapeLike($q) . '%';
            $where[] = '(rr.request_number LIKE ? OR o.order_number LIKE ? OR c.mobile LIKE ? OR rr.status LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);
        $sortMap = [
            'created_at' => 'rr.created_at',
            'status' => 'rr.status',
            'request_number' => 'rr.request_number',
            'approved_amount' => 'rr.approved_amount_inr',
        ];
        $orderSql = AdminServerList::orderBySql($sortColumn, $sortDirection, $sortMap, 'created_at');
        $from = ' FROM refund_requests rr INNER JOIN orders o ON o.id = rr.order_id INNER JOIN customers c ON c.id = rr.customer_id WHERE ' . $whereSql;
        $countStmt = $pdo->prepare('SELECT COUNT(*)' . $from);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $sql = 'SELECT rr.*, o.order_number, c.mobile AS customer_mobile' . $from . ' ORDER BY ' . $orderSql . ' LIMIT ? OFFSET ?';
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

    /** @return array<string, mixed>|null */
    public static function byNumber(string $requestNumber): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM refund_requests WHERE request_number = ? LIMIT 1');
        $stmt->execute([$requestNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array{ok: bool, message?: string} */
    public static function setUnderReview(int $requestId): array
    {
        return self::transition($requestId, RefundStatus::UNDER_REVIEW, 'under_review');
    }

    public static function availableApprovalAmountForRequest(int $requestId): float
    {
        if ($requestId <= 0 || !Install::isSchemaReady()) {
            return 0.0;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT order_id, approved_amount_inr FROM refund_requests WHERE id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            return 0.0;
        }
        $orderId = (int) $req['order_id'];
        $refundable = self::capturedAmountForOrder($orderId);
        $alreadyRefunded = self::completedRefundTotalForOrder($orderId);
        $otherPending = self::pendingRefundTotalForOrder($orderId) - (float) ($req['approved_amount_inr'] ?? 0);
        $available = $refundable - $alreadyRefunded - $otherPending;

        return round(max(0.0, $available), 2);
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    private static function validateApprovalAmount(array $req, float $amountInr): array
    {
        if ($amountInr <= 0) {
            return ['ok' => false, 'message' => 'Approve a positive amount.'];
        }
        $orderId = (int) $req['order_id'];
        $refundable = self::capturedAmountForOrder($orderId);
        $alreadyRefunded = self::completedRefundTotalForOrder($orderId);
        $otherApproved = self::pendingRefundTotalForOrder($orderId) - (float) ($req['approved_amount_inr'] ?? 0);
        if ($amountInr + $alreadyRefunded + $otherApproved > $refundable + 0.01) {
            return ['ok' => false, 'message' => 'Amount exceeds refundable balance.'];
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, message?: string, approved_amount?: float}
     */
    private static function persistApproval(
        PDO $pdo,
        int $requestId,
        array $req,
        float $amountInr,
        ?string $internalNote
    ): array {
        $status = (string) $req['status'];
        if (!in_array($status, [RefundStatus::REQUESTED, RefundStatus::UNDER_REVIEW], true)) {
            return ['ok' => false, 'message' => 'Request cannot be approved in this status.'];
        }
        $check = self::validateApprovalAmount($req, $amountInr);
        if (empty($check['ok'])) {
            return $check;
        }
        $rounded = round($amountInr, 2);
        $pdo->prepare(
            'UPDATE refund_requests SET status = ?, approved_amount_inr = ?, internal_notes = CONCAT(COALESCE(internal_notes,\'\'), ?), updated_at = NOW() WHERE id = ?'
        )->execute([
            RefundStatus::APPROVED,
            $rounded,
            $internalNote !== null && trim($internalNote) !== '' ? "\n" . trim($internalNote) : '',
            $requestId,
        ]);
        self::appendHistory($pdo, $requestId, 'admin', AdminAuth::id(), 'approved', ['amount' => $rounded]);
        AuditLog::write('refund.approve', 'refund_request', (string) $requestId, ['amount' => $rounded]);

        return ['ok' => true, 'approved_amount' => $rounded];
    }

    /** @return array{ok: bool, message?: string} */
    public static function approve(int $requestId, float $amountInr, ?string $internalNote = null): array
    {
        try {
            return Database::transaction(static function () use ($requestId, $amountInr, $internalNote): array {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare('SELECT * FROM refund_requests WHERE id = ? FOR UPDATE');
                $stmt->execute([$requestId]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$req) {
                    return ['ok' => false, 'message' => 'Request not found.'];
                }

                return self::persistApproval($pdo, $requestId, $req, $amountInr, $internalNote);
            });
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not approve request.'];
        }
    }

    /**
     * Approve and immediately run gateway refund (single transaction; approval kept on gateway failure).
     *
     * @return array{ok: bool, message?: string, gateway_refund_id?: string|null, approved_saved?: bool, status?: string}
     */
    public static function approveAndProcessGatewayRefund(int $requestId, float $amountInr, ?string $internalNote = null): array
    {
        try {
            return Database::transaction(static function () use ($requestId, $amountInr, $internalNote): array {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare('SELECT * FROM refund_requests WHERE id = ? FOR UPDATE');
                $stmt->execute([$requestId]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$req) {
                    return ['ok' => false, 'message' => 'Request not found.'];
                }
                $approve = self::persistApproval($pdo, $requestId, $req, $amountInr, $internalNote);
                if (empty($approve['ok'])) {
                    return $approve;
                }
                $stmt->execute([$requestId]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC) ?: $req;
                $gw = self::runGatewayRefundForApprovedRequest($pdo, $req);
                if (empty($gw['ok'])) {
                    return [
                        'ok' => false,
                        'approved_saved' => true,
                        'message' => 'Refund approval saved, but gateway processing failed. You can retry the refund below.',
                        'gateway_error' => (string) ($gw['message'] ?? ''),
                        'status' => RefundStatus::REFUND_FAILED,
                    ];
                }

                return [
                    'ok' => true,
                    'message' => (string) ($gw['message'] ?? 'Refund processed successfully.'),
                    'gateway_refund_id' => $gw['gateway_refund_id'] ?? null,
                    'status' => $gw['status'] ?? null,
                    'approved_saved' => true,
                ];
            });
        } catch (Throwable $e) {
            error_log('Approve process refund: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not process refund.'];
        }
    }

    /** @return array{ok: bool, message?: string} */
    public static function reject(int $requestId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'message' => 'Rejection reason is required.'];
        }

        return self::transition($requestId, RefundStatus::REJECTED, 'rejected', ['reason' => $reason], function ($pdo, $id) use ($reason): void {
            $pdo->prepare('UPDATE refund_requests SET rejection_reason = ? WHERE id = ?')->execute([$reason, $id]);
        });
    }

    /**
     * @param array<string, mixed> $req Locked row in approved (or immediately post-approve) state.
     * @return array{ok: bool, message?: string, gateway_refund_id?: string|null, status?: string}
     */
    private static function runGatewayRefundForApprovedRequest(PDO $pdo, array $req): array
    {
        $requestId = (int) $req['id'];
        if ((string) ($req['status'] ?? '') !== RefundStatus::APPROVED) {
            return ['ok' => false, 'message' => 'Refund must be approved before gateway initiation.'];
        }
        $amount = (float) ($req['approved_amount_inr'] ?? 0);
        if ($amount <= 0) {
            return ['ok' => false, 'message' => 'Missing approved amount.'];
        }
        $orderId = (int) $req['order_id'];
        $payment = PaymentRepository::latestPaymentForOrder($orderId);
        if ($payment === null || (string) ($payment['status'] ?? '') !== 'captured') {
            return ['ok' => false, 'message' => 'No captured payment for this order.'];
        }
        $idempotency = 'refund_req_' . $requestId;
        $attemptStmt = $pdo->prepare('SELECT COUNT(*) FROM payment_refunds WHERE refund_request_id = ?');
        $attemptStmt->execute([$requestId]);
        $attempts = (int) $attemptStmt->fetchColumn();
        if ($attempts > 0) {
            $idempotency = 'refund_req_' . $requestId . '_attempt_' . ($attempts + 1);
        }
        $inFlight = $pdo->prepare(
            "SELECT id FROM payment_refunds WHERE refund_request_id = ? AND gateway_status NOT IN ('failed') LIMIT 1"
        );
        $inFlight->execute([$requestId]);
        if ($inFlight->fetchColumn()) {
            return ['ok' => false, 'message' => 'Gateway refund already in progress.'];
        }

        $pdo->prepare(
            'UPDATE refund_requests SET status = ?, updated_at = NOW() WHERE id = ?'
        )->execute([RefundStatus::REFUND_INITIATED, $requestId]);

        $gwResult = GatewayRefundService::refundPayment((int) $payment['id'], $amount, $idempotency);
        if (!$gwResult['ok']) {
            $pdo->prepare('UPDATE refund_requests SET status = ?, updated_at = NOW() WHERE id = ?')
                ->execute([RefundStatus::REFUND_FAILED, $requestId]);
            self::appendHistory($pdo, $requestId, 'system', null, 'gateway_failed', [
                'message' => $gwResult['message'] ?? '',
            ]);

            return ['ok' => false, 'message' => $gwResult['message'] ?? 'Gateway refund failed.'];
        }

        $pdo->prepare(
            'INSERT INTO payment_refunds
             (refund_request_id, payment_id, gateway, amount_inr, idempotency_key, gateway_refund_id, gateway_status, response_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $requestId,
            (int) $payment['id'],
            (string) $payment['gateway'],
            $amount,
            $idempotency,
            $gwResult['gateway_refund_id'] ?? null,
            (string) ($gwResult['status'] ?? 'pending'),
            json_encode($gwResult, JSON_UNESCAPED_UNICODE),
        ]);

        $newStatus = RefundStatus::PROCESSING;
        if (!empty($gwResult['simulated'])) {
            RefundCompletionService::completeOrderRefund(
                (int) $pdo->lastInsertId(),
                'processed',
                $amount
            );
            $newStatus = RefundStatus::REFUNDED;
        } else {
            $pdo->prepare('UPDATE refund_requests SET status = ?, updated_at = NOW() WHERE id = ?')
                ->execute([RefundStatus::PROCESSING, $requestId]);
        }

        self::appendHistory($pdo, $requestId, 'admin', AdminAuth::id(), 'gateway_initiated', [
            'gateway_refund_id' => $gwResult['gateway_refund_id'] ?? null,
        ]);
        AuditLog::write('refund.gateway_init', 'refund_request', (string) $requestId);

        $message = $newStatus === RefundStatus::REFUNDED
            ? 'Refund processed successfully.'
            : 'Refund processed successfully. Completion will be confirmed via gateway webhook.';

        return [
            'ok' => true,
            'message' => $message,
            'gateway_refund_id' => $gwResult['gateway_refund_id'] ?? null,
            'status' => $newStatus,
        ];
    }

    /** @return array{ok: bool, message?: string} */
    public static function initiateGatewayRefund(int $requestId): array
    {
        try {
            return Database::transaction(static function () use ($requestId): array {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare('SELECT * FROM refund_requests WHERE id = ? FOR UPDATE');
                $stmt->execute([$requestId]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$req || (string) $req['status'] !== RefundStatus::APPROVED) {
                    return ['ok' => false, 'message' => 'Refund must be approved before gateway initiation.'];
                }

                return self::runGatewayRefundForApprovedRequest($pdo, $req);
            });
        } catch (Throwable $e) {
            error_log('Gateway refund: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not initiate gateway refund.'];
        }
    }

    /** @return array{ok: bool, message?: string} */
    public static function retryGatewayRefund(int $requestId): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM refund_requests WHERE id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req || (string) $req['status'] !== RefundStatus::REFUND_FAILED) {
            return ['ok' => false, 'message' => 'Only failed refunds can be retried.'];
        }
        $pdo->prepare(
            'UPDATE refund_requests SET status = ?, updated_at = NOW() WHERE id = ?'
        )->execute([RefundStatus::APPROVED, $requestId]);
        self::appendHistoryPublic($pdo, $requestId, 'admin', AdminAuth::id(), 'retry_requested', null);

        return self::initiateGatewayRefund($requestId);
    }

    /** @return array<string, mixed>|null */
    public static function getForCustomer(int $requestId, int $customerId): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT rr.*, o.order_number FROM refund_requests rr
             INNER JOIN orders o ON o.id = rr.order_id
             WHERE rr.id = ? AND rr.customer_id = ? LIMIT 1'
        );
        $stmt->execute([$requestId, $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::customerSafeRequest($row) : null;
    }

    /** @return array<string, mixed>|null */
    public static function byNumberForCustomer(string $requestNumber, int $customerId): ?array
    {
        $requestNumber = trim($requestNumber);
        if ($requestNumber === '') {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT rr.*, o.order_number FROM refund_requests rr
             INNER JOIN orders o ON o.id = rr.order_id
             WHERE rr.request_number = ? AND rr.customer_id = ? LIMIT 1'
        );
        $stmt->execute([$requestNumber, $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::customerSafeRequest($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listForCustomer(int $customerId, int $limit = 50): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT rr.*, o.order_number FROM refund_requests rr
             INNER JOIN orders o ON o.id = rr.order_id
             WHERE rr.customer_id = ?
             ORDER BY rr.updated_at DESC, rr.created_at DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $customerId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $safe = self::customerSafeRequest($row);
            $safe['display_amount_inr'] = self::displayAmountInr($row);
            $safe['gateway_status'] = self::latestGatewayStatusForRequest((int) $row['id']);
            $safe['subscription_number'] = self::subscriptionNumberForRequest((int) $row['id'], $customerId);
            $out[] = $safe;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listForOrderCustomer(int $orderId, int $customerId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT rr.*, o.order_number FROM refund_requests rr
             INNER JOIN orders o ON o.id = rr.order_id
             WHERE rr.order_id = ? AND rr.customer_id = ?
             ORDER BY rr.created_at DESC'
        );
        $stmt->execute([$orderId, $customerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::customerSafeRequest($row);
        }

        return $out;
    }

    public static function publicStatusLabel(string $status): string
    {
        switch ($status) {
            case RefundStatus::REQUESTED:
                return 'Refund requested';
            case RefundStatus::UNDER_REVIEW:
                return 'Under review';
            case RefundStatus::APPROVED:
                return 'Refund approved';
            case RefundStatus::REJECTED:
                return 'Refund rejected';
            case RefundStatus::REFUND_INITIATED:
                return 'Refund initiated';
            case RefundStatus::PROCESSING:
                return 'Refund processing';
            case RefundStatus::REFUNDED:
                return 'Refund completed';
            case RefundStatus::REFUND_FAILED:
                return 'Refund failed';
            case RefundStatus::CANCELLED:
                return 'Cancelled';
            default:
                return ucwords(str_replace('_', ' ', $status));
        }
    }

    public static function publicGatewayStatusLabel(?string $gatewayStatus): string
    {
        if ($gatewayStatus === null || trim($gatewayStatus) === '') {
            return 'Not started';
        }
        $s = strtolower(trim($gatewayStatus));
        if (RefundCompletionService::isTerminalSuccess($s)) {
            return 'Refund completed';
        }
        if ($s === 'failed') {
            return 'Refund failed';
        }
        if (in_array($s, ['created', 'pending'], true)) {
            return 'Processing';
        }

        return ucwords(str_replace('_', ' ', $s));
    }

    public static function displayAmountInr(array $requestRow): float
    {
        if (isset($requestRow['approved_amount_inr']) && $requestRow['approved_amount_inr'] !== null) {
            $approved = (float) $requestRow['approved_amount_inr'];
            if ($approved > 0) {
                return round($approved, 2);
            }
        }

        return self::sumLineItemsForRequest((int) ($requestRow['id'] ?? 0));
    }

    public static function sumLineItemsForRequest(int $requestId): float
    {
        if ($requestId <= 0) {
            return 0.0;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(amount_inr), 0) FROM refund_request_items WHERE refund_request_id = ?'
        );
        $stmt->execute([$requestId]);

        return round((float) $stmt->fetchColumn(), 2);
    }

    public static function latestGatewayStatusForRequest(int $requestId): ?string
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT gateway_status FROM payment_refunds WHERE refund_request_id = ? ORDER BY updated_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$requestId]);
        $val = $stmt->fetchColumn();

        return $val !== false ? (string) $val : null;
    }

    /**
     * @return list<array{at: string, label: string, detail: string|null}>
     */
    public static function customerTimeline(int $requestId, int $customerId): array
    {
        $req = self::getForCustomer($requestId, $customerId);
        if ($req === null) {
            return [];
        }

        $events = [];
        foreach (self::history($requestId) as $h) {
            $mapped = self::customerHistoryEvent($h, $req);
            if ($mapped !== null) {
                $events[] = $mapped;
            }
        }

        foreach (self::paymentRefundsForRequest($requestId, $customerId) as $pr) {
            $events[] = [
                'at' => (string) ($pr['created_at'] ?? ''),
                'label' => 'Payment refund initiated',
                'detail' => self::publicGatewayStatusLabel((string) ($pr['gateway_status'] ?? '')),
            ];
            $updated = (string) ($pr['updated_at'] ?? '');
            $created = (string) ($pr['created_at'] ?? '');
            if ($updated !== '' && $updated !== $created) {
                $events[] = [
                    'at' => $updated,
                    'label' => 'Payment refund update',
                    'detail' => self::publicGatewayStatusLabel((string) ($pr['gateway_status'] ?? '')),
                ];
            }
        }

        usort($events, static function (array $a, array $b): int {
            return strcmp($a['at'], $b['at']);
        });

        return $events;
    }

    /**
     * Customer-safe payment_refunds rows (no response_json or secrets).
     *
     * @return list<array<string, mixed>>
     */
    public static function paymentRefundsForRequest(int $requestId, int $customerId): array
    {
        if (self::getForCustomer($requestId, $customerId) === null) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT id, refund_request_id, gateway, amount_inr, gateway_status, created_at, updated_at
             FROM payment_refunds WHERE refund_request_id = ? ORDER BY created_at'
        );
        $stmt->execute([$requestId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array{subscription_number: string, subscription_payment_id: int}|null */
    public static function subscriptionContextForRequest(int $requestId, int $customerId): ?array
    {
        if (self::getForCustomer($requestId, $customerId) === null) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT cs.subscription_number, sp.id AS subscription_payment_id
             FROM payment_refunds pr
             INNER JOIN subscription_payments sp ON sp.id = pr.subscription_payment_id
             INNER JOIN customer_subscriptions cs ON cs.id = sp.customer_subscription_id
             WHERE pr.refund_request_id = ? AND cs.customer_id = ?
             LIMIT 1'
        );
        $stmt->execute([$requestId, $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'subscription_number' => (string) $row['subscription_number'],
            'subscription_payment_id' => (int) $row['subscription_payment_id'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function customerSafeRequest(array $row): array
    {
        unset($row['internal_notes']);

        return $row;
    }

    /**
     * @param array<string, mixed> $historyRow
     * @param array<string, mixed> $requestRow
     * @return array{at: string, label: string, detail: string|null}|null
     */
    private static function customerHistoryEvent(array $historyRow, array $requestRow): ?array
    {
        $action = (string) ($historyRow['action'] ?? '');
        $at = (string) ($historyRow['created_at'] ?? '');
        $payload = null;
        if (!empty($historyRow['payload_json'])) {
            $decoded = json_decode((string) $historyRow['payload_json'], true);
            $payload = is_array($decoded) ? $decoded : null;
        }

        $label = null;
        switch ($action) {
            case 'requested':
                $label = 'Refund requested';
                break;
            case 'under_review':
                $label = 'Under review';
                break;
            case 'approved':
                $label = 'Refund approved';
                break;
            case 'rejected':
                $label = 'Refund rejected';
                break;
            case 'gateway_initiated':
                $label = 'Refund processing';
                break;
            case 'gateway_failed':
                $label = 'Refund failed';
                break;
            case 'retry_requested':
                $label = 'Refund retry requested';
                break;
            case 'refunded':
                $label = 'Refund completed';
                break;
        }
        if ($label === null) {
            return null;
        }

        $detail = null;
        if ($action === 'approved' && is_array($payload) && isset($payload['amount'])) {
            $detail = 'Amount: ₹' . number_format((float) $payload['amount'], 2);
        }
        if ($action === 'rejected') {
            $reason = trim((string) ($requestRow['rejection_reason'] ?? ''));
            if ($reason !== '') {
                $detail = $reason;
            }
        }
        if ($action === 'requested' && is_array($payload) && isset($payload['amount'])) {
            $detail = 'Requested: ₹' . number_format((float) $payload['amount'], 2);
        }

        return ['at' => $at, 'label' => $label, 'detail' => $detail];
    }

    private static function subscriptionNumberForRequest(int $requestId, int $customerId): ?string
    {
        $ctx = self::subscriptionContextForRequest($requestId, $customerId);

        return $ctx !== null ? $ctx['subscription_number'] : null;
    }

    /**
     * Admin reconciliation dashboard rows.
     *
     * @return list<array<string, mixed>>
     */
    public static function listReconciliation(?string $bucket = null, int $limit = 200): array
    {
        Install::ensureConnection();
        $sql = 'SELECT pr.*, rr.request_number, rr.status AS request_status, o.order_number,
                       p.amount AS payment_amount, p.gateway_payment_id, p.amount_refunded_inr,
                       sp.id AS sub_payment_id, cs.subscription_number
                FROM payment_refunds pr
                LEFT JOIN refund_requests rr ON rr.id = pr.refund_request_id
                LEFT JOIN payments p ON p.id = pr.payment_id
                LEFT JOIN orders o ON o.id = p.order_id
                LEFT JOIN subscription_payments sp ON sp.id = pr.subscription_payment_id
                LEFT JOIN customer_subscriptions cs ON cs.id = sp.customer_subscription_id';
        $params = [];
        if ($bucket === 'pending') {
            $sql .= " WHERE pr.gateway_status IN ('created', 'pending')";
        } elseif ($bucket === 'processing') {
            $sql .= " WHERE pr.gateway_status NOT IN ('processed', 'completed', 'success', 'failed') AND pr.gateway_status NOT IN ('created', 'pending')";
        } elseif ($bucket === 'completed') {
            $sql .= " WHERE pr.gateway_status IN ('processed', 'completed', 'success')";
        } elseif ($bucket === 'failed') {
            $sql .= " WHERE pr.gateway_status = 'failed' OR rr.status = ?";
            $params[] = RefundStatus::REFUND_FAILED;
        }
        $sql .= ' ORDER BY pr.updated_at DESC LIMIT ?';
        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $i => $p) {
            $stmt->bindValue($i + 1, $p);
        }
        $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function completedRefundTotalForOrder(int $orderId): float
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            "SELECT COALESCE(SUM(amount_refunded_inr), 0) FROM payments WHERE order_id = ? AND status = 'captured'"
        );
        $stmt->execute([$orderId]);

        return (float) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public static function history(int $requestId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM refund_request_history WHERE refund_request_id = ? ORDER BY created_at'
        );
        $stmt->execute([$requestId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param callable(PDO, int): void|null $extra
     * @param array<string, mixed>|null $payload
     * @return array{ok: bool, message?: string}
     */
    private static function transition(
        int $requestId,
        string $newStatus,
        string $action,
        ?array $payload = null,
        ?callable $extra = null
    ): array {
        try {
            Database::transaction(static function () use ($requestId, $newStatus, $action, $payload, $extra): void {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare('SELECT id, status FROM refund_requests WHERE id = ? FOR UPDATE');
                $stmt->execute([$requestId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    throw new RuntimeException('Not found');
                }
                $pdo->prepare('UPDATE refund_requests SET status = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$newStatus, $requestId]);
                if ($extra !== null) {
                    $extra($pdo, $requestId);
                }
                self::appendHistory($pdo, $requestId, 'admin', AdminAuth::id(), $action, $payload);
            });

            NotificationDispatcher::refundStatusUpdated($requestId, $newStatus);

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not update request.'];
        }
    }

    /** @param array<string, mixed>|null $payload */
    public static function appendHistoryPublic(
        PDO $pdo,
        int $requestId,
        string $actorType,
        ?int $actorId,
        string $action,
        ?array $payload = null
    ): void {
        $pdo->prepare(
            'INSERT INTO refund_request_history (refund_request_id, actor_type, actor_id, action, payload_json)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $requestId,
            $actorType,
            $actorId,
            $action,
            $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /** @param array<string, mixed>|null $payload */
    private static function appendHistory(
        PDO $pdo,
        int $requestId,
        string $actorType,
        ?int $actorId,
        string $action,
        ?array $payload = null
    ): void {
        self::appendHistoryPublic($pdo, $requestId, $actorType, $actorId, $action, $payload);
    }

    /** @return array<string, mixed>|null */
    public static function latestPaymentRefundForRequest(int $requestId): ?array
    {
        if ($requestId <= 0 || !Install::isSchemaReady()) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT id, gateway_refund_id, gateway_status, amount_inr, created_at, updated_at
             FROM payment_refunds WHERE refund_request_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function findByIdForAdmin(int $requestId): ?array
    {
        if ($requestId <= 0 || !Install::isSchemaReady()) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT rr.*, o.order_number
             FROM refund_requests rr
             INNER JOIN orders o ON o.id = rr.order_id
             WHERE rr.id = ? LIMIT 1'
        );
        $stmt->execute([$requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Stored refund line items only (joined to order_items for product display).
     *
     * @return list<array{name: string, variant: string, qty: int, unit_price: float, line_total: float, image: string}>
     */
    public static function lineItemsForAdmin(int $requestId): array
    {
        if ($requestId <= 0 || !Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT ri.quantity, ri.amount_inr,
                    oi.product_name, oi.variant_name, oi.unit_price,
                    (SELECT pi.file_path FROM product_images pi
                     INNER JOIN product_variants pv ON pv.product_id = pi.product_id
                     WHERE pv.id = oi.variant_id
                     ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC LIMIT 1) AS image_path
             FROM refund_request_items ri
             INNER JOIN order_items oi ON oi.id = ri.order_item_id
             WHERE ri.refund_request_id = ?
             ORDER BY ri.id ASC'
        );
        $stmt->execute([$requestId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $imagePath = (string) ($it['image_path'] ?? '');
            $out[] = [
                'name' => (string) ($it['product_name'] ?? ''),
                'variant' => (string) ($it['variant_name'] ?? ''),
                'qty' => (int) ($it['quantity'] ?? 0),
                'unit_price' => (float) ($it['unit_price'] ?? 0),
                'line_total' => (float) ($it['amount_inr'] ?? 0),
                'image' => $imagePath !== '' ? asset_url($imagePath) : '',
            ];
        }

        return $out;
    }
}
