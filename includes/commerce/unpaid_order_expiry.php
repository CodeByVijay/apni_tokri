<?php

declare(strict_types=1);

/**
 * Payment window for unpaid storefront orders (default 15 minutes, fixed at placement).
 */
final class UnpaidOrderExpiryService
{
    public const SETTING_MINUTES = 'commerce.unpaid_order_payment_minutes';
    public const DEFAULT_MINUTES = 15;
    public const REASON_CODE = 'payment_timeout';
    public const CRON_LOCK = 'apni_tokri_expire_unpaid_orders';

    public static function columnReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();

            return (int) Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'payment_expires_at'"
            )->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function windowMinutes(): int
    {
        $raw = AppSettings::get(self::SETTING_MINUTES, (string) self::DEFAULT_MINUTES) ?? (string) self::DEFAULT_MINUTES;
        $n = (int) $raw;

        return max(5, min(120, $n > 0 ? $n : self::DEFAULT_MINUTES));
    }

    public static function computeExpiryFromPlacement(?string $placedAt): string
    {
        $base = DeliveryConfig::now();
        if ($placedAt !== null && trim($placedAt) !== '') {
            try {
                $base = new DateTimeImmutable($placedAt);
            } catch (Throwable $e) {
                $base = DeliveryConfig::now();
            }
        }

        return $base->modify('+' . self::windowMinutes() . ' minutes')->format('Y-m-d H:i:s');
    }

    public static function stampExpiryOnOrder(PDO $pdo, int $orderId, ?string $placedAt = null): void
    {
        if (!self::columnReady() || $orderId <= 0) {
            return;
        }
        if ($placedAt === null) {
            $placedAt = (string) ($pdo->query('SELECT COALESCE(placed_at, created_at) FROM orders WHERE id = ' . (int) $orderId)->fetchColumn() ?: '');
        }
        $exp = self::computeExpiryFromPlacement($placedAt);
        $pdo->prepare('UPDATE orders SET payment_expires_at = ? WHERE id = ? AND payment_expires_at IS NULL')
            ->execute([$exp, $orderId]);
    }

    public static function isPaymentWindowOpen(?array $orderRow): bool
    {
        if ($orderRow === null || !self::columnReady()) {
            return true;
        }
        if ((string) ($orderRow['payment_status'] ?? '') === 'paid') {
            return false;
        }
        $exp = (string) ($orderRow['payment_expires_at'] ?? '');
        if ($exp === '') {
            return true;
        }
        try {
            return new DateTimeImmutable($exp) > DeliveryConfig::now();
        } catch (Throwable $e) {
            return true;
        }
    }

    /** @return array{open: bool, expires_at: ?string, remaining_seconds: ?int, expired: bool} */
    public static function customerPaymentWindowSummary(array $orderRow): array
    {
        if (!self::columnReady()) {
            return ['open' => true, 'expires_at' => null, 'remaining_seconds' => null, 'expired' => false];
        }
        $expRaw = (string) ($orderRow['payment_expires_at'] ?? '');
        $pay = (string) ($orderRow['payment_status'] ?? '');
        $status = (string) ($orderRow['status'] ?? '');
        if ($pay === 'paid' || $status === OrderStatus::CANCELLED) {
            return ['open' => false, 'expires_at' => $expRaw !== '' ? $expRaw : null, 'remaining_seconds' => 0, 'expired' => $status === OrderStatus::CANCELLED];
        }
        if ($expRaw === '') {
            return ['open' => true, 'expires_at' => null, 'remaining_seconds' => null, 'expired' => false];
        }
        try {
            $exp = new DateTimeImmutable($expRaw);
        } catch (Throwable $e) {
            return ['open' => true, 'expires_at' => null, 'remaining_seconds' => null, 'expired' => false];
        }
        $now = DeliveryConfig::now();
        $remaining = $exp->getTimestamp() - $now->getTimestamp();

        return [
            'open' => $remaining > 0,
            'expires_at' => $expRaw,
            'remaining_seconds' => max(0, $remaining),
            'expired' => $remaining <= 0,
        ];
    }

    public static function cronEligibleOrder(PDO $pdo, int $batchSize): array
    {
        if (!self::columnReady()) {
            return [];
        }
        $stmt = $pdo->prepare(
            "SELECT o.id FROM orders o
             WHERE o.status = 'pending_payment'
               AND o.payment_status IN ('pending', 'failed')
               AND o.payment_expires_at IS NOT NULL
               AND o.payment_expires_at <= NOW()
               AND (o.order_source IS NULL OR o.order_source NOT IN ('subscription'))
               AND NOT EXISTS (
                   SELECT 1 FROM payments p
                   WHERE p.order_id = o.id AND LOWER(p.gateway) IN ('cod', 'cash_on_delivery')
               )
             ORDER BY o.payment_expires_at ASC
             LIMIT " . (int) $batchSize
        );
        $stmt->execute();
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * @return array{action: string, message?: string}
     */
    public static function processOrder(int $orderId): array
    {
        if ($orderId <= 0 || !Install::isSchemaReady()) {
            return ['action' => 'skip', 'message' => 'invalid'];
        }

        $pdo = Database::pdo();
        $pre = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
        $pre->execute([$orderId]);
        $preOrder = $pre->fetch(PDO::FETCH_ASSOC);
        if (!$preOrder || !$thisPrecheckEligible($preOrder)) {
            return self::skipActionForPreOrder($preOrder);
        }

        $reconcile = self::reconcileBeforeCancel($pdo, $preOrder);
        if ($reconcile === 'paid') {
            return ['action' => 'completed_payment', 'message' => 'payment_confirmed'];
        }
        if ($reconcile === 'defer') {
            self::logExpiryDeferral((int) ($preOrder['id'] ?? 0), (string) ($preOrder['order_number'] ?? ''), 'pre_cancel_gateway_verify');

            return ['action' => 'defer', 'message' => 'verification_deferred'];
        }

        return Database::transaction(static function () use ($orderId): array {
            $pdo = Database::pdo();
            $lock = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
            $lock->execute([$orderId]);
            $order = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$order || !self::thisPrecheckEligible($order)) {
                return self::skipActionForPreOrder($order);
            }
            if ((string) ($order['payment_status'] ?? '') === 'paid') {
                return ['action' => 'completed_payment', 'message' => 'payment_confirmed'];
            }

            $result = OrderCancellationService::cancelForPaymentTimeout($orderId);
            if (!empty($result['ok'])) {
                if (empty($result['idempotent'])) {
                    NotificationDispatcher::orderPaymentTimeoutCancelled($orderId);
                }

                return ['action' => 'cancelled', 'message' => (string) ($result['message'] ?? 'cancelled')];
            }

            return ['action' => 'error', 'message' => (string) ($result['message'] ?? 'cancel_failed')];
        });
    }

    /** @param array<string, mixed>|false|null $order */
    private static function skipActionForPreOrder($order): array
    {
        if (!is_array($order)) {
            return ['action' => 'skip', 'message' => 'not_found'];
        }
        if ((string) ($order['status'] ?? '') === OrderStatus::CANCELLED) {
            return ['action' => 'skip', 'message' => 'already_cancelled'];
        }
        if ((string) ($order['payment_status'] ?? '') === 'paid') {
            return ['action' => 'skip', 'message' => 'paid'];
        }
        if ((string) ($order['status'] ?? '') !== OrderStatus::PENDING_PAYMENT) {
            return ['action' => 'skip', 'message' => 'ineligible_status'];
        }
        $exp = (string) ($order['payment_expires_at'] ?? '');
        if ($exp === '') {
            return ['action' => 'skip', 'message' => 'no_expiry'];
        }
        try {
            if (new DateTimeImmutable($exp) > DeliveryConfig::now()) {
                return ['action' => 'skip', 'message' => 'not_expired'];
            }
        } catch (Throwable $e) {
            return ['action' => 'skip', 'message' => 'bad_expiry'];
        }

        return ['action' => 'skip', 'message' => 'ineligible'];
    }

    /** @param array<string, mixed> $order */
    private static function thisPrecheckEligible(array $order): bool
    {
        if ((string) ($order['status'] ?? '') === OrderStatus::CANCELLED) {
            return false;
        }
        if ((string) ($order['payment_status'] ?? '') === 'paid') {
            return false;
        }
        if ((string) ($order['status'] ?? '') !== OrderStatus::PENDING_PAYMENT) {
            return false;
        }
        $exp = (string) ($order['payment_expires_at'] ?? '');
        if ($exp === '') {
            return false;
        }
        try {
            return new DateTimeImmutable($exp) <= DeliveryConfig::now();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return 'paid'|'unpaid'|'defer'
     */
    private static function reconcileBeforeCancel(PDO $pdo, array $order): string
    {
        $orderId = (int) ($order['id'] ?? 0);
        $orderNumber = (string) ($order['order_number'] ?? '');
        if (CheckoutIdempotencyService::orderHasCapturedPayment($pdo, $orderId)) {
            return 'paid';
        }
        if (self::hasOpenReconciliationException($pdo, $orderId)) {
            return 'defer';
        }
        if (self::hasDeferredGatewayVerification($pdo, $orderId)) {
            return 'defer';
        }

        $stmt = $pdo->prepare('SELECT gateway FROM payments WHERE order_id = ? ORDER BY id DESC');
        $stmt->execute([$orderId]);
        $gateways = array_unique(array_map('strtolower', array_filter($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        if ($gateways === []) {
            return 'unpaid';
        }

        foreach ($gateways as $gw) {
            if ($gw === 'cashfree') {
                $sync = PaymentService::reconcileCashfreeOrderFromGateway($orderNumber, null, 'expiry_cron', null);
                if (!empty($sync['redirect']) || ($sync['state'] ?? '') === 'confirmed') {
                    return 'paid';
                }
                if (($sync['state'] ?? '') === 'error') {
                    return 'defer';
                }
                if (($sync['state'] ?? '') === 'reconciliation') {
                    return 'defer';
                }
            }
            if ($gw === 'razorpay') {
                $sync = PaymentService::reconcileRazorpayOrderForExpiry($orderNumber);
                if (($sync['state'] ?? '') === 'confirmed') {
                    return 'paid';
                }
                if (($sync['state'] ?? '') === 'defer') {
                    return 'defer';
                }
                if (($sync['state'] ?? '') === 'error' && !empty($sync['message'])) {
                    return 'defer';
                }
            }
        }

        $captured = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE order_id = ? AND status = 'captured'");
        $captured->execute([$orderId]);
        if ((int) $captured->fetchColumn() > 0) {
            return 'paid';
        }

        return 'unpaid';
    }

    private static function hasOpenReconciliationException(PDO $pdo, int $orderId): bool
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
            return false;
        }
    }

    private static function hasDeferredGatewayVerification(PDO $pdo, int $orderId): bool
    {
        $st = $pdo->prepare(
            "SELECT status, updated_at FROM payments WHERE order_id = ?
             AND status IN ('created', 'pending', 'reconciliation_required')
             ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$orderId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $status = (string) ($row['status'] ?? '');
        if ($status === 'reconciliation_required') {
            return true;
        }
        $updated = (string) ($row['updated_at'] ?? '');
        if ($updated === '') {
            return false;
        }
        try {
            $age = DeliveryConfig::now()->getTimestamp() - (new DateTimeImmutable($updated))->getTimestamp();
        } catch (Throwable $e) {
            return false;
        }

        return $age < 120;
    }

    /**
     * @return array{cancelled: int, skipped: int, deferred: int, paid: int, errors: int, messages: list<string>}
     */
    public static function runCronBatch(?int $batchSize = null): array
    {
        $summary = ['cancelled' => 0, 'skipped' => 0, 'deferred' => 0, 'paid' => 0, 'errors' => 0, 'messages' => []];
        if (!Install::isSchemaReady() || !self::columnReady()) {
            $summary['messages'][] = 'schema_not_ready';

            return $summary;
        }
        $batch = $batchSize ?? self::resolveBatchSize();
        Install::ensureConnection();
        $pdo = Database::pdo();
        foreach (self::cronEligibleOrder($pdo, $batch) as $orderId) {
            try {
                $out = self::processOrder($orderId);
                $action = (string) ($out['action'] ?? 'skip');
                if ($action === 'cancelled') {
                    $summary['cancelled']++;
                } elseif ($action === 'defer') {
                    $summary['deferred']++;
                } elseif ($action === 'completed_payment') {
                    $summary['paid']++;
                } elseif ($action === 'error') {
                    $summary['errors']++;
                    self::appendExpiryBatchDiagnostic(
                        $summary,
                        $orderId,
                        self::orderNumberForDiagnostic($pdo, $orderId),
                        'cancellation',
                        (string) ($out['message'] ?? 'error')
                    );
                } else {
                    $summary['skipped']++;
                }
            } catch (Throwable $e) {
                $summary['errors']++;
                self::logExpiryProcessThrowable($summary, $pdo, $orderId, $e);
            }
        }

        return $summary;
    }

    private static function orderNumberForDiagnostic(PDO $pdo, int $orderId): string
    {
        if ($orderId <= 0) {
            return 'id:' . $orderId;
        }
        try {
            $st = $pdo->prepare('SELECT order_number FROM orders WHERE id = ? LIMIT 1');
            $st->execute([$orderId]);
            $num = trim((string) ($st->fetchColumn() ?: ''));

            return $num !== '' ? $num : 'id:' . $orderId;
        } catch (Throwable $e) {
            return 'id:' . $orderId;
        }
    }

    private static function classifyExpiryThrowable(Throwable $e): string
    {
        if ($e instanceof PDOException) {
            return 'database';
        }
        $cls = strtolower(get_class($e));
        if (str_contains($cls, 'payment') || str_contains($cls, 'cashfree') || str_contains($cls, 'razorpay')) {
            return 'payment_reconcile';
        }
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'cashfree') || str_contains($msg, 'razorpay') || str_contains($msg, 'gateway')) {
            return 'payment_reconcile';
        }
        if (str_contains($msg, 'cancel') || str_contains($msg, 'cancellation')) {
            return 'cancellation';
        }

        return 'exception';
    }

    private static function sanitizeExpiryDiagnosticText(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');
        if ($message === '') {
            return 'unknown';
        }
        $message = preg_replace('/[A-Za-z0-9_\-]{20,}/', '[redacted]', $message) ?? $message;
        $message = preg_replace('/\b(sk|rzp|cf)_[a-zA-Z0-9]{8,}\b/i', '[redacted]', $message) ?? $message;
        $message = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[email]', $message) ?? $message;
        $message = preg_replace('/\b(?:\+91[\s\-]?)?[6-9]\d{9}\b/', '[phone]', $message) ?? $message;

        return mb_substr($message, 0, 160);
    }

    /**
     * @param array{cancelled: int, skipped: int, deferred: int, paid: int, errors: int, messages: list<string>} $summary
     */
    private static function appendExpiryBatchDiagnostic(
        array &$summary,
        int $orderId,
        string $orderNumber,
        string $category,
        string $message
    ): void {
        if (count($summary['messages']) >= 8) {
            return;
        }
        $safeMsg = self::sanitizeExpiryDiagnosticText($message);
        $category = preg_replace('/[^a-z_]/', '', strtolower($category)) ?: 'unknown';
        $label = $orderNumber !== '' ? $orderNumber : 'id:' . $orderId;
        $summary['messages'][] = $label . ':' . $category . ':' . $safeMsg;
    }

    /**
     * @param array{cancelled: int, skipped: int, deferred: int, paid: int, errors: int, messages: list<string>} $summary
     */
    private static function logExpiryProcessThrowable(array &$summary, PDO $pdo, int $orderId, Throwable $e): void
    {
        $orderNumber = self::orderNumberForDiagnostic($pdo, $orderId);
        $category = self::classifyExpiryThrowable($e);
        $safeMsg = self::sanitizeExpiryDiagnosticText($e->getMessage());
        $sqlState = '';
        if ($e instanceof PDOException) {
            $code = $e->getCode();
            $sqlState = is_scalar($code) ? (string) $code : '';
        }
        error_log(
            'UnpaidOrderExpiryService processOrder failure'
            . ' order_id=' . $orderId
            . ' order_number=' . $orderNumber
            . ' category=' . $category
            . ' exception=' . get_class($e)
            . ($sqlState !== '' ? ' sqlstate=' . $sqlState : '')
            . ' message=' . $safeMsg
        );
        self::appendExpiryBatchDiagnostic($summary, $orderId, $orderNumber, $category, $safeMsg);
    }

    public static function resolveBatchSize(): int
    {
        $raw = getenv('APNI_TOKRI_UNPAID_EXPIRY_BATCH');
        $n = is_string($raw) && $raw !== '' ? (int) $raw : 40;

        return max(1, min(200, $n));
    }

    public static function acquireCronLock(): bool
    {
        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') {
            return true;
        }
        Install::ensureConnection();
        $key = self::CRON_LOCK;
        $stmt = Database::pdo()->query('SELECT GET_LOCK(' . Database::pdo()->quote($key) . ', 0)');
        $got = (int) ($stmt ? $stmt->fetchColumn() : 0);

        return $got === 1;
    }

    private static function logExpiryDeferral(int $orderId, string $orderNumber, string $phase): void
    {
        if ($orderId <= 0) {
            return;
        }
        error_log(
            'UnpaidOrderExpiry defer order_id=' . $orderId
            . ' order_number=' . $orderNumber
            . ' phase=' . $phase
            . ' (payment window expired; gateway verify inconclusive — not cancelled)'
        );
        try {
            AuditLog::write('order.expiry_deferred', 'orders', (string) $orderId, [
                'order_number' => $orderNumber,
                'phase' => $phase,
            ]);
        } catch (Throwable $e) {
            // non-fatal
        }
    }

    public static function releaseCronLock(): void
    {
        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') {
            return;
        }
        Install::ensureConnection();
        Database::pdo()->query('SELECT RELEASE_LOCK(' . Database::pdo()->quote(self::CRON_LOCK) . ')');
    }
}
