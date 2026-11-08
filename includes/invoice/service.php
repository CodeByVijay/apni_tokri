<?php

declare(strict_types=1);

/**
 * Invoice / credit-note lifecycle: eligibility, numbering, immutable snapshots, atomic creation.
 */
final class InvoiceService
{
    public const GENERATOR_VERSION = '1';

    /** @var callable|null test seam: invoked after the PDF is stored and before the row is inserted */
    public static $afterRenderHook;

    /** Order lifecycle statuses that can never be invoiced. */
    private const BLOCKED_STATUSES = ['pending_payment', 'payment_failed', 'cancelled', 'abandoned'];
    private const INVOICEABLE_PAYMENT_STATUSES = ['paid', 'partially_refunded', 'refunded'];

    // ------------------------------------------------------------------ eligibility

    /**
     * @param array<string, mixed> $order orders row
     * @return array{ok: bool, reason?: string, message?: string}
     */
    public static function eligibility(array $order): array
    {
        if ((string) ($order['order_source'] ?? '') === 'subscription') {
            return ['ok' => false, 'reason' => 'subscription', 'message' => 'Subscription deliveries are not invoiced individually.'];
        }
        $status = (string) ($order['status'] ?? '');
        if (in_array($status, self::BLOCKED_STATUSES, true)) {
            return ['ok' => false, 'reason' => 'status', 'message' => 'Orders that are unpaid, failed or cancelled are not invoiced.'];
        }
        if (!in_array((string) ($order['payment_status'] ?? ''), self::INVOICEABLE_PAYMENT_STATUSES, true)) {
            return ['ok' => false, 'reason' => 'payment', 'message' => 'Payment has not been confirmed for this order.'];
        }
        if ((float) ($order['grand_total'] ?? 0) <= 0) {
            return ['ok' => false, 'reason' => 'amount', 'message' => 'Order total is zero.'];
        }
        if ((int) ($order['customer_id'] ?? 0) <= 0) {
            return ['ok' => false, 'reason' => 'customer', 'message' => 'Order has no customer.'];
        }
        $st = Database::pdo()->prepare("SELECT COUNT(*) FROM payments WHERE order_id = ? AND status = 'captured'");
        $st->execute([(int) $order['id']]);
        if ((int) $st->fetchColumn() < 1) {
            return ['ok' => false, 'reason' => 'no_capture', 'message' => 'No captured payment is recorded for this order.'];
        }
        if (self::capturedPaymentTotal((int) $order['id']) <= 0) {
            return ['ok' => false, 'reason' => 'no_capture', 'message' => 'No captured payment amount is recorded for this order.'];
        }

        return ['ok' => true];
    }

    /** Sum of captured payment rows (invoice eligibility and snapshots; no order-status fallback). */
    public static function capturedPaymentTotal(int $orderId): float
    {
        $st = Database::pdo()->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ? AND status = 'captured'");
        $st->execute([$orderId]);

        return round((float) $st->fetchColumn(), 2);
    }

    // ------------------------------------------------------------------ lookup

    /** @return array<string, mixed>|null */
    public static function byId(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM invoices WHERE id = ? LIMIT 1');
        $st->execute([$id]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function byRef(string $ref): ?array
    {
        if (preg_match('/^[a-f0-9]{24}$/', $ref) !== 1) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM invoices WHERE public_ref = ? LIMIT 1');
        $st->execute([$ref]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function retailForOrder(int $orderId): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM invoices WHERE dedupe_key = ? LIMIT 1');
        $st->execute(['inv:' . $orderId]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<array<string, mixed>> */
    public static function creditNotesForOrder(int $orderId): array
    {
        $st = Database::pdo()->prepare(
            "SELECT * FROM invoices WHERE order_id = ? AND document_type = 'credit_note' ORDER BY id ASC"
        );
        $st->execute([$orderId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Customer-scoped fetch: null when the invoice does not belong to the customer. */
    /** @return array<string, mixed>|null */
    public static function forCustomer(string $ref, int $customerId): ?array
    {
        $inv = self::byRef($ref);
        if ($inv === null || (int) $inv['customer_id'] !== $customerId || $customerId <= 0) {
            return null;
        }

        return $inv;
    }

    /** Whether the customer portal may view/download invoice documents for this order. */
    public static function customerPortalReleased(int $orderId): bool
    {
        return self::orderDeliveryProven($orderId);
    }

    /**
     * True only when the order was actually delivered (not merely refunded).
     * Refunded / partially_refunded release access only if delivery is proven via status, history, or delivery row.
     */
    public static function orderDeliveryProven(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT status FROM orders WHERE id = ? LIMIT 1');
        $st->execute([$orderId]);
        $status = (string) ($st->fetchColumn() ?: '');
        if ($status === OrderStatus::DELIVERED) {
            return true;
        }
        $hist = $pdo->prepare(
            'SELECT 1 FROM order_status_history WHERE order_id = ? AND status = ? LIMIT 1'
        );
        $hist->execute([$orderId, OrderStatus::DELIVERED]);
        if ($hist->fetchColumn()) {
            return true;
        }
        $del = $pdo->prepare(
            "SELECT 1 FROM deliveries WHERE order_id = ? AND (status = ? OR delivered_at IS NOT NULL) LIMIT 1"
        );
        $del->execute([$orderId, DeliveryStatus::DELIVERED]);
        if ($del->fetchColumn()) {
            return true;
        }

        return false;
    }

    /** SQL AND-clause fragment; order row must be aliased as $orderAlias (default o). */
    public static function sqlOrderDeliveryProven(string $orderAlias = 'o'): string
    {
        $delivered = OrderStatus::DELIVERED;
        $delStatus = DeliveryStatus::DELIVERED;

        return '(' . $orderAlias . '.status = \'' . $delivered . '\''
            . ' OR EXISTS (SELECT 1 FROM order_status_history h WHERE h.order_id = ' . $orderAlias . '.id AND h.status = \'' . $delivered . '\')'
            . ' OR EXISTS (SELECT 1 FROM deliveries d WHERE d.order_id = ' . $orderAlias . '.id AND (d.status = \'' . $delStatus . '\' OR d.delivered_at IS NOT NULL))'
            . ')';
    }

    /** Customer portal: ownership plus delivery release (admin paths use forCustomer/byId). */
    /** @return array<string, mixed>|null */
    public static function forCustomerPortal(string $ref, int $customerId): ?array
    {
        $inv = self::forCustomer($ref, $customerId);
        if ($inv === null) {
            return null;
        }
        if (!self::customerPortalReleased((int) $inv['order_id'])) {
            return null;
        }

        return $inv;
    }

    /** @return array<string, mixed> */
    public static function snapshot(array $invoice): array
    {
        $data = json_decode((string) ($invoice['snapshot_json'] ?? ''), true);

        return is_array($data) ? $data : [];
    }

    // ------------------------------------------------------------------ creation

    /**
     * Idempotently create the retail invoice for an order.
     *
     * @param array<string, mixed> $opts prefix?, now? (timestamp), admin_id?
     * @return array{ok: bool, created?: bool, invoice?: array<string, mixed>, error?: string, message?: string}
     */
    public static function ensureForOrder(int $orderId, array $opts = []): array
    {
        $existing = self::retailForOrder($orderId);
        if ($existing !== null) {
            return ['ok' => true, 'created' => false, 'invoice' => $existing];
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
        $st->execute([$orderId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return ['ok' => false, 'error' => 'not_found', 'message' => 'Order not found.'];
        }
        $el = self::eligibility($order);
        if (!$el['ok']) {
            return ['ok' => false, 'error' => (string) $el['reason'], 'message' => (string) $el['message']];
        }

        return self::create(InvoiceSettings::DOC_RETAIL, $orderId, 'inv:' . $orderId, null, null, $opts);
    }

    /**
     * Issue a credit note for a completed refund. Never alters the original invoice.
     *
     * @param array<string, mixed> $opts
     * @return array{ok: bool, created?: bool, invoice?: array<string, mixed>, error?: string, message?: string}
     */
    public static function issueCreditNote(int $paymentRefundId, array $opts = []): array
    {
        $pdo = Database::pdo();
        $key = 'cn:' . $paymentRefundId;
        $st = $pdo->prepare('SELECT * FROM invoices WHERE dedupe_key = ? LIMIT 1');
        $st->execute([$key]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            return ['ok' => true, 'created' => false, 'invoice' => $existing];
        }
        $refund = self::refundRow($paymentRefundId);
        if ($refund === null) {
            return ['ok' => false, 'error' => 'not_found', 'message' => 'Refund not found.'];
        }
        if ((int) ($refund['order_id'] ?? 0) <= 0) {
            return ['ok' => false, 'error' => 'no_order', 'message' => 'Refund is not linked to an order payment.'];
        }
        if (!in_array((string) $refund['gateway_status'], ['processed', 'success'], true) || empty($refund['completed_at'])) {
            return ['ok' => false, 'error' => 'not_completed', 'message' => 'Credit notes are issued only for completed refunds.'];
        }
        if ((float) $refund['amount_inr'] <= 0) {
            return ['ok' => false, 'error' => 'amount', 'message' => 'Refund amount is zero.'];
        }
        $parent = self::ensureForOrder((int) $refund['order_id'], $opts);
        if (!$parent['ok']) {
            return ['ok' => false, 'error' => 'no_invoice', 'message' => 'An invoice must exist before a credit note: ' . (string) ($parent['message'] ?? '')];
        }

        return self::create(InvoiceSettings::DOC_CREDIT_NOTE, (int) $refund['order_id'], $key, (array) $parent['invoice'], $refund, $opts);
    }

    /**
     * Lazy, idempotent catch-up used when a customer/admin opens an order: invoice (if eligible) plus
     * credit notes for completed refunds. Never throws.
     *
     * @return array{invoice: array<string, mixed>|null, credit_notes: list<array<string, mixed>>, eligible: bool, message: string}
     */
    public static function syncForOrder(int $orderId): array
    {
        $out = ['invoice' => null, 'credit_notes' => [], 'eligible' => false, 'message' => ''];
        try {
            $inv = self::retailForOrder($orderId);
            if ($inv === null) {
                $r = self::ensureForOrder($orderId);
                if ($r['ok']) {
                    $inv = $r['invoice'];
                } else {
                    $out['eligible'] = !in_array((string) ($r['error'] ?? ''), ['status', 'payment', 'amount', 'customer', 'no_capture', 'subscription', 'not_found'], true);
                    $out['message'] = (string) ($r['message'] ?? '');

                    return $out;
                }
            }
            $out['invoice'] = $inv;
            $out['eligible'] = true;
            $st = Database::pdo()->prepare(
                "SELECT pr.id FROM payment_refunds pr JOIN payments p ON p.id = pr.payment_id
                 WHERE p.order_id = ? AND pr.gateway_status IN ('processed','success') AND pr.completed_at IS NOT NULL AND pr.amount_inr > 0
                 ORDER BY pr.id ASC"
            );
            $st->execute([$orderId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $rid) {
                self::issueCreditNote((int) $rid);
            }
            $out['credit_notes'] = self::creditNotesForOrder($orderId);
        } catch (Throwable $e) {
            error_log('InvoiceService::syncForOrder: ' . get_class($e));
            $out['message'] = 'Invoice is being prepared.';
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function refundRow(int $id): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT pr.*, p.order_id AS order_id, rr.reason_code, rr.description AS request_description, rr.request_number
             FROM payment_refunds pr
             LEFT JOIN payments p ON p.id = pr.payment_id
             LEFT JOIN refund_requests rr ON rr.id = pr.refund_request_id
             WHERE pr.id = ? LIMIT 1'
        );
        $st->execute([$id]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @param array<string, mixed>|null $parent
     * @param array<string, mixed>|null $refund
     * @param array<string, mixed> $opts
     * @return array{ok: bool, created?: bool, invoice?: array<string, mixed>, error?: string, message?: string}
     */
    private static function create(string $type, int $orderId, string $dedupeKey, ?array $parent, ?array $refund, array $opts): array
    {
        $pdo = Database::pdo();
        $savedFile = null;
        $ownTxn = !$pdo->inTransaction();
        try {
            if ($ownTxn) {
                $pdo->beginTransaction();
            }
            // Serialise per order so concurrent requests cannot both create.
            $lock = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
            $lock->execute([$orderId]);
            $order = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                throw new RuntimeException('order_missing');
            }
            $dup = $pdo->prepare('SELECT * FROM invoices WHERE dedupe_key = ? LIMIT 1');
            $dup->execute([$dedupeKey]);
            $found = $dup->fetch(PDO::FETCH_ASSOC);
            if ($found) {
                if ($ownTxn) {
                    $pdo->commit();
                }

                return ['ok' => true, 'created' => false, 'invoice' => $found];
            }
            if ($type === InvoiceSettings::DOC_RETAIL) {
                $el = self::eligibility($order);
                if (!$el['ok']) {
                    if ($ownTxn) {
                        $pdo->rollBack();
                    }

                    return ['ok' => false, 'error' => (string) $el['reason'], 'message' => (string) $el['message']];
                }
            }

            $now = (int) ($opts['now'] ?? time());
            $date = date('Y-m-d', $now);
            $prefix = (string) ($opts['prefix'] ?? InvoiceSettings::prefix());
            $isCredit = $type === InvoiceSettings::DOC_CREDIT_NOTE;
            $alloc = self::allocateNumber($pdo, $prefix, $isCredit, $date);

            $snapshot = InvoiceSnapshotBuilder::build($order, $type, $alloc['number'], $date, date('Y-m-d H:i:s', $now), $parent, $refund);
            $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $renderSnap = $snapshot;
            $renderSnap['logo_path'] = $snapshot['seller']['logo_file'] ? InvoiceSettings::logoPath() : null;
            $pdfBytes = InvoicePdfRenderer::renderA4($renderSnap);
            $saved = InvoiceStorage::savePdf($pdfBytes);
            if (!$saved['ok']) {
                throw new RuntimeException('pdf_' . (string) ($saved['error'] ?? 'failed'));
            }
            $savedFile = (string) $saved['file'];
            if (self::$afterRenderHook !== null) {
                (self::$afterRenderHook)($savedFile);
            }

            $ref = bin2hex(random_bytes(12));
            $ins = $pdo->prepare(
                'INSERT INTO invoices (public_ref, order_id, customer_id, document_type, document_status, parent_invoice_id,
                    payment_refund_id, dedupe_key, invoice_number, fiscal_year, sequence_no, invoice_date, currency,
                    grand_total, snapshot_json, snapshot_hash, pdf_path, pdf_hash, pdf_size, pdf_generated_at,
                    generator_version, created_by_admin_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $ins->execute([
                $ref, $orderId, (int) $order['customer_id'], $type, 'final',
                $parent['id'] ?? null, $refund['id'] ?? null, $dedupeKey,
                $alloc['number'], $alloc['fy'], $alloc['seq'], $date, 'INR',
                $isCredit ? round((float) $refund['amount_inr'], 2) : round((float) $order['grand_total'], 2),
                $snapshotJson, hash('sha256', $snapshotJson), $savedFile, $saved['hash'], $saved['size'],
                date('Y-m-d H:i:s', $now), self::GENERATOR_VERSION, $opts['admin_id'] ?? null,
            ]);
            $id = (int) $pdo->lastInsertId();
            if ($ownTxn) {
                $pdo->commit();
            }
            $row = self::byId($id);

            return ['ok' => true, 'created' => true, 'invoice' => $row ?? []];
        } catch (Throwable $e) {
            if ($ownTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($savedFile !== null) {
                InvoiceStorage::delete($savedFile);
            }
            // A concurrent winner may have inserted the same dedupe key.
            if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                $again = self::retailForOrder($orderId);
                $st = $pdo->prepare('SELECT * FROM invoices WHERE dedupe_key = ? LIMIT 1');
                $st->execute([$dedupeKey]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return ['ok' => true, 'created' => false, 'invoice' => $row];
                }
                unset($again);
            }
            error_log('InvoiceService::create failed: ' . get_class($e) . ' ' . $e->getMessage());

            return ['ok' => false, 'error' => 'generation_failed', 'message' => 'Invoice could not be generated right now. Please try again shortly.'];
        }
    }

    /**
     * Concurrency-safe sequential number. Must run inside a transaction.
     *
     * @return array{number: string, fy: string, seq: int}
     */
    private static function allocateNumber(PDO $pdo, string $prefix, bool $credit, string $ymd): array
    {
        $fy = InvoiceSettings::fyNumbering() ? InvoiceFormat::financialYear($ymd) : 'ALL';
        $series = $credit ? 'credit_note' : 'invoice';
        $start = max(1, InvoiceSettings::startSequence());
        $pdo->prepare(
            'INSERT INTO invoice_sequences (prefix, fiscal_year, doc_series, last_number) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE last_number = last_number'
        )->execute([$prefix, $fy, $series, $start - 1]);
        $sel = $pdo->prepare('SELECT last_number FROM invoice_sequences WHERE prefix = ? AND fiscal_year = ? AND doc_series = ? FOR UPDATE');
        $sel->execute([$prefix, $fy, $series]);
        $next = (int) $sel->fetchColumn() + 1;
        $pdo->prepare('UPDATE invoice_sequences SET last_number = ? WHERE prefix = ? AND fiscal_year = ? AND doc_series = ?')
            ->execute([$next, $prefix, $fy, $series]);
        $label = ($credit ? $prefix . 'CN' : $prefix);
        $number = $label . '/' . ($fy !== 'ALL' ? $fy . '/' : '') . str_pad((string) $next, 5, '0', STR_PAD_LEFT);

        return ['number' => $number, 'fy' => $fy, 'seq' => $next];
    }

    // ------------------------------------------------------------------ on-demand renders

    /** Thermal layout rendered from the stored snapshot (never stored; never regenerated from live settings). */
    public static function thermalPdf(array $invoice): ?string
    {
        if (!InvoiceSettings::thermalEnabled()) {
            return null;
        }
        $snap = self::snapshot($invoice);
        if ($snap === []) {
            return null;
        }

        return InvoicePdfRenderer::renderThermal($snap);
    }

    // ------------------------------------------------------------------ sweeper

    /**
     * Generate missing invoices for eligible orders. Used by cron; safe to re-run.
     *
     * @return array{created: int, skipped: int, failed: int}
     */
    public static function sweep(int $limit = 50): array
    {
        $st = Database::pdo()->prepare(
            "SELECT o.id FROM orders o
             WHERE o.payment_status IN ('paid','partially_refunded','refunded')
               AND o.order_source <> 'subscription'
               AND o.status NOT IN ('pending_payment','payment_failed','cancelled')
               AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.dedupe_key = CONCAT('inv:', o.id))
             ORDER BY o.id ASC LIMIT " . max(1, min(500, $limit))
        );
        $st->execute();
        $res = ['created' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $oid) {
            $r = self::ensureForOrder((int) $oid);
            if ($r['ok'] && !empty($r['created'])) {
                $res['created']++;
            } elseif ($r['ok']) {
                $res['skipped']++;
            } elseif (($r['error'] ?? '') === 'generation_failed') {
                $res['failed']++;
            } else {
                $res['skipped']++;
            }
        }

        return $res;
    }
}

final class InvoiceSnapshotBuilder
{
    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed>|null $parent
     * @param array<string, mixed>|null $refund
     * @return array<string, mixed>
     */
    public static function build(array $order, string $type, string $number, string $date, string $generatedAt, ?array $parent, ?array $refund): array
    {
        $pdo = Database::pdo();
        $orderId = (int) $order['id'];
        $isCredit = $type === InvoiceSettings::DOC_CREDIT_NOTE;

        $cs = $pdo->prepare('SELECT name, mobile, email FROM customers WHERE id = ? LIMIT 1');
        $cs->execute([(int) $order['customer_id']]);
        $cust = $cs->fetch(PDO::FETCH_ASSOC) ?: [];

        $payments = CustomerOrderDetailService::paymentsForOrder($orderId);
        $price = CustomerOrderDetailService::priceBreakdown($order, $payments);
        $addr = CustomerOrderDetailService::addressBlock($order, $pdo);
        $del = CustomerOrderDetailService::deliveryBlock($order, $pdo);

        $is = $pdo->prepare('SELECT product_name, variant_name, sku, quantity, unit_price, line_total FROM order_items WHERE order_id = ? ORDER BY id ASC');
        $is->execute([$orderId]);
        $items = [];
        foreach ($is->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $items[] = [
                'name' => (string) $r['product_name'],
                'variant' => (string) ($r['variant_name'] ?? ''),
                'sku' => (string) ($r['sku'] ?? ''),
                'qty' => (int) $r['quantity'],
                'unit_price' => round((float) $r['unit_price'], 2),
                'line_total' => round((float) $r['line_total'], 2),
            ];
        }

        $seller = InvoiceSettings::seller();
        $opts = InvoiceSettings::renderOptions();
        $seller['logo_file'] = InvoiceSettings::logoPath() !== null ? (string) (InvoiceSettings::formState()['logo_file'] ?? '') : '';

        $subtotal = round((float) $order['subtotal'], 2);
        $discount = round((float) $order['discount_total'], 2);
        $fee = round((float) $order['delivery_fee'], 2);
        $grand = round((float) $order['grand_total'], 2);
        $rounding = round($grand - ($subtotal - $discount + $fee), 2);
        // Invoice snapshots use captured payment totals only (not priceBreakdown's paid-status fallback).
        $paid = InvoiceService::capturedPaymentTotal($orderId);
        $payRows = [];
        foreach ($payments as $p) {
            $payRows[] = [
                'gateway_label' => (string) $p['gateway_label'],
                'status' => (string) $p['status'],
                'amount' => (float) $p['amount'],
                'method' => $p['payment_method'] ?? null,
                'paid_at' => (string) ($p['paid_at'] ?? ''),
                'reference' => $p['gateway_payment_id'] ?? null,
            ];
        }
        $orderPay = (string) ($order['payment_status'] ?? '');

        $delivery = null;
        if ($del['has_slot']) {
            $delivery = [
                'date' => $del['delivery_date'],
                'slot' => $del['slot_display'] ?? $del['slot_label'],
                'slot_display' => $del['slot_display'] ?? null,
                'window' => null,
                'time_range' => $del['time_range'],
                'status' => $del['delivery_status'],
            ];
        }
        if (is_array($addr) && empty($opts['show_map_link'])) {
            $addr['map_link'] = null;
        }

        $docTitle = $isCredit ? 'CREDIT NOTE' : 'RETAIL INVOICE';
        $document = [
            'type' => $type,
            'title' => $docTitle,
            'number' => $number,
            'date' => $date,
            'badge' => $isCredit ? 'Credit issued' : ($orderPay === 'refunded' ? 'Refunded' : ($orderPay === 'partially_refunded' ? 'Partly refunded' : 'Paid')),
        ];
        if ($isCredit && $refund !== null) {
            $reasons = RefundRequestService::reasonLabels();
            $reasonCode = (string) ($refund['reason_code'] ?? '');
            $document['original_invoice_number'] = (string) ($parent['invoice_number'] ?? '');
            $document['original_invoice_date'] = (string) ($parent['invoice_date'] ?? '');
            $document['refund'] = [
                'amount' => round((float) $refund['amount_inr'], 2),
                'status_label' => 'Refund completed',
                'reason' => $reasons[$reasonCode] ?? ($reasonCode !== '' ? ucwords(str_replace('_', ' ', $reasonCode)) : ''),
                'reference' => (string) ($refund['gateway_refund_id'] ?? ''),
                'completed_at' => (string) ($refund['completed_at'] ?? ''),
            ];
        }

        return [
            'schema' => 1,
            'generated_at' => $generatedAt,
            'seller' => $seller,
            'options' => $opts,
            'document' => $document,
            'customer' => [
                'name' => (string) ($cust['name'] ?? '') !== '' ? (string) $cust['name'] : 'Customer',
                'mobile' => (string) ($cust['mobile'] ?? ''),
                'email' => (string) ($cust['email'] ?? ''),
                'billing_address' => null,
            ],
            'order' => [
                'id' => $orderId,
                'number' => (string) $order['order_number'],
                'placed_at' => (string) ($order['placed_at'] ?? $order['created_at'] ?? ''),
                'status_label' => ucwords(str_replace('_', ' ', (string) $order['status'])),
                'payment_status_label' => CustomerOrderDetailService::orderPaymentStatusLabel($orderPay),
            ],
            'delivery_address' => $addr,
            'delivery' => $delivery,
            'items' => $items,
            'pricing' => [
                'subtotal' => $subtotal,
                'discount' => $discount,
                'coupon_code' => $order['coupon_code'] ?? null,
                'delivery_fee' => $fee,
                'rounding' => $rounding,
                'grand_total' => $grand,
                'amount_paid' => $paid,
                'amount_refunded' => (float) $price['amount_refunded'],
                'balance_due' => max(0.0, round($grand - $paid, 2)),
            ],
            'payments' => $payRows,
        ];
    }
}
